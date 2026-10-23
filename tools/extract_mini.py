"""Extract only the *mini* PDFs of each district archive into _stage/<archive>/."""
import subprocess, sys, os
sys.stdout.reconfigure(encoding="utf-8")
Z = r"C:\Program Files\7-Zip\7z.exe"
ROOT = r"D:\Projects\Tutash_bot"
ARCHIVES = [
    "01. Mirobod (просмотренные).rar",
    "04. Olmazor (просмотренные).rar",
    "05. Shayxontohur (просмотренные).rar",
    "06. Yunusobod (просмотренные).rar",
    "07. Chilonzor (просмотренные).rar",
    "08. Uchtepa.rar",
    "09. Yakkasaroy.rar",
    "10. Bektemir.rar",
    "11. Sergeli.rar",
    "12. Yashnabod.part1.rar",
]
for a in ARCHIVES:
    out = os.path.join(ROOT, "_stage", a.replace(".part1.rar", "").replace(".rar", ""))
    os.makedirs(out, exist_ok=True)
    print("==>", a, flush=True)
    r = subprocess.run([Z, "x", "-y", "-aos", "-sccUTF-8", f"-o{out}",
                        os.path.join(ROOT, a),
                        "-ir!*мини*.pdf", "-ir!*mini*.pdf"],
                       capture_output=True)
    print(r.stdout.decode("utf-8", "replace")[-400:], r.returncode, flush=True)
print("DONE")
