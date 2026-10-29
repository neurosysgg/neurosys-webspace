# shellcheck shell=bash
# The verify script's HTTP assertions, shared by test/basic_test.sh and test/conformance.sh.
# Sourced, never run. Each helper asks with "${CURL_ARGS[@]}" and counts into PASS and FAIL.

PASS=0
FAIL=0

pass() { echo "  OK   $1"; ((PASS+=1)); }
fail() { echo "  FAIL $1"; ((FAIL+=1)); }

# Assert an HTTP status code against a URL.
check_status() {
    local desc="$1"; local url="$2"; local expected="$3"
    local actual
    actual=$(curl "${CURL_ARGS[@]}" -o /dev/null -w "%{http_code}" "$url") || true
    if [[ "$actual" == "$expected" ]]; then
        pass "$desc ($actual)"
    else
        fail "$desc (expected $expected, got $actual)"
    fi
}

# Assert that a URL's body contains (or does not contain) a string.
check_body() {
    local desc="$1"; local url="$2"; local needle="$3"; local mode="${4:-contains}"
    local body
    body=$(curl "${CURL_ARGS[@]}" "$url" 2>/dev/null) || true
    if [[ "$mode" == "contains" ]] && [[ "$body" == *"$needle"* ]]; then
        pass "$desc"
    elif [[ "$mode" == "absent" ]] && [[ "$body" != *"$needle"* ]]; then
        pass "$desc"
    else
        fail "$desc (expected to $mode '$needle')"
    fi
}

# Assert that a response header matches a pattern. header() is a no-op under CLI, so this
# is the only place the headers can actually be observed.
check_header() {
    local desc="$1"; local url="$2"; local pattern="$3"; local method="${4:-HEAD}"
    local headers
    # -D dumps headers for any method; -I would force HEAD, which the 405 gate allows.
    headers=$(curl "${CURL_ARGS[@]}" -X "$method" -o /dev/null -D - "$url" 2>/dev/null | tr -d '\r') || true
    if echo "$headers" | grep -qi -- "$pattern"; then
        pass "$desc"
    else
        fail "$desc (no header matching '$pattern')"
    fi
}

# Assert a header is absent, or present but not matching. The counterpart to check_header, for
# the things whose whole claim is that they are not there.
check_no_header() {
    local desc="$1"; local url="$2"; local pattern="$3"; local method="${4:-HEAD}"
    local headers
    headers=$(curl "${CURL_ARGS[@]}" -X "$method" -o /dev/null -D - "$url" 2>/dev/null | tr -d '\r') || true
    if echo "$headers" | grep -qi -- "$pattern"; then
        fail "$desc (header matching '$pattern' is being sent)"
    else
        pass "$desc"
    fi
}

# Assert that a response's Vary names every header given. Vary is a set, not a string: a server
# may join it without spaces, merge several Vary lines, and add what it varies on itself — Apache's
# mod_deflate appends Accept-Encoding, Strato adds X-Forwarded-For — so the check is membership,
# and a `*` (which no cache can key on) fails it.
check_vary() {
    local desc="$1"; local url="$2"; shift 2
    local tokens missing=""
    tokens=$(curl "${CURL_ARGS[@]}" -o /dev/null -D - "$url" 2>/dev/null | tr -d '\r' \
             | grep -i '^vary:' | sed 's/^[^:]*://' | tr ',' '\n' | tr -d ' ' | tr '[:upper:]' '[:lower:]')
    for want in "$@"; do
        grep -qx "${want,,}" <<< "$tokens" || missing="$missing $want"
    done
    if grep -qx '\*' <<< "$tokens"; then
        fail "$desc (Vary: *)"
    elif [[ -n "$missing" ]]; then
        fail "$desc (Vary does not name:$missing)"
    else
        pass "$desc"
    fi
}

# Assert an HTTP status code for a given method.
check_method() {
    local desc="$1"; local method="$2"; local url="$3"; local expected="$4"
    local actual
    actual=$(curl "${CURL_ARGS[@]}" -X "$method" -o /dev/null -w "%{http_code}" "$url") || true
    if [[ "$actual" == "$expected" ]]; then
        pass "$desc ($actual)"
    else
        fail "$desc (expected $expected, got $actual)"
    fi
}

# Assert that an AJAX request returns a 200 fragment (no <html> tag) that says how to read it.
#
# The charset is checked here rather than only on a full page because the fragment is the response
# that needs it most: a page carries <meta charset>, a fragment carries nothing, so the header is
# the only thing telling the browser what encoding the bytes are in.
check_spa_fragment() {
    local desc="$1"; local url="$2"
    local tmp status body headers
    tmp=$(mktemp)
    status=$(curl "${CURL_ARGS[@]}" -H "X-Requested-With: XMLHttpRequest" \
        -o "$tmp" -D "$tmp.h" -w "%{http_code}" "$url" 2>/dev/null) || true
    body=$(cat "$tmp"); headers=$(tr -d '\r' < "$tmp.h"); rm -f "$tmp" "$tmp.h"
    if [[ "$status" != "200" ]]; then
        fail "$desc (expected 200, got $status)"
    elif echo "$body" | grep -qi "<html"; then
        fail "$desc (AJAX response contains <html>)"
    elif ! echo "$headers" | grep -qi "^content-type: text/html; charset=utf-8"; then
        fail "$desc (fragment does not declare text/html; charset=utf-8)"
    else
        pass "$desc"
    fi
}

# Assert that a URL revalidates: it hands out an ETag, and handing that ETag back gets a 304 with
# no body. Two requests, because that is the whole mechanism — one to be given a validator, one to
# spend it. Only observable over real HTTP; header() is a no-op under CLI and so is the 304.
check_revalidates() {
    local desc="$1"; local url="$2"
    local etag status body
    etag=$(curl "${CURL_ARGS[@]}" -o /dev/null -D - "$url" 2>/dev/null | tr -d '\r' \
           | grep -i '^etag:' | sed 's/^[Ee][Tt][Aa][Gg]: //') || true

    if [[ -z "$etag" ]]; then
        fail "$desc (no ETag to revalidate with)"
        return
    fi

    local tmp; tmp=$(mktemp)
    status=$(curl "${CURL_ARGS[@]}" -H "If-None-Match: $etag" -o "$tmp" -w "%{http_code}" "$url" 2>/dev/null) || true
    body=$(cat "$tmp"); rm -f "$tmp"

    if [[ "$status" != "304" ]]; then
        fail "$desc (expected 304 for a matching ETag, got $status)"
    elif [[ -n "$body" ]]; then
        fail "$desc (304 carried a body)"
    else
        pass "$desc"
    fi
}

CURL_ARGS=(-s)
