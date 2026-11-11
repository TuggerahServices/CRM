<?php
// The board, DERIVED from Kidsoft rather than copied into our tables.
//
// WHY THIS REPLACED THE placements COPY (6 Aug 2026). CLAUDE.md's own rule is
// "anything Kidsoft can answer, READ from Kidsoft - live and cached, not copied
// into our tables, because a copy drifts the moment Kidsoft changes." The board
// was the one place that rule was never applied, and it cost exactly what the
// rule predicts. Measured before this change:
//
//   * All 870 placements were PURE COPIES. Zero carried a non-default status,
//     zero carried a sibling/staff/two-educator/behaviour flag, zero carried a
//     note, zero were provisional. Every byte was derivable from Kidsoft.
//   * The copy was stale BY CONSTRUCTION. Niagara Park's stored 2026 board
//     matched Kidsoft's JULY bookings 157 of 157 - the month somebody pressed
//     the button - and already disagreed with August on 6. Month to month,
//     4-19% of (child, room, days) arrangements change.
//   * 2027 was EMPTY while Kidsoft held 220 children already re-enrolled, because
//     board_import_year() was never wired to anything and Roll over needed a
//     human to press it.
//   * _incl/drift.php - 500 lines, an OSA distance function and a bespoke
//     near-name matcher - existed solely to detect that the copy had gone stale.
//     A problem created entirely by making the copy.
//
// Deriving instead costs nothing: one API call per centre-year (not twelve), and
// the whole group derives in 0.05s warm.
//
// THE SPLIT THIS ESTABLISHES, and it is the whole architecture:
//
//   Kidsoft is the WINDOW  - who actually holds a place, right now. Never copied.
//   Our tables are the WORK - what the team is doing about the empty places.
//                              Kidsoft has no field for any of it.
//
// So `placements` survives only as an ANNOTATION store (see board_annotations()):
// status, flags and notes keyed on (kidsoft_child_id, room, year), plus the rare
// child pencilled in before Kidsoft knows them. It is never again the source of
// who is on the board.

declare(strict_types=1);

require_once __DIR__ . '/kidsoft-cache.php';
require_once __DIR__ . '/identity.php';   // kidsoft_children(), name_case()
// syd_date() for centre_not_open_yet(). Named here rather than relied on
// arriving through board.php: a missing function LINTS CLEAN in PHP and only
// fails at the moment somebody opens an unopened centre.
require_once __DIR__ . '/dates.php';
// bookings_on_trading_days() - Kidsoft books children inside the Christmas
// shutdown, and a booking on a shut day holds nothing.
require_once __DIR__ . '/occupancy.php';

/**
 * Which month to read a year's bookings from.
 *
 * A board answers "who holds a place", which is a question about a MOMENT, so
 * one reference month is sampled rather than the union of the year - importing
 * everyone who attended at any point puts a child who left in March beside one
 * who started in November and the board then exceeds its own capacity (Kanwal
 * came out at 457 child-days against 395 places). A whole month rather than a
 * week, so a public holiday cannot silently drop a weekday from a pattern.
 *
 *   current year -> the current month (what is happening now)
 *   future year  -> February. Verified 6 Aug 2026: Tuggerah's 2027 bookings read
 *                   143 children in Feb, Apr, Jun, Sep and Nov alike - centres
 *                   book the whole year at once when they re-enrol, so February
 *                   is as good as any and is the earliest the year is real.
 *                   January is avoided; it is half shutdown.
 *   past year    -> June, mid-year and settled.
 */
function board_reference_month(int $year): int {
    $now  = date_create('today', new DateTimeZone('Australia/Sydney'));
    $thisYear = (int)$now->format('Y');
    if ($year === $thisYear) return (int)$now->format('n');
    return $year > $thisYear ? 2 : 6;
}

/**
 * THE DATE A BOARD COUNTS FROM - one definition, read by the forward peak, the
 * declared departures and cells_year()'s leaving filter alike.
 *
 * Without a chosen date: today for the year running, 1 January for a year being
 * planned, and NULL for a year that has finished - which keeps that year on the
 * reference-month fallback it has always had.
 *
 * A CHOSEN DATE (16 Oct 2026, Broc: "a date option for future or past dates for
 * the placement board", and asked what it should mean: "held from that date
 * on"). The board is then the board as it reads ON that date - who holds each
 * place from it to 31 December - so 1/03/2027 drops a child leaving in February
 * and keeps one starting in June, and a past date shows what the board would
 * have said that morning, read from the bookings as Kidsoft holds them now. The
 * caller has already checked it is a real date inside $year (board_parse_from()).
 */
