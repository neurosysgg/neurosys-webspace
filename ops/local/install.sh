#!/usr/bin/env bash
# Installs the confined php-fpm instance for neurosys.localhost and neurosys.local, and the
# unconfined side door at machine.neurosys.localhost. Run once, as root:
#
#   sudo ops/local/install.sh
#
# Every system file it changes is copied to <file>.bak-confine first; ops/local/uninstall.sh puts
# them back. It stops at the first failure, before httpd is restarted on a config that does not test.
# See docs/research/attested-single-box.md (Phase 1).
set -euo pipefail

if [[ $EUID -ne 0 ]]; then
    echo "run as root: sudo $0" >&2
    exit 1
fi

REPO="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
OPS="$REPO/ops/local"
CONF=/etc/httpd/conf
EXTRA=$CONF/extra
BACKUP=.bak-confine

backup() {
    [[ -e "$1" && ! -e "$1$BACKUP" ]] && cp -a "$1" "$1$BACKUP"
    return 0
}

echo "== the filter"
build=$(mktemp -d)
trap 'rm -rf "$build"' EXIT
make -s -C "$REPO/phpanta/tools/confine" OUT="$build"
install -D -o root -g root -m 0644 "$build/confine.so" /usr/local/lib/phpanta/confine.so

echo "== the user"
install -o root -g root -m 0644 "$OPS/neurosys-php.sysusers" /etc/sysusers.d/neurosys-php.conf
systemd-sysusers /etc/sysusers.d/neurosys-php.conf

echo "== data/ and the serial, handed from http to neurosys-php"
# Both pools run as neurosys-php, and the framework writes the passkey store, the drops and the
# serial at 0600; what the www pool wrote as http is handed over once. uninstall.sh hands it back.
find "$REPO/data" "$REPO/.update-serial" -user http -exec chown neurosys-php {} +
chmod g+s "$REPO/data/logs"

echo "== the confined instance"
install -D -o root -g root -m 0644 "$OPS/php-fpm.conf" /etc/php-fpm-neurosys/php-fpm.conf
install -o root -g root -m 0644 "$OPS/php-fpm-neurosys.service" /etc/systemd/system/php-fpm-neurosys.service
/usr/bin/php-fpm -t -y /etc/php-fpm-neurosys/php-fpm.conf -d extension=/usr/local/lib/phpanta/confine.so
systemd-analyze verify /etc/systemd/system/php-fpm-neurosys.service

echo "== the side door's pool, in the system php-fpm"
install -o root -g root -m 0644 "$OPS/neurosys-sidedoor.pool.conf" /etc/php/php-fpm.d/neurosys-sidedoor.conf
/usr/bin/php-fpm -t

echo "== the side door's certificate"
if [[ ! -e $CONF/machine.neurosys.localhost.crt ]]; then
    openssl req -x509 -newkey ec -pkeyopt ec_paramgen_curve:P-256 -nodes -days 825 \
        -subj /CN=machine.neurosys.localhost \
        -addext subjectAltName=DNS:machine.neurosys.localhost \
        -addext basicConstraints=critical,CA:FALSE \
        -keyout $CONF/machine.neurosys.localhost.key -out $CONF/machine.neurosys.localhost.crt 2>/dev/null
    chmod 0600 $CONF/machine.neurosys.localhost.key
fi

echo "== httpd"
install -o root -g root -m 0644 "$OPS/httpd/neurosys-confined-handler.conf" $EXTRA/neurosys-confined-handler.conf
install -o root -g root -m 0644 "$OPS/httpd/neurosys-machine-vhost.conf" $EXTRA/neurosys-machine-vhost.conf

for vhost in $EXTRA/neurosys-vhost.conf $EXTRA/neurosys-lan-vhost.conf; do
    if ! grep -q neurosys-confined-handler "$vhost"; then
        backup "$vhost"
        sed -i -E '/^\s*ServerName neurosys\.local(host)?\s*$/a\    Include conf/extra/neurosys-confined-handler.conf' "$vhost"
    fi
done

if ! grep -q neurosys-machine-vhost $CONF/httpd.conf; then
    backup $CONF/httpd.conf
    echo 'Include conf/extra/neurosys-machine-vhost.conf' >> $CONF/httpd.conf
fi

httpd -t

echo "== start"
systemctl daemon-reload
systemctl enable --now php-fpm-neurosys.service
systemctl restart php-fpm.service
systemctl restart httpd.service

systemctl --no-pager --lines=0 status php-fpm-neurosys.service
echo ""
echo "Installed. Now: bash test/confinement_test.sh"
