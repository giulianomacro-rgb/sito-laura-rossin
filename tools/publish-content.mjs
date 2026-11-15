import assert from 'node:assert/strict';
import fs from 'node:fs/promises';
import { execFileSync } from 'node:child_process';

const revision = process.argv[2];
if (!/^[0-9a-f]{7,40}$/.test(revision || '')) throw new Error('Pass the previous content commit hash.');
const old = JSON.parse(execFileSync('git', ['show', `${revision}:content/site.json`], {encoding:'utf8'}));
const next = JSON.parse(await fs.readFile(new URL('../content/site.json', import.meta.url), 'utf8'));
const credentials = JSON.parse((await fs.readFile(new URL('./accessi-railway.local.json', import.meta.url), 'utf8')).replace(/^\uFEFF/, ''));
const base = new URL(credentials.url);
assert.equal(base.origin, 'https://sito-laura-rossin-production.up.railway.app');
const cookies = new Map();
async function request(route, data) {
  const headers = {};
  if (cookies.size) headers.Cookie = [...cookies].map(([k,v]) => `${k}=${v}`).join('; ');
  const response = await fetch(new URL(route, base), {headers, redirect:'manual', ...(data ? {method:'POST', body:new URLSearchParams(data)} : {})});
  for (const c of response.headers.getSetCookie()) {
    const [k,...v] = c.split(';')[0].split('='); cookies.set(k, v.join('='));
  }
  return response;
}
const csrf = html => html.match(/name="csrf" value="([a-f0-9]+)"/)[1];
const entry = await (await request('/admin/')).text();
assert.equal((await request('/admin/', {action:'login', csrf:csrf(entry), username:credentials.username, password:credentials.password})).status, 302);
const read = async () => {
  const html = await (await request('/admin/index.php?section=visual')).text();
  return {token:csrf(html), content:JSON.parse(html.match(/id="visual-site-data">(.*?)<\/script>/s)[1])};
};
const current = await read();
const merged = structuredClone(current.content);
const changes = [], conflicts = [];
const same = (a,b) => JSON.stringify(a) === JSON.stringify(b);
function merge(before, after, target, keys = []) {
  if (same(before, after)) return;
  if (after && typeof after === 'object' && before && typeof before === 'object') {
    for (const key of new Set([...Object.keys(before), ...Object.keys(after)])) {
      merge(before[key], after[key], target, [...keys, key]);
    }
    return;
  }
  let parent = target;
  for (const key of keys.slice(0, -1)) parent = parent[key] ??= {};
  const key = keys.at(-1), actual = parent[key];
  if (same(actual, after)) return;
  if (!same(actual, before)) { conflicts.push(keys.join('.')); return; }
  if (after === undefined) {
    delete parent[key];
    if (Array.isArray(parent)) while (parent.length && parent.at(-1) === undefined) parent.pop();
  } else parent[key] = structuredClone(after);
  changes.push(keys.join('.'));
}
merge(old, next, merged);
console.log(JSON.stringify({changes, conflicts}, null, 2));
assert.equal(conflicts.length, 0, 'Concurrent CMS edits: review before publishing.');
if (process.env.APPLY_CONTENT === '1' && changes.length) {
  const fresh = await read();
  assert.deepEqual(fresh.content, current.content, 'CMS changed during preparation; retry.');
  await fs.writeFile(new URL('./content-before-publish.local.json', import.meta.url), JSON.stringify(current.content, null, 2));
  const saved = await request('/admin/', {action:'save_visual_content', csrf:fresh.token, site_json:JSON.stringify(merged)});
  assert.equal(saved.status, 200);
  assert.equal((await saved.json()).ok, true);
  const verified = await read();
  assert.deepEqual(verified.content, merged, 'Readback after content publication');
  console.log('Published scoped CMS changes and verified full readback.');
}
await request('/admin/', {action:'logout', csrf:current.token});
