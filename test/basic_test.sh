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

# ext/uri ships with 8.5 and is what Element and Request parse URLs with. Bundled is not the same
# as present — a host can build without it — and the failure would be a fatal on every request, so
# it is asked for by name here as well as in composer.json. Checked on the live host before it was
# relied on; see docs/deployment.md.
if php -r 'exit(class_exists("Uri\\WhatWg\\Url") && class_exists("Uri\\Rfc3986\\Uri") ? 0 : 1);'; then
    pass "ext/uri is present — Element and Request have a URL parser"
else
    fail "ext/uri is missing; Element::isAllowedUrl() and Request::normalisePath() need it"
fi

# ext/dom is what MarkupParser reads the privacy policy with, and it is the same argument as ext/uri
# above one shelf along: bundled with PHP is not the same as built into this host's PHP. The failure
# is a fatal on /privacy alone — the one page here that is a legal obligation rather than a choice,
# and the one page a smoke test of the site's own markup would never reach. Checked on the live host
# before it was relied on, by parsing rather than by asking whether it is loaded; see CLAUDE.md.
if php -r 'exit(class_exists("Dom\\HTMLDocument") ? 0 : 1);'; then
    pass "ext/dom is present — the privacy policy can be parsed into the markup tree"
else
    fail "ext/dom is missing; MarkupParser::parse() needs it and /privacy would be a fatal"
fi

# ext/openssl verifies the update signature and ext/zlib unpacks the payload, so between them they
# are the whole of what /api needs beyond core. Both are in composer.json, and composer never
# runs on the server — vendor/ is not deployed — so this is the only place the question gets asked
# where it matters. The failure is a fatal on a push rather than on a page, which is the quietest
# kind: the endpoint answers as though it is not there for every other reason too.
if php -r 'exit(extension_loaded("openssl") && extension_loaded("zlib") ? 0 : 1);'; then
    pass "ext/openssl and ext/zlib are present — /api can verify and unpack a payload"
else
    fail "ext/openssl or ext/zlib is missing; PublicKey::verify() and UpdateApplier need them"
fi

# #[\NoDiscard] is what enforces that a copy-returning builder's result is used. It is an attribute,
# so a runtime without it ignores it silently rather than erroring — which is the whole guarantee
# quietly gone, with every test still green.
if php -r 'exit(class_exists("NoDiscard") ? 0 : 1);'; then
    pass "#[\\NoDiscard] exists — a discarded builder result is a warning"
else
    fail "#[\\NoDiscard] is missing; discarded builder results would pass unnoticed"
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
         if (!class_exists(\$class) && !interface_exists(\$class) && !enum_exists(\$class) && !trait_exists(\$class)) \$bad[] = \$class;
     }
     \$bad === [] or exit(1);"

# The same question of the development tooling, which has an autoloader of its own — `tools/` is not
# deployed, so the site's must not know about it. Nothing else reaches these classes: the CLI layer
# is outside the coverage source and the commands are run by hand, so a namespace that disagrees
# with its path would otherwise surface the first time someone ran the tool.
php_ok "every class under tools/lib/ actually loads" \
    "require '$REPO/tools/autoload.php';
     \$bad = [];
     \$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator('$REPO/tools/lib'));
     foreach (\$it as \$f) {
         if (\$f->getExtension() !== 'php') continue;
         \$rel = substr(\$f->getPathname(), strlen('$REPO/tools/lib/'));
         \$class = 'NeuroSYS' . chr(92) . 'Tool' . chr(92) . str_replace('/', chr(92), substr(\$rel, 0, -4));
         if (!class_exists(\$class) && !interface_exists(\$class) && !enum_exists(\$class) && !trait_exists(\$class)) \$bad[] = \$class;
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

# data/demos.php is gitignored, so most machines have none — which is a valid state and the reason
# DemoRepository is guarded where ReleaseRepository is not. Where there is one, a bad paste has to
# fail here rather than as a 401 nobody can get past.
if [[ -f "$REPO/data/demos.php" ]]; then
    php_ok "data/demos.php loads and every Demo constructs" \
        "foreach ((new NeuroSYS\Service\DemoRepository())->all() as \$slug => \$d) {
             \$d->tracks->isEmpty() and exit(1);
         }"
else
    echo "  SKIP data/demos.php — none on this machine (it is gitignored; that is a valid state)"
fi

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

# The tooling makes exactly one kind of outbound request — an upload to SoundCloud — and one class
# makes it, the way Probe is the one class that shells out. Options set once are options that cannot
# disagree between call sites, and two of them are load-bearing: certificates are verified, and
# redirects are not followed with a credential and a 50 MB body attached.
outbound=$(grep -rl "curl_" "$REPO/tools/lib" 2>/dev/null | grep -v "/Http/CurlTransport.php$" || true)
if [[ -z "$outbound" ]]; then
    pass "curl is called in one place under tools/lib/"
else
    fail "curl called outside Http/CurlTransport.php: $(echo "$outbound" | tr '\n' ' ')"
fi

# And the site makes none at all. That is a property rather than an accident — index.php answers
# requests and never issues one — and it is what the privacy policy rests on: no server-side call to
# a third party means no visitor's address reaching one. The SoundCloud client is tooling, and
# tools/ is never deployed.
phoning=$(grep -rlE "curl_(init|exec|setopt)|fsockopen|stream_socket_client" "$REPO/src" 2>/dev/null || true)
if [[ -z "$phoning" ]]; then
    pass "nothing under src/ makes an outbound request"
else
    fail "src/ phones out: $(echo "$phoning" | tr '\n' ' ')"
fi

# Signature verification happens in exactly one class, for the reason curl does under tools/lib/:
# options set once cannot disagree between call sites, and here the option that matters is the
# comparison itself. openssl_verify() returns 1, 0 or -1, and only 1 is a pass — a call site that
# wrote `if (openssl_verify(...))` would accept the error case as success and let an unsigned
# payload overwrite src/. PublicKey asks `=== 1` in one place so no second place can ask it wrongly.
verifying=$(grep -rl "openssl_" "$REPO/src" 2>/dev/null | grep -v "/Support/PublicKey.php$" || true)
if [[ -z "$verifying" ]]; then
    pass "openssl is called in one place under src/"
else
    fail "openssl called outside Support/PublicKey.php: $(echo "$verifying" | tr '\n' ' ')"
fi

