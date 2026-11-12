#!/bin/bash
# Deploy the latest code from GitHub. Run as root on the server:  bash /srv/tutash/deploy/update.sh
set -euo pipefail
APP=/srv/tutash
KEY=/home/tutash/.ssh/id_ed25519_deploy
cd "$APP"
sudo -u tutash GIT_SSH_COMMAND="ssh -i $KEY -o IdentitiesOnly=yes" git fetch -q origin main
echo "changes to deploy:"; sudo -u tutash git --no-pager log --oneline HEAD..origin/main || true
sudo -u tutash git checkout -q -f -B main origin/main
sudo -u tutash "$APP/venv/bin/pip" install -q -r requirements.txt
cd web
sudo -u tutash composer install --no-dev -o --no-interaction -q
sudo -u tutash php artisan config:cache -q   # first: migrate must see the current .env, not the last deploy's
sudo -u tutash php artisan migrate --force -q
sudo -u tutash php artisan filament:assets -q
sudo -u tutash php artisan view:clear -q
chgrp -R www-data storage bootstrap/cache; chmod -R g+rwX storage bootstrap/cache
# the bot (group tutash) writes registration photos, the dashboard (www-data) shows them: the folders carry the www-data
# group and pass it on to every new folder (setgid). Without it the photos of new users were "not found" in the dashboard.
chgrp -R www-data "$APP/uploads" "$APP/data"; chmod -R g+rwX "$APP/uploads" "$APP/data"
find "$APP/uploads" "$APP/data" -type d ! -perm -g+s -exec chmod g+s {} +
# the bot owns the bot database schema (adds columns/indexes at start): restart it before the new dashboard goes live
systemctl restart tutash-bot
sleep 10   # longer than RestartSec: a crash loop would not look "active" by now
systemctl is-active tutash-bot
systemctl reload php8.4-fpm
echo "deployed: $(sudo -u tutash git -C $APP rev-parse --short HEAD)"
