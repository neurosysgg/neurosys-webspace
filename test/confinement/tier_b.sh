# Tier B of test/confinement_test.sh — the confined pool, over its own socket. Sourced, not run:
# it uses that script's pass/fail, $REPO, $SOCKET, $UNIT and $PROBES.

SIDEDOOR=/run/php-fpm/neurosys-sidedoor.sock
FCGI="$PROBES/fcgi.php"
KEYS="$HOME/.config/neurosys"

ask()      { php "$FCGI" "$1" "$PROBES/$2" "${3:-}" 2>/dev/null; }
field()    { php -r '$v = json_decode(stream_get_contents(STDIN), true)[$argv[1]] ?? null; echo is_array($v) ? implode(",", $v) : var_export($v, true);' "$1"; }
accepted() { php "$FCGI" "$SOCKET" /fpm-status 2>/dev/null | sed -n 's/^accepted conn: *//p'; }

expect() {
    local desc="$1"; local actual="$2"; local expected="$3"
    if [[ "$actual" == "$expected" ]]; then
        pass "$desc"
    else
        fail "$desc (expected '$expected', got '$actual')"
    fi
}

# --- who a worker is ---------------------------------------------------------------------------

me=$(ask "$SOCKET" whoami.php)

expect "a worker runs as neurosys-php, not http"           "$(field user <<<"$me")"         "'neurosys-php'"
expect "a worker is in seccomp filter mode"                "$(field seccomp <<<"$me")"      "2"
expect "a worker has no_new_privs"                         "$(field no_new_privs <<<"$me")" "1"
expect "a worker holds no capability"                      "$(field cap_eff <<<"$me")"      "'0000000000000000'"
expect "a worker loaded the confine filter"                "$(field confine <<<"$me")"      "true"
expect "a worker has no debugger loaded"                   "$(field xdebug <<<"$me")"       "false"
expect "a worker sees one network interface, loopback"     "$(field interfaces <<<"$me")"   "lo"
expect "allow_url_fopen is off"                            "$(field allow_url_fopen <<<"$me")" "'0'"
expect "pcre.jit is off, as MemoryDenyWriteExecute needs"  "$(field pcre_jit <<<"$me")"     "'0'"
expect "opcache is on, so recycling costs no compile"      "$(field opcache <<<"$me")"      "true"

filters=$(field filters <<<"$me")
if [[ "$filters" =~ ^[0-9]+$ ]] && (( filters >= 2 )); then
    pass "two filters stack: the unit's and the worker's ($filters)"
else
    fail "two filters stack: the unit's and the worker's (got '$filters')"
fi

[[ "$(field disable_functions <<<"$me")" == *proc_open*popen* ]] \
    && pass "proc_open and popen are disabled above the kernel too" \
    || fail "proc_open and popen are disabled above the kernel too"

visible=$(field visible_pids <<<"$me")
if [[ "$visible" =~ ^[0-9]+$ ]] && (( visible <= 8 )); then
    pass "/proc shows the pool's own processes only ($visible)"
else
    fail "/proc shows the pool's own processes only (got '$visible')"
fi

# --- no two requests share a process -----------------------------------------------------------

pids=$(for _ in $(seq 50); do ask "$SOCKET" whoami.php | field pid; echo; done)
distinct=$(sort -u <<<"$pids" | grep -c .)
expect "50 requests, 50 processes — a worker never serves twice" "$distinct" "50"

# --- what a worker can write and read ----------------------------------------------------------

for dir in "$REPO/src" "$REPO/public" "$REPO/phpanta/src" "$REPO"; do
    answer=$(ask "$SOCKET" write.php "dir=$dir")
    [[ "$answer" == *"Read-only file system"* ]] \
        && pass "a write into ${dir#"$REPO"}/ is refused by a read-only mount" \
        || fail "a write into ${dir#"$REPO"}/ is refused by a read-only mount (got '$answer')"
done

expect "a write into data/logs/ succeeds" "$(ask "$SOCKET" write.php "dir=$REPO/data/logs")" "written"
expect "a write into a private /tmp succeeds" "$(ask "$SOCKET" write.php "dir=/tmp")" "written"
expect "data/session.key is readable — the pool needs it" "$(ask "$SOCKET" read.php "file=$REPO/data/session.key")" "readable"

for key in "$KEYS/update.key" "$KEYS/update-neurosys.localhost.key"; do
    answer=$(ask "$SOCKET" read.php "file=$key")
    [[ "$answer" != readable ]] \
        && pass "the signing key ${key#"$HOME"/} cannot be read" \
        || fail "the signing key ${key#"$HOME"/} cannot be read"
done

# --- a forbidden syscall kills the worker ------------------------------------------------------

since=$(date '+%Y-%m-%d %H:%M:%S')
ask "$SOCKET" dial.php "to=1.1.1.1:80" >/dev/null
status=$?
expect "an outbound connection never answers: the worker died mid-request" "$status" "2"
sleep 1
if journalctl -u "$UNIT" --since "$since" --no-pager 2>/dev/null | grep -q "exited on signal 31"; then
    pass "the master logs the worker's death by SIGSYS"
else
    fail "the master logs the worker's death by SIGSYS (is the journal readable to this user?)"
fi
expect "the pool serves the next request" "$(ask "$SOCKET" whoami.php | field seccomp)" "2"

# --- httpd reaches the confined pool, and the side door does not -------------------------------
# The status page counts itself, so a request through httpd that reached this pool moves the count
# by two between the readings, and one that did not moves it by one.

before=$(accepted)
code=$(curl -sk -o /dev/null -w '%{http_code}' https://neurosys.localhost/)
after=$(accepted)
expect "https://neurosys.localhost/ answers through httpd" "$code" "200"
expect "…and the request reached the confined pool" "$((after - before))" "2"

code=$(curl -sk -o /dev/null -w '%{http_code}' --resolve neurosys.local:443:127.0.0.1 https://neurosys.local/)
expect "https://neurosys.local/ (the LAN host) answers too" "$code" "200"

before=$(accepted)
code=$(curl -sk -o /dev/null -w '%{http_code}' https://machine.neurosys.localhost/)
after=$(accepted)
expect "https://machine.neurosys.localhost/ answers" "$code" "200"
expect "…without reaching the confined pool" "$((after - before))" "1"

if [[ -S "$SIDEDOOR" ]]; then
    door=$(ask "$SIDEDOOR" whoami.php)
    expect "the side door runs as the same user" "$(field user <<<"$door")" "'neurosys-php'"
    expect "the side door is not filtered — the difference is visible" "$(field seccomp <<<"$door")" "0"
else
    fail "the side door's socket exists ($SIDEDOOR)"
fi
