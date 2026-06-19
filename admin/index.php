<?php
declare(strict_types=1);

$isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['SERVER_PORT'] ?? '') === '443');
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'secure' => $isHttps,
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_start();

$root = dirname(__DIR__);
$contentFile = $root . DIRECTORY_SEPARATOR . 'content' . DIRECTORY_SEPARATOR . 'site.json';
$backupDir = $root . DIRECTORY_SEPARATOR . 'content' . DIRECTORY_SEPARATOR . 'backups';
$configFile = __DIR__ . DIRECTORY_SEPARATOR . 'config.php';
$calendarConfigFile = __DIR__ . DIRECTORY_SEPARATOR . 'google-calendar-config.php';
$loginAttemptsFile = __DIR__ . DIRECTORY_SEPARATOR . '.login-attempts.json';
$uploadDir = $root . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'uploads';
$uploadBase = 'assets/uploads/';

if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0775, true);
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
    return file_put_contents($file, $body, LOCK_EX) !== false;
}

function is_logged_in(): bool
{
    return !empty($_SESSION['cms_user']);
}

function verify_csrf(): bool
{
    return isset($_POST['csrf'], $_SESSION['csrf']) && hash_equals((string) $_SESSION['csrf'], (string) $_POST['csrf']);
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
    return $json !== false && file_put_contents($file, $json . PHP_EOL, LOCK_EX) !== false;
}

