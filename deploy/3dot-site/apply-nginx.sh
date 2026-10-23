#!/bin/bash
# nginx + HTTPS for 3dot.uz and www.3dot.uz (run as root; safe to run again; does not rebuild the site).
#   bash /srv/tutash/deploy/3dot-site/apply-nginx.sh        (optional: EMAIL=you@example.com)
set -euo pipefail
EMAIL="${EMAIL:-}"
TUTASH=/srv/tutash
install -m 644 "$TUTASH/deploy/nginx-limits.conf" /etc/nginx/conf.d/tutash-limits.conf
install -m 644 "$TUTASH/deploy/3dot-site/nginx-3dot.conf" /etc/nginx/sites-available/3dot
ln -sf /etc/nginx/sites-available/3dot /etc/nginx/sites-enabled/3dot
nginx -t && systemctl reload nginx
SERVER_IP=$(curl -s -4 https://ifconfig.me || true)
for h in 3dot.uz www.3dot.uz; do
  dns=$(getent ahostsv4 "$h" | awk 'NR==1{print $1}' || true)
  [ -n "$SERVER_IP" ] && [ "$dns" = "$SERVER_IP" ] || { echo "DNS: $h -> '${dns:-nothing}', this server is '$SERVER_IP'. Point the A record here, then run this script again."; exit 3; }
done
if [ -n "$EMAIL" ]; then ACME_ACCOUNT=(-m "$EMAIL"); else ACME_ACCOUNT=(--register-unsafely-without-email); fi
certbot --nginx -d 3dot.uz -d www.3dot.uz "${ACME_ACCOUNT[@]}" --agree-tos --no-eff-email --redirect --keep-until-expiring --non-interactive
echo; echo "DONE: https://3dot.uz"
