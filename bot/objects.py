"""Street concept PDFs -> objects. Citizens still get the whole PDF; district staff work with one object per page.

Which pages are objects
  * Page 1 is the cover; every following page is one object (as the official object list counts them). An admin can
    switch off a page that is not an object.
  * Some concept PDFs are pictures only (no text). They count when they are the street's concept PDF; a picture-only PDF
    next to a concept PDF with text (e.g. "Mirobod render.pdf") is extra material, not objects.

No duplicates, no lost work
  * Each object page is fingerprinted (a tiny rendering, `page_hash`). A page identical to an object already shown from
    another file is not added again.
  * When a PDF is replaced (new version, same or new file), pages that are still there keep their object - with its
    status, details and inspections - even if they moved to another page number or file. Only really new pages become
    new objects; pages that disappeared are marked `gone` (hidden, never deleted).
  * `is_active` is the admin's switch ("not an object"); `gone` is set here. Lists show is_active=1 AND gone=0.

Each object gets a JPEG of its page (DATA_DIR/_objects/<file>/<page>.jpg). Runs in a worker thread from the bot
(sync_all). Rendering uses PyMuPDF.
"""
import hashlib
import logging
import os
from pathlib import Path

from . import db
from .config import DATA_DIR

OBJECTS_DIR = "_objects"
IMAGE_WIDTH = 1200   # px: sharp on a phone, ~200 KB per page
JPEG_QUALITY = 78
MARKER = "TAKLIF"    # "TAKLIF KO'RINISH" printed on concept pages: tells a concept PDF from a picture-only one


def _fitz():
    import fitz  # PyMuPDF; imported late so the bot starts even without it (splitting then only logs an error)

    return fitz


def classify(doc) -> tuple[str, list[int]]:
    """Every page after the cover is an object (this is how the official object list counts them). The kind tells whether
    the PDF is a concept PDF with text ("text": "TAKLIF KO'RINISH" printed on its pages) or pictures only ("images")."""
    pages = list(range(2, len(doc) + 1))
    # the cover is not looked at: a render album's cover says "Taklif loyiha" too
    kind = "text" if any(MARKER in doc[i].get_text().upper() for i in range(1, len(doc))) else "images"
    return kind, pages


def page_hash(doc, page_no: int) -> str:
    """Fingerprint of how the page looks (a tiny greyscale rendering): the same page in a re-exported PDF matches."""
    fitz = _fitz()
    pix = doc[page_no - 1].get_pixmap(matrix=fitz.Matrix(0.12, 0.12), colorspace=fitz.csGRAY, alpha=False)
    return hashlib.sha1(pix.samples).hexdigest()


def _render(doc, page_no: int, dest: Path) -> None:
    fitz = _fitz()
    page = doc[page_no - 1]
    zoom = IMAGE_WIDTH / max(page.rect.width, 1)
    pix = page.get_pixmap(matrix=fitz.Matrix(zoom, zoom), alpha=False)
    tmp = dest.with_suffix(".tmp")
    pix.save(tmp, output="jpeg", jpg_quality=JPEG_QUALITY)
    os.replace(tmp, dest)  # never leave a half-written picture under the real name


def _street_has_text_pdf(street_id: int, except_file: int) -> bool:
    return bool(db.fetch_all(
        "SELECT 1 FROM object_sources o JOIN street_files f ON f.id = o.file_id "
        "WHERE f.street_id=? AND o.file_id<>? AND o.kind='text' AND o.objects>0 LIMIT 1", (street_id, except_file)))


