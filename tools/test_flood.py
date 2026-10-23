"""Flood-protection checks (no Telegram, no network):  python tools/test_flood.py"""
import asyncio, os, sys, time
from types import SimpleNamespace as NS

ROOT = os.path.abspath(os.path.join(os.path.dirname(__file__), ".."))
sys.path.insert(0, ROOT); os.environ.setdefault("LINK_SECRET", "x"); sys.stdout.reconfigure(encoding="utf-8")
from aiogram.methods import GetUpdates, SendMessage  # noqa: E402
from bot.guard import Guard, OutgoingLimiter  # noqa: E402

ok = True
def check(name, cond, extra=""):
    global ok
    ok &= bool(cond)
    print(("PASS " if cond else "FAIL ") + name + (f"   [{extra}]" if extra else ""))

class Event:
    def __init__(self): self.answers = []
    async def answer(self, text=None, **kw): self.answers.append(text)

async def run_events(guard, uid, n, handler_delay=0.0):
    done = 0
    async def handler(event, data):
        nonlocal done
        if handler_delay: await asyncio.sleep(handler_delay)
        done += 1
    events = [Event() for _ in range(n)]
    await asyncio.gather(*[guard(handler, e, {"event_from_user": NS(id=uid)}) for e in events])
    return done, events

async def main():
    # 1. one user hammering: only a small burst gets through, the rest is dropped silently
    g = Guard(burst=6, per_second=1.5, strikes=12, mute_seconds=0.4, max_inflight=1000)
    done, events = await run_events(g, 1, 300)
    check("one user, 300 presses in an instant: only the burst is processed", done <= 7, f"processed {done}/300")
    check("... and the flooder is muted (a single notice, not 300 replies)", sum(1 for e in events if e.answers) == 1)
    done2, _ = await run_events(g, 1, 20)
    check("muted user: further presses cost nothing", done2 == 0, f"processed {done2}/20")

    # 2. another user is not affected by the flooder
    done3, _ = await run_events(g, 2, 3)
    check("a normal user next to a flooder is served", done3 == 3)

    # 3. the mute ends by itself
    await asyncio.sleep(1.5)   # mute (0.4 s here, 120 s in production) is over and the bucket has refilled
    done4, _ = await run_events(g, 1, 2)
    check("mute expires, user can use the bot again", done4 == 2, f"processed {done4}/2")

    # 4. normal pace is never refused (1 press per second for 10 s of 'virtual' time)
    g2 = Guard(burst=6, per_second=1.5, strikes=12, mute_seconds=60, max_inflight=1000)
    served = 0
    async def h(event, data):
        nonlocal served; served += 1
    for _ in range(12):
        await g2(h, Event(), {"event_from_user": NS(id=3)}); await asyncio.sleep(0.7)
    check("a user pressing every 0.7 s is never refused", served == 12, f"served {served}/12")

    # 5. 500 different users at once: everybody is served (fairness), memory stays bounded
    g3 = Guard(burst=6, per_second=1.5, strikes=12, mute_seconds=60, max_inflight=10000)
    count = 0
    async def hc(event, data):
        nonlocal count; count += 1
    await asyncio.gather(*[g3(hc, Event(), {"event_from_user": NS(id=10_000 + i)}) for i in range(500)])
    check("500 different users at once: all 500 are served", count == 500, f"{count}/500")

    # 6. global saturation: slow handlers pile up -> extra work is shed with a notice, not queued without limit
    g4 = Guard(burst=6, per_second=1.5, strikes=12, mute_seconds=60, max_inflight=20)
    running, peak, shed = 0, 0, 0
    async def slow(event, data):
        nonlocal running, peak
        running += 1; peak = max(peak, running); await asyncio.sleep(0.3); running -= 1
    evs = [Event() for _ in range(200)]
    await asyncio.gather(*[g4(slow, e, {"event_from_user": NS(id=20_000 + i)}) for i, e in enumerate(evs)])
    shed = sum(1 for e in evs if e.answers)
    check("200 users at once with a cap of 20: never more than 20 handlers run", peak <= 20, f"peak {peak}")
    check("... the other 180 get a 'busy' notice instead of piling up", shed == 180, f"{shed} notified")

    # 7. outgoing: our calls to Telegram never exceed the configured rate
    lim = OutgoingLimiter(per_second=50)
    async def fake_request(bot, method): return "ok"
    t0 = time.monotonic()
    await asyncio.gather(*[lim(fake_request, None, SendMessage(chat_id=1, text="x")) for _ in range(100)])
    dt = time.monotonic() - t0
    check("100 outgoing calls at a 50/s limit take >= ~2 s (not a burst)", dt >= 1.9, f"{dt:.2f}s = {100/dt:.0f}/s")
    t0 = time.monotonic()
    await asyncio.gather(*[lim(fake_request, None, GetUpdates()) for _ in range(100)])
    check("long polling (GetUpdates) is not throttled", time.monotonic() - t0 < 0.5)

asyncio.run(main())
print("\nALL OK" if ok else "\nSOME CHECKS FAILED")
sys.exit(0 if ok else 1)
