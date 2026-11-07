#!/usr/bin/env bash
# The confinement suite: what a fully owned PHP worker can still reach, asserted rather than read
# off a config that looks right. See docs/research/attested-single-box.md (Phase 1).
#
#   Tier A — the seccomp filter itself, under the CLI. Needs gcc, libseccomp and php-config;
#            without them it says so and passes nothing.
#   Tier B — the confined pool, over its own FastCGI socket. Needs php-fpm-neurosys.service
#            active (ops/local/install.sh); without it it says so.
#
# Usage: bash test/confinement_test.sh

REPO="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
BUILD="$REPO/build/confine"
SO="$BUILD/confine.so"
SOCKET=/run/php-fpm-neurosys/fpm.sock
UNIT=php-fpm-neurosys.service
PROBES="$REPO/test/confinement"

PASS=0
FAIL=0

pass() { echo "  OK   $1"; ((PASS+=1)); }
fail() { echo "  FAIL $1"; ((FAIL+=1)); }

# The exit status of a process the kernel killed with SIGSYS: 128 + 31.
SIGSYS_STATUS=159

# Run PHP code under the filter and assert its exit status.
confined_exit() {
    local desc="$1"; local expected="$2"; local code="$3"; local enabled="${4:-1}"
    local actual
    # In a subshell with its stderr closed, so the shell's own "Bad system call" report of the
    # killed child stays out of the output, and with no core file for a death that is the point.
    (ulimit -c 0; php -n -d extension="$SO" -d confine.enabled="$enabled" -r "$code" >/dev/null 2>&1) 2>/dev/null
    actual=$?
    if [[ "$actual" == "$expected" ]]; then
        pass "$desc (exit $actual)"
    else
        fail "$desc (expected exit $expected, got $actual)"
    fi
}

echo "Tier A — the filter"

if ! command -v gcc >/dev/null || ! command -v php-config >/dev/null || [[ ! -f /usr/include/seccomp.h ]]; then
    echo "  NOTE gcc, php-config or libseccomp's headers are missing — the filter is not built or tested"
else
    if make -s -C "$REPO/phpanta/tools/confine" OUT="$BUILD" >/dev/null; then
        pass "confine.so builds with -Werror"
    else
        fail "confine.so builds with -Werror"
    fi

    status=$(php -n -d extension="$SO" -d confine.enabled=1 -r \
        'echo implode(",", preg_grep("/^Seccomp:/", file("/proc/self/status")));' 2>/dev/null)
    if [[ "$status" == *"Seccomp:"*"2"* ]]; then
        pass "a confined process is in filter mode before its first line runs"
    else
        fail "a confined process is in filter mode before its first line runs (got '$status')"
    fi

    confined_exit "an ordinary script runs" 0 'echo strlen(str_repeat("x", 1 << 20));'
    confined_exit "a local socket pair is allowed" 0 'stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);'
    confined_exit "a file can be written and read" 0 \
        '$f = tempnam(sys_get_temp_dir(), "confine"); file_put_contents($f, "x"); exit(file_get_contents($f) === "x" && unlink($f) ? 0 : 1);'
    confined_exit "proc_open (execve) kills the process" "$SIGSYS_STATUS" 'proc_open(["true"], [], $pipes);'
    confined_exit "an outbound TCP socket kills the process" "$SIGSYS_STATUS" 'stream_socket_client("tcp://127.0.0.1:9");'
    confined_exit "a UDP socket kills the process" "$SIGSYS_STATUS" 'stream_socket_client("udp://127.0.0.1:9");'
    confined_exit "pcntl_fork kills the process" "$SIGSYS_STATUS" 'pcntl_fork();'
    confined_exit "posix_setuid kills the process" "$SIGSYS_STATUS" 'posix_setuid(0);'
    confined_exit "unconfined, proc_open runs — so the probes above prove the filter" 0 \
        'exit(proc_close(proc_open(["true"], [], $pipes)));' 0

    after=$(ulimit -c 0; php -n -d extension="$SO" -d confine.enabled=1 -r 'ini_set("confine.enabled", "0"); proc_open(["true"], [], $p); echo "escaped";' 2>/dev/null)
    if [[ "$after" != *escaped* ]]; then
        pass "a script cannot switch the filter off with ini_set"
    else
        fail "a script cannot switch the filter off with ini_set"
    fi
fi

echo ""
echo "Tier B — the confined pool"

if ! systemctl is-active --quiet "$UNIT" || [[ ! -S "$SOCKET" ]]; then
    echo "  NOTE $UNIT is not active — run 'sudo ops/local/install.sh' to test the live pool"
else
    # shellcheck source=test/confinement/tier_b.sh
    source "$REPO/test/confinement/tier_b.sh"
fi

echo ""
if [[ $FAIL -eq 0 ]]; then
    echo "All $PASS checks passed."
else
    echo "$PASS passed, $FAIL failed."
    exit 1
fi
