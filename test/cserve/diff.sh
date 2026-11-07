#!/usr/bin/env bash
# The gate the native app keeps (docs/research/c-serve.md, phases c4 and c5): every request below
# asked of two c-serve hosts on one staged tree — A hands everything to php-cgi, which is the PHP
# site as it runs on Strato; B is the neurosys app (app/) alone, with no CGI behind it — and the
# two answers compared: the status, every header in order, the body byte for byte.
#
# The addresses only PHP has — the admin, /drop — are asked of B alone, and each must answer
# exactly as an address that does not exist.
#
# What may differ, and is taken out of both before they are compared:
#   - Date, Server and Connection, which are the host's;
#   - the framing: php-cgi's output is streamed (chunked), the app's has a Content-Length — which
#     is checked against the body it came with instead;
#   - the ETag's value, a SHA-256 in C and xxh128 in PHP (its presence is compared, and each side's
#     own validator is sent back to it for the 304);
#   - "Content-Type: text/html; charset=UTF-8" on A alone: php-cgi's default_mimetype, added to a
#     response that named no type (a redirect). Phpanta's own types are spelled "utf-8".
#
# A staged demo stands in for the real ones, which never enter a test: one track with a waveform
# sidecar, one without, its password minted here.
#
# Usage:
#   bash test/cserve/diff.sh             # c-serve from ./c-serve, else ~/Projects/c-serve
#   CSERVE_DIR=... bash test/cserve/diff.sh
#   VERBOSE=1 bash test/cserve/diff.sh   # every case named, not only the ones that differ
#   SAN=1 bash test/cserve/diff.sh       # the hosts and the app under ASan+UBSan

set -u
REPO="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
if [[ -z "${CSERVE_DIR:-}" ]]; then
    CSERVE_DIR="$REPO/c-serve"
    [[ -f "$CSERVE_DIR/Makefile" ]] || CSERVE_DIR="$HOME/Projects/c-serve"
fi
BIN="$CSERVE_DIR/build/O2${SAN:+-san}/bin"
PORT_A=18083
PORT_B=18084
PASSWORD="open sesame"

for tool in php-cgi curl; do
    command -v "$tool" >/dev/null || { echo "ERROR: $tool is not installed."; exit 1; }
done
if ! make -s -C "$CSERVE_DIR" ${SAN:+SAN=1} EXTERNAL_APPS="neurosys=$REPO/app" >/dev/null; then
    echo "ERROR: c-serve did not build the app in $CSERVE_DIR."
    exit 1
fi

PIDS=()
STAGE="$(mktemp -d /tmp/neurosys-diff-XXXXXX)"
# shellcheck disable=SC2329 # run by the EXIT trap
cleanup() {
    for pid in "${PIDS[@]}"; do kill "$pid" 2>/dev/null; wait "$pid" 2>/dev/null; done
    rm -rf "$STAGE"
}
trap cleanup EXIT

# --- the tree: the site's code and pages, the public data, and a demo of the test's own ---------
mkdir -p "$STAGE/phpanta" "$STAGE/data/demos/fixture"
cp -r "$REPO/autoload.php" "$REPO/src" "$REPO/public" "$STAGE/"
cp -r "$REPO/phpanta/autoload.php" "$REPO/phpanta/src" "$STAGE/phpanta/"
for f in releases.php profiles.php privacy.de.html privacy.en.html; do
    cp "$REPO/data/$f" "$STAGE/data/"
done
DIGEST="$(php -r 'require $argv[1]; echo Phpanta\Support\PasswordHash::mint($argv[2], 100000)->digest();' \
    "$STAGE/autoload.php" "$PASSWORD")"
