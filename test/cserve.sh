#!/usr/bin/env bash
# The conformance suite against c-serve's host: the site's files served natively, everything else
# handed to php-cgi, the way the box will run it until the routes go native
# (docs/research/c-serve.md, phase c2).
#
# Twice: against public/ (the debug tree, as the verify script serves it), then against the
# production layout deploy.sh builds — build/dist/public/ as the webroot, the source and the
# framework above it, the prod manifest on top — staged in a scratch directory with the data files
# the pages read and none of the credentials.
#
# Usage:
#   bash test/cserve.sh                  # c-serve from ~/Projects/c-serve, built if it isn't
#   CSERVE_DIR=../c-serve bash test/cserve.sh

set -u
REPO="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
CSERVE_DIR="${CSERVE_DIR:-$HOME/Projects/c-serve}"
HOST="$CSERVE_DIR/build/O2/bin/cserve-static"
PORT=18082

if ! command -v php-cgi >/dev/null; then
    echo "ERROR: php-cgi is not installed — the host's front controller runs through it."
    exit 1
fi
if ! make -s -C "$CSERVE_DIR" >/dev/null; then
    echo "ERROR: c-serve did not build in $CSERVE_DIR."
    exit 1
fi

SERVER_PID=""
STAGE=""
# shellcheck disable=SC2329 # run by the EXIT trap
cleanup() {
    [[ -n "$SERVER_PID" ]] && { kill "$SERVER_PID" 2>/dev/null; wait "$SERVER_PID" 2>/dev/null; }
    [[ -n "$STAGE" ]] && rm -rf "$STAGE"
}
trap cleanup EXIT

# the host on ROOT, then the suite against it: its exit status
run_against() {
    local root="$1" label="$2" status
    "$HOST" --listen "127.0.0.1:$PORT" --root "$root" --cgi php-cgi --climb front \
        --env PHPANTA_ENVIRONMENT=development >/dev/null 2>&1 &
    SERVER_PID=$!
    echo ""
    echo "##### c-serve, $label ($root)"
    BASE="http://127.0.0.1:$PORT" bash "$REPO/test/conformance.sh"
    status=$?
    kill "$SERVER_PID" 2>/dev/null
    wait "$SERVER_PID" 2>/dev/null
    SERVER_PID=""
    return $status
}

fail=0
run_against "$REPO/public" "the debug tree" || fail=1

if [[ -f "$REPO/build/dist/src/NeuroSYS/AssetManifest.php" ]]; then
    STAGE="$(mktemp -d /tmp/neurosys-cserve-XXXXXX)"
    mkdir -p "$STAGE/phpanta" "$STAGE/data"
    cp -r "$REPO/autoload.php" "$REPO/src" "$STAGE/"
    cp -r "$REPO/phpanta/autoload.php" "$REPO/phpanta/src" "$STAGE/phpanta/"
    cp -r "$REPO/build/dist/public" "$STAGE/public"
    cp "$REPO/build/dist/src/NeuroSYS/AssetManifest.php" "$STAGE/src/NeuroSYS/AssetManifest.php"
    # what the pages read; never a credential, a demo or a switch
    for f in releases.php profiles.php privacy.de.html privacy.en.html; do
        cp "$REPO/data/$f" "$STAGE/data/"
    done
    run_against "$STAGE/public" "the production layout" || fail=1
else
    echo ""
    echo "NOTE: no build/dist/ — run 'npm run build:prod' to check the production layout too."
fi

exit $fail
