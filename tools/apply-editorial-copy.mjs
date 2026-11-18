import {readFile,writeFile} from 'node:fs/promises';
import assert from 'node:assert/strict';
const root=new URL('../',import.meta.url);
const site=JSON.parse(await readFile(new URL('content/site.json',root),'utf8'));
const revision=JSON.parse(await readFile(new URL('tools/editorial-2026-11-18.json',root),'utf8'));
function merge(target,source) {
  for(const [key,value] of Object.entries(source)) {
    if(value && typeof value==='object' && !Array.isArray(value)) merge(target[key]??={},value);
    else target[key]=value;
  }
}
merge(site.home,revision.home);
merge(site.pages,revision.pages);
for(const [slug,article] of Object.entries(revision.articles)) {
  const target=site.home.topics.items.find(item=>item.slug===slug);
  assert.ok(target,slug);merge(target,article);
}
for(const draft of revision.drafts) {
  assert.ok(!site.home.topics.items.some(item=>item.slug===draft.slug),'Draft already exists');
  site.home.topics.items.push({visible:false,order:site.home.topics.items.length+1,label:draft.title,slug:draft.slug,href:'articolo.html?slug='+draft.slug,title:draft.title,excerpt:draft.excerpt,socialImage:site.assets.ogImage,heroImage:site.assets.articleIllustration,sections:draft.sections});
}
await writeFile(new URL('content/site.json',root),JSON.stringify(site,null,2)+'\n');
console.log('Updated editorial content; seven existing slugs retained and three unpublished drafts added.');
