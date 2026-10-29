#!/usr/bin/env bash
# The conformance suite: what the site answers over HTTP, asked of whatever serves it.
#
# Every check here holds for any server that claims to be neuro.SYS — PHP behind the dev router,
# the local Apache, or a native host — because each one is a request and what came back. Nothing
# here loads PHP, reads the repo, or knows how an answer was made, so it is the definition of
# "the site is right" that survives the runtime changing underneath it. See
# docs/research/c-serve.md.
#
# Usage:
#   bash test/conformance.sh                       # starts php -S with the dev router itself
#   bash test/conformance.sh https://neurosys.localhost
#   CONFORMANCE_CURL="--cacert /etc/httpd/conf/neurosys.localhost.crt" \
#       bash test/conformance.sh https://neurosys.localhost
#
# test/basic_test.sh sources this file and calls conformance_checks against its own server, so
# `composer verify` runs every check here and counts them in its own total.

CONFORMANCE_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=lib/http.sh
source "$CONFORMANCE_DIR/lib/http.sh"

conformance_checks() {
echo ""
echo "=== HTTP routes ==="

check_status "GET /                              → 200" "$BASE/"                                200
check_status "GET /releases                      → 200" "$BASE/releases"                       200
check_status "GET /releases/                     → 200" "$BASE/releases/"                      200
check_status "GET /releases/hello-world          → 200" "$BASE/releases/hello-world"           200
check_status "GET /releases/hello-world/flac     → 303" "$BASE/releases/hello-world/flac"      303
check_status "GET /releases/ill                  → 200" "$BASE/releases/ill"                   200
check_status "GET /releases/ill/flac             → 303" "$BASE/releases/ill/flac"              303
check_status "GET /imprint                       → 200" "$BASE/imprint"                        200
check_status "GET /privacy                       → 200" "$BASE/privacy"                        200
check_status "GET /language/de                   → 303" "$BASE/language/de"                    303
check_status "GET /language/xx                   → 404" "$BASE/language/xx"                    404
check_status "GET /releases/no-such-slug         → 404" "$BASE/releases/no-such-slug"          404
check_status "GET /releases/hello-world/badformat→ 404" "$BASE/releases/hello-world/badformat" 404
check_status "GET /notfound                      → 404" "$BASE/notfound"                       404

# public/.user.ini is a real file in the webroot. The dev router hands it to the site, as .htaccess
# does in production, so it is the same 404 as a missing page and none of it crosses the wire.
check_status "GET /.user.ini                     → 404" "$BASE/.user.ini"                      404
if curl -s "$BASE/.user.ini" | grep -q 'register_argc_argv'; then
    fail "GET /.user.ini served the file's contents"
else
    pass "GET /.user.ini does not serve the file's contents"
fi

# The rest of what the built-in server would hand out on its own. Apache refuses .ht* and resolves
# `..` before it looks for a file; php -S does neither, and before the router sent every dot segment
# to the site, `../../index.php` under a stamp served the webroot's PHP source. Asked with
# --path-as-is, because curl would otherwise resolve the dots itself and ask for something else.
for target in "/.htaccess" "/%2euser.ini" "/assets/js/v-00000000/../../index.php" \
              "/assets/js/v-00000000/%2e%2e/%2e%2e/index.php" "/assets/js/v-00000000/x%2F..%2F..%2Findex.php" \
              "/assets/css/v-00000000/../../.user.ini"; do
    answer=$(curl "${CURL_ARGS[@]}" --path-as-is -w '\n%{http_code}' "$BASE$target") || true
    if [[ "${answer##*$'\n'}" == 404 ]] && ! grep -qE 'strict_types|register_argc_argv|DirectoryIndex' <<<"$answer"; then
        pass "GET $target → 404, and none of the file"
    else
        fail "GET $target answered ${answer##*$'\n'} or served the file — the server let it through"
    fi
done

# Targets parse_url() will not parse. It returns false on failure and `?? '/'` only catches null, so
# read that way each of these is an uncaught TypeError in fromGlobals() — a 500 ahead of the router
# and the read-only gate. Request::normalisePath() asks the RFC 3986 parser instead.
# Worth a real request rather than only a unit test: what was wrong was the status code, and PHPUnit
# sees an exception either way.
check_status "GET /// is the root                → 200" "$BASE///"                             200
check_status "GET //host:notaport/x              → 404" "$BASE//host:notaport/x"               404
check_method "POST /// is still refused          → 405" POST "$BASE///"                        405

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
# A prod build bundles the modules and hints none (docs/frontend.md), which is not a failure here:
# the claim is about the hints there are. The debug build's 49 are what make it mean something.
if [[ -z "$preloaded" ]]; then
    pass "no modulepreload hints to resolve (a bundled build)"
elif [[ -z "$unresolved" ]]; then
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
check_header "Cross-Origin-Opener-Policy is set"      "$BASE/"             "^cross-origin-opener-policy: same-origin"
check_header "Cross-Origin-Resource-Policy is set"    "$BASE/"             "^cross-origin-resource-policy: same-origin"
# PHP appends this before any of our code runs, so SecurityHeaders::send() removes it. Only visible
# on a real server -- header() and header_remove() are both no-ops under CLI, so PHPUnit cannot see
# either. expose_php is On in this dev server's ini, which is what makes the assertion mean anything.
check_no_header "no PHP version is disclosed"         "$BASE/"             "^x-powered-by:"
# A download redirect is where the Referer would otherwise leak to the file host.
check_header "headers reach a 303 too"                "$BASE/releases/ill/flac" "^referrer-policy:"
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
check_vary "  and names the headers its body depends on" "$BASE/" \
    X-Requested-With Accept-Language Cookie
check_vary "  which every page does, since every page is written in a language" "$BASE/releases" \
    X-Requested-With Accept-Language Cookie
check_revalidates "an unchanged document comes back as a 304" "$BASE/"
check_revalidates "  and so does a release page" "$BASE/releases/ill"

# What a browser holds behind mod_deflate is the tag with -gzip inside the quotes. The built-in
# server compresses nothing, so the suffix is added by hand — and it has to validate, or no
# compressed page is ever a 304.
GZIP_ETAG=$(curl "${CURL_ARGS[@]}" -o /dev/null -D - "$BASE/" 2>/dev/null | tr -d '\r' \
            | grep -i '^etag:' | sed 's/^[Ee][Tt][Aa][Gg]: //') || true
GZIP_STATUS=$(curl "${CURL_ARGS[@]}" -H "If-None-Match: ${GZIP_ETAG%\"}-gzip\"" -o /dev/null \
              -w "%{http_code}" "$BASE/" 2>/dev/null) || true
if [[ "$GZIP_STATUS" == "304" ]]; then
    pass "  and so does a validator a compressing module suffixed with -gzip"
else
    fail "  and so does a validator a compressing module suffixed with -gzip (got $GZIP_STATUS)"
fi

# Only a success is validated: RFC 9110 has a server ignore If-None-Match on a response that would
# not otherwise be a 2xx. A 404 still says no-cache — left to its heuristics a browser may keep one,
# for a page since published — and still names what its body depends on.
check_no_header "a 404 hands out no validator" "$BASE/nope" "^etag:"
check_header "  but is still revalidated" "$BASE/nope" "^cache-control: no-cache"
check_vary "  and still names the headers its body depends on" "$BASE/nope" \
    X-Requested-With Accept-Language Cookie
NOPE_STATUS=$(curl "${CURL_ARGS[@]}" -H 'If-None-Match: *' -o /dev/null -w "%{http_code}" \
              "$BASE/nope" 2>/dev/null) || true
if [[ "$NOPE_STATUS" == "404" ]]; then
    pass "  and is never a 304, whatever validator arrives"
else
    fail "  and is never a 304, whatever validator arrives (got $NOPE_STATUS)"
fi

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

# A 303 carries none of it: it is not cacheable by default. A 405 is, and its body is in the
# caller's language, so the router's refusal says no-store instead.
check_no_header "a 303 is not cacheable"                  "$BASE/releases/ill/flac"  "^cache-control:"
check_header    "  and a 405 says it may not be kept"     "$BASE/"                   "^cache-control: no-store, private$" "POST"

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
    local cookie="${6:-}"
    local body before_first before_second
    local extra=()
    [[ -n "$cookie" ]] && extra=(-H "Cookie: $cookie")
    body=$(curl "${CURL_ARGS[@]}" "${extra[@]}" -H "Accept-Language: $accept" "$url" 2>/dev/null) || true

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

# A lang cookie is a choice made on this site, so it outranks the browser's setting — and one that
# names no language of ours is no choice, and the browser's setting stands.
check_language_order "a chosen language outranks the browser's" "$BASE/imprint" \
    "de-DE,de;q=0.9" "<h1>Imprint</h1>" "<h1>Impressum</h1>" "lang=en"
check_language_order "  and the other way round, among other cookies" "$BASE/imprint" \
    "en-GB,en;q=0.9" "<h1>Impressum</h1>" "<h1>Imprint</h1>" "theme=dark; lang=de"
check_language_order "  but a cookie naming no language of ours is no choice" "$BASE/imprint" \
    "de" "<h1>Impressum</h1>" "<h1>Imprint</h1>" "lang=xx"

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

# Content-Language says on the wire what <html lang> says in the document — and a fragment has no
# <html>, so for Navigation's fetches the header is the only statement of it.
check_header "every page says which language its body is in" "$BASE/" "^content-language: en"
CONTENT_LANGUAGE=$(curl "${CURL_ARGS[@]}" -H "Accept-Language: de" -o /dev/null -D - "$BASE/imprint" 2>/dev/null \
                   | tr -d '\r' | grep -i '^content-language:')
if printf '%s' "$CONTENT_LANGUAGE" | grep -qi '^content-language: de$'; then
    pass "  and the imprint the language it led with"
else
    fail "  and the imprint the language it led with (${CONTENT_LANGUAGE:-none})"
fi

# Every page, not only the two legal ones: the request's language is the page's. The same address
# answers in German to a German browser or a German cookie, and in English to everyone else — and
# a fragment, which has no <html lang>, is put into the same language as the document would be.
check_localised() {
    local desc="$1"; local url="$2"; local accept="$3"; local cookie="$4"; local expected="$5"
    local body
    local extra=()
    [[ -n "$cookie" ]] && extra=(-H "Cookie: $cookie")
    body=$(curl "${CURL_ARGS[@]}" "${extra[@]}" -H "Accept-Language: $accept" "$url" 2>/dev/null) || true
    if [[ "$body" == *"$expected"* ]]; then
        pass "$desc"
    else
        fail "$desc (no '$expected')"
    fi
}

check_localised "the home page speaks German to a German browser" "$BASE/" "de-DE,de;q=0.9" "" "neueste tracks"
check_localised "  and says so on <html lang>" "$BASE/" "de" "" '<html lang="de">'
check_localised "  and English to a browser asking for neither" "$BASE/" "fr" "" "latest tracks"
check_localised "a chosen language outranks the browser's on every page" "$BASE/releases/ill" \
    "en-GB,en;q=0.9" "lang=de" "künstler"
check_localised "  the 404 included" "$BASE/nope" "de" "" "nicht gefunden"
check_localised "a release's own words are translated too" "$BASE/releases" "de" "" "debütsingle"
check_localised "  and so is its key" "$BASE/releases" "de" "" "dis-Moll"

# The switch: a link, a cookie, and back to the page the visitor was on — by the Referer's path
# alone, so whatever host the Referer names, the redirect stays here.
SWITCH=$(curl "${CURL_ARGS[@]}" -o /dev/null -D - -H "Referer: http://localhost:$PORT/releases/ill" \
         "$BASE/language/de" 2>/dev/null | tr -d '\r')
if printf '%s' "$SWITCH" | grep -qi '^set-cookie: lang=de; Path=/; Max-Age=31536000; SameSite=Lax; Secure; HttpOnly$'; then
    pass "switching language sets the cookie"
else
    fail "switching language sets the cookie"
fi
if printf '%s' "$SWITCH" | grep -qi '^location: /releases/ill$'; then
    pass "  and sends the visitor back to the page they were on"
else
    fail "  and sends the visitor back to the page they were on"
fi
if printf '%s' "$SWITCH" | grep -qi '^cache-control: no-store'; then
    pass "  and is never stored"
else
    fail "  and is never stored"
fi
OFFSITE=$(curl "${CURL_ARGS[@]}" -o /dev/null -D - -H "Referer: https://evil.example//evil.example/x" \
          "$BASE/language/en" 2>/dev/null | tr -d '\r' | grep -i '^location:')
if printf '%s' "$OFFSITE" | grep -qi '^location: /$'; then
    pass "  but never to another host, however the path is spelled"
else
    fail "  but never to another host, however the path is spelled (${OFFSITE:-none})"
fi
check_body "the footer offers the other language" "$BASE/" 'href="/language/de"'
check_localised "  and a German page offers English" "$BASE/" "de" "" 'href="/language/en"'

FRAGMENT=$(curl "${CURL_ARGS[@]}" -H "X-Requested-With: XMLHttpRequest" -H "Accept-Language: de" "$BASE/" 2>/dev/null) || true
if [[ "$FRAGMENT" == *"neueste tracks"* && "$FRAGMENT" != *"<html"* ]]; then
    pass "a fragment is put into the request's language too"
else
    fail "a fragment is put into the request's language too"
fi


echo ""
echo "=== Read-only method gate ==="
# Every route is a read; a write method must not be silently treated as a GET.

check_method "POST   /                    → 405" POST   "$BASE/"                           405
check_method "POST   /releases/ill/flac   → 405" POST   "$BASE/releases/ill/flac"          405
check_method "DELETE /releases/ill/flac   → 405" DELETE "$BASE/releases/ill/flac"          405
check_method "HEAD   /                    → 200" HEAD   "$BASE/"                           200
check_header "the 405 names the allowed methods" "$BASE/" "^allow: GET, HEAD" POST


# AJAX requests should return a page fragment, not a full HTML document.
check_spa_fragment "AJAX /         returns fragment only" "$BASE/"
check_spa_fragment "AJAX /releases returns fragment only" "$BASE/releases"
check_spa_fragment "AJAX /releases/ill returns fragment only" "$BASE/releases/ill"
}

