import asyncio
import html
import logging
import time
from collections import defaultdict
from types import SimpleNamespace

from aiogram import BaseMiddleware, Bot, Dispatcher, F, Router
from aiogram.client.session.aiohttp import AiohttpSession
from aiogram.client.telegram import TelegramAPIServer
from aiogram.exceptions import TelegramBadRequest, TelegramForbiddenError, TelegramRetryAfter
from aiogram.filters import BaseFilter, CommandStart, ExceptionMessageFilter, ExceptionTypeFilter
from aiogram.types import CallbackQuery, ErrorEvent, FSInputFile, Message

from . import catalog, db, registration, web
from .config import (
    BOT_TOKEN,
    DATA_DIR,
    DESIGN_CODE,
    LINK_SECRET,
    LOCAL_API_URL,
    MAX_FILE_MB,
    MAX_PARALLEL_UPLOADS,
)
from .guard import Guard, OutgoingLimiter
from .keyboards import (
    FB_LEVELS,
    TXT_CHOICE,
    TXT_DISTRICTS,
    TXT_FB_LEVEL,
    TXT_LANG,
    choice_kb,
    districts_kb,
    lang_kb,
    level_kb,
    streets_kb,
)

TXT_STREETS = "<b>{}</b>\nКўчани танланг:"
TXT_EMPTY = "<b>{}</b>\nБу туман учун файллар ҳали юкланмаган."
TXT_CONTACT = (
    "\n\n📞 Савол, таклиф ёки эътирозларингиз бўлса, {} бўйича қуйидаги рақамга мурожаат қилинг:\n<b>{}</b>"
)
TXT_STALE = "Меню янгиланган. /start босинг."
TXT_NEED_REG = "Аввал рўйхатдан ўтинг: /start босинг."
TXT_ERROR = "⚠️ Хатолик юз берди. Бироздан кейин қайта уриниб кўринг."
TXT_BUSY = "⏳ Олдинги файл юкланмоқда, кутиб туринг."
TXT_LOADING = "⏳ «{}» юкланмоқда..."
TXT_ETA = "\nТахминан: {}"
TXT_FAILED = "❌ «{}» юкланиши тўхтади ({}). Қайта уриниб кўринг ёки ҳаволадан фойдаланинг."
TXT_NO_FILE = "Бу кўча учун файл ҳали юкланмаган."
TXT_LINK = "{} — {}\nФайл катта ({} МБ), юклаб олиш учун ҳавола:\n{}\n\n(Ҳавола 24 соат амал қилади)"

router = Router()
# Main menu (design code / concept): open to anyone who gave a name and phone, so it is NOT behind RequireRegistration
menu = Router()
DESIGN_TITLE = "Дизайн-код"
DESIGN_LANG = {"uz": "Ўзбекча", "ru": "Русский"}

# Requests (мурожаат): level button -> one text message -> saved for the dashboard; the answer comes back through the bot
TXT_FB_WRITE = (
    "Муаммо даражаси: {}\n\nМурожаатингиз матнини битта хабарда батафсил ёзиб юборинг "
    "(манзил, муаммо мазмуни ва таклифингиз).\n\nБекор қилиш учун /start ни босинг."
)
TXT_FB_TEXT_ONLY = "Илтимос, мурожаатингизни матн кўринишида юборинг."
TXT_FB_SHORT = "Мурожаат матни жуда қисқа. Илтимос, муаммони батафсилроқ ёзинг."
TXT_FB_LONG = "Мурожаат матни {} белгидан ошмаслиги керак. Илтимос, қисқартириб қайта юборинг."
TXT_FB_LIMIT = "Бир кунда {} тадан ортиқ мурожаат юбориш мумкин эмас. Илтимос, эртага қайта уриниб кўринг."
TXT_FB_SAVED = (
    "✅ Мурожаатингиз қабул қилинди.\nМурожаат рақами: №{}\n\n"
    "Мурожаатингиз масъул ходимлар томонидан кўриб чиқилиб, жавоб шу ерга юборилади."
)
TXT_FB_ANSWER = "📩 <b>№{} мурожаатингизга жавоб</b>\n\n<i>Сизнинг мурожаатингиз:</i>\n{}\n\n<b>Жавоб:</b>\n{}"
FB_MIN, FB_MAX, FB_PER_DAY, FB_WAIT_SECONDS = 10, 2000, 5, 1800
_fb_wait: dict[int, tuple[str, float]] = {}  # user id -> (level, when the level was picked): waiting for the text


