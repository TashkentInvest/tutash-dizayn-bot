"""Sets how many non-residential objects each street has, from the official 1st-stage list (Excel). A FIXED figure:

    python tools/import_nonres_objects.py "Кўчалар_142_талик.xlsx"          # dry run: every street, its number, its match
    python tools/import_nonres_objects.py "Кўчалар_142_талик.xlsx" --save   # writes streets.nonres_objects

Only streets that have no number yet get one. Once written it never changes: this tool skips it, and the database
refuses any change (trigger trg_streets_nonres_objects_fixed). Excel: the first sheet with a district row
("... тумани") followed by street rows (№, name, number); the district's "Жами:" row is skipped, a street without a
number is left empty. Streets are matched like tools/import_object_plan.py.
"""
import os
import sys

sys.path.insert(0, os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
sys.stdout.reconfigure(encoding="utf-8")

import openpyxl  # noqa: E402

from bot import db  # noqa: E402
from tools.import_object_plan import match  # noqa: E402


def read_list(xlsx: str) -> list[tuple[str, str, int | None]]:
    for ws in openpyxl.load_workbook(xlsx, data_only=True).worksheets:
        rows, district = [], None
        for r in ws.iter_rows(values_only=True):
            r = list(r) + [None] * 3
            if isinstance(r[0], str) and r[0].strip().lower().endswith("тумани"):
                # match() compares the district's last word: "Бектемир тумани" -> "Бектемир"
                district = r[0].strip()[: -len("тумани")].strip()
            elif district and isinstance(r[0], (int, float)) and r[1] and not str(r[1]).strip().lower().startswith("жами"):
                n = r[2] if isinstance(r[2], (int, float)) else None
                rows.append((district, str(r[1]).strip(), int(n) if n is not None else None))
        if rows:
            print(f"varaq: {ws.title}")
            return rows
    sys.exit("Ro‘yxat topilmadi: tuman qatori (\"... тумани\") va ko‘cha qatorlari (№, nomi, soni) kerak.")


def main(xlsx: str, save: bool) -> None:
    db.init()
    rows = read_list(xlsx)
    result = match(rows)
    have = {r["id"]: r["nonres_objects"] for r in db.fetch_all("SELECT id, nonres_objects FROM streets")}
    seen: dict[int, list[str]] = {}
    for d, s, name, n in result:
        mark = "!! katalogda topilmadi" if not s else s["name"]
        if s and have.get(s["id"]) is not None:
            mark += f"  (o‘zgarmaydi: {have[s['id']]})"
        print(f"{(d['name'] if d else '?'):<22} {name:<38} {('—' if n is None else n):>6}  ->  {mark}")
        if s:
            seen.setdefault(s["id"], []).append(name)
    missing = [r for r in result if r[1] is None]
    twice = {sid: names for sid, names in seen.items() if len(names) > 1}
    print(f"\nro‘yxat: {len(rows)} ko‘cha, {sum(r[2] or 0 for r in rows)} obyekt | topilmadi: {len(missing)}")
    if twice:
        print("!! bir ko‘chaga ikki qator tushdi:", twice)
    if not save:
        print("dry run: nothing saved. Add --save.")
        return
    if missing or twice:
        sys.exit("Saqlanmadi: avval topilmagan yoki takror ko‘chalarni to‘g‘rilang.")
    n_new = 0
    for d, s, name, n in result:
        if n is not None and have.get(s["id"]) is None:
            db.execute("UPDATE streets SET nonres_objects=? WHERE id=? AND nonres_objects IS NULL", (n, s["id"]))
            n_new += 1
    print(f"saqlandi: {n_new} ko‘chaga yozildi (bundan keyin o‘zgarmaydi)")


if __name__ == "__main__":
    files = [a for a in sys.argv[1:] if not a.startswith("--")]
    if not files:
        sys.exit('usage: import_nonres_objects.py "Кўчалар_142_талик.xlsx" [--save]')
    main(files[0], "--save" in sys.argv)
