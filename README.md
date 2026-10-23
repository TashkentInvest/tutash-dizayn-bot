# Tutash bot (@DizaynKodBot)

Telegram bot: `/start` → 12 districts → streets of the district → the street's PDF file(s).
Every user who touches the bot is saved to SQLite (`bot.db`: `users`, `downloads`, `file_cache`).

## Run

```
pip install aiogram
python -m bot.main
```

Token and options live in `.env` (not committed): `BOT_TOKEN`, optional `LOCAL_API_URL`.

## Data

> The catalog now lives in the database (`districts`, `streets`, `street_files`) and is edited in the dashboard
> (Katalog → Tumanlar / Ko'chalar va fayllar). `data/` only holds the files; the first bot start imported the
> folder tree below into the tables. Dashboard uploads go to `data/dashboard/<street id>/`, deletes move files to `data/_trash/`.

`data/<NN_District>/<NN_Street>/*.pdf` — folder names are the names from
`142 талик кўчалар охири.xlsx` (sheet `Sheet1 (3)`). The bot reads this tree on every request,
so to add or replace a file just drop it into the street folder (several files per street are all sent).
Streets with a file get a 📄 mark.

`tools/build_data.py` rebuilds the tree from the extracted archives (the Russian-folder → Excel-name
mapping is the `M` table inside). `tools/extract_mini.py` extracts only the `*мини*.pdf` files from the RARs.

## 50 MB limit

The public Bot API only accepts uploads up to 50 MB; 10 of the 59 mini PDFs are bigger.
Run a local Bot API server (limit 2 GB) and set `LOCAL_API_URL=http://localhost:8081` in `.env`.
Needs `api_id` / `api_hash` from https://my.telegram.org. Until then the bot tells the user the file is too big.

## Admin dashboard (`web/`)

Laravel 13 + Filament 5, read-only view of the bot's data: users (filters: registered, location, dates,
downloaded), their photos and map link, download log (filters: district, street, dates), dashboard with
totals, daily chart and top streets.

```
powershell -File start-dashboard.ps1     # then open http://127.0.0.1:8000/admin
```

Login: `ADMIN_EMAIL` / `ADMIN_PASSWORD` in `web/.env`.

- The bot owns its tables; the dashboard connects through the `bot` connection (`BOT_DB_*` in `web/.env`).
  When the bot moves to PostgreSQL only those variables change (`BOT_DB_DRIVER=pgsql`, host, user, ...).
- All numbers come from `web/app/Services/BotStats.php` (no Filament inside), so a new UI or an API can reuse them.
- Photos are served by `/bot-photo/{id}` to logged-in admins only, from `BOT_UPLOADS_PATH`.
- Times are stored in UTC and shown in `DISPLAY_TIMEZONE` (Asia/Tashkent).
- Filament passes closure arguments by *name* (`$query`, `$data`, `$search`, `$record`) — keep those names.

## Server

Serverga o'tish, parametrlar hisob-kitobi, zaxira nusxa, nginx va systemd shablonlari: [deploy/README.md](deploy/README.md).
Xavfsizlik/yuk tekshiruvi natijalari va tekshiruv skriptlari: `tools/test_bot_fixes.py`, `tools/bench_after.py`, `web/tests/`.
