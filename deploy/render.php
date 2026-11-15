<?php
declare(strict_types=1);

$root = is_dir(__DIR__ . '/lib') ? __DIR__ : dirname(__DIR__);
require_once $root . '/lib/runtime.php';
require_once $root . '/lib/seo.php';
require_once $root . '/lib/page-renderer.php';

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$siteJson = file_get_contents(site_content_file());
header('Cache-Control: no-cache');
$site = json_decode($siteJson ?: '{}', true);
if (!is_array($site) || empty($site['home'])) {
    http_response_code(503);
    exit('Sito momentaneamente non disponibile.');
}
if ($path === '/robots.txt') {
    header('Content-Type: text/plain; charset=utf-8');
    echo "User-agent: *\nAllow: /\nDisallow: /admin/\nDisallow: /api/\n\nSitemap: https://www.laurarossin.it/sitemap.xml\n";
    exit;
}
if ($path === '/sitemap.xml') {
    header('Content-Type: application/xml; charset=utf-8');
    echo '<?xml version="1.0" encoding="UTF-8"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';
    $urls = ['/', '/prenotazioni.html'];
    foreach (public_pages() as $route => $key) {
        if (isset($site['pages'][$key]) && visible_content($site['pages'][$key]['published'] ?? true) && !in_array($key, ['privacy','cookie'], true)) $urls[] = $route;
    }
    foreach ($site['home']['topics']['items'] ?? [] as $item) {
        if (visible_content($item['visible'] ?? true) && !empty($item['slug'])) $urls[] = '/articolo.html?slug=' . rawurlencode($item['slug']);
    }
    foreach (array_unique($urls) as $route) echo '<url><loc>' . html_text('https://www.laurarossin.it' . $route) . '</loc></url>';
    echo '</urlset>';
    exit;
}

if ($path === '/content/site.json') {
    header('Content-Type: application/json; charset=utf-8');
    foreach ($site['pages'] ?? [] as $key => $page) if (!visible_content($page['published'] ?? true)) $site['pages'][$key] = ['published' => false];
    $site['home']['topics']['items'] = array_values(array_filter($site['home']['topics']['items'] ?? [], static fn ($item) => visible_content($item['visible'] ?? true)));
    echo json_encode($site, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$pages = public_pages();
$pages['/index.html'] = 'home';
$path = $path === '/' ? '/index.html' : $path;
if (!is_string($path) || !array_key_exists($path, $pages)) {
    http_response_code(404);
    exit('Pagina non trovata.');
}

$key = $pages[$path];
$seo = $key === 'home' ? ($site['home']['seo'] ?? []) : ($site['pages'][$key]['seo'] ?? []);
$ogImage = $site['assets']['ogImage'] ?? '';
$html = (string) file_get_contents($root . $path);
$article = null;
if ($key !== 'home' && isset($site['pages'][$key]) && !visible_content($site['pages'][$key]['published'] ?? true)) {
    http_response_code(404);
    header('X-Robots-Tag: noindex');
    exit('Pagina non disponibile.');
}
if ($path === '/articolo.html') {
    $slug = $_GET['slug'] ?? '';
    $article = null;
    foreach ($site['home']['topics']['items'] ?? [] as $item) {
        if ($slug !== '' && ($item['slug'] ?? '') === $slug && !in_array($item['visible'] ?? true, [false, 0, '0'], true)) {
            $article = $item;
            break;
        }
    }
    if ($article === null) {
        http_response_code(404);
        header('X-Robots-Tag: noindex');
        exit('Articolo non disponibile.');
    } else {
        $seo = ['title' => ($article['title'] ?? $article['label']) . ' | Laura Rossin', 'description' => $article['excerpt'] ?? ''];
        $ogImage = ($article['socialImage'] ?? '') ?: $ogImage;
        $html = str_replace('href="https://www.laurarossin.it/articolo.html"', 'href="https://www.laurarossin.it/articolo.html?slug=' . rawurlencode($slug) . '"', $html);
    }
}
if (is_string($ogImage) && preg_match('~^/?assets/[^\s]+$~', $ogImage)) {
    $ogImage = 'https://www.laurarossin.it/' . ltrim($ogImage, '/');
}
if (!is_string($ogImage) || !str_starts_with($ogImage, 'https://')) {
    $ogImage = '';
}
header('Content-Type: text/html; charset=utf-8');
if (in_array($key, ['privacy','cookie'], true)) header('X-Robots-Tag: noindex');
$html = render_site_page($html, $site, $path === '/index.html' ? '/' : $path, $article);
echo apply_site_seo($html, is_array($seo) ? $seo : [], $ogImage);
