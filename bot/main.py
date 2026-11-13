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
from aiogram.types import (
    BotCommand,
    CallbackQuery,
    ErrorEvent,
    FSInputFile,
    KeyboardButton,
    LinkPreviewOptions,
    Message,
    ReplyKeyboardMarkup,
    ReplyKeyboardRemove,
)

from . import adb, catalog, db, registration, web
from .config import (
    BOT_TOKEN,
    DATA_DIR,
    DESIGN_CODE,
    LINK_SECRET,
    LOCAL_API_URL,
    MAX_FILE_MB,
    MAX_PARALLEL_UPLOADS,
    UPLOADS_DIR,
)
from .guard import Guard, OutgoingLimiter
from . import i18n
from .config import LINK_TTL_HOURS
from .i18n import t
from .keyboards import FB_LEVELS, choice_kb, districts_kb, level_kb, streets_kb

router = Router()
# Main menu (design code / concept): open to anyone who gave a name and phone, so it is NOT behind RequireRegistration
menu = Router()
DESIGN_LANG = {"uz": "Ўзбекча", "ru": "Русский"}  # download log labels (as before), shown in the dashboard

# Requests: level button -> one text message -> saved for the dashboard; the answer comes back through the bot
FB_MIN, FB_MAX, FB_PER_DAY, FB_WAIT_SECONDS = 10, 2000, 5, 1800
# which user's request text is awaited is kept in the database (users.fb_wait): a restart of the bot does not lose it


class WaitingFeedback(BaseFilter):
    """A message from a user who picked a level and has not sent the text yet (expires after FB_WAIT_SECONDS)."""

    async def __call__(self, m: Message) -> bool:
        if not m.from_user or (m.text or "").startswith("/"):
            return False
        return bool(await adb.fb_wait(m.from_user.id))


class RequireRegistration(BaseMiddleware):
    """Menu buttons work only after registration (and approval, when REQUIRE_APPROVAL=1)."""

    async def __call__(self, handler, event: CallbackQuery, data):
        uid = event.from_user.id
        if not await adb.is_registered(uid):
            return await event.answer(t(i18n.lang(uid), "need_reg"), show_alert=True)
        if not await adb.run(registration.has_access, uid):
            return await event.answer(t(i18n.lang(uid), "pending"), show_alert=True)
        return await handler(event, data)


class OncePerPress(BaseMiddleware):
    """A button pressed several times quickly (or a double tap) is handled once: the repeats only stop the spinner.
    aiogram runs every update as its own task, so without this two presses race and send the menu/file twice."""

    WINDOW = 2.0

    def __init__(self):
        self.seen: dict[tuple, float] = {}

    async def __call__(self, handler, event: CallbackQuery, data):
        msg = event.message
        key = (event.from_user.id, msg.message_id if msg else 0, event.data)
        now = time.monotonic()
        if now - self.seen.get(key, 0) < self.WINDOW:
            try:
                await event.answer()
            except Exception:
                pass
            return None
        self.seen[key] = now
        if len(self.seen) > 5000:  # forget old presses
            self.seen = {k: v for k, v in self.seen.items() if now - v < self.WINDOW}
        return await handler(event, data)


def _touch(u, started: bool, chat_id: int | None = None) -> None:
    db.touch_user(u, started=started, chat_id=chat_id)
    i18n.lang(u.id)  # loads the language into memory: the handlers read it without touching the database


class TrackUsers(BaseMiddleware):
    """Save/update every user who interacts with the bot."""

    async def __call__(self, handler, event, data):
        u = data.get("event_from_user")
        chat = data.get("event_chat")
        if u and not u.is_bot:
            await adb.run(_touch, u, isinstance(event, Message) and (event.text or "").startswith("/start"),
                          chat.id if chat and chat.type == "private" else None)
        return await handler(event, data)


def private_only(r: Router) -> None:
    """The bot is personal: in a group, registration would post a member's name, phone and location for all to see."""
    r.message.filter(F.chat.type == "private")
    r.callback_query.filter(F.message.chat.type == "private")


