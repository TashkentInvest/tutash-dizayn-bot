from aiogram.types import InlineKeyboardButton, InlineKeyboardMarkup
from aiogram.utils.keyboard import InlineKeyboardBuilder

from . import catalog

TXT_DISTRICTS = "Туманни танланг:"
BTN_BACK = "⬅️ Орқага"


def districts_kb() -> InlineKeyboardMarkup:
    b = InlineKeyboardBuilder()
    for d in catalog.districts():
        b.button(text=d.name, callback_data=f"d:{d.num}")
    b.adjust(2)
    return b.as_markup()


def streets_kb(d: catalog.Entry) -> InlineKeyboardMarkup:
    b = InlineKeyboardBuilder()
    for s in catalog.streets(d):
        files = catalog.files(s)
        if files:
            mb = max(1, round(catalog.total_size(s) / 2**20))
            b.button(text=f"{s.name} · {mb} МБ", callback_data=f"s:{d.num}:{s.num}")
    b.adjust(1)
    b.row(InlineKeyboardButton(text=BTN_BACK, callback_data="back"))
    return b.as_markup()
