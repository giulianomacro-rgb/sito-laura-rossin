import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import vm from 'node:vm';

const source = readFileSync(new URL('../admin/index.php', import.meta.url), 'utf8');
const scripts = [...source.matchAll(/<script>([\s\S]*?)<\/script>/g)];
assert.equal(scripts.length, 1);
new vm.Script(scripts[0][1], {filename: 'cms-inline.js'});
const start = source.indexOf('const visibleIndexes =');
const end = source.indexOf('const imageHelpFor =', start);
assert.ok(start > 0 && end > start);
const rows = [
  { visible: false, order: 0 }, { visible: true, order: 3 },
  { visible: '0', order: 1 }, { visible: true, order: 1 },
  { visible: 0, order: 2 }, { order: 1 },
];
const actual = vm.runInNewContext(source.slice(start, end) + 'visibleIndexes(rows)', {rows});
assert.equal(JSON.stringify(actual), '[3,5,1]', 'Editor uses source indexes after filtering and sorting');
console.log('OK: CMS script syntax and filtered/sorted editor mapping');
