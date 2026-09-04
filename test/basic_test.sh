#!/usr/bin/env bash
# End-to-end verify script for neuro.SYS.
#
# This is the *other half* of the test suite. `vendor/bin/phpunit` covers the units —
# pure logic, branches, escaping — against Composer's autoloader. This script covers
# what unit tests structurally cannot:
#
#   * the real hand-rolled autoloader in autoload.php (the |> pipe operator needs PHP 8.5)
#   * the real HTTP stack: status codes, redirects, Basic Auth (Auth::* calls exit)
#   * the real data files as they will be deployed
#   * repo hygiene that would only bite on the server
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

# Run a PHP snippet against the REAL autoloader; exit 0 = pass, anything else = fail.
php_ok() {
    local desc="$1"
    local code="$2"
    if php -r "require '$AUTOLOAD'; $code" 2>/dev/null; then
        pass "$desc"
    else
        fail "$desc"
    fi
}

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


echo ""
echo "=== Environment ==="

# autoload.php uses the |> pipe operator, which is a hard parse error below 8.5.
if php -r 'exit(PHP_VERSION_ID >= 80500 ? 0 : 1);'; then
    pass "PHP $(php -r 'echo PHP_VERSION;') satisfies the >=8.5 the pipe operator needs"
else
    fail "PHP $(php -r 'echo PHP_VERSION;') is below the 8.5 autoload.php requires"
fi


echo ""
echo "=== Production autoloader ==="
# PHPUnit runs against Composer's autoloader; these exercise the one that actually ships.

php_ok "autoload.php resolves a Support class" \
    "class_exists('NeuroSYS\Support\Collection', true) or exit(1);"

php_ok "autoload.php resolves a nested-namespace class" \
    "class_exists('NeuroSYS\Model\Link\HiDriveLink', true) or exit(1);"

php_ok "autoload.php resolves an enum" \
    "NeuroSYS\Http\HttpStatusCode::NotFound->value === 404 or exit(1);"

php_ok "autoload.php ignores classes outside the NeuroSYS prefix" \
    "class_exists('Some\Other\Vendor\Thing', true) === false or exit(1);"

php_ok "every class under src/ actually loads" \
    "\$bad = [];
     \$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator('$REPO/src'));
     foreach (\$it as \$f) {
         if (\$f->getExtension() !== 'php') continue;
         \$rel = substr(\$f->getPathname(), strlen('$REPO/src/'));
         \$class = str_replace('/', chr(92), substr(\$rel, 0, -4));
         if (!class_exists(\$class) && !interface_exists(\$class) && !enum_exists(\$class)) \$bad[] = \$class;
     }
     \$bad === [] or exit(1);"


echo ""
echo "=== Data files ==="
# These load the real data/ as it will be uploaded, so a bad paste fails here.

php_ok "data/releases.php loads and every Release constructs" \
    "(new NeuroSYS\Service\ReleaseRepository())->all()->count() > 0 or exit(1);"

php_ok "data/profiles.php loads and every link is https" \
    "foreach ((new NeuroSYS\Service\ProfileRepository())->all() as \$p) {
         str_starts_with(\$p->url, 'https://') or exit(1);
     }"

php_ok "data/admin.php is shaped the way Auth expects" \
    "\$c = require '$REPO/data/admin.php';
     (isset(\$c['user'], \$c['pass_hash']) && is_string(\$c['pass_hash'])) or exit(1);"

php_ok "download logging is switched off and writes nothing" \
    "use NeuroSYS\Config;
     use NeuroSYS\Service\DownloadLogger;
     \$f = '$REPO/data/logs/downloads.log';
     \$before = is_file(\$f) ? filesize(\$f) : -1;
     new DownloadLogger()->log('test-slug', NeuroSYS\Model\ReleaseFormat::FLAC);
     clearstatcache();
     \$after = is_file(\$f) ? filesize(\$f) : -1;
     (Config::DOWNLOAD_LOGGING === false && \$after === \$before) or exit(1);"

if [[ -f "$REPO/data/.htaccess" ]] && grep -qi 'Require all denied' "$REPO/data/.htaccess"; then
    pass "data/.htaccess denies web access (fallback if data/ ends up inside the webroot)"
else
    fail "data/.htaccess is missing or no longer denies access"
fi


echo ""
echo "=== Repo hygiene ==="
# Things that would only hurt once they are on the server.

