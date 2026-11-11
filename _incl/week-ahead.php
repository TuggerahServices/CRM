<?php
// WHAT'S CHANGING THIS FORTNIGHT - the week-to-week movement the year board
// cannot show. Called "What changes next week" until 4 Nov 2026.
//
// Asked for by Broc, 15 Sep 2026: "the projection for next week ... and then if
// there could be a little Snapshot of what the differences are? e.g. Child
// ending care 4 days in AA, New child starting in LA 4 days."
//
// WIDENED TO THE FORTNIGHT by Broc, 4 Nov 2026: every change dated from Monday
// of this week to Friday of next, "exactly as it already does". So the rows are
// the old card's, for three weeks running, kept when their date is inside the
// fortnight - checked that literally over all 54 weeks from 5 Jan 2026 to
// 11 Jan 2027: the same rows in every one, nothing missing and nothing extra.
// On the day it changed that was 19 rows where the one-week card showed 6: the
// 13 the card had already shown the week before, for things happening now.
//
// ANY RANGE, 6 Nov 2026: the card's "View more..." dialog asks week_changes()
// for the dates the team picks, and week_ahead() is now its fortnight call.
// Re-checked on all 104 Mondays of 2026-2027: every row and total identical.
// See "View more..." in _incl/docs/dashboard.md, and its Christmas section
// before trusting a range that crosses the break.
//
// THE % PROJECTION WAS MEASURED AND DROPPED; THE SNAPSHOT IS THE WHOLE FILE.
// Measured 15 Sep 2026 over all seven centres, this week against next:
//
//   group fill    95.2% -> 95.1%   (2 place-days, out of 2,718)
//   six of seven centres moved by 0 or 1 place-day; the largest single move was
//   Kanwal, 89.1% -> 88.4%
//
// which is the same finding that retired the week picker - cells_week() for
// Tuggerah on two weeks ten weeks apart returned identical capacity, placed,
// free and candidate counts for all 55 cells. A headline that cannot move is
// not a headline. WORSE, IT WOULD HAVE BEEN A SECOND FILL % DISAGREEING WITH
// THE BOARD: the board reports what is SOLD for the year (the peak to
// December), so Tuggerah reads 93.9% sold against 93.2% attending next week and
// Kanwal 90.9% against 88.4% - that 10 place-day gap is the Dyer twins, enrolled
// and starting 28 September. Two numbers for one centre is the fault this app
// keeps having to un-make.
//
// THE MOVEMENTS ARE REAL AND ARE VISIBLE NOWHERE ELSE. The same measurement
// found 12 booking changes next week across 4 centres, which are 10 children:
// 1 starting, 3 ending care, 2 moving up a room, 3 taking more days and 1
// dropping one. Niagara Park owns 8 of those 12 rows and its fill % is
// IDENTICAL in both weeks, so the list carries everything the percentage could
// not.
//
// COST IS NIL. The month reads below use exactly the cache keys bookings_year()
// writes and cron-nightly.php --warm fills, and board_load() has normally
// already pulled the same months in the same request. Do not give them a
// shorter TTL - the keys are shared with tenure, coverage and the year board.

declare(strict_types=1);

require_once __DIR__ . '/board.php';        // centres_all(), rooms_for_centre(), board_departures()
require_once __DIR__ . '/board-live.php';
require_once __DIR__ . '/identity.php';     // kidsoft_children(), name_case()
require_once __DIR__ . '/dates.php';        // syd_date()

/** Mon-Fri of the week containing $monday, as [weekday => Y-m-d]. */
function week_ahead_days(DateTimeImmutable $monday): array {
    $out = [];
    foreach (array_keys(WEEKDAYS) as $wd) {
        $out[$wd] = $monday->modify('+' . ($wd - 1) . ' days')->format('Y-m-d');
    }
    return $out;
}

