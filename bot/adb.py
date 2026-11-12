"""The database for the bot's async code: every call runs on a small pool of worker threads, so a slow query (or a
PostgreSQL hiccup) never stops the event loop that serves all the other users.

    await adb.get_user(uid)                 # any function of db.py, awaited
    await adb.run(registration.step, uid)   # any other blocking function (one that reads the database, or files)

On PostgreSQL each worker has its own connection (db._local): queries of different users really run side by side.
"""
import asyncio
import functools
from concurrent.futures import ThreadPoolExecutor

from . import db

# at most this many connections to PostgreSQL from the bot (the server allows 100)
_pool = ThreadPoolExecutor(max_workers=8, thread_name_prefix="db")


async def run(fn, *args, **kwargs):
    return await asyncio.get_running_loop().run_in_executor(_pool, functools.partial(fn, *args, **kwargs))


def __getattr__(name: str):
    fn = getattr(db, name)  # looked up at call time: a test that replaces db.x is still honoured

    async def call(*args, **kwargs):
        return await run(fn, *args, **kwargs)

    call.__name__ = name
    return call
