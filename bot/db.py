"""The bot's database: PostgreSQL in production (DATABASE_URL), a SQLite file for local work and the tests.

Queries are written once, SQLite style ("?" placeholders, datetime('now')); for PostgreSQL they are translated on the way
(see _pg_sql). Booleans are compared with TRUE, upserts use ON CONFLICT: both work on either database.
PostgreSQL: one long-lived connection per worker thread (the bot calls the database from a small thread pool, see adb.py,
so a slow query never stops its event loop). SQLite: one shared connection guarded by a lock.
"""
import re
import sqlite3
import threading
import time
from datetime import datetime, timezone
from contextlib import contextmanager, nullcontext

from .config import DATABASE_URL, DB_PATH

PG = DATABASE_URL.startswith(("postgres://", "postgresql://"))
if DATABASE_URL and not PG:  # never fall back to the old SQLite file silently: the dashboard reads PostgreSQL
    raise SystemExit("DATABASE_URL must start with postgresql:// (got another form); unset it to use SQLite")

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
    is_active  BOOLEAN NOT NULL DEFAULT TRUE,
    phone      TEXT                         -- contact shown when the district is opened (Chamber of Commerce representative)
);
CREATE TABLE IF NOT EXISTS streets (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    district_id INTEGER NOT NULL REFERENCES districts(id),
    name        TEXT NOT NULL,
    sort_order  INTEGER NOT NULL DEFAULT 0,
    is_active   BOOLEAN NOT NULL DEFAULT TRUE
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
    is_active        BOOLEAN NOT NULL DEFAULT TRUE,
    created_at       TEXT NOT NULL DEFAULT (datetime('now'))
);
-- A business on a street (an "object" of the design code), added by district staff in the Mini App. How many each street
-- should have comes from the official list (streets.planned_objects); the street's concept PDF stays whole.
CREATE TABLE IF NOT EXISTS objects (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    street_id   INTEGER NOT NULL REFERENCES streets(id),
    shop_name   TEXT NOT NULL,
    owner_name  TEXT,
    owner_phone TEXT,
    stir        TEXT,
    status      TEXT NOT NULL DEFAULT 'pending', -- pending / removed (old sign taken down) / installed (design code done)
    latitude    REAL,                           -- from the latest approved inspection; later ones must be near it
    longitude   REAL,
    is_active   BOOLEAN NOT NULL DEFAULT TRUE,  -- the admin's switch: 0 = added by mistake / a duplicate
    created_by  INTEGER REFERENCES staff(id),
    created_at  TEXT NOT NULL DEFAULT (datetime('now')),
    updated_at  TEXT NOT NULL DEFAULT (datetime('now'))
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
-- a staff member's working day in the Mini App (the attendance report): first and last request of the day (Tashkent date)
CREATE TABLE IF NOT EXISTS staff_days (
    staff_id INTEGER NOT NULL,
    day      TEXT NOT NULL,
    first_at TEXT NOT NULL,
    last_at  TEXT NOT NULL,
    hits     INTEGER NOT NULL DEFAULT 1,
    PRIMARY KEY (staff_id, day)
);
-- who gets the design-code report pictures the dashboard sends (people who started the bot)
CREATE TABLE IF NOT EXISTS report_recipients (
    user_id    INTEGER PRIMARY KEY,
    label      TEXT,
    created_at TEXT NOT NULL DEFAULT (datetime('now'))
);
CREATE TABLE IF NOT EXISTS outbox (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id    INTEGER NOT NULL,
    text       TEXT NOT NULL,
    created_at TEXT NOT NULL DEFAULT (datetime('now')),
    sent_at    TEXT,
    error      TEXT
);
"""


class Row:
    """A result row readable by column name and by index, and convertible with dict() - like sqlite3.Row."""

    __slots__ = ("_names", "_values")

    def __init__(self, names, values):
        self._names, self._values = names, tuple(values)

    def __getitem__(self, k):
        return self._values[k] if isinstance(k, int) else self._values[self._names.index(k)]

    def keys(self):
        return list(self._names)

    def __iter__(self):
        return iter(self._values)

    def __len__(self):
        return len(self._values)


# tables whose rows have an "id": an INSERT into them returns it (lastrowid) on PostgreSQL too
_ID_TABLES = {"downloads", "user_photos", "districts", "streets", "street_files", "feedback", "staff", "objects",
              "inspections", "outbox"}


def _pg_sql(sql: str, has_params: bool = True) -> tuple[str, bool]:
    """SQLite-style query -> PostgreSQL: placeholders, the current UTC time, RETURNING id for inserts.
    A literal % is doubled only when parameters are passed (psycopg parses the query only then)."""
    if has_params:
        sql = sql.replace("%", "%%")
    sql = sql.replace("datetime('now', ?)", "((now() AT TIME ZONE 'utc') + CAST(? AS interval))")
    sql = sql.replace("datetime('now')", "(now() AT TIME ZONE 'utc')").replace("?", "%s")
    m = re.match(r"\s*INSERT\s+INTO\s+(\w+)", sql, re.I)
    want_id = bool(m and m.group(1).lower() in _ID_TABLES and "RETURNING" not in sql.upper())
    return (sql + " RETURNING id" if want_id else sql), want_id


class _PgCursor:
    def __init__(self, cur, want_id: bool):
        self._cur = cur
        self.lastrowid = cur.fetchone()[0] if want_id else None

    def fetchone(self):
        return self._cur.fetchone() if self._cur.description else None

    def fetchall(self):
        return self._cur.fetchall() if self._cur.description else []


class _PgConn:
    """psycopg connection with the sqlite3 calls this module uses. Autocommit: never an open idle transaction."""

    def __init__(self):
        import psycopg

        def rows(cur):
            names = [d.name for d in cur.description] if cur.description else []
            return lambda values: Row(names, values)

        self._connect = lambda: psycopg.connect(
            DATABASE_URL, autocommit=True, row_factory=rows, connect_timeout=10,
            # a stuck lock or a dead socket must fail fast: every call runs on the bot's event loop
            options="-c statement_timeout=15000 -c lock_timeout=5000 -c idle_in_transaction_session_timeout=60000",
            keepalives=1, keepalives_idle=30, keepalives_interval=10, keepalives_count=3)
        self._broken = (psycopg.OperationalError, psycopg.InterfaceError)
        self.c = self._connect()

    def execute(self, sql, params=()):
        q, want_id = _pg_sql(sql, bool(params))
        try:
            cur = self.c.execute(q, params or None)
        except self._broken:
            if not self.c.closed and not self.c.broken:
                raise  # a real error (timeout, constraint): not a lost connection
            self.c = self._connect()  # PostgreSQL restarted / network dropped: reconnect once and repeat
            cur = self.c.execute(q, params or None)
        return _PgCursor(cur, want_id)

    def executescript(self, script):
        self.c.execute(script)

    def commit(self):
        pass

    def transaction(self):
        return self.c.transaction()  # autocommit is off inside: several statements land together or not at all

    def rollback(self):
        pass

    @property
    def closed(self) -> bool:
        return self.c.closed


def _conn():
    if PG:
        return _PgConn()
    c = sqlite3.connect(DB_PATH, timeout=30, check_same_thread=False)  # wait for a concurrent writer, don't fail
    c.execute("PRAGMA synchronous=NORMAL")  # with WAL: no fsync on every commit, still crash-safe
    c.row_factory = sqlite3.Row  # rows work by name and by index
    return c


# One long-lived connection instead of connect-per-call: opening a SQLite file costs ~2 ms (measured), which was
# most of the time of every button press. The lock keeps statements of different threads from interleaving.
_lock = threading.RLock()
_shared = None
_local = threading.local()  # PostgreSQL: each worker thread has its own connection, so queries run side by side


@contextmanager
def _db():
    global _shared
    if PG:
        c = getattr(_local, "c", None)
        if c is None or c.closed:  # first use in this thread, or PostgreSQL restarted
            c = _local.c = _PgConn()
        try:
            yield c
        except Exception as e:
            if type(e).__module__.startswith("psycopg") and "Operational" in type(e).__name__:
                _local.c = None  # broken connection: the next call in this thread reconnects
            raise
        return
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
CREATE INDEX IF NOT EXISTS idx_inspections_photo_hash ON inspections(photo_hash);
CREATE INDEX IF NOT EXISTS idx_inspections_video_hash ON inspections(video_hash);
CREATE INDEX IF NOT EXISTS idx_outbox_unsent ON outbox(id) WHERE sent_at IS NULL;
CREATE INDEX IF NOT EXISTS idx_feedback_unanswered ON feedback(id) WHERE answer IS NULL;
CREATE INDEX IF NOT EXISTS idx_feedback_undelivered ON feedback(answered_at) WHERE answer IS NOT NULL AND answer_sent_at IS NULL;
"""


def _drop_page_objects(c) -> None:
    """Objects once were pages split out of the street PDFs. That was dropped: the PDFs stay whole and staff add the
    businesses themselves. The old table (and its split bookkeeping) goes, unless staff evidence already points at it."""
    cols = {r[1] for r in c.execute("PRAGMA table_info(objects)")}
    if "page" not in cols:
        return
    has_evidence = c.execute("SELECT name FROM sqlite_master WHERE name='inspections'").fetchone() and \
        c.execute("SELECT 1 FROM inspections LIMIT 1").fetchone()
    if has_evidence:
        raise RuntimeError("objects still has PDF pages and inspections point at them: migrate by hand")
    c.execute("DROP TABLE objects")
    c.execute("DROP TABLE IF EXISTS object_sources")
    c.commit()


def _added_columns():
    """Columns added after a table was first created: (table, {column: type}). Existing databases get them at start."""
    return (("users", {**REG_COLUMNS,
                       # a request being written: the level picked, and until when (unix time) the text is awaited.
                       # In the database, not in memory: a restart of the bot no longer loses it
                       "fb_wait": "TEXT", "fb_wait_until": "REAL"}),
            ("districts", {"phone": "TEXT"}),
            # the street's start and end ("dan" / "gacha" of the official list): staff must stand near this line to work on it
            ("streets", {"planned_objects": "INTEGER NOT NULL DEFAULT 0", "start_lat": "REAL", "start_lon": "REAL",
                         "end_lat": "REAL", "end_lon": "REAL",
                         "path": "TEXT"}),  # the real shape, [[lat, lon], ...] (tools/import_street_lines.py); else start -> end
            ("inspections", {"obj_latitude": "REAL", "obj_longitude": "REAL"}),
            # a personal invite link from the dashboard (t.me/<bot>?start=s_<token>): links a staff member whatever number they joined with
            ("staff", {"invite_token": "TEXT", "invite_expires": "TEXT"}),
            ("outbox", {"photo": "TEXT"}),  # a picture (path under UPLOADS_DIR) sent with the text as its caption
            # which scheduled reports a recipient gets (Sozlamalar): the svod pictures and the attendance at 18:00, hourly status
            ("report_recipients", {"daily_report": "BOOLEAN NOT NULL DEFAULT TRUE", "attendance_report": "BOOLEAN NOT NULL DEFAULT TRUE",
                                   "hourly_report": "BOOLEAN NOT NULL DEFAULT FALSE"}),
            # where the staff member stood when adding the business, and the address found there (Mini App "find my place")
            ("objects", {"address": "TEXT", "found_lat": "REAL", "found_lon": "REAL"}))  # a picture (path under UPLOADS_DIR) sent with the text as its caption


def init() -> None:
    if PG:
        _init_pg()
        return
    with _db() as c:
        c.execute("PRAGMA journal_mode=WAL")  # readers don't block the writer
        _drop_page_objects(c)
        c.executescript(SCHEMA)
        for table, columns in _added_columns():
            have = {r[1] for r in c.execute(f"PRAGMA table_info({table})")}
            for col, typ in columns.items():  # migrate a database created before a column existed
                if col not in have:
                    c.execute(f"ALTER TABLE {table} ADD COLUMN {col} {typ}")
        c.executescript(INDEXES)
        c.commit()
    _rekey_file_cache()


def schema_columns() -> dict[str, set[str]]:
    """{table: {column, ...}} the bot needs: the tables of SCHEMA plus the columns added later."""
    out: dict[str, set[str]] = {}
    for table, body in re.findall(r"CREATE TABLE IF NOT EXISTS (\w+) \((.*?)\);", SCHEMA, re.S):
        body = re.sub(r"--[^\n]*", "", body)  # comments may hold anything
        out[table] = {m.group(1) for part in body.split(",")
                      if (m := re.match(r"\s*(\w+)\s+[A-Z]", part)) and m.group(1) not in ("PRIMARY", "UNIQUE")}
    for table, columns in _added_columns():
        out.setdefault(table, set()).update(columns)
    return out


def _init_pg() -> None:
    """On PostgreSQL the schema belongs to the dashboard's migrations (web/database/migrations/bot, with the foreign keys):
    the bot only checks that every table and column it uses is there, and refuses to start on an old schema."""
    with _db() as c:
        have: dict[str, set[str]] = {}
        for table, column in c.execute("SELECT table_name, column_name FROM information_schema.columns "
                                       "WHERE table_schema = current_schema()").fetchall():
            have.setdefault(table, set()).add(column)
    missing = [f"{t}.{col}" for t, cols in schema_columns().items() for col in sorted(cols) if col not in have.get(t, ())]
    if missing:
        raise SystemExit("The database schema is behind the bot (missing: " + ", ".join(missing[:12]) + "). Run in web/:  "
                         "php artisan migrate --path=database/migrations/bot --force")


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
                 last_seen=datetime('now'), start_count=users.start_count + ?""",
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


def set_fb_wait(user_id: int, level: str | None, seconds: float = 0) -> None:
    """The user picked a request level and the bot now waits for the text (None: stop waiting)."""
    with _db() as c:
        c.execute("UPDATE users SET fb_wait=?, fb_wait_until=? WHERE user_id=?",
                  (level, time.time() + seconds if level else None, user_id))
        c.commit()


def fb_wait(user_id: int) -> str | None:
    """The level of the request whose text is awaited, None when nothing is (or the wait expired)."""
    with _db() as c:
        row = c.execute("SELECT fb_wait FROM users WHERE user_id=? AND fb_wait IS NOT NULL AND fb_wait_until > ?",
                        (user_id, time.time())).fetchone()
    return row[0] if row else None


def save_awaited_feedback(user_id: int, text: str) -> int | None:
    """Saves the text as the awaited request and stops waiting, in one transaction: two copies of the same message
    (a long text split by Telegram, a double send) make one request. None when no request was awaited."""
    with _db() as c:
        with c.transaction() if PG else nullcontext():
            level = c.execute("SELECT fb_wait FROM users WHERE user_id=? AND fb_wait IS NOT NULL AND fb_wait_until > ?",
                              (user_id, time.time())).fetchone()
            # claimed only if still waiting: a concurrent copy waits on the row lock, then finds fb_wait NULL and gets nothing
            # (RETURNING gives the value after the update, hence the level read above)
            if not level or not c.execute("UPDATE users SET fb_wait=NULL, fb_wait_until=NULL WHERE user_id=? AND fb_wait IS NOT NULL "
                                          "RETURNING user_id", (user_id,)).fetchone():
                return None
            cur = c.execute("INSERT INTO feedback (user_id, level, text) VALUES (?, ?, ?)", (user_id, level[0], text))
        c.commit()
        return cur.lastrowid


def feedback_count_since(user_id: int, hours: int) -> int:
    with _db() as c:
        return c.execute("SELECT COUNT(*) FROM feedback WHERE user_id=? AND created_at >= datetime('now', ?)",
                         (user_id, f"-{hours} hours")).fetchone()[0]


def unsent_answers(limit: int = 20) -> list:
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
                        "WHERE s.user_id=? AND s.is_active = TRUE", (user_id,)).fetchone()
    return dict(row) if row else None


def staff_by_phone(phone: str) -> dict | None:
    with _db() as c:
        row = c.execute("SELECT * FROM staff WHERE phone=? AND is_active = TRUE", (phone,)).fetchone()
    return dict(row) if row else None


def staff_by_invite(token: str) -> dict | None:
    """The active staff member an unexpired invite link belongs to."""
    with _db() as c:
        row = c.execute("SELECT s.*, d.name AS district FROM staff s JOIN districts d ON d.id = s.district_id "
                        "WHERE s.invite_token = ? AND s.is_active = TRUE AND s.invite_expires > ?",
                        (token, datetime.now(timezone.utc).strftime("%Y-%m-%d %H:%M:%S"))).fetchone()
    return dict(row) if row else None


def use_invite(staff_id: int) -> None:
    """An invite works once."""
    with _db() as c:
        c.execute("UPDATE staff SET invite_token = NULL, invite_expires = NULL WHERE id = ?", (staff_id,))
        c.commit()


def link_staff(staff_id: int, user_id: int) -> None:
    """One Telegram account per staff member: a new confirmation moves the link (a changed phone / account)."""
    with _db() as c:
        with c.transaction() if PG else nullcontext():  # SQLite: one implicit transaction until commit()
            c.execute("UPDATE staff SET user_id=NULL WHERE user_id=? AND id<>?", (user_id, staff_id))
            c.execute("UPDATE staff SET user_id=? WHERE id=?", (user_id, staff_id))
        c.commit()


def staff_counts(district_id: int) -> dict:
    with _db() as c:
        r = c.execute(
            "SELECT SUM(CASE WHEN o.status='removed' THEN 1 ELSE 0 END) AS removed, "
            "SUM(CASE WHEN o.status='installed' THEN 1 ELSE 0 END) AS installed "
            "FROM objects o JOIN streets s ON s.id = o.street_id WHERE s.district_id=? AND o.is_active = TRUE", (district_id,)
        ).fetchone()
        planned = c.execute("SELECT COALESCE(SUM(planned_objects), 0) FROM streets WHERE district_id=?", (district_id,)).fetchone()[0]
        pending = c.execute(
            "SELECT COUNT(*) FROM inspections i JOIN objects o ON o.id=i.object_id JOIN streets s ON s.id=o.street_id "
            "WHERE s.district_id=? AND i.review='pending'", (district_id,)
        ).fetchone()[0]
    return {"total": planned, "removed": r["removed"] or 0, "installed": r["installed"] or 0, "review": pending}


def unsent_outbox(limit: int = 20) -> list:
    with _db() as c:
        return c.execute("SELECT id, user_id, text, photo FROM outbox WHERE sent_at IS NULL ORDER BY id LIMIT ?", (limit,)).fetchall()


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
        c.execute("INSERT INTO file_cache (path, size, mtime, file_id) VALUES (?, ?, ?, ?) "
                  "ON CONFLICT (path, size, mtime) DO UPDATE SET file_id = excluded.file_id", (path, size, mtime, file_id))
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
        c.execute("INSERT INTO kv (key, value) VALUES ('upload_bps', ?) ON CONFLICT (key) DO UPDATE SET value = excluded.value", (bps,))
        c.commit()


def fetch_all(sql: str, params: tuple = ()) -> list:
    with _db() as c:
        return c.execute(sql, params).fetchall()


def execute(sql: str, params: tuple = ()) -> int:
    with _db() as c:
        cur = c.execute(sql, params)
        c.commit()
        return cur.lastrowid
