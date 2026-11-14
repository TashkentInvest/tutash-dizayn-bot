/*
 * One location reading for the staff Mini App.
 *
 * Telegram's LocationManager (Bot API 8.0+) calls the init() callback only once: a second init() never answers. So the
 * init result is cached and shared. If Telegram gives nothing within FALLBACK_MS, the browser's navigator.geolocation is
 * used. Whatever happens, the promise settles exactly once (no endless "Joylashuv aniqlanmoqda...").
 *
 * Errors carry .code: "denied" (offer the settings button), "unavailable", "timeout".
 * Works in the browser (window.TgLocation) and in Node for tests (module.exports).
 */
(function (root) {
  var FALLBACK_MS = 25000;
  var TOTAL_MS = 45000;

  function makeLocator(env) {
    env = env || {};
    var tg = env.tg !== undefined ? env.tg : (root.Telegram && root.Telegram.WebApp);
    var nav = env.navigator !== undefined ? env.navigator : root.navigator;
    var setT = env.setTimeout || setTimeout;
    var clearT = env.clearTimeout || clearTimeout;
    var fallbackMs = env.fallbackMs || FALLBACK_MS;
    var totalMs = env.totalMs || TOTAL_MS;
    var initPromise = null;

    function lm() {
      return tg && tg.LocationManager && typeof tg.LocationManager.init === 'function' ? tg.LocationManager : null;
    }

    function initOnce() {
      if (!initPromise) {
        initPromise = new Promise(function (ok) {
          var m = lm();
          if (!m) return ok(null);
          if (m.isInited) return ok(m);
          try { m.init(function () { ok(m); }); } catch (e) { ok(null); }
        });
      }
      return initPromise;
    }

    function err(code, message) {
      var e = new Error(message || code);
      e.code = code;
      return e;
    }

    function fromBrowser() {
      return new Promise(function (ok, no) {
        if (!nav || !nav.geolocation) return no(err('unavailable'));
        nav.geolocation.getCurrentPosition(
          function (p) { ok({ latitude: p.coords.latitude, longitude: p.coords.longitude, accuracy: p.coords.accuracy, source: 'browser' }); },
          function (e) { no(err(e && e.code === 1 ? 'denied' : 'unavailable')); },
          { enableHighAccuracy: true, timeout: fallbackMs, maximumAge: 0 }
        );
      });
    }

    /** Resolves {latitude, longitude, accuracy, source} or rejects with .code. Settles once. */
    function locate() {
      return new Promise(function (resolve, reject) {
        var settled = false;
        var timers = [];
        function done(fn, v) {
          if (settled) return;
          settled = true;
          timers.forEach(clearT);
          fn(v);
        }
        var finish = function (v) { done(resolve, v); };
        var fail = function (e) { done(reject, e); };
        var browserStarted = false;
        function tryBrowser(refused) {
          if (browserStarted || settled) return;
          browserStarted = true;
          // Telegram remembers a "no" for this bot and never asks again: the phone's own permission may still allow it.
          // If that fails too, the answer stays "denied" (the settings button), not "GPS is off".
          fromBrowser().then(finish, function (e) { fail(refused ? err('denied') : e); });
        }
        timers.push(setT(function () { fail(err('timeout')); }, totalMs));
        timers.push(setT(tryBrowser, fallbackMs));

        initOnce().then(function (m) {
          if (settled) return;
          if (!m || !m.isLocationAvailable) return tryBrowser();
          if (m.isAccessRequested && !m.isAccessGranted) return tryBrowser(true);
          try {
            m.getLocation(function (data) {
              if (data) finish({ latitude: data.latitude, longitude: data.longitude, accuracy: data.horizontal_accuracy || null, source: 'telegram' });
              else if (m.isAccessRequested && !m.isAccessGranted) tryBrowser(true);
              else tryBrowser();
            });
          } catch (e) { tryBrowser(); }
        });
      });
    }

    function openSettings() {
      var m = lm();
      if (m && typeof m.openSettings === 'function') m.openSettings();
    }

    return { locate: locate, openSettings: openSettings, canOpenSettings: function () { return !!lm(); } };
  }

  var api = { makeLocator: makeLocator };
  if (typeof module !== 'undefined' && module.exports) module.exports = api;
  else root.TgLocation = api;
})(typeof window !== 'undefined' ? window : globalThis);
