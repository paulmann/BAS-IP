/**
 * BAS-IP · public guest page runtime (no authentication required).
 *
 * One job: make the "Открыть шлагбаум" button on a guest link fast, obvious
 * and safe — with a big target, haptic feedback, cooldown handling and a clear
 * explanation when the pass is no longer valid.
 */
(function () {
  'use strict';

  var B = window.BasIp;
  var doc = document;

  function boot() {
    var btn = doc.querySelector('[data-guest-open]');
    if (!btn) return;

    var url = btn.getAttribute('data-guest-open');
    var cooldown = parseInt(btn.getAttribute('data-guest-cooldown') || '5', 10) * 1000;
    var until = 0;
    var status = doc.querySelector('[data-guest-status]');
    var bar = doc.querySelector('[data-gate-bar]');

    function setState(text, tone) {
      if (!status) return;
      status.textContent = text;
      status.className = 'text-sm font-medium ' + ({
        ok: 'text-success', err: 'text-destructive', wait: 'text-warning', idle: 'text-muted-foreground'
      }[tone] || 'text-muted-foreground');
    }

    btn.addEventListener('click', function (e) {
      e.preventDefault();
      if (Date.now() < until) {
        setState('Подождите ' + Math.ceil((until - Date.now()) / 1000) + ' с и повторите.', 'wait');
        return;
      }

      btn.disabled = true;
      btn.setAttribute('aria-busy', 'true');
      var original = btn.innerHTML;
      btn.innerHTML = '<span class="inline-flex items-center gap-2">' +
        '<svg class="lucide animate-spin" aria-hidden="true"><use href="' + B.iconHref('loader-circle') + '"></use></svg> Открываем…</span>';
      setState('Отправляем команду на шлагбаум…', 'idle');

      B.post(url, {})
        .then(function (data) {
          until = Date.now() + cooldown;
          if (bar) bar.classList.add('is-open');
          setState(data.message || 'Шлагбаум открыт. Проезжайте!', 'ok');
          if (navigator.vibrate) { try { navigator.vibrate([15, 40, 15]); } catch (err) {} }
          var left = doc.querySelector('[data-guest-uses-left]');
          if (left && data.opens_left !== null && data.opens_left !== undefined) {
            left.textContent = data.opens_left === null ? 'без ограничений' : ('осталось ' + data.opens_left);
          }
          setTimeout(function () { if (bar) bar.classList.remove('is-open'); }, 8000);
        })
        .catch(function (err) {
          var message = (err && err.message) || 'Не удалось открыть шлагбаум';
          setState(message, 'err');
          if (navigator.vibrate) { try { navigator.vibrate(160); } catch (e2) {} }
          if (err && err.data && err.data.error_code === 'cooldown') until = Date.now() + cooldown;
        })
        .then(function () {
          btn.disabled = false;
          btn.removeAttribute('aria-busy');
          btn.innerHTML = original;
        });
    });

    // Открытие по Enter/пробелу для клавиатуры и вспомогательных технологий.
    doc.addEventListener('keydown', function (e) {
      if ((e.key === 'Enter' || e.key === ' ') && doc.activeElement === btn) return;
      if (e.key === 'Enter' && !/^(INPUT|TEXTAREA)$/.test(doc.activeElement.tagName) && !btn.disabled) {
        e.preventDefault();
        btn.click();
      }
    });
  }

  if (doc.readyState === 'loading') doc.addEventListener('DOMContentLoaded', boot);
  else boot();
})();
