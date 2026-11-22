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
    $pdo->exec('CREATE TABLE IF NOT EXISTS memories (key TEXT PRIMARY KEY, content TEXT NOT NULL, updated_at TEXT NOT NULL, category TEXT)');
    // migrate.php 未適用の既存DB向けフォールバック（冪等）
    $cols = array_column($pdo->query('PRAGMA table_info(memories)')->fetchAll(), 'name');
    if (!in_array('category', $cols, true)) $pdo->exec('ALTER TABLE memories ADD COLUMN category TEXT');
    if (!in_array('updated_by', $cols, true)) $pdo->exec('ALTER TABLE memories ADD COLUMN updated_by TEXT');
    // shortcut: 履歴は無期限保持、DBが肥大化したら古い履歴の削除を追加
    $pdo->exec('CREATE TABLE IF NOT EXISTS memory_history (id INTEGER PRIMARY KEY AUTOINCREMENT, key TEXT NOT NULL, content TEXT NOT NULL, updated_at TEXT NOT NULL, archived_at TEXT NOT NULL)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS memory_history_key ON memory_history(key, id)');
    // 新記憶層（migrate.sql と同じ定義、冪等）
    $pdo->exec("CREATE TABLE IF NOT EXISTS raw_logs (id INTEGER PRIMARY KEY AUTOINCREMENT, content TEXT NOT NULL, source_ai TEXT, created_at TEXT NOT NULL DEFAULT (datetime('now', 'localtime')), processed_at TEXT)");
    $pdo->exec('CREATE TABLE IF NOT EXISTS semantic_memories (id INTEGER PRIMARY KEY AUTOINCREMENT, summary TEXT NOT NULL, category TEXT, tags TEXT, importance INTEGER NOT NULL DEFAULT 3, embedding_json TEXT, updated_at TEXT)');
    $pdo->exec("CREATE TABLE IF NOT EXISTS periodic_digests (id INTEGER PRIMARY KEY AUTOINCREMENT, period_type TEXT NOT NULL, period_label TEXT NOT NULL, digest_text TEXT NOT NULL, created_at TEXT NOT NULL DEFAULT (datetime('now', 'localtime')))");
    // OAuth 2.0 (Authorization Code + PKCE) 用テーブル
    $pdo->exec('CREATE TABLE IF NOT EXISTS oauth_clients (client_id TEXT PRIMARY KEY, client_name TEXT, redirect_uris TEXT NOT NULL, created_at TEXT NOT NULL)');
    $pdo->exec('CREATE TABLE IF NOT EXISTS oauth_codes (code TEXT PRIMARY KEY, client_id TEXT NOT NULL, redirect_uri TEXT NOT NULL, code_challenge TEXT, expires_at TEXT NOT NULL)');
    $pdo->exec('CREATE TABLE IF NOT EXISTS oauth_tokens (token TEXT PRIMARY KEY, client_id TEXT, created_at TEXT NOT NULL)');
    // アクセスログ・操作ログ・MCPセッション（actor 判定用）
    $pdo->exec('CREATE TABLE IF NOT EXISTS mcp_sessions (session_id TEXT PRIMARY KEY, client_name TEXT, client_version TEXT, created_at TEXT NOT NULL)');
    $pdo->exec('CREATE TABLE IF NOT EXISTS access_log (id INTEGER PRIMARY KEY AUTOINCREMENT, at TEXT NOT NULL, method TEXT, path TEXT, status INTEGER, actor TEXT, ua TEXT, ip TEXT)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS access_log_at ON access_log(at)');
    $pdo->exec('CREATE TABLE IF NOT EXISTS ops_log (id INTEGER PRIMARY KEY AUTOINCREMENT, at TEXT NOT NULL, actor TEXT, action TEXT NOT NULL, key TEXT, detail TEXT)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS ops_log_at ON ops_log(at)');
    return $pdo;
}

function valid_key($key): bool {
    return is_string($key) && preg_match('/^[A-Za-z0-9_.\-]{1,100}$/', $key) === 1;
}

function valid_content($content): bool {
    return is_string($content) && strlen($content) <= 1048576;
}

function list_memories(): array {
    return db()->query('SELECT key, category, updated_at, updated_by FROM memories ORDER BY key')->fetchAll();
}

function get_memory(string $key): ?array {
    $st = db()->prepare('SELECT key, content, updated_at, updated_by FROM memories WHERE key = ?');
    $st->execute([$key]);
    $row = $st->fetch();
    return $row === false ? null : $row;
}

