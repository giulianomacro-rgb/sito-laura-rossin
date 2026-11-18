<?php
declare(strict_types=1);
require dirname(__DIR__) . '/lib/page-renderer.php';
require dirname(__DIR__) . '/lib/seo.php';
$root = dirname(__DIR__);
$site = json_decode(file_get_contents($root . '/content/site.json'), true, 512, JSON_THROW_ON_ERROR);
foreach (public_pages() as $path => $key) {
    if (in_array($key, ['privacy', 'cookie', 'booking', 'article'], true)) continue;
    $file = $root . ($path === '/' ? '/index.html' : $path);
    $doc = Dom\HTMLDocument::createFromString(file_get_contents($file), LIBXML_NOERROR, 'UTF-8');
    if (in_array($key, ['home', 'iniziative'], true)) {
        // Only Mente Adolescente is an initiative; the other two cards duplicate services.
        $carousel = $doc->querySelector('.initiatives-carousel');
        if ($carousel) $carousel->replaceWith($carousel->querySelector('.initiative-panel'));
    }
    if ($key === 'home') {
        $main = $doc->querySelector('main');
        foreach (['.hero', '.intro-band', '#metodo', '#servizi', '#faq', '.resources-section', '#iniziative', '.image-band', '.contact-section', '.contact-details'] as $selector) {
            $main->appendChild($doc->querySelector($selector));
        }
    }
    $html = render_site_page($doc->saveHtml(), $site, $path);
    $html = apply_site_seo($html, $key === 'home' ? $site['home']['seo'] : $site['pages'][$key]['seo']);
    $doc = Dom\HTMLDocument::createFromString($html, LIBXML_NOERROR, 'UTF-8');
    // These are fallback templates, not a frozen response: the CMS still controls rendering.
    $doc->documentElement->removeAttribute('data-server-rendered');
    $doc->documentElement->removeAttribute('style');
    foreach ($doc->querySelectorAll('link[rel="stylesheet"], script[src]') as $asset) {
        $attribute = $asset->tagName === 'LINK' || $asset->tagName === 'link' ? 'href' : 'src';
        $url = $asset->getAttribute($attribute);
        if (str_contains($url, '?v=')) $asset->setAttribute($attribute, parse_url($url, PHP_URL_PATH));
    }
    $formatted = preg_replace('/[\t ]+$/m', '', $doc->saveHtml());
    $formatted = preg_replace('/\n{3,}/', "\n\n", $formatted);
    file_put_contents($file, rtrim($formatted) . "\n");
    echo "Synced $path\n";
}
