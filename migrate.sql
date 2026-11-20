-- ai-memory 拡張マイグレーション（Step 1）
-- 既存データ（memories, memory_history）は変更しない。追加のみ。
-- 複数回実行してよい（CREATE ... IF NOT EXISTS）。category の追加だけは
-- ALTER TABLE なので migrate.php 側で「カラム有無を見て」実行する。

-- 確定記憶: 既存 memories テーブルが kv_memories の役割
--   memories(key TEXT PK, content TEXT NOT NULL, updated_at TEXT NOT NULL, category TEXT)
--   content = 仕様の value に対応（API側で value として返す）

-- 生ログ・一時メモ層
CREATE TABLE IF NOT EXISTS raw_logs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    content TEXT NOT NULL,
    source_ai TEXT,
    created_at TEXT NOT NULL DEFAULT (datetime('now', 'localtime')),
    processed_at TEXT
);
CREATE INDEX IF NOT EXISTS raw_logs_processed ON raw_logs(processed_at);

-- 曖昧な記憶・エピソード層
CREATE TABLE IF NOT EXISTS semantic_memories (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    summary TEXT NOT NULL,
    category TEXT,
    tags TEXT,
    importance INTEGER NOT NULL DEFAULT 3,
    embedding_json TEXT,
    updated_at TEXT
);
CREATE INDEX IF NOT EXISTS semantic_memories_updated ON semantic_memories(updated_at);

-- 定期要約層
CREATE TABLE IF NOT EXISTS periodic_digests (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    period_type TEXT NOT NULL,
    period_label TEXT NOT NULL,
    digest_text TEXT NOT NULL,
    created_at TEXT NOT NULL DEFAULT (datetime('now', 'localtime'))
);
CREATE UNIQUE INDEX IF NOT EXISTS periodic_digests_uniq ON periodic_digests(period_type, period_label);
