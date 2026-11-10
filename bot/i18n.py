"""Bot texts in two languages: Uzbek (Latin script) and Russian. The user picks one at /start (stored in users.lang).

t(lang, key, *args) returns the text; names stored in Uzbek Cyrillic (districts, streets) are shown in Latin for Uzbek
through name().
"""
from . import db

LANGS = ("uz", "ru")
DEFAULT = "uz"

TEXTS: dict[str, tuple[str, str]] = {
    # language choice (always shown in both languages)
    "pick_lang": ("Tilni tanlang / Выберите язык:", "Tilni tanlang / Выберите язык:"),
    "btn_uz": ("🇺🇿 O‘zbekcha", "🇺🇿 O‘zbekcha"),
    "btn_ru": ("🇷🇺 Русский", "🇷🇺 Русский"),

    # registration
    "intro": ("Assalomu alaykum! Qisqa ro‘yxatdan o‘tamiz.", "Здравствуйте! Пройдём короткую регистрацию."),
    "consent": (
        "Botdan foydalanish uchun F.I.Sh., telefon raqamingiz, obyekt joylashuvi va rasmlaringiz to‘planadi va saqlanadi. "
        "Ma’lumotlar faqat shu xizmatni ko‘rsatish maqsadida ishlatiladi va uchinchi shaxslarga berilmaydi.\n\n"
        "«Roziman» tugmasini bosish bilan shaxsiy ma’lumotlaringizni qayta ishlashga rozilik berasiz. "
        "Ma’lumotlaringizni o‘chirishni so‘rasangiz, administratorga murojaat qiling.",
        "Для работы с ботом собираются и хранятся Ф.И.О., номер телефона, местоположение объекта и фотографии. "
        "Данные используются только для оказания этой услуги и не передаются третьим лицам.\n\n"
        "Нажимая «Согласен», вы даёте согласие на обработку персональных данных. "
        "Чтобы удалить свои данные, обратитесь к администратору.",
    ),
    "btn_consent": ("✅ Roziman", "✅ Согласен"),
    "name": ("1/4. Familiya, ism, otasining ismini kiriting (F.I.Sh.):", "1/4. Введите фамилию, имя и отчество (Ф.И.О.):"),
    "name_bad": (
        "Iltimos, F.I.Sh.ni to‘liq kiriting (kamida 2 ta so‘z), masalan: Karimov Alisher Ravshanovich",
        "Пожалуйста, введите Ф.И.О. полностью (не менее 2 слов), например: Каримов Алишер Равшанович",
    ),
    "phone": (
        "2/4. Telefon raqamingizni kiriting: +998 (--) --- -- --\nYoki pastdagi tugma orqali yuboring.",
        "2/4. Введите номер телефона: +998 (--) --- -- --\nИли отправьте его кнопкой ниже.",
    ),
    "phone_bad": ("Raqam noto‘g‘ri. Namuna: +998 (90) 123 45 67", "Неверный номер. Пример: +998 (90) 123 45 67"),
    "contact_not_own": (
        "Bu sizning kontaktingiz emas. O‘z raqamingizni tugma orqali yuboring yoki qo‘lda kiriting.",
        "Это не ваш контакт. Отправьте свой номер кнопкой или введите вручную.",
    ),
    "btn_contact": ("📱 Raqamni yuborish", "📱 Отправить номер"),
    "thanks": ("Rahmat!", "Спасибо!"),
    "location": (
        "3/4. Obyekt joylashuvini yuboring (📎 → Geopozitsiya) yoki pastdagi tugmani bosing.",
        "3/4. Отправьте местоположение объекта (📎 → Геопозиция) или нажмите кнопку ниже.",
    ),
    "btn_location": ("📍 Joylashuvni yuborish", "📍 Отправить местоположение"),
    "location_bad": ("Iltimos, Telegram geopozitsiyasini yuboring.", "Пожалуйста, отправьте геопозицию через Telegram."),
    "location_out": (
        "Joylashuv O‘zbekiston hududidan tashqarida. Obyekt joylashuvini qayta yuboring.",
        "Местоположение за пределами Узбекистана. Отправьте местоположение объекта ещё раз.",
    ),
    "photo": ("4/4. Obyektning {} ta rasmini yuboring ({}-rasm).", "4/4. Отправьте {} фотографии объекта (фото {})."),
    "photo_bad": ("Iltimos, rasm yuboring.", "Пожалуйста, отправьте фотографию."),
    "photo_fail": ("Rasmni yuklab bo‘lmadi. Qayta yuboring.", "Не удалось загрузить фото. Отправьте ещё раз."),
    "photo_dup": ("Bu rasm allaqachon yuborilgan. Boshqa rasm yuboring.", "Это фото уже отправлено. Отправьте другое."),
    "photo_big": ("Rasm hajmi katta. Kichikroq rasm yuboring.", "Фото слишком большое. Отправьте фото поменьше."),
    "done": ("✅ Ro‘yxatdan o‘tdingiz, rahmat!", "✅ Регистрация завершена, спасибо!"),
    "pending": (
        "⏳ Arizangiz ko‘rib chiqilmoqda. Tasdiqlangach, foydalanish mumkin bo‘ladi.",
        "⏳ Ваша заявка на рассмотрении. После подтверждения вы сможете пользоваться ботом.",
    ),
    "need_reg": ("Avval ro‘yxatdan o‘ting: /start ni bosing.", "Сначала пройдите регистрацию: нажмите /start."),

    # main menu
    "choice": ("Hurmatli fuqaro, iltimos, kerakli bo‘limni tanlang:", "Уважаемый гражданин, пожалуйста, выберите нужный раздел:"),
    "btn_design": ("🎨 Dizayn-kod", "🎨 Дизайн-код"),
    "btn_concept": ("🏙 Konsepsiya", "🏙 Концепция"),
    "btn_feedback": ("📝 Murojaat yuborish", "📝 Отправить обращение"),
    "btn_back": ("⬅️ Orqaga", "⬅️ Назад"),
    "design_title": ("Dizayn-kod", "Дизайн-код"),

    # districts, streets, files
    "districts": ("Tumanni tanlang:", "Выберите район:"),
    "streets": ("<b>{}</b>\nKo‘chani tanlang:", "<b>{}</b>\nВыберите улицу:"),
    "empty": ("<b>{}</b>\nBu tuman uchun fayllar hali yuklanmagan.", "<b>{}</b>\nДля этого района файлы ещё не загружены."),
    "contact": (
        "\n\n📞 Savol, taklif yoki e’tirozlaringiz bo‘lsa, {} bo‘yicha quyidagi raqamga murojaat qiling:\n<b>{}</b>",
        "\n\n📞 По вопросам, предложениям и возражениям по району «{}» обращайтесь по номеру:\n<b>{}</b>",
    ),
    "mb": ("MB", "МБ"),
    "stale": ("Menyu yangilangan. /start ni bosing.", "Меню обновлено. Нажмите /start."),
    "error": ("⚠️ Xatolik yuz berdi. Birozdan keyin qayta urinib ko‘ring.", "⚠️ Произошла ошибка. Попробуйте ещё раз чуть позже."),
    "just_sent": ("✅ Bu fayl hozirgina yuborildi — chatda yuqoriroqda.", "✅ Этот файл только что отправлен — он выше в чате."),
    "busy": ("⏳ Oldingi fayl yuklanmoqda, kutib turing.", "⏳ Предыдущий файл ещё отправляется, подождите."),
    "loading": ("⏳ «{}» yuklanmoqda...", "⏳ «{}» загружается..."),
    "eta": ("\nTaxminan: {}", "\nПримерно: {}"),
    "eta_few": ("bir necha soniya", "несколько секунд"),
    "eta_sec": ("~{} soniya", "~{} сек."),
    "eta_min": ("~{} daqiqa", "~{} мин."),
    "failed": (
        "❌ «{}» yuklanishi to‘xtadi. Qayta urinib ko‘ring yoki havoladan foydalaning.",
        "❌ Отправка «{}» прервалась. Попробуйте ещё раз или воспользуйтесь ссылкой.",
    ),
    "no_file": ("Bu bo‘lim uchun fayl hali yuklanmagan.", "Файл для этого раздела ещё не загружен."),
    "link": (
        "{} — {}\nFayl katta ({} MB), yuklab olish uchun: 📥 <a href=\"{}\">Havola</a>\n\n(Havola {} soat amal qiladi)",
        "{} — {}\nФайл большой ({} МБ), для скачивания: 📥 <a href=\"{}\">Ссылка</a>\n\n(Ссылка действует {} ч.)",
    ),

    # requests (murojaat)
    "fb_level": (
        "E’tiroz, taklif yoki ehtiyojlaringizni yuborishingiz mumkin. Murojaatlar mas’ul xodimlar tomonidan ko‘rib chiqiladi "
        "va javob shu yerga yuboriladi.\n\nIltimos, muammo darajasini belgilang:",
        "Вы можете отправить возражение, предложение или потребность. Обращения рассматриваются ответственными сотрудниками, "
        "ответ придёт сюда.\n\nПожалуйста, укажите уровень проблемы:",
    ),
    "lvl_low": ("🟢 Past", "🟢 Низкий"),
    "lvl_medium": ("🟡 O‘rta", "🟡 Средний"),
    "lvl_high": ("🔴 Yuqori", "🔴 Высокий"),
    "fb_write": (
        "Muammo darajasi: {}\n\nMurojaatingiz matnini bitta xabarda batafsil yozib yuboring "
        "(manzil, muammo mazmuni va taklifingiz).\n\nBekor qilish uchun /start ni bosing.",
        "Уровень проблемы: {}\n\nПодробно опишите обращение одним сообщением "
        "(адрес, суть проблемы и ваше предложение).\n\nДля отмены нажмите /start.",
    ),
    "fb_text_only": ("Iltimos, murojaatingizni matn ko‘rinishida yuboring.", "Пожалуйста, отправьте обращение текстом."),
    "fb_short": ("Murojaat matni juda qisqa. Iltimos, muammoni batafsilroq yozing.", "Текст обращения слишком короткий. Опишите проблему подробнее."),
    "fb_long": (
        "Murojaat matni {} belgidan oshmasligi kerak. Iltimos, qisqartirib qayta yuboring.",
        "Текст обращения не должен превышать {} символов. Сократите и отправьте снова.",
    ),
    "fb_limit": (
        "Bir kunda {} tadan ortiq murojaat yuborish mumkin emas. Iltimos, ertaga qayta urinib ko‘ring.",
        "Нельзя отправить более {} обращений в день. Попробуйте завтра.",
    ),
    "fb_saved": (
        "✅ Murojaatingiz qabul qilindi.\nMurojaat raqami: №{}\n\nMurojaatingiz mas’ul xodimlar tomonidan ko‘rib chiqilib, "
        "javob shu yerga yuboriladi.",
        "✅ Ваше обращение принято.\nНомер обращения: №{}\n\nОбращение рассмотрят ответственные сотрудники, ответ придёт сюда.",
    ),
    "fb_answer": (
        "📩 <b>№{} murojaatingizga javob</b>\n\n<i>Sizning murojaatingiz:</i>\n{}\n\n<b>Javob:</b>\n{}",
        "📩 <b>Ответ на ваше обращение №{}</b>\n\n<i>Ваше обращение:</i>\n{}\n\n<b>Ответ:</b>\n{}",
    ),

    # district staff
    "staff_menu": (
        "👷 Tuman xodimi: <b>{}</b>\n{}\n\nObyektlar: <b>{}</b>\n🔧 Yechildi (jarayonda): <b>{}</b>\n"
        "✅ Dizayn-kod bo‘yicha qo‘yildi: <b>{}</b>\n⏳ Tekshiruvda: <b>{}</b>\n\nObyektlar bilan ishlash uchun pastdagi tugmani bosing.",
        "👷 Сотрудник района: <b>{}</b>\n{}\n\nОбъектов: <b>{}</b>\n🔧 Снято (в процессе): <b>{}</b>\n"
        "✅ Установлено по дизайн-коду: <b>{}</b>\n⏳ На проверке: <b>{}</b>\n\nДля работы с объектами нажмите кнопку ниже.",
    ),
    "btn_objects": ("📋 Obyektlarni ochish", "📋 Открыть объекты"),
    "btn_refresh": ("🔄 Yangilash", "🔄 Обновить"),
    "staff_confirm": (
        "Siz tuman xodimlari ro‘yxatidasiz. Tasdiqlash uchun pastdagi tugma orqali raqamingizni yuboring.",
        "Вы есть в списке сотрудников района. Для подтверждения отправьте свой номер кнопкой ниже.",
    ),
    "staff_linked": ("✅ Xodim sifatida tasdiqlandingiz.", "✅ Вы подтверждены как сотрудник."),

    # bot command menu and the one-time announcement after the two-language update (tools/broadcast.py)
    "cmd_start": ("Botni ishga tushirish / tilni tanlash", "Запустить бота / выбрать язык"),
    "broadcast_restart": (
        "Hurmatli foydalanuvchi! Bot yangilandi: endi u o‘zbek va rus tillarida ishlaydi. "
        "Iltimos, /start ni qaytadan bosing.",
        "Уважаемый пользователь! Бот обновлён: теперь он работает на узбекском и русском языках. "
        "Пожалуйста, нажмите /start ещё раз.",
    ),

    # flood protection
    "muted": (
        "⛔ Juda tez-tez bosdingiz. Bir necha daqiqadan keyin qayta urinib ko‘ring.",
        "⛔ Слишком частые нажатия. Попробуйте через несколько минут.",
    ),
    "bot_busy": ("⏳ Bot band, birozdan keyin qayta urinib ko‘ring.", "⏳ Бот занят, попробуйте чуть позже."),
}

