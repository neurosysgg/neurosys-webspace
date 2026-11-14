#!/usr/bin/env bash
# Moves neurosys.localhost and neurosys.local from Apache + PHP to c-serve, on this machine.
# Apache keeps phpanta.localhost alone, moved to :8080 and :8444. Build the binary first, as
# yourself (not root), then install it as root:
#
#   make -C ~/Projects/c-serve B=build/O2-neurosys-local EXTERNAL_APPS=neurosys=$PWD/app \
#       STATIC_APPS=neurosys build/O2-neurosys-local/bin/cserve-static
#   sudo ops/local/cserve/install.sh ~/Projects/c-serve/build/O2-neurosys-local/bin/cserve-static
#
# Run it again with a new binary to update it. Every system file it changes is copied to
# <file>.bak-cserve first; ops/local/cserve/uninstall.sh puts them back. It stops at the first
# failure, before httpd is restarted on a config that does not test.
set -euo pipefail

if [[ $EUID -ne 0 ]]; then
    echo "run as root: sudo $0 BINARY" >&2
    exit 1
fi
if [[ $# -ne 1 || ! -x "$1" ]]; then
    echo "usage: $0 path/to/cserve-static (built with STATIC_APPS=neurosys)" >&2
    exit 1
fi

OPS="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
CONF=/etc/httpd/conf
EXTRA=$CONF/extra
CERTS=/etc/cserve/certs
LIB=/usr/local/lib/cserve-neurosys
BACKUP=.bak-cserve

backup() {
    [[ -e "$1" && ! -e "$1$BACKUP" ]] && cp -a "$1" "$1$BACKUP"
    return 0
}

echo "== the user"
install -o root -g root -m 0644 "$OPS/cserve.sysusers" /etc/sysusers.d/cserve.conf
systemd-sysusers /etc/sysusers.d/cserve.conf

echo "== the binary"
install -D -o root -g root -m 0755 "$1" "$LIB/cserve-static.new"
mv -f "$LIB/cserve-static.new" "$LIB/cserve-static"

echo "== the certificates"
# DIR/<name>/{cert,key}.pem, P-256 alone; the group says who may read a key
install -d -o root -g cserve -m 0750 /etc/cserve "$CERTS" "$CERTS/neurosys.local" "$CERTS/neurosys.localhost"
# neurosys.local: the LAN dev CA's certificate, already P-256
install -o root -g cserve -m 0644 $CONF/neurosys.local.crt "$CERTS/neurosys.local/cert.pem"
install -o root -g cserve -m 0640 $CONF/neurosys.local.key "$CERTS/neurosys.local/key.pem"
# neurosys.localhost: Apache's is RSA, so a P-256 one of its own, made once
if [[ ! -e "$CERTS/neurosys.localhost/cert.pem" ]]; then
    openssl req -x509 -newkey ec -pkeyopt ec_paramgen_curve:P-256 -nodes -days 825 \
        -subj /CN=neurosys.localhost \
        -addext subjectAltName=DNS:neurosys.localhost \
        -addext basicConstraints=critical,CA:FALSE \
        -keyout "$CERTS/neurosys.localhost/key.pem" -out "$CERTS/neurosys.localhost/cert.pem" 2>/dev/null
    chown root:cserve "$CERTS/neurosys.localhost/key.pem" "$CERTS/neurosys.localhost/cert.pem"
    chmod 0640 "$CERTS/neurosys.localhost/key.pem"
    chmod 0644 "$CERTS/neurosys.localhost/cert.pem"
fi

echo "== the unit"
install -o root -g root -m 0644 "$OPS/cserve-neurosys.service" /etc/systemd/system/cserve-neurosys.service
systemd-analyze verify /etc/systemd/system/cserve-neurosys.service

echo "== httpd: phpanta.localhost alone, on :8080 and :8444"
if grep -qE '^Include conf/extra/neurosys(-lan)?-vhost\.conf' $CONF/httpd.conf; then
    backup $CONF/httpd.conf
    sed -i -E 's|^(Include conf/extra/neurosys(-lan)?-vhost\.conf)|# on c-serve (cserve-neurosys.service): \1|' $CONF/httpd.conf
fi
if grep -qE '^Listen 80$' $CONF/httpd.conf; then
    backup $CONF/httpd.conf
    sed -i -E 's|^Listen 80$|Listen 8080|' $CONF/httpd.conf
fi
if ! grep -q '^Listen 8444' $EXTRA/phpanta-vhost.conf; then
    backup $EXTRA/phpanta-vhost.conf
    sed -i -E -e 's|<VirtualHost \*:80>|<VirtualHost *:8080>|' -e 's|<VirtualHost \*:443>|<VirtualHost *:8444>|' \
        $EXTRA/phpanta-vhost.conf
    sed -i '1i Listen 8444' $EXTRA/phpanta-vhost.conf
fi
httpd -t

echo "== start"
systemctl daemon-reload
systemctl restart httpd.service
systemctl enable cserve-neurosys.service
systemctl restart cserve-neurosys.service
sleep 1
systemctl --no-pager --lines=5 status cserve-neurosys.service
echo ""
echo "Installed: https://neurosys.localhost/ and https://neurosys.local/ on c-serve;"
echo "phpanta.localhost on Apache at http://phpanta.localhost:8080/ and https://phpanta.localhost:8444/."
echo "The access log: journalctl -u cserve-neurosys -f"
