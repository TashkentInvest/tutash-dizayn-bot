#!/bin/bash
# Deploy the latest code from GitHub. Run as root on the server:  bash /srv/tutash/deploy/update.sh
set -euo pipefail
APP=/srv/tutash
KEY=/home/tutash/.ssh/id_ed25519_deploy
cd "$APP"
if [ -z "${TUTASH_UPDATE_FRESH:-}" ]; then
  sudo -u tutash GIT_SSH_COMMAND="ssh -i $KEY -o IdentitiesOnly=yes" git fetch -q origin main
  echo "changes to deploy:"; sudo -u tutash git --no-pager log --oneline HEAD..origin/main || true
  sudo -u tutash git checkout -q -f -B main origin/main
  # bash keeps reading the copy of this script it opened: run the version just checked out, so a deploy that changes the
  # deploy steps uses the new steps at once (it once skipped the bot's migrations and the bot refused to start)
  TUTASH_UPDATE_FRESH=1 exec bash "$APP/deploy/update.sh"
fi
sudo -u tutash "$APP/venv/bin/pip" install -q -r requirements.txt
cd web
sudo -u tutash composer install --no-dev -o --no-interaction -q
sudo -u tutash php artisan config:cache -q   # first: migrate must see the current .env, not the last deploy's
sudo -u tutash php artisan migrate --force -q
# the bot's database (tables, indexes, foreign keys): its schema lives in these migrations; the bot only checks it at start
sudo -u tutash php artisan migrate --path=database/migrations/bot --force -q
sudo -u tutash php artisan filament:assets -q
sudo -u tutash php artisan view:clear -q
chgrp -R www-data storage bootstrap/cache; chmod -R g+rwX storage bootstrap/cache
# the bot (group tutash) writes registration photos, the dashboard (www-data) shows them: the folders carry the www-data
# group and pass it on to every new folder (setgid). Without it the photos of new users were "not found" in the dashboard.
chgrp -R www-data "$APP/uploads" "$APP/data"; chmod -R g+rwX "$APP/uploads" "$APP/data"
find "$APP/uploads" "$APP/data" -type d ! -perm -g+s -exec chmod g+s {} +
# the new bot (it refuses to start on an old schema, so the migrations above run first), then the new dashboard
systemctl restart tutash-bot
restarts=$(systemctl show -p NRestarts --value tutash-bot)
sleep 15   # three times RestartSec: a crash loop has restarted by now
# "active" alone is not enough: a bot that exits at once is restarted every 5 s and looks active most of the time
if [ "$(systemctl show -p NRestarts --value tutash-bot)" != "$restarts" ] || ! systemctl is-active -q tutash-bot; then
  echo "THE BOT DOES NOT STAY UP. Its last words:"; journalctl -u tutash-bot -n 15 --no-pager; exit 1
fi
systemctl reload php8.4-fpm
echo "deployed: $(sudo -u tutash git -C $APP rev-parse --short HEAD)"