function board_from(int $year, ?string $chosen = null): ?string {
    if ($chosen !== null) return $chosen;
    $today = date_create('today', new DateTimeZone('Australia/Sydney'))->format('Y-m-d');
    if ($today > sprintf('%d-12-31', $year)) return null;
    return max($today, sprintf('%d-01-01', $year));
}

/**
 * Read a centre-year's real occupancy straight from Kidsoft.
 *
 * @param array $opts 'refresh' => bypass the cache (the Refresh button)
 * @return array{rooms:array<int,array>, children:int, unmapped:int, month:int, stale:bool}
 *         rooms is [our room id => [ ['ks_id','first','last','dob','days'], ... ]]
 */
function board_live_children(int $centreId, int $year, array $opts = []): array {
    $centre = centre_get($centreId);
    if (!$centre) throw new InvalidArgumentException('unknown centre');
    $serviceId = $centre['kidsoft_service_id'];

    $roomByKs = [];
    foreach (rooms_for_centre($centreId, $year) as $r) {
        if ($r['kidsoft_room_id']) $roomByKs[$r['kidsoft_room_id']] = $r;
    }

    $month = (int)($opts['month'] ?? board_reference_month($year));
    $from  = sprintf('%d-%02d-01', $year, $month);
    $to    = date('Y-m-t', (int)strtotime($from));

    $empty = ['rooms' => [], 'children' => 0, 'unmapped' => 0, 'month' => $month, 'stale' => false];
    if (!$serviceId || !$roomByKs) return $empty;

    // A FUTURE YEAR IS BEING DECIDED RIGHT NOW, so it cannot wear the 36 h TTL
    // that suits a settled one. Measured 6 Aug 2026 during the re-enrolment
    // round: Tuggerah's Feb 2027 bookings read 88 children from the cache written
    // by the 02:00 warm and 143 live - 55 children re-enrolled during that one
    // day, so the cached copy understated next year's roll by 38%. The team is
    // planning against this number in the same week the centres are creating it.
    //
    // A short TTL is affordable only because of the stale-serve in
    // kidsoft_cache_run_deferred(): the unlucky request gets the stale row and
    // the refresh runs after fastcgi_finish_request(), so this costs background
    // refreshes, not user-visible waits. Do not shorten it further without
    // re-checking that property still holds.
    //
    // AND THE CURRENT YEAR NOW TAKES 15 MINUTES (13 Aug 2026, Broc: 36 h "seems
    // kind of long in relation to the purpose and goals of this project"). He is
    // right, and the 36 h was never a decision about the board - it was
    // inherited from the KIDSOFT_TTL entry this call SHARES with bookings_year(),
    // whose twelve monthly reads are the expensive ones the long TTL was written
    // for. THE BOARD READS ONE MONTH. Measured live 13 Aug 2026:
    //
    //   one centre's reference month   1.6-4.0s   (Tuggerah, the largest, 4.0s)
    //   all seven centres              14.0s      10,953 bookings
    //   one DAY, whole centre          0.44s
    //
    // Four seconds behind a 36-hour cache is not a cost trade-off, it is an
    // accident of two callers sharing one key. bookings_year() passes no
    // override and still gets 36 h, so coverage, unsold place-days and tenure
    // are untouched; the only thing that can refetch here is the ONE month the
    // board is showing. Paired with the hourly warm in cron-nightly.php --warm,
    // a page load should normally find this fresh rather than stale-then-refresh.
    //
    // NOT DONE WITH `UpdatedAfter`, which getBookings does accept (0.65s for the
    // same month) - an incremental read CANNOT SEE A CANCELLED BOOKING, because
    // a deleted row is simply absent from the response rather than returned as
    // changed. Merging that onto a cached month would leave a child sitting in a
    // room they have come out of, which is the exact failure this app exists to
    // prevent, and it would do it in the direction that hides a free place. At
    // 4s for an honest full read, buying that risk saves nothing worth having.
    // (Bookings/getBookingIDs for a month is 1.23s and WOULD detect the removals
    // if incremental ever became necessary. It is not necessary.)
    //
    // A PAST YEAR KEEPS 36 h: it is finished, nothing in it moves, and it is
    // what tenure reads five months of.
    $thisYear = (int)date_create('today', new DateTimeZone('Australia/Sydney'))->format('Y');
    $ttl = !empty($opts['refresh']) ? 0
         : ($year > $thisYear ? 3600 : ($year === $thisYear ? 900 : null));

    try {
        $res = kidsoft_cached('Bookings', 'getBookings', [
            'ServiceID' => $serviceId, 'DateFrom' => $from, 'DateTo' => $to,
        ], $ttl);
    } catch (Throwable $e) {
        // The board must still render when Kidsoft is unreachable. A cached copy
        // is served by kidsoft_cached() even when expired, so reaching here means
        // there is no copy at all - say so rather than showing an empty centre,
        // which reads as "nobody is enrolled".
        $empty['stale'] = true;
        return $empty;
    }

    // childId => ksRoomId => [weekday => true]
    $seen = []; $unmapped = 0;
    foreach (bookings_on_trading_days(kidsoft_rows($res, 'Bookings', $serviceId)) as $b) {
        if (empty($b['ChildID']) || empty($b['RoomID']) || empty($b['Date'])) continue;
        // A casual is a one-off day, not a standing place. Occupancy counts them
        // (the child is physically in the room) but the BOARD is the standing
        // weekly arrangement, so they are excluded here exactly as the old
        // importer excluded them.
        if (!empty($b['Casual'])) continue;
        if (!isset($roomByKs[$b['RoomID']])) { $unmapped++; continue; }
        $wd = (int)date('N', (int)strtotime(substr((string)$b['Date'], 0, 10)));
        if ($wd < 1 || $wd > 5) continue;
        $seen[$b['ChildID']][$b['RoomID']][$wd] = true;
    }

    $names = kidsoft_children($serviceId);
    $rooms = [];
    foreach ($seen as $childId => $inRooms) {
        $c     = $names[$childId] ?? null;
        $first = $c ? name_case((string)($c['FirstName'] ?? '')) : '';
        $last  = $c ? name_case((string)($c['LastName'] ?? '')) : '';
        // A booking we cannot name is not something to put on a board.
        if ($first === '' || $last === '') continue;
        $dob = ($c && !empty($c['DOB'])) ? substr((string)$c['DOB'], 0, 10) : null;

        foreach ($inRooms as $ksRoomId => $wds) {
            $days = array_keys($wds);
            sort($days);
            if (!$days) continue;
            $rooms[(int)$roomByKs[$ksRoomId]['id']][] = [
                'ks_id' => (string)$childId,
                'first' => $first, 'last' => $last, 'dob' => $dob, 'days' => $days,
            ];
        }
    }
    foreach ($rooms as &$list) {
        usort($list, static fn($a, $b) => [$a['last'], $a['first']] <=> [$b['last'], $b['first']]);
    }
    unset($list);

    return ['rooms' => $rooms, 'children' => count($seen),
            'unmapped' => $unmapped, 'month' => $month, 'stale' => false];
}

