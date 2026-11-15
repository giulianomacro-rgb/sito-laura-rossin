<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/lib/runtime.php';

if (!defined('BOOKING_LIBRARY_ONLY')) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
}

function respond(array $payload, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function base64url(string $value): string
{
    return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
}

function load_calendar_config(): array
{
    $file = calendar_config_file();
    if (!is_file($file)) {
        throw new RuntimeException('Google Calendar non configurato.');
    }

    $config = require $file;
    if (!is_array($config)) {
        throw new RuntimeException('Configurazione Google Calendar non valida.');
    }

    $serviceAccount = $config['service_account'] ?? [];
    if (($config['enabled'] ?? false) !== true || empty($config['calendar_id']) || !is_array($serviceAccount) || empty($serviceAccount['client_email']) || empty($serviceAccount['private_key'])) {
        throw new RuntimeException('Google Calendar non configurato.');
    }

    if (($config['availability_mode'] ?? 'tag') === 'calendar' && empty($config['dedicated_calendar'])) {
        throw new RuntimeException('Conferma il calendario dedicato nel CMS.');
    }
    if (empty($config['test_mode']) && empty($config['privacy_ready'])) {
        throw new RuntimeException('Prenotazioni pubbliche non ancora abilitate.');
    }

    return $config;
}

function http_request(string $method, string $url, array $headers = [], ?string $body = null): array
{
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 20,
        ]);

        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }

        $raw = curl_exec($ch);
        if ($raw === false) {
            $error = curl_error($ch);
            curl_close($ch);
            throw new RuntimeException($error ?: 'Richiesta HTTP non riuscita.');
        }

        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $responseBody = substr((string) $raw, $headerSize);
        curl_close($ch);

        return ['status' => $status, 'body' => $responseBody];
    }

    $context = stream_context_create([
        'http' => [
            'method' => $method,
            'header' => implode("\r\n", $headers),
            'content' => $body ?? '',
            'ignore_errors' => true,
            'follow_location' => 0,
            'timeout' => 20,
        ],
    ]);
    $responseBody = file_get_contents($url, false, $context);
    $status = 0;
    if (isset($http_response_header[0]) && preg_match('/\s(\d{3})\s/', $http_response_header[0], $match)) {
        $status = (int) $match[1];
    }

    return ['status' => $status, 'body' => $responseBody === false ? '' : $responseBody];
}

function google_access_token(array $serviceAccount): string
{
    $tokenUri = 'https://oauth2.googleapis.com/token';
    $privateKey = (string) $serviceAccount['private_key'];
    $privateKey = str_replace('\\n', "\n", $privateKey);
    $now = time();
    $header = ['alg' => 'RS256', 'typ' => 'JWT'];
    $claims = [
        'iss' => (string) $serviceAccount['client_email'],
        'scope' => 'https://www.googleapis.com/auth/calendar.events',
        'aud' => $tokenUri,
        'iat' => $now,
        'exp' => $now + 3600,
    ];

    $unsigned = base64url((string) json_encode($header)) . '.' . base64url((string) json_encode($claims));
    $signature = '';
    if (!openssl_sign($unsigned, $signature, $privateKey, OPENSSL_ALGO_SHA256)) {
        throw new RuntimeException('Firma Google non riuscita.');
    }

    $jwt = $unsigned . '.' . base64url($signature);
    $response = http_request(
        'POST',
        $tokenUri,
        ['Content-Type: application/x-www-form-urlencoded'],
        http_build_query([
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion' => $jwt,
        ])
    );
    $data = json_decode($response['body'], true);

    if ($response['status'] < 200 || $response['status'] >= 300 || !is_array($data) || empty($data['access_token'])) {
        throw new RuntimeException('Autenticazione Google non riuscita.');
    }

    return (string) $data['access_token'];
}

