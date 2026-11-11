<?php
// TENURE - how long children stay, and every movement that cuts it short.
//
// The screen is called Tenure. What it COUNTS is churn: arrivals and departures
// that the ideal year would not contain. Both words are used below and they are
// not interchangeable - tenure is the outcome the organisation wants (a child
// enrolled once, staying until they age out), churn is the movement that
// prevents it, and it is churn that is measurable per room per year.
//
// WHAT THE TEAM IS TRYING TO ACHIEVE. In the ideal year a child enters at the
// BOTTOM of the ladder (the babies room), progresses up one room a year as they
// age, and leaves exactly once, at the TOP, for school. Nothing else moves. Every
// other movement - a family leaving in June, a stranger joining the 3-4s in
// August to backfill the place they left - is churn, and each one costs the team
// a phone call, an offer, an enrolment pack and a settling-in period for work
// that, in the ideal, never needed doing.
//
// SO CHURN IS MEASURED AS GROSS FLOW, NOT NET CHANGE, and that is the whole point
// of this file. Tuggerah lost 27 children and gained 32 during 2025. Reported as a
// net "+5" that is a good year; reported honestly it is 59 movements against an
// average roll of 283, and 59 is the number that describes the work. A net figure
// hides exactly the thing the organisation wants to minimise.
//
// Measured group-wide while building this (Feb -> Nov, three years pooled):
//
//   mid-year churn      8.3% (2023), 9.6% (2024), 9.6% (2025)  - stable
//   gross turnover      34.2% of the average roll in 2025
//   by child age        under 1: 5.9%  ...  4 to 5: 11.7%   - babies are stickiest
//   by days attended    1 day/wk: 30.9%   4 day/wk: 4.8%    - and it survives a
//                       settling-in control (established 1-day children: 26.3%)
//   tenure              a child who ages out stays ~36 months; one who churns ~15,
//                       and 27.7% of churn departures happen in the first 6 months
//
//   AND THE ONE THAT STATES THE GAP FROM THE IDEAL: of 336 mid-year arrivals over
//   three years, only 30 were babies. 91% entered ABOVE the bottom room - i.e.
//   they were backfilling a place churn had opened, not starting at the beginning.
//
// WHAT IS DELIBERATELY NOT HERE:
//
// * No financial figures. Revenue is the organisation's goal but not this
//   project's scope, and the app already prices unsold place-days in
//   occupancy.php. Churn EXPLAINS that gap; it must not grow a second, rival
//   money model that disagrees with the first.
// * No reason taxonomy. Kidsoft's ExitReason is used for exactly ONE binary --
//   did this child age out to school - because that is the only distinction that
//   changes whether a departure counts. Measured on 102 real leavers, it never
//   labels a young child a school leaver, and it disagrees with an age-at-31-July
//   test on 3 of 102. Beyond that binary it is noise: "Change of Care Conditions"
//   is 1,485 rows group-wide, is the value people pick when they pick nothing,
//   and is what all ten known centre TRANSFERS are filed under.
// * No month-by-month grid. Departures are evenly spread - group 2025 ran 4.1%
//   by May, 3.0% by August, 2.5% by November - so a room x month heat map would
//   be flat noise. Retention is a continuous job, not a season to prepare for.

require_once __DIR__ . '/kidsoft-cache.php';
require_once __DIR__ . '/rooms.php';
require_once __DIR__ . '/board.php';
require_once __DIR__ . '/drift.php';       // name_fold()
require_once __DIR__ . '/identity.php';    // name_case()
require_once __DIR__ . '/search.php';      // search_history_params() - the archived waitlist, one cache entry
require_once __DIR__ . '/waitlist-import.php';   // waitlist_requested_weekdays()

/**
 * When in the year we take a reading.
 *
 * QUARTERLY, NOT MONTHLY, and not a full year stream. A month of bookings is one
 * API call of a few seconds; a year is twelve and ~91s at August 2026 API speed.
 * Four readings answer every question this file asks - who was here, who went,
 * who arrived - because we are counting movements, not billing days. It also
 * lands on the SAME cache keys bookings_year() already uses (DateFrom = 1st,
 * DateTo = last day of month), so the nightly bookings warm covers the current
 * year for free.
 *
 * February rather than January: January is half shutdown and the roll-over is
 * still settling, so a January reading counts families as absent who are simply
 * on holiday.
 *
 * THE ONE THING FOUR READINGS CANNOT SEE is a child whose whole stay falls
 * between two of them, so the months in between are read too - for that and
 * nothing else (tenure_apply_short_stays()). Both are the same cache keys, so
 * the current year is still free and the nightly job warms last year's.
 */
const TENURE_SAMPLES = ['Feb' => 2, 'May' => 5, 'Aug' => 8, 'Nov' => 11];

/** A centre this far below its own peak, and still climbing, is filling up rather than churning. */
const TENURE_STEADY_RATIO = 0.6;

