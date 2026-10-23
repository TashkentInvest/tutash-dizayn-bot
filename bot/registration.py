"""Short registration: consent -> full name -> phone -> object location -> 2 object photos.

The current step is derived from what is already saved in the database, so a restart of the bot
(or a user coming back later) simply continues where they stopped.
"""
import asyncio
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
)

from . import db
from .config import REQUIRE_APPROVAL, UPLOADS_DIR
from .keyboards import TXT_DISTRICTS, districts_kb

PHOTOS_NEEDED = 2
MAX_PHOTO_BYTES = 10 * 1024 * 1024
# Uzbekistan bounding box (generous): rejects obviously fake locations
LAT_RANGE, LON_RANGE = (37.0, 46.0), (55.0, 74.5)

TXT_INTRO = "Ассалому алайкум! Қисқа рўйхатдан ўтамиз."
TXT_CONSENT = (
    "Ботдан фойдаланиш учун Ф.И.Ш., телефон рақамингиз, объект жойлашуви ва расмларингиз тўпланади ва сақланади. "
    "Маълумотлар фақат шу хизматни кўрсатиш мақсадида ишлатилади ва учинчи шахсларга берилмайди.\n\n"
    "«Розиман» тугмасини босиш билан шахсий маълумотларингизни қайта ишлашга розилик берасиз. "
    "Маълумотларингизни ўчиришни сўрасангиз, администраторга мурожаат қилинг."
)
BTN_CONSENT = "✅ Розиман"
TXT_NAME = "1/4. Фамилия, исм, отчествангизни киритинг (Ф.И.Ш.):"
TXT_NAME_BAD = "Илтимос, Ф.И.Ш. ни тўлиқ киритинг (камида 2 та сўз), масалан: Каримов Алишер Равшанович"
TXT_PHONE = "2/4. Телефон рақамингизни киритинг: +998 (--) --- -- --\nЁки пастдаги тугма орқали юборинг."
TXT_PHONE_BAD = "Рақам нотўғри. Намуна: +998 (90) 123 45 67"
TXT_CONTACT_NOT_OWN = "Бу сизнинг контактингиз эмас. Ўзингизнинг рақамингизни тугма орқали юборинг ёки қўлда киритинг."
TXT_LOCATION = "3/4. Объект жойлашувини юборинг (📎 → Геопозиция) ёки пастдаги тугмани босинг."
TXT_LOCATION_BAD = "Илтимос, Telegram геопозициясини юборинг."
TXT_LOCATION_OUT = "Жойлашув Ўзбекистон ҳудудидан ташқарида. Объект жойлашувини қайта юборинг."
TXT_PHOTO = "4/4. Объектнинг {} та расмини юборинг ({}-расм)."
TXT_PHOTO_BAD = "Илтимос, расм юборинг."
TXT_PHOTO_FAIL = "Расмни юклаб бўлмади. Қайта юборинг."
TXT_PHOTO_DUP = "Бу расм аллақачон юборилган. Бошқа расм юборинг."
TXT_PHOTO_BIG = "Расм ҳажми катта. Кичикроқ расм юборинг."
TXT_DONE = "✅ Рўйхатдан ўтдингиз, раҳмат!"
TXT_PENDING = "⏳ Аризангиз кўриб чиқилмоқда. Тасдиқлангач, фойдаланиш мумкин бўлади."
BTN_CONTACT = "📱 Рақамни юбориш"
BTN_LOCATION = "📍 Жойлашувни юбориш"

router = Router()
_locks: defaultdict[int, asyncio.Lock] = defaultdict(asyncio.Lock)


def _now() -> str:
    return datetime.now(timezone.utc).strftime("%Y-%m-%d %H:%M:%S")


def has_access(user_id: int) -> bool:
    """Registered, and approved by an admin when REQUIRE_APPROVAL is on."""
    return db.is_registered(user_id) and (not REQUIRE_APPROVAL or db.is_approved(user_id))


def step(user_id: int) -> str:
    u = db.get_user(user_id) or {}
    if u.get("registered_at"):
        return "done"
    if not u.get("consent_at"):
        return "consent"
    if not u.get("full_name"):
        return "name"
    if not u.get("phone"):
        return "phone"
    if u.get("latitude") is None:
        return "location"
    if db.photo_count(user_id) >= PHOTOS_NEEDED:  # photos saved but the final write was lost (crash/restart)
        db.update_user(user_id, registered_at=_now())
        db.forget_access(user_id)
        return "done"
    return "photo"


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


