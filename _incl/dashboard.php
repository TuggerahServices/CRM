<?php
// THE DASHBOARD - the whole enrolment process at a glance, group-wide or per
// centre. It is the one screen that crosses the two halves of the app.
//
// The organisation's only revenue is a real child in a real room. So this screen
// is built around a single chain, and every figure on it is a step in that chain
// or an obstacle to it:
//
//     an empty place  ->  a family who might take it  ->  the WORK of getting
//     them there  ->  Kidsoft showing them in the room  ->  we get paid
//
// WHAT IT DELIBERATELY DOES NOT DO: stage-to-stage conversion percentages.
// Measured 6 Aug 2026 over all 1,693 Kidsoft waitlist rows, the outcome statuses
// (Enrolled, Offered, ExpiryReminderFirst/Second, DeletedByParent) ALL first
// appear in 2026Q3 - a Kidsoft release, since six of the seven centres began
// emitting them within the same fortnight - and 89 of the 949 rows filed as
// "ExpiredWaitlist" had in fact become enrolled children. A funnel with rates on
// it would be reporting Kidsoft admin hygiene as if it were this organisation's
// performance, and that is a number that ends up in a board pack. So the
// pipeline is reported as a SNAPSHOT of where families stand now, bracketed by
// the two flows that are genuinely reliable.
//
// EVERY CENTRE-LEVEL AGGREGATE MUST SURVIVE A CENTRE THAT IS NOT TRADING.
// Warnervale has 4 linked rooms and 385 recorded place-days a week, zero
// bookings and zero children. Counted naively it is 80% of the group's "places
// free" - the single biggest number on this screen would be a centre that is
// shut. dash_trading() is the explicit test, the same shape as tenure_steady().

declare(strict_types=1);

require_once __DIR__ . '/board.php';
require_once __DIR__ . '/work.php';
require_once __DIR__ . '/waitlist.php';
require_once __DIR__ . '/waitlist-group.php';
require_once __DIR__ . '/reenrolment.php';
require_once __DIR__ . '/week-ahead.php';

// NO "LAPSING" FIGURE (11 Nov 2026). DASH_EXPIRY_SOON_DAYS (30) counted open
// applications within 30 days of Kidsoft's ExpiryDate, as a tile, a column, a
// banner and a "Needs attention" row. Broc turned the "Waitlist expire" option
// off in Kidsoft that day, so a waiting place no longer lapses and every one of
// those was counting down to nothing - 52 applications group-wide. The Waitlist's
// "Expiring soon" tile went the same day; see _incl/docs/waitlist.md.

/**
 * Is this centre actually trading in this year?  true / false / null = cannot say.
 *
 * A centre with capacity and no children at all is not a centre with a filling
 * problem - it is closed, or not open yet. It must be reported, never ranked,
 * and never folded into a group total. Derived from the data every run, NEVER
 * keyed to a centre name, so a centre that starts trading is picked up with no
 * code change.
 *
 * "NOT TRADING" AND "COULD NOT ASK" ARE DIFFERENT ANSWERS, and this returned the
 * first for both until a live Kidsoft maintenance window (22:40, 6 Aug 2026)
 * showed Kanwal - 52 children booked for 2027 - reported as closed. A centre
 * the API would not answer for has UNKNOWN occupancy: it must be excluded from
 * the totals like a closed one, but reported as a gap in the data rather than as
 * a fact about the business. Silently downgrading an outage to "not trading" is
 * how a screen tells a confident lie.
 */
function dash_trading(array $board): ?bool {
    return board_trading($board);   // one implementation, in board-live.php
}

/**
 * One centre's dashboard row.
 *
 * @param array $opts 'work' => include the work-item counts (a DB read per centre)
 */
