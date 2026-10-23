import sqlite3
import threading
import time
from contextlib import contextmanager

from .config import DB_PATH

SCHEMA = """
CREATE TABLE IF NOT EXISTS users (
    user_id       INTEGER PRIMARY KEY,
    username      TEXT,
    first_name    TEXT,
    last_name     TEXT,
    language_code TEXT,
    first_seen    TEXT NOT NULL DEFAULT (datetime('now')),
    last_seen     TEXT NOT NULL DEFAULT (datetime('now')),
    start_count   INTEGER NOT NULL DEFAULT 0
);
CREATE TABLE IF NOT EXISTS downloads (
    id        INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id   INTEGER NOT NULL REFERENCES users(user_id),
    district  TEXT NOT NULL,
    street    TEXT NOT NULL,
    file_name TEXT NOT NULL,
    sent_at   TEXT NOT NULL DEFAULT (datetime('now'))
);
CREATE TABLE IF NOT EXISTS file_cache (
    path    TEXT NOT NULL,
    size    INTEGER NOT NULL,
    mtime   INTEGER NOT NULL,
    file_id TEXT NOT NULL,
    PRIMARY KEY (path, size, mtime)
);
CREATE TABLE IF NOT EXISTS user_photos (
    id             INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id        INTEGER NOT NULL REFERENCES users(user_id),
    file_id        TEXT NOT NULL,
    file_unique_id TEXT NOT NULL,
    path           TEXT NOT NULL,
    created_at     TEXT NOT NULL DEFAULT (datetime('now'))
);
CREATE TABLE IF NOT EXISTS districts (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    name       TEXT NOT NULL,
    sort_order INTEGER NOT NULL DEFAULT 0,
    is_active  INTEGER NOT NULL DEFAULT 1,
    phone      TEXT                         -- contact shown when the district is opened (Chamber of Commerce representative)
);
CREATE TABLE IF NOT EXISTS streets (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    district_id INTEGER NOT NULL REFERENCES districts(id),
    name        TEXT NOT NULL,
    sort_order  INTEGER NOT NULL DEFAULT 0,
    is_active   INTEGER NOT NULL DEFAULT 1
);
CREATE INDEX IF NOT EXISTS idx_streets_district ON streets(district_id);
CREATE TABLE IF NOT EXISTS street_files (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    street_id  INTEGER NOT NULL REFERENCES streets(id),
    file_name  TEXT NOT NULL,
    path       TEXT NOT NULL,          -- relative to DATA_DIR, forward slashes
    size       INTEGER NOT NULL DEFAULT 0,
    sort_order INTEGER NOT NULL DEFAULT 0,
    created_at TEXT NOT NULL DEFAULT (datetime('now'))
);
CREATE INDEX IF NOT EXISTS idx_files_street ON street_files(street_id);
CREATE TABLE IF NOT EXISTS kv (key TEXT PRIMARY KEY, value REAL NOT NULL);
"""


def _conn() -> sqlite3.Connection:
    c = sqlite3.connect(DB_PATH, timeout=30, check_same_thread=False)  # wait for a concurrent writer, don't fail
    c.execute("PRAGMA synchronous=NORMAL")  # with WAL: no fsync on every commit, still crash-safe
    c.row_factory = sqlite3.Row  # rows work by name and by index
    return c


# One long-lived connection instead of connect-per-call: opening a SQLite file costs ~2 ms (measured), which was
# most of the time of every button press. The lock keeps statements of different threads from interleaving.
_lock = threading.RLock()
_shared: sqlite3.Connection | None = None


@contextmanager
def _db():
    global _shared
    with _lock:
        if _shared is None:
            _shared = _conn()
        try:
            yield _shared
        except Exception:
            _shared.rollback()
            raise


REG_COLUMNS = {
    "full_name": "TEXT", "phone": "TEXT", "latitude": "REAL", "longitude": "REAL", "registered_at": "TEXT",
    "consent_at": "TEXT",   # user pressed "I agree" to the personal-data notice
    "approved_at": "TEXT",  # set from the dashboard; only checked when REQUIRE_APPROVAL=1
}

# Indexes the dashboard's counts/filters/sorts depend on (measured: users list 9 s -> 0.6 s at 100k users).
# Created after the column migration, because some columns are added there.
INDEXES = """
CREATE INDEX IF NOT EXISTS idx_users_registered ON users(registered_at);
CREATE INDEX IF NOT EXISTS idx_users_first_seen ON users(first_seen);
CREATE INDEX IF NOT EXISTS idx_users_last_seen ON users(last_seen);
CREATE INDEX IF NOT EXISTS idx_downloads_user ON downloads(user_id);
CREATE INDEX IF NOT EXISTS idx_downloads_sent ON downloads(sent_at);
CREATE INDEX IF NOT EXISTS idx_downloads_street ON downloads(district, street);
CREATE INDEX IF NOT EXISTS idx_photos_user ON user_photos(user_id);
CREATE INDEX IF NOT EXISTS idx_files_size ON street_files(size);
CREATE INDEX IF NOT EXISTS idx_files_name ON street_files(file_name);
CREATE UNIQUE INDEX IF NOT EXISTS uq_files_path ON street_files(path);
"""


def init() -> None:
    with _db() as c:
        c.execute("PRAGMA journal_mode=WAL")  # readers don't block the writer
        c.executescript(SCHEMA)
        for table, columns in (("users", REG_COLUMNS), ("districts", {"phone": "TEXT"})):
            have = {r[1] for r in c.execute(f"PRAGMA table_info({table})")}
            for col, typ in columns.items():  # migrate a database created before a column existed
                if col not in have:
                    c.execute(f"ALTER TABLE {table} ADD COLUMN {col} {typ}")
        c.executescript(INDEXES)
        c.commit()
    _rekey_file_cache()