/**
 * Every child Kidsoft knows, keyed by a person id that SURVIVES A CENTRE MOVE.
 *
 * KIDSOFT'S ChildID IS PER SERVICE, which is the single most important fact in
 * this file. Measured across the group: not one of 4,805 ChildIDs appears at more
 * than one centre, and 149 children hold a separate record at two centres under
 * two different ids. So a child transferring from Tuggerah to Kanwal looks, to
 * anything keyed on ChildID, like one child vanishing and a different child
 * appearing - which reports a transfer as churn at one end and a new family at
 * the other, and both are wrong.
 *
 * The key is therefore CRN first: a Centrelink customer reference number, which
 * is issued to the child rather than to the enrolment and so is stable across
 * services by definition. Populated on 98.3% of active children (845 of 860).
 *
 * Name + DOB is the fallback for the remaining ~2%, and it is a good one: checked
 * against CRN across the whole group it produced 149 cross-centre matches to
 * CRN's 145, with ZERO conflicts in either direction - no pair CRN unified that
 * the name key split, and no pair the name key merged that CRN says are different
 * children. Nine pairs rest on the fallback alone.
 *
 * THE CRN IS A JOIN KEY AND NOTHING ELSE. It is a government identifier for a
 * child, it is never stored in our tables, never rendered on a screen and never
 * written to the audit log - the moment it lands in a column it lands in a
 * backup. Everything downstream of here uses the opaque key, not the number.
 *
 * @return array{0: array<string,string>, 1: array<string,array>} [ChildID => key, ChildID => record]
 */
function tenure_people(bool $refresh = false): array {
    static $cache = null;
    if ($cache !== null && !$refresh) return $cache;

    $pk = [];
    $rec = [];
    foreach (centres_all() as $c) {
        if (empty($c['kidsoft_service_id'])) continue;
        try {
            $rows = kidsoft_rows(kidsoft_cached('Children', 'getChildren', [
                'ServiceID' => $c['kidsoft_service_id'],
            ], $refresh ? 0 : null), 'Children', $c['kidsoft_service_id']);
        } catch (RuntimeException $e) {
            continue;   // a centre we cannot read is reported by the caller, not fatal here
        }
        foreach ($rows as $r) {
            $id = $r['ChildID'] ?? null;
            if (!$id) continue;
            $crn = trim((string)($r['CRN'] ?? ''));
            $dob = !empty($r['DOB']) ? substr((string)$r['DOB'], 0, 10) : '';
            if ($crn !== '') {
                $key = 'crn:' . $crn;
            } elseif ($dob !== '') {
                $key = 'nm:' . name_fold((string)($r['FirstName'] ?? ''))
                     . '|' . name_fold((string)($r['LastName'] ?? '')) . '|' . $dob;
            } else {
                continue;   // nothing stable to key on; better absent than wrongly merged
            }
            $pk[$id] = $key;
            $rec[$id] = [
                'key'   => $key,
                'dob'   => $dob ?: null,
                // The ONE thing ExitReason is used for. Substring, case-insensitive,
                // because the value is free text Kidsoft staff pick from a list that
                // has grown spelling variants ("Change of mind" / "Changed mind").
                'aged'  => stripos((string)($r['ExitReason'] ?? ''), 'school') !== false,
                // Shown verbatim against a child who started and left between
                // two readings, and nowhere else. Still not a breakdown - see
                // the header.
                'reason'=> trim((string)($r['ExitReason'] ?? '')) ?: null,
                'name'  => name_case(trim((string)($r['FirstName'] ?? '') . ' ' . (string)($r['LastName'] ?? ''))),
                'centre'=> (int)$c['id'],
            ];
        }
    }
    return $cache = [$pk, $rec];
}

/**
 * Who was booked at this centre in this month, by person.
 *
 * CASUAL BOOKINGS ARE EXCLUDED, like everywhere else (Broc, 13 Aug 2026: this
 * tool is about long-term permanent enrolments only). This used to include them,
 * arguing "a casual day still means the child was in the building, and a child
 * attending casually has not left". That answers a different question from the
 * one this file asks. Tenure measures whether a PLACE is still held: a family who
 * gave up their permanent days and now books the odd casual day has left as far
 * as every other screen is concerned, and counting them as retained hid exactly
 * the departure the enrolments team needs to see.
 *
 * $detail, when an array is passed, also collects each person's ChildID, first
 * and last booked date, and booked / attended day counts - what a short stay
 * needs (see tenure_apply_short_stays()) and a reading does not. Same rows, same
 * filters, so the two cannot disagree about who was here.
 *
 * @return array<string, array<string, array<int,bool>>> personKey => ksRoomId => weekday => true
 */
