#!/bin/bash
# Nightly backup: databases and env files as dated copies; data/ and uploads/ as incremental mirrors (not 14 full copies).
# Install: cp deploy/tutash-backup.sh /usr/local/bin/tutash-backup && chmod 700 /usr/local/bin/tutash-backup
# Off-box copy: configure `rclone config` (remote named "backup") - a backup on the same disk is not a backup.
set -euo pipefail
umask 077   # the copies hold users' personal data and admin password hashes: readable by root only
APP=/srv/tutash
B=/var/backups/tutash
D=$(date +%F)
mkdir -p "$B/$D"
chmod 700 "$B"

# The bot's database: PostgreSQL when DATABASE_URL is set in the bot's .env (a consistent dump while the bot runs),
# otherwise the SQLite file. Restore: pg_restore -d tutash --clean db.dump
DBURL=$(grep -m1 '^DATABASE_URL=' "$APP/.env" | cut -d= -f2- || true)
if [ -n "$DBURL" ]; then
  pg_dump -Fc "$DBURL" > "$B/$D/bot-db.dump"
else
  sqlite3 "$APP/bot.db" ".backup '$B/$D/bot.db'"
fi
sqlite3 "$APP/web/database/database.sqlite" ".backup '$B/$D/laravel.sqlite'"

install -m 600 "$APP/.env" "$B/$D/bot.env"
install -m 600 "$APP/web/.env" "$B/$D/web.env"
# PDFs rarely change: incremental mirror, keeps _trash too. Files deleted or replaced in data/ are not lost with the mirror:
# --backup-dir moves the old copy into today's folder, which is kept 14 days like the rest (a wiped data/ stays recoverable)
rsync -a --delete --backup --backup-dir="$B/$D/data-removed" "$APP/data/" "$B/data/"
rsync -a "$APP/uploads/" "$B/uploads/"             # registration photos: incremental, never --delete (a deleted photo stays recoverable)

# keep 14 daily copies locally
find "$B" -maxdepth 1 -type d -name '20*' -mtime +14 -exec rm -rf {} +

# off-box copy (uncomment once rclone is configured)
# rclone sync "$B" backup:tutash-backup --transfers 4

# fail (-> alert) when the disk is nearly full
df -P / | awk 'NR==2 && $5+0 > 85 { exit 1 }'