function google_request(string $method, string $url, string $token, ?array $payload = null, array $extraHeaders = []): array
{
    $headers = array_merge([
        'Authorization: Bearer ' . $token,
        'Accept: application/json',
    ], $extraHeaders);

    $body = null;
    if ($payload !== null) {
        $headers[] = 'Content-Type: application/json; charset=utf-8';
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    $response = http_request($method, $url, $headers, $body === false ? null : $body);
    $data = json_decode($response['body'], true);
    return [
        'status' => $response['status'],
        'data' => is_array($data) ? $data : [],
        'body' => $response['body'],
    ];
}

function calendar_event_url(string $calendarId, string $eventId = ''): string
{
    $base = 'https://www.googleapis.com/calendar/v3/calendars/' . rawurlencode($calendarId) . '/events';
    return $eventId === '' ? $base : $base . '/' . rawurlencode($eventId);
}

function event_is_available_slot(array $event, string $mode = 'calendar'): bool
{
    if (($event['status'] ?? '') === 'cancelled' || ($event['eventType'] ?? 'default') !== 'default'
        || ($event['visibility'] ?? 'default') === 'private'
        || !empty($event['recurringEventId']) || !empty($event['recurrence'])
        || !empty($event['attendees']) || !empty($event['extendedProperties']['private']['lauraBooked'])
        || empty($event['start']['dateTime']) || empty($event['end']['dateTime'])) {
        return false;
    }

    $summary = trim((string) ($event['summary'] ?? ''));
    $description = trim((string) ($event['description'] ?? ''));
    if (preg_match('/^(Appuntamento|Occupato)(?:\s|$)/iu', $summary)) return false;
    return $mode === 'calendar' || preg_match('/(?<![\pL\pN_])#disp(?![\pL\pN_])/iu', $summary . "\n" . strip_tags($description)) === 1;
}

function require_calendar_write_access(string $role): void
{
    if (!in_array($role, ['writer', 'owner'], true)) {
        throw new RuntimeException('Nel calendario condiviso seleziona Apporta modifiche e visualizza i dettagli degli eventi. Il permesso attuale non consente di gestire gli appuntamenti privati.');
    }
}

function verify_calendar_connection(array $config): void
{
    if (empty($config['calendar_id']) || empty($config['service_account']['private_key'])) {
        throw new RuntimeException('Inserisci ID calendario e JSON dell’account di servizio, poi salva.');
    }
    $token = google_access_token($config['service_account']);
    $response = google_request('GET', calendar_event_url($config['calendar_id']) . '?maxResults=1&fields=accessRole', $token);
    if ($response['status'] === 404) {
        throw new RuntimeException('Calendario non accessibile. Verifica l’ID e la condivisione con l’email dell’account di servizio.');
    }
    if ($response['status'] !== 200) throw new RuntimeException('Google Calendar non accessibile. Verifica API e autorizzazioni del progetto.');
    require_calendar_write_access((string) ($response['data']['accessRole'] ?? ''));
}

function calendar_events(string $calendarId, string $token, DateTimeImmutable $from, DateTimeImmutable $to): array
{
    $params = [
        'timeMin' => $from->format(DateTimeInterface::ATOM),
        'timeMax' => $to->format(DateTimeInterface::ATOM),
        'singleEvents' => 'true',
        'orderBy' => 'startTime',
        'maxResults' => '2500',
        'fields' => 'accessRole,nextPageToken,items(id,summary,description,status,eventType,visibility,recurringEventId,recurrence,start,end,attendees,extendedProperties)',
    ];
    $events = [];
    for ($page = 0; $page < 8; $page++) {
        $response = google_request('GET', calendar_event_url($calendarId) . '?' . http_build_query($params), $token);
        if ($response['status'] !== 200) {
            throw new RuntimeException('Impossibile leggere Google Calendar.');
        }
        require_calendar_write_access((string) ($response['data']['accessRole'] ?? ''));
        array_push($events, ...($response['data']['items'] ?? []));
        $params['pageToken'] = $response['data']['nextPageToken'] ?? '';
        if ($params['pageToken'] === '') {
            return $events;
        }
    }
    throw new RuntimeException('Troppi eventi per leggere il calendario completo.');
}

function busy_intervals(array $events, DateTimeZone $timezone, string $mode = 'calendar'): array
{
    $intervals = [];
    foreach ($events as $event) {
        if (($event['status'] ?? '') === 'cancelled' || event_is_available_slot($event, $mode)) {
            continue;
        }
        $start = $event['start']['dateTime'] ?? $event['start']['date'] ?? '';
        $end = $event['end']['dateTime'] ?? $event['end']['date'] ?? '';
        if ($start !== '' && $end !== '') {
            $intervals[] = [new DateTimeImmutable($start, $timezone), new DateTimeImmutable($end, $timezone)];
        }
    }
    return $intervals;
}

function overlaps_busy(DateTimeImmutable $start, DateTimeImmutable $end, array $intervals): bool
{
    foreach ($intervals as [$busyStart, $busyEnd]) {
        if ($start < $busyEnd && $busyStart < $end) {
            return true;
        }
    }
    return false;
}

function list_slots(array $config): array
{
    $timezone = new DateTimeZone((string) ($config['timezone'] ?? 'Europe/Rome'));
    $daysAhead = max(1, min(180, (int) ($config['days_ahead'] ?? 60)));
    $now = new DateTimeImmutable('now', $timezone);
    $max = $now->modify('+' . $daysAhead . ' days');
    $token = google_access_token($config['service_account']);
    $events = calendar_events($config['calendar_id'], $token, $now, $max);
    $mode = $config['availability_mode'] ?? 'tag';
    $busy = busy_intervals($events, $timezone, $mode);

    $slots = [];
    foreach ($events as $event) {
        if (!is_array($event) || !event_is_available_slot($event, $mode)) {
            continue;
        }

        $startRaw = $event['start']['dateTime'] ?? '';
        $endRaw = $event['end']['dateTime'] ?? '';
        if ($startRaw === '' || $endRaw === '') {
            continue;
        }

        $start = (new DateTimeImmutable((string) $startRaw))->setTimezone($timezone);
        $end = (new DateTimeImmutable((string) $endRaw))->setTimezone($timezone);
        if ($start <= $now || $start >= $max || $end <= $start || overlaps_busy($start, $end, $busy)) {
            continue;
        }

        $slots[] = [
            'id' => (string) ($event['id'] ?? ''),
            'start' => $start->format(DateTimeInterface::ATOM),
            'end' => $end->format(DateTimeInterface::ATOM),
            'dateKey' => $start->format('Y-m-d'),
            'dateLabel' => $start->format('d/m/Y'),
            'timeLabel' => $start->format('H:i') . ' - ' . $end->format('H:i'),
        ];
    }

    return $slots;
}

function read_payload(): array
{
    if (strtolower(trim(explode(';', $_SERVER['CONTENT_TYPE'] ?? '')[0])) !== 'application/json') {
        respond(['ok' => false, 'message' => 'Formato della richiesta non valido.'], 415);
    }
    $raw = file_get_contents('php://input', false, null, 0, 16385);
    if ($raw === false || strlen($raw) > 16384) {
        respond(['ok' => false, 'message' => 'Richiesta troppo lunga.'], 413);
    }
    $data = json_decode((string) $raw, true);
    if (is_array($data)) {
        return $data;
    }
    respond(['ok' => false, 'message' => 'Richiesta non valida.'], 400);
    return [];
}

function clean_text(array $payload, string $key, int $max = 500): string
{
    $raw = $payload[$key] ?? '';
    $value = trim(is_string($raw) ? $raw : '');
    $value = preg_replace('/[[:cntrl:]]+/u', ' ', $value) ?? '';
    preg_match('/^.{0,' . $max . '}/us', $value, $limited);
    return trim($limited[0] ?? '');
}

function book_slot(array $config, array $payload): array
{
    $required = ['eventId', 'nome', 'cognome', 'telefono', 'email', 'eta', 'sesso'];
    foreach ($required as $key) {
        if (clean_text($payload, $key) === '') {
            respond(['ok' => false, 'message' => 'Compila tutti i campi obbligatori.'], 422);
        }
    }

    if (($payload['privacy'] ?? false) !== true) {
        respond(['ok' => false, 'message' => 'Per prenotare è necessario il consenso privacy.'], 422);
    }
    $privacyVersion = booking_privacy_version();
    if (($payload['privacyVersion'] ?? '') !== $privacyVersion) {
        respond(['ok' => false, 'message' => 'L’informativa è stata aggiornata. Ricarica la pagina e leggila prima di confermare.'], 422);
    }

    $email = clean_text($payload, 'email', 160);
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        respond(['ok' => false, 'message' => 'Inserisci un indirizzo email valido.'], 422);
    }
    if (!empty($config['test_mode']) && (($payload['testData'] ?? false) !== true || !str_ends_with($email, '@example.invalid'))) {
        respond(['ok' => false, 'message' => 'Nel collaudo usa solo dati inventati e una email che termina con @example.invalid.'], 422);
    }

    $eta = filter_var($payload['eta'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 120]]);
    if ($eta === false) {
        respond(['ok' => false, 'message' => 'Inserisci un età valida.'], 422);
    }

    $nome = clean_text($payload, 'nome', 80);
    $cognome = clean_text($payload, 'cognome', 80);
    $telefono = clean_text($payload, 'telefono', 80);
    $sesso = clean_text($payload, 'sesso', 80);
    $sintesi = clean_text($payload, 'sintesi', 1200);
    $eventId = clean_text($payload, 'eventId', 200);
    if (!preg_match('/^[a-zA-Z0-9_-]{1,200}$/D', $eventId)) {
        respond(['ok' => false, 'message' => 'Orario non valido.'], 422);
    }
    $token = google_access_token($config['service_account']);
    $eventResponse = google_request('GET', calendar_event_url((string) $config['calendar_id'], $eventId), $token);

    if (in_array($eventResponse['status'], [404, 410], true)) {
        respond(['ok' => false, 'message' => 'Questo orario non è più disponibile. Scegli un altro slot.'], 409);
    }

    if ($eventResponse['status'] < 200 || $eventResponse['status'] >= 300) {
        throw new RuntimeException('Impossibile verificare lo slot.');
    }

    $event = $eventResponse['data'];
    if (!event_is_available_slot($event, $config['availability_mode'] ?? 'tag') || empty($event['etag'])) {
        respond(['ok' => false, 'message' => 'Questo orario non è più disponibile. Scegli un altro slot.'], 409);
    }
    $timezone = new DateTimeZone((string) ($config['timezone'] ?? 'Europe/Rome'));
    $now = new DateTimeImmutable('now', $timezone);
    $start = new DateTimeImmutable($event['start']['dateTime']);
    $end = new DateTimeImmutable($event['end']['dateTime']);
    $max = $now->modify('+' . max(1, min(180, (int) ($config['days_ahead'] ?? 60))) . ' days');
    if ($start <= $now || $start >= $max || $end <= $start
        || overlaps_busy($start, $end, busy_intervals(calendar_events($config['calendar_id'], $token, $start, $end), $timezone, $config['availability_mode'] ?? 'tag'))) {
        respond(['ok' => false, 'message' => 'Questo orario non è più disponibile. Scegli un altro slot.'], 409);
    }

    $description = implode("\n", [
        'Nome: ' . $nome,
        'Cognome: ' . $cognome,
        'Telefono: ' . $telefono,
        'Email: ' . $email,
        'Età: ' . $eta,
        'Sesso: ' . $sesso,
        'Sintesi problematica: ' . ($sintesi ?: 'Non indicata'),
        'Consenso prenotazione online e dati relativi alla salute: espresso',
        'Informativa: ' . $privacyVersion,
        'Confermato il: ' . $now->format(DateTimeInterface::ATOM),
        !empty($config['test_mode']) ? 'COLLAUDO: dati fittizi, non è un appuntamento reale.' : '',
    ]);

    $updateResponse = google_request(
        'PATCH',
        calendar_event_url((string) $config['calendar_id'], $eventId) . '?sendUpdates=none&fields=id',
        $token,
        [
            'summary' => 'Appuntamento - ' . $nome . ' ' . $cognome,
            'description' => htmlspecialchars($description, ENT_NOQUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            'status' => 'confirmed',
            'visibility' => 'private',
            'transparency' => 'opaque',
            'extendedProperties' => ['private' => array_merge($event['extendedProperties']['private'] ?? [], [
                'lauraBooked' => '1', 'lauraPrivacy' => $privacyVersion,
                'lauraBookedAt' => $now->format(DateTimeInterface::ATOM),
                'lauraTest' => !empty($config['test_mode']) ? '1' : '0',
            ])],
        ],
        ['If-Match: ' . $event['etag']]
    );

    if (in_array($updateResponse['status'], [404, 409, 410, 412], true)) {
        respond(['ok' => false, 'message' => 'Questo orario non è più disponibile. Scegli un altro slot.'], 409);
    }

    if ($updateResponse['status'] < 200 || $updateResponse['status'] >= 300) {
        throw new RuntimeException('Impossibile confermare la prenotazione.');
    }

    return ['ok' => true, 'message' => !empty($config['test_mode']) ? 'Prova completata: evento aggiornato nel calendario. Non è un appuntamento reale.' : 'Prenotazione confermata. Per modifiche o chiarimenti puoi contattare Laura.'];
}

