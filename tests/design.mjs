import assert from 'node:assert/strict';
import { readFileSync, existsSync } from 'node:fs';
import vm from 'node:vm';

const root = new URL('../', import.meta.url);
const css = readFileSync(new URL('styles.css', root), 'utf8');
// Outlines belong to buttons only; the open arrow keeps its two icon strokes.
const buttonBorders = new Map([
  ['.header-cta', ['1px solid var(--red-ink)']],
  ['.button', ['1px solid var(--button-color)']],
  ['.initiative-scroll', ['1px solid currentColor']],
  ['.initiative-scroll::before', ['2px solid currentColor', '2px solid currentColor']],
  ['.booking-day, .booking-slot', ['1px solid var(--blue-ink)']],
]);
const foundBorders = new Map();
const rules = new Map();
for (const [, selectors, declarations] of css.matchAll(/([^{}]+)\{([^{}]*)\}/g)) {
  const selector = selectors.replace(/\/\*[\s\S]*?\*\//g, '').trim().replace(/\s+/g, ' ');
  rules.set(selector, (rules.get(selector) || '') + declarations);
  const borders = [...declarations.matchAll(/\bborder(?:-(?:top|right|bottom|left))?\s*:\s*([^;]+);/g)]
    .map(match => match[1].trim()).filter(value => value !== '0');
  if (!borders.length) continue;
  assert.ok(buttonBorders.has(selector), 'No decorative border on ' + selector);
  foundBorders.set(selector, borders);
}
assert.deepEqual(foundBorders, buttonBorders);
for (const selector of ['.header-cta', '.button', '.initiative-scroll', '.booking-day, .booking-slot']) {
  assert.match(rules.get(selector), /background:\s*transparent;/, selector + ' has no idle fill');
}
assert.doesNotMatch(css, /--button-surface/);
assert.match(rules.get('.contact-strip .button'), /--button-color:\s*var\(--paper-warm\);/);
assert.doesNotMatch(rules.get('.contact-strip .button'), /\bbackground(?:-color)?:/);
for (const selector of ['.button:hover, .button:focus-visible', '.header-cta:hover, .header-cta:focus-visible', '.contact-strip .button:hover, .contact-strip .button:focus-visible']) {
  assert.match(rules.get(selector), /background:\s*var\(/, selector + ' keeps an active fill');
}
assert.doesNotMatch(css, /box-shadow|text-shadow|(?:linear|radial)-gradient|rgba\(/);
assert.match(css, /:focus-visible\s*\{[^}]*outline:\s*3px/s);
for (const selector of ['h1', 'h2', 'h3']) {
  assert.match(css, new RegExp(`(?:^|\\n)${selector} \\{[^}]*font-weight: 600;`));
}
for (const file of ['carta-beige.png', 'carta-rossa.png', 'carta-blu.png', 'greg-rosenke-luce.jpg']) {
  assert.ok(existsSync(new URL(`assets/${file}`, root)), file);
}
const luminance = hex => {
  const rgb = hex.match(/[a-f\d]{2}/gi).map(c => parseInt(c, 16) / 255).map(c => c <= .04045 ? c / 12.92 : ((c + .055) / 1.055) ** 2.4);
  return rgb[0] * .2126 + rgb[1] * .7152 + rgb[2] * .0722;
};
const contrast = (a, b) => (Math.max(luminance(a), luminance(b)) + .05) / (Math.min(luminance(a), luminance(b)) + .05);
assert.match(css, /--red:\s*#e4452e;/);
assert.match(css, /--blue-deep:\s*#237d8f;/);
assert.match(css, /--red-ink:\s*var\(--red\);/);
assert.match(css, /--blue-ink:\s*var\(--blue-deep\);/);
assert.doesNotMatch(css, /#ad3020|#1d6978/i);
for (const ink of ['1d1d1b', '5f645f']) {
  for (const paper of ['f8f2e8', 'fdf9f2', 'eae0d0']) assert.ok(contrast(ink, paper) >= 4.5, `${ink}/${paper} readable`);
}
for (const color of ['e4452e', '237d8f']) assert.ok(contrast(color, 'fdf9f2') >= 3, 'Large band heading contrast');
new vm.Script(readFileSync(new URL('cms-content.js', root), 'utf8'));
console.log('OK: unfilled idle buttons, button-only borders, original brand accents, borderless surfaces, keyboard focus, typography, textures, body text contrast, client syntax');