class WaitingFeedback(BaseFilter):
    """A message from a user who picked a level and has not sent the text yet (expires after FB_WAIT_SECONDS)."""

    async def __call__(self, m: Message) -> bool:
        w = _fb_wait.get(m.from_user.id) if m.from_user else None
        if w and time.monotonic() - w[1] > FB_WAIT_SECONDS:
            _fb_wait.pop(m.from_user.id, None)
            return False
        return bool(w) and not (m.text or "").startswith("/")


class RequireRegistration(BaseMiddleware):
    """Menu buttons work only after registration (and approval, when REQUIRE_APPROVAL=1)."""

    async def __call__(self, handler, event: CallbackQuery, data):
        uid = event.from_user.id
        if not db.is_registered(uid):
            return await event.answer(TXT_NEED_REG, show_alert=True)
        if not registration.has_access(uid):
            return await event.answer(registration.TXT_PENDING, show_alert=True)
        return await handler(event, data)


class TrackUsers(BaseMiddleware):
    """Save/update every user who interacts with the bot."""

    async def __call__(self, handler, event, data):
        u = data.get("event_from_user")
        if u and not u.is_bot:
            db.touch_user(u, started=isinstance(event, Message) and (event.text or "").startswith("/start"))
        return await handler(event, data)


def private_only(r: Router) -> None:
    """The bot is personal: in a group, registration would post a member's name, phone and location for all to see."""
    r.message.filter(F.chat.type == "private")
    r.callback_query.filter(F.message.chat.type == "private")


async def stale_query(event: ErrorEvent) -> bool:
    """Buttons pressed while the bot was offline: Telegram rejects the late answer, nothing to do."""
    return True


async def on_error(event: ErrorEvent) -> bool:
    """Last resort: log with traceback and never leave the user in silence."""
    logging.error("unhandled error", exc_info=event.exception)
    update = event.update
    msg = update.message or (update.callback_query.message if update.callback_query else None)
    if msg:
        try:
            await msg.answer(TXT_ERROR)
        except Exception:
            pass
    return True


def _has_contact(uid: int) -> bool:
    """Name and phone given: enough for the main menu (the design code needs nothing more)."""
    return registration.step(uid) not in ("consent", "name", "phone")


@router.message(CommandStart())
async def start(m: Message):
    uid = m.from_user.id
    _fb_wait.pop(uid, None)  # /start cancels a request being written
    if _has_contact(uid):  # everyone past the phone step starts at the main menu, registered or not
        return await registration.send_choice(m.bot, m.chat.id)
    if registration.step(uid) == "consent":
        await m.answer(registration.TXT_INTRO)
    await registration.prompt(m.bot, m.chat.id, uid)


@menu.callback_query(F.data.startswith("pick:") | F.data.startswith("dc:") | F.data.startswith("fb:"))
async def main_menu(c: CallbackQuery):
    uid = c.from_user.id
    if not _has_contact(uid):
        return await c.answer(TXT_NEED_REG, show_alert=True)
    what = c.data.split(":", 1)[1]
    if what == "menu":  # "back" from the language / level choice
        _fb_wait.pop(uid, None)
        await _edit(c, TXT_CHOICE, choice_kb())
    elif what == "feedback":
        await _edit(c, TXT_FB_LEVEL, level_kb())
    elif c.data.startswith("fb:") and what in FB_LEVELS:
        if db.feedback_count_since(uid, 24) >= FB_PER_DAY:
            return await c.answer(TXT_FB_LIMIT.format(FB_PER_DAY), show_alert=True)
        _fb_wait[uid] = (what, time.monotonic())
        await _edit(c, TXT_FB_WRITE.format(FB_LEVELS[what]), None)
    elif what == "design":
        await _edit(c, TXT_LANG, lang_kb())
    elif what == "concept":
        if not (db.get_user(uid) or {}).get("concept_at"):
            db.update_user(uid, concept_at=registration._now())
        if registration.has_access(uid):
            await _edit(c, TXT_DISTRICTS, districts_kb())
        elif db.is_registered(uid):
            await c.answer(registration.TXT_PENDING, show_alert=True)
            return
        else:  # concept needs the object: location, then photos
            await c.answer()
            await _drop_markup(c)
            return await registration.prompt(c.bot, c.message.chat.id, uid)
    elif what in DESIGN_CODE:
        rel, name = DESIGN_CODE[what]
        f = DATA_DIR / rel
        if not f.is_file():
            logging.error("design code file missing: %s", f)
            return await c.answer(TXT_NO_FILE, show_alert=True)
        return await _start_send(c, SimpleNamespace(name=DESIGN_TITLE), SimpleNamespace(name=DESIGN_LANG[what]), [(f, name)])
    await c.answer()