# The site signs nothing. It holds the public half of the update key and can only ever check a
# signature; the private half never enters this repository at all. A signing call under src/ would
# mean a key had, or was about to.
signing=$(grep -rlE "openssl_(sign|pkey_get_private|pkey_new)" "$REPO/src" 2>/dev/null || true)
if [[ -z "$signing" ]]; then
    pass "nothing under src/ signs anything"
else
    fail "src/ signs or mints keys: $(echo "$signing" | tr '\n' ' ')"
fi

# The update key is per-deployment and its absence is the off switch, so a repository carrying one
# would both publish a deployment's key and switch the endpoint on for every clone.
if git -C "$REPO" ls-files --error-unmatch data/update.pub >/dev/null 2>&1; then
    fail "data/update.pub is tracked — it is per-deployment and must stay gitignored"
else
    pass "data/update.pub is not in the repository"
fi

# File::write() narrows the temporary file before it fills it, and the order is the whole
# guarantee rather than a detail of how it is written. file_put_contents() creates at the umask
# default — 0644 under the usual 022 — so a chmod placed *after* the write leaves the contents
# readable by anyone on the machine for exactly as long as the two calls take. The one thing this
# writes on a real machine is the SoundCloud refresh token, which is single-use and rotates.
#
# Asserted here because nothing at runtime can see that window: both orders end with the same file
# at the same mode, so PHPUnit can only check where it landed and never how it got there. Same
# instinct as the CSP being asserted at build time rather than observed at run time.
# Comment lines are stripped before the two are located, because the paragraph above the code says
# both names in prose — a check that read those would be asserting the explanation rather than the
# thing explained, and would pass on a method that had been rewritten the wrong way round.
write_body=$(sed -n '/public function write(/,/^    }$/p' "$REPO/src/NeuroSYS/Support/File.php" \
    | grep -vE '^[[:space:]]*(//|\*|/\*)')
narrowed_at=$(echo "$write_body" | grep -n "chmod(" | head -1 | cut -d: -f1)
filled_at=$(echo "$write_body" | grep -n "file_put_contents(" | head -1 | cut -d: -f1)
if [[ -n "$narrowed_at" && -n "$filled_at" && "$narrowed_at" -lt "$filled_at" ]]; then
    pass "File::write() applies its mode before the contents"
else
    fail "File::write() chmods at line ${narrowed_at:-none} and writes at line ${filled_at:-none} — a credential is world-readable in between"
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

# src/NeuroSYS/AssetManifest.php is generated and committed, because Layout reads it for the
# stylesheet href, the script src and the whole <link rel="modulepreload"> list. Stale, it is the
# quiet kind of wrong: the page still works, it just names a version that is no longer what is at
# that URL — so a visitor is served a year-immutable copy of the wrong thing, or the preload hints
# miss and every module is fetched twice.
#
# Stale, it is the quiet kind of wrong: the page still works, it just names a build stamp that is no
# longer what is at those URLs — so a visitor is handed a year-immutable copy of the wrong thing, or
# every preload hint misses and each module is fetched twice. build-assets.mjs writes nothing but the
# manifest, so this can run against the real tree; like build-css.mjs it reads only committed files
# and needs no node_modules.
if command -v node >/dev/null 2>&1; then
    ASSETOUT="$REPO/.assetcheck/AssetManifest.php"
    rm -rf "$REPO/.assetcheck"
    if asset_error=$(node "$REPO/tools/build-assets.mjs" --out "$ASSETOUT" 2>&1 >/dev/null); then
        if diff -q "$ASSETOUT" "$REPO/src/NeuroSYS/AssetManifest.php" >/dev/null 2>&1; then
            pass "src/NeuroSYS/AssetManifest.php is current with the built assets"
        else
            fail "src/NeuroSYS/AssetManifest.php has drifted from the built assets (run: npm run build)"
            diff "$ASSETOUT" "$REPO/src/NeuroSYS/AssetManifest.php" | head -20 | sed 's/^/       /'
        fi
    else
        fail "the assets do not stamp, so the manifest cannot be checked"
        echo "$asset_error" | sed 's/^/       /'
    fi
    rm -rf "$REPO/.assetcheck"
else
    echo "  SKIP asset manifest drift check — no node on PATH"
fi

# The version segment is a mirror: public/.htaccess strips it in production, tools/dev-router.php
# strips it under the php -S this script runs. Two spellings of one rule, in two languages, with
# nothing but this check between them — drift and the dev server serves a 404 for a URL that works
# live, or worse, the reverse.
htaccess_shape=$(grep -oE 'assets/\(js\|css\)/v-\[0-9a-f\]\{8\}' "$REPO/public/.htaccess" | head -1)
router_shape=$(grep -oE 'assets/\(js\|css\)/v-\[0-9a-f\]\{8\}' "$REPO/tools/dev-router.php" | head -1)
if [[ -n "$htaccess_shape" && "$htaccess_shape" == "$router_shape" ]]; then
    pass "the version segment is stripped identically by .htaccess and the dev router"
else
    fail "the version-segment pattern differs between public/.htaccess and tools/dev-router.php"
    echo "       .htaccess: ${htaccess_shape:-<not found>}" 
    echo "       router:    ${router_shape:-<not found>}"
fi

