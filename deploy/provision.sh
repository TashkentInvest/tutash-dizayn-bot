#!/bin/bash
# One-time server setup, Ubuntu 24.04, run as root. Safe to run again (every step checks what is already done).
#   DOMAIN=bot.3dot.uz EMAIL=you@example.com bash provision.sh
# What it does NOT do: put the bot token in (you type it into /srv/tutash/.env yourself), copy data/uploads/bot.db (see
# deploy/README.md), PostgreSQL (the bot still uses SQLite; that port is a separate job).
set -euo pipefail
: "${DOMAIN:?set DOMAIN, e.g. DOMAIN=bot.3dot.uz}"
# EMAIL is optional (certificate expiry notices; renewal is automatic anyway). ADMIN_EMAIL is only the dashboard login name.
EMAIL="${EMAIL:-}"
ADMIN_EMAIL="${ADMIN_EMAIL:-${EMAIL:-admin@$DOMAIN}}"
# public repository: plain https, no key needed. For a private one use REPO_URL=git@github.com:... (a deploy key is created and shown).
REPO_URL="${REPO_URL:-https://github.com/TashkentInvest/tutash-dizayn-bot.git}"
APP=/srv/tutash
KEY=/home/tutash/.ssh/id_ed25519_deploy
export DEBIAN_FRONTEND=noninteractive
step() { echo; echo "==> $*"; }

step "1/11 packages"
apt-get update -y
apt-get -y upgrade
# composer.lock was resolved on PHP 8.4 (Symfony 8 needs >= 8.4.1) but Ubuntu 24.04 ships 8.3: use the standard PHP PPA
apt-get install -y software-properties-common
add-apt-repository -y ppa:ondrej/php
apt-get update -y
apt-get install -y ufw fail2ban nginx git rsync sqlite3 curl unzip ca-certificates unattended-upgrades \
  python3 python3-venv python3-pip certbot python3-certbot-nginx \
  php8.4-fpm php8.4-cli php8.4-sqlite3 php8.4-pgsql php8.4-mbstring php8.4-xml php8.4-intl php8.4-gd \
  php8.4-zip php8.4-curl php8.4-bcmath composer

# optional: install an SSH public key for root (key login), e.g. SSH_PUBKEY="ssh-ed25519 AAAA... name"
if [ -n "${SSH_PUBKEY:-}" ]; then
  mkdir -p /root/.ssh && chmod 700 /root/.ssh
  grep -qF "$SSH_PUBKEY" /root/.ssh/authorized_keys 2>/dev/null || echo "$SSH_PUBKEY" >> /root/.ssh/authorized_keys
  chmod 600 /root/.ssh/authorized_keys
fi

step "2/11 swap (a 2 GB upload must not be able to kill the server)"
if ! swapon --show | grep -q .; then
  fallocate -l 2G /swapfile && chmod 600 /swapfile && mkswap /swapfile && swapon /swapfile
  grep -q '^/swapfile' /etc/fstab || echo '/swapfile none swap sw 0 0' >> /etc/fstab
fi

step "3/11 user and folders"
id tutash >/dev/null 2>&1 || useradd --create-home --shell /bin/bash tutash
usermod -aG www-data tutash
mkdir -p "$APP/data" "$APP/uploads" /home/tutash/.ssh
chown -R tutash:www-data "$APP"; chmod 2770 "$APP" "$APP/data" "$APP/uploads"
chown -R tutash:tutash /home/tutash/.ssh; chmod 700 /home/tutash/.ssh
# php-fpm writes into the same folders (dashboard uploads, bot.db): files it creates must be group-writable
systemctl disable --now php8.3-fpm 2>/dev/null || true   # the first run installed 8.3; one PHP is enough
mkdir -p /etc/systemd/system/php8.4-fpm.service.d
printf '[Service]\nUMask=0007\n' > /etc/systemd/system/php8.4-fpm.service.d/umask.conf
systemctl daemon-reload