/**
 * The forward roster: who holds a place in each room, today to 31 December.
 *
 * This is what board_load() puts on the board. It replaced the reference month
 * on 21 Aug 2026 - see the long note at the head of board_load() for the two
 * faults it fixes and the numbers behind them.
 *
 * FALLS BACK TO THE REFERENCE MONTH when there is no future to read: a past
 * year, a centre with no Kidsoft service id, or every month read failing. A
 * LINKED room that the centre answered for and that holds no future bookings is
 * genuinely empty and gets an empty list - it must not fall back, or a room the
 * last child left in July would keep showing them until December.
 *
 * A booking nobody can name is left off, exactly as board_live_children() leaves
 * it off: a name is the only thing a board row is for.
 *
 * @param array $peak board_forward_occupancy() output
 * @param array $live board_live_children() output, the fallback
 * @return array our room id => [ {ks_id, first, last, dob, days, dates}, ... ]
 *         dates is weekday => {first,last}, the child's own span in this room.
 */
function board_forward_roster(int $centreId, int $year, array $peak, array $live): array {
    if (!$peak) return $live['rooms'] ?? [];
    $centre    = centre_get($centreId);
    $serviceId = $centre['kidsoft_service_id'] ?? null;
    if (!$serviceId) return $live['rooms'] ?? [];

    $names = kidsoft_children($serviceId);
    $out   = [];
    foreach ($peak as $rid => $byWd) {
        $byChild = [];
        foreach ($byWd as $wd => $cell) {
            foreach ($cell['children'] as $childId => $span) {
                $byChild[$childId]['days'][]          = (int)$wd;
                $byChild[$childId]['dates'][(int)$wd] = $span;
            }
        }
        foreach ($byChild as $childId => $c) {
            $k     = $names[$childId] ?? null;
            $first = $k ? name_case((string)($k['FirstName'] ?? '')) : '';
            $last  = $k ? name_case((string)($k['LastName'] ?? '')) : '';
            if ($first === '' || $last === '') continue;
            $days = $c['days'];
            sort($days);
            ksort($c['dates']);
            $out[(int)$rid][] = [
                'ks_id' => (string)$childId,
                'first' => $first,
                'last'  => $last,
                'dob'   => ($k && !empty($k['DOB'])) ? substr((string)$k['DOB'], 0, 10) : null,
                'days'  => $days,
                'dates' => $c['dates'],
            ];
        }
    }
    foreach ($out as &$list) {
        usort($list, static fn($a, $b) => [$a['last'], $a['first']] <=> [$b['last'], $b['first']]);
    }
    unset($list);
    return $out;
}