# public/assets/js/ is generated from assets/ts/ and committed, because deploy.sh rsyncs public/
# straight from the working tree. Both checks need the npm dev tooling; without it they are skipped
# rather than failed, so `composer test` still runs on a clone that has never seen `npm install`.
TSC="$REPO/node_modules/.bin/tsc"
if [[ -x "$TSC" ]]; then
    # Three configs: assets/ts/ emits, tools/*.mjs and test/js/*.mjs are checked in place. The
    # second is the one worth having here — those four tools write the three committed artefacts
    # this script goes on to diff, and until they were checked a crash on an untaken path was
    # invisible to everything.
    if (cd "$REPO" && "$TSC" --noEmit >/dev/null 2>&1 \
        && "$TSC" -p "$REPO/tsconfig.tools.json" >/dev/null 2>&1 \
        && "$TSC" -p "$REPO/tsconfig.test.json" >/dev/null 2>&1); then
        pass "assets/ts/, tools/*.mjs and test/js/*.mjs type-check"
    else
        fail "the front end or its tooling has type errors (run: npm run check)"
    fi

    # The element and enum-parity tests run against the compiled output in public/assets/js/, so
    # they need the build to be current -- which the check below is what guarantees.
    if (cd "$REPO" && node --test 'test/js/*.test.mjs' >/dev/null 2>&1); then
        pass "the element and enum-parity tests pass"
    else
        fail "client-side tests failed (run: npm test)"
    fi

    # Editing a .ts and forgetting to rebuild would deploy stale JS, and nothing else would notice.
    # The scratch outDir has to sit exactly as deep as public/assets/js/ — three levels below the
    # repo root — or every .map's "sources" path differs and the diff fails for the wrong reason.
    #
    # A straight diff, because build-assets.mjs writes no JS: the version lives in the URL path, not
    # in any file, so the committed output stays byte-identical to what tsc emits. That is the whole
    # reason the version is a path segment — see the tool.
    TSOUT="$REPO/.tscheck/assets/js"
    rm -rf "$REPO/.tscheck"
    if (cd "$REPO" && "$TSC" --outDir "$TSOUT" >/dev/null 2>&1) \
       && node "$REPO/tools/build-assets.mjs" --js-dir "$TSOUT" \
               --out "$REPO/.tscheck/AssetManifest.php" >/dev/null 2>&1; then
        # --brief names the files rather than dumping them; "Only in" lines are the ones that
        # matter after a source is deleted, since tsc never removes what it no longer emits.
        drift=$(diff -rq "$TSOUT" "$REPO/public/assets/js" 2>&1 | sed "s|$TSOUT|<rebuilt>|g; s|$REPO/||g")
        if [[ -z "$drift" ]]; then
            pass "public/assets/js/ is current with assets/ts/"
        else
            fail "public/assets/js/ has drifted from assets/ts/ (run: npm run build)"
            echo "$drift" | sed 's/^/       /'
        fi

        if diff -q "$REPO/.tscheck/AssetManifest.php" "$REPO/src/NeuroSYS/AssetManifest.php" >/dev/null 2>&1; then
            pass "the manifest matches a build from the TypeScript sources"
        else
            fail "src/NeuroSYS/AssetManifest.php does not match a full rebuild (run: npm run build)"
        fi
    else
        fail "assets/ts/ does not compile, so its output cannot be checked"
    fi
    rm -rf "$REPO/.tscheck"

    # ── the prod tree ───────────────────────────────────────────────────────────────────────────
    #
    # public/ is the debug tree: readable, mapped, committed, forty-nine separate modules, and
    # everything above this line is about keeping it in step with assets/ts/. build/dist/ is what
    # actually ships — that same graph bundled into one module and minified, with the maps dropped,
    # built by tools/build-prod.mjs and rsynced by deploy.sh.
    #
    # Nothing above can see it, and neither can PHPUnit. Three failure modes live here and every one
    # of them is invisible in a browser until it is live:
    #
    #   - a surviving `//# sourceMappingURL` is a 404 the moment DevTools opens
    #   - a tree that copied instead of bundling ships forty-nine files under a manifest naming one
    #   - a bad mangle is an element that registers and then does nothing
    #
    # The last is the one worth the most, and it is checked by re-running the whole client suite
    # against the shipped bytes: test/js/dom.mjs takes the tree from NEUROSYS_JS_DIR, so the nesting
    # guards, TerminalWindow's subtree, both embeds and Navigation all execute what the server will
    # send. That still works across the bundling change, and it is worth knowing why rather than
    # being lucky: dom.mjs loads the whole vocabulary through one `import ${JS}/main.js` and every
    # element test then goes through the DOM, so not one of them names a module path. A bundle is
    # simply what that single import resolves to now. Nothing else about the tests changes, and the
    # coverage gate is untouched because it takes the default.
    #
    # This runs build-prod.mjs directly rather than `npm run build:prod`, because the block above
    # has already proven public/ current and rebuilding it here would just be slower.
    if node "$REPO/tools/build-prod.mjs" >/dev/null 2>&1; then
        pass "the prod tree builds"

        DIST_JS="$REPO/build/dist/public/assets/js"

        # `find -quit` rather than a count: one map is as wrong as forty-two.
        if [[ -z "$(find "$REPO/build/dist/public" -name '*.map' -print -quit)" ]] \
           && ! grep -rq sourceMappingURL "$DIST_JS"; then
            pass "the prod tree ships no source map, and names none"
        else
            fail "the prod tree still carries source maps (see tools/build-prod.mjs)"
        fi

        if (cd "$REPO" && NEUROSYS_JS_DIR="$DIST_JS" node --test 'test/js/*.test.mjs' >/dev/null 2>&1); then
            pass "the client-side tests pass against the minified output"
        else
            fail "the minified output fails the client-side tests — a mangle broke something"
            # Absolute deliberately: dom.mjs interpolates this into an import specifier, and a
            # relative one resolves as a *package* name — "Cannot find package 'build'".
            echo "       reproduce: NEUROSYS_JS_DIR=\$PWD/build/dist/public/assets/js npm test"
        fi

        # The two manifests deliberately differ now, so diffing them would assert away the thing
        # this build exists to do: the prod tree ships one bundle, so its MODULES is empty where the
        # committed one lists all forty-six. What still has to hold is narrower, and is three
        # separate facts rather than one file comparison.
        DIST_MANIFEST="$REPO/build/dist/src/NeuroSYS/AssetManifest.php"
        SRC_MANIFEST="$REPO/src/NeuroSYS/AssetManifest.php"

        # A string constant with the build stamp normalised away — those are different bytes at the
        # same path, which is the whole reason the two trees have separate stamps.
        manifest_url() {
            sed -nE "s/^ *public const string $2 = '([^']*)';.*/\1/p" "$1" \
                | sed -E 's#/v-[0-9a-f]{8}/#/v-STAMP/#'
        }

        if [[ "$(manifest_url "$SRC_MANIFEST" SCRIPT)" == "$(manifest_url "$DIST_MANIFEST" SCRIPT)" \
           && "$(manifest_url "$SRC_MANIFEST" STYLESHEET)" == "$(manifest_url "$DIST_MANIFEST" STYLESHEET)" ]]; then
            pass "the prod manifest points at the same entry and stylesheet as the committed one"
        else
            fail "the prod manifest names a different entry or stylesheet path than the committed one"
        fi

        # Empty is the bundled shape. A list here means build-prod.mjs copied the tree instead of
        # bundling it, and the page would then preload forty-six modules that are no longer served.
        if grep -q 'public const array MODULES = \[\];' "$DIST_MANIFEST"; then
            pass "the prod manifest preloads nothing, as a bundled tree should"
        else
            fail "the prod manifest still lists preloads — the prod tree did not bundle"
        fi

        # The manifest is generated from the tree, so the only way this fails is the URL base being
        # wrong — which is exactly how it has failed before, and is invisible until it is live.
        script_url=$(sed -nE "s/^ *public const string SCRIPT = '([^']*)';.*/\1/p" "$DIST_MANIFEST")
        script_file="$REPO/build/dist/public$(sed -E 's#/(assets/js)/v-[0-9a-f]{8}/#/\1/#' <<< "$script_url")"

        if [[ -f "$script_file" ]]; then
            pass "the prod manifest's entry URL has bytes behind it"
        else
            fail "the prod manifest names $script_url, and $script_file does not exist"
        fi
    else
        fail "the prod tree does not build (run: npm run build:prod)"
    fi
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
        -S "localhost:$PORT" -t "$REPO/public" "$REPO/tools/dev-router.php" >/dev/null 2>&1 &
