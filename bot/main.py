import asyncio
import logging
import time
from collections import defaultdict

from aiogram import BaseMiddleware, Bot, Dispatcher, F, Router
from aiogram.client.session.aiohttp import AiohttpSession
from aiogram.client.telegram import TelegramAPIServer
from aiogram.exceptions import TelegramBadRequest, TelegramRetryAfter
from aiogram.filters import CommandStart, ExceptionMessageFilter, ExceptionTypeFilter
from aiogram.types import CallbackQuery, ErrorEvent, FSInputFile, Message

from . import catalog, db, registration, web
from .config import (
    BOT_TOKEN,
    DATA_DIR,
    LINK_SECRET,
    LOCAL_API_URL,
    MAX_FILE_MB,
    MAX_PARALLEL_UPLOADS,
)
from .guard import Guard, OutgoingLimiter
from .keyboards import TXT_DISTRICTS, districts_kb, streets_kb

TXT_STREETS = "<b>{}</b>\nКўчани танланг:"
TXT_EMPTY = "<b>{}</b>\nБу туман учун файллар ҳали юкланмаган."
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


@router.message(CommandStart())
async def start(m: Message):
    uid = m.from_user.id
    if registration.has_access(uid):
        return await m.answer(TXT_DISTRICTS, reply_markup=districts_kb())
    if db.is_registered(uid):
        return await m.answer(registration.TXT_PENDING)
    if registration.step(uid) == "consent":
        await m.answer(registration.TXT_INTRO)
    await registration.prompt(m.bot, m.chat.id, uid)


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
    await c.message.edit_text(
        (TXT_STREETS if has_streets else TXT_EMPTY).format(d.name), reply_markup=kb, parse_mode="HTML"
    )
    await c.answer()


@router.callback_query(F.data.startswith("s:"))
async def pick_street(c: CallbackQuery):
    _, dn, sn = c.data.split(":")
    d = catalog.district(int(dn))
    s = catalog.street(d, int(sn)) if d else None
    if not s:
        return await c.answer(TXT_STALE, show_alert=True)
    files = catalog.files(s)
    if not files:
        return await c.answer(TXT_NO_FILE, show_alert=True)
    uid = c.from_user.id
    if uid in _busy:  # one street at a time per user: repeated presses must not multiply the work
        return await c.answer(TXT_BUSY)
    _busy.add(uid)
    loader = keep_action = None
    try:
        await c.answer()
        try:
            eta_s = _estimate(files)
        except OSError:  # a file vanished between the listing and now
            eta_s = 0
        if eta_s >= 5:
            # only worth a progress message when something really has to be uploaded; files already on
            # Telegram's side go out at once, and every extra message eats the ~30 messages/s Telegram allows
            loader = await c.message.answer(TXT_LOADING.format(s.name) + TXT_ETA.format(_fmt_eta(eta_s)))
            keep_action = asyncio.create_task(_keep_upload_action(c.bot, c.message.chat.id))
        for i, f in enumerate(files, 1):
            st = f.stat()
            size_mb = st.st_size // (1024 * 1024)
            if loader and len(files) > 1:
                await _safe_edit(
                    loader,
                    TXT_LOADING.format(s.name) + f" ({i}/{len(files)})" + TXT_ETA.format(_fmt_eta(_estimate(files[i - 1:]))),
                )
            sent_ok = False
            if st.st_size <= MAX_FILE_MB * 1024 * 1024 - 2 * 1024 * 1024:  # margin below Telegram's limit
                try:
                    await _send_file(c.message, f, st, f"{d.name} — {s.name}")
                    sent_ok = True
                except Exception as e:  # upload failed/stalled: tell the user and fall back to a link
                    logging.exception("send failed: %s", f)
                    await c.message.answer(TXT_FAILED.format(s.name, type(e).__name__))
            if not sent_ok:
                await c.message.answer(TXT_LINK.format(d.name, s.name, size_mb, web.link_for(f)))
            db.log_download(uid, d.name, s.name, f.name)
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
_file_locks: defaultdict[str, asyncio.Lock] = defaultdict(asyncio.Lock)
_upload_slots = asyncio.Semaphore(MAX_PARALLEL_UPLOADS)


async def _send_file(msg: Message, f, st, caption: str):
    """Send a document. The first request for a file uploads it once (others wait on the lock and then
    reuse Telegram's file_id), uploads are capped so they don't starve each other, 429s are retried."""
    key, mtime = f.relative_to(DATA_DIR).as_posix(), int(st.st_mtime)  # relative: survives moving data/
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
                    sent = await msg.answer_document(FSInputFile(f), caption=caption, request_timeout=1800)
                    db.record_upload(st.st_size, time.monotonic() - t0)
                db.set_cached(key, st.st_size, mtime, sent.document.file_id)
                return sent
        except TelegramRetryAfter as e:
            if attempt == 3:
                raise
            await asyncio.sleep(e.retry_after + 1)


def _estimate(files) -> float:
    """Seconds needed: cached files are near-instant, the rest depends on the measured upload speed."""
    bps, total = db.get_upload_speed(), 0.0
    for f in files:
        st = f.stat()
        if db.get_cached(f.relative_to(DATA_DIR).as_posix(), st.st_size, int(st.st_mtime)):
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
    dp.errors.register(stale_query, ExceptionTypeFilter(TelegramBadRequest), ExceptionMessageFilter("query is too old"))
    dp.errors.register(on_error)
    router.callback_query.outer_middleware(RequireRegistration())
    dp.include_router(router)
    dp.include_router(registration.router)
    runner = await web.start_web()
    try:
        await dp.start_polling(bot)
    finally:
        await runner.cleanup()


if __name__ == "__main__":
    asyncio.run(main())