/**
 * A child's own start or finish date on a cell, but ONLY when it differs from
 * the CELL'S OWN span. Returns null for a child who holds the cell for as long
 * as anybody does, which is nearly all of them.
 *
 * THE BOUND IS THE ROSTER, NOT THE CALENDAR, and the calendar version had to be
 * thrown away after one look at the output: it bounded Wednesday by 30 December,
 * the last Wednesday of 2026, so every child in every room wore "to 23/12" - the
 * day the centres shut for Christmas. Two hundred true dates hid the four that
 * meant something. A date is only worth printing when it makes this child
 * different from the ones beside them.
 */
function board_span_edge(?array $child, ?array $bounds, string $which): ?string {
    if (!$child || !$bounds || empty($child[$which]) || empty($bounds[$which])) return null;
    if ($which === 'first') return $child['first'] > $bounds['first'] ? $child['first'] : null;
    return $child['last'] < $bounds['last'] ? $child['last'] : null;
}

/**
 * Children with a DECLARED departure date still ahead of them.
 *
 * `ExitDate` and `ExitReason` live on the CHILD record from `Children/getChildren`
 * - not inside the `Enrolments` block, and needing no `Options` flag. This file's
 * notes said for ten days that Kidsoft "cannot predict a single vacancy"; that was
 * measured on 257 children at ONE centre on ONE day and was wrong. Group-wide there
 * are 11 children carrying a future exit (13 Aug 2026). See kidsoft-api.md.
 *
 * WHY IT IS WORTH READING, given coverage_map() already infers departures from the
 * shape of a child's bookings. Measured over all 11: TEN have bookings that already
 * stop at or before their exit date, so the inference finds them and the declaration
 * adds only certainty and a reason. THE ELEVENTH IS THE POINT. Tuggerah's Brodie
 * Jackson exits 14 Aug and still holds 49 bookings running to 24 December, because
 * notice has been given and nobody has cleaned the bookings up yet. Inference reads
 * that child as holding a full-year place; the declaration says the place is free
 * from Friday. Three cells at Mighty Meteors Silver, hidden until December.
 *
 * The team gives notice four weeks out and every place is sold January to December,
 * so this is the ONLY forward signal that a place is coming back. It is worth one
 * cached call per centre.
 *
 * Enrolment rows carry no `RoomID` and do not need to: the child's own bookings say
 * which room and weekday they hold.
 *
 * @return array<string,array{on:string,reason:string}> ChildID => departure
 */
function board_departures(int $centreId, int $year, ?string $chosen = null): array {
    // Called from board_forward_occupancy() AND from board_load(), which must see
    // the same answer and should not pay for it twice.
    static $memo = [];

    $centre = centre_get($centreId);
    if (!$centre || !$centre['kidsoft_service_id']) return [];
    $serviceId = $centre['kidsoft_service_id'];

    // Ahead of the date the board counts from - see board_from().
    $from = board_from($year, $chosen);
    $to   = sprintf('%d-12-31', $year);
    if ($from === null) return [];     // a finished year has no departure ahead of it

    $key = $centreId . ':' . $year . ':' . $from;
    if (isset($memo[$key])) return $memo[$key];

    // A 36 h TTL WOULD HAVE HIDDEN THE ONLY CASE THIS FUNCTION EXISTS FOR, and it
    // did: written first with the shared default, this returned NOTHING for
    // Tuggerah while Kidsoft held Brodie Jackson's 14 Aug exit, because the cached
    // children list predated the notice being entered. The same read live had it.
    //
    // So the caller takes an override, exactly as board_live_children() does and
    // for the same reason - `Children/getChildren` is read by drift, occupancy,
    // day-requests and identity, none of which want a short TTL, and shortening
    // the shared entry would put all of them on a refetch cycle. See the TTL table
    // in kidsoft-cache.php, which says precisely this.
    //
    // 3 h, NOT the board's 15 minutes. Notice is given four weeks out, so a
    // departure entered this morning showing by lunchtime loses nothing real,
    // and the plain children list is 2.4s for the largest centre against the
    // board's own 4.0s - there is no case for paying that every quarter hour for
    // a fact that moves a handful of times a month. It matches the 3 h already
    // used here for "a guardian newly attached should surface within hours".
    $thisYear = (int)date_create('today', new DateTimeZone('Australia/Sydney'))->format('Y');
    try {
        $res = kidsoft_cached('Children', 'getChildren', ['ServiceID' => $serviceId],
                              $year >= $thisYear ? 10800 : null);
    } catch (Throwable $e) {
        // No departures rather than no board. This only ever ADDS a free place, so
        // failing to read it leaves the older, more conservative answer standing.
        return [];
    }

    $out = [];
    foreach (kidsoft_rows($res, 'Children', $serviceId) as $ch) {
        if (empty($ch['ChildID']) || empty($ch['ExitDate'])) continue;
        $on = substr((string)$ch['ExitDate'], 0, 10);
        if ($on < $from || $on > $to) continue;
        $out[(string)$ch['ChildID']] = [
            'on'     => $on,
            // "Gone to School" frees a place at year end; "Change of Care
            // Conditions" frees one now. The team reads those differently.
            'reason' => trim((string)($ch['ExitReason'] ?? '')),
        ];
    }
    return $memo[$key] = $out;
}