else
    php -S "localhost:$PORT" -t "$REPO/public" "$REPO/tools/dev-router.php" >/dev/null 2>&1 &
fi
SERVER_PID=$!
trap "kill $SERVER_PID 2>/dev/null; wait $SERVER_PID 2>/dev/null" EXIT

# Both branches above must pass the router, or the versioned asset URLs 404 in one of them and not
# the other — `composer test` green and `composer coverage` not. See docs/history/frontend.md.
# `dev-rou[t]er` so the pattern does not match the line it is written on — the same idiom as
# `ps aux | grep [f]oo`. Without it this counts itself and passes with one invocation patched.
if [[ $(grep -cE -- '-S "localhost:\$PORT" -t "\$REPO/public" "\$REPO/tools/dev-rou[t]er\.php"' "$0") -eq 2 ]]; then
    pass "both dev-server invocations load the version-stripping router"
else
    fail "one of the php -S invocations in this script is missing tools/dev-router.php"
fi

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

# Targets parse_url() will not parse. It returns false on failure and `?? '/'` only catches null, so
# read that way each of these is an uncaught TypeError in fromGlobals() — a 500 ahead of the router
# and the read-only gate. Request::normalisePath() asks the RFC 3986 parser instead.
# Worth a real request rather than only a unit test: what was wrong was the status code, and PHPUnit
# sees an exception either way.
check_status "GET /// is the root                → 200" "$BASE///"                             200
check_status "GET //host:notaport/x              → 404" "$BASE//host:notaport/x"               404
check_method "POST /// is still refused          → 405" POST "$BASE///"                        405

# Auth::requireAdminAuth() calls exit, so only a real request can prove it gates.
check_status "GET /admin/stats (no creds)        → 401" "$BASE/admin/stats"                    401
check_status "GET /admin/stats (wrong creds)     → 401" "$BASE/admin/stats"                    401


echo ""
echo "=== Demos ==="
# The half of the site whose gate covers bytes rather than only a page — and almost none of it is
# visible to PHPUnit. Auth::requireDemoAuth() calls exit, and header() is a no-op under CLI, so the
# 401, the 206, the 416 and every header below are invisible there. DemoTest covers the decisions;
# this covers the responses.
#
# It writes its own data/demos.php and puts back whatever was there. That is not laziness about
# fixtures: the real file is gitignored, so there is not reliably one to test against, and the
# passwords behind its hashes are by design not recoverable. The restore is in the EXIT trap so an
# interrupt cannot leave the file swapped out.

DEMO_SLUG="verify-fixture"
DEMO_PASS="VERIF-YFIXT-UREPA-SSWRD"
DEMOS_FILE="$REPO/data/demos.php"
DEMOS_SAVED="$(mktemp)"
DEMO_DIR="$REPO/data/demos/$DEMO_SLUG"
DEMOS_SWAPPED=0

restore_demos() {
    [[ $DEMOS_SWAPPED -eq 1 ]] || return 0
    rm -rf "$DEMO_DIR"
    if [[ -s "$DEMOS_SAVED" ]]; then
        mv -f "$DEMOS_SAVED" "$DEMOS_FILE"
    else
        rm -f "$DEMOS_FILE" "$DEMOS_SAVED"
    fi
    rmdir "$REPO/data/demos" 2>/dev/null || true
    DEMOS_SWAPPED=0
}

if [[ -f "$DEMOS_FILE" ]]; then
    cp -p "$DEMOS_FILE" "$DEMOS_SAVED"
fi

# Cost 4 is bcrypt's minimum; password_verify() reads the cost out of the hash, so the gate runs
# exactly the code a real one does.
DEMO_HASH=$(php -r "echo password_hash('$DEMO_PASS', PASSWORD_BCRYPT, ['cost' => 4]);")

mkdir -p "$DEMO_DIR"
# Not real audio, and it does not need to be: MimeType::forAudio() reads the extension and
# FileResponse counts bytes. What is under test is the arithmetic and the status codes.
printf '0123456789' > "$DEMO_DIR/v1.mp3"

# A waveform beside it, written through the same class the tooling writes one with. This is the only
# place the whole path runs end to end — a file on disk, read by WaveformRepository, base64'd into
# an attribute by DemoView — and every step of it is invisible to PHPUnit, which never asks a server
# for a gated page. Analysing real audio would cost ten seconds and prove nothing extra: what is
# under test is that the sidecar is found and reaches the markup, not what is in it.
php -r "require '$REPO/autoload.php';
    \$columns = array_fill(0, NeuroSYS\Model\Waveform::COLUMNS,
        new NeuroSYS\Model\WaveformColumn(0.5, -6.0, -18.0, -30.0));
    NeuroSYS\Model\Waveform::fileIn(new NeuroSYS\Support\Directory('$DEMO_DIR'), 'v1')
        ->write(NeuroSYS\Model\Waveform::of(...\$columns)->bytes());"

cat > "$DEMOS_FILE" <<PHPFIXTURE
<?php
declare(strict_types=1);
return [
    '$DEMO_SLUG' => new NeuroSYS\Model\Demo(
        'verify fixture',
        new NeuroSYS\Support\PasswordHash('$DEMO_HASH'),
        new NeuroSYS\Support\Collection(NeuroSYS\Model\DemoTrack::class)->with(
            new NeuroSYS\Model\DemoTrack('v1', 'v1.mp3', 10),
        ),
    ),
];
PHPFIXTURE

