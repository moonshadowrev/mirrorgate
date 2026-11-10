#!/usr/bin/env bash
# SPDX-License-Identifier: Apache-2.0
#
# MirrorGate end-to-end test.
#
# Starts examples/demo-origin and a MirrorGate instance on local ports with the PHP built-in
# server, then drives them with curl and asserts the mirror behaviour (link rewriting, cookies,
# redirects, cache HIT/STALE after the origin dies, friendly 502/504 pages, SSE streaming, SSRF
# rejection, admin auth, request limits). No network access beyond 127.0.0.1 is used.
#
#   scripts/e2e.sh            run everything
#   KEEP=1 scripts/e2e.sh     keep the temp directory (env file, logs) for inspection
#   PHP=/path/to/php scripts/e2e.sh
set -u

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PHP="${PHP:-php}"
TMP="$(mktemp -d "${TMPDIR:-/tmp}/mirrorgate-e2e.XXXXXX")"
ORIGIN_PID=""
MIRROR_PID=""
PASS=0
FAIL=0

free_port() {
    "$PHP" -r '$s = stream_socket_server("tcp://127.0.0.1:0", $e, $m); $n = stream_socket_get_name($s, false); echo substr($n, strrpos($n, ":") + 1);'
}

OP="$(free_port)"
MP="$(free_port)"
O_HOST="origin.test"
M_HOST="mirror.test"
ORIGIN_BASE="http://$O_HOST:$OP"
MIRROR_BASE="http://$M_HOST:$MP"
ADMIN_TOKEN="e2e-admin-secret-0123456789abcdefghijklmnop"
CURL=(curl -sS --max-time 20 --resolve "$M_HOST:$MP:127.0.0.1" --resolve "$O_HOST:$OP:127.0.0.1")

cleanup() {
    stop_pid "$MIRROR_PID"
    stop_pid "$ORIGIN_PID"
    if [ "${KEEP:-0}" = "1" ]; then
        echo "kept: $TMP"
    else
        rm -rf "$TMP"
    fi
}
trap cleanup EXIT

stop_pid() {
    local pid="${1:-}"
    [ -z "$pid" ] && return 0
    # The built-in server forks workers when PHP_CLI_SERVER_WORKERS is set; stop children first.
    pkill -P "$pid" 2>/dev/null
    kill "$pid" 2>/dev/null
    wait "$pid" 2>/dev/null
    return 0
}

ok() { PASS=$((PASS + 1)); printf '  ok   %s\n' "$1"; }
bad() { FAIL=$((FAIL + 1)); printf '  FAIL %s\n' "$1"; [ -n "${2:-}" ] && printf '       %s\n' "$2"; }

contains() { # name haystack needle
    if printf '%s' "$2" | grep -qF -- "$3"; then ok "$1"; else bad "$1" "expected to contain: $3"; printf '%s\n' "$2" | head -20 | sed 's/^/         | /'; fi
}
not_contains() { # name haystack needle
    if printf '%s' "$2" | grep -qF -- "$3"; then bad "$1" "must not contain: $3"; printf '%s\n' "$2" | head -20 | sed 's/^/         | /'; else ok "$1"; fi
}
equals() { # name actual expected
    if [ "$2" = "$3" ]; then ok "$1"; else bad "$1" "expected '$3', got '$2'"; fi
}
header_of() { # headers-blob name -> first value (case-insensitive), CR stripped
    printf '%s' "$1" | tr -d '\r' | grep -i "^$2:" | head -1 | sed -E "s/^[^:]*:[[:space:]]*//"
}

start_origin() {
    PHP_CLI_SERVER_WORKERS=6 "$PHP" -S "127.0.0.1:$OP" "$ROOT/examples/demo-origin/router.php" >"$TMP/origin.log" 2>&1 &
    ORIGIN_PID=$!
}

wait_port() { # port
    local i
    for i in $(seq 1 50); do
        if "$PHP" -r '$s = @fsockopen("127.0.0.1", (int) $argv[1], $e, $m, 0.2); exit($s ? 0 : 1);' "$1"; then return 0; fi
        sleep 0.1
    done
    return 1
}

