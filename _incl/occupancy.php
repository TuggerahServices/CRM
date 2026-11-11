<?php
// Occupancy engine - what Kidsoft ACTUALLY has booked, week by week.
//
// The planning board (_incl/board.php) is what the team intends; this is what
// Kidsoft records. The two are compared to surface drift.
//
// "Fill every room, every day, to 100%." Fewer than 10% of children attend all
// five days, so the useful unit is not a room but a ROOM x WEEKDAY cell: a room
// at 90% for the week can still have eight empty Friday places worth chasing.
// Everything here is computed per cell.
//
// Occupied  = distinct children with a Kidsoft booking in that room on that date.
// Capacity  = our room_year_capacity for the year the week falls in (Kidsoft has
//             no capacity field at all). Capacity CHANGES BETWEEN YEARS, so the
//             year is derived from the week being shown, never assumed.
//
// Children marked Absence are STILL COUNTED. An absent child holds and pays for
// their place; the place is not available to anyone else, so treating an absence
// as a vacancy would send staff chasing spots that do not exist.
//
// CASUAL BOOKINGS ARE NOT COUNTED ANYWHERE IN THIS FILE (Broc, 13 Aug 2026: this
// tool is about long-term permanent enrolments only). The two are not the same
// rule pointed different ways: an absence is a permanent place being paid for and
// not used, a casual is no place at all. bookings_year() drops casual rows before
// anything is tallied, so `permanent` and `occupied` both mean permanent-only.

require_once __DIR__ . '/simple-mysqli.php';
require_once __DIR__ . '/rooms.php';
require_once __DIR__ . '/kidsoft-cache.php';

/** Monday of the week containing $date (Sydney). */
function week_monday(?string $date = null): string {
    $d = date_create($date ? substr($date, 0, 10) : 'today', new DateTimeZone('Australia/Sydney'));
    $dow = (int)$d->format('N');            // 1=Mon .. 7=Sun
    if ($dow > 1) $d->modify('-' . ($dow - 1) . ' days');
    return $d->format('Y-m-d');
}

/**
 * Every Mon-Fri date of a calendar year, as [weekday => ['Y-m-d', ...]].
 *
 * This is the denominator for every year-level question the board asks: "booked
 * 12 of 51 Mondays" only means something against the count of Mondays there are.
 */
function year_weekday_dates(int $year): array {
    static $cache = [];
    if (isset($cache[$year])) return $cache[$year];

    $out = [1 => [], 2 => [], 3 => [], 4 => [], 5 => []];
    $d   = date_create("$year-01-01", new DateTimeZone('Australia/Sydney'));
    $end = "$year-12-31";
    while ($d->format('Y-m-d') <= $end) {
        $wd = (int)$d->format('N');
        if ($wd <= 5) $out[$wd][] = $d->format('Y-m-d');
        $d->modify('+1 day');
    }
    return $cache[$year] = $out;
}

/**
 * Fold one more date into a {n, first, last} span.
 *
 * first/last are what tell a mid-year starter from a mid-year leaver: a child
 * booked on 10 Mondays reads completely differently depending on whether those
 * Mondays are January-March (they have left) or October-December (they started).
 */
function booking_span_add(?array $span, string $date): array {
    if ($span === null) return ['n' => 1, 'first' => $date, 'last' => $date];
    $span['n']++;
    if ($date < $span['first']) $span['first'] = $date;
    if ($date > $span['last'])  $span['last']  = $date;
    return $span;
}

