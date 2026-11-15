<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/runtime.php';
require_once dirname(__DIR__) . '/lib/seo.php';

header('X-Robots-Tag: noindex, nofollow');
header('X-Frame-Options: SAMEORIGIN');
header('Cache-Control: no-store');
$inContainer = getenv('CMS_CONTAINER') === '1';
$isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (($_SERVER['SERVER_PORT'] ?? '') === '443')
    || ($inContainer && getenv('RAILWAY_ENVIRONMENT_ID') !== false);
ini_set('session.use_strict_mode', '1');
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'secure' => $isHttps,
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_start();

$root = dirname(__DIR__);
$storage = storage_dir();
$contentFile = site_content_file();
$backupDir = $storage ? $storage . '/backups' : $root . '/content/backups';
$configFile = $storage ? $storage . '/private/account.php' : __DIR__ . '/config.php';
$calendarConfigFile = calendar_config_file();
$loginAttemptsFile = $storage ? $storage . '/private/login-attempts.json' : __DIR__ . '/.login-attempts.json';
$uploadDir = $storage ? $storage . '/uploads' : $root . '/assets/uploads';
$uploadBase = 'assets/uploads/';

if ($inContainer && (!$storage || !is_writable($storage))) {
    http_response_code(503);
    exit('CMS non ancora disponibile. Collega un volume Railway al percorso /data.');
}

if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}

function e($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function env_value(string $key): ?string
{
    $value = getenv($key);
    return is_string($value) && $value !== '' ? $value : null;
}

function auth_uses_env(): bool
{
    return env_value('CMS_USERNAME') !== null || env_value('CMS_PASSWORD_HASH') !== null;
}

function load_auth_config(string $file): array
{
    $config = is_file($file) ? require $file : [];
    $config = is_array($config) ? $config : [];

    if (($username = env_value('CMS_USERNAME')) !== null) {
        $config['username'] = $username;
    }

    if (($passwordHash = env_value('CMS_PASSWORD_HASH')) !== null) {
        $config['password_hash'] = $passwordHash;
        unset($config['password_salt']);
    }

    $config['username'] = (string) ($config['username'] ?? 'laura');
    $config['session_version'] = max(1, (int) ($config['session_version'] ?? 1));
    return $config;
}

function verify_password_config(array $config, string $password): array
{
    $hash = (string) ($config['password_hash'] ?? '');
    if ($hash !== '' && is_modern_password_hash($hash)) {
        return [
            'ok' => password_verify($password, $hash),
            'needs_rehash' => password_needs_rehash($hash, PASSWORD_DEFAULT),
        ];
    }

    if (!empty($config['password_salt']) && $hash !== '') {
        $legacy = hash('sha256', (string) $config['password_salt'] . $password);
        return [
            'ok' => hash_equals($hash, $legacy),
            'needs_rehash' => true,
        ];
    }

    return ['ok' => false, 'needs_rehash' => false];
}

function is_modern_password_hash(string $hash): bool
{
    $info = password_get_info($hash);
    $algo = $info['algo'] ?? null;
    $algoName = (string) ($info['algoName'] ?? 'unknown');
    return ($algo !== 0 && $algo !== null && $algo !== '') || $algoName !== 'unknown';
}

function write_auth_config(string $file, string $username, ?string $plainPassword, array $current, ?int $sessionVersion = null): bool
{
    $next = [
        'username' => $username,
        'session_version' => $sessionVersion ?? max(1, (int) ($current['session_version'] ?? 1)),
    ];

    if ($plainPassword !== null) {
        $next['password_hash'] = password_hash($plainPassword, PASSWORD_DEFAULT);
    } else {
        if (!empty($current['password_hash'])) {
            $next['password_hash'] = (string) $current['password_hash'];
        }
        if (!empty($current['password_salt']) && !is_modern_password_hash((string) ($current['password_hash'] ?? ''))) {
            $next['password_salt'] = (string) $current['password_salt'];
        }
    }

    $body = "<?php\nreturn " . var_export($next, true) . ";\n";
    return atomic_write($file, $body);
}

function is_logged_in(): bool
{
    return !empty($_SESSION['cms_user']);
}

function verify_csrf(): bool
{
    return is_string($_POST['csrf'] ?? null) && isset($_SESSION['csrf']) && hash_equals($_SESSION['csrf'], $_POST['csrf']);
}

function destroy_session_and_redirect(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
    }
    session_destroy();
    header('Location: index.php');
    exit;
}

function flash(string $type, string $message): void
{
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
}

function consume_flash(): array
{
    $items = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return is_array($items) ? $items : [];
}

function redirect_section(string $section): void
{
    header('Location: index.php?section=' . rawurlencode($section));
    exit;
}

function json_response(array $payload, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function read_site(string $file): array
{
    if (!is_file($file)) {
        return [];
    }

    $json = file_get_contents($file);
    $data = json_decode((string) $json, true);
    return is_array($data) ? $data : [];
}

function write_site(string $file, array $data): bool
{
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    return $json !== false && atomic_write($file, $json . PHP_EOL);
}

function backup_site_file(string $file, string $backupDir): bool
{
    if (!is_file($file)) {
        return true;
    }

    if (!is_dir($backupDir) && !mkdir($backupDir, 0775, true)) {
        return false;
    }

    $target = $backupDir . DIRECTORY_SEPARATOR . 'site-' . date('Ymd-His') . '-' . bin2hex(random_bytes(4)) . '.json';
    return copy($file, $target);
}

function value(array $data, array $path, string $default = ''): string
{
    $current = $data;
    foreach ($path as $segment) {
        if (!is_array($current) || !array_key_exists($segment, $current)) {
            return $default;
        }
        $current = $current[$segment];
    }

    return is_array($current) ? $default : (string) $current;
}

function truthy($value): bool
{
    if (is_bool($value)) {
        return $value;
    }
    return !in_array((string) $value, ['', '0', 'false', 'no'], true);
}

function field_attrs(array $attributes): string
{
    $html = '';
    foreach ($attributes as $key => $value) {
        if ($value === false || $value === null) {
            continue;
        }
        $html .= ' ' . e($key);
        if ($value !== true) {
            $html .= '="' . e((string) $value) . '"';
        }
    }
    return $html;
}

function field(string $label, string $name, string $value = '', string $type = 'text', string $helper = '', array $attributes = []): void
{
    echo '<label class="field"><span>' . e($label) . '</span>';
    echo '<input type="' . e($type) . '" name="' . e($name) . '" value="' . e($value) . '"' . field_attrs($attributes) . '>';
    if ($helper !== '') {
        echo '<small>' . e($helper) . '</small>';
    }
    echo '</label>';
}

function area(string $label, string $name, string $value = '', int $rows = 4, string $helper = ''): void
{
    echo '<label class="field"><span>' . e($label) . '</span>';
    echo '<textarea name="' . e($name) . '" rows="' . (int) $rows . '">' . e($value) . '</textarea>';
    if ($helper !== '') {
        echo '<small>' . e($helper) . '</small>';
    }
    echo '</label>';
}

function toggle_field(string $label, string $name, bool $checked, string $helper = ''): void
{
    echo '<label class="toggle">';
    echo '<input type="hidden" name="' . e($name) . '" value="0">';
    echo '<input type="checkbox" name="' . e($name) . '" value="1"' . ($checked ? ' checked' : '') . '>';
    echo '<span><strong>' . e($label) . '</strong>';
    if ($helper !== '') {
        echo '<small>' . e($helper) . '</small>';
    }
    echo '</span></label>';
}

function lines_to_array(string $text): array
{
    $rows = preg_split('/\R/u', $text) ?: [];
    $rows = array_map('trim', $rows);
    return array_values(array_filter($rows, static fn ($row) => $row !== ''));
}

function array_to_lines(array $items): string
{
    return implode(PHP_EOL, array_map('strval', $items));
}

function clean_rows(array $rows, array $allowedKeys): array
{
    $clean = [];
    $nonMeaningfulKeys = ['marker', 'visible', 'order', 'href'];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }

        $item = [];
        $hasValue = false;
        foreach ($allowedKeys as $key) {
            $item[$key] = isset($row[$key]) ? trim((string) $row[$key]) : '';
            if (!in_array($key, $nonMeaningfulKeys, true) && $item[$key] !== '') {
                $hasValue = true;
            }
        }

        if ($hasValue) {
            $clean[] = $item;
        }
    }
    return $clean;
}

function page_defaults(): array
{
    $bundled = json_decode((string) file_get_contents(dirname(__DIR__) . '/content/site.json'), true);
    $pages = is_array($bundled['pages'] ?? null) ? $bundled['pages'] : [];
    foreach ($pages as &$page) $page['label'] = $page['title'] ?? '';
    return $pages;
}

function ensure_site_defaults(array $site): array
{
    $site['assets'] = is_array($site['assets'] ?? null) ? $site['assets'] : [];
    $site['home'] = is_array($site['home'] ?? null) ? $site['home'] : [];
    $site['pages'] = is_array($site['pages'] ?? null) ? $site['pages'] : [];

    $trustDefaults = [
        'Albo' => 'https://www.psy.it/albo-psicologi',
        'Approccio' => 'https://www.centroditerapiastrategica.com/',
        'Aree' => '#servizi',
    ];
    foreach (($site['home']['trust'] ?? []) as $index => $item) {
        if (!is_array($item)) {
            continue;
        }
        $title = (string) ($item['title'] ?? '');
        if (empty($site['home']['trust'][$index]['href']) && isset($trustDefaults[$title])) {
            $site['home']['trust'][$index]['href'] = $trustDefaults[$title];
        }
    }

    foreach (page_defaults() as $key => $defaults) {
        $existing = is_array($site['pages'][$key] ?? null) ? $site['pages'][$key] : [];
        unset($defaults['label']);
        $site['pages'][$key] = array_replace_recursive([
            'published' => true,
            'title' => $defaults['title'] ?? ($existing['hero']['title'] ?? ''),
            'slug' => $defaults['slug'] ?? '',
            'socialImage' => $site['assets']['ogImage'] ?? '',
            'seo' => [],
            'hero' => [],
            'sideNoteTitle' => '',
            'sideNotes' => [],
            'sections' => [],
        ], $defaults, $existing);
    }

    return $site;
}

function is_list_like(array $array): bool
{
    if ($array === []) {
        return true;
    }
    return array_keys($array) === range(0, count($array) - 1);
}

function merge_site_data(array $base, array $incoming): array
{
    foreach ($incoming as $key => $value) {
        if (is_array($value) && isset($base[$key]) && is_array($base[$key]) && !is_list_like($value)) {
            $base[$key] = merge_site_data($base[$key], $value);
        } else {
            $base[$key] = $value;
        }
    }
    return $base;
}

function normalize_services(array $rows): array
{
    $clean = [];
    foreach ($rows as $index => $row) {
        if (!is_array($row)) {
            continue;
        }

        $item = [
            'marker' => in_array((string) ($row['marker'] ?? 'blue'), ['blue', 'yellow', 'red'], true) ? (string) ($row['marker'] ?? 'blue') : 'blue',
            'title' => trim((string) ($row['title'] ?? '')),
            'text' => trim((string) ($row['text'] ?? '')),
            'href' => trim((string) ($row['href'] ?? '')),
            'linkLabel' => trim((string) ($row['linkLabel'] ?? '')),
            'visible' => truthy($row['visible'] ?? '1'),
            'order' => max(1, (int) ($row['order'] ?? ($index + 1))),
        ];

        if ($item['title'] !== '' || $item['text'] !== '' || $item['href'] !== '') {
            $clean[] = $item;
        }
    }

    usort($clean, static fn ($a, $b) => ($a['order'] <=> $b['order']));
    foreach ($clean as $index => $item) {
        $clean[$index]['order'] = $index + 1;
    }
    return $clean;
}

function slug_from_title(string $value): string
{
    $value = strtolower(trim($value));
    if (function_exists('iconv')) {
        $value = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value) ?: $value;
    }
    $value = preg_replace('/[^a-z0-9]+/', '-', $value) ?: '';
    return trim($value, '-') ?: 'articolo';
}

function normalize_gallery(array $rows): array
{
    $clean = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }

        $src = trim((string) ($row['src'] ?? ''));
        $alt = trim((string) ($row['alt'] ?? ''));
        if ($src === '' && $alt === '') {
            continue;
        }

        $clean[] = [
            'src' => $src,
            'alt' => $alt,
            'visible' => truthy($row['visible'] ?? '1'),
        ];
    }
    return $clean;
}

function normalize_topics(array $rows): array
{
    $clean = [];
    foreach ($rows as $index => $row) {
        if (!is_array($row)) {
            continue;
        }

        $label = trim((string) ($row['label'] ?? ''));
        $title = trim((string) ($row['title'] ?? $label));
        $slug = trim((string) ($row['slug'] ?? ''));
        if ($slug === '') {
            $slug = slug_from_title($title !== '' ? $title : $label);
        }

        $sections = [];
        foreach (($row['sections'] ?? []) as $section) {
            if (!is_array($section)) {
                continue;
            }
            $sectionTitle = trim((string) ($section['title'] ?? ''));
            $sectionText = trim((string) ($section['text'] ?? ''));
            $items = lines_to_array((string) ($section['itemsText'] ?? array_to_lines($section['items'] ?? [])));
            if ($sectionTitle !== '' || $sectionText !== '' || $items !== []) {
                $sections[] = [
                    'title' => $sectionTitle,
                    'text' => $sectionText,
                    'items' => $items,
                ];
            }
        }

        if ($label !== '' || $title !== '' || $sections !== []) {
            $clean[] = [
                'visible' => truthy($row['visible'] ?? '1'),
                'order' => max(1, (int) ($row['order'] ?? ($index + 1))),
                'label' => $label !== '' ? $label : $title,
                'slug' => $slug,
                'href' => 'articolo.html?slug=' . $slug,
                'title' => $title !== '' ? $title : $label,
                'excerpt' => trim((string) ($row['excerpt'] ?? '')),
                'socialImage' => trim((string) ($row['socialImage'] ?? '')),
                'heroImage' => trim((string) ($row['heroImage'] ?? '')),
                'sections' => $sections,
            ];
        }
    }

    usort($clean, static fn ($a, $b) => ($a['order'] <=> $b['order']));
    foreach ($clean as $index => $item) {
        $clean[$index]['order'] = $index + 1;
    }
    return $clean;
}

