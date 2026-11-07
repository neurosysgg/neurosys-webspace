#!/usr/bin/env bash
# Takes the confined instance and the side door away again and puts httpd back on the www pool
# exactly as install.sh found it. Run as root: sudo ops/local/uninstall.sh
# The user neurosys-php is left in place (removing a uid that owned files is its own decision);
# what it owns in data/ is handed back to http.
set -euo pipefail

if [[ $EUID -ne 0 ]]; then
    echo "run as root: sudo $0" >&2
    exit 1
fi

REPO="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
CONF=/etc/httpd/conf
EXTRA=$CONF/extra
BACKUP=.bak-confine

restore() {
    if [[ -e "$1$BACKUP" ]]; then
        mv "$1$BACKUP" "$1"
    fi
}

restore $EXTRA/neurosys-vhost.conf
restore $EXTRA/neurosys-lan-vhost.conf
restore $CONF/httpd.conf
rm -f $EXTRA/neurosys-confined-handler.conf $EXTRA/neurosys-machine-vhost.conf
httpd -t

systemctl disable --now php-fpm-neurosys.service || true
rm -f /etc/systemd/system/php-fpm-neurosys.service /etc/php/php-fpm.d/neurosys-sidedoor.conf
rm -rf /etc/php-fpm-neurosys
systemctl daemon-reload

if id neurosys-php >/dev/null 2>&1; then
    find "$REPO/data" "$REPO/.update-serial" -user neurosys-php -exec chown http {} +
fi

systemctl restart php-fpm.service
systemctl restart httpd.service
echo "Uninstalled; neurosys.localhost is on the www pool again."