# Every page is a tree of View\Html nodes, so markup written as a string is markup that skipped the
# escaping. The only two files allowed to hold a '<' in a literal are the ones whose job is to turn a
# tree into text — Element and Doctype. A heredoc anywhere under src/ is the same finding.
markup=$(grep -rlE "'[^']*<[a-zA-Z/!]|<<<'?HTML" "$REPO/src" 2>/dev/null \
         | grep -v "/View/Html/Element.php$" | grep -v "/View/Html/Doctype.php$" || true)
if [[ -z "$markup" ]]; then
    pass "no markup is built from strings outside View/Html/"
else
    fail "markup written as a string: $(echo "$markup" | tr '\n' ' ')"
fi

if grep -RIlq --exclude-dir=.git --exclude-dir=vendor --exclude-dir=.idea \
       -e '\$2[aby]\$[0-9]\{2\}\$' "$REPO/data/releases.php" "$REPO/data/profiles.php" 2>/dev/null; then
    fail "a bcrypt hash is sitting in a non-credential data file"
else
    pass "no credentials in releases.php / profiles.php"
fi

if git -C "$REPO" ls-files --error-unmatch data/site_auth.php >/dev/null 2>&1 \
   || git -C "$REPO" ls-files --error-unmatch deploy.sh >/dev/null 2>&1; then
    fail "a gitignored credential file is tracked by git"
else
    pass "site_auth.php and deploy.sh are untracked"
fi

# Every brand icon Platform names must exist, or the footer renders broken images.
php_ok "every vendored brand icon referenced by Platform exists" \
    "foreach (NeuroSYS\Model\Platform::cases() as \$p) {
         is_file('$REPO/public' . \$p->iconSrc()) or exit(1);
     }"

# .htaccess must pass every asset type through as a static file — Strato 500s otherwise.
missing_types=""
for ext in $(find "$REPO/public/assets" -type f | sed 's/.*\.//' | sort -u); do
    grep -q "$ext" "$REPO/public/.htaccess" || missing_types="$missing_types $ext"
done
if [[ -z "$missing_types" ]]; then
    pass "public/.htaccess handles every asset extension in use"
else
    fail "public/.htaccess is missing SetHandler for:$missing_types"
fi

# public/assets/css/style.css is generated from assets/css/ the same way, and for the same reason:
# deploy.sh rsyncs public/ from the working tree, so a part edited without a rebuild would ship a
# stylesheet nothing else notices is stale. Unlike the TypeScript below this needs no node_modules —
# tools/build-css.mjs has no dependencies — so it runs on a clone that has never seen `npm install`.
if command -v node >/dev/null 2>&1; then
    CSSOUT="$REPO/.csscheck/style.css"
    rm -rf "$REPO/.csscheck"
    if css_error=$(node "$REPO/tools/build-css.mjs" --out "$CSSOUT" 2>&1 >/dev/null); then
        if diff -q "$CSSOUT" "$REPO/public/assets/css/style.css" >/dev/null 2>&1; then
            pass "public/assets/css/style.css is current with assets/css/"
        else
            fail "public/assets/css/style.css has drifted from assets/css/ (run: npm run build)"
            diff "$CSSOUT" "$REPO/public/assets/css/style.css" | head -20 | sed 's/^/       /'
        fi
    else
        fail "assets/css/ does not build, so its output cannot be checked"
        echo "$css_error" | sed 's/^/       /'
    fi
    rm -rf "$REPO/.csscheck"
else
    echo "  SKIP assets/css/ drift check — no node on PATH"
fi

