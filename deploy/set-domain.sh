#!/bin/bash
# Move the whole service (bot download links + dashboard) to a different domain on an already installed server.
# Run as root, after the new name's DNS A record points to this server:
#   DOMAIN=bot-thudud.toshkentinvest.uz bash /srv/tutash/deploy/set-domain.sh        (optional: EMAIL=you@example.com)
# The old domain stops working here (its certificate is removed, unknown host names get no answer).
set -euo pipefail
: "${DOMAIN:?set DOMAIN, e.g. DOMAIN=bot-thudud.toshkentinvest.uz}"
EMAIL="${EMAIL:-}"
APP=/srv/tutash

SERVER_IP=$(curl -s -4 https://ifconfig.me || true)
DNS_IP=$(getent ahostsv4 "$DOMAIN" | awk 'NR==1{print $1}' || true)
[ -n "$SERVER_IP" ] && [ "$DNS_IP" = "$SERVER_IP" ] || { echo "DNS: $DOMAIN -> '${DNS_IP:-nothing}', this server is '$SERVER_IP'. Fix the A record first."; exit 3; }

grep -q 'zone=lw' /etc/nginx/conf.d/tutash-limits.conf 2>/dev/null || \
  echo 'limit_req_zone $binary_remote_addr zone=lw:10m rate=120r/m;' >> /etc/nginx/conf.d/tutash-limits.conf

OLD_CERTS=$(certbot certificates 2>/dev/null | awk '/Certificate Name:/{print $3}' | grep -vx "$DOMAIN" || true)
cp /etc/nginx/sites-available/tutash "/root/nginx-tutash.before-set-domain.$(date +%s)"
rm -f /etc/nginx/sites-enabled/tutash-dashboard /etc/nginx/sites-available/tutash-dashboard
sed "s/YOUR-DOMAIN/$DOMAIN/g" "$APP/deploy/nginx-tutash.conf" > /etc/nginx/sites-available/tutash
ln -sf /etc/nginx/sites-available/tutash /etc/nginx/sites-enabled/tutash
nginx -t && systemctl reload nginx

if [ -n "$EMAIL" ]; then ACME_ACCOUNT=(-m "$EMAIL"); else ACME_ACCOUNT=(--register-unsafely-without-email); fi
certbot --nginx -d "$DOMAIN" "${ACME_ACCOUNT[@]}" --agree-tos --no-eff-email --redirect --non-interactive

# settings that contain the address: bot links, dashboard url, secure cookies
sed -i "s#^PUBLIC_BASE_URL=.*#PUBLIC_BASE_URL=https://$DOMAIN#" "$APP/.env"
sed -i "s#^APP_URL=.*#APP_URL=https://$DOMAIN#; s#^SESSION_SECURE_COOKIE=.*#SESSION_SECURE_COOKIE=true#" "$APP/web/.env"
( cd "$APP/web" && sudo -u tutash php artisan config:cache -q )
systemctl restart tutash-bot

# the old certificates would only fail to renew once their names stop pointing here
for c in $OLD_CERTS; do certbot delete --cert-name "$c" --non-interactive || true; done
sed -i "s#https://[A-Za-z0-9.-]*/admin/login#https://$DOMAIN/admin/login#" /usr/local/bin/tutash-monitor 2>/dev/null || true
echo "done: https://$DOMAIN/admin   (links: https://$DOMAIN/f/...)"