def _rekey_file_cache() -> None:
    """file_cache used to be keyed by absolute path (breaks when the data folder moves). Keep keys relative to DATA_DIR."""
    from pathlib import Path

    from .config import DATA_DIR

    with _db() as c:
        for (path,) in c.execute("SELECT DISTINCT path FROM file_cache").fetchall():
            if "\\" not in path and ":" not in path:
                continue  # already relative
            try:
                rel = Path(path).relative_to(DATA_DIR).as_posix()
            except ValueError:
                c.execute("DELETE FROM file_cache WHERE path=?", (path,))  # points outside data/: useless
                continue
            c.execute("DELETE FROM file_cache WHERE path=?", (rel,))
            c.execute("UPDATE file_cache SET path=? WHERE path=?", (rel, path))
        c.commit()


def get_user(user_id: int) -> dict | None:
    with _db() as c:
        row = c.execute("SELECT * FROM users WHERE user_id=?", (user_id,)).fetchone()
    return dict(row) if row else None


def update_user(user_id: int, **fields) -> None:
    cols = ", ".join(f"{k}=?" for k in fields)
    with _db() as c:
        c.execute(f"UPDATE users SET {cols} WHERE user_id=?", (*fields.values(), user_id))
        c.commit()


def photo_count(user_id: int) -> int:
    with _db() as c:
        return c.execute("SELECT COUNT(*) FROM user_photos WHERE user_id=?", (user_id,)).fetchone()[0]


def add_photo(user_id: int, file_id: str, file_unique_id: str, path: str) -> None:
    with _db() as c:
        c.execute(
            "INSERT INTO user_photos (user_id, file_id, file_unique_id, path) VALUES (?, ?, ?, ?)",
            (user_id, file_id, file_unique_id, path),
        )
        c.commit()


_ACCESS_TTL = 30.0  # seconds a positive answer is remembered (dashboard approvals/revocations apply within this)
_access_ok: dict[tuple[str, int], float] = {}


def _remembered(kind: str, user_id: int, compute) -> bool:
    now = time.monotonic()
    if _access_ok.get((kind, user_id), 0) > now:
        return True
    if compute():
        _access_ok[(kind, user_id)] = now + _ACCESS_TTL
        return True
    _access_ok.pop((kind, user_id), None)
    return False


def is_registered(user_id: int) -> bool:
    def check():
        u = get_user(user_id)
        return bool(u and u["registered_at"])

    return _remembered("reg", user_id, check)


def is_approved(user_id: int) -> bool:
    def check():
        u = get_user(user_id)
        return bool(u and u["approved_at"])

    return _remembered("appr", user_id, check)


def forget_access(user_id: int) -> None:
    _access_ok.pop(("reg", user_id), None)
    _access_ok.pop(("appr", user_id), None)


_TOUCH_EVERY = 60.0  # seconds: last_seen is written at most this often per user (was: on every button press)
_last_touch: dict[int, float] = {}


def touch_user(u, started: bool = False) -> None:
    now = time.monotonic()
    if not started and now - _last_touch.get(u.id, -_TOUCH_EVERY) < _TOUCH_EVERY:
        return
    _last_touch[u.id] = now
    with _db() as c:
        c.execute(
            """INSERT INTO users (user_id, username, first_name, last_name, language_code, start_count)
               VALUES (?, ?, ?, ?, ?, ?)
               ON CONFLICT(user_id) DO UPDATE SET
                 username=excluded.username, first_name=excluded.first_name,
                 last_name=excluded.last_name, language_code=excluded.language_code,
                 last_seen=datetime('now'), start_count=start_count + ?""",
            (u.id, u.username, u.first_name, u.last_name, u.language_code, int(started), int(started)),
        )
        c.commit()


def log_download(user_id: int, district: str, street: str, file_name: str) -> None:
    with _db() as c:
        c.execute(
            "INSERT INTO downloads (user_id, district, street, file_name) VALUES (?, ?, ?, ?)",
            (user_id, district, street, file_name),
        )
        c.commit()


def get_cached(path: str, size: int, mtime: int) -> str | None:
    with _db() as c:
        row = c.execute(
            "SELECT file_id FROM file_cache WHERE path=? AND size=? AND mtime=?", (path, size, mtime)
        ).fetchone()
    return row[0] if row else None


def set_cached(path: str, size: int, mtime: int, file_id: str) -> None:
    with _db() as c:
        c.execute("INSERT OR REPLACE INTO file_cache VALUES (?, ?, ?, ?)", (path, size, mtime, file_id))
        c.commit()


def get_upload_speed() -> float:
    """Smoothed upload speed to Telegram in bytes/sec (default 1 MB/s until measured)."""
    with _db() as c:
        row = c.execute("SELECT value FROM kv WHERE key='upload_bps'").fetchone()
    return row[0] if row else 1024 * 1024.0


def record_upload(size: int, seconds: float) -> None:
    if size < 1024 * 1024 or seconds <= 0:
        return
    bps = 0.7 * get_upload_speed() + 0.3 * (size / seconds)
    with _db() as c:
        c.execute("INSERT OR REPLACE INTO kv VALUES ('upload_bps', ?)", (bps,))
        c.commit()


def fetch_all(sql: str, params: tuple = ()) -> list[sqlite3.Row]:
    with _db() as c:
        return c.execute(sql, params).fetchall()


def execute(sql: str, params: tuple = ()) -> int:
    with _db() as c:
        cur = c.execute(sql, params)
        c.commit()
        return cur.lastrowid