/**
 * One centre's non-casual bookings across a set of dates.
 *
 * CASUALS ARE DROPPED HERE, not later. A casual is a one-off day: it neither
 * sells a permanent place nor stops one being sold, so a child picking up a
 * casual Thursday next week is not "gaining a day" in any sense this app
 * reports. See the hard rule in CLAUDE.md - the casual test is part of writing
 * a new getBookings read, never a hardening pass afterwards.
 *
 * AND IT IS LOAD-BEARING, NOT INCIDENTAL. Measured 15 Sep 2026 over the two
 * weeks the card compares: 5,174 permanent bookings and 54 casual. Re-running
 * week_ahead_classify() over the same rows with the casuals LEFT IN takes the
 * card from 10 rows to 43 - 33 of them invented, almost all "dropping a day"
 * and "taking more days" that are one-off casual days appearing and vanishing.
 * Two show how bad it gets: Wamberal's Peyton Samuta reads as MOVING ROOMS,
 * "Radiant Rainbows -> Shining Stars", when he is permanently in Shining Stars
 * and took a single casual day in Radiant Rainbows - a fake room progression on
 * a card whose whole job is telling real ones from departures. And Kanwal's
 * Theodore Burkill reappears, the same child whose casual Tuesdays broke the
 * day-requests block in August.
 *
 * Only `Bookings/getBookings` is read here, never `getWaitlistedBookings`, so
 * the `Casual` FLAG is the test and the CAS type-name rule does not apply - the
 * two disagree on 34 types and the flag is authoritative on a real booking.
 *
 * A DECLARED EXIT TRUNCATES THE CHILD, exactly as board_forward_occupancy()
 * truncates them. Tuggerah's Brodie Jackson gave notice for 14 August and still
 * held 49 bookings running to 24 December; without this the card would show that
 * place as held for another four months and never report the departure at all.
 *
 * EVERY YEAR THE DATES TOUCH, since 6 Nov 2026 (the "View more..." range can
 * run to the end of next year). board_departures() answers one year at a time
 * and only for exits still ahead, so a past year adds nothing and a child with
 * an exit in each keeps the earlier one.
 *
 * @param array $dates  [Y-m-d, ...]
 * @return array date => our room id => [ChildID => true]
 */
function week_ahead_bookings(int $centreId, string $serviceId, array $roomByKs, array $dates): array {
    $exits = [];
    foreach (array_unique(array_map(static fn($d) => (int)substr($d, 0, 4), $dates)) as $y) {
        foreach (board_departures($centreId, $y) as $childId => $x) {
            if (!isset($exits[$childId]) || $x['on'] < $exits[$childId]['on']) $exits[$childId] = $x;
        }
    }
    $months = array_values(array_unique(array_map(static fn($d) => substr($d, 0, 7), $dates)));
    // A set, not in_array(): a two-year range is ~520 dates against ~80,000
    // bookings a centre.
    $want   = array_flip($dates);

    $out = [];
    foreach ($months as $m) {
        $first = $m . '-01';
        $last  = date('Y-m-t', (int)strtotime($first));
        try {
            $res = kidsoft_cached('Bookings', 'getBookings', [
                'ServiceID' => $serviceId, 'DateFrom' => $first, 'DateTo' => $last,
            ]);
        } catch (Throwable $e) {
            // A month we could not read makes a week look emptier than it is,
            // which would report children as leaving who are not. The caller
            // turns that into a reported gap, never into a list of departures.
            throw new RuntimeException('could not read ' . $m);
        }
        foreach (kidsoft_rows($res, 'Bookings', $serviceId) as $b) {
            if (empty($b['ChildID']) || empty($b['RoomID']) || empty($b['Date'])) continue;
            if (!empty($b['Casual'])) continue;
            if (!isset($roomByKs[$b['RoomID']])) continue;
            $date = substr((string)$b['Date'], 0, 10);
            if (!isset($want[$date])) continue;
            if (isset($exits[$b['ChildID']]) && $date >= $exits[$b['ChildID']]['on']) continue;
            $out[$date][$roomByKs[$b['RoomID']]][(string)$b['ChildID']] = true;
        }
    }
    return $out;
}