function dash_centre(array $centre, int $year, array $opts = []): array {
    $centreId = (int)$centre['id'];

    // A CENTRE THAT HAS NOT OPENED IN THIS YEAR IS NOT MEASURED AT ALL, and the
    // test runs BEFORE the board is derived - one row, no Kidsoft. Everything
    // below describes rooms in a year this centre did not exist for.
    //
    // ITS QUEUE IS STILL READ, AND THAT IS THE POINT. Warnervale has 17 families
    // waiting and a work pipeline that can run for months before the doors open;
    // hiding those would be the opposite of useful. What is withheld is the room
    // half - places, cells, fill - which reads `null` here exactly as it does for
    // a centre Kidsoft would not answer for. `--`, not a zero.
    $notOpen = centre_not_open_yet($centre, $year);
    $board = $notOpen ? null : board_load($centreId, $year);
    if ($notOpen) return dash_centre_unopened($centre, $year, $notOpen, $opts);

    $trading = dash_trading($board);
    $free = 0; $capacity = 0; $placed = 0;
    foreach ($board['totals'] as $t) {
        $free     += $t['vacancies'];
        $capacity += $t['capacity'];
        $placed   += $t['occupied'];
    }
    $children = 0;
    foreach ($board['rooms'] as $r) $children += count($r['placements']);

    // The spots worth working: room x weekday cells with a place going spare.
    // The unit is a CELL, not a room - one room can have a full Monday and a
    // spare Friday, and the Friday is the thing to sell.
    $openCells = 0;
    foreach ($board['rooms'] as $r) {
        foreach ($r['cells'] as $cell) if (($cell['vacancies'] ?? 0) > 0) $openCells++;
    }

    // The queue: how long it is, and how long it has been waiting.
    $q = dbx()->query(
        'SELECT COUNT(*) AS open_n,
                ROUND(AVG(DATEDIFF(CURDATE(), date_added))) AS avg_wait
           FROM waitlist_applications
          WHERE centre_id = ? AND status = "waitlisted"',
        [$centreId], 'i'
    )->fetch();

    // WHERE the free places are, for the weekly report to management (asked for
    // by Broc, 15 Sep 2026: "available positions broken up by age groups and
    // centres, and then a combined total ... so we know where to keep the
    // marketing/focus").
    //
    // Built off the SAME cells the hero figure and the grid are built off, in
    // the board this function has already loaded - so the report cannot disagree
    // with the number above it, and it costs nothing. The tier is the room's own
    // name; see room_type_key() for why Kidsoft's age bands cannot do this job.
    $byType   = [];
    $byWeekday = array_fill_keys(array_keys(WEEKDAYS), 0);
    // HOW MANY SPOTS THERE ARE ON THAT WEEKDAY, beside how many are going spare
    // (asked for 16 Sep 2026: "the total number of spots somewhere, so the team
    // can see how many to fill out of how many total"). Accumulated in the same
    // loop and under the same rule as the tier figures: a weekday whose capacity
    // Kidsoft does not give us contributes to neither side.
    $byWeekdayCap = array_fill_keys(array_keys(WEEKDAYS), 0);
    // And how many of them are HELD, so the weekday can carry the same Filled %
    // as a room does (6 Nov 2026). Same skip as the capacity beside it: both
    // sides of the fraction come off the same cells or neither does.
    $byWeekdayPlaced = array_fill_keys(array_keys(WEEKDAYS), 0);
    $roomRows = [];
    foreach ($board['rooms'] as $r) {
        $days = []; $roomFree = 0; $roomPlaced = 0; $roomCap = 0;
        foreach (array_keys(WEEKDAYS) as $wd) {
            $cell = $r['cells'][$wd] ?? [];
            $v = (int)($cell['vacancies'] ?? 0);
            $days[$wd]        = $v;
            $roomFree        += $v;
            $byWeekday[$wd]  += $v;
            // HOW FULL, not just how empty. A tier with 46 places to fill may be
            // the fullest thing in the group or the emptiest, and the count
            // alone cannot say which - that is the whole reason the % is here.
            // A weekday with UNKNOWN capacity contributes to neither side, so
            // the fraction stays honest rather than treating unknown as zero.
            if (($cell['capacity'] ?? null) === null) continue;
            $roomPlaced        += (int)$cell['occupied'];
            $roomCap           += (int)$cell['capacity'];
            $byWeekdayCap[$wd] += (int)$cell['capacity'];
            $byWeekdayPlaced[$wd] += (int)$cell['occupied'];
        }
        $k = room_type_key((string)$r['name']);
        $byType[$k]['free']     = ($byType[$k]['free']     ?? 0) + $roomFree;
        $byType[$k]['placed']   = ($byType[$k]['placed']   ?? 0) + $roomPlaced;
        $byType[$k]['capacity'] = ($byType[$k]['capacity'] ?? 0) + $roomCap;
        $roomRows[] = [
            'room_id'  => (int)$r['id'],
            'room'     => $r['name'],
            'type'     => $k,
            'days'     => $days,            // weekday => free place-days
            'total'    => $roomFree,
            'placed'   => $roomPlaced,
            'capacity' => $roomCap,
            // THE SAME board_pct() EVERY OTHER FILL FIGURE GOES THROUGH, so a
            // room cannot read 100% here with a place still free beside it.
            'pct'      => board_pct($roomPlaced, $roomCap),
        ];
    }

    // Only a centre that is OPEN, and that we could actually read, has a usable
    // free-places figure. A centre OPENING later this year counts too, from the
    // day it opens (Broc, 21 Sep 2026): Warnervale carries 385 place-days a week
    // and 17 waiting families for 2027, and `trading === true` hid every one of
    // them until the first booking landed. See centre_year_state().
    $state = centre_year_state($board);
    $known = centre_state_counts($state);
    $row = [
        'centre_id'  => $centreId,
        'centre'     => $centre['name'],
        'trading'    => $trading,           // true / false / null = could not ask
        // WHAT IT IS DOING and WHETHER IT IS COUNTED, decided once in the engine
        // so no screen re-derives the rule. `unopened` is the state that used to
        // be indistinguishable from `empty`, and `empty` - open, and nobody in
        // it - is an alarm rather than the quiet exclusion it used to get.
        'state'      => $state,
        'counts'     => $known,
        'opens_on'   => $centre['opens_on'] ?? null,
        'opens_disp' => empty($centre['opens_on']) ? null : syd_date($centre['opens_on']),
        'children'   => $children,
        'placed'     => $placed,      // child-days in a full week
        'capacity'   => $capacity,    // place-days in a full week
        'free'       => $known ? $free : null,   // null, not 0: "cannot say" vs "nothing free"
        'open_cells' => $known ? $openCells : null,
        'pct'        => $known ? board_pct($placed, $capacity) : null,
        'waiting'    => (int)($q['open_n'] ?? 0),
        'avg_wait'   => $q['avg_wait'] !== null ? (int)$q['avg_wait'] : null,
        'unreachable'=> (bool)$board['source']['unreachable'],
        // null, not an empty breakdown, for the same reason `free` is null: a
        // closed centre and a centre Kidsoft would not answer for have no
        // sellable places to report, and a row of zeroes would claim they do.
        'fill_types'    => $known ? $byType       : null,
        'fill_days'     => $known ? $byWeekday    : null,
        'fill_days_cap' => $known ? $byWeekdayCap : null,
        'fill_days_placed' => $known ? $byWeekdayPlaced : null,
        'fill_rooms'    => $known ? $roomRows     : null,
    ];

    if (!empty($opts['work'])) {
        // ACROSS EVERY YEAR, not just $year. The rooms are measured in one year
        // because a place is sold for a year; the WORK is not. A family being
        // chased today for a January start is work today, and scoping it to the
        // year would let exactly that item go quiet unseen.
        $row['work'] = work_pipeline($centreId, null);
        $row['work_open'] = 0;
        foreach ($row['work'] as $s) if ($s['open']) $row['work_open'] += $s['n'];
        $row['work_overdue'] = count(work_list($centreId, null, ['overdue' => true]));
    }
    return $row;
}

