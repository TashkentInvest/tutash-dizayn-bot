"""Flood protection: nobody (one user, many users, a script) should be able to slow the bot down or knock it over.

Three layers, all cheap and in memory:
  * Guard            incoming: per-user token bucket, temporary mute for persistent flooders, global cap of
                     handlers running at once (extra work is dropped instead of queued without limit).
  * OutgoingLimiter  outgoing: keeps our calls to Telegram under its ~30 requests/second limit, so the bot is
                     never put into Telegram's flood-wait and everybody keeps getting answers.
  * (nginx / systemd layers live in deploy/.)
"""
import asyncio
import logging
import time

from aiogram import BaseMiddleware
from aiogram.client.session.middlewares.base import BaseRequestMiddleware
from aiogram.methods import GetUpdates
from aiogram.types import CallbackQuery

from .config import (
    GUARD_BURST,
    GUARD_MUTE_SECONDS,
    GUARD_MUTE_STRIKES,
    GUARD_RATE,
    MAX_INFLIGHT,
    TG_OUT_PER_SECOND,
)

TXT_MUTED = "⛔ Жуда тез-тез босдингиз. Бир неча дақиқадан кейин қайта уриниб кўринг."
TXT_BUSY = "⏳ Бот банд, бироздан кейин қайта уриниб кўринг."


class Guard(BaseMiddleware):
    def __init__(self, burst=GUARD_BURST, per_second=GUARD_RATE, strikes=GUARD_MUTE_STRIKES,
                 mute_seconds=GUARD_MUTE_SECONDS, max_inflight=MAX_INFLIGHT) -> None:
        self.burst, self.per_second, self.strikes = burst, per_second, strikes
        self.mute_seconds, self.max_inflight = mute_seconds, max_inflight
        self._state: dict[int, list] = {}  # user id -> [tokens, last refill, strikes, strike window start, muted until]
        self._inflight = 0

    async def __call__(self, handler, event, data):
        user = data.get("event_from_user")
        if user is None:
            return await handler(event, data)
        now = time.monotonic()
        if len(self._state) > 20000:  # bounded memory: forget users who have been quiet for a while
            self._state = {k: v for k, v in self._state.items() if now - v[1] < 300 or v[4] > now}
        st = self._state.setdefault(user.id, [float(self.burst), now, 0, now, 0.0])

        if now < st[4]:  # muted: no work, no reply (a reply would cost an API call per flooded press)
            return None
        st[0] = min(float(self.burst), st[0] + (now - st[1]) * self.per_second)
        st[1] = now
        if st[0] < 1:  # over the allowed rate: drop silently, count a strike
            if now - st[3] > 30:
                st[2], st[3] = 0, now
            st[2] += 1
            if st[2] >= self.strikes:
                st[2], st[4] = 0, now + self.mute_seconds
                logging.warning("user %s muted for %ss (flooding)", user.id, self.mute_seconds)
                return await self._notice(event, TXT_MUTED)
            return None
        st[0] -= 1

        if self._inflight >= self.max_inflight:  # the whole bot is saturated: shed load, do not queue forever
            return await self._notice(event, TXT_BUSY)
        self._inflight += 1
        try:
            return await handler(event, data)
        finally:
            self._inflight -= 1

    @staticmethod
    async def _notice(event, text: str):
        try:
            if isinstance(event, CallbackQuery):
                await event.answer(text, show_alert=True)
            else:
                await event.answer(text)
        except Exception:
            pass


class OutgoingLimiter(BaseRequestMiddleware):
    """Spaces our requests to Telegram evenly (default 25/s). Long polling (GetUpdates) is not counted."""

    def __init__(self, per_second: float = TG_OUT_PER_SECOND) -> None:
        self.interval = 1.0 / per_second
        self._next = 0.0
        self._lock = asyncio.Lock()

    async def __call__(self, make_request, bot, method):
        if not isinstance(method, GetUpdates):
            async with self._lock:
                now = time.monotonic()
                wait = self._next - now
                self._next = max(now, self._next) + self.interval
            if wait > 0:
                await asyncio.sleep(wait)
        return await make_request(bot, method)
