<?php
// Room capacity, read from Kidsoft.
//
// This file exists because a long-standing project assumption was WRONG. CLAUDE.md
// stated for months that "Kidsoft has no capacity field anywhere" and that the
// denominator of every occupancy figure had to be typed in by the enrolments team.
// It does have one: Service/getUtilisation reports OperationalPositions for every
// room, for every weekday of a week. Verified 27 Jul 2026 against all seven
// centres - it matched the hand-entered figures everywhere except Tuggerah's
// Shining Stars Pink, where the spreadsheet said 14 and Kidsoft says 15.
//
// PER WEEKDAY, and that matters: Terrigal's Amazing Asteroids runs 14/15/15/15/16
// Mon-Fri and Wamberal's Little Angels 7/8/7/8/8. The old schema held one number
// per room per year, which would misreport free places on exactly the day someone
// is trying to fill.
//
// OperationalPositions is stable across a year (sampled at four points in 2026),
// so one read per room-year-weekday is enough; the nightly cron refreshes it.
//
// THERE IS NO LONGER A MANUAL OVERRIDE (11 Aug 2026). Capacity comes from
// Kidsoft and only from Kidsoft, the same rule the board already follows. The
// override existed so a weekday closure could be recorded, and it was never used
// for that or anything else: measured before removal, all 360 capacity rows
// group-wide read source='kidsoft' and ZERO were manual. The `source` column
// stays because provenance is still worth recording, and it now always says
// 'kidsoft'.

require_once __DIR__ . '/kidsoft-cache.php';
require_once __DIR__ . '/rooms.php';

/** Kidsoft's weekday keys, in our 1=Mon..5=Fri order. (The published docs
 *  misspell Tuesday and Thursday; the API itself does not.) */
