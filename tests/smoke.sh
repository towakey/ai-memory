#!/usr/bin/env bash
# 使い方: ./smoke.sh [BASE] [TOKEN]   例) ./smoke.sh http://localhost/ai-memory xxxx
BASE="${1:-${BASE:-http://localhost/ai-memory}}"
TOKEN="${2:-${TOKEN:-}}"
if [ -z "$TOKEN" ]; then echo "TOKEN が必要です（引数または環境変数）"; exit 2; fi
AUTH="Authorization: Bearer $TOKEN"
pass=0; fail=0

check() { # name expected actual
  if [ "$2" = "$3" ]; then echo "PASS: $1"; pass=$((pass+1));
  else echo "FAIL: $1 (expected=$2 got=$3)"; fail=$((fail+1)); fi
}
code() { curl -s -o /dev/null -w '%{http_code}' "$@"; }
req() { curl -s -w $'\n%{http_code}' "$@"; } # body\nstatus
status_of() { printf '%s' "$1" | tail -n1; }
body_of() { printf '%s' "$1" | sed '$d'; }

echo "== BASE: $BASE =="

# --- 認証 ---
check "no token -> 401" 401 "$(code "$BASE/")"
check "wrong token -> 401" 401 "$(code -H 'Authorization: Bearer wrong' "$BASE/")"
check "?token= works" 200 "$(code "$BASE/?token=$TOKEN")"
check "X-API-Key works" 200 "$(code -H "X-API-Key: $TOKEN" "$BASE/")"

# --- Markdown ---
resp=$(req -H "$AUTH" "$BASE/")
check "GET / status" 200 "$(status_of "$resp")"
case "$(body_of "$resp")" in "# AI Memory"*) check "markdown header" ok ok ;; *) check "markdown header" ok "missing" ;; esac

# --- REST CRUD ---
resp=$(req -X POST -H "$AUTH" -H 'Content-Type: application/json' "$BASE/api/memories" -d '{"key":"smoke_test","content":"hello"}')
check "POST create" 200 "$(status_of "$resp")"
body_of "$resp" | grep -q '"key":"smoke_test"' && check "POST body" ok ok || check "POST body" ok missing

resp=$(req -H "$AUTH" "$BASE/api/memories")
check "GET list" 200 "$(status_of "$resp")"
body_of "$resp" | grep -q '"key":"smoke_test"' && check "list contains key" ok ok || check "list contains key" ok missing

resp=$(req -H "$AUTH" "$BASE/api/memories/smoke_test")
check "GET item" 200 "$(status_of "$resp")"
body_of "$resp" | grep -q '"content":"hello"' && check "item content" ok ok || check "item content" ok missing

req -X POST -H "$AUTH" -H 'Content-Type: application/json' "$BASE/api/memories" -d '{"key":"smoke_test","content":"world"}' >/dev/null
resp=$(req -H "$AUTH" "$BASE/api/memories/smoke_test")
body_of "$resp" | grep -q '"content":"world"' && check "upsert overwrote" ok ok || check "upsert overwrote" ok missing

resp=$(req -X POST -H "$AUTH" -H 'Content-Type: application/json' "$BASE/api/memories" -d '{"key":"bad key!","content":"x"}')
check "invalid key -> 400" 400 "$(status_of "$resp")"
resp=$(req -H "$AUTH" "$BASE/api/memories/no_such_key")
check "missing key -> 404" 404 "$(status_of "$resp")"

resp=$(req -X DELETE -H "$AUTH" "$BASE/api/memories/smoke_test")
check "DELETE" 200 "$(status_of "$resp")"
body_of "$resp" | grep -q '"deleted":true' && check "delete body" ok ok || check "delete body" ok missing
resp=$(req -X DELETE -H "$AUTH" "$BASE/api/memories/smoke_test")
check "DELETE again -> 404" 404 "$(status_of "$resp")"