/**
 * The fullest each cell gets between today and the end of the year.
 *
 * THIS IS THE NUMBER THAT DECIDES WHETHER A PLACE CAN BE SOLD, and it replaces
 * two separate subtractions that were both approximations of it.
 *
 * The board reads ONE reference month, which answers "who holds a place" correctly
 * and "what may we sell" wrongly, in both directions:
 *
 *   TOO OPTIMISTIC - a child booked to start after that month is invisible, so
 *   their seat reads free and could be promised to a second family.
 *   TOO PESSIMISTIC - `occupied` is the UNION of the month, so a child who leaves
 *   on the 5th and the child who replaces them on the 20th are counted as two
 *   children in one place. Subtracting future starters on top of that made it
 *   worse: at Niagara Park every future starter is a replacement (Radiant Rainbows
 *   loses one on 3 Sep and gains one on 10 Sep, repeating on four weekdays), so
 *   both halves of one seat came off.
 *
 * A PEAK OVER SINGLE DATES HAS NEITHER PROBLEM AND NEEDS NO PAIRING HEURISTIC.
 * Ask what the room actually looks like on each remaining date and take the worst
 * one: the leaver and their replacement are never both present on any single date,
 * so they count once; a starter in December is counted on the December dates.
 * Nothing has to guess which arrival replaces which departure.
 *
 * A DECLARED DEPARTURE TRUNCATES THE CHILD. Bookings dated on or after a child's
 * `ExitDate` are ignored, which is what frees the place of somebody whose notice is
 * in but whose bookings have not been removed. See board_departures().
 *
 * Casual bookings are excluded, exactly as the board excludes them - a casual is a
 * one-off day, not a standing place. (Occupancy counts them elsewhere, on purpose.)
 *
 * Returns [] when the year has no future left, and the caller then falls back to
 * reference-month occupancy: "what can I sell" is not a question about a past year.
 *
 * COST IS NIL because these month reads use exactly the cache keys bookings_year()
 * already writes (DateFrom = 1st, DateTo = last day) and cron-nightly.php --warm
 * fills a year per centre nightly. DO NOT give them a shorter TTL to chase
 * freshness: the keys are shared, so it would force tenure, coverage and the year
 * board to refetch the same months.
 *
 * IT ALSO NAMES THEM, and that is why it returns a roster and not just a number
 * (21 Aug 2026). The reference month cannot see a child enrolled to start after
 * it, so 14 children group-wide - Halle and Olive Dyer holding five days each at
 * Kanwal from 28 September, among them - held a place the board showed in no
 * room at all. The team's rule is that an enrolled child's spot is taken from the
 * moment they are enrolled, whatever their start date, so the child has to be
 * ON the cell, not summarised by a badge beside it.
 *
 * `children` is the UNION from today forward and `peak` is the maximum over
 * single dates, so on a cell where a leaver and their replacement overlap for
 * part of the year the roster is longer than the count. That is not a
 * disagreement: 15 children can pass through 14 places. Each child carries the
 * dates that say which, so the cell can show its own arithmetic.
 *
 * @return array<int,array<int,array>> our room id => weekday =>
 *         {peak:int, children: [ChildID => {first:string, last:string}]}
 */