# public/assets/js/ is generated from assets/ts/ and committed, because deploy.sh rsyncs public/
# straight from the working tree. Both checks need the npm dev tooling; without it they are skipped
# rather than failed, so `composer test` still runs on a clone that has never seen `npm install`.
TSC="$REPO/node_modules/.bin/tsc"
if [[ -x "$TSC" ]]; then
    if (cd "$REPO" && "$TSC" --noEmit >/dev/null 2>&1); then
        pass "assets/ts/ type-checks"
    else
        fail "assets/ts/ has type errors (run: npm run check)"
    fi

    # The element and enum-parity tests run against the compiled output in public/assets/js/, so
    # they need the build to be current -- which the check below is what guarantees.
    if (cd "$REPO" && node --test >/dev/null 2>&1); then
        pass "the element and enum-parity tests pass"
    else
        fail "client-side tests failed (run: npm test)"
    fi

    # Editing a .ts and forgetting to rebuild would deploy stale JS, and nothing else would notice.
    # The scratch outDir has to sit exactly as deep as public/assets/js/ — three levels below the
    # repo root — or every .map's "sources" path differs and the diff fails for the wrong reason.
    TSOUT="$REPO/.tscheck/assets/js"
    rm -rf "$REPO/.tscheck"
    if (cd "$REPO" && "$TSC" --outDir "$TSOUT" >/dev/null 2>&1); then
        # --brief names the files rather than dumping them; "Only in" lines are the ones that
        # matter after a source is deleted, since tsc never removes what it no longer emits.
        drift=$(diff -rq "$TSOUT" "$REPO/public/assets/js" 2>&1 | sed "s|$TSOUT|<rebuilt>|g; s|$REPO/||g")
        if [[ -z "$drift" ]]; then
            pass "public/assets/js/ is current with assets/ts/"
        else
            fail "public/assets/js/ has drifted from assets/ts/ (run: npm run build)"
            echo "$drift" | sed 's/^/       /'
        fi
    else
        fail "assets/ts/ does not compile, so its output cannot be checked"
    fi
    rm -rf "$REPO/.tscheck"
else
    echo "  SKIP assets/ts/ checks — no node_modules (run: npm install)"
fi


echo ""
echo "=== HTTP routes ==="

# Start the built-in dev server in the background; kill it on exit.
#
# With NEUROSYS_COVERAGE_DIR set, the server runs under Xdebug with tools/coverage-prepend.php
# loaded, so the checks below contribute to a coverage report instead of being invisible to one.
# That is the only way the exit-ing auth code, the header() calls and the send() methods are ever
# measured -- they are a no-op or a different process everywhere else. See `composer coverage`.
if [[ -n "${NEUROSYS_COVERAGE_DIR:-}" ]]; then
    mkdir -p "$NEUROSYS_COVERAGE_DIR"
    # Absolute: the prepend script writes from the server process, whose working directory is
    # not something this script gets to decide.
    NEUROSYS_COVERAGE_DIR="$(cd "$NEUROSYS_COVERAGE_DIR" && pwd)"
    export NEUROSYS_COVERAGE_DIR
    XDEBUG_MODE=coverage php -d "auto_prepend_file=$REPO/tools/coverage-prepend.php" \
        -S "localhost:$PORT" -t "$REPO/public" >/dev/null 2>&1 &
else
    php -S "localhost:$PORT" -t "$REPO/public" >/dev/null 2>&1 &
fi
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
check_status "GET /releases/                     → 200" "$BASE/releases/"                      200
check_status "GET /releases/hello-world          → 200" "$BASE/releases/hello-world"           200
check_status "GET /releases/hello-world/flac     → 303" "$BASE/releases/hello-world/flac"      303
check_status "GET /releases/ill                  → 200" "$BASE/releases/ill"                   200
check_status "GET /releases/ill/flac             → 303" "$BASE/releases/ill/flac"              303
check_status "GET /imprint                       → 200" "$BASE/imprint"                        200
check_status "GET /privacy                       → 200" "$BASE/privacy"                        200
check_status "GET /releases/no-such-slug         → 404" "$BASE/releases/no-such-slug"          404
check_status "GET /releases/hello-world/badformat→ 404" "$BASE/releases/hello-world/badformat" 404
check_status "GET /notfound                      → 404" "$BASE/notfound"                       404

# Auth::requireAdminAuth() calls exit, so only a real request can prove it gates.
check_status "GET /admin/stats (no creds)        → 401" "$BASE/admin/stats"                    401
check_status "GET /admin/stats (wrong creds)     → 401" "$BASE/admin/stats"                    401


echo ""
echo "=== Rendered output ==="

# Download links must bypass Navigation, or the 303 is consumed by fetch and nothing downloads.
check_body "download cards carry data-no-spa"        "$BASE/releases/ill"  'data-no-spa'
# Nothing may be requested from SoundCloud before the visitor clicks the consent gate. The widget
# URL is built by <soundcloud-player>, so the served page carries no SoundCloud address at all —
# nothing for a browser to preconnect or prefetch ahead of the click.
check_body "no iframe before the consent gate"       "$BASE/releases/ill"  '<iframe'         absent
# w.soundcloud.com specifically: the footer's profile link to soundcloud.com is a plain href and
# loads nothing, but the widget host is what an iframe or a preconnect hint would reach for.
check_body "the widget host is nowhere in the page"  "$BASE/releases/ill"  'w.soundcloud.com'  absent
check_body "the player element is rendered"          "$BASE/releases/ill"  '<soundcloud-player'

