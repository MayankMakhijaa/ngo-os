/* Fameweb NGO Cloud — progressive enhancement. The app works without JavaScript;
   every rule here is also enforced on the server. No inline scripts (strict CSP). */
(function () {
  'use strict';

  // Toasts for flash messages
  document.querySelectorAll('.toast').forEach(function (el) {
    if (window.bootstrap) { new bootstrap.Toast(el).show(); }
  });

  // Collapsible sidebar (desktop), remembered per browser
  var collapseBtn = document.getElementById('sidebarCollapse');
  try { if (localStorage.getItem('sidebarCollapsed') === '1') { document.body.classList.add('sidebar-collapsed'); } } catch (e) {}
  if (collapseBtn) {
    collapseBtn.setAttribute('aria-pressed', document.body.classList.contains('sidebar-collapsed') ? 'true' : 'false');
    collapseBtn.addEventListener('click', function () {
      var on = document.body.classList.toggle('sidebar-collapsed');
      collapseBtn.setAttribute('aria-pressed', on ? 'true' : 'false');
      try { localStorage.setItem('sidebarCollapsed', on ? '1' : '0'); } catch (e) {}
    });
  }

  // Client-side validation feedback (server validation is authoritative)
  document.querySelectorAll('form.needs-validation').forEach(function (form) {
    form.addEventListener('submit', function (ev) {
      form.querySelectorAll('[data-match]').forEach(function (input) {
        var other = form.querySelector(input.getAttribute('data-match'));
        input.setCustomValidity(other && other.value !== input.value ? 'Passwords do not match' : '');
      });
      if (!form.checkValidity()) {
        ev.preventDefault();
        ev.stopImmediatePropagation();
        var first = form.querySelector(':invalid');
        if (first) { first.focus(); }
      }
      form.classList.add('was-validated');
    }, true);
  });

  // Loading state: disable the submit button to prevent double submits
  document.querySelectorAll('form[data-loading]').forEach(function (form) {
    form.addEventListener('submit', function (ev) {
      if (ev.defaultPrevented) { return; }
      var btn = form.querySelector('button[type="submit"]');
      if (btn) { setTimeout(function () { btn.disabled = true; btn.classList.add('is-loading'); btn.setAttribute('aria-busy', 'true'); }, 0); }
    });
  });

  // Confirmation dialog for sensitive actions: <form data-confirm="..." [data-confirm-reason="field"]>
  var modalEl = document.getElementById('confirmModal');
  var modal = modalEl && window.bootstrap ? new bootstrap.Modal(modalEl) : null;
  var pendingForm = null;
  document.querySelectorAll('form[data-confirm]').forEach(function (form) {
    form.addEventListener('submit', function (ev) {
      if (form.dataset.confirmed === '1') { return; }
      if (ev.defaultPrevented) { return; }
      ev.preventDefault();
      if (!modal) { if (window.confirm(form.dataset.confirm)) { form.dataset.confirmed = '1'; form.requestSubmit ? form.requestSubmit() : form.submit(); } return; }
      pendingForm = form;
      document.getElementById('confirmText').textContent = form.dataset.confirm;
      var reasonWrap = document.getElementById('confirmReasonWrap');
      var reason = document.getElementById('confirmReason');
      reason.value = '';
      reason.classList.remove('is-invalid');
      reasonWrap.classList.toggle('d-none', !form.dataset.confirmReason);
      document.getElementById('confirmOk').textContent = form.dataset.confirmButton || 'Confirm';
      modal.show();
    });
  });
  if (modalEl) {
    modalEl.addEventListener('shown.bs.modal', function () {
      var reasonWrap = document.getElementById('confirmReasonWrap');
      (reasonWrap.classList.contains('d-none') ? document.getElementById('confirmOk') : document.getElementById('confirmReason')).focus();
    });
    document.getElementById('confirmOk').addEventListener('click', function () {
      if (!pendingForm) { return; }
      var field = pendingForm.dataset.confirmReason;
      if (field) {
        var reason = document.getElementById('confirmReason');
        if (!reason.value.trim()) { reason.classList.add('is-invalid'); reason.focus(); return; }
        var input = pendingForm.querySelector('[name="' + field + '"]');
        if (input) { input.value = reason.value.trim(); }
      }
      pendingForm.dataset.confirmed = '1';
      modal.hide();
      var btn = pendingForm.querySelector('button[type="submit"]');
      if (btn) { btn.disabled = true; btn.classList.add('is-loading'); }
      HTMLFormElement.prototype.submit.call(pendingForm);
    });
  }

  // Show / hide password
  document.querySelectorAll('[data-toggle-password]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var input = document.querySelector(btn.getAttribute('data-toggle-password'));
      if (!input) { return; }
      var show = input.type === 'password';
      input.type = show ? 'text' : 'password';
      btn.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
      btn.querySelector('i').className = show ? 'bi bi-eye-slash' : 'bi bi-eye';
    });
  });

  // Copy to clipboard
  document.querySelectorAll('[data-copy]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var input = document.querySelector(btn.getAttribute('data-copy'));
      if (!input) { return; }
      input.select();
      var done = function () { var t = btn.innerHTML; btn.textContent = 'Copied'; setTimeout(function () { btn.innerHTML = t; }, 1500); };
      if (navigator.clipboard) { navigator.clipboard.writeText(input.value).then(done, function () { document.execCommand('copy'); done(); }); }
      else { document.execCommand('copy'); done(); }
    });
  });

  // Charts (data comes from <script type="application/json"> blocks rendered by the server)
  function readJson(id) { var el = document.getElementById(id); try { return el ? JSON.parse(el.textContent) : null; } catch (e) { return null; } }
  if (window.Chart) {
    var accent = getComputedStyle(document.body).getPropertyValue('--app-accent').trim() || '#2b59c3';
    var rev = readJson('revenueData');
    var revEl = document.getElementById('revenueChart');
    if (rev && revEl) {
      new Chart(revEl, {
        type: 'bar',
        data: { labels: rev.labels, datasets: [{ label: 'Revenue (' + rev.currency + ')', data: rev.values, backgroundColor: accent, borderRadius: 6 }] },
        options: { maintainAspectRatio: false, plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true } } }
      });
    }
    var sub = readJson('subData');
    var subEl = document.getElementById('subChart');
    if (sub && subEl) {
      new Chart(subEl, {
        type: 'doughnut',
        data: { labels: sub.labels, datasets: [{ data: sub.values, backgroundColor: ['#0dcaf0', '#198754', '#ffc107', '#6c757d', '#dc3545', '#212529'] }] },
        options: { maintainAspectRatio: false, plugins: { legend: { position: 'bottom' } } }
      });
    }
  }
})();