write_env() {
    cat >"$TMP/.env" <<ENV
ORIGIN_URL=$ORIGIN_BASE
MIRROR_URL=$MIRROR_BASE
ORIGIN_ALLOW_HTTP=true
ALLOW_PRIVATE_ORIGIN=true
ORIGIN_RESOLVE_IP=127.0.0.1
HOST_MAP=api.$O_HOST=api.$M_HOST
STORAGE_DIR=$TMP/var
APP_INSTANCE_ID=e2etest
LOG_LEVEL=debug
ORIGIN_CONNECT_TIMEOUT=2
ORIGIN_TIMEOUT=3
ORIGIN_LOW_SPEED_TIME=0
ORIGIN_RETRIES=0
BREAKER_ENABLED=false
RATE_LIMIT_MAX_CONCURRENT=0
GLOBAL_MAX_CONCURRENT=0
RATE_LIMIT_REQUESTS=1000
SSE_MAX_DURATION=30
SSE_HEARTBEAT=0
MAX_REQUEST_BODY_BYTES=1M
CACHE_DEFAULT_TTL=60
ERROR_PAGE_UPSTREAM=all
CACHE_SWR_SECONDS=0
ADMIN_ENABLED=true
ADMIN_TOKEN=$ADMIN_TOKEN
ADMIN_ALLOW_HTTP=true
ADMIN_FAIL_LIMIT=3
ADMIN_GLOBAL_FAIL_LIMIT=5
CACHE_IGNORE_QUERY_PARAMS=utm_source
CACHE_IGNORE_MUST_REVALIDATE_ON_ERROR=false
ALLOW_SECRETS_IN_DOCROOT=true
ENV
}

echo "MirrorGate e2e (php: $("$PHP" -r 'echo PHP_VERSION;'), origin $ORIGIN_BASE, mirror $MIRROR_BASE)"
write_env

echo "== self check"
if "$PHP" "$ROOT/bin/mirrorgate" check --env="$TMP/.env" >"$TMP/check.out" 2>&1; then ok "bin/mirrorgate check passes"; else bad "bin/mirrorgate check passes" "$(tail -15 "$TMP/check.out")"; fi

start_origin
MIRRORGATE_ENV="$TMP/.env" PHP_CLI_SERVER_WORKERS=6 "$PHP" -S "127.0.0.1:$MP" -t "$ROOT/public" "$ROOT/public/index.php" >"$TMP/mirror.log" 2>&1 &
MIRROR_PID=$!
if ! wait_port "$OP" || ! wait_port "$MP"; then
    bad "servers start" "$(cat "$TMP/origin.log" "$TMP/mirror.log")"
    echo "RESULT: $PASS passed, $FAIL failed"
    exit 1
fi
ok "origin and mirror servers started"

echo "== link rewriting"
BODY="$("${CURL[@]}" "$MIRROR_BASE/")"
contains "absolute link rewritten" "$BODY" "href=\"$MIRROR_BASE/about\""
contains "relative link untouched" "$BODY" 'href="/about"'
contains "protocol-relative link rewritten" "$BODY" "href=\"//$M_HOST:$MP/about\""
contains "subdomain link mapped via HOST_MAP" "$BODY" "href=\"http://api.$M_HOST:$MP/data\""
contains "external link kept" "$BODY" 'https://example.org/keep'
contains "srcset rewritten (1x)" "$BODY" "$MIRROR_BASE/logo.png 1x"
contains "srcset rewritten (2x)" "$BODY" "$MIRROR_BASE/logo@2x.png 2x"
contains "canonical points at the mirror" "$BODY" "rel=\"canonical\" href=\"$MIRROR_BASE/\""
contains "form action rewritten" "$BODY" "action=\"$MIRROR_BASE/echo\""
contains "escaped-slash JSON in HTML rewritten" "$BODY" "http:\\/\\/$M_HOST:$MP\\/escaped"
contains "visible text untouched (REWRITE_VISIBLE_TEXT=false)" "$BODY" "Welcome to $O_HOST"
not_contains "no origin host in any attribute" "$(printf '%s' "$BODY" | grep -v 'Welcome to')" "$O_HOST"

CSS="$("${CURL[@]}" "$MIRROR_BASE/style.css")"
contains "CSS url() rewritten" "$CSS" "url($MIRROR_BASE/bg.png)"
contains "CSS @import rewritten" "$CSS" "$MIRROR_BASE/more.css"
not_contains "no origin host in CSS" "$CSS" "$O_HOST"