// $category 省略(null)時は既存の category を維持し、新規なら 'general'
function save_memory(string $key, string $content, ?string $category = null): array {
    $now = date('c');
    $pdo = db();
    $pdo->beginTransaction();
    // 変更前の行を履歴に退避（内容が同じなら退避しない。categoryのみの変更も退避される）
    $pdo->prepare('INSERT INTO memory_history (key, content, updated_at, archived_at)
        SELECT key, content, updated_at, ? FROM memories WHERE key = ? AND content <> ?')
        ->execute([$now, $key, $content]);
    if ($category === null) {
        $pdo->prepare('INSERT INTO memories (key, content, updated_at, category, updated_by) VALUES (?, ?, ?, ?, ?)
            ON CONFLICT(key) DO UPDATE SET content = excluded.content, updated_at = excluded.updated_at, updated_by = excluded.updated_by')
            ->execute([$key, $content, $now, 'general', actor()]);
    } else {
        $pdo->prepare('INSERT INTO memories (key, content, updated_at, category, updated_by) VALUES (?, ?, ?, ?, ?)
            ON CONFLICT(key) DO UPDATE SET content = excluded.content, updated_at = excluded.updated_at, category = excluded.category, updated_by = excluded.updated_by')
            ->execute([$key, $content, $now, $category, actor()]);
    }
    $pdo->commit();
    ops('kv_set', $key, 'category=' . ($category ?? memory_category($key) ?? ''));
    return ['key' => $key, 'content' => $content, 'updated_at' => $now];
}

// 保存後に確定した category を取得する（kv_set の返り値用）
function memory_category(string $key): ?string {
    $st = db()->prepare('SELECT category FROM memories WHERE key = ?');
    $st->execute([$key]);
    $cat = $st->fetchColumn();
    return $cat === false ? null : (is_string($cat) ? $cat : null);
}

function add_memory_log(string $content, ?string $source_ai): int {
    if ($source_ai === null || trim($source_ai) === '') {
        // shortcut: User-Agent の先頭トークンを AI 名代わりに使う。精度が必要になったらマッピング表に置き換え
        $ua = trim((string)($_SERVER['HTTP_USER_AGENT'] ?? ''));
        $first = $ua === '' ? '' : (preg_split('#[\s/;,()]+#', $ua)[0] ?? '');
        $source_ai = $first === '' ? 'unknown' : $first;
    }
    $pdo = db();
    $pdo->prepare('INSERT INTO raw_logs (content, source_ai) VALUES (?, ?)')
        ->execute([$content, mb_substr(trim($source_ai), 0, 100)]);
    ops('add_log', null, 'source_ai=' . $source_ai);
    return (int)$pdo->lastInsertId();
}

// Embedding 未実装のため summary/tags の LIKE キーワード検索のみ
function recall_memories(string $query, int $limit = 5): array {
    $limit = max(1, min(20, $limit));
    $words = array_values(array_filter(
        preg_split('/\s+/u', trim($query)) ?: [],
        fn($w) => $w !== ''
    ));
    if ($words === []) return [];
    $where = [];
    $params = [];
    foreach (array_slice($words, 0, 10) as $w) {
        $where[] = "(summary LIKE ? ESCAPE '\\' OR tags LIKE ? ESCAPE '\\')";
        $p = '%' . addcslashes($w, '\\%_') . '%';
        $params[] = $p;
        $params[] = $p;
    }
    $st = db()->prepare('SELECT id, summary, category, tags, importance, updated_at FROM semantic_memories
        WHERE ' . implode(' OR ', $where) . ' ORDER BY importance DESC, updated_at DESC LIMIT ' . $limit);
    $st->execute($params);
    return $st->fetchAll();
}

function get_recent_digests(?string $period_type, int $limit = 5): array {
    $limit = max(1, min(100, $limit));
    if ($period_type === null) {
        return db()->query('SELECT id, period_type, period_label, digest_text, created_at FROM periodic_digests
            ORDER BY created_at DESC LIMIT ' . $limit)->fetchAll();
    }
    $st = db()->prepare('SELECT id, period_type, period_label, digest_text, created_at FROM periodic_digests
        WHERE period_type = ? ORDER BY created_at DESC LIMIT ' . $limit);
    $st->execute([$period_type]);
    return $st->fetchAll();
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
    if ($deleted) ops('kv_delete', $key, null);
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
    $saved = save_memory($key, $row['content']);
    ops('restore', $key, 'id=' . $id);
    return $saved;
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
    global $API_KEY, $OAUTH_CLIENT_ID, $AUTHED;
    $token = request_token();
    if ($token !== null) {
        if (hash_equals($API_KEY, $token)) { $AUTHED = true; return; }
        // OAuth で発行したアクセストークンも有効（actor 判定用に client_id を覚える）
        $st = db()->prepare('SELECT client_id FROM oauth_tokens WHERE token = ?');
        $st->execute([$token]);
        $row = $st->fetch();
        if ($row !== false) { $OAUTH_CLIENT_ID = $row['client_id']; $AUTHED = true; return; }
    }
    header('WWW-Authenticate: Bearer resource_metadata="' . origin() . '/.well-known/oauth-protected-resource"');
    json_out(['error' => 'unauthorized'], 401);
}

// ---------- MCP ----------
function mcp_tools(): array {
    return [
        [
            'name' => 'kv_list',
            'description' => '会話開始時の把握用。確定記憶のキー一覧（key・category・updated_at）を返します。まずこれで全体を俯瞰し、必要なキーは kv_get で取得してください',
            'inputSchema' => ['type' => 'object', 'properties' => new stdClass],
        ],
        [
            'name' => 'kv_get',
            'description' => '確定記憶の値を取得します。key 指定で1件（key・value・category・updated_at）、category 指定でそのカテゴリの全件配列を返します。書き込みは kv_set、曖昧検索は recall_memories を使ってください',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'key' => ['type' => 'string', 'description' => '取得するキー'],
                    'category' => ['type' => 'string', 'description' => 'このカテゴリの全件を取得（key と併用不可・key 優先）'],
                ],
                'additionalProperties' => false,
            ],
        ],
        [
            'name' => 'kv_set',
            'description' => '確定した事実・設定・プロジェクト状態など長期保持する情報を保存します。同じキーは上書き（旧内容は履歴に自動保存されRESTで復元可能）。その場限りのメモや好みは add_memory_log を使ってください',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'key' => ['type' => 'string', 'description' => 'キー（英数字・._-、100文字以内）'],
                    'value' => ['type' => 'string', 'description' => '保存する内容'],
                    'category' => ['type' => 'string', 'description' => '分類カテゴリ（100文字以内。省略時は既存維持・新規は general）'],
                ],
                'required' => ['key', 'value'],
                'additionalProperties' => false,
            ],
        ],
        [
            'name' => 'kv_delete',
            'description' => '確定記憶を削除します。削除前の内容は履歴に自動保存され、REST 経由で復元可能です',
            'inputSchema' => [
                'type' => 'object',
                'properties' => ['key' => ['type' => 'string', 'description' => '削除するキー']],
                'required' => ['key'],
                'additionalProperties' => false,
            ],
        ],
        [
            'name' => 'add_memory_log',
            'description' => 'ちょっとした出来事・好み・文脈メモを即記録。後でバッチが長期記憶に整理します。確定済みの事実や設定は kv_set に保存してください',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'content' => ['type' => 'string', 'description' => '記録するメモ内容'],
                    'source_ai' => ['type' => 'string', 'description' => '記録したAI名（省略時は User-Agent から推定）'],
                ],
                'required' => ['content'],
                'additionalProperties' => false,
            ],
        ],
        [
            'name' => 'recall_memories',
            'description' => 'エピソード・好みなど曖昧な記憶を検索します。現在はキーワード検索（ベクトル検索は未実装）。query を空白区切りの各語で OR 検索し importance 順で返します。確定記憶は kv_list / kv_get を使ってください',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'query' => ['type' => 'string', 'description' => '検索語（空白区切りでOR検索）'],
                    'limit' => ['type' => 'integer', 'description' => '最大件数（デフォルト5・上限20）'],
                ],
                'required' => ['query'],
                'additionalProperties' => false,
            ],
        ],
        [
            'name' => 'get_recent_digests',
            'description' => '直近の日次/週次要約を返します。近況の俯瞰・前回までの流れの把握に',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'period_type' => ['type' => 'string', 'description' => '絞り込み（daily / weekly など）'],
                    'limit' => ['type' => 'integer', 'description' => '最大件数（デフォルト5）'],
                ],
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
        case 'kv_list':
            return mcp_text($enc(db()->query('SELECT key, category, updated_at, updated_by FROM memories ORDER BY key')->fetchAll()));
        case 'kv_get':
            $key = $args['key'] ?? null;
            $cat = $args['category'] ?? null;
            if (is_string($key) && $key !== '') {
                if (!valid_key($key)) return mcp_text('invalid key', true);
                $st = db()->prepare('SELECT key, content AS value, category, updated_at, updated_by FROM memories WHERE key = ?');
                $st->execute([$key]);
                $row = $st->fetch();
                return $row === false ? mcp_text('not found', true) : mcp_text($enc($row));
            }
            if (is_string($cat) && $cat !== '') {
                $st = db()->prepare('SELECT key, content AS value, category, updated_at FROM memories WHERE category = ? ORDER BY key');
                $st->execute([$cat]);
                return mcp_text($enc($st->fetchAll()));
            }
            return mcp_text('key または category のどちらかを指定してください', true);
        case 'kv_set':
            $key = $args['key'] ?? '';
            $value = $args['value'] ?? null;
            $cat = $args['category'] ?? null;
            if (!valid_key($key)) return mcp_text('invalid key', true);
            if (!valid_content($value)) return mcp_text('invalid value', true);
            if ($cat !== null && (!is_string($cat) || strlen($cat) > 100)) return mcp_text('invalid category', true);
            if ($cat === '') $cat = null;
            $saved = save_memory($key, $value, $cat);
            return mcp_text($enc(['key' => $saved['key'], 'value' => $saved['content'],
                'category' => $cat ?? memory_category($key), 'updated_at' => $saved['updated_at']]));
        case 'kv_delete':
            $key = $args['key'] ?? '';
            if (!valid_key($key)) return mcp_text('invalid key', true);
            return delete_memory($key) ? mcp_text('{"deleted":true}') : mcp_text('not found', true);
        case 'add_memory_log':
            $content = $args['content'] ?? null;
            if (!valid_content($content)) return mcp_text('invalid content', true);
            $src = $args['source_ai'] ?? null;
            if ($src !== null && !is_string($src)) return mcp_text('invalid source_ai', true);
            return mcp_text($enc(['id' => add_memory_log($content, $src)]));
        case 'recall_memories':
            $q = $args['query'] ?? null;
            if (!is_string($q) || trim($q) === '') return mcp_text('invalid query', true);
            $limit = $args['limit'] ?? 5;
            if (!is_int($limit)) $limit = is_numeric($limit) ? (int)$limit : 5;
            return mcp_text($enc(recall_memories($q, $limit)));
        case 'get_recent_digests':
            $pt = $args['period_type'] ?? null;
            $limit = $args['limit'] ?? 5;
            if (!is_int($limit)) $limit = is_numeric($limit) ? (int)$limit : 5;
            return mcp_text($enc(get_recent_digests(is_string($pt) && $pt !== '' ? $pt : null, $limit)));
        default:
            return null;
    }
}

