"""Street shapes from KMZ / KML files (one Folder = one district, one Placemark LineString = one street).

    python tools/import_street_lines.py CHILONZOR.kmz YAKKASAROY.kmz          # dry run: every line and its catalog street
    python tools/import_street_lines.py CHILONZOR.kmz YAKKASAROY.kmz --save   # saves streets.path (+ start/end = its ends)

The line replaces the straight start -> end of the official list for those streets: the map draws the real shape and the
"staff stands on the street" check measures to it. Streets are matched like tools/import_object_plan.py (district from the
folder name, street by its distinctive words, Latin or Cyrillic).
"""
import json
import os
import sys
import xml.etree.ElementTree as ET
import zipfile

sys.path.insert(0, os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
sys.stdout.reconfigure(encoding="utf-8")

from bot import db  # noqa: E402
from tools.import_object_plan import match  # noqa: E402


def read(path: str) -> list[tuple[str, str, list[list[float]]]]:
    """[(district, street, [[lat, lon], ...])] from a .kmz (zip with doc.kml) or a .kml"""
    raw = zipfile.ZipFile(path).read("doc.kml") if path.lower().endswith(".kmz") else open(path, "rb").read()
    root = ET.fromstring(raw)
    ns = root.tag.split("}")[0] + "}" if root.tag.startswith("{") else ""
    out = []
    for folder in root.iter(ns + "Folder"):
        district = (folder.findtext(ns + "name") or "").strip()
        for pm in folder.iter(ns + "Placemark"):
            coords = pm.find(".//" + ns + "LineString/" + ns + "coordinates")
            if coords is None or not (coords.text or "").strip():
                continue
            line = []
            for p in coords.text.split():
                lon, lat = (float(x) for x in p.split(",")[:2])  # KML: lon,lat[,alt]
                line.append([round(lat, 7), round(lon, 7)])
            out.append((district, (pm.findtext(ns + "name") or "").strip(), line))
    return out


def main(files: list[str], save: bool) -> None:
    db.init()
    lines = [x for f in files for x in read(f)]
    result = match([(d, s, 0) for d, s, _ in lines])
    missing = [r for r in result if r[1] is None]
    seen = {}
    for (d, s, _), (dd, st, *_rest) in zip(lines, result):
        print(f"{d:<12} {s:<32} {len(_):>3} nuqta  ->  {dd['name'] if dd else '?'} · {st['name'] if st else '!! katalogda topilmadi'}")
        if st:
            seen.setdefault(st["id"], []).append(s)
    twice = {k: v for k, v in seen.items() if len(v) > 1}
    print(f"\nchiziqlar: {len(lines)} | topildi: {len(lines) - len(missing)} | topilmadi: {len(missing)}")
    if twice:
        print("!! bir ko‘chaga ikki chiziq tushdi:", twice)
    if not save:
        print("dry run: nothing saved. Add --save.")
        return
    if missing or twice:
        sys.exit("Saqlanmadi: avval topilmagan yoki takror chiziqlarni to‘g‘rilang.")
    for (_, _, line), (_, st, *_rest) in zip(lines, result):
        db.execute("UPDATE streets SET path=?, start_lat=?, start_lon=?, end_lat=?, end_lon=? WHERE id=?",
                   (json.dumps(line), line[0][0], line[0][1], line[-1][0], line[-1][1], st["id"]))
    print(f"saqlandi: {len(lines)} ko‘chaning haqiqiy chizig‘i")


if __name__ == "__main__":
    files = [a for a in sys.argv[1:] if not a.startswith("--")]
    if not files:
        sys.exit("usage: import_street_lines.py FILE.kmz [FILE2.kmz ...] [--save]")
    main(files, "--save" in sys.argv)