/**
 * One centre's row for a year it has not opened in.
 *
 * SAME SHAPE, SAME KEYS, so every caller - the totals, the fill report, the JS -
 * reads it without a special case; only the room figures are null. It is the
 * treatment a centre Kidsoft would not answer for already gets, for the same
 * reason: **the app does not know how full a room is, so it says so rather than
 * printing a zero that looks measured.**
 *
 * The QUEUE and the WORK are read exactly as they are for any other centre. They
 * are not year-scoped, they are real, and for an unopened centre they are the
 * only thing there is - see centre_not_open_yet().
 */
function dash_centre_unopened(array $centre, int $year, array $notOpen, array $opts = []): array {
    $centreId = (int)$centre['id'];

    // The same query dash_centre() runs, and the only figures on this row that
    // are not null.
    $q = dbx()->query(
        'SELECT COUNT(*) AS open_n,
                ROUND(AVG(DATEDIFF(CURDATE(), date_added))) AS avg_wait
           FROM waitlist_applications
          WHERE centre_id = ? AND status = "waitlisted"',
        [$centreId], 'i'
    )->fetch();

    $row = [
        'centre_id'  => $centreId,
        'centre'     => (string)$centre['name'],
        'trading'    => false,
        'state'      => 'unopened',
        'counts'     => false,
        'opens_on'   => $centre['opens_on'],
        'opens_disp' => syd_date((string)$centre['opens_on']),
        'not_open'   => $notOpen,
        'children'   => 0,
        'placed'     => 0,
        'capacity'   => 0,
        'free'       => null,
        'open_cells' => null,
        'pct'        => null,
        'waiting'    => (int)($q['open_n'] ?? 0),
        'avg_wait'   => $q['avg_wait'] !== null ? (int)$q['avg_wait'] : null,
        'unreachable'=> false,
        'fill_types' => null, 'fill_days' => null,
        'fill_days_cap' => null, 'fill_days_placed' => null, 'fill_rooms' => null,
    ];
    if (!empty($opts['work'])) {
        $row['work'] = work_pipeline($centreId, null);
        $row['work_open'] = 0;
        foreach ($row['work'] as $st) if ($st['open']) $row['work_open'] += $st['n'];
        $row['work_overdue'] = count(work_list($centreId, null, ['overdue' => true]));
    }
    return $row;
}

