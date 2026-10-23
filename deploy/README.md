# Serverga o'tish (Ubuntu 24.04) va server parametrlari

Bu papkadagi fayllar tayyor shablonlar: `tutash-bot.service`, `tutash-backup.{sh,service,timer}`, `tutash-monitor.sh`,
`nginx-tutash.conf`, `php-fpm-99-tutash.ini`, `bot.env.production.example`, `web.env.production.example`.
`YOUR-DOMAIN` ni o'zingizning domeningizga almashtiring.

## 1. Server parametri: hisob-kitob

**O'lchangan (shu kompyuterda, 3 ta mustaqil tekshiruv + o'zim):**

| Ko'rsatkich | Qiymat |
|---|---|
| Ishlayotgan bot (Python) | 171 MB RAM |
| `php` (dashboard) bir jarayon | 59–65 MB |
| `data/` (PDF fayllar) | 3.9 GB |
| 1 ta ro'yxatdan o'tgan foydalanuvchi rasmlari (2 ta) | ~0.2 MB |
| Bir tugma bosilishining bot ichidagi sarfi: **oldin → keyin** | 56/s va 2.7 s kechikish → **8 200/s va 18 ms** |
| Dashboard foydalanuvchilar ro'yxati, 100 000 foydalanuvchida: **oldin → keyin** (indekslar) | 9.2 s → ~0.6 s |

**Reja yuki:** 5 000–20 000 foydalanuvchi; e'londan keyin bir daqiqada 100–300 kishi tugma bosadi; 100–270 MB fayllar.

