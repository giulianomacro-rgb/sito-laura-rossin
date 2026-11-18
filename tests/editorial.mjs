import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import vm from 'node:vm';

const read = name => readFileSync(new URL('../' + name, import.meta.url), 'utf8');
const site = JSON.parse(read('content/site.json'));
const topics = site.home.topics.items;
const drafts = topics.filter(item => !item.visible);
assert.equal(drafts.length, 3);
assert.equal(topics.filter(item => item.visible).length, 7);
assert.equal(new Set(topics.map(item => item.slug)).size, topics.length);
for (const item of topics) {
  assert.ok(item.title && item.excerpt && item.sections.length >= 3);
  assert.equal(item.href, 'articolo.html?slug=' + item.slug);
}
assert.equal(site.home.hero.secondaryHref, 'metodo.html');
assert.equal(site.home.services.items[0].href, 'servizi/psicoterapia-breve-strategica-bolzano.html');
assert.equal(site.home.faq.items.length, 6);
const admin = read('admin/index.php');
assert.ok(admin.includes("render_topic_item(['visible' => false"), 'New articles default to draft');
const start = admin.indexOf('const addTopicOptions =');
const end = admin.indexOf('const pushEditable =', start);
const select = {dataset: {}, options: [], appendChild(option) {this.options.push(option);}};
vm.runInNewContext(admin.slice(start, end) + 'addTopicOptions();', {
  visualSite: site, pageSelect: select,
  document: {createElement() {return {dataset: {}};}}
});
assert.equal(select.options.length, 7, 'Only public articles in visual preview');
for (const draft of drafts) assert.ok(!select.options.some(o => o.dataset.url.includes(draft.slug)));
for (const name of ['index.html', 'servizi.html', 'metodo.html', 'iniziative.html', 'contatti.html']) {
  assert.ok(!read(name).includes('data-server-rendered'), 'Fallback stays CMS editable');
}
assert.ok(!read('prenotazioni.html').includes('Scegli un giorno e un orario disponibili per il primo contatto'));
console.log('OK: editorial structure, 7 existing articles, 3 drafts, CMS draft defaults and preview filtering');
