"""Set the contact phone of every district (Chamber of Commerce representatives), from the table the customer sent.
Run against the database in DB_PATH (default: ./bot.db):   python tools/set_district_phones.py
Safe to run again. Later changes are made in the dashboard (Katalog -> Tumanlar -> Murojaat telefoni)."""
import os, sys
sys.path.insert(0, os.path.join(os.path.dirname(__file__), ".."))
sys.stdout.reconfigure(encoding="utf-8")
from bot import db

PHONES = {  # district as named in the catalog -> 9 digits after +998
    "Бектемир тумани": "992012272",       # Бектемирский район
    "Мирзо Улуғбек тумани": "909756080",  # Мирзо-Улугбекский район
    "Миробод тумани": "940053053",        # Мирабадский район
    "Олмазор тумани": "998783522",        # Алмазарский район
    "Сергели тумани": "901170247",        # Сергелийский район
    "Учтепа тумани": "901007676",         # Учтепинский район
    "Чилонзор тумани": "908130506",       # Чиланзарский район
    "Шайхонтоҳур тумани": "901170247",    # Шайхантахурский район
    "Юнусобод тумани": "999075300",       # Юнусабадский район
    "Яккасарой тумани": "908075877",      # Яккасарайский район
    "Янгиҳаёт тумани": "992012272",       # Янгихаётский район
    "Яшнобод тумани": "999075300",        # Яшнабадский район
}

db.init()
missing = []
for name, digits in PHONES.items():
    rows = db.fetch_all("SELECT id FROM districts WHERE name = ?", (name,))
    if len(rows) != 1:
        missing.append(name)
        continue
    db.execute("UPDATE districts SET phone = ? WHERE id = ?", ("+998" + digits, rows[0]["id"]))
print(f"set {len(PHONES) - len(missing)} of {len(PHONES)} phones")
for name in missing:
    print("NOT FOUND in the catalog:", name)