function tenure_snapshot(string $serviceId, int $year, int $month, array $pk, bool $refresh = false,
                         ?array &$detail = null): array {
    $first = sprintf('%d-%02d-01', $year, $month);
    $last  = date_create($first)->modify('last day of this month')->format('Y-m-d');

    $rows = kidsoft_rows(kidsoft_cached('Bookings', 'getBookings', [
        'ServiceID' => $serviceId,
        'DateFrom'  => $first,
        'DateTo'    => $last,
    ], $refresh ? 0 : null), 'Bookings', $serviceId);

    $out = [];
    foreach ($rows as $b) {
        if (empty($b['ChildID']) || empty($b['RoomID']) || empty($b['Date'])) continue;
        if (!empty($b['Casual'])) continue;
        $wd = (int)date('N', strtotime(substr((string)$b['Date'], 0, 10)));
        if ($wd > 5) continue;
        $key = $pk[$b['ChildID']] ?? null;
        if ($key === null) continue;
        $out[$key][(string)$b['RoomID']][$wd] = true;

        if ($detail !== null) {
            // Keyed by DATE, not counted per row: a split session is two rows
            // and one day. A day counts as attended once it has happened and
            // any session on it was not marked absent.
            $d = substr((string)$b['Date'], 0, 10);
            $detail[$key]['child'] ??= (string)$b['ChildID'];
            $detail[$key]['days'][$d] = !empty($detail[$key]['days'][$d])
                || (empty($b['Absence']) && $d <= date('Y-m-d'));
        }
    }
    return $out;
}

/**
 * Compare two readings and post every movement between them onto the rooms.
 *
 * ONE CODE PATH FOR TWO QUESTIONS, deliberately. Movement inside a year and
 * movement across the New Year boundary are the same arithmetic, but they are
 * completely different events: measured at Tuggerah in 2025, mid-year progression
 * and mid-year school leaving are BOTH ZERO - children change room in January and
 * leave for school in December. So a within-year view alone shows churn but no
 * ladder at all, which is the half the team is actually asked about. Running the
 * same comparison over (November of last year -> February of this one) is what
 * produces the progression, and writing it twice is how the two would drift.
 *
 * $prefix is '' for the in-year tallies and 'b_' for the boundary ones.
 */
function tenure_apply_transition(array &$rooms, array $prev, array $cur,
                                array $elsewhereNow, array $byKey, string $prefix = ''): void {
    // Arrivals: here now, not here before.
    foreach ($cur as $key => $byRoom) {
        $rid = tenure_primary_room($byRoom);
        if ($rid === null || !isset($rooms[$rid])) continue;
        if (!isset($prev[$key])) {
            $rooms[$rid][$prefix . 'in_new']++;
        } else {
            $was = tenure_primary_room($prev[$key]);
            if ($was !== null && $was !== $rid) {
                $rooms[$rid][$prefix . 'in_up']++;
                if (isset($rooms[$was])) $rooms[$was][$prefix . 'out_up']++;
            } elseif ($was !== null) {
                $rooms[$rid][$prefix . 'stay']++;
            }
        }
    }
    // Departures: here before, not here now.
    foreach ($prev as $key => $byRoom) {
        if (isset($cur[$key])) continue;
        $rid = tenure_primary_room($byRoom);
        if ($rid === null || !isset($rooms[$rid])) continue;
        tenure_post_departure($rooms[$rid], $key, $elsewhereNow, $byKey, $prefix);
    }
}

/**
 * Post one departure onto its room, and say which kind it was.
 *
 * Shared by the reading-to-reading comparison and by short stays, so a child who
 * left in March is classified exactly like one who left in June.
 *
 * @return string 'out_moved_centre' | 'out_aged' | 'out_left'
 */
function tenure_post_departure(array &$room, string $key, array $elsewhereNow, array $byKey,
                               string $prefix, ?string $name = null): string {
    if (isset($elsewhereNow[$key])) {
        $kind = 'out_moved_centre';
    } else {
        // Did Kidsoft record them as gone to school? This is the ONLY thing
        // ExitReason decides, and the only distinction that changes whether a
        // departure counts against the centre.
        $who  = $byKey[$key] ?? null;
        $kind = $who && $who['aged'] ? 'out_aged' : 'out_left';
        if ($kind === 'out_left' && count($room['left_names']) < 12) {
            $room['left_names'][] = $name ?? $who['name'] ?? 'Unknown';
        }
    }
    $room[$prefix . $kind]++;
    return $kind;
}

/**
 * Children who STARTED AND LEFT BETWEEN TWO READINGS - in no reading at all.
 *
 * A reading is a whole month of bookings, so the readings miss only a child whose
 * every booking falls in the months between two of them: March-April, June-July,
 * September-October, or December-January over the changeover. Before 29 Oct 2026
 * they were not counted anywhere, which understated churn by exactly the families
 * the team most wants to understand (Broc). Measured that day: 2 children in 2025
 * and 8 in 2026 to August, 7 of the 10 over December-January - and 5 of the 10
 * had not been given every day they asked for.
 *
 * Each one is an arrival AND a departure, posted the way any other is, so
 * turnover, the tiles and the group ranking take them in with no special case.
 * $list gets one row per child for the card that shows WHY.
 *
 * $between is the window's monthly snapshots merged; $prev / $cur are the
 * readings either side; $elsewhereNext is the group at the closing reading.
 */
