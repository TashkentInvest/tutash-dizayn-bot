"""Adds the district staff to the bot database from a local JSON file. Safe to run again: a phone already present is
updated, never duplicated. Each person's streets are matched to the catalog (shown first in their Mini App).

The list holds people's names and phone numbers, so it is NOT in the repository (which is public): keep it as
staff.local.json next to the project (*.local.json is git-ignored) and copy it to the server by hand.
    [{"district": "Bektemir", "streets": "Xusayn Bayqaro ko‘chasi", "name": "...", "phone": "90 123 45 67"}, ...]

    venv/bin/python tools/seed_staff.py staff.local.json            # dry run: prints the street matches
    venv/bin/python tools/seed_staff.py staff.local.json --save
"""
import json
import os
import re
import sys

sys.path.insert(0, os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
sys.stdout.reconfigure(encoding="utf-8")

from bot import db  # noqa: E402
from bot.i18n import latin  # noqa: E402
from bot.registration import normalize_phone  # noqa: E402

GENERIC = {"kochasi", "kocha", "kuchasi", "shoh", "yoli", "tumani", "kochalari", "massivi", "mavzesi", "1", "2"}


def key(s: str) -> str:
    """Comparable form: Latin, lower case, no apostrophes, x/h and kh unified."""
    s = latin(s).lower()
    s = re.sub(r"[‘’'`ʻʼ]", "", s).replace("kh", "x")
    return re.sub(r"[^a-z0-9 ]+", " ", s)


def words(s: str) -> set[str]:
    return {w for w in key(s).split() if len(w) > 2 and w not in GENERIC}


def main(path: str, save: bool) -> None:
    db.init()
    staff = [(r["district"], r.get("streets") or "", r["name"], str(r["phone"])) for r in json.load(open(path, encoding="utf-8"))]
    districts = db.fetch_all("SELECT id, name FROM districts")
    for dname, streets_note, name, raw_phone in staff:
        d = next((d for d in districts if key(d["name"]).split()[0:2] == key(dname).split()[0:2]
                  or key(d["name"]).startswith(key(dname).split()[0])), None)
        phone = normalize_phone(raw_phone)
        if not d or not phone:
            print(f"!! {dname} / {name}: district or phone not recognised ({d and d['name']}, {raw_phone})")
            continue
        # every named street (split on "–" / ",") matched to catalog streets of the district by its distinctive words
        wanted = [p for p in re.split(r"\s+[–-]\s+|,\s*", streets_note) if p.strip()]
        catalog = db.fetch_all("SELECT id, name FROM streets WHERE district_id=? ORDER BY sort_order", (d["id"],))
        matched = []
        for w in wanted:
            ww = words(w)
            for s in catalog:
                if ww and ww <= words(s["name"]) and s["id"] not in matched:
                    matched.append(s["id"])
        names = [s["name"] for s in catalog if s["id"] in matched]
        print(f"{d['name']:<24} {name:<12} {phone}  ->  {', '.join(names) or '— ko‘cha topilmadi (admin panelda tanlanadi)'}")
        if save:
            db.execute(
                "INSERT INTO staff (full_name, phone, district_id, streets_note, priority_streets) VALUES (?, ?, ?, ?, ?) "
                "ON CONFLICT(phone) DO UPDATE SET full_name=excluded.full_name, district_id=excluded.district_id, "
                "streets_note=excluded.streets_note, priority_streets=excluded.priority_streets",
                (name, phone, d["id"], streets_note, json.dumps(matched)),
            )
    print("saved" if save else "\ndry run: nothing saved. Add --save.")


if __name__ == "__main__":
    files = [a for a in sys.argv[1:] if not a.startswith("--")]
    if not files:
        sys.exit("usage: seed_staff.py staff.local.json [--save]")
    main(files[0], "--save" in sys.argv)
