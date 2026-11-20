<?php
declare(strict_types=1);

// ---------- 設定 ----------
$configPath = __DIR__ . '/config.php';
$config = is_file($configPath) ? require $configPath : null;
if (!is_array($config) || empty($config['api_key']) || strpos((string)$config['api_key'], 'CHANGE_ME') === 0) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo '{"error":"server not configured"}';
    exit;
}
$API_KEY = (string)$config['api_key'];
$DB_PATH = $config['db'] ?? (__DIR__ . '/data/memory.sqlite');

// ---------- ベースパス解決（/ai-memory/ 配下でもドキュメントルート直下でも動くように） ----------
$scriptName = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
if (basename($scriptName) === 'index.php') {
    $BASE = rtrim(str_replace('\\', '/', dirname($scriptName)), '/');
    if ($BASE === '/' || $BASE === '.') $BASE = '';
} else {
    // SCRIPT_NAME が index.php にならないSAPI向けにDOCUMENT_ROOTからの相対で求める
    $BASE = '';
    $docroot = isset($_SERVER['DOCUMENT_ROOT']) ? realpath($_SERVER['DOCUMENT_ROOT']) : false;
    if ($docroot) {
        $docroot = rtrim(str_replace('\\', '/', $docroot), '/');
        $dir = str_replace('\\', '/', __DIR__);
        if (strpos($dir, $docroot . '/') === 0) $BASE = substr($dir, strlen($docroot));
    }
}

$uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$path = ($BASE !== '' && strpos($uri, $BASE) === 0) ? substr($uri, strlen($BASE)) : $uri;
if ($path === '' || $path === false) $path = '/';
if (strlen($path) > 1) $path = rtrim($path, '/');
if ($path === '') $path = '/';

