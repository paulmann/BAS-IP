/**
 * BAS-IP · control panel runtime
 * ─────────────────────────────────────────────────────────────────────────────
 * Handles the three things the panel page needs beyond the generic UI layer:
 *   1. the big «Открыть» button (with optimistic state, cooldown and errors),
 *   2. live device status + camera snapshot polling,
 *   3. guest-pass issuance with a QR code and a share sheet.
 *
 * Requires: app.js (window.BasIp) and vendor/qrcode.js for QR codes.
 */
(function () {
  'use strict';

  var B = window.BasIp;
  if (!B) return;

  var state = {
    cooldownUntil: 0,
    cameraTimer: null,
    cameraEtag: '',
    cameraFails: 0,
    statusTimer: null,
    lastStatus: null
  };

  /* ══════════════════════════ gate open ══════════════════════════ */

  function initOpenButton() {
    var btn = $('[data-gate-open]');
    if (!btn) return;

    var cooldown = parseInt(btn.getAttribute('data-gate-cooldown') || '3', 10) * 1000;
    var url = btn.getAttribute('data-gate-open');
    var residentId = btn.getAttribute('data-gate-resident') || '';
    var device = btn.getAttribute('data-gate-device') || '';

    btn.addEventListener('click', function (e) {
      e.preventDefault();
      if (Date.now() < state.cooldownUntil) {
        var left = Math.ceil((state.cooldownUntil - Date.now()) / 1000);
        B.toast.warning('Повторное открытие доступно через ' + left + ' с.');
        return;
      }
      open();
    });

    function open() {
      B.busy(btn, true, 'Открываем…');
      setVisual('opening');

      var started = Date.now();
      B.post(url, { resident_id: residentId || null, device: device || null })
        .then(function (data) {
          B.busy(btn, false);
          state.cooldownUntil = Date.now() + cooldown;
          setVisual('open');
          B.toast.success(data.message || 'Шлагбаум открыт', {
            title: 'Готово',
            duration: 3500
          });
          if (navigator.vibrate) { try { navigator.vibrate([12, 40, 12]); } catch (e) {} }
          startCooldownCountdown();
          refreshStatus(true);
          reloadFeed();
        })
        .catch(function (err) {
          B.busy(btn, false);
          setVisual('denied');
          if (navigator.vibrate) { try { navigator.vibrate(140); } catch (e) {} }
          var message = (err && err.message) || 'Не удалось открыть шлагбаум';
          B.toast.error(message, { title: 'Отказ', duration: 8000 });
          if (err && err.data && err.data.error_code === 'cooldown') {
            state.cooldownUntil = Date.now() + cooldown;
            startCooldownCountdown();
          }
          refreshStatus(true);
        });

      // Guard against a hung request blocking the UI.
      setTimeout(function () {
        if (btn.getAttribute('aria-busy') === 'true') {
          B.busy(btn, false);
          setVisual('idle');
        }
      }, Math.max(15000, 15000));
      void started;
    }

    function startCooldownCountdown() {
      var label = $('[data-gate-cooldown-label]');
      var tick = setInterval(function () {
        var left = Math.ceil((state.cooldownUntil - Date.now()) / 1000);
        if (left <= 0) { clearInterval(tick); if (label) label.textContent = ''; btn.disabled = false; return; }
        btn.disabled = true;
        if (label) label.textContent = 'Повторно через ' + left + ' с';
      }, 250);
    }
  }

  function setVisual(phase) {
    var bar = $('[data-gate-bar]');
    var badge = $('[data-gate-state]');
    if (bar) {
      bar.classList.toggle('is-open', phase === 'open' || phase === 'opening');
    }
    if (badge) {
      badge.className = 'badge badge-dot ' + ({
        open: 'badge-soft-success', opening: 'badge-soft-info',
        denied: 'badge-soft-destructive', idle: 'badge-muted'
      }[phase] || 'badge-muted');
      badge.textContent = { open: 'Открыт', opening: 'Открывается…', denied: 'Отказ', idle: 'Закрыт' }[phase] || '—';
    }
    if (phase === 'open') {
      setTimeout(function () { setVisual('idle'); }, 6000);
    }
  }

  /* ══════════════════════════ device status ══════════════════════════ */

  function initStatus() {
    var node = $('[data-gate-status]');
    if (!node) return;

    var url = node.getAttribute('data-gate-status');
    var interval = Math.max(5000, parseInt(node.getAttribute('data-gate-status-interval') || '60000', 10));

    window.refreshStatus = function (force) {
      if (doc.hidden && !force) return;
      fetchStatus(url + (url.indexOf('?') >= 0 ? '&' : '?') + (force ? 'fresh=1&' : '') + 'with_time=1')
        .then(render)
        .catch(function () { setOffline(); });
    };

    function fetchStatus(target) {
      return B.get(target);
    }

    function render(data) {
      state.lastStatus = data;
      var ok = !!data.ok;
      var status = data.status || {};

      $('[data-status-dot]') && ($('[data-status-dot]').className = 'health-dot ' + (ok ? 'ok' : 'fail'));
      setText('[data-status-sip]', status.sip_status || status.sip || (ok ? 'на связи' : 'нет ответа'));
      setText('[data-status-firmware]', status.firmware || status.version || '—');
      setText('[data-status-uptime]', status.uptime ? humanUptime(status.uptime) : '—');
      setText('[data-status-ms]', (data.ms || data.duration_ms || 0) + ' мс');
      setText('[data-status-checked]', new Date().toLocaleTimeString());

      if (data.time && data.time.device_time) {
        setText('[data-device-time]', data.time.device_time);
        var drift = data.time.drift_seconds || 0;
        var driftEl = $('[data-device-drift]');
        if (driftEl) {
          driftEl.textContent = (drift > 0 ? '+' : '') + drift + ' с';
          driftEl.className = 'tabular text-xs font-medium ' + (Math.abs(drift) > 120 ? 'text-destructive' : 'text-muted-foreground');
        }
      }

      var badge = $('[data-device-health]');
      if (badge) {
        badge.className = 'badge ' + (ok ? 'badge-soft-success' : 'badge-soft-destructive');
        badge.textContent = ok ? 'Панель на связи' : (data.message || 'Панель недоступна');
      }
      if (!ok) setOffline();
    }

    function setOffline() {
      var badge = $('[data-device-health]');
      if (badge && !badge.textContent.trim()) {
        badge.className = 'badge badge-soft-destructive';
        badge.textContent = 'Нет связи с панелью';
      }
      $('[data-status-dot]') && ($('[data-status-dot]').className = 'health-dot fail');
    }

    function humanUptime(seconds) {
      var s = parseInt(seconds, 10) || 0;
      var d = Math.floor(s / 86400);
      var h = Math.floor(s % 86400 / 3600);
      var m = Math.floor(s % 3600 / 60);
      return (d ? d + 'д ' : '') + (h ? h + 'ч ' : '') + m + 'м';
    }

    window.refreshStatus(false);
    state.statusTimer = setInterval(function () { window.refreshStatus(false); }, interval);
    doc.addEventListener('visibilitychange', function () { if (!doc.hidden) window.refreshStatus(true); });
  }

  function setText(selector, value) {
    var el = $(selector);
    if (el) el.textContent = String(value === null || value === undefined ? '—' : value);
  }

  /* ══════════════════════════ camera ══════════════════════════ */

  function initCamera() {
    var frame = $('[data-camera]');
    if (!frame) return;

    var url = frame.getAttribute('data-camera');
    var interval = Math.max(1500, parseInt(frame.getAttribute('data-camera-interval') || '5000', 10));
    var img = $('img', frame);
    var toggle = $('[data-camera-toggle]');
    var enabled = true;
    try { enabled = localStorage.getItem('basip:camera') !== '0'; } catch (e) {}

    if (toggle) {
      toggle.addEventListener('click', function () {
        enabled = !enabled;
        try { localStorage.setItem('basip:camera', enabled ? '1' : '0'); } catch (e) {}
        toggle.textContent = enabled ? 'Остановить' : 'Показать камеру';
        if (enabled) tick(); else stop();
      });
      toggle.textContent = enabled ? 'Остановить' : 'Показать камеру';
    }

    function stop() {
      if (state.cameraTimer) clearInterval(state.cameraTimer);
      state.cameraTimer = null;
    }

    function tick() {
      if (!enabled || doc.hidden) return;
      var src = url + (url.indexOf('?') >= 0 ? '&' : '?') + 't=' + Date.now();
      var probe = new Image();
      probe.onload = function () {
        if (img) img.src = src;
        state.cameraFails = 0;
        frame.classList.remove('opacity-50');
        var stamp = $('[data-camera-stamp]');
        if (stamp) stamp.textContent = new Date().toLocaleTimeString();
      };
      probe.onerror = function () {
        state.cameraFails++;
        if (state.cameraFails > 3) {
          frame.classList.add('opacity-50');
          var err = $('[data-camera-error]');
          if (err) err.hidden = false;
          stop();
        }
      };
      probe.src = src;
    }

    if (enabled) {
      tick();
      state.cameraTimer = setInterval(tick, interval);
    }
    doc.addEventListener('visibilitychange', function () {
      if (doc.hidden) stop();
      else if (enabled && !state.cameraTimer) { tick(); state.cameraTimer = setInterval(tick, interval); }
    });
  }

  /* ══════════════════════════ guest pass ══════════════════════════ */

  function initGuestForm() {
    var form = $('[data-guest-form]');
    if (!form) return;

    var ttlInput = $('[data-guest-ttl]', form);
    var untilEl = $('[data-guest-until]');
    var presetWrap = $('[data-guest-presets]');

    function updateUntil() {
      if (!ttlInput || !untilEl) return;
      var hours = parseFloat(String(ttlInput.value).replace(',', '.')) || 12;
      var from = $('[data-guest-from]', form);
      var base = from && from.value ? new Date(from.value) : new Date();
      if (isNaN(base.getTime())) base = new Date();
      base.setHours(base.getHours() + hours);
      untilEl.textContent = base.toLocaleString('ru-RU', { day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' });
      untilEl.setAttribute('data-countdown', base.toISOString());
    }

    if (ttlInput) {
      ttlInput.addEventListener('input', B.debounce(updateUntil, 120));
      updateUntil();
    }

    if (presetWrap) {
      presetWrap.addEventListener('click', function (e) {
        var chip = e.target.closest('[data-guest-preset]');
        if (!chip || !ttlInput) return;
        ttlInput.value = chip.getAttribute('data-guest-preset');
        $$('[data-guest-preset]', presetWrap).forEach(function (c) { c.classList.remove('is-active'); c.removeAttribute('aria-pressed'); });
        chip.classList.add('is-active');
        chip.setAttribute('aria-pressed', 'true');
        updateUntil();
      });
    }

    // Plate mask: uppercase, strip junk.
    var plate = $('[name="car_plate"]', form);
    if (plate) {
      plate.addEventListener('input', function () {
        plate.value = plate.value.toUpperCase().replace(/[^А-ЯЁA-Z0-9\- ]/gi, '').slice(0, 16);
      });
      plate.addEventListener('blur', function () { plate.value = plate.value.replace(/\s+/g, ''); });
    }

    // Submit → show the pass card with QR + share buttons.
    form.addEventListener('basip:success', function (e) {
      var data = e.detail || {};
      showIssuedPass(data);
      ModalCloseForm(form);
      reloadPassList();
    });

    form.addEventListener('submit', function (e) {
      if (!form.hasAttribute('data-ajax')) return;
      e.preventDefault();
      B.submitForm(form).catch(function () {});
    });
  }

  function ModalCloseForm(form) {
    var scope = form.closest('.dialog, .sheet');
    if (!scope) return;
    var entry = B.dialog.stack.filter(function (s) { return s.target === scope; })[0];
    B.dialog.close(entry);
  }

  /**
   * Render the issued pass: code, PIN, link, QR and native share.
   * @param {{code?:string,pin?:string,link?:string,expires_at?:string,id?:number}} data
   */
  function showIssuedPass(data) {
    if (!data || !data.code) return;

    var host = $('[data-guest-result]');
    if (!host) {
      B.toast.success('Гостевой доступ создан: ' + data.code + ' (до ' + (data.expires_at || '—') + ')', { duration: 9000 });
      return;
    }

    var absolute = data.link && /^https?:/i.test(data.link)
      ? data.link
      : window.location.origin + (data.link || '');

    host.innerHTML =
      '<div class="grid gap-4">' +
        '<div class="flex items-start justify-between gap-3">' +
          '<div><div class="text-xs uppercase tracking-wide text-muted-foreground">Код доступа</div>' +
          '<div class="font-mono text-3xl font-semibold tracking-wider">' + B.escapeHtml(data.code) + '</div></div>' +
          (data.pin ? '<div class="text-right"><div class="text-xs uppercase tracking-wide text-muted-foreground">PIN</div>' +
            '<div class="font-mono text-2xl font-semibold tabular">' + B.escapeHtml(data.pin) + '</div></div>' : '') +
        '</div>' +
        '<div class="flex items-center justify-between gap-3 rounded-lg border bg-muted/40 p-3">' +
          '<div class="min-w-0"><div class="text-xs text-muted-foreground">Действует до</div>' +
          '<div class="text-sm font-medium">' + B.escapeHtml(data.expires_at || '—') + '</div></div>' +
          '<div class="shrink-0 text-right"><div class="text-xs text-muted-foreground">Осталось</div>' +
          '<div class="text-sm font-medium tabular" data-countdown="' + B.escapeHtml(toIso(data.expires_at)) + '"></div></div>' +
        '</div>' +
        '<div class="qr-frame mx-auto w-full max-w-[220px]" data-guest-qr></div>' +
        '<div class="grid gap-2 sm:grid-cols-2">' +
          '<button type="button" class="btn btn-outline" data-copy="' + B.escapeHtml(absolute) + '" data-copy-message="Ссылка скопирована">Скопировать ссылку</button>' +
          '<button type="button" class="btn btn-default" data-guest-share>Поделиться</button>' +
        '</div>' +
        '<p class="text-xs text-muted-foreground">Отправьте ссылку гостю — он откроет шлагбаум сам, без звонка. ' +
        'Все открытия записываются в журнал.</p>' +
      '</div>';

    host.hidden = false;
    B.qr(host.querySelector('[data-guest-qr]'), absolute, 220);

    var share = host.querySelector('[data-guest-share]');
    if (share) {
      share.addEventListener('click', function () {
        var payload = {
          title: 'Гостевой доступ к шлагбауму',
          text: 'Код ' + data.code + '. Действует до ' + (data.expires_at || '') + '.',
          url: absolute
        };
        if (navigator.share) {
          navigator.share(payload).catch(function () { fallbackShare(payload); });
        } else {
          fallbackShare(payload);
        }
      });
    }

    // Wire the countdown that we just injected.
    host.querySelectorAll('[data-countdown]').forEach(function (el) {
      var until = Date.parse(el.getAttribute('data-countdown'));
      if (isNaN(until)) return;
      var tick = function () {
        var diff = Math.max(0, until - Date.now());
        var h = Math.floor(diff / 3600000), m = Math.floor(diff / 60000) % 60;
        el.textContent = diff <= 0 ? 'истёк' : h + 'ч ' + String(m).padStart(2, '0') + 'м';
      };
      tick();
      setInterval(tick, 30000);
    });
  }

  function fallbackShare(payload) {
    var text = payload.text + '\n' + payload.url;
    if (navigator.clipboard && window.isSecureContext) {
      navigator.clipboard.writeText(text).then(function () { B.toast.success('Текст для отправки скопирован'); });
      return;
    }
    window.open('https://wa.me/?text=' + encodeURIComponent(text), '_blank', 'noopener');
  }

  function toIso(value) {
    if (!value) return '';
    var normalized = String(value).replace(' ', 'T');
    var ts = Date.parse(normalized);
    return isNaN(ts) ? '' : new Date(ts).toISOString();
  }

  /* ══════════════════════════ lists that refresh ══════════════════════════ */

  function reloadFeed() {
    var feed = $('[data-gate-feed]');
    if (!feed || !feed.getAttribute('data-gate-feed')) return;
    B.get(feed.getAttribute('data-gate-feed')).then(function (data) {
      if (data && typeof data.html === 'string') feed.innerHTML = data.html;
    }).catch(function () {});
  }

  function reloadPassList() {
    var list = $('[data-guest-list]');
    if (!list || !list.getAttribute('data-guest-list')) return;
    B.get(list.getAttribute('data-guest-list')).then(function (data) {
      if (data && typeof data.html === 'string') list.innerHTML = data.html;
    }).catch(function () {});
  }

  /* ══════════════════════════ device commands ══════════════════════════ */

  function initDeviceCommands() {
    $$('[data-device-command]').forEach(function (btn) {
      btn.addEventListener('click', function (e) {
        e.preventDefault();
        var command = btn.getAttribute('data-device-command');
        var target = btn.getAttribute('data-device-url');
        var method = (btn.getAttribute('data-device-method') || 'POST').toUpperCase();
        var body = {};
        try { body = JSON.parse(btn.getAttribute('data-device-body') || '{}'); } catch (err) { body = {}; }

        var run = function () {
          B.busy(btn, true);
          var request = method === 'GET' ? B.get(target) : B.post(target, body);
          request.then(function (data) {
            B.busy(btn, false);
            B.toast.success((data && (data.message || 'Команда выполнена')) || 'Готово');
            if (window.refreshStatus) window.refreshStatus(true);
          }).catch(function (err) {
            B.busy(btn, false);
            B.toast.error((err && err.message) || 'Команда не выполнена');
          });
        };

        if (btn.hasAttribute('data-confirm')) {
          B.confirm({
            title: btn.getAttribute('data-confirm-title') || 'Подтвердите действие',
            message: btn.getAttribute('data-confirm'),
            tone: btn.getAttribute('data-confirm-tone') || 'default',
            confirmLabel: btn.getAttribute('data-confirm-label') || 'Выполнить'
          }).then(function (ok) { if (ok) run(); });
        } else {
          run();
        }
        void command;
      });
    });
  }

  /* ══════════════════════════ boot ══════════════════════════ */

  function $(sel, ctx) { return (ctx || document).querySelector(sel); }
  function $$(sel, ctx) { return Array.prototype.slice.call((ctx || document).querySelectorAll(sel)); }
  var doc = document;

  function boot() {
    initOpenButton();
    initStatus();
    initCamera();
    initGuestForm();
    initDeviceCommands();

    // Shift+O opens the gate from anywhere on the panel.
    doc.addEventListener('keydown', function (e) {
      if (e.shiftKey && (e.key === 'O' || e.key === 'Щ') && !/^(INPUT|TEXTAREA|SELECT)$/.test(doc.activeElement.tagName)) {
        var btn = $('[data-gate-open]');
        if (btn && !btn.disabled) { e.preventDefault(); btn.click(); }
      }
    });
  }

  if (doc.readyState === 'loading') doc.addEventListener('DOMContentLoaded', boot);
  else boot();
})();
