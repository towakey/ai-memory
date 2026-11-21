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

# ログ系テストのクリーンアップ基準（この実行で増えた行だけ消す。ローカルDBが読める時のみ）
DIR="$(cd "$(dirname "$0")" && pwd)"
DBF="$DIR/../data/memory.sqlite"
PHP_BIN="${PHP_BIN:-php}"
A_BASE=0; O_BASE=0
if [ -f "$DBF" ] && command -v "$PHP_BIN" >/dev/null 2>&1; then
  read -r A_BASE O_BASE <<< "$("$PHP_BIN" -r '$p=new PDO("sqlite:".$argv[1]);$t=array_column($p->query("SELECT name FROM sqlite_master")->fetchAll(),"name");$m=function($n)use($p,$t){return in_array($n,$t,true)?(int)$p->query("SELECT MAX(id) FROM $n")->fetchColumn():0;};echo $m("access_log")," ",$m("ops_log");' "$DBF" 2>/dev/null)"
fi

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
body_of "$resp" | grep -q '"name":"kv_set"' && body_of "$resp" | grep -q '"name":"add_memory_log"' && body_of "$resp" | grep -q '"name":"recall_memories"' && check "mcp tools" ok ok || check "mcp tools" ok missing

# kv_set (category付き) -> kv_get
resp=$(req -X POST -H "$AUTH" -H 'Content-Type: application/json' "$BASE/mcp" -d '{"jsonrpc":"2.0","id":3,"method":"tools/call","params":{"name":"kv_set","arguments":{"key":"smoke_mcp","value":"via mcp","category":"smoke"}}}')
check "mcp kv_set" 200 "$(status_of "$resp")"
body_of "$resp" | grep -q 'smoke_mcp' && body_of "$resp" | grep -qF 'value\":\"via mcp' && body_of "$resp" | grep -qF 'category\":\"smoke' && check "mcp kv_set body" ok ok || check "mcp kv_set body" ok "$(body_of "$resp")"

resp=$(req -X POST -H "$AUTH" -H 'Content-Type: application/json' "$BASE/mcp" -d '{"jsonrpc":"2.0","id":4,"method":"tools/call","params":{"name":"kv_get","arguments":{"key":"smoke_mcp"}}}')
body_of "$resp" | grep -q 'via mcp' && body_of "$resp" | grep -qF 'category\":\"smoke' && check "mcp kv_get" ok ok || check "mcp kv_get" ok "$(body_of "$resp")"

# kv_get category 指定 -> 該当カテゴリの配列
resp=$(req -X POST -H "$AUTH" -H 'Content-Type: application/json' "$BASE/mcp" -d '{"jsonrpc":"2.0","id":41,"method":"tools/call","params":{"name":"kv_get","arguments":{"category":"smoke"}}}')
body_of "$resp" | grep -q 'smoke_mcp' && check "mcp kv_get by category" ok ok || check "mcp kv_get by category" ok "$(body_of "$resp")"

# kv_list
resp=$(req -X POST -H "$AUTH" -H 'Content-Type: application/json' "$BASE/mcp" -d '{"jsonrpc":"2.0","id":42,"method":"tools/call","params":{"name":"kv_list","arguments":{}}}')
body_of "$resp" | grep -q 'smoke_mcp' && check "mcp kv_list" ok ok || check "mcp kv_list" ok "$(body_of "$resp")"

# category省略の上書きは既存category維持
req -X POST -H "$AUTH" -H 'Content-Type: application/json' "$BASE/mcp" -d '{"jsonrpc":"2.0","id":43,"method":"tools/call","params":{"name":"kv_set","arguments":{"key":"smoke_mcp","value":"via mcp 2"}}}' >/dev/null
resp=$(req -X POST -H "$AUTH" -H 'Content-Type: application/json' "$BASE/mcp" -d '{"jsonrpc":"2.0","id":44,"method":"tools/call","params":{"name":"kv_get","arguments":{"key":"smoke_mcp"}}}')
body_of "$resp" | grep -qF 'category\":\"smoke' && check "kv_set keeps category" ok ok || check "kv_set keeps category" ok "$(body_of "$resp")"