/**
 * The whole dashboard.
 *
 * @param ?int $centreId null = the whole group
 */
function dash_load(?int $centreId, int $year): array {
    $centres = centres_all();
    if ($centreId !== null) {
        $centres = array_values(array_filter($centres, static fn($c) => (int)$c['id'] === $centreId));
        if (!$centres) throw new InvalidArgumentException('unknown centre');
    }

    $rows = [];
    foreach ($centres as $c) $rows[] = dash_centre($c, $year, ['work' => true]);

    // Group totals over TRADING centres only. A closed centre's 385 empty
    // place-days are not places anybody can sell.
    $t = ['free' => 0, 'capacity' => 0, 'placed' => 0, 'children' => 0,
          'open_cells' => 0, 'waiting' => 0,
          'work_open' => 0, 'work_overdue' => 0, 'not_trading' => 0, 'unreachable' => 0];
    foreach ($rows as $r) {
        // The queue and the work exist whether or not the rooms are trading.
        $t['waiting']      += $r['waiting'];
        $t['work_open']    += $r['work_open']    ?? 0;
        $t['work_overdue'] += $r['work_overdue'] ?? 0;
        // Counted, reported as not open, or reported as unknown. A centre
        // OPENING this year is counted - see centre_year_state() - so the test
        // is the state's own, never `trading` again.
        if ($r['state'] === 'unreachable') { $t['unreachable']++; continue; }
        if (!$r['counts'])                 { $t['not_trading']++; continue; }
        $t['free']       += $r['free'];
        $t['capacity']   += $r['capacity'];
        $t['placed']     += $r['placed'];
        $t['children']   += $r['children'];
        $t['open_cells'] += $r['open_cells'];
    }
    $t['pct'] = board_pct($t['placed'], $t['capacity']);

    // The pipeline snapshot, summed across whatever is in scope (all years).
    $pipeline = [];
    foreach (WORK_STAGES as $k => $meta) {
        $n = 0;
        foreach ($rows as $r) $n += $r['work'][$k]['n'] ?? 0;
        $pipeline[] = ['stage' => $k, 'label' => $meta['label'], 'help' => $meta['help'],
                       'n' => $n, 'open' => work_is_open($k)];
    }

    return [
        'year'      => $year,
        'centre_id' => $centreId,
        'centres'   => $rows,
        'totals'    => $t,
        'pipeline'  => $pipeline,
        'reenrolment' => dash_reenrolment($centreId),
        // Where the places to fill actually are - the weekly report to
        // management. Free: it adds up breakdowns dash_centre() already built.
        'fill'        => dash_fill_report($rows, $year),
        // What changes this fortnight (Monday of this week to Friday of next).
        // A KIDSOFT OUTAGE COSTS THIS CARD, NEVER THE PAGE - the same rule
        // dash_reenrolment() follows, and
        // it matters more here: every one of these rows is derived from bookings
        // being ABSENT next week, so a month that would not load looks exactly
        // like a centre emptying. week_ahead() reports that centre as a gap
        // rather than as a list of departures; this catch is for everything
        // outside it.
        'week'        => (static function () use ($centreId) {
            try { return week_ahead($centreId); }
            catch (Throwable $e) { return ['error' => $e->getMessage(), 'changes' => []]; }
        })(),
        // GROUP VIEW ONLY: how many DISTINCT families those applications are.
        // Nothing else on this screen can tell the difference, and on the group
        // view the difference is real - measured 10 Aug 2026, 723 open
        // applications belong to 626 children, 74 of whom are waiting at more
        // than one centre. Summing seven centres' queues therefore overstates
        // the families the organisation is holding by ~15%, and "ring them once,
        // take them off three other queues" is only visible from up here. Per
        // centre it is not computed. NOT because a centre's own queue holds no
        // duplicates - this comment said so, unmeasured, and on 6 Nov 2026 four
        // centres held a child applied for twice (see waitlist_duplicates(), which
        // flags them for archiving in Kidsoft). The difference per centre is a
        // handful of rows the flag already points at, not a figure worth a tile.
        // 0.04s - it reuses waitlist_for_centre().
        'queue' => $centreId === null ? dash_queue_group() : null,
    ];
}

