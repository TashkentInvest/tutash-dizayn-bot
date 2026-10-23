#!/bin/bash
# Nightly backup: databases and env files as dated copies; data/ and uploads/ as incremental mirrors (not 14 full copies).
# Install: cp deploy/tutash-backup.sh /usr/local/bin/tutash-backup && chmod 700 /usr/local/bin/tutash-backup
# Off-box copy: configure `rclone config` (remote named "backup") - a backup on the same disk is not a backup.
set -euo pipefail
APP=/srv/tutash
B=/var/backups/tutash
D=$(date +%F)
mkdir -p "$B/$D"

# SQLite (current) - a consistent copy even while the bot is writing. After the PostgreSQL move replace with:
#   sudo -u postgres pg_dump -Fc tutash > "$B/$D/db.dump"
sqlite3 "$APP/bot.db" ".backup '$B/$D/bot.db'"
sqlite3 "$APP/web/database/database.sqlite" ".backup '$B/$D/laravel.sqlite'"

install -m 600 "$APP/.env" "$B/$D/bot.env"
install -m 600 "$APP/web/.env" "$B/$D/web.env"
rsync -a --delete "$APP/data/" "$B/data/"          # PDFs rarely change: incremental mirror, keeps _trash too
rsync -a "$APP/uploads/" "$B/uploads/"             # registration photos: incremental, never --delete (a deleted photo stays recoverable)

# keep 14 daily copies locally
find "$B" -maxdepth 1 -type d -name '20*' -mtime +14 -exec rm -rf {} +

# off-box copy (uncomment once rclone is configured)
# rclone sync "$B" backup:tutash-backup --transfers 4

# fail (-> alert) when the disk is nearly full
df -P / | awk 'NR==2 && $5+0 > 85 { exit 1 }'
