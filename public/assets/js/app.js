/* LoyalBox front-end behaviour. Plain JavaScript, no libraries.
   Each block below is independent and starts with a comment saying what it does. */
(function () {
  'use strict';
  var $ = function (sel, root) { return (root || document).querySelector(sel); };
  var $$ = function (sel, root) { return Array.prototype.slice.call((root || document).querySelectorAll(sel)); };
  var reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  var ICONS = {
    success: '<path d="M5 12.5l4.5 4.5L19 7.5"/>',
    error: '<path d="M12 3l10 18H2z"/><path d="M12 10v5M12 18h.01"/>',
    info: '<circle cx="12" cy="12" r="9"/><path d="M12 11v5M12 8h.01"/>',
    x: '<path d="M6 6l12 12M18 6L6 18"/>'
  };
  function svg(name) {
    return '<svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' + ICONS[name] + '</svg>';
  }

  /* ---- Theme toggle (saved in localStorage) ---- */
  var themeBtn = $('.theme-toggle');
  function syncThemeLabel() {
    if (!themeBtn) return;
    var dark = document.documentElement.getAttribute('data-theme') === 'dark';
    themeBtn.setAttribute('aria-label', dark ? 'Switch to light theme' : 'Switch to dark theme');
  }
  if (themeBtn) {
    syncThemeLabel();
    themeBtn.addEventListener('click', function () {
      var next = document.documentElement.getAttribute('data-theme') === 'dark' ? 'light' : 'dark';
      document.body.classList.add('theme-anim');                       // smooth colour change
      document.documentElement.setAttribute('data-theme', next);
      try { localStorage.setItem('lb-theme', next); } catch (e) { /* ignore */ }
      syncThemeLabel();
      setTimeout(function () { document.body.classList.remove('theme-anim'); }, 400);
    });
  }

  /* ---- Mobile navigation (slide-out sidebar) ---- */
  var sidebar = $('#sidebar'), scrim = $('.scrim'), navBtn = $('.nav-toggle');
  function setNav(open) {
    if (!sidebar) return;
    sidebar.classList.toggle('is-open', open);
    if (scrim) scrim.hidden = !open;
    if (navBtn) { navBtn.setAttribute('aria-expanded', String(open)); navBtn.setAttribute('aria-label', open ? 'Close menu' : 'Open menu'); }
    if (open) { var first = $('a', sidebar); if (first) first.focus(); }
  }
  if (navBtn) navBtn.addEventListener('click', function () { setNav(!sidebar.classList.contains('is-open')); });
  if (scrim) scrim.addEventListener('click', function () { setNav(false); });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && sidebar && sidebar.classList.contains('is-open')) { setNav(false); if (navBtn) navBtn.focus(); } });

  /* ---- Time-based greeting (uses the visitor's own clock) ---- */
  var greet = $('[data-greeting]');
  if (greet) {
    var h = new Date().getHours();
    greet.textContent = (h < 5 ? 'Good evening' : h < 12 ? 'Good morning' : h < 18 ? 'Good afternoon' : 'Good evening') + ', ' + greet.getAttribute('data-name') + '.';
  }

  /* ---- Toasts ---- */
  var toastBox = $('.toasts');
  function toast(type, message) {
    if (!toastBox) return;
    var ms = type === 'error' ? 8000 : 5000;
    var el = document.createElement('div');
    el.className = 'toast toast-' + type;
    el.setAttribute('role', type === 'error' ? 'alert' : 'status');
    el.style.setProperty('--toast-ms', ms + 'ms');
    el.innerHTML = svg(type === 'error' ? 'error' : type === 'success' ? 'success' : 'info') + '<p></p>' +
      '<button class="toast-close" type="button" aria-label="Dismiss notification">' + svg('x') + '</button><span class="toast-bar"></span>';
    el.querySelector('p').textContent = message;                        // textContent: never interpreted as HTML
    var timer;
    function close() { clearTimeout(timer); el.remove(); }
    function start() { timer = setTimeout(close, ms); }
    el.querySelector('.toast-close').addEventListener('click', close);
    el.addEventListener('mouseenter', function () { clearTimeout(timer); });
    el.addEventListener('mouseleave', start);
    toastBox.appendChild(el);
    start();
  }
  try {
    JSON.parse(($('#flash-data') || {}).textContent || '[]').forEach(function (f) { toast(f.type, f.message); });
  } catch (e) { /* no flashes */ }

  /* ---- Custom confirmation dialog for forms with data-confirm ---- */
  var cDialog = $('#confirm-dialog'), pendingForm = null;
  if (cDialog) {
    $$('form[data-confirm]').forEach(function (form) {
      form.addEventListener('submit', function (e) {
        if (form.dataset.confirmed === '1') return;
        e.preventDefault(); e.stopImmediatePropagation();
        pendingForm = form;
        $('#confirm-text').textContent = form.getAttribute('data-confirm');
        var ok = $('[data-confirm-ok]', cDialog);
        ok.classList.toggle('btn-danger', form.hasAttribute('data-danger'));
        ok.classList.toggle('btn-primary', !form.hasAttribute('data-danger'));
        cDialog.showModal();
        $('[data-confirm-cancel]', cDialog).focus();
      }, true);
    });
    $('[data-confirm-cancel]', cDialog).addEventListener('click', function () { cDialog.close(); pendingForm = null; });
    $('[data-confirm-ok]', cDialog).addEventListener('click', function () {
      var f = pendingForm; cDialog.close(); pendingForm = null;
      if (f) { f.dataset.confirmed = '1'; f.requestSubmit(); }
    });
  }

  /* ---- Generic dialogs: [data-dialog-open="#id"], optional data-fill JSON to pre-fill fields ---- */
  function fillForm(form, data) {
    $$('input, select, textarea', form).forEach(function (el) {
      if (!el.name || el.type === 'hidden' && el.name === 'csrf' || el.name === 'action') return;
      if (el.tagName === 'SELECT' && !data) return;                      // keep the selected option when adding
      if (el.type === 'checkbox') el.checked = data ? !!data[el.name] : true;
      else el.value = data && data[el.name] != null ? data[el.name] : (el.name === 'id' ? '0' : el.defaultValue);
    });
  }
  $$('[data-dialog-open]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var d = $(btn.getAttribute('data-dialog-open'));
      if (!d) return;
      var form = $('form', d), data = null;
      try { data = btn.hasAttribute('data-fill') ? JSON.parse(btn.getAttribute('data-fill')) : null; } catch (e) { data = null; }
      if (form) { fillForm(form, data); clearErrors(form); 
        var t = $('h2', d);                                              // "Product" -> "Add product" / "Edit product"
        if (t && $('input[name=id]', form)) { if (t.dataset.base === undefined) t.dataset.base = t.textContent; t.textContent = (data ? 'Edit ' : 'Add ') + t.dataset.base.toLowerCase(); } }
      d.showModal();
      var first = $('input:not([type=hidden]), select, textarea', d); if (first) first.focus();
    });
  });
  $$('dialog.modal').forEach(function (d) {
    $$('[data-dialog-close]', d).forEach(function (b) { b.addEventListener('click', function () { d.close(); }); });
    d.addEventListener('click', function (e) { if (e.target === d) d.close(); });   // click on backdrop
  });

  /* ---- Inline validation (uses the browser's built-in rules; messages shown under the field) ---- */
  function errorEl(input) { return input.id ? document.getElementById(input.id + '-error') : null; }
  function validateInput(input) {
    var msg = '';
    if (input.dataset.match) {                                           // "repeat password" must equal the other field
      var other = input.form.elements[input.dataset.match];
      input.setCustomValidity(other && input.value !== other.value ? 'The passwords do not match.' : '');
    }
    if (!input.checkValidity()) {
      msg = input.validationMessage;
      if (input.validity.valueMissing) msg = 'This field is required.';
    }
    var box = errorEl(input);
    if (box) box.textContent = msg;
    if (input.closest('.field')) input.closest('.field').classList.toggle('has-error', !!msg);
    input.setAttribute('aria-invalid', msg ? 'true' : 'false');
    return !msg;
  }
  function clearErrors(form) {
    $$('.field', form).forEach(function (f) { f.classList.remove('has-error'); var s = $('.field-error', f); if (s) s.textContent = ''; });
  }
  $$('form').forEach(function (form) {
    var inputs = $$('input:not([type=hidden]):not([type=checkbox]), select, textarea', form);
    inputs.forEach(function (i) {
      i.addEventListener('blur', function (e) {
        // Focus moving to a button (e.g. Submit)? Skip: an error line appearing now would shift the button and the click would miss.
        if (e.relatedTarget && e.relatedTarget.tagName === 'BUTTON') return;
        if (i.value !== '' || i.closest('.has-error')) validateInput(i);
      });
      i.addEventListener('input', function () { if (i.closest('.has-error')) validateInput(i); });
    });
    form.addEventListener('submit', function (e) {
      var bad = inputs.filter(function (i) { return !validateInput(i); });
      if (bad.length) { e.preventDefault(); e.stopImmediatePropagation(); bad[0].focus(); return; }
      var btn = $('button[type=submit]', form);                         // loading state; disabled a tick later so the form still posts
      if (btn && !form.hasAttribute('data-confirm') ) { btn.classList.add('is-loading'); btn.setAttribute('aria-busy', 'true'); setTimeout(function () { btn.disabled = true; }, 0); }
    });
  });
  window.addEventListener('pageshow', function (e) {                    // Back button: undo the loading state
    if (!e.persisted) return;
    $$('button.is-loading').forEach(function (b) { b.classList.remove('is-loading'); b.removeAttribute('aria-busy'); b.disabled = false; });
  });
  var firstServerError = $('.field.has-error input, .field.has-error select, .field.has-error textarea');
  if (firstServerError) firstServerError.focus(); else { var af = $('[data-autofocus]'); if (af) af.focus(); }
  $$('select[data-autosubmit]').forEach(function (s) { s.addEventListener('change', function () { s.form.submit(); }); });

  /* ---- Tables: live search, status filter, sortable headers, record counter ---- */
  $$('[data-table]').forEach(function (wrap) {
    var body = $('tbody', wrap), rows = $$('tr', body);
    var search = $('[data-table-search]', wrap), filter = $('[data-table-filter]', wrap), count = $('[data-table-count]', wrap), empty = $('[data-table-empty]', wrap), scroller = $('.table-scroll', wrap);
    var texts = rows.map(function (r) { return r.textContent.toLowerCase().replace(/\s+/g, ' '); });
    function apply() {
      var q = search ? search.value.trim().toLowerCase() : '', fv = filter ? filter.value : '', col = filter ? +filter.dataset.col : 0, shown = 0;
      rows.forEach(function (r, i) {
        var ok = (!q || texts[i].indexOf(q) !== -1) && (!fv || r.cells[col].textContent.trim().toLowerCase() === fv);
        r.hidden = !ok; if (ok) shown++;
      });
      if (count) count.textContent = shown === rows.length ? rows.length + ' record' + (rows.length === 1 ? '' : 's') : shown + ' of ' + rows.length + ' records';
      if (empty) empty.hidden = shown !== 0;
      if (scroller) scroller.hidden = shown === 0;
    }
    if (search) search.addEventListener('input', apply);
    if (filter) filter.addEventListener('change', apply);
    $$('button.sort', wrap).forEach(function (btn) {
      btn.addEventListener('click', function () {
        var th = btn.closest('th'), idx = th.cellIndex, type = btn.dataset.sort;
        var dir = th.getAttribute('aria-sort') === 'ascending' ? 'descending' : 'ascending';
        $$('th', wrap).forEach(function (h) { h.setAttribute('aria-sort', 'none'); });
        th.setAttribute('aria-sort', dir);
        function val(r) {
          var c = r.cells[idx], v = c.hasAttribute('data-v') ? c.getAttribute('data-v') : c.textContent.trim();
          return type === 'text' ? v.toLowerCase() : (parseFloat(String(v).replace(/[^0-9.\-]/g, '')) || 0);
        }
        rows.sort(function (a, b) { var x = val(a), y = val(b); var r = type === 'text' ? String(x).localeCompare(String(y)) : x - y; return dir === 'ascending' ? r : -r; });
        rows.forEach(function (r) { body.appendChild(r); });
        texts = rows.map(function (r) { return r.textContent.toLowerCase().replace(/\s+/g, ' '); });
      });
    });
    apply();
  });
  /* "/" focuses the first search box (unless you are already typing somewhere) */
  document.addEventListener('keydown', function (e) {
    if (e.key !== '/' || e.ctrlKey || e.metaKey || e.altKey) return;
    var t = e.target, tag = t && t.tagName;
    if (tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT' || (t && t.isContentEditable)) return;
    var s = $('[data-table-search]'); if (s) { e.preventDefault(); s.focus(); }
  });

  /* ---- Count-up for dashboard numbers (skipped when the visitor prefers reduced motion) ---- */
  if (!reduceMotion) {
    $$('[data-count]').forEach(function (el) {
      var target = +el.getAttribute('data-count'), t0 = null;
      if (!target) return;
      function step(t) {
        if (t0 === null) t0 = t;
        var p = Math.min(1, (t - t0) / 700), eased = 1 - Math.pow(1 - p, 3);
        el.textContent = Math.round(target * eased).toLocaleString();
        if (p < 1) requestAnimationFrame(step); else el.textContent = target.toLocaleString();
      }
      requestAnimationFrame(step);
    });
  }

  /* ---- Staggered entrance: give each child of .stagger its position ---- */
  $$('.stagger').forEach(function (g) { Array.prototype.forEach.call(g.children, function (c, i) { c.style.setProperty('--i', Math.min(i, 8)); }); });

  /* ---- Dashboard chart: claims per day, loaded from the server and drawn as inline SVG ---- */
  var chart = $('#claims-chart');
  if (chart) {
    fetch(chart.getAttribute('data-src'), { credentials: 'same-origin' })
      .then(function (r) { if (!r.ok) throw new Error('bad status'); return r.json(); })
      .then(function (data) {
        var NS = 'http://www.w3.org/2000/svg', W = 420, H = 220, pad = { l: 28, r: 4, t: 12, b: 28 };
        var max = Math.max.apply(null, data.map(function (d) { return d.claims; })), total = data.reduce(function (s, d) { return s + d.claims; }, 0);
        var top = Math.max(4, max), bw = (W - pad.l - pad.r) / data.length;
        var s = document.createElementNS(NS, 'svg');
        s.setAttribute('viewBox', '0 0 ' + W + ' ' + H); s.setAttribute('role', 'img');
        s.setAttribute('aria-label', total + ' claims in the last 14 days');
        function el(name, attrs, text) { var n = document.createElementNS(NS, name); for (var k in attrs) n.setAttribute(k, attrs[k]); if (text != null) n.textContent = text; return n; }
        [0, .5, 1].forEach(function (f) {
          var y = pad.t + (H - pad.t - pad.b) * (1 - f);
          s.appendChild(el('line', { x1: pad.l, x2: W - pad.r, y1: y, y2: y, 'class': 'axis' }));
          s.appendChild(el('text', { x: pad.l - 6, y: y + 4, 'text-anchor': 'end' }, String(Math.round(top * f))));
        });
        data.forEach(function (d, i) {
          var h = (H - pad.t - pad.b) * d.claims / top, x = pad.l + i * bw + bw * 0.18;
          var bar = el('rect', { x: x, y: H - pad.b - h, width: bw * 0.64, height: Math.max(h, 0), rx: 4, 'class': 'bar' });
          bar.appendChild(el('title', {}, d.label + ': ' + d.claims + ' claim' + (d.claims === 1 ? '' : 's')));
          s.appendChild(bar);
          if (i % 3 === 0) s.appendChild(el('text', { x: x + bw * 0.32, y: H - 8, 'text-anchor': 'middle' }, d.label));
        });
        chart.textContent = '';
        chart.appendChild(s);
        if (!total) { var p = document.createElement('p'); p.className = 'muted'; p.textContent = 'No claims in this period yet.'; chart.appendChild(p); }
      })
      .catch(function () { chart.innerHTML = '<p class="muted">The chart could not be loaded. Reload the page to try again.</p>'; });
  }

  /* ---- Download generated codes as CSV (the codes only exist in this page, never on the server) ---- */
  var dl = $('#download-codes');
  if (dl) {
    dl.addEventListener('click', function () {
      var rows = JSON.parse($('#codes-data').textContent), cols = ['batch', 'sku', 'product', 'code', 'claim_url'];
      function cell(v) { v = String(v); return '"' + v.replace(/"/g, '""') + '"'; }
      var csv = cols.join(',') + '\r\n' + rows.map(function (r) { return cols.map(function (c) { return cell(r[c]); }).join(','); }).join('\r\n');
      var a = document.createElement('a');
      a.href = URL.createObjectURL(new Blob([csv], { type: 'text/csv' }));
      a.download = dl.getAttribute('data-filename');
      document.body.appendChild(a); a.click(); a.remove();
      setTimeout(function () { URL.revokeObjectURL(a.href); }, 1000);
      toast('success', 'CSV downloaded. Send it to the printer, then delete your copy.');
    });
  }
})();