| Resurs | Hisob | Natija |
|---|---|---|
| **RAM** | Ubuntu 350 MB + bot 300 + php-fpm 5×60 = 300 + PostgreSQL 450 + nginx 30 (+ local Bot API 300, ixtiyoriy) | **1.4–1.7 GB**. Qolgani PDF'lar uchun fayl keshi. 2 GB tor, **4 GB** to'g'ri |
| **CPU** | bot bitta yadroda sekundiga ming marta bosishni ko'taradi, Telegram esa baribir ~20–30 bosish/s dan oshirmaydi | **2 vCPU** yetadi |
| **Disk** | OS 6 GB + `data/` 4 GB + rasmlar: 20 000 × 0.2–0.4 MB = **4–8 GB** + baza <0.2 GB + log 1 GB | **~16–20 GB**. Zaxira nusxa **serverning o'zida emas, tashqarida** bo'lsa 40 GB yetadi; ichkarida saqlansa 48 GB+ kerak |
| **Tarmoq** | Telegram orqali yuborilgan fayl serverdan chiqmaydi (Telegram'dan keladi). Faqat 50 MB dan katta *havola* fayllar serverdan: 300 kishi × 270 MB = 81 GB | 1 Gbit/s da ~11 daq., 100 Mbit/s da ~1.8 soat |

**Xulosa: Cloud NVMe 4 (2 vCPU 3 GHz / 4 GB / 40 GB NVMe, €16/oy) yetadi.** Shartlari:
1. Zaxira nusxa tashqi omborga (`rclone`) ketadi.
2. Provayderdan port tezligini so'rang (kamida **1 Gbit/s**).
3. **Local Telegram Bot API serverini** o'rnating (`api_id`/`api_hash` kerak): 50 MB dan katta fayllar ham Telegram orqali ketadi,
   server trafigi ham, "Visit Site" muammosi ham yo'qoladi. Bu eng katta tarmoq yukini olib tashlaydi.
4. Kattalashtirish belgilari: disk 70% dan oshsa → Cloud NVMe 6 (80 GB, ayniqsa to'liq PDF'lar qo'shilsa); `free -h` da swap
   ishlatilsa → RAM 6 GB.

**Birinchi to'siq nima bo'ladi (tartib bilan):** (1) Telegram'ning o'zi: sekundiga ~30 ta xabar; (2) havola fayllar uchun tarmoq;
(3) 50 000+ foydalanuvchida dashboard so'rovlari (indekslar bor, lekin PostgreSQL'ga o'tish kerak).

## 2. O'tish tartibi

1. Paketlar: `python3.13` (+venv), `php8.4-fpm` (+pgsql mbstring xml intl gd zip sqlite3), `composer`, `postgresql`, `nginx`,
   `certbot`, `rsync`, `sqlite3`, `rclone`, `ufw`, `fail2ban`. Foydalanuvchi `tutash`, papka `/srv/tutash`.
2. **SSH:** faqat kalit bilan, `PermitRootLogin no`, `PasswordAuthentication no`. **ufw:** faqat 22, 80, 443. 8787 va PostgreSQL tashqariga ochilmaydi.
3. Fayllarni ko'chirish: `rsync -a` (vaqt belgilarini saqlaydi) bilan `data/`, `uploads/`, `bot.db`. Keyin Cyrillic nomli har bir fayl
   yo'li Linux'da topilishini tekshiring (katta-kichik harf farqi).
4. `.env` fayllar: `bot.env.production.example` va `web.env.production.example` dan nusxa, `chmod 600`. **Yangi** `BOT_TOKEN` (eskisini @BotFather'da `/revoke`),
   alohida `LINK_SECRET`, `APP_DEBUG=false`.
5. Dashboard: `composer install --no-dev -o`, `php artisan key:generate`, `php artisan migrate`, `php artisan make:filament-user`,
   `php artisan config:cache route:cache`. `web/storage/logs/*.log` ni ko'chirmang (ichida shaxsiy ma'lumot bo'lishi mumkin).
6. `php-fpm-99-tutash.ini` → `/etc/php/8.4/fpm/conf.d/`. `nginx-tutash.conf` → nginx, `certbot --nginx -d YOUR-DOMAIN`.
7. `systemctl enable --now tutash-bot tutash-backup.timer`, `tutash-monitor.sh` ni 5 daqiqada bir marta ishga tushiring (alohida kichik bot orqali ogohlantirish).
8. **Ko'chirish kuni:** Windows'dagi botni **avval to'xtating** (bir token bilan ikkita bot `getUpdates` ziddiyatini beradi), so'ng Linux'dagini yoqing.
   Tekshiruv: `/start`, bitta ro'yxatdan o'tish, kichik fayl, katta havola.
9. Birinchi zaxirani qo'lda ishga tushiring va **tiklashni sinab ko'ring** (oyda bir marta takrorlang).

## 3. PostgreSQL'ga o'tish (alohida ish, serverda)

Bot hozir SQLite'da; bu fayllarning o'zida ham ishlaydi. PostgreSQL'ga o'tishda quyidagilar o'zgaradi (`bot/db.py`, `bot/catalog.py`):
`datetime('now')` → `now()`, `AUTOINCREMENT` → `GENERATED ... AS IDENTITY`, `?` → `%s`, `INSERT OR REPLACE` → `ON CONFLICT DO UPDATE`,
`lastrowid` → `RETURNING id`, `is_active` 0/1 → `boolean`, `users.user_id` → `BIGINT` (Telegram ID 2^31 dan katta bo'lishi mumkin),
matn vaqtlar → `timestamptz`. Ma'lumotlarni `pgloader` ko'chiradi. Sxemani raqamlangan SQL-migratsiyalar bilan boshqaring.
Dashboard tomoni faqat `web/.env` dagi `BOT_DB_*` o'zgaruvchilarini almashtirishni talab qiladi.
Ikkita rol: bot jadvallar egasi, dashboard esa faqat kerakli jadvallarga huquqli.

## 4. Hali qilinmagan (ochiq qoldirilgan)

* PostgreSQL porti (yuqorida), local Bot API serveri (`api_id`/`api_hash`), dashboard 2 bosqichli kirish (2FA).
* "Mening ma'lumotlarimni o'chir" buyrug'i va saqlash muddati qoidasi; dashboard'da shaxsiy ma'lumotni ko'rish jurnali.
* `data/_trash/` va `file_cache` ni vaqti-vaqti bilan tozalash.
* Rozilik matni (`bot/registration.py`, `TXT_CONSENT`) — huquqshunos yoki o'zingiz tasdiqlashingiz kerak.