DEMOS_SWAPPED=1
trap "restore_demos; kill $SERVER_PID 2>/dev/null; wait $SERVER_PID 2>/dev/null" EXIT

# There is no /demos. A listing would publish the names of unreleased tracks, which is the one
# thing this half of the site is arranged to keep quiet.
check_status "GET /demos is not a route          → 404" "$BASE/demos"                          404

check_status "GET /demos/{slug} (no creds)       → 401" "$BASE/demos/$DEMO_SLUG"               401
check_status "GET /demos/{slug}/{label} too      → 401" "$BASE/demos/$DEMO_SLUG/v1"            401

# The one that is easy to get wrong and impossible to notice: a 404 for a slug that names nothing
# and a 401 for one that names something is a catalogue readable one guess at a time.
check_status "GET /demos/no-such-demo            → 401" "$BASE/demos/no-such-demo"             401
check_status "  and its audio route as well      → 401" "$BASE/demos/no-such-demo/v1"          401

check_method "POST /demos/{slug} is still refused→ 405" POST "$BASE/demos/$DEMO_SLUG"          405

# The realm is what a browser keys saved credentials by. One shared realm across every demo is a
# browser volunteering one demo's password at another demo's prompt.
demo_realm=$(curl "${CURL_ARGS[@]}" -o /dev/null -D - "$BASE/demos/$DEMO_SLUG" 2>/dev/null \
             | tr -d '\r' | grep -i '^www-authenticate:' || true)
if [[ "$demo_realm" == *"demo: $DEMO_SLUG"* ]]; then
    pass "  each demo challenges in its own realm"
else
    fail "the demo challenge does not name the demo: ${demo_realm:-<none>}"
fi

# Everything past here has to hand over the demo's password, and a request carries exactly one
# Basic credential. So where the pre-launch site gate is active it has already claimed that header,
# and no request can satisfy both gates — which is a real property of the site rather than a gap in
# this script, and is why docs/demos.md says demos are unreachable while the site gate is on.
if [[ -f "$REPO/data/site_auth.php" ]]; then
    echo "  SKIP the rest of the demo checks — the site gate is active, and Basic Auth carries"
    echo "       one credential per request, so no request can satisfy both gates. See docs/demos.md."