function board_forward_occupancy(int $centreId, int $year, ?string $chosen = null): array {
    $centre = centre_get($centreId);
    if (!$centre || !$centre['kidsoft_service_id']) return [];
    $serviceId = $centre['kidsoft_service_id'];

    // Today, 1 January, or the date somebody picked - see board_from().
    $from = board_from($year, $chosen);
    $to   = sprintf('%d-12-31', $year);
    if ($from === null) return [];

    $roomByKs = [];
    foreach (rooms_for_centre($centreId, $year) as $r) {
        if ($r['kidsoft_room_id']) $roomByKs[$r['kidsoft_room_id']] = (int)$r['id'];
    }
    if (!$roomByKs) return [];

    $exits = board_departures($centreId, $year, $chosen);

    // roomId => weekday => date => [childId => true]. Kept per date rather than
    // per month, because the whole point is the shape WITHIN a month.
    // And roomId => weekday => childId => {first,last}, which is the same rows
    // asked "who holds this cell" instead of "how many on this date".
    $byDate = []; $span = [];
    for ($m = (int)substr($from, 5, 2); $m <= 12; $m++) {
        $first = sprintf('%d-%02d-01', $year, $m);
        $last  = date_create($first)->modify('last day of this month')->format('Y-m-d');
        try {
            $res = kidsoft_cached('Bookings', 'getBookings', [
                'ServiceID' => $serviceId, 'DateFrom' => $first, 'DateTo' => $last,
            ]);
        } catch (Throwable $e) {
            // Missing a month understates the peak, which reads as MORE places
            // free. The board reports itself stale in this case, and refusing to
            // render is worse; but do not quietly widen this catch.
            continue;
        }
        // A booking on a day the centre is shut holds nothing, and left in it
        // stretched the cell's span past the real last day, so every child
        // booked to that day was printed as leaving early. See
        // bookings_on_trading_days().
        foreach (bookings_on_trading_days(kidsoft_rows($res, 'Bookings', $serviceId)) as $b) {
            if (empty($b['ChildID']) || empty($b['RoomID']) || empty($b['Date'])) continue;
            if (!empty($b['Casual'])) continue;
            if (!isset($roomByKs[$b['RoomID']])) continue;
            $date = substr((string)$b['Date'], 0, 10);
            if ($date < $from || $date > $to) continue;
            // Notice is in: this child does not hold the place from that date on.
            if (isset($exits[$b['ChildID']]) && $date >= $exits[$b['ChildID']]['on']) continue;
            $wd = (int)date('N', (int)strtotime($date));
            if ($wd < 1 || $wd > 5) continue;
            $rid = $roomByKs[$b['RoomID']];
            $byDate[$rid][$wd][$date][$b['ChildID']] = true;
            // The same rows, collapsed the other way: who holds this cell at all,
            // and between which dates. Kept alongside the per-date buckets rather
            // than derived from them afterwards, because the peak needs every
            // date and the roster needs only the two ends.
            $sp = &$span[$rid][$wd][$b['ChildID']];
            $sp = ['first' => min($sp['first'] ?? '9999-99-99', $date),
                   'last'  => max($sp['last']  ?? '0000-00-00', $date)];
            unset($sp);
        }
    }

    $out = [];
    foreach ($byDate as $rid => $byWd) {
        foreach ($byWd as $wd => $dates) {
            $peak = 0;
            foreach ($dates as $children) {
                $n = count($children);
                if ($n > $peak) $peak = $n;
            }
            $out[$rid][$wd] = ['peak' => $peak, 'children' => $span[$rid][$wd] ?? []];
        }
    }
    return $out;
}
/**
 * The things Kidsoft has no field for, keyed so a derived child can pick them up.
 *
 * This is all `placements` is for now. Nothing here can drift, because none of it
 * is a copy of anything Kidsoft holds - it is the team's own record: the
 * workbook's cell-colour status, the five child-level flags, and a note.
 *
 * @return array{by_child:array<string,array>, provisional:array<int,array>}
 *         by_child is "ksChildId|roomId" => annotation
 *         provisional is [room id => [rows]] for children Kidsoft has never heard
 *         of - pencilled in ahead of Kidsoft, or offered a place by this app,
 *         which cannot write back.
 */
