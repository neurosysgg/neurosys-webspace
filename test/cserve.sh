#!/usr/bin/env bash
# The conformance suite against c-serve's host with the neurosys app (app/) and nothing else: the
# site's files served natively, every route the app's, and what no route fits the app's own 404
# or 405 — no CGI behind it (docs/research/c-serve.md, phase c5). The webroot is public/, the
# debug tree, as the verify script serves it; the app is built against the manifest app/gen/ was
# printed from, which is the debug one. It runs twice: on cserve, the app dlopen()ed, and on
# cserve-static, the production host, the app linked in and nothing loaded. When `npm run
# build:prod` has made build/dist/, it runs a third time on what the box ships: cserve-static built
# from build/dist/app/ (gen/ on the prod manifest, in a build directory of its own) on
# build/dist/public/.
#
# Usage:
#   bash test/cserve.sh                  # c-serve from ~/Projects/c-serve, built if it isn't
#   CSERVE_DIR=../c-serve bash test/cserve.sh

set -u
REPO="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
if [[ -z "${CSERVE_DIR:-}" ]]; then
    CSERVE_DIR="$REPO/c-serve"
    [[ -f "$CSERVE_DIR/Makefile" ]] || CSERVE_DIR="$HOME/Projects/c-serve"
fi
DEV="$CSERVE_DIR/build/O2/bin/cserve"
PROD="$CSERVE_DIR/build/O2/bin/cserve-static"
APP="$CSERVE_DIR/build/O2/bin/apps/neurosys.so"
PORT=18082
DIST_B="build/O2-neurosys-dist"
DIST="$CSERVE_DIR/$DIST_B/bin/cserve-static"

if ! make -s -C "$CSERVE_DIR" EXTERNAL_APPS="neurosys=$REPO/app" STATIC_APPS=neurosys >/dev/null; then
    echo "ERROR: c-serve did not build in $CSERVE_DIR."
    exit 1
fi

SERVER_PID=""
# shellcheck disable=SC2329 # run by the EXIT trap
cleanup() {
    [[ -n "$SERVER_PID" ]] && { kill "$SERVER_PID" 2>/dev/null; wait "$SERVER_PID" 2>/dev/null; }
}
trap cleanup EXIT

# the host on ROOT (with any further flags), then the suite against it: its exit status
run_against() {
    local host="$1" root="$2" label="$3" status
    shift 3
    "$host" --listen "127.0.0.1:$PORT" --root "$root" \
        "$@" >/dev/null 2>&1 &
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
run_against "$DEV" "$REPO/public" "the app alone" --app "$APP" --data "$REPO/data" || fail=1
run_against "$PROD" "$REPO/public" "the app linked in" --app neurosys --data "$REPO/data" || fail=1

if [[ -f "$REPO/build/dist/app/gen/site.h" ]]; then
    if make -s -C "$CSERVE_DIR" B="$DIST_B" EXTERNAL_APPS="neurosys=$REPO/build/dist/app" \
        STATIC_APPS=neurosys "$DIST_B/bin/cserve-static" >/dev/null; then
        run_against "$DIST" "$REPO/build/dist/public" "the prod tree" --app neurosys \
            --data "$REPO/data" || fail=1
    else
        echo "ERROR: cserve-static did not build from build/dist/app."
        fail=1
    fi
else
    echo ""
    echo "NOTE: no build/dist/app (npm run build:prod): the prod tree's run skipped."
fi

exit $fail