@menu.message(WaitingFeedback())
async def feedback_text(m: Message):
    uid = m.from_user.id
    text = (m.text or "").strip()
    if not text:
        return await m.answer(TXT_FB_TEXT_ONLY)
    if len(text) < FB_MIN:
        return await m.answer(TXT_FB_SHORT)
    if len(text) > FB_MAX:
        return await m.answer(TXT_FB_LONG.format(FB_MAX))
    level, _ = _fb_wait.pop(uid)
    if db.feedback_count_since(uid, 24) >= FB_PER_DAY:
        return await m.answer(TXT_FB_LIMIT.format(FB_PER_DAY))
    fid = db.add_feedback(uid, level, text)
    await m.answer(TXT_FB_SAVED.format(fid))
    await registration.send_choice(m.bot, m.chat.id)


async def deliver_answers(bot: Bot) -> None:
    """Sends the answers written in the dashboard. Polls the shared database, so the dashboard needs no bot token."""
    while True:
        try:
            for r in db.unsent_answers():
                text = r["text"] if len(r["text"]) <= 600 else r["text"][:600] + "…"
                try:
                    await bot.send_message(r["user_id"], TXT_FB_ANSWER.format(r["id"], html.escape(text), html.escape(r["answer"])),
                                           parse_mode="HTML")
                    db.mark_answer_sent(r["id"])
                except (TelegramForbiddenError, TelegramBadRequest) as e:  # blocked the bot / chat gone: will never arrive
                    logging.warning("answer %s not delivered: %s", r["id"], e)
                    db.mark_answer_sent(r["id"], error=str(e)[:200])
        except Exception:  # database busy, network: try again next round
            logging.exception("deliver_answers failed")
        await asyncio.sleep(10)


async def _edit(c: CallbackQuery, text: str, kb) -> None:
    try:
        await c.message.edit_text(text, reply_markup=kb)
    except TelegramBadRequest:  # message too old / not modified: send the menu anew
        await c.message.answer(text, reply_markup=kb)


async def _drop_markup(c: CallbackQuery) -> None:
    try:
        await c.message.edit_reply_markup(reply_markup=None)
    except TelegramBadRequest:
        pass


@router.callback_query(F.data == "back")
async def back(c: CallbackQuery):
    await c.message.edit_text(TXT_DISTRICTS, reply_markup=districts_kb())
    await c.answer()


@router.callback_query(F.data.startswith("d:"))
async def pick_district(c: CallbackQuery):
    d = catalog.district(int(c.data.split(":")[1]))
    if not d:
        return await c.answer(TXT_STALE, show_alert=True)
    kb = streets_kb(d)
    has_streets = len(kb.inline_keyboard) > 1
    text = (TXT_STREETS if has_streets else TXT_EMPTY).format(d.name)
    if phone := catalog.format_phone(d.phone):
        text += TXT_CONTACT.format(d.name, phone)
    await c.message.edit_text(text, reply_markup=kb, parse_mode="HTML")
    await c.answer()


@router.callback_query(F.data.startswith("s:"))
async def pick_street(c: CallbackQuery):
    _, dn, sn = c.data.split(":")
    d = catalog.district(int(dn))
    s = catalog.street(d, int(sn)) if d else None
    if not s:
        return await c.answer(TXT_STALE, show_alert=True)
    named = catalog.named_files(s)
    if not named:
        return await c.answer(TXT_NO_FILE, show_alert=True)
    await _start_send(c, d, s, named)