function mcp_error($id, int $code, string $message): void {
    json_out(['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => $code, 'message' => $message]]);
}

function handle_mcp(): void {
    $reqMethod = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if ($reqMethod === 'GET') {
        // GETはSSE受信ストリーム（server-initiated messages）。短く保持して切断し、
        // クライアントの自動再接続に任せる（共有サーバーで長時間占有できないため）
        header('Content-Type: text/event-stream; charset=utf-8');
        header('Cache-Control: no-cache');
        header('X-Accel-Buffering: no');
        if (function_exists('apache_setenv')) @apache_setenv('no-gzip', '1');
        while (ob_get_level() > 0) ob_end_clean();
        echo ": stream open\n\n";
        flush();
        for ($i = 0; $i < 4; $i++) {
            sleep(5);
            if (connection_aborted()) exit;
            echo ": keep-alive\n\n";
            flush();
        }
        exit;
    }
    if ($reqMethod !== 'POST') {
        header('Allow: GET, POST');
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
            // セッションIDは返すが検証はしない（ステートレス）。clientInfo は actor 判定用に保存
            $sid = bin2hex(random_bytes(16));
            header('Mcp-Session-Id: ' . $sid);
            $ci = $msg['params']['clientInfo'] ?? null;
            $cname = is_array($ci) && isset($ci['name']) && is_string($ci['name']) && $ci['name'] !== ''
                ? mb_substr($ci['name'], 0, 100) : 'unknown';
            $cver = is_array($ci) && isset($ci['version']) && is_string($ci['version'])
                ? mb_substr($ci['version'], 0, 50) : null;
            try {
                db()->prepare('INSERT INTO mcp_sessions (session_id, client_name, client_version, created_at) VALUES (?, ?, ?, ?)')
                    ->execute([$sid, $cname, $cver, date('c')]);
            } catch (Throwable $e) {}
            $supported = ['2025-06-18', '2025-03-26', '2024-11-05'];
            $requested = $msg['params']['protocolVersion'] ?? '';
            // 指示はメモリから動的に組み立て: instructions=全AI共通、instructions.<client名小文字>=個別
            $inst = '複数のAIで共有する個人メモリ。確定事実はkv_*、その場限りのメモや好み・エピソードはadd_memory_log、曖昧検索はrecall_memories、近況俯瞰はget_recent_digests。上書き・削除は履歴に自動保存され、REST経由で復元可能。操作履歴はops_logに自動記録';
            $g = get_memory('instructions');
            if ($g !== null) $inst .= "\n\n## 全AI共通の指示\n" . $g['content'];
            $ckey = 'instructions.' . preg_replace('/[^a-z0-9_.-]+/', '-', strtolower(trim($cname)));
            $c = get_memory($ckey);
            if ($c !== null) $inst .= "\n\n## " . $cname . " 専用の指示\n" . $c['content'];
            mcp_result($id, [
                'protocolVersion' => in_array($requested, $supported, true) ? $requested : '2025-06-18',
                'capabilities' => ['tools' => new stdClass],
                'serverInfo' => ['name' => 'ai-memory', 'version' => '1.0.0'],
                'instructions' => $inst,
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

function admin_history_html(string $key, string $csrf, string $page = ''): string {
    $hist = get_history($key);
    $html = '<details class="hist"><summary>履歴 HISTORY (' . count($hist) . ')</summary>';
    foreach ($hist as $h) {
        $html .= '<div class="hist-item">'
            . '<small>archived: <span class="t">' . h($h['archived_at']) . '</span> / original: <span class="t">' . h($h['updated_at']) . '</span></small>'
            . '<pre>' . h($h['content']) . '</pre>'
            . '<form method="post" onsubmit="return confirm(\'この版に復元しますか？\')">'
            . '<input type="hidden" name="csrf" value="' . h($csrf) . '"><input type="hidden" name="action" value="restore">'
            . '<input type="hidden" name="key" value="' . h($key) . '"><input type="hidden" name="id" value="' . (int)$h['id'] . '">'
            . ($page !== '' ? '<input type="hidden" name="page" value="' . h($page) . '">' : '')
            . '<button type="submit" class="btn-restore">この版に復元 RESTORE</button></form></div>';
    }
    return $html . '</details>';
}

function admin_redirect(string $base, string $page = ''): void {
    header('Location: ' . $base . '/admin' . ($page !== '' ? '?page=' . rawurlencode($page) : ''), true, 303);
    exit;
}

function handle_admin(string $base): void {
    global $API_KEY, $ACTOR;
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
        $ACTOR = 'admin';
        $action = $_POST['action'] ?? '';
        if ($action === 'login') {
            $key = $_POST['api_key'] ?? '';
            if (is_string($key) && hash_equals($API_KEY, $key)) {
                session_regenerate_id(true);
                $_SESSION['auth'] = true;
                ops('admin_login', null, null);
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
        $ret = in_array($_POST['page'] ?? '', ['history', 'logs'], true) ? (string)$_POST['page'] : '';
        admin_redirect($base, $ret);
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
.tabs{display:flex;gap:2px;max-width:960px;margin:0 auto;padding:0 16px}
.tabs a{display:block;padding:9px 18px;color:var(--dim);text-decoration:none;letter-spacing:.18em;font-size:12px;border-top:2px solid transparent}
.tabs a.on{color:var(--orange);border-top-color:var(--orange);background:#0f0f0f}
.log-h{color:var(--orange);letter-spacing:.2em;font-size:13px;margin:26px 0 10px}
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

    $page = in_array($_GET['page'] ?? '', ['history', 'logs'], true) ? (string)$_GET['page'] : '';
    $mems = db()->query('SELECT key, content, updated_at, updated_by FROM memories ORDER BY key')->fetchAll();
    echo '<header><div class="stripe"></div><div class="hdr-in"><div>
<span class="brand-sm">SHARED MEMORY SYSTEM</span><h1 class="brand">AI MEMORY</h1></div>
<div class="hdr-right"><span class="status">UNITS: ' . count($mems) . '</span>
<form method="post"><input type="hidden" name="csrf" value="' . h($csrf) . '"><input type="hidden" name="action" value="logout"><button type="submit" class="btn-ghost">LOGOUT</button></form></div></div>
<nav class="tabs"><a href="' . h($base) . '/admin"' . ($page === '' ? ' class="on"' : '') . '>UNITS</a><a href="' . h($base) . '/admin?page=history"' . ($page === 'history' ? ' class="on"' : '') . '>HISTORY</a><a href="' . h($base) . '/admin?page=logs"' . ($page === 'logs' ? ' class="on"' : '') . '>LOGS</a></nav></header>';
    echo '<main>';
    if ($flash !== null) echo '<div class="flash"><b>WARNING</b> ' . h($flash) . '</div>';

    if ($page === 'history') {
        echo '<h2 class="gone-h">DELETED UNITS // 削除済み</h2>';
        $gone = db()->query('SELECT h.key AS k FROM memory_history h LEFT JOIN memories m ON m.key = h.key WHERE m.key IS NULL GROUP BY h.key ORDER BY h.key');
        foreach ($gone->fetchAll() as $g) {
            echo '<div class="mem gone"><div class="mem-head"><h3>' . h($g['k']) . '</h3></div>' . admin_history_html($g['k'], $csrf, 'history') . '</div>';
        }
    } elseif ($page === 'logs') {
        $clients = db()->query('SELECT client_name, COUNT(*) n, MAX(created_at) last FROM mcp_sessions GROUP BY client_name ORDER BY last DESC')->fetchAll();
        echo '<h2 class="log-h">CONNECTED CLIENTS（instructions.&lt;client&gt; キー名の参考）</h2><pre>';
        foreach ($clients as $c) {
            $norm = preg_replace('/[^a-z0-9_.-]+/', '-', strtolower(trim((string)$c['client_name'])));
            echo h($c['client_name'] . ' -> instructions.' . $norm . '  (' . $c['n'] . ' sessions, last ' . $c['last'] . ')') . "\n";
        }
        echo '</pre>';
        $opsRows = db()->query('SELECT at, actor, action, key, detail FROM ops_log ORDER BY id DESC LIMIT 100')->fetchAll();
        $opsTotal = (int)db()->query('SELECT COUNT(*) FROM ops_log')->fetchColumn();
        echo '<h2 class="log-h">操作ログ OPS (' . $opsTotal . ')</h2><pre>';
        foreach ($opsRows as $r) {
            echo h($r['at'] . ' | ' . $r['actor'] . ' | ' . $r['action'] . ' | ' . $r['key'] . ' | ' . $r['detail']) . "\n";
        }
        echo '</pre>';
        $accRows = db()->query('SELECT at, method, path, status, actor, ua FROM access_log ORDER BY id DESC LIMIT 100')->fetchAll();
        $accTotal = (int)db()->query('SELECT COUNT(*) FROM access_log')->fetchColumn();
        echo '<h2 class="log-h">アクセスログ ACCESS (' . $accTotal . ')</h2><pre>';
        foreach ($accRows as $r) {
            echo h($r['at'] . ' | ' . $r['method'] . ' | ' . $r['path'] . ' | ' . $r['status'] . ' | ' . $r['actor'] . ' | ' . $r['ua']) . "\n";
        }
        echo '</pre>';
    } else {
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
<div class="mem-head"><h3>' . h($m['key']) . '</h3><span class="t">updated: ' . h($m['updated_at'])
        . (isset($m['updated_by']) && $m['updated_by'] !== '' ? ' by ' . h($m['updated_by']) : '') . '</span></div>
<form method="post" class="pad"><input type="hidden" name="csrf" value="' . h($csrf) . '"><input type="hidden" name="action" value="save"><input type="hidden" name="key" value="' . h($m['key']) . '">
<label class="fld"><span>CONTENT</span><textarea name="content" rows="' . $rows . '">' . h($m['content']) . '</textarea></label>
<div class="btn-row"><button type="submit" class="btn-primary">保存 SAVE</button>
<button type="submit" class="btn-danger" form="' . h('del-' . $m['key']) . '">削除 DELETE</button></div></form>
<form method="post" id="' . h('del-' . $m['key']) . '" onsubmit="return confirm(\'' . h($m['key']) . ' を削除しますか？\')"><input type="hidden" name="csrf" value="' . h($csrf) . '"><input type="hidden" name="action" value="delete"><input type="hidden" name="key" value="' . h($m['key']) . '"></form>'
        . admin_history_html($m['key'], $csrf) . '</div>';
    }
    echo '</section>';
    }

    echo '</main>';
    if ($page === '') echo '<script>var f=document.getElementById("filter");if(f)f.addEventListener("input",function(){var q=this.value.toLowerCase();document.querySelectorAll("#list .mem").forEach(function(c){c.style.display=c.dataset.key.toLowerCase().indexOf(q)===-1?"none":""})});</script>';
    echo '</body></html>';
    exit;
}

// ---------- OAuth 2.0 (Authorization Code + PKCE) ----------
function origin(): string {
    return (is_https() ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
}

// 同意画面・エラー画面の共通HTML（adminと同系の暗いテーマ・最小CSS）
function oauth_page(string $title, string $body): void {
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html lang="ja"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>' . h($title) . ' | AI MEMORY</title><style>
body{margin:0;min-height:100vh;display:grid;place-items:center;padding:24px;color:#c9c9c9;font:15px/1.6 ui-monospace,Consolas,"Courier New",monospace;
background:radial-gradient(circle at 50% -10%,rgba(255,122,0,.07),transparent 55%),#0a0a0a}
.panel{width:min(440px,100%);background:#0f0f0f;border:1px solid #262626;padding-bottom:26px}
.stripe{height:7px;background:repeating-linear-gradient(45deg,#ff7a00 0 14px,#0a0a0a 14px 28px);margin-bottom:22px}
h1{margin:0;color:#ff7a00;font-size:22px;letter-spacing:.1em;text-align:center}
.sub{margin:2px 0 18px;color:#8a8a8a;font-size:10px;letter-spacing:.3em;text-align:center}
.inner{display:grid;gap:14px;padding:0 28px}
.kv{font-size:11px;letter-spacing:.22em;color:#8a8a8a}.kv div{color:#39ff6a;font-size:14px;letter-spacing:0;word-break:break-all;margin-top:2px}
label{display:grid;gap:6px;font-size:11px;letter-spacing:.22em;color:#8a8a8a}
input{background:#070707;border:1px solid #2c2c2c;color:#e8e8e8;font:16px/1.5 ui-monospace,Consolas,monospace;padding:10px}
input:focus{outline:2px solid #ff7a00}
button{cursor:pointer;background:#ff7a00;border:2px solid #ff7a00;color:#000;font:inherit;font-weight:bold;letter-spacing:.18em;min-height:44px}
.flash{border:1px solid #e0301e;background:rgba(224,48,30,.14);color:#ff9a90;padding:10px 14px;font-size:13px}
</style></head><body><main class="panel"><div class="stripe"></div><h1>AI MEMORY</h1><p class="sub">' . h($title) . '</p>' . $body . '</main></body></html>';
    exit;
}

// パラメータ不正時はエラー画面を返す（redirect_uri へのリダイレクトは行わない）
function oauth_error(string $msg, int $code = 400): void {
    http_response_code($code);
    oauth_page('ERROR', '<div class="inner"><div class="flash">' . h($msg) . '</div></div>');
}

// 登録済みクライアントは登録 redirect_uris に完全一致、未登録は許可ホストのみ
function oauth_redirect_allowed(string $client_id, string $redirect_uri): bool {
    $st = db()->prepare('SELECT redirect_uris FROM oauth_clients WHERE client_id = ?');
    $st->execute([$client_id]);
    $row = $st->fetch();
    if ($row !== false) {
        $uris = json_decode((string)$row['redirect_uris'], true);
        return is_array($uris) && in_array($redirect_uri, $uris, true);
    }
    $host = parse_url($redirect_uri, PHP_URL_HOST);
    return is_string($host) && in_array(strtolower($host), ['gemini.google.com', 'accounts.google.com', 'oauth-redirect.googleusercontent.com', 'chatgpt.com', 'chat.openai.com', 'claude.ai', 'localhost', '127.0.0.1'], true);
}

function handle_oauth_authorize(): void {
    global $API_KEY;
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if ($method !== 'GET' && $method !== 'POST') {
        header('Allow: GET, POST');
        json_out(['error' => 'method not allowed'], 405);
    }
    $p = $method === 'POST' ? $_POST : $_GET;
    $get = static function (string $k) use ($p): ?string {
        $v = $p[$k] ?? null;
        return is_string($v) && $v !== '' ? $v : null;
    };
    $client_id = $get('client_id');
    $redirect_uri = $get('redirect_uri');
    $state = $get('state');
    $challenge = $get('code_challenge');
    $ccm = $get('code_challenge_method');

    if ($get('response_type') !== 'code') oauth_error('response_type must be "code"');
    if ($client_id === null || $redirect_uri === null) oauth_error('client_id and redirect_uri are required');
    // PKCE は S256 のみ。method 省略は RFC7636 上 plain 扱いのため challenge がある場合も必須
    if ($ccm !== 'S256' && ($ccm !== null || $challenge !== null)) oauth_error('code_challenge_method must be S256');
    if (!oauth_redirect_allowed($client_id, $redirect_uri)) oauth_error('redirect_uri is not allowed');

    $hidden = '';
    foreach (['response_type' => 'code', 'client_id' => $client_id, 'redirect_uri' => $redirect_uri,
              'state' => $state, 'code_challenge' => $challenge, 'code_challenge_method' => $ccm] as $k => $v) {
        if ($v !== null) $hidden .= '<input type="hidden" name="' . h($k) . '" value="' . h($v) . '">';
    }
    $host = parse_url($redirect_uri, PHP_URL_HOST);
    $info = '<div class="inner"><div class="kv">CLIENT<div>' . h($client_id) . '</div></div>'
        . '<div class="kv">REDIRECT HOST<div>' . h(is_string($host) ? $host : $redirect_uri) . '</div></div></div>';
    $form = static function (?string $err) use ($hidden, $info): string {
        return $info . ($err === null ? '' : '<div class="inner"><div class="flash">' . h($err) . '</div></div>')
            . '<form method="post" class="inner">' . $hidden
            . '<label>API KEY<input type="password" name="api_key" required autocomplete="current-password"></label>'
            . '<button type="submit">AUTHORIZE</button></form>';
    };

    if ($method === 'GET') oauth_page('AUTHORIZE', $form(null));

    // CSRF は不要（APIキー入力が実質の認証）
    $key = $p['api_key'] ?? null;
    if (!is_string($key) || !hash_equals($API_KEY, $key)) oauth_page('AUTHORIZE', $form('APIキーが違います'));

    $code = bin2hex(random_bytes(32));
    db()->prepare('INSERT INTO oauth_codes (code, client_id, redirect_uri, code_challenge, expires_at) VALUES (?, ?, ?, ?, ?)')
        ->execute([$code, $client_id, $redirect_uri, $challenge, date('c', time() + 600)]);
    ops('oauth_authorize', null, 'client_id=' . $client_id . ' redirect_host=' . (is_string($host) ? $host : ''));
    $sep = strpos($redirect_uri, '?') === false ? '?' : '&';
    $loc = $redirect_uri . $sep . 'code=' . rawurlencode($code);
    if ($state !== null) $loc .= '&state=' . rawurlencode($state);
    header('Location: ' . $loc, true, 302);
    exit;
}

function handle_oauth_token(): void {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        header('Allow: POST');
        json_out(['error' => 'method not allowed'], 405);
    }
    $p = $_POST;
    if ($p === []) {
        $j = json_decode((string)file_get_contents('php://input'), true);
        if (is_array($j)) $p = $j;
    }
    if (($p['grant_type'] ?? null) !== 'authorization_code') {
        json_out(['error' => 'unsupported_grant_type'], 400);
    }
    $code = $p['code'] ?? null;
    if (!is_string($code) || $code === '') json_out(['error' => 'invalid_grant'], 400);
    $st = db()->prepare('SELECT client_id, redirect_uri, code_challenge, expires_at FROM oauth_codes WHERE code = ?');
    $st->execute([$code]);
    $row = $st->fetch();
    // expires_at は date('c') 同士の文字列比較（同一タイムゾーン前提）
    $ok = $row !== false
        && $row['expires_at'] > date('c')
        && $row['client_id'] === ($p['client_id'] ?? null)
        && $row['redirect_uri'] === ($p['redirect_uri'] ?? null);
    if ($ok && $row['code_challenge'] !== null && $row['code_challenge'] !== '') {
        $verifier = $p['code_verifier'] ?? null;
        $ok = is_string($verifier) && hash_equals((string)$row['code_challenge'],
            rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='));
    }
    if (!$ok) json_out(['error' => 'invalid_grant'], 400);
    // 認証コードは使い回し禁止（検証成功後に削除）
    db()->prepare('DELETE FROM oauth_codes WHERE code = ?')->execute([$code]);
    $token = bin2hex(random_bytes(32));
    db()->prepare('INSERT INTO oauth_tokens (token, client_id, created_at) VALUES (?, ?, ?)')
        ->execute([$token, $row['client_id'], date('c')]);
    ops('oauth_token', null, 'client_id=' . $row['client_id']);
    // expires_in/refresh_token は省略（無期限扱い）
    json_out(['access_token' => $token, 'token_type' => 'Bearer']);
}

// RFC7591 最小実装の動的クライアント登録
function handle_oauth_register(): void {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        header('Allow: POST');
        json_out(['error' => 'method not allowed'], 405);
    }
    $body = json_decode((string)file_get_contents('php://input'), true);
    $uris = is_array($body) ? ($body['redirect_uris'] ?? null) : null;
    if (!is_array($uris) || $uris === [] || array_filter($uris, fn($u) => !is_string($u) || $u === '') !== []) {
        json_out(['error' => 'invalid_client_metadata'], 400);
    }
    $name = isset($body['client_name']) && is_string($body['client_name']) ? $body['client_name'] : null;
    $client_id = bin2hex(random_bytes(16));
    db()->prepare('INSERT INTO oauth_clients (client_id, client_name, redirect_uris, created_at) VALUES (?, ?, ?, ?)')
        ->execute([$client_id, $name, json_encode(array_values($uris)), date('c')]);
    ops('oauth_register', null, 'client_id=' . $client_id . ' name=' . ($name ?? ''));
    json_out([
        'client_id' => $client_id,
        'client_name' => $name,
        'redirect_uris' => array_values($uris),
        'token_endpoint_auth_method' => 'none',
        'grant_types' => ['authorization_code'],
        'response_types' => ['code'],
    ], 201);
}

// アプリ配下パスの .well-known（ドメイン直下は別ファイルで配信）
function handle_oauth_metadata(string $base, string $which): void {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
        header('Allow: GET');
        json_out(['error' => 'method not allowed'], 405);
    }
    $origin = origin();
    if ($which === 'authorization-server') {
        json_out([
            'issuer' => $origin,
            'authorization_endpoint' => $origin . $base . '/oauth/authorize',
            'token_endpoint' => $origin . $base . '/oauth/token',
            'registration_endpoint' => $origin . $base . '/oauth/register',
            'response_types_supported' => ['code'],
            'grant_types_supported' => ['authorization_code'],
            'code_challenge_methods_supported' => ['S256'],
            'token_endpoint_auth_methods_supported' => ['none'],
        ]);
    }
    json_out([
        'resource' => $origin . $base . '/mcp',
        'authorization_servers' => [$origin],
        'bearer_methods_supported' => ['header'],
    ]);
}

// ---------- ログ（アクセス/操作/actor判定） ----------
$ACTOR = null;            // actor() の結果キャッシュ。handle_admin が 'admin' に上書きする
$OAUTH_CLIENT_ID = false; // OAuthトークン認証が通った時の client_id（APIキー認証・未認証では false のまま）
$AUTHED = false;          // require_token() 通過フラグ（access_log の 'noauth' 判定用）

// 操作者の推定: MCPセッション(clientInfo) > OAuthクライアント > User-Agent先頭トークン
function actor(): string {
    global $ACTOR, $OAUTH_CLIENT_ID;
    if (is_string($ACTOR) && $ACTOR !== '') return $ACTOR;
    $sid = $_SERVER['HTTP_MCP_SESSION_ID'] ?? '';
    if ($sid !== '') {
        try {
            $st = db()->prepare('SELECT client_name FROM mcp_sessions WHERE session_id = ?');
            $st->execute([$sid]);
            $name = $st->fetchColumn();
            if (is_string($name) && $name !== '') return $ACTOR = $name;
        } catch (Throwable $e) {}
    }
    if ($OAUTH_CLIENT_ID !== false) {
        if (is_string($OAUTH_CLIENT_ID) && $OAUTH_CLIENT_ID !== '') {
            try {
                $st = db()->prepare('SELECT client_name FROM oauth_clients WHERE client_id = ?');
                $st->execute([$OAUTH_CLIENT_ID]);
                $name = $st->fetchColumn();
                if (is_string($name) && $name !== '') return $ACTOR = $name;
            } catch (Throwable $e) {}
            return $ACTOR = 'oauth:' . substr($OAUTH_CLIENT_ID, 0, 8);
        }
        return $ACTOR = 'oauth';
    }
    $ua = trim((string)($_SERVER['HTTP_USER_AGENT'] ?? ''));
    $first = $ua === '' ? '' : (preg_split('#[\s/]#', $ua)[0] ?? '');
    return $ACTOR = $first === '' ? 'manual' : $first;
}

// 操作ログ記録（ログ失敗で本体処理を止めない）
function ops(string $action, ?string $key = null, ?string $detail = null): void {
    try {
        db()->prepare('INSERT INTO ops_log (at, actor, action, key, detail) VALUES (?, ?, ?, ?, ?)')
            ->execute([date('c'), actor(), $action, $key, $detail]);
    } catch (Throwable $e) {}
}

// 全リクエストのアクセスログ（終了時に記録。$path はクエリ除去済みで ?token= 等は入らない。ログ用DBエラーは無視）
register_shutdown_function(static function () use ($path) {
    global $ACTOR, $AUTHED;
    try {
        $act = is_string($ACTOR) && $ACTOR !== '' ? $ACTOR : (!empty($AUTHED) ? actor() : 'noauth');
        db()->prepare('INSERT INTO access_log (at, method, path, status, actor, ua, ip) VALUES (?, ?, ?, ?, ?, ?, ?)')
            ->execute([date('c'), $_SERVER['REQUEST_METHOD'] ?? '?', $path, (int)http_response_code(),
                $act, mb_substr(trim((string)($_SERVER['HTTP_USER_AGENT'] ?? '')), 0, 200), $_SERVER['REMOTE_ADDR'] ?? null]);
        // shortcut: 30日より古いログを1/50の確率で掃除。長期保持が必要になったらアーカイブ先を検討
        if (random_int(1, 50) === 1) {
            $old = date('c', time() - 30 * 86400);
            db()->prepare('DELETE FROM access_log WHERE at < ?')->execute([$old]);
            db()->prepare('DELETE FROM ops_log WHERE at < ?')->execute([$old]);
        }
    } catch (Throwable $e) {}
});

// ---------- ルーティング ----------
if ($path === '/openapi.json') send_openapi($BASE);
if ($path === '/admin') handle_admin($BASE);
// OAuth 2.0 エンドポイント（認証不要）
if ($path === '/oauth/authorize') handle_oauth_authorize();
if ($path === '/oauth/token') handle_oauth_token();
if ($path === '/oauth/register') handle_oauth_register();
if ($path === '/.well-known/oauth-authorization-server') handle_oauth_metadata($BASE, 'authorization-server');
if ($path === '/.well-known/oauth-protected-resource') handle_oauth_metadata($BASE, 'protected-resource');

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