async def stale_query(event: ErrorEvent) -> bool:
    """Buttons pressed while the bot was offline: Telegram rejects the late answer, nothing to do."""
    return True


_error_told: dict[int, float] = {}


async def on_error(event: ErrorEvent) -> bool:
    """Last resort: log with traceback and never leave the user in silence."""
    logging.error("unhandled error", exc_info=event.exception)
    update = event.update
    cq = update.callback_query
    if cq:
        try:
            await cq.answer()  # otherwise the button keeps spinning
        except Exception:
            pass
    msg = update.message or (cq.message if cq else None)
    now = time.monotonic()
    if msg and now - _error_told.get(msg.chat.id, 0) > 30:  # one notice per user, not one per failed press
        _error_told[msg.chat.id] = now
        if len(_error_told) > 5000:
            _error_told.clear()
        try:
            await msg.answer(t(i18n.cached_lang(msg.chat.id), "error"))
        except Exception:
            pass
    return True


_has_contact = registration.has_contact


@router.message(CommandStart(deep_link=True, magic=F.args.startswith("s_")))
async def start_invite(m: Message, command):
    """A staff member opened their personal invite link from the dashboard: linked at once, whatever number they use."""
    uid = m.from_user.id
    staff = await adb.staff_by_invite(command.args[2:])
    if not staff:
        await m.answer(t(i18n.lang(uid), "invite_bad"))
        return await registration.send_lang_pick(m.bot, m.chat.id)
    await adb.link_staff(staff["id"], uid)
    await adb.use_invite(staff["id"])
    if not (await adb.get_user(uid) or {}).get("lang"):
        await adb.run(i18n.set_lang, uid, "uz")
    L = i18n.lang(uid)
    await m.answer(t(L, "staff_linked"), reply_markup=ReplyKeyboardRemove())
    await registration.send_choice(m.bot, m.chat.id, L, uid)


@router.message(CommandStart())
async def start(m: Message):
    """Every /start begins with the language (it is also how a user switches it); the chosen one leads the whole flow."""
    await adb.set_fb_wait(m.from_user.id, None)  # /start cancels a request being written
    await registration.send_lang_pick(m.bot, m.chat.id)


@menu.callback_query(F.data.in_({"lang:uz", "lang:ru"}))
async def pick_lang(c: CallbackQuery):
    uid, L = c.from_user.id, c.data.split(":")[1]
    await adb.run(i18n.set_lang, uid, L)
    await c.answer()
    await _drop_markup(c)
    if await adb.run(_has_contact, uid):  # everyone past the phone step goes to the main menu, registered or not
        if await adb.run(_unconfirmed_staff, uid):
            return await _ask_staff_contact(c.message, L)  # on the staff list: confirm the phone with the contact button
        return await registration.send_choice(c.bot, c.message.chat.id, L, uid)
    if await adb.run(registration.step, uid) == "consent":
        await c.message.answer(t(L, "intro"))
    await registration.prompt(c.bot, c.message.chat.id, uid)


def _unconfirmed_staff(uid: int) -> bool:
    """The user's phone is on the staff list but the account is not linked yet."""
    return not db.staff_by_user(uid) and bool(db.staff_by_phone((db.get_user(uid) or {}).get("phone") or ""))