cat > "$STAGE/data/demos.php" <<EOF
<?php
use NeuroSYS\Model\Demo;
use NeuroSYS\Model\DemoTrack;
use Phpanta\Support\Collection;
use Phpanta\Support\PasswordHash;
return [
    'fixture' => new Demo(
        title:    'a "fixture" — ü!',
        password: new PasswordHash('$DIGEST'),
        tracks:   new Collection(DemoTrack::class)->with(
            new DemoTrack('v2', 'v2.mp3', 157),
            new DemoTrack('v1', 'v1.ogg', 0),
            new DemoTrack('gone', 'gone.mp3', 3),
        ),
        description: 'a <b>test</b> & nothing else',
    ),
];
EOF
cat > "$STAGE/data/demos.json" <<EOF
{"fixture": {"title": "a \"fixture\" — ü!", "password": "$DIGEST",
  "description": "a <b>test</b> & nothing else",
  "tracks": [{"label": "v2", "file": "v2.mp3", "seconds": 157},
             {"label": "v1", "file": "v1.ogg", "seconds": 0},
             {"label": "gone", "file": "gone.mp3", "seconds": 3}]}}
EOF
head -c 5000 /dev/urandom > "$STAGE/data/demos/fixture/v2.mp3"
head -c 0 /dev/urandom > "$STAGE/data/demos/fixture/v1.ogg"
{ printf 'NSW1'; head -c 2048 /dev/urandom; } > "$STAGE/data/demos/fixture/v2.wave"

# --- the two hosts ------------------------------------------------------------------------------
start() {
    local port="$1"; shift
    "$BIN/cserve" --listen "127.0.0.1:$port" --root "$STAGE/public" --climb front \
        "$@" >"$STAGE/host-$port.log" 2>&1 &
    PIDS+=($!)
    for _ in $(seq 50); do
        grep -q "listening on" "$STAGE/host-$port.log" 2>/dev/null && return 0
        sleep 0.1
    done
    echo "ERROR: the host on :$port did not start:"; cat "$STAGE/host-$port.log"; exit 1
}
start "$PORT_A" --cgi php-cgi --env PHPANTA_ENVIRONMENT=development
start "$PORT_B" --app "$BIN/apps/neurosys.so" --data "$STAGE/data"

# --- one answer, normalised ---------------------------------------------------------------------
# PORT METHOD PATH [curl args...] into OUT.head and OUT.body; prints nothing
fetch() {
    local out="$1" port="$2" method="$3" path="$4"; shift 4
    local m=(-X "$method" -o "$out.body")
    # -I, not -X HEAD: a HEAD said without framing is read to the connection's end otherwise.
    # It writes the head where the body would go, so the body is nothing.
    [[ "$method" == HEAD ]] && m=(-I -o /dev/null)
    : > "$out.body"
    curl -s "${m[@]}" -D "$out.raw" "$@" "http://127.0.0.1:$port$path"
    local length
    length="$(grep -i '^Content-Length:' "$out.raw" | tr -d '\r' | awk '{print $2}')"
    if [[ -n "$length" && "$method" != HEAD && "$length" != "$(wc -c < "$out.body")" ]]; then
        echo "Content-Length $length, body $(wc -c < "$out.body")" >> "$out.raw"
    fi
    tr -d '\r' < "$out.raw" \
        | grep -viE '^(date|server|connection|transfer-encoding|content-length):' \
        | grep -vx 'Content-Type: text/html; charset=UTF-8' \
        | sed -E 's/^ETag: .*/ETag: (a validator)/' > "$out.head"
}

# the ETag a side gives PATH, for its own 304
etag() {
    curl -s -D- -o /dev/null "${@:2}" "http://127.0.0.1:$1" | tr -d '\r' | sed -n 's/^ETag: //p'
}

CASES=0
DIFFS=0
# METHOD PATH [curl args...]: both sides asked, the answers compared
case_() {
    local method="$1" path="$2"; shift 2
    CASES=$((CASES + 1))
    fetch "$STAGE/a" "$PORT_A" "$method" "$path" "$@"
    fetch "$STAGE/b" "$PORT_B" "$method" "$path" "$@"
    if cmp -s "$STAGE/a.head" "$STAGE/b.head" && cmp -s "$STAGE/a.body" "$STAGE/b.body"; then
        [[ -n "${VERBOSE:-}" ]] && echo "  same  $method $path $*"
        return 0
    fi
    DIFFS=$((DIFFS + 1))
    echo "  DIFF  $method $path $*"
    diff <(cat "$STAGE/a.head"; echo; cat "$STAGE/a.body") \
         <(cat "$STAGE/b.head"; echo; cat "$STAGE/b.body") | head -20 | sed 's/^/        /'
}