/**
 * A month of booking rows with the rows on days the centre is SHUT taken out.
 *
 * KIDSOFT BOOKS CHILDREN ON DAYS THE CENTRE IS CLOSED, AND HAS NO IDEA IT IS
 * CLOSED. Terrigal's Rhodes Kapene-Belcher moves up to Shining Stars for 2027 on
 * an enrolment that starts Monday 28/12/2026, so Kidsoft booked him on 28/12,
 * 31/12 and 1/1/2027 - all inside the Christmas shutdown. The board took that
 * 28/12 as the day Shining Stars' Monday runs to, and every other child in the
 * room, booked to the true last Monday of 21/12, was printed "to 21/12/2026" as
 * though they were leaving (reported by Broc, 10 Nov 2026). Rhodes was also on
 * the 2026 roster, "from 28/12/2026", for a room he never attends that year.
 *
 * The calendar cannot answer this. `Service/getOpeningHours` reports Terrigal
 * OPEN on Christmas Day, with and without TreatPublicHolidaysAsClosed=true
 * (re-probed 10 Nov 2026; see kidsoft-api.md). So the bookings are the
 * evidence: a shut day is one where the centre has almost nobody booked.
 *
 * MEASURED, all seven centres, every month of 2026 and 2027 (3,133 booked
 * weekdays), each date's children against the busiest day of its own month:
 * every shutdown date is at or under 6% (never more than 3 children - 2027 has
 * strays at Tuggerah, Niagara Park, Point Clare and Terrigal), and every
 * trading day is at or over 73%. "Under a quarter" sits well inside that gap.
 * Public holidays are NOT shut by this test - they are booked like any other
 * day - and nothing here claims they are.
 *
 * Counts distinct permanent children per date, so split sessions and casual
 * days cannot hold a shut day open. Read per month because every caller reads
 * per month; a month always contains its centre's ordinary trading days.
 */
function bookings_on_trading_days(array $rows): array {
    $perDate = [];
    foreach ($rows as $b) {
        if (empty($b['ChildID']) || empty($b['Date']) || !empty($b['Casual'])) continue;
        $perDate[substr((string)$b['Date'], 0, 10)][$b['ChildID']] = true;
    }
    if (!$perDate) return $rows;

    $busiest = max(array_map('count', $perDate));
    $shut = [];
    foreach ($perDate as $date => $children) {
        if (count($children) * 4 < $busiest) $shut[$date] = true;
    }
    if (!$shut) return $rows;

    return array_values(array_filter($rows, static fn($b) =>
        !isset($shut[substr((string)($b['Date'] ?? ''), 0, 10)])
    ));
}

/**
 * A whole year of Kidsoft bookings for one service, reduced to the aggregates
 * the board actually compares against.
 *
 * WHY THIS EXISTS. The enrolments team sells a PLACE FOR THE YEAR - a family
 * booked on Mondays pays for every Monday whether or not the child turns up --
 * so the question is never "who is in this week", it is "is this room's Monday
 * sold for the year". Reading Kidsoft a week at a time could not answer that.
 *
 * FETCHED A MONTH AT A TIME AND REDUCED AS IT GOES. A year at Tuggerah is
 * ~40,000 booking rows and 20.6MB of JSON; accumulating them all peaked at 84MB
 * against PHP-FPM's 128M limit. Nothing but the aggregates below outlives the
 * month it came from, which keeps a year comfortably inside a page load.
 *
 * A child may hold more than one booking row for the same day (split sessions),
 * so days are collected in a set per month and only then counted - counting
 * rows would overstate both occupancy and a child's coverage.
 *
 * @param bool $refresh bypass the cache (the Refresh button), at ~33s per year.
 * @return array{
 *   permanent: array<string, array<string, array<int, array{n:int,first:string,last:string}>>>,
 *   occupied:  array<string, array<int, array<string, int>>>,
 *   weekdays:  array<int, list<string>>,
 *   months:    int
 * }
 */