/**
 * THE SAME REPORT, FOR THE YEAR AHEAD - and it is a different question, not one
 * question asked twice.
 *
 * Asked for 16 Sep 2026. This year's report says where a place is standing
 * empty RIGHT NOW; next year's says where the re-enrolment round has not sold
 * the room yet, and measured the same day the two point at opposite ends of the
 * building:
 *
 *   tier                2026 free / filled     2027 free / filled
 *   Little Angels          5 /  98.7%            223 /  38.6%
 *   Mighty Meteors        46 /  92.7%             43 /  89.1%
 *   group                117 /  95.7%            607 /  73.0%
 *
 * Little Angels is the FULLEST tier in this year and the emptiest by a distance
 * in the next, because next year's babies have not been enrolled yet and this
 * year's are moving up. A marketing spend aimed at 2026 alone would have been
 * aimed at the wrong end of the building.
 *
 * IT IS FETCHED ON ITS OWN REQUEST, not folded into dash_load(). One more board
 * per centre is 1.8s on a group load that already takes 4.2s, and the screen the
 * team opens the day on must not get 40% slower for a card below the fold. The
 * same treatment the work list and the tours list already get.
 *
 * NOT A SECOND HERO, and it must never become one. Every percentage elsewhere on
 * this screen is THIS year; this card names its own year in its own title, which
 * is the rule that stands in for the year picker this screen deliberately has
 * no room for.
 */
function dash_fill_next(?int $centreId, int $year): array {
    $centres = centres_all();
    if ($centreId !== null) {
        $centres = array_values(array_filter($centres, static fn($c) => (int)$c['id'] === $centreId));
        if (!$centres) throw new InvalidArgumentException('unknown centre');
    }
    $rows = [];
    // No 'work' => the work layer is not year-scoped and is already on the page.
    foreach ($centres as $c) $rows[] = dash_centre($c, $year + 1);
    return dash_fill_report($rows, $year + 1);
}

/**
 * PLACES TO FILL, by room tier and by centre - the weekly report to management.
 *
 * Asked for by Broc, 15 Sep 2026: "a report I need to supply weekly to
 * management that included the available positions broken up by age groups and
 * centres, and then a combined total. e.g. 4 3-4 in TUG need filling, and 6
 * Mondays across centres, so we know where to keep the marketing/focus."
 *
 * TWO CUTS OF ONE NUMBER, because the report is asked two different questions.
 * The matrix says WHICH ROOM AT WHICH CENTRE to market; the weekday row says
 * WHICH DAY, and that one is invisible in the matrix because it runs across it.
 * Measured 15 Sep 2026 over 115 free place-days group-wide: Mon 32, Tue 15,
 * Wed 16, Thu 16, Fri 35 - **Mondays and Fridays are 58% of the entire gap**,
 * which no room-by-centre table can show and which is exactly the kind of thing
 * a marketing spend should know.
 *
 * THE TIER IS THE ROOM NAME, NOT THE LICENSED AGE BAND, and that was measured
 * rather than assumed - see room_type_key(). Kidsoft has no age-group field at
 * all, and bucketing by band files Kanwal's ten free Shining Stars places (that
 * room is licensed 24-60m) under 3-5 year olds.
 *
 * A CLOSED CENTRE AND AN UNREACHABLE ONE ARE BOTH ABSENT FROM THE MATRIX, and
 * they are counted separately so the reader can tell which. Warnervale's 385
 * empty place-days a week are 77% of the group's raw free figure and none of
 * them is a place anybody can sell; a Kidsoft outage is a gap in the data. Same
 * three states, same three treatments, as everywhere else on this screen.
 *
 * Costs nothing: `dash_centre()` has already built each centre's breakdown off
 * the board it loaded anyway, so this only adds up.
 *
 * @param array $rows dash_centre() rows
 */