JSON="$("${CURL[@]}" "$MIRROR_BASE/api/data.json")"
contains "JSON values rewritten (escaped slashes)" "$JSON" "http:\\/\\/$M_HOST:$MP\\/api\\/data.json"
not_contains "no origin host in JSON" "$JSON" "$O_HOST"
JH="$("${CURL[@]}" -D - -o /dev/null "$MIRROR_BASE/api/data.json")"
contains "CORS origin header rewritten" "$JH" "Access-Control-Allow-Origin: $MIRROR_BASE"

XML="$("${CURL[@]}" "$MIRROR_BASE/feed.xml")"
contains "RSS links rewritten" "$XML" "<link>$MIRROR_BASE/</link>"
ROB="$("${CURL[@]}" "$MIRROR_BASE/robots.txt")"
contains "robots Sitemap rewritten" "$ROB" "Sitemap: $MIRROR_BASE/sitemap.xml"

echo "== headers"
H="$("${CURL[@]}" -D - -o /dev/null "$MIRROR_BASE/")"
not_contains "no origin host in response headers" "$H" "$O_HOST"
not_contains "no X-Powered-By leak" "$H" "DemoOrigin"
contains "nosniff or security header present or cache header present" "$H" "X-MirrorGate-Cache"
contains "Via header added" "$H" "mirrorgate"

echo "== redirects"
R="$("${CURL[@]}" -D - -o /dev/null "$MIRROR_BASE/redirect")"
equals "redirect status" "$(printf '%s' "$R" | head -1 | tr -d '\r' | awk '{print $2}')" "302"
equals "redirect Location rewritten" "$(header_of "$R" location)" "$MIRROR_BASE/final"
RF="$("${CURL[@]}" -L "$MIRROR_BASE/redirect")"
contains "following redirect lands on mirror content" "$RF" "final destination"
RE="$("${CURL[@]}" -D - -o /dev/null "$MIRROR_BASE/redirect-external")"
equals "external redirect passes unchanged" "$(header_of "$RE" location)" "https://example.org/elsewhere"

echo "== cookies"
C="$("${CURL[@]}" -D - -o /dev/null "$MIRROR_BASE/cookie")"
SC="$(printf '%s' "$C" | tr -d '\r' | grep -i '^set-cookie:')"
contains "sid cookie forwarded" "$SC" "sid=abc123"
contains "pref cookie forwarded" "$SC" "pref=dark"
not_contains "Domain attribute dropped" "$SC" "Domain="
not_contains "no origin host in cookies" "$SC" "$O_HOST"
contains "HttpOnly kept" "$SC" "HttpOnly"

echo "== request forwarding"
E="$("${CURL[@]}" -X POST -H 'Content-Type: application/json' -H "Cookie: a=b" -H 'X-Forwarded-Host: evil.example' --data '{"k":"v"}' "$MIRROR_BASE/echo")"
T="$("${CURL[@]}" -H 'Cookie: a=b' -H 'X-Forwarded-Host: evil.example' -H 'X-Original-URL: /admin' "$MIRROR_BASE/echo?format=text")"
contains "POST body reaches origin" "$E" '"body":"{\"k\":\"v\"}"'
contains "method preserved" "$E" '"method":"POST"'
contains "Host header set to origin" "$T" "host: $O_HOST:$OP"
contains "cookie forwarded" "$T" "cookie: a=b"
contains "X-Forwarded-Host is the mirror host" "$T" "x-forwarded-host: $M_HOST:$MP"
not_contains "X-Original-URL smuggling header dropped" "$T" "x-original-url"
not_contains "spoofed X-Forwarded-Host dropped" "$T" "evil.example"

