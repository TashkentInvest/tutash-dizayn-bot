"""Move the PDFs from 'must be uploaded/' into data/dashboard/<street id>/ and register them in street_files,
exactly like an upload through the dashboard. A file whose name is already registered for the street is replaced."""
import os, shutil, sys
sys.path.insert(0, os.path.join(os.path.dirname(__file__), ".."))
sys.stdout.reconfigure(encoding="utf-8")
from bot import db
from bot.config import DATA_DIR, ROOT

SRC = ROOT / "must be uploaded"
MAP = {  # file name -> (district, street) as named in the dashboard
    "Bektemir Shoh ko`chasi (mini).pdf": ("Бектемир тумани", "Бектемир Шоҳ кўчаси"),
    "Алмазарский_район_улица_Себзармини.pdf": ("Олмазор тумани", "Себзор кўчаси"),
    "Чиланзарский_район_Малая_кольцевая_дорога_мини.pdf": ("Чилонзор тумани", "КХАЙ кўчаси"),
    "Чиланзарский_район_улица_Гавхар_мини.pdf": ("Чилонзор тумани", "Гавхар кўчаси"),
    "Чиланзарский_район_улица_Заргарлик_мини.pdf": ("Чилонзор тумани", "Заргарлик кўчаси"),
    "Чиланзарский_район_улица_Катартал_мини.pdf": ("Чилонзор тумани", "Қатортол кўчаси"),
    "Чиланзарский_район_улица_Катта_Хирмонтепа_мини.pdf": ("Чилонзор тумани", "Катта Хирмонтепа кўчаси"),
    "Чиланзарский_район_улица_Чапаната_мини.pdf": ("Чилонзор тумани", "Чўпонота кўчаси"),
    "Шайхантахурский_район_Большая_кольцевая_дорога_мини.pdf": ("Шайхонтоҳур тумани", "ТХАЙ кўчаси"),
    "Шайхантахурский_район_обводная_улица_Нурафшон_мини.pdf": ("Шайхонтоҳур тумани", "Нурафшон кўчаси"),
    "Шайхантахурский_район_улица_Абая_мини.pdf": ("Шайхонтоҳур тумани", "Абай кўчаси"),
    "Шайхантахурский_район_улица_Абдуллы_Кадыри_мини.pdf": ("Шайхонтоҳур тумани", "Абдулло Қодирий кўчаси"),
    "Шайхантахурский_район_улица_Алишера_Навои_мини.pdf": ("Шайхонтоҳур тумани", "Алишер Навоий"),
    "Шайхантахурский_район_улица_Беруни_мини.pdf": ("Шайхонтоҳур тумани", "Беруний кўчаси"),
    "Шайхантахурский_район_улица_Джарарык_мини.pdf": ("Шайхонтоҳур тумани", "Жарариқ кўчаси"),
    "Шайхантахурский_район_улица_Зульфияханум_мини.pdf": ("Шайхонтоҳур тумани", "Зулфияхоним кўчаси"),
    "Шайхантахурский_район_улица_Караташ_мини.pdf": ("Шайхонтоҳур тумани", "Қоратош кўчаси"),
    "Шайхантахурский_район_улица_Кукча_Дарвоза_мини.pdf": ("Шайхонтоҳур тумани", "Кўкча Дарвоза кўчаси"),
    "Шайхантахурский_район_улица_Самарканд_Дарвоза_мини.pdf": ("Шайхонтоҳур тумани", "Самарқанд Дарвоза кўчаси"),
    "Шайхантахурский_район_улица_Фурката_мини.pdf": ("Шайхонтоҳур тумани", "Фурқат кўчаси"),
    "Юнусабадский_район_Малая_кольцевая_дорога_мини.pdf": ("Юнусобод тумани", "КХАЙ кўчаси"),
    "Юнусабадский_район_проспект_Амира_Темура_мини.pdf": ("Юнусобод тумани", "Амир Темур шоҳ кўчаси"),
    "Юнусабадский_район_улица_Янги_Юнусабад_мини.pdf": ("Юнусобод тумани", "Янги Юнусобод (Ниёзбек)"),
    "Янгихаётский_район_улица_Дустлик_мини.pdf": ("Янгиҳаёт тумани", "Дўстлик кўчаси"),
    "Янгихаётский_район_улица_Мирзы_Турсунзаде_мини.pdf": ("Янгиҳаёт тумани", "Мирза Турсунзода кўчаси"),
    "Яшнабадский_район_проспект_ТХАЙ.pdf": ("Яшнобод тумани", "ТХАЙ кўчаси"),
}

done, problems = 0, []
for f in sorted(os.listdir(SRC)):
    if f not in MAP:
        problems.append(f"no mapping: {f}")
        continue
    dname, sname = MAP[f]
    rows = db.fetch_all(
        "SELECT s.id FROM streets s JOIN districts d ON d.id = s.district_id WHERE d.name = ? AND s.name = ?", (dname, sname)
    )
    if len(rows) != 1:
        problems.append(f"street not found/ambiguous ({len(rows)}): {dname} / {sname}  <- {f}")
        continue
    sid = rows[0]["id"]
    rel = f"dashboard/{sid}/{f}"
    dest = DATA_DIR / rel
    dest.parent.mkdir(parents=True, exist_ok=True)
    shutil.move(str(SRC / f), str(dest))
    db.execute("DELETE FROM street_files WHERE street_id = ? AND file_name = ?", (sid, f))
    nxt = db.fetch_all("SELECT COALESCE(MAX(sort_order), 0) + 1 AS n FROM street_files WHERE street_id = ?", (sid,))[0]["n"]
    db.execute(
        "INSERT INTO street_files (street_id, file_name, path, size, sort_order, created_at) "
        "VALUES (?, ?, ?, ?, ?, datetime('now'))",
        (sid, f, rel, dest.stat().st_size, nxt),
    )
    done += 1
    print(f"ok  {dname} | {sname} | {f} | {dest.stat().st_size / 2**20:.1f} MB")
print(f"\nadded {done} of {len(MAP)}; problems: {len(problems)}")
for p in problems:
    print("PROBLEM", p)