# --- history ---
req -X POST -H "$AUTH" -H 'Content-Type: application/json' "$BASE/api/memories" -d '{"key":"smoke_hist","content":"v1"}' >/dev/null
req -X POST -H "$AUTH" -H 'Content-Type: application/json' "$BASE/api/memories" -d '{"key":"smoke_hist","content":"v2"}' >/dev/null
resp=$(req -H "$AUTH" "$BASE/api/memories/smoke_hist/history")
check "history GET" 200 "$(status_of "$resp")"
check "history 1 entry" 1 "$(body_of "$resp" | grep -o '"id":[0-9]*' | wc -l | tr -d ' ')"
body_of "$resp" | grep -q '"content":"v1"' && check "history has v1" ok ok || check "history has v1" ok "$(body_of "$resp")"

req -X POST -H "$AUTH" -H 'Content-Type: application/json' "$BASE/api/memories" -d '{"key":"smoke_hist","content":"v2"}' >/dev/null
resp=$(req -H "$AUTH" "$BASE/api/memories/smoke_hist/history")
check "same content no archive" 1 "$(body_of "$resp" | grep -o '"id":[0-9]*' | wc -l | tr -d ' ')"

req -X DELETE -H "$AUTH" "$BASE/api/memories/smoke_hist" >/dev/null
resp=$(req -H "$AUTH" "$BASE/api/memories/smoke_hist/history")
check "after delete history 2" 2 "$(body_of "$resp" | grep -o '"id":[0-9]*' | wc -l | tr -d ' ')"
check "deleted key GET -> 404" 404 "$(code -H "$AUTH" "$BASE/api/memories/smoke_hist")"

hid=$(body_of "$resp" | grep -o '"id":[0-9]*' | tail -1 | cut -d: -f2) # 最古 = v1
resp=$(req -X POST -H "$AUTH" -H 'Content-Type: application/json' "$BASE/api/memories/smoke_hist/restore" -d "{\"id\":$hid}")
check "restore" 200 "$(status_of "$resp")"
body_of "$resp" | grep -q '"content":"v1"' && check "restored content v1" ok ok || check "restored content v1" ok "$(body_of "$resp")"

# 他keyのid指定 -> 404
req -X POST -H "$AUTH" -H 'Content-Type: application/json' "$BASE/api/memories" -d '{"key":"smoke_other","content":"o1"}' >/dev/null
req -X POST -H "$AUTH" -H 'Content-Type: application/json' "$BASE/api/memories" -d '{"key":"smoke_other","content":"o2"}' >/dev/null
other_hid=$(req -H "$AUTH" "$BASE/api/memories/smoke_other/history" | grep -o '"id":[0-9]*' | head -1 | cut -d: -f2)
resp=$(req -X POST -H "$AUTH" -H 'Content-Type: application/json' "$BASE/api/memories/smoke_hist/restore" -d "{\"id\":$other_hid}")
check "restore other key id -> 404" 404 "$(status_of "$resp")"

resp=$(req -X POST -H "$AUTH" -H 'Content-Type: application/json' "$BASE/api/memories/smoke_hist/restore" -d '{"id":"abc"}')
check "restore non-int id -> 400" 400 "$(status_of "$resp")"
resp=$(req -X POST -H "$AUTH" -H 'Content-Type: application/json' "$BASE/api/memories/smoke_hist/restore" -d "{\"id\":999999}")
check "restore missing id -> 404" 404 "$(status_of "$resp")"

# --- openapi.json（認証不要） ---
resp=$(req "$BASE/openapi.json")
check "openapi no auth" 200 "$(status_of "$resp")"
body_of "$resp" | grep -q '"openapi":"3.1.0"' && check "openapi version" ok ok || check "openapi version" ok missing
body_of "$resp" | grep -q '"operationId":"listMemories"' && check "openapi ops" ok ok || check "openapi ops" ok missing

