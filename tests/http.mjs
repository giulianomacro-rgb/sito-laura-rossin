import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';

const base = new URL(process.env.TEST_SITE_URL || 'http://127.0.0.1:4180');
const cookies = new Map();
async function request(path, options = {}) {
  const headers = new Headers(options.headers);
  if (cookies.size) headers.set('Cookie', [...cookies].map(([k, v]) => `${k}=${v}`).join('; '));
  const response = await fetch(new URL(path, base), { ...options, headers, redirect: 'manual' });
  for (const value of response.headers.getSetCookie()) {
    const [name, ...parts] = value.split(';')[0].split('=');
    cookies.set(name, parts.join('='));
  }
  return response;
}
function csrf(html) {
  const match = html.match(/name="csrf" value="([a-f0-9]+)"/);
  assert.ok(match, 'CSRF token in form');
  return match[1];
}
const homepage = await request('/');
assert.equal(homepage.status, 200, 'Homepage');
const homeHtml = await homepage.text();
assert.match(homeHtml, /data-server-rendered="true"/);
assert.equal((homeHtml.match(/<h1\b/g) || []).length, 1);
const schema = JSON.parse(homeHtml.match(/<script type="application\/ld\+json">(.*?)<\/script>/s)[1]);
assert.ok(schema['@graph'].some(item => item['@type'] === 'Person'));
if (base.protocol === 'https:') {
  assert.match(homepage.headers.get('x-robots-tag') || '', /noindex/, 'Preview stays unindexed');
  for (const path of ['/admin/config.php', '/admin/google-calendar-config.php', '/lib/runtime.php', '/data/site.json', '/.git/config']) {
    assert.ok([403, 404].includes((await request(path)).status), `Private path protected: ${path}`);
  }
}
const content = await (await request('/content/site.json')).json();
const routes = ['/servizi.html', '/metodo.html', '/iniziative.html', '/contatti.html', '/privacy-policy.html', '/cookie-policy.html', '/prenotazioni.html', '/sitemap.xml', '/robots.txt', '/healthz'];
for (const item of content.home.services.items) routes.push(`/${item.href}`);
for (const item of content.home.topics.items) routes.push(`/articolo.html?slug=${encodeURIComponent(item.slug)}`);
for (const route of routes) assert.equal((await request(route)).status, 200, route);
assert.equal((await request('/articolo.html?slug=non-esiste-audit')).status, 404);
assert.equal((await request('/inesistente-audit.html')).status, 404);
assert.equal((await request('/api/prenotazioni.php')).status, 503, 'Bookings disabled');
console.log(`OK: homepage SSR, schema, ${routes.length} public routes, 404, bookings disabled`);

if (process.env.CMS_TEST_CREDENTIALS) {
  const credentials = JSON.parse((await readFile(process.env.CMS_TEST_CREDENTIALS, 'utf8')).replace(/^\uFEFF/, ''));
  if (!['localhost', '127.0.0.1'].includes(base.hostname)) assert.equal(new URL(credentials.url).origin, base.origin, 'Credential destination');
  const entry = await request('/admin/');
  assert.match(entry.headers.get('cache-control') || '', /no-store/);
  const sessionCookie = entry.headers.getSetCookie().find(value => value.startsWith('PHPSESSID='));
  assert.match(sessionCookie || '', /HttpOnly/i);
  assert.match(sessionCookie || '', /SameSite=Lax/i);
  if (base.protocol === 'https:') assert.match(sessionCookie || '', /Secure/i);
  let html = await entry.text();
  assert.match(html, /name="password"/);
  const login = await request('/admin/', {
    method: 'POST', body: new URLSearchParams({action: 'login', csrf: csrf(html), username: credentials.username, password: credentials.password}),
  });
  assert.equal(login.status, 302, 'CMS login');
  html = await (await request('/admin/index.php?section=visual')).text();
  const token = csrf(html);
  assert.match(html, /id="visual-site-data"/);
  const original = JSON.parse(html.match(/id="visual-site-data">(.*?)<\/script>/s)[1]);
  const denied = await request('/admin/', {method: 'POST', body: new URLSearchParams({action: 'save_visual_content', csrf: 'invalid', site_json: '{}'})});
  assert.equal(denied.status, 403, 'Reject invalid CSRF');
  if (process.env.CMS_TEST_WRITE === '1') {
    // Save the unchanged authenticated payload, never the filtered public JSON.
    const saved = await request('/admin/', {method: 'POST', body: new URLSearchParams({action: 'save_visual_content', csrf: token, site_json: JSON.stringify(original)})});
    assert.equal(saved.status, 200, 'CMS save');
    assert.equal((await saved.json()).ok, true);
    const afterHtml = await (await request('/admin/index.php?section=visual')).text();
    const after = JSON.parse(afterHtml.match(/id="visual-site-data">(.*?)<\/script>/s)[1]);
    assert.deepEqual(after, original, 'Unchanged content round trip');
    const invalidUpload = new FormData();
    invalidUpload.set('action', 'upload_visual_image');
    invalidUpload.set('csrf', token);
    invalidUpload.set('visual_image', new Blob(['<svg xmlns="http://www.w3.org/2000/svg"/>'], {type: 'image/svg+xml'}), 'audit.svg');
    assert.equal((await request('/admin/', {method: 'POST', body: invalidUpload})).status, 422, 'Reject active image format');
    const upload = new FormData();
    upload.set('action', 'upload_visual_image');
    upload.set('csrf', token);
    upload.set('label', 'Audit tecnico');
    upload.set('visual_image', new Blob([await readFile(new URL('../assets/og-laura-rossin.jpg', import.meta.url))], {type: 'image/jpeg'}), 'audit-tecnico.jpg');
    const image = await (await request('/admin/', {method: 'POST', body: upload})).json();
    assert.equal(image.ok, true, 'CMS image upload');
    assert.equal((await request(`/${image.path}`)).status, 200, 'Uploaded image readable');
    console.log(`Uploaded test image: ${image.path}`);
    console.log('OK: unchanged-content save and readback, valid JPEG upload, SVG rejected');
  }
  const logout = await request('/admin/', {method: 'POST', body: new URLSearchParams({action: 'logout', csrf: token})});
  assert.equal(logout.status, 302);
  assert.match(await (await request('/admin/')).text(), /name="password"/);
  const expired = await request('/admin/', {method: 'POST', body: new URLSearchParams({action: 'save_visual_content', csrf: token, site_json: '{}'})});
  assert.equal(expired.status, 401, 'Expired session cannot save');
  assert.equal((await expired.json()).ok, false);
  console.log('OK: CMS login, CSRF protection, logout');
}