echo "== hardening regressions"
UT="$("${CURL[@]}" -H 'X_Forwarded_For: 6.6.6.6' -H 'X_Http_Method_Override: DELETE' -H 'X-Remote-IP: 6.6.6.7' -H 'Remote-User: admin' "$MIRROR_BASE/echo?format=text&u=1")"
not_contains "underscore header spellings never reach the origin" "$UT" "6.6.6.6"
not_contains "method override via underscore header dropped" "$UT" "delete"
not_contains "extended spoofable headers dropped (X-Remote-IP)" "$UT" "6.6.6.7"
not_contains "trusted-user header dropped (Remote-User)" "$UT" "remote-user"
LOOP="$("${CURL[@]}" -o /dev/null -w '%{http_code}' -H 'X-MirrorGate-Hop: E2ETEST' "$MIRROR_BASE/")"
equals "loop guard is case-insensitive (508)" "$LOOP" "508"
HP="$("${CURL[@]}" -o /dev/null -w '%{http_code}' -H "Host: $M_HOST:1" "http://127.0.0.1:$MP/")"
equals "unexpected Host port rejected (421)" "$HP" "421"
PB="$("${CURL[@]}" -D - -o /dev/null "$MIRROR_BASE/pingback")"
equals "X-Pingback origin URL rewritten" "$(header_of "$PB" x-pingback)" "$MIRROR_BASE/xmlrpc.php"
not_contains "no origin host in other URL-carrying headers" "$PB" "$O_HOST"
RF1="$("${CURL[@]}" "$MIRROR_BASE/reflect?a=1&utm_source=PAYLOAD")"
not_contains "ignored query parameter is not forwarded on a cache fill" "$RF1" "PAYLOAD"
contains "other query parameters are still forwarded" "$RF1" "query=a=1"
RF2="$("${CURL[@]}" "$MIRROR_BASE/reflect?a=1&utm_source=OTHER")"
not_contains "cached variant carries no planted content" "$RF2" "PAYLOAD"
for i in 1 2 3 4 5 6 7 8; do "${CURL[@]}" -o /dev/null -H 'Authorization: Bearer wrong-token-wrong-token-wrong-token-00' "$MIRROR_BASE/__mirrorgate/health"; done
AL="$("${CURL[@]}" -o /dev/null -w '%{http_code}' -H "Authorization: Bearer $ADMIN_TOKEN" "$MIRROR_BASE/__mirrorgate/health")"
equals "valid admin token works after repeated failures" "$AL" "200"
AW="$("${CURL[@]}" -o /dev/null -w '%{http_code}' -H 'Authorization: Bearer wrong-token-wrong-token-wrong-token-00' "$MIRROR_BASE/__mirrorgate/health")"
equals "throttled anonymous requests still look like 404 (no 429 oracle)" "$AW" "404"

echo "== cache"
H1="$("${CURL[@]}" -D - "$MIRROR_BASE/cached")"
equals "first /cached is MISS" "$(header_of "$H1" x-mirrorgate-cache)" "MISS"
BODY1="$(printf '%s' "$H1" | tr -d '\r' | tail -1)"
H2="$("${CURL[@]}" -D - "$MIRROR_BASE/cached")"
equals "second /cached is HIT" "$(header_of "$H2" x-mirrorgate-cache)" "HIT"
equals "HIT serves cached body" "$(printf '%s' "$H2" | tr -d '\r' | tail -1)" "$BODY1"
ETAG="$(header_of "$H2" etag)"
if [ -n "$ETAG" ]; then
    CODE="$("${CURL[@]}" -o /dev/null -w '%{http_code}' -H "If-None-Match: $ETAG" "$MIRROR_BASE/cached")"
    equals "conditional request gets 304" "$CODE" "304"
else
    bad "ETag present for conditional requests" "no ETag header on cached response"
fi
HH="$("${CURL[@]}" -I "$MIRROR_BASE/cached")"
contains "HEAD answered" "$HH" "200"

echo "== SSE streaming"
"${CURL[@]}" -N "$MIRROR_BASE/sse" 2>/dev/null | while IFS= read -r line; do
    case "$line" in data:*) printf '%s %s\n' "$("$PHP" -r 'echo number_format(microtime(true), 3, ".", "");')" "$line" ;; esac
done >"$TMP/sse.out"
N="$(wc -l <"$TMP/sse.out" | tr -d ' ')"
equals "SSE delivered 5 events" "$N" "5"
if [ "$N" = "5" ]; then
    FIRST="$(head -1 "$TMP/sse.out" | awk '{print $1}')"
    LAST="$(tail -1 "$TMP/sse.out" | awk '{print $1}')"
    SPREAD="$("$PHP" -r 'echo $argv[2] - $argv[1] > 1.2 ? "incremental" : "buffered";' "$FIRST" "$LAST")"
    equals "SSE chunks arrive incrementally (not all at once)" "$SPREAD" "incremental"
fi
SH="$("${CURL[@]}" -m 1 -D - -o /dev/null "$MIRROR_BASE/sse" 2>/dev/null)"
contains "SSE content type preserved" "$SH" "text/event-stream"
not_contains "no origin host in streamed SSE headers" "$SH" "$O_HOST"
BH="$("${CURL[@]}" -D - -o /dev/null "$MIRROR_BASE/big")"
equals "/big is BYPASS (streamed passthrough)" "$(header_of "$BH" x-mirrorgate-cache)" "BYPASS"
not_contains "no origin host in passthrough headers" "$BH" "$O_HOST"

