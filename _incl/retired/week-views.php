<?php
// RETIRED 13 Aug 2026. Loaded by nothing.
//
// The two week-shaped engines left over from the week picker, which the year axis
// replaced (see _incl/docs/placement-board.md: cells_week() returned identical
// figures for two weeks ten weeks apart). Both had already lost every caller.
//
// They are here rather than deleted because BOTH COUNTED CASUAL BOOKINGS, and
// this tool is about long-term permanent enrolments ONLY (Broc, 13 Aug 2026):
//
//   occupancy_week()       - collapsed every booking row onto (room, date,
//                             child) with no `Casual` test at all, so a one-off
//                             day consumed a place in the occupancy figures.
//   day_requests_for_dates() - the per-date twin of day_requests_year(), with
//                             none of the filters that function has grown:
//                             no archived-child test and no casual-type test.
//
// If a week view is ever wanted again, take the year engines and narrow them --
// do not revive these.

declare(strict_types=1);

/**
 * Occupancy for one centre for the week beginning $monday.
 *
 * @return array{
 *   monday:string, dates:array<int,string>, rooms:array, totals:array, stale:?string
 * }
 */
function occupancy_week(int $centreId, string $monday): array {
    $centre = centre_get($centreId);
    if (!$centre) throw new InvalidArgumentException('unknown centre');
    $serviceId = $centre['kidsoft_service_id'];
    $dates = week_dates($monday);
    // Capacity is year-scoped; take the year from the week being displayed.
    $year  = (int)substr($dates[1], 0, 4);
    $rooms = rooms_for_centre($centreId, $year);

    // --- Kidsoft bookings for the week -------------------------------------
    // The API caps a getBookings range at ~1 month, so a single week is safe.
    $bookings = kidsoft_rows(
        kidsoft_cached('Bookings', 'getBookings', [
            'ServiceID' => $serviceId,
            'DateFrom'  => $dates[1],
            'DateTo'    => $dates[5],
        ]),
        'Bookings', $serviceId
    );

    // roomKidsoftId => date => [ChildID => true]. A child can have more than one
    // booking row for a day (split sessions), so collapse on ChildID - counting
    // rows would overstate occupancy.
    $seen = [];
    foreach ($bookings as $b) {
        $date = substr((string)$b['Date'], 0, 10);
        if (!in_array($date, $dates, true)) continue;
        $seen[$b['RoomID']][$date][$b['ChildID']] = true;
    }

    $dobs = null;   // fetched lazily - only sub-quotas need ages

    $out = [];
    $totals = array_fill_keys(array_keys($dates), ['occupied' => 0, 'capacity' => 0]);

    foreach ($rooms as $room) {
        $cells = [];

        foreach ($dates as $wd => $date) {
            // Capacity is PER WEEKDAY. null = none recorded, reported as unknown
            // rather than as a real zero, which would read as permanently full.
            $capacity = $room['capacity'][$wd] ?? null;
            $childIds = [];
            if ($room['kidsoft_room_id'] && isset($seen[$room['kidsoft_room_id']][$date])) {
                $childIds = array_keys($seen[$room['kidsoft_room_id']][$date]);
            }
            $occupied = count($childIds);

            // --- Sub-quota usage for this cell ------------------------------
            $quotas = [];
            if ($room['subquotas']) {
                if ($dobs === null) $dobs = children_dob_map($serviceId);
                $ages = [];
                foreach ($childIds as $cid) $ages[] = age_months($dobs[$cid] ?? null, $date);
                foreach ($room['subquotas'] as $q) {
                    $used = 0;
                    foreach ($ages as $a) if (subquota_matches($q, $a)) $used++;
                    $quotas[] = [
                        'id'    => (int)$q['id'],
                        'label' => $q['label'],
                        'used'  => $used,
                        'max'   => (int)$q['max_children'],
                        'full'  => $used >= (int)$q['max_children'],
                    ];
                }
            }

            $cells[$wd] = [
                'date'      => $date,
                'occupied'  => $occupied,
                'capacity'  => $capacity,
                'vacancies' => $capacity === null ? null : max(0, $capacity - $occupied),
                // Real and worth flagging loudly: it means the room is booked
                // beyond its licensed places on that day.
                'over'      => $capacity !== null && $occupied > $capacity,
                'pct'       => $capacity ? (int)round($occupied / $capacity * 100) : null,
                'subquotas' => $quotas,
            ];

            $totals[$wd]['occupied'] += $occupied;
            if ($capacity !== null) $totals[$wd]['capacity'] += $capacity;
        }

        $out[] = [
            'id'              => (int)$room['id'],
            'name'            => $room['name'],
            'capacity'        => $room['capacity'],
            'capacity_max'    => $room['capacity_max'],
            'min_age_months'  => $room['min_age_months'],
            'max_age_months'  => $room['max_age_months'],
            'linked'          => (bool)$room['kidsoft_room_id'],
            'cells'           => $cells,
            'week_vacancies'  => $room['capacity_max'] === null
                ? null : array_sum(array_map(static fn($c) => (int)$c['vacancies'], $cells)),
        ];
    }

    foreach ($totals as $wd => $t) {
        $totals[$wd]['vacancies'] = max(0, $t['capacity'] - $t['occupied']);
        $totals[$wd]['pct'] = $t['capacity'] > 0 ? (int)round($t['occupied'] / $t['capacity'] * 100) : null;
    }

    return [
        'monday' => $monday,
        'year'   => $year,
        'dates'  => $dates,
        'rooms'  => $out,
        'totals' => $totals,
        'centre' => ['id' => $centreId, 'name' => $centre['name']],
    ];
}

