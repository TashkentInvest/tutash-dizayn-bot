"""Add the PDFs from 'Просмотренные концепции 20.10.2026.zip' to data/ (skips files already there by size)."""
import os, shutil, subprocess, sys
sys.path.insert(0, r"D:\Projects\Tutash_bot"); sys.stdout.reconfigure(encoding="utf-8")
from bot import catalog
ROOT = r"D:\Projects\Tutash_bot"; STAGE = os.path.join(ROOT, "_stage_viewed")
Z = r"C:\Program Files\7-Zip\7z.exe"
subprocess.run([Z, "x", "-y", f"-o{STAGE}", os.path.join(ROOT, "Просмотренные концепции 20.10.2026.zip"), "*.pdf", "-r"], capture_output=True)
MAP = {  # file name -> (district, street)
 "Алмазарский_район_2-я_улица_Каракамыш_мини.pdf": ("Олмазор тумани", "Қорақамиш 2-кўчаси"),
 "Алмазарский_район_обводная_улица_Нурафшон_мини.pdf": ("Олмазор тумани", "Нурафшон кўчаси"),
 "Алмазарский_район_улица_Сагбан_мини.pdf": ("Олмазор тумани", "Сағбон кўчаси"),
 "Мирабадский_район_Малая_кольцевая_дорогамини.pdf": ("Миробод тумани", "КХАЙ кўчаси"),
 "Мирабадский_район_улица_Абдурауфа_Фитрата_мини.pdf": ("Миробод тумани", "Абдурауф Фитрат кўчаси"),
 "Мирабадский_район_улица_Истикбол_мини.pdf": ("Миробод тумани", "Истиқбол кўчаси"),
 "Мирабадский_район_улица_Локомотив_мини.pdf": ("Миробод тумани", "Локоматив кўчаси"),
 "Мирабадский_район_улица_Мирабад_мини.pdf": ("Миробод тумани", "Миробод кўчаси"),
 "Мирабадский_район_улица_Шахрисабз_мини.pdf": ("Миробод тумани", "Шахрисабз кўчаси"),
 "Toshkent_Shahar_Mirobod_Tumani_Mirobod_kochasi_39_Dom_obyektiga.pdf": ("Миробод тумани", "Миробод кўчаси"),
 "Mirobod render.pdf": ("Миробод тумани", "Миробод кўчаси"),
 "Мирзо_Улугбекский_район_улица_Гульсанам_мини.pdf": ("Мирзо Улуғбек тумани", "Гулсанам кўчаси"),
 "Мирзо_Улугбекский_район_улица_Зиёлилар_мини.pdf": ("Мирзо Улуғбек тумани", "Зиёлилар кўчаси"),
 "Чиланзарский_район_проспект_Бунёдкор_мини.pdf": ("Чилонзор тумани", "Бунёдкор шоҳ кўчаси"),
 "Чиланзарский_район_улица_Фурката_мини.pdf": ("Чилонзор тумани", "Фурқат кўчаси"),
}
have = {f.stat().st_size for d in catalog.districts() for s in catalog.streets(d) for f in catalog.files(s)}
for dp, _, fs in os.walk(STAGE):
    for f in fs:
        src = os.path.join(dp, f); sz = os.path.getsize(src)
        if f in MAP:
            dn, sn = MAP[f]
            d = next(x for x in catalog.districts() if x.name == dn); s = next(x for x in catalog.streets(d) if x.name == sn)
            shutil.move(src, s.path / f); print("added  ", dn, "|", sn, "|", f)
        elif sz in have:
            print("same as existing:", f)
        else:
            print("UNMAPPED:", f)
shutil.rmtree(STAGE)