# kv_delete
resp=$(req -X POST -H "$AUTH" -H 'Content-Type: application/json' "$BASE/mcp" -d '{"jsonrpc":"2.0","id":5,"method":"tools/call","params":{"name":"kv_delete","arguments":{"key":"smoke_mcp"}}}')
body_of "$resp" | grep -q 'deleted' && check "mcp kv_delete" ok ok || check "mcp kv_delete" ok missing

resp=$(req -X POST -H "$AUTH" -H 'Content-Type: application/json' "$BASE/mcp" -d '{"jsonrpc":"2.0","id":6,"method":"tools/call","params":{"name":"kv_get","arguments":{"key":"smoke_mcp"}}}')
body_of "$resp" | grep -q '"isError":true' && check "mcp not found isError" ok ok || check "mcp not found isError" ok missing

# add_memory_log
resp=$(req -X POST -H "$AUTH" -H 'Content-Type: application/json' "$BASE/mcp" -d '{"jsonrpc":"2.0","id":61,"method":"tools/call","params":{"name":"add_memory_log","arguments":{"content":"smoke log entry","source_ai":"smoke-test"}}}')
body_of "$resp" | grep -q '"id":' && check "mcp add_memory_log" ok ok || check "mcp add_memory_log" ok "$(body_of "$resp")"

# recall_memories / get_recent_digests は配列で返ること（空でもOK）
resp=$(req -X POST -H "$AUTH" -H 'Content-Type: application/json' "$BASE/mcp" -d '{"jsonrpc":"2.0","id":62,"method":"tools/call","params":{"name":"recall_memories","arguments":{"query":"smoke"}}}')
body_of "$resp" | grep -q '"text":"\[' && check "mcp recall_memories" ok ok || check "mcp recall_memories" ok "$(body_of "$resp")"

resp=$(req -X POST -H "$AUTH" -H 'Content-Type: application/json' "$BASE/mcp" -d '{"jsonrpc":"2.0","id":63,"method":"tools/call","params":{"name":"get_recent_digests","arguments":{}}}')
body_of "$resp" | grep -q '"text":"\[' && check "mcp get_recent_digests" ok ok || check "mcp get_recent_digests" ok "$(body_of "$resp")"

resp=$(req -X POST -H "$AUTH" -H 'Content-Type: application/json' "$BASE/mcp" -d '{"jsonrpc":"2.0","method":"notifications/initialized"}')
check "mcp notification -> 202" 202 "$(status_of "$resp")"

resp=$(req -X POST -H "$AUTH" -H 'Content-Type: application/json' "$BASE/mcp" -d '{"jsonrpc":"2.0","id":7,"method":"bogus/method"}')
body_of "$resp" | grep -q '"code":-32601' && check "mcp unknown -32601" ok ok || check "mcp unknown -32601" ok missing

# GET /mcp はSSEストリーム（20秒で切断）なので max-time で打ち切ってヘッダだけ見る
check "mcp GET -> SSE 200" 200 "$(code -H "$AUTH" --max-time 3 "$BASE/mcp")"

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

# --- OAuth 2.0 (Authorization Code + PKCE) ---
PHP_BIN="${PHP_BIN:-php}"
CID=""
resp=$(req -X POST -H 'Content-Type: application/json' "$BASE/oauth/register" -d '{"redirect_uris":["http://localhost:9999/cb"],"client_name":"smoke-oauth"}')
check "oauth register -> 201" 201 "$(status_of "$resp")"
CID=$(body_of "$resp" | grep -o '"client_id":"[a-f0-9]*"' | head -1 | cut -d'"' -f4)
check "client_id issued" 32 "${#CID}"

VERIFIER="smoke-verifier-$(date +%s)-$$"
CHAL=$("$PHP_BIN" -r 'echo rtrim(strtr(base64_encode(hash("sha256",$argv[1],true)),"+/","-_"),"=");' "$VERIFIER" 2>/dev/null)
check "pkce challenge generated" 43 "${#CHAL}"

RURI='http://localhost:9999/cb'
RURI_ENC='http%3A%2F%2Flocalhost%3A9999%2Fcb'
resp=$(req "$BASE/oauth/authorize?response_type=code&client_id=$CID&redirect_uri=$RURI_ENC&state=xyz&code_challenge=$CHAL&code_challenge_method=S256")
check "authorize GET -> 200" 200 "$(status_of "$resp")"
body_of "$resp" | grep -q 'name="api_key"' && check "authorize form" ok ok || check "authorize form" ok missing