function tenure_apply_short_stays(array &$rooms, array $between, array $detail, array $prev,
                                  array $cur, array $elsewhereNext, array $byKey, array $rec,
                                  string $prefix, array &$list): void {
    foreach ($between as $key => $byRoom) {
        if (isset($prev[$key]) || isset($cur[$key])) continue;
        $rid = tenure_primary_room($byRoom);
        if ($rid === null || !isset($rooms[$rid])) continue;

        // The record AT THIS CENTRE, for the name and the reason - the person's
        // other records (a transfer) say why they left somewhere else.
        $d     = $detail[$key] ?? [];
        $here  = $rec[$d['child'] ?? ''] ?? ($byKey[$key] ?? []);
        $rooms[$rid][$prefix . 'in_new']++;
        $kind  = tenure_post_departure($rooms[$rid], $key, $elsewhereNext, $byKey, $prefix,
                                       $here['name'] ?? null);

        $dates = array_keys($d['days'] ?? []);
        sort($dates);
        $wds = array_keys(array_replace(...array_values($byRoom)));
        sort($wds);
        $list[] = [
            'child_id'  => $d['child'] ?? null,     // for the waitlist match; leaves the server as ks_id
            'name'      => $here['name'] ?? 'Unknown',
            'room'      => $rooms[$rid]['name'],
            'days'      => array_map('intval', $wds),
            'first'     => $dates[0] ?? null,
            'last'      => $dates ? end($dates) : null,
            'booked'    => count($dates),
            'came'      => count(array_filter($d['days'] ?? [])),
            'went'      => ['out_moved_centre' => 'moved', 'out_aged' => 'school', 'out_left' => 'left'][$kind],
            'moved_to'  => $elsewhereNext[$key] ?? null,
            'reason'    => $here['reason'] ?? null,
        ];
    }
}

/** The room a child mostly sits in that month - most weekdays wins. */
function tenure_primary_room(array $rooms): ?string {
    $best = 0; $pick = null;
    foreach ($rooms as $rid => $wds) {
        if (count($wds) > $best) { $best = count($wds); $pick = (string)$rid; }
    }
    return $pick;
}

/** How many distinct weekdays a child attends that month, across all their rooms. */
function tenure_days(array $rooms): int {
    $d = [];
    foreach ($rooms as $wds) foreach ($wds as $wd => $_) $d[$wd] = true;
    return count($d);
}

/**
 * Is this centre in a steady state, i.e. is a churn figure meaningful for it?
 *
 * THIS GUARD EXISTS BECAUSE TWO OF SEVEN CENTRES BREAK EVERY GROUP AGGREGATE.
 * Warnervale is not trading at all - 77 recorded places a day and zero bookings
 * - and Kanwal opened mid-2025 and is still filling: 55 children in Aug 2025,
 * then 82, 97, 108, 111. Kanwal's 2025 "turnover" computes to 234.7%, which is
 * not a centre in trouble, it is a centre being born. Ranking centres by churn
 * without this test puts the group's newest investment at the top of the problem
 * list and buries the centre that genuinely is churning.
 *
 * @return array{steady: bool, reason: ?string}
 */
function tenure_steady(array $counts): array {
    $vals = array_values($counts);
    if (!$vals || max($vals) === 0)       return ['steady' => false, 'reason' => 'not trading'];
    if (min($vals) === 0)                 return ['steady' => false, 'reason' => 'opened or closed part-way through the year'];
    $minIdx = array_search(min($vals), $vals, true);
    $maxIdx = array_search(max($vals), $vals, true);
    if (min($vals) < TENURE_STEADY_RATIO * max($vals) && $minIdx < $maxIdx) {
        return ['steady' => false, 'reason' => 'still filling up'];
    }
    return ['steady' => true, 'reason' => null];
}

/**
 * The churn ladder for one centre for one year.
 *
 * Rooms are returned BOTTOM FIRST (youngest age band first), because the shape
 * the team is looking for is a single column of arrows going up: children in at
 * the bottom, out at the top, nothing sideways.
 */