function board_annotations(int $centreId, int $year): array {
    $rows = dbx()->query(
        "SELECT p.*, GROUP_CONCAT(pd.weekday ORDER BY pd.weekday) AS days
           FROM placements p
           LEFT JOIN placement_days pd ON pd.placement_id = p.id
          WHERE p.centre_id = ? AND p.year = ?
          GROUP BY p.id",
        [$centreId, $year], 'ii'
    )->fetchAll();

    $byChild = []; $provisional = [];
    foreach ($rows as $p) {
        $ann = [
            'id'        => (int)$p['id'],
            'status'    => $p['status'],
            'notes'     => $p['notes'],
            'flags'     => array_values(array_filter([
                $p['is_sibling']           ? 'sibling'       : null,
                $p['is_staff_child']       ? 'staff'         : null,
                $p['needs_two_educators']  ? 'two_educators' : null,
                $p['has_behaviour_plan']   ? 'behaviour'     : null,
                $p['has_individual_plan']  ? 'individual'    : null,
            ])),
        ];
        if ($p['kidsoft_child_id']) {
            $byChild[$p['kidsoft_child_id'] . '|' . (int)$p['room_id']] = $ann;
            continue;
        }
        // No Kidsoft id: a child this app knows about and Kidsoft does not yet.
        $provisional[(int)$p['room_id']][] = $ann + [
            'ks_id' => null,
            'first' => (string)$p['child_first_name'],
            'last'  => (string)$p['child_last_name'],
            'dob'   => $p['child_dob'] ? substr((string)$p['child_dob'], 0, 10) : null,
            'days'  => $p['days'] === null ? [] : array_map('intval', explode(',', (string)$p['days'])),
        ];
    }
    return ['by_child' => $byChild, 'provisional' => $provisional];
}

/**
 * Is this centre actually trading in this year?  true / false / null = cannot say.
 *
 * Lives here rather than in dashboard.php because BOTH group-wide screens need
 * the same answer, and two implementations of "is this centre real" would drift.
 *
 * A centre with capacity and no children is not a centre with a filling problem;
 * it is closed, or not open yet. Warnervale has 4 linked rooms and 385 recorded
 * place-days a week, zero bookings and zero children - 77% of the group's raw
 * "places free" and, ranked emptiest-first, the top row of a screen whose job is
 * pointing at work. Derived from the data every run and NEVER keyed to a centre
 * name, so a centre that starts trading is picked up with no code change.
 *
 * "Not trading" and "Kidsoft would not answer" are DIFFERENT answers: the second
 * returns null, is excluded from totals just the same, but is reported as a gap
 * in the data rather than as a fact about the business.
 */
function board_trading(array $board): ?bool {
    if (!empty($board['source']['unreachable'])) return null;
    $children = 0;
    foreach ($board['rooms'] as $r) $children += count($r['placements']);
    // A PLACE HELD FOR A CHILD THE CENTRE ALREADY HAS IS A CHILD (27 Oct 2026).
    // Erina opened 6 Oct 2026 and had no 2027 bookings in Kidsoft yet, so the
    // test above found nobody and its 2027 read `empty` - out of the totals on
    // the Placement overview, the Dashboard and Marketing - while 105 of its
    // place-days were held for its own children awaiting re-enrolment. `held`
    // (re-enrolment) and `reserved` (enrolled, not booked on the day) are both
    // families it already has. `promised` is NOT: an offer goes to a family in
    // the queue, and a centre that has never opened can have those.
    foreach ($board['totals'] as $t) $children += $t['held'] + $t['reserved'];
    return $children > 0;
}

/**
 * WHAT A CENTRE IS DOING IN A YEAR - one answer, five states, for every screen
 * that has to decide whether to count it.
 *
 * `board_trading()` answers "are there children in it", which was the whole test
 * until 21 Sep 2026 and is not enough on its own: a centre that has not opened
 * yet and a centre that is open and empty look identical from the bookings, and
 * they are opposite facts. Warnervale carries 385 place-days a week of capacity,
 * 17 waiting families and zero bookings in BOTH 2026 and 2027, so the year it
 * opens was being excluded from the Dashboard totals, from Places to fill and
 * from the Marketing ranking - the centre with the most to sell, missing from
 * the screen that says where to sell.
 *
 * `centres.opens_on` is what separates them, and it is ours by measurement, not
 * by omission: `Service/getServices` has no date field at all, and
 * `Service/getOpeningHours` - which does give a per-date Open/Closed status for
 * the service and every room - says Warnervale has been open since 15 June 2026,
 * the day its record was set up. See _incl/docs/rooms-centres.md.
 *
 *   trading      children are booked. Counted, whatever any date says.
 *   opening      opens later this year and nobody is booked yet. COUNTED, from
 *                the opening date (Broc, 21 Sep 2026): those places are real and
 *                unsold, and hiding them is how a new centre opens with an empty
 *                roll.
 *   unopened     does not open at all in this year. Excluded, and NAMED with its
 *                date rather than reported as "no children booked".
 *   empty        should be running and has nobody in it. NOT a quiet state - it
 *                is the alarm that "not trading" used to swallow.
 *   unreachable  Kidsoft would not answer. Excluded, reported as a gap in the
 *                data rather than as a fact about the business.
 *
 * EVIDENCE BEATS THE DATE, always. A booked child makes a centre `trading` even
 * if the date says it has not opened, so a typo can never hide real children --
 * the same rule every stored figure in this app follows against the live read.
 *
 * AND THE DATE EXPIRES ITSELF. `opening` requires the opening day to still be
 * ahead; once it has passed with nobody booked the centre reads `empty`, so the
 * state cannot age into "opening soon" about a date in the past.
 */
