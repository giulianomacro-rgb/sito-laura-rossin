import assert from 'node:assert/strict';
import { readFileSync, existsSync } from 'node:fs';
import vm from 'node:vm';

const root = new URL('../', import.meta.url);
const css = readFileSync(new URL('styles.css', root), 'utf8');
// The only drawn lines are the two strokes of the carousel's open arrow.
const borders = [...css.matchAll(/\bborder(?:-(?:top|right|bottom|left))?\s*:\s*([^;]+);/g)].map(m => m[1]);
assert.deepEqual(borders.filter(value => value !== '0'), ['2px solid currentColor', '2px solid currentColor']);
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
for (const ink of ['1d6978', 'ad3020', '5f645f']) {
  for (const paper of ['f8f2e8', 'fdf9f2', 'eae0d0']) assert.ok(contrast(ink, paper) >= 4.5, `${ink}/${paper} readable`);
}
for (const color of ['e4452e', '237d8f']) assert.ok(contrast(color, 'fdf9f2') >= 3, 'Large band heading contrast');
new vm.Script(readFileSync(new URL('cms-content.js', root), 'utf8'));
console.log('OK: borderless surfaces, keyboard focus, typography, textures, text contrast, client syntax');