/**
 * What changes this fortnight - Monday of this week to Friday of next - per
 * child.
 *
 * A CHILD CAN APPEAR TWICE, and that is the old card's rows unchanged rather
 * than a fault of the widening: each row is one week-to-week step, and the
 * fortnight holds up to three of them. Ryleigh Tait moves Silver -> Pink on
 * 6 Nov 2026 and back on 13 Nov; both are real. The cases that read as ONE
 * event in two rows are a departure or a start that falls mid-week - "Dropping a
 * day" the Thursday, then "Ending care" the Wednesday before it is the same
 * child leaving. Counted over 54 weeks from 5 Jan 2026: 85 leaving splits and
 * 44 starting splits, most of them in January. Left as they are.
 *
 * THE UNIT IS THE CHILD, NOT THE (CHILD, ROOM) ROW, and the difference is the
 * whole reason this function is not six lines of array_diff. Measured 15 Sep
 * 2026, next week's 12 raw (child, room) changes are 10 children, and the two
 * that collapse are both children SHIFTING DAYS BETWEEN TWO ROOMS at Niagara
 * Park - Jasper Mamone drops from three Little Angels days to one and picks up
 * two in Radiant Rainbows, Sonny Beehag does the mirror of it. Diffed per room
 * each of them reads as a child STARTING in one room and ENDING CARE in
 * another, on the same card. They are birthdays.
 *
 * This is the same fault coverage_mark_handovers() exists to fix on the year
 * board, where 80 of 215 early-ending arrangements at Tuggerah were room
 * progressions and counting all of them overstated sellable capacity by more
 * than half. A card that calls a child moving up a room "ending care" would put
 * a place on the market that was never free.
 *
 * SHUTDOWN IS NOT A MASS DEPARTURE. Bookings run at full strength to 24 December
 * and then stop dead (measured at Tuggerah: 163 children on Wed 23 Dec, none
 * after the 25th), so the card read in the week before Christmas would otherwise
 * report every child in the organisation as leaving. A week of the fortnight
 * with no bookings at all, at a centre with bookings in the weeks around it, is
 * reported as that centre closed for that week and produces no rows.
 *
 * Public holidays need no such guard and were checked rather than assumed:
 * Kidsoft keeps the bookings on them, because the families are charged. Monday
 * 26 January 2026 reads 148 children at Tuggerah against 148 and 149 on the
 * Mondays either side, and Good Friday 3 April reads 145 against 146 and 145.
 *
 * @param ?int    $centreId null = the whole group
 * @param ?string $asAt     Y-m-d to read the week from, for checking the dates
 *                          that only come round once a year. Nothing in the app
 *                          passes it; it exists so the Christmas guard above can
 *                          be EXERCISED rather than asserted. Measured 15 Sep
 *                          2026 against the three weeks that bite:
 *                            Mon 14 Dec  1 row, a real departure. Without the
 *                                        per-date guard it would be 504: Friday
 *                                        25 December is shut, so all 503
 *                                        children booked on Friday the 18th
 *                                        would read as dropping their Friday.
 *                            Mon 21 Dec  no rows, all six trading centres
 *                                        reported closed - every day of the
 *                                        following week is shut, so there is not
 *                                        one comparable weekday.
 *                            Mon 28 Dec  the same, from the other side.
 *
 *                          RE-MEASURED 4 Nov 2026 and THE GUARD IS DEFEATED BY
 *                          ONE BOOKING. Terrigal holds a single permanent
 *                          booking on Mon 28 Dec, Thu 31 Dec and Fri 1 Jan (one
 *                          child, Shining Stars), so those days read as OPEN
 *                          and get compared: the week of 21 Dec reports 76
 *                          Terrigal children ending care and the week of 28 Dec
 *                          68 starting. That was already true of the one-week
 *                          card; the fortnight shows it on the 14 Dec to 4 Jan
 *                          cards. Not fixed here - reported to Broc.
 */
