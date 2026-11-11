<?php
// MARKETING - where there are places to sell and NOBODY WAITING to take them.
//
// Asked for by Broc, 18 Sep 2026: "Identify where there is a gap between
// available spots and waitlisted children, ordered by most pressing. For
// example, if we had a room in a centre that had numerous spots across all days
// of the week, but noone was waiting to get in, we would want to start
// advertising efforts in order to reach people with children of that age-range
// to advise them that we have room available."
//
// IT IS THE SECOND HALF OF A REPORT THAT ALREADY EXISTS. The Dashboard's Places
// to fill (dash_fill_report(), 15 Sep 2026) says WHERE the free place-days are,
// by room tier and centre and weekday, and it was asked for in the same words:
// "so we know where to keep the marketing/focus". What it cannot say is whether
// anybody is already waiting for them, and that is the whole difference between
// the two jobs this organisation does with an empty place:
//
//   places free AND a queue for them  ->  make an offer. The work layer's job,
//                                         and every screen already points at it.
//   places free AND NOBODY WAITING     ->  nothing in this app can fill it.
//                                         Somebody has to go and find a family.
//
// So this module reports only the second, and the figure it is built on is the
// UNCOVERED PLACE-DAY: a place going spare on a weekday that no waiting child
// could take even if the team rang every one of them this afternoon.
//
// MEASURED BEFORE IT WAS BUILT (18 Sep 2026, all seven centres):
//
//   year  places free   uncovered   where
//   2026      124           38      Mighty Meteors (Tuggerah 17, Kanwal 10),
//                                   Amazing Asteroids (Kanwal 5, Point Clare 4)
//   2027      620           27      Amazing Asteroids Kanwal 13, Shining Stars
//                                   Point Clare 5, Amazing Asteroids Terrigal 3
//
// 124 free against a queue of 615 applications, and 38 of those place-days
// cannot be filled from the queue at all - which is exactly the shape the ask
// describes. The oldest tier is where it lives: a Mighty Meteors place is the
// hardest thing this organisation sells, because the children who would fill it
// are starting school.
//
// THE UNIT IS THE PLACE-DAY, AND THE ROW IS A CAMPAIGN.
//
// The gap is MEASURED per room x weekday cell, because that is the unit of work
// everywhere in this app and the only level at which supply and demand are
// comparable: a family wanting Mon/Wed can take one Monday place and one
// Wednesday place, and nothing else.
//
// It is REPORTED per centre x room tier, because that is what an advertising
// decision is: "we have room for three-year-olds at Kanwal", never "we have room
// on Kanwal's Thursday". The weekdays ride along on the row - they are what the
// ad copy says - but they are not what is being decided.
//
// WHY THE TIER AND NOT THE ROOM, MEASURED. Tuggerah runs three Shining Stars
// rooms, three Amazing Asteroids and two of everything else, and the same waiting
// child is age-eligible for all three of a family. Counted room by room that
// child is counted three times against three rooms' places: Tuggerah's 2027
// Shining Stars gap reads 8 place-days per room and 3 once the children are
// counted once. The tier is also the only level at which the answer is a
// campaign - there is no ad that targets Pink and not Silver.
//
// THREE THINGS THAT WOULD HAVE MADE IT LIE, ALL MEASURED:
//
// 1. A CLOSED CENTRE IS 92% OF THE GAP. Warnervale has 385 place-days a week of
//    capacity, zero children and zero bookings; unfiltered it contributes 333
//    uncovered place-days against the six trading centres' 38 put together. It
//    is not a marketing problem, it is a centre that is not open. board_trading()
//    is the same test the Dashboard and Tenure already use, and the three states
//    get the three treatments they get everywhere: counted, reported as closed,
//    reported as unknown.
//
// 2. THE QUEUE HAS TO BE SCOPED TO THE YEAR. 31 of the 615 open applications
//    cannot start until 2028 or later. Counted as demand for a 2026 place they
//    close a gap that is real: the 2026 figure reads 26 uncovered place-days
//    across 9 cells with the whole queue and 37 across 15 with the queue cut to
//    families who need a place by 31 December - a 30% understatement, and every
//    one of those place-days is a place standing empty all year. Since 6 Nov
//    2026 the cut is fill_suggestions()' `start_window` - within 90 days of the
//    place coming free and never past 31 December - the same rule as the
//    placement board's "Could take this day".
//
// 3. FREE MEANS THE BOARD'S FREE. Places held for a child who has not answered
//    re-enrolment are not for sale and must not be advertised: 137 place-days of
//    them group-wide in 2027, plus 5 under a pending offer. board_load()'s
//    `vacancies` takes all three kinds of promise off the figure (promised, held,
//    reserved) and is what the whole app means by "free"; fill_suggestions()
//    subtracts only the first two, so its own `free` reads 737 where the board
//    reads 620. THE BOARD'S FIGURE IS THE ONE USED HERE - see the note in
//    marketing_centre(). (That difference is a fault in fill.php, not a choice
//    made here, and it is recorded in _incl/docs/marketing.md.)
//
// WHAT THIS DOES NOT DO, ON PURPOSE:
//
//   - NO DOLLAR FIGURE. Not a total, not an estimate, not a rate. An uncovered
//     place-day is the unit and it always was the better one. See CLAUDE.md.
//   - NO CASUAL ANYTHING. Every read here is already casual-free: the board tests
//     the booking flag, day_requests_year() tests the booking TYPE.
//   - NO ADVICE. The rows carry figures, not sentences. The team runs these
//     centres and knows what an empty Mighty Meteors Friday means; a caption
//     explaining it is noise that ages into something wrong.