function centre_year_state(array $board): string {
    $trading = board_trading($board);
    if ($trading === null) return 'unreachable';
    if ($trading === true) return 'trading';

    // ONE TEST, shared with centre_not_open_yet() below - a second copy of "is
    // this year before the centre opens" is the drift this app keeps having to
    // hunt down.
    if (centre_not_open_yet($board['centre'], (int)$board['year'])) return 'unopened';

    $opens = $board['centre']['opens_on'] ?? null;
    if ($opens !== null && $opens !== '') {
        $today = date_create('today', new DateTimeZone('Australia/Sydney'))->format('Y-m-d');
        if (substr((string)$opens, 0, 10) > $today) return 'opening';
    }
    return 'empty';
}

/**
 * IS THIS CENTRE-YEAR ENTIRELY BEFORE THE CENTRE OPENS? Everything a screen
 * needs to say so, or null.
 *
 * IT TAKES A CENTRE ROW AND A YEAR, AND NOTHING ELSE - no board, no Kidsoft, no
 * cache. That is the whole point of it: a caller can answer the question BEFORE
 * paying to derive a year that cannot exist. Measured 21 Sep 2026, Warnervale
 * 2026: the placement board spent 7.2s building a grid of 385 free places and a
 * headline reading "20,097 un-occupied positions across 2026", for a centre that
 * has never opened its doors. Broc: *"it is pointless showing and calculating a
 * placement board for 2026 for warnervale."*
 *
 * WHAT IT DOES NOT COVER, deliberately. This answers a question about ROOMS in a
 * YEAR. The queue, the offers, the work items and the contact log are not
 * year-scoped and are at their busiest before a centre opens - Warnervale has 16
 * families waiting today - so no screen built on those consults this. See
 * _incl/docs/rooms-centres.md.
 *
 * AS AT A DATE, ON THE PLACEMENT SCREENS (Broc, 27 Oct 2026). A placement
 * board counts from its From date (board_from()), and whether the centre is
 * open is read AT THAT DATE: Warnervale opens 1 Oct 2027, so 2027 from 1
 * January has no Warnervale board and 2027 from 1 October does. Those callers
 * pass `$from`; every other screen leaves it null and asks about the year.
 * `from` in the payload says the date decided it, not the year.
 *
 * @return ?array{centre_id:int, centre:string, opens_on:string, opens:string,
 *                opens_year:int, year:int, from:?string, from_disp:?string}
 */
function centre_not_open_yet(?array $centre, int $year, ?string $from = null): ?array {
    $opens = $centre['opens_on'] ?? null;
    if ($opens === null || $opens === '') return null;
    $opens = substr((string)$opens, 0, 10);
    // The year (or the date) contains the opening day, or is after it: there is
    // something real to show, even if nobody is booked into it yet.
    if ($opens <= ($from ?? $year . '-12-31')) return null;
    $byDate = $from !== null && $opens <= $year . '-12-31';

    return [
        'centre_id'  => (int)($centre['id'] ?? 0),
        'centre'     => (string)($centre['name'] ?? ''),
        'opens_on'   => $opens,
        // Formatted HERE, like every other date this app publishes: these are
        // Sydney wall-clock strings and new Date() in the browser re-reads them
        // in the viewer's own timezone. See _incl/dates.php.
        'opens'      => syd_date($opens),
        'opens_year' => (int)substr($opens, 0, 4),
        'year'       => $year,
        'from'       => $byDate ? $from : null,
        'from_disp'  => $byDate ? syd_date($from) : null,
    ];
}

/** Does this state contribute to a group total? */
function centre_state_counts(string $state): bool {
    return $state === 'trading' || $state === 'opening';
}

/**
 * The first day of $year this centre can sell, as yyyy-mm-dd.
 *
 * A centre opening in November has two months of the year to sell, not twelve,
 * and anything counting place-days ACROSS the year has to start from the door
 * opening rather than from 1 January. Null means the whole year is available.
 */
function centre_year_start(array $centre, int $year): ?string {
    $opens = $centre['opens_on'] ?? null;
    if ($opens === null || $opens === '') return null;
    $opens = substr((string)$opens, 0, 10);
    return ($opens > $year . '-01-01' && $opens <= $year . '-12-31') ? $opens : null;
}
