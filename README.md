# AI Memory

複数のAI（Claude / Devin / ChatGPT GPTs 等）と人間が、個人データ・作業状況を共有するための読み書き可能なメモDB。PHP 8.2+ / SQLite / 外部ライブラリなし。さくらのレンタルサーバー等の共用PHP環境で動く。

## 構成

| ファイル | 役割 |
|---|---|
| `index.php` | 全エンドポイント（1ファイル） |
| `config.php` | APIキー・DBパス（Git管理外、`config.sample.php` をコピーして作成） |
| `migrate.sql` / `migrate.php` | スキーマ拡張（冪等） |
| `.htaccess` | mod_rewrite ルーティング + Authorization 引き回し |
| `tests/smoke.sh`, `tests/seed.php` | 疎通テスト、テストデータ投入 |

## 記憶の3層

| 層 | テーブル | 用途 |
|---|---|---|
| 確定記憶 | `memories` (key/value/category) | 環境・予定・ルール等の正確な事実 |
| 生ログ | `raw_logs` | 会話の断片・メモ（バッチ処理前の受け皿） |
| 曖昧な記憶 | `semantic_memories` | 好み・エピソード・関心事（Embeddingは Step3 で追加予定） |
| 定期要約 | `periodic_digests` | 日次/週次ダイジェスト |
| 履歴 | `memory_history` | 上書き・削除の自動バックアップ（REST/adminで復元可） |

## エンドポイント

- `GET /?token=キー` … 全件をMarkdownで返す
- `GET/POST /api/memories`, `GET/DELETE /api/memories/{key}`, `GET /api/memories/{key}/history`, `POST /api/memories/{key}/restore` … REST API（JSON）
- `GET /openapi.json` … GPTs 用 OpenAPI 3.1
- `POST /mcp` … MCP Streamable HTTP（ツール: `kv_list` `kv_get` `kv_set` `kv_delete` `add_memory_log` `recall_memories` `get_recent_digests`）
- `/admin` … ブラウザ管理画面（履歴・復元・削除済み表示つき）

## 認証

`Authorization: Bearer <API_KEY>` / `X-API-Key` ヘッダ / `?token=` のいずれか。APIキーは `config.php` の `api_key`。

## 設置（さくらのレンタルサーバー等）

1. 公開フォルダに `index.php` `.htaccess` `config.sample.php` `migrate.sql` `migrate.php` `data/.htaccess`（空の `data/` ごと）をアップ。
2. `config.sample.php` を `config.php` にコピーし、`api_key` に `bin2hex(random_bytes(32))` で作った値を設定。`db` は公開フォルダ外のパスを推奨（さくらは親 `.htaccess` の `Satisfy any` で `Require all denied` が無効化される場合あり）。
3. `php migrate.php` を1回実行（未実行でも `db()` が初回アクセス時に自動作成する）。
4. HTTPS でアクセス確認: `curl -H "X-API-Key: キー" https://ドメイン/api/memories`

## 運用

- バックアップ: DBファイル1つをコピーすればよい。cron例（毎日3時、公開外フォルダに7日分保持）:
  ```
  0 3 * * * cd /home/USER/ai-memory-data && cp -p memory.sqlite memory.sqlite.$(date +\%Y\%m\%d) && ls -t memory.sqlite.???????? | tail -n +8 | xargs rm -f
  ```
- テストデータ: `php tests/seed.php` で投入、`php tests/seed.php --clean` で削除。
- 疎通テスト: `BASE=https://ドメイン TOKEN=キー bash tests/smoke.sh`

## 既知の制約

- 履歴は無期限保持（DB肥大化時は古い履歴の削除を検討）。
- `recall_memories` は現在キーワード検索のみ。LLM要約・Embedding・`batch_consolidate.php`（日次要約・記憶統合）は Step3 で追加予定。
