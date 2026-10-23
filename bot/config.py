import os
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent


def _load_env(path: Path) -> None:
    if not path.exists():
        return
    for line in path.read_text(encoding="utf-8").splitlines():
        line = line.strip()
        if line and not line.startswith("#") and "=" in line:
            k, v = line.split("=", 1)
            os.environ.setdefault(k.strip(), v.strip().strip('"').strip("'"))


_load_env(ROOT / ".env")

BOT_TOKEN = os.environ.get("BOT_TOKEN", "")
# e.g. http://localhost:8081 when a local Telegram Bot API server is running (lifts the 50 MB limit to 2 GB)
LOCAL_API_URL = os.environ.get("LOCAL_API_URL", "").strip()
DATA_DIR = Path(os.environ.get("DATA_DIR", ROOT / "data"))
DB_PATH = Path(os.environ.get("DB_PATH", ROOT / "bot.db"))
UPLOADS_DIR = Path(os.environ.get("UPLOADS_DIR", ROOT / "uploads"))
MAX_FILE_MB = 2000 if LOCAL_API_URL else 50
# Files above the limit are sent as a link served by the built-in web server (web.py).
# PUBLIC_BASE_URL must be reachable by users: ngrok / domain / VPS address (localhost works only on this PC).
PUBLIC_BASE_URL = os.environ.get("PUBLIC_BASE_URL", "http://localhost:8787").rstrip("/")
WEB_PORT = int(os.environ.get("WEB_PORT", "8787"))
# Separate key for the signed download links (never reuse the bot token: rotating the token must not break links,
# and a leaked token must not let anyone forge links). Generate: python -c "import secrets;print(secrets.token_hex(32))"
LINK_SECRET = os.environ.get("LINK_SECRET", "")
LINK_TTL_HOURS = int(os.environ.get("LINK_TTL_HOURS", "24"))  # a shared link stops working after this
# Bind the file server to localhost; a reverse proxy (nginx/Caddy) or ngrok exposes it. Use 0.0.0.0 only in a container.
WEB_HOST = os.environ.get("WEB_HOST", "127.0.0.1")
# 1 = a registered user also needs approval from the dashboard before getting the files
REQUIRE_APPROVAL = os.environ.get("REQUIRE_APPROVAL", "0") == "1"
# Seconds between two menu/file presses of one user (anti-flood)
USER_COOLDOWN = float(os.environ.get("USER_COOLDOWN", "0.7"))
# How many files may be uploaded to Telegram at the same moment (the rest wait in line).
MAX_PARALLEL_UPLOADS = int(os.environ.get("MAX_PARALLEL_UPLOADS", "3"))

# --- flood protection (bot/guard.py) ---
# per user: a short burst is fine, then at most GUARD_RATE events/second; GUARD_MUTE_STRIKES refused events within
# 30 s mute the user for GUARD_MUTE_SECONDS. MAX_INFLIGHT = handlers running at the same time before work is shed.
GUARD_BURST = int(os.environ.get("GUARD_BURST", "6"))
GUARD_RATE = float(os.environ.get("GUARD_RATE", "1.5"))
GUARD_MUTE_STRIKES = int(os.environ.get("GUARD_MUTE_STRIKES", "12"))
GUARD_MUTE_SECONDS = int(os.environ.get("GUARD_MUTE_SECONDS", "120"))
MAX_INFLIGHT = int(os.environ.get("MAX_INFLIGHT", "150"))
# our own calls to Telegram per second (its limit is about 30 for one bot)
TG_OUT_PER_SECOND = float(os.environ.get("TG_OUT_PER_SECOND", "25"))
