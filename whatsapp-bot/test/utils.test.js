const test = require('node:test');
const assert = require('node:assert/strict');
const { normalizePhone, dedupeKey, renderTemplate, monthBounds } = require('../src/utils');

test('normalizePhone handles local Pakistani numbers', () => {
  assert.equal(normalizePhone('0300-1234567', '92'), '923001234567');
});

test('dedupeKey is stable', () => {
  assert.equal(dedupeKey(['a', 'b', 'c']), dedupeKey(['a', 'b', 'c']));
});

test('renderTemplate replaces placeholders', () => {
  assert.equal(renderTemplate('Hello {{ name }}', { name: 'Ali' }), 'Hello Ali');
});

test('monthBounds returns a previous month key', () => {
  const bounds = monthBounds(new Date('2026-05-05T00:00:00Z'));
  assert.equal(bounds.monthKey, '2026-04');
  assert.equal(bounds.start, '2026-04-01');
  assert.equal(bounds.end, '2026-04-30');
});