_cache: dict[int, str] = {}


def t(lang: str, key: str, *args) -> str:
    text = TEXTS[key][1 if lang == "ru" else 0]
    return text.format(*args) if args else text


def lang(user_id: int | None) -> str:
    """The user's language (uz until they pick one)."""
    if user_id is None:
        return DEFAULT
    if user_id not in _cache:
        u = db.get_user(user_id) or {}
        _cache[user_id] = u.get("lang") if u.get("lang") in LANGS else DEFAULT
    return _cache[user_id]


def cached_lang(user_id: int | None) -> str:
    """Without touching the database (flood protection runs before anything else)."""
    return _cache.get(user_id, DEFAULT) if user_id is not None else DEFAULT


def set_lang(user_id: int, value: str) -> None:
    db.update_user(user_id, lang=value)
    _cache[user_id] = value


def has_lang(user_id: int) -> bool:
    return (db.get_user(user_id) or {}).get("lang") in LANGS


_CYR = {
    "а": "a", "б": "b", "в": "v", "г": "g", "ғ": "g‘", "д": "d", "ж": "j", "з": "z", "и": "i", "й": "y", "к": "k",
    "қ": "q", "л": "l", "м": "m", "н": "n", "о": "o", "п": "p", "р": "r", "с": "s", "т": "t", "у": "u", "ў": "o‘",
    "ф": "f", "х": "x", "ҳ": "h", "ц": "s", "ч": "ch", "ш": "sh", "щ": "sh", "ъ": "’", "ы": "i", "ь": "", "э": "e",
    "ё": "yo", "ю": "yu", "я": "ya",
}
_VOWELS = set("аеёиоуўэюяaeiou")


def latin(s: str) -> str:
    """Uzbek Cyrillic -> Latin (official 1995 alphabet rules for е and ц). Text with no Cyrillic is returned as is."""
    out = []
    for i, ch in enumerate(s):
        low = ch.lower()
        if low == "е":  # ye at the start of a word or after a vowel, e otherwise
            prev = s[i - 1].lower() if i else " "
            r = "ye" if (not prev.isalpha() or prev in _VOWELS or prev in "ъь") else "e"
        else:
            r = _CYR.get(low)
        if r is None:
            out.append(ch)
            continue
        if ch != low and r:  # keep capitals: "Ш" -> "Sh", but an all-caps word -> "SH"
            nxt = s[i + 1] if i + 1 < len(s) else ""
            r = r.upper() if (nxt.isupper() and len(r) > 1) else r[0].upper() + r[1:]
        out.append(r)
    return "".join(out)


def name(lang_: str, s: str) -> str:
    """A district / street name for display: stored in Uzbek Cyrillic, shown in Latin to Uzbek-language users."""
    return latin(s) if lang_ == "uz" else s
