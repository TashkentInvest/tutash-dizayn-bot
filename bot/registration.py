"""Short registration: language -> consent -> full name -> phone -> choice (design code / concept / request).
"Design code" needs nothing more (the PDF is sent in main.py); "concept" goes on: object location -> 2 object photos.

The current step is derived from what is already saved in the database, so a restart of the bot
(or a user coming back later) simply continues where they stopped.
"""
import asyncio
import html
import logging
import re
from collections import defaultdict
from datetime import datetime, timezone

from aiogram import Bot, F, Router
from aiogram.filters import BaseFilter
from aiogram.types import (
    CallbackQuery,
    InlineKeyboardButton,
    InlineKeyboardMarkup,
    KeyboardButton,
    Message,
    ReplyKeyboardMarkup,
    ReplyKeyboardRemove,
    WebAppInfo,
)

from . import adb, db, i18n
from .config import REQUIRE_APPROVAL, UPLOADS_DIR, WEBAPP_URL
from .i18n import t
from .keyboards import choice_kb, districts_kb, lang_pick_kb

PHOTOS_NEEDED = 2
MAX_PHOTO_BYTES = 10 * 1024 * 1024
# Uzbekistan bounding box (generous): rejects obviously fake locations
LAT_RANGE, LON_RANGE = (37.0, 46.0), (55.0, 74.5)

router = Router()
_locks: defaultdict[int, asyncio.Lock] = defaultdict(asyncio.Lock)


def _now() -> str:
    return datetime.now(timezone.utc).strftime("%Y-%m-%d %H:%M:%S")


def has_contact(user_id: int) -> bool:
    """Language, consent, full name and own phone given: nobody uses the bot anonymously, staff included.
    Enough for the main menu (design code and requests need nothing more)."""
    return step(user_id) not in ("lang", "consent", "name", "phone")


def has_access(user_id: int) -> bool:
    """Registered, and approved by an admin when REQUIRE_APPROVAL is on."""
    return db.is_registered(user_id) and (not REQUIRE_APPROVAL or db.is_approved(user_id))


def step(user_id: int) -> str:
    u = db.get_user(user_id) or {}
    # language, consent, name and phone come first for everyone: also for those who registered before a step existed
    if u.get("lang") not in i18n.LANGS:
        return "lang"
    if not u.get("consent_at"):
        return "consent"
    if not u.get("full_name"):
        staff = db.staff_by_user(user_id)
        if not staff or not staff.get("full_name"):
            return "name"
        db.update_user(user_id, full_name=staff["full_name"])  # a linked staff member: the name on the staff list
    if not u.get("phone"):
        return "phone"
    if u.get("registered_at"):
        return "done"
    if not u.get("concept_at") and u.get("latitude") is None:  # a location given before this step existed = concept
        return "choice"
    if u.get("latitude") is None:
        return "location"
    if db.photo_count(user_id) >= PHOTOS_NEEDED:  # photos saved but the final write was lost (crash/restart)
        db.update_user(user_id, registered_at=_now())
        db.forget_access(user_id)
        return "done"
    return "photo"


NAME_MARKS = " '-.ʻʼ’‘`"


def valid_name(name: str) -> bool:
    """Surname and name at least: only letters (Latin or Cyrillic) and the marks names use; no digits, links or @."""
    return (2 <= len(name.split()) <= 6 and 5 <= len(name) <= 120
            and all(ch.isalpha() or ch in NAME_MARKS for ch in name) and sum(ch.isalpha() for ch in name) >= 4)


def normalize_phone(raw: str) -> str | None:
    """Accepts +998 (90) 123 45 67 / 998901234567 / 901234567 -> '+998901234567'."""
    digits = re.sub(r"\D", "", raw)
    if len(digits) == 9:
        digits = "998" + digits
    return "+" + digits if re.fullmatch(r"998\d{9}", digits) else None


def pretty_phone(p: str) -> str:
    return f"+998 ({p[4:6]}) {p[6:9]} {p[9:11]} {p[11:13]}"


def _kb(rows):
    return ReplyKeyboardMarkup(keyboard=rows, resize_keyboard=True, one_time_keyboard=True)


async def send_lang_pick(bot: Bot, chat_id: int) -> None:
    await bot.send_message(chat_id, t("uz", "pick_lang"), reply_markup=lang_pick_kb())


