#!/bin/bash
# Deploy the latest commit of the 3dot.uz repository (run as root):  bash /srv/tutash/deploy/3dot-site/update.sh
set -euo pipefail
APPDIR=/srv/3dot/app
before=$(sudo -u site3dot git -C "$APPDIR" rev-parse HEAD)
sudo -u site3dot git -C "$APPDIR" fetch --quiet origin main
after=$(sudo -u site3dot git -C "$APPDIR" rev-parse origin/main)
if [ "$before" = "$after" ] && [ "${FORCE:-0}" != "1" ]; then echo "already up to date ($(echo "$after" | cut -c1-7))"; exit 0; fi
sudo -u site3dot git -C "$APPDIR" log --oneline "$before..origin/main" | head -10 || true
sudo -u site3dot bash -c "
  set -e
  cd '$APPDIR'
  git checkout --quiet -f -B main origin/main
  export NODE_OPTIONS=--max-old-space-size=1800 CI=1
  nice -n 19 ionice -c3 npm ci --ignore-scripts --no-audit --no-fund
  nice -n 19 npx --no-install nuxt prepare
  nice -n 19 npm run build"
sudo -u site3dot bash -c "cd '$APPDIR' && node scripts/sitemap.mjs"
install -D -m 644 "$APPDIR/.output/public/sitemap.xml" /var/www/3dot/sitemap.xml
systemctl restart 3dot-site
sleep 3
curl -s -o /dev/null -w "site: HTTP %{http_code}\n" http://127.0.0.1:3001/
echo "deployed $(sudo -u site3dot git -C "$APPDIR" rev-parse --short HEAD)"