// ---------- ユーティリティ ----------
function json_out($data, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function is_https(): bool {
    if (!empty($_SERVER['HTTP_X_FORWARDED_PROTO'])) {
        return strtolower(trim(explode(',', $_SERVER['HTTP_X_FORWARDED_PROTO'])[0])) === 'https';
    }
    return !empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off';
}

// ---------- DB ----------
function db(): PDO {
    static $pdo = null;
    if ($pdo instanceof PDO) return $pdo;
    global $DB_PATH;
    $dir = dirname($DB_PATH);
    if (!is_dir($dir)) mkdir($dir, 0775, true);
    $pdo = new PDO('sqlite:' . $DB_PATH, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec('CREATE TABLE IF NOT EXISTS memories (key TEXT PRIMARY KEY, content TEXT NOT NULL, updated_at TEXT NOT NULL)');
    // shortcut: 履歴は無期限保持、DBが肥大化したら古い履歴の削除を追加
    $pdo->exec('CREATE TABLE IF NOT EXISTS memory_history (id INTEGER PRIMARY KEY AUTOINCREMENT, key TEXT NOT NULL, content TEXT NOT NULL, updated_at TEXT NOT NULL, archived_at TEXT NOT NULL)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS memory_history_key ON memory_history(key, id)');
    return $pdo;
}

function valid_key($key): bool {
    return is_string($key) && preg_match('/^[A-Za-z0-9_.\-]{1,100}$/', $key) === 1;
}

function valid_content($content): bool {
    return is_string($content) && strlen($content) <= 1048576;
}

function list_memories(): array {
    return db()->query('SELECT key, updated_at FROM memories ORDER BY key')->fetchAll();
}

function get_memory(string $key): ?array {
    $st = db()->prepare('SELECT key, content, updated_at FROM memories WHERE key = ?');
    $st->execute([$key]);
    $row = $st->fetch();
    return $row === false ? null : $row;
}

function save_memory(string $key, string $content): array {
    $now = date('c');
    $pdo = db();
    $pdo->beginTransaction();
    // 変更前の行を履歴に退避（内容が同じなら退避しない）
    $pdo->prepare('INSERT INTO memory_history (key, content, updated_at, archived_at)
        SELECT key, content, updated_at, ? FROM memories WHERE key = ? AND content <> ?')
        ->execute([$now, $key, $content]);
    $pdo->prepare('INSERT INTO memories (key, content, updated_at) VALUES (?, ?, ?)
        ON CONFLICT(key) DO UPDATE SET content = excluded.content, updated_at = excluded.updated_at')
        ->execute([$key, $content, $now]);
    $pdo->commit();
    return ['key' => $key, 'content' => $content, 'updated_at' => $now];
}

function delete_memory(string $key): bool {
    $pdo = db();
    $pdo->beginTransaction();
    $pdo->prepare('INSERT INTO memory_history (key, content, updated_at, archived_at)
        SELECT key, content, updated_at, ? FROM memories WHERE key = ?')
        ->execute([date('c'), $key]);
    $st = $pdo->prepare('DELETE FROM memories WHERE key = ?');
    $st->execute([$key]);
    $deleted = $st->rowCount() > 0;
    $pdo->commit();
    return $deleted;
}

function get_history(string $key): array {
    $st = db()->prepare('SELECT id, content, updated_at, archived_at FROM memory_history WHERE key = ? ORDER BY id DESC');
    $st->execute([$key]);
    return $st->fetchAll();
}

function restore_memory(string $key, int $id): ?array {
    $st = db()->prepare('SELECT content FROM memory_history WHERE id = ? AND key = ?');
    $st->execute([$id, $key]);
    $row = $st->fetch();
    if ($row === false) return null;
    return save_memory($key, $row['content']);
}

// ---------- 認証 ----------
function request_token(): ?string {
    $auth = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
    if (preg_match('/Bearer\s+(\S+)/i', $auth, $m)) return $m[1];
    if (isset($_SERVER['HTTP_X_API_KEY']) && $_SERVER['HTTP_X_API_KEY'] !== '') return $_SERVER['HTTP_X_API_KEY'];
    if (isset($_GET['token']) && $_GET['token'] !== '') return (string)$_GET['token'];
    return null;
}

function require_token(): void {
    global $API_KEY;
    $token = request_token();
    if ($token === null || !hash_equals($API_KEY, $token)) {
        json_out(['error' => 'unauthorized'], 401);
    }
}

// ---------- MCP ----------
function mcp_tools(): array {
    return [
        [
            'name' => 'list_memories',
            'description' => '保存されているメモのキーと更新日時の一覧を返します',
            'inputSchema' => ['type' => 'object', 'properties' => new stdClass],
        ],
        [
            'name' => 'get_memory',
            'description' => '指定したキーのメモ内容を取得します',
            'inputSchema' => [
                'type' => 'object',
                'properties' => ['key' => ['type' => 'string', 'description' => 'メモのキー']],
                'required' => ['key'],
                'additionalProperties' => false,
            ],
        ],
        [
            'name' => 'put_memory',
            'description' => 'メモを保存します。同じキーが既に存在する場合は上書き保存されます（上書き前の内容は履歴に自動保存されます）',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'key' => ['type' => 'string', 'description' => 'メモのキー（英数字・._-、100文字以内）'],
                    'content' => ['type' => 'string', 'description' => '保存する内容'],
                ],
                'required' => ['key', 'content'],
                'additionalProperties' => false,
            ],
        ],
        [
            'name' => 'delete_memory',
            'description' => '指定したキーのメモを削除します',
            'inputSchema' => [
                'type' => 'object',
                'properties' => ['key' => ['type' => 'string', 'description' => 'メモのキー']],
                'required' => ['key'],
                'additionalProperties' => false,
            ],
        ],
        [
            'name' => 'get_history',
            'description' => '指定したキーの過去の版の一覧（id付き）を返します。上書き・削除前の内容を確認できます',
            'inputSchema' => [
                'type' => 'object',
                'properties' => ['key' => ['type' => 'string', 'description' => 'メモのキー']],
                'required' => ['key'],
                'additionalProperties' => false,
            ],
        ],
        [
            'name' => 'restore_memory',
            'description' => '指定した履歴idの内容でメモを復元します。現在の内容は履歴に自動保存されます',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'key' => ['type' => 'string', 'description' => 'メモのキー'],
                    'id' => ['type' => 'integer', 'description' => 'get_history で取得した履歴のid'],
                ],
                'required' => ['key', 'id'],
                'additionalProperties' => false,
            ],
        ],
    ];
}

function mcp_text(string $text, bool $isError = false): array {
    $r = ['content' => [['type' => 'text', 'text' => $text]]];
    if ($isError) $r['isError'] = true;
    return $r;
}

// 不明ツールは null を返し、呼び出し側で -32601 にする
function mcp_call_tool(string $name, array $args): ?array {
    $enc = function ($v) { return json_encode($v, JSON_UNESCAPED_UNICODE); };
    switch ($name) {
        case 'list_memories':
            return mcp_text($enc(list_memories()));
        case 'get_memory':
            $key = $args['key'] ?? '';
            if (!valid_key($key)) return mcp_text('invalid key', true);
            $m = get_memory($key);
            return $m === null ? mcp_text('not found', true) : mcp_text($enc($m));
        case 'put_memory':
            $key = $args['key'] ?? '';
            $content = $args['content'] ?? null;
            if (!valid_key($key)) return mcp_text('invalid key', true);
            if (!valid_content($content)) return mcp_text('invalid content', true);
            return mcp_text($enc(save_memory($key, $content)));
        case 'delete_memory':
            $key = $args['key'] ?? '';
            if (!valid_key($key)) return mcp_text('invalid key', true);
            return delete_memory($key) ? mcp_text('{"deleted":true}') : mcp_text('not found', true);
        case 'get_history':
            $key = $args['key'] ?? '';
            if (!valid_key($key)) return mcp_text('invalid key', true);
            return mcp_text($enc(get_history($key)));
        case 'restore_memory':
            $key = $args['key'] ?? '';
            $id = $args['id'] ?? null;
            if (!valid_key($key)) return mcp_text('invalid key', true);
            if (!is_int($id)) return mcp_text('invalid id', true);
            $m = restore_memory($key, $id);
            return $m === null ? mcp_text('not found', true) : mcp_text($enc($m));
        default:
            return null;
    }
}

function mcp_error($id, int $code, string $message): void {
    json_out(['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => $code, 'message' => $message]]);
}

function handle_mcp(): void {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        header('Allow: POST');
        json_out(['error' => 'method not allowed'], 405);
    }
    $msg = json_decode((string)file_get_contents('php://input'), true);
    if (!is_array($msg)) mcp_error(null, -32700, 'Parse error');
    if ($msg === [] || array_is_list($msg)) mcp_error(null, -32600, 'Invalid Request: batch not supported');

    $method = $msg['method'] ?? null;
    $id = $msg['id'] ?? null;
    // 通知はレスポンスを返さない
    if (!array_key_exists('id', $msg) || (is_string($method) && strpos($method, 'notifications/') === 0)) {
        http_response_code(202);
        exit;
    }
    if (!is_string($method)) mcp_error($id, -32600, 'Invalid Request');

    switch ($method) {
        case 'initialize':
            $supported = ['2025-06-18', '2025-03-26', '2024-11-05'];
            $requested = $msg['params']['protocolVersion'] ?? '';
            mcp_result($id, [
                'protocolVersion' => in_array($requested, $supported, true) ? $requested : '2025-06-18',
                'capabilities' => ['tools' => new stdClass],
                'serverInfo' => ['name' => 'ai-memory', 'version' => '1.0.0'],
                'instructions' => 'ユーザーの個人情報と作業状況の共有メモ。会話開始時に list_memories→必要なら get_memory。進捗があれば put_memory で更新。誤って上書き・削除したら get_history → restore_memory で戻せる',
            ]);
            break;
        case 'ping':
            mcp_result($id, new stdClass);
            break;
        case 'tools/list':
            mcp_result($id, ['tools' => mcp_tools()]);
            break;
        case 'tools/call':
            $name = $msg['params']['name'] ?? '';
            $args = $msg['params']['arguments'] ?? [];
            if (!is_array($args)) $args = [];
            $result = is_string($name) ? mcp_call_tool($name, $args) : null;
            if ($result === null) mcp_error($id, -32601, 'Method not found');
            mcp_result($id, $result);
            break;
        default:
            mcp_error($id, -32601, 'Method not found');
    }
}

function mcp_result($id, $result): void {
    json_out(['jsonrpc' => '2.0', 'id' => $id, 'result' => $result]);
}

// ---------- OpenAPI ----------
function send_openapi(string $base): void {
    $scheme = is_https() ? 'https' : 'http';
    $url = $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . $base;
    $memoryMeta = ['type' => 'object', 'required' => ['key', 'updated_at'],
        'properties' => ['key' => ['type' => 'string'], 'updated_at' => ['type' => 'string', 'description' => 'ISO8601形式の更新日時']]];
    $memory = ['type' => 'object', 'required' => ['key', 'content', 'updated_at'],
        'properties' => ['key' => ['type' => 'string'], 'content' => ['type' => 'string'], 'updated_at' => ['type' => 'string']]];
    $historyEntry = ['type' => 'object', 'required' => ['id', 'content', 'updated_at', 'archived_at'],
        'properties' => ['id' => ['type' => 'integer'], 'content' => ['type' => 'string'], 'updated_at' => ['type' => 'string'], 'archived_at' => ['type' => 'string']]];
    $keyParam = [['name' => 'key', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string'], 'description' => 'メモのキー']];
    json_out([
        'openapi' => '3.1.0',
        'info' => [
            'title' => 'AI Memory',
            'version' => '1.0.0',
            'description' => '複数のAIと人間が個人データ・作業状況を共有するための読み書き可能なメモAPI。会話開始時にまず GET /api/memories で一覧を取得してください。',
        ],
        'servers' => [['url' => $url]],
        'paths' => [
            '/api/memories' => [
                'get' => [
                    'operationId' => 'listMemories',
                    'description' => '保存されている全メモのキーと更新日時の一覧を返します。',
                    'responses' => ['200' => ['description' => 'メモ一覧', 'content' => ['application/json' => ['schema' => ['type' => 'array', 'items' => $memoryMeta]]]]],
                ],
                'post' => [
                    'operationId' => 'upsertMemory',
                    'description' => 'メモを新規作成または上書き保存します。同じキーが既にある場合は内容が置き換わります。',
                    'requestBody' => ['required' => true, 'content' => ['application/json' => ['schema' => [
                        'type' => 'object', 'required' => ['key', 'content'],
                        'properties' => ['key' => ['type' => 'string', 'description' => '英数字・._-、100文字以内'], 'content' => ['type' => 'string', 'description' => '保存する内容（1MB以内）']],
                    ]]]],
                    'responses' => ['200' => ['description' => '保存されたメモ', 'content' => ['application/json' => ['schema' => $memory]]], '400' => ['description' => 'キーまたは内容が不正']],
                ],
            ],
            '/api/memories/{key}' => [
                'get' => [
                    'operationId' => 'getMemory',
                    'description' => '指定したキーのメモの内容を取得します。',
                    'parameters' => [['name' => 'key', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string'], 'description' => 'メモのキー']],
                    'responses' => ['200' => ['description' => 'メモ', 'content' => ['application/json' => ['schema' => $memory]]], '404' => ['description' => 'メモが見つかりません']],
                ],
                'delete' => [
                    'operationId' => 'deleteMemory',
                    'description' => '指定したキーのメモを削除します。',
                    'parameters' => [['name' => 'key', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string'], 'description' => 'メモのキー']],
                    'responses' => ['200' => ['description' => '削除しました'], '404' => ['description' => 'メモが見つかりません']],
                ],
            ],
            '/api/memories/{key}/history' => [
                'get' => [
                    'operationId' => 'getMemoryHistory',
                    'description' => '指定したキーの過去の版の一覧（id付き・新しい順）を返します。上書き・削除前の内容を確認できます。',
                    'parameters' => $keyParam,
                    'responses' => ['200' => ['description' => '履歴一覧', 'content' => ['application/json' => ['schema' => ['type' => 'array', 'items' => $historyEntry]]]]],
                ],
            ],
            '/api/memories/{key}/restore' => [
                'post' => [
                    'operationId' => 'restoreMemory',
                    'description' => '指定した履歴idの内容でメモを復元します。現在の内容は履歴に自動保存されます。',
                    'parameters' => $keyParam,
                    'requestBody' => ['required' => true, 'content' => ['application/json' => ['schema' => [
                        'type' => 'object', 'required' => ['id'],
                        'properties' => ['id' => ['type' => 'integer', 'description' => 'getMemoryHistory で取得した履歴のid']],
                    ]]]],
                    'responses' => ['200' => ['description' => '復元されたメモ', 'content' => ['application/json' => ['schema' => $memory]]], '400' => ['description' => 'idが不正'], '404' => ['description' => '該当する履歴がありません']],
                ],
            ],
        ],
        'components' => [
            'securitySchemes' => ['ApiKeyAuth' => ['type' => 'apiKey', 'in' => 'header', 'name' => 'X-API-Key']],
        ],
        'security' => [['ApiKeyAuth' => []]],
    ]);
}

// ---------- /admin ----------
function h(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

function admin_history_html(string $key, string $csrf): string {
    $hist = get_history($key);
    $html = '<details class="hist"><summary>履歴 HISTORY (' . count($hist) . ')</summary>';
    foreach ($hist as $h) {
        $html .= '<div class="hist-item">'
            . '<small>archived: <span class="t">' . h($h['archived_at']) . '</span> / original: <span class="t">' . h($h['updated_at']) . '</span></small>'
            . '<pre>' . h($h['content']) . '</pre>'
            . '<form method="post" onsubmit="return confirm(\'この版に復元しますか？\')">'
            . '<input type="hidden" name="csrf" value="' . h($csrf) . '"><input type="hidden" name="action" value="restore">'
            . '<input type="hidden" name="key" value="' . h($key) . '"><input type="hidden" name="id" value="' . (int)$h['id'] . '">'
            . '<button type="submit" class="btn-restore">この版に復元 RESTORE</button></form></div>';
    }
    return $html . '</details>';
}

function admin_redirect(string $base): void {
    header('Location: ' . $base . '/admin', true, 303);
    exit;
}

function handle_admin(string $base): void {
    global $API_KEY;
    session_name('aimemory');
    session_set_cookie_params([
        'path' => $base === '' ? '/' : $base . '/',
        'httponly' => true,
        'samesite' => 'Lax',
        'secure' => is_https(),
    ]);
    session_start();
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        $csrf = $_POST['csrf'] ?? '';
        if (!is_string($csrf) || !hash_equals($_SESSION['csrf'], $csrf)) {
            http_response_code(403);
            exit('invalid csrf token');
        }
        $action = $_POST['action'] ?? '';
        if ($action === 'login') {
            $key = $_POST['api_key'] ?? '';
            if (is_string($key) && hash_equals($API_KEY, $key)) {
                session_regenerate_id(true);
                $_SESSION['auth'] = true;
            } else {
                $_SESSION['flash'] = 'APIキーが違います';
            }
            admin_redirect($base);
        }
        if (empty($_SESSION['auth'])) admin_redirect($base);
        switch ($action) {
            case 'save':
                $key = $_POST['key'] ?? '';
                $content = $_POST['content'] ?? '';
                if (!valid_key($key)) {
                    $_SESSION['flash'] = 'キーが不正です（英数字・._-、100文字以内）';
                } elseif (!valid_content($content)) {
                    $_SESSION['flash'] = '内容が不正です（1MB以内の文字列）';
                } else {
                    save_memory($key, $content);
                }
                break;
            case 'delete':
                $key = $_POST['key'] ?? '';
                if (valid_key($key)) delete_memory($key);
                break;
            case 'restore':
                $key = $_POST['key'] ?? '';
                $id = $_POST['id'] ?? '';
                if (valid_key($key) && is_string($id) && ctype_digit($id)
                    && restore_memory($key, (int)$id) === null) {
                    $_SESSION['flash'] = '履歴が見つかりません';
                }
                break;
            case 'logout':
                $_SESSION = [];
                session_destroy();
                break;
        }
        admin_redirect($base);
    }

    $flash = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    $csrf = $_SESSION['csrf'];
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html lang="ja"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>AI MEMORY</title><style>
:root{--orange:#ff7a00;--red:#e0301e;--green:#39ff6a;--dim:#8a8a8a}
*{box-sizing:border-box}
body{margin:0;min-height:100vh;color:#c9c9c9;font:15px/1.6 ui-monospace,Consolas,"Courier New",monospace;
background:radial-gradient(circle at 50% -10%,rgba(255,122,0,.07),transparent 55%),repeating-linear-gradient(0deg,transparent 0 39px,rgba(57,255,106,.028) 39px 40px),repeating-linear-gradient(90deg,transparent 0 39px,rgba(57,255,106,.028) 39px 40px),#0a0a0a}
body::after{content:"";position:fixed;inset:0;z-index:9999;pointer-events:none;background:repeating-linear-gradient(0deg,rgba(0,0,0,.15) 0 1px,transparent 1px 3px)}
.stripe{height:7px;background:repeating-linear-gradient(45deg,#ff7a00 0 14px,#0a0a0a 14px 28px)}
header{position:sticky;top:0;z-index:100;background:rgba(8,8,8,.94);border-bottom:1px solid #222}
.hdr-in{display:flex;justify-content:space-between;align-items:center;gap:14px;max-width:960px;margin:0 auto;padding:10px 16px;flex-wrap:wrap}
.brand{margin:0;color:var(--orange);font-family:"Hiragino Mincho ProN","Yu Mincho","Times New Roman",serif;font-weight:900;letter-spacing:.1em;line-height:1.05;font-size:clamp(24px,6vw,38px);transform:scaleX(.78);transform-origin:left center}
.brand-sm{display:block;color:var(--dim);font-size:10px;letter-spacing:.4em;margin-bottom:2px}
.hdr-right{display:flex;align-items:center;gap:14px}
.status{color:var(--green);font-size:13px;letter-spacing:.12em}
.status::before{content:"\\25C9";margin-right:6px;animation:blink 1.5s steps(2,start) infinite}
@keyframes blink{50%{opacity:.15}}
main{max-width:960px;margin:0 auto;padding:22px 16px 70px}
.flash{border:1px solid var(--red);background:rgba(224,48,30,.14);color:#ff9a90;padding:12px 16px;margin:0 0 20px}
.flash b{color:#ff7060;letter-spacing:.2em}
.fld{display:grid;gap:6px;color:var(--dim);font-size:11px;letter-spacing:.22em}
textarea,input[type=text],input[type=password],input[type=search]{width:100%;background:#070707;border:1px solid #2c2c2c;color:#e8e8e8;font:16px/1.5 ui-monospace,Consolas,monospace;padding:10px}
textarea:focus,input:focus{outline:2px solid var(--orange);outline-offset:1px}
button{cursor:pointer;font-family:inherit;font-weight:bold;letter-spacing:.18em;min-height:44px;padding:9px 20px;border-radius:0}
.btn-primary{background:var(--orange);border:2px solid var(--orange);color:#000}
.btn-danger{background:transparent;border:2px solid var(--red);color:#ff7060}
.btn-ghost{background:transparent;border:1px solid #3d3d3d;color:#c9c9c9}
.btn-restore{background:transparent;border:1px solid var(--orange);color:var(--orange);min-height:38px;font-size:12px}
button:focus-visible,summary:focus-visible{outline:2px solid var(--green);outline-offset:2px}
.panel,.mem{position:relative;background:#0f0f0f;border:1px solid #262626;clip-path:polygon(0 0,calc(100% - 16px) 0,100% 16px,100% 100%,16px 100%,0 calc(100% - 16px))}
.mem{margin-bottom:24px;padding-bottom:20px}
.mem::before{content:"";display:block;height:5px;background:repeating-linear-gradient(45deg,#ff7a00 0 12px,#0a0a0a 12px 24px)}
.mem.gone::before{background:repeating-linear-gradient(45deg,#e0301e 0 12px,#0a0a0a 12px 24px)}
.mem-head{display:flex;justify-content:space-between;align-items:baseline;gap:10px;flex-wrap:wrap;padding:14px 18px 4px}
.mem h3{margin:0;color:var(--orange);font-family:"Hiragino Mincho ProN","Yu Mincho","Times New Roman",serif;font-weight:900;font-size:21px;word-break:break-all}
.t{color:var(--green);font-size:12px}
form.pad{padding:0 18px;display:grid;gap:12px}
.btn-row{display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap}
details.hist{margin:10px 18px 0}
summary{cursor:pointer;padding:8px 0;color:var(--dim);letter-spacing:.12em}
summary:hover{color:var(--orange)}
.hist-item{border-top:1px dashed #2c2c2c;margin-top:8px;padding-top:10px}
pre{white-space:pre-wrap;word-break:break-word;background:#080808;border:1px solid #202020;color:#b5b5b5;font-size:13px;padding:10px;margin:8px 0;max-height:280px;overflow:auto}
.new-unit{border:1px solid var(--orange);background:#0f0f0f;margin-bottom:26px}
.new-unit>summary{padding:15px 18px;color:var(--orange);font-weight:bold;letter-spacing:.3em}
.new-form{padding:0 18px 20px;display:grid;gap:14px}
.toolbar{margin-bottom:20px}
.gone-h{color:var(--red);font-family:"Hiragino Mincho ProN","Yu Mincho","Times New Roman",serif;font-weight:900;letter-spacing:.12em;transform:scaleX(.85);transform-origin:left;border-bottom:1px solid var(--red);padding-bottom:8px;margin:34px 0 20px}
.login{min-height:100vh;display:grid;place-items:center;padding:24px}
.login-panel{width:min(440px,100%);text-align:center;padding-bottom:28px}
.login-panel .brand{transform:none;font-size:46px;margin:14px 0 2px}
.login-panel .sub{color:var(--dim);letter-spacing:.32em;font-size:10px;margin:0 0 22px}
.login-panel form{padding:0 28px;display:grid;gap:16px;text-align:left}
.login-panel .flash{margin:0 28px 16px}
@media (prefers-reduced-motion:reduce){*,*::before,*::after{animation:none!important;transition:none!important}}
</style></head><body>';

    if (empty($_SESSION['auth'])) {
        echo '<main class="login"><div class="panel login-panel"><div class="stripe"></div>
<span class="brand-sm">RESTRICTED ACCESS</span><h1 class="brand">AI MEMORY</h1>
<p class="sub">AUTHORIZATION REQUIRED</p>';
        if ($flash !== null) echo '<div class="flash"><b>WARNING</b> ' . h($flash) . '</div>';
        echo '<form method="post"><input type="hidden" name="csrf" value="' . h($csrf) . '"><input type="hidden" name="action" value="login">
<label class="fld" for="api_key"><span>API KEY</span><input type="password" id="api_key" name="api_key" required autocomplete="current-password"></label>
<button type="submit" class="btn-primary">LOGIN</button></form></div></main></body></html>';
        exit;
    }

    $mems = db()->query('SELECT key, content, updated_at FROM memories ORDER BY key')->fetchAll();
    echo '<header><div class="stripe"></div><div class="hdr-in"><div>
<span class="brand-sm">SHARED MEMORY SYSTEM</span><h1 class="brand">AI MEMORY</h1></div>
<div class="hdr-right"><span class="status">UNITS: ' . count($mems) . '</span>
<form method="post"><input type="hidden" name="csrf" value="' . h($csrf) . '"><input type="hidden" name="action" value="logout"><button type="submit" class="btn-ghost">LOGOUT</button></form></div></div></header>';
    echo '<main>';
    if ($flash !== null) echo '<div class="flash"><b>WARNING</b> ' . h($flash) . '</div>';
    echo '<details class="new-unit"><summary>+ NEW UNIT</summary>
<form method="post" class="new-form"><input type="hidden" name="csrf" value="' . h($csrf) . '"><input type="hidden" name="action" value="save">
<label class="fld"><span>KEY</span><input type="text" name="key" required pattern="[A-Za-z0-9_.\-]{1,100}" placeholder="my_key"></label>
<label class="fld"><span>CONTENT</span><textarea name="content" required rows="8"></textarea></label>
<button type="submit" class="btn-primary">CREATE</button></form></details>
<div class="toolbar"><label class="fld"><span>FILTER</span><input type="search" id="filter" placeholder="キーで絞り込み" autocomplete="off"></label></div>
<section id="list">';

    foreach ($mems as $m) {
        $rows = min(30, max(6, substr_count($m['content'], "\n") + 2));
        echo '<div class="mem" data-key="' . h($m['key']) . '">
<div class="mem-head"><h3>' . h($m['key']) . '</h3><span class="t">updated: ' . h($m['updated_at']) . '</span></div>
<form method="post" class="pad"><input type="hidden" name="csrf" value="' . h($csrf) . '"><input type="hidden" name="action" value="save"><input type="hidden" name="key" value="' . h($m['key']) . '">
<label class="fld"><span>CONTENT</span><textarea name="content" rows="' . $rows . '">' . h($m['content']) . '</textarea></label>
<div class="btn-row"><button type="submit" class="btn-primary">保存 SAVE</button>
<button type="submit" class="btn-danger" form="' . h('del-' . $m['key']) . '">削除 DELETE</button></div></form>
<form method="post" id="' . h('del-' . $m['key']) . '" onsubmit="return confirm(\'' . h($m['key']) . ' を削除しますか？\')"><input type="hidden" name="csrf" value="' . h($csrf) . '"><input type="hidden" name="action" value="delete"><input type="hidden" name="key" value="' . h($m['key']) . '"></form>'
        . admin_history_html($m['key'], $csrf) . '</div>';
    }
    echo '</section>';

    echo '<h2 class="gone-h">DELETED UNITS // 削除済み</h2>';
    $gone = db()->query('SELECT h.key AS k FROM memory_history h LEFT JOIN memories m ON m.key = h.key WHERE m.key IS NULL GROUP BY h.key ORDER BY h.key');
    foreach ($gone->fetchAll() as $g) {
        echo '<div class="mem gone"><div class="mem-head"><h3>' . h($g['k']) . '</h3></div>' . admin_history_html($g['k'], $csrf) . '</div>';
    }
    echo '</main>
<script>document.getElementById("filter").addEventListener("input",function(){var q=this.value.toLowerCase();document.querySelectorAll("#list .mem").forEach(function(c){c.style.display=c.dataset.key.toLowerCase().indexOf(q)===-1?"none":""})});</script>
</body></html>';
    exit;
}

// ---------- ルーティング ----------
if ($path === '/openapi.json') send_openapi($BASE);
if ($path === '/admin') handle_admin($BASE);

require_token();

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($path === '/' && $method === 'GET') {
    header('Content-Type: text/markdown; charset=utf-8');
    echo "# AI Memory\n";
    $st = db()->query('SELECT key, content, updated_at FROM memories ORDER BY key');
    foreach ($st->fetchAll() as $m) {
        echo '## ' . $m['key'] . "\n_updated: " . $m['updated_at'] . "_\n\n" . $m['content'] . "\n\n";
    }
    exit;
}

if ($path === '/api/memories') {
    if ($method === 'GET') json_out(list_memories());
    if ($method === 'POST') {
        $body = json_decode((string)file_get_contents('php://input'), true);
        if (!is_array($body)) json_out(['error' => 'invalid json'], 400);
        $key = $body['key'] ?? null;
        $content = $body['content'] ?? null;
        if (!valid_key($key)) json_out(['error' => 'invalid key'], 400);
        if (!valid_content($content)) json_out(['error' => 'invalid content'], 400);
        json_out(save_memory($key, $content));
    }
    header('Allow: GET, POST');
    json_out(['error' => 'method not allowed'], 405);
}

if (preg_match('#^/api/memories/([^/]+)(?:/(history|restore))?$#', $path, $m)) {
    $key = rawurldecode($m[1]);
    if (!valid_key($key)) json_out(['error' => 'invalid key'], 400);
    $sub = $m[2] ?? null;
    if ($sub === 'history') {
        if ($method === 'GET') json_out(get_history($key));
        header('Allow: GET');
        json_out(['error' => 'method not allowed'], 405);
    }
    if ($sub === 'restore') {
        if ($method === 'POST') {
            $body = json_decode((string)file_get_contents('php://input'), true);
            $id = is_array($body) ? ($body['id'] ?? null) : null;
            if (!is_int($id)) json_out(['error' => 'invalid id'], 400);
            $restored = restore_memory($key, $id);
            if ($restored === null) json_out(['error' => 'not found'], 404);
            json_out($restored);
        }
        header('Allow: POST');
        json_out(['error' => 'method not allowed'], 405);
    }
    if ($method === 'GET') {
        $m = get_memory($key);
        if ($m === null) json_out(['error' => 'not found'], 404);
        json_out($m);
    }
    if ($method === 'DELETE') {
        if (!delete_memory($key)) json_out(['error' => 'not found'], 404);
        json_out(['deleted' => true]);
    }
    header('Allow: GET, DELETE');
    json_out(['error' => 'method not allowed'], 405);
}

if ($path === '/mcp') handle_mcp();

json_out(['error' => 'not found'], 404);
