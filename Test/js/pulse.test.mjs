import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

const src = readFileSync(new URL('../../Assets/js/pulse.js', import.meta.url), 'utf8');
// A fake document: records listeners, hands out fake elements (their innerHTML setter throws, so any
// HTML parsing of tooltip text fails the test) and keeps the last tooltip it created.
const doc = {
  readyState: 'loading', listeners: {}, created: [],
  addEventListener(type, fn) { (this.listeners[type] ||= []).push(fn); },
  removeEventListener(type, fn) { this.listeners[type] = (this.listeners[type] || []).filter((f) => f !== fn); },
  fire(type, ev) { (this.listeners[type] || []).slice().forEach((fn) => fn(ev)); },
  createElement() {
    const node = el({ offsetWidth: 100, offsetHeight: 20, hidden: false });
    Object.defineProperty(node, 'innerHTML', { set() { throw new Error('innerHTML must not be assigned'); } });
    this.created.push(node);
    return node;
  },
  body: { appendChild() {} },
  documentElement: { clientWidth: 1000 },
};
const win = { pageXOffset: 0, pageYOffset: 0 };
new Function('window', 'document', src)(win, doc);
const P = win.KBPulse;
const lastTip = () => doc.created[doc.created.length - 1];

const cell = (level, typeColor = '#e11d48', projectColor = '#0891b2') =>
  ({ dataset: { level: String(level), typeColor, projectColor } });

test('person mode uses the person colour', () => {
  assert.equal(P.colorFor(cell(2), 'person', '#2563eb'), '#2563eb');
});
test('type mode uses the cell type colour', () => {
  assert.equal(P.colorFor(cell(3), 'type', '#2563eb'), '#e11d48');
});
test('project mode uses the cell project colour', () => {
  assert.equal(P.colorFor(cell(4), 'project', '#2563eb'), '#0891b2');
});
test('an empty day has no colour in any mode', () => {
  for (const mode of ['person', 'type', 'project']) assert.equal(P.colorFor(cell(0), mode, '#2563eb'), null);
});
test('an unknown mode falls back to the person colour', () => {
  assert.equal(P.colorFor(cell(1), 'banana', '#2563eb'), '#2563eb');
});

// Minimal DOM fakes: enough for init() to bind a graph and react to a mode click.
function el(props = {}) {
  const handlers = {}, classes = new Set(props.classes || []), attrs = {};
  return Object.assign({
    dataset: {}, style: { setProperty(k, v) { this[k] = v; }, getPropertyValue(k) { return this[k] || ''; } },
    classList: { add: (c) => classes.add(c), remove: (c) => classes.delete(c), contains: (c) => classes.has(c), toggle: (c, on) => (on ? classes.add(c) : classes.delete(c)) },
    setAttribute: (k, v) => { attrs[k] = String(v); }, getAttribute: (k) => attrs[k] ?? null,
    addEventListener: (type, fn) => { (handlers[type] ||= []).push(fn); },
    fire(type, ev) { (handlers[type] || []).forEach((fn) => fn(ev)); },
    closest() { return null; }, handlers,
  }, props);
}

test('init does nothing on a page without graphs', () => {
  let asked = 0;
  P.init({ querySelectorAll: () => { asked++; return []; } });
  assert.equal(asked, 1);
});

test('a mode click recolours the cells and marks only that button pressed', () => {
  const cells = [el({ dataset: { level: '2', typeColor: '#e11d48', projectColor: '#0891b2' } }), el({ dataset: { level: '0', typeColor: '', projectColor: '' } })];
  const buttons = ['person', 'type', 'project'].map((m) => el({ dataset: { pulseMode: m } }));
  const legends = ['person', 'type', 'project'].map((m) => el({ dataset: { pulseLegend: m }, hidden: m !== 'person' }));
  const graph = el({ querySelectorAll: (sel) => ({ '.pulse-cell': cells, '[data-pulse-mode]': buttons, '[data-pulse-legend]': legends })[sel] || [] });
  graph.style['--pulse-c'] = '#2563eb';
  buttons.forEach((b) => { b.closest = (sel) => (sel === '[data-pulse-mode]' ? b : null); });
  P.init({ querySelectorAll: () => [graph] });

  graph.fire('click', { target: buttons[1] });
  assert.equal(cells[0].style['--pulse-h'], '#e11d48');
  assert.equal(cells[1].style['--pulse-h'], '');
  assert.equal(cells[0].style.backgroundColor, undefined, 'the hue goes through --pulse-h, the cell keeps its empty background');
  assert.equal(graph.dataset.pulseMode, 'type');
  assert.deepEqual(buttons.map((b) => b.getAttribute('aria-pressed')), ['false', 'true', 'false']);
  assert.deepEqual(buttons.map((b) => b.classList.contains('active')), [false, true, false]);
  assert.deepEqual(legends.map((l) => l.hidden), [true, false, true], 'only the type legend shows');

  graph.fire('click', { target: buttons[0] });
  assert.equal(cells[0].style['--pulse-h'], '#2563eb');
  assert.deepEqual(legends.map((l) => l.hidden), [false, true, true]);
});