function tenure_year(int $centreId, int $year, array $opts = []): array {
    $refresh = !empty($opts['refresh']);
    $centre  = centre_get($centreId);
    if (!$centre || empty($centre['kidsoft_service_id'])) {
        return tenure_empty($centreId, $year, 'This centre is not linked to Kidsoft.');
    }
    [$pk, $rec] = tenure_people($refresh);

    // personKey => the one record that carries the exit, if any. A transferring
    // child holds a record at each centre, so prefer whichever one Kidsoft marked
    // as having aged out; otherwise any of them will do for the name.
    $byKey = [];
    foreach ($rec as $r) {
        $k = $r['key'];
        if (!isset($byKey[$k]) || (!$byKey[$k]['aged'] && $r['aged'])) $byKey[$k] = $r;
    }

    // Only sample months that have actually happened - a reading of next
    // November would report the whole centre as having left.
    $today   = date('Y-m-d');
    $samples = [];
    foreach (TENURE_SAMPLES as $label => $month) {
        if (sprintf('%d-%02d-01', $year, $month) <= $today) $samples[$label] = $month;
    }
    if (count($samples) < 2) {
        return tenure_empty($centreId, $year, 'Too early in the year to measure movement yet.');
    }

    $snap = [];
    foreach ($samples as $label => $month) {
        $snap[$label] = tenure_snapshot($centre['kidsoft_service_id'], $year, $month, $pk, $refresh);
    }

    // Where else in the GROUP a person appears at each reading. A child who
    // leaves this centre and turns up at another has not churned - the group
    // still has the family - and nine of ten known Tuggerah "departures" in
    // 2026 were exactly this, following the new Kanwal centre opening.
    $elsewhere = [];
    foreach (centres_all() as $c) {
        if ((int)$c['id'] === $centreId || empty($c['kidsoft_service_id'])) continue;
        foreach ($samples as $label => $month) {
            try {
                foreach (tenure_snapshot($c['kidsoft_service_id'], $year, $month, $pk, false) as $key => $_) {
                    $elsewhere[$label][$key] = (int)$c['id'];
                }
            } catch (RuntimeException $e) {
                // One unreachable centre must not cost this one its whole screen;
                // it only makes a transfer look like a departure, which the
                // caller is told about via 'degraded'.
                $degraded[] = $c['name'];
            }
        }
    }

    // Our rooms, bottom of the ladder first.
    $rooms = [];
    foreach (rooms_for_centre($centreId, $year) as $r) {
        if (empty($r['kidsoft_room_id'])) continue;
        $rooms[(string)$r['kidsoft_room_id']] = [
            'id'       => (int)$r['id'],
            'name'     => $r['name'],
            'min_age'  => $r['min_age_months'] !== null ? (int)$r['min_age_months'] : null,
            'max_age'  => $r['max_age_months'] !== null ? (int)$r['max_age_months'] : null,
            'headcount'=> [],
            'in_new' => 0, 'in_up' => 0, 'stay' => 0,
            'out_left' => 0, 'out_aged' => 0, 'out_moved_centre' => 0, 'out_up' => 0,
            'b_in_new' => 0, 'b_in_up' => 0, 'b_stay' => 0,
            'b_out_left' => 0, 'b_out_aged' => 0, 'b_out_moved_centre' => 0, 'b_out_up' => 0,
            'left_names' => [],
        ];
    }
    uasort($rooms, static function ($a, $b) {
        // Unknown age band sinks to the bottom of the list rather than pretending
        // to be the babies room.
        $x = $a['min_age'] ?? 9999; $y = $b['min_age'] ?? 9999;
        return $x <=> $y ?: strcmp($a['name'], $b['name']);
    });

    $labels = array_keys($samples);
    $counts = [];
    $short  = [];

    foreach ($labels as $i => $label) {
        $cur = $snap[$label];
        $counts[$label] = count($cur);

        foreach ($cur as $key => $byRoom) {
            $rid = tenure_primary_room($byRoom);
            if ($rid !== null && isset($rooms[$rid])) {
                $rooms[$rid]['headcount'][$label] = ($rooms[$rid]['headcount'][$label] ?? 0) + 1;
            }
        }
        if ($i === 0) continue;
        $prevLabel = $labels[$i - 1];
        tenure_apply_transition($rooms, $snap[$prevLabel], $cur,
                               $elsewhere[$label] ?? [], $byKey, '');

        // The months between these two readings. Only ever a window that has
        // CLOSED: the reading after it has happened, so everybody in it either
        // shows up there or has gone. The months after the last reading of a
        // year still running are left for the next one.
        $months = [];
        for ($m = TENURE_SAMPLES[$prevLabel] + 1; $m < TENURE_SAMPLES[$label]; $m++) $months[] = [$year, $m];
        $detail = [];
        $between = tenure_window($centre['kidsoft_service_id'], $months, $pk, $refresh, $detail);
        tenure_apply_short_stays($rooms, $between, $detail, $snap[$prevLabel], $cur,
                                 $elsewhere[$label] ?? [], $byKey, $rec, '', $short);
    }

    // THE YEAR BOUNDARY - November of last year against February of this one.
    // This is where the ladder actually moves: children progress a room in
    // January and leave for school in December, so without this the screen
    // shows churn and no progression whatsoever.
    $boundary = null;
    $firstLabel = $labels[0];
    if (!empty($opts['boundary'] ?? true) && $firstLabel === 'Feb') {
        try {
            $prevNov = tenure_snapshot($centre['kidsoft_service_id'], $year - 1, 11, $pk, $refresh);
            if ($prevNov) {
                tenure_apply_transition($rooms, $prevNov, $snap['Feb'],
                                       $elsewhere['Feb'] ?? [], $byKey, 'b_');
                $boundary = ['from' => ($year - 1) . ' Nov', 'to' => $year . ' Feb',
                             'count_from' => count($prevNov), 'count_to' => count($snap['Feb'])];

                // December and January: the window most short stays fall in.
                $detail = [];
                $between = tenure_window($centre['kidsoft_service_id'], [[$year - 1, 12], [$year, 1]],
                                         $pk, $refresh, $detail);
                tenure_apply_short_stays($rooms, $between, $detail, $prevNov, $snap['Feb'],
                                         $elsewhere['Feb'] ?? [], $byKey, $rec, 'b_', $short);
            }
        } catch (RuntimeException $e) {
            // Last year may simply predate the centre. Not an error: the in-year
            // half of the screen stands on its own.
        }
    }

    // Centre totals. Turnover is gross flow over the average roll: the number of
    // movements the team had to handle, per child on the books.
    $tot = ['in_new' => 0, 'in_up' => 0, 'stay' => 0, 'out_left' => 0, 'out_aged' => 0,
            'out_moved_centre' => 0, 'out_up' => 0,
            'b_in_new' => 0, 'b_in_up' => 0, 'b_stay' => 0, 'b_out_left' => 0,
            'b_out_aged' => 0, 'b_out_moved_centre' => 0, 'b_out_up' => 0];
    foreach ($rooms as $r) foreach (array_keys($tot) as $k) $tot[$k] += $r[$k];

    // A room that neither held a child nor saw a movement all year is not part of
    // this year's ladder - Kidsoft lists half of its near-duplicate room pairs as
    // active, and a row of zeroes reads as a room that emptied.
    foreach ($rooms as $rid => $r) {
        $moved = $r['in_new'] + $r['out_left'] + $r['b_in_new'] + $r['b_in_up'] + $r['b_out_up'] + $r['b_out_aged'];
        if (array_sum($r['headcount']) === 0 && $moved === 0) unset($rooms[$rid]);
    }

    $avg = $counts ? array_sum($counts) / count($counts) : 0;
    $gross = $tot['in_new'] + $tot['out_left'];
    $tot['avg_roll']  = round($avg, 1);
    $tot['turnover']  = $avg > 0 ? round(100 * $gross / $avg, 1) : null;
    $tot['movements'] = $gross;

    // GROWTH IS NOT CHURN, and turnover cannot tell them apart. A centre that
    // filled from 97 children to 111 during the year took 14 arrivals that
    // backfilled nothing - nobody left to make room for them. Kanwal in 2026
    // passes the steady-state test above (it is no longer opening) and still
    // reads 53.2% turnover, most of which is simply the centre getting bigger.
    // Reporting the net alongside the gross is what stops the screen calling
    // growth a problem; it is deliberately NOT subtracted from turnover, because
    // which particular arrival was growth and which was backfill is unknowable.
    $vals = array_values($counts);
    $tot['net_change'] = $vals ? end($vals) - $vals[0] : 0;
    $tot['growing']    = $avg > 0 && $tot['net_change'] > 0.05 * $avg;

    // THE GAP FROM THE IDEAL, in one ratio. In the perfect year every arrival
    // from outside walks into the bottom room; anything joining higher up is
    // backfilling a place churn opened.
    // Split BOTH intakes, not just the mid-year one: a child joining the 3-4s in
    // February is backfilling a place just as much as one joining in August.
    $bottom = array_key_first($rooms);
    $tot['in_new_bottom']   = $bottom !== null ? $rooms[$bottom]['in_new'] : 0;
    $tot['in_new_above']    = $tot['in_new'] - $tot['in_new_bottom'];
    $tot['b_in_new_bottom'] = $bottom !== null ? $rooms[$bottom]['b_in_new'] : 0;
    $tot['b_in_new_above']  = $tot['b_in_new'] - $tot['b_in_new_bottom'];

    $steady = tenure_steady($counts);

    return [
        'centre_id' => $centreId,
        'centre'    => $centre['name'],
        'year'      => $year,
        'samples'   => $labels,
        'counts'    => $counts,
        'rooms'     => array_values($rooms),
        'totals'    => $tot,
        'boundary'  => $boundary,
        'steady'    => $steady['steady'],
        'not_steady_reason' => $steady['reason'],
        'degraded'  => array_values(array_unique($degraded ?? [])),
        // A YEAR STILL RUNNING COVERS A SHORTER SPAN, AND THE FIGURE MUST SAY SO.
        // The readings are quarterly (Feb/May/Aug/Nov), so the current year has
        // only the ones that have happened: measured 6 Aug 2026, Niagara Park
        // read 19.3% from three readings (Feb-Aug) against 2025's 13.4% from four
        // (Feb-Nov). Printed side by side with no label that reads as a centre
        // getting dramatically worse, when the two numbers do not cover the same
        // amount of time. This matters more since the year default became the
        // CURRENT calendar year, because it is now what the screen opens on.
        'partial'   => count($labels) < count(TENURE_SAMPLES),
        'span'      => $labels ? (reset($labels) . ' to ' . end($labels)) : null,
        'short_stays' => tenure_short_stays_why($short, $centre['kidsoft_service_id']),
        'note'      => null,
    ];
}