function normalize_site(array $site): array
{
    $site = ensure_site_defaults($site);
    $site['home']['trust'] = clean_rows($site['home']['trust'] ?? [], ['title', 'text', 'href']);
    $site['home']['services']['items'] = normalize_services($site['home']['services']['items'] ?? []);
    $site['home']['method']['steps'] = clean_rows($site['home']['method']['steps'] ?? [], ['number', 'title', 'text']);
    $site['home']['gallery'] = normalize_gallery($site['home']['gallery'] ?? []);
    $site['home']['topics']['items'] = normalize_topics($site['home']['topics']['items'] ?? []);
    $site['home']['faq']['items'] = clean_rows($site['home']['faq']['items'] ?? [], ['question', 'answer']);

    foreach (($site['pages'] ?? []) as $key => $page) {
        if (!is_array($page)) {
            unset($site['pages'][$key]);
            continue;
        }

        $site['pages'][$key]['published'] = truthy($page['published'] ?? '1');
        $site['pages'][$key]['title'] = trim((string) ($page['title'] ?? ($page['hero']['title'] ?? '')));
        $site['pages'][$key]['slug'] = trim((string) ($page['slug'] ?? ''));
        $site['pages'][$key]['socialImage'] = trim((string) ($page['socialImage'] ?? ''));

        if (isset($page['sideNotesText'])) {
            $site['pages'][$key]['sideNotes'] = lines_to_array((string) $page['sideNotesText']);
            unset($site['pages'][$key]['sideNotesText']);
        }

        $sections = [];
        foreach (($page['sections'] ?? []) as $section) {
            if (!is_array($section)) {
                continue;
            }

            $title = trim((string) ($section['title'] ?? ''));
            $text = trim((string) ($section['text'] ?? ''));
            $items = lines_to_array((string) ($section['itemsText'] ?? array_to_lines($section['items'] ?? [])));

            if ($title !== '' || $text !== '' || $items !== []) {
                $sections[] = [
                    'title' => $title,
                    'text' => $text,
                    'items' => $items,
                ];
            }
        }
        $site['pages'][$key]['sections'] = $sections;
    }

    return $site;
}

function upload_image_file(array $file, string $uploadDir, string $uploadBase, string $nameSeed, string $label): array
{
    $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($error !== UPLOAD_ERR_OK) {
        return ['ok' => false, 'message' => 'Upload non riuscito per "' . $label . '". Riprova con un altro file.'];
    }

    $size = (int) ($file['size'] ?? 0);
    if ($size > 5 * 1024 * 1024) {
        return ['ok' => false, 'message' => '"' . $label . '" supera il limite di 5 MB.'];
    }

    $tmpFile = (string) ($file['tmp_name'] ?? '');
    $image = is_uploaded_file($tmpFile) ? @getimagesize($tmpFile) : false;
    $extensions = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    $extension = $extensions[$image['mime'] ?? ''] ?? null;
    if (!$extension || !($image[0] > 0 && $image[1] > 0) || $image[0] * $image[1] > 40000000) {
        return ['ok' => false, 'message' => 'Usa un file JPG, PNG o WebP valido, fino a 40 megapixel.'];
    }

    if (!is_dir($uploadDir) && !mkdir($uploadDir, 0775, true)) {
        return ['ok' => false, 'message' => 'Non riesco a creare la cartella assets/uploads. Verifica i permessi.'];
    }

    $safeSeed = trim((string) preg_replace('/[^a-z0-9-]+/i', '-', $nameSeed), '-');
    $safeSeed = $safeSeed !== '' ? strtolower($safeSeed) : 'immagine';
    $filename = substr($safeSeed, 0, 50) . '-' . bin2hex(random_bytes(10)) . '.' . $extension;
    $target = $uploadDir . DIRECTORY_SEPARATOR . $filename;
    if (!move_uploaded_file($tmpFile, $target)) {
        return ['ok' => false, 'message' => 'Non riesco a salvare "' . $label . '". Verifica i permessi della cartella uploads.'];
    }

    return ['ok' => true, 'path' => $uploadBase . $filename];
}

function handle_uploads(array &$site, string $uploadDir, string $uploadBase, array &$errors, array $assetDefinitions): void
{
    if (empty($_FILES['uploads']) || !is_array($_FILES['uploads']['name'])) {
        return;
    }

    foreach ($assetDefinitions as $assetKey => $definition) {
        if (empty($_FILES['uploads']['name'][$assetKey])) {
            continue;
        }

        $label = (string) ($definition['label'] ?? $assetKey);
        $result = upload_image_file([
            'name' => $_FILES['uploads']['name'][$assetKey] ?? '',
            'type' => $_FILES['uploads']['type'][$assetKey] ?? '',
            'tmp_name' => $_FILES['uploads']['tmp_name'][$assetKey] ?? '',
            'error' => $_FILES['uploads']['error'][$assetKey] ?? UPLOAD_ERR_NO_FILE,
            'size' => $_FILES['uploads']['size'][$assetKey] ?? 0,
        ], $uploadDir, $uploadBase, $assetKey, $label);
        if (empty($result['ok'])) {
            $errors[] = (string) ($result['message'] ?? 'Upload non riuscito.');
            continue;
        }

        $site['assets'][$assetKey] = (string) $result['path'];
    }
}

function write_google_calendar_config_file(string $file, array $data): bool
{
    $body = "<?php\nreturn " . var_export($data, true) . ";\n";
    return atomic_write($file, $body);
}

function service_account_status(array $calendarConfig): string
{
    $serviceAccount = $calendarConfig['service_account'] ?? [];
    if (is_array($serviceAccount) && !empty($serviceAccount['client_email']) && !empty($serviceAccount['private_key'])) {
        return 'Configurato per ' . (string) $serviceAccount['client_email'];
    }
    return 'Non configurato';
}

function update_html_seo(string $root, array $site): void
{
    if (getenv('CMS_CONTAINER') === '1') {
        return;
    }
    $files = [
        'index.html' => [
            'title' => $site['home']['seo']['title'] ?? '',
            'description' => $site['home']['seo']['description'] ?? '',
            'ogTitle' => $site['home']['seo']['ogTitle'] ?? ($site['home']['seo']['title'] ?? ''),
            'ogDescription' => $site['home']['seo']['ogDescription'] ?? ($site['home']['seo']['description'] ?? ''),
        ],
        'servizi/psicoterapia-breve-strategica-bolzano.html' => [
            'title' => $site['pages']['psicoterapia']['seo']['title'] ?? '',
            'description' => $site['pages']['psicoterapia']['seo']['description'] ?? '',
        ],
        'servizi/coaching-professionale-strategico.html' => [
            'title' => $site['pages']['coaching']['seo']['title'] ?? '',
            'description' => $site['pages']['coaching']['seo']['description'] ?? '',
        ],
        'servizi/psicologia-del-lavoro-sportello-aziendale.html' => [
            'title' => $site['pages']['lavoro']['seo']['title'] ?? '',
            'description' => $site['pages']['lavoro']['seo']['description'] ?? '',
        ],
        'iniziative/mente-adolescente.html' => [
            'title' => $site['pages']['mente']['seo']['title'] ?? '',
            'description' => $site['pages']['mente']['seo']['description'] ?? '',
        ],
        'privacy-policy.html' => [
            'title' => $site['pages']['privacy']['seo']['title'] ?? '',
            'description' => $site['pages']['privacy']['seo']['description'] ?? '',
        ],
        'cookie-policy.html' => [
            'title' => $site['pages']['cookie']['seo']['title'] ?? '',
            'description' => $site['pages']['cookie']['seo']['description'] ?? '',
        ],
    ];

    foreach ($files as $relative => $seo) {
        $path = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
        if (!is_file($path)) {
            continue;
        }

        $html = file_get_contents($path);
        if ($html === false) {
            continue;
        }

        atomic_write($path, apply_site_seo($html, $seo));
    }
}

function password_errors(string $password): array
{
    $errors = [];
    if (strlen($password) < 12 || strlen($password) > 72 || str_contains($password, "\0")) {
        $errors[] = 'La nuova password deve avere almeno 12 caratteri e non puo superare 72 byte.';
    }
    if (!preg_match('/[A-Z]/', $password) || !preg_match('/[a-z]/', $password) || !preg_match('/[0-9]/', $password)) {
        $errors[] = 'Usa almeno una maiuscola, una minuscola e un numero.';
    }
    return $errors;
}

function login_key(): string
{
    return hash('sha256', (string) ($_SERVER['REMOTE_ADDR'] ?? 'local'));
}

function login_attempts(string $file): array
{
    if (!is_file($file)) {
        return [];
    }
    $data = json_decode((string) file_get_contents($file), true);
    return is_array($data) ? $data : [];
}