# --- MCP ---
resp=$(req -X POST -H "$AUTH" -H 'Content-Type: application/json' "$BASE/mcp" -d '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-03-26","capabilities":{},"clientInfo":{"name":"smoke","version":"0"}}}')
check "mcp initialize" 200 "$(status_of "$resp")"
body_of "$resp" | grep -q '"protocolVersion":"2025-03-26"' && check "mcp version neg" ok ok || check "mcp version neg" ok "$(body_of "$resp")"
body_of "$resp" | grep -q '"name":"ai-memory"' && check "mcp serverInfo" ok ok || check "mcp serverInfo" ok missing

resp=$(req -X POST -H "$AUTH" -H 'Content-Type: application/json' "$BASE/mcp" -d '{"jsonrpc":"2.0","id":2,"method":"tools/list"}')
check "mcp tools/list" 200 "$(status_of "$resp")"
body_of "$resp" | grep -q '"name":"list_memories"' && body_of "$resp" | grep -q '"name":"put_memory"' && check "mcp tools" ok ok || check "mcp tools" ok missing

resp=$(req -X POST -H "$AUTH" -H 'Content-Type: application/json' "$BASE/mcp" -d '{"jsonrpc":"2.0","id":3,"method":"tools/call","params":{"name":"put_memory","arguments":{"key":"smoke_mcp","content":"via mcp"}}}')
check "mcp put" 200 "$(status_of "$resp")"
body_of "$resp" | grep -q 'smoke_mcp' && check "mcp put body" ok ok || check "mcp put body" ok missing

resp=$(req -X POST -H "$AUTH" -H 'Content-Type: application/json' "$BASE/mcp" -d '{"jsonrpc":"2.0","id":4,"method":"tools/call","params":{"name":"get_memory","arguments":{"key":"smoke_mcp"}}}')
body_of "$resp" | grep -q 'via mcp' && check "mcp get" ok ok || check "mcp get" ok "$(body_of "$resp")"

resp=$(req -X POST -H "$AUTH" -H 'Content-Type: application/json' "$BASE/mcp" -d '{"jsonrpc":"2.0","id":5,"method":"tools/call","params":{"name":"delete_memory","arguments":{"key":"smoke_mcp"}}}')
body_of "$resp" | grep -q 'deleted' && check "mcp delete" ok ok || check "mcp delete" ok missing

resp=$(req -X POST -H "$AUTH" -H 'Content-Type: application/json' "$BASE/mcp" -d '{"jsonrpc":"2.0","id":6,"method":"tools/call","params":{"name":"get_memory","arguments":{"key":"smoke_mcp"}}}')
body_of "$resp" | grep -q '"isError":true' && check "mcp not found isError" ok ok || check "mcp not found isError" ok missing

resp=$(req -X POST -H "$AUTH" -H 'Content-Type: application/json' "$BASE/mcp" -d '{"jsonrpc":"2.0","method":"notifications/initialized"}')
check "mcp notification -> 202" 202 "$(status_of "$resp")"

resp=$(req -X POST -H "$AUTH" -H 'Content-Type: application/json' "$BASE/mcp" -d '{"jsonrpc":"2.0","id":7,"method":"bogus/method"}')
body_of "$resp" | grep -q '"code":-32601' && check "mcp unknown -32601" ok ok || check "mcp unknown -32601" ok missing

check "mcp GET -> 405" 405 "$(code -H "$AUTH" "$BASE/mcp")"

resp=$(req -X POST -H "$AUTH" -H 'Content-Type: application/json' "$BASE/mcp" -d '{"jsonrpc":"2.0","id":8,"method":"tools/call","params":{"name":"get_history","arguments":{"key":"smoke_hist"}}}')
body_of "$resp" | grep -q 'v1' && check "mcp get_history" ok ok || check "mcp get_history" ok "$(body_of "$resp")"

resp=$(req -X POST -H "$AUTH" -H 'Content-Type: application/json' "$BASE/mcp" -d "{\"jsonrpc\":\"2.0\",\"id\":9,\"method\":\"tools/call\",\"params\":{\"name\":\"restore_memory\",\"arguments\":{\"key\":\"smoke_hist\",\"id\":$hid}}}")
body_of "$resp" | grep -q 'v1' && check "mcp restore_memory" ok ok || check "mcp restore_memory" ok "$(body_of "$resp")"

