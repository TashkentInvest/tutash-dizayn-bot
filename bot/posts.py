"""Posts from the dashboard ("Post yuborish"): a picture or video and a text, to the people the admin ticked.

The dashboard writes the post and one `post_deliveries` row per person; this worker sends them at Telegram's pace,
uploads the file once and reuses its file_id, keeps every message id, and takes the post back from the chats when the
admin asks ("Botdan o'chirish", Telegram allows it within 48 hours). "Tanishdim" under the post is the `pa:<id>` button.
"""
import asyncio
import html
import logging

from aiogram import Bot, F, Router
from aiogram.exceptions import (TelegramAPIError, TelegramBadRequest, TelegramForbiddenError, TelegramNetworkError,
                                TelegramRetryAfter, TelegramServerError)
from aiogram.types import CallbackQuery, FSInputFile, InlineKeyboardButton, InlineKeyboardMarkup

from . import adb
from .config import UPLOADS_DIR

router = Router(name="posts")

PAUSE = 0.04        # seconds between messages: ~30 a second, Telegram's limit for a bot
COUNT_EVERY = 200   # the dashboard's delivered / failed counts are written this often while sending
ACK, ACKED = "✅ Tanishdim", "✅ Tanishdingiz"


def ack_kb(post_id: int, done: bool = False) -> InlineKeyboardMarkup:
    return InlineKeyboardMarkup(inline_keyboard=[[InlineKeyboardButton(text=ACKED if done else ACK, callback_data=f"pa:{post_id}")]])


@router.callback_query(F.data.startswith("pa:"))
async def ack(c: CallbackQuery):
    """ "Tanishdim": the first press is recorded (the dashboard counts it), the button then says so."""
    try:
        post_id = int(c.data[3:])
    except ValueError:
        return await c.answer()
    await adb.ack_post(post_id, c.from_user.id)
    await c.answer("Rahmat!")
    try:
        await c.message.edit_reply_markup(reply_markup=ack_kb(post_id, done=True))
    except TelegramBadRequest:  # already changed (a second press) or the message is gone
        pass


class _Missing(Exception):
    """The post's file is not on the disk (deleted, or outside the uploads folder)."""


async def _send_one(bot: Bot, post: dict, chat_id: int, media) -> tuple[int, str | None]:
    """Sends the post to one person: (message id, the file_id Telegram gave the media)."""
    # the admin's text exactly as written: never transliterated, never read as markup
    text = html.escape(post["caption"] or "")
    kb = ack_kb(post["id"]) if post["ack_button"] else None
    if post["media_type"] == "photo":
        m = await bot.send_photo(chat_id, media, caption=text or None, parse_mode="HTML", reply_markup=kb)
        return m.message_id, m.photo[-1].file_id
    if post["media_type"] == "video":
        # without its size Telegram shows a phone video stretched (bug seen in practice)
        m = await bot.send_video(chat_id, media, caption=text or None, parse_mode="HTML", reply_markup=kb,
                                 width=post["media_width"] or None, height=post["media_height"] or None,
                                 duration=post["media_duration"] or None, supports_streaming=True)
        return m.message_id, m.video.file_id
    m = await bot.send_message(chat_id, text, parse_mode="HTML", reply_markup=kb)
    return m.message_id, None


async def send_post(bot: Bot, p) -> None:
    post = dict(p)
    file_id = post["file_id"]
    local = None
    if post["media_type"] and not file_id:
        f = (UPLOADS_DIR / (post["media_path"] or "")).resolve()
        if UPLOADS_DIR.resolve() in f.parents and await adb.run(f.is_file):
            local = f
    n = 0
    while batch := await adb.pending_deliveries(post["id"]):
        for d in batch:
            try:
                if post["media_type"] and not file_id and not local:
                    raise _Missing("fayl topilmadi")
                media = (file_id or FSInputFile(local)) if post["media_type"] else None
                mid, got = await _send_one(bot, post, d["user_id"], media)
                if got and not file_id:  # uploaded once: everyone after gets the same file
                    file_id = got
                    await adb.set_post_file_id(post["id"], got)
                await adb.mark_delivery(d["id"], mid)
            except TelegramRetryAfter as e:  # too fast: wait, this person stays pending and is sent next round
                await asyncio.sleep(e.retry_after + 1)
                continue
            except (TelegramNetworkError, TelegramServerError):
                raise  # not this person's fault: the worker tries again in a moment
            except (TelegramAPIError, _Missing) as e:  # blocked the bot, chat gone...: noted, the others still get it
                await adb.mark_delivery(d["id"], None, error=str(e)[:200])
                if isinstance(e, TelegramForbiddenError):
                    await adb.mark_blocked(d["user_id"])
            n += 1
            if n % COUNT_EVERY == 0:
                await adb.count_post(post["id"])
            await asyncio.sleep(PAUSE)
    await adb.count_post(post["id"])


async def recall_post(bot: Bot, post_id: int) -> None:
    """ "Botdan o'chirish": deletes the post from every chat it reached; Telegram refuses after 48 hours (counted)."""
    while batch := await adb.recall_queue(post_id):
        for d in batch:
            try:
                await bot.delete_message(d["user_id"], d["message_id"])
                await adb.mark_recalled(d["id"])
            except TelegramRetryAfter as e:
                await asyncio.sleep(e.retry_after + 1)
                continue
            except (TelegramNetworkError, TelegramServerError):
                raise
            except TelegramAPIError as e:
                await adb.mark_recalled(d["id"], error=str(e)[:200])
            await asyncio.sleep(PAUSE)
    await adb.count_recall(post_id)


async def post_worker(bot: Bot) -> None:
    """Runs with the bot: picks up new posts and recalls from the shared database (the dashboard needs no bot token)."""
    while True:
        busy = False
        try:
            for p in await adb.posts_to_send():
                busy = True
                await send_post(bot, p)
            for r in await adb.posts_to_recall():
                busy = True
                await recall_post(bot, r["id"])
        except Exception:  # database busy, network: the rest is still waiting, try again soon
            logging.exception("post_worker failed")
        await asyncio.sleep(2 if busy else 5)