async def prompt(bot: Bot, chat_id: int, user_id: int) -> None:
    s, L = await adb.run(step, user_id), i18n.lang(user_id)
    if s == "lang":
        await send_lang_pick(bot, chat_id)
    elif s == "consent":
        await bot.send_message(
            chat_id, t(L, "consent"), reply_markup=InlineKeyboardMarkup(
                inline_keyboard=[[InlineKeyboardButton(text=t(L, "btn_consent"), callback_data="consent")]]
            )
        )
    elif s == "name":
        await bot.send_message(chat_id, t(L, "name"), reply_markup=ReplyKeyboardRemove())
    elif s == "phone":
        await bot.send_message(chat_id, t(L, "phone"),
                               reply_markup=_kb([[KeyboardButton(text=t(L, "btn_contact"), request_contact=True)]]))
    elif s in ("choice", "done"):  # "done": an old registration that only lacked the consent, name or phone
        await send_choice(bot, chat_id, None, user_id)
    elif s == "location":
        await bot.send_message(chat_id, t(L, "location"),
                               reply_markup=_kb([[KeyboardButton(text=t(L, "btn_location"), request_location=True)]]))
    elif s == "photo":
        n = await adb.photo_count(user_id)
        await bot.send_message(chat_id, t(L, "photo", PHOTOS_NEEDED, n + 1), reply_markup=ReplyKeyboardRemove())


async def send_choice(bot: Bot, chat_id: int, lang: str | None = None, user_id: int | None = None) -> None:
    """Main menu. In a private chat the chat id is the user id, so it also tells the language.
    Whoever has not given the name and phone yet gets the missing step instead."""
    if not await adb.run(has_contact, user_id or chat_id):
        return await prompt(bot, chat_id, user_id or chat_id)
    L = lang or i18n.lang(user_id or chat_id)
    if staff := await adb.staff_by_user(user_id or chat_id):  # district staff get their own menu instead of the citizens' one
        return await send_staff_menu(bot, chat_id, staff, L)
    await bot.send_message(chat_id, t(L, "choice"), reply_markup=choice_kb(L))


def staff_menu(staff: dict, L: str) -> tuple[str, InlineKeyboardMarkup]:
    n = db.staff_counts(staff)  # only the streets assigned to this person (all of the district when none are)
    text = t(L, "staff_menu", html.escape(staff["full_name"]), html.escape(i18n.name(L, staff["district"])),
             n["total"], n["removed"], n["installed"], n["review"])
    # Telegram opens Mini Apps over https only; a plain link keeps a local http setup usable for testing
    open_btn = (InlineKeyboardButton(text=t(L, "btn_objects"), web_app=WebAppInfo(url=f"{WEBAPP_URL}?lang={L}"))
                if WEBAPP_URL.startswith("https://") else InlineKeyboardButton(text=t(L, "btn_objects"), url=WEBAPP_URL))
    kb = InlineKeyboardMarkup(inline_keyboard=[
        [open_btn],
        [InlineKeyboardButton(text=t(L, "btn_design"), callback_data="pick:design"),
         InlineKeyboardButton(text=t(L, "btn_feedback"), callback_data="pick:feedback")],
        [InlineKeyboardButton(text=t(L, "btn_refresh"), callback_data="staff:refresh")],
    ])
    return text, kb


async def send_staff_menu(bot: Bot, chat_id: int, staff: dict, L: str) -> None:
    text, kb = await adb.run(staff_menu, staff, L)
    await bot.send_message(chat_id, text, reply_markup=kb, parse_mode="HTML")


async def finish(m: Message) -> None:
    L = i18n.lang(m.from_user.id)
    await m.answer(t(L, "done"), reply_markup=ReplyKeyboardRemove())
    if await adb.run(has_access, m.from_user.id):
        await m.answer(t(L, "districts"), reply_markup=await adb.run(districts_kb, L))
    else:
        await m.answer(t(L, "pending"))


class NotRegistered(BaseFilter):
    async def __call__(self, m: Message) -> bool:
        # registered long ago but without consent, name or phone: those steps are still answered here
        return bool(m.from_user) and not (await adb.is_registered(m.from_user.id) and await adb.run(has_contact, m.from_user.id))


@router.callback_query(F.data == "consent")
async def consent(c: CallbackQuery):
    uid = c.from_user.id
    async with _locks[uid]:
        if not (await adb.get_user(uid) or {}).get("consent_at"):
            await adb.update_user(uid, consent_at=_now())
    await c.answer()
    try:
        await c.message.edit_reply_markup(reply_markup=None)
    except Exception:
        pass
    await prompt(c.bot, c.message.chat.id, uid)


