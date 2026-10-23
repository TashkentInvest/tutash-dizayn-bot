"""District / street / file catalog.

The catalog lives in the database (tables `districts`, `streets`, `street_files`) so the dashboard can edit it;
files stay on disk under DATA_DIR and `street_files.path` is relative to it.
"""
import re
import time
from dataclasses import dataclass
from pathlib import Path

from . import db
from .config import DATA_DIR

_cache: dict = {}
_TTL = 5.0  # seconds; dashboard edits show up in the bot within this time


@dataclass
class Entry:
    num: int  # database id
    name: str
    phone: str | None = None  # districts only: contact for questions


def _cached(key, compute):
    now = time.monotonic()
    hit = _cache.get(key)
    if hit and now - hit[0] < _TTL:
        return hit[1]
    value = compute()
    _cache[key] = (now, value)
    return value


def districts() -> list[Entry]:
    return _cached("d", lambda: [
        Entry(r["id"], r["name"], r["phone"])
        for r in db.fetch_all("SELECT id, name, phone FROM districts WHERE is_active = 1 ORDER BY sort_order, id")
    ])


def format_phone(raw: str | None) -> str | None:
    """'+998992012272' / '99-201-22-72' / '992012272' -> '+998 99 201 22 72'; anything else is shown as typed."""
    if not raw or not raw.strip():
        return None
    digits = re.sub(r"\D", "", raw)
    if len(digits) == 9:
        digits = "998" + digits
    if len(digits) == 12 and digits.startswith("998"):
        return f"+998 {digits[3:5]} {digits[5:8]} {digits[8:10]} {digits[10:12]}"
    return raw.strip()


def district(num: int) -> Entry | None:
    return next((d for d in districts() if d.num == num), None)


def streets(d: Entry) -> list[Entry]:
    return _cached(("s", d.num), lambda: [
        Entry(r["id"], r["name"])
        for r in db.fetch_all(
            "SELECT id, name FROM streets WHERE district_id = ? AND is_active = 1 ORDER BY sort_order, id", (d.num,)
        )
    ])


def street(d: Entry, num: int) -> Entry | None:
    return next((s for s in streets(d) if s.num == num), None)


def files(s: Entry) -> list[Path]:
    """Existing files of a street, in the order set in the dashboard."""
    def load():
        rows = db.fetch_all("SELECT path FROM street_files WHERE street_id = ? ORDER BY sort_order, id", (s.num,))
        paths = [DATA_DIR / r["path"] for r in rows]
        return [p for p in paths if p.is_file()]

    return _cached(("f", s.num), load)


def total_size(s: Entry) -> int:
    """Bytes of all files of a street, from the size stored at upload time (no disk access)."""
    return _cached(("z", s.num), lambda: db.fetch_all(
        "SELECT COALESCE(SUM(size), 0) AS n FROM street_files WHERE street_id = ?", (s.num,))[0]["n"])


def import_from_folders() -> bool:
    """One-time import of the old data/<NN_District>/<NN_Street>/ tree. Does nothing if the catalog already has data."""
    if db.fetch_all("SELECT 1 FROM districts LIMIT 1") or not DATA_DIR.is_dir():
        return False
    prefix = re.compile(r"^(\d+)_(.*)$")

    def entries(base: Path):
        return sorted(((int(m.group(1)), m.group(2), p) for p in base.iterdir()
                       if p.is_dir() and (m := prefix.match(p.name))), key=lambda e: e[0])

    for dnum, dname, dpath in entries(DATA_DIR):
        did = db.execute("INSERT INTO districts (name, sort_order) VALUES (?, ?)", (dname, dnum))
        for snum, sname, spath in entries(dpath):
            sid = db.execute("INSERT INTO streets (district_id, name, sort_order) VALUES (?, ?, ?)", (did, sname, snum))
            for i, f in enumerate(sorted(x for x in spath.iterdir() if x.is_file() and not x.name.startswith("."))):
                db.execute(
                    "INSERT INTO street_files (street_id, file_name, path, size, sort_order) VALUES (?, ?, ?, ?, ?)",
                    (sid, f.name, f.relative_to(DATA_DIR).as_posix(), f.stat().st_size, i),
                )
    return True