out=$(curl -s -o /dev/null -w $'%{http_code}\n%{redirect_url}' -X POST "$BASE/oauth/authorize" \
  --data-urlencode "response_type=code" --data-urlencode "client_id=$CID" \
  --data-urlencode "redirect_uri=$RURI" --data-urlencode "state=xyz" \
  --data-urlencode "code_challenge=$CHAL" --data-urlencode "code_challenge_method=S256" \
  --data-urlencode "api_key=$TOKEN")
check "authorize POST -> 302" 302 "$(printf '%s\n' "$out" | head -n1)"
LOC=$(printf '%s\n' "$out" | tail -n1)
case "$LOC" in *state=xyz*) check "redirect has state" ok ok ;; *) check "redirect has state" ok "$LOC" ;; esac
CODE=$(printf '%s' "$LOC" | grep -o 'code=[a-f0-9]*' | head -1 | cut -d= -f2)
check "redirect has code" 64 "${#CODE}"

resp=$(req -X POST "$BASE/oauth/token" --data-urlencode "grant_type=authorization_code" \
  --data-urlencode "code=$CODE" --data-urlencode "redirect_uri=$RURI" --data-urlencode "client_id=$CID")
check "token without verifier -> 400" 400 "$(status_of "$resp")"
body_of "$resp" | grep -q 'invalid_grant' && check "invalid_grant body" ok ok || check "invalid_grant body" ok "$(body_of "$resp")"

resp=$(req -X POST "$BASE/oauth/token" --data-urlencode "grant_type=authorization_code" \
  --data-urlencode "code=$CODE" --data-urlencode "redirect_uri=$RURI" --data-urlencode "client_id=$CID" \
  --data-urlencode "code_verifier=$VERIFIER")
check "token with verifier -> 200" 200 "$(status_of "$resp")"
OATOKEN=$(body_of "$resp" | grep -o '"access_token":"[a-f0-9]*"' | head -1 | cut -d'"' -f4)
check "access_token issued" 64 "${#OATOKEN}"

check "oauth token /api/memories" 200 "$(code -H "Authorization: Bearer $OATOKEN" "$BASE/api/memories")"
resp=$(req -X POST -H "Authorization: Bearer $OATOKEN" -H 'Content-Type: application/json' "$BASE/mcp" -d '{"jsonrpc":"2.0","id":9,"method":"tools/list"}')
check "oauth token /mcp tools/list" 200 "$(status_of "$resp")"

resp=$(req -X POST "$BASE/oauth/token" --data-urlencode "grant_type=authorization_code" \
  --data-urlencode "code=$CODE" --data-urlencode "redirect_uri=$RURI" --data-urlencode "client_id=$CID" \
  --data-urlencode "code_verifier=$VERIFIER")
check "code reuse -> 400" 400 "$(status_of "$resp")"

check "bad host authorize -> 400" 400 "$(code "$BASE/oauth/authorize?response_type=code&client_id=nosuchclient&redirect_uri=https%3A%2F%2Fevil.example.com%2Fcb")"
check "localhost authorize -> 200" 200 "$(code "$BASE/oauth/authorize?response_type=code&client_id=nosuchclient&redirect_uri=http%3A%2F%2Flocalhost%3A8888%2Fcb")"

resp=$(req "$BASE/.well-known/oauth-authorization-server")
check "well-known as -> 200" 200 "$(status_of "$resp")"
body_of "$resp" | grep -q '"authorization_endpoint"' && body_of "$resp" | grep -q '"code_challenge_methods_supported":\["S256"\]' && check "well-known json" ok ok || check "well-known json" ok "$(body_of "$resp")"
check "well-known pr -> 200" 200 "$(code "$BASE/.well-known/oauth-protected-resource")"

hdr=$(curl -s -D - -o /dev/null "$BASE/api/memories")
printf '%s' "$hdr" | tr -d '\r' | grep -qi '^www-authenticate:.*oauth-protected-resource' && check "401 WWW-Authenticate" ok ok || check "401 WWW-Authenticate" ok missing