declare(strict_types=1);

require_once __DIR__ . '/board.php';
require_once __DIR__ . '/fill.php';
require_once __DIR__ . '/day-requests.php';
require_once __DIR__ . '/internal-requests.php';
require_once __DIR__ . '/occupancy.php';   // year_weekday_dates() for the year's own weeks
require_once __DIR__ . '/users.php';

/**
 * Every centre x room tier with a place to sell, ranked by what nobody is
 * waiting for.
 *
 * SCOPE IS RE-READ HERE, not taken from the caller - the rule
 * support_plans_list() follows. $centreId 0 means every centre this user may
 * see, which for a supervisor is their own; the endpoint still refuses a group
 * request from one, and this is the second line rather than the only one.
 *
 * @param int $centreId 0 = every centre the user may see
 */
function marketing_load(int $centreId, int $year): array {
    $ids = $centreId ? [$centreId] : user_centre_ids();

    $rows = [];
    $unopened = [];      // opens after this year ends: named with its date
    $closed = [];        // open, and nobody in it at all: the alarm
    $unreachable = [];   // Kidsoft would not answer
    $queueIds = [];      // distinct applications in scope, for the summary
    $measured = 0;       // centres genuinely trading and genuinely read
    $priced = 0;         // tiers with a capacity for this year to measure against
    $capacity = 0;       // every place the counted centres hold, full tiers included

    foreach (centres_all() as $c) {
        $cid = (int)$c['id'];
        if (!in_array($cid, $ids, true)) continue;

        try {
            $one = marketing_centre($c, $year);
        } catch (Throwable $e) {
            // ONE CENTRE FAILING MUST NOT TAKE THE SCREEN DOWN, and it must be
            // SAID rather than swallowed: a centre missing from a ranked list
            // reads as a centre with nothing to advertise, which is the opposite
            // of "we could not ask". Same three states, same three treatments,
            // as the Dashboard.
            $unreachable[] = (string)$c['name'];
            continue;
        }

        // A CENTRE THAT HAS NOT OPENED IS NOT A CENTRE WITH NOTHING TO SELL, and
        // until 21 Sep 2026 this screen could not tell the two apart - both
        // arrived as "no children booked". Warnervale is the case: excluded from
        // 2026 because it does not open until January, and RANKED in 2027
        // because by then it has 385 place-days a week and nobody in them.
        if ($one['state'] === 'unreachable') { $unreachable[] = (string)$c['name']; continue; }
        if ($one['state'] === 'unopened') {
            $unopened[] = ['centre' => (string)$c['name'],
                           'opens'  => syd_date((string)$c['opens_on'])];
            continue;
        }
        if ($one['state'] === 'empty') { $closed[] = (string)$c['name']; continue; }

        $measured++;
        $priced   += $one['priced'];
        $capacity += $one['capacity'];
        foreach ($one['rows'] as $r) $rows[] = $r;
        foreach ($one['queue_ids'] as $id) $queueIds[$id] = true;
    }

    // MOST PRESSING FIRST, and every key is a measured count rather than a score
    // nobody can check:
    //
    //   1. uncovered place-days, most first. The size of the hole the queue
    //      cannot fill is the whole question this screen was asked.
    //   2. then NOBODY WAITING AT ALL ahead of a tier with some queue behind it.
    //      Two tiers five place-days short are not the same job when one has
    //      eleven families to ring first and the other has none.
    //   3. then the most places free, because a tier with no gap today and 34
    //      places free is the one to watch next.
    //   4. then centre and tier name, so the order is stable between loads.
    usort($rows, static function ($a, $b) {
        if ($a['gap'] !== $b['gap']) return $b['gap'] <=> $a['gap'];
        $na = $a['waiting'] === 0 ? 0 : 1;
        $nb = $b['waiting'] === 0 ? 0 : 1;
        if ($na !== $nb) return $na <=> $nb;
        if ($a['free'] !== $b['free']) return $b['free'] <=> $a['free'];
        return [$a['centre'], $a['tier_label']] <=> [$b['centre'], $b['tier_label']];
    });

    // THE SUMMARY IS COUNTED OFF EVERY ROW, and the group LIST is then cut down
    // to the rows with a gap - so the two must be computed in this order.
    $summary = marketing_summary($rows, count($queueIds), $capacity);

    // THE ALL CENTRES VIEW IS AN OVERVIEW, NOT AN UNFILTERED DUMP (21 Sep 2026,
    // at Broc's request). Under a heading that says "Where to advertise", a row
    // with places free and a queue that covers them answers "do not advertise
    // here" - and there were 19 of them in 2027's 29 and 13 of 2026's 18, so
    // two thirds of the group table was the answer the screen is NOT about.
    // Worse, the rows it pushed off the first screenful are the ones it exists
    // for.
    //
    // NOTHING IS LOST, because the count was already on the screen: the "Age
    // groups to advertise" tile reads "10 of 29 groups with places free", which
    // is exactly "what is listed, out of what was measured". A reader who wants
    // the covered tiers opens that centre's own tab, where the matrix still
    // carries every tier - there the reader is asking about ONE centre and the
    // full picture IS the answer, which is the group-is-a-different-shape rule
    // in CLAUDE.md rather than one table filtered two ways.
    //
    // `summary['rows']` above still counts all of them, and so do the three
    // cuts, so the denominators and the by-centre / by-age-group / by-weekday
    // figures are unchanged by this line.
    if ($centreId === 0) {
        $rows = array_values(array_filter($rows, static fn($r) => $r['gap'] > 0));
    }

    return [
        'year'        => $year,
        'centre_id'   => $centreId,
        'rows'        => $rows,
        'summary'     => $summary,
        // HOW MANY CENTRES WERE ACTUALLY MEASURED, and it is not derivable from
        // the rows: a trading centre with every place sold contributes none.
        // Without it an empty list cannot be told from a group where every
        // centre is shut, which is the confident lie the Dashboard's fill card
        // told on its first look at Warnervale - 385 empty place-days reported
        // as "Every place is filled."
        'measured'    => $measured,
        // AND HOW MANY OF THEIR TIERS HAVE A CAPACITY FOR THIS YEAR. Nothing
        // else can tell a sold-out year from one Kidsoft holds no operational
        // positions for: both produce an empty list. The nightly job reads this
        // year and next only, so 2028 legitimately has none - and "every place
        // is filled in 2028" would be a confident lie about 2,725 place-days
        // nobody has measured. A year with no capacity is UNKNOWN, never zero,
        // which is the rule room_year_capacity has carried from the start.
        'priced'      => $priced,
        // WHICH CENTRES ARE NOT IN THE RANKING AND WHY. A banner, not a caption:
        // whether a centre is absent because it has not opened, because it is
        // empty, or because Kidsoft would not answer is exactly the thing the
        // team cannot know unaided, and the three mean different things. The
        // first is a fact with a date on it; the last two are problems.
        'unopened'    => $unopened,
        'closed'      => array_values(array_unique($closed)),
        'unreachable' => array_values(array_unique($unreachable)),
    ];
}