/**
 * Several monthly snapshots read as one: who was booked at any point in them.
 *
 * @param array<int, array{0:int,1:int}> $months [[year, month], ...]
 */
function tenure_window(string $serviceId, array $months, array $pk, bool $refresh, array &$detail): array {
    $out = [];
    foreach ($months as [$y, $m]) {
        $out = array_replace_recursive($out, tenure_snapshot($serviceId, $y, $m, $pk, $refresh, $detail));
    }
    return $out;
}

/**
 * Finish the short-stay rows with the half of WHY that Kidsoft can answer.
 *
 * The bookings say how long they stayed and how many of their days they came
 * for; ExitReason is whatever was picked when they were exited. What the family
 * ASKED FOR is on the application that became the enrolment, and it is the
 * telling part: measured 29 Oct 2026, 5 of 10 had not been given every day they
 * asked for (one asked for five days, was given Monday, came once and left
 * "Change of mind").
 *
 * The application is matched on ChildID only, which Kidsoft writes onto it at
 * conversion - the same rule as everywhere else, no name matching. The read is
 * the archived history the search palette already caches and the nightly job
 * already warms; search_history_params() asks for the requested days so this
 * needs no second copy of it. Read only when there is somebody to explain.
 *
 * The ChildID is dropped here: nothing in the browser needs it.
 */