# the 304: each side sent its own validator for PATH, with the same request headers
revalidates() {
    local path="$1"; shift
    local ea eb
    ea="$(etag "$PORT_A$path" "$@")"
    eb="$(etag "$PORT_B$path" "$@")"
    CASES=$((CASES + 1))
    fetch "$STAGE/a" "$PORT_A" GET "$path" -H "If-None-Match: $ea" "$@"
    fetch "$STAGE/b" "$PORT_B" GET "$path" -H "If-None-Match: $eb" "$@"
    if [[ -n "$ea" && -n "$eb" ]] && cmp -s "$STAGE/a.head" "$STAGE/b.head" \
            && cmp -s "$STAGE/a.body" "$STAGE/b.body"; then
        [[ -n "${VERBOSE:-}" ]] && echo "  same  304 $path $*"
        return 0
    fi
    DIFFS=$((DIFFS + 1))
    echo "  DIFF  304 $path $* (validators '$ea' / '$eb')"
    diff "$STAGE/a.head" "$STAGE/b.head" | head -10 | sed 's/^/        /'
}

AUTH=(-u "demo:$PASSWORD")
PAGES=(/ /releases /releases/ill /releases/hello-world /imprint /privacy /releases/nope
       /releases/ill/ /imprint/ /rel%65ases /releases/%69ll /releases//ill /imprint?x=1)

# B's app must have loaded: without it the host answers every path with its own plain 404, which
# no case below would mistake for a page — but the failure is clearer said here.
native() {
    [[ "$(curl -s -o /dev/null -w '%{http_code}' "${@:2}" "http://127.0.0.1:$PORT_B$1")" == 200 ]]
}
for path in / /releases /releases/ill /imprint /privacy; do
    native "$path" || { echo "ERROR: B does not answer $path — the app is not loaded."; exit 1; }
done
native /demos/fixture -u "demo:$PASSWORD" \
    || { echo "ERROR: B's demo gate refused the fixture's password."; exit 1; }

echo "##### c-serve, PHP (A, :$PORT_A) against the app (B, :$PORT_B)"
echo "=== pages, in each language, as a document and as the SPA's fragment"
for path in "${PAGES[@]}"; do
    for lang in none cookie accept; do
        case "$lang" in
            none)   l=() ;;
            cookie) l=(-H 'Cookie: lang=de') ;;
            accept) l=(-H 'Accept-Language: de-AT, en;q=0.5') ;;
        esac
        case_ GET "$path" "${l[@]}"
        case_ GET "$path" "${l[@]}" -H 'X-Requested-With: XMLHttpRequest'
    done
    case_ HEAD "$path"
done

echo "=== the copy a client holds"
for path in / /releases /releases/ill /imprint /privacy; do
    revalidates "$path"
    revalidates "$path" -H 'Cookie: lang=de'
    revalidates "$path" -H 'X-Requested-With: XMLHttpRequest'
done

echo "=== downloads"
for path in /releases/ill/flac /releases/ill/wav /releases/ill/mp3 /releases/ill/stems \
            /releases/ill/ogg /releases/ill/FLAC /releases/nope/flac /releases/ill/flac/x; do
    case_ GET "$path"
    case_ GET "$path" -H 'Accept-Language: de'
done

echo "=== the language switch"
for ref in '' 'https://neurosys.gg/releases/ill?x=1#y' 'https://neurosys.gg' 'https://neurosys.gg/' \
           'http://evil.example/imprint' 'https://neurosys.gg/language/en' 'not a url' \
           'https://neurosys.gg/releases/hello-world/' 'ftp://neurosys.gg/x'; do
    for lang in de en fr DE; do
        if [[ -n "$ref" ]]; then case_ GET "/language/$lang" -H "Referer: $ref"
        else case_ GET "/language/$lang"; fi
    done
done

echo "=== a demo: the gate, the page, the audio"
for path in /demos/fixture /demos/nope /demos/fixture/v2 /demos/fixture/v1 /demos/fixture/x \
            /demos/fixture/gone /demos/a%20b; do
    case_ GET "$path"
    case_ GET "$path" -u "demo:wrong"
    case_ GET "$path" -u "nobody:$PASSWORD"
    case_ GET "$path" "${AUTH[@]}"
    case_ HEAD "$path" "${AUTH[@]}"
