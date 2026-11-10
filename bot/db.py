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
-- Citizens' requests (objections, needs, suggestions). Answered from the dashboard, the bot delivers the answer.
CREATE TABLE IF NOT EXISTS feedback (
    id             INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id        INTEGER NOT NULL REFERENCES users(user_id),
    level          TEXT NOT NULL,             -- problem level: low / medium / high
    text           TEXT NOT NULL,
    created_at     TEXT NOT NULL DEFAULT (datetime('now')),
    answer         TEXT,                      -- written in the dashboard
    answered_at    TEXT,
    answered_by    TEXT,                      -- admin e-mail
    answer_sent_at TEXT,                      -- set by the bot once the answer reached the user (or failed for good)
    answer_error   TEXT                       -- why it could not be delivered (user blocked the bot, ...)
);
CREATE INDEX IF NOT EXISTS idx_feedback_created ON feedback(created_at);
CREATE INDEX IF NOT EXISTS idx_feedback_user ON feedback(user_id);

-- District staff: check on the spot that businesses take down old signs and put up design-code ones.
CREATE TABLE IF NOT EXISTS staff (
    id               INTEGER PRIMARY KEY AUTOINCREMENT,
    full_name        TEXT NOT NULL,
    phone            TEXT NOT NULL UNIQUE,      -- +998XXXXXXXXX; the person confirms it with the contact button
    district_id      INTEGER NOT NULL REFERENCES districts(id),
    streets_note     TEXT,                      -- the streets named when the person was assigned (free text)
    priority_streets TEXT,                      -- JSON array of street ids listed first in the Mini App
    user_id          INTEGER UNIQUE,            -- Telegram id, set once the phone was confirmed
    is_active        INTEGER NOT NULL DEFAULT 1,
    created_at       TEXT NOT NULL DEFAULT (datetime('now'))
);
-- Street PDFs already split into object pages (redone when the file changes).
CREATE TABLE IF NOT EXISTS object_sources (
    file_id  INTEGER PRIMARY KEY,               -- street_files.id
    size     INTEGER NOT NULL,
    mtime    INTEGER NOT NULL,
    pages    INTEGER NOT NULL,
    kind     TEXT,                              -- text (concept PDF) / images (picture-only concept) / extra (renders, not objects)
    objects  INTEGER NOT NULL DEFAULT 0,
    error    TEXT,
    done_at  TEXT NOT NULL DEFAULT (datetime('now'))
);
-- One page of a street's concept PDF (after the cover) = one object: a business whose facade must follow the design code.
CREATE TABLE IF NOT EXISTS objects (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    street_id   INTEGER NOT NULL REFERENCES streets(id),
    file_id     INTEGER NOT NULL,               -- street_files.id
    page        INTEGER NOT NULL,               -- page in that PDF (1 is the cover, objects start at 2)
    image       TEXT,                           -- the page as a picture, relative to DATA_DIR
    shop_name   TEXT,
    owner_name  TEXT,
    owner_phone TEXT,
    stir        TEXT,
    status      TEXT NOT NULL DEFAULT 'pending', -- pending / removed (old sign taken down) / installed (design code done)
    latitude    REAL,                           -- from the first approved inspection; later ones must be near it
    longitude   REAL,
    is_active   INTEGER NOT NULL DEFAULT 1,     -- the admin's switch: 0 = "not an object" (a scheme, a summary page)
    gone        INTEGER NOT NULL DEFAULT 0,     -- 1: the page is no longer in any current PDF (kept with its history)
    page_hash   TEXT,                           -- fingerprint of the page: no duplicates, and a re-uploaded PDF keeps its objects
    updated_at  TEXT NOT NULL DEFAULT (datetime('now')),
    UNIQUE (file_id, page)
);
CREATE INDEX IF NOT EXISTS idx_objects_street ON objects(street_id);
CREATE INDEX IF NOT EXISTS idx_objects_status ON objects(status);
-- Evidence sent from the Mini App: location -> live photo -> live video -> comment. Changes the object only once approved.
CREATE TABLE IF NOT EXISTS inspections (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    object_id    INTEGER NOT NULL REFERENCES objects(id),
    staff_id     INTEGER NOT NULL REFERENCES staff(id),
    kind         TEXT NOT NULL,                 -- removed / installed
    step         TEXT NOT NULL DEFAULT 'photo', -- photo / location / point / video / comment / submitted
    latitude     REAL,                          -- where the staff member stood (the phone's location)
    longitude    REAL,
    accuracy     REAL,
    obj_latitude REAL,                          -- the object's place, picked on the map by the staff member
    obj_longitude REAL,
    distance_m   REAL,                          -- staff member <-> picked object place
    photo        TEXT,                          -- relative to UPLOADS_DIR
    photo_hash   TEXT,
    video        TEXT,
    video_hash   TEXT,
    comment      TEXT,
    review       TEXT,                          -- set on submit: pending / approved / rejected
    review_note  TEXT,
    reviewed_by  TEXT,
    reviewed_at  TEXT,
    created_at   TEXT NOT NULL DEFAULT (datetime('now')),
    submitted_at TEXT
);
CREATE INDEX IF NOT EXISTS idx_inspections_object ON inspections(object_id);
CREATE INDEX IF NOT EXISTS idx_inspections_review ON inspections(review);
CREATE INDEX IF NOT EXISTS idx_inspections_staff ON inspections(staff_id);
-- Messages the dashboard wants delivered (review results to staff); the bot sends them.
CREATE TABLE IF NOT EXISTS outbox (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id    INTEGER NOT NULL,
    text       TEXT NOT NULL,
    created_at TEXT NOT NULL DEFAULT (datetime('now')),
    sent_at    TEXT,
    error      TEXT
);
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
    "lang": "TEXT",         # bot language picked at /start: uz (Latin) / ru
    "concept_at": "TEXT",   # user chose "Концепция": only then are location and object photos asked
    "approved_at": "TEXT",  # set from the dashboard; only checked when REQUIRE_APPROVAL=1
}

