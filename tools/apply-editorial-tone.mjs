import {readFile, writeFile} from 'node:fs/promises';
import assert from 'node:assert/strict';

const root = new URL('../', import.meta.url);
const site = JSON.parse(await readFile(new URL('content/site.json', root), 'utf8'));
const copy = JSON.parse(await readFile(new URL('tools/editorial-tone-2026-11-18.json', root), 'utf8'));
const before = structuredClone(site);
function merge(target, source) {
  for (const [key, value] of Object.entries(source)) {
    if (value && typeof value === 'object' && !Array.isArray(value)) merge(target[key] ??= {}, value);
    else target[key] = value;
  }
}
merge(site.home, copy.home);
merge(site.pages, copy.pages);
for (const [slug, article] of Object.entries(copy.articles)) {
  const target = site.home.topics.items.find(item => item.slug === slug);
  assert.ok(target, 'Existing article: ' + slug);
  merge(target, article);
}
assert.deepEqual(site.assets, before.assets);
for (const key of ['privacy', 'cookie']) assert.deepEqual(site.pages[key], before.pages[key]);
const articleState = s => s.home.topics.items.map(({slug, href, visible, order}) => ({slug, href, visible, order}));
assert.deepEqual(articleState(site), articleState(before), 'Publication state and URLs unchanged');
await writeFile(new URL('content/site.json', root), JSON.stringify(site, null, 2) + '\n');
console.log('Updated editorial voice; assets, legal pages, article URLs and draft states unchanged.');
