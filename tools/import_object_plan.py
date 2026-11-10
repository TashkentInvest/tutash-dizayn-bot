"""Sets how many objects (businesses) each street has, from the official design-code list (Excel).

    python tools/import_object_plan.py "ДИЗАЙН КОД.xlsx"          # dry run: prints every street and its match
    python tools/import_object_plan.py "ДИЗАЙН КОД.xlsx" --save   # writes streets.planned_objects

Excel: first sheet, column B district (merged cells), C street, D objects, data from row 6. Streets are matched to the
catalog by district and by the distinctive words of the name (Latin or Cyrillic, any case; the ring roads "Katta /
Kichik halqa yo'li" are TXAY / KXAY in the catalog). With --save every street of the catalog gets the number from the
list, and streets the list does not name get 0. These numbers are the "Jami" of the design-code svod.
"""
import os
import re
import sys

sys.path.insert(0, os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
sys.stdout.reconfigure(encoding="utf-8")

import openpyxl  # noqa: E402

from bot import db  # noqa: E402
from bot.i18n import latin  # noqa: E402

GENERIC = {"kochasi", "kocha", "shoh", "yoli", "tumani", "kochalari"}
# the list and the catalog spell a few names differently
ALIAS = {"lokomotiv": "lokomativ", "istiqlol": "istiqbol", "boyqaro": "bayqaro", "maxmud": "mahmud", "oxangaron": "ohangaron",
         "shaxrisabz": "shahrisabz", "dombrobod": "dombirobod"}


def key(s: str) -> str:
    s = latin(s or "").lower()
    for ch in "’'‘`ʻ":
        s = s.replace(ch, "")
    s = s.replace("kh", "x").replace("al ", "al")
    return re.sub(r"[^a-z0-9 ]+", " ", s)


def words(s: str) -> frozenset:
    k = key(s)
    for long, short in (("katta halqa", "txay"), ("kichik halqa", "kxay")):
        k = k.replace(long, short)
    return frozenset(ALIAS.get(w, w) for w in k.split() if w not in GENERIC and (len(w) > 1 or w.isdigit()))


def read_list(xlsx: str) -> list[tuple[str, str, int]]:
    ws = openpyxl.load_workbook(xlsx, data_only=True).worksheets[0]
    rows, district = [], None
    for r in ws.iter_rows(min_row=6, values_only=True):
        if r[1]:
            district = str(r[1]).strip()
        if r[2]:
            rows.append((district, str(r[2]).strip(), int(r[3] or 0)))
    return rows


def match(rows):
    """[(district row, street row or None, list name, count)]"""
    districts = db.fetch_all("SELECT id, name FROM districts")
    out = []
    for dname, sname, n in rows:
        dk = key(dname).replace(".", " ").split()
        d = next((d for d in districts if dk and (key(d["name"]).startswith(dk[-1][:5]) or dk[-1][:5] in key(d["name"]))), None)
        cand = db.fetch_all("SELECT id, name FROM streets WHERE district_id=?", (d["id"],)) if d else []
        w = words(sname)
        found = [s for s in cand if w and words(s["name"]) and (w <= words(s["name"]) or words(s["name"]) <= w)]
        if len(found) > 1:
            found = [s for s in found if words(s["name"]) == w] or found[:1]
        out.append((d, found[0] if found else None, sname, n))
    return out


def main(xlsx: str, save: bool) -> None:
    db.init()
    rows = read_list(xlsx)
    result = match(rows)
    missing = [r for r in result if r[1] is None]
    seen = {}
    for d, s, name, n in result:
        if s:
            seen.setdefault(s["id"], []).append(name)
    twice = {sid: names for sid, names in seen.items() if len(names) > 1}
    for d, s, name, n in result:
        print(f"{(d['name'] if d else '?'):<22} {name:<38} {n:>4}  ->  {s['name'] if s else '!! katalogda topilmadi'}")
    print(f"\nro‘yxat: {len(rows)} ko‘cha, {sum(r[2] for r in rows)} obyekt | topildi: {len(rows) - len(missing)} | topilmadi: {len(missing)}")
    if twice:
        print("!! bir ko‘chaga ikki qator tushdi:", twice)
    if save:
        if missing or twice:
            sys.exit("Saqlanmadi: avval topilmagan yoki takror ko‘chalarni to‘g‘rilang.")
        db.execute("UPDATE streets SET planned_objects=0")
        for d, s, name, n in result:
            db.execute("UPDATE streets SET planned_objects=? WHERE id=?", (n, s["id"]))
        total = db.fetch_all("SELECT SUM(planned_objects) FROM streets")[0][0]
        print(f"saqlandi: jami {total} obyekt")
    else:
        print("dry run: nothing saved. Add --save.")


if __name__ == "__main__":
    files = [a for a in sys.argv[1:] if not a.startswith("--")]
    if not files:
        sys.exit('usage: import_object_plan.py "ДИЗАЙН КОД.xlsx" [--save]')
    main(files[0], "--save" in sys.argv)
