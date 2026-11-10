"""Copies the bot's SQLite database into PostgreSQL (the move to PostgreSQL, or a fresh copy for checks).

    DATABASE_URL=postgresql://... python tools/sqlite_to_pg.py /srv/tutash/bot.db            # refuses a non-empty target
    DATABASE_URL=postgresql://... python tools/sqlite_to_pg.py /srv/tutash/bot.db --replace  # empties the target first

Creates the schema (bot/db.py), copies every table row by row (only columns both sides have; 0/1 -> booleans, empty
times -> NULL), moves the id counters past the copied ids, and checks that every table has the same number of rows.
Stop the bot first for the real move, so nothing is written to SQLite meanwhile.
"""
import os
import sqlite3
import sys

sys.path.insert(0, os.path.dirname(os.path.dirname(os.path.abspath(__file__))))

from bot import db  # noqa: E402

TABLES = ["users", "districts", "streets", "street_files", "downloads", "file_cache", "kv", "user_photos", "feedback",
          "staff", "objects", "inspections", "outbox"]


def main(src: str, replace: bool) -> None:
    if not db.PG:
        sys.exit("DATABASE_URL (postgresql://...) is not set")
    s = sqlite3.connect(src)
    s.row_factory = sqlite3.Row
    db.init()  # schema on PostgreSQL
    with db._db() as c:
        pg = c.c
        filled = [t for t in TABLES if pg.execute(f"SELECT 1 FROM {t} LIMIT 1").fetchone()]
        if filled and not replace:
            sys.exit(f"target not empty ({', '.join(filled)}): add --replace to overwrite")
        if replace:
            pg.execute("TRUNCATE " + ", ".join(TABLES) + " RESTART IDENTITY")
        have_src = {r[0] for r in s.execute("SELECT name FROM sqlite_master WHERE type='table'")}
        for t in TABLES:
            if t not in have_src:
                print(f"{t:<14} (not in SQLite)")
                continue
            types = {r[0]: r[1] for r in pg.execute(
                "SELECT column_name, data_type FROM information_schema.columns WHERE table_name = %s", (t,)).fetchall()}
            cols = [r[1] for r in s.execute(f"PRAGMA table_info({t})") if r[1] in types]
            rows = s.execute(f"SELECT {', '.join(cols)} FROM {t}").fetchall()

            def conv(col, v):
                if v is None:
                    return None
                if types[col] == "boolean":
                    return bool(int(v))
                if types[col].startswith("timestamp") and isinstance(v, str) and not v.strip():
                    return None
                return v

            data = [tuple(conv(col, r[col]) for col in cols) for r in rows]
            if data:
                with pg.cursor() as cur:
                    cur.executemany(f"INSERT INTO {t} ({', '.join(cols)}) VALUES ({', '.join(['%s'] * len(cols))})", data)
            if "id" in cols:  # next ids continue after the copied ones
                pg.execute(f"SELECT setval(pg_get_serial_sequence('{t}', 'id'), COALESCE(MAX(id), 1), MAX(id) IS NOT NULL) FROM {t}")
            n_pg = pg.execute(f"SELECT COUNT(*) FROM {t}").fetchone()[0]
            ok = "ok" if n_pg == len(rows) else "!! MISMATCH"
            print(f"{t:<14} sqlite {len(rows):>6}  postgres {n_pg:>6}  {ok}")
            if n_pg != len(rows):
                sys.exit(1)
    print("copied")


if __name__ == "__main__":
    files = [a for a in sys.argv[1:] if not a.startswith("--")]
    if not files:
        sys.exit("usage: sqlite_to_pg.py /path/to/bot.db [--replace]")
    main(files[0], "--replace" in sys.argv)