def split_file(file_id: int, street_id: int, rel_path: str) -> dict:
    """Splits one street PDF. Returns {"kind", "objects", "new", "kept", "duplicates"}. Raises if the PDF cannot be read."""
    fitz = _fitz()
    path = DATA_DIR / rel_path
    st = path.stat()
    out = DATA_DIR / OBJECTS_DIR / str(file_id)
    res = {"kind": "", "objects": 0, "new": 0, "kept": 0, "duplicates": 0}
    with fitz.open(path) as doc:
        n_pages = len(doc)
        kind, pages = classify(doc)
        if kind == "images" and _street_has_text_pdf(street_id, file_id):
            kind, pages = "extra", []  # renders / photos next to the real concept PDF
        res["kind"] = kind
        # this file's current objects step aside: each page below either takes one back or becomes a new object
        db.execute("UPDATE objects SET gone=1, page=-id WHERE file_id=?", (file_id,))
        if pages:
            out.mkdir(parents=True, exist_ok=True)
        for page_no in pages:
            h = page_hash(doc, page_no)
            same = db.fetch_all("SELECT id, file_id, gone FROM objects WHERE page_hash=? ORDER BY gone, id", (h,))
            live_elsewhere = [r for r in same if not r["gone"] and r["file_id"] != file_id]
            if live_elsewhere:  # this very page is already an object from another PDF
                res["duplicates"] += 1
                continue
            dest = out / f"{page_no}.jpg"
            _render(doc, page_no, dest)
            image = dest.relative_to(DATA_DIR).as_posix()
            back = next((r for r in same if r["gone"]), None)  # the same page from this or an older/removed file
            if back:
                db.execute("UPDATE objects SET street_id=?, file_id=?, page=?, image=?, gone=0, updated_at=datetime('now') WHERE id=?",
                           (street_id, file_id, page_no, image, back["id"]))
                res["kept"] += 1
            else:
                db.execute("INSERT INTO objects (street_id, file_id, page, image, page_hash) VALUES (?, ?, ?, ?, ?)",
                           (street_id, file_id, page_no, image, h))
                res["new"] += 1
            res["objects"] += 1
    db.execute(
        "INSERT INTO object_sources (file_id, size, mtime, pages, kind, objects, error) VALUES (?, ?, ?, ?, ?, ?, NULL) "
        "ON CONFLICT(file_id) DO UPDATE SET size=excluded.size, mtime=excluded.mtime, pages=excluded.pages, kind=excluded.kind, "
        "objects=excluded.objects, error=NULL, done_at=datetime('now')",
        (file_id, st.st_size, int(st.st_mtime), n_pages, kind, res["objects"]),
    )
    return res


def sync_all(limit: int | None = None) -> dict:
    """Splits every street PDF that is new or changed since the last run. Safe to call often (cheap when nothing changed).
    Text concept PDFs go first, so a picture-only PDF of the same street is recognised as extra material."""
    fitz = _fitz()
    total = {"files": 0, "pages": 0, "new": 0, "kept": 0, "duplicates": 0, "failed": 0}
    files = db.fetch_all("SELECT f.id, f.street_id, f.path, f.file_name FROM street_files f ORDER BY f.id")
    known = {r["file_id"]: r for r in db.fetch_all("SELECT file_id, size, mtime FROM object_sources")}
    live_ids, todo = set(), []
    for f in files:
        if not f["file_name"].lower().endswith(".pdf"):
            continue
        live_ids.add(f["id"])
        try:
            st = (DATA_DIR / f["path"]).stat()
        except OSError:
            continue
        k = known.get(f["id"])
        if not (k and k["size"] == st.st_size and k["mtime"] == int(st.st_mtime)):
            todo.append((f, st))

    # files removed from the catalog first: their pages become "gone", so a re-upload can take them back
    for (fid,) in db.fetch_all("SELECT DISTINCT file_id FROM objects WHERE gone=0"):
        if fid not in live_ids:
            db.execute("UPDATE objects SET gone=1, page=-id WHERE file_id=?", (fid,))

    def has_text(item) -> int:
        try:
            with fitz.open(DATA_DIR / item[0]["path"]) as d:
                return 0 if any(d[i].get_text().strip() for i in range(min(len(d), 3))) else 1
        except Exception:
            return 2
    todo.sort(key=has_text)

    for f, st in todo:
        if limit is not None and total["files"] + total["failed"] >= limit:
            break
        try:
            r = split_file(f["id"], f["street_id"], f["path"])
            total["files"] += 1
            total["pages"] += r["objects"]
            for k in ("new", "kept", "duplicates"):
                total[k] += r[k]
        except Exception as e:  # a broken PDF must not stop the others
            logging.exception("split failed: %s", f["path"])
            total["failed"] += 1
            db.execute(
                "INSERT INTO object_sources (file_id, size, mtime, pages, error) VALUES (?, ?, ?, 0, ?) "
                "ON CONFLICT(file_id) DO UPDATE SET size=excluded.size, mtime=excluded.mtime, error=excluded.error",
                (f["id"], st.st_size, int(st.st_mtime), str(e)[:300]),
            )
    if total["files"] or total["failed"]:
        logging.info("objects: %s", total)
    return total
