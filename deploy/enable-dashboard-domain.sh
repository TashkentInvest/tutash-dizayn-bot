#!/bin/bash
# Publish the dashboard on its own domain over HTTPS (run as root, after the DNS A record points to this server):
#   APP_DOMAIN=3dot.uz bash /srv/tutash/deploy/enable-dashboard-domain.sh        (optional: APP_ALIASES=www.3dot.uz)
# Safe to run again.
set -euo pipefail
: "${APP_DOMAIN:?set APP_DOMAIN, e.g. APP_DOMAIN=3dot.uz}"
ALIASES="${APP_ALIASES:-www.$APP_DOMAIN}"
EMAIL="${EMAIL:-}"
APP=/srv/tutash

# rate-limit zone for the Livewire endpoint (the login form posts there); the others come from provision.sh
grep -q 'zone=lw' /etc/nginx/conf.d/tutash-limits.conf 2>/dev/null || \
  echo 'limit_req_zone $binary_remote_addr zone=lw:10m rate=120r/m;' >> /etc/nginx/conf.d/tutash-limits.conf

sed -e "s/APP-DOMAIN/$APP_DOMAIN/g" -e "s/APP-ALIASES/$ALIASES/g" "$APP/deploy/nginx-dashboard-public.conf" > /etc/nginx/sites-available/tutash-dashboard
ln -sf /etc/nginx/sites-available/tutash-dashboard /etc/nginx/sites-enabled/tutash-dashboard
nginx -t && systemctl reload nginx

SERVER_IP=$(curl -s -4 https://ifconfig.me || true)
for h in "$APP_DOMAIN" $ALIASES; do
  [ "$(getent ahostsv4 "$h" | awk 'NR==1{print $1}')" = "$SERVER_IP" ] || { echo "DNS: $h does not point to $SERVER_IP yet"; exit 3; }
done
if [ -n "$EMAIL" ]; then ACME_ACCOUNT=(-m "$EMAIL"); else ACME_ACCOUNT=(--register-unsafely-without-email); fi
CERT_NAMES=(-d "$APP_DOMAIN"); for h in $ALIASES; do CERT_NAMES+=(-d "$h"); done
certbot --nginx "${CERT_NAMES[@]}" "${ACME_ACCOUNT[@]}" --agree-tos --no-eff-email --redirect --non-interactive

# the dashboard now lives on https: secure cookies, correct URL
cd "$APP/web"
sed -i "s#^APP_URL=.*#APP_URL=https://$APP_DOMAIN#; s#^SESSION_SECURE_COOKIE=.*#SESSION_SECURE_COOKIE=true#" .env
sudo -u tutash php artisan config:cache -q
echo "dashboard: https://$APP_DOMAIN/admin"
