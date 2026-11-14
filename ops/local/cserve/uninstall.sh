#!/usr/bin/env bash
# Puts neurosys.localhost and neurosys.local back on Apache + PHP: undoes install.sh, restoring
# every <file>.bak-cserve it left. The certificates in /etc/cserve stay (they protect nothing
# but this machine's names); `rm -r /etc/cserve` takes them too.
set -euo pipefail

if [[ $EUID -ne 0 ]]; then
    echo "run as root: sudo $0" >&2
    exit 1
fi

CONF=/etc/httpd/conf
BACKUP=.bak-cserve

systemctl disable --now cserve-neurosys.service 2>/dev/null || true
rm -f /etc/systemd/system/cserve-neurosys.service
rm -rf /usr/local/lib/cserve-neurosys
systemctl daemon-reload

for f in $CONF/httpd.conf $CONF/extra/phpanta-vhost.conf; do
    if [[ -e "$f$BACKUP" ]]; then
        mv -f "$f$BACKUP" "$f"
    fi
done
httpd -t
systemctl restart httpd.service
echo "Uninstalled: neurosys is Apache's again. The cserve user stays (/etc/sysusers.d/cserve.conf)."
