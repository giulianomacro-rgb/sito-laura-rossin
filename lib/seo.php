<?php
declare(strict_types=1);

function apply_site_seo(string $html, array $seo, string $ogImage = ''): string
{
    $escape = static fn (string $text): string => htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $headEnd = strpos($html, '</head>');
    if ($headEnd === false) {
        return $html;
    }
    $head = substr($html, 0, $headEnd);
    if (is_string($seo['title'] ?? null) && $seo['title'] !== '') {
        $title = '<title>' . $escape($seo['title']) . '</title>';
        $head = preg_replace_callback('/<title>.*?<\/title>/si', static fn () => $title, $head, 1) ?? $head;
    }
    $tags = [
        'name="description"' => $seo['description'] ?? null,
        'property="og:title"' => $seo['ogTitle'] ?? $seo['title'] ?? null,
        'property="og:description"' => $seo['ogDescription'] ?? $seo['description'] ?? null,
        'property="og:image"' => $ogImage,
    ];
    foreach ($tags as $attribute => $value) {
        if (is_string($value) && $value !== '') {
            $tag = '<meta ' . $attribute . ' content="' . $escape($value) . '">';
            $head = preg_replace_callback('/<meta ' . preg_quote($attribute, '/') . ' content="[^"]*">/i', static fn () => $tag, $head, 1) ?? $head;
        }
    }
    return $head . substr($html, $headEnd);
}