function tenure_short_stays_why(array $list, string $serviceId): array {
    if (!$list) return [];

    $app = [];
    try {
        $rows = kidsoft_rows(kidsoft_cached('Waitlist', 'getWaitingList',
            search_history_params($serviceId), SEARCH_HISTORY_TTL), 'WaitingList', $serviceId);
        foreach ($rows as $w) {
            $cid = (string)($w['ChildID'] ?? '');
            if ($cid === '') continue;
            // A child who applied twice: the application Kidsoft marked Enrolled
            // is the one that became this place; failing that, the latest.
            $rank = [($w['Status'] ?? '') === 'Enrolled' ? 1 : 0, (string)($w['DateAdded'] ?? '')];
            if (!isset($app[$cid]) || $rank > $app[$cid][0]) $app[$cid] = [$rank, $w];
        }
    } catch (RuntimeException $e) {
        // The rows still stand on the bookings; they only lose what was asked for.
    }

    $centres = array_column(centres_all(), 'name', 'id');
    $span = static fn(?string $a, ?string $b) => ($a && $b)
        ? (int)date_create($a)->diff(date_create($b))->days : null;

    foreach ($list as &$s) {
        $w = $app[$s['child_id'] ?? ''][1] ?? null;
        $asked = $w ? waitlist_requested_weekdays($w) : [];
        // No requested days is "we do not know", not "asked for nothing".
        $s['asked']       = $asked ?: null;
        $s['applied']     = $w ? (substr((string)($w['DateAdded'] ?? ''), 0, 10) ?: null) : null;
        $s['waited_days'] = $span($s['applied'], $s['first']);
        $s['stayed_days'] = $s['first'] ? $span($s['first'], $s['last']) + 1 : null;
        $s['moved_to']    = $s['moved_to'] !== null ? ($centres[$s['moved_to']] ?? null) : null;
        // THE ChildID AT THIS CENTRE, so the name opens the child's dialog (6 Nov
        // 2026). It was dropped here when nothing in the browser had a use for it.
        $s['ks_id'] = $s['child_id'];
        unset($s['child_id']);
    }
    unset($s);

    usort($list, static fn($a, $b) => strcmp((string)$a['first'], (string)$b['first']));
    return $list;
}

