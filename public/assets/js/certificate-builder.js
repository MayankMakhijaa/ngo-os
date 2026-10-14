/*
 * Certificate builder (Fameweb Admin > Certificate templates).
 * The form holds one row per element (parallel el_*[] inputs that the server reads); this
 * script draws a live preview from those inputs, lets the admin select and drag elements,
 * add / duplicate / reorder / remove rows and insert {{placeholders}}. No inline scripts (CSP).
 */
(function () {
  'use strict';
  var form = document.querySelector('form[data-cert-builder]');
  if (!form) { return; }
  var canvas = document.getElementById('certCanvas');
  var list = document.getElementById('certElements');
  var tpl = document.getElementById('certElementTpl');
  var sample = JSON.parse(canvas.getAttribute('data-sample') || '{}');
  var max = parseInt(list.getAttribute('data-max'), 10) || 40;
  var PT = 0.3528; // mm per point
  var FONTS = { helvetica: 'Helvetica, Arial, sans-serif', times: '"Times New Roman", Times, serif', courier: '"Courier New", Courier, monospace' };
  var selected = null;
  var scale = 1;

  function rows() { return Array.prototype.slice.call(list.querySelectorAll('.cert-element-row')); }
  function field(row, name) { return row.querySelector('[data-field="' + name + '"]'); }
  function val(row, name) { var f = field(row, name); return f ? f.value : ''; }
  function num(row, name, d) { var n = parseFloat(val(row, name)); return isNaN(n) ? d : n; }
  function pageSize() { return form.querySelector('[name="orientation"]').value === 'portrait' ? [210, 297] : [297, 210]; }
  function fill(text) {
    return text.replace(/\{\{\s*([a-z_]+)\s*\}\}/g, function (m, k) { return sample[k] !== undefined ? sample[k] : ''; }).replace(/[ \t]{2,}/g, ' ').trim();
  }

  function summary(row) {
    var type = val(row, 'type');
    var s = row.querySelector('[data-summary]');
    if (type === 'text') { s.textContent = val(row, 'text').split('\n')[0]; }
    else if (type === 'logo') { s.textContent = 'NGO logo'; }
    else if (type === 'line') { s.textContent = 'Line'; }
    else { s.textContent = 'Filled box'; }
    var size = row.querySelector('.el-size-label');
    if (size) { size.textContent = type === 'line' ? 'Thickness mm' : 'Size pt'; }
  }

  function frame(inset, width, color) {
    var d = document.createElement('div');
    d.className = 'cert-frame';
    d.style.left = d.style.top = (inset * scale) + 'px';
    d.style.width = ((pageSize()[0] - 2 * inset) * scale) + 'px';
    d.style.height = ((pageSize()[1] - 2 * inset) * scale) + 'px';
    d.style.border = Math.max(1, width * scale) + 'px solid ' + color;
    d.style.boxSizing = 'border-box';
    canvas.appendChild(d);
  }

  function render() {
    var size = pageSize();
    var wrapW = canvas.parentElement.clientWidth - 32;
    scale = Math.max(1.2, Math.min(3.2, wrapW / size[0]));
    canvas.style.width = (size[0] * scale) + 'px';
    canvas.style.height = (size[1] * scale) + 'px';
    canvas.style.background = form.querySelector('[name="page_background"]').value;
    canvas.innerHTML = '';
    var border = form.querySelector('[name="page_border"]').value;
    var bc = form.querySelector('[name="page_border_color"]').value;
    // Frames match CertificatePdf (line centred on the rectangle edge).
    if (border === 'double') { frame(8 - 0.6, 1.2, bc); frame(11.5 - 0.2, 0.4, bc); }
    else if (border === 'single') { frame(10 - 0.3, 0.6, bc); }
    else if (border === 'corners') { frame(6 - 1.5, 3, bc); }

    rows().forEach(function (row, i) {
      var type = val(row, 'type');
      row.setAttribute('data-type', type);
      summary(row);
      var el = document.createElement('div');
      el.className = 'cert-el' + (row === selected ? ' is-selected' : '');
      el.setAttribute('data-index', i);
      var x = num(row, 'x', 0), y = num(row, 'y', 0), w = num(row, 'w', 50), h = num(row, 'h', 10);
      el.style.left = (x * scale) + 'px';
      el.style.top = (y * scale) + 'px';
      el.style.width = (w * scale) + 'px';
      if (type === 'text') {
        var pt = num(row, 'size', 14);
        var style = val(row, 'style');
        el.textContent = fill(val(row, 'text'));
        el.style.fontFamily = FONTS[val(row, 'font')] || FONTS.helvetica;
        el.style.fontSize = (pt * PT * scale) + 'px';
        el.style.lineHeight = '1.3';
        el.style.marginTop = (-0.12 * pt * PT * scale) + 'px';
        el.style.fontWeight = style.indexOf('B') >= 0 ? '700' : '400';
        el.style.fontStyle = style.indexOf('I') >= 0 ? 'italic' : 'normal';
        el.style.textAlign = { L: 'left', C: 'center', R: 'right' }[val(row, 'align')] || 'center';
        el.style.color = val(row, 'color');
        el.style.overflow = 'visible';
      } else if (type === 'logo') {
        el.classList.add('cert-el-logo');
        el.style.height = (h * scale) + 'px';
        el.textContent = 'NGO logo';
      } else if (type === 'line') {
        var t = Math.max(1, num(row, 'size', 0.4) * scale);
        el.style.height = t + 'px';
        el.style.marginTop = (-t / 2) + 'px';
        el.style.background = val(row, 'color');
      } else {
        el.style.height = (h * scale) + 'px';
        el.style.background = val(row, 'color');
      }
      canvas.appendChild(el);
    });
  }

  function select(row, scroll) {
    rows().forEach(function (r) { r.classList.toggle('is-selected', r === row); });
    selected = row;
    // Scroll the element list (not the page) so the canvas stays put while dragging.
    if (row && scroll) { list.scrollTop = row.offsetTop - list.offsetTop - 8; }
    render();
  }

  function addRow(type, from) {
    if (rows().length >= max) { window.alert('A template can have up to ' + max + ' elements.'); return null; }
    var row = tpl.content.firstElementChild.cloneNode(true);
    if (from) {
      ['type', 'text', 'x', 'y', 'w', 'h', 'size', 'font', 'style', 'align', 'color'].forEach(function (k) {
        if (field(row, k) && field(from, k)) { field(row, k).value = field(from, k).value; }
      });
      field(row, 'y').value = Math.min(pageSize()[1] - 5, num(from, 'y', 0) + 8);
      from.after(row);
    } else {
      var size = pageSize();
      field(row, 'type').value = type;
      if (type === 'logo') { field(row, 'w').value = 30; field(row, 'h').value = 22; field(row, 'x').value = (size[0] - 30) / 2; }
      if (type === 'line') { field(row, 'w').value = 80; field(row, 'size').value = 0.4; field(row, 'color').value = '#333333'; field(row, 'x').value = (size[0] - 80) / 2; }
      if (type === 'rect') { field(row, 'w').value = size[0]; field(row, 'h').value = 12; field(row, 'x').value = 0; field(row, 'color').value = '#eeeeee'; }
      if (type === 'text') { field(row, 'x').value = 20; field(row, 'w').value = size[0] - 40; }
      field(row, 'y').value = size[1] / 2;
      list.appendChild(row);
    }
    select(row, true);
    return row;
  }

  list.addEventListener('click', function (e) {
    var row = e.target.closest('.cert-element-row');
    if (!row) { return; }
    var btn = e.target.closest('button');
    if (btn && btn.hasAttribute('data-remove')) {
      row.remove();
      if (selected === row) { selected = null; }
      render();
      return;
    }
    if (btn && btn.hasAttribute('data-duplicate')) { addRow(null, row); return; }
    if (btn && btn.hasAttribute('data-move')) {
      var dir = parseInt(btn.getAttribute('data-move'), 10);
      if (dir < 0 && row.previousElementSibling) { row.previousElementSibling.before(row); }
      if (dir > 0 && row.nextElementSibling) { row.nextElementSibling.after(row); }
      select(row, false);
      return;
    }
    if (selected !== row) { select(row, false); }
  });
  list.addEventListener('focusin', function (e) {
    var row = e.target.closest('.cert-element-row');
    if (row && selected !== row) { select(row, false); }
  });
  form.addEventListener('input', render);
  form.addEventListener('change', render);
  window.addEventListener('resize', render);

  form.querySelectorAll('[data-add]').forEach(function (b) {
    b.addEventListener('click', function () { addRow(b.getAttribute('data-add')); });
  });

  // Insert a placeholder into the selected (or first) text element at the cursor.
  document.querySelectorAll('[data-placeholder]').forEach(function (b) {
    b.addEventListener('click', function () {
      var row = selected && val(selected, 'type') === 'text' ? selected : null;
      if (!row) { row = addRow('text'); if (!row) { return; } field(row, 'text').value = ''; }
      var ta = field(row, 'text');
      var token = '{{' + b.getAttribute('data-placeholder') + '}}';
      var start = ta.selectionStart || ta.value.length, end = ta.selectionEnd || ta.value.length;
      ta.value = ta.value.slice(0, start) + token + ta.value.slice(end);
      ta.focus();
      ta.selectionStart = ta.selectionEnd = start + token.length;
      render();
    });
  });

  // Select and drag elements on the canvas; positions snap to 0.5 mm.
  var drag = null;
  canvas.addEventListener('pointerdown', function (e) {
    var el = e.target.closest('.cert-el');
    if (!el) { return; }
    var row = rows()[parseInt(el.getAttribute('data-index'), 10)];
    if (!row) { return; }
    select(row, true);
    drag = { row: row, sx: e.clientX, sy: e.clientY, x: num(row, 'x', 0), y: num(row, 'y', 0) };
    canvas.setPointerCapture(e.pointerId);
    e.preventDefault();
  });
  canvas.addEventListener('pointermove', function (e) {
    if (!drag) { return; }
    var size = pageSize();
    var snap = function (v) { return Math.round(v * 2) / 2; };
    field(drag.row, 'x').value = Math.max(0, Math.min(size[0], snap(drag.x + (e.clientX - drag.sx) / scale)));
    field(drag.row, 'y').value = Math.max(0, Math.min(size[1], snap(drag.y + (e.clientY - drag.sy) / scale)));
    render();
  });
  canvas.addEventListener('pointerup', function () { drag = null; });
  canvas.addEventListener('pointercancel', function () { drag = null; });

  render();
})();
