// node tests/js/location.test.cjs — the Mini App's location helper against a fake Telegram LocationManager.
const assert = require('assert');
// load the browser file as-is (the web/ package is an ES module, this one is plain script): run it with a module object
const fs = require('fs'), path = require('path'), vm = require('vm');
const mod = { exports: {} };
vm.runInNewContext(fs.readFileSync(path.join(__dirname, '../../public/tg/location.js'), 'utf8'), { module: mod, setTimeout, clearTimeout, globalThis: {} });
const { makeLocator } = mod.exports;

function fakeLM({ available = true, requested = true, granted = true, data = { latitude: 41.3, longitude: 69.2, horizontal_accuracy: 12 } } = {}) {
  const lm = { isInited: false, isLocationAvailable: available, isAccessRequested: requested, isAccessGranted: granted, inits: 0, opened: 0 };
  lm.init = (cb) => {
    lm.inits++;
    if (lm.inits > 1) return; // the real one never calls back a second time
    lm.isInited = true;
    setTimeout(cb, 1);
  };
  lm.getLocation = (cb) => setTimeout(() => cb(granted ? data : null), 1);
  lm.openSettings = () => lm.opened++;
  return lm;
}
const fastEnv = (tg, navigator = null) => ({ tg, navigator, fallbackMs: 30, totalMs: 200 });

(async () => {
  // 1. Telegram answers; a second press ("Qayta tekshirish") must not hang although init() never calls back again
  const lm = fakeLM();
  const loc = makeLocator(fastEnv({ LocationManager: lm }));
  const a = await loc.locate();
  assert.strictEqual(a.source, 'telegram');
  assert.strictEqual(a.accuracy, 12);
  const b = await loc.locate();
  assert.strictEqual(b.latitude, 41.3);
  assert.strictEqual(lm.inits, 1, 'init() is called once and cached');

  // 2. access denied in Telegram, no browser location either -> "denied", and the settings button works
  const denied = fakeLM({ granted: false });
  const l2 = makeLocator(fastEnv({ LocationManager: denied }, { geolocation: { getCurrentPosition: (ok, no) => setTimeout(() => no({ code: 2 }), 1) } }));
  await assert.rejects(l2.locate(), (e) => e.code === 'denied');
  l2.openSettings();
  assert.strictEqual(denied.opened, 1);
  // denied in Telegram once, but the phone's own permission allows it -> the browser's reading is used
  const navOk = { geolocation: { getCurrentPosition: (ok) => setTimeout(() => ok({ coords: { latitude: 41.2, longitude: 69.2, accuracy: 15 } }), 1) } };
  const r2 = await makeLocator(fastEnv({ LocationManager: fakeLM({ granted: false }) }, navOk)).locate();
  assert.strictEqual(r2.source, 'browser');

  // 3. no LocationManager (old Telegram) -> browser geolocation
  const nav = { geolocation: { getCurrentPosition: (ok) => setTimeout(() => ok({ coords: { latitude: 40.1, longitude: 70.1, accuracy: 30 } }), 1) } };
  const c = await makeLocator(fastEnv({}, nav)).locate();
  assert.strictEqual(c.source, 'browser');

  // 4. Telegram silent (init never answers) -> browser after the fallback delay
  const silent = { LocationManager: { isInited: false, init: () => {} } };
  const d = await makeLocator(fastEnv(silent, nav)).locate();
  assert.strictEqual(d.source, 'browser');

  // 5. nothing answers at all -> rejects with "timeout" once, never hangs, never settles twice
  const dead = { geolocation: { getCurrentPosition: () => {} } };
  let settled = 0;
  await makeLocator(fastEnv(silent, dead)).locate().then(() => settled++, (e) => { settled++; assert.strictEqual(e.code, 'timeout'); });
  await new Promise((r) => setTimeout(r, 250));
  assert.strictEqual(settled, 1);

  // 6. browser permission denied -> "denied"
  const navDenied = { geolocation: { getCurrentPosition: (ok, no) => setTimeout(() => no({ code: 1 }), 1) } };
  await assert.rejects(makeLocator(fastEnv({}, navDenied)).locate(), (e) => e.code === 'denied');

  console.log('location.js: all 7 checks passed');
})().catch((e) => { console.error(e); process.exit(1); });
