<?php
// Build a year's board from Kidsoft's actual bookings.
//
// This replaces seeding the board from the per-centre spreadsheets, which were
// retired on 27 Jul 2026 as obsolete. Measured against Kidsoft at the time, the
// Tuggerah workbook had misspelt or truncated 26 of 261 children (it drops the
// second half of double-barrelled surnames, so "Lewis-Menard" arrived as
// "Lewis") and was missing 43 children who were booked and being billed.
//
// Kidsoft is the source: every child here arrives already linked by ChildID, so
// none of the name-matching machinery in _incl/drift.php ever has to run on them.
//
// A SNAPSHOT OF ONE MONTH, not a union of the year. This matters and was got
// wrong first time: importing every child who attended at any point in the year
// puts a child who left in March on the board beside one who started in November,
// and the board then exceeds its own capacity on every day (Kanwal came out at
// 457 child-days against 395 places). The board answers "who holds a place",
// which is a question about a moment, so one reference month is sampled and only
// children booked in it are placed.
//
// A whole month is used rather than a single week so a public holiday cannot
// silently drop a weekday from someone's pattern.
//
// CASUAL bookings are excluded: a casual is a one-off, not a standing place.

require_once __DIR__ . '/kidsoft-cache.php';
require_once __DIR__ . '/rooms.php';
require_once __DIR__ . '/board.php';      // placement_set_days()
require_once __DIR__ . '/identity.php';
require_once __DIR__ . '/audit.php';

/**
 * Import (or refresh) a centre's board for a year from Kidsoft bookings.
 *
 * Idempotent: a child already placed in that room and year has their days
 * updated rather than a second row created. Rows added by hand that Kidsoft has
 * never heard of are left alone - this only ever adds or corrects what Kidsoft
 * can vouch for.
 *
 * @param array $opts 'apply' => false for a dry run
 * @return array{children:int, created:int, updated:int, unchanged:int, rows:array, unmapped:int}
 */
function board_import_year(int $centreId, int $year, array $opts = []): array {
    $apply = ($opts['apply'] ?? true) !== false;
    $centre = centre_get($centreId);
    if (!$centre) throw new InvalidArgumentException('unknown centre');
    $serviceId = $centre['kidsoft_service_id'];

    $roomByKs = [];
    foreach (rooms_for_centre($centreId, $year) as $r) {
        if ($r['kidsoft_room_id']) $roomByKs[$r['kidsoft_room_id']] = $r;
    }

    // The reference month: the current month when it falls inside $year,
    // otherwise the middle of that year. Overridable for a deliberate re-read.
    $now = date_create('today', new DateTimeZone('Australia/Sydney'));
    $month = (int)($opts['month'] ?? ((int)$now->format('Y') === $year ? (int)$now->format('n') : 6));

    $from = sprintf('%d-%02d-01', $year, $month);
    $to = date_create($from, new DateTimeZone('Australia/Sydney'))
        ->modify('last day of this month')->format('Y-m-d');

    // childId => roomKidsoftId => [weekday => true]
    $seen = [];
    $unmapped = 0;
    $rows = kidsoft_rows(kidsoft_cached('Bookings', 'getBookings', [
        'ServiceID' => $serviceId, 'DateFrom' => $from, 'DateTo' => $to,
    ]), 'Bookings', $serviceId);

    foreach ($rows as $b) {
        if (empty($b['ChildID']) || empty($b['RoomID']) || empty($b['Date'])) continue;
        if (!empty($b['Casual'])) continue;
        if (!isset($roomByKs[$b['RoomID']])) { $unmapped++; continue; }
        $date = substr((string)$b['Date'], 0, 10);
        $wd = (int)date('N', strtotime($date));
        if ($wd < 1 || $wd > 5) continue;
        $seen[$b['ChildID']][$b['RoomID']][$wd] = true;
    }

    $children = kidsoft_children($serviceId);

    $created = 0; $updated = 0; $unchanged = 0; $report = [];
    foreach ($seen as $childId => $rooms) {
        $c = $children[$childId] ?? null;
        $first = $c ? name_case((string)($c['FirstName'] ?? '')) : '';
        $last  = $c ? name_case((string)($c['LastName'] ?? '')) : '';
        // A booking we cannot name is not something to put on a board.
        if ($first === '' || $last === '') continue;
        $dob = ($c && !empty($c['DOB'])) ? substr((string)$c['DOB'], 0, 10) : null;

        foreach ($rooms as $ksRoomId => $wds) {
            $room = $roomByKs[$ksRoomId];
            $days = array_keys($wds);
            sort($days);
            if (!$days) continue;

            $existing = dbx()->query(
                'SELECT * FROM placements WHERE kidsoft_child_id = ? AND room_id = ? AND year = ?',
                [$childId, $room['id'], $year]
            )->fetch();

            $report[] = [
                'child' => "$first $last", 'room' => $room['name'], 'days' => $days,
                'action' => $existing ? 'update' : 'create',
            ];

            if (!$apply) { $existing ? $updated++ : $created++; continue; }

            if ($existing) {
                $was = array_map('intval', dbx()->query(
                    'SELECT weekday FROM placement_days WHERE placement_id = ? ORDER BY weekday',
                    [$existing['id']], 'i')->fetchAll('col'));
                if ($was === $days) { $unchanged++; continue; }
                placement_set_days((int)$existing['id'], $days);
                audit('placement.import_days', 'placement', (int)$existing['id'],
                      ['from' => $was, 'to' => $days, 'source' => 'kidsoft']);
                $updated++;
                continue;
            }

            dbx()->query(
                'INSERT INTO placements (centre_id, room_id, year, kidsoft_child_id, child_first_name,
                        child_last_name, child_dob, identity_synced_at, status)
                 VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), ?)',
                [$centreId, $room['id'], $year, $childId, $first, $last, $dob,
                 // They are booked and being billed, so their place is held --
                 // which is exactly what 'confirmed' means on this board.
                 'confirmed']
            );
            $id = dbx()->insertId();
            placement_set_days($id, $days);
            $created++;
        }
    }

    if ($apply && ($created || $updated)) {
        audit('board.import_from_kidsoft', 'centre', $centreId, [
            'year' => $year, 'created' => $created, 'updated' => $updated,
            'unchanged' => $unchanged, 'children' => count($seen),
        ]);
    }

    return ['children' => count($seen), 'created' => $created, 'updated' => $updated,
            'unchanged' => $unchanged, 'rows' => $report, 'unmapped' => $unmapped];
}