test('init binds a graph only once', () => {
  const graph = el({ querySelectorAll: () => [] });
  P.init({ querySelectorAll: () => [graph] });
  P.init({ querySelectorAll: () => [graph] });
  assert.equal(graph.handlers.click.length, 1);
});

// A graph of n day cells, each focusable: focus() fires focusin on the graph, like the real DOM.
function gridGraph(n, tips = []) {
  const graph = el();
  const cells = Array.from({ length: n }, (_, i) => {
    const c = el({ classes: ['pulse-cell'], dataset: { level: '1', tip: tips[i] || 'day ' + i }, getBoundingClientRect: () => ({ left: 0, top: 50, width: 11, bottom: 61 }) });
    c.setAttribute('tabindex', i === n - 1 ? '0' : '-1');
    c.focus = () => { graph.focused = i; graph.fire('focusin', { target: c }); };
    return c;
  });
  graph.querySelectorAll = (sel) => (sel === '.pulse-cell' ? cells : []);
  P.init({ querySelectorAll: () => [graph] });
  return { graph, cells };
}
const key = (target, k) => { const ev = { key: k, target, prevented: false, preventDefault() { this.prevented = true; } }; return ev; };

test('arrow keys step a day up and down and a week left and right; Home and End jump to the ends', () => {
  assert.equal(P.nextIndex(10, 'ArrowUp', 30), 9);
  assert.equal(P.nextIndex(10, 'ArrowDown', 30), 11);
  assert.equal(P.nextIndex(10, 'ArrowLeft', 30), 3);
  assert.equal(P.nextIndex(10, 'ArrowRight', 30), 17);
  assert.equal(P.nextIndex(10, 'Home', 30), 0);
  assert.equal(P.nextIndex(10, 'End', 30), 29);
});
test('a move past either end stays on the cell; other keys are not handled', () => {
  assert.equal(P.nextIndex(0, 'ArrowUp', 30), 0);
  assert.equal(P.nextIndex(3, 'ArrowLeft', 30), 3);
  assert.equal(P.nextIndex(29, 'ArrowDown', 30), 29);
  assert.equal(P.nextIndex(25, 'ArrowRight', 30), 25);
  assert.equal(P.nextIndex(10, 'a', 30), null);
  assert.equal(P.nextIndex(10, 'Tab', 30), null);
});

test('arrows move focus, the single tab stop follows, and the tooltip shows the focused day', () => {
  const { graph, cells } = gridGraph(20);
  const tabStops = () => cells.map((c, i) => (c.getAttribute('tabindex') === '0' ? i : null)).filter((i) => i !== null);

  const ev = key(cells[19], 'ArrowLeft');
  graph.fire('keydown', ev);
  assert.equal(ev.prevented, true);
  assert.equal(graph.focused, 12);
  assert.deepEqual(tabStops(), [12]);
  assert.equal(lastTip().textContent, 'day 12');
  assert.equal(lastTip().hidden, false);

  graph.fire('keydown', key(cells[12], 'ArrowUp'));
  assert.deepEqual(tabStops(), [11]);
  graph.fire('keydown', key(cells[11], 'Home'));
  assert.deepEqual(tabStops(), [0]);
  assert.equal(lastTip().textContent, 'day 0');

  const other = key(cells[0], 'x');
  graph.fire('keydown', other);
  assert.equal(other.prevented, false);
  assert.deepEqual(tabStops(), [0]);
});

test('Escape anywhere hides the open tooltip, and the document listener goes with it', () => {
  const { graph, cells } = gridGraph(3);
  graph.fire('focusin', { target: cells[1] });
  assert.equal(lastTip().hidden, false);
  assert.equal(doc.listeners.keydown.length, 1);

  doc.fire('keydown', { key: 'Enter' });
  assert.equal(lastTip().hidden, false);
  doc.fire('keydown', { key: 'Escape' });
  assert.equal(lastTip().hidden, true);
  assert.equal(doc.listeners.keydown.length, 0);
});

test('tooltip text is set as text, never parsed as HTML', () => {
  const payload = '<img src=x onerror=alert(1)>';
  const { graph, cells } = gridGraph(2, [payload]);
  graph.fire('focusin', { target: cells[0] });
  assert.equal(lastTip().textContent, payload);
});