echo "== admin"
A0="$("${CURL[@]}" -o /dev/null -w '%{http_code}' "$MIRROR_BASE/__mirrorgate/health")"
equals "admin health without token is 404" "$A0" "404"
A1="$("${CURL[@]}" -o /dev/null -w '%{http_code}' -H 'Authorization: Bearer wrong-token-wrong-token-wrong-token-00' "$MIRROR_BASE/__mirrorgate/health")"
equals "admin health with wrong token is 404" "$A1" "404"
A2="$("${CURL[@]}" -o /dev/null -w '%{http_code}' -H "Authorization: Bearer $ADMIN_TOKEN" "$MIRROR_BASE/__mirrorgate/health")"
equals "admin health with token is 200" "$A2" "200"
P0="$("${CURL[@]}" -o /dev/null -w '%{http_code}' -X POST -H 'Content-Type: application/json' --data '{"mode":"all"}' "$MIRROR_BASE/__mirrorgate/purge")"
equals "purge without token rejected" "$P0" "404"
P1="$("${CURL[@]}" -o /dev/null -w '%{http_code}' -X POST -H "Authorization: Bearer $ADMIN_TOKEN" -H 'Content-Type: application/json' --data '{"mode":"url","target":"/cached"}' "$MIRROR_BASE/__mirrorgate/purge")"
equals "purge with token accepted" "$P1" "200"
H3="$("${CURL[@]}" -D - -o /dev/null "$MIRROR_BASE/cached")"
equals "purged URL is MISS again" "$(header_of "$H3" x-mirrorgate-cache)" "MISS"
GETP="$("${CURL[@]}" -o /dev/null -w '%{http_code}' -H "Authorization: Bearer $ADMIN_TOKEN" "$MIRROR_BASE/__mirrorgate/purge")"
equals "purge via GET is not allowed (405)" "$GETP" "405"

echo "== SSRF and request hardening"
S1="$("${CURL[@]}" -o /dev/null -w '%{http_code}' -H 'Host: 169.254.169.254' "http://127.0.0.1:$MP/")"
case "$S1" in 400|403|404|421) ok "foreign Host header rejected ($S1)";; *) bad "foreign Host header rejected" "got $S1";; esac
S2="$("${CURL[@]}" -o /dev/null -w '%{http_code}' --path-as-is "$MIRROR_BASE/http://169.254.169.254/latest/meta-data/")"
S2B="$("${CURL[@]}" -s --path-as-is "$MIRROR_BASE/http://169.254.169.254/latest/meta-data/")"
not_contains "absolute-URL path never reaches metadata address" "$S2B" "ami-id"
case "$S2" in 404|400) ok "absolute URL in path stays on origin ($S2)";; *) bad "absolute URL in path" "got $S2";; esac
S3="$("${CURL[@]}" -o /dev/null -w '%{http_code}' --path-as-is "$MIRROR_BASE//169.254.169.254/x")"
case "$S3" in 404|400|301|302|308) ok "double-slash host trick handled ($S3)";; *) bad "double-slash host trick" "got $S3";; esac
S4="$("${CURL[@]}" -o /dev/null -w '%{http_code}' --path-as-is "$MIRROR_BASE/a/../../etc/passwd")"
case "$S4" in 400|404) ok "path traversal not served ($S4)";; *) bad "path traversal" "got $S4";; esac
S5="$("${CURL[@]}" -o /dev/null -w '%{http_code}' --path-as-is "$MIRROR_BASE/a%0d%0aX-Injected:%201")"
equals "CRLF in path rejected" "$S5" "400"
S6="$("${CURL[@]}" -o /dev/null -w '%{http_code}' -X POST -H 'Content-Length: 4' -H 'Transfer-Encoding: chunked' --data 'abcd' "$MIRROR_BASE/echo")"
case "$S6" in 400|411|501) ok "CL+TE smuggling attempt rejected ($S6)";; *) bad "CL+TE smuggling" "got $S6";; esac
head -c 2000000 /dev/zero | tr '\0' 'x' >"$TMP/big.bin"
S7="$("${CURL[@]}" -o /dev/null -w '%{http_code}' -X POST --data-binary @"$TMP/big.bin" "$MIRROR_BASE/echo")"
equals "oversized body rejected with 413" "$S7" "413"
S8="$("${CURL[@]}" -o /dev/null -w '%{http_code}' -X TRACE "$MIRROR_BASE/")"
case "$S8" in 405|501) ok "TRACE refused ($S8)";; *) bad "TRACE refused" "got $S8";; esac
S9="$("${CURL[@]}" -s -H 'Host: evil.example' "http://127.0.0.1:$MP/" | head -c 400)"
not_contains "foreign Host never gets origin content" "$S9" "Welcome to"

