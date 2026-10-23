#!/bin/bash
# Every 5 minutes (tutash-monitor.timer or cron): alert a Telegram admin chat if the bot died, the disk is almost full,
# or the dashboard stopped answering. Put ALERT_BOT_TOKEN and ALERT_CHAT_ID in /etc/tutash-monitor.env (chmod 600).
# Use a SEPARATE small bot for alerts, not the production bot's token.
source /etc/tutash-monitor.env
problems=()
systemctl is-active --quiet tutash-bot || problems+=("bot service is DOWN")
[ "$(df -P / | awk 'NR==2{print $5+0}')" -lt 85 ] || problems+=("disk is over 85% full")
code=$(curl -s -o /dev/null -m 10 -w '%{http_code}' https://YOUR-DOMAIN/admin/login || true)
[ "$code" = "200" ] || problems+=("dashboard answered HTTP $code")
if [ ${#problems[@]} -gt 0 ]; then
  curl -s -m 10 "https://api.telegram.org/bot${ALERT_BOT_TOKEN}/sendMessage" \
    --data-urlencode "chat_id=${ALERT_CHAT_ID}" \
    --data-urlencode "text=Tutash: ${problems[*]}" > /dev/null
fi
