"""Sets each street's length (km) from the official street list (Excel), for the dashboard map.

    python tools/import_street_lengths.py "142 улицы.xlsx"          # dry run: prints every street, its km and its match
    python tools/import_street_lengths.py "142 улицы.xlsx" --save   # writes streets.length_km

Excel: the first sheet that has rows like ("Бектемир тумани", ...) for a district and then (1, "street", km, ...) for its
streets. Streets are matched to the catalog by district and name, the same way as tools/import_object_plan.py. A row
without a km is skipped (the street keeps what it has); streets the list does not name are left alone.
"""
import os
import sys

sys.path.insert(0, os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
sys.stdout.reconfigure(encoding="utf-8")

import openpyxl  # noqa: E402

from bot import db  # noqa: E402
from tools.import_object_plan import match  # noqa: E402


def read_list(xlsx: str) -> list[tuple[str, str, float | None]]:
    for ws in openpyxl.load_workbook(xlsx, data_only=True).worksheets:
        rows, district = [], None
        for r in ws.iter_rows(values_only=True):
            r = list(r) + [None] * 3
            if isinstance(r[0], str) and r[0].strip().lower().endswith("тумани"):
                # match() compares the district's last word: "Бектемир тумани" -> "Бектемир"
                district = r[0].strip()[: -len("тумани")].strip()
            elif district and isinstance(r[0], (int, float)) and r[1]:
                km = r[2] if isinstance(r[2], (int, float)) else None
                rows.append((district, str(r[1]).strip(), float(km) if km is not None else None))
        if rows:
            print(f"varaq: {ws.title}")
            return rows
    sys.exit("Ro‘yxat topilmadi: tuman qatori (\"... тумани\") va ko‘cha qatorlari (№, nomi, km) kerak.")


def main(xlsx: str, save: bool) -> None:
    db.init()
    rows = read_list(xlsx)
    result = match(rows)
    seen: dict[int, list[str]] = {}
    for d, s, name, km in result:
        mark = "!! katalogda topilmadi" if not s else s["name"]
        print(f"{(d['name'] if d else '?'):<22} {name:<38} {('—' if km is None else km):>6}  ->  {mark}")
        if s:
            seen.setdefault(s["id"], []).append(name)
    missing = [r for r in result if r[1] is None]
    twice = {sid: names for sid, names in seen.items() if len(names) > 1}
    print(f"\nro‘yxat: {len(rows)} ko‘cha, {sum(r[2] or 0 for r in rows):.2f} km | topilmadi: {len(missing)}")
    if twice:
        print("!! bir ko‘chaga ikki qator tushdi:", twice)
    if not save:
        print("dry run: nothing saved. Add --save.")
        return
    if missing or twice:
        sys.exit("Saqlanmadi: avval topilmagan yoki takror ko‘chalarni to‘g‘rilang.")
    n = 0
    for d, s, name, km in result:
        if km is not None:
            db.execute("UPDATE streets SET length_km=? WHERE id=?", (km, s["id"]))
            n += 1
    print(f"saqlandi: {n} ko‘chaga uzunlik yozildi")


if __name__ == "__main__":
    files = [a for a in sys.argv[1:] if not a.startswith("--")]
    if not files:
        sys.exit('usage: import_street_lengths.py "142 улицы.xlsx" [--save]')
    main(files[0], "--save" in sys.argv)
