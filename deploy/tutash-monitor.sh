#!/bin/bash
# Every 5 minutes (tutash-monitor.timer): alert one admin in Telegram if the bot died, the database or the dashboard stopped
# answering, the disk is almost full or the nightly backup failed. A problem is reported when it starts and when it is fixed,
# not every 5 minutes. /etc/tutash-monitor.env (chmod 600): ALERT_BOT_TOKEN, ALERT_CHAT_ID, SITE (https://your-domain).
# Install: install -m 700 deploy/tutash-monitor.sh /usr/local/bin/tutash-monitor
#          cp deploy/tutash-monitor.{service,timer} /etc/systemd/system/ && systemctl enable --now tutash-monitor.timer
set -u
source /etc/tutash-monitor.env
STATE=/var/lib/tutash-monitor.last
problems=()
systemctl is-active --quiet tutash-bot || problems+=("bot xizmati ishlamayapti (bot service is DOWN)")
[ "$(df -P / | awk 'NR==2{print $5+0}')" -lt 85 ] || problems+=("disk 85% dan ko'proq to'lgan")
code=$(curl -s -o /dev/null -m 10 -w '%{http_code}' "$SITE/admin/login" || true)
[ "$code" = "200" ] || problems+=("dashboard javob bermayapti (HTTP $code)")
# the bot's PostgreSQL (the login page does not touch it): a hung database freezes the bot without stopping it
pg_isready -q -t 5 || problems+=("PostgreSQL ulanishni qabul qilmayapti")
systemctl is-failed --quiet tutash-backup && problems+=("tungi zaxira nusxa xato bilan tugadi (journalctl -u tutash-backup)")

now=$(printf '%s\n' "${problems[@]:-}")
last=$(cat "$STATE" 2>/dev/null || true)
[ "$now" = "$last" ] && exit 0   # nothing changed since the last check: no repeated messages
printf '%s' "$now" > "$STATE"
if [ ${#problems[@]} -gt 0 ]; then
  text="⚠️ Tutash: $(printf '\n• %s' "${problems[@]}")"
else
  text="✅ Tutash: hammasi yana ishlayapti"
fi
curl -s -m 10 "https://api.telegram.org/bot${ALERT_BOT_TOKEN}/sendMessage" \
  --data-urlencode "chat_id=${ALERT_CHAT_ID}" --data-urlencode "text=${text}" > /dev/null
