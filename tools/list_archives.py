import subprocess, glob, json, sys, os
sys.stdout.reconfigure(encoding="utf-8")
Z = r"C:\Program Files\7-Zip\7z.exe"
out = {}
for f in sorted(glob.glob("*.rar")):
    if ".part2." in f or ".part3." in f:
        continue
    r = subprocess.run([Z, "l", "-slt", "-sccUTF-8", f], capture_output=True)
    txt = r.stdout.decode("utf-8", "replace")
    items, cur = [], {}
    for line in txt.splitlines():
        if line.startswith("Path = "):
            if cur.get("Path") and cur.get("Folder") is not None: items.append(cur)
            cur = {"Path": line[7:]}
        elif " = " in line and cur:
            k, v = line.split(" = ", 1); cur[k] = v
    if cur.get("Folder") is not None: items.append(cur)
    out[f] = [(i["Path"], int(i.get("Size") or 0)) for i in items if i.get("Folder") == "-"]
json.dump(out, open("tools/archive_index.json", "w", encoding="utf-8"), ensure_ascii=False, indent=1)
for a, files in out.items():
    print("==", a)
    for p, s in files:
        print(f"  {s/1e6:8.1f}MB {p}")
