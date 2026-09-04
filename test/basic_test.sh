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

# --- DownloadLogger ---

# Download logging is deliberately switched off (legal). Guard against it being turned back on by accident.
php_ok "DownloadLogger is switched off and writes nothing" \
    "use NeuroSYS\Service\DownloadLogger;
     \$f = '$REPO/data/logs/downloads.log';
     \$before = is_file(\$f) ? filesize(\$f) : -1;
     new DownloadLogger()->log('test-slug', 'flac');
     clearstatcache();
     \$after = is_file(\$f) ? filesize(\$f) : -1;
     (DownloadLogger::ENABLED === false && \$after === \$before) or exit(1);"

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


# --- SoundCloudEmbed ---

# The embed generates its own markup, so the track id must survive into the player URL.
php_ok "SoundCloudEmbed renders an iframe for the right track" \
    "use NeuroSYS\Model\Embed\SoundCloudEmbed;
     \$h = new SoundCloudEmbed(trackId: 123, permalink: 'x')->toHtml('t');
     (str_contains(\$h, '<iframe') && str_contains(\$h, 'soundcloud%3Atracks%3A123')) or exit(1);"

# Options are an enum set; a bare string would silently produce a broken query flag.
php_ok "SoundCloudEmbed rejects a non-SoundCloudOption" \
    "use NeuroSYS\Model\Embed\SoundCloudEmbed;
     use NeuroSYS\Exception\ReleaseVerificationException;
     try { new SoundCloudEmbed(trackId: 1, permalink: 'x', options: ['show_user']); exit(1); }
     catch (ReleaseVerificationException \$e) {}"

# Presence in the option list means true, absence means false — both are emitted.
php_ok "SoundCloudEmbed maps options to true/false query flags" \
    "use NeuroSYS\Model\Embed\SoundCloudEmbed;
     use NeuroSYS\Model\Embed\SoundCloudOption;
     \$on  = new SoundCloudEmbed(trackId: 1, permalink: 'x', options: [SoundCloudOption::ShowComments])->toHtml('t');
     \$off = new SoundCloudEmbed(trackId: 1, permalink: 'x', options: [])->toHtml('t');
     (str_contains(\$on, 'show_comments=true') && str_contains(\$off, 'show_comments=false')) or exit(1);"

# The attribution text comes from the release title, never a second hand-typed copy.
php_ok "SoundCloudEmbed credits the title it is given" \
    "use NeuroSYS\Model\Embed\SoundCloudEmbed;
     \$h = new SoundCloudEmbed(trackId: 1, permalink: 'x')->toHtml('my track!');
     str_contains(\$h, '>my track!</a>') or exit(1);"

# End to end: the view must gate the embed behind the consent placeholder, named per platform.
php_ok "ReleaseView gates the embed behind a named consent placeholder" \
    "use NeuroSYS\Service\ReleaseRepository;
     use NeuroSYS\View\ReleaseView;
     \$h = new ReleaseView((new ReleaseRepository())->find('ill'), 'ill')->content();
     (str_contains(\$h, 'player-consent') && str_contains(\$h, 'SoundCloud player')) or exit(1);"


# --- HiDriveLink ---

# The share id is the only per-file part; the endpoint is generated around it.
php_ok "HiDriveLink builds the direct-download URL from a share id" \
    "use NeuroSYS\Model\Link\HiDriveLink;
     new HiDriveLink('BXRsy9S7d')->url()
       === 'https://my.hidrive.com/api/sharelink/download?id=BXRsy9S7d' or exit(1);"

# A truncated or mistyped paste must fail when the data file loads, not 404 later at HiDrive.
php_ok "HiDriveLink rejects a malformed share id" \
    "use NeuroSYS\Model\Link\HiDriveLink;
     use NeuroSYS\Exception\ReleaseVerificationException;
     \$bad = 0;
     foreach (['BXRsy9S7', 'BXRsy9S7dd', '', 'BXRsy-9S7', 'https://my.hidrive.com/x'] as \$id) {
         try { new HiDriveLink(\$id); } catch (ReleaseVerificationException \$e) { \$bad++; }
     }
     \$bad === 5 or exit(1);"

# Every link in the catalogue must resolve to the direct-download endpoint, not a share page.
php_ok "Every release link points at HiDrive direct download" \
    "use NeuroSYS\Service\ReleaseRepository;
     \$n = 0;
     foreach ((new ReleaseRepository())->all() as \$r) {
         foreach ([\$r->cover, ...array_map(fn(\$f) => \$f->link, \$r->formats->all())] as \$l) {
             if (\$l === null) continue;
             str_starts_with(\$l->url(), 'https://my.hidrive.com/api/sharelink/download?id=') or exit(1);
             \$n++;
         }
     }
     \$n > 0 or exit(1);"

# The staging state: a format declared with no link yet. DownloadController keys its 503
# branch off exactly this being null, so guard the default rather than the controller
# (which builds its own ReleaseRepository and can't be handed a synthetic release).
php_ok "A format declared without a link has a null link" \
    "use NeuroSYS\Model\Format;
     use NeuroSYS\Model\Genre;
     use NeuroSYS\Model\MusicalKey;
     use NeuroSYS\Model\Release;
     use NeuroSYS\Model\ReleaseFormat;
     use NeuroSYS\Support\Collection;
     \$r = new Release('t', 1, MusicalKey::CMajor, Genre::Dubstep, 'd', null,
         new Collection(Format::class)->add(new Format(ReleaseFormat::FLAC)));
     \$r->findFormat('flac')->link === null or exit(1);"

# A release with no cover link renders the placeholder rather than an empty src.
php_ok "ReleaseView falls back to the cover placeholder" \
    "use NeuroSYS\Model\Format;
     use NeuroSYS\Model\Genre;
     use NeuroSYS\Model\MusicalKey;
     use NeuroSYS\Model\Release;
     use NeuroSYS\Support\Collection;
     use NeuroSYS\View\ReleaseView;
     \$r = new Release('t', 1, MusicalKey::CMajor, Genre::Dubstep, 'd', null,
         new Collection(Format::class));
     \$h = new ReleaseView(\$r, 't')->content();
     (str_contains(\$h, 'src=\"/assets/img/cover-placeholder.svg\"')
      && !str_contains(\$h, 'src=\"\"')) or exit(1);"

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
check_status "GET /releases/ill                  → 200" "$BASE/releases/ill"                   200
check_status "GET /releases/ill/flac             → 303" "$BASE/releases/ill/flac"              303
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
