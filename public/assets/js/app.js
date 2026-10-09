/**
 * BAS-IP Gate Manager · UI runtime
 * ─────────────────────────────────────────────────────────────────────────────
 * Vanilla ES2020, no framework, no CDN. Everything is progressive enhancement:
 * every control works as a plain HTML form without JS, and JS layers on
 * dialogs, toasts, AJAX submission, the command palette, live polling, charts
 * and QR codes.
 *
 * Public API: window.BasIp  (toast, dialog, confirm, fetch, qrcode, …)
 *
 * Conventions used by the PHP templates:
 *   data-dialog="#id"          open a dialog
 *   data-close                 close the nearest dialog/sheet/dropdown
 *   data-dropdown="#id"        toggle a dropdown
 *   data-tabs / data-tab       tab group
 *   data-toast="message"       show a toast on click
 *   data-copy="text"           copy to clipboard
 *   data-confirm="Are you sure?"  guard a destructive submit/click
 *   data-ajax                  submit the form via fetch and toast the result
 *   data-tooltip="text"        hover tooltip
 *   data-countdown="ISO"       live countdown
 *   data-poll-url / data-poll-interval   periodic refresh
 */
(function () {
  'use strict';

  var doc = document;
  var root = doc.documentElement;

  /* ═══════════════════════════ tiny helpers ═══════════════════════════ */

  function $(sel, ctx) { return (ctx || doc).querySelector(sel); }
  function $$(sel, ctx) { return Array.prototype.slice.call((ctx || doc).querySelectorAll(sel)); }

  function on(target, type, sel, handler, opts) {
    // Delegated binding so dynamically injected markup keeps working.
    target.addEventListener(type, function (e) {
      var node = e.target.closest ? e.target.closest(sel) : null;
      if (node && target.contains(node)) handler(e, node);
    }, opts || false);
  }

  function csrfToken() {
    var meta = $('meta[name="csrf-token"]');
    if (meta) return meta.getAttribute('content');
    var input = $('input[name="_token"]');
    return input ? input.value : '';
  }

  function basePath() { return (window.__BASIP && window.__BASIP.basePath) || ''; }

  function url(path) {
    if (/^https?:\/\//i.test(path)) return path;
    return basePath() + (path.charAt(0) === '/' ? path : '/' + path);
  }

  /** POST as JSON with the CSRF header; resolves to parsed JSON. */
  function post(path, body, opts) {
    return fetch(url(path), Object.assign({
      method: 'POST',
      credentials: 'same-origin',
      headers: {
        'Content-Type': 'application/json',
        'Accept': 'application/json',
        'X-CSRF-Token': csrfToken(),
        'X-Requested-With': 'XMLHttpRequest'
      },
      body: body === undefined ? null : JSON.stringify(body || {})
    }, opts || {})).then(function (res) {
      return res.text().then(function (text) {
        var data = null;
        try { data = JSON.parse(text); } catch (e) { data = { ok: res.ok, raw: text }; }
        if (!res.ok && !(data && data.ok === true)) {
          var err = new Error((data && (data.error || data.message)) || ('HTTP ' + res.status));
          err.status = res.status; err.data = data;
          throw err;
        }
        return data;
      });
    });
  }

  function get(path, opts) {
    return fetch(url(path), Object.assign({
      credentials: 'same-origin',
      headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
    }, opts || {})).then(function (r) { return r.json(); });
  }

  /* ═══════════════════════════ toasts ═══════════════════════════ */

  var Toast = (function () {
    var viewport = null;
    var queue = [];
    var MAX = 4;

    function ensureViewport() {
      if (viewport && doc.body.contains(viewport)) return viewport;
      viewport = doc.createElement('div');
      viewport.className = 'toast-viewport';
      viewport.setAttribute('role', 'region');
      viewport.setAttribute('aria-label', 'Уведомления');
      doc.body.appendChild(viewport);
      return viewport;
    }

    function dismiss(el) {
      if (!el || el.dataset.closing) return;
      el.dataset.closing = '1';
      el.style.transition = 'opacity .18s, transform .18s';
      el.style.opacity = '0';
      el.style.transform = 'translateY(6px) scale(.98)';
      setTimeout(function () {
        el.remove();
        queue = queue.filter(function (x) { return x !== el; });
        flush();
      }, 180);
    }

    function flush() {
      while (queue.length > MAX) dismiss(queue.shift());
    }

    function show(message, options) {
      options = options || {};
      var vp = ensureViewport();
      var type = options.type || 'info';
      var el = doc.createElement('div');
      el.className = 'toast toast-' + type;
      el.setAttribute('role', type === 'destructive' ? 'alert' : 'status');
      el.setAttribute('aria-live', type === 'destructive' ? 'assertive' : 'polite');

      var icon = { success: 'check-circle', destructive: 'alert-circle', warning: 'alert-triangle', info: 'info' }[type] || 'info';

      el.innerHTML =
        '<svg class="lucide mt-0.5 text-base shrink-0" aria-hidden="true"><use href="' + iconHref(icon) + '"></use></svg>' +
        '<div class="min-w-0 flex-1">' +
          (options.title ? '<div class="toast-title">' + escapeHtml(options.title) + '</div>' : '') +
          '<div class="' + (options.title ? 'toast-desc' : '') + '">' + escapeHtml(message) + '</div>' +
          (options.actionLabel ? '<button type="button" class="btn btn-sm btn-outline mt-2" data-toast-action>' + escapeHtml(options.actionLabel) + '</button>' : '') +
        '</div>' +
        '<button type="button" class="toast-close" aria-label="Закрыть"><svg class="lucide"><use href="' + iconHref('x') + '"></use></svg></button>';

      if (options.duration !== 0) {
        var bar = doc.createElement('span');
        bar.className = 'toast-progress';
        bar.style.animationDuration = (options.duration || 4500) + 'ms';
        el.appendChild(bar);
      }

      vp.appendChild(el);
      queue.push(el);
      flush();

      var timer = options.duration === 0 ? null : setTimeout(function () { dismiss(el); }, options.duration || 4500);
      $('.toast-close', el).addEventListener('click', function () { if (timer) clearTimeout(timer); dismiss(el); });
      if (options.actionLabel && typeof options.onAction === 'function') {
        $('[data-toast-action]', el).addEventListener('click', function () { options.onAction(); dismiss(el); });
      }
      el.addEventListener('mouseenter', function () { if (timer) clearTimeout(timer); });

      return { el: el, close: function () { dismiss(el); } };
    }

    return {
      show: show,
      success: function (m, o) { return show(m, Object.assign({ type: 'success' }, o || {})); },
      error: function (m, o) { return show(m, Object.assign({ type: 'destructive', duration: 8000 }, o || {})); },
      warning: function (m, o) { return show(m, Object.assign({ type: 'warning' }, o || {})); },
      info: function (m, o) { return show(m, Object.assign({ type: 'info' }, o || {})); },
      dismissAll: function () { queue.slice().forEach(dismiss); }
    };
  })();

  /* ═══════════════════════════ icons & escaping ═══════════════════════════ */

  function iconHref(name) {
    var base = (window.__BASIP && window.__BASIP.iconBase) || (basePath() + '/assets/icons/lucide.svg');
    return base + '#i-' + name;
  }

  function escapeHtml(value) {
    return String(value === null || value === undefined ? '' : value)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;').replace(/'/g, '&#039;');
  }

  function icon(name, cls) {
    return '<svg class="lucide ' + (cls || 'h-4 w-4') + '" aria-hidden="true"><use href="' + iconHref(name) + '"></use></svg>';
  }

  /* ═══════════════════════════ dialogs / sheets ═══════════════════════════ */

  var Modal = (function () {
    var stack = [];

    function lockScroll(on) {
      if (on) {
        doc.body.dataset.scrollY = String(window.scrollY);
        doc.body.style.position = 'fixed';
        doc.body.style.top = '-' + doc.body.dataset.scrollY + 'px';
        doc.body.style.width = '100%';
      } else {
        doc.body.style.position = '';
        doc.body.style.top = '';
        doc.body.style.width = '';
        window.scrollTo(0, parseInt(doc.body.dataset.scrollY || '0', 10));
      }
    }

    function open(target) {
      if (typeof target === 'string') target = $(target);
      if (!target || target.dataset.state === 'open') return null;

      var overlay = doc.createElement('div');
      overlay.className = 'overlay';
      overlay.dataset.state = 'open';
      overlay.setAttribute('data-modal-overlay', '');
      target.parentNode.insertBefore(overlay, target);

      target.dataset.state = 'open';
      target.removeAttribute('hidden');
      target.setAttribute('aria-modal', 'true');
      target.setAttribute('role', 'dialog');

      var entry = { target: target, overlay: overlay, restore: doc.activeElement };
      stack.push(entry);
      lockScroll(true);

      var focusable = target.querySelector('[data-autofocus], input:not([type=hidden]), select, textarea, button, [tabindex]:not([tabindex="-1"])');
      setTimeout(function () { if (focusable) focusable.focus(); }, 30);

      target.dispatchEvent(new CustomEvent('basip:opened', { bubbles: true }));
      return entry;
    }

    function close(entry) {
      if (!entry) entry = stack.pop();
      if (!entry) return;
      var idx = stack.indexOf(entry);
      if (idx >= 0) stack.splice(idx, 1);

      entry.target.dataset.state = 'closed';
      entry.target.setAttribute('hidden', '');
      if (entry.overlay) entry.overlay.remove();
      if (stack.length === 0) lockScroll(false);
      if (entry.restore && entry.restore.focus) entry.restore.focus();
      entry.target.dispatchEvent(new CustomEvent('basip:closed', { bubbles: true }));
    }

    function closeTop() { if (stack.length) close(stack[stack.length - 1]); }
    function closeAll() { while (stack.length) close(stack.pop()); }
    function isOpen() { return stack.length > 0; }

    return { open: open, close: close, closeTop: closeTop, closeAll: closeAll, isOpen: isOpen, stack: stack };
  })();

  /**
   * Promise-based confirm dialog. Resolves true/false.
   * Falls back to window.confirm when JS dialogs are disabled.
   */
  function confirmDialog(options) {
    options = options || {};
    if (typeof options === 'string') options = { message: options };

    var id = 'basip-confirm-' + Date.now();
    var tone = options.tone || 'default';
    var confirmLabel = options.confirmLabel || (tone === 'destructive' ? 'Удалить' : 'Подтвердить');

    var wrap = doc.createElement('div');
    wrap.id = id;
    wrap.className = 'dialog dialog-sm dialog-no-sheet';
    wrap.dataset.state = 'closed';
    wrap.setAttribute('hidden', '');
    wrap.innerHTML =
      '<div class="dialog-header">' +
        '<h2 class="dialog-title" id="' + id + '-t">' + escapeHtml(options.title || 'Подтвердите действие') + '</h2>' +
        '<p class="dialog-desc" id="' + id + '-d">' + escapeHtml(options.message || 'Вы уверены?') + '</p>' +
      '</div>' +
      (options.details ? '<pre class="max-h-40 overflow-auto rounded-md bg-muted p-3 font-mono text-xs">' + escapeHtml(options.details) + '</pre>' : '') +
      (options.requireText ? '<div class="field"><label class="label" for="' + id + '-i">Введите <code class="inline-code">' + escapeHtml(options.requireText) + '</code> для подтверждения</label>' +
        '<input class="input input-mono" id="' + id + '-i" autocomplete="off" spellcheck="false"></div>' : '') +
      '<div class="dialog-footer">' +
        '<button type="button" class="btn btn-outline" data-close>' + escapeHtml(options.cancelLabel || 'Отмена') + '</button>' +
        '<button type="button" class="btn btn-' + (tone === 'destructive' ? 'destructive' : 'default') + '" data-confirm>' + escapeHtml(confirmLabel) + '</button>' +
      '</div>';
    doc.body.appendChild(wrap);

    return new Promise(function (resolve) {
      var entry = Modal.open(wrap);
      var input = $('#' + id + '-i', wrap);

      function finish(value) {
        Modal.close(entry);
        setTimeout(function () { wrap.remove(); }, 200);
        resolve(value);
      }

      $('[data-confirm]', wrap).addEventListener('click', function () {
        if (options.requireText && (!input || input.value.trim() !== options.requireText)) {
          if (input) { input.focus(); input.classList.add('border-destructive'); }
          Toast.warning('Введите подтверждающее слово.');
          return;
        }
        finish(true);
      });
      $('[data-close]', wrap).addEventListener('click', function () { finish(false); });
      wrap.addEventListener('basip:closed', function () { finish(false); }, { once: true });
      if (input) input.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); $('[data-confirm]', wrap).click(); } });
    });
  }

  /* ═══════════════════════════ dropdowns ═══════════════════════════ */

  function closeAllDropdowns(except) {
    $$('[data-dropdown-panel][data-state="open"]').forEach(function (panel) {
      if (panel === except) return;
      panel.dataset.state = 'closed';
      panel.setAttribute('hidden', '');
      var trigger = panel.__trigger;
      if (trigger) trigger.setAttribute('aria-expanded', 'false');
    });
  }

  function toggleDropdown(trigger) {
    var selector = trigger.getAttribute('data-dropdown');
    var panel = selector ? $(selector) : trigger.nextElementSibling;
    if (!panel) return;
    var open = panel.dataset.state === 'open';
    closeAllDropdowns();
    if (open) {
      panel.dataset.state = 'closed';
      panel.setAttribute('hidden', '');
      trigger.setAttribute('aria-expanded', 'false');
      return;
    }
    panel.dataset.state = 'open';
    panel.removeAttribute('hidden');
    panel.__trigger = trigger;
    trigger.setAttribute('aria-expanded', 'true');

    // Keep the panel inside the viewport.
    var rect = panel.getBoundingClientRect();
    if (rect.right > window.innerWidth - 8) {
      panel.style.left = 'auto'; panel.style.right = '0';
    }
    if (rect.bottom > window.innerHeight - 8) {
      panel.style.top = 'auto'; panel.style.bottom = '100%';
    }
    var first = panel.querySelector('[role="menuitem"], button, a, input');
    if (first) first.focus();
  }

  /* ═══════════════════════════ tabs ═══════════════════════════ */

  function activateTab(trigger) {
    var group = trigger.closest('[data-tabs]');
    var value = trigger.getAttribute('data-tab');
    if (!group || !value) return;

    $$('[data-tab]', group).forEach(function (t) {
      var active = t === trigger;
      t.setAttribute('data-state', active ? 'active' : 'inactive');
      t.setAttribute('aria-selected', active ? 'true' : 'false');
      t.tabIndex = active ? 0 : -1;
    });
    $$('[data-tab-panel]', group).forEach(function (panel) {
      var active = panel.getAttribute('data-tab-panel') === value;
      panel.hidden = !active;
      panel.dataset.state = active ? 'active' : 'inactive';
    });

    var key = group.getAttribute('data-tabs-persist');
    if (key) { try { localStorage.setItem('basip:tabs:' + key, value); } catch (e) {} }
    group.dispatchEvent(new CustomEvent('basip:tab', { detail: { tab: value }, bubbles: true }));

    // Reflect in the URL hash so links are shareable.
    if (group.hasAttribute('data-tabs-hash')) {
      try { history.replaceState(null, '', '#' + value); } catch (e) {}
    }
  }

  function restoreTabs() {
    $$('[data-tabs]').forEach(function (group) {
      var key = group.getAttribute('data-tabs-persist');
      var saved = null;
      if (group.hasAttribute('data-tabs-hash') && location.hash) saved = location.hash.slice(1);
      if (!saved && key) { try { saved = localStorage.getItem('basip:tabs:' + key); } catch (e) {} }
      if (!saved) {
        var urlTab = new URLSearchParams(location.search).get('tab');
        if (urlTab) saved = urlTab;
      }
      if (!saved) return;
      var trigger = group.querySelector('[data-tab="' + CSS.escape(saved) + '"]');
      if (trigger) activateTab(trigger);
    });
  }

  /* ═══════════════════════════ tooltips ═══════════════════════════ */

  var Tooltip = (function () {
    var el = null;
    function ensure() {
      if (el && doc.body.contains(el)) return el;
      el = doc.createElement('div');
      el.className = 'tooltip';
      el.setAttribute('role', 'tooltip');
      doc.body.appendChild(el);
      return el;
    }
    function show(target) {
      var text = target.getAttribute('data-tooltip');
      if (!text) return;
      var tip = ensure();
      tip.textContent = text;
      tip.classList.add('is-visible');
      var rect = target.getBoundingClientRect();
      var tipRect = tip.getBoundingClientRect();
      var top = rect.top - tipRect.height - 8;
      if (top < 8) top = rect.bottom + 8;
      var left = rect.left + rect.width / 2 - tipRect.width / 2;
      left = Math.max(8, Math.min(left, window.innerWidth - tipRect.width - 8));
      tip.style.top = (top + window.scrollY) + 'px';
      tip.style.left = (left + window.scrollX) + 'px';
    }
    function hide() { if (el) el.classList.remove('is-visible'); }
    return { show: show, hide: hide };
  })();

  /* ═══════════════════════════ clipboard ═══════════════════════════ */

  function copy(text, message) {
    var done = function () { Toast.success(message || 'Скопировано в буфер обмена'); };
    var fail = function () { Toast.error('Не удалось скопировать. Скопируйте вручную.'); };

    if (navigator.clipboard && window.isSecureContext) {
      navigator.clipboard.writeText(text).then(done).catch(function () { legacy(); });
      return;
    }
    legacy();

    function legacy() {
      try {
        var ta = doc.createElement('textarea');
        ta.value = text;
        ta.setAttribute('readonly', '');
        ta.style.cssText = 'position:fixed;top:-1000px;opacity:0';
        doc.body.appendChild(ta);
        ta.select();
        var ok = doc.execCommand('copy');
        ta.remove();
        ok ? done() : fail();
      } catch (e) { fail(); }
    }
  }

  /* ═══════════════════════════ forms & AJAX ═══════════════════════════ */

  function busy(el, on, label) {
    if (!el) return;
    if (on) {
      el.dataset.busyLabel = el.innerHTML;
      el.setAttribute('aria-busy', 'true');
      el.disabled = true;
      el.innerHTML = '<span class="inline-flex items-center gap-2">' +
        '<svg class="lucide animate-spin" aria-hidden="true"><use href="' + iconHref('loader-circle') + '"></use></svg>' +
        (label ? escapeHtml(label) : '') + '</span>';
    } else {
      el.removeAttribute('aria-busy');
      el.disabled = false;
      if (el.dataset.busyLabel) el.innerHTML = el.dataset.busyLabel;
    }
  }

  /** Submit a form via fetch, then toast / redirect / dispatch. */
  function submitForm(form, options) {
    options = options || {};
    var submitter = form.__submitter || form.querySelector('[type=submit]');
    busy(submitter, true, options.busyLabel);

    var method = (form.getAttribute('method') || 'POST').toUpperCase();
    var action = form.getAttribute('action') || window.location.pathname;
    var body;
    var headers = { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-Token': csrfToken() };

    if (options.json !== false && !form.querySelector('input[type=file]')) {
      headers['Content-Type'] = 'application/json';
      body = JSON.stringify(formDataToObject(new FormData(form)));
    } else {
      body = new FormData(form);
    }
    if (method === 'GET') {
      action += (action.indexOf('?') >= 0 ? '&' : '?') + new URLSearchParams(body).toString();
      body = null;
    }

    return fetch(url(action), {
      method: method, body: body, headers: headers, credentials: 'same-origin'
    }).then(function (res) {
      return res.text().then(function (text) {
        var data;
        try { data = JSON.parse(text); } catch (e) { data = { ok: res.ok, message: text.slice(0, 300) }; }
        return { res: res, data: data };
      });
    }).then(function (r) {
      busy(submitter, false);
      var data = r.data || {};
      if (r.res.ok && data.ok !== false) {
        if (data.message && !options.silent) Toast.success(data.message);
        form.dispatchEvent(new CustomEvent('basip:success', { detail: data, bubbles: true }));
        var redirect = data.redirect || form.getAttribute('data-redirect');
        if (redirect) { window.location.href = redirect; return data; }
        if (options.reload !== false && form.hasAttribute('data-reload')) window.location.reload();
        return data;
      }
      var message = data.error || data.message || ('Ошибка (' + r.res.status + ')');
      if (!options.silent) Toast.error(message);
      showFieldErrors(form, data.errors || {});
      form.dispatchEvent(new CustomEvent('basip:error', { detail: data, bubbles: true }));
      return Promise.reject(new Error(message));
    }).catch(function (err) {
      busy(submitter, false);
      if (err && err.message && !/Ошибка/.test(err.message)) Toast.error(err.message);
      throw err;
    });
  }

  function formDataToObject(fd) {
    var out = {};
    fd.forEach(function (value, key) {
      // `contacts[0][value]` → nested object
      if (key.indexOf('[') >= 0) {
        var parts = key.replace(/\]$/, '').split('[');
        var node = out;
        parts.forEach(function (part, i) {
          if (i === parts.length - 1) { node[part] = value; return; }
          if (!node[part]) node[part] = /^\d+$/.test(parts[i + 1]) ? [] : {};
          node = node[part];
        });
        return;
      }
      if (Object.prototype.hasOwnProperty.call(out, key)) {
        out[key] = [].concat(out[key], value);
        return;
      }
      out[key] = value;
    });
    return out;
  }

  function showFieldErrors(form, errors) {
    $$('.form-message', form).forEach(function (el) { el.textContent = ''; });
    $$('.input, .select, .textarea', form).forEach(function (el) { el.removeAttribute('aria-invalid'); });
    Object.keys(errors || {}).forEach(function (key) {
      var list = errors[key];
      var message = Array.isArray(list) ? list[0] : list;
      var field = form.querySelector('[name="' + key + '"], [name="' + key + '[]"]');
      if (!field) return;
      field.setAttribute('aria-invalid', 'true');
      var slot = field.closest('.field');
      if (slot) {
        var msg = slot.querySelector('.form-message');
        if (!msg) { msg = doc.createElement('p'); msg.className = 'form-message'; slot.appendChild(msg); }
        msg.textContent = message;
      }
    });
  }

  /* ═══════════════════════════ data tables ═══════════════════════════ */

  function initTable(table) {
    var selectAll = $('[data-select-all]', table);
    if (selectAll) {
      selectAll.addEventListener('change', function () {
        $$('tbody [data-row-select]', table).forEach(function (cb) {
          cb.checked = selectAll.checked;
          cb.dispatchEvent(new Event('change', { bubbles: true }));
        });
      });
    }

    var updateBulk = function () {
      var checked = $$('tbody [data-row-select]:checked', table);
      $$('[data-bulk-bar]').forEach(function (bar) {
        bar.hidden = checked.length === 0;
        $$('[data-bulk-count]', bar).forEach(function (el) { el.textContent = String(checked.length); });
      });
      $$('[data-selected-ids]').forEach(function (el) {
        el.value = checked.map(function (cb) { return cb.value; }).join(',');
      });
      var rows = checked.map(function (cb) { return cb.closest('tr'); });
      $$('tbody tr', table).forEach(function (tr) {
        tr.classList.toggle('is-selected', rows.indexOf(tr) >= 0);
      });
      if (selectAll) {
        var all = $$('tbody [data-row-select]', table);
        selectAll.indeterminate = checked.length > 0 && checked.length < all.length;
        selectAll.checked = all.length > 0 && checked.length === all.length;
      }
    };

    table.addEventListener('change', function (e) {
      if (e.target.matches('[data-row-select]')) updateBulk();
    });

    // Client-side filtering of the visible rows (fast path for small tables).
    var filter = $('[data-table-filter]');
    if (filter) {
      var run = function () {
        var q = filter.value.trim().toLowerCase();
        $$('tbody tr', table).forEach(function (tr) {
          if (!q) { tr.hidden = false; return; }
          tr.hidden = tr.textContent.toLowerCase().indexOf(q) < 0;
        });
      };
      filter.addEventListener('input', debounce(run, 160));
      run();
    }

    updateBulk();
  }

  /* ═══════════════════════════ misc widgets ═══════════════════════════ */

  function debounce(fn, ms) {
    var t;
    return function () {
      var args = arguments, self = this;
      clearTimeout(t);
      t = setTimeout(function () { fn.apply(self, args); }, ms || 200);
    };
  }

  function initCountdowns() {
    var nodes = $$('[data-countdown]');
    if (!nodes.length) return;
    var tick = function () {
      nodes.forEach(function (el) {
        var until = Date.parse(el.getAttribute('data-countdown'));
        if (isNaN(until)) return;
        var diff = Math.max(0, until - Date.now());
        var d = Math.floor(diff / 86400000);
        var h = Math.floor(diff / 3600000) % 24;
        var m = Math.floor(diff / 60000) % 60;
        var s = Math.floor(diff / 1000) % 60;
        el.textContent = diff <= 0
          ? (el.getAttribute('data-countdown-expired') || 'истёк')
          : (d > 0 ? d + 'д ' : '') + String(h).padStart(2, '0') + ':' + String(m).padStart(2, '0') + ':' + String(s).padStart(2, '0');
        if (diff <= 0) el.classList.add('text-destructive');
      });
    };
    tick();
    setInterval(tick, 1000);
  }

  function initRelativeTime() {
    var nodes = $$('[data-time-ago]');
    if (!nodes.length) return;
    var render = function () {
      nodes.forEach(function (el) {
        var ts = Date.parse(el.getAttribute('data-time-ago'));
        if (isNaN(ts)) return;
        var diff = Date.now() - ts;
        var text;
        if (diff < 0) text = 'через ' + humanize(-diff);
        else if (diff < 5000) text = 'только что';
        else text = humanize(diff) + ' назад';
        el.textContent = text;
        el.title = new Date(ts).toLocaleString();
      });
    };
    function humanize(ms) {
      var s = Math.floor(ms / 1000);
      if (s < 60) return s + ' с';
      var m = Math.floor(s / 60);
      if (m < 60) return m + ' ' + plural(m, 'минуту', 'минуты', 'минут');
      var h = Math.floor(m / 60);
      if (h < 24) return h + ' ' + plural(h, 'час', 'часа', 'часов');
      var d = Math.floor(h / 24);
      if (d < 30) return d + ' ' + plural(d, 'день', 'дня', 'дней');
      return Math.floor(d / 30) + ' мес.';
    }
    render();
    setInterval(render, 30000);
  }

  function plural(n, one, few, many) {
    var n10 = n % 10, n100 = n % 100;
    if (n10 === 1 && n100 !== 11) return one;
    if (n10 >= 2 && n10 <= 4 && (n100 < 10 || n100 >= 20)) return few;
    return many;
  }

  /* ── QR codes (vendored qrcode-generator, offline) ─────────────────── */

  function renderQr(container, text, size) {
    if (typeof window.qrcode !== 'function') {
      if (container) container.innerHTML = '<div class="text-xs text-muted-foreground">QR недоступен</div>';
      return;
    }
    try {
      var qr = window.qrcode(0, 'M');
      qr.addData(String(text));
      qr.make();
      var svg = qr.createSvgTag({ cellSize: 4, margin: 2, scalable: true });
      if (container) {
        container.innerHTML = svg;
        var el = container.querySelector('svg');
        if (el) {
          el.setAttribute('width', String(size || 180));
          el.setAttribute('height', String(size || 180));
          el.style.width = '100%';
          el.style.height = 'auto';
          el.setAttribute('role', 'img');
          el.setAttribute('aria-label', 'QR-код гостевого доступа');
        }
      }
      return svg;
    } catch (e) {
      Toast.error('Не удалось построить QR-код.');
    }
  }

  /* ═══════════════════════════ charts (inline SVG) ═══════════════════════════ */

  var Chart = {
    /**
     * Bar chart.
     * @param {HTMLElement} el
     * @param {{labels:string[], values:number[], height?:number, color?:string, format?:Function}} data
     */
    bars: function (el, data) {
      if (!el) return;
      var values = data.values || [];
      var labels = data.labels || [];
      var max = Math.max.apply(null, values.concat([1]));
      var h = data.height || 120;
      var w = Math.max(el.clientWidth || 320, values.length * 12);
      var step = w / Math.max(1, values.length);
      var barW = Math.max(2, step * 0.62);
      var svg = '<svg class="chart" viewBox="0 0 ' + w + ' ' + (h + 18) + '" preserveAspectRatio="none" role="img" aria-label="Столбчатая диаграмма">';
      // grid
      for (var g = 0; g <= 3; g++) {
        var y = (h / 3) * g;
        svg += '<line x1="0" y1="' + y + '" x2="' + w + '" y2="' + y + '" stroke="hsl(var(--chart-grid))" stroke-width="1" stroke-dasharray="2 3"/>';
      }
      values.forEach(function (v, i) {
        var bh = max > 0 ? (v / max) * h : 0;
        var x = i * step + (step - barW) / 2;
        svg += '<rect class="chart-bar" x="' + x.toFixed(1) + '" y="' + (h - bh).toFixed(1) + '" width="' + barW.toFixed(1) +
               '" height="' + Math.max(0, bh).toFixed(1) + '" rx="2"><title>' + escapeHtml(labels[i] || '') + ': ' + (data.format ? data.format(v) : v) + '</title></rect>';
      });
      svg += '</svg>';
      el.innerHTML = svg;
    },

    /** Line/area chart. */
    line: function (el, data) {
      if (!el) return;
      var values = data.values || [];
      var labels = data.labels || [];
      if (!values.length) { el.innerHTML = ''; return; }
      var max = Math.max.apply(null, values.concat([1]));
      var min = data.min === undefined ? 0 : data.min;
      var h = data.height || 120, pad = 4;
      var w = Math.max(el.clientWidth || 320, 120);
      var stepX = (w - pad * 2) / Math.max(1, values.length - 1);
      var points = values.map(function (v, i) {
        var x = pad + i * stepX;
        var y = h - pad - ((v - min) / Math.max(1e-9, max - min)) * (h - pad * 2);
        return [x, y];
      });
      var line = points.map(function (p, i) { return (i ? 'L' : 'M') + p[0].toFixed(1) + ' ' + p[1].toFixed(1); }).join(' ');
      var area = line + ' L' + points[points.length - 1][0].toFixed(1) + ' ' + h + ' L' + points[0][0].toFixed(1) + ' ' + h + ' Z';
      var svg = '<svg class="chart" viewBox="0 0 ' + w + ' ' + h + '" preserveAspectRatio="none" role="img" aria-label="График">';
      svg += '<path class="chart-area" d="' + area + '"/>';
      svg += '<path class="chart-line" d="' + line + '"/>';
      points.forEach(function (p, i) {
        if (values.length > 60 && i % 3) return;
        svg += '<circle class="chart-dot" cx="' + p[0].toFixed(1) + '" cy="' + p[1].toFixed(1) + '" r="2.2"><title>' +
               escapeHtml(labels[i] || '') + ': ' + (data.format ? data.format(values[i]) : values[i]) + '</title></circle>';
      });
      svg += '</svg>';
      el.innerHTML = svg;
    },

    /** Donut with legend. */
    donut: function (el, data) {
      if (!el) return;
      var items = (data.items || []).filter(function (i) { return i.value > 0; });
      var total = items.reduce(function (s, i) { return s + i.value; }, 0);
      var size = data.size || 140, stroke = data.stroke || 18, r = (size - stroke) / 2, c = size / 2;
      var circumference = 2 * Math.PI * r;
      var offset = 0;
      var svg = '<svg viewBox="0 0 ' + size + ' ' + size + '" width="' + size + '" height="' + size + '" role="img" aria-label="Круговая диаграмма">';
      svg += '<circle cx="' + c + '" cy="' + c + '" r="' + r + '" fill="none" stroke="hsl(var(--muted))" stroke-width="' + stroke + '"/>';
      items.forEach(function (item, i) {
        var share = total > 0 ? item.value / total : 0;
        var len = share * circumference;
        var color = item.color || ('hsl(var(--chart-' + ((i % 5) + 1) + '))');
        svg += '<circle class="donut-segment" cx="' + c + '" cy="' + c + '" r="' + r + '" fill="none" stroke="' + color +
               '" stroke-width="' + stroke + '" stroke-dasharray="' + len.toFixed(2) + ' ' + (circumference - len).toFixed(2) +
               '" stroke-dashoffset="' + (-offset).toFixed(2) + '" transform="rotate(-90 ' + c + ' ' + c + ')" stroke-linecap="butt">' +
               '<title>' + escapeHtml(item.label) + ': ' + (data.format ? data.format(item.value) : item.value) + '</title></circle>';
        offset += len;
      });
      if (data.center) {
        svg += '<text x="' + c + '" y="' + (c - 2) + '" text-anchor="middle" font-size="18" font-weight="650" fill="hsl(var(--foreground))">' + escapeHtml(data.center) + '</text>';
        if (data.centerSub) {
          svg += '<text x="' + c + '" y="' + (c + 15) + '" text-anchor="middle" font-size="10" fill="hsl(var(--muted-foreground))">' + escapeHtml(data.centerSub) + '</text>';
        }
      }
      svg += '</svg>';

      var legend = '<div class="legend mt-3">';
      items.forEach(function (item, i) {
        var color = item.color || ('hsl(var(--chart-' + ((i % 5) + 1) + '))');
        legend += '<span class="legend-item"><span class="legend-swatch" style="background:' + color + '"></span>' +
                  escapeHtml(item.label) + ' · <b class="tabular">' + (data.format ? data.format(item.value) : item.value) + '</b></span>';
      });
      legend += '</div>';

      el.innerHTML = '<div class="flex flex-col items-center">' + svg + legend + '</div>';
    },

    /** Horizontal progress-style ranking. */
    ranks: function (el, data) {
      if (!el) return;
      var items = data.items || [];
      var max = Math.max.apply(null, items.map(function (i) { return i.value; }).concat([1]));
      el.innerHTML = items.map(function (item, i) {
        var pct = Math.round((item.value / max) * 100);
        return '<div class="grid gap-1">' +
          '<div class="flex items-baseline justify-between gap-2 text-xs">' +
            '<span class="truncate">' + escapeHtml(item.label) + '</span>' +
            '<span class="tabular font-medium">' + (data.format ? data.format(item.value) : item.value) + '</span>' +
          '</div>' +
          '<div class="progress progress-sm"><div class="progress-bar" style="width:' + pct + '%;background:hsl(var(--chart-' + ((i % 5) + 1) + '))"></div></div>' +
        '</div>';
      }).join('<div class="h-2"></div>');
    }
  };

  /* ═══════════════════════════ command palette (Ctrl+K) ═══════════════════════════ */

  var Palette = (function () {
    var overlay = null, input = null, list = null, items = [], active = 0;

    function source() {
      var out = [];
      $$('[data-command]').forEach(function (el) {
        out.push({
          title: el.getAttribute('data-command'),
          hint: el.getAttribute('data-command-hint') || '',
          icon: el.getAttribute('data-command-icon') || 'arrow-right',
          group: el.getAttribute('data-command-group') || 'Переходы',
          action: function () {
            var href = el.getAttribute('href') || el.getAttribute('data-command-action');
            if (href) window.location.href = href;
            else el.click();
          }
        });
      });
      // Nav links become commands automatically.
      $$('.nav-item[href]').forEach(function (el) {
        if (el.hasAttribute('data-command')) return;
        out.push({
          title: el.textContent.trim().replace(/\s+/g, ' '),
          icon: (el.querySelector('use') || {}).getAttribute ? (el.querySelector('use').getAttribute('href') || '').split('#i-')[1] || 'arrow-right' : 'arrow-right',
          group: 'Разделы',
          action: function () { window.location.href = el.getAttribute('href'); }
        });
      });
      var actions = [
        { title: 'Переключить тему', hint: 'Shift+T', icon: 'moon', group: 'Действия', action: function () { window.BasIpTheme.cycle(); Toast.info('Тема: ' + window.BasIpTheme.get()); } },
        { title: 'Обновить страницу', icon: 'refresh-cw', group: 'Действия', action: function () { window.location.reload(); } },
        { title: 'Открыть справку', hint: 'Shift+?', icon: 'life-buoy', group: 'Действия', action: function () { window.location.href = url('/help'); } }
      ];
      return out.concat(actions);
    }

    function open() {
      if (overlay) return;
      overlay = doc.createElement('div');
      overlay.className = 'overlay';
      overlay.dataset.state = 'open';
      overlay.innerHTML =
        '<div class="command absolute left-1/2 top-[12vh] w-[calc(100vw-2rem)] max-w-xl -translate-x-1/2 animate-fade-in-down" role="dialog" aria-modal="true" aria-label="Командная палитра">' +
          '<div class="command-input-wrap">' + icon('search', 'h-4 w-4 text-muted-foreground') +
            '<input class="command-input" placeholder="Команда, раздел, житель, квартира…" autocomplete="off" spellcheck="false" aria-label="Поиск">' +
            '<kbd class="kbd">Esc</kbd></div>' +
          '<div class="command-list" role="listbox"></div>' +
        '</div>';
      doc.body.appendChild(overlay);
      Modal.lockScroll && Modal.lockScroll(true);
      input = overlay.querySelector('input');
      list = overlay.querySelector('.command-list');
      items = source();
      render('');
      input.focus();
      input.addEventListener('input', function () { render(input.value); });
      input.addEventListener('keydown', onKey);
      overlay.addEventListener('mousedown', function (e) { if (e.target === overlay) close(); });
    }

    function close() {
      if (!overlay) return;
      overlay.remove();
      overlay = null; input = null; list = null; items = []; active = 0;
      Modal.closeAll();
    }

    function score(item, q) {
      var title = item.title.toLowerCase();
      if (title === q) return 100;
      if (title.indexOf(q) === 0) return 80;
      var idx = title.indexOf(q);
      if (idx > 0) return 60 - Math.min(30, idx);
      // Subsequence match.
      var i = 0;
      for (var c = 0; c < q.length; c++) {
        i = title.indexOf(q[c], i);
        if (i < 0) return -1;
        i++;
      }
      return 20;
    }

    function render(query) {
      var q = (query || '').trim().toLowerCase();
      var scored = q === '' ? items.slice(0, 40) : items.map(function (i) {
        var s = score(i, q);
        return s < 0 ? null : { item: i, score: s };
      }).filter(Boolean).sort(function (a, b) { return b.score - a.score; }).slice(0, 40).map(function (x) { return x.item; });

      active = 0;
      if (!scored.length) {
        list.innerHTML = '<div class="command-empty">Ничего не найдено по запросу «' + escapeHtml(q) + '»</div>';
        return;
      }
      var html = '', lastGroup = null;
      scored.forEach(function (item, i) {
        var group = item.group || 'Команды';
        if (group !== lastGroup) {
          html += '<div class="command-group-title">' + escapeHtml(group) + '</div>';
          lastGroup = group;
        }
        html += '<div class="command-item" role="option" data-index="' + i + '" ' + (i === 0 ? 'aria-selected="true"' : '') + '>' +
                icon(item.icon || 'arrow-right', 'h-4 w-4') +
                '<span class="truncate">' + escapeHtml(item.title) + '</span>' +
                (item.hint ? '<span class="command-item-hint">' + escapeHtml(item.hint) + '</span>' : '') +
                '</div>';
      });
      list.innerHTML = html;
      list.__items = scored;
      $$('.command-item', list).forEach(function (node) {
        node.addEventListener('click', function () { run(parseInt(node.getAttribute('data-index'), 10)); });
        node.addEventListener('mousemove', function () { setActive(parseInt(node.getAttribute('data-index'), 10)); });
      });
    }

    function setActive(index) {
      var nodes = $$('.command-item', list);
      if (!nodes.length) return;
      active = Math.max(0, Math.min(nodes.length - 1, index));
      nodes.forEach(function (n, i) { n.setAttribute('aria-selected', i === active ? 'true' : 'false'); });
      nodes[active].scrollIntoView({ block: 'nearest' });
    }

    function run(index) {
      var item = (list.__items || [])[index];
      if (!item) return;
      close();
      try { item.action(); } catch (e) { Toast.error('Не удалось выполнить команду.'); }
    }

    function onKey(e) {
      if (e.key === 'ArrowDown') { e.preventDefault(); setActive(active + 1); }
      else if (e.key === 'ArrowUp') { e.preventDefault(); setActive(active - 1); }
      else if (e.key === 'Enter') { e.preventDefault(); run(active); }
      else if (e.key === 'Escape') { e.preventDefault(); close(); }
    }

    return { open: open, close: close, isOpen: function () { return !!overlay; } };
  })();

  /* ═══════════════════════════ sidebar ═══════════════════════════ */

  function setSidebar(open) {
    var sidebar = $('[data-sidebar]');
    var backdrop = $('[data-sidebar-backdrop]');
    if (!sidebar) return;
    sidebar.dataset.state = open ? 'open' : 'closed';
    if (backdrop) backdrop.dataset.state = open ? 'open' : 'closed';
    try { localStorage.setItem('basip:sidebar', open ? '1' : '0'); } catch (e) {}
    doc.body.classList.toggle('overflow-hidden', !!open && window.innerWidth < 1024);
  }

  /* ═══════════════════════════ polling ═══════════════════════════ */

  function initPolling() {
    $$('[data-poll-url]').forEach(function (el) {
      var path = el.getAttribute('data-poll-url');
      var interval = Math.max(3000, parseInt(el.getAttribute('data-poll-interval') || '30000', 10));
      var hiddenPause = el.getAttribute('data-poll-visible-only') !== 'false';

      var tick = function () {
        if (hiddenPause && doc.hidden) return;
        get(path).then(function (data) {
          el.dispatchEvent(new CustomEvent('basip:poll', { detail: data, bubbles: true }));
        }).catch(function () { /* silent — the panel shows its own error state */ });
      };
      tick();
      setInterval(tick, interval);
    });
  }

  /* ═══════════════════════════ boot ═══════════════════════════ */

  function bindGlobal() {
    // Dialogs
    on(doc, 'click', '[data-dialog]', function (e, el) {
      e.preventDefault();
      Modal.open(el.getAttribute('data-dialog'));
    });
    on(doc, 'click', '[data-close]', function (e, el) {
      e.preventDefault();
      var scope = el.closest('.dialog, .sheet');
      if (scope) {
        var entry = Modal.stack.filter(function (s) { return s.target === scope; })[0];
        Modal.close(entry);
      } else closeAllDropdowns();
    });

    // Dropdowns
    on(doc, 'click', '[data-dropdown]', function (e, el) {
      e.preventDefault();
      e.stopPropagation();
      toggleDropdown(el);
    });
    doc.addEventListener('click', function (e) {
      if (!e.target.closest('[data-dropdown], [data-dropdown-panel]')) closeAllDropdowns();
    });

    // Tabs
    on(doc, 'click', '[data-tab]', function (e, el) {
      e.preventDefault();
      activateTab(el);
    });
    on(doc, 'keydown', '[data-tab]', function (e, el) {
      if (e.key !== 'ArrowRight' && e.key !== 'ArrowLeft') return;
      var siblings = $$('[data-tab]', el.closest('[data-tabs]'));
      var i = siblings.indexOf(el);
      var next = siblings[(i + (e.key === 'ArrowRight' ? 1 : siblings.length - 1)) % siblings.length];
      if (next) { next.focus(); activateTab(next); }
    });

    // Toasts
    on(doc, 'click', '[data-toast]', function (e, el) {
      Toast.info(el.getAttribute('data-toast'), { type: el.getAttribute('data-toast-type') || 'info' });
    });

    // Copy
    on(doc, 'click', '[data-copy]', function (e, el) {
      e.preventDefault();
      var value = el.getAttribute('data-copy');
      if (value && value.charAt(0) === '#') {
        var node = $(value);
        value = node ? (node.value || node.textContent || '').trim() : value;
      }
      copy(value, el.getAttribute('data-copy-message'));
    });

    // Tooltips
    on(doc, 'mouseover', '[data-tooltip]', function (e, el) { Tooltip.show(el); });
    on(doc, 'mouseout', '[data-tooltip]', function () { Tooltip.hide(); });
    on(doc, 'focusin', '[data-tooltip]', function (e, el) { Tooltip.show(el); });
    on(doc, 'focusout', '[data-tooltip]', function () { Tooltip.hide(); });

    // Confirms
    on(doc, 'click', '[data-confirm]', function (e, el) {
      if (el.dataset.confirmed) { delete el.dataset.confirmed; return; }
      e.preventDefault();
      e.stopPropagation();
      confirmDialog({
        title: el.getAttribute('data-confirm-title') || 'Подтвердите действие',
        message: el.getAttribute('data-confirm'),
        tone: el.getAttribute('data-confirm-tone') || 'default',
        confirmLabel: el.getAttribute('data-confirm-label') || 'Подтвердить',
        requireText: el.getAttribute('data-confirm-text') || null,
        details: el.getAttribute('data-confirm-details') || null
      }).then(function (ok) {
        if (!ok) return;
        el.dataset.confirmed = '1';
        if (el.form && el.getAttribute('type') === 'submit') { el.form.requestSubmit ? el.form.requestSubmit(el) : el.form.submit(); }
        else el.click();
      });
    }, true);

    // AJAX forms
    on(doc, 'submit', 'form[data-ajax]', function (e, form) {
      e.preventDefault();
      submitForm(form).catch(function () {});
    });

    // Sidebar
    on(doc, 'click', '[data-sidebar-toggle]', function (e) {
      e.preventDefault();
      var sidebar = $('[data-sidebar]');
      setSidebar(!(sidebar && sidebar.dataset.state === 'open'));
    });
    on(doc, 'click', '[data-sidebar-backdrop]', function () { setSidebar(false); });

    // Command palette
    on(doc, 'click', '[data-command-palette]', function (e) { e.preventDefault(); Palette.open(); });

    // QR rendering
    $$('[data-qr]').forEach(function (el) {
      renderQr(el, el.getAttribute('data-qr'), parseInt(el.getAttribute('data-qr-size') || '180', 10));
    });

    // Theme switcher
    on(doc, 'click', '[data-theme-toggle]', function (e, el) {
      e.preventDefault();
      var mode = el.getAttribute('data-theme-toggle');
      var next = mode === 'cycle' ? window.BasIpTheme.cycle() : window.BasIpTheme.set(mode);
      Toast.info('Тема: ' + ({ light: 'светлая', dark: 'тёмная', system: 'как в системе' }[next] || next), { duration: 1800 });
    });

    // Auto-submit filters
    $$('[data-auto-submit]').forEach(function (el) {
      var form = el.closest('form');
      if (!form) return;
      el.addEventListener('change', function () { form.submit(); });
    });

    // Auto-growing textareas
    $$('textarea[data-autosize]').forEach(function (el) {
      var resize = function () { el.style.height = 'auto'; el.style.height = (el.scrollHeight + 2) + 'px'; };
      el.addEventListener('input', resize);
      resize();
    });

    // Number inputs with a live "per person" hint
    $$('[data-split-total]').forEach(function (input) {
      var out = $(input.getAttribute('data-split-total'));
      var count = parseInt(input.getAttribute('data-split-count') || '1', 10);
      var update = function () {
        if (!out) return;
        var total = parseFloat(String(input.value).replace(',', '.')) || 0;
        var per = count > 0 ? total / count : 0;
        out.textContent = (window.__BASIP && window.__BASIP.money ? window.__BASIP.money(per) : per.toFixed(2));
      };
      input.addEventListener('input', debounce(update, 120));
      update();
    });
  }

  function bindShortcuts() {
    doc.addEventListener('keydown', function (e) {
      var typing = /^(INPUT|TEXTAREA|SELECT)$/.test(doc.activeElement && doc.activeElement.tagName) || (doc.activeElement && doc.activeElement.isContentEditable);

      if ((e.ctrlKey || e.metaKey) && (e.key === 'k' || e.key === 'K' || e.key === 'л' || e.key === 'Л')) {
        e.preventDefault();
        Palette.isOpen() ? Palette.close() : Palette.open();
        return;
      }
      if (e.key === 'Escape') {
        if (Palette.isOpen()) { Palette.close(); return; }
        if (Modal.isOpen()) { Modal.closeTop(); return; }
        closeAllDropdowns();
        return;
      }
      if (typing || e.ctrlKey || e.metaKey || e.altKey) return;

      if (e.key === '/') {
        var search = $('[data-global-search]');
        if (search) { e.preventDefault(); search.focus(); search.select(); }
        return;
      }
      if (e.shiftKey) {
        var map = {
          'O': '[data-shortcut="open"]',
          'G': '[data-shortcut="guest"]',
          'T': '[data-theme-toggle="cycle"]',
          '?': '[data-shortcut="help"]'
        };
        var sel = map[e.key];
        if (sel) {
          var target = $(sel);
          if (target) { e.preventDefault(); target.click(); }
          else if (e.key === 'T') { e.preventDefault(); window.BasIpTheme.cycle(); }
        }
      }
      if (/^[a-zа-я]$/i.test(e.key) && doc.body.dataset.gSequence === 'g') {
        var jumps = { d: '/admin', r: '/admin/residents', g: '/admin/gate', b: '/admin/billing', u: '/admin/users', s: '/admin/settings', n: '/admin/notifications', a: '/admin/audit' };
        var target2 = jumps[e.key.toLowerCase()];
        delete doc.body.dataset.gSequence;
        if (target2) { e.preventDefault(); window.location.href = url(target2); }
        return;
      }
      if (e.key === 'g') {
        doc.body.dataset.gSequence = 'g';
        setTimeout(function () { delete doc.body.dataset.gSequence; }, 1200);
      }
    });
  }

  function flashFromServer() {
    var node = $('#basip-flash');
    if (!node) return;
    try {
      var payload = JSON.parse(node.textContent || '[]');
      payload.forEach(function (item) {
        Toast.show(item.message, { type: item.type === 'error' ? 'destructive' : (item.type || 'info'), title: item.title, duration: item.type === 'error' ? 9000 : 5000 });
      });
    } catch (e) {}
  }

  function init() {
    bindGlobal();
    bindShortcuts();
    $$('[data-table]').forEach(initTable);
    initCountdowns();
    initRelativeTime();
    initPolling();
    restoreTabs();
    flashFromServer();

    // Sidebar state persistence (desktop collapse is CSS-only, mobile uses data-state).
    if (window.innerWidth < 1024) setSidebar(false);

    // Warn about unsaved changes in long forms.
    $$('form[data-dirty-guard]').forEach(function (form) {
      var dirty = false;
      form.addEventListener('input', function () { dirty = true; });
      form.addEventListener('submit', function () { dirty = false; });
      window.addEventListener('beforeunload', function (e) {
        if (!dirty) return;
        e.preventDefault();
        e.returnValue = '';
      });
    });

    doc.dispatchEvent(new CustomEvent('basip:ready'));
  }

  /* ═══════════════════════════ public API ═══════════════════════════ */

  window.BasIp = {
    version: (window.__BASIP && window.__BASIP.version) || '2.0.0',
    $: $, $$: $$,
    toast: Toast,
    dialog: Modal,
    confirm: confirmDialog,
    dropdown: { close: closeAllDropdowns, toggle: toggleDropdown },
    tabs: { activate: activateTab },
    copy: copy,
    post: post,
    get: get,
    fetchJson: get,
    submitForm: submitForm,
    busy: busy,
    debounce: debounce,
    escapeHtml: escapeHtml,
    icon: icon,
    iconHref: iconHref,
    qr: renderQr,
    chart: Chart,
    palette: Palette,
    sidebar: { set: setSidebar },
    plural: plural,
    money: function (value, symbol) {
      var n = Number(value || 0);
      var s = symbol || (window.__BASIP && window.__BASIP.currency) || '₽';
      var fixed = n.toFixed(2);
      var parts = fixed.split('.');
      var grouped = parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, '\u00A0');
      var tail = parts[1] === '00' ? '' : ',' + parts[1];
      return grouped + tail + '\u00A0' + s;
    },
    url: url,
    csrf: csrfToken,
    theme: window.BasIpTheme
  };

  if (doc.readyState === 'loading') doc.addEventListener('DOMContentLoaded', init);
  else init();
})();
