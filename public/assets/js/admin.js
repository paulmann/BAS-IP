/**
 * BAS-IP · admin runtime
 * ─────────────────────────────────────────────────────────────────────────────
 * Progressive enhancement for the back office:
 *   • residents table  — inline toggles, quick gate open, bulk actions
 *   • resident form    — repeatable contact / car rows with validation hints
 *   • charge wizard    — live "total ÷ active residents" preview, step nav
 *   • data import      — upload → column mapping → dry-run → apply
 *   • settings         — channel self-tests, secret storage
 *   • charts           — render from JSON payloads embedded in the markup
 *
 * Requires app.js (window.BasIp).
 */
(function () {
  'use strict';

  var B = window.BasIp;
  if (!B) return;
  var doc = document;
  var $ = B.$, $$ = B.$$;

  /* ══════════════════════════ residents table ══════════════════════════ */

  function initResidentsTable() {
    var table = $('[data-residents-table]');
    if (!table) return;

    // Inline switches (active / access / billed) — optimistic update + rollback.
    table.addEventListener('change', function (e) {
      var input = e.target.closest('[data-toggle-url]');
      if (!input) return;

      var field = input.getAttribute('data-toggle-field');
      var url = input.getAttribute('data-toggle-url');
      var row = input.closest('tr');
      var previous = !input.checked;

      input.disabled = true;
      B.post(url, { field: field, value: input.checked ? 1 : 0 })
        .then(function (data) {
          input.disabled = false;
          B.toast.success(data.message || 'Сохранено', { duration: 2200 });
          if (row) {
            row.classList.toggle('is-inactive', field === 'is_active' && !input.checked);
            row.classList.toggle('opacity-60', field === 'access_granted' && !input.checked);
          }
          refreshCounters();
          if (field === 'is_active' && table.getAttribute('data-status') === 'active' && !input.checked) {
            B.toast.info('Запись скрыта: выбран фильтр «Активные».', {
              duration: 5000,
              actionLabel: 'Показать все',
              onAction: function () {
                var link = table.getAttribute('data-all-url');
                if (link) window.location.href = link;
              }
            });
          }
        })
        .catch(function (err) {
          input.disabled = false;
          input.checked = previous;
          B.toast.error((err && err.message) || 'Не удалось сохранить');
        });
    });

    // Quick «открыть» per row.
    table.addEventListener('click', function (e) {
      var btn = e.target.closest('[data-quick-open]');
      if (!btn) return;
      e.preventDefault();

      var url = btn.getAttribute('data-quick-open');
      var name = btn.getAttribute('data-quick-open-name') || '';
      B.busy(btn, true);
      B.post(url, {}).then(function (data) {
        B.busy(btn, false);
        B.toast.success((data.message || 'Шлагбаум открыт') + (name ? ' · ' + name : ''));
      }).catch(function (err) {
        B.busy(btn, false);
        B.toast.error((err && err.message) || 'Отказ');
      });
    });

    // Bulk action bar.
    var bar = $('[data-bulk-bar]');
    if (bar) {
      bar.addEventListener('click', function (e) {
        var btn = e.target.closest('[data-bulk-action]');
        if (!btn) return;
        e.preventDefault();

        var action = btn.getAttribute('data-bulk-action');
        var ids = selectedIds();
        if (!ids.length) { B.toast.warning('Выберите хотя бы одну запись.'); return; }

        var needsInput = { tag: 'tag', note: 'note' };
        var destructive = ['delete', 'deactivate', 'revoke_access', 'unbill'].indexOf(action) >= 0;

        var run = function (extra) {
          var form = $('[data-bulk-form]');
          if (!form) return;
          $('[name="action"]', form).value = action;
          $$('input[name="ids[]"]', form).forEach(function (n) { n.remove(); });
          ids.forEach(function (id) {
            var input = doc.createElement('input');
            input.type = 'hidden'; input.name = 'ids[]'; input.value = id;
            form.appendChild(input);
          });
          Object.keys(extra || {}).forEach(function (key) {
            var input = form.querySelector('[name="' + key + '"]');
            if (!input) {
              input = doc.createElement('input');
              input.type = 'hidden'; input.name = key;
              form.appendChild(input);
            }
            input.value = extra[key];
          });
          B.busy(btn, true);
          form.submit();
        };

        if (needsInput[action]) {
          promptBulk(action, ids.length).then(function (value) {
            if (value === null) return;
            var payload = {};
            payload[needsInput[action]] = value;
            if (action === 'tag') payload.tag_mode = $('[data-bulk-tag-mode]') ? $('[data-bulk-tag-mode]').value : 'add';
            run(payload);
          });
          return;
        }

        if (destructive) {
          B.confirm({
            title: labels[action].title,
            message: labels[action].message.replace('{n}', ids.length),
            tone: action === 'delete' ? 'destructive' : 'default',
            confirmLabel: labels[action].confirm
          }).then(function (ok) { if (ok) run({ only_active: $('[data-bulk-only-active]') && $('[data-bulk-only-active]').checked ? 1 : 0 }); });
          return;
        }

        run({ only_active: $('[data-bulk-only-active]') && $('[data-bulk-only-active]').checked ? 1 : 0 });
      });
    }

    var labels = {
      activate: { title: 'Включить жителей', message: 'Включить выбранные записи ({n})?', confirm: 'Включить' },
      deactivate: { title: 'Отключить жителей', message: 'Отключить выбранные записи ({n})? Они перестанут участвовать в расчётах и рассылках, а их гостевые доступы будут отозваны.', confirm: 'Отключить' },
      grant_access: { title: 'Разрешить доступ', message: 'Разрешить доступ к шлагбауму для {n} записей?', confirm: 'Разрешить' },
      revoke_access: { title: 'Приостановить доступ', message: 'Приостановить доступ к шлагбауму для {n} записей?', confirm: 'Приостановить', },
      bill: { title: 'Включить в оплату', message: 'Включить {n} записей в расчёт оплаты?', confirm: 'Включить' },
      unbill: { title: 'Исключить из оплаты', message: 'Исключить {n} записей из расчёта оплаты?', confirm: 'Исключить' },
      delete: { title: 'Удалить записи', message: 'Переместить {n} записей в архив? Их можно восстановить из фильтра «Удалённые».', confirm: 'Удалить' },
      recalc_balance: { title: 'Пересчитать балансы', message: 'Пересчитать баланс для {n} записей?', confirm: 'Пересчитать' }
    };

    function selectedIds() {
      return $$('[data-row-select]:checked', table).map(function (cb) { return cb.value; }).filter(Boolean);
    }

    function refreshCounters() {
      var url = table.getAttribute('data-counters-url');
      if (!url) return;
      B.get(url).then(function (data) {
        Object.keys(data.counts || {}).forEach(function (key) {
          var el = $('[data-count="' + key + '"]');
          if (el) el.textContent = String(data.counts[key]);
        });
      }).catch(function () {});
    }
  }

  function promptBulk(action, count) {
    return new Promise(function (resolve) {
      var isTag = action === 'tag';
      var id = 'basip-bulk-' + Date.now();
      var wrap = doc.createElement('div');
      wrap.className = 'dialog dialog-sm';
      wrap.dataset.state = 'closed';
      wrap.setAttribute('hidden', '');
      wrap.innerHTML =
        '<div class="dialog-header"><h2 class="dialog-title">' + (isTag ? 'Метка' : 'Комментарий') + '</h2>' +
        '<p class="dialog-desc">Будет применено к ' + count + ' ' + B.plural(count, 'записи', 'записям', 'записям') + '.</p></div>' +
        '<div class="field">' +
          (isTag
            ? '<input class="input" id="' + id + '" list="' + id + '-list" placeholder="Например: староста, должник, аренда" autocomplete="off">' +
              '<datalist id="' + id + '-list"></datalist>' +
              '<label class="checkbox-row mt-2"><input type="checkbox" class="checkbox" id="' + id + '-mode"> <span class="text-sm">Удалить метку вместо добавления</span></label>'
            : '<textarea class="textarea" id="' + id + '" placeholder="Текст будет добавлен к существующему комментарию"></textarea>') +
        '</div>' +
        '<div class="dialog-footer">' +
          '<button type="button" class="btn btn-outline" data-close>Отмена</button>' +
          '<button type="button" class="btn btn-default" data-ok>Применить</button>' +
        '</div>';
      doc.body.appendChild(wrap);
      var entry = B.dialog.open(wrap);
      var input = wrap.querySelector('#' + id);
      input.focus();

      if (isTag) {
        var known = $('[data-known-tags]');
        if (known && known.value) {
          var dl = wrap.querySelector('datalist');
          known.value.split(',').forEach(function (tag) {
            var opt = doc.createElement('option');
            opt.value = tag.trim();
            dl.appendChild(opt);
          });
        }
      }

      function finish(value, mode) {
        B.dialog.close(entry);
        setTimeout(function () { wrap.remove(); }, 200);
        if (value === null) { resolve(null); return; }
        if (isTag && mode) {
          var el = $('[data-bulk-tag-mode]');
          if (!el) { el = doc.createElement('input'); el.type = 'hidden'; el.name = 'tag_mode'; doc.body.appendChild(el); }
          el.value = mode;
        }
        resolve(value);
      }

      wrap.querySelector('[data-ok]').addEventListener('click', function () {
        var value = input.value.trim();
        if (!value) { B.toast.warning('Введите значение.'); return; }
        var mode = isTag && wrap.querySelector('#' + id + '-mode').checked ? 'remove' : 'add';
        finish(value, mode);
      });
      wrap.querySelector('[data-close]').addEventListener('click', function () { finish(null); });
      input.addEventListener('keydown', function (e) { if (e.key === 'Enter' && !isTag) { e.preventDefault(); wrap.querySelector('[data-ok]').click(); } });
    });
  }

  /* ══════════════════════════ resident form ══════════════════════════ */

  function initResidentForm() {
    var form = $('[data-resident-form]');
    if (!form) return;

    var contacts = $('[data-contacts]', form);
    var cars = $('[data-cars]', form);

    // ── ФИО: авто-сборка полного имени из частей ──────────────────────
    var parts = ['last_name', 'first_name', 'patronymic'].map(function (n) { return $('[name="' + n + '"]', form); });
    var fullName = $('[name="full_name"]', form);
    var autoName = true;
    if (fullName) {
      fullName.addEventListener('input', function () { autoName = false; });
      parts.forEach(function (input) {
        if (!input) return;
        input.addEventListener('input', function () {
          if (!autoName || !fullName) return;
          fullName.value = parts.filter(Boolean).map(function (i) { return i.value.trim(); }).filter(Boolean).join(' ');
        });
      });
    }

    // ── Квартира: выделение числовой части для сортировки ─────────────
    var apartment = $('[name="apartment"]', form);
    var apartmentNo = $('[name="apartment_no"]', form);
    if (apartment && apartmentNo) {
      apartment.addEventListener('input', function () {
        var match = String(apartment.value).match(/\d+/);
        if (match && !apartmentNo.dataset.touched) apartmentNo.value = match[0];
      });
      apartmentNo.addEventListener('input', function () { apartmentNo.dataset.touched = '1'; });
    }

    // ── Телефон: нормализация при потере фокуса ───────────────────────
    form.addEventListener('focusout', function (e) {
      var input = e.target;
      if (!input.matches || !input.matches('[data-phone]')) return;
      var digits = String(input.value).replace(/\D/g, '');
      if (!digits) return;
      if (digits.length === 11 && digits[0] === '8') digits = '7' + digits.slice(1);
      if (digits.length === 10) digits = '7' + digits;
      input.value = '+' + digits;
    });

    // ── Повторяющиеся строки: контакты ────────────────────────────────
    if (contacts) {
      contacts.addEventListener('click', function (e) {
        var add = e.target.closest('[data-contact-add]');
        if (add) { e.preventDefault(); addContactRow(contacts, {}); return; }
        var del = e.target.closest('[data-contact-remove]');
        if (del) {
          e.preventDefault();
          var row = del.closest('[data-contact-row]');
          if (row) row.remove();
          reindex(contacts, 'contacts');
        }
        var primary = e.target.closest('[data-contact-primary]');
        if (primary) {
          var type = primary.getAttribute('data-contact-primary');
          $$('[data-contact-primary="' + type + '"]', contacts).forEach(function (el) {
            if (el !== primary) el.checked = false;
          });
        }
      });
      reindex(contacts, 'contacts');
    }

    if (cars) {
      cars.addEventListener('click', function (e) {
        var add = e.target.closest('[data-car-add]');
        if (add) { e.preventDefault(); addCarRow(cars, {}); return; }
        var del = e.target.closest('[data-car-remove]');
        if (del) {
          e.preventDefault();
          var row = del.closest('[data-car-row]');
          if (row) row.remove();
          reindex(cars, 'cars');
        }
      });
      reindex(cars, 'cars');
    }

    // ── Метки: chip-ввод ──────────────────────────────────────────────
    initTagInput(form);

    // ── Подтверждение при отключении жителя ───────────────────────────
    var activeToggle = $('[name="is_active"]', form);
    if (activeToggle) {
      activeToggle.addEventListener('change', function () {
        var hint = $('[data-active-hint]', form);
        if (!hint) return;
        hint.hidden = activeToggle.checked;
      });
    }
  }

  function reindex(container, prefix) {
    $$('[data-' + (prefix === 'contacts' ? 'contact' : 'car') + '-row]', container).forEach(function (row, i) {
      $$('[name]', row).forEach(function (input) {
        input.name = input.name.replace(new RegExp('^' + prefix + '\\[\\d+\\]'), prefix + '[' + i + ']');
      });
    });
  }

  function addContactRow(container, data) {
    var index = $$('[data-contact-row]', container).length;
    var types = (container.getAttribute('data-contact-types') || 'phone,email,telegram,whatsapp,viber,sip,other').split(',');
    var row = doc.createElement('div');
    row.setAttribute('data-contact-row', '');
    row.className = 'grid gap-2 rounded-lg border bg-card/60 p-3 sm:grid-cols-[7.5rem_1fr_7.5rem_auto] sm:items-center animate-fade-in-up';
    row.innerHTML =
      '<select class="select select-sm" name="contacts[' + index + '][type]">' +
        types.map(function (t) { return '<option value="' + t + '"' + (data.type === t ? ' selected' : '') + '>' + contactLabel(t) + '</option>'; }).join('') +
      '</select>' +
      '<input class="input input-sm" name="contacts[' + index + '][value]" placeholder="Значение" value="' + B.escapeHtml(data.value || '') + '" ' +
        (data.type === 'phone' ? 'data-phone inputmode="tel"' : '') + ' required>' +
      '<input class="input input-sm" name="contacts[' + index + '][label]" placeholder="Метка" value="' + B.escapeHtml(data.label || '') + '">' +
      '<div class="flex items-center gap-3">' +
        '<label class="inline-flex items-center gap-1.5 text-xs text-muted-foreground">' +
          '<input type="checkbox" class="checkbox" name="contacts[' + index + '][is_primary]" value="1" data-contact-primary="' + (data.type || 'phone') + '"' + (data.is_primary ? ' checked' : '') + '> осн.' +
        '</label>' +
        '<label class="inline-flex items-center gap-1.5 text-xs text-muted-foreground">' +
          '<input type="checkbox" class="checkbox" name="contacts[' + index + '][notify]" value="1"' + (data.notify === false ? '' : ' checked') + '> уведомлять' +
        '</label>' +
        '<button type="button" class="btn btn-sm btn-ghost btn-icon" data-contact-remove aria-label="Удалить контакт">' + B.icon('trash') + '</button>' +
      '</div>';
    var list = $('[data-contact-list]', container) || container;
    list.appendChild(row);
    var value = row.querySelector('input[name$="[value]"]');
    if (value) value.focus();
  }

  function contactLabel(type) {
    return {
      phone: 'Телефон', email: 'E-mail', telegram: 'Telegram', whatsapp: 'WhatsApp',
      viber: 'Viber', sip: 'SIP', other: 'Другое'
    }[type] || type;
  }

  function addCarRow(container, data) {
    var index = $$('[data-car-row]', container).length;
    var row = doc.createElement('div');
    row.setAttribute('data-car-row', '');
    row.className = 'grid gap-2 rounded-lg border bg-card/60 p-3 sm:grid-cols-[8rem_7rem_7rem_5rem_auto] sm:items-center animate-fade-in-up';
    row.innerHTML =
      '<input class="input input-sm input-mono" name="cars[' + index + '][plate]" placeholder="А123ВС77" maxlength="16" value="' + B.escapeHtml(data.plate || '') + '" data-plate>' +
      '<input class="input input-sm" name="cars[' + index + '][brand]" placeholder="Марка" value="' + B.escapeHtml(data.brand || '') + '">' +
      '<input class="input input-sm" name="cars[' + index + '][model]" placeholder="Модель" value="' + B.escapeHtml(data.model || '') + '">' +
      '<input class="input input-sm" name="cars[' + index + '][year]" placeholder="Год" inputmode="numeric" maxlength="4" value="' + B.escapeHtml(data.year || '') + '">' +
      '<div class="flex items-center gap-3">' +
        '<label class="inline-flex items-center gap-1.5 text-xs text-muted-foreground">' +
          '<input type="checkbox" class="checkbox" name="cars[' + index + '][is_active]" value="1"' + (data.is_active === false ? '' : ' checked') + '> активен' +
        '</label>' +
        '<button type="button" class="btn btn-sm btn-ghost btn-icon" data-car-remove aria-label="Удалить автомобиль">' + B.icon('trash') + '</button>' +
      '</div>' +
      '<input type="hidden" name="cars[' + index + '][color]" value="' + B.escapeHtml(data.color || '') + '">' +
      '<input type="hidden" name="cars[' + index + '][type]" value="' + B.escapeHtml(data.type || 'car') + '">' +
      '<input type="hidden" name="cars[' + index + '][rfid_tag]" value="' + B.escapeHtml(data.rfid_tag || '') + '">' +
      '<input type="hidden" name="cars[' + index + '][notes]" value="' + B.escapeHtml(data.notes || '') + '">';
    var list = $('[data-car-list]', container) || container;
    list.appendChild(row);
    var plate = row.querySelector('[data-plate]');
    if (plate) {
      plate.addEventListener('input', function () { plate.value = plate.value.toUpperCase().replace(/[^А-ЯЁA-Z0-9]/gi, '').slice(0, 12); });
      plate.focus();
    }
  }

  function initTagInput(form) {
    var wrap = $('[data-tag-input]', form);
    if (!wrap) return;
    var input = $('input[data-tag-field]', wrap);
    var hidden = $('input[type="hidden"][name="tags"]', form);
    if (!input) return;

    var tags = [];
    try { tags = JSON.parse(wrap.getAttribute('data-tag-values') || '[]'); } catch (e) { tags = []; }

    function render() {
      var chips = $('[data-tag-chips]', wrap);
      if (!chips) return;
      chips.innerHTML = tags.map(function (tag, i) {
        return '<span class="token">' + B.escapeHtml(tag) +
               '<button type="button" class="token-remove" data-tag-remove="' + i + '" aria-label="Удалить метку">' + B.icon('x', 'h-3 w-3') + '</button></span>';
      }).join('');
      if (hidden) hidden.value = JSON.stringify(tags);
    }

    function add(value) {
      value = String(value || '').trim();
      if (!value) return;
      value.split(/[,;]/).forEach(function (part) {
        part = part.trim();
        if (part && tags.indexOf(part) < 0) tags.push(part);
      });
      input.value = '';
      render();
    }

    input.addEventListener('keydown', function (e) {
      if (e.key === 'Enter' || e.key === ',') { e.preventDefault(); add(input.value); }
      else if (e.key === 'Backspace' && !input.value && tags.length) { tags.pop(); render(); }
    });
    input.addEventListener('blur', function () { add(input.value); });
    wrap.addEventListener('click', function (e) {
      var btn = e.target.closest('[data-tag-remove]');
      if (!btn) return;
      tags.splice(parseInt(btn.getAttribute('data-tag-remove'), 10), 1);
      render();
    });
    render();
  }

  /* ══════════════════════════ charge wizard ══════════════════════════ */

  function initChargeWizard() {
    var form = $('[data-charge-wizard]');
    if (!form) return;

    var previewUrl = form.getAttribute('data-charge-preview');
    var total = $('[name="total_amount"]', form);
    var allocation = $('[name="allocation"]', form);
    var summary = $('[data-charge-summary]');
    var rowsWrap = $('[data-charge-rows]');
    var message = $('[name="message"]', form);
    var template = $('[data-message-template]');
    var loading = $('[data-charge-loading]');

    var lastTotal = null;

    function refresh() {
      var value = parseFloat(String(total.value).replace(',', '.'));
      if (!isFinite(value) || value <= 0) {
        if (summary) summary.innerHTML = '<div class="text-sm text-muted-foreground">Введите общую сумму — расчёт появится здесь.</div>';
        if (rowsWrap) rowsWrap.innerHTML = '';
        return;
      }
      if (loading) loading.hidden = false;

      var body = new URLSearchParams();
      body.set('total_amount', String(value));
      body.set('allocation', allocation ? allocation.value : 'equal');

      fetch(B.url(previewUrl), {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'Accept': 'application/json', 'X-CSRF-Token': B.csrf(), 'X-Requested-With': 'XMLHttpRequest' },
        body: body.toString()
      }).then(function (r) { return r.json(); }).then(function (data) {
        if (loading) loading.hidden = true;
        if (!data || data.ok === false) {
          if (summary) summary.innerHTML = '<div class="text-sm text-destructive">' + B.escapeHtml((data && data.error) || 'Ошибка расчёта') + '</div>';
          return;
        }
        renderSummary(data);
        renderRows(data);
        lastTotal = value;
      }).catch(function (err) {
        if (loading) loading.hidden = true;
        if (summary) summary.innerHTML = '<div class="text-sm text-destructive">' + B.escapeHtml(err.message) + '</div>';
      });
    }

    function renderSummary(data) {
      if (!summary) return;
      summary.innerHTML =
        '<div class="grid gap-3 sm:grid-cols-3">' +
          tile('Общая сумма', B.money(data.total), 'wallet') +
          tile('Активных участников', String(data.participants), 'users') +
          tile('На каждого', B.money(data.per_unit), 'calculator', 'accent-success') +
        '</div>' +
        '<p class="mt-3 text-xs text-muted-foreground">' + B.escapeHtml(data.message || '') +
        (Math.abs(data.rounding_delta) > 0.004 ? ' · Расхождение округления: ' + B.money(data.rounding_delta) : '') + '</p>';

      function tile(label, value, iconName, accent) {
        return '<div class="stat ' + (accent || '') + '">' +
          '<div class="stat-label">' + B.icon(iconName) + label + '</div>' +
          '<div class="stat-value">' + B.escapeHtml(value) + '</div></div>';
      }
    }

    function renderRows(data) {
      if (!rowsWrap) return;
      if (!data.rows || !data.rows.length) {
        rowsWrap.innerHTML = '<div class="empty"><div class="empty-icon">' + B.icon('users') + '</div>' +
          '<div class="empty-title">Нет активных жителей</div>' +
          '<div class="empty-desc">Включите жителей в списке — только активные участвуют в расчёте.</div></div>';
        return;
      }
      var body = data.rows.map(function (row) {
        return '<tr>' +
          '<td class="tabular text-muted-foreground">' + B.escapeHtml(row.apartment || '—') + '</td>' +
          '<td><div class="font-medium">' + B.escapeHtml(row.name) + '</div>' +
            (row.building ? '<div class="text-xs text-muted-foreground">корп. ' + B.escapeHtml(row.building) + '</div>' : '') + '</td>' +
          '<td class="table-numeric">' + (row.weight !== 1 ? row.weight + ' (' + row.share_pct + '%)' : row.share_pct + '%') + '</td>' +
          '<td class="table-numeric font-semibold tabular">' + B.money(row.amount) + '</td>' +
          '<td class="text-center">' + (row.has_contact
            ? '<span class="badge badge-soft-success">есть</span>'
            : '<span class="badge badge-soft-destructive" data-tooltip="У жителя нет ни телефона, ни почты — уведомление не дойдёт">нет</span>') + '</td>' +
        '</tr>';
      }).join('');

      rowsWrap.innerHTML =
        '<div class="table-wrap"><table class="table"><thead><tr>' +
        '<th>Кв.</th><th>Житель</th><th class="text-right">Доля</th><th class="text-right">К оплате</th><th class="text-center">Контакты</th>' +
        '</tr></thead><tbody>' + body + '</tbody>' +
        '<tfoot><tr><td colspan="3" class="text-right font-medium">Итого</td>' +
        '<td class="table-numeric font-semibold">' + B.money(data.sum) + '</td><td></td></tr></tfoot></table></div>';
    }

    if (total) total.addEventListener('input', B.debounce(refresh, 350));
    if (allocation) allocation.addEventListener('change', refresh);

    // Подстановка шаблона сообщения.
    if (template && message) {
      template.addEventListener('change', function () {
        var name = template.value;
        if (!name) return;
        var tpl = (window.__BASIP && window.__BASIP.templates && window.__BASIP.templates[name]) || null;
        if (!tpl) return;
        if (message.value.trim() === '' || message.dataset.touched !== '1') {
          message.value = tpl.body || '';
          var subject = $('[name="subject"]', form);
          if (subject && !subject.value) subject.value = tpl.subject || '';
        }
      });
      message.addEventListener('input', function () { message.dataset.touched = '1'; });
    }

    // Подсказки по плейсхолдерам — вставка по клику.
    $$('[data-placeholder-insert]').forEach(function (btn) {
      btn.addEventListener('click', function (e) {
        e.preventDefault();
        if (!message) return;
        var token = btn.getAttribute('data-placeholder-insert');
        var pos = message.selectionStart || message.value.length;
        message.value = message.value.slice(0, pos) + token + message.value.slice(pos);
        message.focus();
        message.setSelectionRange(pos + token.length, pos + token.length);
        message.dataset.touched = '1';
      });
    });

    // Шаги мастера.
    $$('[data-wizard-step]').forEach(function (btn) {
      btn.addEventListener('click', function (e) {
        e.preventDefault();
        var target = btn.getAttribute('data-wizard-step');
        $$('[data-wizard-panel]').forEach(function (p) { p.hidden = p.getAttribute('data-wizard-panel') !== target; });
        $$('[data-wizard-step]').forEach(function (b) {
          var active = b === btn;
          b.classList.toggle('is-active', active);
          b.classList.toggle('is-done', !active);
        });
        if (target === 'review') refresh();
      });
    });

    if (total && total.value) refresh();
    void lastTotal;
  }

  /* ══════════════════════════ recalculation ══════════════════════════ */

  function initRecalc() {
    var form = $('[data-recalc-form]');
    if (!form) return;

    var previewBtn = $('[data-recalc-preview]');
    var out = $('[data-recalc-result]');

    if (!previewBtn || !out) return;

    previewBtn.addEventListener('click', function (e) {
      e.preventDefault();
      B.busy(previewBtn, true, 'Считаем…');

      var body = new URLSearchParams(new FormData(form));
      body.set('dry_run', '1');

      fetch(B.url(form.getAttribute('action')), {
        method: 'POST', credentials: 'same-origin',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'Accept': 'application/json', 'X-CSRF-Token': B.csrf(), 'X-Requested-With': 'XMLHttpRequest' },
        body: body.toString()
      }).then(function (r) { return r.json(); }).then(function (data) {
        B.busy(previewBtn, false);
        if (!data || data.ok === false) {
          out.innerHTML = '<div class="alert alert-destructive">' + B.icon('alert-circle') + '<div><div class="alert-title">Ошибка</div>' +
            '<div class="alert-desc">' + B.escapeHtml((data && data.error) || 'Не удалось выполнить перерасчёт') + '</div></div></div>';
          return;
        }
        renderRecalc(data);
      }).catch(function (err) {
        B.busy(previewBtn, false);
        out.innerHTML = '<div class="alert alert-destructive">' + B.escapeHtml(err.message) + '</div>';
      });
    });

    function renderRecalc(data) {
      var nothing = (data.adjustments || 0) === 0 && (data.new_invoices || 0) === 0 && (data.credits || 0) === 0;
      var html = '<div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">' +
        mini('Сумма', B.money(data.old_total) + ' → ' + B.money(data.total)) +
        mini('Участников', data.old_participants + ' → ' + data.participants) +
        mini('На каждого', B.money(data.old_per_unit) + ' → ' + B.money(data.per_unit)) +
        mini('Документов', (data.adjustments || 0) + ' доп. · ' + (data.new_invoices || 0) + ' новых · ' + (data.credits || 0) + ' сторно') +
      '</div>';

      if (nothing) {
        html += '<div class="alert alert-info mt-3">' + B.icon('info') + '<div><div class="alert-title">Изменений не требуется</div>' +
                '<div class="alert-desc">Суммы и состав участников совпадают — дополнительные счета создавать не нужно.</div></div></div>';
        out.innerHTML = html;
        return;
      }

      html += '<p class="mt-3 text-xs text-muted-foreground">Причина: ' + B.escapeHtml(data.reason || '') + '</p>';

      var rows = (data.details || []).slice(0, 60).map(function (d) {
        var tone = d.type === 'credit' ? 'badge-soft-warning' : (d.type === 'new' ? 'badge-soft-info' : 'badge-soft-success');
        var label = d.type === 'credit' ? 'сторно' : (d.type === 'new' ? 'новый счёт' : 'доп. счёт');
        return '<tr' + (d.active ? '' : ' class="is-inactive"') + '>' +
          '<td class="tabular text-muted-foreground">' + B.escapeHtml(d.apartment || '—') + '</td>' +
          '<td>' + B.escapeHtml(d.name) + (d.active ? '' : ' <span class="badge badge-muted">отключён</span>') + '</td>' +
          '<td><span class="badge ' + tone + '">' + label + '</span></td>' +
          '<td class="table-numeric font-semibold tabular ' + (d.amount < 0 ? 'text-success' : '') + '">' + B.money(d.amount) + '</td></tr>';
      }).join('');

      html += '<div class="table-wrap mt-3 max-h-80 overflow-auto"><table class="table"><thead><tr>' +
              '<th>Кв.</th><th>Житель</th><th>Документ</th><th class="text-right">Сумма</th></tr></thead><tbody>' + rows + '</tbody></table></div>';
      html += '<div class="alert alert-warning mt-3">' + B.icon('alert-triangle') + '<div><div class="alert-title">Выставленные счета не изменяются</div>' +
              '<div class="alert-desc">Будут созданы отдельные дополнительные счета и сторно — так сохраняется полная история начислений.</div></div></div>';

      out.innerHTML = html;

      function mini(label, value) {
        return '<div class="rounded-lg border bg-muted/30 p-3"><div class="text-xs text-muted-foreground">' + label + '</div>' +
               '<div class="mt-1 text-sm font-semibold">' + value + '</div></div>';
      }
    }
  }

  /* ══════════════════════════ import wizard ══════════════════════════ */

  function initImport() {
    var form = $('[data-import-form]');
    if (!form) return;

    var file = $('input[type="file"]', form);
    var inspectUrl = form.getAttribute('data-import-inspect');
    var drop = $('[data-file-drop]');
    var preview = $('[data-import-preview]');

    if (drop && file) {
      ['dragenter', 'dragover'].forEach(function (evt) {
        drop.addEventListener(evt, function (e) { e.preventDefault(); drop.classList.add('is-over'); });
      });
      ['dragleave', 'drop'].forEach(function (evt) {
        drop.addEventListener(evt, function (e) { e.preventDefault(); drop.classList.remove('is-over'); });
      });
      drop.addEventListener('drop', function (e) {
        if (e.dataTransfer && e.dataTransfer.files.length) {
          file.files = e.dataTransfer.files;
          file.dispatchEvent(new Event('change'));
        }
      });
      drop.addEventListener('click', function () { file.click(); });
    }

    if (!file) return;

    file.addEventListener('change', function () {
      if (!file.files || !file.files[0]) return;
      var f = file.files[0];
      var sizeLabel = $('[data-file-size]', form);
      if (sizeLabel) sizeLabel.textContent = f.name + ' · ' + humanSize(f.size);

      if (!inspectUrl) return;
      var fd = new FormData();
      fd.append('file', f);
      fd.append('delimiter', (form.querySelector('[name="delimiter"]') || {}).value || 'auto');

      fetch(B.url(inspectUrl), {
        method: 'POST', credentials: 'same-origin', body: fd,
        headers: { 'Accept': 'application/json', 'X-CSRF-Token': B.csrf(), 'X-Requested-With': 'XMLHttpRequest' }
      }).then(function (r) { return r.json(); }).then(function (data) {
        if (!data || data.ok === false) {
          if (preview) preview.innerHTML = '<div class="alert alert-destructive">' + B.escapeHtml((data && data.error) || 'Не удалось разобрать файл') + '</div>';
          return;
        }
        renderPreview(data);
      }).catch(function (err) {
        if (preview) preview.innerHTML = '<div class="alert alert-destructive">' + B.escapeHtml(err.message) + '</div>';
      });
    });

    function renderPreview(data) {
      if (!preview) return;
      var cols = data.columns || [];
      var mapped = cols.filter(function (c) { return c.mapped; }).length;
      var html = '<div class="alert alert-info"><div class="alert-title">Файл распознан</div>' +
        '<div class="alert-desc">Разделитель: <code class="inline-code">' + B.escapeHtml(data.delimiter || ';') + '</code> · ' +
        'распознано колонок: <b>' + mapped + '</b> из ' + cols.length + '</div></div>';

      html += '<div class="table-wrap mt-3"><table class="table"><thead><tr><th>Колонка в файле</th><th>Поле системы</th></tr></thead><tbody>';
      cols.forEach(function (c) {
        html += '<tr><td class="font-mono text-xs">' + B.escapeHtml(c.raw || '(пусто)') + '</td>' +
                '<td>' + (c.mapped
                  ? '<span class="badge badge-soft-success">' + B.escapeHtml(c.mapped) + '</span>'
                  : '<span class="badge badge-muted">игнорируется</span>') + '</td></tr>';
      });
      html += '</tbody></table></div>';

      if ((data.sample || []).length) {
        var keys = Object.keys(data.sample[0]);
        html += '<div class="mt-4 text-sm font-medium">Первые строки</div>';
        html += '<div class="table-wrap mt-2 max-h-64 overflow-auto"><table class="table"><thead><tr>' +
                keys.map(function (k) { return '<th>' + B.escapeHtml(k) + '</th>'; }).join('') + '</tr></thead><tbody>';
        data.sample.forEach(function (row) {
          html += '<tr>' + keys.map(function (k) { return '<td class="max-w-[14rem] truncate">' + B.escapeHtml(row[k] == null ? '' : row[k]) + '</td>'; }).join('') + '</tr>';
        });
        html += '</tbody></table></div>';
      }

      if ((data.unmapped || []).length) {
        html += '<div class="alert alert-warning mt-3">' + B.icon('alert-triangle') + '<div><div class="alert-title">Нераспознанные колонки</div>' +
                '<div class="alert-desc">' + data.unmapped.map(B.escapeHtml).join(', ') + ' — эти данные будут проигнорированы.</div></div></div>';
      }

      preview.innerHTML = html;
      preview.hidden = false;
    }

    function humanSize(bytes) {
      var units = ['Б', 'КБ', 'МБ', 'ГБ'];
      var i = 0, v = bytes;
      while (v >= 1024 && i < units.length - 1) { v /= 1024; i++; }
      return v.toFixed(i ? 1 : 0) + ' ' + units[i];
    }
  }

  /* ══════════════════════════ settings ══════════════════════════ */

  function initSettings() {
    // Channel self-tests.
    $$('[data-channel-test]').forEach(function (btn) {
      btn.addEventListener('click', function (e) {
        e.preventDefault();
        var channel = btn.getAttribute('data-channel-test');
        var to = (btn.getAttribute('data-channel-test-to') || '').trim();
        if (!to) {
          var input = $('[data-channel-recipient="' + channel + '"]');
          to = input ? input.value.trim() : '';
        }
        if (!to) { B.toast.warning('Укажите получателя для теста.'); return; }

        B.busy(btn, true, 'Проверяем…');
        B.post(btn.getAttribute('data-channel-test-url') || '/admin/notifications/test', { channel: channel, to: to })
          .then(function (data) {
            B.busy(btn, false);
            B.toast.success(data.message || 'Канал работает');
          })
          .catch(function (err) {
            B.busy(btn, false);
            B.toast.error((err && err.message) || 'Канал не отвечает');
          });
      });
    });

    // Reveal/hide secret values.
    $$('[data-secret-toggle]').forEach(function (btn) {
      btn.addEventListener('click', function (e) {
        e.preventDefault();
        var input = $(btn.getAttribute('data-secret-toggle'));
        if (!input) return;
        var shown = input.type === 'text';
        input.type = shown ? 'password' : 'text';
        btn.setAttribute('aria-label', shown ? 'Показать' : 'Скрыть');
        btn.innerHTML = B.icon(shown ? 'eye' : 'eye-off');
      });
    });
  }

  /* ══════════════════════════ charts from embedded data ══════════════════════════ */

  function initCharts() {
    $$('[data-chart]').forEach(function (el) {
      var type = el.getAttribute('data-chart');
      var raw = el.getAttribute('data-chart-data');
      var data;
      try { data = JSON.parse(raw || '{}'); } catch (e) { return; }
      var format = data.money ? B.money : null;

      if (type === 'bars') {
        B.chart.bars(el, { labels: data.labels || [], values: data.values || [], height: data.height || 120, format: format });
      } else if (type === 'line') {
        B.chart.line(el, { labels: data.labels || [], values: data.values || [], height: data.height || 120, format: format, min: data.min });
      } else if (type === 'donut') {
        B.chart.donut(el, { items: data.items || [], size: data.size || 150, center: data.center, centerSub: data.centerSub, format: format });
      } else if (type === 'ranks') {
        B.chart.ranks(el, { items: data.items || [], format: format });
      }
    });

    // Redraw on resize (SVG viewBox keeps it crisp, but bar widths adapt).
    var redraw = B.debounce(function () {
      $$('[data-chart="bars"], [data-chart="line"]').forEach(function (el) {
        var raw = el.getAttribute('data-chart-data');
        try {
          var data = JSON.parse(raw || '{}');
          if (el.getAttribute('data-chart') === 'bars') B.chart.bars(el, data);
          else B.chart.line(el, data);
        } catch (e) {}
      });
    }, 250);
    window.addEventListener('resize', redraw);
  }

  /* ══════════════════════════ misc admin bits ══════════════════════════ */

  function initPaymentDialog() {
    var form = $('[data-payment-form]');
    if (!form) return;

    var resident = $('[data-payment-resident]', form);
    var amount = $('[name="amount"]', form);
    var invoice = $('[name="invoice_id"]', form);

    function syncFromInvoice() {
      if (!invoice || !amount) return;
      var selected = invoice.options[invoice.selectedIndex];
      if (!selected) return;
      var due = selected.getAttribute('data-amount');
      if (due && !amount.dataset.touched) amount.value = due;
      var label = $('[data-payment-debt]');
      if (label) label.textContent = selected.getAttribute('data-label') || '';
    }

    if (invoice) {
      invoice.addEventListener('change', syncFromInvoice);
      syncFromInvoice();
    }
    if (amount) amount.addEventListener('input', function () { amount.dataset.touched = '1'; });

    // Quick "оплатить полностью" buttons.
    $$('[data-payment-fill]', form).forEach(function (btn) {
      btn.addEventListener('click', function (e) {
        e.preventDefault();
        if (amount) { amount.value = btn.getAttribute('data-payment-fill'); amount.dataset.touched = '1'; amount.focus(); }
      });
    });

    if (resident && resident.tagName === 'SELECT') {
      resident.addEventListener('change', function () {
        var url = form.getAttribute('data-payment-invoices-url');
        if (!url || !invoice) return;
        B.get(url + '?resident_id=' + encodeURIComponent(resident.value)).then(function (data) {
          invoice.innerHTML = (data.invoices || []).map(function (inv) {
            return '<option value="' + inv.id + '" data-amount="' + inv.balance + '" data-label="' + B.escapeHtml(inv.period || '') + ' · остаток ' + inv.balance + '">' +
                   B.escapeHtml(inv.number + ' — ' + (inv.period || '') + ' · ' + inv.balance) + '</option>';
          }).join('') || '<option value="">Нет открытых счетов</option>';
          syncFromInvoice();
        }).catch(function () {});
      });
    }
  }

  function initDatePresets() {
    $$('[data-date-preset]').forEach(function (btn) {
      btn.addEventListener('click', function (e) {
        e.preventDefault();
        var preset = btn.getAttribute('data-date-preset');
        var from = $('[data-date-from]');
        var to = $('[data-date-to]');
        var now = new Date();
        var start = new Date(now);

        if (preset === 'today') { /* start = today */ }
        else if (preset === 'week') start.setDate(now.getDate() - 7);
        else if (preset === 'month') start.setMonth(now.getMonth() - 1);
        else if (preset === 'quarter') start.setMonth(now.getMonth() - 3);
        else if (preset === 'year') start.setFullYear(now.getFullYear() - 1);

        if (from) from.value = iso(start);
        if (to) to.value = iso(now);
        var form = btn.closest('form');
        if (form && btn.getAttribute('data-date-submit') !== 'false') form.submit();
      });
    });

    function iso(d) {
      return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
    }
  }

  function initLiveSearch() {
    $$('[data-global-search]').forEach(function (input) {
      var results = $('[data-search-results]');
      if (!results) return;
      var url = input.getAttribute('data-search-url');

      input.addEventListener('input', B.debounce(function () {
        var q = input.value.trim();
        if (q.length < 2) { results.hidden = true; results.innerHTML = ''; return; }
        B.get(url + '?q=' + encodeURIComponent(q)).then(function (data) {
          var items = data.results || [];
          if (!items.length) {
            results.innerHTML = '<div class="command-empty">Ничего не найдено</div>';
            results.hidden = false;
            return;
          }
          results.innerHTML = items.map(function (item) {
            return '<a class="command-item" href="' + B.escapeHtml(item.url) + '">' + B.icon(item.icon || 'arrow-right') +
              '<span class="min-w-0"><span class="block truncate">' + B.escapeHtml(item.title) + '</span>' +
              (item.subtitle ? '<span class="block truncate text-xs text-muted-foreground">' + B.escapeHtml(item.subtitle) + '</span>' : '') +
              '</span><span class="command-item-hint">' + B.escapeHtml(item.group || '') + '</span></a>';
          }).join('');
          results.hidden = false;
        }).catch(function () {});
      }, 220));

      doc.addEventListener('click', function (e) {
        if (!e.target.closest('[data-search-results], [data-global-search]')) results.hidden = true;
      });
    });
  }

  /* ══════════════════════════ boot ══════════════════════════ */

  function boot() {
    initResidentsTable();
    initResidentForm();
    initChargeWizard();
    initRecalc();
    initImport();
    initSettings();
    initCharts();
    initPaymentDialog();
    initDatePresets();
    initLiveSearch();
  }

  if (doc.readyState === 'loading') doc.addEventListener('DOMContentLoaded', boot);
  else boot();
})();
