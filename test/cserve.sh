#!/usr/bin/env bash
# The conformance suite against c-serve's host with the neurosys app (app/) and nothing else: the
# site's files served natively, every route the app's, and what no route fits the app's own 404
# or 405 — no CGI behind it (docs/research/c-serve.md, phase c5). The webroot is public/, the
# debug tree, as the verify script serves it; the app is built against the manifest app/gen/ was
# printed from, which is the debug one. The production layout natively is c6's static build.
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
HOST="$CSERVE_DIR/build/O2/bin/cserve"
APP="$CSERVE_DIR/build/O2/bin/apps/neurosys.so"
PORT=18082

if ! make -s -C "$CSERVE_DIR" EXTERNAL_APPS="neurosys=$REPO/app" >/dev/null; then
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
    local root="$1" label="$2" status
    shift 2
    "$HOST" --listen "127.0.0.1:$PORT" --root "$root" --climb front \
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
run_against "$REPO/public" "the app alone" --app "$APP" --data "$REPO/data" || fail=1

exit $fail