@menu.callback_query(F.data.startswith("pick:") | F.data.startswith("dc:") | F.data.startswith("fb:"))
async def main_menu(c: CallbackQuery):
    uid = c.from_user.id
    L = i18n.lang(uid)
    if not await adb.run(_has_contact, uid):  # nobody uses the bot anonymously: the missing step comes first
        await c.answer(t(L, "need_reg"))
        return await registration.prompt(c.bot, c.message.chat.id, uid)
    what = c.data.split(":", 1)[1]
    if what == "design":  # the edition in the user's language: Uzbek = the short one, Russian = the full one
        what = L
    if what == "menu":  # "back" from the level choice
        await adb.set_fb_wait(uid, None)
        if staff := await adb.staff_by_user(uid):  # staff came from their own menu
            text, kb = await adb.run(registration.staff_menu, staff, L)
            try:
                await c.message.edit_text(text, reply_markup=kb, parse_mode="HTML")
            except TelegramBadRequest:
                pass
        else:
            await _edit(c, t(L, "choice"), choice_kb(L))
    elif what == "feedback":
        await _edit(c, t(L, "fb_level"), level_kb(L))
    elif c.data.startswith("fb:") and what in FB_LEVELS:
        if await adb.feedback_count_since(uid, 24) >= FB_PER_DAY:
            return await c.answer(t(L, "fb_limit", FB_PER_DAY), show_alert=True)
        await adb.set_fb_wait(uid, what, FB_WAIT_SECONDS)
        await _edit(c, t(L, "fb_write", t(L, f"lvl_{what}")), None)
    elif what == "concept":
        if not (await adb.get_user(uid) or {}).get("concept_at"):
            await adb.update_user(uid, concept_at=registration._now())
        if await adb.run(registration.has_access, uid):
            await _edit(c, t(L, "districts"), await adb.run(districts_kb, L))
        elif await adb.is_registered(uid):
            await c.answer(t(L, "pending"), show_alert=True)
            return
        else:  # concept needs the object: location, then photos
            await c.answer()
            await _drop_markup(c)
            return await registration.prompt(c.bot, c.message.chat.id, uid)
    elif what in DESIGN_CODE:
        rel, name = DESIGN_CODE[what]
        f = DATA_DIR / rel
        if not await adb.run(f.is_file):
            logging.error("design code file missing: %s", f)
            return await c.answer(t(L, "no_file"), show_alert=True)
        # name = what the download log says (unchanged for the dashboard), label = what the user sees in the caption
        d = SimpleNamespace(name="Дизайн-код", label=t(L, "design_title"))
        s = SimpleNamespace(name=DESIGN_LANG[what], label=t(what, "btn_" + what)[3:])
        return await _start_send(c, d, s, [(f, name)])
    await c.answer()


async def _ask_staff_contact(msg: Message, L: str) -> None:
    kb = ReplyKeyboardMarkup(keyboard=[[KeyboardButton(text=t(L, "btn_contact"), request_contact=True)]],
                             resize_keyboard=True, one_time_keyboard=True)
    await msg.answer(t(L, "staff_confirm"), reply_markup=kb)


class StaffContact(BaseFilter):
    """The user's own contact (sent with the button, not forwarded) whose number is on the staff list."""

    async def __call__(self, m: Message) -> bool | dict:
        if not (m.contact and m.from_user and m.contact.user_id == m.from_user.id and not m.forward_origin):
            return False
        phone = registration.normalize_phone(m.contact.phone_number or "")
        staff = await adb.staff_by_phone(phone) if phone else None
        return {"staff": staff, "phone": phone} if staff else False


@menu.message(StaffContact())
async def staff_contact(m: Message, staff: dict, phone: str):
    """Links the account to the staff member: at the phone step of registration or later, from the confirm prompt."""
    uid = m.from_user.id
    L = i18n.lang(uid)
    if not (await adb.get_user(uid) or {}).get("phone"):
        await adb.update_user(uid, phone=phone)
    await adb.link_staff(staff["id"], uid)
    await m.answer(t(L, "staff_linked"), reply_markup=ReplyKeyboardRemove())
    await registration.send_choice(m.bot, m.chat.id, L, uid)


@menu.callback_query(F.data == "staff:refresh")
async def staff_refresh(c: CallbackQuery):
    L = i18n.lang(c.from_user.id)
    staff = await adb.staff_by_user(c.from_user.id)
    if not staff:
        return await c.answer(t(L, "stale"), show_alert=True)
    if not await adb.run(_has_contact, c.from_user.id):
        await c.answer()
        return await registration.prompt(c.bot, c.message.chat.id, c.from_user.id)
    text, kb = await adb.run(registration.staff_menu, staff, L)
    try:
        await c.message.edit_text(text, reply_markup=kb, parse_mode="HTML")
    except TelegramBadRequest:  # nothing changed
        pass
    await c.answer()


