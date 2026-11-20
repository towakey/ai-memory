<?php
declare(strict_types=1);
// Step1 マイグレーション: php migrate.php （CLI）
// 冪等。既存テーブル・データには触れず、カラム・テーブルの追加のみ行う。

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('cli only'); }

$config = require __DIR__ . '/config.php';
$DB_PATH = $config['db'] ?? (__DIR__ . '/data/memory.sqlite');

$pdo = new PDO('sqlite:' . $DB_PATH, null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);

// memories に category が無ければ追加（既存行は NULL、既定値なし）
$cols = array_column($pdo->query('PRAGMA table_info(memories)')->fetchAll(PDO::FETCH_ASSOC), 'name');
if (!in_array('category', $cols, true)) {
    $pdo->exec('ALTER TABLE memories ADD COLUMN category TEXT');
    echo "memories: + category\n";
} else {
    echo "memories: category exists\n";
}

$sql = file_get_contents(__DIR__ . '/migrate.sql');
if ($sql === false) exit('migrate.sql not found');
$pdo->exec($sql);

foreach (['raw_logs', 'semantic_memories', 'periodic_digests'] as $t) {
    $n = $pdo->query("SELECT COUNT(*) FROM $t")->fetchColumn();
    echo "$t: ok ($n rows)\n";
}
echo "done\n";