function week_ahead(?int $centreId = null, ?string $asAt = null): array {
    $tz    = new DateTimeZone('Australia/Sydney');
    $mon0  = (new DateTimeImmutable($asAt ?? 'today', $tz))->modify('monday this week');
    // Monday of this week to Friday of next.
    return week_changes($centreId, $mon0->format('Y-m-d'), $mon0->modify('+11 days')->format('Y-m-d'));
}

/**
 * The dates the "View more..." dialog may ask for: 1 January this year to 31
 * December next year, as [Y-m-d, Y-m-d].
 *
 * THESE ARE THE TWO YEARS THE NIGHTLY WARM KEEPS (bookings_year() for this year
 * and next, cron-nightly.php job 8), so any range inside them is cached month
 * reads - measured 6 Nov 2026 at 1.0s for both whole years across the group,
 * 1,810 rows. A month outside them is a live Kidsoft read per centre, ~2s each
 * cold, and a range of last year would be a minute of them. They are also the
 * two years the team plans; next year's bookings thin out after January
 * because that is as far as places have been sold.
 */
function week_changes_bounds(): array {
    $y = (int)(new DateTimeImmutable('today', new DateTimeZone('Australia/Sydney')))->format('Y');
    return [$y . '-01-01', ($y + 1) . '-12-31'];
}

/**
 * Every change dated from $from to $to inclusive - the card's rows for any
 * range. week_ahead() is this for the fortnight; the Dashboard's "View more..."
 * dialog (Broc, 6 Nov 2026) asks it for a range the team picks.
 *
 * THE SAME COMPARISON, MORE WEEKS OF IT. Every week the range touches is read,
 * plus the week either side, and each pair of neighbouring weeks is classified
 * exactly as the one-week card classified its single pair. A row is kept when
 * its date is inside the range. For the fortnight that is the four weeks and
 * three steps it always was - checked, see dashboard.md.
 *
 * @param ?array $readable [Y-m-d, Y-m-d]: dates outside it are never sent to
 *                         Kidsoft. The dialog passes the two years the nightly
 *                         warm keeps, so the week either side of its range
 *                         cannot become a cold read of a year nobody warmed. An
 *                         unread date has no bookings, which the per-date guard
 *                         below already treats as "not comparable" - the same
 *                         answer it gives a day the centre is shut.
 */