# --- アクセスログ/操作ログ/actor判定/updated_by ---
# initialize の clientInfo が mcp_sessions に保存され、Mcp-Session-Id ヘッダで actor 解決される
resp=$(curl -s -D - -o /dev/null -X POST -H "$AUTH" -H 'Content-Type: application/json' "$BASE/mcp" -d '{"jsonrpc":"2.0","id":70,"method":"initialize","params":{"protocolVersion":"2025-03-26","capabilities":{},"clientInfo":{"name":"smoke-client","version":"9.9"}}}')
SID=$(printf '%s' "$resp" | tr -d '\r' | grep -i '^mcp-session-id:' | head -1 | awk '{print $2}' | tr -d '[:space:]')
check "mcp session id issued" 32 "${#SID}"
req -X POST -H "$AUTH" -H "Mcp-Session-Id: $SID" -H 'Content-Type: application/json' "$BASE/mcp" -d '{"jsonrpc":"2.0","id":71,"method":"tools/call","params":{"name":"kv_set","arguments":{"key":"smoke_log","value":"x"}}}' >/dev/null
# OAuthトークンで kv_set -> actor は client_name(smoke-oauth) または oauth:xxxxxxxx
req -X POST -H "Authorization: Bearer $OATOKEN" -H 'Content-Type: application/json' "$BASE/mcp" -d '{"jsonrpc":"2.0","id":72,"method":"tools/call","params":{"name":"kv_set","arguments":{"key":"smoke_log_oa","value":"x"}}}' >/dev/null
# APIキー + User-Agent で REST POST -> actor は UA先頭トークン
req -X POST -H "$AUTH" -A 'SmokeUA/1.0' -H 'Content-Type: application/json' "$BASE/api/memories" -d '{"key":"smoke_log_ua","content":"x"}' >/dev/null
resp=$(req -H "$AUTH" "$BASE/api/memories")
body_of "$resp" | grep -q '"updated_by"' && check "list has updated_by" ok ok || check "list has updated_by" ok missing

# admin 画面のログ表示 + admin 経由 save で actor=admin
jar2=$(mktemp)
resp=$(req -c "$jar2" "$BASE/admin")
csrf2=$(body_of "$resp" | grep -o 'name="csrf" value="[a-f0-9]*"' | head -1 | sed 's/.*value="//;s/"//')
req -b "$jar2" -c "$jar2" -X POST "$BASE/admin" --data-urlencode "csrf=$csrf2" --data-urlencode "action=login" --data-urlencode "api_key=$TOKEN" >/dev/null
resp=$(req -b "$jar2" -c "$jar2" "$BASE/admin")
csrf2=$(body_of "$resp" | grep -o 'name="csrf" value="[a-f0-9]*"' | head -1 | sed 's/.*value="//;s/"//')
body_of "$resp" | grep -q '操作ログ' && body_of "$resp" | grep -q 'アクセスログ' && check "admin log sections" ok ok || check "admin log sections" ok missing
check "admin save for ops" 303 "$(code -b "$jar2" -c "$jar2" -X POST "$BASE/admin" --data-urlencode "csrf=$csrf2" --data-urlencode "action=save" --data-urlencode "key=smoke_log_adm" --data-urlencode "content=x")"
rm -f "$jar2"

