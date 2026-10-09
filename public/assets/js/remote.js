/**
 * BAS-IP Gate Manager · resident remote (/remote)
 * ─────────────────────────────────────────────────────────────────────────────
 * One giant OPEN button + the live camera bound to the selected gate.
 *
 *  • cameras come from the server-rendered list (data-cameras); the last
 *    selection is remembered in localStorage and restored on open;
 *  • snapshot mode reloads the proxied JPEG on a timer, proxy/mjpeg modes
 *    point the <img> at the MJPEG stream (browser-native);
 *  • any camera failure degrades gracefully: the button keeps working,
 *    the video area shows «камера недоступна»;
 *  • the OPEN press is blocked during the device cooldown with a visible
 *    countdown, and the whole screen flashes green/red as feedback —
 *    visible even mounted on a dashboard in bright sunlight;
 *  • Wake Lock keeps the screen on while the page is foreground (phone on
 *    the car mount); harmless where unsupported.
 *
 * Vanilla ES2020, no dependencies beyond window.BasIp (app.js).
 */
(function () {
  'use strict';

  var root = document.querySelector('[data-remote]');
  if (!root) return;

  var openBtn      = root.querySelector('[data-open-gate]');
  var closeBtn     = root.querySelector('[data-close-gate]');
  var openLabel    = root.querySelector('[data-open-label]');
  var openHint     = root.querySelector('[data-open-hint]');
  var countdownEl  = root.querySelector('[data-open-countdown]');
  var statusDot    = root.querySelector('[data-remote-status-dot]');
  var statusText   = root.querySelector('[data-remote-status-text]');
  var stage        = root.querySelector('[data-camera-stage]');
  var img          = root.querySelector('[data-camera-img]');
  var liveBadge    = root.querySelector('[data-camera-live]');
  var offline      = root.querySelector('[data-camera-offline]');
  var overlay      = document.querySelector('[data-remote-overlay]');
  var overlayIcon  = document.querySelector('[data-overlay-icon]');
  var overlayText  = document.querySelector('[data-overlay-text]');
  var overlayNote  = document.querySelector('[data-overlay-note]');

  var OPEN_URL   = root.getAttribute('data-open-url') || '/panel/open';
  var CLOSE_URL  = root.getAttribute('data-close-url') || '/panel/close';
  var STATUS_URL = root.getAttribute('data-status-url') || '/panel/status';
  var COOLDOWN   = parseInt(root.getAttribute('data-cooldown') || '3', 10);

  var cameras = [];
  try { cameras = JSON.parse(root.getAttribute('data-cameras') || '[]'); } catch (e) { cameras = []; }

  var STORE_KEY = 'basip:remote:camera';
  var current = null;
  var refreshTimer = null;
  var busy = false;
  var cooldownUntil = 0;
  var cooldownTimer = null;

  function csrf() {
    var meta = document.querySelector('meta[name="csrf-token"]');
    return meta ? meta.getAttribute('content') : '';
  }

  function store(value) {
    try {
      if (value === null) return window.localStorage.getItem(STORE_KEY);
      window.localStorage.setItem(STORE_KEY, value);
    } catch (e) { /* private mode */ }
    return null;
  }

  /* ── cameras ─────────────────────────────────────────────────────── */

  function pickCamera(slug) {
    for (var i = 0; i < cameras.length; i++) {
      if (cameras[i].device === slug) return cameras[i];
    }
    return cameras.length ? cameras[0] : null;
  }

  function showCamera(camera) {
    current = camera;
    if (refreshTimer) { clearInterval(refreshTimer); refreshTimer = null; }
    if (!camera) {
      if (stage) stage.classList.add('hidden');
      if (openHint) openHint.textContent = 'шлагбаум';
      return;
    }
    store(camera.device);
    if (openHint) openHint.textContent = camera.name;

    root.querySelectorAll('[data-camera-chip]').forEach(function (chip) {
      chip.setAttribute('data-state', chip.getAttribute('data-camera-chip') === camera.device ? 'active' : 'inactive');
    });

    if (!stage || !img) return;
    stage.classList.remove('hidden');
    setOffline(false);

    if (camera.stream_url) {
      // proxy / direct mjpeg — the browser plays multipart/x-mixed-replace natively
      img.src = camera.stream_url;
      if (liveBadge) liveBadge.hidden = false;
    } else {
      // snapshot polling through the server (panel credentials stay server-side)
      if (liveBadge) liveBadge.hidden = true;
      var tick = function () { img.src = camera.frame_url + '&t=' + Date.now(); };
      tick();
      refreshTimer = setInterval(tick, Math.max(1, camera.refresh || 2) * 1000);
    }
  }

  function setOffline(isOffline) {
    if (!offline) return;
    offline.classList.toggle('hidden', !isOffline);
    offline.classList.toggle('flex', isOffline);
  }

  if (img) {
    img.addEventListener('error', function () {
      // Stream broke (or panel offline): fall back to snapshot polling,
      // and if that fails too — show the offline plate. The OPEN button
      // keeps working regardless.
      if (current && current.stream_url && current.frame_url) {
        var fallback = { device: current.device, name: current.name, frame_url: current.frame_url, refresh: current.refresh, stream_url: null };
        showCamera(fallback);
        return;
      }
      setOffline(true);
    });
    img.addEventListener('load', function () { setOffline(false); });
  }

  root.querySelectorAll('[data-camera-chip]').forEach(function (chip) {
    chip.addEventListener('click', function () {
      showCamera(pickCamera(chip.getAttribute('data-camera-chip')));
    });
  });

  if (cameras.length) {
    showCamera(pickCamera(store(null)) || cameras[0]);
  } else {
    showCamera(null);
  }

  /* ── full-screen feedback overlay ────────────────────────────────── */

  var overlayTimer = null;
  function flash(kind, text, note) {
    if (!overlay) return;
    var bg = kind === 'ok' ? 'hsl(var(--success))' : (kind === 'wait' ? 'hsl(var(--background) / .92)' : 'hsl(var(--destructive))');
    var fg = kind === 'wait' ? 'hsl(var(--foreground))' : '#fff';
    overlay.style.background = bg;
    overlay.style.color = fg;
    overlayIcon.innerHTML = kind === 'ok' ? svgIcon('check-circle') : (kind === 'wait' ? svgIcon('loader') : svgIcon('alert-circle'));
    overlayText.textContent = text;
    overlayNote.textContent = note || '';
    overlay.classList.remove('hidden');
    overlay.classList.add('flex');
    if (overlayTimer) clearTimeout(overlayTimer);
    if (kind !== 'wait') {
      overlayTimer = setTimeout(function () {
        overlay.classList.add('hidden');
        overlay.classList.remove('flex');
      }, kind === 'ok' ? 1600 : 2600);
    }
    if (navigator.vibrate) { try { navigator.vibrate(kind === 'ok' ? [60, 40, 60] : kind === 'wait' ? 30 : [120, 60, 120]); } catch (e) {} }
  }

  function svgIcon(name) {
    var base = (window.__BASIP && window.__BASIP.iconBase) || '/assets/icons/lucide.svg';
    return '<svg class="h-24 w-24" aria-hidden="true"><use href="' + base + '#i-' + name + '"></use></svg>';
  }

  /* ── cooldown ────────────────────────────────────────────────────── */

  function startCooldown(seconds) {
    cooldownUntil = Date.now() + seconds * 1000;
    openBtn.disabled = true;
    if (countdownEl) countdownEl.style.opacity = '1';
    if (cooldownTimer) clearInterval(cooldownTimer);
    cooldownTimer = setInterval(function () {
      var left = Math.ceil((cooldownUntil - Date.now()) / 1000);
      if (left <= 0) {
        clearInterval(cooldownTimer);
        cooldownTimer = null;
        openBtn.disabled = false;
        if (countdownEl) { countdownEl.textContent = ''; countdownEl.style.opacity = '0'; }
        if (openLabel) openLabel.textContent = 'ОТКРЫТЬ';
        return;
      }
      if (openLabel) openLabel.textContent = String(left);
      if (countdownEl) countdownEl.textContent = 'сек. до повторного открытия';
    }, 200);
  }

  /* ── the big button ──────────────────────────────────────────────── */

  function act(url, okText) {
    if (busy) return;
    var wait = Math.ceil((cooldownUntil - Date.now()) / 1000);
    if (wait > 0) { flash('wait', 'Подождите ' + wait + ' с', 'Защита от повторных открытий'); return; }
    busy = true;
    openBtn.disabled = true;
    if (openLabel) openLabel.textContent = '…';

    var body = new URLSearchParams({ _token: csrf() });
    if (current) body.set('device', current.device);

    fetch(url, {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-CSRF-Token': csrf(), 'Accept': 'application/json' },
      body: body.toString(),
      credentials: 'same-origin'
    })
      .then(function (r) { return r.json().then(function (d) { return { status: r.status, data: d }; }); })
      .then(function (res) {
        busy = false;
        openBtn.disabled = false;
        if (openLabel) openLabel.textContent = 'ОТКРЫТЬ';
        if (res.data && res.data.ok) {
          flash('ok', okText, res.data.message || '');
          startCooldown(Math.max(COOLDOWN, parseInt(res.data.cooldown || COOLDOWN, 10) || COOLDOWN));
        } else if (res.status === 422 && res.data && res.data.error_code === 'cooldown') {
          flash('wait', 'Слишком часто', res.data.message || '');
          startCooldown(COOLDOWN);
        } else {
          flash('err', 'Не открылось', (res.data && (res.data.error || res.data.message)) || ('HTTP ' + res.status));
        }
      })
      .catch(function (e) {
        busy = false;
        openBtn.disabled = false;
        if (openLabel) openLabel.textContent = 'ОТКРЫТЬ';
        flash('err', 'Нет связи с сервером', String(e));
      });
  }

  openBtn.addEventListener('click', function () { act(OPEN_URL, 'ОТКРЫТО'); });
  if (closeBtn) closeBtn.addEventListener('click', function () { act(CLOSE_URL, 'ЗАКРЫТО'); });

  /* ── status line ─────────────────────────────────────────────────── */

  function pollStatus() {
    var url = STATUS_URL + (current ? '?device=' + encodeURIComponent(current.device) : '');
    fetch(url, { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        if (!statusDot || !statusText) return;
        var online = d && d.ok && d.status && d.status.online !== false && d.status.sip_status !== 'unregistered';
        statusDot.classList.toggle('ok', !!online);
        statusDot.classList.toggle('fail', !online);
        var label = current ? current.name : 'шлагбаум';
        statusText.textContent = online ? label + ' · на связи' : label + ' · нет связи с устройством';
      })
      .catch(function () {
        if (!statusDot || !statusText) return;
        statusDot.classList.remove('ok');
        statusDot.classList.add('fail');
        statusText.textContent = 'сервер недоступен';
      });
  }
  pollStatus();
  setInterval(pollStatus, 30000);

  /* ── wake lock: экран не гаснет, пока пульт открыт ───────────────── */

  var wakeLock = null;
  function requestWake() {
    if (!('wakeLock' in navigator)) return;
    navigator.wakeLock.request('screen').then(function (lock) { wakeLock = lock; }).catch(function () {});
  }
  requestWake();
  document.addEventListener('visibilitychange', function () {
    if (document.visibilityState === 'visible') requestWake();
  });
})();