step "4/11 access to the repository"
if [[ "$REPO_URL" == git@* ]]; then
  [ -f "$KEY" ] || sudo -u tutash ssh-keygen -t ed25519 -N "" -C "tutash-server-deploy" -f "$KEY" -q
  if ! sudo -u tutash GIT_SSH_COMMAND="ssh -i $KEY -o IdentitiesOnly=yes -o StrictHostKeyChecking=accept-new"        git ls-remote "$REPO_URL" >/dev/null 2>&1; then
    echo "GitHub does not accept this server yet. Add this PUBLIC key as a read-only Deploy key of the repository,"
    echo "then run this script again:"; cat "$KEY.pub"; exit 2
  fi
else
  sudo -u tutash git ls-remote "$REPO_URL" >/dev/null   # public: just check that it is reachable
fi

step "5/11 code from GitHub"
cd "$APP"
sudo -u tutash bash -c "
  export GIT_SSH_COMMAND='ssh -i $KEY -o IdentitiesOnly=yes -o StrictHostKeyChecking=accept-new'
  [ -d .git ] || git init -q -b main
  git remote get-url origin >/dev/null 2>&1 || git remote add origin '$REPO_URL'
  git fetch -q origin main && git checkout -q -f -B main origin/main
"

step "6/11 bot: python environment and settings"
sudo -u tutash bash -c "python3 -m venv '$APP/venv' && '$APP/venv/bin/pip' install -q -r '$APP/requirements.txt'"
if [ ! -f "$APP/.env" ]; then
  LINK_SECRET=$(python3 -c "import secrets;print(secrets.token_hex(32))")
  cat > "$APP/.env" <<EOF
BOT_TOKEN=
LINK_SECRET=$LINK_SECRET
PUBLIC_BASE_URL=https://$DOMAIN
WEB_HOST=127.0.0.1
WEB_PORT=8787
LINK_TTL_HOURS=24
DATA_DIR=$APP/data
UPLOADS_DIR=$APP/uploads
DB_PATH=$APP/bot.db
REQUIRE_APPROVAL=0
USER_COOLDOWN=0.7
MAX_PARALLEL_UPLOADS=3
EOF
  chown tutash:tutash "$APP/.env"; chmod 600 "$APP/.env"
fi

step "7/11 dashboard: dependencies, settings, admin account"
sudo -u tutash bash -c "cd '$APP/web' && composer install --no-dev -o --no-interaction -q"
if [ ! -f "$APP/web/.env" ]; then
  sudo -u tutash bash -c "cd '$APP/web' && cp .env.example .env && php artisan key:generate --force -q"
  cat >> "$APP/web/.env" <<EOF

# --- production overrides (appended by provision.sh) ---
APP_NAME="Tutash Admin"
APP_ENV=production
APP_DEBUG=false
APP_URL=http://127.0.0.1:8000
APP_LOCALE=uz
LOG_LEVEL=warning
ADMIN_EMAILS=$ADMIN_EMAIL
TRUSTED_PROXIES=127.0.0.1
SESSION_DRIVER=database
SESSION_LIFETIME=60
SESSION_ENCRYPT=true
# the dashboard is opened through an SSH tunnel over plain http; switch to true if you ever publish it over https
SESSION_SECURE_COOKIE=false
SESSION_SAME_SITE=strict
DB_CONNECTION=sqlite
CACHE_STORE=database
BOT_DB_DRIVER=sqlite
BOT_DB_DATABASE=$APP/bot.db
BOT_DATA_PATH=$APP/data
BOT_UPLOADS_PATH=$APP/uploads
DISPLAY_TIMEZONE=Asia/Tashkent
EOF
  chown tutash:www-data "$APP/web/.env"; chmod 640 "$APP/web/.env"
fi
cd "$APP/web"
sudo -u tutash bash -c "touch database/database.sqlite && php artisan migrate --force -q && php artisan filament:assets -q && php artisan config:cache -q"
if ! sudo -u tutash sqlite3 database/database.sqlite "select 1 from users where email='$ADMIN_EMAIL'" | grep -q 1; then
  ADMIN_PW=$(python3 -c "import secrets;print(secrets.token_urlsafe(18))")
  sudo -u tutash php artisan make:filament-user --name=Admin --email="$ADMIN_EMAIL" --password="$ADMIN_PW" --no-interaction
  umask 077; printf 'email: %s\npassword: %s\n' "$ADMIN_EMAIL" "$ADMIN_PW" > /root/tutash-admin-login.txt
  echo "Dashboard login saved in /root/tutash-admin-login.txt (readable only by root); change the password after the first sign-in."
