import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

const src = readFileSync(new URL('../../Assets/js/pulse.js', import.meta.url), 'utf8');
const win = {};
new Function('window', 'document', src)(win, { readyState: 'loading', addEventListener() {} });
const P = win.KBPulse;

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
  const graph = el({ querySelectorAll: (sel) => (sel === '.pulse-cell' ? cells : buttons) });
  graph.style['--pulse-c'] = '#2563eb';
  buttons.forEach((b) => { b.closest = (sel) => (sel === '[data-pulse-mode]' ? b : null); });
  P.init({ querySelectorAll: () => [graph] });

  graph.fire('click', { target: buttons[1] });
  assert.equal(cells[0].style.backgroundColor, '#e11d48');
  assert.equal(cells[1].style.backgroundColor, '');
  assert.equal(graph.dataset.pulseMode, 'type');
  assert.deepEqual(buttons.map((b) => b.getAttribute('aria-pressed')), ['false', 'true', 'false']);
  assert.deepEqual(buttons.map((b) => b.classList.contains('active')), [false, true, false]);

  graph.fire('click', { target: buttons[0] });
  assert.equal(cells[0].style.backgroundColor, '#2563eb');
});

test('init binds a graph only once', () => {
  const graph = el({ querySelectorAll: () => [] });
  P.init({ querySelectorAll: () => [graph] });
  P.init({ querySelectorAll: () => [graph] });
  assert.equal(graph.handlers.click.length, 1);
});