# --- 403 (直アクセス拒否) ---
check "config.php -> 403" 403 "$(code "$BASE/config.php")"
check "memory.sqlite -> 403" 403 "$(code "$BASE/data/memory.sqlite")"

# --- admin ---
jar=$(mktemp)
resp=$(req -c "$jar" "$BASE/admin")
check "admin login page" 200 "$(status_of "$resp")"
csrf=$(body_of "$resp" | grep -o 'name="csrf" value="[a-f0-9]*"' | head -1 | sed 's/.*value="//;s/"//')
check "csrf present" 64 "${#csrf}"

check "admin login" 303 "$(code -b "$jar" -c "$jar" -X POST "$BASE/admin" --data-urlencode "csrf=$csrf" --data-urlencode "action=login" --data-urlencode "api_key=$TOKEN")"

resp=$(req -b "$jar" -c "$jar" "$BASE/admin")
csrf=$(body_of "$resp" | grep -o 'name="csrf" value="[a-f0-9]*"' | head -1 | sed 's/.*value="//;s/"//')

check "admin save" 303 "$(code -b "$jar" -c "$jar" -X POST "$BASE/admin" --data-urlencode "csrf=$csrf" --data-urlencode "action=save" --data-urlencode "key=smoke_admin" --data-urlencode "content=admintest")"
resp=$(req -H "$AUTH" "$BASE/api/memories/smoke_admin")
body_of "$resp" | grep -q 'admintest' && check "admin saved data" ok ok || check "admin saved data" ok missing

check "POST without csrf -> 403" 403 "$(code -b "$jar" -c "$jar" -X POST "$BASE/admin" --data-urlencode "action=save" --data-urlencode "key=x" --data-urlencode "content=y")"

# admin経由で save→restore
req -b "$jar" -c "$jar" -X POST "$BASE/admin" --data-urlencode "csrf=$csrf" --data-urlencode "action=save" --data-urlencode "key=smoke_admin" --data-urlencode "content=admintest2" >/dev/null
hid_admin=$(req -H "$AUTH" "$BASE/api/memories/smoke_admin/history" | grep -o '"id":[0-9]*' | head -1 | cut -d: -f2)
check "admin restore -> 303" 303 "$(code -b "$jar" -c "$jar" -X POST "$BASE/admin" --data-urlencode "csrf=$csrf" --data-urlencode "action=restore" --data-urlencode "key=smoke_admin" --data-urlencode "id=$hid_admin")"
resp=$(req -H "$AUTH" "$BASE/api/memories/smoke_admin")
body_of "$resp" | grep -q '"content":"admintest"' && check "admin restored data" ok ok || check "admin restored data" ok "$(body_of "$resp")"
rm -f "$jar"

# --- クリーンアップ ---
curl -s -X DELETE -H "$AUTH" "$BASE/api/memories/smoke_admin" >/dev/null
curl -s -X DELETE -H "$AUTH" "$BASE/api/memories/smoke_hist" >/dev/null
curl -s -X DELETE -H "$AUTH" "$BASE/api/memories/smoke_other" >/dev/null

# 履歴も掃除（ローカルDB限定: php CLIが使える時のみ）
DIR="$(cd "$(dirname "$0")" && pwd)"
DBF="$DIR/../data/memory.sqlite"
PHP_BIN="${PHP_BIN:-php}"
if [ -f "$DBF" ] && command -v "$PHP_BIN" >/dev/null 2>&1; then
  "$PHP_BIN" -r '$pdo=new PDO("sqlite:".$argv[1]);$pdo->prepare("DELETE FROM memory_history WHERE key LIKE ?")->execute(["smoke%"]);' "$DBF" \
    && echo "history cleanup done"
elif [ -f "$DBF" ]; then
  echo "WARN: php CLI 不在のため履歴クリーンアップ未実行（PHP_BIN で指定可）"
fi

echo "== $pass passed, $fail failed =="
[ "$fail" -eq 0 ]
