"""Tiny file server: /f/<expires>/<sig>/<path>. sig is an HMAC of "<path>|<expires>", so links can't be guessed,
expire (LINK_TTL_HOURS) and the data/ tree can't be browsed. In production put nginx/Caddy in front of it."""
import hashlib
import hmac
import time
from pathlib import Path
from urllib.parse import quote

from aiohttp import web

from .config import DATA_DIR, LINK_SECRET, LINK_TTL_HOURS, PUBLIC_BASE_URL, WEB_HOST, WEB_PORT


def _sig(rel: str, exp: int) -> str:
    return hmac.new(LINK_SECRET.encode(), f"{rel}|{exp}".encode(), hashlib.sha256).hexdigest()[:32]


def link_for(path: Path) -> str:
    rel = path.relative_to(DATA_DIR).as_posix()
    exp = int(time.time()) + LINK_TTL_HOURS * 3600
    return f"{PUBLIC_BASE_URL}/f/{exp}/{_sig(rel, exp)}/{quote(rel)}"


async def _serve(request: web.Request) -> web.StreamResponse:
    exp, sig, rel = request.match_info["exp"], request.match_info["sig"], request.match_info["rel"]
    if not exp.isdigit() or int(exp) < time.time():
        raise web.HTTPNotFound()  # expired links look exactly like unknown ones
    path = (DATA_DIR / rel).resolve()
    if not hmac.compare_digest(sig, _sig(rel, int(exp))) or DATA_DIR.resolve() not in path.parents or not path.is_file():
        raise web.HTTPNotFound()
    return web.FileResponse(path, headers={
        "Content-Disposition": f"attachment; filename*=UTF-8''{quote(path.name)}",
        "X-Content-Type-Options": "nosniff",
    })


async def start_web() -> web.AppRunner:
    app = web.Application()
    app.router.add_get(r"/f/{exp:\d+}/{sig}/{rel:.+}", _serve)
    runner = web.AppRunner(app)
    await runner.setup()
    await web.TCPSite(runner, WEB_HOST, WEB_PORT).start()
    return runner