const UTIL_DAYS = [1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday'];

/**
 * Operational positions per room per weekday for the week containing $monday.
 *
 * @return array kidsoftRoomId => [weekday => ?int]
 */
function capacity_from_kidsoft(string $serviceId, string $monday, ?int $ttl = null): array {
    $sunday = date_create($monday, new DateTimeZone('Australia/Sydney'))
        ->modify('+6 days')->format('Y-m-d');

    $res = kidsoft_cached('Service', 'getUtilisation', [
        'ServiceID' => $serviceId,
        'DateFrom'  => $monday,
        'DateTo'    => $sunday,
        // Casuals and absentees change who is BOOKED, never how many places the
        // room is licensed for. Pinned so a Kidsoft-side settings change cannot
        // silently move our denominator.
        'ExcludeCasualBookings' => 'true',
    ], $ttl);

    $out = [];
    foreach ($res['Utilisations'] ?? [] as $u) {
        foreach ($u['Rooms'] ?? [] as $rm) {
            if (empty($rm['RoomID'])) continue;
            $days = [];
            foreach (UTIL_DAYS as $wd => $key) {
                $v = $rm['Utilisation'][$key]['OperationalPositions'] ?? null;
                $days[$wd] = ($v === null || $v === '') ? null : (int)$v;
            }
            $out[$rm['RoomID']] = $days;
        }
    }
    return $out;
}

/** Monday of the Nth week of a month, Sydney. */
function capacity_sample_week(int $year, int $month, int $plusWeeks = 2): string {
    $d = date_create(sprintf('%d-%02d-01', $year, $month), new DateTimeZone('Australia/Sydney'));
    while ((int)$d->format('N') !== 1) $d->modify('+1 day');
    return $d->modify("+{$plusWeeks} weeks")->format('Y-m-d');
}

/**
 * Operational positions for every room at a centre across a whole year.
 *
 * FOUR weeks are sampled, and the first that reports a given room wins. One
 * sample is not enough: a February-only read found no capacity at all for
 * Warnervale in 2026 and missed a Tuggerah room, because a centre or room that
 * opens mid-year simply is not operating in February. Sampling in term time
 * (never January or the Christmas shutdown) keeps the figures representative.
 *
 * NOVEMBER WAS ADDED 2 Oct 2026, for Erina: its rooms report positions from the
 * week of 5 Oct (it opens Tue 6 Oct), so Feb/May/Aug all came back empty and
 * its 2026 had no capacity at all while 2027 was fine. Measured before adding
 * it: the November read changed nothing for the other seven centres in either
 * year. A centre opening after mid-November is still missed for that year.
 *
 * Extracted from capacity_sync_year() so the ROOM sync can share the same read
 * (_incl/rooms-sync.php uses it as the "is this room really operating" test,
 * which is the documented alternative to trusting getRooms.Active). Two callers
 * doing their own sampling would be six API calls per centre for one answer, and
 * with ttl=0 - which the Refresh button passes - the cache would not save them.
 *
 * @return array kidsoftRoomId => [weekday => ?int]
 */
function capacity_util_year(string $serviceId, int $year, ?int $ttl = null): array {
    $ks = [];
    foreach ([2, 5, 8, 11] as $month) {   // mid Feb, May, Aug, Nov - all term time
        try {
            $sample = capacity_from_kidsoft($serviceId, capacity_sample_week($year, $month), $ttl);
        } catch (RuntimeException $e) { continue; }
        foreach ($sample as $roomId => $days) {
            if (!isset($ks[$roomId])) $ks[$roomId] = $days;
            else foreach ($days as $wd => $v) if ($ks[$roomId][$wd] === null) $ks[$roomId][$wd] = $v;
        }
    }
    return $ks;
}

/**
 * Refresh one centre's capacities for a year from Kidsoft.
 *
 * $util lets a caller that has already read the utilisation (the combined
 * Refresh on the Rooms screen, and the nightly cron) hand it over rather than
 * paying for it twice.
 *
 * @return array{rooms:int, written:int, changed:int}
 */
function capacity_sync_year(int $centreId, int $year, ?int $ttl = null, ?array $util = null): array {
    $centre = centre_get($centreId);
    if (!$centre) return ['rooms' => 0, 'written' => 0, 'changed' => 0];

    $ks = $util ?? capacity_util_year($centre['kidsoft_service_id'], $year, $ttl);
    if (!$ks) return ['rooms' => 0, 'written' => 0, 'changed' => 0];

    $rooms = dbx()->query(
        'SELECT id, kidsoft_room_id FROM rooms WHERE centre_id = ? AND active = 1 AND kidsoft_room_id IS NOT NULL',
        [$centreId], 'i'
    )->fetchAll();

    $existing = [];
    foreach (dbx()->query(
        'SELECT y.room_id, y.weekday, y.capacity, y.source FROM room_year_capacity y
         JOIN rooms r ON r.id = y.room_id WHERE r.centre_id = ? AND y.year = ?',
        [$centreId, $year], 'ii'
    )->fetchAll() as $e) {
        $existing[(int)$e['room_id']][(int)$e['weekday']] = $e;
    }

    $written = 0; $changed = 0; $seen = 0;
    foreach ($rooms as $r) {
        $days = $ks[$r['kidsoft_room_id']] ?? null;
        if ($days === null) continue;      // room not operating that year
        $seen++;
        foreach ($days as $wd => $cap) {
            // No operational positions on a day is genuinely CLOSED, not zero
            // capacity to be filled. Nothing is written, so the cell reports
            // unknown rather than inviting someone to fill a shut room.
            if ($cap === null) continue;

            $prev = $existing[(int)$r['id']][$wd] ?? null;
            if ($prev && (int)$prev['capacity'] === $cap && $prev['source'] === 'kidsoft') {
                dbx()->query('UPDATE room_year_capacity SET synced_at = NOW()
                              WHERE room_id = ? AND year = ? AND weekday = ?',
                    [$r['id'], $year, $wd], 'iii');
                continue;
            }
            dbx()->query(
                'INSERT INTO room_year_capacity (room_id, year, weekday, capacity, source, synced_at)
                 VALUES (?, ?, ?, ?, \'kidsoft\', NOW())
                 ON DUPLICATE KEY UPDATE capacity = VALUES(capacity), source = \'kidsoft\', synced_at = NOW()',
                [$r['id'], $year, $wd, $cap], 'iiii'
            );
            $written++;
            if ($prev) $changed++;
        }
    }
    return ['rooms' => $seen, 'written' => $written, 'changed' => $changed];
}

/**
 * One room's capacity for a year, with provenance: [weekday => [capacity, source,
 * synced_at]]. The Rooms screen shows WHEN each figure was read, so a number that
 * looks wrong can be told apart from a number that is merely old.
 */
function room_capacity_detail(int $roomId, int $year): array {
    $out = [];
    foreach (array_keys(WEEKDAYS) as $wd) {
        $out[$wd] = ['capacity' => null, 'source' => null, 'synced_at' => null];
    }
    foreach (dbx()->query(
        'SELECT weekday, capacity, source, synced_at FROM room_year_capacity
         WHERE room_id = ? AND year = ?', [$roomId, $year], 'ii'
    )->fetchAll() as $r) {
        $out[(int)$r['weekday']] = [
            'capacity'  => (int)$r['capacity'],
            'source'    => $r['source'],
            'synced_at' => $r['synced_at'],
        ];
    }
    return $out;
}

/** Sync every active centre for a year. */
function capacity_sync_all(int $year, ?int $ttl = null): array {
    $t = ['rooms' => 0, 'written' => 0, 'changed' => 0];
    foreach (centres_all() as $c) {
        try { $r = capacity_sync_year((int)$c['id'], $year, $ttl); }
        catch (RuntimeException $e) { continue; }   // one centre failing must not stop the rest
        foreach ($t as $k => $v) $t[$k] = $v + $r[$k];
    }
    return $t;
}
