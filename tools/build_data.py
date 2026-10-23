"""Build data/<NN_District>/<NN_Street>/ from the Excel street list and the extracted _stage/ PDFs."""
import os, re, shutil, sys, openpyxl
sys.stdout.reconfigure(encoding="utf-8")
ROOT = r"D:\Projects\Tutash_bot"
XLSX = os.path.join(ROOT, "142 талик кўчалар охири.xlsx")
DATA = os.path.join(ROOT, "data")
STAGE = os.path.join(ROOT, "_stage")

# stage archive dir -> Excel district name
ARCHIVE_DISTRICT = {
    "01. Mirobod (просмотренные)": "Миробод тумани",
    "04. Olmazor (просмотренные)": "Олмазор тумани",
    "05. Shayxontohur (просмотренные)": "Шайхонтоҳур тумани",
    "06. Yunusobod (просмотренные)": "Юнусобод тумани",
    "07. Chilonzor (просмотренные)": "Чилонзор тумани",
    "08. Uchtepa": "Учтепа тумани",
    "09. Yakkasaroy": "Яккасарой тумани",
    "10. Bektemir": "Бектемир тумани",
    "11. Sergeli": "Сергели тумани",
    "12. Yashnabod": "Яшнобод тумани",
}
# (district, source street folder without the NN. prefix) -> Excel street name
M = {
 "Миробод тумани": {
  "улица_Янги_Куйлюк": "Янги Қўйлиқ кўчаси", "Ферганское_шоссе": "Фарғона йўли кўчаси",
  "улица_Абу_Сулеймана_Банокати": "Банокатий кўчаси"},
 "Олмазор тумани": {"Ахмада_Дониш": "Ахмад Дониш кўчаси"},
 "Шайхонтоҳур тумани": {"Себзар": "Себзор кўчаси", "проспект_Бунёдкор": "Бунёдкор шоҳ кўчаси"},
 "Юнусобод тумани": {"Ахмада_Дониш": "Ахмад Дониш кўчаси", "улица_Янгишахар": "Янги Шаҳар кўчаси"},
 "Чилонзор тумани": {
  "улица_Мукими": "Муқумий кўчаси", "улица_Аль_Хорезми": "Ал Хоразмий кўчаси",
  "улица_Чиланзар": "Чилонзор кўчаси", "улица_Домбрабад": "Дўмбробод кўчаси",
  "Большая_кольцевая_дорога": "ТХАЙ кўчаси", "улицв_Катта_Кани": "Катта Қани кўчаси"},
 "Учтепа тумани": {
  "Фархадская_улица": "Фарход кўчаси", "улица_Лутфи": "Лутфий кўчаси", "улица_Катартал": "Қатортол кўчаси",
  "улица_Заргарлик": "Заргарлик кўчаси", "Малая_кольцевая_дорога": "КХАЙ кўчаси",
  "улица_Катта_Хирмонтепа": "Катта Хирмонтепа кўчаси", "улица_Джурджани": "Журжоний кўчаси",
  "улица_Назарбек": "Назарбек кўчаси", "улица_Фазылтепа": "Фозилтепа кўчаси",
  "улица_Кукча_Дарвоза": "Кўкча Дарвоза кўчаси", "Большая_кольцевая_дорога": "ТХАЙ кўчаси",
  "улица_Катта_Каъни": "Катта Қани кўчаси"},
 "Яккасарой тумани": {
  "Малая_кольцевая_дорога": "КХАЙ кўчаси", "улица_Бабура": "Бобур кўчаси", "улица_Ислама_Каримова": "И.Каримов",
  "улица_Мирабад": "Миробод кўчаси", "улица_Мукими": "Муқумий кўчаси", "улица_Братислава": "Братислава",
  "улица_Шота_Руставели": "Шота Руставели кўчаси", "улица_Кушбеги": "Қушбеги кўчаси",
  "улица_Чапаната": "Чўпонота кўчаси"},
 "Бектемир тумани": {
  "улица_Хусейна_Байкары": "Хусайн Байқаро кўчаси", "Большая_кольцевая_дорога": "ТХАЙ кўчаси",
  "Ферганское_шоссе": "Фарғона йўли кўчаси", "Ахангаранский_проспект": "Охангарон шоҳ кўчаси"},
 "Сергели тумани": {
  "улица_Янги_Сергели": "Янги Сергели кўчаси", "улица_Мирзы_Турсунзаде": "Мирзо Турсунзода кўчаси",
  "улица_Шокирарык": "Шокирариқ кўчаси", "Большая_кольцевая_дорога": "ТХАЙ кўчаси",
  "проспект_Сергели_Йули": "Сергели йўли кўчаси", "улица_Кумарык": "Қумариқ кўчаси"},
 "Яшнобод тумани": {
  "Малая_кольцевая_дорога": "КХАЙ кўчаси", "Махтумкули": "Маҳтумқули кўчаси", "улица_Истикбол": "Истиқбол кўчаси",
  "Ферганское_шоссе": "Фарғона йўли кўчаси", "улица_Шахрисабз": "Шахрисабз кўчаси", "улица_Карасу": "Қорасув кўчаси",
  "улица_Пахлавона_Махмуда": "Пахлавон Махмуд кўчаси", "улица_Алимкент": "Алимкент кўчаси",
  "улица_Эльбека": "Элбек кўчаси", "улица_Мирзо_Улугбека": "Мирзо Улуғбек кўчаси",
  "улица_Мухтара_Ашрафи": "Мухтор Ашрафий кўчаси", "Ахангаранский_проспект": "Охангарон шоҳ кўчаси",
  "улица_Паркент": "Паркент кўчаси"},
}
# loose PDFs in the project root (only used when the street has no file yet)
LOOSE = {
    "Алмазарский_район_Ахмад_Дониш_мини.pdf": ("Олмазор тумани", "Ахмад Дониш кўчаси"),
    "Мирабадский_район_Ферганское_шоссе_мини.pdf": ("Миробод тумани", "Фарғона йўли кўчаси"),
    "Юнусабадский_район_Ахмад_Дониш_мини.pdf": ("Юнусобод тумани", "Ахмад Дониш кўчаси"),"Юнусабадский_район_улица_Осиё_мини.pdf": ("Юнусобод тумани", "Осиё кўчаси")}

