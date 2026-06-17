#!/usr/bin/env bash
# Basic smoke tests for neuro.SYS.
# No test framework — PHP CLI checks for class logic, curl checks for HTTP behaviour.
#
# Usage (from any directory):
#   bash test/basic_test.sh
#
# If data/site_auth.php is active (pre-launch auth), pass credentials:
#   SITE_USER=preview SITE_PASS='...' bash test/basic_test.sh

REPO="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
AUTOLOAD="$REPO/autoload.php"
PORT=18080
BASE="http://localhost:$PORT"

PASS=0
FAIL=0

pass() { echo "  OK   $1"; ((PASS+=1)); }
fail() { echo "  FAIL $1"; ((FAIL+=1)); }

# Run a PHP snippet; exit 0 = pass, anything else = fail.
# The snippet runs with the autoloader already required.
php_ok() {
    local desc="$1"
    local code="$2"
    if php -r "require '$AUTOLOAD'; $code" 2>/dev/null; then
        pass "$desc"
    else
        fail "$desc"
    fi
}

# Build curl args — include Basic Auth credentials if site_auth.php is active.
CURL_ARGS=(-s)
if [[ -f "$REPO/data/site_auth.php" ]]; then
    if [[ -n "${SITE_USER:-}" && -n "${SITE_PASS:-}" ]]; then
        CURL_ARGS+=(-u "${SITE_USER}:${SITE_PASS}")
    else
        echo "NOTE: data/site_auth.php is active — HTTP checks will 401 without credentials."
        echo "      Run: SITE_USER=<user> SITE_PASS=<pass> bash test/basic_test.sh"
        echo ""
    fi
fi

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

# Assert that an AJAX request returns a 200 fragment (no <html> tag).
check_spa_fragment() {
    local desc="$1"; local url="$2"
    local tmp status body
    tmp=$(mktemp)
    status=$(curl "${CURL_ARGS[@]}" -H "X-Requested-With: XMLHttpRequest" \
        -o "$tmp" -w "%{http_code}" "$url" 2>/dev/null) || true
    body=$(cat "$tmp"); rm -f "$tmp"
    if [[ "$status" != "200" ]]; then
        fail "$desc (expected 200, got $status)"
    elif echo "$body" | grep -qi "<html"; then
        fail "$desc (AJAX response contains <html>)"
    else
        pass "$desc"
    fi
}


echo ""
echo "=== PHP class checks ==="

# --- Autoloader ---

php_ok "Autoloader resolves Collection" \
    "new NeuroSYS\Support\Collection(stdClass::class);"

php_ok "Autoloader resolves SearchableCollection" \
    "new NeuroSYS\Support\SearchableCollection(stdClass::class);"

php_ok "Autoloader resolves HttpStatusCode" \
    "NeuroSYS\Http\HttpStatusCode::NotFound->value === 404 or exit(1);"

# --- SearchableCollection ---

php_ok "SearchableCollection::find returns null for unknown key" \
    "use NeuroSYS\Support\SearchableCollection;
     \$c = new SearchableCollection(stdClass::class);
     \$c->find('x') === null or exit(1);"

php_ok "SearchableCollection::find returns the item for a known key" \
    "use NeuroSYS\Support\SearchableCollection;
     \$c = new SearchableCollection(stdClass::class);
     \$o = new stdClass();
     \$c->add('k', \$o);
     \$c->find('k') === \$o or exit(1);"

php_ok "SearchableCollection rejects items of the wrong type" \
    "use NeuroSYS\Support\SearchableCollection;
     \$c = new SearchableCollection(DateTime::class);
     try { \$c->add('k', new stdClass()); exit(1); } catch (TypeError \$e) {}"

# --- DownloadLogEntry ---

# Verifies that __toString() produces valid JSON and fromJson() reconstructs the object.
php_ok "DownloadLogEntry round-trips through JSON" \
    "use NeuroSYS\Service\DownloadLogEntry;
     \$e = new DownloadLogEntry('2026-06-17T00:00:00+00:00', 'test-slug', 'flac', '');
     \$d = DownloadLogEntry::fromJson((string) \$e);
     (\$d->slug === 'test-slug' && \$d->format === 'flac') or exit(1);"

# Malformed lines in the log file must not crash the stats parser.
php_ok "DownloadLogEntry::fromJson returns null for invalid JSON" \
    "use NeuroSYS\Service\DownloadLogEntry;
     DownloadLogEntry::fromJson('not json') === null or exit(1);"

# --- ReleaseRepository ---

php_ok "ReleaseRepository loads at least one release" \
    "use NeuroSYS\Service\ReleaseRepository;
     (new ReleaseRepository())->all()->count() > 0 or exit(1);"

php_ok "ReleaseRepository::find returns null for unknown slug" \
    "use NeuroSYS\Service\ReleaseRepository;
     (new ReleaseRepository())->find('does-not-exist') === null or exit(1);"

# Confirm the known slug from data/releases.php resolves to a Release object.
php_ok "ReleaseRepository::find returns a Release for 'hello-world'" \
    "use NeuroSYS\Service\ReleaseRepository;
     use NeuroSYS\Model\Release;
     \$r = (new ReleaseRepository())->find('hello-world');
     \$r instanceof Release or exit(1);"


echo ""
echo "=== HTTP route checks ==="

# Start the built-in dev server in the background; kill it on exit.
php -S "localhost:$PORT" -t "$REPO/public" >/dev/null 2>&1 &
SERVER_PID=$!
trap "kill $SERVER_PID 2>/dev/null; wait $SERVER_PID 2>/dev/null" EXIT

# Poll until the server is accepting connections (max ~3s).
started=0
for i in $(seq 1 15); do
    curl -s --max-time 0.5 -o /dev/null "$BASE/" && { started=1; break; }
    sleep 0.2
done
if [[ $started -eq 0 ]]; then
    echo "ERROR: PHP dev server did not start on port $PORT — aborting HTTP checks."
    exit 1
fi

check_status "GET /                              → 200" "$BASE/"                                200
check_status "GET /releases                      → 200" "$BASE/releases"                       200
check_status "GET /releases/hello-world          → 200" "$BASE/releases/hello-world"           200
check_status "GET /releases/hello-world/flac     → 303" "$BASE/releases/hello-world/flac"      303
check_status "GET /releases/no-such-slug         → 404" "$BASE/releases/no-such-slug"          404
check_status "GET /releases/hello-world/badformat→ 404" "$BASE/releases/hello-world/badformat" 404
check_status "GET /notfound                      → 404" "$BASE/notfound"                       404

# AJAX requests should return a page fragment, not a full HTML document.
check_spa_fragment "AJAX /         returns fragment only" "$BASE/"
check_spa_fragment "AJAX /releases returns fragment only" "$BASE/releases"


echo ""
if [[ $FAIL -eq 0 ]]; then
    echo "All $PASS checks passed."
else
    echo "$PASS passed, $FAIL failed."
    exit 1
fi