@menu.message(WaitingFeedback())
async def feedback_text(m: Message):
    uid = m.from_user.id
    L = i18n.lang(uid)
    text = (m.text or "").strip()
    if not text:
        return await m.answer(t(L, "fb_text_only"))
    if len(text) < FB_MIN:
        return await m.answer(t(L, "fb_short"))
    if len(text) > FB_MAX:
        return await m.answer(t(L, "fb_long", FB_MAX))
    if await adb.feedback_count_since(uid, 24) >= FB_PER_DAY:
        await adb.set_fb_wait(uid, None)
        return await m.answer(t(L, "fb_limit", FB_PER_DAY))
    fid = await adb.save_awaited_feedback(uid, text)  # if this fails the wait stays: the user can simply send the text again
    if not fid:  # the same text arrived twice (a split long message): the first one already saved it
        return
    await m.answer(t(L, "fb_saved", fid))
    await registration.send_choice(m.bot, m.chat.id)


async def set_commands(bot: Bot) -> None:
    """The "Menu" button next to the message field: /start, described in the Telegram app's language (Russian or Uzbek)."""
    try:
        await bot.set_my_commands([BotCommand(command="start", description=t("uz", "cmd_start"))])
        await bot.set_my_commands([BotCommand(command="start", description=t("ru", "cmd_start"))], language_code="ru")
    except Exception:  # not worth stopping the bot over
        logging.exception("set_my_commands failed")


async def deliver_answers(bot: Bot) -> None:
    """Sends the answers written in the dashboard. Polls the shared database, so the dashboard needs no bot token."""
    while True:
        try:
            for r in await adb.unsent_answers():
                text = r["text"] if len(r["text"]) <= 600 else r["text"][:600] + "…"
                L = await adb.run(i18n.lang, r["user_id"])
                try:
                    await bot.send_message(r["user_id"], t(L, "fb_answer", r["id"], html.escape(text), html.escape(r["answer"])),
                                           parse_mode="HTML")
                    await adb.mark_answer_sent(r["id"])
                except (TelegramForbiddenError, TelegramBadRequest) as e:  # blocked the bot / chat gone: will never arrive
                    logging.warning("answer %s not delivered: %s", r["id"], e)
                    await adb.mark_answer_sent(r["id"], error=str(e)[:200])
                    await _maybe_blocked(r["user_id"], e)
            batch = await adb.unsent_outbox()
            for r in batch:  # review results for staff, report pictures and mass messages, written by the dashboard
                try:
                    if r["photo"]:
                        f = (UPLOADS_DIR / r["photo"]).resolve()
                        if UPLOADS_DIR.resolve() not in f.parents or not await adb.run(f.is_file):
                            await adb.mark_outbox_sent(r["id"], error="picture file missing")
                            continue
                        await bot.send_photo(r["user_id"], FSInputFile(f), caption=r["text"][:1024] or None)
                    else:
                        await bot.send_message(r["user_id"], r["text"])
                    await adb.mark_outbox_sent(r["id"])
                except TelegramRetryAfter as e:  # too fast for Telegram: the rest goes next round
                    await asyncio.sleep(e.retry_after + 1)
                    break
                except (TelegramForbiddenError, TelegramBadRequest) as e:
                    logging.warning("outbox %s not delivered: %s", r["id"], e)
                    await adb.mark_outbox_sent(r["id"], error=str(e)[:200])
                    await _maybe_blocked(r["user_id"], e)
        except Exception:  # database busy, network: try again next round
            logging.exception("deliver_answers failed")
            batch = []
        # a full batch means more is waiting (a mass message): carry on at once, OutgoingLimiter keeps the pace
        await asyncio.sleep(1 if len(batch) >= 20 else 10)


async def _maybe_blocked(user_id: int, e: Exception) -> None:
    """Blocked the bot, deactivated or never started it: kept on the user, so a mass message skips them next time."""
    if isinstance(e, TelegramForbiddenError) or "chat not found" in str(e).lower():
        await adb.mark_blocked(user_id)


