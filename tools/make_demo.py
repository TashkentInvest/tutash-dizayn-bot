"""Build a throw-away demo copy of the bot database (fake users/downloads/photos) for checking the dashboard.
Usage: python tools/make_demo.py  ->  tools/demo/bot.db + tools/demo/uploads/
"""
import os, random, shutil, sqlite3, sys
from datetime import datetime, timedelta, timezone

sys.path.insert(0, os.path.join(os.path.dirname(__file__), ".."))
os.environ["DB_PATH"] = os.path.abspath(os.path.join(os.path.dirname(__file__), "demo", "bot.db"))
os.makedirs(os.path.dirname(os.environ["DB_PATH"]), exist_ok=True)
if os.path.exists(os.environ["DB_PATH"]):
    os.remove(os.environ["DB_PATH"])
from bot import catalog, db  # noqa: E402

db.init()
random.seed(7)
now = datetime.now(timezone.utc)
fmt = "%Y-%m-%d %H:%M:%S"
names = ["Karimov Alisher Ravshanovich", "Yusupova Dilfuza Akmalovna", "Rahimov Jasur Bahodirovich", "Tursunov Sardor Olimovich",
         "Abdullayeva Malika Qodirovna", "Nazarov Bekzod Ilhomovich", "Ergashev Otabek Shuhratovich", "Saidova Nodira Karimovna"]
uploads = os.path.join(os.path.dirname(os.environ["DB_PATH"]), "uploads")
shutil.rmtree(uploads, ignore_errors=True)
try:
    from PIL import Image, ImageDraw
except ImportError:
    Image = None
streets = [(d.name, s.name, f.name) for d in catalog.districts() for s in catalog.streets(d) for f in catalog.files(s)]
c = sqlite3.connect(os.environ["DB_PATH"])
for i in range(1, 41):
    uid = 1000 + i
    first = now - timedelta(days=random.randint(0, 29), hours=random.randint(0, 23))
    reg = random.random() < 0.7
    row = (uid, f"user{i}" if random.random() < 0.8 else None, f"Name{i}", None, "uz", first.strftime(fmt), (first + timedelta(hours=3)).strftime(fmt), random.randint(1, 4))
    c.execute("INSERT INTO users (user_id, username, first_name, last_name, language_code, first_seen, last_seen, start_count) VALUES (?,?,?,?,?,?,?,?)", row)
    if reg:
        c.execute("UPDATE users SET full_name=?, phone=?, latitude=?, longitude=?, registered_at=? WHERE user_id=?",
                  (random.choice(names), f"+99890{random.randint(1000000, 9999999)}", 41.2 + random.random() * .2, 69.1 + random.random() * .25,
                   (first + timedelta(minutes=5)).strftime(fmt), uid))
        for n in (1, 2):
            rel = f"{uid}/{n}_demo.jpg"
            if Image:
                os.makedirs(os.path.join(uploads, str(uid)), exist_ok=True)
                im = Image.new("RGB", (640, 480), (random.randint(40, 200), random.randint(40, 200), random.randint(40, 200)))
                ImageDraw.Draw(im).text((20, 20), f"demo photo {n} / user {uid}", fill="white")
                im.save(os.path.join(uploads, rel))
            c.execute("INSERT INTO user_photos (user_id, file_id, file_unique_id, path) VALUES (?,?,?,?)", (uid, f"f{uid}{n}", f"u{uid}{n}", rel))
    for _ in range(random.randint(0, 8)):
        d, s, f = random.choice(streets)
        c.execute("INSERT INTO downloads (user_id, district, street, file_name, sent_at) VALUES (?,?,?,?,?)",
                  (uid, d, s, f, (first + timedelta(days=random.randint(0, 3), minutes=random.randint(0, 600))).strftime(fmt)))
c.commit()
print("demo ready:", os.environ["DB_PATH"], "| photos:", bool(Image))
