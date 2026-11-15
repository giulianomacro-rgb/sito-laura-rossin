<?php
declare(strict_types=1);
require dirname(__DIR__) . '/lib/runtime.php';
// Exercise the real booking logic against an isolated in-memory Google adapter.
$source = file_get_contents(dirname(__DIR__) . '/api/prenotazioni.php');
$source = substr($source, strpos($source, 'function respond'), strpos($source, "\ntry {") - strpos($source, 'function respond'));
foreach (['respond','google_access_token','google_request'] as $name) $source = str_replace('function ' . $name . '(', 'function original_' . $name . '(', $source);
eval($source);
class HttpResult extends RuntimeException {
    public function __construct(public array $payload, public int $status) { parent::__construct($payload['message'] ?? ''); }
}
function respond(array $payload, int $status = 200): void { throw new HttpResult($payload, $status); }
function google_access_token(array $account): string { return 'test-token'; }
$start = (new DateTimeImmutable('tomorrow 10:00', new DateTimeZone('Europe/Rome')))->format(DATE_ATOM);
$end = (new DateTimeImmutable($start))->modify('+1 hour')->format(DATE_ATOM);
$event = ['id' => 'test1', 'summary' => 'Disponibilita', 'etag' => '"v1"', 'start' => ['dateTime' => $start], 'end' => ['dateTime' => $end]];
$initial = $event;
$patchStatus = 200;
$patchCount = 0;
$accessRole = 'writer';
function google_request(string $method, string $url, string $token, ?array $payload = null, array $headers = []): array {
    global $event, $patchStatus, $patchCount, $accessRole;
    if ($method === 'PATCH') {
        if (!in_array('If-Match: "v1"', $headers, true)) throw new RuntimeException('Missing conditional update');
        $patchCount++;
        if ($patchStatus === 200) $event = array_replace_recursive($event, $payload, ['etag' => '"v2"']);
        return ['status' => $patchStatus, 'data' => ['id' => 'test1']];
    }
    return ['status' => 200, 'data' => str_contains($url, '/events?') ? ['accessRole' => $accessRole, 'items' => [$event]] : $event];
}
function check_flow(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
function expect_status(callable $run, int $expected): void {
    try { $run(); } catch (HttpResult $result) { check_flow($result->status === $expected, $result->getMessage()); return; }
    throw new RuntimeException('Expected HTTP ' . $expected);
}
$config = ['calendar_id' => 'test@example.invalid', 'service_account' => [], 'availability_mode' => 'calendar', 'timezone' => 'Europe/Rome', 'test_mode' => true];
$payload = ['eventId' => 'test1', 'nome' => 'Prova', 'cognome' => 'Collaudo', 'telefono' => '0000000000', 'email' => 'test@example.invalid', 'eta' => '35', 'sesso' => 'Preferisco non indicarlo', 'sintesi' => '', 'privacy' => true, 'privacyVersion' => booking_privacy_version(), 'testData' => true];
$slots = list_slots($config);
check_flow(count($slots) === 1, 'Untagged event is available');
$accessRole = 'writerWithoutPrivateAccess';
$rejected = false;
try { list_slots($config); } catch (RuntimeException $error) { $rejected = true; }
check_flow($rejected, 'No slots returned without private event access');
$accessRole = 'writer';
check_flow(!isset($slots[0]['summary'], $slots[0]['description'], $slots[0]['attendees']), 'No private event details');
expect_status(fn() => book_slot($config, array_replace($payload, ['privacy' => false])), 422);
expect_status(fn() => book_slot($config, array_replace($payload, ['privacyVersion' => 'old'])), 422);
expect_status(fn() => book_slot($config, array_replace($payload, ['email' => 'real@example.com'])), 422);
check_flow($patchCount === 0, 'Invalid consent never writes to Google');
check_flow(book_slot($config, $payload)['ok'], 'Booking succeeds');
check_flow($event['summary'] === 'Appuntamento - Prova Collaudo', 'Same event renamed');
check_flow($event['visibility'] === 'private' && $event['extendedProperties']['private']['lauraBooked'] === '1', 'Private permanent booked marker');
check_flow(str_contains($event['description'], 'COLLAUDO') && str_contains($event['description'], booking_privacy_version()), 'Consent and test audit recorded');
check_flow(list_slots($config) === [], 'Booked slot disappears');
expect_status(fn() => book_slot($config, $payload), 409);
$event['summary'] = 'Renamed';
check_flow(list_slots($config) === [], 'Renamed booking stays hidden');
$event = $initial;
$patchStatus = 412;
expect_status(fn() => book_slot($config, $payload), 409);
echo "OK: isolated Google flow, data minimization, consent, same-event update, replay and ETag conflict\n";