async def _start_send(c: CallbackQuery, d, s, named) -> None:
    """d.name / s.name only label the caption and the download log (a district and street, or the design code and language)."""
    uid = c.from_user.id
    if uid in _busy:  # one street at a time per user: repeated presses must not multiply the work
        return await c.answer(TXT_BUSY)
    _busy.add(uid)
    try:
        await c.answer()
    except Exception:  # pressed while the bot was down/busy: too late to answer, still send the files (and free _busy)
        pass
    # The sending runs as its own task: an upload can take minutes, and holding the handler that long would keep one of
    # Guard's MAX_INFLIGHT slots per waiting user, so a burst of uncached requests made the whole bot answer "busy".
    t = asyncio.create_task(_send_street(c, d, s, named, uid))
    _tasks.add(t)
    t.add_done_callback(_tasks.discard)


async def _send_street(c: CallbackQuery, d, s, named, uid: int):
    files = [f for f, _ in named]
    loader = keep_action = None
    try:
        try:
            eta_s = _estimate(named)
        except OSError:  # a file vanished between the listing and now
            eta_s = 0
        if eta_s >= 5:
            # only worth a progress message when something really has to be uploaded; files already on
            # Telegram's side go out at once, and every extra message eats the ~30 messages/s Telegram allows
            loader = await c.message.answer(TXT_LOADING.format(s.name) + TXT_ETA.format(_fmt_eta(eta_s)))
            keep_action = asyncio.create_task(_keep_upload_action(c.bot, c.message.chat.id))
        for i, (f, name) in enumerate(named, 1):
            st = f.stat()
            size_mb = st.st_size // (1024 * 1024)
            if loader and len(files) > 1:
                await _safe_edit(
                    loader,
                    TXT_LOADING.format(s.name) + f" ({i}/{len(files)})" + TXT_ETA.format(_fmt_eta(_estimate(named[i - 1:]))),
                )
            sent_ok = False
            if st.st_size <= MAX_FILE_MB * 1024 * 1024 - 2 * 1024 * 1024:  # margin below Telegram's limit
                try:
                    await _send_file(c.message, f, st, f"{d.name} — {s.name}", name)
                    sent_ok = True
                except Exception as e:  # upload failed/stalled: tell the user and fall back to a link
                    logging.exception("send failed: %s", f)
                    await c.message.answer(TXT_FAILED.format(s.name, type(e).__name__))
            if not sent_ok:
                await c.message.answer(TXT_LINK.format(d.name, s.name, size_mb, web.link_for(f, name)))
            db.log_download(uid, d.name, s.name, name)
    except Exception as e:
        logging.exception("street handler failed")
        await c.message.answer(TXT_FAILED.format(s.name, type(e).__name__))
    finally:
        _busy.discard(uid)  # always released, whatever failed above
        if keep_action:
            keep_action.cancel()
        if loader:
            try:
                await loader.delete()
            except TelegramBadRequest:
                pass


_busy: set[int] = set()
_tasks: set[asyncio.Task] = set()  # running street sends (a reference keeps them from being garbage-collected)
_file_locks: defaultdict[str, asyncio.Lock] = defaultdict(asyncio.Lock)
_upload_slots = asyncio.Semaphore(MAX_PARALLEL_UPLOADS)


async def _send_file(msg: Message, f, st, caption: str, name: str):
    """Send a document. The first request for a file uploads it once (others wait on the lock and then
    reuse Telegram's file_id), uploads are capped so they don't starve each other, 429s are retried."""
    key, mtime = _cache_key(f, name), int(st.st_mtime)
    for attempt in range(4):
        try:
            file_id = db.get_cached(key, st.st_size, mtime)
            if file_id:
                return await msg.answer_document(file_id, caption=caption)
            async with _file_locks[key]:
                file_id = db.get_cached(key, st.st_size, mtime)  # uploaded by someone else while we waited
                if file_id:
                    return await msg.answer_document(file_id, caption=caption)
                async with _upload_slots:
                    t0 = time.monotonic()
                    sent = await msg.answer_document(FSInputFile(f, filename=name), caption=caption, request_timeout=1800)
                    db.record_upload(st.st_size, time.monotonic() - t0)
                db.set_cached(key, st.st_size, mtime, sent.document.file_id)
                return sent
        except TelegramRetryAfter as e:
            if attempt == 3:
                raise
            await asyncio.sleep(e.retry_after + 1)