function week_changes(?int $centreId, string $from, string $to, ?array $readable = null): array {
    $tz   = new DateTimeZone('Australia/Sydney');
    $monA = (new DateTimeImmutable($from, $tz))->modify('monday this week');
    $monZ = (new DateTimeImmutable($to, $tz))->modify('monday this week');

    // THE WEEK EITHER SIDE IS READ AS WELL. A change is the difference between
    // one week and the next, dated by the rule further down: a departure by its
    // last day, which lies in the EARLIER week of the pair, and everything else
    // by its first, which lies in the LATER one. So the first week's starters
    // need the week before it, and the last week's leavers the week after. For
    // the fortnight that is four weeks read to report two - last week to this,
    // this to next, next to the one after - and each step is exactly the
    // comparison the card made when it was one week.
    $weeks = [];
    for ($m = $monA->modify('-7 days'); $m <= $monZ->modify('+7 days'); $m = $m->modify('+7 days')) {
        $weeks[] = week_ahead_days($m);
    }
    $lastWk = count($weeks) - 1;
    $dates = array_merge(...array_map('array_values', $weeks));
    $read  = $readable
        ? array_values(array_filter($dates, static fn($d) => $d >= $readable[0] && $d <= $readable[1]))
        : $dates;

    $centres = centres_all();
    if ($centreId !== null) {
        $centres = array_values(array_filter($centres, static fn($c) => (int)$c['id'] === $centreId));
        if (!$centres) throw new InvalidArgumentException('unknown centre');
    }

    $out = [
        'from'      => $from,
        'to'        => $to,
        // Formatted here like every other date this app publishes: these are
        // Sydney wall-clock strings and new Date() in the browser re-reads them
        // in the viewer's own timezone. See _incl/dates.php.
        'from_disp' => syd_date($from),
        'to_disp'   => syd_date($to),
        'changes'   => [],
        'totals'    => ['starting' => 0, 'leaving' => 0, 'moving' => 0,
                        'more_days' => 0, 'fewer_days' => 0, 'place_days' => 0],
        // Centres the weeks could not be read for, and centres shut for a whole
        // week of the range. Reported as a gap in the data, never folded into
        // the list - the same three-state rule dash_trading() follows.
        'unreadable' => [],
        'closed'     => [],
    ];

    foreach ($centres as $c) {
        $cid       = (int)$c['id'];
        $serviceId = $c['kidsoft_service_id'];
        if (!$serviceId) continue;

        $year = (int)$monZ->format('Y');
        // NOTHING CHANGES AT A CENTRE THAT HAS NOT OPENED. It was already silent
        // here - a centre that books nothing in any of the weeks read is skipped
        // as "not news" further down - but only AFTER a Kidsoft read per centre.
        // This is the same test every other year-scoped surface now runs, and it
        // costs one already-loaded row. The year is the LAST week's (next week's,
        // on the card), so a centre opening mid-week is picked up the week its
        // Monday belongs to.
        if (centre_not_open_yet($c, $year)) continue;
        $roomByKs = []; $roomName = [];
        foreach (rooms_for_centre($cid, $year) as $r) {
            if (!$r['kidsoft_room_id']) continue;
            $roomByKs[$r['kidsoft_room_id']] = (int)$r['id'];
            $roomName[(int)$r['id']]         = (string)$r['name'];
        }
        if (!$roomByKs) continue;

        try {
            $booked = $read ? week_ahead_bookings($cid, $serviceId, $roomByKs, $read) : [];
        } catch (Throwable $e) {
            $out['unreadable'][] = ['centre_id' => $cid, 'centre' => $c['name']];
            continue;
        }

        // Not one booking in any week read: not trading. Warnervale books
        // nothing all year and is not news.
        $any = array_map(static fn($wk) => (bool)array_filter($wk, static fn($d) => !empty($booked[$d])), $weeks);
        if (!array_filter($any)) continue;

        // A WEEK OF THE RANGE WITH NO BOOKINGS AT ALL IS THE CENTRE SHUT, never
        // the centre emptying - the week of 28 December - and it is said so on
        // the card rather than left as a silence. The per-date guard below is
        // what keeps it from producing rows.
        //
        // ONLY BETWEEN TWO WEEKS THAT HAVE BOOKINGS, which is what "shut" means.
        // A centre that has not had its first booking yet is not shut, and over a
        // range of months the difference is one line against fifty: a centre
        // opening in the range would otherwise be "closed" every week before it.
        foreach ($weeks as $i => $wk) {
            if ($i === 0 || $i === $lastWk || $any[$i]) continue;
            if (!in_array(true, array_slice($any, 0, $i), true)) continue;
            if (!in_array(true, array_slice($any, $i + 1), true)) continue;
            $out['closed'][] = ['centre_id' => $cid, 'centre' => $c['name'],
                                'week_disp' => syd_date($wk[1])];
        }

        $names = null;
        for ($s = 0; $s < $lastWk; $s++) {
            $wasWeek = $weeks[$s];
            $nowWeek = $weeks[$s + 1];

            // A WEEKDAY IS ONLY COMPARABLE IF THE CENTRE IS OPEN ON IT IN BOTH
            // WEEKS, and this is the guard that stops Christmas reporting the
            // whole organisation as dropping a day.
            //
            // Measured across all of 2026 at all seven centres (15 Sep 2026):
            // every trading centre has EXACTLY SEVEN weekdays with no bookings at
            // all - 1 and 2 January, and 25, 28, 29, 30, 31 December - and the
            // seven are identical at all six. Every other weekday of the year has
            // bookings, public holidays included, because the families are
            // charged for them: Monday 26 January reads 148 children at Tuggerah
            // against 148 and 149 on the Mondays either side.
            //
            // So a zero-booking date at a trading centre means the centre is
            // SHUT, never that it emptied - and in the week of Monday 21
            // December, Friday the 25th is shut while Friday the 18th was not.
            // Comparing the two would report every Friday child in the
            // organisation as dropping a day: ~145 of them at Tuggerah alone. The
            // day is dropped from BOTH weeks instead, which is the honest answer
            // - nobody gained or lost a day the centre was never open on.
            //
            // Derived from the data every run and never keyed to a date, so a
            // centre that closes on a different day is handled with no code
            // change - the same rule dash_trading() follows for a centre that
            // starts trading.
            $open = [];
            foreach (array_keys(WEEKDAYS) as $wd) {
                $open[$wd] = !empty($booked[$wasWeek[$wd]]) && !empty($booked[$nowWeek[$wd]]);
            }
            if (!array_filter($open)) continue;     // not one comparable day

            // childId => our room id => [weekday, ...], for each of the two weeks.
            $held = [0 => [], 1 => []];
            foreach ([0 => $wasWeek, 1 => $nowWeek] as $w => $days) {
                foreach ($days as $wd => $date) {
                    if (!$open[$wd]) continue;
                    foreach ($booked[$date] ?? [] as $rid => $kids) {
                        foreach (array_keys($kids) as $childId) $held[$w][(string)$childId][$rid][] = $wd;
                    }
                }
            }

            $names ??= kidsoft_children($serviceId);
            foreach (array_unique(array_merge(array_keys($held[0]), array_keys($held[1]))) as $childId) {
                $row = week_ahead_classify(
                    (string)$childId,
                    $held[0][$childId] ?? [],
                    $held[1][$childId] ?? [],
                    $roomName
                );
                if (!$row) continue;

                // WHEN IT HAPPENS, as a real date rather than a weekday letter.
                //
                // A DEPARTURE IS DATED BY ITS LAST DAY, EVERYTHING ELSE BY ITS
                // FIRST. "Leo Birch ends care Friday 18 September" is the
                // sentence the team acts on; the first day he is ABSENT is not a
                // fact anybody uses, and it would also be the one date on the
                // card that names a day nothing happens on. So a leaver's date
                // lands in the earlier week of the pair and every other kind in
                // the later - which is safe to put in one column only because the
                // Change column beside it says which it is, and the cell carries
                // the wording in its title.
                //
                // `days` is always the weekdays that CHANGED (see
                // week_ahead_classify), so the earliest of them is the day the new
                // arrangement starts: the first day in the destination room for a
                // move, the first of the days taken up, or the first day a
                // dropped day is missing.
                //
                // A DECLARED ExitDate NEEDS NO BRANCH HERE. week_ahead_bookings()
                // has already dropped every booking on or after it, so the last
                // booked date IS the last day - which is what makes Brodie
                // Jackson's case work, where notice was in and 49 bookings ran on
                // to December.
                $on = null;
                if ($row['kind'] === 'leaving') {
                    if ($row['was'])  $on = $wasWeek[max($row['was'])] ?? null;
                } elseif ($row['days']) {
                    $on = $nowWeek[min($row['days'])] ?? null;
                }
                // THE RANGE IS THE FILTER. On the card, last week's leavers and the
                // week after's starters belong to the cards either side of it.
                if ($on === null || $on < $from || $on > $to) continue;

                $k = $names[$childId] ?? null;
                $first = $k ? name_case((string)($k['FirstName'] ?? '')) : '';
                $last  = $k ? name_case((string)($k['LastName']  ?? '')) : '';
                // A booking nobody can name is not something to put on a card,
                // the same rule board_live_children() applies to a board row.
                if ($first === '' || $last === '') continue;

                // WHY THEY ARE GOING, as the Parent Account Manager typed it when
                // they exited the child in Kidsoft (asked for at the enrolments
                // meeting, 22 Oct 2026). It is the plain `ExitReason` on the
                // child record, and the record outlives the exit: measured that
                // day, all 54 children exited since 1 Aug were still returned by
                // getChildren and all 54 carried a reason. There is no free-text
                // exit note anywhere in the API - Options=CustomData,
                // Enrolments, Comments and AdditionalDetails were all read for
                // those 54, and the comments are undated profile text. So the
                // dropdown is the answer, and 46 of the 54 read "Change of Care
                // Conditions".
                //
                // ONLY WHEN THE EXIT IS THIS ONE. A child who left once before and
                // came back can still carry the old exit's date and reason, so
                // the reason is shown only when the exit is dated no earlier than
                // the week before the one they leave in. A leaver whose exit is
                // not entered yet gets nothing - the card already says they are
                // going.
                $reason = null;
                if ($row['kind'] === 'leaving' && $k) {
                    $exitOn = substr((string)($k['ExitDate'] ?? ''), 0, 10);
                    // Monday of the week before the earlier week of the pair.
                    $since  = (new DateTimeImmutable($wasWeek[1], $tz))->modify('-7 days')->format('Y-m-d');
                    if ($exitOn !== '' && $exitOn >= $since) {
                        $reason = trim((string)($k['ExitReason'] ?? '')) ?: null;
                    }
                }

                $out['changes'][] = $row + [
                    'centre_id' => $cid,
                    'centre'    => $c['name'],
                    'ks_id'     => (string)$childId,
                    'name'      => trim($first . ' ' . $last),
                    'exit_reason' => $reason,
                    'on'        => $on,
                    // Formatted HERE, like every date this app publishes. These
                    // are Sydney wall-clock strings and new Date() in the browser
                    // re-reads them in the viewer's own timezone. See
                    // _incl/dates.php.
                    'on_disp'   => syd_date($on),
                    // What the date MEANS, for the cell's title. The column
                    // header cannot say both.
                    'on_said'   => $row['kind'] === 'leaving' ? 'Last day' : 'From',
                ];
                $out['totals'][$row['kind']]++;
                $out['totals']['place_days'] += $row['delta'];
            }
        }
    }

    // STARTING AND LEAVING FIRST, because those are the two that change what can
    // be sold; then the room moves, then the day changes. WITHIN A KIND, BY DATE
    // (Broc, 4 Nov 2026) - a fortnight is a calendar, and it used to put the
    // biggest movement first, which on a one-week card hardly mattered. Centre
    // and name only break a tie on the same day. The Y-m-d `on` sorts as a
    // string; never on_disp, which is d/m/Y.
    $order = ['leaving' => 0, 'starting' => 1, 'moving' => 2, 'fewer_days' => 3, 'more_days' => 4];
    usort($out['changes'], static fn($a, $b) =>
        [$order[$a['kind']], $a['on'], $a['centre'], $a['name']]
        <=> [$order[$b['kind']], $b['on'], $b['centre'], $b['name']]);

    return $out;
}

