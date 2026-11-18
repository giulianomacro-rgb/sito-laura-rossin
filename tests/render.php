<?php
declare(strict_types=1);
require dirname(__DIR__) . '/lib/page-renderer.php';
require dirname(__DIR__) . '/lib/seo.php';
$root = dirname(__DIR__);
$site = json_decode(file_get_contents($root . '/content/site.json'), true, 512, JSON_THROW_ON_ERROR);
function check(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}
foreach (public_pages() as $path => $key) {
    $article = $key === 'article' ? $site['home']['topics']['items'][0] : null;
    $html = render_site_page(file_get_contents($root . ($path === '/' ? '/index.html' : $path)), $site, $path, $article);
    $doc = Dom\HTMLDocument::createFromString($html, LIBXML_NOERROR, 'UTF-8');
    check($doc->querySelectorAll('h1')->length === 1, "One h1: $path");
    check($doc->documentElement->getAttribute('data-server-rendered') === 'true', "SSR: $path");
    $stylesheet = $doc->querySelector('link[rel="stylesheet"]')->getAttribute('href');
    check(str_ends_with($stylesheet, '?v=' . substr(hash_file('sha256', $root . '/styles.css'), 0, 12)), 'Stylesheet tracks release: ' . $path);
    foreach ($doc->querySelectorAll('script[src]') as $script) {
        $file = basename(parse_url($script->getAttribute('src'), PHP_URL_PATH));
        if (in_array($file, ['cms-content.js', 'prenotazioni.js'], true)) check(str_contains($script->getAttribute('src'), '?v='), 'Client script tracks release');
    }
    check($doc->querySelectorAll('script[type="application/ld+json"]')->length === 1, "No duplicate schema: $path");
    json_decode($doc->querySelector('script[type="application/ld+json"]')->textContent, true, 512, JSON_THROW_ON_ERROR);
    foreach ($doc->querySelectorAll('.site-nav a') as $nav) {
        check(!str_contains($nav->getAttribute('href'), '#'), 'Menu goes to pages: ' . $path);
    }
    if (in_array($key, ['servizi','metodo','iniziative','contatti'], true)) {
        check($doc->querySelectorAll('.site-nav [aria-current="page"]')->length === 1, 'Current menu page: ' . $path);
        foreach (preg_split('/\R+/u', $site['pages'][$key]['sections'][0]['text']) as $paragraph) {
            check(str_contains($doc->body->textContent, $paragraph), 'Dedicated CMS paragraph: ' . $path);
        }
    }
    if (in_array($key, ['home', 'servizi'], true)) {
        $images = [];
        foreach ($doc->querySelectorAll('.service-illustration') as $img) $images[] = $img->getAttribute('src');
        check(count(array_unique($images)) === 3, 'Distinct service illustrations');
    }
    if ($key === 'home') {
        foreach (['.intro-band', '.contact-section'] as $selector) {
            $band = $doc->querySelector($selector);
            check($band->querySelectorAll('h2')->length === 1, 'One heading in band');
            check($band->querySelectorAll('p, address, img')->length === 0, 'Heading-only colored band');
        }
        check($doc->querySelector('.intro-band h2')->textContent === $site['home']['intro']['title'], 'Keep original CMS heading');
        check($doc->querySelector('.contact-details .contact-card') !== null, 'Contacts on light background');
        check(str_contains($doc->querySelector('.contact-details')->textContent, $site['home']['contact']['text']), 'Contact paragraph preserved');
        check($doc->querySelector('.image-grid img')->getAttribute('src') === $site['home']['gallery'][0]['src'], 'Gallery source');
        check($doc->querySelector('.image-grid figcaption a')->getAttribute('href') === $site['home']['gallery'][0]['creditUrl'], 'Photo credit link');
        check($doc->querySelector('.image-band')->getAttribute('aria-label') !== 'Studio professionale', 'Stock photo is not the studio');
        $links = $doc->querySelectorAll('.initiative-panel .button');
        check($links->item(0)->getAttribute('href') === $site['home']['initiative']['href'], 'CMS initiative link');
        check($links->length === 1, 'Only actual initiative, no duplicated services');
        check($doc->querySelector('.initiative-controls') === null, 'No empty carousel controls');
        $sections = [];
        foreach ($doc->querySelectorAll('main > section') as $section) $sections[] = $section->getAttribute('id') ?: $section->getAttribute('class');
        check(array_search('metodo', $sections) < array_search('servizi', $sections), 'Approach before service choice');
        check(array_search('faq', $sections) < array_search('iniziative', $sections), 'First appointment before group projects');
        check($doc->querySelector('.hero-actions .button-secondary')->getAttribute('href') === 'metodo.html', 'Primary orientation link explains approach');
        foreach ($site['home']['topics']['items'] as $topic) {
            if (!$topic['visible']) check(!str_contains($html, $topic['slug']), 'Draft not in home: ' . $topic['slug']);
        }
    }
    if ($article) {
        check(str_contains($doc->querySelector('link[rel="canonical"]')->getAttribute('href'), '?slug=' . $article['slug']), 'Article canonical');
        check(str_contains($doc->body->textContent, $article['sections'][0]['text']), 'Article readable without JS');
        check(!str_contains($doc->body->textContent, 'Contenuto in caricamento'), 'No loading placeholder');
        check($doc->querySelector('.article-hero .button')->getAttribute('href') === 'index.html#risorse-title', 'Article returns to reading list');
        check(str_contains($doc->querySelector('.side-note')->textContent, 'orientamento generale'), 'Informational scope clear');
    }
}
$site['home']['hero']['title'] = 'CMS: <script>alert(1)</script> & prova';
$site['home']['gallery'][0]['credit'] = '<script>credit</script>';
$site['home']['gallery'][0]['creditUrl'] = 'javascript:alert(1)';
$html = render_site_page(file_get_contents($root . '/index.html'), $site, '/');
check(str_contains($html, '&lt;script&gt;'), 'CMS text escaped');
check(!str_contains($html, '<script>alert(1)'), 'No script injection');
check(str_contains($html, '&lt;script&gt;credit&lt;/script&gt;'), 'Photo credit escaped');
$doc = Dom\HTMLDocument::createFromString($html, LIBXML_NOERROR, 'UTF-8');
check($doc->querySelector('.image-grid figcaption a') === null, 'Unsafe photo credit URL rejected');
$site['home']['gallery'][0]['visible'] = false;
$html = render_site_page(file_get_contents($root . '/index.html'), $site, '/');
$doc = Dom\HTMLDocument::createFromString($html, LIBXML_NOERROR, 'UTF-8');
check($doc->querySelector('.image-band')->hasAttribute('hidden'), 'Hidden gallery has no empty band');
check(safe_site_url('javascript:alert(1)') === '', 'Reject unsafe URL');
$seo = apply_site_seo('<head><title>Old</title><meta name="description" content="old"></head>', ['title' => 'Test $1 & <script>', 'description' => 'New']);
check(str_contains($seo, 'Test $1 &amp; &lt;script&gt;'), 'SEO escaping and literal dollar');
echo 'OK: ' . count(public_pages()) . " page routes, navigation, illustrations, schema, article SSR, CMS escaping, metadata\n";