function save_login_attempts(string $file, array $attempts): void
{
    file_put_contents($file, json_encode($attempts, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
}

function reserve_login_attempt(string $file, string $key, int $limit, int $windowSeconds): bool
{
    $lock = @fopen($file . '.lock', 'c');
    if ($lock === false || !flock($lock, LOCK_EX)) {
        return false;
    }
    $attempts = login_attempts($file);
    $cutoff = time() - $windowSeconds;
    foreach ($attempts as $ip => $times) {
        $attempts[$ip] = array_values(array_filter((array) $times, static fn ($ts) => is_int($ts) && $ts > $cutoff));
        if (!$attempts[$ip]) {
            unset($attempts[$ip]);
        }
    }
    // Cap per-IP and total attempts, including requests behind a shared proxy.
    $allowed = count($attempts[$key] ?? []) < $limit && array_sum(array_map('count', $attempts)) < 100;
    if ($allowed) {
        $attempts[$key][] = time();
        $allowed = atomic_write($file, (string) json_encode($attempts));
    }
    fclose($lock);
    return $allowed;
}

function clear_failed_login(string $file, string $key): void
{
    $lock = @fopen($file . '.lock', 'c');
    if ($lock === false || !flock($lock, LOCK_EX)) {
        return;
    }
    $attempts = login_attempts($file);
    unset($attempts[$key]);
    save_login_attempts($file, $attempts);
    fclose($lock);
}

function format_time(?int $timestamp): string
{
    if (!$timestamp) {
        return 'Non disponibile';
    }
    return date('d/m/Y H:i', $timestamp);
}

function format_bytes(int $bytes): string
{
    if ($bytes >= 1024 * 1024) {
        return number_format($bytes / (1024 * 1024), 1, ',', '.') . ' MB';
    }
    return number_format($bytes / 1024, 0, ',', '.') . ' KB';
}

function backup_files(string $backupDir): array
{
    if (!is_dir($backupDir)) {
        return [];
    }
    $files = glob($backupDir . DIRECTORY_SEPARATOR . 'site-*.json') ?: [];
    usort($files, static fn ($a, $b) => filemtime($b) <=> filemtime($a));
    return array_slice($files, 0, 5);
}

function render_service_item(array $item, $index): void
{
    $base = 'site[home][services][items][' . $index . ']';
    $visible = array_key_exists('visible', $item) ? truthy($item['visible']) : true;
    ?>
    <div class="service-editor-row" data-service-row>
      <div class="row-head">
        <strong>Servizio</strong>
        <div class="row-actions">
          <button type="button" class="button ghost" data-move-service="-1">Su</button>
          <button type="button" class="button ghost" data-move-service="1">Giu</button>
          <button type="button" class="button secondary" data-duplicate-service>Duplica</button>
          <button type="button" class="button danger" data-delete-service>Elimina</button>
        </div>
      </div>
      <div class="grid four">
        <?php field('Ordine', $base . '[order]', (string) ($item['order'] ?? ((int) $index + 1)), 'number', 'Numero più basso = prima posizione.', ['min' => '1']); ?>
        <?php toggle_field('Visibile sul sito', $base . '[visible]', $visible, 'Disattiva per nasconderlo senza eliminarlo.'); ?>
      </div>
      <div class="grid two">
        <?php field('Titolo servizio', $base . '[title]', (string) ($item['title'] ?? '')); ?>
        <?php field('Testo link', $base . '[linkLabel]', (string) ($item['linkLabel'] ?? '')); ?>
        <?php area('Descrizione', $base . '[text]', (string) ($item['text'] ?? ''), 4); ?>
        <?php field('Link di approfondimento', $base . '[href]', (string) ($item['href'] ?? ''), 'text', 'Esempio: servizi/psicoterapia-breve-strategica-bolzano.html'); ?>
      </div>
    </div>
    <?php
}

function render_topic_item(array $item, $index): void
{
    $base = 'site[home][topics][items][' . $index . ']';
    $visible = array_key_exists('visible', $item) ? truthy($item['visible']) : true;
    $sections = array_merge($item['sections'] ?? [], [['title' => '', 'text' => '', 'items' => []]]);
    ?>
    <div class="service-editor-row" data-topic-row>
      <div class="row-head">
        <strong>Articolo</strong>
        <div class="row-actions">
          <button type="button" class="button secondary" data-move-topic="-1">Su</button>
          <button type="button" class="button secondary" data-move-topic="1">Giù</button>
          <button type="button" class="button secondary" data-duplicate-topic>Duplica</button>
          <button type="button" class="button danger" data-delete-topic>Elimina</button>
        </div>
      </div>
      <div class="grid four">
        <?php field('Ordine', $base . '[order]', (string) ($item['order'] ?? ((int) $index + 1)), 'number', 'Numero più basso = prima posizione.', ['min' => '1']); ?>
        <?php toggle_field('Visibile sul sito', $base . '[visible]', $visible, 'Disattiva per nasconderlo senza eliminarlo.'); ?>
      </div>
      <div class="grid two">
        <?php field('Titolo nella lista', $base . '[label]', (string) ($item['label'] ?? '')); ?>
        <?php field('Slug articolo', $base . '[slug]', (string) ($item['slug'] ?? ''), 'text', 'Esempio: ansia-panico-blocchi-personali'); ?>
        <?php field('Titolo pagina articolo', $base . '[title]', (string) ($item['title'] ?? '')); ?>
        <?php field('Immagine social', $base . '[socialImage]', (string) ($item['socialImage'] ?? ''), 'text', 'Consigliato: 1200 x 630 px.'); ?>
        <?php field('Immagine hero articolo', $base . '[heroImage]', (string) ($item['heroImage'] ?? '')); ?>
        <?php area('Riassunto breve', $base . '[excerpt]', (string) ($item['excerpt'] ?? ''), 3); ?>
      </div>
      <div class="subsection">
        <h3>Contenuto articolo</h3>
        <?php foreach ($sections as $i => $section): ?>
          <div class="mini-card">
            <p class="row-title">Blocco <?= (int) $i + 1 ?></p>
            <div class="grid two">
              <?php field('Titolo blocco', $base . '[sections][' . $i . '][title]', (string) ($section['title'] ?? '')); ?>
              <?php area('Testo blocco', $base . '[sections][' . $i . '][text]', (string) ($section['text'] ?? ''), 5); ?>
              <?php area('Elenco puntato, una voce per riga', $base . '[sections][' . $i . '][itemsText]', array_to_lines($section['items'] ?? []), 4); ?>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
    <?php
}

$sections = [
    'dashboard' => 'Dashboard',
    'visual' => 'Editor visuale',
    'media' => 'Media',
    'homepage' => 'Homepage',
    'servizi' => 'Servizi',
    'pagine' => 'Pagine',
    'prenotazioni' => 'Prenotazioni',
    'seo' => 'SEO / Condivisione',
    'account' => 'Account',
    'impostazioni' => 'Impostazioni',
];

$assetDefinitions = [
    'logo' => [
        'label' => 'Logo',
        'recommended' => 'PNG trasparente, larghezza 600 px.',
    ],
    'heroImage' => [
        'label' => 'Foto principale homepage',
        'recommended' => 'JPG/WebP verticale, almeno 1200 x 1600 px, sotto 2 MB.',
    ],
    'portraitImage' => [
        'label' => 'Ritratto / primo piano',
        'recommended' => 'JPG/WebP, almeno 1200 x 1200 px.',
    ],
    'patternImage' => [
        'label' => 'Pattern grafico',
        'recommended' => 'JPG/WebP orizzontale, almeno 1600 x 600 px.',
    ],
    'servicesIllustration' => [
        'label' => 'Illustrazione schede servizi',
        'recommended' => 'JPG/WebP orizzontale, almeno 1600 x 600 px.',
    ],
    'methodIllustration' => [
        'label' => 'Illustrazione metodo',
        'recommended' => 'JPG/WebP orizzontale, almeno 1600 x 600 px.',
    ],
    'initiativeIllustration' => [
        'label' => 'Illustrazione iniziative',
        'recommended' => 'JPG/WebP orizzontale, almeno 1600 x 600 px.',
    ],
    'pageIllustration' => [
        'label' => 'Illustrazione pagine interne',
        'recommended' => 'JPG/WebP orizzontale, almeno 1600 x 600 px.',
    ],
    'articleIllustration' => [
        'label' => 'Illustrazione articoli',
        'recommended' => 'JPG/WebP orizzontale, almeno 1600 x 600 px.',
    ],
    'studioDeskImage' => [
        'label' => 'Foto studio / ambiente',
        'recommended' => 'JPG/WebP orizzontale, almeno 1600 x 1000 px.',
    ],
    'archimpresaLogo' => [
        'label' => 'Logo Archimpresa',
        'recommended' => 'PNG trasparente, larghezza 400 px.',
    ],
    'ogImage' => [
        'label' => 'Immagine social',
        'recommended' => 'JPG/PNG 1200 x 630 px.',
    ],
];

$config = load_auth_config($configFile);
$hasCredentials = is_modern_password_hash((string) ($config['password_hash'] ?? ''))
    || (!$inContainer && !empty($config['password_salt']) && !empty($config['password_hash']));
$calendarConfig = is_file($calendarConfigFile) ? require $calendarConfigFile : [];
$calendarConfig = is_array($calendarConfig) ? $calendarConfig : [];
$sessionLifetime = max(900, (int) (env_value('CMS_SESSION_LIFETIME') ?? 3600));
$maxLoginAttempts = max(3, (int) (env_value('CMS_MAX_LOGIN_ATTEMPTS') ?? 5));
$loginWindow = 15 * 60;
$errors = [];

if (is_logged_in()) {
    $lastActivity = (int) ($_SESSION['last_activity'] ?? 0);
    $sessionVersion = (int) ($_SESSION['cms_session_version'] ?? 0);
    if (!$hasCredentials || ($lastActivity > 0 && $lastActivity + $sessionLifetime < time())
        || $sessionVersion !== (int) $config['session_version']
        || !hash_equals((string) ($_SESSION['cms_auth'] ?? ''), hash('sha256', $config['username'] . '|' . ($config['password_hash'] ?? '')))) {
        destroy_session_and_redirect();
    }
    $_SESSION['last_activity'] = time();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    if (in_array($action, ['save_visual_content', 'upload_visual_image'], true) && !is_logged_in()) {
        json_response(['ok' => false, 'message' => 'Sessione scaduta. Accedi di nuovo al CMS.'], 401);
    }
    $returnSection = array_key_exists((string) ($_POST['section'] ?? ''), $sections) ? (string) $_POST['section'] : 'dashboard';

    if ($action === 'login') {
        if (!$hasCredentials || !verify_csrf()) {
            $errors[] = 'Sessione scaduta. Riprova.';
        } else {
            $key = login_key();
            if (!reserve_login_attempt($loginAttemptsFile, $key, $maxLoginAttempts, $loginWindow)) {
                $errors[] = 'Accesso non riuscito. Riprova tra qualche minuto.';
            } else {
                $username = trim((string) ($_POST['username'] ?? ''));
                $password = (string) ($_POST['password'] ?? '');
                $passwordCheck = verify_password_config($config, $password);

                if (hash_equals((string) $config['username'], $username) && $passwordCheck['ok']) {
                    clear_failed_login($loginAttemptsFile, $key);
                    session_regenerate_id(true);
                    $_SESSION['cms_user'] = $username;
                    $_SESSION['cms_session_version'] = (int) $config['session_version'];
                    $_SESSION['last_activity'] = time();
                    $_SESSION['csrf'] = bin2hex(random_bytes(32));

                    if ($passwordCheck['needs_rehash'] && !auth_uses_env() && write_auth_config($configFile, $username, $password, $config)) {
                        $config = load_auth_config($configFile);
                    }
                    $_SESSION['cms_auth'] = hash('sha256', $config['username'] . '|' . $config['password_hash']);
                    redirect_section('dashboard');
                }

                $errors[] = 'Accesso non riuscito. Controlla le credenziali e riprova.';
            }
        }
    }

    if ($action === 'logout' && verify_csrf()) {
        destroy_session_and_redirect();
    }

    if (is_logged_in() && $action === 'upload_visual_image') {
        if (!verify_csrf()) {
            json_response(['ok' => false, 'message' => 'Sessione scaduta. Ricarica il CMS e riprova.'], 403);
        }

        $path = trim((string) ($_POST['path'] ?? 'immagine'));
        $file = $_FILES['visual_image'] ?? null;
        if (!is_array($file)) {
            json_response(['ok' => false, 'message' => 'Seleziona un file immagine da caricare.'], 422);
        }

        $label = trim((string) ($_POST['label'] ?? 'immagine'));
        $result = upload_image_file($file, $uploadDir, $uploadBase, $path, $label !== '' ? $label : 'immagine');
        if (empty($result['ok'])) {
            json_response(['ok' => false, 'message' => (string) ($result['message'] ?? 'Upload non riuscito.')], 422);
        }

        json_response(['ok' => true, 'path' => (string) $result['path'], 'message' => 'Immagine caricata. Salva le modifiche per pubblicarla nel contenuto.']);
    }

    if (is_logged_in() && $action === 'save_visual_content') {
        if (!verify_csrf()) {
            json_response(['ok' => false, 'message' => 'Sessione scaduta. Ricarica il CMS e riprova.'], 403);
        }

        $incoming = json_decode((string) ($_POST['site_json'] ?? ''), true);
        if (!is_array($incoming)) {
            json_response(['ok' => false, 'message' => 'Dati non validi. Ricarica la pagina e riprova.'], 422);
        }

        $site = normalize_site($incoming);
        if (!backup_site_file($contentFile, $backupDir)) {
            json_response(['ok' => false, 'message' => 'Non riesco a creare il backup automatico. Salvataggio annullato.'], 500);
        }

        if (!write_site($contentFile, $site)) {
            json_response(['ok' => false, 'message' => 'Impossibile salvare content/site.json. Verifica i permessi del file.'], 500);
        }

        update_html_seo($root, $site);
        json_response(['ok' => true, 'message' => 'Salvato correttamente.', 'savedAt' => format_time(time())]);
    }

    if (is_logged_in() && $action === 'save_content') {
        if (!verify_csrf()) {
            flash('error', 'Sessione scaduta. Riprova.');
            redirect_section($returnSection);
        }

        $incoming = $_POST['site'] ?? [];
        $current = ensure_site_defaults(read_site($contentFile));
        $site = is_array($incoming) ? merge_site_data($current, $incoming) : $current;
        $site = normalize_site($site);
        $saveErrors = [];
        handle_uploads($site, $uploadDir, $uploadBase, $saveErrors, $assetDefinitions);

        if ($saveErrors === [] && !backup_site_file($contentFile, $backupDir)) {
            $saveErrors[] = 'Non riesco a creare il backup automatico. Salvataggio annullato.';
        }

        if ($saveErrors === [] && write_site($contentFile, $site)) {
            update_html_seo($root, $site);
            flash('success', 'Salvato correttamente.');
        } elseif ($saveErrors === []) {
            flash('error', 'Impossibile salvare content/site.json. Verifica i permessi del file.');
        }

        foreach ($saveErrors as $error) {
            flash('error', $error);
        }
        redirect_section($returnSection);
    }

    if (is_logged_in() && $action === 'save_credentials') {
        if (!verify_csrf()) {
            flash('error', 'Sessione scaduta. Riprova.');
            redirect_section('account');
        }

        if (auth_uses_env()) {
            flash('error', 'Le credenziali sono gestite tramite variabili ambiente. Aggiorna CMS_USERNAME e CMS_PASSWORD_HASH sul server.');
            redirect_section('account');
        }

        $currentPassword = (string) ($_POST['current_password'] ?? '');
        $newUser = trim((string) ($_POST['new_username'] ?? ''));
        $newPassword = (string) ($_POST['new_password'] ?? '');
        $confirm = (string) ($_POST['confirm_password'] ?? '');
        $passwordCheck = verify_password_config($config, $currentPassword);
        $credentialErrors = [];

        if (!$passwordCheck['ok']) {
            $credentialErrors[] = 'La vecchia password non è corretta.';
        }
        if ($newUser === '') {
            $credentialErrors[] = 'Il nome utente non può essere vuoto.';
        }
        $credentialErrors = array_merge($credentialErrors, password_errors($newPassword));
        if ($newPassword !== $confirm) {
            $credentialErrors[] = 'La nuova password e la conferma non coincidono.';
        }

        if ($credentialErrors === [] && write_auth_config($configFile, $newUser, $newPassword, $config, $config['session_version'] + 1)) {
            $config = load_auth_config($configFile);
            $_SESSION['cms_user'] = $newUser;
            $_SESSION['cms_session_version'] = (int) $config['session_version'];
            $_SESSION['cms_auth'] = hash('sha256', $config['username'] . '|' . $config['password_hash']);
            flash('success', 'Password aggiornata correttamente.');
        } elseif ($credentialErrors === []) {
            $credentialErrors[] = 'Impossibile aggiornare le credenziali.';
        }

        foreach ($credentialErrors as $error) {
            flash('error', $error);
        }
        redirect_section('account');
    }

    if (is_logged_in() && $action === 'logout_all') {
        if (!verify_csrf()) {
            flash('error', 'Sessione scaduta. Riprova.');
            redirect_section('account');
        }

        $newVersion = ((int) $config['session_version']) + 1;
        if (write_auth_config($configFile, (string) $config['username'], null, $config, $newVersion)) {
            $_SESSION['cms_session_version'] = $newVersion;
            flash('success', 'Le altre sessioni sono state invalidate.');
        } else {
            flash('error', 'Non riesco a invalidare le sessioni.');
        }
        redirect_section('account');
    }

    if (is_logged_in() && $action === 'check_google_calendar') {
        if (!verify_csrf()) {
            flash('error', 'Sessione scaduta. Riprova.');
            redirect_section('prenotazioni');
        }
        define('BOOKING_LIBRARY_ONLY', true);
        require_once dirname(__DIR__) . '/api/prenotazioni.php';
        try {
            verify_calendar_connection($calendarConfig);
            flash('success', 'Collegamento Google verificato: lettura e modifica degli appuntamenti privati consentite. Nessun evento modificato.');
        } catch (Throwable $error) {
            flash('error', $error->getMessage());
        }
        redirect_section('prenotazioni');
    }

    if (is_logged_in() && $action === 'save_google_calendar') {
        if (!verify_csrf()) {
            flash('error', 'Sessione scaduta. Riprova.');
            redirect_section('prenotazioni');
        }

        $calendarId = trim((string) ($_POST['calendar_id'] ?? ''));
        $timezone = trim((string) ($_POST['timezone'] ?? 'Europe/Rome')) ?: 'Europe/Rome';
        $daysAhead = max(1, min(180, (int) ($_POST['days_ahead'] ?? 60)));
        $serviceAccountJson = trim((string) ($_POST['service_account_json'] ?? ''));
        $enabled = ($_POST['booking_enabled'] ?? '') === '1';
        $testMode = ($_POST['test_mode'] ?? '') === '1';
        $dedicated = ($_POST['dedicated_calendar'] ?? '') === '1';
        $privacyReady = ($_POST['privacy_ready'] ?? '') === '1';
        $serviceAccount = is_array($calendarConfig['service_account'] ?? null) ? $calendarConfig['service_account'] : [];
        $calendarErrors = [];

        if ($enabled && $calendarId === '') {
            $calendarErrors[] = 'Inserisci il Google Calendar ID.';
        }
        if ($enabled && !$dedicated) $calendarErrors[] = 'Conferma che il calendario contiene solo disponibilità e appuntamenti del sito.';
        if ($enabled && !$testMode && !$privacyReady) $calendarErrors[] = 'Per le prenotazioni pubbliche occorre completare la verifica privacy. In alternativa usa il collaudo riservato.';
        if (!in_array($timezone, DateTimeZone::listIdentifiers(DateTimeZone::ALL_WITH_BC), true)) {
            $calendarErrors[] = 'Inserisci un fuso orario valido, ad esempio Europe/Rome.';
        }

        if ($serviceAccountJson !== '') {
            $decoded = json_decode($serviceAccountJson, true);
            if (!is_array($decoded) || ($decoded['type'] ?? '') !== 'service_account'
                || !is_string($decoded['client_email'] ?? null) || !filter_var($decoded['client_email'], FILTER_VALIDATE_EMAIL)
                || !is_string($decoded['private_key'] ?? null) || !@openssl_pkey_get_private($decoded['private_key'])) {
                $calendarErrors[] = 'Il JSON dell account di servizio Google non è valido.';
            } else {
                $serviceAccount = array_intersect_key($decoded, array_flip(['client_email', 'private_key']));
            }
        }

        if ($enabled && (empty($serviceAccount['client_email']) || empty($serviceAccount['private_key']))) {
            $calendarErrors[] = 'Per attivare le prenotazioni serve il JSON dell account di servizio.';
        }

        if ($enabled && $calendarErrors === []) {
            define('BOOKING_LIBRARY_ONLY', true);
            require_once dirname(__DIR__) . '/api/prenotazioni.php';
            try {
                verify_calendar_connection(['calendar_id' => $calendarId, 'service_account' => $serviceAccount]);
            } catch (Throwable $error) {
                $calendarErrors[] = $error->getMessage();
            }
        }

        if ($calendarErrors === []) {
            $calendarConfig = [
                'enabled' => $enabled,
                'availability_mode' => 'calendar',
                'dedicated_calendar' => $dedicated,
                'test_mode' => $testMode,
                'privacy_ready' => $privacyReady,
                'calendar_id' => $calendarId,
                'timezone' => $timezone,
                'days_ahead' => $daysAhead,
                'service_account' => $serviceAccount,
            ];

            if (write_google_calendar_config_file($calendarConfigFile, $calendarConfig)) {
                flash('success', 'Configurazione prenotazioni salvata.');
            } else {
                $calendarErrors[] = 'Impossibile salvare la configurazione Google Calendar.';
            }
        }

        foreach ($calendarErrors as $error) {
            flash('error', $error);
        }
        redirect_section('prenotazioni');
    }
}

$site = ensure_site_defaults(read_site($contentFile));
$flashes = consume_flash();
$active = (string) ($_GET['section'] ?? 'dashboard');
if (!array_key_exists($active, $sections)) {
    $active = 'dashboard';
}

if (!is_logged_in()):
?>
<!doctype html>
<html lang="it">
  <head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Accesso CMS | Laura Rossin</title>
    <link rel="stylesheet" href="styles.css">
  </head>
  <body class="login-page">
    <main class="login-card">
      <img src="../assets/logo-laura-rossin.svg" alt="Laura Rossin">
      <p class="eyebrow">Area riservata</p>
      <h1>Accesso CMS</h1>
      <p class="hint">Entra per aggiornare testi, immagini, pagine e prenotazioni del sito.</p>
      <?php foreach ($errors as $error): ?>
        <p class="notice error"><?= e($error) ?></p>
      <?php endforeach; ?>
      <?php if (!$hasCredentials): ?>
        <p class="notice error">Accesso non configurato. Imposta CMS_USERNAME e CMS_PASSWORD_HASH sul server.</p>
      <?php else: ?>
      <form method="post" class="grid">
        <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
        <input type="hidden" name="action" value="login">
        <?php field('Nome utente', 'username', '', 'text', '', ['autocomplete' => 'username']); ?>
        <?php field('Password', 'password', '', 'password', '', ['autocomplete' => 'current-password']); ?>
        <button type="submit">Entra</button>
      </form>
      <?php endif; ?>
    </main>
  </body>
</html>
<?php
exit;
endif;

$lastSaved = is_file($contentFile) ? filemtime($contentFile) : null;
$siteStatus = [
    ['label' => 'File contenuti', 'ok' => is_file($contentFile), 'detail' => is_file($contentFile) ? 'content/site.json trovato' : 'File non trovato'],
    ['label' => 'Scrittura contenuti', 'ok' => is_writable($contentFile), 'detail' => is_writable($contentFile) ? 'Permessi corretti' : 'Non scrivibile'],
    ['label' => 'Upload immagini', 'ok' => is_dir($uploadDir) && is_writable($uploadDir), 'detail' => is_writable($uploadDir) ? 'Cartella pronta' : 'Cartella non scrivibile'],
    ['label' => 'Prenotazioni', 'ok' => service_account_status($calendarConfig) !== 'Non configurato', 'detail' => service_account_status($calendarConfig)],
];
$pageDefinitions = page_defaults();
$backupList = backup_files($backupDir);
?>
<!doctype html>
<html lang="it">
  <head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($sections[$active]) ?> | CMS Laura Rossin</title>
    <link rel="stylesheet" href="styles.css">
  </head>
  <body>
    <div class="admin-shell">
      <aside class="admin-sidebar">
        <a class="admin-brand" href="index.php?section=dashboard" aria-label="Dashboard CMS">
          <img src="../assets/logo-laura-rossin.svg" alt="Laura Rossin">
        </a>
        <nav class="admin-nav" aria-label="Sezioni CMS">
          <?php foreach ($sections as $key => $label): ?>
            <a href="index.php?section=<?= e($key) ?>"<?= $active === $key ? ' aria-current="page"' : '' ?>><?= e($label) ?></a>
          <?php endforeach; ?>
        </nav>
        <form method="post" class="logout-form">
          <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
          <input type="hidden" name="action" value="logout">
          <button type="submit" class="button secondary">Logout</button>
        </form>
      </aside>

      <main class="admin-main">
        <header class="admin-topbar">
          <div>
            <p class="eyebrow">CMS Laura Rossin</p>
            <h1><?= e($sections[$active]) ?></h1>
          </div>
          <div class="topbar-actions">
            <span class="save-state" data-save-state>Ultimo salvataggio: <?= e(format_time($lastSaved)) ?></span>
            <a class="button secondary" href="../index.html" target="_blank" rel="noopener">Vedi sito</a>
          </div>
        </header>

        <?php foreach ($flashes as $flash): ?>
          <p class="notice <?= e((string) ($flash['type'] ?? '')) ?>" data-toast><?= e((string) ($flash['message'] ?? '')) ?></p>
        <?php endforeach; ?>

        <div class="unsaved-banner" data-unsaved-banner hidden>
          Ci sono modifiche non salvate. Salva prima di cambiare pagina o chiudere il browser.
        </div>

        <?php if ($active === 'dashboard'): ?>
          <section class="panel">
            <div class="dashboard-hero">
              <div>
                <p class="eyebrow">Panoramica</p>
                <h2>Uno sguardo rapido allo stato del sito.</h2>
                <p class="hint">Da qui puoi aprire il sito pubblico, controllare l ultimo salvataggio e raggiungere le aree più usate.</p>
              </div>
              <a class="button" href="../index.html" target="_blank" rel="noopener">Vedi sito</a>
            </div>
            <div class="status-grid">
              <?php foreach ($siteStatus as $item): ?>
                <div class="status-card <?= $item['ok'] ? 'ok' : 'warn' ?>">
                  <span><?= $item['ok'] ? 'OK' : 'Da controllare' ?></span>
                  <strong><?= e($item['label']) ?></strong>
                  <p><?= e($item['detail']) ?></p>
                </div>
              <?php endforeach; ?>
            </div>
          </section>

          <section class="panel">
            <div class="section-title">
              <div>
                <h2>Scorciatoie rapide</h2>
                <p class="hint">Le sezioni principali sono separate: niente pagina infinita da scorrere.</p>
              </div>
            </div>
            <div class="shortcut-grid">
              <a href="index.php?section=visual">Editor visuale<span>Clicca i testi del sito e modificali in anteprima</span></a>
              <a href="index.php?section=media">Aggiorna immagini<span>Logo, foto e social preview</span></a>
              <a href="index.php?section=homepage">Modifica homepage<span>Hero, introduzione, CTA e footer</span></a>
              <a href="index.php?section=servizi">Gestisci servizi<span>Ordine, visibilità e duplicazione</span></a>
              <a href="index.php?section=pagine">Modifica pagine<span>Pagine servizio, privacy e cookie</span></a>
              <a href="index.php?section=prenotazioni">Prenotazioni<span>Google Calendar e disponibilità</span></a>
              <a href="index.php?section=account">Account<span>Password e sessioni</span></a>
            </div>
          </section>
        <?php endif; ?>

        <?php if ($active === 'visual'): ?>
          <section class="panel visual-panel" data-visual-editor>
            <input type="hidden" data-visual-csrf value="<?= e($_SESSION['csrf']) ?>">
            <div class="section-title">
              <div>
                <h2>Editor visuale</h2>
                <p class="hint">Scegli una pagina, clicca un testo nell'anteprima e modificalo direttamente. Il salvataggio aggiorna content/site.json e crea un backup automatico.</p>
              </div>
              <div class="visual-actions">
                <button type="button" class="button secondary" data-visual-reload>Ricarica anteprima</button>
                <button type="button" data-visual-save>Salva modifiche</button>
              </div>
            </div>

            <div class="visual-toolbar">
              <label class="field">
                <span>Pagina da modificare</span>
                <select data-visual-page>
                  <option value="home" data-url="../index.html">Homepage</option>
                  <option value="psicoterapia" data-url="../servizi/psicoterapia-breve-strategica-bolzano.html">Psicoterapia</option>
                  <option value="coaching" data-url="../servizi/coaching-professionale-strategico.html">Coaching</option>
                  <option value="lavoro" data-url="../servizi/psicologia-del-lavoro-sportello-aziendale.html">Psicologia del lavoro</option>
                  <option value="mente" data-url="../iniziative/mente-adolescente.html">Mente Adolescente</option>
                  <option value="privacy" data-url="../privacy-policy.html">Privacy policy</option>
                  <option value="servizi" data-url="../servizi.html">Tutti i servizi</option>
                  <option value="metodo" data-url="../metodo.html">Metodo</option>
                  <option value="iniziative" data-url="../iniziative.html">Tutte le iniziative</option>
                  <option value="contatti" data-url="../contatti.html">Contatti e studio</option>
                  <option value="cookie" data-url="../cookie-policy.html">Cookie policy</option>
                </select>
              </label>
              <div class="visual-status" data-visual-status role="status">Anteprima pronta.</div>
            </div>

            <div class="visual-layout">
              <div class="visual-frame-wrap">
                <iframe title="Anteprima modificabile del sito" src="../index.html?cmsPreview=1" data-visual-frame></iframe>
              </div>
              <aside class="visual-inspector">
                <p class="eyebrow">Testo selezionato</p>
                <h3 data-visual-label>Nessun testo selezionato</h3>
                <p class="hint" data-visual-path>Clicca un testo evidenziato nell'anteprima.</p>
                <label class="field">
                  <span>Modifica testo</span>
                  <textarea rows="9" data-visual-text disabled></textarea>
                  <small>Puoi scrivere qui oppure direttamente dentro l'anteprima.</small>
                </label>
                <label class="field" data-visual-link-wrap hidden>
                  <span>Link collegato</span>
                  <input type="text" data-visual-link>
                  <small>Usato per pulsanti, card e collegamenti selezionati.</small>
                </label>
                <div class="visual-image-editor" data-visual-image-wrap hidden>
                  <img src="" alt="" data-visual-image-preview>
                  <p class="hint" data-visual-image-help>JPG, PNG o WebP. Max 5 MB.</p>
                  <label class="field">
                    <span>Carica nuova immagine</span>
                    <input type="file" accept=".jpg,.jpeg,.png,.webp" data-visual-image-file>
                    <small>L'anteprima cambia subito; usa Salva modifiche per confermare nel contenuto.</small>
                  </label>
                  <label class="field">
                    <span>Percorso immagine</span>
                    <input type="text" data-visual-image-path>
                    <small>Opzione avanzata: modifica manuale del path se serve.</small>
                  </label>
                  <label class="field" data-visual-image-alt-wrap hidden>
                    <span>Testo alternativo</span>
                    <input type="text" data-visual-image-alt>
                    <small>Descrive l'immagine per accessibilita e SEO.</small>
                  </label>
                </div>
              </aside>
            </div>
          </section>

          <script type="application/json" id="visual-site-data"><?= json_encode($site, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
          <script type="application/json" id="visual-asset-data"><?= json_encode($assetDefinitions, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
        <?php endif; ?>

        <?php if ($active === 'media'): ?>
          <form method="post" enctype="multipart/form-data" data-dirty-form data-media-form>
            <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
            <input type="hidden" name="action" value="save_content">
            <input type="hidden" name="section" value="media">
            <section class="panel">
              <div class="section-title">
                <div>
                  <h2>Media</h2>
                  <p class="hint">Carica un file e controlla l anteprima prima di salvare. I percorsi manuali restano disponibili nelle opzioni avanzate.</p>
                </div>
                <button type="submit">Salva media</button>
              </div>
              <div class="media-grid">
                <?php foreach ($assetDefinitions as $key => $definition): ?>
                  <?php $src = value($site, ['assets', $key]); ?>
                  <article class="media-card">
                    <div class="media-thumb">
                      <img src="../<?= e($src) ?>" alt="" data-preview-image="<?= e($key) ?>">
                    </div>
                    <div class="media-body">
                      <h3><?= e($definition['label']) ?></h3>
                      <p class="hint"><?= e($definition['recommended']) ?> Formati accettati: JPG, PNG, WebP. Peso massimo: 5 MB.</p>
                      <label class="upload-control">
                        <span>Carica nuovo file</span>
                        <input type="file" name="uploads[<?= e($key) ?>]" accept=".jpg,.jpeg,.png,.webp" data-media-input="<?= e($key) ?>">
                      </label>
                      <p class="field-error" data-media-error="<?= e($key) ?>"></p>
                      <details class="advanced">
                        <summary>Modifica percorso manualmente</summary>
                        <?php field('Percorso attuale', 'site[assets][' . $key . ']', $src, 'text', 'Da usare solo se il file è già presente sul server.'); ?>
                      </details>
                    </div>
                  </article>
                <?php endforeach; ?>
              </div>
              <div class="form-actions">
                <button type="submit">Salva media</button>
              </div>
            </section>
          </form>
        <?php endif; ?>

        <?php if ($active === 'homepage'): ?>
          <form method="post" data-dirty-form>
            <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
            <input type="hidden" name="action" value="save_content">
            <input type="hidden" name="section" value="homepage">
            <section class="panel">
              <div class="section-title">
                <div>
                  <h2>Homepage a blocchi</h2>
                  <p class="hint">Ogni blocco si apre e si salva separatamente, senza attraversare tutta la pagina.</p>
                </div>
                <button type="submit">Salva homepage</button>
              </div>

              <details class="editor-card" open>
                <summary>Hero</summary>
                <div class="grid two">
                  <?php field('Etichetta', 'site[home][hero][eyebrow]', value($site, ['home', 'hero', 'eyebrow'])); ?>
                  <?php field('Titolo principale', 'site[home][hero][title]', value($site, ['home', 'hero', 'title'])); ?>
                  <?php area('Testo introduttivo', 'site[home][hero][lead]', value($site, ['home', 'hero', 'lead']), 4); ?>
                  <div class="grid">
                    <?php field('Testo pulsante principale', 'site[home][hero][primaryLabel]', value($site, ['home', 'hero', 'primaryLabel'])); ?>
                    <?php field('Link pulsante principale', 'site[home][hero][primaryHref]', value($site, ['home', 'hero', 'primaryHref'])); ?>
                    <?php field('Testo pulsante secondario', 'site[home][hero][secondaryLabel]', value($site, ['home', 'hero', 'secondaryLabel'])); ?>
                    <?php field('Link pulsante secondario', 'site[home][hero][secondaryHref]', value($site, ['home', 'hero', 'secondaryHref'])); ?>
                  </div>
                </div>
              </details>

              <details class="editor-card">
                <summary>Introduzione</summary>
                <div class="grid two">
                  <?php field('Etichetta', 'site[home][intro][eyebrow]', value($site, ['home', 'intro', 'eyebrow'])); ?>
                  <?php field('Titolo', 'site[home][intro][title]', value($site, ['home', 'intro', 'title'])); ?>
                  <?php area('Testo', 'site[home][intro][text]', value($site, ['home', 'intro', 'text']), 6); ?>
                </div>
              </details>

              <details class="editor-card">
                <summary>Servizi in evidenza</summary>
                <div class="grid two">
                  <?php field('Etichetta sezione', 'site[home][services][eyebrow]', value($site, ['home', 'services', 'eyebrow'])); ?>
                  <?php field('Titolo sezione', 'site[home][services][title]', value($site, ['home', 'services', 'title'])); ?>
                </div>
                <p class="hint">Le singole card si gestiscono nella sezione Servizi.</p>
              </details>

              <details class="editor-card">
                <summary>Chi sono</summary>
                <?php $trust = $site['home']['trust'] ?? []; ?>
                <?php foreach ($trust as $i => $item): ?>
                  <div class="mini-card">
                    <div class="grid three">
                      <?php field('Titolo', 'site[home][trust][' . $i . '][title]', (string) ($item['title'] ?? '')); ?>
                      <?php field('Testo', 'site[home][trust][' . $i . '][text]', (string) ($item['text'] ?? '')); ?>
                      <?php field('Link', 'site[home][trust][' . $i . '][href]', (string) ($item['href'] ?? ''), 'text', 'Albo e approccio possono puntare a siti esterni.'); ?>
                    </div>
                  </div>
                <?php endforeach; ?>
                <div class="grid two">
                  <?php $gallery = array_merge($site['home']['gallery'] ?? [], [['src' => '', 'alt' => '']]); ?>
                  <?php foreach ($gallery as $i => $item): ?>
                    <div class="mini-card">
                      <?php toggle_field('Mostra questa immagine', 'site[home][gallery][' . $i . '][visible]', array_key_exists('visible', $item) ? truthy($item['visible']) : true, 'Utile se vuoi nascondere la foto dello studio senza cancellarla.'); ?>
                      <?php field('Immagine studio', 'site[home][gallery][' . $i . '][src]', (string) ($item['src'] ?? '')); ?>
                      <?php field('Testo alternativo', 'site[home][gallery][' . $i . '][alt]', (string) ($item['alt'] ?? '')); ?>
                    </div>
                  <?php endforeach; ?>
                </div>
              </details>

              <details class="editor-card">
                <summary>Metodo</summary>
                <div class="grid two">
                  <?php field('Etichetta', 'site[home][method][eyebrow]', value($site, ['home', 'method', 'eyebrow'])); ?>
                  <?php field('Titolo', 'site[home][method][title]', value($site, ['home', 'method', 'title'])); ?>
                  <?php area('Testo', 'site[home][method][text]', value($site, ['home', 'method', 'text']), 4); ?>
                </div>
                <?php $steps = array_merge($site['home']['method']['steps'] ?? [], [['number' => '', 'title' => '', 'text' => '']]); ?>
                <?php foreach ($steps as $i => $item): ?>
                  <div class="mini-card">
                    <div class="grid three">
                      <?php field('Numero', 'site[home][method][steps][' . $i . '][number]', (string) ($item['number'] ?? '')); ?>
                      <?php field('Titolo', 'site[home][method][steps][' . $i . '][title]', (string) ($item['title'] ?? '')); ?>
                      <?php area('Testo', 'site[home][method][steps][' . $i . '][text]', (string) ($item['text'] ?? ''), 2); ?>
                    </div>
                  </div>
                <?php endforeach; ?>
              </details>

              <details class="editor-card">
                <summary>Iniziative</summary>
                <div class="grid two">
                  <?php field('Etichetta sezione', 'site[home][initiative][eyebrow]', value($site, ['home', 'initiative', 'eyebrow'])); ?>
                  <?php field('Titolo sezione', 'site[home][initiative][title]', value($site, ['home', 'initiative', 'title'])); ?>
                  <?php field('Etichetta card', 'site[home][initiative][label]', value($site, ['home', 'initiative', 'label'])); ?>
                  <?php field('Nome iniziativa', 'site[home][initiative][name]', value($site, ['home', 'initiative', 'name'])); ?>
                  <?php area('Testo iniziativa', 'site[home][initiative][text]', value($site, ['home', 'initiative', 'text']), 4); ?>
                  <div class="grid">
                    <?php field('Testo pulsante', 'site[home][initiative][buttonLabel]', value($site, ['home', 'initiative', 'buttonLabel'])); ?>
                    <?php field('Link pulsante', 'site[home][initiative][href]', value($site, ['home', 'initiative', 'href'])); ?>
                  </div>
                </div>
              </details>

              <details class="editor-card" data-topic-editor>
                <summary>Argomenti utili / articoli</summary>
                <div class="grid two">
                  <?php field('Etichetta sezione', 'site[home][topics][eyebrow]', value($site, ['home', 'topics', 'eyebrow'])); ?>
                  <?php field('Titolo sezione', 'site[home][topics][title]', value($site, ['home', 'topics', 'title'])); ?>
                </div>
                <div class="section-title compact">
                  <p class="hint">Gli argomenti visibili diventano link a pagine articolo. Puoi crearne, nasconderne o eliminarne uno senza toccare i servizi.</p>
                  <button type="button" class="button secondary" data-add-topic>Aggiungi articolo</button>
                </div>
                <div data-topic-list>
                  <?php foreach (($site['home']['topics']['items'] ?? []) as $i => $item): ?>
                    <?php render_topic_item($item, $i); ?>
                  <?php endforeach; ?>
                </div>
                <template data-topic-template>
                  <?php render_topic_item(['visible' => true, 'order' => 1, 'label' => '', 'slug' => '', 'title' => '', 'excerpt' => '', 'socialImage' => value($site, ['assets', 'ogImage']), 'heroImage' => value($site, ['assets', 'articleIllustration']), 'sections' => []], '__INDEX__'); ?>
                </template>
              </details>

              <details class="editor-card">
                <summary>FAQ</summary>
                <div class="grid two">
                  <?php field('Etichetta sezione', 'site[home][faq][eyebrow]', value($site, ['home', 'faq', 'eyebrow'])); ?>
                  <?php field('Titolo sezione', 'site[home][faq][title]', value($site, ['home', 'faq', 'title'])); ?>
                </div>
                <?php $faqItems = array_merge($site['home']['faq']['items'] ?? [], [['question' => '', 'answer' => '']]); ?>
                <?php foreach ($faqItems as $i => $item): ?>
                  <div class="mini-card">
                    <div class="grid two">
                      <?php field('Domanda', 'site[home][faq][items][' . $i . '][question]', (string) ($item['question'] ?? '')); ?>
                      <?php area('Risposta', 'site[home][faq][items][' . $i . '][answer]', (string) ($item['answer'] ?? ''), 4); ?>
                    </div>
                  </div>
                <?php endforeach; ?>
              </details>

              <details class="editor-card">
                <summary>Call to action</summary>
                <div class="grid two">
                  <?php field('Etichetta contatti', 'site[home][contact][eyebrow]', value($site, ['home', 'contact', 'eyebrow'])); ?>
                  <?php field('Titolo contatti', 'site[home][contact][title]', value($site, ['home', 'contact', 'title'])); ?>
                  <?php area('Testo contatti', 'site[home][contact][text]', value($site, ['home', 'contact', 'text']), 4); ?>
                  <div class="grid">
                    <?php field('Email', 'site[home][contact][email]', value($site, ['home', 'contact', 'email'])); ?>
                    <?php field('Telefono visibile', 'site[home][contact][phone]', value($site, ['home', 'contact', 'phone'])); ?>
                    <?php field('Telefono per link', 'site[home][contact][phoneHref]', value($site, ['home', 'contact', 'phoneHref'])); ?>
                    <?php field('Città', 'site[home][contact][cities]', value($site, ['home', 'contact', 'cities'])); ?>
                    <?php field('Partita IVA', 'site[home][contact][vat]', value($site, ['home', 'contact', 'vat'])); ?>
                  </div>
                </div>
              </details>

              <details class="editor-card">
                <summary>Footer</summary>
                <div class="grid two">
                  <?php field('Footer riga 1', 'site[home][footer][line1]', value($site, ['home', 'footer', 'line1'])); ?>
                  <?php field('Footer riga 2', 'site[home][footer][line2]', value($site, ['home', 'footer', 'line2'])); ?>
                  <?php field('Testo powered by', 'site[home][footer][poweredBy][label]', value($site, ['home', 'footer', 'poweredBy', 'label'], 'Powered by Archimpresa')); ?>
                  <?php field('Logo powered by', 'site[home][footer][poweredBy][logo]', value($site, ['home', 'footer', 'poweredBy', 'logo'], 'assets/archimpresa-logo.svg')); ?>
                  <?php field('Link powered by', 'site[home][footer][poweredBy][href]', value($site, ['home', 'footer', 'poweredBy', 'href']), 'text', 'Facoltativo. Lascia vuoto se non deve essere cliccabile.'); ?>
                </div>
              </details>
              <div class="form-actions">
                <button type="submit">Salva homepage</button>
              </div>
            </section>
          </form>
        <?php endif; ?>

        <?php if ($active === 'servizi'): ?>
          <form method="post" data-dirty-form data-service-editor>
            <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
            <input type="hidden" name="action" value="save_content">
            <input type="hidden" name="section" value="servizi">
            <section class="panel">
              <div class="section-title">
                <div>
                  <h2>Servizi</h2>
                  <p class="hint">Gestisci le card della homepage: puoi aggiungere, duplicare, nascondere, eliminare e riordinare i servizi.</p>
                </div>
                <button type="button" class="button secondary" data-add-service>Aggiungi servizio</button>
              </div>
              <div data-service-list>
                <?php foreach (($site['home']['services']['items'] ?? []) as $i => $item): ?>
                  <?php render_service_item($item, $i); ?>
                <?php endforeach; ?>
              </div>
              <template data-service-template>
                <?php render_service_item(['visible' => true, 'order' => 1, 'title' => '', 'text' => '', 'href' => '', 'linkLabel' => ''], '__INDEX__'); ?>
              </template>
              <div class="form-actions">
                <button type="submit">Salva servizi</button>
              </div>
            </section>
          </form>
        <?php endif; ?>

        <?php if ($active === 'pagine'): ?>
          <form method="post" data-dirty-form>
            <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
            <input type="hidden" name="action" value="save_content">
            <input type="hidden" name="section" value="pagine">
            <section class="panel">
              <div class="section-title">
                <div>
                  <h2>Pagine</h2>
                  <p class="hint">Privacy policy e Cookie policy sono pagine locali del sito, modificabili qui. I testi sono segnaposto da far validare a un professionista.</p>
                </div>
                <button type="submit">Salva pagine</button>
              </div>

              <div class="page-list">
                <?php foreach ($pageDefinitions as $key => $definition): ?>
                  <?php $page = $site['pages'][$key] ?? []; ?>
                  <details class="page-card">
                    <summary>
                      <span>
                        <strong><?= e($definition['label']) ?></strong>
                        <small><?= truthy($page['published'] ?? true) ? 'Pubblicata' : 'Nascosta' ?> · <?= e((string) ($page['slug'] ?? $definition['slug'])) ?></small>
                      </span>
                    </summary>
                    <div class="grid two">
                      <?php toggle_field('Pagina pubblicata', 'site[pages][' . $key . '][published]', truthy($page['published'] ?? true), 'Se nascosta, il contenuto viene oscurato via CMS sul sito pubblico.'); ?>
                      <?php field('Titolo pagina', 'site[pages][' . $key . '][title]', (string) ($page['title'] ?? $definition['title'])); ?>
                      <?php field('Slug / percorso', 'site[pages][' . $key . '][slug]', (string) ($page['slug'] ?? $definition['slug'])); ?>
                      <?php field('Meta title', 'site[pages][' . $key . '][seo][title]', (string) ($page['seo']['title'] ?? '')); ?>
                      <?php area('Meta description', 'site[pages][' . $key . '][seo][description]', (string) ($page['seo']['description'] ?? ''), 3); ?>
                      <?php field('Immagine social', 'site[pages][' . $key . '][socialImage]', (string) ($page['socialImage'] ?? ''), 'text', 'Consigliato: 1200 x 630 px.'); ?>
                    </div>
                    <div class="subsection">
                      <h3>Hero e contenuto</h3>
                      <div class="grid two">
                        <?php field('Etichetta hero', 'site[pages][' . $key . '][hero][eyebrow]', (string) ($page['hero']['eyebrow'] ?? '')); ?>
                        <?php field('Titolo hero', 'site[pages][' . $key . '][hero][title]', (string) ($page['hero']['title'] ?? '')); ?>
                        <?php area('Testo hero', 'site[pages][' . $key . '][hero][text]', (string) ($page['hero']['text'] ?? ''), 4); ?>
                        <div class="grid">
                          <?php field('Testo pulsante', 'site[pages][' . $key . '][hero][buttonLabel]', (string) ($page['hero']['buttonLabel'] ?? '')); ?>
                          <?php field('Link pulsante', 'site[pages][' . $key . '][hero][buttonHref]', (string) ($page['hero']['buttonHref'] ?? '')); ?>
                          <?php field('Immagine hero', 'site[pages][' . $key . '][hero][image]', (string) ($page['hero']['image'] ?? '')); ?>
                          <?php field('Alt immagine', 'site[pages][' . $key . '][hero][imageAlt]', (string) ($page['hero']['imageAlt'] ?? '')); ?>
                        </div>
                        <?php field('Titolo nota laterale', 'site[pages][' . $key . '][sideNoteTitle]', (string) ($page['sideNoteTitle'] ?? '')); ?>
                        <?php area('Voci nota laterale, una per riga', 'site[pages][' . $key . '][sideNotesText]', array_to_lines($page['sideNotes'] ?? []), 4); ?>
                      </div>
                      <?php $sectionsForPage = array_merge($page['sections'] ?? [], [['title' => '', 'text' => '', 'items' => []]]); ?>
                      <?php foreach ($sectionsForPage as $i => $section): ?>
                        <div class="mini-card">
                          <p class="row-title">Sezione <?= (int) $i + 1 ?></p>
                          <div class="grid two">
                            <?php field('Titolo sezione', 'site[pages][' . $key . '][sections][' . $i . '][title]', (string) ($section['title'] ?? '')); ?>
                            <?php area('Testo sezione', 'site[pages][' . $key . '][sections][' . $i . '][text]', (string) ($section['text'] ?? ''), 5); ?>
                            <?php area('Elenco puntato, una voce per riga', 'site[pages][' . $key . '][sections][' . $i . '][itemsText]', array_to_lines($section['items'] ?? []), 4); ?>
                          </div>
                        </div>
                      <?php endforeach; ?>
                    </div>
                  </details>
                <?php endforeach; ?>
              </div>
              <div class="form-actions">
                <button type="submit">Salva pagine</button>
              </div>
            </section>
          </form>
        <?php endif; ?>

        <?php if ($active === 'prenotazioni'): ?>
          <section class="panel">
            <div class="section-title">
              <div>
                <h2>Prenotazioni</h2>
                <p class="hint">Nel calendario dedicato Prenotazioni ogni evento singolo con giorno e orario diventa una disponibilità: non serve #disp. Non inserire qui impegni personali. Sono esclusi gli eventi privati, ricorrenti, con invitati, di giornata intera, già prenotati o con titolo che inizia per Appuntamento o Occupato.</p>
              </div>
              <a class="button secondary" href="../prenotazioni.html" target="_blank" rel="noopener">Apri pagina prenotazioni</a>
            </div>
            <div class="status-card <?= service_account_status($calendarConfig) === 'Non configurato' ? 'warn' : 'ok' ?>">
              <span>Google Calendar</span>
              <strong><?= e(service_account_status($calendarConfig)) ?></strong>
              <p>Condividi il calendario con l’account di servizio scegliendo Apporta modifiche e visualizza i dettagli degli eventi. Non serve la gestione delle condivisioni.</p>
              <form method="post">
                <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
                <input type="hidden" name="action" value="check_google_calendar">
                <button class="button secondary" type="submit">Verifica collegamento Google</button>
              </form>
            </div>
            <form method="post" data-dirty-form class="settings-form">
              <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
              <input type="hidden" name="action" value="save_google_calendar">
              <input type="hidden" name="section" value="prenotazioni">
              <?php toggle_field('Attiva prenotazioni online', 'booking_enabled', ($calendarConfig['enabled'] ?? false) === true, 'Attiva solo dopo aver verificato il calendario e aggiornato l informativa privacy.'); ?>
              <?php toggle_field('Collaudo riservato al CMS', 'test_mode', ($calendarConfig['test_mode'] ?? true) === true, 'Solo chi ha effettuato accesso al CMS vede gli orari. Usa eventi e dati fittizi, poi elimina gli eventi di prova.'); ?>
              <?php toggle_field('Calendario dedicato confermato', 'dedicated_calendar', ($calendarConfig['dedicated_calendar'] ?? false) === true, 'Confermo che questo ID identifica il calendario Prenotazioni, senza altri impegni o dati di pazienti. Gli altri calendari non vengono letti.'); ?>
              <?php toggle_field('Verifica privacy per apertura pubblica completata', 'privacy_ready', ($calendarConfig['privacy_ready'] ?? false) === true, 'Da confermare dalla titolare: informativa definitiva, conservazione, account Google idoneo, accordi con fornitori e trasferimenti extra SEE. Non necessario per il collaudo con dati fittizi.'); ?>
              <div class="grid two">
                <?php field('Google Calendar ID', 'calendar_id', (string) ($calendarConfig['calendar_id'] ?? ''), 'text', 'Di solito è un indirizzo email o una stringa lunga del calendario.'); ?>
                <?php field('Fuso orario', 'timezone', (string) ($calendarConfig['timezone'] ?? 'Europe/Rome')); ?>
                <?php field('Giorni mostrati in avanti', 'days_ahead', (string) ($calendarConfig['days_ahead'] ?? '60'), 'number', 'Valore consigliato: 30-90 giorni.', ['min' => '1', 'max' => '180']); ?>
                <?php area('JSON account di servizio Google', 'service_account_json', '', 8, 'Lascia vuoto per mantenere quello già salvato.'); ?>
              </div>
              <div class="form-actions">
                <button type="submit">Salva prenotazioni</button>
              </div>
            </form>
          </section>
        <?php endif; ?>

        <?php if ($active === 'seo'): ?>
          <form method="post" data-dirty-form>
            <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
            <input type="hidden" name="action" value="save_content">
            <input type="hidden" name="section" value="seo">
            <section class="panel">
              <div class="section-title">
                <div>
                  <h2>SEO / Condivisione</h2>
                  <p class="hint">Qui gestisci titolo e descrizione della homepage e l immagine usata quando il sito viene condiviso.</p>
                </div>
                <button type="submit">Salva SEO</button>
              </div>
              <div class="grid two">
                <?php field('Titolo SEO homepage', 'site[home][seo][title]', value($site, ['home', 'seo', 'title']), 'text', 'Consigliato: 50-60 caratteri.'); ?>
                <?php field('Titolo social', 'site[home][seo][ogTitle]', value($site, ['home', 'seo', 'ogTitle'])); ?>
                <?php area('Descrizione SEO homepage', 'site[home][seo][description]', value($site, ['home', 'seo', 'description']), 3, 'Consigliato: 140-160 caratteri.'); ?>
                <?php area('Descrizione social', 'site[home][seo][ogDescription]', value($site, ['home', 'seo', 'ogDescription']), 3); ?>
                <?php field('Immagine social globale', 'site[assets][ogImage]', value($site, ['assets', 'ogImage']), 'text', 'Consigliato: 1200 x 630 px.'); ?>
              </div>
              <div class="form-actions">
                <button type="submit">Salva SEO</button>
              </div>
            </section>
          </form>
        <?php endif; ?>

        <?php if ($active === 'account'): ?>
          <section class="panel">
            <div class="section-title">
              <div>
                <h2>Account</h2>
                <p class="hint">La password non viene salvata in chiaro. Se usi variabili ambiente, aggiorna CMS_USERNAME e CMS_PASSWORD_HASH sul server.</p>
              </div>
            </div>
            <form method="post" data-dirty-form class="settings-form">
              <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
              <input type="hidden" name="action" value="save_credentials">
              <div class="grid two">
                <?php field('Nome utente', 'new_username', (string) $config['username'], 'text', '', ['autocomplete' => 'username']); ?>
                <?php field('Vecchia password', 'current_password', '', 'password', 'Obbligatoria per confermare il cambio.', ['autocomplete' => 'current-password']); ?>
                <?php field('Nuova password', 'new_password', '', 'password', 'Almeno 12 caratteri, con maiuscola, minuscola e numero.', ['autocomplete' => 'new-password']); ?>
                <?php field('Conferma nuova password', 'confirm_password', '', 'password', '', ['autocomplete' => 'new-password']); ?>
              </div>
              <div class="form-actions">
                <button type="submit">Aggiorna password</button>
              </div>
            </form>
            <div class="account-actions">
              <form method="post">
                <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
                <input type="hidden" name="action" value="logout_all">
                <button type="submit" class="button secondary">Logout da tutte le sessioni</button>
              </form>
              <form method="post">
                <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
                <input type="hidden" name="action" value="logout">
                <button type="submit" class="button secondary">Logout</button>
              </form>
            </div>
          </section>
        <?php endif; ?>

        <?php if ($active === 'impostazioni'): ?>
          <section class="panel">
            <div class="section-title">
              <div>
                <h2>Impostazioni</h2>
                <p class="hint">Informazioni tecniche essenziali, in linguaggio leggibile.</p>
              </div>
            </div>
            <div class="status-grid">
              <div class="status-card <?= is_writable($contentFile) ? 'ok' : 'warn' ?>">
                <span>Contenuti</span>
                <strong>content/site.json</strong>
                <p><?= is_file($contentFile) ? format_bytes((int) filesize($contentFile)) : 'File non trovato' ?></p>
              </div>
              <div class="status-card <?= is_writable($uploadDir) ? 'ok' : 'warn' ?>">
                <span>Upload</span>
                <strong>assets/uploads</strong>
                <p><?= is_writable($uploadDir) ? 'Pronta per nuovi file' : 'Non scrivibile' ?></p>
              </div>
              <div class="status-card ok">
                <span>Sessione</span>
                <strong><?= (int) round($sessionLifetime / 60) ?> minuti</strong>
                <p>Scadenza automatica dopo inattività.</p>
              </div>
            </div>
            <div class="subsection">
              <h3>Ultimi backup automatici</h3>
              <?php if ($backupList === []): ?>
                <p class="hint">I backup compariranno qui dopo il primo salvataggio dei contenuti.</p>
              <?php else: ?>
                <ul class="backup-list">
                  <?php foreach ($backupList as $file): ?>
                    <li><strong><?= e(basename($file)) ?></strong><span><?= e(format_time(filemtime($file))) ?> · <?= e(format_bytes((int) filesize($file))) ?></span></li>
                  <?php endforeach; ?>
                </ul>
              <?php endif; ?>
            </div>
          </section>
        <?php endif; ?>
      </main>
    </div>

    <script>
      (function () {
        const banner = document.querySelector("[data-unsaved-banner]");
        const saveState = document.querySelector("[data-save-state]");
        let dirty = false;

        const setDirty = (value) => {
          dirty = value;
          if (banner) banner.hidden = !dirty;
          if (saveState) saveState.classList.toggle("dirty", dirty);
        };

        document.querySelectorAll("[data-dirty-form]").forEach((form) => {
          form.addEventListener("input", () => setDirty(true));
          form.addEventListener("change", () => setDirty(true));
          form.addEventListener("submit", () => setDirty(false));
        });

        window.addEventListener("beforeunload", (event) => {
          if (!dirty) return;
          event.preventDefault();
          event.returnValue = "";
        });

        document.querySelectorAll("[data-media-input]").forEach((input) => {
          input.addEventListener("change", () => {
            const key = input.dataset.mediaInput;
            const file = input.files && input.files[0];
            const preview = document.querySelector('[data-preview-image="' + key + '"]');
            const error = document.querySelector('[data-media-error="' + key + '"]');
            if (error) error.textContent = "";
            if (!file) return;

            const allowed = ["image/jpeg", "image/png", "image/webp"];
            const allowedName = /\.(jpe?g|png|webp|svg)$/i.test(file.name);
            if ((!allowed.includes(file.type) && !allowedName) || file.size > 5 * 1024 * 1024) {
              if (error) error.textContent = "Formato non valido o file oltre 5 MB. Usa JPG, PNG o WebP.";
              input.value = "";
              return;
            }

            const reader = new FileReader();
            reader.onload = () => {
              if (preview) preview.src = String(reader.result || "");
            };
            reader.readAsDataURL(file);
          });
        });

        const editor = document.querySelector("[data-service-editor]");
        if (editor) {
          const list = editor.querySelector("[data-service-list]");
          const template = editor.querySelector("[data-service-template]");
          const reindex = () => {
            Array.from(list.querySelectorAll("[data-service-row]")).forEach((row, index) => {
              row.querySelectorAll("[name]").forEach((field) => {
                field.name = field.name.replace(/\[items\]\[[^\]]+\]/, "[items][" + index + "]");
              });
              const order = row.querySelector('[name$="[order]"]');
              if (order && !order.matches(":focus")) order.value = String(index + 1);
            });
          };

          editor.addEventListener("click", (event) => {
            const button = event.target.closest("button");
            if (!button) return;
            const row = button.closest("[data-service-row]");

            if (button.matches("[data-add-service]") && template) {
              const html = template.innerHTML.replaceAll("__INDEX__", String(list.children.length));
              list.insertAdjacentHTML("beforeend", html);
              reindex();
              setDirty(true);
            }

            if (row && button.matches("[data-duplicate-service]")) {
              const clone = row.cloneNode(true);
              row.after(clone);
              reindex();
              setDirty(true);
            }

            if (row && button.matches("[data-delete-service]")) {
              if (confirm("Eliminare questo servizio? L azione sarà definitiva dopo il salvataggio.")) {
                row.remove();
                reindex();
                setDirty(true);
              }
            }

            if (row && button.matches("[data-move-service]")) {
              const direction = Number(button.dataset.moveService || 0);
              if (direction < 0 && row.previousElementSibling) {
                list.insertBefore(row, row.previousElementSibling);
              }
              if (direction > 0 && row.nextElementSibling) {
                list.insertBefore(row.nextElementSibling, row);
              }
              reindex();
              setDirty(true);
            }
          });
        }

        const topicEditor = document.querySelector("[data-topic-editor]");
        if (topicEditor) {
          const list = topicEditor.querySelector("[data-topic-list]");
          const template = topicEditor.querySelector("[data-topic-template]");
          const reindex = () => {
            Array.from(list.querySelectorAll("[data-topic-row]")).forEach((row, index) => {
              row.querySelectorAll("[name]").forEach((field) => {
                field.name = field.name.replace(/\[items\]\[[^\]]+\]/, "[items][" + index + "]");
              });
              const order = row.querySelector('[name$="[order]"]');
              if (order && !order.matches(":focus")) order.value = String(index + 1);
            });
          };

          topicEditor.addEventListener("click", (event) => {
            const button = event.target.closest("button");
            if (!button) return;
            const row = button.closest("[data-topic-row]");

            if (button.matches("[data-add-topic]") && template) {
              const html = template.innerHTML.replaceAll("__INDEX__", String(list.children.length));
              list.insertAdjacentHTML("beforeend", html);
              reindex();
              setDirty(true);
            }

            if (row && button.matches("[data-duplicate-topic]")) {
              const clone = row.cloneNode(true);
              row.after(clone);
              reindex();
              setDirty(true);
            }

            if (row && button.matches("[data-delete-topic]")) {
              if (confirm("Eliminare questo articolo? L azione sarà definitiva dopo il salvataggio.")) {
                row.remove();
                reindex();
                setDirty(true);
              }
            }

            if (row && button.matches("[data-move-topic]")) {
              const direction = Number(button.dataset.moveTopic || 0);
              if (direction < 0 && row.previousElementSibling) {
                list.insertBefore(row, row.previousElementSibling);
              }
              if (direction > 0 && row.nextElementSibling) {
                list.insertBefore(row.nextElementSibling, row);
              }
              reindex();
              setDirty(true);
            }
          });
        }

        const visualEditor = document.querySelector("[data-visual-editor]");
        if (visualEditor) {
          let visualSite = JSON.parse(document.getElementById("visual-site-data").textContent || "{}");
          const frame = visualEditor.querySelector("[data-visual-frame]");
          const pageSelect = visualEditor.querySelector("[data-visual-page]");
          const saveButton = visualEditor.querySelector("[data-visual-save]");
          const reloadButton = visualEditor.querySelector("[data-visual-reload]");
          const status = visualEditor.querySelector("[data-visual-status]");
          const label = visualEditor.querySelector("[data-visual-label]");
          const pathLabel = visualEditor.querySelector("[data-visual-path]");
          const textInput = visualEditor.querySelector("[data-visual-text]");
          const linkWrap = visualEditor.querySelector("[data-visual-link-wrap]");
          const linkInput = visualEditor.querySelector("[data-visual-link]");
          const imageWrap = visualEditor.querySelector("[data-visual-image-wrap]");
          const imagePreview = visualEditor.querySelector("[data-visual-image-preview]");
          const imageHelp = visualEditor.querySelector("[data-visual-image-help]");
          const imageFile = visualEditor.querySelector("[data-visual-image-file]");
          const imagePathInput = visualEditor.querySelector("[data-visual-image-path]");
          const imageAltWrap = visualEditor.querySelector("[data-visual-image-alt-wrap]");
          const imageAltInput = visualEditor.querySelector("[data-visual-image-alt]");
          const csrf = visualEditor.querySelector("[data-visual-csrf]")?.value || "";
          const visualAssetDefinitions = JSON.parse(document.getElementById("visual-asset-data")?.textContent || "{}");
          let selected = null;

          const setVisualStatus = (message, type = "") => {
            if (!status) return;
            status.textContent = message;
            status.dataset.type = type;
          };

          const toParts = (path) => String(path).split(".").filter(Boolean);

          const getPath = (source, path) => {
            return toParts(path).reduce((current, part) => {
              if (current === undefined || current === null) return undefined;
              return current[part];
            }, source);
          };

          const setPath = (source, path, value) => {
            const parts = toParts(path);
            const last = parts.pop();
            const parent = parts.reduce((current, part, index) => {
              if (current[part] === undefined || current[part] === null) {
                const nextPart = parts[index + 1] ?? last;
                current[part] = /^\d+$/.test(String(nextPart)) ? [] : {};
              }
              return current[part];
            }, source);
            parent[last] = value;
          };

          const absolutePreviewHref = (href) => {
            if (!href) return "#";
            if (/^(https?:|mailto:|tel:|#|\/)/.test(href)) return href;
            return "../" + href;
          };

          const previewAssetHref = (src) => {
            if (!src) return "";
            if (/^(https?:|data:|blob:|\/)/.test(src)) return src;
            const url = pageSelect.selectedOptions[0]?.dataset.url || "../index.html";
            const nested = url.includes("../servizi/") || url.includes("../iniziative/");
            return nested ? "../" + src : src;
          };

          const cssImageUrl = (src) => {
            const href = previewAssetHref(src);
            return href ? `url("${href.replace(/"/g, "%22")}")` : "";
          };

          const inspectorAssetHref = (src) => src ? new URL(previewAssetHref(src), frame.src).href : "";

          const readCmsResponse = async (response) => {
            if (response.redirected || !response.headers.get("content-type")?.includes("application/json")) {
              throw new Error("Sessione scaduta. Accedi di nuovo al CMS. Le modifiche in anteprima non sono state salvate.");
            }
            return response.json();
          };

          const visibleIndexes = (rows = []) => rows
            .map((item, index) => ({ item, index }))
            .filter(({ item }) => ![false, 0, "0"].includes(item.visible))
            .sort((a, b) => Number(a.item.order || 0) - Number(b.item.order || 0))
            .map(({ index }) => index);

          const imageHelpFor = (path, fallback = "") => {
            const parts = toParts(path);
            if (parts[0] === "assets" && visualAssetDefinitions[parts[1]]?.recommended) {
              return visualAssetDefinitions[parts[1]].recommended;
            }
            return fallback || "JPG, PNG o WebP. Max 5 MB.";
          };

          const addTopicOptions = () => {
            if (!pageSelect || pageSelect.dataset.topicsAdded) return;
            (visualSite.home?.topics?.items || []).forEach((topic, index) => {
              if (!topic.slug) return;
              const option = document.createElement("option");
              option.value = "topic:" + index;
              option.dataset.url = "../articolo.html?slug=" + encodeURIComponent(topic.slug);
              option.textContent = "Articolo: " + (topic.label || topic.title || topic.slug);
              pageSelect.appendChild(option);
            });
            pageSelect.dataset.topicsAdded = "1";
          };

          const pushEditable = (items, el, path, itemLabel, linkPath = "") => {
            if (!el || getPath(visualSite, path) === undefined) return;
            items.push({ el, path, label: itemLabel, linkPath });
          };

          const pushImage = (items, el, path, itemLabel, altPath = "", recommended = "", cssVar = "") => {
            if (!el || getPath(visualSite, path) === undefined) return;
            items.push({ type: "image", el, path, label: itemLabel, altPath, recommended, cssVar });
          };

          const addSharedImages = (items, doc) => {
            pushImage(items, doc.querySelector(".brand img"), "assets.logo", "Logo intestazione");
            pushImage(items, doc.querySelector(".footer-logo"), "assets.logo", "Logo footer");
            pushImage(items, doc.querySelector(".powered-by img"), "home.footer.poweredBy.logo", "Footer: logo Archimpresa");
          };

          const mapHome = (doc) => {
            const items = [];
            addSharedImages(items, doc);
            pushImage(items, doc.querySelector(".hero-media img"), "assets.heroImage", "Hero: foto principale");
            pushImage(items, doc.querySelector(".hero"), "assets.patternImage", "Hero: banda grafica", "", "", "--pattern-image");
            pushImage(items, doc.querySelector(".initiative-panel"), "assets.initiativeIllustration", "Iniziative: illustrazione card", "", "", "--initiative-image");
            const galleryIndex = (visualSite.home?.gallery || []).findIndex(item => ![false, 0, "0"].includes(item.visible) && item.src);
            if (galleryIndex >= 0) pushImage(items, doc.querySelector(".image-grid img"), `home.gallery.${galleryIndex}.src`, "Studio: immagine", `home.gallery.${galleryIndex}.alt`);
            pushEditable(items, doc.querySelector(".hero .eyebrow"), "home.hero.eyebrow", "Hero: etichetta");
            pushEditable(items, doc.querySelector("#hero-title"), "home.hero.title", "Hero: titolo");
            pushEditable(items, doc.querySelector(".hero-lead"), "home.hero.lead", "Hero: testo introduttivo");
            pushEditable(items, doc.querySelector(".hero-actions .button-primary"), "home.hero.primaryLabel", "Hero: pulsante principale", "home.hero.primaryHref");
            pushEditable(items, doc.querySelector(".hero-actions .button-secondary"), "home.hero.secondaryLabel", "Hero: pulsante secondario", "home.hero.secondaryHref");
            doc.querySelectorAll(".trust-list .trust-item").forEach((el, index) => {
              pushEditable(items, el.querySelector(".trust-title"), `home.trust.${index}.title`, `Chi sono ${index + 1}: titolo`, `home.trust.${index}.href`);
              pushEditable(items, el.querySelector(".trust-text"), `home.trust.${index}.text`, `Chi sono ${index + 1}: testo`, `home.trust.${index}.href`);
            });
            pushEditable(items, doc.querySelector(".intro-band .eyebrow"), "home.intro.eyebrow", "Introduzione: etichetta");
            pushEditable(items, doc.querySelector(".intro-band h2"), "home.intro.title", "Introduzione: titolo");
            pushEditable(items, doc.querySelector(".intro-band .intro-grid > p"), "home.intro.text", "Introduzione: testo");
            pushEditable(items, doc.querySelector("#servizi .section-heading .eyebrow"), "home.services.eyebrow", "Servizi: etichetta");
            pushEditable(items, doc.querySelector("#servizi-title"), "home.services.title", "Servizi: titolo");
            const serviceIndexes = visibleIndexes(visualSite.home?.services?.items);
            doc.querySelectorAll(".service-grid .service-card").forEach((el, renderedIndex) => {
              const index = serviceIndexes[renderedIndex];
              if (index === undefined) return;
              const pageEntry = Object.entries(visualSite.pages || {}).find(([, page]) => page.slug === visualSite.home.services.items[index].href);
              if (pageEntry) pushImage(items, el.querySelector(".service-illustration"), `pages.${pageEntry[0]}.hero.image`, `Servizio ${index + 1}: immagine della scheda e della pagina`);
              pushEditable(items, el.querySelector("h3"), `home.services.items.${index}.title`, `Servizio ${index + 1}: titolo`);
              pushEditable(items, el.querySelector("p"), `home.services.items.${index}.text`, `Servizio ${index + 1}: descrizione`);
              pushEditable(items, el.querySelector("a"), `home.services.items.${index}.linkLabel`, `Servizio ${index + 1}: link`, `home.services.items.${index}.href`);
            });
            pushEditable(items, doc.querySelector(".method-section .eyebrow"), "home.method.eyebrow", "Metodo: etichetta");
            pushEditable(items, doc.querySelector("#metodo-title"), "home.method.title", "Metodo: titolo");
            pushEditable(items, doc.querySelector(".method-section .method-grid > div p:not(.eyebrow)"), "home.method.text", "Metodo: testo");
            doc.querySelectorAll(".steps-list li").forEach((el, index) => {
              pushEditable(items, el.querySelector("strong"), `home.method.steps.${index}.title`, `Metodo step ${index + 1}: titolo`);
              let textNode = el.querySelector(".visual-step-text");
              if (!textNode) {
                textNode = doc.createElement("span");
                textNode.className = "visual-step-text";
                Array.from(el.childNodes).forEach((node) => {
                  if (node.nodeType === Node.TEXT_NODE && node.textContent.trim()) node.remove();
                });
                el.appendChild(textNode);
              }
              pushEditable(items, textNode, `home.method.steps.${index}.text`, `Metodo step ${index + 1}: testo`);
            });
            pushEditable(items, doc.querySelector(".initiatives-section .eyebrow"), "home.initiative.eyebrow", "Iniziative: etichetta");
            pushEditable(items, doc.querySelector("#iniziative-title"), "home.initiative.title", "Iniziative: titolo");
            pushEditable(items, doc.querySelector(".initiative-panel .initiative-label"), "home.initiative.label", "Iniziativa: etichetta");
            pushEditable(items, doc.querySelector(".initiative-panel h3"), "home.initiative.name", "Iniziativa: nome");
            pushEditable(items, doc.querySelector(".initiative-panel p:not(.initiative-label)"), "home.initiative.text", "Iniziativa: testo");
            pushEditable(items, doc.querySelector(".initiative-panel .button"), "home.initiative.buttonLabel", "Iniziativa: pulsante", "home.initiative.href");
            pushEditable(items, doc.querySelector(".resources-section .eyebrow"), "home.topics.eyebrow", "Argomenti: etichetta");
            pushEditable(items, doc.querySelector("#risorse-title"), "home.topics.title", "Argomenti: titolo");
            const topicIndexes = visibleIndexes(visualSite.home?.topics?.items);
            doc.querySelectorAll(".topic-list a").forEach((el, renderedIndex) => {
              const index = topicIndexes[renderedIndex];
              if (index === undefined) return;
              pushEditable(items, el, `home.topics.items.${index}.label`, `Argomento ${index + 1}`, `home.topics.items.${index}.href`);
            });
            pushEditable(items, doc.querySelector(".faq-section .eyebrow"), "home.faq.eyebrow", "FAQ: etichetta");
            pushEditable(items, doc.querySelector("#faq-title"), "home.faq.title", "FAQ: titolo");
            doc.querySelectorAll(".faq-list details").forEach((el, index) => {
              pushEditable(items, el.querySelector("summary"), `home.faq.items.${index}.question`, `FAQ ${index + 1}: domanda`);
              pushEditable(items, el.querySelector("p"), `home.faq.items.${index}.answer`, `FAQ ${index + 1}: risposta`);
            });
            pushEditable(items, doc.querySelector(".contact-section .eyebrow"), "home.contact.eyebrow", "Contatti: etichetta");
            pushEditable(items, doc.querySelector("#contatti-title"), "home.contact.title", "Contatti: titolo");
            pushEditable(items, doc.querySelector(".contact-section .contact-grid > div p:not(.eyebrow)"), "home.contact.text", "Contatti: testo");
            const contactItems = doc.querySelectorAll(".contact-card a, .contact-card span");
            pushEditable(items, contactItems[0], "home.contact.email", "Contatti: email");
            pushEditable(items, contactItems[1], "home.contact.phone", "Contatti: telefono", "home.contact.phoneHref");
            pushEditable(items, contactItems[2], "home.contact.cities", "Contatti: città");
            pushEditable(items, contactItems[3], "home.contact.vat", "Contatti: partita IVA");
            pushEditable(items, doc.querySelector(".footer-copy p:nth-child(1)"), "home.footer.line1", "Footer: riga 1");
            pushEditable(items, doc.querySelector(".footer-copy p:nth-child(2)"), "home.footer.line2", "Footer: riga 2");
            pushEditable(items, doc.querySelector(".powered-by span"), "home.footer.poweredBy.label", "Footer: powered by", "home.footer.poweredBy.href");
            return items;
          };

          const mapRichContent = (items, doc, prefixPath, labelPrefix) => {
            const rich = doc.querySelector(".rich-content");
            if (!rich) return;
            let sectionIndex = -1;
            let itemIndex = 0;
            let hasSectionText = false;
            Array.from(rich.children).forEach((el) => {
              if (el.tagName === "H2") {
                sectionIndex += 1;
                itemIndex = 0;
                hasSectionText = false;
                pushEditable(items, el, `${prefixPath}.sections.${sectionIndex}.title`, `${labelPrefix}: sezione ${sectionIndex + 1} titolo`);
              } else if (el.tagName === "P" && sectionIndex >= 0) {
                if (hasSectionText) {
                  el.remove();
                } else {
                  el.classList.add("visual-section-text");
                  pushEditable(items, el, `${prefixPath}.sections.${sectionIndex}.text`, `${labelPrefix}: sezione ${sectionIndex + 1} testo`);
                  hasSectionText = true;
                }
              } else if (el.tagName === "UL" && sectionIndex >= 0) {
                el.querySelectorAll("li").forEach((li) => {
                  pushEditable(items, li, `${prefixPath}.sections.${sectionIndex}.items.${itemIndex}`, `${labelPrefix}: sezione ${sectionIndex + 1} elenco`);
                  itemIndex += 1;
                });
              }
            });
          };

          const mapPage = (doc, key) => {
            const items = ["servizi", "metodo", "iniziative", "contatti"].includes(key) ? mapHome(doc) : [];
            const base = `pages.${key}`;
            addSharedImages(items, doc);
            pushImage(items, doc.querySelector(".page-hero:not(.directory-hero)"), `${base}.hero.image`, "Pagina: illustrazione coordinata", "", "", "--page-image");
            pushImage(items, doc.querySelector(".page-hero img"), `${base}.hero.image`, "Hero pagina: immagine", `${base}.hero.imageAlt`);
            pushEditable(items, doc.querySelector(".page-hero .eyebrow"), `${base}.hero.eyebrow`, "Hero pagina: etichetta");
            pushEditable(items, doc.querySelector(".page-hero h1"), `${base}.hero.title`, "Hero pagina: titolo");
            pushEditable(items, doc.querySelector(".page-hero p:not(.eyebrow)"), `${base}.hero.text`, "Hero pagina: testo");
            pushEditable(items, doc.querySelector(".page-hero .button"), `${base}.hero.buttonLabel`, "Hero pagina: pulsante", `${base}.hero.buttonHref`);
            pushEditable(items, doc.querySelector(".side-note strong"), `${base}.sideNoteTitle`, "Nota laterale: titolo");
            doc.querySelectorAll(".side-note span").forEach((el, index) => {
              pushEditable(items, el, `${base}.sideNotes.${index}`, `Nota laterale ${index + 1}`);
            });
            mapRichContent(items, doc, base, "Pagina");
            return items;
          };

          const mapTopic = (doc, index) => {
            const items = [];
            const base = `home.topics.items.${index}`;
            addSharedImages(items, doc);
            pushImage(items, doc.querySelector(".article-hero"), "assets.articleIllustration", "Articolo: banda grafica", "", "", "--article-image");
            pushImage(items, doc.querySelector(".article-hero img"), `${base}.heroImage`, "Articolo: immagine hero");
            pushEditable(items, doc.querySelector(".article-hero h1"), `${base}.title`, "Articolo: titolo");
            pushEditable(items, doc.querySelector(".article-hero .article-lead"), `${base}.excerpt`, "Articolo: riassunto");
            mapRichContent(items, doc, base, "Articolo");
            return items;
          };

          const descriptorList = (doc) => {
            const value = pageSelect.value;
            if (value === "home") return mapHome(doc);
            if (value.startsWith("topic:")) return mapTopic(doc, Number(value.split(":")[1] || 0));
            return mapPage(doc, value);
          };

          const selectEditable = (descriptor) => {
            selected?.el.classList.remove("visual-selected");
            selected = descriptor;
            descriptor.el.classList.add("visual-selected");
            label.textContent = descriptor.label;
            pathLabel.textContent = descriptor.path;
            textInput.disabled = false;
            textInput.value = getPath(visualSite, descriptor.path) ?? descriptor.el.textContent.trim();
            if (descriptor.linkPath) {
              linkWrap.hidden = false;
              linkInput.value = getPath(visualSite, descriptor.linkPath) ?? "";
            } else {
              linkWrap.hidden = true;
              linkInput.value = "";
            }
            imageWrap.hidden = true;
            if (imageFile) imageFile.value = "";
          };

          const writeDescriptor = (descriptor, value) => {
            setPath(visualSite, descriptor.path, value);
            descriptor.el.textContent = value;
            if (selected === descriptor) textInput.value = value;
            setDirty(true);
            setVisualStatus("Modifiche non salvate.", "dirty");
          };

          const applyImageDescriptor = (descriptor, value) => {
            const doc = frame.contentDocument;
            if (descriptor.cssVar && doc?.documentElement) {
              doc.documentElement.style.setProperty(descriptor.cssVar, cssImageUrl(value));
            }
            if (descriptor.el.tagName === "IMG") {
              descriptor.el.setAttribute("src", previewAssetHref(value));
              if (descriptor.altPath) {
                descriptor.el.setAttribute("alt", getPath(visualSite, descriptor.altPath) || "");
              }
            }
          };

          const selectImage = (descriptor) => {
            selected?.el.classList.remove("visual-selected");
            selected = descriptor;
            descriptor.el.classList.add("visual-selected");
            const currentValue = getPath(visualSite, descriptor.path) || "";
            label.textContent = descriptor.label;
            pathLabel.textContent = descriptor.path;
            textInput.disabled = true;
            textInput.value = "";
            linkWrap.hidden = true;
            linkInput.value = "";
            imageWrap.hidden = false;
            imagePreview.src = inspectorAssetHref(currentValue);
            imagePreview.hidden = currentValue === "";
            imageHelp.textContent = imageHelpFor(descriptor.path, descriptor.recommended);
            imagePathInput.value = currentValue;
            if (imageFile) imageFile.value = "";
            if (descriptor.altPath) {
              imageAltWrap.hidden = false;
              imageAltInput.value = getPath(visualSite, descriptor.altPath) || "";
            } else {
              imageAltWrap.hidden = true;
              imageAltInput.value = "";
            }
          };

          const writeImagePath = (descriptor, value) => {
            setPath(visualSite, descriptor.path, value);
            applyImageDescriptor(descriptor, value);
            if (selected === descriptor) {
              imagePathInput.value = value;
              imagePreview.src = inspectorAssetHref(value);
              imagePreview.hidden = value === "";
            }
            setDirty(true);
            setVisualStatus("Modifiche non salvate.", "dirty");
          };

          const bindPreview = () => {
            const doc = frame.contentDocument;
            if (!doc) return;
            let style = doc.getElementById("visual-editor-style");
            if (!style) {
              style = doc.createElement("style");
              style.id = "visual-editor-style";
              style.textContent = `
                [data-visual-editable] { outline: 2px dashed rgba(35,125,143,.55); outline-offset: 4px; cursor: text; }
                .steps-list .visual-step-text { display: block; }
                .visual-section-text { white-space: pre-line; }
                [data-visual-editable]:hover { outline-color: #e4452e; background: rgba(255,255,255,.18); }
                [data-visual-image-editable] { outline: 2px solid rgba(246,167,35,.72); outline-offset: 5px; cursor: pointer; }
                [data-visual-image-editable]:hover { outline-color: #e4452e; }
                .visual-selected { outline: 3px solid #e4452e !important; background: rgba(228,69,46,.09) !important; }
              `;
              doc.head.appendChild(style);
            }

            selected = null;
            textInput.disabled = true;
            textInput.value = "";
            linkWrap.hidden = true;
            imageWrap.hidden = true;
            imagePreview.hidden = true;
            if (imageFile) imageFile.value = "";
            label.textContent = "Nessun testo selezionato";
            pathLabel.textContent = "Clicca un testo evidenziato nell'anteprima.";

            const descriptors = descriptorList(doc);
            descriptors.forEach((descriptor) => {
              if (descriptor.type === "image") {
                const currentValue = getPath(visualSite, descriptor.path);
                if (currentValue !== undefined) applyImageDescriptor(descriptor, currentValue);
                descriptor.el.dataset.visualImageEditable = "1";
                descriptor.el.addEventListener("click", (event) => {
                  event.preventDefault();
                  event.stopPropagation();
                  selectImage(descriptor);
                });
                return;
              }

              const currentValue = getPath(visualSite, descriptor.path);
              if (currentValue !== undefined) descriptor.el.textContent = currentValue;
              descriptor.el.dataset.visualEditable = "1";
              descriptor.el.contentEditable = "true";
              descriptor.el.spellcheck = true;
              descriptor.el.addEventListener("click", (event) => {
                event.preventDefault();
                event.stopPropagation();
                selectEditable(descriptor);
              });
              descriptor.el.addEventListener("input", () => {
                setPath(visualSite, descriptor.path, descriptor.el.textContent);
                if (selected === descriptor) textInput.value = descriptor.el.textContent;
                setDirty(true);
                setVisualStatus("Modifiche non salvate.", "dirty");
              });
              if (descriptor.linkPath) {
                const link = descriptor.el.closest("a") || descriptor.el;
                link.addEventListener("click", (event) => event.preventDefault());
                const href = getPath(visualSite, descriptor.linkPath);
                if (href && link.tagName === "A") link.setAttribute("href", absolutePreviewHref(href));
              }
            });
            setVisualStatus("Clicca un testo evidenziato per modificarlo.", "");
          };

          const loadPreview = () => {
            const option = pageSelect.selectedOptions[0];
            const url = option?.dataset.url || "../index.html";
            const separator = url.includes("?") ? "&" : "?";
            frame.src = url + separator + "cmsPreview=" + Date.now();
            setVisualStatus("Caricamento anteprima...");
          };

          addTopicOptions();
          pageSelect.addEventListener("change", loadPreview);
          reloadButton.addEventListener("click", loadPreview);
          frame.addEventListener("load", () => {
            setTimeout(bindPreview, 450);
          });

          textInput.addEventListener("input", () => {
            if (!selected || selected.type === "image") return;
            writeDescriptor(selected, textInput.value);
          });

          linkInput.addEventListener("input", () => {
            if (!selected?.linkPath) return;
            setPath(visualSite, selected.linkPath, linkInput.value);
            const link = selected.el.closest("a") || selected.el;
            if (link.tagName === "A") link.setAttribute("href", absolutePreviewHref(linkInput.value));
            setDirty(true);
            setVisualStatus("Modifiche non salvate.", "dirty");
          });

          imagePathInput.addEventListener("input", () => {
            if (!selected || selected.type !== "image") return;
            writeImagePath(selected, imagePathInput.value);
          });

          imageAltInput.addEventListener("input", () => {
            if (!selected?.altPath) return;
            setPath(visualSite, selected.altPath, imageAltInput.value);
            if (selected.el.tagName === "IMG") selected.el.setAttribute("alt", imageAltInput.value);
            setDirty(true);
            setVisualStatus("Modifiche non salvate.", "dirty");
          });

          imageFile.addEventListener("change", async () => {
            if (!selected || selected.type !== "image") return;
            const descriptor = selected;
            const file = imageFile.files?.[0];
            if (!file) return;

            const extension = (file.name.split(".").pop() || "").toLowerCase();
            if (!["jpg", "jpeg", "png", "webp"].includes(extension)) {
              setVisualStatus("Formato non valido. Usa JPG, PNG o WebP.", "error");
              imageFile.value = "";
              return;
            }
            if (file.size > 5 * 1024 * 1024) {
              setVisualStatus("Il file supera il limite di 5 MB.", "error");
              imageFile.value = "";
              return;
            }

            const previousValue = getPath(visualSite, descriptor.path) || "";
            const temporaryUrl = URL.createObjectURL(file);
            applyImageDescriptor(descriptor, temporaryUrl);
            if (selected === descriptor) {
              imagePreview.src = temporaryUrl;
              imagePreview.hidden = false;
            }
            setVisualStatus("Caricamento immagine...");

            const body = new FormData();
            body.set("csrf", csrf);
            body.set("action", "upload_visual_image");
            body.set("path", descriptor.path);
            body.set("label", descriptor.label);
            body.set("visual_image", file);

            try {
              const response = await fetch("index.php?section=visual", { method: "POST", body });
              const data = await readCmsResponse(response);
              if (!response.ok || !data.ok) throw new Error(data.message || "Upload non riuscito.");
              writeImagePath(descriptor, data.path);
              setVisualStatus(data.message || "Immagine caricata. Salva le modifiche.", "dirty");
            } catch (error) {
              applyImageDescriptor(descriptor, previousValue);
              if (selected === descriptor) {
                imagePreview.src = inspectorAssetHref(previousValue);
                imagePreview.hidden = previousValue === "";
              }
              setVisualStatus(error.message || "Errore durante l'upload.", "error");
            } finally {
              URL.revokeObjectURL(temporaryUrl);
            }
          });

          saveButton.addEventListener("click", async () => {
            saveButton.disabled = true;
            setVisualStatus("Salvataggio in corso...");
            const body = new FormData();
            body.set("csrf", csrf);
            body.set("action", "save_visual_content");
            body.set("site_json", JSON.stringify(visualSite));
            try {
              const response = await fetch("index.php?section=visual", { method: "POST", body });
              const data = await readCmsResponse(response);
              if (!response.ok || !data.ok) throw new Error(data.message || "Salvataggio non riuscito.");
              setDirty(false);
              setVisualStatus(data.message || "Salvato correttamente.", "ok");
              if (saveState && data.savedAt) saveState.textContent = "Ultimo salvataggio: " + data.savedAt;
            } catch (error) {
              setVisualStatus(error.message || "Errore durante il salvataggio.", "error");
            } finally {
              saveButton.disabled = false;
            }
          });
        }

        document.querySelectorAll("[data-toast]").forEach((toast) => {
          setTimeout(() => toast.classList.add("is-fading"), 5200);
        });
      })();
    </script>
  </body>
</html>
