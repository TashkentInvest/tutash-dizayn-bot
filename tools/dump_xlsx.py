import sys, openpyxl
sys.stdout.reconfigure(encoding="utf-8")
wb = openpyxl.load_workbook(sys.argv[1])
for ws in wb:
    print("##", ws.title, ws.max_row, ws.max_column)
    for r in ws.iter_rows(values_only=True):
        if any(c is not None for c in r):
            print(r)