fi
chgrp -R www-data "$APP/web/storage" "$APP/web/bootstrap/cache" "$APP/web/database"; chmod -R g+rwX "$APP/web/storage" "$APP/web/bootstrap/cache" "$APP/web/database"

step "8/11 php and nginx"
cp "$APP/deploy/php-fpm-99-tutash.ini" /etc/php/8.4/fpm/conf.d/99-tutash.ini
cat > /etc/nginx/conf.d/tutash-limits.conf <<'EOF'
limit_req_zone  $binary_remote_addr zone=login:10m rate=5r/m;
limit_req_zone  $binary_remote_addr zone=files:10m rate=30r/m;
limit_conn_zone $binary_remote_addr zone=perip:10m;
EOF
sed "s/YOUR-DOMAIN/$DOMAIN/g" "$APP/deploy/nginx-tutash.conf" > /etc/nginx/sites-available/tutash
ln -sf /etc/nginx/sites-available/tutash /etc/nginx/sites-enabled/tutash
rm -f /etc/nginx/sites-enabled/default
nginx -t && systemctl reload nginx && systemctl restart php8.4-fpm

step "9/11 systemd: bot, nightly backup"
install -m 644 "$APP/deploy/tutash-bot.service" /etc/systemd/system/tutash-bot.service
install -m 644 "$APP/deploy/tutash-backup.service" /etc/systemd/system/tutash-backup.service
install -m 644 "$APP/deploy/tutash-backup.timer" /etc/systemd/system/tutash-backup.timer
install -m 700 "$APP/deploy/tutash-backup.sh" /usr/local/bin/tutash-backup
systemctl daemon-reload
systemctl enable --now tutash-backup.timer
systemctl enable tutash-bot
if grep -qE '^BOT_TOKEN=.+' "$APP/.env"; then systemctl restart tutash-bot; else
  echo "BOT_TOKEN is empty in $APP/.env: put the new token there, then: systemctl start tutash-bot"; fi

step "10/11 HTTPS certificate (needs $DOMAIN to point to this server)"
SERVER_IP=$(curl -s -4 https://ifconfig.me || true)
DNS_IP=$(getent ahostsv4 "$DOMAIN" | awk 'NR==1{print $1}' || true)
if [ -n "$SERVER_IP" ] && [ "$DNS_IP" = "$SERVER_IP" ]; then
  if [ -n "$EMAIL" ]; then ACME_ACCOUNT=(-m "$EMAIL"); else ACME_ACCOUNT=(--register-unsafely-without-email); fi
  certbot --nginx -d "$DOMAIN" "${ACME_ACCOUNT[@]}" --agree-tos --no-eff-email --redirect --non-interactive
else
  echo "DNS check: $DOMAIN -> '${DNS_IP:-nothing}', this server is '$SERVER_IP'. Not issuing the certificate yet."
  echo "When DNS is right run:  certbot --nginx -d $DOMAIN --agree-tos --redirect --non-interactive --register-unsafely-without-email"
fi

step "11/11 firewall and SSH hardening"
ufw default deny incoming; ufw default allow outgoing
ufw allow OpenSSH; ufw allow 80/tcp; ufw allow 443/tcp
ufw --force enable
systemctl enable --now fail2ban
if [ -s /root/.ssh/authorized_keys ]; then   # only when a key is installed, otherwise we would lock ourselves out
  printf 'PasswordAuthentication no\nPermitRootLogin prohibit-password\n' > /etc/ssh/sshd_config.d/99-tutash.conf
  sshd -t && systemctl reload ssh
fi

echo; echo "DONE. Next: put BOT_TOKEN in $APP/.env, copy data/ uploads/ bot.db (deploy/README.md), then: systemctl restart tutash-bot"
