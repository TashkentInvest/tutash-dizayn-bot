import sys, asyncio
sys.path.insert(0, r"D:\Projects\Tutash_bot")
sys.stdout.reconfigure(encoding="utf-8")
from bot import catalog, db
from bot.config import BOT_TOKEN
from bot.main import districts_kb, streets_kb
from aiogram import Bot
ds = catalog.districts()
print(len(ds), [d.name for d in ds][:3])
d = catalog.district(12); print([ (s.name, [f.name for f in catalog.files(s)][:1]) for s in catalog.streets(d)][:3])
kb = streets_kb(d); print(len(kb.inline_keyboard), kb.inline_keyboard[0][0])
big = [f for dd in ds for s in catalog.streets(dd) for f in catalog.files(s) if f.stat().st_size > 50*2**20]
print("files >50MB:", len(big), "of", sum(len(catalog.files(s)) for dd in ds for s in catalog.streets(dd)))
async def go():
    b = Bot(BOT_TOKEN); me = await b.get_me(); print("bot:", me.username); await b.session.close()
asyncio.run(go())
