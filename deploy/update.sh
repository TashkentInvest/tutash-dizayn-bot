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
sudo -u tutash php artisan migrate --force -q
sudo -u tutash php artisan filament:assets -q
sudo -u tutash php artisan config:cache -q
chgrp -R www-data storage bootstrap/cache; chmod -R g+rwX storage bootstrap/cache
systemctl reload php8.4-fpm
systemctl restart tutash-bot
sleep 3; systemctl is-active tutash-bot && echo "deployed: $(sudo -u tutash git -C $APP rev-parse --short HEAD)"
