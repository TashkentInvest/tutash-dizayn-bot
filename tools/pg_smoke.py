"""Runs the bot's database calls against PostgreSQL and checks the answers (use a throwaway copy, it writes test rows).

    DATABASE_URL=postgresql://.../tutash_check python tools/pg_smoke.py
The schema comes from the migrations: run them on that database first (web/: php artisan migrate --path=database/migrations/bot).
"""
import asyncio
import os
import sys
import threading
from types import SimpleNamespace as NS

sys.path.insert(0, os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
os.environ.setdefault("LINK_SECRET", "smoke")

from bot import adb, catalog, db  # noqa: E402

ok = True


def check(name, cond):
    global ok
    ok &= bool(cond)
    print(("PASS " if cond else "FAIL ") + name)


if not db.PG:
    sys.exit("DATABASE_URL is not a postgresql:// URL")
db.init()  # the schema check: the migrations created every table and column the bot uses
check("schema made by the migrations is the one the bot needs", True)
if not db.fetch_all("SELECT 1 FROM districts LIMIT 1"):  # an empty database (CI): one district to work with
    db.execute("INSERT INTO districts (name, sort_order) VALUES ('Smoke tumani', 1)")
catalog._cache.clear()
uid = 900000000001  # beyond 32 bits, like real Telegram ids
db.execute("DELETE FROM users WHERE user_id=?", (uid,))
db.touch_user(NS(id=uid, username="smoke", first_name="S", last_name=None, language_code="uz"), started=True)
u = db.get_user(uid)
check("touch_user / get_user (64-bit id, defaults)", u and u["start_count"] == 1 and u["first_seen"] is not None)
db.update_user(uid, full_name="Smoke Test", phone="+998901110001", lang="ru", consent_at="2026-01-01 00:00:00")
check("update_user, timestamps from strings", db.get_user(uid)["lang"] == "ru" and str(db.get_user(uid)["consent_at"]).startswith("2026-01-01"))
fid = db.add_feedback(uid, "high", "smoke test request")
check("insert returns the new id", isinstance(fid, int) and fid > 0)
check("datetime('now', '-24 hours') translated", db.feedback_count_since(uid, 24) >= 1)
db.execute("UPDATE feedback SET answer='ok', answered_at=datetime('now') WHERE id=?", (fid,))
check("unsent answers", any(r["id"] == fid for r in db.unsent_answers(500)))
db.mark_answer_sent(fid)
check("mark sent", not any(r["id"] == fid for r in db.unsent_answers(500)))
db.set_cached("smoke/x.pdf", 1, 2, "A"); db.set_cached("smoke/x.pdf", 1, 2, "B")
check("upsert file_cache (ON CONFLICT)", db.get_cached("smoke/x.pdf", 1, 2) == "B")
db.record_upload(5 * 1024 * 1024, 2.0)
check("kv upsert", db.get_upload_speed() > 0)
d = catalog.districts()
check("catalog: districts (is_active = TRUE)", len(d) >= 1)
s = catalog.streets(d[0]) if d else []
check("catalog: streets", isinstance(s, list))
sid = db.execute("INSERT INTO staff (full_name, phone, district_id) VALUES ('Smoke', '+998900000000', ?)", (d[0].num,))
db.link_staff(sid, uid)
check("staff link + lookup (boolean column)", db.staff_by_user(uid)["id"] == sid and db.staff_by_phone("+998900000000"))
counts = db.staff_counts(db.staff_by_user(uid))
check("staff_counts (CASE sums, planned total)", set(counts) == {"total", "removed", "installed", "review"})
db.log_download(uid, "Smoke", "Smoke street", "x.pdf")
row = db.fetch_all("SELECT COUNT(*) AS n FROM downloads WHERE user_id=?", (uid,))[0]
check("rows by index and by name", row[0] == row["n"] == 1 and dict(row) == {"n": 1})
db.set_fb_wait(uid, "high", 60)
check("awaited request kept in the database", db.fb_wait(uid) == "high")
fid2 = db.save_awaited_feedback(uid, "smoke awaited request")
check("awaited request saved once, the wait cleared", fid2 and db.save_awaited_feedback(uid, "again") is None and db.fb_wait(uid) is None)


async def side_by_side():
    names = set()

    def who(_):
        names.add(threading.current_thread().name)
        return db.fetch_all("SELECT pg_sleep(0.2), pg_backend_pid() AS pid")[0]["pid"]

    pids = await asyncio.gather(*(adb.run(who, i) for i in range(6)))
    return len(set(pids)), len(names)


conns, threads = asyncio.run(side_by_side())
check(f"async calls run side by side on their own connections ({conns} connections, {threads} threads)", conns >= 2 and threads >= 2)


def refused(sql, params=()):
    try:
        db.execute(sql, params)
    except Exception as e:
        return "foreign key" in str(e).lower()
    return False


check("foreign key: a district with staff cannot be deleted", refused("DELETE FROM districts WHERE id=?", (d[0].num,)))
check("foreign key: a request for an unknown user is refused", refused("INSERT INTO feedback (user_id, level, text) VALUES (1, 'low', 'x')"))
oid = db.execute("INSERT INTO outbox (user_id, text) VALUES (?, '50% done')", (uid,))
check("literal % in SQL", db.fetch_all("SELECT text FROM outbox WHERE id=?", (oid,))[0]["text"] == "50% done")
# clean up
for sql in ("DELETE FROM outbox WHERE user_id=?", "DELETE FROM downloads WHERE user_id=?", "DELETE FROM feedback WHERE user_id=?",
            "DELETE FROM staff WHERE user_id=?", "DELETE FROM users WHERE user_id=?"):
    db.execute(sql, (uid,))
db.execute("DELETE FROM file_cache WHERE path='smoke/x.pdf'")
print("\nALL OK" if ok else "\nSOME CHECKS FAILED")
sys.exit(0 if ok else 1)