/**
 * One centre's tiers for a year.
 *
 * @return array{trading:?bool, rows:array, queue_ids:array}
 */
function marketing_centre(array $centre, int $year): array {
    $cid   = (int)$centre['id'];
    $board = board_load($cid, $year);

    // Not open yet, empty, or unreadable - the caller reports each of those
    // rather than ranking it. centre_year_state() is the one implementation, in
    // board-live.php, and a centre OPENING later this year is ranked like any
    // other: its places are real and unsold, which is the whole subject here.
    $state = centre_year_state($board);
    if (!centre_state_counts($state)) {
        return ['state' => $state, 'rows' => [], 'queue_ids' => [], 'priced' => 0];
    }

    // WHO COULD TAKE A FREE PLACE - fill_suggestions(), which owns that question
    // for the whole app. Marketing does not get its own eligibility rules: the
    // age band, the age at the date the family actually needs care, and the
    // sub-quotas are all tested in one place, and a shortlist on the placement
    // board that disagrees with this screen about who is waiting would be two
    // answers to one question.
    //
    // `start_window` is the board's own rule (6 Nov 2026): a family counts as
    // waiting for a place only if they start within 90 days of it coming free,
    // and never in a later year. It replaced `starts_by` = 31 December, the year
    // cut measured in the header of this file, which it still contains. Until
    // then Enzo Galli, starting 12/07/2027, covered a 2027 Tuggerah place here
    // while the board had stopped offering it to him - the two answers to one
    // question the note above forbids. `limit` is high because this counts
    // rather than lists: the default of 5 would cap a room's demand at five
    // families and report a gap that is not there.
    $fill = fill_suggestions($cid, $year, [
        'board'        => $board,
        'limit'        => 500,
        'start_window' => FILL_START_WINDOW_DAYS,
    ]);

    // Families already in care who have asked for a day - the OTHER demand, and
    // Kidsoft holds half of it. They have preference over an external applicant
    // (see the no-upselling rule), so a place somebody has already asked for is
    // not a place to advertise. A Kidsoft outage costs this half and nothing
    // else: demand we cannot read makes the gap look BIGGER, which is the
    // direction that wastes money rather than the one that loses a child, so it
    // is reported as a gap in the data by the caller.
    $requests = [];
    try { $requests = day_requests_year($cid, $year); }
    catch (Throwable $e) { $requests = []; }
    $wishes = internal_requests_year($cid, $year, ['contacts' => false]);

    // Rooms in tier order, with the tier's own age span at THIS centre - Kanwal's
    // Shining Stars is licensed 24-60 months where every other centre's stops at
    // 36, so the span is read per centre rather than taken from room_types().
    $rooms = [];
    foreach ($fill['rooms'] as $r) $rooms[(int)$r['id']] = $r;

    $tiers = [];
    foreach ($board['rooms'] as $r) {
        $rid = (int)$r['id'];
        $key = room_type_key((string)$r['name']);
        $tiers[$key]['rooms'][] = ['id' => $rid, 'name' => (string)$r['name']];
        $tiers[$key]['cells'][$rid] = $r['cells'];
        foreach (['min_age_months' => 'min', 'max_age_months' => 'max'] as $col => $k) {
            $v = $r[$col] === null ? null : (int)$r[$col];
            if ($v === null) continue;
            $tiers[$key][$k] = $k === 'min'
                ? min($tiers[$key][$k] ?? $v, $v)
                : max($tiers[$key][$k] ?? $v, $v);
        }
    }

    $labels   = room_types();
    // How many of each weekday this centre has left to sell in this year - from
    // the day it OPENS, where that is still ahead. See marketing_weekdays_left().
    $left     = marketing_weekdays_left($year, centre_year_start($centre, $year));
    // EVERY PLACE THE CENTRE HAS, including the tiers that are full and are
    // therefore dropped from the rows below. It is the denominator of the
    // "Places free" tile, and it has to be the SAME population the Dashboard
    // measures or the two screens print one fact as two numbers: measured 21 Sep
    // 2026, summing only the listed tiers gave "124 of 2,100" here against the
    // Dashboard's "124 of 2,725" for the same group, same year.
    $centreCapacity = 0;
    $out      = [];
    $queueIds = [];
    // TIERS WITH A CAPACITY TO MEASURE AGAINST, counted separately from the rows.
    // A year Kidsoft holds no operational positions for - and the nightly job
    // reads only this year and next - produces no rows at all, which is
    // indistinguishable from a year that is sold out unless somebody counts
    // this. See the note on the return of marketing_load().
    $priced   = 0;

    foreach ($tiers as $key => $t) {
        $free = $freeDays = $gapDays = $waitDays = [];
        $gap = 0; $capacity = 0;
        $waiting = [];    // distinct applications, across the week
        $asked   = [];    // distinct children already in care, across the week

        foreach (array_keys(WEEKDAYS) as $wd) {
            $supply = 0;
            $wantWd = [];   // waitlist ids that could take a place in this tier today
            $askWd  = [];   // children in care who have asked for this day

            foreach ($t['rooms'] as $room) {
                $rid  = $room['id'];
                $cell = $t['cells'][$rid][$wd] ?? null;
                // UNKNOWN CAPACITY IS NOT ZERO. No operational positions read for
                // a weekday means the room is closed that day or was never read,
                // and either way there is nothing to advertise and nothing to
                // count - see capacity_sync_year().
                if ($cell === null || $cell['capacity'] === null) continue;
                $capacity += (int)$cell['capacity'];

                $v = (int)$cell['vacancies'];
                if ($v <= 0) continue;
                $supply += $v;

                // DEMAND IS ONLY COUNTED WHERE THERE IS SUPPLY, in both
                // directions. A child who fits only a room that is full cannot
                // take this tier's spare place, and a room that is full
                // contributes no place for them to take - so the same rooms
                // decide both halves of the comparison.
                foreach ($rooms[$rid]['cells'][$wd]['candidates'] ?? [] as $cand) {
                    if (empty($cand['eligible'])) continue;
                    $wantWd[(int)$cand['waitlist_id']] = true;
                }
                // ONE FAMILY, ONE ROW, the rule cell_merge_requests() owns: a
                // parent who booked a Wednesday in the portal AND rang the centre
                // about Wednesdays is one family wanting one day. Keyed on the
                // ChildID both sources carry, and on our own row for the
                // provisional child Kidsoft has never heard of.
                foreach (array_merge($requests[$rid][$wd] ?? [], $wishes[$rid][$wd] ?? []) as $q) {
                    $askWd[$q['kidsoft_child_id'] !== null
                        ? 'k' . $q['kidsoft_child_id']
                        : 'i' . (int)($q['id'] ?? 0)] = true;
                }
            }

            $demand = count($wantWd) + count($askWd);
            $freeDays[$wd] = $supply;
            $waitDays[$wd] = $demand;
            $gapDays[$wd]  = max(0, $supply - $demand);
            $gap          += $gapDays[$wd];
            $free[$wd]     = $supply;
            foreach ($wantWd as $id => $_) $waiting[$id] = true;
            foreach ($askWd as $id => $_) $asked[$id] = true;
        }

        foreach ($waiting as $id => $_) $queueIds['w' . $id] = true;

        if ($capacity > 0) $priced++;
        $centreCapacity += $capacity;

        $freeTotal = array_sum($freeDays);
        // NOTHING TO SELL IS NOT A MARKETING ROW. A tier that is full has no
        // place to advertise, and a ranked list of campaigns is not the place to
        // restate what the placement board and the Dashboard's Places to fill
        // both already show. A tier with places free and no gap DOES stay -
        // "34 free, 93 waiting" is the answer to "should we advertise for babies
        // at Tuggerah", and dropping it would leave the reader unable to tell a
        // tier with a queue from a tier this screen simply did not look at.
        if ($freeTotal <= 0) continue;

        $out[] = [
            'centre_id'  => $cid,
            'centre'     => (string)$centre['name'],
            'tier'       => $key,
            'tier_label' => $labels[$key]['label'] ?? ucfirst($key),
            // The rooms behind the row, because the campaign is a tier and the
            // place is a room: somebody has to know which door the child comes in.
            'rooms'      => $t['rooms'],
            'min_age_months' => $t['min'] ?? null,
            'max_age_months' => $t['max'] ?? null,
            'capacity'   => $capacity,
            'free'       => $freeTotal,
            'free_days'  => $freeDays,
            // CHILDREN, not place-days: one family wanting three days is one
            // phone call. The per-weekday counts are the ones the gap is computed
            // from, and they are on the row so the strip can show both.
            'waiting'      => count($waiting),
            'waiting_days' => $waitDays,
            'asked'        => count($asked),
            'gap'          => $gap,
            'gap_days'     => $gapDays,
            // WHAT THE GAP COSTS BETWEEN NOW AND DECEMBER, counted on this
            // centre's OWN calendar. A centre that opens in November has two
            // months of the year to sell, not twelve, so the weekdays are
            // counted from the later of today and the day it opens - otherwise
            // an opening centre's gap would be multiplied by a year it will not
            // be trading for. centre_year_start() returns null for a centre that
            // was already open, which means the whole year.
            'place_days'   => array_sum(array_map(
                static fn($wd) => $gapDays[$wd] * $left[$wd], array_keys(WEEKDAYS))),
        ];
    }

    return ['state' => $state, 'rows' => $out, 'queue_ids' => array_keys($queueIds),
            'priced' => $priced, 'capacity' => $centreCapacity];
}

