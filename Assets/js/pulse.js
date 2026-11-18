// Pulse heatmap: per-graph colour-mode switch (person/type/project, with its legend), arrow-key day navigation
// (one tab stop per grid) and one shared hover/focus tooltip that Escape closes from anywhere.
// Loaded on every page via template:layout:js (CSP forbids inline script), so init() no-ops without a .pulse-graph.
// Shade comes from CSS (opacity by data-level); this file only picks the hue, via the cell's --pulse-h.
(function (window, document) {
  'use strict';
  var tip = null;

  function colorFor(cell, mode, personColor) {
    if (!(Number(cell.dataset.level) > 0)) return null;
    if (mode === 'type') return cell.dataset.typeColor || personColor;
    if (mode === 'project') return cell.dataset.projectColor || personColor;
    return personColor;
  }

  function setMode(graph, mode) {
    var personColor = graph.style.getPropertyValue('--pulse-c').trim();
    var cells = graph.querySelectorAll('.pulse-cell');
    for (var i = 0; i < cells.length; i++) cells[i].style.setProperty('--pulse-h', colorFor(cells[i], mode, personColor) || '');
    var buttons = graph.querySelectorAll('[data-pulse-mode]');
    for (var j = 0; j < buttons.length; j++) {
      var on = buttons[j].dataset.pulseMode === mode;
      buttons[j].classList.toggle('active', on);
      buttons[j].setAttribute('aria-pressed', on ? 'true' : 'false');
    }
    var legends = graph.querySelectorAll('[data-pulse-legend]');
    for (var k = 0; k < legends.length; k++) legends[k].hidden = legends[k].dataset.pulseLegend !== mode;
    graph.dataset.pulseMode = mode;
  }

  // Cells flow down 7-day columns, so a day is ±1 and a week is ±7. A move past either end stays put.
  var STEPS = { ArrowUp: -1, ArrowDown: 1, ArrowLeft: -7, ArrowRight: 7 };
  function nextIndex(index, key, count) {
    if (key === 'Home') return 0;
    if (key === 'End') return count - 1;
    if (!STEPS.hasOwnProperty(key)) return null;
    var next = index + STEPS[key];
    return next < 0 || next >= count ? index : next;
  }

  // Roving tabindex: the grid is one tab stop, on the cell that last had focus.
  function rove(graph, cell) {
    var cells = graph.querySelectorAll('.pulse-cell');
    for (var i = 0; i < cells.length; i++) cells[i].setAttribute('tabindex', cells[i] === cell ? '0' : '-1');
  }

  function onArrow(graph, e) {
    var cell = cellOf(e.target);
    if (!cell) return;
    var cells = graph.querySelectorAll('.pulse-cell');
    var index = Array.prototype.indexOf.call(cells, cell);
    var next = nextIndex(index, e.key, cells.length);
    if (next === null) return;
    e.preventDefault();
    if (next !== index) cells[next].focus();
  }

  function onDocumentKeydown(e) { if (e.key === 'Escape') hideTip(); }

  function cellOf(target) {
    return target && target.classList && target.classList.contains('pulse-cell') && target.dataset.tip ? target : null;
  }

  function showTip(cell) {
    if (!tip) {
      tip = document.createElement('div');
      tip.className = 'pulse-tip';
      tip.setAttribute('role', 'tooltip');
      tip.hidden = true;
      document.body.appendChild(tip);
    }
    tip.textContent = cell.dataset.tip;
    if (tip.hidden) document.addEventListener('keydown', onDocumentKeydown);
    tip.hidden = false;
    var r = cell.getBoundingClientRect(), w = tip.offsetWidth, h = tip.offsetHeight;
    var left = Math.max(4, Math.min(r.left + r.width / 2 - w / 2, document.documentElement.clientWidth - w - 4));
    var top = r.top - h - 6 < 0 ? r.bottom + 6 : r.top - h - 6; // flip below when there is no room above
    tip.style.left = (left + window.pageXOffset) + 'px';
    tip.style.top = (top + window.pageYOffset) + 'px';
  }

  function hideTip() {
    if (!tip || tip.hidden) return;
    tip.hidden = true;
    document.removeEventListener('keydown', onDocumentKeydown);
  }

  function bind(graph) {
    if (graph.dataset.pulseBound) return;
    graph.dataset.pulseBound = '1';
    graph.addEventListener('click', function (e) {
      var button = e.target.closest && e.target.closest('[data-pulse-mode]');
      if (button) setMode(graph, button.dataset.pulseMode);
    });
    graph.addEventListener('mouseover', function (e) { var c = cellOf(e.target); if (c) showTip(c); });
    graph.addEventListener('focusin', function (e) { var c = cellOf(e.target); if (c) { rove(graph, c); showTip(c); } });
    graph.addEventListener('mouseout', function (e) { if (cellOf(e.target)) hideTip(); });
    graph.addEventListener('focusout', hideTip);
    graph.addEventListener('keydown', function (e) { onArrow(graph, e); });
  }

  function init(root) {
    var graphs = (root || document).querySelectorAll('.pulse-graph');
    for (var i = 0; i < graphs.length; i++) bind(graphs[i]);
  }

  window.KBPulse = { colorFor: colorFor, nextIndex: nextIndex, init: init };
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', function () { init(); }); else init();
})(window, document);