async def _edit(c: CallbackQuery, text: str, kb) -> None:
    try:
        await c.message.edit_text(text, reply_markup=kb)
    except TelegramBadRequest as e:
        if "not modified" in str(e):  # already showing it (a repeated press): nothing to send
            return
        await c.message.answer(text, reply_markup=kb)  # message too old to edit: send the menu anew


async def _drop_markup(c: CallbackQuery) -> None:
    try:
        await c.message.edit_reply_markup(reply_markup=None)
    except TelegramBadRequest:
        pass


@router.callback_query(F.data == "back")
async def back(c: CallbackQuery):
    L = i18n.lang(c.from_user.id)
    await c.message.edit_text(t(L, "districts"), reply_markup=await adb.run(districts_kb, L))
    await c.answer()


@router.callback_query(F.data.startswith("d:"))
async def pick_district(c: CallbackQuery):
    L = i18n.lang(c.from_user.id)
    d = await adb.run(catalog.district, int(c.data.split(":")[1]))
    if not d:
        return await c.answer(t(L, "stale"), show_alert=True)
    kb = await adb.run(streets_kb, d, L)
    has_streets = len(kb.inline_keyboard) > 1
    dn = html.escape(i18n.name(L, d.name))
    text = t(L, "streets" if has_streets else "empty", dn)
    if phone := catalog.format_phone(d.phone):
        text += t(L, "contact", dn, html.escape(phone))
    await c.message.edit_text(text, reply_markup=kb, parse_mode="HTML")
    await c.answer()


@router.callback_query(F.data.startswith("s:"))
async def pick_street(c: CallbackQuery):
    _, dn, sn = c.data.split(":")
    d, s, named = await adb.run(_street_files, int(dn), int(sn))
    if not s:
        return await c.answer(t(i18n.lang(c.from_user.id), "stale"), show_alert=True)
    if not named:
        return await c.answer(t(i18n.lang(c.from_user.id), "no_file"), show_alert=True)
    await _start_send(c, d, s, named)


def _street_files(dn: int, sn: int):
    """(district, street, its files on disk); street None when either is gone."""
    d = catalog.district(dn)
    s = catalog.street(d, sn) if d else None
    return d, s, (catalog.named_files(s) if s else [])


async def _start_send(c: CallbackQuery, d, s, named) -> None:
    """d.name / s.name only label the caption and the download log (a district and street, or the design code and language)."""
    uid = c.from_user.id
    if uid in _busy:  # one street at a time per user: repeated presses must not multiply the work
        return await c.answer(t(i18n.lang(uid), "busy"))
    key, now = (uid, tuple(str(f) for f, _ in named)), time.monotonic()
    if now - _recent.get(key, 0) < RESEND_SECONDS:  # the same file was just sent: it is right above in the chat
        return await c.answer(t(i18n.lang(uid), "just_sent"), show_alert=True)
    _recent[key] = now
    if len(_recent) > 5000:
        for k in [k for k, v in _recent.items() if now - v > RESEND_SECONDS]:
            del _recent[k]
    _busy.add(uid)
    try:
        await c.answer()
    except Exception:  # pressed while the bot was down/busy: too late to answer, still send the files (and free _busy)
        pass
    # The sending runs as its own task: an upload can take minutes, and holding the handler that long would keep one of
    # Guard's MAX_INFLIGHT slots per waiting user, so a burst of uncached requests made the whole bot answer "busy".
    task = asyncio.create_task(_send_street(c, d, s, named, uid))  # not "t": that is the text function used above
    _tasks.add(task)
    task.add_done_callback(_tasks.discard)


