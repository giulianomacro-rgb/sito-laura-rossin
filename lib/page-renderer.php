<?php
declare(strict_types=1);

function public_pages(): array
{
    return [
        '/' => 'home',
        '/servizi.html' => 'servizi', '/metodo.html' => 'metodo',
        '/iniziative.html' => 'iniziative', '/contatti.html' => 'contatti',
        '/servizi/psicoterapia-breve-strategica-bolzano.html' => 'psicoterapia',
        '/servizi/coaching-professionale-strategico.html' => 'coaching',
        '/servizi/psicologia-del-lavoro-sportello-aziendale.html' => 'lavoro',
        '/iniziative/mente-adolescente.html' => 'mente',
        '/privacy-policy.html' => 'privacy', '/cookie-policy.html' => 'cookie',
        '/prenotazioni.html' => 'booking', '/articolo.html' => 'article',
    ];
}

function visible_content($value): bool
{
    return !in_array($value, [false, 0, '0'], true);
}

function html_text($value): string
{
    return htmlspecialchars(is_scalar($value) ? (string) $value : '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function safe_site_url($value, string $prefix = ''): string
{
    if (!is_string($value) || preg_match('/[\x00-\x20\\\\]/', $value)) {
        return '';
    }
    if (preg_match('~^(https?://|mailto:|tel:|#|/)~i', $value)) {
        return $value;
    }
    return str_contains($value, ':') ? '' : $prefix . $value;
}

function rich_sections(array $sections): string
{
    $html = '';
    foreach ($sections as $section) {
        $html .= '<h2>' . html_text($section['title'] ?? '') . '</h2>';
        foreach (preg_split('/\R+/u', (string) ($section['text'] ?? '')) as $p) {
            if (trim($p) !== '') $html .= '<p>' . html_text($p) . '</p>';
        }
        if (!empty($section['items'])) {
            $html .= '<ul class="check-list">';
            foreach ($section['items'] as $item) $html .= '<li>' . html_text($item) . '</li>';
            $html .= '</ul>';
        }
    }
    return $html;
}

function render_site_page(string $html, array $site, string $path, ?array $article = null): string
{
    // HTML5 parsing keeps the CMS and the document served to crawlers in sync.
    $doc = Dom\HTMLDocument::createFromString($html, LIBXML_NOERROR, 'UTF-8');
    $text = static function (string $selector, $value) use ($doc): void {
        if (is_string($value) && ($el = $doc->querySelector($selector))) $el->textContent = $value;
    };
    $attr = static function (string $selector, string $key, string $value) use ($doc): void {
        foreach ($doc->querySelectorAll($selector) as $el) $el->setAttribute($key, $value);
    };
    $replace = static function (string $selector, string $markup) use ($doc): void {
        $el = $doc->querySelector($selector);
        if (!$el) return;
        $fragment = Dom\HTMLDocument::createFromString('<!doctype html><html><body>' . $markup . '</body></html>', LIBXML_NOERROR, 'UTF-8');
        $el->replaceChildren();
        foreach ($fragment->body->childNodes as $node) $el->appendChild($doc->importNode($node, true));
    };
    $key = public_pages()[$path] ?? 'home';
    $prefix = str_contains(trim($path, '/'), '/') ? '../' : '';
    $home = $site['home'] ?? [];
    $assets = $site['assets'] ?? [];
    $page = $site['pages'][$key] ?? [];
    $url = static fn ($v): string => safe_site_url($v, $prefix);
    $link = static function ($selector, $label, $href) use ($text, $doc, $url): void {
        $text($selector, $label);
        if ($el = $doc->querySelector($selector)) $el->setAttribute('href', $url($href));
    };
    $image = static function ($selector, $src, $alt) use ($attr, $url): void {
        if (!$src) return;
        $attr($selector, 'src', $url($src));
        $attr($selector, 'alt', (string) $alt);
    };
    $image('.brand img, .footer-logo', $assets['logo'] ?? '', 'Laura Rossin');
    $text('.footer-copy p:first-child', $home['footer']['line1'] ?? null);
    $text('.footer-copy p:nth-child(2)', $home['footer']['line2'] ?? null);
    $text('.powered-by span', $home['footer']['poweredBy']['label'] ?? null);
    $image('.powered-by img', $home['footer']['poweredBy']['logo'] ?? '', $home['footer']['poweredBy']['label'] ?? '');
    if (!empty($home['footer']['poweredBy']['href'])) $attr('.powered-by', 'href', $url($home['footer']['poweredBy']['href']));
    foreach (['pattern' => 'patternImage', 'services' => 'servicesIllustration', 'method' => 'methodIllustration', 'initiative' => 'initiativeIllustration', 'page' => 'pageIllustration', 'article' => 'articleIllustration'] as $name => $field) {
        $src = $url($assets[$field] ?? $assets['patternImage'] ?? '');
        if ($src !== '') {
            $style = $doc->documentElement->getAttribute('style') ?? '';
            $doc->documentElement->setAttribute('style', $style . '--' . $name . '-image:url("' . str_replace(['"', '<', '>'], ['%22', '%3C', '%3E'], $src) . '");');
        }
    }
    foreach ($doc->querySelectorAll('.site-nav a') as $nav) {
        $section = basename(parse_url($nav->getAttribute('href'), PHP_URL_PATH) ?? '', '.html');
        if ($section === $key || ($section === 'servizi' && in_array($key, ['psicoterapia','coaching','lavoro'], true)) || ($section === 'iniziative' && $key === 'mente')) $nav->setAttribute('aria-current', 'page');
    }
    if ($key === 'home' || in_array($key, ['servizi','metodo','iniziative','contatti'], true)) {
        $mapping = [
            '.hero .eyebrow' => ['hero','eyebrow'], '#hero-title' => ['hero','title'], '.hero-lead' => ['hero','lead'],
            '.intro-band h2' => ['intro','title'],
            '#servizi .eyebrow' => ['services','eyebrow'], '#servizi-title' => ['services','title'],
            '.method-section .eyebrow' => ['method','eyebrow'], '#metodo-title' => ['method','title'], '.method-grid > div p:not(.eyebrow)' => ['method','text'],
            '.initiatives-section .eyebrow' => ['initiative','eyebrow'], '#iniziative-title' => ['initiative','title'],
            '.initiative-label' => ['initiative','label'], '.initiative-panel h3' => ['initiative','name'], '.initiative-panel p:not(.initiative-label)' => ['initiative','text'],
            '.resources-section .eyebrow' => ['topics','eyebrow'], '#risorse-title' => ['topics','title'],
            '.faq-section .eyebrow' => ['faq','eyebrow'], '#faq-title' => ['faq','title'],
            '.contact-details .eyebrow' => ['contact','eyebrow'], '#contatti-title' => ['contact','title'], '.contact-grid > div p:not(.eyebrow)' => ['contact','text'],
        ];
        foreach ($mapping as $selector => [$section,$field]) $text($selector, $home[$section][$field] ?? null);
        $image('.hero-media img', $assets['heroImage'] ?? '', 'Laura Rossin, psicologa e psicoterapeuta a Bolzano');
        $attr('.hero-media img', 'fetchpriority', 'high');
        $link('.hero-actions .button-primary', $home['hero']['primaryLabel'], $home['hero']['primaryHref']);
        $link('.hero-actions .button-secondary', $home['hero']['secondaryLabel'], $home['hero']['secondaryHref']);
        $link('.initiative-panel .button', $home['initiative']['buttonLabel'], $home['initiative']['href']);
        $trust = '';
        foreach ($home['trust'] ?? [] as $item) {
            $trust .= '<a class="trust-item" href="' . html_text($url($item['href'] ?? '#')) . '"><span class="trust-title">' . html_text($item['title']) . '</span><span class="trust-text">' . html_text($item['text']) . '</span></a>';
        }
        $replace('.trust-list', $trust);
        $services = '';
        $rows = $home['services']['items'] ?? [];
        usort($rows, static fn ($a,$b) => ($a['order'] ?? 0) <=> ($b['order'] ?? 0));
        foreach ($rows as $item) if (visible_content($item['visible'] ?? true)) {
            $serviceKey = public_pages()['/' . ltrim($item['href'], '/')] ?? '';
            $art = $site['pages'][$serviceKey]['hero']['image'] ?? $assets['servicesIllustration'] ?? '';
            $services .= '<article class="service-card has-illustration"><img class="service-illustration" src="' . html_text($url($art)) . '" alt="" loading="lazy" decoding="async"><h3>' . html_text($item['title']) . '</h3><p>' . html_text($item['text']) . '</p><a href="' . html_text($url($item['href'])) . '">' . html_text($item['linkLabel']) . '</a></article>';
        }
        $replace('.service-grid', $services);
        $steps = '';
        foreach ($home['method']['steps'] ?? [] as $item) $steps .= '<li><span>' . html_text($item['number']) . '</span><strong>' . html_text($item['title']) . '</strong>' . html_text($item['text']) . '</li>';
        $replace('.steps-list', $steps);
        $gallery = '';
        foreach ($home['gallery'] ?? [] as $item) {
            if (!visible_content($item['visible'] ?? true) || empty($item['src'])) continue;
            $gallery .= '<figure><img src="' . html_text($url($item['src'])) . '" alt="' . html_text($item['alt'] ?? '') . '" loading="lazy" decoding="async">';
            if (!empty($item['credit'])) {
                $credit = html_text($item['credit']);
                $creditUrl = $url($item['creditUrl'] ?? '');
                $gallery .= '<figcaption>Foto di ' . ($creditUrl ? '<a href="' . html_text($creditUrl) . '" rel="noopener noreferrer">' . $credit . '</a>' : $credit) . '</figcaption>';
            }
            $gallery .= '</figure>';
        }
        $replace('.image-grid', $gallery);
        if (!$gallery) $attr('.image-band', 'hidden', '');
        $topics = '';
        $rows = $home['topics']['items'] ?? [];
        usort($rows, static fn ($a,$b) => ($a['order'] ?? 0) <=> ($b['order'] ?? 0));
        foreach ($rows as $item) if (visible_content($item['visible'] ?? true)) $topics .= '<li><a href="' . html_text($url($item['href'] ?? 'articolo.html?slug=' . rawurlencode($item['slug']))) . '">' . html_text($item['label']) . '</a></li>';
        $replace('.topic-list', $topics);
        $faq = '';
        foreach ($home['faq']['items'] ?? [] as $item) $faq .= '<details><summary>' . html_text($item['question']) . '</summary><p>' . html_text($item['answer']) . '</p></details>';
        $replace('.faq-list', $faq);
        $c = $home['contact'];
        $replace('.contact-card', '<a href="' . html_text($url('mailto:' . $c['email'])) . '">' . html_text($c['email']) . '</a><a href="' . html_text($url('tel:' . $c['phoneHref'])) . '">' . html_text($c['phone']) . '</a><span>' . html_text($c['cities']) . '</span><span>' . html_text($c['vat']) . '</span>');
    }
    if ($key === 'article' && $article) {
        $text('.article-hero h1', $article['title'] ?? $article['label']);
        $text('.article-lead', $article['excerpt'] ?? '');
        $text('.article-hero .eyebrow', $home['topics']['eyebrow'] ?? 'Risorse e temi');
        $image('.article-hero img', $article['heroImage'] ?? $assets['patternImage'], '');
        $replace('.article-content', rich_sections($article['sections'] ?? []));
        $replace('.side-note', '<strong>Risorse di Laura Rossin</strong><span>Psicologa e psicoterapeuta a Bolzano</span><a href="/servizi.html">Scopri i percorsi</a>');
    } elseif ($page) {
        $text('.page-hero .eyebrow', $page['hero']['eyebrow'] ?? '');
        $text('.page-hero h1', $page['hero']['title'] ?? '');
        $text('.page-hero p:not(.eyebrow)', $page['hero']['text'] ?? '');
        $link('.page-hero .button', $page['hero']['buttonLabel'], $page['hero']['buttonHref']);
        $image('.page-hero img', $page['hero']['image'] ?? '', $page['hero']['imageAlt'] ?? '');
        if (!empty($page['hero']['image'])) {
            $doc->documentElement->setAttribute('style', $doc->documentElement->getAttribute('style') . '--page-image:url("' . str_replace(['"','<','>'], ['%22','%3C','%3E'], $url($page['hero']['image'])) . '");');
        }
        $side = '<strong>' . html_text($page['sideNoteTitle'] ?? '') . '</strong>';
        foreach ($page['sideNotes'] ?? [] as $item) $side .= '<span>' . html_text($item) . '</span>';
        $replace('.side-note', $side);
        $replace('.rich-content', rich_sections($page['sections'] ?? []));
    } elseif ($key === 'booking') {
        $c = $home['contact'];
        $link('.booking-contact a[href^="mailto:"]', $c['email'], 'mailto:' . $c['email']);
        $link('.booking-contact a[href^="tel:"]', $c['phone'], 'tel:' . $c['phoneHref']);
        $attr('.hero-actions a[href^="mailto:"]', 'href', $url('mailto:' . $c['email']));
    }
    $doc->documentElement->setAttribute('data-server-rendered', 'true');
    foreach ($doc->querySelectorAll('script[type="application/ld+json"]') as $script) $script->remove();
    $base = 'https://www.laurarossin.it';
    $canonical = $base . $path . ($article ? '?slug=' . rawurlencode($article['slug']) : '');
    $attr('link[rel="canonical"]', 'href', $canonical);
    $person = ['@type' => 'Person', '@id' => $base . '/#person', 'name' => 'Laura Rossin', 'url' => $base . '/', 'jobTitle' => ['Psicologa', 'Psicoterapeuta', 'Psicologa del lavoro'], 'email' => $home['contact']['email'], 'telephone' => $home['contact']['phoneHref']];
    foreach ($home['trust'] ?? [] as $item) if (str_starts_with($item['href'] ?? '', 'https://areariservata.psy.it/')) $person['sameAs'][] = $item['href'];
    $website = ['@type' => 'WebSite', '@id' => $base . '/#website', 'url' => $base . '/', 'name' => 'Laura Rossin', 'inLanguage' => 'it-IT', 'publisher' => ['@id' => $person['@id']]];
    $webpage = ['@type' => 'WebPage', '@id' => $canonical . '#page', 'url' => $canonical, 'name' => $doc->querySelector('h1')?->textContent ?? 'Laura Rossin', 'inLanguage' => 'it-IT', 'isPartOf' => ['@id' => $website['@id']], 'about' => ['@id' => $person['@id']]];
    $graph = [$person, $website, $webpage];
    if (in_array($key, ['psicoterapia', 'coaching', 'lavoro'], true)) $graph[] = [
        '@type' => 'Service', '@id' => $canonical . '#service', 'name' => $page['title'],
        'description' => $page['hero']['text'], 'url' => $canonical,
        'provider' => ['@id' => $person['@id']], 'areaServed' => ['@type' => 'City', 'name' => 'Bolzano'],
    ];
    if ($article) $graph[] = ['@type' => 'Article', '@id' => $canonical . '#article', 'headline' => $article['title'] ?? $article['label'], 'description' => $article['excerpt'] ?? '', 'publisher' => ['@id' => $person['@id']], 'inLanguage' => 'it-IT', 'mainEntityOfPage' => ['@id' => $webpage['@id']]];
    if ($path !== '/') $graph[] = ['@type' => 'BreadcrumbList', 'itemListElement' => [
        ['@type' => 'ListItem', 'position' => 1, 'name' => 'Laura Rossin', 'item' => $base . '/'],
        ['@type' => 'ListItem', 'position' => 2, 'name' => $webpage['name'], 'item' => $canonical],
    ]];
    $script = $doc->createElement('script');
    $script->setAttribute('type', 'application/ld+json');
    $script->textContent = json_encode(['@context' => 'https://schema.org', '@graph' => $graph], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP);
    $doc->head->appendChild($script);
    $attr('meta[property="og:url"]', 'content', $canonical);
    return $doc->saveHtml();
}
