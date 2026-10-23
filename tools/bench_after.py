"""Hot-path timing of one 'press a street button', on a COPY of the DB (same method the load review used).
python tools/bench_after.py"""
import asyncio, os, shutil, sqlite3, sys, tempfile, time
from types import SimpleNamespace as NS

ROOT = os.path.abspath(os.path.join(os.path.dirname(__file__), ".."))
tmp = tempfile.mkdtemp()
shutil.copy(os.path.join(ROOT, "bot.db"), os.path.join(tmp, "bot.db"))
os.environ.update(DB_PATH=os.path.join(tmp, "bot.db"), LINK_SECRET="x")
sys.path.insert(0, ROOT)
sys.stdout.reconfigure(encoding="utf-8")
from bot import catalog, db  # noqa: E402

db.init()
c = sqlite3.connect(os.environ["DB_PATH"])
for i in range(20000):  # 20,000 synthetic users like the review
    c.execute("insert or ignore into users (user_id, first_name, registered_at, consent_at) values (?,?,?,?)", (10_000_000 + i, "u", "2026-01-01 00:00:00", "2026-01-01 00:00:00"))
c.commit()
d = catalog.districts()[0]; s = next(x for x in catalog.streets(d) if catalog.files(x)); f = catalog.files(s)[0]
users = [NS(id=10_000_000 + i, username=None, first_name="u", last_name=None, language_code="uz") for i in range(500)]
key = f.relative_to(catalog.DATA_DIR).as_posix(); st = f.stat()
db.set_cached(key, st.st_size, int(st.st_mtime), "FID")

def press(u):
    db.touch_user(u)                       # middleware (throttled)
    db.is_registered(u.id)                 # gate (cached)
    db.is_registered(u.id)
    catalog.district(d.num); catalog.street(d, s.num); catalog.files(s)
    db.get_cached(key, st.st_size, int(st.st_mtime))
    db.get_upload_speed()
    db.log_download(u.id, d.name, s.name, f.name)   # the one real write per press

n = 2000
t = time.perf_counter()
for i in range(n): press(users[i % len(users)])
dt = time.perf_counter() - t
print(f"sequential: {n/dt:,.0f} presses/s  ({dt/n*1000:.2f} ms each)")

async def conc():
    lag = 0.0
    async def beat():
        nonlocal lag
        while True:
            t0 = time.perf_counter(); await asyncio.sleep(0.01); lag = max(lag, time.perf_counter() - t0 - 0.01)
    hb = asyncio.create_task(beat())
    async def one(i):
        for k in range(20): press(users[(i * 20 + k) % len(users)]); await asyncio.sleep(0)
    t = time.perf_counter()
    await asyncio.gather(*[one(i) for i in range(50)])
    dt = time.perf_counter() - t; hb.cancel()
    print(f"50 concurrent users x 20 presses: {1000/dt:,.0f} presses/s, max event-loop lag {lag*1000:.0f} ms")
asyncio.run(conc())
shutil.rmtree(tmp, ignore_errors=True)