else
    CURL_ARGS_WITHOUT_DEMO=("${CURL_ARGS[@]}")
    CURL_ARGS+=(-u "demo:$DEMO_PASS")

    check_status "GET /demos/{slug} (right password) → 200" "$BASE/demos/$DEMO_SLUG"           200

    check_header "  the page is not to be stored"       "$BASE/demos/$DEMO_SLUG"  "^cache-control: no-store, private"
    check_header "  nor indexed"                        "$BASE/demos/$DEMO_SLUG"  "^x-robots-tag: noindex"
    # No validator, so no 304: a page reached by handing over a password must not come back on a
    # guessed ETag. ViewResponse stands down because the controller already said how it may be kept.
    check_no_header "  and carries no validator"        "$BASE/demos/$DEMO_SLUG"  "^etag:"

    # The audio. This is the difference between a demo and a release: a release redirects to a
    # HiDrive share URL anyone can forward, and these bytes are under data/, which Apache cannot
    # reach at all — so this route is the only way to them, and it asks for the password first.
    # The waveform reached the page. It is a decoration, so nothing anywhere fails when it does not
    # — which is exactly why the one end-to-end check of it is worth having.
    demo_page=$(curl "${CURL_ARGS[@]}" "$BASE/demos/$DEMO_SLUG" 2>/dev/null || true)
    if grep -q '<demo-waveform' <<< "$demo_page"; then
        pass "  the card is the waveform element"
    else
        fail "the demo card is not a <demo-waveform> — the sidecar did not reach the view"
    fi

    demo_peaks=$(grep -oE 'peaks="[A-Za-z0-9+/=]+"' <<< "$demo_page" | head -1 | sed 's/peaks="//;s/"$//')
    demo_bytes=$(php -r "echo strlen(base64_decode('$demo_peaks', true));")
    demo_wanted=$(php -r "require '$REPO/autoload.php';
        echo NeuroSYS\Model\Waveform::COLUMNS * NeuroSYS\Model\WaveformBand::stride();")
    if [[ "$demo_bytes" == "$demo_wanted" ]]; then
        pass "  and carries every column of it ($demo_bytes bytes)"
    else
        fail "the peaks attribute decoded to $demo_bytes bytes, wanted $demo_wanted"
    fi

    check_header "the audio declares what it is"        "$BASE/demos/$DEMO_SLUG/v1"  "^content-type: audio/mpeg"
    check_header "  and that it can be asked in parts"  "$BASE/demos/$DEMO_SLUG/v1"  "^accept-ranges: bytes"
    check_header "  and how long it is"                 "$BASE/demos/$DEMO_SLUG/v1"  "^content-length: 10"
    check_no_header "  and is not to be stored either"  "$BASE/demos/$DEMO_SLUG/v1"  "^etag:"

    # Ranges, which are not a nicety: an <audio> element seeks by asking for one, so a server that
    # ignores them gives a player that plays and will not skip, with nothing in any console.
    demo_body=$(curl "${CURL_ARGS[@]}" -r 3-5 "$BASE/demos/$DEMO_SLUG/v1" 2>/dev/null || true)
    demo_code=$(curl "${CURL_ARGS[@]}" -r 3-5 -o /dev/null -w '%{http_code}' "$BASE/demos/$DEMO_SLUG/v1" 2>/dev/null || true)
    if [[ "$demo_code" == "206" && "$demo_body" == "345" ]]; then
        pass "a range request gets exactly the bytes it named (206)"
    else
        fail "a range request returned $demo_code with '$demo_body' (wanted 206 and '345')"
    fi

    demo_headers=$(curl "${CURL_ARGS[@]}" -r 3-5 -o /dev/null -D - "$BASE/demos/$DEMO_SLUG/v1" 2>/dev/null | tr -d '\r' || true)
    if grep -qi '^content-range: bytes 3-5/10' <<< "$demo_headers"; then
        pass "  and says which part it sent"
    else
        fail "the 206 did not state its Content-Range"
    fi

    demo_code=$(curl "${CURL_ARGS[@]}" -r 500- -o /dev/null -w '%{http_code}' "$BASE/demos/$DEMO_SLUG/v1" 2>/dev/null || true)
    if [[ "$demo_code" == "416" ]]; then
        pass "a range past the end is refused (416), not answered with the whole file"
    else
        fail "a range past the end returned $demo_code, wanted 416"
    fi

    # Nothing in a URL may name a file. The last segment is matched against declared labels, never
    # resolved as a path — so a traversal is a label naming no track, which is a 404 like any other.
    check_status "  no label names a file             → 404" "$BASE/demos/$DEMO_SLUG/v2"       404
    check_status "  nor does an encoded traversal     → 404" "$BASE/demos/$DEMO_SLUG/%2e%2e%2fadmin.php" 404

    CURL_ARGS=("${CURL_ARGS_WITHOUT_DEMO[@]}")
fi

restore_demos
trap "kill $SERVER_PID 2>/dev/null; wait $SERVER_PID 2>/dev/null" EXIT

# And with the fixture gone, the route answers as it does on a machine with no demos at all: still
# a 401, never a 404, so the absence of a demo is as unreadable as the presence of one.
check_status "with no demos at all, still         → 401" "$BASE/demos/$DEMO_SLUG"              401


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

# The home page carries the profile player, which is the same promise on the page most visitors
# land on first. It is sent even less than the track player is — no id, no handle, no title, since
# the element mirrors the handle itself — so the artist's own address is absent here too.
check_body "no iframe on the home page either"       "$BASE/"              '<iframe'           absent
check_body "the widget host is nowhere on the home page" "$BASE/"          'w.soundcloud.com'  absent
check_body "the profile element is rendered"         "$BASE/"              '<soundcloud-profile'

# An element the browser has never heard of renders as an inert inline box with no error anywhere,
# so a tag reaching a page with no registration behind it is invisible. Checked in that direction:
# every custom tag the server actually serves has to be one the Tag enum names. The reverse is
# ViewTest's and vocabulary.test.mjs's — it would fail here on the terminal's own tags, which are
# registered but built by <terminal-window> rather than written out by any view.
#
# Read from the enum rather than grepped out of the TypeScript: the tag names are Tag cases, not
# string literals, and the parity test is what ties this list to the client's copy.
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
# The drift check above proves the list matches the graph. It cannot prove the list points at
# anything: the href is the graph path under a URL base the tool writes by hand, so every entry can
# be perfectly in step and still 404. That is the quietest failure available here — the page works,
# the module is simply fetched late the slow way, and the console offers at most an unused-preload
# notice nobody is reading. So ask the server for each one.
preloaded=$(curl "${CURL_ARGS[@]}" "$BASE/" 2>/dev/null \
            | grep -oE 'rel="modulepreload" href="[^"]+"' | sed 's/.*href="//; s/"$//')
unresolved=""
for module in $preloaded; do
    code=$(curl "${CURL_ARGS[@]}" -o /dev/null -w "%{http_code}" "$BASE$module") || true
    [[ "$code" == 200 ]] || unresolved="$unresolved $module($code)"
done
if [[ -n "$preloaded" && -z "$unresolved" ]]; then
    pass "every preloaded module resolves ($(wc -w <<< "$preloaded") hinted)"
else
    fail "modulepreload hints that do not resolve:$unresolved"
fi
# The entry point is the <script src>; hinting it too would be a redundant fetch instruction.
# No trailing quote in the needle: the href carries a ?v= now, so matching the closing quote would
# make this pass by never matching anything rather than by the entry point being absent.
check_body "the stylesheet URL carries the build stamp"  "$BASE/"  'href="/assets/css/v-'
check_body "the entry script URL carries it too"        "$BASE/"  'src="/assets/js/v-'
# The stamp sits between the prefix and the filename, so this is the one needle that cannot be a
# plain substring. main.js is the <script src> already in flight; preloading it as well would be a
# second instruction to fetch a file the browser is on its way to fetching.
if curl "${CURL_ARGS[@]}" "$BASE/" 2>/dev/null | grep -qE 'rel="modulepreload" href="[^"]*/main\.js"'; then
    fail "the entry point is preloaded as well as being the script src"
else
    pass "the entry point is not also preloaded"
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

# The credentials on both Basic Auth gates are base64, so a plaintext request has already put them
# on the wire. The .htaccess redirect fixes that request; this header stops there being another.
check_header "Strict-Transport-Security is sent"      "$BASE/"             "^strict-transport-security:"
check_header "  it lasts a year"                      "$BASE/"             "max-age=31536000"
check_header "  and covers subdomains"                "$BASE/"             "includeSubDomains"
check_header "Content-Security-Policy is sent"        "$BASE/"             "^content-security-policy:"
check_header "  script-src is strict"                 "$BASE/"             "script-src 'self'"
check_header "  style-src is strict too"                "$BASE/"             "style-src 'self';"
check_header "  only HiDrive may serve images"        "$BASE/"             "img-src 'self' https://my.hidrive.com;"
# data: in img-src covered nothing here and widens where bytes may come from. Asserted over HTTP as
# well as in SecurityTest because a scheme source is what gets pasted back in to debug a broken image.
check_no_header "  and no image may be a data: URI"  "$BASE/"             "^content-security-policy:.*data:"
check_header "  only SoundCloud may be framed"        "$BASE/"             "frame-src https://w.soundcloud.com"
check_header "  the site may not be framed"           "$BASE/"             "frame-ancestors 'none'"
check_header "Referrer-Policy is set"                 "$BASE/"             "^referrer-policy: strict-origin-when-cross-origin"
check_header "X-Content-Type-Options is set"          "$BASE/"             "^x-content-type-options: nosniff"
check_header "Permissions-Policy is set"              "$BASE/"             "^permissions-policy:"
# PHP appends this before any of our code runs, so SecurityHeaders::send() removes it. Only visible
# on a real server -- header() and header_remove() are both no-ops under CLI, so PHPUnit cannot see
# either. expose_php is On in this dev server's ini, which is what makes the assertion mean anything.
check_no_header "no PHP version is disclosed"         "$BASE/"             "^x-powered-by:"
# A download redirect is where the Referer would otherwise leak to the file host.
check_header "headers reach a 303 too"                "$BASE/releases/ill/flac" "^referrer-policy:"
check_header "transport policy reaches a 401 too"     "$BASE/admin/stats"  "^strict-transport-security:"
check_header "headers reach a 404 too"                "$BASE/nope"         "^content-security-policy:"

# ViewResponse sends Content-Type itself rather than inheriting PHP's default_mimetype, which is
# right only by accident of the runtime's ini. It matters most for the AJAX fragment,
# which carries no <meta charset> of its own, so the header is all a browser has to go on.
check_header "a page declares its type and encoding"  "$BASE/"             "^content-type: text/html; charset=utf-8"
check_header "  the 404 does too"                     "$BASE/nope"         "^content-type: text/html; charset=utf-8"
check_header "  and the 405 says plain text"          "$BASE/"             "^content-type: text/plain; charset=utf-8" "POST"


echo ""
echo "=== Caching ==="
# Documents carry no Cache-Control at all until this block passes. The pairing that makes it worth
# having is that a document names last build's asset URLs, and .htaccess marked those immutable for
# a year — so a stale document plus a fresh deploy is old JS against new HTML. `no-cache` removes
# the window rather than bounding it: the browser keeps the page and asks, and usually gets a 304.

check_header "a document says it must be revalidated" "$BASE/"       "^cache-control: no-cache"
check_header "  and hands out a validator to do it with" "$BASE/"    "^etag: \""
check_header "  and names the header its body depends on" "$BASE/"   "^vary: X-Requested-With"
check_header "  and the bilingual pages name the language too" "$BASE/imprint" \
    "^vary: X-Requested-With, Accept-Language"
check_header "  which the pages that are not do not" "$BASE/releases" "^vary: X-Requested-With$"
check_revalidates "an unchanged document comes back as a 304" "$BASE/"
check_revalidates "  and so does a release page" "$BASE/releases/ill"
check_revalidates "  and the 404, which is a document like any other" "$BASE/nope"

# One URL, two bodies. Vary is what tells a cache; the validators differing is the belt to that
# brace, and is what would still hold in a cache that ignored it.
DOC_ETAG=$(curl "${CURL_ARGS[@]}" -o /dev/null -D - "$BASE/" 2>/dev/null | tr -d '\r' | grep -i '^etag:')
FRAG_ETAG=$(curl "${CURL_ARGS[@]}" -H "X-Requested-With: XMLHttpRequest" -o /dev/null -D - "$BASE/" 2>/dev/null \
            | tr -d '\r' | grep -i '^etag:')
if [[ -n "$DOC_ETAG" && "$DOC_ETAG" != "$FRAG_ETAG" ]]; then
    pass "the fragment and the document do not share a validator"
else
    fail "the fragment and the document share a validator ($DOC_ETAG)"
fi

# The gated page opts out of all of it, and the three responses that never become a ViewResponse
# carry none of it either.
check_no_header "the gated page hands out no validator"   "$BASE/admin/stats"        "^etag:"
check_no_header "a 303 is not cacheable"                  "$BASE/releases/ill/flac"  "^cache-control:"
check_no_header "  and neither is the 405"                "$BASE/"                   "^cache-control:" "POST"

echo ""
echo "=== Language negotiation ==="
# The imprint and the privacy policy carry a German half and an English half, and lead with
# whichever the visitor asked for. Both halves are always sent — the German imprint is what
# discharges § 5 DDG, so a language guess can cost a scroll and never a document.
#
# Only the verify script can see this end to end: it is the request header, the negotiation, the
# ordering and the Vary that has to hold them together, and a unit test can hold at most three.

check_language_order() {
    local desc="$1"; local url="$2"; local accept="$3"; local first="$4"; local second="$5"
    local body before_first before_second
    body=$(curl "${CURL_ARGS[@]}" -H "Accept-Language: $accept" "$url" 2>/dev/null) || true

    if [[ "$body" != *"$first"* || "$body" != *"$second"* ]]; then
        fail "$desc (the page is missing one of its two halves)"
        return
    fi

    # Prefix removal rather than `grep -bo -m1`: grep exiting on the first match closes the pipe
    # under it, and the printf feeding it then dies of SIGPIPE mid-run. Pure bash has no pipe.
    before_first="${body%%"$first"*}"
    before_second="${body%%"$second"*}"

    if (( ${#before_first} < ${#before_second} )); then
        pass "$desc"
    else
        fail "$desc ('$first' came after '$second')"
    fi
}

check_language_order "a German browser reads the Impressum first" "$BASE/imprint" \
    "de-DE,de;q=0.9,en;q=0.8" "<h1>Impressum</h1>" "<h1>Imprint</h1>"
check_language_order "  and an English one reads the Imprint first" "$BASE/imprint" \
    "en-GB,en;q=0.9" "<h1>Imprint</h1>" "<h1>Impressum</h1>"
check_language_order "  a browser asking for neither gets the site's own language" "$BASE/imprint" \
    "fr,es;q=0.8" "<h1>Imprint</h1>" "<h1>Impressum</h1>"
check_language_order "  and no header at all is the same as asking for neither" "$BASE/imprint" \
    "" "<h1>Imprint</h1>" "<h1>Impressum</h1>"
check_language_order "the policy orders its two halves the same way" "$BASE/privacy" \
    "de" "Datenschutz" "Privacy Policy"

check_body "the German imprint is sent to an English reader too" "$BASE/imprint" "<h1>Impressum</h1>"
check_body "  and each half says which language it is" "$BASE/imprint" '<section lang="de">'

# The two orderings are different bytes, so they cannot validate against each other even in a cache
# that ignored Vary. Same belt-and-braces as the document/fragment pair above.
DE_ETAG=$(curl "${CURL_ARGS[@]}" -H "Accept-Language: de" -o /dev/null -D - "$BASE/imprint" 2>/dev/null \
          | tr -d '\r' | grep -i '^etag:')
EN_ETAG=$(curl "${CURL_ARGS[@]}" -H "Accept-Language: en" -o /dev/null -D - "$BASE/imprint" 2>/dev/null \
          | tr -d '\r' | grep -i '^etag:')
if [[ -n "$DE_ETAG" && "$DE_ETAG" != "$EN_ETAG" ]]; then
    pass "the two orderings do not share a validator"
else
    fail "the two orderings share a validator ($DE_ETAG)"
fi

# <html lang> follows the half that leads, so assistive technology is told the page's own language
# rather than the site's. Each half carries its own lang besides, which is what changes the voice at
# the boundary.
check_language_attribute() {
    local desc="$1"; local accept="$2"; local expected="$3"
    local body
    body=$(curl "${CURL_ARGS[@]}" -H "Accept-Language: $accept" "$BASE/imprint" 2>/dev/null) || true
    if printf '%s' "$body" | grep -q -- "<html lang=\"$expected\">"; then
        pass "$desc"
    else
        fail "$desc (no <html lang=\"$expected\">)"
    fi
}

check_language_attribute "the document declares the language it led with" "de" "de"
check_language_attribute "  and the other one when that is what led" "en" "en"


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
echo "=== The API ==="
# /api is the one address family that writes, and its whole design is that an unsigned caller cannot
# tell any of it from an address that does not exist. That is a claim about real responses — status,
# headers and body — so only this suite can check it. Compared against a path that genuinely is not
# there rather than against a remembered expectation, because what must hold is that the two agree.
#
# Every depth is swept, not just the real endpoint. `/api` and `/api/update` match no route at all
# and reach UnroutedController through the router; `/api/update/v1/patch` matches and reaches it
# through ApiController. Two code paths that must not be distinguishable — and `nope` is the one
# that looks exactly like the real address and is not.
#
# BREW is in the verb list on purpose: Request::method() is null for a verb the site does not
# recognise, and a gate that read `$method->value` without asking would answer 500 where an absent
# address answers 405.
#
# Both services are swept, and the second is not a formality: `update` writes and `health` only
# reads, so a refusal that leaked the difference would leak which of the two an address is. It
# cannot — ApiController hands anything it will not verify to UnroutedController before it has
# resolved a service at all — and that is precisely why the rows are cheap to keep.
api_paths=(/api /api/update /api/update/v1 /api/update/v1/patch /api/update/v1/version /api/update/v1/nope
           /api/health /api/health/v1 /api/health/v1/report /api/health/v1/nope)

for method in GET HEAD POST PUT DELETE PATCH OPTIONS BREW; do
    absent=$(curl "${CURL_ARGS[@]}" -o /dev/null -w '%{http_code}' -X "$method" "$BASE/no-such-page")
    mismatch=""

    for path in "${api_paths[@]}"; do
        got=$(curl "${CURL_ARGS[@]}" -o /dev/null -w '%{http_code}' -X "$method" "$BASE$path")
        [[ "$got" == "$absent" ]] || mismatch="$mismatch $path→$got"
    done

    if [[ -z "$mismatch" ]]; then
        pass "$method /api/* answers like an address that is not there ($absent)"
    else
        fail "$method /no-such-page → $absent but$mismatch, so the endpoint announces itself"
    fi
done

# The Allow header is the subtler half. The route accepts POST, so a 405 naming its own methods
# would read `GET, HEAD, POST` — and that POST is exactly the fact being hidden.
api_allow=$(curl "${CURL_ARGS[@]}" -D - -o /dev/null -X POST "$BASE/api/update/v1/patch" | grep -i '^allow:' | tr -d '\r')
absent_allow=$(curl "${CURL_ARGS[@]}" -D - -o /dev/null -X POST "$BASE/no-such-page" | grep -i '^allow:' | tr -d '\r')
if [[ "$api_allow" == "$absent_allow" && "$api_allow" == *"GET, HEAD"* && "$api_allow" != *"POST"* ]]; then
    pass "  and its 405 never names POST"
else
    fail "  /api/update/v1/patch sent '$api_allow' where /no-such-page sent '$absent_allow'"
fi

# Neither a body that is not a payload nor a credential that is not ours changes that answer. The
# second matters more than it looks: the credential now rides in Authorization, and a malformed one
# reaching strlen() as `false` would be an uncaught TypeError — a 500, on the one route built to be
# indistinguishable from a typo.
absent_post=$(curl "${CURL_ARGS[@]}" -o /dev/null -w '%{http_code}' -X POST "$BASE/no-such-page")
for probe in "--data-binary|not a payload" "-H|Authorization: NS1 !!!!" "-H|Authorization: NS1 " \
             "-H|Authorization: NS1abc" "-H|Authorization: Basic YTpi" "-H|Authorization: NS1 $(printf 'A%.0s' {1..9000})"; do
    flag=${probe%%|*}
    value=${probe#*|}
    got=$(curl "${CURL_ARGS[@]}" -o /dev/null -w '%{http_code}' -X POST "$flag" "$value" "$BASE/api/update/v1/patch")

    # A credential over the header size limit is refused by Apache before PHP sees it, which is a
    # 400 rather than our 405 — a refusal from the wrong layer, but not one that says /api is there.
    if [[ "$got" == "$absent_post" || "$got" == "400" || "$got" == "431" ]]; then
        pass "  refused the same way: ${value:0:28}"
    else
        fail "  '${value:0:28}' → $got, where an absent path → $absent_post"
    fi
done

# The GET half of the same claim: a read action is as invisible as the write one. Asked of both
# services, because a service made entirely of reads is the one somebody would be tempted to leave
# open — and the whole of `/api` is that nothing under it answers differently from a typo.
absent_get=$(curl "${CURL_ARGS[@]}" -o /dev/null -w '%{http_code}' -X GET "$BASE/no-such-page")
for read_path in /api/update/v1/version /api/health/v1/report; do
    read_get=$(curl "${CURL_ARGS[@]}" -o /dev/null -w '%{http_code}' -H 'Authorization: NS1 abcd' "$BASE$read_path")
    if [[ "$read_get" == "$absent_get" ]]; then
        pass "  an unsigned read of $read_path is refused the same way ($read_get)"
    else
        fail "  an unsigned read of $read_path → $read_get, where an absent path → $absent_get"
    fi
done

# Nothing may exist under public/api. The webroot passes real files and directories straight through
# (RewriteCond !-f / !-d), so a directory there would be answered by Apache — a listing or a 403 —
# and /api would stop looking like a typo without a line of PHP being involved.
if [[ -e "$REPO/public/api" ]]; then
    fail "  public/api exists, so Apache answers /api before the router ever sees it"
else
    pass "  nothing exists under public/api, so every /api request reaches the router"
fi

if [[ -f "$REPO/data/update.pub" ]]; then
    pass "  a key is installed, so this deployment can accept a signed call"
else
    echo "  SKIP the endpoint is switched off here — no data/update.pub, which is the safe default"
fi

echo ""
if [[ $FAIL -eq 0 ]]; then
    echo "All $PASS checks passed."
else
    echo "$PASS passed, $FAIL failed."
    exit 1
fi