function dash_fill_report(array $rows, int $year): array {
    $types = room_types();

    $centres = [];
    $totType = array_fill_keys(array_keys($types), 0);
    $totDays = array_fill_keys(array_keys(WEEKDAYS), 0);
    // The other half of every total on this report: how many spots there ARE.
    $totDaysCap = array_fill_keys(array_keys(WEEKDAYS), 0);
    $tierPlaced = $tierCap = array_fill_keys(array_keys($types), 0);
    $grand   = 0;
    $notTrading = $unreachable = 0;

    foreach ($rows as $r) {
        if ($r['fill_types'] === null) {
            // Three states, three treatments - see centre_year_state().
            if ($r['state'] === 'unreachable') $unreachable++; else $notTrading++;
            continue;
        }
        $byType = array_fill_keys(array_keys($types), 0);
        foreach ($r['fill_types'] as $k => $v) {
            // A tier room_types() has never seen cannot happen (both read the
            // same active rooms) but must not be silently dropped if it ever
            // does - a place nobody can see is worse than an unlabelled row.
            if (!isset($byType[$k])) { $byType[$k] = 0; $totType[$k] = $totType[$k] ?? 0; }
            $byType[$k]  += (int)$v['free'];
            $totType[$k]  = ($totType[$k] ?? 0) + (int)$v['free'];
            // Placed and capacity accumulate ACROSS CENTRES so the tier's % is
            // the group's, not an average of six centres' percentages - which
            // would weight Niagara Park's five rooms the same as Tuggerah's
            // eleven and is the classic way to get a wrong total.
            $tierPlaced[$k] = ($tierPlaced[$k] ?? 0) + (int)$v['placed'];
            $tierCap[$k]    = ($tierCap[$k]    ?? 0) + (int)$v['capacity'];
        }
        $total = array_sum($byType);
        foreach ($r['fill_days'] as $wd => $v) $totDays[$wd] += (int)$v;
        foreach (($r['fill_days_cap'] ?? []) as $wd => $v) $totDaysCap[$wd] += (int)$v;
        $grand += $total;

        // The centre's own fill, summed from its ROOMS so the single-centre
        // view's footer is the same arithmetic as the rows above it.
        $cPlaced = $cCap = 0;
        foreach ($r['fill_rooms'] as $rm) { $cPlaced += $rm['placed']; $cCap += $rm['capacity']; }

        // Each weekday's Filled %, for the All row of the single-centre matrix
        // (6 Nov 2026) - the same board_pct() as the rooms above it.
        $pctDay = [];
        foreach ($r['fill_days_cap'] as $wd => $cap) {
            $pctDay[$wd] = board_pct((int)($r['fill_days_placed'][$wd] ?? 0), (int)$cap);
        }

        $centres[] = [
            'centre_id' => $r['centre_id'],
            'centre'    => $r['centre'],
            'by_type'   => $byType,
            'by_day'    => $r['fill_days'],
            'cap_by_day'=> $r['fill_days_cap'],
            'pct_by_day'=> $pctDay,
            'rooms'     => $r['fill_rooms'],
            'total'     => $total,
            // The spots this centre HAS, against the ones it has to fill. Summed
            // from the same rooms as `pct`, so the count and the percentage
            // cannot come from different populations.
            'capacity'  => $cCap,
            'pct'       => board_pct($cPlaced, $cCap),
        ];
    }

    // THE CENTRE BAR'S ORDER (Broc, 27 Oct 2026), which $rows already arrive in
    // from centres_all(). It was emptiest first, so the columns reshuffled every
    // week; a centre that is not open is not in the matrix at all (above), so
    // there is nothing to sink.

    // A TIER THE ORGANISATION RUNS IS A ROW, FULL OR NOT (16 Sep 2026).
    //
    // This dropped any tier with nothing free anywhere, on the grounds that the
    // report should be as tall as the work is. That was written before the
    // Filled column existed, when such a row genuinely was nothing but dashes.
    // It is not any more: reported from the centre view, "Tuggerah Places to
    // Fill is missing Little Angels" - the room was 100.0% sold and vanished,
    // and a room silently absent reads as a room that is not there rather than
    // a room with nothing left to sell. The two are opposite facts and this is
    // a report somebody hands to management.
    //
    // The test is therefore CAPACITY, not vacancies: a tier only some other
    // centre runs, or one that exists only at a centre that is shut, still
    // contributes no capacity here and still drops out - which is the row that
    // would have been all dashes.
    $shown = array_values(array_filter($types, static fn($t) => ($tierCap[$t['key']] ?? 0) > 0));

    // HOW FULL EACH TIER IS, group-wide. The count says how much is left to
    // sell; only this says whether that is a lot. Measured 15 Sep 2026: Mighty
    // Meteors carries 46 of the group's 115 free place-days AND the lowest fill
    // of any tier, so the two agree - but Little Angels' 5 free places are a
    // higher share of a much smaller tier than the count suggests, which is
    // exactly the comparison a marketing decision needs and a column of counts
    // cannot make.
    $pctType = [];
    foreach ($totType as $k => $_) $pctType[$k] = board_pct($tierPlaced[$k] ?? 0, $tierCap[$k] ?? 0);

    return [
        'year'        => $year,
        'types'       => $shown,          // youngest first
        'centres'     => $centres,        // the centre bar's order
        'by_type'     => $totType,
        // HOW MANY SPOTS EACH TIER HAS, group-wide - the denominator of every
        // margin on this report. It is $tierCap, the SAME figure the tier
        // percentage is computed from, rather than a second count: "5 of 380"
        // and "98.7%" sitting in one row must not be able to disagree.
        'cap_by_type' => $tierCap,
        'pct_by_type' => $pctType,
        // The overall figure the tier percentages sit under, through the SAME
        // board_pct() as the hero - so the footer of this report and the number
        // at the top of the screen cannot disagree.
        'pct'         => board_pct(array_sum($tierPlaced), array_sum($tierCap)),
        'by_day'      => $totDays,
        'cap_by_day'  => $totDaysCap,
        'total'       => $grand,
        // The one figure the whole card comes down to: places to fill out of
        // places there are. Same denominator as `pct` above it.
        'capacity'    => array_sum($tierCap),
        'not_trading' => $notTrading,
        'unreachable' => $unreachable,
    ];
}