done
case_ GET /demos/fixture "${AUTH[@]}" -H 'Accept-Language: de'
case_ GET /demos/fixture "${AUTH[@]}" -H 'X-Requested-With: XMLHttpRequest'
for range in 'bytes=0-99' 'bytes=-100' 'bytes=4900-' 'bytes=-0' 'bytes=5000-' 'bytes=1-2,4-5' \
             'bytes=9-1' 'BYTES=0-0' 'pages=0-1'; do
    case_ GET /demos/fixture/v2 "${AUTH[@]}" -H "Range: $range"
    case_ HEAD /demos/fixture/v2 "${AUTH[@]}" -H "Range: $range"
done
case_ GET /demos/fixture/v1 "${AUTH[@]}" -H 'Range: bytes=-5'

echo "=== a method no route takes"
for path in / /releases /releases/ill /releases/ill/flac /demos/fixture /demos/fixture/v2 \
            /imprint /privacy /language/de; do
    for method in POST PUT DELETE OPTIONS; do
        case_ "$method" "$path"
    done
    case_ POST "$path" -H 'Accept-Language: de'
done

echo "=== an address no route has"
for path in /nope /releases/ill/flac/x /nope/deeper '/no%20such' /index.php; do
    case_ GET "$path"
    case_ GET "$path" -H 'Accept-Language: de'
    case_ GET "$path" -H 'X-Requested-With: XMLHttpRequest'
    case_ HEAD "$path"
    for method in POST PUT DELETE OPTIONS PATCH BREW; do
        case_ "$method" "$path"
    done
    case_ POST "$path" -H 'Accept-Language: de'
done

# METHOD PATH [curl args...] asked of B and compared with B's own /nope, the path the 404 page's
# terminal shows taken out of both: an address only PHP has is, natively, one that does not exist
absent() {
    local method="$1" path="$2"; shift 2
    CASES=$((CASES + 1))
    fetch "$STAGE/b" "$PORT_B" "$method" "$path" "$@"
    fetch "$STAGE/n" "$PORT_B" "$method" /nope "$@"
    sed -i 's/command="find &quot;[^&]*&quot;"/command="find (the path)"/' "$STAGE/b.body" "$STAGE/n.body"
    if cmp -s "$STAGE/n.head" "$STAGE/b.head" && cmp -s "$STAGE/n.body" "$STAGE/b.body"; then
        [[ -n "${VERBOSE:-}" ]] && echo "  none  $method $path $*"
        return 0
    fi
    DIFFS=$((DIFFS + 1))
    echo "  DIFF  $method $path $* (B, against B's /nope)"
    diff <(cat "$STAGE/n.head"; echo; cat "$STAGE/n.body") \
         <(cat "$STAGE/b.head"; echo; cat "$STAGE/b.body") | head -20 | sed 's/^/        /'
}

echo "=== the addresses only PHP has, natively absent"
for path in /admin /admin/ /admin/update /admin/update/v1 /admin/update/v1/version /drop /api \
            /api/update/v1/patch; do
    absent GET "$path"
    absent GET "$path" -H 'Accept: application/json'
    absent GET "$path" -H 'Accept-Language: de'
    absent HEAD "$path"
    absent POST "$path"
    absent POST "$path" -H 'Authorization: NS1 x'
    absent OPTIONS "$path"
done

# a sanitizer that spoke without stopping its host (UBSan, by default) spoke into its log
if grep -l "runtime error\|Sanitizer" "$STAGE"/host-*.log >/dev/null 2>&1; then
    echo "  a sanitizer reported:"; grep -h -A5 "runtime error\|Sanitizer" "$STAGE"/host-*.log | head -30
    DIFFS=$((DIFFS + 1))
fi

echo ""
if [[ "$DIFFS" -eq 0 ]]; then
    echo "##### $CASES cases, every answer the same"
    exit 0
fi
echo "##### $DIFFS of $CASES cases differ"
exit 1