def _cache_key(f, name: str) -> str:
    """Relative path (survives moving data/). A Telegram file_id keeps the file name it was uploaded with, so a file
    sent under another name (dashboard uploads: random name on disk) gets its own entry."""
    rel = f.relative_to(DATA_DIR).as_posix()
    return rel if name == f.name else f"{rel}|{name}"


def _estimate(named) -> float:
    """Seconds needed: cached files are near-instant, the rest depends on the measured upload speed."""
    bps, total = db.get_upload_speed(), 0.0
    for f, name in named:
        st = f.stat()
        if db.get_cached(_cache_key(f, name), st.st_size, int(st.st_mtime)):
            total += 2
        elif st.st_size <= MAX_FILE_MB * 1024 * 1024 - 2 * 1024 * 1024:
            total += st.st_size / bps + 2
        # larger files are only a link, instant
    return total


def _fmt_eta(sec: float) -> str:
    if sec < 5:
        return "бир неча сония"
    if sec < 90:
        return f"~{int(round(sec / 5) * 5)} сония"
    return f"~{int(round(sec / 60))} дақиқа"


async def _keep_upload_action(bot: Bot, chat_id: int):
    """Telegram shows 'sending a file…' for only ~5 s, so refresh it while the upload runs."""
    while True:
        try:
            await bot.send_chat_action(chat_id, "upload_document")
        except Exception:
            pass
        await asyncio.sleep(4)


async def _safe_edit(msg: Message, text: str):
    try:
        await msg.edit_text(text)
    except TelegramBadRequest:
        pass


async def main():
    logging.basicConfig(level=logging.INFO)
    if not BOT_TOKEN:
        raise SystemExit("BOT_TOKEN is not set (see .env.example)")
    if not LINK_SECRET:
        raise SystemExit("LINK_SECRET is not set: python -c \"import secrets;print(secrets.token_hex(32))\" and put it in .env")
    db.init()
    if catalog.import_from_folders():
        logging.info("catalog imported from data/ folders")
    api = TelegramAPIServer.from_base(LOCAL_API_URL, is_local=True) if LOCAL_API_URL else None
    bot = Bot(BOT_TOKEN, session=AiohttpSession(api=api, timeout=3600) if api else AiohttpSession(timeout=1800))
    bot.session.middleware(OutgoingLimiter())  # stay under Telegram's ~30 requests/second
    dp = Dispatcher()
    guard = Guard()  # first in line: flooded events are dropped before they cost a database write or an API call
    dp.message.outer_middleware(guard)
    dp.callback_query.outer_middleware(guard)
    dp.message.outer_middleware(TrackUsers())
    dp.callback_query.outer_middleware(TrackUsers())
    # the exception text starts with "Telegram server says - Bad Request: ...", and the filter matches from the start
    for late in ("query is too old", "query ID is invalid", "message is not modified"):
        dp.errors.register(stale_query, ExceptionTypeFilter(TelegramBadRequest), ExceptionMessageFilter(rf".*{late}"))
    dp.errors.register(on_error)
    # inner, not outer: an outer middleware on this router would also catch the "consent" button (it passes through
    # this router on its way to registration.router) and answer "register first", so nobody could ever register
    router.callback_query.middleware(RequireRegistration())
    private_only(router)
    private_only(menu)
    private_only(registration.router)
    dp.include_router(router)
    dp.include_router(menu)
    dp.include_router(registration.router)
    runner = await web.start_web()
    answers = asyncio.create_task(deliver_answers(bot))
    try:
        await dp.start_polling(bot)
    finally:
        answers.cancel()
        await runner.cleanup()


if __name__ == "__main__":
    asyncio.run(main())