/**
 * The group queue as FAMILIES rather than applications.
 *
 * waitlist_group_all() owns the merge rule (folded name AND DOB AND a shared
 * parent mobile or email, merged transitively) and it is deliberately NOT the
 * same rule the search palette uses - see the waitlist rules in CLAUDE.md. This
 * takes its totals and nothing else; a Kidsoft outage cannot reach it, because
 * the queue lives in our own table.
 */
function dash_queue_group(): array {
    $g = waitlist_group_all();
    return [
        'families'     => (int)$g['totals']['families'],
        'applications' => (int)$g['totals']['applications'],
        'multi'        => (int)$g['totals']['multi'],
        // Deliberately NOT the pair breakdown, though waitlist_group_all() has
        // computed it by this point: which centres overlap is the All centres
        // waitlist register's job and it already ranks the pairs. A figure
        // nothing on this screen renders is a field somebody later has to work
        // out whether they may delete.
    ];
}

/**
 * The re-enrolment round: how far through it each centre is.
 *
 * THE ONLY FORWARD-LOOKING VACANCY SIGNAL IN THE API. Every other departure
 * signal this app can see is retrospective - Children.ExitDate is never
 * future-dated, enrolment EndDate/CeasedDate had zero future dates across 257
 * active children, and coverage only infers a leaver once the bookings stop. A
 * DECLINE is a place coming free next year, known now, while it can still be
 * sold.
 *
 * The gap between accepted and booked is the other half, and it is the thing
 * nobody could see before: measured 6 Aug 2026, 452 families had accepted for
 * 2027 group-wide and only 220 had a booking created, with Niagara Park at 92
 * accepted and none booked. Those families believe they have a place.
 *
 * A Kidsoft outage here costs this panel, never the page.
 */
