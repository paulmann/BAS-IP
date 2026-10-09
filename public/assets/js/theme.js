/**
 * BAS-IP · theme bootstrap (runs before first paint).
 *
 * Resolution order:
 *   1. explicit override in the URL (?theme=dark) — handy for kiosks/screens
 *   2. the `basip_theme` cookie (also readable by PHP, so the server renders
 *      the right class on the very first byte — zero flash)
 *   3. localStorage `basip:theme`
 *   4. the saved preference of the signed-in user (`window.__BASIP.theme`)
 *   5. `prefers-color-scheme`
 *
 * Accent and density are applied the same way. The file is intentionally tiny
 * and dependency-free, and is loaded with `defer`-free inline semantics by the
 * layout (see resources/views/layouts/app.php).
 */
(function () {
  'use strict';

  var STORAGE_KEY = 'basip:theme';
  var ACCENT_KEY = 'basip:accent';
  var DENSITY_KEY = 'basip:density';
  var COOKIE = 'basip_theme';
  var VALID = { light: 1, dark: 1, system: 1 };
  var ACCENTS = { slate: 1, blue: 1, green: 1, violet: 1, orange: 1, rose: 1, teal: 1 };
  var DENSITIES = { comfortable: 1, compact: 1 };

  function readCookie(name) {
    try {
      var match = document.cookie.match(new RegExp('(?:^|;\\s*)' + name + '=([^;]*)'));
      return match ? decodeURIComponent(match[1]) : null;
    } catch (e) { return null; }
  }

  function readStorage(key) {
    try { return window.localStorage.getItem(key); } catch (e) { return null; }
  }

  function writeStorage(key, value) {
    try { window.localStorage.setItem(key, value); } catch (e) { /* private mode */ }
  }

  function readQuery(name) {
    try { return new URLSearchParams(window.location.search).get(name); } catch (e) { return null; }
  }

  function systemTheme() {
    try {
      return window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
    } catch (e) { return 'light'; }
  }

  /** Resolve the effective light/dark choice. */
  function resolve(pref) {
    if (pref === 'dark' || pref === 'light') return pref;
    return systemTheme();
  }

  function boot() {
    var root = document.documentElement;
    var server = (window.__BASIP && window.__BASIP.theme) || null;
    var query = readQuery('theme');
    var stored = readStorage(STORAGE_KEY);
    var cookie = readCookie(COOKIE);

    var pref = query || cookie || stored || server || 'system';
    if (!VALID[pref]) pref = 'system';

    apply(pref);

    // Keep the choice in sync across tabs.
    try {
      window.addEventListener('storage', function (e) {
        if (e.key === STORAGE_KEY && e.newValue && VALID[e.newValue]) apply(e.newValue);
      });
    } catch (e) {}

    // Follow the OS while the user is on "system".
    try {
      var mq = window.matchMedia('(prefers-color-scheme: dark)');
      var onChange = function () {
        var current = readStorage(STORAGE_KEY) || cookie || server || 'system';
        if (current === 'system') apply('system');
      };
      if (mq.addEventListener) mq.addEventListener('change', onChange);
      else if (mq.addListener) mq.addListener(onChange);
    } catch (e) {}

    // Accent + density.
    var accent = readQuery('accent') || readStorage(ACCENT_KEY) || (window.__BASIP && window.__BASIP.accent) || 'slate';
    if (ACCENTS[accent]) root.setAttribute('data-accent', accent);
    var density = readQuery('density') || readStorage(DENSITY_KEY) || (window.__BASIP && window.__BASIP.density) || 'comfortable';
    if (DENSITIES[density]) root.setAttribute('data-density', density);
  }

  function apply(pref) {
    var root = document.documentElement;
    var effective = resolve(pref);
    root.classList.toggle('dark', effective === 'dark');
    root.style.colorScheme = effective;
    root.setAttribute('data-theme', pref);
    root.setAttribute('data-effective-theme', effective);

    // Theme-coloured browser UI (mobile address bar).
    var meta = document.querySelector('meta[name="theme-color"]');
    if (meta) meta.setAttribute('content', effective === 'dark' ? '#09090b' : '#ffffff');

    writeStorage(STORAGE_KEY, pref);
    document.dispatchEvent(new CustomEvent('basip:theme', { detail: { pref: pref, effective: effective } }));
  }

  /** Public API used by the theme switcher in app.js. */
  window.BasIpTheme = {
    get: function () {
      return readStorage(STORAGE_KEY) || readCookie(COOKIE) || (window.__BASIP && window.__BASIP.theme) || 'system';
    },
    effective: function () {
      return document.documentElement.classList.contains('dark') ? 'dark' : 'light';
    },
    set: function (pref) {
      if (!VALID[pref]) return;
      writeStorage(STORAGE_KEY, pref);
      // Cookie so PHP renders the correct class on the next full page load.
      try {
        var secure = window.location.protocol === 'https:' ? '; Secure' : '';
        document.cookie = COOKIE + '=' + encodeURIComponent(pref) + '; Path=/; Max-Age=31536000; SameSite=Lax' + secure;
      } catch (e) {}
      apply(pref);
      return pref;
    },
    cycle: function () {
      var order = ['light', 'dark', 'system'];
      var next = order[(order.indexOf(this.get()) + 1) % order.length];
      return this.set(next);
    },
    setAccent: function (accent) {
      if (!ACCENTS[accent]) return;
      writeStorage(ACCENT_KEY, accent);
      document.documentElement.setAttribute('data-accent', accent);
    },
    setDensity: function (density) {
      if (!DENSITIES[density]) return;
      writeStorage(DENSITY_KEY, density);
      document.documentElement.setAttribute('data-density', density);
    }
  };

  boot();
})();