function backup_site_file(string $file, string $backupDir): bool
{
    if (!is_file($file)) {
        return true;
    }

    if (!is_dir($backupDir) && !mkdir($backupDir, 0775, true)) {
        return false;
    }

    $target = $backupDir . DIRECTORY_SEPARATOR . 'site-' . date('Ymd-His') . '.json';
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

function marker_select(string $name, string $value = 'blue'): void
{
    $options = ['blue' => 'Blu', 'yellow' => 'Giallo', 'red' => 'Rosso'];
    echo '<label class="field"><span>Colore</span><select name="' . e($name) . '">';
    foreach ($options as $key => $label) {
        echo '<option value="' . e($key) . '"' . ($key === $value ? ' selected' : '') . '>' . e($label) . '</option>';
    }
    echo '</select><small>Usato per il segno grafico della card.</small></label>';
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
    return [
        'psicoterapia' => [
            'label' => 'Psicoterapia',
            'slug' => 'servizi/psicoterapia-breve-strategica-bolzano.html',
            'title' => 'Psicoterapia breve strategica',
        ],
        'coaching' => [
            'label' => 'Coaching',
            'slug' => 'servizi/coaching-professionale-strategico.html',
            'title' => 'Coaching professionale',
        ],
        'lavoro' => [
            'label' => 'Psicologia del lavoro',
            'slug' => 'servizi/psicologia-del-lavoro-sportello-aziendale.html',
            'title' => 'Psicologia del lavoro',
        ],
        'mente' => [
            'label' => 'Mente Adolescente',
            'slug' => 'iniziative/mente-adolescente.html',
            'title' => 'Mente Adolescente',
        ],
        'privacy' => [
            'label' => 'Privacy policy',
            'slug' => 'privacy-policy.html',
            'title' => 'Privacy policy',
            'seo' => [
                'title' => 'Privacy policy | Laura Rossin',
                'description' => 'Informativa privacy provvisoria del sito Laura Rossin. Testo segnaposto da validare con un professionista.',
            ],
            'hero' => [
                'eyebrow' => 'Informative',
                'title' => 'Privacy policy',
                'text' => 'Testo informativo provvisorio da verificare e completare con un professionista.',
                'buttonLabel' => 'Torna al sito',
                'buttonHref' => 'index.html',
                'image' => '',
                'imageAlt' => '',
            ],
            'sections' => [
                [
                    'title' => 'Informativa provvisoria',
                    'text' => 'Questa pagina contiene testo segnaposto. Prima della pubblicazione definitiva, l informativa deve essere verificata e personalizzata da un professionista competente.',
                    'items' => [],
                ],
                [
                    'title' => 'Dati inviati tramite form',
                    'text' => 'I dati inseriti nel form di prenotazione vengono utilizzati esclusivamente per gestire la richiesta di contatto e l appuntamento.',
                    'items' => [],
                ],
            ],
        ],
        'cookie' => [
            'label' => 'Cookie policy',
            'slug' => 'cookie-policy.html',
            'title' => 'Cookie policy',
            'seo' => [
                'title' => 'Cookie policy | Laura Rossin',
                'description' => 'Informativa cookie provvisoria del sito Laura Rossin. Testo segnaposto da validare con un professionista.',
            ],
            'hero' => [
                'eyebrow' => 'Informative',
                'title' => 'Cookie policy',
                'text' => 'Testo informativo provvisorio da verificare e completare con un professionista.',
                'buttonLabel' => 'Torna al sito',
                'buttonHref' => 'index.html',
                'image' => '',
                'imageAlt' => '',
            ],
            'sections' => [
                [
                    'title' => 'Uso dei cookie',
                    'text' => 'Questa pagina contiene testo segnaposto. Indica qui quali cookie tecnici, statistici o di terze parti vengono usati dal sito.',
                    'items' => [],
                ],
            ],
        ],
    ];
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

function normalize_site(array $site): array
{
    $site = ensure_site_defaults($site);
    $site['home']['trust'] = clean_rows($site['home']['trust'] ?? [], ['title', 'text', 'href']);
    $site['home']['services']['items'] = normalize_services($site['home']['services']['items'] ?? []);
    $site['home']['method']['steps'] = clean_rows($site['home']['method']['steps'] ?? [], ['number', 'title', 'text']);
    $site['home']['gallery'] = clean_rows($site['home']['gallery'] ?? [], ['src', 'alt']);
    $site['home']['topics']['items'] = clean_rows($site['home']['topics']['items'] ?? [], ['label', 'href']);
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

function handle_uploads(array &$site, string $uploadDir, string $uploadBase, array &$errors, array $assetDefinitions): void
{
    if (empty($_FILES['uploads']) || !is_array($_FILES['uploads']['name'])) {
        return;
    }

    $allowed = ['jpg', 'jpeg', 'png', 'webp', 'svg'];
    foreach ($assetDefinitions as $assetKey => $definition) {
        if (empty($_FILES['uploads']['name'][$assetKey])) {
            continue;
        }

        $label = (string) ($definition['label'] ?? $assetKey);
        $error = (int) $_FILES['uploads']['error'][$assetKey];
        if ($error !== UPLOAD_ERR_OK) {
            $errors[] = 'Upload non riuscito per "' . $label . '". Riprova con un altro file.';
            continue;
        }

        $size = (int) $_FILES['uploads']['size'][$assetKey];
        if ($size > 5 * 1024 * 1024) {
            $errors[] = '"' . $label . '" supera il limite di 5 MB.';
            continue;
        }

        $original = (string) $_FILES['uploads']['name'][$assetKey];
        $extension = strtolower(pathinfo($original, PATHINFO_EXTENSION));
        if (!in_array($extension, $allowed, true)) {
            $errors[] = 'Formato non valido per "' . $label . '". Usa JPG, PNG, WebP o SVG.';
            continue;
        }

        $filename = preg_replace('/[^a-z0-9-]+/i', '-', $assetKey) . '-' . date('YmdHis') . '.' . $extension;
        $target = $uploadDir . DIRECTORY_SEPARATOR . $filename;
        if (!move_uploaded_file((string) $_FILES['uploads']['tmp_name'][$assetKey], $target)) {
            $errors[] = 'Non riesco a salvare "' . $label . '". Verifica i permessi della cartella uploads.';
            continue;
        }

        $site['assets'][$assetKey] = $uploadBase . $filename;
    }
}

function write_google_calendar_config_file(string $file, array $data): bool
{
    $body = "<?php\nreturn " . var_export($data, true) . ";\n";
    return file_put_contents($file, $body, LOCK_EX) !== false;
}

function service_account_status(array $calendarConfig): string
{
    $serviceAccount = $calendarConfig['service_account'] ?? [];
    if (is_array($serviceAccount) && !empty($serviceAccount['client_email']) && !empty($serviceAccount['private_key'])) {
        return 'Configurato per ' . (string) $serviceAccount['client_email'];
    }
    return 'Non configurato';
}

function html_value(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function update_html_seo(string $root, array $site): void
{
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

        if (!empty($seo['title'])) {
            $html = preg_replace('/<title>.*?<\/title>/si', '<title>' . html_value((string) $seo['title']) . '</title>', $html, 1) ?? $html;
        }

        if (!empty($seo['description'])) {
            $html = preg_replace('/<meta name="description" content=".*?">/si', '<meta name="description" content="' . html_value((string) $seo['description']) . '">', $html, 1) ?? $html;
        }

        if ($relative === 'index.html') {
            if (!empty($seo['ogTitle'])) {
                $html = preg_replace('/<meta property="og:title" content=".*?">/si', '<meta property="og:title" content="' . html_value((string) $seo['ogTitle']) . '">', $html, 1) ?? $html;
            }
            if (!empty($seo['ogDescription'])) {
                $html = preg_replace('/<meta property="og:description" content=".*?">/si', '<meta property="og:description" content="' . html_value((string) $seo['ogDescription']) . '">', $html, 1) ?? $html;
            }
        }

        file_put_contents($path, $html, LOCK_EX);
    }
}

function password_errors(string $password): array
{
    $errors = [];
    if (strlen($password) < 12) {
        $errors[] = 'La nuova password deve avere almeno 12 caratteri.';
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

function is_login_limited(string $file, string $key, int $limit, int $windowSeconds): bool
{
    $attempts = login_attempts($file);
    $now = time();
    $recent = array_values(array_filter($attempts[$key] ?? [], static fn ($ts) => is_int($ts) && $ts > $now - $windowSeconds));
    $attempts[$key] = $recent;
    save_login_attempts($file, $attempts);
    return count($recent) >= $limit;
}

function register_failed_login(string $file, string $key, int $windowSeconds): void
{
    $attempts = login_attempts($file);
    $now = time();
    $recent = array_values(array_filter($attempts[$key] ?? [], static fn ($ts) => is_int($ts) && $ts > $now - $windowSeconds));
    $recent[] = $now;
    $attempts[$key] = $recent;
    save_login_attempts($file, $attempts);
}

function clear_failed_login(string $file, string $key): void
{
    $attempts = login_attempts($file);
    unset($attempts[$key]);
    save_login_attempts($file, $attempts);
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
        <?php marker_select($base . '[marker]', (string) ($item['marker'] ?? 'blue')); ?>
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

$sections = [
    'dashboard' => 'Dashboard',
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
        'recommended' => 'SVG o PNG trasparente, larghezza 600 px.',
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
    'studioDeskImage' => [
        'label' => 'Foto studio / ambiente',
        'recommended' => 'JPG/WebP orizzontale, almeno 1600 x 1000 px.',
    ],
    'ogImage' => [
        'label' => 'Immagine social',
        'recommended' => 'JPG/PNG 1200 x 630 px.',
    ],
];

$config = load_auth_config($configFile);
$calendarConfig = is_file($calendarConfigFile) ? require $calendarConfigFile : [];
$calendarConfig = is_array($calendarConfig) ? $calendarConfig : [];
$sessionLifetime = max(900, (int) (env_value('CMS_SESSION_LIFETIME') ?? 3600));
$maxLoginAttempts = max(3, (int) (env_value('CMS_MAX_LOGIN_ATTEMPTS') ?? 5));
$loginWindow = 15 * 60;
$errors = [];

if (is_logged_in()) {
    $lastActivity = (int) ($_SESSION['last_activity'] ?? 0);
    $sessionVersion = (int) ($_SESSION['cms_session_version'] ?? 0);
    if (($lastActivity > 0 && $lastActivity + $sessionLifetime < time()) || $sessionVersion !== (int) $config['session_version']) {
        destroy_session_and_redirect();
    }
    $_SESSION['last_activity'] = time();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    $returnSection = array_key_exists((string) ($_POST['section'] ?? ''), $sections) ? (string) $_POST['section'] : 'dashboard';

    if ($action === 'login') {
        if (!verify_csrf()) {
            $errors[] = 'Sessione scaduta. Riprova.';
        } else {
            $key = login_key();
            if (is_login_limited($loginAttemptsFile, $key, $maxLoginAttempts, $loginWindow)) {
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

                    if ($passwordCheck['needs_rehash'] && !auth_uses_env()) {
                        write_auth_config($configFile, $username, $password, $config);
                    }
                    redirect_section('dashboard');
                }

                register_failed_login($loginAttemptsFile, $key, $loginWindow);
                $errors[] = 'Accesso non riuscito. Controlla le credenziali e riprova.';
            }
        }
    }

    if ($action === 'logout') {
        destroy_session_and_redirect();
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

        if ($credentialErrors === [] && write_auth_config($configFile, $newUser, $newPassword, $config)) {
            $config = load_auth_config($configFile);
            $_SESSION['cms_user'] = $newUser;
            $_SESSION['cms_session_version'] = (int) $config['session_version'];
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

    if (is_logged_in() && $action === 'save_google_calendar') {
        if (!verify_csrf()) {
            flash('error', 'Sessione scaduta. Riprova.');
            redirect_section('prenotazioni');
        }

        $calendarId = trim((string) ($_POST['calendar_id'] ?? ''));
        $timezone = trim((string) ($_POST['timezone'] ?? 'Europe/Rome')) ?: 'Europe/Rome';
        $daysAhead = max(1, min(180, (int) ($_POST['days_ahead'] ?? 60)));
        $serviceAccountJson = trim((string) ($_POST['service_account_json'] ?? ''));
        $serviceAccount = is_array($calendarConfig['service_account'] ?? null) ? $calendarConfig['service_account'] : [];
        $calendarErrors = [];

        if ($calendarId === '') {
            $calendarErrors[] = 'Inserisci il Google Calendar ID.';
        }

        if ($serviceAccountJson !== '') {
            $decoded = json_decode($serviceAccountJson, true);
            if (!is_array($decoded) || empty($decoded['client_email']) || empty($decoded['private_key'])) {
                $calendarErrors[] = 'Il JSON dell account di servizio Google non è valido.';
            } else {
                $serviceAccount = $decoded;
            }
        }

        if ($calendarErrors === []) {
            $calendarConfig = [
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
      <form method="post" class="grid">
        <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
        <input type="hidden" name="action" value="login">
        <?php field('Nome utente', 'username', '', 'text', '', ['autocomplete' => 'username']); ?>
        <?php field('Password', 'password', '', 'password', '', ['autocomplete' => 'current-password']); ?>
        <button type="submit">Entra</button>
      </form>
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
              <a href="index.php?section=media">Aggiorna immagini<span>Logo, foto e social preview</span></a>
              <a href="index.php?section=homepage">Modifica homepage<span>Hero, introduzione, CTA e footer</span></a>
              <a href="index.php?section=servizi">Gestisci servizi<span>Ordine, visibilità e duplicazione</span></a>
              <a href="index.php?section=pagine">Modifica pagine<span>Pagine servizio, privacy e cookie</span></a>
              <a href="index.php?section=prenotazioni">Prenotazioni<span>Google Calendar e disponibilità</span></a>
              <a href="index.php?section=account">Account<span>Password e sessioni</span></a>
            </div>
          </section>
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
                      <p class="hint"><?= e($definition['recommended']) ?> Formati accettati: JPG, PNG, WebP, SVG. Peso massimo: 5 MB.</p>
                      <label class="upload-control">
                        <span>Carica nuovo file</span>
                        <input type="file" name="uploads[<?= e($key) ?>]" accept=".jpg,.jpeg,.png,.webp,.svg" data-media-input="<?= e($key) ?>">
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
                <?php render_service_item(['marker' => 'blue', 'visible' => true, 'order' => 1, 'title' => '', 'text' => '', 'href' => '', 'linkLabel' => ''], '__INDEX__'); ?>
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
                <p class="hint">Il sito mostra come prenotabili solo gli eventi del calendario che contengono il tag #disp.</p>
              </div>
              <a class="button secondary" href="../prenotazioni.html" target="_blank" rel="noopener">Apri pagina prenotazioni</a>
            </div>
            <div class="status-card <?= service_account_status($calendarConfig) === 'Non configurato' ? 'warn' : 'ok' ?>">
              <span>Google Calendar</span>
              <strong><?= e(service_account_status($calendarConfig)) ?></strong>
              <p>Condividi il calendario Google con l email dell account di servizio prima di testare gli slot.</p>
            </div>
            <form method="post" data-dirty-form class="settings-form">
              <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
              <input type="hidden" name="action" value="save_google_calendar">
              <input type="hidden" name="section" value="prenotazioni">
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

            const allowed = ["image/jpeg", "image/png", "image/webp", "image/svg+xml"];
            const allowedName = /\.(jpe?g|png|webp|svg)$/i.test(file.name);
            if ((!allowed.includes(file.type) && !allowedName) || file.size > 5 * 1024 * 1024) {
              if (error) error.textContent = "Formato non valido o file oltre 5 MB. Usa JPG, PNG, WebP o SVG.";
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

        document.querySelectorAll("[data-toast]").forEach((toast) => {
          setTimeout(() => toast.classList.add("is-fading"), 5200);
        });
      })();
    </script>
  </body>
</html>