echo "== security probe cases"
PROBE="$("$PHP" "$ROOT/tests/Security/security-probe.php" "127.0.0.1:$MP" "$M_HOST:$MP" 2>&1)"
if printf '%s' "$PROBE" | tail -1 | grep -q " 0 failed"; then ok "security-probe.php: $(printf '%s' "$PROBE" | tail -1)"; else bad "security-probe.php" "$PROBE"; fi

echo "== error pages"
E500="$("${CURL[@]}" -D - "$MIRROR_BASE/error500")"
not_contains "origin 500 internals not leaked" "$E500" "SECRET"
not_contains "origin 500 path not leaked" "$E500" "/srv/app"
E500S="$(printf '%s' "$E500" | head -1 | tr -d '\r' | awk '{print $2}')"
case "$E500S" in 500|502) ok "origin 500 replaced by friendly error page ($E500S)";; *) bad "origin 500 status" "got $E500S";; esac
contains "origin 500 shows friendly HTML" "$E500" "<html"
ES="$("${CURL[@]}" -D - "$MIRROR_BASE/slow?sec=8" )"
equals "slow origin gives 504" "$(printf '%s' "$ES" | head -1 | tr -d '\r' | awk '{print $2}')" "504"
contains "504 page is the friendly page" "$ES" "Gateway"
not_contains "504 page hides origin host" "$ES" "$O_HOST"

echo "== origin killed: stale-if-error and 502"
# Prime a short-lived entry, let it go stale, then kill the origin.
"${CURL[@]}" -o /dev/null "$MIRROR_BASE/cached?prime=1" >/dev/null
"${CURL[@]}" -o /dev/null "$MIRROR_BASE/mustreval" >/dev/null
sleep 2.2
stop_pid "$ORIGIN_PID"
ORIGIN_PID=""
sleep 0.3
ST="$("${CURL[@]}" -D - "$MIRROR_BASE/cached?prime=1")"
equals "stale entry served while origin is down (200)" "$(printf '%s' "$ST" | head -1 | tr -d '\r' | awk '{print $2}')" "200"
equals "stale entry is marked STALE" "$(header_of "$ST" x-mirrorgate-cache)" "STALE"
contains "stale-if-error path (not SWR) reports origin failure" "$(header_of "$ST" cache-status)" "fwd-status=502"
MR="$("${CURL[@]}" -D - "$MIRROR_BASE/mustreval")"
equals "must-revalidate entry is not served stale when CACHE_IGNORE_MUST_REVALIDATE_ON_ERROR=false" "$(printf '%s' "$MR" | head -1 | tr -d '\r' | awk '{print $2}')" "502"
D1="$("${CURL[@]}" -D - "$MIRROR_BASE/never-cached-page")"
equals "uncached page with origin down gives 502" "$(printf '%s' "$D1" | head -1 | tr -d '\r' | awk '{print $2}')" "502"
contains "502 page is friendly HTML" "$D1" "<html"
not_contains "502 page hides origin host" "$D1" "$O_HOST"
not_contains "502 page hides origin port" "$D1" "127.0.0.1:$OP"
not_contains "502 page hides curl internals" "$D1" "curl"
D1H="$("${CURL[@]}" -s -D - -o /dev/null -H 'Accept: text/html' "$MIRROR_BASE/never-cached-page")"
contains "error page not cacheable" "$D1H" "no-store"

echo "== logs"
LOGS="$(cat "$TMP"/var/logs/*.log 2>/dev/null)"
if [ -n "$LOGS" ]; then ok "log file written"; else bad "log file written" "no logs under $TMP/var/logs"; fi
not_contains "admin token never logged" "$LOGS" "$ADMIN_TOKEN"
not_contains "cookie values never logged" "$LOGS" "abc123"

echo
echo "RESULT: $PASS passed, $FAIL failed"
[ "$FAIL" -eq 0 ]