# An element the browser has never heard of renders as an inert inline box with no error anywhere,
# so a tag reaching a page with no registration behind it is invisible. Checked in that direction:
# every custom tag the server actually serves has to be one the Tag enum names. The reverse is
# ViewTest's and vocabulary.test.mjs's — it would fail here on the terminal's own tags, which are
# registered but built by <terminal-window> rather than written out by any view.
#
# Read from the enum rather than grepped out of the TypeScript: the tag names stopped being string
# literals when Tag arrived, and the parity test is what ties this list to the client's copy.
registered=$(php -r "require '$REPO/autoload.php';
                     foreach (NeuroSYS\View\Html\Tag::cases() as \$t) echo \$t->value, PHP_EOL;" \
             | sort -u)
served=$(curl "${CURL_ARGS[@]}" "$BASE/releases" "$BASE/releases/ill" "$BASE/nope" 2>/dev/null \
         | grep -oE '<[a-z][a-z0-9]*-[a-z0-9-]+' | tr -d '<' | sort -u)
unregistered=""
for tag in $served; do
    grep -qx "$tag" <<< "$registered" || unregistered="$unregistered $tag"
done
if [[ -n "$served" && -z "$unregistered" ]]; then
    pass "every custom tag served is registered ($(wc -w <<< "$served") in use)"
else
    fail "custom tags served with no element behind them:$unregistered"
fi
# A PHP notice or warning leaking into the page means something is broken upstream.
check_body "no PHP errors leak into the home page"   "$BASE/"              'Warning'   absent
check_body "no PHP errors leak into a release page"  "$BASE/releases/ill"  'Fatal'     absent
check_body "the privacy policy is served"            "$BASE/privacy"       'Datenschutz'
# HiDrive receives the visitor's IP on a download, so the policy has to say so.
check_body "the privacy policy names HiDrive"        "$BASE/privacy"       'HiDrive'
check_body "the imprint carries a legal name"        "$BASE/imprint"       'Niclas Ahl'


echo ""
echo "=== Security headers ==="
# Sent from index.php before dispatch, so they cover every response the app produces.

check_header "Content-Security-Policy is sent"        "$BASE/"             "^content-security-policy:"
check_header "  script-src is strict"                 "$BASE/"             "script-src 'self'"
check_header "  style-src is strict too"                "$BASE/"             "style-src 'self';"
check_header "  only HiDrive may serve images"        "$BASE/"             "img-src 'self' data: https://my.hidrive.com"
check_header "  only SoundCloud may be framed"        "$BASE/"             "frame-src https://w.soundcloud.com"
check_header "  the site may not be framed"           "$BASE/"             "frame-ancestors 'none'"
check_header "Referrer-Policy is set"                 "$BASE/"             "^referrer-policy: strict-origin-when-cross-origin"
check_header "X-Content-Type-Options is set"          "$BASE/"             "^x-content-type-options: nosniff"
check_header "Permissions-Policy is set"              "$BASE/"             "^permissions-policy:"
# A download redirect is where the Referer would otherwise leak to the file host.
check_header "headers reach a 303 too"                "$BASE/releases/ill/flac" "^referrer-policy:"
check_header "headers reach a 404 too"                "$BASE/nope"         "^content-security-policy:"


echo ""
echo "=== Read-only method gate ==="
# Every route is a read; a write method must not be silently treated as a GET.

check_method "POST   /                    → 405" POST   "$BASE/"                           405
check_method "POST   /releases/ill/flac   → 405" POST   "$BASE/releases/ill/flac"          405
check_method "DELETE /releases/ill/flac   → 405" DELETE "$BASE/releases/ill/flac"          405
check_method "PUT    /admin/stats         → 405" PUT    "$BASE/admin/stats"                405
check_method "HEAD   /                    → 200" HEAD   "$BASE/"                           200
check_header "the 405 names the allowed methods" "$BASE/" "^allow: GET, HEAD" POST


# AJAX requests should return a page fragment, not a full HTML document.
check_spa_fragment "AJAX /         returns fragment only" "$BASE/"
check_spa_fragment "AJAX /releases returns fragment only" "$BASE/releases"
check_spa_fragment "AJAX /releases/ill returns fragment only" "$BASE/releases/ill"


echo ""
if [[ $FAIL -eq 0 ]]; then
    echo "All $PASS checks passed."
else
    echo "$PASS passed, $FAIL failed."
    exit 1
fi