async def _send_street(c: CallbackQuery, d, s, named, uid: int):
    """d.name / s.name go to the download log as stored; the user sees them in their language (label)."""
    L = i18n.lang(uid)
    dl = getattr(d, "label", None) or i18n.name(L, d.name)
    sl = getattr(s, "label", None) or i18n.name(L, s.name)
    files = [f for f, _ in named]
    loader = keep_action = None
    try:
        try:
            eta_s = await adb.run(_estimate, named)
        except OSError:  # a file vanished between the listing and now
            eta_s = 0
        if eta_s >= 5:
            # only worth a progress message when something really has to be uploaded; files already on
            # Telegram's side go out at once, and every extra message eats the ~30 messages/s Telegram allows
            loader = await c.message.answer(t(L, "loading", sl) + t(L, "eta", _fmt_eta(eta_s, L)))
            keep_action = asyncio.create_task(_keep_upload_action(c.bot, c.message.chat.id))
        for i, (f, name) in enumerate(named, 1):
            st = await adb.run(f.stat)
            size_mb = st.st_size // (1024 * 1024)
            if loader and len(files) > 1:
                await _safe_edit(
                    loader,
                    t(L, "loading", sl) + f" ({i}/{len(files)})" + t(L, "eta", _fmt_eta(await adb.run(_estimate, named[i - 1:]), L)),
                )
            sent_ok = False
            if st.st_size <= MAX_FILE_MB * 1024 * 1024 - 2 * 1024 * 1024:  # margin below Telegram's limit
                try:
                    await _send_file(c.message, f, st, f"{dl} — {sl}", name)
                    sent_ok = True
                except Exception:  # upload failed/stalled: tell the user and fall back to a link
                    logging.exception("send failed: %s", f)
                    await c.message.answer(t(L, "failed", sl))
            if not sent_ok:
                # the long signed URL hides behind a short "Havola" / "Ссылка" word; no preview (it would only fetch the file)
                url = web.link_for(f, name)
                await c.message.answer(
                    t(L, "link", html.escape(dl), html.escape(sl), size_mb, html.escape(url, quote=True), LINK_TTL_HOURS),
                    parse_mode="HTML", link_preview_options=LinkPreviewOptions(is_disabled=True),
                )
            await adb.log_download(uid, d.name, s.name, name)
    except Exception:
        logging.exception("street handler failed")
        _recent.pop((uid, tuple(str(f) for f in files)), None)  # let the user try again at once
        await c.message.answer(t(L, "failed", sl))
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
RESEND_SECONDS = 60
_recent: dict[tuple, float] = {}  # (user, files) -> when they were last sent
_tasks: set[asyncio.Task] = set()  # running street sends (a reference keeps them from being garbage-collected)
_file_locks: defaultdict[str, asyncio.Lock] = defaultdict(asyncio.Lock)
_upload_slots = asyncio.Semaphore(MAX_PARALLEL_UPLOADS)


async def _send_file(msg: Message, f, st, caption: str, name: str):
    """Send a document. The first request for a file uploads it once (others wait on the lock and then
    reuse Telegram's file_id), uploads are capped so they don't starve each other, 429s are retried."""
    key, mtime = _cache_key(f, name), int(st.st_mtime)
    for attempt in range(4):
        try:
            file_id = await adb.get_cached(key, st.st_size, mtime)
            if file_id:
                return await msg.answer_document(file_id, caption=caption)
            async with _file_locks[key]:
                file_id = await adb.get_cached(key, st.st_size, mtime)  # uploaded by someone else while we waited
                if file_id:
                    return await msg.answer_document(file_id, caption=caption)
                async with _upload_slots:
                    t0 = time.monotonic()
                    sent = await msg.answer_document(FSInputFile(f, filename=name), caption=caption, request_timeout=1800)
                    await adb.record_upload(st.st_size, time.monotonic() - t0)
                await adb.set_cached(key, st.st_size, mtime, sent.document.file_id)
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


def _fmt_eta(sec: float, lang: str = "uz") -> str:
    if sec < 5:
        return t(lang, "eta_few")
    if sec < 90:
        return t(lang, "eta_sec", int(round(sec / 5) * 5))
    return t(lang, "eta_min", int(round(sec / 60)))


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
    dp.callback_query.outer_middleware(OncePerPress())
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
    await set_commands(bot)
    try:
        await dp.start_polling(bot)
    finally:
        answers.cancel()
        await runner.cleanup()


if __name__ == "__main__":
    asyncio.run(main())
