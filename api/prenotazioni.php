<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

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
    $file = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'admin' . DIRECTORY_SEPARATOR . 'google-calendar-config.php';
    if (!is_file($file)) {
        throw new RuntimeException('Google Calendar non configurato.');
    }

    $config = require $file;
    if (!is_array($config)) {
        throw new RuntimeException('Configurazione Google Calendar non valida.');
    }

    $serviceAccount = $config['service_account'] ?? [];
    if (empty($config['calendar_id']) || !is_array($serviceAccount) || empty($serviceAccount['client_email']) || empty($serviceAccount['private_key'])) {
        throw new RuntimeException('Google Calendar non configurato.');
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
    $tokenUri = (string) ($serviceAccount['token_uri'] ?? 'https://oauth2.googleapis.com/token');
    $privateKey = (string) $serviceAccount['private_key'];
    $privateKey = str_replace('\\n', "\n", $privateKey);
    $now = time();
    $header = ['alg' => 'RS256', 'typ' => 'JWT'];
    $claims = [
        'iss' => (string) $serviceAccount['client_email'],
        'scope' => 'https://www.googleapis.com/auth/calendar',
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

function event_has_availability_tag(array $event): bool
{
    $text = (string) ($event['summary'] ?? '') . "\n" . (string) ($event['description'] ?? '');
    return stripos($text, '#disp') !== false;
}

function list_slots(array $config): array
{
    $timezone = new DateTimeZone((string) ($config['timezone'] ?? 'Europe/Rome'));
    $daysAhead = max(1, min(180, (int) ($config['days_ahead'] ?? 60)));
    $now = new DateTimeImmutable('now', $timezone);
    $max = $now->modify('+' . $daysAhead . ' days');
    $token = google_access_token($config['service_account']);
    $query = http_build_query([
        'timeMin' => $now->format(DateTimeInterface::ATOM),
        'timeMax' => $max->format(DateTimeInterface::ATOM),
        'singleEvents' => 'true',
        'orderBy' => 'startTime',
        'maxResults' => '2500',
    ]);
    $response = google_request('GET', calendar_event_url((string) $config['calendar_id']) . '?' . $query, $token);

    if ($response['status'] < 200 || $response['status'] >= 300) {
        throw new RuntimeException('Impossibile leggere Google Calendar.');
    }

    $slots = [];
    foreach (($response['data']['items'] ?? []) as $event) {
        if (!is_array($event) || !event_has_availability_tag($event)) {
            continue;
        }

        $startRaw = $event['start']['dateTime'] ?? '';
        $endRaw = $event['end']['dateTime'] ?? '';
        if ($startRaw === '' || $endRaw === '') {
            continue;
        }

        $start = (new DateTimeImmutable((string) $startRaw))->setTimezone($timezone);
        $end = (new DateTimeImmutable((string) $endRaw))->setTimezone($timezone);
        if ($start <= $now) {
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
    $raw = file_get_contents('php://input');
    $data = json_decode((string) $raw, true);
    if (is_array($data)) {
        return $data;
    }
    return $_POST;
}

function clean_text(array $payload, string $key, int $max = 500): string
{
    $value = trim((string) ($payload[$key] ?? ''));
    $value = preg_replace('/[[:cntrl:]]+/u', ' ', $value) ?? $value;
    return substr($value, 0, $max);
}

function book_slot(array $config, array $payload): array
{
    $required = ['eventId', 'nome', 'cognome', 'telefono', 'email', 'eta', 'sesso', 'sintesi'];
    foreach ($required as $key) {
        if (trim((string) ($payload[$key] ?? '')) === '') {
            respond(['ok' => false, 'message' => 'Compila tutti i campi obbligatori.'], 422);
        }
    }

    if (empty($payload['privacy'])) {
        respond(['ok' => false, 'message' => 'Per prenotare è necessario il consenso privacy.'], 422);
    }

    $email = clean_text($payload, 'email', 160);
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        respond(['ok' => false, 'message' => 'Inserisci un indirizzo email valido.'], 422);
    }

    $eta = (int) clean_text($payload, 'eta', 3);
    if ($eta < 1 || $eta > 120) {
        respond(['ok' => false, 'message' => 'Inserisci un età valida.'], 422);
    }

    $nome = clean_text($payload, 'nome', 80);
    $cognome = clean_text($payload, 'cognome', 80);
    $telefono = clean_text($payload, 'telefono', 80);
    $sesso = clean_text($payload, 'sesso', 80);
    $sintesi = clean_text($payload, 'sintesi', 1200);
    $eventId = clean_text($payload, 'eventId', 200);
    $token = google_access_token($config['service_account']);
    $eventResponse = google_request('GET', calendar_event_url((string) $config['calendar_id'], $eventId), $token);

    if ($eventResponse['status'] === 404) {
        respond(['ok' => false, 'message' => 'Questo orario non è più disponibile. Scegli un altro slot.'], 409);
    }

    if ($eventResponse['status'] < 200 || $eventResponse['status'] >= 300) {
        throw new RuntimeException('Impossibile verificare lo slot.');
    }

    $event = $eventResponse['data'];
    if (!event_has_availability_tag($event)) {
        respond(['ok' => false, 'message' => 'Questo orario non è più disponibile. Scegli un altro slot.'], 409);
    }

    $description = implode("\n", [
        'Nome: ' . $nome,
        'Cognome: ' . $cognome,
        'Telefono: ' . $telefono,
        'Email: ' . $email,
        'Età: ' . $eta,
        'Sesso: ' . $sesso,
        'Sintesi problematica: ' . $sintesi,
    ]);

    $headers = [];
    if (!empty($event['etag'])) {
        $headers[] = 'If-Match: ' . (string) $event['etag'];
    }

    $updateResponse = google_request(
        'PATCH',
        calendar_event_url((string) $config['calendar_id'], $eventId),
        $token,
        [
            'summary' => 'Appuntamento - ' . $nome . ' ' . $cognome,
            'description' => $description,
            'status' => 'confirmed',
        ],
        $headers
    );

    if (in_array($updateResponse['status'], [409, 412], true)) {
        respond(['ok' => false, 'message' => 'Questo orario non è più disponibile. Scegli un altro slot.'], 409);
    }

    if ($updateResponse['status'] < 200 || $updateResponse['status'] >= 300) {
        throw new RuntimeException('Impossibile confermare la prenotazione.');
    }

    return ['ok' => true, 'message' => 'Prenotazione confermata. Riceverai eventuali dettagli da Laura.'];
}

try {
    $config = load_calendar_config();
    $action = (string) ($_GET['action'] ?? $_POST['action'] ?? 'slots');

    if ($_SERVER['REQUEST_METHOD'] === 'GET' && $action === 'slots') {
        respond(['ok' => true, 'slots' => list_slots($config)]);
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'book') {
        respond(book_slot($config, read_payload()));
    }

    respond(['ok' => false, 'message' => 'Richiesta non valida.'], 400);
} catch (Throwable $error) {
    respond(['ok' => false, 'message' => 'Calendario momentaneamente non disponibile. Riprova più tardi.'], 503);
}
