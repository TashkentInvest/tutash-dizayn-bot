import os, shutil, sys, tempfile, time
from types import SimpleNamespace as NS
ROOT = os.path.abspath(os.path.join(os.path.dirname(__file__), ".."))
tmp = tempfile.mkdtemp(); shutil.copy(os.path.join(ROOT, "bot.db"), os.path.join(tmp, "bot.db"))
os.environ.update(DB_PATH=os.path.join(tmp, "bot.db"), LINK_SECRET="x"); sys.path.insert(0, ROOT)
from bot import catalog, db
db.init()
u = NS(id=999, username=None, first_name="u", last_name=None, language_code="uz")
def t(name, fn, n=300):
    a = time.perf_counter()
    for _ in range(n): fn()
    print(f"{name:28s} {(time.perf_counter()-a)/n*1000:7.2f} ms")
import sqlite3
t("bare sqlite3.connect+close", lambda: sqlite3.connect(os.environ["DB_PATH"]).close())
t("db._conn() (connect+pragma)", lambda: db._conn().close())
t("get_upload_speed (read)", db.get_upload_speed)
t("log_download (write+commit)", lambda: db.log_download(999, "d", "s", "f"))
t("is_registered (cached)", lambda: db.is_registered(999))
t("catalog.districts cached", catalog.districts, 5000)
db.set_cached("k", 1, 1, "x"); t("get_cached", lambda: db.get_cached("k", 1, 1))
t("touch_user throttled", lambda: db.touch_user(u), 5000)
shutil.rmtree(tmp, ignore_errors=True)
