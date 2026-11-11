"""Checks of the hardening fixes, on a COPY of the database (never the real one).  python tools/test_bot_fixes.py"""
import asyncio, os, shutil, sys, tempfile, time
from types import SimpleNamespace as NS

ROOT = os.path.abspath(os.path.join(os.path.dirname(__file__), ".."))
tmp = tempfile.mkdtemp()
# a sample catalog, no people (tools/make_test_db.py); the street files are made in a temporary data folder
shutil.copy(os.path.join(ROOT, "tests", "fixtures", "bot.sqlite"), os.path.join(tmp, "bot.db"))
os.environ["DATA_DIR"] = os.path.join(tmp, "data")
import sqlite3 as _sq
for (_path,) in _sq.connect(os.path.join(tmp, "bot.db")).execute("SELECT path FROM street_files"):
    _f = os.path.join(tmp, "data", *_path.split("/"))
    os.makedirs(os.path.dirname(_f), exist_ok=True)
    open(_f, "wb").write(b"%PDF-1.4 test file\n")
os.environ.update(DB_PATH=os.path.join(tmp, "bot.db"), UPLOADS_DIR=os.path.join(tmp, "uploads"), LINK_SECRET="test-secret", LINK_TTL_HOURS="1", REQUIRE_APPROVAL="1")
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
check("new user starts at the language choice", reg.step(424242) == "lang")
db.update_user(424242, lang="uz")
check("then consent", reg.step(424242) == "consent")
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
    bm.private_only(bm.router); bm.private_only(bm.menu); bm.private_only(reg.router)
    dp.include_router(bm.router); dp.include_router(bm.menu); dp.include_router(reg.router)
    uid = 515151
    from aiogram.methods import EditMessageText
    from bot.i18n import t
    n = [10]
    async def press(data, who=uid):
        n[0] += 1
        f = {"id": who, "is_bot": False, "first_name": "N"}
        await dp.feed_update(bot, Update.model_validate({"update_id": n[0], "callback_query": {"id": f"q{n[0]}", "from": f, "chat_instance": "c", "message": msg, "data": data}}))
    async def say(text, who=uid):
        n[0] += 1
        await dp.feed_update(bot, Update.model_validate({"update_id": n[0], "message": {"message_id": n[0], "date": 0, "chat": {"id": who, "type": "private"}, "from": {"id": who, "is_bot": False, "first_name": "N"}, "text": text}}))
    texts = lambda: [getattr(m, "text", None) for m in sent if isinstance(m, (SendMessage, EditMessageText))]
    alerts = lambda: [m.text for m in sent if isinstance(m, AnswerCallbackQuery) and m.text]
    msg = {"message_id": 5, "date": 0, "chat": {"id": uid, "type": "private"}, "from": {"id": 1, "is_bot": True, "first_name": "B"}, "text": "x"}

    # /start always asks the language first; the choice leads the whole flow
    await say("/start")
    check("/start asks the language (uz / ru)", t("uz", "pick_lang") in texts() and reg.step(uid) == "lang")
    sent.clear(); await say("salom")
    check("text before the language repeats the language choice", t("uz", "pick_lang") in texts())
    sent.clear(); await press("lang:uz")
    check("uz -> intro and consent in Uzbek (Latin)", t("uz", "intro") in texts() and t("uz", "consent") in texts())
    sent.clear(); await press("consent")
    check("consent button is not blocked by the menu's registration check", not alerts() and reg.step(uid) == "name")
    check("after consent the bot asks for the name", t("uz", "name") in texts())
    sent.clear(); await press("d:1")
    check("menu buttons still need registration", t("uz", "need_reg") in alerts())
    sent.clear()
    grp = {"message_id": 7, "date": 0, "chat": {"id": -100, "type": "supergroup", "title": "g"}, "from": {"id": 777, "is_bot": False, "first_name": "G"}, "text": "/start"}
    await dp.feed_update(bot, Update.model_validate({"update_id": 3, "message": grp}))
    check("group chats are ignored (no registration prompt posted to a group)", not sent)

    # late answers / repeated edits: Telegram's text starts with "Telegram server says - ", the filter must still match
    from aiogram.exceptions import TelegramBadRequest
    from aiogram.filters import ExceptionMessageFilter
    from aiogram.types import ErrorEvent
    for late in ("query is too old and response timeout expired or query ID is invalid", "message is not modified: x"):
        e = TelegramBadRequest(method=AnswerCallbackQuery(callback_query_id="x"), message="Bad Request: " + late)
        ev = ErrorEvent(update=Update(update_id=9), exception=e)
        check(f"stale filter matches '{late[:24]}'", await ExceptionMessageFilter(r".*" + late.split(" and")[0].split(":")[0])(ev))

    # main menu after name + phone: design code (edition by language) / concept / request
    sends = []
    async def fake_start_send(c, d, s, named):
        sends.append((d.name, s.name, [x[1] for x in named])); await c.answer()
    bm._start_send = fake_start_send

    await press("pick:design")
    check("menu is closed before name and phone", sends == [] and reg.step(uid) == "name")
    sent.clear(); await say("Тестов Тест Тестович"); await say("+998 90 123 45 67")
    check("a typed phone number is refused: the contact button is asked for", t("uz", "phone_button") in texts() and reg.step(uid) == "phone")
    n[0] += 1
    await dp.feed_update(bot, Update.model_validate({"update_id": n[0], "message": {"message_id": n[0], "date": 0,
        "chat": {"id": uid, "type": "private"}, "from": {"id": uid, "is_bot": False, "first_name": "N"},
        "contact": {"phone_number": "998901234567", "first_name": "N", "user_id": uid}}}))
    check("after the phone the bot asks for the section (in Uzbek)", reg.step(uid) == "choice" and t("uz", "choice") in texts())
    sent.clear(); await say("bir matn")
    check("text at the choice step repeats the choice", t("uz", "choice") in texts())
    import bot.config as cfg
    made = []
    for k in ("uz", "ru"):
        f = DATA_DIR / cfg.DESIGN_CODE[k][0]
        if not f.exists():
            f.parent.mkdir(parents=True, exist_ok=True); f.write_bytes(b"%PDF-1.4 test"); made.append(f)
    try:
        sent.clear(); await press("pick:design")
        check("uz: design code sends the Uzbek edition at once (no flags)", sends and sends[-1][1] == "Ўзбекча"
              and sends[-1][2] == [cfg.DESIGN_CODE["uz"][1]] and reg.step(uid) == "choice")
        sent.clear(); await say("/start"); await press("lang:ru")
        check("switching to ru at /start: menu in Russian", t("ru", "choice") in texts())
        await press("pick:design")
        check("ru: design code sends the Russian edition", sends[-1][1] == "Русский" and sends[-1][2] == [cfg.DESIGN_CODE["ru"][1]])
    finally:
        for f in made:
            f.unlink()
        if made and not any(made[0].parent.iterdir()):
            made[0].parent.rmdir()
    sent.clear(); await press("pick:concept")
    check("concept -> location step in Russian", reg.step(uid) == "location" and t("ru", "location") in texts())

    # a registered (and approved) user: /start -> language -> menu, concept -> districts in Latin
    sent.clear(); await say("/start", 424242); await press("lang:uz", 424242); await press("pick:concept", 424242)
    kb = next(m.reply_markup for m in sent if isinstance(m, (SendMessage, EditMessageText)) and m.text == t("uz", "districts"))
    labels = [b.text for row in kb.inline_keyboard for b in row]
    check("registered user: concept opens the districts, names in Latin", t("uz", "choice") in texts()
          and "Bektemir tumani" in labels and not any("тумани" in x for x in labels))

    # requests: level -> text -> saved; an answer written in the dashboard is delivered by the bot (in the user's language)
    sent.clear(); await press("pick:feedback")
    check("request button -> problem level (ru)", t("ru", "fb_level") in texts())
    await press("fb:high"); sent.clear(); await say("коротко")
    check("too short request is refused", t("ru", "fb_short") in texts() and not db.fetch_all("SELECT 1 FROM feedback WHERE user_id=?", (uid,)))
    body = "На нашей улице вечером не работает освещение, опасно для пешеходов <b>"
    sent.clear(); await say(body)
    rows = db.fetch_all("SELECT id, level, text FROM feedback WHERE user_id=?", (uid,))
    check("request saved with its level", len(rows) == 1 and rows[0]["level"] == "high" and rows[0]["text"] == body
          and t("ru", "fb_saved", rows[0]["id"]) in texts())
    sent.clear(); await say("это обычное сообщение, не обращение")
    check("after saving, the next message is not another request", len(db.fetch_all("SELECT 1 FROM feedback WHERE user_id=?", (uid,))) == 1)

    db.execute("UPDATE feedback SET answer=?, answered_at=datetime('now') WHERE id=?", ("Освещение восстановят за 3 дня.", rows[0]["id"]))
    sent.clear()
    task = asyncio.create_task(bm.deliver_answers(bot)); await asyncio.sleep(0.3); task.cancel()
    msgs = [m for m in sent if isinstance(m, SendMessage) and m.chat_id == uid]
    after = db.fetch_all("SELECT answer_sent_at, answer_error FROM feedback WHERE id=?", (rows[0]["id"],))[0]
    check("dashboard answer delivered once, in Russian, user text escaped", len(msgs) == 1 and "Освещение восстановят" in msgs[0].text
          and "Ответ на ваше обращение" in msgs[0].text and "&lt;b&gt;" in msgs[0].text and after["answer_sent_at"] and after["answer_error"] is None)

    # district staff: the phone confirmed with the contact button links the account; the staff menu replaces the citizens' one
    sid = db.execute("INSERT INTO staff (full_name, phone, district_id) VALUES ('Test Xodim', '+998901110001', (SELECT MIN(id) FROM districts))")
    sent.clear(); await say("/start", 424242)
    # 424242's stored phone is not on the list -> normal menu
    db.update_user(424242, phone="+998901110001")
    sent.clear(); await say("/start", 424242); await press("lang:uz", 424242)
    check("staff phone not yet confirmed -> asked for the contact button", t("uz", "staff_confirm") in texts() and not db.staff_by_user(424242))
    n[0] += 1
    contact = {"update_id": n[0], "message": {"message_id": n[0], "date": 0, "chat": {"id": 424242, "type": "private"},
               "from": {"id": 424242, "is_bot": False, "first_name": "N"},
               "contact": {"phone_number": "998901110001", "first_name": "N", "user_id": 424242}}}
    sent.clear(); await dp.feed_update(bot, Update.model_validate(contact))
    staff_msg = next((m for m in sent if isinstance(m, SendMessage) and "Tuman xodimi" in (m.text or "")), None)
    check("own contact links the staff member and opens the staff menu", db.staff_by_user(424242) and db.staff_by_user(424242)["id"] == sid
          and t("uz", "staff_linked") in texts() and staff_msg is not None)
    btn = staff_msg.reply_markup.inline_keyboard[0][0] if staff_msg else None
    datas = [b.callback_data for row in (staff_msg.reply_markup.inline_keyboard if staff_msg else []) for b in row]
    check("staff menu: design code and request buttons too", "pick:design" in datas and "pick:feedback" in datas)
    check("staff menu: button to the Mini App", btn is not None and (btn.web_app or btn.url) and "/tg/app" in ((btn.web_app.url if btn.web_app else btn.url) or ""))
    forged = {**contact, "update_id": n[0] + 1}
    forged["message"] = {**contact["message"], "message_id": n[0] + 1, "from": {"id": 515151, "is_bot": False, "first_name": "X"},
                         "chat": {"id": 515151, "type": "private"}}
    n[0] += 1; await dp.feed_update(bot, Update.model_validate(forged))
    check("someone else's contact links nothing", db.staff_by_user(515151) is None)
    sent.clear(); await press("pick:feedback", 424242); await press("pick:menu", 424242)
    check("'back' from a request returns staff to the staff menu", any("Tuman xodimi" in (x or "") for x in texts()))
    # a new person typing a staff number by hand is asked for the contact button, not registered as a citizen
    w = 636363
    await say("/start", w); await press("lang:uz", w); await press("consent", w); await say("Yozuvchi Xodim Test", w)
    sent.clear(); await say("+998 90 111 00 01", w)
    check("typed staff number -> contact button asked, phone not taken", t("uz", "phone_button") in texts()
          and not (db.get_user(w) or {}).get("phone") and reg.step(w) == "phone")
    n[0] += 1
    other = {"update_id": n[0], "message": {"message_id": n[0], "date": 0, "chat": {"id": w, "type": "private"},
             "from": {"id": w, "is_bot": False, "first_name": "N"}, "contact": {"phone_number": "998935550000", "first_name": "N", "user_id": w}}}
    sent.clear(); await dp.feed_update(bot, Update.model_validate(other))
    check("a new person's own number not on the staff list is taken as a citizen's", reg.step(w) == "choice")
    db.update_user(w, phone="+998901110001")  # e.g. typed before the fix: on the staff list, not confirmed
    sent.clear(); await say("/start", w); await press("lang:uz", w)
    check("asked to confirm the staff number", t("uz", "staff_confirm") in texts())
    other["message"] = {**other["message"], "message_id": n[0] + 1}; other["update_id"] = n[0] + 1; n[0] += 1
    sent.clear(); await dp.feed_update(bot, Update.model_validate(other))
    check("confirmed with a number not on the list -> told so, menu again", t("uz", "staff_not_found") in texts() and t("uz", "choice") in texts())
    sent.clear(); await say("/start", 424242); await press("lang:uz", 424242)
    check("next /start: straight to the staff menu", any("Tuman xodimi" in (x or "") for x in texts()) and t("uz", "choice") not in texts())

    # review results written by the dashboard reach the staff member through the bot
    db.execute("INSERT INTO outbox (user_id, text) VALUES (424242, '✅ №1 obyekt bo‘yicha dalilingiz tasdiqlandi')")
    sent.clear()
    task = asyncio.create_task(bm.deliver_answers(bot)); await asyncio.sleep(0.3); task.cancel()
    check("outbox message delivered once", [m.text for m in sent if isinstance(m, SendMessage) and m.chat_id == 424242]
          == ["✅ №1 obyekt bo‘yicha dalilingiz tasdiqlandi"] and not db.unsent_outbox())

    # a report picture from the dashboard: sent as a photo with its caption; a missing file is marked, not retried forever
    from aiogram.methods import SendPhoto
    (bm.UPLOADS_DIR / "reports").mkdir(parents=True, exist_ok=True)
    (bm.UPLOADS_DIR / "reports" / "t.png").write_bytes(b"\x89PNG test")
    db.execute("INSERT INTO outbox (user_id, text, photo) VALUES (424242, 'Dizayn-kod · svod', 'reports/t.png')")
    db.execute("INSERT INTO outbox (user_id, text, photo) VALUES (424242, 'yo‘q', 'reports/missing.png')")
    db.execute("INSERT INTO outbox (user_id, text, photo) VALUES (424242, 'tashqarida', '../bot.db')")
    sent.clear()
    task = asyncio.create_task(bm.deliver_answers(bot)); await asyncio.sleep(0.3); task.cancel()
    photos = [m for m in sent if isinstance(m, SendPhoto)]
    check("outbox picture sent as a photo with caption; missing / outside files never sent",
          [p.caption for p in photos] == ["Dizayn-kod · svod"] and not db.unsent_outbox())

    from bot import i18n
    check("transliteration: Uzbek Cyrillic -> Latin", i18n.latin("Мирзо Улуғбек тумани") == "Mirzo Ulug‘bek tumani"
          and i18n.latin("Фарғона йўли кўчаси") == "Farg‘ona yo‘li ko‘chasi" and i18n.latin("Ёшлар") == "Yoshlar")
    check("every text exists in both languages", all(len(v) == 2 and all(v) for v in i18n.TEXTS.values()))

asyncio.run(consent_flow())


async def repeated_presses():
    """A file button pressed again while sending / right after: a short notice, never the error message (bug of Nov 10)."""
    from pathlib import Path
    from types import SimpleNamespace as N
    import importlib
    from bot import main as M
    M = importlib.reload(M)  # the flows above replaced _start_send with a recorder
    got = []

    async def ans(text=None, **k):
        got.append(text)
    c = N(from_user=N(id=4242), answer=ans)
    M._busy.add(4242)
    await M._start_send(c, None, None, [(Path("x.pdf"), "x.pdf")])
    M._busy.discard(4242)
    M._recent[(4242, ("x.pdf",))] = time.monotonic()
    await M._start_send(c, None, None, [(Path("x.pdf"), "x.pdf")])
    check("repeated file press: busy / just-sent notice, no crash", len(got) == 2 and all(got))

asyncio.run(repeated_presses())


os.environ["WEB_PORT"] = "8799"; web.WEB_PORT = 8799
asyncio.run(http())
shutil.rmtree(tmp, ignore_errors=True)
print("\nALL OK" if ok else "\nSOME CHECKS FAILED")
sys.exit(0 if ok else 1)
