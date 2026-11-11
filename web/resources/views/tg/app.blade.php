<!doctype html>
<html lang="uz">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title>Obyektlar</title>
<script src="https://telegram.org/js/telegram-web-app.js?57"></script>
<script src="/tg/location.js?v={{ $version }}"></script>
{{-- the map step (the object's place): Leaflet + OpenStreetMap tiles --}}
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<style>
  :root { --bg: var(--tg-theme-bg-color, #fff); --text: var(--tg-theme-text-color, #111); --hint: var(--tg-theme-hint-color, #888);
    --link: var(--tg-theme-link-color, #2481cc); --btn: var(--tg-theme-button-color, #2481cc); --btn-text: var(--tg-theme-button-text-color, #fff);
    --card: var(--tg-theme-secondary-bg-color, #f2f3f5); --red: #d93025; --green: #188038; --amber: #b06000; }
  * { box-sizing: border-box; }
  body { margin: 0; font: 15px/1.4 -apple-system, system-ui, "Segoe UI", Roboto, sans-serif; background: var(--bg); color: var(--text); }
  header { position: sticky; top: 0; z-index: 5; background: var(--bg); padding: 10px 14px; border-bottom: 1px solid var(--card); display: flex; gap: 10px; align-items: center; }
  header button { border: 0; background: none; color: var(--link); font-size: 17px; padding: 8px 10px 8px 0; min-height: 40px; }
  body.in-tg #back { display: none !important; }  /* Telegram's own Back button is used instead */
  header h1 { font-size: 16px; margin: 0; flex: 1; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
  main { padding: 12px 14px 90px; }
  .muted { color: var(--hint); font-size: 13px; }
  .card { background: var(--card); border-radius: 12px; padding: 12px; margin-bottom: 10px; }
  .row { display: flex; justify-content: space-between; align-items: center; gap: 8px; }
  .stats { display: grid; grid-template-columns: repeat(4, 1fr); gap: 6px; text-align: center; }
  .stats b { display: block; font-size: 18px; }
  .stats span { font-size: 11px; color: var(--hint); }
  .street { cursor: pointer; }
  .street .name { font-weight: 600; }
  .pill { display: inline-block; font-size: 12px; padding: 2px 8px; border-radius: 10px; background: var(--bg); margin: 2px 2px 0 0; }
  .s-pending { color: var(--hint); } .s-removed { color: var(--amber); } .s-installed { color: var(--green); } .s-review { color: var(--link); }
  .grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 8px; }
  .obj { background: var(--card); border-radius: 10px; overflow: hidden; cursor: pointer; }
  .obj img { width: 100%; aspect-ratio: 4/3; object-fit: cover; display: block; background: #ddd; }
  .obj div { padding: 6px 8px; font-size: 13px; }
  .chips { display: flex; gap: 6px; overflow-x: auto; margin-bottom: 10px; }
  .chip { border: 1px solid var(--hint); border-radius: 18px; padding: 8px 12px; min-height: 36px; font-size: 13px; white-space: nowrap; background: none; color: var(--text); }
  .chip.on { background: var(--btn); color: var(--btn-text); border-color: var(--btn); }
  .page { width: 100%; border-radius: 10px; display: block; }
  label { display: block; font-size: 13px; color: var(--hint); margin: 8px 0 3px; }
  input, textarea, select { width: 100%; font: inherit; padding: 9px 10px; border-radius: 8px; border: 1px solid var(--hint); background: var(--bg); color: var(--text); }
  textarea { min-height: 90px; }
  .btn { display: block; width: 100%; border: 0; border-radius: 10px; padding: 13px; font: 600 15px inherit; background: var(--btn); color: var(--btn-text); margin-top: 10px; }
  .btn.alt { background: var(--card); color: var(--text); }
  .card .btn.alt { background: var(--bg); border: 1px solid var(--line, rgba(127,127,127,.35)); }
  .row > * { min-width: 0; overflow-wrap: anywhere; }
  .btn.danger { background: var(--red); color: #fff; }
  .btn:disabled { opacity: .55; }
  .btn.link { background: none; color: var(--link); padding: 8px; font-weight: 500; }
  .err { color: var(--red); margin: 8px 0; white-space: pre-wrap; }
  .ok { color: var(--green); margin: 8px 0; }
  .steps { display: flex; gap: 4px; margin-bottom: 12px; }
  .steps i { flex: 1; height: 4px; border-radius: 2px; background: var(--card); }
  .steps i.on { background: var(--btn); }
  video, canvas.preview { width: 100%; border-radius: 10px; background: #000; display: block; }
  .rec { color: var(--red); font-weight: 600; }
  .center { text-align: center; }
  #map { height: 52vh; min-height: 280px; border-radius: 10px; }
</style>
</head>
<body>
<header><button id="back" hidden>‹</button><h1 id="title">…</h1></header>
<main id="app"><p class="muted center">…</p></main>
<script>
(function () {
  'use strict';
  var tg = window.Telegram && window.Telegram.WebApp;
  if (tg) {
    tg.ready(); tg.expand();
    // dragging the map or marker must not minimise the app
    if (tg.disableVerticalSwipes) try { tg.disableVerticalSwipes(); } catch (e) {}
  }
  var params = new URLSearchParams(location.search);
  var LANG = params.get('lang') === 'ru' ? 'ru' : 'uz';
  var T = {
    uz: { loading: 'Yuklanmoqda…', title: 'Obyektlar', total: 'Jami', removed: 'Yechildi', installed: 'Qo‘yildi', review: 'Tekshiruvda',
      pending: 'Kutilmoqda', all: 'Hammasi', streetsHint: 'Ko‘chani tanlang', priority: 'Asosiy', none: 'Bu yerda obyekt yo‘q.', findBtn: '📍 Joyimni aniqlash va obyektni topish', findHint: 'Obyekt oldida turib bosing: manzil va yaqin atrofdagi obyektlar chiqadi.', findWait: 'Joylashuv aniqlanmoqda…', here: 'Siz turgan joy', noAddress: 'Manzil topilmadi — o‘zingiz yozing.', streetLabel: 'Ko‘cha', pickStreet: '— ko‘chani tanlang —', nearbyTitle: 'Yaqin atrofdagi obyektlar ({n} m gacha)', nearbyNone: 'Yaqin atrofda kiritilgan obyekt yo‘q.', addHere: '➕ Shu joyda yangi obyekt qo‘shish', refind: '🔄 Qayta aniqlash', address: 'Manzil', m: 'm', editDetails: '✏️ Ma’lumotlarni tahrirlash', orBrowse: 'Yoki ko‘chani ro‘yxatdan tanlang:', chooseHint: 'Kerakli obyektni tanlang — rasm, video va joylashuv faqat unga yoziladi.', needStreet: 'Avval ko‘chani tanlang.', exists: 'Bu nomdagi obyekt bu ko‘chada allaqachon bor — o‘sha ochildi.',
      added: 'Kiritilgan', pdf: '📄 Konsepsiya (PDF)', addTitle: 'Tadbirkor qo‘shish', addBtn: '➕ Tadbirkor qo‘shish', addHint: 'Ko‘chadagi do‘kon yoki firmani kiriting. Kerakli ko‘rinish — konsepsiya PDF’ida.', create: 'Qo‘shish',
      obj: 'Obyekt', shop: 'Do‘kon / firma nomi', owner: 'Tadbirkor F.I.Sh.', phone: 'Tadbirkor telefoni', stir: 'STIR (9 raqam)',
      save: 'Saqlash', saved: 'Saqlandi', status: 'Holat', startRemoved: '🔧 Eski peshtaxta yechildi', startInstalled: '✅ Dizayn-kod bo‘yicha qo‘yildi',
      inReview: 'Bu obyekt bo‘yicha dalil tekshiruvda. Admin natijasini kuting.', continueDraft: 'Tugallanmagan tekshiruvni davom ettirish',
      history: 'Tarix', approved: 'Tasdiqlandi', rejected: 'Rad etildi', st1: '1/5 · Jonli rasm', st2: '2/5 · Sizning joylashuvingiz', st3: '3/5 · Obyekt joylashuvi', st4: '4/5 · Jonli video', st5: '5/5 · Izoh',
      pointHint: 'Xaritada obyektni belgilang: belgini suring yoki xaritaga bosing. Ko‘k doira — siz turgan joydan {x} m.',
      pointBtn: '📍 Obyekt shu yerda — tasdiqlash', pointKnown: 'Obyektning tasdiqlangan joyi ko‘rsatilgan. To‘g‘ri bo‘lsa, tasdiqlang.', mapErr: 'Xarita yuklanmadi. Internetni tekshirib, qayta oching.',
      locHint: 'Obyekt yonida turib, o‘z joylashuvingizni yuboring.', locBtn: '📍 Joylashuvni aniqlash', locWait: 'Joylashuv aniqlanmoqda…',
      locDenied: 'Joylashuvga ruxsat berilmagan.', openSettings: 'Ruxsatni yoqish', retry: 'Qayta urinish', locNone: 'Joylashuv aniqlanmadi. GPS yoqilganini tekshirib, qayta urinib ko‘ring.',
      camStart: '📷 Kamerani yoqish', shot: '📸 Rasmga olish', retake: 'Qayta olish', send: 'Yuborish', camErr: 'Kamera ochilmadi. Telegram’ga kameraga ruxsat bering.',
      photoHint: 'Obyektni (peshtaxta va fasad) to‘liq ko‘rinadigan qilib rasmga oling.',
      videoHint: '3–15 soniyalik video: obyekt va atrofi. Yozish 15 soniyada o‘zi to‘xtaydi.', recStart: '⏺ Yozishni boshlash', recStop: '⏹ To‘xtatish',
      recShort: 'Video kamida 3 soniya bo‘lsin.', recNo: 'Bu qurilmada video yozib bo‘lmaydi. Telegram va telefon tizimini yangilang.', cancel: 'Bekor qilish', slow: 'Yuborish juda uzoq davom etdi. Internetni tekshirib, qayta yuboring.', uploading: 'Yuborilmoqda…', commentHint: 'Nima qilindi? Masalan: “Eski peshtaxta olib tashlandi, yangisi buyurtma qilingan”.',
      comment: 'Izoh', submit: 'Tekshiruvga yuborish', done: 'Yuborildi! Admin tasdiqlagach, holat o‘zgaradi.', toObject: 'Obyektga qaytish',
      otherDraft: 'Sizda boshqa obyekt bo‘yicha tugallanmagan tekshiruv bor ({x}). Uni o‘chirib, yangisini boshlaysizmi?', discard: 'Ha, o‘chirib yangisini boshlash',
      openOther: 'Yo‘q, o‘sha obyektni ochish', kindRemoved: 'Yechildi', kindInstalled: 'Qo‘yildi', netErr: 'Internet bilan muammo. Qayta urinib ko‘ring.',
      noTg: 'Bu oyna faqat Telegram ichida ishlaydi. Botdagi “Obyektlarni ochish” tugmasini bosing.' },
    ru: { loading: 'Загрузка…', title: 'Объекты', total: 'Всего', removed: 'Снято', installed: 'Установлено', review: 'На проверке',
      pending: 'Ожидает', all: 'Все', streetsHint: 'Выберите улицу', priority: 'Основная', none: 'Здесь нет объектов.', findBtn: '📍 Определить место и найти объект', findHint: 'Нажмите, стоя у объекта: появится адрес и объекты рядом.', findWait: 'Определяем местоположение…', here: 'Вы находитесь', noAddress: 'Адрес не найден — впишите сами.', streetLabel: 'Улица', pickStreet: '— выберите улицу —', nearbyTitle: 'Объекты рядом (до {n} м)', nearbyNone: 'Рядом нет внесённых объектов.', addHere: '➕ Добавить новый объект здесь', refind: '🔄 Определить снова', address: 'Адрес', m: 'м', editDetails: '✏️ Изменить данные', orBrowse: 'Или выберите улицу из списка:', chooseHint: 'Выберите нужный объект — фото, видео и место запишутся только к нему.', needStreet: 'Сначала выберите улицу.', exists: 'Объект с таким названием на этой улице уже есть — открыт он.',
      added: 'Внесено', pdf: '📄 Концепция (PDF)', addTitle: 'Добавить предпринимателя', addBtn: '➕ Добавить предпринимателя', addHint: 'Внесите магазин или фирму на улице. Нужный вид — в PDF концепции.', create: 'Добавить',
      obj: 'Объект', shop: 'Название магазина / фирмы', owner: 'Ф.И.О. предпринимателя', phone: 'Телефон предпринимателя', stir: 'ИНН (9 цифр)',
      save: 'Сохранить', saved: 'Сохранено', status: 'Статус', startRemoved: '🔧 Старая вывеска снята', startInstalled: '✅ Установлено по дизайн-коду',
      inReview: 'Доказательства по этому объекту на проверке. Дождитесь решения администратора.', continueDraft: 'Продолжить незавершённую проверку',
      history: 'История', approved: 'Подтверждено', rejected: 'Отклонено', st1: '1/5 · Фото', st2: '2/5 · Ваше местоположение', st3: '3/5 · Место объекта', st4: '4/5 · Видео', st5: '5/5 · Комментарий',
      pointHint: 'Отметьте объект на карте: передвиньте метку или нажмите на карту. Синий круг — {x} м от вас.',
      pointBtn: '📍 Объект здесь — подтвердить', pointKnown: 'Показано подтверждённое место объекта. Если верно — подтвердите.', mapErr: 'Карта не загрузилась. Проверьте интернет и откройте снова.',
      locHint: 'Встаньте у объекта и отправьте своё местоположение.', locBtn: '📍 Определить местоположение', locWait: 'Определяем местоположение…',
      locDenied: 'Нет доступа к местоположению.', openSettings: 'Разрешить доступ', retry: 'Повторить', locNone: 'Не удалось определить местоположение. Проверьте GPS и попробуйте снова.',
      camStart: '📷 Включить камеру', shot: '📸 Сфотографировать', retake: 'Переснять', send: 'Отправить', camErr: 'Камера не открылась. Разрешите Telegram доступ к камере.',
      photoHint: 'Сфотографируйте объект так, чтобы вывеска и фасад были видны полностью.',
      videoHint: 'Видео 3–15 секунд: объект и окрестности. Запись остановится сама через 15 секунд.', recStart: '⏺ Начать запись', recStop: '⏹ Остановить',
      recShort: 'Видео должно быть не короче 3 секунд.', recNo: 'На этом устройстве нельзя записать видео. Обновите Telegram и систему телефона.', cancel: 'Отменить', slow: 'Отправка заняла слишком долго. Проверьте интернет и отправьте снова.', uploading: 'Отправка…', commentHint: 'Что сделано? Например: «Старая вывеска снята, новая заказана».',
      comment: 'Комментарий', submit: 'Отправить на проверку', done: 'Отправлено! Статус изменится после подтверждения администратором.', toObject: 'Вернуться к объекту',
      otherDraft: 'У вас есть незавершённая проверка по другому объекту ({x}). Удалить её и начать новую?', discard: 'Да, удалить и начать новую',
      openOther: 'Нет, открыть тот объект', kindRemoved: 'Снято', kindInstalled: 'Установлено', netErr: 'Проблема с интернетом. Попробуйте ещё раз.',
      noTg: 'Это окно работает только внутри Telegram. Нажмите в боте кнопку «Открыть объекты».' }
  };
  function t(k, x) { var s = (T[LANG][k] || k); return x !== undefined ? s.replace('{x}', x) : s; }
  var app = document.getElementById('app'), titleEl = document.getElementById('title'), backBtn = document.getElementById('back');
  var stack = [];       // navigation history (functions that redraw a screen)
  var stream = null;    // the open camera, closed whenever the screen changes
  var locator = window.TgLocation.makeLocator();

  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  function h(html) { app.innerHTML = html; window.scrollTo(0, 0); }
  // nav changes with every screen change: a response that arrives after the user left its screen draws nothing
  var nav = 0, camReq = 0, lastPush = 0;
  function stopCamera() { camReq++; if (stream) { stream.getTracks().forEach(function (tr) { tr.stop(); }); stream = null; } }
  var xhr = null;  // the running upload; leaving its screen cancels it
  function guardClose(on) { if (tg && tg.enableClosingConfirmation) try { on ? tg.enableClosingConfirmation() : tg.disableClosingConfirmation(); } catch (e) {} }
  function abortUpload() { if (xhr) { var x = xhr; xhr = null; x.abort(); } guardClose(false); }
  function show(screen) {
    var now = Date.now();
    if (now - lastPush < 400) return;  // a double tap opens the screen once
    lastPush = now; nav++; abortUpload();
    stopCamera(); stack.push(screen); backBtn.hidden = stack.length < 2; screen();
  }
  function back() { nav++; abortUpload(); stopCamera(); stack.pop(); var s = stack[stack.length - 1]; backBtn.hidden = stack.length < 2; if (s) s(); }
  backBtn.onclick = back;
  if (tg && tg.initData) document.body.classList.add('in-tg');
  if (tg && tg.BackButton) { tg.BackButton.onClick(function () { if (stack.length > 1) back(); else tg.close(); }); }
  function syncBack() { if (tg && tg.BackButton) { if (stack.length > 1) tg.BackButton.show(); else tg.BackButton.hide(); } }

  // every API call carries Telegram's signed initData; no cookies are used
  function api(path, opts) {
    opts = opts || {};
    var headers = { 'X-Telegram-Init-Data': tg ? tg.initData : '', 'Accept': 'application/json' };
    var body = opts.body;
    if (body && !(body instanceof FormData)) { headers['Content-Type'] = 'application/json'; body = JSON.stringify(body); }
    var my = nav;
    return fetch('/tg/api' + path, { method: opts.method || (body ? 'POST' : 'GET'), headers: headers, body: body })
      .catch(function () { throw { error: t('netErr') }; })
      .then(function (r) {
        if (my !== nav) return new Promise(function () {});  // the user moved on: never render this answer
        return r.json().catch(function () { return {}; }).then(function (d) {
          if (!r.ok) { d.status = r.status; if (!d.error) d.error = d.message || ('Xato ' + r.status); throw d; }
          return d;
        });
      });
  }
  // a button that ignores further presses until its action finishes
  function busy(btn, fn) {
    if (!btn || btn.disabled) return;
    btn.disabled = true;
    Promise.resolve().then(fn).catch(function (e) { showErr(e); }).then(function () { btn.disabled = false; });
  }
  function showErr(e) {
    if (e && e.aborted) return;
    var el = document.getElementById('err');
    var msg = (e && (e.error || (e.errors && Object.values(e.errors)[0][0]))) || (e && e.message) || t('netErr');
    if (el) el.textContent = msg; else alert(msg);
  }
  function statusPill(o) {
    if (o.in_review) return '<span class="pill s-review">⏳ ' + t('review') + '</span>';
    return '<span class="pill s-' + o.status + '">' + t(o.status) + '</span>';
  }

  // ------------------------------------------------------------------ screens
  var me = null;
  function home() {
    titleEl.textContent = t('title'); syncBack();
    h('<p class="muted center">' + t('loading') + '</p>');
    api('/me').then(function (d) {
      me = d; LANG = d.lang === 'ru' ? 'ru' : 'uz';
      titleEl.textContent = d.staff.district || t('title');
      var tot = d.totals;
      var html = '<div class="card"><div class="muted">' + esc(d.staff.name) + '</div><div class="stats" style="margin-top:8px">' +
        '<div><b>' + tot.total + '</b><span>' + t('total') + '</span></div><div><b class="s-removed">' + tot.removed + '</b><span>' + t('removed') + '</span></div>' +
        '<div><b class="s-installed">' + tot.installed + '</b><span>' + t('installed') + '</span></div><div><b class="s-review">' + tot.review + '</b><span>' + t('review') + '</span></div></div></div>';
      if (d.draft) html += '<button class="btn alt" id="draft">↩︎ ' + t('continueDraft') + '</button>';
      html += '<button class="btn" id="find">' + t('findBtn') + '</button><p class="muted">' + t('findHint') + '</p>';
      html += '<p class="muted" style="margin-top:16px">' + t('orBrowse') + '</p>';
      d.streets.forEach(function (s) {
        html += '<div class="card street" data-id="' + s.id + '"><div class="row"><span class="name">' + esc(s.name) + '</span>' +
          (s.priority ? '<span class="pill">⭐ ' + t('priority') + '</span>' : '') + '</div><div>' +
          '<span class="pill">' + t('total') + ': ' + s.total + '</span><span class="pill">' + t('added') + ': ' + s.added + '</span><span class="pill s-removed">' + t('removed') + ': ' + s.removed + '</span>' +
          '<span class="pill s-installed">' + t('installed') + ': ' + s.installed + '</span>' + (s.review ? '<span class="pill s-review">' + t('review') + ': ' + s.review + '</span>' : '') +
          '</div></div>';
      });
      if (!d.streets.length) html += '<p class="muted">' + t('none') + '</p>';
      h(html);
      app.querySelectorAll('.street').forEach(function (el) { el.onclick = function () { var s = d.streets.find(function (x) { return x.id == el.dataset.id; }); show(function () { street(s); }); }; });
      var dr = document.getElementById('draft');
      if (dr) dr.onclick = function () { show(function () { object(d.draft.object_id); }); };
      document.getElementById('find').onclick = function () { show(findHere); };
    }).catch(fatal);
  }

  // "Find my place": where the staff member stands -> the address, the street, the businesses nearby -> pick one or add one here
  var lastPlace = null;
  function findHere() {
    titleEl.textContent = t('here'); syncBack();
    h('<p class="muted center">' + t('findWait') + '</p><div id="err" class="err"></div><div id="extra"></div>');
    var my = nav;
    locator.locate().then(function (p) {
      if (my !== nav) return;
      return api('/nearby', { body: { latitude: p.latitude, longitude: p.longitude } }).then(function (d) {
        lastPlace = { lat: p.latitude, lon: p.longitude, accuracy: p.accuracy, address: d.address, street: d.street, at: Date.now() };
        var opts = '<option value="">' + t('pickStreet') + '</option>' + d.streets.map(function (s) {
          return '<option value="' + s.id + '"' + (d.street && d.street.id === s.id ? ' selected' : '') + '>' + esc(s.name) + '</option>';
        }).join('');
        var list = d.objects.map(function (o) {
          return '<div class="card obj" data-id="' + o.id + '"><div class="row"><b>' + esc(o.shop_name) + '</b>' + statusPill(o) + '</div>' +
            '<div class="muted">' + esc(o.address || o.street || '') + ' · ' + o.distance + ' ' + t('m') + '</div></div>';
        }).join('');
        h('<div class="card"><div class="muted">' + t('here') + '</div><div style="font-weight:600;margin:4px 0 8px">' + esc(d.address || t('noAddress')) + '</div>' +
          '<label>' + t('streetLabel') + '</label><select id="st">' + opts + '</select></div>' +
          '<button class="btn" id="addHere">' + t('addHere') + '</button><div id="err" class="err"></div>' +
          '<p class="muted" style="margin-top:14px">' + t('nearbyTitle').replace('{n}', d.radius) + '</p>' +
          (list ? '<p class="muted">' + t('chooseHint') + '</p>' + list : '<p class="muted">' + t('nearbyNone') + '</p>') +
          '<button class="btn alt" id="again">' + t('refind') + '</button>');
        app.querySelectorAll('.obj').forEach(function (el) { el.onclick = function () { show(function () { object(+el.dataset.id); }); }; });
        document.getElementById('again').onclick = function () { stack[stack.length - 1] = findHere; nav++; findHere(); };
        document.getElementById('addHere').onclick = function () {
          var sel = document.getElementById('st'), id = +sel.value;
          if (!id) { showErr({ error: t('needStreet') }); sel.focus(); return; }
          var s = { id: id, name: sel.options[sel.selectedIndex].text };
          show(function () { addObject(s, lastPlace); });
        };
      });
    }).catch(function (e) {
      if (my !== nav) return;
      h('<div id="err" class="err"></div><div id="extra"></div><button class="btn" id="again">' + t('retry') + '</button>');
      document.getElementById('again').onclick = function () { stack[stack.length - 1] = findHere; nav++; findHere(); };
      if (e && e.code === 'denied') {
        document.getElementById('err').textContent = t('locDenied');
        if (locator.canOpenSettings()) {
          document.getElementById('extra').innerHTML = '<button class="btn alt" id="settings">' + t('openSettings') + '</button>';
          document.getElementById('settings').onclick = function () { locator.openSettings(); };
        }
        return;
      }
      showErr(e && (e.code === 'timeout' || e.code === 'unavailable') ? { error: t('locNone') } : e);
    });
  }

  function street(s, filter) {
    titleEl.textContent = s.name; syncBack();
    filter = filter || 'all';
    h('<p class="muted center">' + t('loading') + '</p>');
    api('/streets/' + s.id + '/objects').then(function (d) {
      var keep = function (o) { return filter === 'all' || (filter === 'review' ? o.in_review : (!o.in_review && o.status === filter)); };
      var chips = ['all', 'pending', 'removed', 'installed', 'review'].map(function (f) {
        return '<button class="chip' + (f === filter ? ' on' : '') + '" data-f="' + f + '">' + t(f) + '</button>';
      }).join('');
      var list = d.objects.filter(keep).map(function (o) {
        return '<div class="card obj" data-id="' + o.id + '"><div class="row"><b>' + esc(o.shop_name) + '</b>' + statusPill(o) + '</div>' +
          (o.owner_name || o.stir ? '<div class="muted">' + esc(o.owner_name || '') + (o.stir ? ' · STIR ' + esc(o.stir) : '') + '</div>' : '') + '</div>';
      }).join('');
      var files = d.files.map(function (f) {
        return '<button class="btn alt pdf" data-url="' + esc(f.url) + '">' + t('pdf') + ' · ' + f.mb + ' MB</button>';
      }).join('');
      h(files + '<p class="muted">' + t('total') + ': ' + d.street.total + ' · ' + t('added') + ': ' + d.objects.length + '</p>' +
        '<button class="btn" id="add">' + t('addBtn') + '</button><div class="chips" style="margin-top:12px">' + chips + '</div>' +
        (list || '<p class="muted">' + t('none') + '</p>'));
      app.querySelectorAll('.pdf').forEach(function (b) {
        // the PDF opens in the phone's browser / PDF viewer (a Mini App cannot show a large PDF itself)
        b.onclick = function () { var u = location.origin + b.dataset.url; if (tg && tg.openLink) tg.openLink(u); else window.open(u, '_blank'); };
      });
      document.getElementById('add').onclick = function () { show(function () { addObject(s); }); };
      app.querySelectorAll('.chip').forEach(function (c) { c.onclick = function () { stack[stack.length - 1] = function () { street(s, c.dataset.f); }; street(s, c.dataset.f); }; });
      app.querySelectorAll('.obj').forEach(function (el) { el.onclick = function () { show(function () { object(+el.dataset.id); }); }; });
    }).catch(fatal);
  }

  // a business the staff member found on the street
  function addObject(s, place) {
    titleEl.textContent = t('addTitle'); syncBack();
    h('<p class="muted">' + esc(s.name) + '. ' + t('addHint') + '</p><div class="card"><label>' + t('shop') + ' *</label><input id="shop_name" maxlength="150">' +
      '<label>' + t('address') + '</label><input id="address" maxlength="200" value="' + esc(place && place.address || '') + '">' +
      '<label>' + t('owner') + '</label><input id="owner_name" maxlength="150">' +
      '<label>' + t('phone') + '</label><input id="owner_phone" inputmode="tel" maxlength="30">' +
      '<label>' + t('stir') + '</label><input id="stir" inputmode="numeric" maxlength="14"></div><div id="err" class="err"></div>' +
      '<button class="btn" id="go">' + t('create') + '</button>');
    var go = document.getElementById('go');
    go.onclick = function () {
      busy(go, function () {
        var body = {}; ['shop_name', 'address', 'owner_name', 'owner_phone', 'stir'].forEach(function (k) { body[k] = document.getElementById(k).value.trim(); });
        // where the staff member stands is required (no adding from home): reuse a fresh "find my place", else locate now
        var fresh = place && Date.now() - place.at < 120000;
        go.textContent = fresh ? go.textContent : t('locWait');
        return (fresh ? Promise.resolve({ latitude: place.lat, longitude: place.lon, accuracy: place.accuracy }) : locator.locate()).catch(function (e) {
          go.textContent = t('create');
          throw e && e.code === 'denied' ? { error: t('locDenied') } : (e && (e.code === 'timeout' || e.code === 'unavailable') ? { error: t('locNone') } : e);
        }).then(function (p) {
          body.latitude = p.latitude; body.longitude = p.longitude; body.accuracy = p.accuracy;
          return api('/streets/' + s.id + '/objects', { body: body });
        }).then(function (d) {
          var fl = d.already ? t('exists') : null;
          nav++; stack.pop(); stack.push(function () { object(d.object.id); }); object(d.object.id, fl);
        }).catch(function (e) { go.textContent = t('create'); throw e; });
      });
    };
  }

  function object(id, flash) {
    syncBack();
    h('<p class="muted center">' + t('loading') + '</p>');
    api('/objects/' + id).then(function (o) {
      titleEl.textContent = o.shop_name;
      var html = '<div class="card"><div class="row"><b>' + esc(o.shop_name) + '</b>' + statusPill(o) + '</div>' +
        '<div class="muted">' + esc([o.street, o.address].filter(Boolean).join(' · ')) + '</div>' +
        (o.owner_name || o.stir ? '<div class="muted">' + esc(o.owner_name || '') + (o.stir ? ' · STIR ' + esc(o.stir) : '') + '</div>' : '') + '</div>' +
        (flash ? '<p class="ok">' + esc(flash) + '</p>' : '') + '<div id="err" class="err"></div>';
      if (o.draft) {
        html += '<button class="btn" id="cont">↩︎ ' + t('continueDraft') + ' (' + t(o.draft.kind === 'removed' ? 'kindRemoved' : 'kindInstalled') + ')</button>';
      } else if (o.in_review) {
        html += '<div class="card">⏳ ' + t('inReview') + '</div>';
      } else {
        if (o.status === 'pending') html += '<button class="btn" data-kind="removed">' + t('startRemoved') + '</button>';
        if (o.status !== 'installed') html += '<button class="btn" data-kind="installed">' + t('startInstalled') + '</button>';
      }
      html += '<details class="card" style="margin-top:12px"><summary style="font-weight:600;cursor:pointer">' + t('editDetails') + '</summary>' +
        '<label>' + t('shop') + '</label><input id="shop_name" maxlength="150" value="' + esc(o.shop_name) + '">' +
        '<label>' + t('address') + '</label><input id="address" maxlength="200" value="' + esc(o.address) + '">' +
        '<label>' + t('owner') + '</label><input id="owner_name" maxlength="150" value="' + esc(o.owner_name) + '">' +
        '<label>' + t('phone') + '</label><input id="owner_phone" inputmode="tel" maxlength="30" value="' + esc(o.owner_phone) + '">' +
        '<label>' + t('stir') + '</label><input id="stir" inputmode="numeric" maxlength="14" value="' + esc(o.stir) + '">' +
        '<button class="btn alt" id="save">' + t('save') + '</button></details>';
      if (o.history && o.history.length) {
        html += '<p class="muted">' + t('history') + '</p>' + o.history.map(function (x) {
          var r = x.review === 'approved' ? '<span class="s-installed">' + t('approved') + '</span>' : x.review === 'rejected' ? '<span style="color:var(--red)">' + t('rejected') + '</span>' : '<span class="s-review">' + t('review') + '</span>';
          return '<div class="card"><div class="row"><span>' + t(x.kind === 'removed' ? 'kindRemoved' : 'kindInstalled') + '</span>' + r + '</div><div class="muted">' + esc(x.at) + (x.note ? ' · ' + esc(x.note) : '') + '</div></div>';
        }).join('');
      }
      h(html);
      var cont = document.getElementById('cont');
      if (cont) cont.onclick = function () { show(function () { wizard(o, o.draft); }); };
      app.querySelectorAll('[data-kind]').forEach(function (b) { b.onclick = function () { busy(b, function () { return begin(o, b.dataset.kind, false); }); }; });
      var save = document.getElementById('save');
      save.onclick = function () {
        busy(save, function () {
          var body = {}; ['shop_name', 'address', 'owner_name', 'owner_phone', 'stir'].forEach(function (k) { body[k] = document.getElementById(k).value.trim(); });
          return api('/objects/' + o.id, { body: body }).then(function () { document.getElementById('err').innerHTML = '<span class="ok">' + t('saved') + '</span>'; });
        });
      };
    }).catch(fatal);
  }

  function begin(o, kind, discardOther) {
    return api('/objects/' + o.id + '/inspections', { body: { kind: kind, discard_other: discardOther } })
      .then(function (d) { show(function () { wizard(o, d.draft); }); })
      .catch(function (e) {
        if (e.status === 409 && e.other) {
          // never drop a started inspection with a photo/video silently: ask
          h('<div class="card">' + esc(t('otherDraft', (e.other.street || '') + ' ' + (e.other.label || ''))) + '</div><div id="err" class="err"></div>' +
            '<button class="btn danger" id="yes">' + t('discard') + '</button><button class="btn alt" id="no">' + t('openOther') + '</button>');
          var yes = document.getElementById('yes');
          yes.onclick = function () { busy(yes, function () { return begin(o, kind, true); }); };
          document.getElementById('no').onclick = function () { show(function () { object(e.other.object_id); }); };
          return;
        }
        throw e;
      });
  }

  // ------------------------------------------------------------------ the 4-step wizard
  function wizard(o, draft) {
    syncBack();
    var stepNo = { photo: 1, location: 2, point: 3, video: 4, comment: 5 }[draft.step] || 5;
    titleEl.textContent = o.shop_name + ' · ' + t(draft.kind === 'removed' ? 'kindRemoved' : 'kindInstalled');
    var bar = '<div class="steps">' + [1, 2, 3, 4, 5].map(function (n) { return '<i class="' + (n <= stepNo ? 'on' : '') + '"></i>'; }).join('') + '</div>';
    var next = function (d) { draft = d.draft; if (draft.step === 'submitted') return finished(o); wizard(o, draft); };
    // order: live photo -> where the staff member stands -> the object's place on the map -> live video -> comment
    if (draft.step === 'photo') return stepPhoto(bar, draft, next);
    if (draft.step === 'location') return stepLocation(bar, draft, next);
    if (draft.step === 'point') return stepPoint(bar, draft, next);
    if (draft.step === 'video') return stepVideo(bar, draft, next);
    if (draft.step === 'comment') return stepComment(bar, draft, next);
    finished(o);
  }

  function stepLocation(bar, draft, next) {
    h(bar + '<h3>' + t('st2') + '</h3><p class="muted">' + t('locHint') + '</p><div id="err" class="err"></div><div id="extra"></div><button class="btn" id="go">' + t('locBtn') + '</button>');
    var go = document.getElementById('go');
    go.onclick = function () {
      busy(go, function () {
        document.getElementById('err').textContent = ''; document.getElementById('extra').innerHTML = '';
        go.textContent = t('locWait');
        return locator.locate().then(function (p) {
          return api('/inspections/' + draft.id + '/location', { body: { latitude: p.latitude, longitude: p.longitude, accuracy: p.accuracy } });
        }).then(next).catch(function (e) {
          go.textContent = t('retry');
          if (e && e.code === 'denied') {
            document.getElementById('err').textContent = t('locDenied');
            if (locator.canOpenSettings()) {
              document.getElementById('extra').innerHTML = '<button class="btn alt" id="settings">' + t('openSettings') + '</button>';
              document.getElementById('settings').onclick = function () { locator.openSettings(); };
            }
            return;
          }
          if (e && (e.code === 'timeout' || e.code === 'unavailable')) throw { error: t('locNone') };
          throw e;
        });
      });
    };
  }

  // 3/5: the object's place. The marker starts at the object's approved place (if any), else where the staff member stands;
  // the blue circle shows how far from the staff member the point may be.
  function stepPoint(bar, draft, next) {
    h(bar + '<h3>' + t('st3') + '</h3><p class="muted">' + t('pointHint', draft.max_distance) + '</p>' +
      (draft.object_point ? '<p class="muted">' + t('pointKnown') + '</p>' : '') +
      '<div id="map"></div><div id="err" class="err"></div><button class="btn" id="go">' + t('pointBtn') + '</button>');
    if (!window.L || !draft.me) {
      showErr({ error: t('mapErr') });
      var retry = document.getElementById('go'); retry.textContent = t('retry');
      retry.onclick = function () {
        if (window.L) return stepPoint(bar, draft, next);
        busy(retry, function () {
          return new Promise(function (ok, bad) {  // fetch Leaflet again (the first load failed)
            var sc = document.createElement('script'); sc.src = 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.js';
            sc.onload = ok; sc.onerror = function () { bad({ error: t('mapErr') }); }; document.head.appendChild(sc);
          }).then(function () { stepPoint(bar, draft, next); });
        });
      };
      return;
    }
    var me = draft.me, start = draft.object_point || me;
    var map = L.map('map', { zoomControl: true, attributionControl: true }).setView(start, 18);
    L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 19, attribution: '© OpenStreetMap' }).addTo(map);
    L.circle(me, { radius: draft.max_distance, color: '#2481cc', weight: 1, fillOpacity: 0.06 }).addTo(map);
    L.circleMarker(me, { radius: 7, color: '#fff', weight: 2, fillColor: '#2481cc', fillOpacity: 1 }).addTo(map);
    var marker = L.marker(start, { draggable: true, autoPan: true }).addTo(map);
    map.on('click', function (e) { marker.setLatLng(e.latlng); });
    setTimeout(function () { map.invalidateSize(); }, 200);
    var go = document.getElementById('go');
    go.onclick = function () {
      busy(go, function () {
        var p = marker.getLatLng();
        return api('/inspections/' + draft.id + '/point', { body: { latitude: p.lat, longitude: p.lng } }).then(next);
      });
    };
  }

  function openCamera(videoEl) {
    stopCamera();
    var my = camReq;
    if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) return Promise.reject({ error: t('camErr') });
    return navigator.mediaDevices.getUserMedia({ video: { facingMode: { ideal: 'environment' }, width: { ideal: 1280 }, height: { ideal: 720 } }, audio: false })
      .then(function (s) {
        if (my !== camReq) { s.getTracks().forEach(function (tr) { tr.stop(); }); return; }  // superseded: never leave a camera on
        stream = s; videoEl.srcObject = s; videoEl.muted = true; videoEl.setAttribute('playsinline', ''); return videoEl.play(); })
      .catch(function () { throw { error: t('camErr') }; });
  }

  // big videos on a slow network: show the percentage, give up after 3 minutes, can be cancelled (the server is idempotent)
  function upload(path, field, blob, filename, btn) {
    var fd = new FormData(); fd.append(field, blob, filename);
    var my = nav, label = btn ? btn.textContent : '';
    abortUpload();
    var cancel = null;
    if (btn) {
      cancel = document.createElement('button'); cancel.className = 'btn link'; cancel.textContent = t('cancel');
      btn.parentNode.insertBefore(cancel, btn.nextSibling);
    }
    guardClose(true);
    return new Promise(function (resolve, reject) {
      var x = xhr = new XMLHttpRequest();
      x.open('POST', '/tg/api' + path);
      x.setRequestHeader('X-Telegram-Init-Data', tg ? tg.initData : '');
      x.setRequestHeader('Accept', 'application/json');
      x.timeout = 180000;
      x.upload.onprogress = function (e) { if (btn && e.lengthComputable) btn.textContent = label + ' ' + Math.floor(e.loaded * 100 / e.total) + '%'; };
      x.onload = function () {
        var d = {}; try { d = JSON.parse(x.responseText); } catch (e) {}
        if (x.status < 200 || x.status >= 300) { d.status = x.status; if (!d.error) d.error = d.message || ('Xato ' + x.status); return reject(d); }
        resolve(d);
      };
      x.onerror = function () { reject({ error: t('netErr') }); };
      x.ontimeout = function () { reject({ error: t('slow') }); };
      x.onabort = function () { reject({ error: '', aborted: true }); };
      if (cancel) cancel.onclick = function () { if (xhr === x) { xhr = null; x.abort(); } };
      x.send(fd);
    }).then(function (d) {
      if (xhr) xhr = null;
      guardClose(false); if (cancel) cancel.remove();
      if (my !== nav) return new Promise(function () {});  // the user moved on
      return d;
    }, function (e) {
      if (xhr) xhr = null;
      guardClose(false); if (cancel) cancel.remove();
      if (my !== nav) return new Promise(function () {});
      throw e;
    });
  }

  function stepPhoto(bar, draft, next) {
    h(bar + '<h3>' + t('st1') + '</h3><p class="muted">' + t('photoHint') + '</p><video id="v" autoplay playsinline muted></video>' +
      '<canvas class="preview" id="c" hidden></canvas><div id="err" class="err"></div>' +
      '<button class="btn" id="cam">' + t('camStart') + '</button><button class="btn" id="shot" hidden>' + t('shot') + '</button>' +
      '<button class="btn" id="send" hidden>' + t('send') + '</button><button class="btn alt" id="again" hidden>' + t('retake') + '</button>');
    var v = document.getElementById('v'), c = document.getElementById('c'), cam = document.getElementById('cam'), shot = document.getElementById('shot'),
      send = document.getElementById('send'), again = document.getElementById('again'), blob = null;
    function live() { c.hidden = true; v.hidden = false; send.hidden = again.hidden = true; shot.hidden = false; cam.hidden = true; }
    cam.onclick = function () { busy(cam, function () { return openCamera(v).then(live); }); };
    shot.onclick = function () {
      if (!v.videoWidth) return;
      c.width = v.videoWidth; c.height = v.videoHeight; c.getContext('2d').drawImage(v, 0, 0);
      c.toBlob(function (b) { blob = b; c.hidden = false; v.hidden = true; shot.hidden = true; send.hidden = again.hidden = false; }, 'image/jpeg', 0.88);
    };
    again.onclick = live;
    send.onclick = function () {
      busy(send, function () { send.textContent = t('uploading'); return upload('/inspections/' + draft.id + '/photo', 'photo', blob, 'photo.jpg', send).then(function (d) { stopCamera(); next(d); })
        .catch(function (e) { send.textContent = t('send'); throw e; }); });
    };
    cam.click();
  }

  function stepVideo(bar, draft, next) {
    h(bar + '<h3>' + t('st4') + '</h3><p class="muted">' + t('videoHint') + '</p><video id="v" autoplay playsinline muted></video>' +
      '<p class="center"><span id="timer"></span></p><div id="err" class="err"></div>' +
      '<button class="btn" id="cam">' + t('camStart') + '</button><button class="btn danger" id="rec" hidden>' + t('recStart') + '</button>' +
      '<button class="btn" id="send" hidden>' + t('send') + '</button><button class="btn alt" id="again" hidden>' + t('retake') + '</button>');
    var v = document.getElementById('v'), cam = document.getElementById('cam'), rec = document.getElementById('rec'), send = document.getElementById('send'),
      again = document.getElementById('again'), timer = document.getElementById('timer'), recorder = null, chunks = [], started = 0, tick = null, blob = null, type = '';
    function live() { send.hidden = again.hidden = true; rec.hidden = false; rec.textContent = t('recStart'); cam.hidden = true; timer.textContent = ''; v.srcObject = stream; v.controls = false; }
    cam.onclick = function () { busy(cam, function () { return openCamera(v).then(live); }); };
    function pickType() {
      var c = ['video/mp4;codecs=avc1', 'video/mp4', 'video/webm;codecs=vp9', 'video/webm;codecs=vp8', 'video/webm'];
      for (var i = 0; i < c.length; i++) { if (window.MediaRecorder && MediaRecorder.isTypeSupported && MediaRecorder.isTypeSupported(c[i])) return c[i]; }
      return '';
    }
    function stop() {
      if (recorder && recorder.state === 'recording') recorder.stop();
    }
    rec.onclick = function () {
      if (recorder && recorder.state === 'recording') {
        if (Date.now() - started < 3000) { document.getElementById('err').textContent = t('recShort'); return; }
        return stop();
      }
      document.getElementById('err').textContent = '';
      if (!window.MediaRecorder) return showErr({ error: t('recNo') });
      type = pickType(); chunks = [];
      try { recorder = new MediaRecorder(stream, type ? { mimeType: type, videoBitsPerSecond: 2500000 } : undefined); } catch (e) { return showErr({ error: t('recNo') }); }
      recorder.ondataavailable = function (e) { if (e.data && e.data.size) chunks.push(e.data); };
      recorder.onstop = function () {
        clearInterval(tick); guardClose(false);
        blob = new Blob(chunks, { type: (type || 'video/webm').split(';')[0] });
        v.srcObject = null; v.src = URL.createObjectURL(blob); v.controls = true; v.muted = true;
        rec.hidden = true; send.hidden = again.hidden = false;
      };
      recorder.start(500); started = Date.now(); guardClose(true); rec.textContent = t('recStop');
      tick = setInterval(function () {
        var s = Math.floor((Date.now() - started) / 1000);
        timer.innerHTML = '<span class="rec">● ' + s + ' / 15 s</span>';
        if (s >= 15) stop();
      }, 250);
    };
    again.onclick = function () { busy(again, function () { return openCamera(v).then(live); }); };
    send.onclick = function () {
      busy(send, function () {
        send.textContent = t('uploading');
        var ext = blob.type.indexOf('mp4') >= 0 ? 'mp4' : 'webm';
        return upload('/inspections/' + draft.id + '/video', 'video', blob, 'video.' + ext, send).then(function (d) { stopCamera(); next(d); })
          .catch(function (e) { send.textContent = t('send'); throw e; });
      });
    };
    cam.click();
  }

  function stepComment(bar, draft, next) {
    h(bar + '<h3>' + t('st5') + '</h3><label>' + t('comment') + '</label><textarea id="cm" maxlength="2000" placeholder="' + esc(t('commentHint')) + '"></textarea>' +
      '<div id="err" class="err"></div><button class="btn" id="go">' + t('submit') + '</button>');
    var go = document.getElementById('go');
    go.onclick = function () { busy(go, function () { return api('/inspections/' + draft.id + '/submit', { body: { comment: document.getElementById('cm').value } }).then(next); }); };
  }

  function finished(o) {
    stopCamera();
    if (tg && tg.HapticFeedback) tg.HapticFeedback.notificationOccurred('success');
    h('<div class="card center" style="margin-top:20px"><p style="font-size:40px;margin:0">✅</p><p>' + t('done') + '</p></div><button class="btn" id="to">' + t('toObject') + '</button>');
    document.getElementById('to').onclick = function () { stack = stack.slice(0, -1); stack[stack.length - 1] = function () { object(o.id); }; object(o.id); };
  }

  function fatal(e) {
    h('<div class="card"><p class="err">' + esc((e && e.error) || t('netErr')) + '</p></div><button class="btn" id="re">' + t('retry') + '</button>');
    document.getElementById('re').onclick = function () { var s = stack[stack.length - 1]; if (s) s(); };
  }

  if (!tg || !tg.initData) { titleEl.textContent = t('title'); h('<div class="card">' + t('noTg') + '</div>'); return; }
  show(home);
})();
</script>
</body>
</html>
