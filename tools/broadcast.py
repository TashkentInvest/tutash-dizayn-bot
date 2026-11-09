"""One-time announcement to the bot's users: "the bot was updated, please press /start again" (Uzbek + Russian).

Run on the server, as the bot user, with the bot's .env loaded:
    sudo -u tutash bash -c 'set -a; . ./.env; set +a; venv/bin/python tools/broadcast.py'          # dry run: counts only
    sudo -u tutash bash -c 'set -a; . ./.env; set +a; venv/bin/python tools/broadcast.py --send'   # really sends

Users who already picked a language (pressed /start after the update) are skipped. ~15 messages a second, well under
Telegram's limit, so the running bot keeps answering. Users who blocked the bot are counted, not retried.
"""
import asyncio
import logging
import os
import sys

sys.path.insert(0, os.path.dirname(os.path.dirname(os.path.abspath(__file__))))

from aiogram import Bot  # noqa: E402
from aiogram.client.session.aiohttp import AiohttpSession  # noqa: E402
from aiogram.client.telegram import TelegramAPIServer  # noqa: E402
from aiogram.exceptions import TelegramBadRequest, TelegramForbiddenError, TelegramRetryAfter  # noqa: E402

from bot import db  # noqa: E402
from bot.config import BOT_TOKEN, LOCAL_API_URL  # noqa: E402
from bot.i18n import t  # noqa: E402

TEXT = t("uz", "broadcast_restart") + "\n\n" + t("ru", "broadcast_restart")
PER_SECOND = 15


async def main(send: bool) -> None:
    db.init()
    users = [r["user_id"] for r in db.fetch_all(
        "SELECT user_id FROM users WHERE lang IS NULL OR lang NOT IN ('uz', 'ru') ORDER BY user_id")]
    print(f"recipients: {len(users)} (users who have not picked a language yet)")
    print("text:\n" + TEXT)
    if not send:
        print("\ndry run: nothing sent. Add --send to send.")
        return
    api = TelegramAPIServer.from_base(LOCAL_API_URL, is_local=True) if LOCAL_API_URL else None
    bot = Bot(BOT_TOKEN, session=AiohttpSession(api=api) if api else AiohttpSession())
    ok = blocked = failed = 0
    try:
        for i, uid in enumerate(users, 1):
            for attempt in range(3):
                try:
                    await bot.send_message(uid, TEXT)
                    ok += 1
                    break
                except TelegramRetryAfter as e:  # Telegram asks to slow down: wait and retry the same user
                    await asyncio.sleep(e.retry_after + 1)
                except TelegramForbiddenError:  # blocked the bot / deleted account
                    blocked += 1
                    break
                except TelegramBadRequest as e:  # chat not found and the like
                    logging.warning("user %s: %s", uid, e)
                    failed += 1
                    break
            if i % 50 == 0:
                print(f"{i}/{len(users)}: sent {ok}, blocked {blocked}, failed {failed}", flush=True)
            await asyncio.sleep(1 / PER_SECOND)
    finally:
        await bot.session.close()
    print(f"done: sent {ok}, blocked the bot {blocked}, failed {failed}, of {len(users)}")


if __name__ == "__main__":
    asyncio.run(main("--send" in sys.argv))