/**
 * One child's two weeks, classified.
 *
 * A MOVE REQUIRES THE DAYS TO LAND SOMEWHERE - some room has to GROW. The first
 * version tested only "did the set of rooms change", and it got Tuggerah's Luca
 * Yates wrong on the first run: he holds Radiant Rainbows Silver on Monday and
 * Radiant Rainbows Pink on Tuesday this week, and next week only the Pink
 * Tuesday. The room set changed, so it reported "moving Silver -> Pink" when
 * nothing moved anywhere - he gave a Monday up and his Tuesday never altered.
 * Growth on one side and shrinkage on the other is what a move actually is.
 *
 * AND THAT TEST OUTRANKS THE DAY COUNT, which is the other half of it: Niagara
 * Park's Sonny Beehag gives up Little Angels Tuesday and Wednesday and picks up
 * exactly those two days in Radiant Rainbows, so his net delta is ZERO and a
 * day-count test alone reports nothing at all. Both of Niagara Park's movers
 * next week are that shape. This is the same fault coverage_mark_handovers()
 * exists to fix on the year board, where 80 of 215 early-ending arrangements at
 * Tuggerah were room progressions; a card that called a child moving up a room
 * "ending care" would put a place on the market that was never free.
 *
 * `days` ALWAYS MEANS THE WEEKDAYS THAT CHANGED, whatever the kind - taken up,
 * given up, or moved across - so one Mon-Fri strip means one thing on every row
 * of the card. `was` and `now` carry the whole pattern in the headline room for
 * the tooltip behind it.
 *
 * @param array $was  our room id => [weekday, ...], this week
 * @param array $now  our room id => [weekday, ...], next week
 * @return ?array null when nothing changed
 */
