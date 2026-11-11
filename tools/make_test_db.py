"""Builds tests/fixtures/bot.sqlite: the bot's schema and a small sample catalog, NO people.

The test suites (tools/test_bot_fixes.py, web/tests) copy this file instead of the real bot.db, so they run anywhere
(CI included) and never touch anyone's name or phone number.   python tools/make_test_db.py
"""
import os
import sys
import tempfile
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
OUT = ROOT / "tests" / "fixtures" / "bot.sqlite"

DISTRICTS = ["Бектемир тумани", "Мирзо Улуғбек тумани", "Миробод тумани", "Чилонзор тумани", "Янгиҳаёт тумани", "Яшнобод тумани",
             "Яккасарой тумани", "Юнусобод тумани", "Шайхонтоҳур тумани", "Олмазор тумани", "Сергели тумани", "Учтепа тумани"]
STREETS = ["Охангарон шоҳ кўчаси", "Фарғона йўли кўчаси", "Хусайн Байқаро кўчаси"]


def main() -> None:
    OUT.parent.mkdir(parents=True, exist_ok=True)
    tmp = Path(tempfile.mkdtemp()) / "bot.sqlite"
    os.environ["DB_PATH"] = str(tmp)
    os.environ.pop("DATABASE_URL", None)
    sys.path.insert(0, str(ROOT))
    from bot import db

    db.init()
    for i, dname in enumerate(DISTRICTS, 1):
        did = db.execute("INSERT INTO districts (name, sort_order, phone) VALUES (?, ?, ?)", (dname, i, "+998712000000"))
        for j, sname in enumerate(STREETS, 1):
            sid = db.execute("INSERT INTO streets (district_id, name, sort_order, planned_objects) VALUES (?, ?, ?, ?)",
                             (did, sname, j, 10))
            db.execute("INSERT INTO street_files (street_id, file_name, path, size, sort_order) VALUES (?, ?, ?, ?, 1)",
                       (sid, f"{sname}.pdf", f"{i:02d}_{j:02d}/{sname}.pdf", 20))
    import sqlite3
    db._shared.close()  # the bot's own connection holds the WAL; let go before compacting
    db._shared = None
    c = sqlite3.connect(tmp)
    c.execute("PRAGMA journal_mode=DELETE")  # one self-contained file
    c.execute("VACUUM")
    c.close()
    OUT.write_bytes(tmp.read_bytes())
    print(f"{OUT.relative_to(ROOT)}: {len(DISTRICTS)} districts, {len(DISTRICTS) * len(STREETS)} streets, no people")


if __name__ == "__main__":
    main()
