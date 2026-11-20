<?php
declare(strict_types=1);
// 動作確認用テストデータ投入: php tests/seed.php  (消すとき: php tests/seed.php --clean)
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('cli only'); }

$config = require dirname(__DIR__) . '/config.php';
$pdo = new PDO('sqlite:' . ($config['db'] ?? dirname(__DIR__) . '/data/memory.sqlite'), null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);
$now = date('c');

if (in_array('--clean', $argv, true)) {
    $pdo->exec("DELETE FROM memories WHERE key LIKE 'seed_%'");
    $pdo->exec("DELETE FROM memory_history WHERE key LIKE 'seed_%'");
    $pdo->exec("DELETE FROM raw_logs WHERE source_ai LIKE 'seed%'");
    $pdo->exec("DELETE FROM semantic_memories WHERE category = 'seed'");
    $pdo->exec("DELETE FROM periodic_digests WHERE period_label LIKE 'seed%'");
    exit("cleaned\n");
}

$kv = [
    ['seed_dev_env', 'dev', "OS: Windows 11 / PHP 8.2 (XAMPP)\n本番: さくら PHP 8.3"],
    ['seed_profile', 'profile', "名前: テストユーザー\n好み: 日本語で応答"],
    ['seed_rule_ja', 'rule', "AIへの応答は常に日本語"],
];
$st = $pdo->prepare('INSERT INTO memories (key, content, updated_at, category) VALUES (?, ?, ?, ?)
    ON CONFLICT(key) DO UPDATE SET content = excluded.content, updated_at = excluded.updated_at, category = excluded.category');
foreach ($kv as $r) $st->execute([$r[0], $r[1], $now, $r[2]]);

$logs = [
    '今日はポケモン図鑑の encounter.json を直していた。あと半分くらい。',
    'ユーザーはコーヒーより紅茶派かも。',
    'ai-memory の設計で履歴機能を付けた。さくらの Satisfy any で .htaccess が効かない件に注意。',
    '週末に半導体工場シミュレータを触る予定。',
    '雑談: 天気が良かった。',
];
$st = $pdo->prepare('INSERT INTO raw_logs (content, source_ai) VALUES (?, ?)');
foreach ($logs as $l) $st->execute([$l, 'seed']);

$sm = [
    ['ユーザーはポケモン図鑑サイトを個人開発している', 'seed', 'pokemon,project', 4],
    ['紅茶が好きという話題が出た（未確認）', 'seed', 'preference', 2],
    ['さくらサーバーでは .htaccess の Require all denied が効かないことがある', 'seed', 'dev,server', 5],
];
$st = $pdo->prepare('INSERT INTO semantic_memories (summary, category, tags, importance, embedding_json, updated_at) VALUES (?, ?, ?, ?, NULL, ?)');
foreach ($sm as $r) $st->execute([$r[0], $r[1], $r[2], $r[3], $now]);

$st = $pdo->prepare('INSERT INTO periodic_digests (period_type, period_label, digest_text) VALUES (?, ?, ?)');
$st->execute(['daily', 'seed-2026-11-20', '図鑑データ修正・ai-memoryの拡張を実施。週末はシミュレータの予定。']);
$st->execute(['weekly', 'seed-2026-W47', '今週はai-memoryを本番公開し、MCP対応・履歴機能・管理画面UIを整備した。']);

echo "seeded: kv=3, raw_logs=5, semantic=3, digests=2\n";