/**
 * Open day requests for a set of dates at one service.
 *
 * getWaitlistedBookings caps a range at one month (the same cap as getBookings),
 * and a Mon-Fri week can straddle a month boundary, so the fetch is done per
 * distinct calendar month covering $dates.
 *
 * @param  array $dates  weekday => 'Y-m-d'
 * @return array kidsoftRoomId => date => list of requests
 */
function day_requests_for_dates(string $serviceId, array $dates): array {
    if (!$dates) return [];

    $months = [];
    foreach ($dates as $d) $months[substr($d, 0, 7)] = true;

    $rows = [];
    foreach (array_keys($months) as $ym) {
        $first = $ym . '-01';
        $last  = date_create($first)->modify('last day of this month')->format('Y-m-d');
        $res = kidsoft_cached('Bookings', 'getWaitlistedBookings', [
            'ServiceID' => $serviceId,
            'DateFrom'  => $first,
            'DateTo'    => $last,
        ]);
        foreach (kidsoft_rows($res, 'WaitlistedBookings', $serviceId) as $r) {
            $rows[$r['WaitlistedBookingID'] ?? count($rows)] = $r;
        }
    }
    if (!$rows) return [];

    // Names, ages and contact details. Both lookups are cached for hours; a day
    // request is worthless to staff without a name and a way to ring the family.
    $children = [];
    foreach (kidsoft_rows(kidsoft_cached('Children', 'getChildren', ['ServiceID' => $serviceId]),
                          'Children', $serviceId) as $c) {
        if (!empty($c['ChildID'])) $children[$c['ChildID']] = $c;
    }
    $guardians = guardians_by_id($serviceId);   // with phone numbers

    $wanted = array_flip($dates);
    $out = [];
    foreach ($rows as $r) {
        if (!in_array($r['Status'] ?? '', DAY_REQUEST_OPEN, true)) continue;
        $date = substr((string)($r['Date'] ?? ''), 0, 10);
        if (!isset($wanted[$date]) || empty($r['RoomID'])) continue;
        // A family who has left is not demand - see the header. Guarded here as
        // well as in day_requests_year() so the two cannot disagree about who is
        // still waiting if this week-shaped path is ever put back to work.
        if (day_request_child_archived($children, $r['ChildID'] ?? null)) continue;

        $c = $children[$r['ChildID'] ?? ''] ?? null;
        $g = $guardians[$r['GuardianID'] ?? ''] ?? null;
        $phone = $g ? guardian_phone($g) : null;

        $out[$r['RoomID']][$date][] = [
            'id'               => $r['WaitlistedBookingID'] ?? null,
            'kidsoft_child_id' => $r['ChildID'] ?? null,
            // Kidsoft stores names in block capitals; cased for reading here the
            // same way linked placements are, so one screen does not mix the two.
            'name'             => $c ? trim(name_case((string)($c['FirstName'] ?? '')) . ' '
                                          . name_case((string)($c['LastName'] ?? ''))) : 'Unknown child',
            'dob'              => $c['DOB'] ?? null,
            'guardian'         => $g ? trim(name_case((string)($g['FirstName'] ?? '')) . ' '
                                          . name_case((string)($g['LastName'] ?? ''))) : null,
            'guardian_email'   => $g['Email'] ?? null,
            // See day_requests_year and _incl/contacts.php.
            'guardian_phone'      => $phone['number'] ?? null,
            'guardian_phone_type' => $phone['type'] ?? null,
            'guardian_phone_is_parent' => $phone['is_parent'] ?? null,
            'status'           => $r['Status'],
            'requested_at'     => $r['CreatedDateTime'] ?? null,
        ];
    }

    // Longest-waiting first within a cell: the queue-order principle used
    // everywhere else in the app applies just as much here.
    foreach ($out as $room => $byDate) {
        foreach ($byDate as $date => $list) {
            usort($list, static fn($a, $b) => (string)$a['requested_at'] <=> (string)$b['requested_at']);
            $out[$room][$date] = $list;
        }
    }
    return $out;
}
