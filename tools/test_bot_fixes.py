"""Checks of the hardening fixes, on a COPY of the database (never the real one).  python tools/test_bot_fixes.py"""
import asyncio, os, shutil, sys, tempfile, time
from types import SimpleNamespace as NS

ROOT = os.path.abspath(os.path.join(os.path.dirname(__file__), ".."))
tmp = tempfile.mkdtemp()
shutil.copy(os.path.join(ROOT, "bot.db"), os.path.join(tmp, "bot.db"))
os.environ.update(DB_PATH=os.path.join(tmp, "bot.db"), LINK_SECRET="test-secret", LINK_TTL_HOURS="1", REQUIRE_APPROVAL="1")
sys.path.insert(0, ROOT)
sys.stdout.reconfigure(encoding="utf-8")

from bot import catalog, db, registration as reg, web  # noqa: E402
from bot.config import DATA_DIR  # noqa: E402

ok = True
def check(name, cond):
    global ok
    ok &= bool(cond)
    print(("PASS " if cond else "FAIL ") + name)

db.init()
import sqlite3
c = sqlite3.connect(os.environ["DB_PATH"])
idx = {r[1] for r in c.execute("select * from sqlite_master where type='index'")}
check("indexes created", {"idx_downloads_user", "idx_photos_user", "idx_users_registered", "uq_files_path", "idx_files_size"} <= idx)
cols = {r[1] for r in c.execute("pragma table_info(users)")}
check("consent_at/approved_at columns", {"consent_at", "approved_at"} <= cols)
check("file_cache keys are relative", not any(":" in r[0] or "\\" in r[0] for r in c.execute("select path from file_cache")))

# registration: consent first, then the heal for "photos saved but registered_at lost"
u = NS(id=424242, username="t", first_name="T", last_name=None, language_code="uz")
db.touch_user(u, started=True)
check("new user starts at consent", reg.step(424242) == "consent")
db.update_user(424242, consent_at="2026-01-01 00:00:00", full_name="Test User", phone="+998901234567", latitude=41.3, longitude=69.2)
db.add_photo(424242, "a", "ua", "424242/1.jpg"); db.add_photo(424242, "b", "ub", "424242/2.jpg")
check("lost final write is healed", reg.step(424242) == "done" and db.is_registered(424242))
check("approval gate: registered but not approved", db.is_registered(424242) and not reg.has_access(424242))
db.update_user(424242, approved_at="2026-01-01 00:00:00"); db._access_ok.clear()
check("approval gate: approved -> access", reg.has_access(424242))

# touch_user throttle: second plain touch within 60 s must not write
db.touch_user(u); first = c.execute("select last_seen from users where user_id=424242").fetchone()[0]
time.sleep(1.1); db.touch_user(u)
check("last_seen write is throttled", sqlite3.connect(os.environ["DB_PATH"]).execute("select last_seen from users where user_id=424242").fetchone()[0] == first)

# phone / location helpers
check("phone formats", reg.normalize_phone("+998 (90) 123 45 67") == "+998901234567" and reg.normalize_phone("123") is None)

# signed links: valid, tampered, expired
f = next(p for d in catalog.districts() for s in catalog.streets(d) for p in catalog.files(s))
url = web.link_for(f); parts = url.split("/f/")[1].split("/")
exp, sig = parts[0], parts[1]
check("link carries an expiry in the future", int(exp) > time.time())
check("signature depends on expiry", web._sig(f.relative_to(DATA_DIR).as_posix(), int(exp)) == sig
      and web._sig(f.relative_to(DATA_DIR).as_posix(), int(exp) + 1) != sig)

async def http():
    from aiohttp import ClientSession
    runner = await web.start_web()
    try:
        async with ClientSession() as s:
            base = f"http://127.0.0.1:{web.WEB_PORT}"
            path = url.split("/f/")[1]
            async with s.get(f"{base}/f/{path}", headers={"Range": "bytes=0-99"}) as r:
                check("valid link downloads (206)", r.status == 206 and r.headers.get("X-Content-Type-Options") == "nosniff"
                      and r.headers["Content-Disposition"].startswith("attachment"))
            bad = path.replace(sig, "0" * 32)
            async with s.get(f"{base}/f/{bad}") as r:
                check("tampered link -> 404", r.status == 404)
            old = int(time.time()) - 5
            async with s.get(f"{base}/f/{old}/{web._sig(f.relative_to(DATA_DIR).as_posix(), old)}/{'/'.join(path.split('/')[2:])}") as r:
                check("expired link -> 404 even with a valid signature", r.status == 404)
    finally:
        await runner.cleanup()

# end to end through the dispatcher as wired in main(): a new user presses "Розиман" and must reach the name step,
# not get "register first" (the menu's registration check once caught the consent button too)
async def consent_flow():
    from datetime import datetime
    from aiogram import Bot, Dispatcher
    from aiogram.client.session.base import BaseSession
    from aiogram.methods import AnswerCallbackQuery, SendMessage
    from aiogram.types import Chat, Message, Update
    from bot import main as bm

    sent = []
    class Fake(BaseSession):
        async def make_request(self, bot, method, timeout=None):
            sent.append(method)
            if isinstance(method, SendMessage):
                return Message(message_id=1, date=datetime.now(), chat=Chat(id=method.chat_id, type="private"), text=method.text)
            return True
        async def stream_content(self, *a, **k):
            yield b""
        async def close(self):
            pass

    bot = Bot("42:TEST", session=Fake())
    dp = Dispatcher()
    dp.callback_query.outer_middleware(bm.TrackUsers())
    bm.router.callback_query.middleware(bm.RequireRegistration())
    dp.include_router(bm.router); dp.include_router(reg.router)
    uid = 515151
    frm = {"id": uid, "is_bot": False, "first_name": "N"}
    msg = {"message_id": 5, "date": 0, "chat": {"id": uid, "type": "private"}, "from": {"id": 1, "is_bot": True, "first_name": "B"}, "text": "x"}
    await dp.feed_update(bot, Update.model_validate({"update_id": 1, "callback_query": {"id": "q1", "from": frm, "chat_instance": "c", "message": msg, "data": "consent"}}))
    alerts = [m.text for m in sent if isinstance(m, AnswerCallbackQuery) and m.text]
    check("consent button is not blocked by the menu's registration check", not alerts and reg.step(uid) == "name")
    check("after consent the bot asks for the name", any(isinstance(m, SendMessage) and m.text == reg.TXT_NAME for m in sent))
    sent.clear()
    await dp.feed_update(bot, Update.model_validate({"update_id": 2, "callback_query": {"id": "q2", "from": frm, "chat_instance": "c", "message": msg, "data": "d:1"}}))
    check("menu buttons still need registration", any(isinstance(m, AnswerCallbackQuery) and m.text == bm.TXT_NEED_REG for m in sent))

asyncio.run(consent_flow())

os.environ["WEB_PORT"] = "8799"; web.WEB_PORT = 8799
asyncio.run(http())
shutil.rmtree(tmp, ignore_errors=True)
print("\nALL OK" if ok else "\nSOME CHECKS FAILED")
sys.exit(0 if ok else 1)