# Indexes the dashboard's counts/filters/sorts depend on (measured: users list 9 s -> 0.6 s at 100k users).
# Created after the column migration, because some columns are added there.
INDEXES = """
CREATE INDEX IF NOT EXISTS idx_objects_hash ON objects(page_hash);
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
        for table, columns in (("users", REG_COLUMNS), ("districts", {"phone": "TEXT"}),
                               ("objects", {"gone": "INTEGER NOT NULL DEFAULT 0", "page_hash": "TEXT"}),
                               ("object_sources", {"kind": "TEXT", "objects": "INTEGER NOT NULL DEFAULT 0"}),
                               ("inspections", {"obj_latitude": "REAL", "obj_longitude": "REAL"})):
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
            file_part = path.split("|", 1)[0]  # "<rel>|<name>": the name may contain anything
            if "\\" not in file_part and ":" not in file_part:
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


def add_feedback(user_id: int, level: str, text: str) -> int:
    with _db() as c:
        cur = c.execute("INSERT INTO feedback (user_id, level, text) VALUES (?, ?, ?)", (user_id, level, text))
        c.commit()
        return cur.lastrowid


def feedback_count_since(user_id: int, hours: int) -> int:
    with _db() as c:
        return c.execute("SELECT COUNT(*) FROM feedback WHERE user_id=? AND created_at >= datetime('now', ?)",
                         (user_id, f"-{hours} hours")).fetchone()[0]


def unsent_answers(limit: int = 20) -> list[sqlite3.Row]:
    with _db() as c:
        return c.execute("SELECT id, user_id, text, answer FROM feedback WHERE answer IS NOT NULL AND answer_sent_at IS NULL "
                         "ORDER BY answered_at LIMIT ?", (limit,)).fetchall()


def mark_answer_sent(feedback_id: int, error: str | None = None) -> None:
    with _db() as c:
        c.execute("UPDATE feedback SET answer_sent_at=datetime('now'), answer_error=? WHERE id=?", (error, feedback_id))
        c.commit()


def staff_by_user(user_id: int) -> dict | None:
    """The active staff member linked to this Telegram account, with the district name."""
    with _db() as c:
        row = c.execute("SELECT s.*, d.name AS district FROM staff s JOIN districts d ON d.id = s.district_id "
                        "WHERE s.user_id=? AND s.is_active=1", (user_id,)).fetchone()
    return dict(row) if row else None


def staff_by_phone(phone: str) -> dict | None:
    with _db() as c:
        row = c.execute("SELECT * FROM staff WHERE phone=? AND is_active=1", (phone,)).fetchone()
    return dict(row) if row else None


def link_staff(staff_id: int, user_id: int) -> None:
    """One Telegram account per staff member: a new confirmation moves the link (a changed phone / account)."""
    with _db() as c:
        c.execute("UPDATE staff SET user_id=NULL WHERE user_id=? AND id<>?", (user_id, staff_id))
        c.execute("UPDATE staff SET user_id=? WHERE id=?", (user_id, staff_id))
        c.commit()


def staff_counts(district_id: int) -> dict:
    with _db() as c:
        r = c.execute(
            "SELECT COUNT(*) AS total, SUM(o.status='removed') AS removed, SUM(o.status='installed') AS installed "
            "FROM objects o JOIN streets s ON s.id = o.street_id WHERE s.district_id=? AND o.is_active=1 AND o.gone=0", (district_id,)
        ).fetchone()
        pending = c.execute(
            "SELECT COUNT(*) FROM inspections i JOIN objects o ON o.id=i.object_id JOIN streets s ON s.id=o.street_id "
            "WHERE s.district_id=? AND i.review='pending'", (district_id,)
        ).fetchone()[0]
    return {"total": r["total"] or 0, "removed": r["removed"] or 0, "installed": r["installed"] or 0, "review": pending}


def unsent_outbox(limit: int = 20) -> list[sqlite3.Row]:
    with _db() as c:
        return c.execute("SELECT id, user_id, text FROM outbox WHERE sent_at IS NULL ORDER BY id LIMIT ?", (limit,)).fetchall()


def mark_outbox_sent(msg_id: int, error: str | None = None) -> None:
    with _db() as c:
        c.execute("UPDATE outbox SET sent_at=datetime('now'), error=? WHERE id=?", (error, msg_id))
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
