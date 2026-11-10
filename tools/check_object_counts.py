"""Compares the object counts of the design-code Excel list with the street PDFs (pages after the cover).

    python tools/check_object_counts.py "D:/TG/ДИЗАЙН КОД.xlsx" [report.csv]

Excel: sheet 1, columns B district (merged), C street, D objects. Streets are matched to the catalog by district and by
the distinctive words of the name (Latin/Cyrillic, any case). Prints every street with both numbers and the difference.
"""
import csv
import os
import re
import sys

sys.path.insert(0, os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
sys.stdout.reconfigure(encoding="utf-8")

import fitz  # noqa: E402
import openpyxl  # noqa: E402

from bot import db  # noqa: E402
from bot.config import DATA_DIR  # noqa: E402
from bot.i18n import latin  # noqa: E402
from bot.objects import classify  # noqa: E402

GENERIC = {"kochasi", "kocha", "shoh", "yoli", "tumani", "kochalari"}
ALIAS = {"lokomotiv": "lokomativ", "istiqlol": "istiqbol", "boyqaro": "bayqaro", "maxmud": "mahmud", "oxangaron": "ohangaron", "shaxrisabz": "shahrisabz",
         "xorazmiy": "xorazmiy", "jarariq": "jarariq", "dombrobod": "dombirobod"}


def key(s: str) -> str:
    s = latin(s or "").lower().replace("’", "").replace("'", "").replace("‘", "").replace("`", "").replace("ʻ", "")
    s = s.replace("kh", "x").replace("al ", "al")
    return re.sub(r"[^a-z0-9 ]+", " ", s)


def words(s: str) -> frozenset:
    k = key(s)
    for long, short in (("katta halqa", "txay"), ("kichik halqa", "kxay")):  # ring roads: TXAY / KXAY in the catalog
        k = k.replace(long, short)
    return frozenset(ALIAS.get(w, w) for w in k.split() if w not in GENERIC and (len(w) > 1 or w.isdigit()))


def pdf_objects(street_id: int) -> tuple[int, list[str]]:
    """Object pages of the street, by the same rule the bot uses (bot/objects.py): concept pages with text; a picture-only
    PDF counts only when the street has no text concept PDF."""
    found = []
    for f in db.fetch_all("SELECT path, file_name FROM street_files WHERE street_id=? ORDER BY sort_order, id", (street_id,)):
        if not f["file_name"].lower().endswith(".pdf"):
            continue
        with fitz.open(DATA_DIR / f["path"]) as d:
            kind, pages = classify(d)
        found.append((f["file_name"], kind, len(pages)))
    has_text = any(k == "text" and n for _, k, n in found)
    total, names = 0, []
    for name, kind, n in found:
        if kind == "images" and has_text:
            names.append(f"{name} (render, hisobga olinmadi)")
            continue
        total += n
        names.append(f"{name} ({n}{', faqat rasm' if kind == 'images' else ''})")
    return total, names


def main(xlsx: str, out: str | None) -> None:
    ws = openpyxl.load_workbook(xlsx, data_only=True).worksheets[0]
    rows, district = [], None
    for r in ws.iter_rows(min_row=6, values_only=True):
        if r[1]:
            district = str(r[1]).strip()
        if r[2]:
            rows.append((district, str(r[2]).strip(), int(r[3] or 0)))

    districts = db.fetch_all("SELECT id, name FROM districts")
    used, report = set(), []
    for dname, sname, n in rows:
        dk = key(dname).replace(".", " ").split()
        d = next((d for d in districts if dk and (key(d["name"]).startswith(dk[-1][:5]) or dk[-1][:5] in key(d["name"]))), None)
        cand = db.fetch_all("SELECT id, name FROM streets WHERE district_id=?", (d["id"],)) if d else []
        w = words(sname)
        match = [s for s in cand if w and (w <= words(s["name"]) or words(s["name"]) <= w) and words(s["name"])]
        if len(match) > 1:  # prefer the exact word set
            exact = [s for s in match if words(s["name"]) == w]
            match = exact or match[:1]
        if match:
            s = match[0]
            used.add(s["id"])
            pages, files = pdf_objects(s["id"])
            report.append((d["name"], sname, n, s["name"], pages, pages - n, "; ".join(files)))
        else:
            report.append((d["name"] if d else dname, sname, n, "— katalogda topilmadi", None, None, ""))

    # streets with a PDF that the Excel list does not mention
    extra = []
    for s in db.fetch_all("SELECT s.id, s.name, d.name AS district FROM streets s JOIN districts d ON d.id=s.district_id "
                          "WHERE s.id IN (SELECT street_id FROM street_files)"):
        if s["id"] not in used:
            pages, files = pdf_objects(s["id"])
            extra.append((s["district"], "— Excel’da yo‘q", None, s["name"], pages, None, "; ".join(files)))

    ok = sum(1 for r in report if r[5] == 0)
    off = [r for r in report if r[5] not in (0, None)]
    missing = [r for r in report if r[4] is None]
    print(f"Excel: {len(rows)} ko‘cha, {sum(r[2] for r in rows)} obyekt")
    print(f"Mos keldi: {ok} ko‘cha | farq bor: {len(off)} | katalogda topilmadi: {len(missing)} | Excel’da yo‘q PDF: {len(extra)}")
    print(f"PDF bo‘yicha (topilganlar): {sum(r[4] for r in report if r[4] is not None)} obyekt; Excel shu ko‘chalar uchun: "
          f"{sum(r[2] for r in report if r[4] is not None)}")
    for title, items in (("FARQ BOR", off), ("KATALOGDA TOPILMADI", missing), ("EXCEL’DA YO‘Q", extra)):
        if items:
            print(f"\n--- {title} ---")
            for r in items:
                print(f"{r[0]:<20} | {r[1]:<34} | Excel {str(r[2]):>4} | PDF {str(r[4]):>4} | farq {str(r[5]):>4} | {r[3]}")
    if out:
        with open(out, "w", encoding="utf-8-sig", newline="") as fh:
            wr = csv.writer(fh, delimiter=";")
            wr.writerow(["Tuman", "Excel ko‘cha", "Excel obyekt", "Katalog ko‘cha", "PDF obyekt (sahifa-1)", "Farq (PDF-Excel)", "PDF fayl"])
            wr.writerows(report + extra)


if __name__ == "__main__":
    main(sys.argv[1], sys.argv[2] if len(sys.argv) > 2 else None)
