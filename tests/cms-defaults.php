<?php
declare(strict_types=1);
$root = dirname(__DIR__);
// Load pure content helpers without invoking the authenticated HTTP controller.
$source = file_get_contents($root . '/admin/index.php');
$start = strpos($source, 'function page_defaults()');
$end = strpos($source, 'function normalize_services(', $start);
eval(substr($source, $start, $end - $start));
function verify(bool $ok, string $label): void {
    if (!$ok) throw new RuntimeException($label);
}
$site = json_decode(file_get_contents($root . '/content/site.json'), true, 512, JSON_THROW_ON_ERROR);
verify(ensure_site_defaults($site) === $site, 'Complete content is unchanged');
$site['pages']['psicoterapia']['sections'] = [
    ['title' => 'Testo salvato', 'text' => 'Contenuto modificato nel CMS', 'items' => []],
];
$site['pages']['psicoterapia']['sideNotes'] = [];
$site['pages']['coaching']['sections'] = [];
$site['pages']['lavoro']['sections'][0]['items'] = ['Una sola voce'];
$merged = ensure_site_defaults($site);
verify($merged === $site, 'Shortened and empty lists are preserved, not merged by index');
verify(ensure_site_defaults($merged) === $merged, 'Repeated loading does not resurrect removed content');
unset($site['pages']['metodo']['hero']['buttonLabel']);
$merged = ensure_site_defaults($site);
verify($merged['pages']['metodo']['hero']['buttonLabel'] !== '', 'Missing individual field receives a default');
verify($merged['pages']['psicoterapia'] === $site['pages']['psicoterapia'], 'Other page content untouched');
echo "OK: CMS defaults preserve saved lists, empty sections and deleted blocks\n";
