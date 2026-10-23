import sys, urllib.request, urllib.error
sys.path.insert(0, r"D:\Projects\Tutash_bot"); sys.stdout.reconfigure(encoding="utf-8")
from bot import catalog
from bot.web import link_for
big = max((f for d in catalog.districts() for s in catalog.streets(d) for f in catalog.files(s)), key=lambda f: f.stat().st_size)
u = link_for(big); print(u, big.stat().st_size)
r = urllib.request.urlopen(u); n = 0
while (b := r.read(1<<20)): n += len(b)
print("downloaded", n, r.headers["Content-Disposition"])
try: urllib.request.urlopen(u.replace("/f/", "/f/0000"))
except urllib.error.HTTPError as e: print("bad sig ->", e.code)