async def prompt(bot: Bot, chat_id: int, user_id: int) -> None:
    s = step(user_id)
    if s == "consent":
        await bot.send_message(
            chat_id, TXT_CONSENT, reply_markup=InlineKeyboardMarkup(
                inline_keyboard=[[InlineKeyboardButton(text=BTN_CONSENT, callback_data="consent")]]
            )
        )
    elif s == "name":
        await bot.send_message(chat_id, TXT_NAME, reply_markup=ReplyKeyboardRemove())
    elif s == "phone":
        await bot.send_message(chat_id, TXT_PHONE, reply_markup=_kb([[KeyboardButton(text=BTN_CONTACT, request_contact=True)]]))
    elif s == "location":
        await bot.send_message(chat_id, TXT_LOCATION, reply_markup=_kb([[KeyboardButton(text=BTN_LOCATION, request_location=True)]]))
    elif s == "photo":
        n = db.photo_count(user_id)
        await bot.send_message(chat_id, TXT_PHOTO.format(PHOTOS_NEEDED, n + 1), reply_markup=ReplyKeyboardRemove())


async def finish(m: Message) -> None:
    await m.answer(TXT_DONE, reply_markup=ReplyKeyboardRemove())
    if has_access(m.from_user.id):
        await m.answer(TXT_DISTRICTS, reply_markup=districts_kb())
    else:
        await m.answer(TXT_PENDING)


class NotRegistered(BaseFilter):
    async def __call__(self, m: Message) -> bool:
        return bool(m.from_user) and not db.is_registered(m.from_user.id)


@router.callback_query(F.data == "consent")
async def consent(c: CallbackQuery):
    uid = c.from_user.id
    async with _locks[uid]:
        if not (db.get_user(uid) or {}).get("consent_at"):
            db.update_user(uid, consent_at=_now())
    await c.answer()
    try:
        await c.message.edit_reply_markup(reply_markup=None)
    except Exception:
        pass
    await prompt(c.bot, c.message.chat.id, uid)


@router.message(NotRegistered())
async def register(m: Message):
    uid = m.from_user.id
    async with _locks[uid]:
        s = step(uid)
        if s == "done":  # the heal in step() just completed a registration that had lost its last write
            return await finish(m)
        if s == "consent":
            return await prompt(m.bot, m.chat.id, uid)
        if s == "name":
            name = " ".join((m.text or "").split())
            if len(name.split()) < 2 or len(name) < 5 or len(name) > 120 or name.startswith("/"):
                return await m.answer(TXT_NAME_BAD)
            db.update_user(uid, full_name=name)
        elif s == "phone":
            if m.contact and m.contact.user_id != uid:
                return await m.answer(TXT_CONTACT_NOT_OWN)
            raw = m.contact.phone_number if m.contact else (m.text or "")
            phone = normalize_phone(raw)
            if not phone:
                return await m.answer(TXT_PHONE_BAD)
            db.update_user(uid, phone=phone)
        elif s == "location":
            loc = m.location
            if not loc or m.forward_origin:
                return await m.answer(TXT_LOCATION_BAD)
            if not (LAT_RANGE[0] <= loc.latitude <= LAT_RANGE[1] and LON_RANGE[0] <= loc.longitude <= LON_RANGE[1]):
                return await m.answer(TXT_LOCATION_OUT)
            db.update_user(uid, latitude=loc.latitude, longitude=loc.longitude)
        elif s == "photo":
            if not m.photo:
                return await m.answer(TXT_PHOTO_BAD)
            n = db.photo_count(uid)
            if n >= PHOTOS_NEEDED:
                return
            photo = m.photo[-1]  # largest size
            if (photo.file_size or 0) > MAX_PHOTO_BYTES:
                return await m.answer(TXT_PHOTO_BIG)
            if db.fetch_all("SELECT 1 FROM user_photos WHERE user_id=? AND file_unique_id=?", (uid, photo.file_unique_id)):
                return await m.answer(TXT_PHOTO_DUP)
            dest = UPLOADS_DIR / str(uid) / f"{n + 1}_{photo.file_unique_id}.jpg"
            dest.parent.mkdir(parents=True, exist_ok=True)
            try:
                await m.bot.download(photo, destination=dest)
            except Exception:
                logging.exception("photo download failed for user %s", uid)
                dest.unlink(missing_ok=True)  # never keep a half-written file
                return await m.answer(TXT_PHOTO_FAIL)
            # path is relative to UPLOADS_DIR and times are UTC, so a web app can read them as they are
            db.add_photo(uid, photo.file_id, photo.file_unique_id, dest.relative_to(UPLOADS_DIR).as_posix())
            if n + 1 >= PHOTOS_NEEDED:
                db.update_user(uid, registered_at=_now())
                db.forget_access(uid)
                return await finish(m)
        await prompt(m.bot, m.chat.id, uid)