/** A shaped-but-empty result, so callers never have to special-case a missing key. */
function tenure_empty(int $centreId, int $year, string $note): array {
    $c = centre_get($centreId);
    return [
        'centre_id' => $centreId, 'centre' => $c['name'] ?? '', 'year' => $year,
        'samples' => [], 'counts' => [], 'rooms' => [],
        'totals' => ['in_new' => 0, 'in_up' => 0, 'out_left' => 0, 'out_aged' => 0,
                     'out_moved_centre' => 0, 'out_up' => 0, 'avg_roll' => 0,
                     'turnover' => null, 'movements' => 0, 'net_change' => 0, 'growing' => false,
                     'b_in_new' => 0, 'b_in_up' => 0, 'b_out_left' => 0, 'b_out_aged' => 0,
                     'in_new_bottom' => 0, 'in_new_above' => 0,
                     'b_in_new_bottom' => 0, 'b_in_new_above' => 0],
        'steady' => false, 'not_steady_reason' => null, 'degraded' => [], 'short_stays' => [],
        'note' => $note,
    ];
}

/**
 * Every centre's churn for the year, worst first.
 *
 * Unlike the Board's overview this one CANNOT be answered from our own tables --
 * churn is a fact about Kidsoft's bookings and nothing else - so it is four
 * month-reads per centre. Warm (the nightly cron samples these same months) that
 * is well under a second; cold it is minutes, which is why the cron warms it.
 */
function tenure_overview(int $year, array $opts = []): array {
    $out = [];
    foreach (centres_all() as $c) {
        try {
            $y = tenure_year((int)$c['id'], $year, $opts);
        } catch (RuntimeException $e) {
            continue;
        }
        $out[] = [
            'centre_id' => (int)$c['id'],
            'centre'    => $c['name'],
            'steady'    => $y['steady'],
            'not_steady_reason' => $y['not_steady_reason'],
            'totals'    => $y['totals'],
            'note'      => $y['note'],
        ];
    }
    // Steady centres first, then worst turnover - an unsteady centre has no
    // meaningful rank and must not occupy the top of a problem list.
    usort($out, static function ($a, $b) {
        if ($a['steady'] !== $b['steady']) return $a['steady'] ? -1 : 1;
        return ($b['totals']['turnover'] ?? -1) <=> ($a['totals']['turnover'] ?? -1);
    });
    return $out;
}

/**
 * One set of figures for the whole organisation.
 *
 * COMPUTED OVER THE COMPARABLE CENTRES ONLY, and the excluded ones are named
 * rather than dropped silently. Warnervale contributes nothing (it does not
 * trade) but Kanwal in its opening year would add 90 arrivals against an average
 * roll of 42, which on its own drags a group turnover of ~24% up by a third and
 * describes a centre opening, not families leaving. A reader who is not told
 * which centres were left out cannot tell a real movement in the number from a
 * change in which centres qualified.
 *
 * `bar_max` is the scale every centre's bars are drawn against, so a bar means
 * the same length at every row.
 */
function tenure_group_summary(array $overview): array {
    $steady = array_values(array_filter($overview, static fn($c) => $c['steady']));
    $sum = static function (string $k) use ($steady) {
        return array_sum(array_map(static fn($c) => (int)($c['totals'][$k] ?? 0), $steady));
    };
    $roll = array_sum(array_map(static fn($c) => (float)($c['totals']['avg_roll'] ?? 0), $steady));
    $in   = $sum('in_new');
    $out  = $sum('out_left');
    $newTotal = $in + $sum('b_in_new');

    return [
        'centres'      => count($steady),
        'excluded'     => array_values(array_map(
            static fn($c) => ['centre' => $c['centre'], 'why' => $c['not_steady_reason']],
            array_filter($overview, static fn($c) => !$c['steady'])
        )),
        'avg_roll'     => round($roll),
        'in_new'       => $in,
        'out_left'     => $out,
        'out_aged'     => $sum('b_out_aged') + $sum('out_aged'),
        'moved_centre' => $sum('out_moved_centre'),
        'movements'    => $in + $out,
        'turnover'     => $roll > 0 ? round(100 * ($in + $out) / $roll, 1) : null,
        // The ratio that states the gap from the ideal - see the header.
        'above_bottom' => $newTotal > 0
            ? round(100 * ($sum('in_new_above') + $sum('b_in_new_above')) / $newTotal)
            : null,
        // THE BARS ARE A RATE, NOT A COUNT, and this was wrong the first time.
        // The list is ranked by turnover, so bars drawn in absolute children
        // contradicted the ranking they sat next to: Tuggerah (283 children) had
        // the longest bars on the screen while ranking third, and Point Clare --
        // actually the worst centre in the group - looked mild. A rate is used
        // for the ranking precisely because centres are different sizes, so the
        // mark beside it has to be the same rate. The absolute counts are still
        // one column to the right, where the eye is not comparing them across
        // rows of unequal denominators.
        'bar_max'      => max(1.0, max(array_merge([0.0], array_map(
            static function ($c) {
                $roll = (float)$c['totals']['avg_roll'];
                if ($roll <= 0) return 0.0;
                return max((int)$c['totals']['in_new'], (int)$c['totals']['out_left']) / $roll * 100;
            }, $steady
        )))),
    ];
}
