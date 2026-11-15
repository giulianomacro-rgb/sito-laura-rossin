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
    check($doc->querySelectorAll('script[type="application/ld+json"]')->length === 1, "No duplicate schema: $path");
    json_decode($doc->querySelector('script[type="application/ld+json"]')->textContent, true, 512, JSON_THROW_ON_ERROR);
    if ($article) {
        check(str_contains($doc->querySelector('link[rel="canonical"]')->getAttribute('href'), '?slug=' . $article['slug']), 'Article canonical');
        check(str_contains($doc->body->textContent, $article['sections'][0]['text']), 'Article readable without JS');
        check(!str_contains($doc->body->textContent, 'Contenuto in caricamento'), 'No loading placeholder');
    }
}
$site['home']['hero']['title'] = 'CMS: <script>alert(1)</script> & prova';
$html = render_site_page(file_get_contents($root . '/index.html'), $site, '/');
check(str_contains($html, '&lt;script&gt;'), 'CMS text escaped');
check(!str_contains($html, '<script>alert(1)'), 'No script injection');
check(safe_site_url('javascript:alert(1)') === '', 'Reject unsafe URL');
$seo = apply_site_seo('<head><title>Old</title><meta name="description" content="old"></head>', ['title' => 'Test $1 & <script>', 'description' => 'New']);
check(str_contains($seo, 'Test $1 &amp; &lt;script&gt;'), 'SEO escaping and literal dollar');
echo "OK: 9 page routes, schema, article SSR, CMS escaping, metadata\n";