/**
 * The figures above the list, counted off the same rows the list renders.
 *
 * THE WEEKS ARE THE POINT OF `place_days`. A place standing empty on a Friday is
 * not one place-day, it is every remaining Friday of the year - 39 uncovered
 * place-days a week at the 2026 figures is 565 place-days between September and
 * Christmas, which is the scale a marketing spend is decided against. Each row
 * brings its own, counted on its own centre's calendar from the later of today
 * and the day that centre opens; summing them here is the only honest way to
 * total a group where one centre has twelve months of the year to sell and
 * another has two.
 *
 * `$capacity` ARRIVES FROM THE CALLER and is not counted off the rows, because
 * the rows are only the tiers with something free: a tier that is full is
 * dropped from the list and its places still exist. Counted off the rows this
 * printed "124 of 2,100" beside the Dashboard's "124 of 2,725" for the same
 * group and the same year - one fact, two numbers, which is the disagreement
 * this app keeps having to hunt down. Both now come from the same centres.
 */
function marketing_summary(array $rows, int $queue, int $capacity): array {
    $gap = $free = 0;
    $gapDays  = array_fill_keys(array_keys(WEEKDAYS), 0);
    $freeDays = array_fill_keys(array_keys(WEEKDAYS), 0);
    $targets  = 0; $nobody = 0;
    $centres  = [];
    $byCentre = [];   // centre_id => gap + free, for the by-centre cut
    $byTier   = [];   // tier key  => gap + free, for the by-age-group cut

    foreach ($rows as $r) {
        $gap      += $r['gap'];
        $free     += $r['free'];
        $centres[$r['centre_id']] = true;
        if ($r['gap'] > 0) $targets++;
        if ($r['gap'] > 0 && $r['waiting'] === 0) $nobody++;
        foreach ($r['gap_days'] as $wd => $n)  $gapDays[$wd]  += $n;
        foreach ($r['free_days'] as $wd => $n) $freeDays[$wd] += $n;

        // THE TWO CUTS THE RANKING CANNOT MAKE, for the same reason the weekday
        // one exists: they run ACROSS the rows. Measured 21 Sep 2026, each says
        // something no reader could get off the table in front of them -
        // Warnervale is 332 of 2027's 359 uncovered place-days while holding
        // only the top four rows of twenty-nine, and 2026's gap is 28 of 38 in
        // the two OLDEST tiers against nothing at all in the two youngest, which
        // is the opposite shape to 2027 and the whole reason this screen has a
        // year stepper.
        $c = (int)$r['centre_id'];
        $byCentre[$c]['centre'] = (string)$r['centre'];
        $byCentre[$c]['gap']    = ($byCentre[$c]['gap']  ?? 0) + $r['gap'];
        $byCentre[$c]['free']   = ($byCentre[$c]['free'] ?? 0) + $r['free'];

        $t = (string)$r['tier'];
        $byTier[$t]['label'] = (string)$r['tier_label'];
        $byTier[$t]['gap']   = ($byTier[$t]['gap']  ?? 0) + $r['gap'];
        $byTier[$t]['free']  = ($byTier[$t]['free'] ?? 0) + $r['free'];
    }

    // BOTH CUTS KEEP THEIR NATURAL ORDER rather than ranking themselves. The
    // centres run in the order of the centre bar the reader is looking at, so
    // the strip and the tabs above it name things in the same sequence; the
    // tiers run youngest to oldest, which is room_types()' own order and is what
    // makes "the gap lives in the oldest rooms" readable straight off the strip
    // rather than having to be said in a caption. A strip that re-sorted itself
    // every year would be a second ranking arguing with the table under it.
    $order = [];
    foreach (centres_all() as $i => $c) $order[(int)$c['id']] = $i;
    uksort($byCentre, static fn($a, $b) => ($order[$a] ?? PHP_INT_MAX) <=> ($order[$b] ?? PHP_INT_MAX));

    $tierOrder = array_flip(array_keys(room_types()));
    uksort($byTier, static fn($a, $b) => ($tierOrder[$a] ?? PHP_INT_MAX) <=> ($tierOrder[$b] ?? PHP_INT_MAX));

    // SUMMED FROM THE ROWS, not multiplied out here. Each row is counted on its
    // own centre's calendar, and the moment one centre in the group opens
    // mid-year there is no single "weekdays left" that is true of all of them.
    $placeDays = 0;
    foreach ($rows as $r) $placeDays += $r['place_days'];

    return [
        'gap'        => $gap,
        'free'       => $free,
        'capacity'   => $capacity,
        'queue'      => $queue,
        'rows'       => count($rows),
        'centres'    => count($centres),
        // Tiers with a gap, and the ones with NOBODY behind them at all. Two
        // facts and not one total: "five place-days short with eleven families
        // waiting" and "five place-days short with nobody waiting" are different
        // jobs, and summing them would hide the one this screen exists for.
        'targets'    => $targets,
        'nobody'     => $nobody,
        'gap_days'   => $gapDays,
        'free_days'  => $freeDays,
        // THE ORGANISATION-WIDE CUTS. Counted off EVERY row, including the ones
        // with no gap that the group list drops - a centre or a tier whose free
        // places are all covered is a real "0 of 46 free" and is the answer to
        // "should we be advertising there", which a strip that only saw the
        // listed rows could not give. Keys are dropped: the browser gets ordered
        // lists and draws them in the order they arrive.
        'by_centre'  => array_values($byCentre),
        'by_tier'    => array_values($byTier),
        'place_days' => $placeDays,
    ];
}

/**
 * How many of each weekday the year has left, counting today - or, for a centre
 * that has not opened yet, counting from the day it does.
 *
 * Sydney's own clock, from the server. A date decided in the viewer's browser is
 * the reinterpretation _incl/dates.php forbids, and this one decides the biggest
 * figure on the screen.
 *
 * @param ?string $from yyyy-mm-dd, a day later than today - a centre's opening
 *                      date. Null, or a date already past, means count from today.
 */
function marketing_weekdays_left(int $year, ?string $from = null): array {
    $today = date_create('today', new DateTimeZone('Australia/Sydney'))->format('Y-m-d');
    if ($from !== null && $from > $today) $today = $from;
    $out = [];
    foreach (year_weekday_dates($year) as $wd => $dates) {
        $out[$wd] = count(array_filter($dates, static fn($d) => $d >= $today));
    }
    return $out;
}