# ops_log/access_log の中身確認（ローカルDB + php CLI がある時のみ）
if [ -f "$DBF" ] && command -v "$PHP_BIN" >/dev/null 2>&1; then
  mapfile -t L < <("$PHP_BIN" -r '
    $p=new PDO("sqlite:".$argv[1]);
    $g=function($sql,$a=[])use($p){$s=$p->prepare($sql);$s->execute($a);$v=$s->fetchColumn();return $v===false?"":$v;};
    echo $g("SELECT actor FROM ops_log WHERE action=? AND key=? ORDER BY id DESC",["kv_set","smoke_log"]),"\n";
    echo $g("SELECT actor FROM ops_log WHERE action=? AND key=? ORDER BY id DESC",["kv_set","smoke_log_oa"]),"\n";
    echo $g("SELECT actor FROM ops_log WHERE action=? AND key=? ORDER BY id DESC",["kv_set","smoke_log_ua"]),"\n";
    echo $g("SELECT actor FROM ops_log WHERE action=? AND key=? ORDER BY id DESC",["kv_set","smoke_log_adm"]),"\n";
    echo $g("SELECT client_name FROM mcp_sessions WHERE session_id=?",[$argv[2]]),"\n";
    echo $g("SELECT COUNT(*) FROM access_log WHERE path=? AND actor=?",["/api/memories","SmokeUA"]),"\n";
    echo $g("SELECT updated_by FROM memories WHERE key=?",["smoke_log_ua"]),"\n";
  ' "$DBF" "${SID:-}")
  check "ops actor=mcp client" "smoke-client" "${L[0]:-}"
  check "ops actor=oauth client" "smoke-oauth" "${L[1]:-}"
  check "ops actor=UA head" "SmokeUA" "${L[2]:-}"
  check "ops actor=admin" "admin" "${L[3]:-}"
  check "mcp_sessions row" "smoke-client" "${L[4]:-}"
  [ "${L[5]:-0}" -gt 0 ] && check "access_log recorded" ok ok || check "access_log recorded" ok "count=${L[5]:-0}"
  check "updated_by stored" "SmokeUA" "${L[6]:-}"
else
  echo "SKIP: ops_log/access_log のDB確認は php CLI + ローカルDB がある時のみ"
fi

# --- クリーンアップ ---
curl -s -X DELETE -H "$AUTH" "$BASE/api/memories/smoke_admin" >/dev/null
curl -s -X DELETE -H "$AUTH" "$BASE/api/memories/smoke_hist" >/dev/null
curl -s -X DELETE -H "$AUTH" "$BASE/api/memories/smoke_other" >/dev/null
curl -s -X DELETE -H "$AUTH" "$BASE/api/memories/smoke_log" >/dev/null
curl -s -X DELETE -H "$AUTH" "$BASE/api/memories/smoke_log_oa" >/dev/null
curl -s -X DELETE -H "$AUTH" "$BASE/api/memories/smoke_log_ua" >/dev/null
curl -s -X DELETE -H "$AUTH" "$BASE/api/memories/smoke_log_adm" >/dev/null

# 履歴も掃除（ローカルDB限定: php CLIが使える時のみ。ログは実行前の最大id以降だけ消す）
if [ -f "$DBF" ] && command -v "$PHP_BIN" >/dev/null 2>&1; then
  "$PHP_BIN" -r '$pdo=new PDO("sqlite:".$argv[1]);$pdo->prepare("DELETE FROM memory_history WHERE key LIKE ?")->execute(["smoke%"]);$pdo->exec("DELETE FROM raw_logs WHERE content LIKE '"'"'smoke%'"'"'");
    $tables=array_column($pdo->query("SELECT name FROM sqlite_master")->fetchAll(),"name");
    if(in_array("oauth_clients",$tables,true)){
      $pdo->prepare("DELETE FROM oauth_clients WHERE client_id=?")->execute([$argv[2]]);
      $pdo->prepare("DELETE FROM oauth_codes WHERE client_id=?")->execute([$argv[2]]);
      $pdo->prepare("DELETE FROM oauth_tokens WHERE client_id=?")->execute([$argv[2]]);
    }
    if(in_array("mcp_sessions",$tables,true)) $pdo->exec("DELETE FROM mcp_sessions WHERE client_name IN ('"'"'smoke-client'"'"','"'"'smoke'"'"')");
    if(in_array("access_log",$tables,true)) $pdo->prepare("DELETE FROM access_log WHERE id > ?")->execute([(int)$argv[3]]);
    if(in_array("ops_log",$tables,true)) $pdo->prepare("DELETE FROM ops_log WHERE id > ?")->execute([(int)$argv[4]]);' "$DBF" "${CID:-}" "${A_BASE:-0}" "${O_BASE:-0}" \
    && echo "history cleanup done"
elif [ -f "$DBF" ]; then
  echo "WARN: php CLI 不在のため履歴クリーンアップ未実行（PHP_BIN で指定可）"
fi

echo "== $pass passed, $fail failed =="
[ "$fail" -eq 0 ]
