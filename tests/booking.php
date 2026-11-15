<?php
declare(strict_types=1);
require dirname(__DIR__) . '/lib/runtime.php';
// Load the pure booking functions without invoking the HTTP controller.
$source = file_get_contents(dirname(__DIR__) . '/api/prenotazioni.php');
$source = substr($source, strpos($source, 'function respond'), strpos($source, "\ntry {") - strpos($source, 'function respond'));
eval($source);
function verify(bool $ok, string $label): void {
    if (!$ok) throw new RuntimeException($label);
}
$slot = ['id' => 'test', 'summary' => 'Disponibilita #disp', 'start' => ['dateTime' => '2030-01-07T10:00:00+01:00'], 'end' => ['dateTime' => '2030-01-07T11:00:00+01:00']];
verify(event_is_available_slot($slot), 'Tagged timed slot');
verify(event_is_available_slot(array_replace($slot, ['summary' => ''])), 'Dedicated calendar needs no title');
verify(!event_is_available_slot(array_replace($slot, ['summary' => '#disponibile']), 'tag'), 'Legacy tag boundary');
verify(!event_is_available_slot(array_replace($slot, ['summary' => '', 'extendedProperties' => ['private' => ['lauraBooked' => '1']]])), 'Renaming a booking never reopens it');
verify(!event_is_available_slot(array_replace($slot, ['summary' => 'Occupato colloquio'])), 'Manual busy event excluded');
verify(!event_is_available_slot(array_replace($slot, ['summary' => 'Appuntamento - #disp'])), 'Booked name cannot reopen slot');
verify(!event_is_available_slot(array_replace($slot, ['status' => 'cancelled'])), 'Cancelled slot');
verify(!event_is_available_slot(array_replace($slot, ['attendees' => [['email' => 'test@example.invalid']]])), 'Shared event is not bookable');
verify(!event_is_available_slot(array_replace($slot, ['start' => ['date' => '2030-01-07']])), 'All-day slot excluded');
$timezone = new DateTimeZone('Europe/Rome');
$busy = busy_intervals([array_replace($slot, ['summary' => 'Appuntamento - Prova'])], $timezone);
verify(overlaps_busy(new DateTimeImmutable('2030-01-07T10:30:00+01:00'), new DateTimeImmutable('2030-01-07T11:30:00+01:00'), $busy), 'Overlap blocks slot');
verify(!overlaps_busy(new DateTimeImmutable('2030-01-07T11:00:00+01:00'), new DateTimeImmutable('2030-01-07T12:00:00+01:00'), $busy), 'Adjacent interval allowed');
verify(clean_text(['nome' => 'Caffe ééé'], 'nome', 7) === 'Caffe é', 'Unicode truncation');
echo "OK: booking filters, overlaps, Unicode validation\n";