def clean(s):
    return re.sub(r'[<>:"/\|?*]', "", s).strip().rstrip(".")

def read_excel():
    ws = openpyxl.load_workbook(XLSX)["Sheet1 (3)"]
    out, cur = [], None
    for a, b, *_ in ws.iter_rows(min_row=2, values_only=True):
        if isinstance(a, str) and b is None:
            if a.strip().startswith("Жами"):
                continue
            cur = (a.strip(), []); out.append(cur)
        elif isinstance(b, str) and cur:
            name = b.strip()
            if name == "Корасув":   # duplicate of "Қорасув кўчаси"
                continue
            cur[1].append(name)
    return out

def main():
    if os.path.exists(DATA):
        shutil.rmtree(DATA)
    cat = read_excel()
    paths = {}
    for di, (d, streets) in enumerate(cat, 1):
        for si, s in enumerate(streets, 1):
            p = os.path.join(DATA, f"{di:02d}_{clean(d)}", f"{si:02d}_{clean(s)}")
            os.makedirs(p); paths[(d, s)] = p
    print("districts:", len(cat), "streets:", sum(len(s) for _, s in cat))
    unmatched, placed = [], 0
    for arch, d in ARCHIVE_DISTRICT.items():
        base = os.path.join(STAGE, arch)
        for dp, _, fs in os.walk(base):
            for f in fs:
                if not f.lower().endswith(".pdf"):
                    continue
                m = re.match(r"^\d+[.,]\s*(.*)$", os.path.basename(dp))
                key = m.group(1) if m else None
                street = M.get(d, {}).get(key) if key else None
                src = os.path.join(dp, f)
                if key is None:
                    continue  # old versions in sub-folders (eskisi, 1v, 2v)
                if street and (d, street) in paths:
                    shutil.move(src, os.path.join(paths[(d, street)], f)); placed += 1
                else:
                    unmatched.append((arch, os.path.relpath(src, base)))
    for f, (d, s) in LOOSE.items():
        src = os.path.join(ROOT, f); p = paths[(d, s)]
        if os.path.exists(src):
            for old in os.listdir(p): os.remove(os.path.join(p, old))
            shutil.copy2(src, os.path.join(p, f)); placed += 1
    if unmatched:
        un = os.path.join(DATA, "_unmatched")
        for arch, rel in unmatched:
            os.makedirs(os.path.join(un, arch), exist_ok=True)
            shutil.move(os.path.join(STAGE, arch, rel), os.path.join(un, arch, os.path.basename(rel)))
    print("files placed:", placed); print("unmatched:", unmatched)
    for d, streets in cat:
        n = sum(1 for s in streets if any(os.scandir(paths[(d, s)])))
        print(f"{d}: {n}/{len(streets)} streets have a file")

if __name__ == "__main__":
    main()