@router.message(NotRegistered())
async def register(m: Message):
    uid = m.from_user.id
    L = i18n.lang(uid)
    async with _locks[uid]:
        s = await adb.run(step, uid)
        if s == "done":  # the heal in step() just completed a registration that had lost its last write
            return await finish(m)
        if s in ("lang", "consent", "choice"):  # these steps are answered with buttons, not text
            if s == "choice" and m.contact and m.contact.user_id == uid:  # the staff confirmation answered with another number
                await m.answer(t(L, "staff_not_found"), reply_markup=ReplyKeyboardRemove())
            return await prompt(m.bot, m.chat.id, uid)
        if s == "name":
            name = " ".join((m.text or "").split())
            if not valid_name(name):
                return await m.answer(t(L, "name_bad"))
            await adb.update_user(uid, full_name=name)
        elif s == "phone":
            # only the person's own number, sent with Telegram's contact button: a typed number proves nothing
            contact_kb = _kb([[KeyboardButton(text=t(L, "btn_contact"), request_contact=True)]])
            if not m.contact:
                return await m.answer(t(L, "phone_button"), reply_markup=contact_kb)
            if m.contact.user_id != uid or m.forward_origin:
                return await m.answer(t(L, "contact_not_own"), reply_markup=contact_kb)
            phone = normalize_phone(m.contact.phone_number or "")
            if not phone:
                return await m.answer(t(L, "phone_bad"), reply_markup=ReplyKeyboardRemove())
            await adb.update_user(uid, phone=phone)
            await m.answer(t(L, "thanks"), reply_markup=ReplyKeyboardRemove())  # the choice comes with inline buttons
        elif s == "location":
            loc = m.location
            if not loc or m.forward_origin:
                return await m.answer(t(L, "location_bad"))
            if not (LAT_RANGE[0] <= loc.latitude <= LAT_RANGE[1] and LON_RANGE[0] <= loc.longitude <= LON_RANGE[1]):
                return await m.answer(t(L, "location_out"))
            await adb.update_user(uid, latitude=loc.latitude, longitude=loc.longitude)
        elif s == "photo":
            if not m.photo:
                return await m.answer(t(L, "photo_bad"))
            n = await adb.photo_count(uid)
            if n >= PHOTOS_NEEDED:
                return
            photo = m.photo[-1]  # largest size
            if (photo.file_size or 0) > MAX_PHOTO_BYTES:
                return await m.answer(t(L, "photo_big"))
            if await adb.fetch_all("SELECT 1 FROM user_photos WHERE user_id=? AND file_unique_id=?", (uid, photo.file_unique_id)):
                return await m.answer(t(L, "photo_dup"))
            dest = UPLOADS_DIR / str(uid) / f"{n + 1}_{photo.file_unique_id}.jpg"
            await adb.run(dest.parent.mkdir, parents=True, exist_ok=True)
            try:
                await m.bot.download(photo, destination=dest)
            except Exception:
                logging.exception("photo download failed for user %s", uid)
                dest.unlink(missing_ok=True)  # never keep a half-written file
                return await m.answer(t(L, "photo_fail"))
            # path is relative to UPLOADS_DIR and times are UTC, so a web app can read them as they are
            await adb.add_photo(uid, photo.file_id, photo.file_unique_id, dest.relative_to(UPLOADS_DIR).as_posix())
            if n + 1 >= PHOTOS_NEEDED:
                await adb.update_user(uid, registered_at=_now())
                db.forget_access(uid)  # memory only, no query
                return await finish(m)
        await prompt(m.bot, m.chat.id, uid)


@router.message()
async def fallback(m: Message):
    """Registered users who type something no step expects (or a contact that is not on the staff list) get the menu
    again instead of silence."""
    if not m.from_user:
        return
    if m.contact and m.contact.user_id == m.from_user.id and not await adb.staff_by_user(m.from_user.id):
        # answered the staff confirmation with a number that is not on the list (StaffContact took the ones that are)
        await m.answer(t(i18n.lang(m.from_user.id), "staff_not_found"), reply_markup=ReplyKeyboardRemove())
    await send_choice(m.bot, m.chat.id, None, m.from_user.id)
