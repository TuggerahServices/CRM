<?php
// First-run seeder: creates centres from Kidsoft's ACTIVE services, then hands
// the rooms to rooms_sync_all().
//
//   php _incl/seed.php                  # report only, changes nothing
//   php _incl/seed.php --apply          # write
//   php _incl/seed.php --apply --year=2027
//
// IT USED TO READ rooms.json AND TYPE THE ROOMS IN, and that was wrong twice
// over. Wrong in principle - a room's name, age band and existence all live in
// Kidsoft, so copying them in creates a copy that drifts. And wrong in fact:
// rooms.json was measurably bad (it truncated double-barrelled names and had one
// room's capacity wrong), it was moved to /var/lib/crm-archive on 6 Aug
// 2026 for publishing children's data out of the web root, and this script has
// therefore exited 1 at the first file_get_contents ever since - taking centre
// creation, the one thing it is still needed for, down with it.
//
// Room matching is no longer a problem to solve here. rooms_sync_centre() joins
// on RoomID, which is unambiguous, so the exact-name matching this file used to
// do - and the near-duplicate and typo traps that made it necessary - are gone
// with the typing. Capacities come from Service/getUtilisation, per weekday, in
// the same pass.

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

// Pins Australia/Sydney - see the header of dates.php. One definition, shared
// with the web path, which had none of its own until 13 Aug 2026.
require_once __DIR__ . '/dates.php';
require_once __DIR__ . '/simple-mysqli.php';
require_once __DIR__ . '/kidsoft.php';
require_once __DIR__ . '/centres-sync.php';
require_once __DIR__ . '/rooms-sync.php';
require_once __DIR__ . '/capacity.php';

$apply = in_array('--apply', $argv, true);

$year = (int)date('Y');
foreach ($argv as $a) {
    if (preg_match('/^--year=(\d{4})$/', $a, $m)) $year = (int)$m[1];
}

// --- Kidsoft: active services ------------------------------------------------
$services = [];
foreach (kidsoft('Service', 'getServices')['Services'] as $s) {
    if (($s['Status'] ?? '') === 'Active') $services[$s['ServiceID']] = $s['Name'];
}
foreach ($services as $serviceId => $kidsoftName) {
    echo str_pad(centre_friendly_name($serviceId, $kidsoftName), 20), $serviceId, "\n";
}

$line = str_repeat('-', 72);
echo $line, "\n", ($apply ? "APPLIED" : "DRY RUN (pass --apply to write)"),
     ": ", count($services), " active service(s)\n";

// centres_sync() is the same code the nightly cron and the Rooms screen's
// "Check Kidsoft for new centres" button run. This script is no longer the only
// way in - it is a first-run convenience, for the moment the database is empty
// and there is no screen to press a button on.
if ($apply) {
    $s = centres_sync(0);
    printf("centres: %d created%s, %d no longer active, %d reopened\n",
        $s['created'], $s['names'] ? ' (' . implode(', ', $s['names']) . ')' : '',
        $s['retired'], $s['restored']);
}

if (!$apply) { echo "\nRooms and capacities would then be read from Kidsoft for {$year}.\n"; exit; }

// --- Rooms and capacities, straight from Kidsoft -----------------------------
$q = rooms_sync_all($year, 0);
$c = capacity_sync_all($year, 0);
printf("rooms %d: %d read, %d created, %d renamed, %d age range(s) changed, %d retired, %d unlinked\n",
    $year, $q['checked'], $q['created'], $q['renamed'], $q['aged'], $q['retired'], $q['unlinked']);
if ($q['clashes']) echo "  NAME CLASH (not applied): ", implode(', ', $q['clashes']), "\n";
printf("capacity %d: %d room(s), %d written, %d changed\n",
    $year, $c['rooms'], $c['written'], $c['changed']);
echo "\n";
