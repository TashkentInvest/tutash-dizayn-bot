"""The bot's side of "Post yuborish", on a COPY of the database with a fake Telegram.  python tools/test_posts.py"""
import asyncio, os, shutil, sys, tempfile
from datetime import datetime

ROOT = os.path.abspath(os.path.join(os.path.dirname(__file__), ".."))
tmp = tempfile.mkdtemp()
shutil.copy(os.path.join(ROOT, "tests", "fixtures", "bot.sqlite"), os.path.join(tmp, "bot.db"))
os.makedirs(os.path.join(tmp, "uploads", "posts"))
os.environ.update(DB_PATH=os.path.join(tmp, "bot.db"), UPLOADS_DIR=os.path.join(tmp, "uploads"), DATA_DIR=os.path.join(tmp, "data"),
                  LINK_SECRET="test-secret")
sys.path.insert(0, ROOT)
sys.stdout.reconfigure(encoding="utf-8")

from aiogram import Bot, Dispatcher  # noqa: E402
from aiogram.client.session.base import BaseSession  # noqa: E402
from aiogram.exceptions import TelegramForbiddenError  # noqa: E402
from aiogram.methods import (AnswerCallbackQuery, DeleteMessage, EditMessageReplyMarkup, SendMessage, SendPhoto,  # noqa: E402
                             SendVideo)
from aiogram.types import Chat, InputFile, Message, PhotoSize, Update, Video  # noqa: E402

from bot import db, posts  # noqa: E402

ok = True


def check(name, cond):
    global ok
    ok &= bool(cond)
    print(("PASS " if cond else "FAIL ") + name)


db.init()
BLOCKED = 700003
sent = []


class Fake(BaseSession):
    async def make_request(self, bot, method, timeout=None):
        sent.append(method)
        chat = getattr(method, "chat_id", None)
        if chat == BLOCKED and isinstance(method, (SendMessage, SendPhoto, SendVideo)):
            raise TelegramForbiddenError(method=method, message="Forbidden: bot was blocked by the user")
        base = dict(message_id=len(sent) + 100, date=datetime.now(), chat=Chat(id=chat or 1, type="private"))
        if isinstance(method, SendPhoto):
            return Message(**base, photo=[PhotoSize(file_id="PHOTO-ID", file_unique_id="u", width=10, height=10)])
        if isinstance(method, SendVideo):
            return Message(**base, video=Video(file_id="VIDEO-ID", file_unique_id="v", width=720, height=1280, duration=3))
        if isinstance(method, SendMessage):
            return Message(**base, text=method.text)
        return True

    async def stream_content(self, *a, **k):
        yield b""

    async def close(self):
        pass


bot = Bot("42:TEST", session=Fake())
posts.PAUSE = 0
people = [700001, 700002, BLOCKED]
for u in people:
    db.execute("INSERT INTO users (user_id, first_name) VALUES (?, 'P') ON CONFLICT (user_id) DO NOTHING", (u,))


def make_post(**kw) -> int:
    cols = {"caption": None, "media_type": None, "media_path": None, "ack_button": 1, "total": len(people), **kw}
    pid = db.execute(f"INSERT INTO posts ({', '.join(cols)}) VALUES ({', '.join('?' * len(cols))})", tuple(cols.values()))
    for u in people:
        db.execute("INSERT INTO post_deliveries (post_id, user_id) VALUES (?, ?)", (pid, u))
    return pid


def post(pid):
    return dict(db.fetch_all("SELECT * FROM posts WHERE id=?", (pid,))[0])