function week_ahead_classify(string $childId, array $was, array $now, array $roomName): ?array {
    $delta = array_sum(array_map('count', $now)) - array_sum(array_map('count', $was));

    $mk = static function (string $kind, ?int $rid, ?int $fromRid, array $days, array $was, array $now, int $delta) use ($roomName) {
        sort($days); sort($was); sort($now);
        return [
            'kind'      => $kind,
            'room'      => $rid     !== null ? ($roomName[$rid]     ?? '') : null,
            'from_room' => $fromRid !== null ? ($roomName[$fromRid] ?? '') : null,
            // Carried so the card can POINT AT THE CELL rather than describe it.
            // The same lesson the coming-free banner learned twice: naming a room
            // and a weekday in prose, above a grid that has a square for exactly
            // that, sends the reader hunting with a shopping list. A row here
            // deep-links to /placement carrying room and wd, which index.php
            // forwards intact.
            'room_id'   => $rid,
            // The weekdays this change is ABOUT. window.dayStrip() draws them in
            // fixed Mon-Fri positions, so the column reads down without words.
            'days'      => array_values($days),
            // The whole pattern in the headline room, both weeks, for the title.
            'was'       => array_values($was),
            'now'       => array_values($now),
            'delta'     => $delta,
        ];
    };

    if (!$was && $now) {
        $rid = (int)array_key_first($now);
        return $mk('starting', $rid, null, $now[$rid], [], $now[$rid], $delta);
    }
    if ($was && !$now) {
        $rid = (int)array_key_first($was);
        return $mk('leaving', $rid, null, $was[$rid], $was[$rid], [], $delta);
    }

    // Which rooms grew and which shrank, counting a room newly taken up as pure
    // growth and one given up entirely as pure shrinkage.
    $grew = []; $shrank = [];
    foreach (array_keys($now + $was) as $rid) {
        $d = count($now[$rid] ?? []) - count($was[$rid] ?? []);
        if ($d > 0) $grew[(int)$rid]   = $d;
        if ($d < 0) $shrank[(int)$rid] = -$d;
    }
    if (!$grew && !$shrank) return null;

    arsort($grew); arsort($shrank);

    if ($grew && $shrank) {
        $to   = (int)array_key_first($grew);
        $from = (int)array_key_first($shrank);
        return $mk('moving', $to, $from,
            // The days that arrive in the destination room.
            array_values(array_diff($now[$to] ?? [], $was[$to] ?? [])),
            $was[$from] ?? [], $now[$to] ?? [], $delta);
    }

    if ($grew) {
        $rid = (int)array_key_first($grew);
        return $mk('more_days', $rid, null,
            array_values(array_diff($now[$rid] ?? [], $was[$rid] ?? [])),
            $was[$rid] ?? [], $now[$rid] ?? [], $delta);
    }

    $rid = (int)array_key_first($shrank);
    return $mk('fewer_days', $rid, null,
        array_values(array_diff($was[$rid] ?? [], $now[$rid] ?? [])),
        $was[$rid] ?? [], $now[$rid] ?? [], $delta);
}