# Run standalone, rather than sourced by the verify script.
if [[ "${BASH_SOURCE[0]}" == "$0" ]]; then
    REPO="$(cd "$CONFORMANCE_DIR/.." && pwd)"
    BASE="${1:-${BASE:-}}"
    # shellcheck disable=SC2206 # extra curl flags, split on purpose
    CURL_ARGS=(-s ${CONFORMANCE_CURL:-})

    if [[ -z "$BASE" ]]; then
        PORT=18081
        BASE="http://localhost:$PORT"
        php -S "localhost:$PORT" -t "$REPO/public" "$REPO/phpanta/tools/dev-router.php" >/dev/null 2>&1 &
        SERVER_PID=$!
        # shellcheck disable=SC2064 # the PID is meant to be fixed now
        trap "kill $SERVER_PID 2>/dev/null; wait $SERVER_PID 2>/dev/null" EXIT
    fi

    started=0
    for _ in $(seq 1 15); do
        curl "${CURL_ARGS[@]}" --max-time 0.5 -o /dev/null "$BASE/" && { started=1; break; }
        sleep 0.2
    done
    if [[ $started -eq 0 ]]; then
        echo "ERROR: nothing is answering at $BASE — aborting."
        exit 1
    fi

    echo "Conformance against $BASE"
    conformance_checks

    echo ""
    if [[ $FAIL -eq 0 ]]; then
        echo "All $PASS checks passed."
    else
        echo "$PASS passed, $FAIL failed."
        exit 1
    fi
fi
