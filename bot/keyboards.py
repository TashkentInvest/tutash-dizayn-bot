from aiogram.types import InlineKeyboardButton, InlineKeyboardMarkup
from aiogram.utils.keyboard import InlineKeyboardBuilder

from . import catalog
from .i18n import name, t

FB_LEVELS = ("low", "medium", "high")


def lang_pick_kb() -> InlineKeyboardMarkup:
    return InlineKeyboardMarkup(inline_keyboard=[[
        InlineKeyboardButton(text=t("uz", "btn_uz"), callback_data="lang:uz"),
        InlineKeyboardButton(text=t("ru", "btn_ru"), callback_data="lang:ru"),
    ]])


def choice_kb(lang: str) -> InlineKeyboardMarkup:
    return InlineKeyboardMarkup(inline_keyboard=[
        [InlineKeyboardButton(text=t(lang, "btn_design"), callback_data="pick:design"),
         InlineKeyboardButton(text=t(lang, "btn_concept"), callback_data="pick:concept")],
        [InlineKeyboardButton(text=t(lang, "btn_feedback"), callback_data="pick:feedback")],
    ])


def level_kb(lang: str) -> InlineKeyboardMarkup:
    return InlineKeyboardMarkup(inline_keyboard=[
        [InlineKeyboardButton(text=t(lang, f"lvl_{k}"), callback_data=f"fb:{k}") for k in FB_LEVELS],
        [InlineKeyboardButton(text=t(lang, "btn_back"), callback_data="pick:menu")],
    ])


def districts_kb(lang: str) -> InlineKeyboardMarkup:
    b = InlineKeyboardBuilder()
    for d in catalog.districts():
        b.button(text=name(lang, d.name), callback_data=f"d:{d.num}")
    b.adjust(2)
    return b.as_markup()


def streets_kb(d: catalog.Entry, lang: str) -> InlineKeyboardMarkup:
    b = InlineKeyboardBuilder()
    for s in catalog.streets(d):
        files = catalog.files(s)
        if files:
            mb = max(1, round(catalog.total_size(s) / 2**20))
            b.button(text=f"{name(lang, s.name)} · {mb} {t(lang, 'mb')}", callback_data=f"s:{d.num}:{s.num}")
    b.adjust(1)
    b.row(InlineKeyboardButton(text=t(lang, "btn_back"), callback_data="back"))
    return b.as_markup()