function booking_privacy_version(): string
{
    $site = json_decode((string) file_get_contents(site_content_file()), true);
    return '2026-11-15-' . substr(hash('sha256', json_encode($site['pages']['privacy'] ?? [])), 0, 12);
}

function test_booking_access(): string
{
    // Use the CMS session without exposing credentials or locking it during Google requests.
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    if (empty($_COOKIE[session_name()])) throw new RuntimeException('Collaudo riservato.');
    session_start(['read_and_close' => true]);
    $file = storage_dir() ? storage_dir() . '/private/account.php' : dirname(__DIR__) . '/admin/config.php';
    $auth = is_file($file) ? require $file : [];
    $username = getenv('CMS_USERNAME') ?: ($auth['username'] ?? 'laura');
    $hash = getenv('CMS_PASSWORD_HASH') ?: ($auth['password_hash'] ?? '');
    $valid = !empty($_SESSION['cms_user']) && !empty($_SESSION['csrf'])
        && (int) ($_SESSION['last_activity'] ?? 0) + max(900, (int) (getenv('CMS_SESSION_LIFETIME') ?: 3600)) >= time()
        && (int) ($_SESSION['cms_session_version'] ?? 0) === (int) ($auth['session_version'] ?? 1)
        && hash_equals(hash('sha256', $username . '|' . $hash), (string) ($_SESSION['cms_auth'] ?? ''));
    if (!$valid) throw new RuntimeException('Collaudo riservato.');
    return $_SESSION['csrf'];
}