async def run():
    # 1) text only: escaped (plain text, the admin's < > & stay as typed), the "Tanishdim" button, a blocked user noted
    pid = make_post(caption="Narx < 5 & > 2 <b>qalin emas</b>")
    await posts.send_post(bot, db.posts_to_send()[0])
    msgs = [m for m in sent if isinstance(m, SendMessage)]
    check("text post goes to everyone ticked", len(msgs) == 3)
    check("text is escaped (HTML mode, nothing read as markup)", msgs[0].text == "Narx &lt; 5 &amp; &gt; 2 &lt;b&gt;qalin emas&lt;/b&gt;"
          and msgs[0].parse_mode == "HTML")
    btn = msgs[0].reply_markup.inline_keyboard[0][0]
    check("the post has the Tanishdim button (pa:<id>)", btn.text == posts.ACK and btn.callback_data == f"pa:{pid}")
    p = post(pid)
    check("counted: 2 delivered, 1 failed, post sent", (p["delivered"], p["failed"], p["status"]) == (2, 1, "sent") and p["finished_at"])
    bad = db.fetch_all("SELECT status, error FROM post_deliveries WHERE post_id=? AND user_id=?", (pid, BLOCKED))[0]
    check("the blocked user: failed with Telegram's reason, marked blocked",
          bad["status"] == "failed" and "blocked" in bad["error"]
          and db.fetch_all("SELECT blocked_at FROM users WHERE user_id=?", (BLOCKED,))[0][0])
    check("message ids kept (needed to take the post back)",
          len(db.fetch_all("SELECT 1 FROM post_deliveries WHERE post_id=? AND message_id IS NOT NULL", (pid,))) == 2)

    # 2) a picture: uploaded once, then the same file_id
    db.execute("UPDATE users SET blocked_at=NULL")
    open(os.path.join(tmp, "uploads", "posts", "a.jpg"), "wb").write(b"\xff\xd8\xff test")
    sent.clear()
    pid2 = make_post(caption="Rasm", media_type="photo", media_path="posts/a.jpg", ack_button=0)
    await posts.send_post(bot, db.posts_to_send()[0])
    ph = [m for m in sent if isinstance(m, SendPhoto)]
    check("picture: the first upload is the file, the next ones reuse its file_id",
          isinstance(ph[0].photo, InputFile) and ph[1].photo == "PHOTO-ID" and post(pid2)["file_id"] == "PHOTO-ID")
    check("no button when the admin turned it off", ph[0].reply_markup is None)

    # 3) a video: its frame and length go along (a phone video is not stretched); the text is not transliterated
    open(os.path.join(tmp, "uploads", "posts", "v.mp4"), "wb").write(b"\x00\x00\x00 ftyp test")
    sent.clear()
    make_post(caption="Ҳурматли ходимлар", media_type="video", media_path="posts/v.mp4", media_width=720, media_height=1280, media_duration=12)
    await posts.send_post(bot, db.posts_to_send()[0])
    v = [m for m in sent if isinstance(m, SendVideo)][0]
    check("video: width / height / duration / supports_streaming sent",
          (v.width, v.height, v.duration, v.supports_streaming) == (720, 1280, 12, True))
    check("video caption stays Cyrillic, as written", v.caption == "Ҳурматли ходимлар")

    # 4) a missing file: every delivery fails with a reason, the post still finishes
    sent.clear()
    pid4 = make_post(caption="x", media_type="photo", media_path="posts/none.jpg")
    await posts.send_post(bot, db.posts_to_send()[0])
    check("missing file: failed, not stuck", post(pid4)["status"] == "sent" and post(pid4)["failed"] == 3 and not sent)

    # 5) "Tanishdim": recorded once, Rahmat!, the button changes
    sent.clear()
    dp = Dispatcher()
    dp.include_router(posts.router)
    msg = {"message_id": 101, "date": 0, "chat": {"id": 700001, "type": "private"}, "from": {"id": 1, "is_bot": True, "first_name": "B"}, "text": "x"}
    for n in (1, 2):
        await dp.feed_update(bot, Update.model_validate({"update_id": n, "callback_query": {
            "id": f"q{n}", "from": {"id": 700001, "is_bot": False, "first_name": "N"}, "chat_instance": "c", "message": msg, "data": f"pa:{pid}"}}))
    ack = db.fetch_all("SELECT ack_at FROM post_deliveries WHERE post_id=? AND user_id=700001", (pid,))[0][0]
    answers = [m.text for m in sent if isinstance(m, AnswerCallbackQuery)]
    edits = [m for m in sent if isinstance(m, EditMessageReplyMarkup)]
    check("Tanishdim: ack_at written, answered Rahmat!", ack and answers == ["Rahmat!", "Rahmat!"])
    check("the button becomes 'Tanishdingiz'", edits and edits[0].reply_markup.inline_keyboard[0][0].text == posts.ACKED)
    first = ack
    db.execute("UPDATE post_deliveries SET ack_at='2000-01-01 00:00:00' WHERE post_id=? AND user_id=700001", (pid,))
    check("a second press does not move the first time", not db.ack_post(pid, 700001) and first)

    # 6) "Botdan o'chirish": deleteMessage for each delivered one; delivered / failed stay as they were
    sent.clear()
    db.execute("UPDATE posts SET recalled_at=datetime('now') WHERE id=?", (pid,))
    for r in db.posts_to_recall():
        await posts.recall_post(bot, r["id"])
    dels = [m for m in sent if isinstance(m, DeleteMessage)]
    p = post(pid)
    check("recall: deleteMessage for each delivered message", len(dels) == 2 and {d.chat_id for d in dels} == {700001, 700002})
    check("recall counted, stats unchanged", (p["recalled"], p["recall_failed"], p["delivered"], p["failed"]) == (2, 0, 2, 1)
          and p["recall_finished_at"])
    check("nothing left to recall", not db.posts_to_recall())


asyncio.run(run())
shutil.rmtree(tmp, ignore_errors=True)
print("\nALL OK" if ok else "\nSOME CHECKS FAILED")
sys.exit(0 if ok else 1)