function bookings_year(string $serviceId, int $year, bool $refresh = false): array {
    $weekdays  = year_weekday_dates($year);
    $permanent = [];
    $occupied  = [];
    $months    = 0;

    for ($m = 1; $m <= 12; $m++) {
        $first = sprintf('%d-%02d-01', $year, $m);
        $last  = date_create($first)->modify('last day of this month')->format('Y-m-d');

        // Shutdown bookings out first - see bookings_on_trading_days().
        $rows = bookings_on_trading_days(kidsoft_rows(
            kidsoft_cached('Bookings', 'getBookings', [
                'ServiceID' => $serviceId,
                'DateFrom'  => $first,
                'DateTo'    => $last,
            ], $refresh ? 0 : null),
            'Bookings', $serviceId
        ));
        $months++;

        // Per-month sets, so a split session counts once and nothing from an
        // earlier month is still being held.
        $monthDays = [];   // childId => ksRoomId => date => true
        $monthOcc  = [];   // ksRoomId => date => childId => true

        foreach ($rows as $b) {
            // A row missing any of these cannot be attributed to a child in a
            // room on a day; grouping such rows under an empty key would invent
            // a synthetic child.
            if (empty($b['ChildID']) || empty($b['RoomID']) || empty($b['Date'])) continue;
            $date = substr((string)$b['Date'], 0, 10);
            if (substr($date, 0, 4) !== (string)$year) continue;
            $wd = (int)date('N', strtotime($date));
            if ($wd > 5) continue;

            // CASUAL DAYS ARE NOT PART OF THIS TOOL AT ALL (Broc, 13 Aug 2026).
            // This used to keep them in a parallel `casual` map AND to count them
            // as occupied, on the argument that "a casual child is in the room and
            // the place is gone". True of the room on the day, and irrelevant to
            // every question this app asks: the unit here is a PERMANENT weekday
            // place sold for the year, and a casual day neither sells one nor
            // stops one being sold. Counting them made booked_year_summary() read
            // a cell as fuller across the year than its permanent roll ever was,
            // which understates the unsold place-days that whole figure is for.
            if (!empty($b['Casual'])) continue;

            // NO FEE IS READ OFF THESE ROWS, and `Fee` is deliberately ignored.
            // There was a histogram here tallying what each room bills so unsold
            // place-days could be priced; it fed a dollar figure on the placement
            // board, and this is a child enrolment tool, not a revenue one.
            // Removed 10 Aug 2026 at Broc's direction. Revenue is a different
            // team's job with different systems, so do not tally it here again
            // "just in case a screen wants it" - a computed figure is one line
            // away from being rendered, which is how it came back last time.
            $monthDays[$b['ChildID']][$b['RoomID']][$date] = true;
            $monthOcc[$b['RoomID']][$date][$b['ChildID']] = true;
        }
        unset($rows);

        foreach ($monthDays as $childId => $byRoom) {
            foreach ($byRoom as $roomId => $dates) {
                foreach (array_keys($dates) as $date) {
                    $wd = (int)date('N', strtotime($date));
                    $permanent[$childId][$roomId][$wd] =
                        booking_span_add($permanent[$childId][$roomId][$wd] ?? null, $date);
                }
            }
        }

        foreach ($monthOcc as $roomId => $byDate) {
            foreach ($byDate as $date => $children) {
                $wd = (int)date('N', strtotime($date));
                $occupied[$roomId][$wd][$date] = count($children);
            }
        }
        unset($monthDays, $monthOcc);
    }

    return [
        'permanent' => $permanent,
        'occupied'  => $occupied,
        'weekdays'  => $weekdays,
        'months'    => $months,
    ];
}

// bookings_fee_by_room() WAS HERE and is gone (10 Aug 2026). It took the median
// fee each room really billed and priced the unsold place-days from it. The
// arithmetic was sound; the feature was not this project's. Nothing in this app
// renders a currency figure, so nothing needs a rate.

/** The five weekday dates of a week, as [1 => 'Y-m-d', .. 5 => ...]. */
function week_dates(string $monday): array {
    $out = [];
    $d = date_create($monday, new DateTimeZone('Australia/Sydney'));
    for ($i = 1; $i <= 5; $i++) {
        $out[$i] = $d->format('Y-m-d');
        $d->modify('+1 day');
    }
    return $out;
}

/** ChildID => DOB, for the age tests that sub-quotas need. */
function children_dob_map(string $serviceId): array {
    $rows = kidsoft_rows(
        kidsoft_cached('Children', 'getChildren', ['ServiceID' => $serviceId]),
        'Children', $serviceId
    );
    $map = [];
    foreach ($rows as $c) {
        if (!empty($c['ChildID'])) $map[$c['ChildID']] = $c['DOB'] ?? null;
    }
    return $map;
}