function dash_reenrolment(?int $centreId): array {
    $centres = centres_all();
    if ($centreId !== null) {
        $centres = array_values(array_filter($centres, static fn($c) => (int)$c['id'] === $centreId));
    }
    $out = ['centres' => [], 'accepted' => 0, 'declined' => 0, 'to_chase' => 0,
            'school_age' => 0, 'place_days' => 0, 'declines_detail' => [],
            'roll' => 0, 'answered' => 0, 'cannot_stay' => 0,
            'held' => 0, 'held_days' => 0, 'chase_due' => 0, 'error' => null];
    // WHO IS RE-ENROLLING IS A QUESTION ABOUT THIS YEAR'S ROLL, so a centre that
    // has not opened yet has nobody to ask about - and asking costs several
    // Kidsoft reads per centre. The round for Warnervale's own first year will
    // appear on its own, the year after it opens, with no code change.
    $thisYear = (int)date_create('today', new DateTimeZone('Australia/Sydney'))->format('Y');
    $centres  = array_values(array_filter($centres,
        static fn($c) => !centre_not_open_yet($c, $thisYear)));

    foreach ($centres as $c) {
        try {
            $r = reenrolment_round((int)$c['id']);
            // WHO HAS NOT ANSWERED - phase one's actual work list. Kidsoft raises
            // an event only when a family accepts or declines, never when the
            // offer goes out, so silence is derived rather than read. See
            // reenrolment_outstanding().
            $o = reenrolment_outstanding((int)$c['id']);
            // A decline matched to the room and days it frees: the only
            // forward-looking vacancy signal in the API, made actionable.
            $d = reenrolment_declines((int)$c['id']);
            $r['decline_list'] = $d['declines'];
            $r['place_days']   = $d['place_days'];
            $r['to_chase']    = count($o['outstanding']) - $o['school_age'];

            // THE HELD PLACES, AND WHO IS DUE A CALL ABOUT THEM.
            //
            // A DIFFERENT LIST FROM `to_chase`, and the two must not be merged:
            // that one counts children who have not ANSWERED, this one counts
            // children with NO PLACE NEXT YEAR AND NO DECLINE - the enrolments
            // team's own test (8 Sep 2026). Measured group-wide the same day, the
            // answer-based test misses 7 families who accepted and whom nobody
            // has booked, and wrongly flags 5 who never answered but are already
            // booked. Both are reported because they answer different questions:
            // one is how far through the round the centre is, the other is which
            // places are being kept and who has to be rung about them.
            $h = reenrolment_holds((int)$c['id']);
            $r['held']      = count($h['holds']);
            $r['held_days'] = array_sum(array_map(
                static fn($x) => count($x['days']), $h['holds']));
            $r['chase_due'] = count(array_filter($h['holds'],
                static fn($x) => $x['chase_due']));
            // Named, with the dates, because a count is not a work list - the
            // team has to know who to ring and by when. Due first, soonest
            // hold expiry next; reenrolment_holds() has already sorted them.
            $r['chase_list'] = array_map(static fn($x) => [
                'name'       => $x['name'],
                'from_room'  => $x['from_room'],
                'to_room'    => $x['to_room'],
                'days'       => $x['days'],
                'hold_until' => $x['hold_until'],
                'chase_from' => $x['chase_from'],
                // FORMATTED SERVER-SIDE, like every other date this app
                // publishes: these are Sydney wall-clock strings and putting
                // them through new Date() in the browser re-reads them in the
                // viewer's own timezone. See _incl/dates.php.
                'hold_until_disp' => syd_date($x['hold_until']),
                'chase_from_disp' => syd_date($x['chase_from']),
                'chase_due'  => $x['chase_due'],
                'school_age' => $x['school_age'],
            ], array_slice($h['holds'], 0, 50));
            $r['school_age']  = $o['school_age'];
            $r['cannot_stay'] = $o['ageing_out'];
            $r['roll']        = $o['roll'];
            // THE ONLY FIGURE A PROGRESS BAR MAY BE BUILT ON, and it is not
            // `accepted` + `declined`. Those two count NOTIFICATIONS over the
            // whole year; `answered` counts children ON THE ROLL matched to an
            // answer since the round opened. Only the second satisfies
            //     roll = answered + cannot_stay + to_chase + school_age
            // exactly, by construction in reenrolment_outstanding()'s own loop.
            // Measured 10 Aug 2026 the two disagree by 1 at Tuggerah and by 4 at
            // Kanwal - a family who answered but has since left the roll, or
            // answered twice. Stacking the notification counts would produce
            // segments that silently do not sum to the bar's own denominator,
            // which is the one thing a part-to-whole mark must never do.
            $r['answered']    = $o['answered'];
        } catch (Throwable $e) {
            $out['error'] = $e->getMessage();
            continue;
        }
        $out['centres'][] = $r;
        $out['accepted'] += $r['accepted'];
        $out['declined'] += $r['declined'];
        $out['roll']        += $r['roll'];
        $out['held']       = ($out['held'] ?? 0) + $r['held'];
        $out['held_days']  = ($out['held_days'] ?? 0) + $r['held_days'];
        $out['chase_due']  = ($out['chase_due'] ?? 0) + $r['chase_due'];
        $out['answered']    += $r['answered'];
        $out['cannot_stay'] += $r['cannot_stay'];
        $out['to_chase'] = ($out['to_chase'] ?? 0) + $r['to_chase'];
        $out['place_days'] = ($out['place_days'] ?? 0) + $r['place_days'];
        foreach ($r['decline_list'] as $d) {
            $out['declines_detail'][] = $d + ['centre' => $c['name'], 'centre_id' => (int)$c['id']];
        }
        $out['school_age'] = ($out['school_age'] ?? 0) + $r['school_age'];
    }
    // THE CENTRE BAR'S ORDER (Broc, 27 Oct 2026) - centres_all() order, as
    // walked above. It was most-to-chase first. A centre not open this year is
    // filtered out before the loop, so there is nothing to sink.
    return $out;
}