function book_slot_locked(array $config, array $payload): array
{
    $dir = storage_dir() ? storage_dir() . '/private' : dirname(__DIR__) . '/admin';
    $lock = fopen($dir . '/.booking.lock', 'c');
    if (!$lock || !flock($lock, LOCK_EX)) throw new RuntimeException('Prenotazione momentaneamente non disponibile.');
    try { return book_slot($config, $payload); }
    finally { flock($lock, LOCK_UN); fclose($lock); }
}

if (defined('BOOKING_LIBRARY_ONLY')) return;

try {
    $config = load_calendar_config();
    $testCsrf = !empty($config['test_mode']) ? test_booking_access() : '';
    $action = (string) ($_GET['action'] ?? $_POST['action'] ?? 'slots');

    if ($_SERVER['REQUEST_METHOD'] === 'GET' && $action === 'slots') {
        respond(['ok' => true, 'slots' => list_slots($config), 'testMode' => !empty($config['test_mode']), 'testCsrf' => $testCsrf, 'privacyVersion' => booking_privacy_version()]);
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'book') {
        $payload = read_payload();
        if ($testCsrf !== '' && !hash_equals($testCsrf, (string) ($payload['testCsrf'] ?? ''))) respond(['ok' => false, 'message' => 'Sessione scaduta. Riapri il collaudo dal CMS.'], 403);
        respond(book_slot_locked($config, $payload));
    }

    respond(['ok' => false, 'message' => 'Richiesta non valida.'], 400);
} catch (Throwable $error) {
    respond(['ok' => false, 'message' => 'Calendario momentaneamente non disponibile. Riprova più tardi.'], 503);
}
