<?php
// The room x weekday CELL for a CALENDAR YEAR - the app's unit of work.
//
// WHY A YEAR AND NOT A WEEK. The enrolments team sells a place for the year: a
// family booked on Mondays pays for that Monday whether or not the child turns
// up, so the job is to fill every room, every weekday, for the whole year. The
// grid used to be driven by a week picker, and it was measurably the wrong axis
// - comparing two weeks ten weeks apart at Tuggerah, all 55 room x weekday
// cells had IDENTICAL capacity, placed, free and candidate counts. Placements
// carry no start or end date; a placement IS a standing weekly commitment for
// the year. So the week picker navigated an axis on which the numbers staff are
// trying to move never changed, while hiding the ones that did.
//
// What genuinely varies through a year is the other half of the grid, and it
// varies by ENROLMENT, not by week: children start and leave mid-year, and they
// change room as they age. Those are exactly the facts a year view can state and
// a week view could not.
//
// This composes the existing engines rather than recomputing occupancy again --
// a second independent occupancy calculation is what once made the Board and
// Fill disagree. Each stays the single authority on its own question:
//
//   board_load()        - what the team plans (placed, promised, capacity)
//   bookings_year()     - what Kidsoft has booked, every weekday date of the year
//   coverage_year()     - when each arrangement starts and stops during the year
//                          (mid-year starters and leavers). There is no longer a
//                          'where these disagree' step: the board IS Kidsoft now,
//                          so there is nothing left to reconcile. See board-live.php.
//   fill_suggestions()  - who in the queue could take a free place
//   day_requests_year() - families who have asked for a day and are still waiting
//
// The year/week seam is gone: the board is a year, Kidsoft is now read as a
// year, and there is no longer a week that can straddle New Year.

require_once __DIR__ . '/board.php';
require_once __DIR__ . '/occupancy.php';
require_once __DIR__ . '/drift.php';
require_once __DIR__ . '/fill.php';
require_once __DIR__ . '/day-requests.php';
require_once __DIR__ . '/identity.php';
require_once __DIR__ . '/contacts.php';
require_once __DIR__ . '/work.php';       // open work items, attached per candidate
require_once __DIR__ . '/internal-requests.php';   // the wishes Kidsoft has no field for
require_once __DIR__ . '/day-holds.php';           // and the places kept for a child who is not booked on them

/** How many "already here" families to list per cell before saying "and N more". */
const CELL_EXTRA_DAY_LIMIT = 8;

/**
 * Families already in this room who do NOT attend on this weekday.
 *
 * THE SECOND SOURCE OF DEMAND, and much the larger of the two. day_requests_year()
 * finds families who have ASKED for a day; this finds the ones nobody has asked.
 * Under 10% of children attend all five days, so almost every child in a room is a
 * candidate for almost every other day of it - and they are the easiest sale in
 * the building: already enrolled, already settled in that room, already known to
 * the educators, no new placement and no waitlist offer required. The workbook kept
 * an "Existing children wanting days" block for exactly this and it only ever
 * recorded families who volunteered.
 *
 * Only computed for a cell with a place going spare; a full day has nothing to
 * sell. Children with a waitlist / no_position status are excluded - they do not
 * hold a place in the room at all, so adding a day is not the conversation.
 *
 * A COUNT, NOT A CALL LIST (changed 7 Aug 2026). This returned a ranked shortlist
 * with each family's guardian and phone number, ordered fewest-days-first as "the
 * easiest sell". Little Miracles does not upsell as a policy: a family already in
 * care who wants more days ASKS for them, and is then a waitlisted booking in
 * Kidsoft, which day_requests_year() already surfaces at the top of the cell with
 * preference over the external queue. So the names and numbers are no longer
 * returned - only how many children hold this room on other days, which is
 * context for judging whether a day is genuinely sellable.
 *
 * @param array $byChild placementId => ['name','ks_id','days'=>[wd,...]]
 * @return array{list:array,total:int}
 */
function cell_extra_day_families(array $byChild, int $wd): array {
    $n = 0;
    foreach ($byChild as $c) {
        if (in_array($wd, $c['days'], true)) continue;   // already here that day
        $n++;
    }
    return ['list' => [], 'total' => $n];
}

/**
 * Kidsoft's day requests and our own internal wishes, as ONE list per cell.
 *
 * ONE FAMILY, ONE ROW - the rule search.php is built on. A parent who booked a
 * Wednesday in the portal AND rang the centre about Wednesdays is one family
 * wanting one day, and listing them twice would put two calls on the list and
 * count two against `grantable`, which is the figure that decides whether the
 * cell shouts. So a duplicate MERGES: the Kidsoft row stays (it carries the real
 * dates the parent asked for) and our note is attached to it, which is the whole
 * point of the note - what they said on the phone belongs next to what they
 * booked, not on a row of its own underneath it.
 *
 * Matched on kidsoft_child_id, never on a name. A wish for a provisional child
 * has no id to match on and simply stands alone, which is right: Kidsoft has
 * never heard of that child, so it cannot be holding a request from them.
 *
 * Ordered by when they asked, the queue-order principle used everywhere else --
 * so a family who rang in March comes before one who booked the portal in July,
 * whichever system happens to hold the record.
 */
function cell_merge_requests(array $kidsoft, array $internal): array {
    foreach ($internal as $roomId => $byWd) {
        foreach ($byWd as $wd => $wishes) {
            $existing = $kidsoft[$roomId][$wd] ?? [];

            // Where the same child is in both, fold the wish into the Kidsoft row.
            $byKs = [];
            foreach ($existing as $i => $q) {
                if (!empty($q['kidsoft_child_id'])) $byKs[$q['kidsoft_child_id']] = $i;
            }

            foreach ($wishes as $w) {
                $ks = $w['kidsoft_child_id'] ?? null;
                if ($ks !== null && isset($byKs[$ks])) {
                    $i = $byKs[$ks];
                    $existing[$i]['note']            = $w['note'];
                    $existing[$i]['recorded_by']     = $w['recorded_by'];
                    $existing[$i]['recorded_at']     = $w['recorded_at'];
                    $existing[$i]['wish_days']       = $w['wish_days'];
                    // The id of OUR row, so the panel can still edit the note on
                    // a family whose request Kidsoft also holds.
                    $existing[$i]['internal_id']     = $w['id'];
                    $existing[$i]['also_internal']   = true;
                    continue;
                }
                $existing[] = $w + ['internal_id' => $w['id']];
            }

            usort($existing, static fn($a, $b) =>
                (string)($a['requested_at'] ?? '') <=> (string)($b['requested_at'] ?? ''));
            $kidsoft[$roomId][$wd] = $existing;
        }
    }
    return $kidsoft;
}

/**
 * Every room x weekday cell at a centre for a calendar year.
 *
 * @param array $opts 'candidates' => false to skip the queue match (the
 *                    expensive part); 'refresh' => true to bypass the Kidsoft
 *                    cache (~33s for a year).
 */
/**
 * WHAT A CHILD'S LAST DAY ON A CELL MEANS: leaving care, giving up this day, or
 * moving to another room.
 *
 * A "to 7/12/2026" on the roll said nothing about which, and the team asked
 * (10 Nov 2026): "is a child dropping one day, or are they leaving care all
 * together and all their days becoming free?" Elliott Hatty and Penelope Robinson
 * sat one above the other with the same kind of date and four green days each;
 * one is giving up Mon/Tue and keeps Wed/Thu, the other is leaving.
 *
 * ONE RULE FOR EVERY PLACE THAT SAYS IT - the roll's label, the banner's list
 * and its "N leaving, N giving up a day" counts - so they cannot disagree:
 *
 *   leaving   Kidsoft holds an ExitDate, or nothing else this child holds runs
 *             a week or more past this day. "A week" because a child leaving on
 *             a Friday ends their Monday four days earlier, and that is not a
 *             Monday given up.
 *   dropping  this room still has them on another day a week or more later.
 *   moving    only another room does - a move up, which is why `room_id` is
 *             returned with it.
 *
 * Bookings on or after an ExitDate do not count as continuing - the exit is the
 * first day gone (see the churn loop in cells_year()).
 *
 * @param array $coverage coverage_year() for the centre: childId => roomId => wd => {last, ...}
 * @return array{kind:string, room_id:?int, reason:?string}
 */
function cells_end_kind(string $kid, int $rid, int $wd, string $last,
                        array $coverage, array $exits): array {
    $x = $exits[$kid] ?? null;
    if ($x) return ['kind' => 'leaving', 'room_id' => null, 'reason' => $x['reason'] ?: null];

    $weekOn = date('Y-m-d', strtotime($last . ' +7 days'));
    $moveTo = null; $moveLast = '';
    foreach ($coverage[$kid] ?? [] as $r2 => $wds) {
        foreach ($wds as $w2 => $c2) {
            if ((int)$r2 === $rid && (int)$w2 === $wd) continue;
            if ($c2['last'] < $weekOn) continue;
            if ((int)$r2 === $rid) return ['kind' => 'dropping', 'room_id' => null, 'reason' => null];
            if ($c2['last'] > $moveLast) { $moveTo = (int)$r2; $moveLast = $c2['last']; }
        }
    }
    return $moveTo !== null
        ? ['kind' => 'moving', 'room_id' => $moveTo, 'reason' => null]
        : ['kind' => 'leaving', 'room_id' => null, 'reason' => null];
}

function cells_year(int $centreId, int $year, array $opts = []): array {
    $withCandidates = ($opts['candidates'] ?? true) !== false;
    $refresh        = !empty($opts['refresh']);

    // placements_sync_identity() used to run here, refreshing the cached names on
    // board rows. It is gone from the page load: the board reads a linked child's
    // name straight from Kidsoft now (board_live_children()), so the cached copy
    // on an annotation row is never displayed and syncing it on every page view
    // was a query per load for nothing. The nightly cron still calls it, which
    // keeps the stored names sane for anything that reads them directly.

    // 'from': the date the board is read AS AT, when somebody picked one - see
    // board_from(). Validated by the caller; board_load() ignores one outside
    // this year.
    $board = board_load($centreId, $year, ['from' => $opts['from'] ?? null]);
    // The board goes IN, because fill_suggestions() now takes its occupancy from
    // it (see the note there). Passing the one we already hold keeps this to a
    // single derivation per request and makes it impossible for the shortlist to
    // be computed against a different board from the one on screen.
    // `start_window`: a family starting more than 90 days after the place comes
    // free is a near miss here, not a candidate - see the note in fill.php.
    $fill  = $withCandidates ? fill_suggestions($centreId, $year, [
        'board' => $board, 'start_window' => FILL_START_WINDOW_DAYS,
    ]) : null;

    // One read of the year's bookings, shared by the occupancy figures and the
    // drift comparison. Kidsoft being unreachable must not blank the plan --
    // free places come from OUR placements and are still worth showing.
    $ks = null;
    $ksError = null;
    try {
        $ks = bookings_year($board['centre']['kidsoft_service_id'], $year, $refresh);
    } catch (RuntimeException $e) {
        $ksError = $e->getMessage();
    }

    // COVERAGE ONLY. This used to be drift_year(), which compared our copy of the
    // board against Kidsoft and reported every disagreement. With the board
    // derived from Kidsoft there are no disagreements by construction - what the
    // comparison found afterwards was simply "the reference month is not the whole
    // year", which is mid-year movement and is what coverage already says, with
    // dates attached. Keeping both would have put a dot on every cell for a fact
    // that is now true of every cell.
    $coverage = $ks ? coverage_year($centreId, $year, $ks) : [];

    // Open demand for the rest of the year, per room per weekday.
    $requests = [];
    try {
        $requests = day_requests_year($centreId, $year, $refresh);
    } catch (RuntimeException $e) {
        // Demand is an enhancement to the grid, not the grid itself.
        $requests = [];
    }

    // THE SECOND HALF OF THE SAME QUESTION, and Kidsoft holds none of it: a
    // parent who rings the centre wanting "a Wednesday when one comes free" has
    // raised nothing in the portal, so day_requests_year() cannot see them. See
    // the header of _incl/internal-requests.php.
    //
    // GRANTED FIRST, because a wish the board has already met must not sit on a
    // cell as demand - the child is in that room on that day, and counting them
    // would show a request against a place they are themselves occupying.
    //
    // Yes, this WRITES during a read, which an engine normally should not. It is
    // here rather than in api/cells.php (where offers_expire_due() sits, and
    // where work_autoclose() is called from api/dashboard.php) because the sweep
    // needs the board, and $board is already in hand: doing it at the endpoint
    // would mean a second board_load() and two answers that could disagree
    // inside one response. The write is idempotent and touches only rows this
    // same board proves are met.
    internal_requests_close_met($board);
    $requests     = cell_merge_requests($requests, internal_requests_year($centreId, $year));
    $wishesByRoom = internal_requests_by_child($centreId, $year);
    $holdsByRoom  = day_holds_by_child($centreId, $year);

    // AND THE SAME SWEEP FOR THE OPPOSITE FACT. A day held for a child who has
    // since been booked back onto it is over: the booking is the stronger claim
    // and the peak already counts it. Same reasoning for it living here - the
    // board is in hand - and the same idempotence. board_load() does not wait for
    // this to be correct; it ignores a hold on a cell the child is booked on, so
    // fill.php and the offer path can never be answered from a stale row.
    day_holds_close_booked($board);

    // Who to ring about a spare day, for every child in the centre. Two cached
    // Kidsoft calls; an outage costs the phone numbers, not the grid.
    $contacts = [];
    try {
        $contacts = child_contacts($board['centre']['kidsoft_service_id'], $refresh);
    } catch (RuntimeException $e) {
        $contacts = [];
    }

    // OPEN WORK, so a family already being chased shows their stage instead of a
    // button offering to start again. Keyed by identity, because the two demand
    // sources identify a family differently - the wishlist by Kidsoft ChildID,
    // the queue by our application id.
    $workItems = work_list($centreId, $year);
    $workByWho = [];
    foreach ($workItems as $w) {
        if ($w['kidsoft_child_id']) $workByWho['k' . $w['kidsoft_child_id']] = $w;
        if ($w['waitlist_id'])      $workByWho['w' . (int)$w['waitlist_id']]  = $w;
    }
    // AND THE SAME WORK KEYED BY THE SPOT IT IS AIMED AT, which is the question
    // the badge above cannot answer: "is somebody already working THIS place?"
    // The per-candidate badge only tells you about a family you are already
    // looking at, so it stops you starting the same family twice and does
    // nothing at all about a colleague working a DIFFERENT family toward the
    // same seat. See work_by_cell() for why this is advisory and why anything at
    // `offered` or past it is left out.
    $workByCell = work_by_cell($workItems);

    $fillByRoom = [];
    foreach ($fill['rooms'] ?? [] as $r) $fillByRoom[(int)$r['id']] = $r;

    // Our room id => Kidsoft room id, for reading the occupancy series back.
    $ksByRoom = [];
    foreach (rooms_for_centre($centreId, $year) as $rm) {
        if ($rm['kidsoft_room_id']) $ksByRoom[(int)$rm['id']] = $rm['kidsoft_room_id'];
    }

    // Who is leaving and who is arriving, per cell. Counted from coverage rather
    // than from drift, because a child leaving in March is not a disagreement
    // with the board - it is a place about to go on sale, which is the single
    // most valuable thing this screen can say. Handovers are excluded: a child
    // moving up a room has not freed anything.
    //
    // ONLY DEPARTURES STILL AHEAD OF TODAY COUNT (10 Aug 2026). Coverage sees the
    // WHOLE year, so unfiltered this reported every child who had already gone --
    // measured on 10 Aug 2026 over all seven centres, 482 of the 510 departures it
    // was announcing as "places come free" had already happened, Tuggerah's 134 of
    // 138 at a median last day of 8 April. Worse, they were not even news: the
    // board derives from board_reference_month(), which for the current year is
    // THIS month, so a child who stopped in April is already off the roll and
    // their seat is already in the cell's `free` figure and its heat colour. The
    // banner was re-announcing what the coloured blocks were already saying,
    // which is the same reason CLAUDE.md gives for a leaver not earning a grid dot.
    //
    // What is left is the forward signal the screen was built for: 28 arrangements
    // group-wide, ~11 children, small enough to name rather than count. A past
    // year now produces none at all, which is correct - "comes free" is a claim
    // about the future and a finished year has no future left in it.
    //
    // "AHEAD" MEANS AHEAD OF THE DATE THE BOARD COUNTS FROM (16 Oct 2026): today,
    // or the date somebody picked, so a board read as at 1 March names the
    // children leaving after 1 March. A finished year with no date picked still
    // has none - board_from() is null and nothing is ahead of it.
    $today = $board['from'] ?? '9999-12-31';
    // A DECLARED LAST DAY BEATS AN INFERRED ONE (13 Aug 2026). Until now the only
    // departure signal was the shape of a child's bookings: they stop early, so
    // they must be leaving. Kidsoft states it outright on the child record, and
    // for 10 of the 11 children carrying a future `ExitDate` the two agree - the
    // bookings are already truncated and the inference finds them.
    //
    // THE ELEVENTH IS WHY THIS IS HERE. Tuggerah's Brodie Jackson leaves on 14 Aug
    // and still holds bookings to 24 December, because notice is in and nobody has
    // cleaned them up. Inference sees a full-year child and reports NOTHING; the
    // declaration frees three cells at Mighty Meteors Silver four months early.
    // Notice is given four weeks out, so this is the whole warning the team gets.
    //
    // The declaration wins on the DATE only where it is EARLIER than the bookings:
    // an exit after the last booked day does not bring a finished arrangement
    // back (see the churn loop below).
    $exits = $board['exits'] ?? [];

    // LEAVING THE SERVICE IS NOT THE SAME EVENT AS DROPPING A DAY, and the banner
    // called both "stop attending" (Broc, 13 Aug 2026). Coverage is per (child,
    // room, weekday), so a child who gives up one weekday and keeps the rest of
    // their week counted as a departure. Measured at Niagara Park 2026: of the 4
    // children the banner said were stopping, TWO were not - Axel Lam keeps
    // Mon/Tue/Fri to 21 December and drops only his Thursday, Bella Lam keeps
    // Mon/Tue/Thu and drops only her Friday.
    //
    // BOTH STILL FREE THE PLACE-DAY and both stay on the cell (Broc: "it does free
    // up that place-day for another child to take"). This changes what they are
    // CALLED, never whether they are counted.
    //
    // The test is cells_end_kind() (10 Nov 2026), shared with the roll's labels.
    // It replaced "an ExitDate, or EVERY arrangement ends early", which called a
    // child giving up a day whenever one of their days ran into the last two
    // weeks - inside the three-week edge, so not "early" - even when all of them
    // ended in the same week.
    $churn = [];
    $leavingKids  = [];       // children leaving the SERVICE, with a day still ahead
    $droppingKids = [];       // children staying, giving up one or more days
    // WHO, not just how many: childId => {kind, reason, days: [{room_id, wd,
    // last, free_from}]}. The banner lists these by name and the panel names the
    // ones on its own cell - see the return array.
    $departing = [];
    foreach ($coverage as $cid => $rooms) {
        foreach ($rooms as $rid => $wds) {
            foreach ($wds as $wd => $c) {
                $churn[$rid][$wd] ??= ['leaving' => 0, 'arriving' => 0,
                                       'free_from' => null, 'arrives_on' => null];
                // A declaration moves the DATE - to the EARLIER of the exit and
                // the last day booked - and the fact follows from that date by the
                // same test the bookings get. Handover is not consulted - a
                // declared exit is a child leaving the service, never a room move.
                //
                // THE EARLIER, NOT THE DECLARED (10 Nov 2026). Taking the exit
                // outright revived arrangements that had already ended: Kelly
                // Schuurink stopped Mondays at Terrigal on 20 April, exits 22/11,
                // and her Monday was announced as "+1 free from 22/11/2026" - a
                // place that came free seven months earlier and is already in
                // `free`. 14 day-places group-wide, 10 at Terrigal. The earlier
                // date is still Brodie's exit (bookings past it) and is Kelly's
                // April (bookings stopped first), which the filter below drops.
                //
                // AND NOT ALWAYS EARLY. A child booked to the last week with an
                // exit after the close is leaving because the YEAR ends, which
                // every enrolment does - see coverage_ends_early().
                //
                // AN ExitDate IS THE FIRST DAY GONE, NOT THE LAST DAY THERE.
                // Measured 10 Nov 2026: all 25 declared exits group-wide are a
                // SUNDAY after the child's last booked day, and
                // board_forward_occupancy() already drops bookings ON the exit.
                // So the last day in the room is the day before it.
                $declared = $exits[$cid]['on'] ?? null;
                if ($declared !== null) {
                    $c['handover']   = false;
                    $c['last']       = min($c['last'],
                                           date('Y-m-d', strtotime($declared . ' -1 day')));
                    $c['ends_early'] = coverage_ends_early($c['last'], $ks['weekdays'][$wd] ?? []);
                }
                if ($c['ends_early'] && !$c['handover'] && $c['last'] >= $today) {
                    $churn[$rid][$wd]['leaving']++;
                    $end = cells_end_kind((string)$cid, (int)$rid, (int)$wd, $c['last'], $coverage, $exits);
                    // PER CELL ONLY. There was a centre-wide soonest date here
                    // and the banner rendered it against the netted count; see
                    // the note on the return array.
                    //
                    // THE FIRST DAY THE PLACE IS FREE, NOT THE CHILD'S LAST DAY
                    // (Broc, 10 Nov 2026). It was `leaves_on` and held the last
                    // day there, so the panel printed "+1 free from 7/12/2026"
                    // beside the same child's "to 7/12/2026" - Elliott Hatty's
                    // last Monday, on which his place is very much taken. This is
                    // the next date of this weekday after it.
                    $free = coverage_free_from($c['last'], $ks['weekdays'][$wd] ?? []);
                    $lo = &$churn[$rid][$wd]['free_from'];
                    if ($free !== null && ($lo === null || $free < $lo)) $lo = $free;
                    unset($lo);

                    // A child is LEAVING if any day they give up is them leaving;
                    // a Monday dropped in September does not make a child who
                    // leaves in November "staying".
                    $d = &$departing[$cid];
                    $d ??= ['kind' => $end['kind'], 'reason' => $end['reason'], 'days' => []];
                    if ($end['kind'] === 'leaving') $d['kind'] = 'leaving';
                    $d['days'][] = ['room_id' => (int)$rid, 'wd' => (int)$wd,
                                    'last' => $c['last'], 'free_from' => $free];
                    unset($d);
                }
                // ARRIVALS TAKE THE SAME FILTER, AND IT CHANGES WHAT THEY MEAN.
                // Unfiltered this was 772 group-wide of which 734 had already
                // happened - and those are pure noise, because a child who
                // started in March is on the August-derived board already and is
                // counted in `placed`. The 38 that remain are the opposite of
                // noise: a child whose first booking is in October is a seat
                // ALREADY COMMITTED that this cell is still reporting as free,
                // which is a place two staff could promise to two families. So
                // the badge stopped being context about the year and became a
                // qualifier on the free figure, which is why it now carries a
                // date. `free` itself is deliberately unchanged - same rule as
                // work_by_cell(): advisory, never a lock.
                if ($c['starts_late'] && !$c['gappy'] && $c['first'] >= $today) {
                    $churn[$rid][$wd]['arriving']++;
                    $ao = &$churn[$rid][$wd]['arrives_on'];
                    if ($ao === null || $c['first'] < $ao) $ao = $c['first'];
                    unset($ao);
                }
            }
        }
    }

    // The two counts the banner has always printed, now off the same list it
    // names - see cells_end_kind().
    foreach ($departing as $cid => $d) {
        if ($d['kind'] === 'leaving') $leavingKids[$cid] = true;
        else                          $droppingKids[$cid] = true;
    }

    // NAMED, because "3 children leaving" sent the reader hunting for them.
    // Kidsoft's own spelling, cased as the roll cases it, so a name in the banner
    // and the same child on the roll read identically.
    $roomNames = array_column($board['rooms'], 'name', 'id');
    $ksNames   = $departing ? kidsoft_children($board['centre']['kidsoft_service_id']) : [];
    $nameOf    = static function (string $cid) use ($ksNames): string {
        $k = $ksNames[$cid] ?? [];
        return trim(name_case((string)($k['FirstName'] ?? '')) . ' ' .
                    name_case((string)($k['LastName'] ?? '')));
    };

    // WHAT A CHILD GIVING UP A DAY KEEPS - the other half of "giving up Mondays",
    // and the thing that tells it apart from leaving. Days they still hold from
    // the board's date on, room by room; a handover is a move up, not a day kept
    // in the room they are leaving.
    $keeps = [];
    foreach ($departing as $cid => $d) {
        if ($d['kind'] === 'leaving') continue;
        $ending = [];
        foreach ($d['days'] as $x) $ending[$x['room_id']][$x['wd']] = true;
        foreach ($coverage[$cid] ?? [] as $kr => $kwds) {
            foreach ($kwds as $kw => $kc) {
                if (isset($ending[$kr][$kw]) || $kc['handover'] || $kc['last'] < $today) continue;
                $keeps[$cid][(int)$kr][] = (int)$kw;
            }
        }
        foreach ($keeps[$cid] ?? [] as &$kl) sort($kl);
        unset($kl);
    }

    // A departure an arrival already covers frees nothing to sell. Per CELL, the
    // same netting as `coming_free`: where arrivals cover every departure, each of
    // them is re-filled. Which arrival replaces which leaver is never guessed, so a
    // cell only partly covered marks none.
    $refilled = static fn(int $rid, int $wd): bool =>
        ($churn[$rid][$wd]['leaving'] ?? 0) > 0 &&
        ($churn[$rid][$wd]['arriving'] ?? 0) >= ($churn[$rid][$wd]['leaving'] ?? 0);

    $departByCell = [];
    foreach ($departing as $cid => $d) {
        foreach ($d['days'] as $x) {
            $departByCell[$x['room_id']][$x['wd']][] = [
                'ks_id'     => (string)$cid,
                'name'      => $nameOf((string)$cid),
                'kind'      => $d['kind'],
                'reason'    => $d['reason'],
                'last'      => $x['last'],
                'free_from' => $x['free_from'],
                'keeps'     => $keeps[$cid][$x['room_id']] ?? [],
                'refilled'  => $refilled($x['room_id'], $x['wd']),
            ];
        }
    }
    // Soonest first, so the panel reads in the order the places free.
    foreach ($departByCell as &$byWd) {
        foreach ($byWd as &$list) {
            usort($list, static fn($a, $b) => [$a['free_from'] ?? '9999', $a['name']] <=> [$b['free_from'] ?? '9999', $b['name']]);
        }
        unset($list);
    }
    unset($byWd);

    $rooms  = [];
    $comingFree = [];   // cells with a departure no arrival already covers
    $totals = [];
    foreach (array_keys(WEEKDAYS) as $wd) {
        $totals[$wd] = ['capacity' => 0, 'placed' => 0, 'promised' => 0, 'free' => 0,
                        'requests' => 0, 'grantable' => 0, 'in_progress' => 0,
                        'leaving' => 0, 'weeks_free' => 0];
    }

    foreach ($board['rooms'] as $r) {
        $rid = (int)$r['id'];
        $f   = $fillByRoom[$rid] ?? null;
        $cells = [];

        // Everyone placed in this room, and which weekdays each of them holds.
        // Built by walking the five per-weekday lists the board already produced,
        // so there is no second query and no second definition of "placed".
        // KEYED ON THE CHILD'S IDENTITY, NOT ON A ROW ID. This was keyed on
        // `$ch['id']` - the placements row - which was fine while every child
        // on the board had one. Now the board is derived from Kidsoft and `id` is
        // NULL for anyone nobody has annotated, so every child in the room
        // collapsed into a single null-keyed entry holding all five weekdays,
        // every day looked "already attended", and the extra-day call list
        // silently went to ZERO - the largest pool of demand in the app,
        // reporting nothing, with no error anywhere. The 870 stale copy rows had
        // been hiding it.
        $byChild = [];
        foreach (array_keys(WEEKDAYS) as $w) {
            foreach ($r['cells'][$w]['children'] as $ch) {
                // A Kidsoft child is its ChildID; a provisional one has only our
                // row. Prefixed so the two can never collide.
                $key = $ch['ks_id'] !== null ? 'k' . $ch['ks_id'] : 'p' . $ch['id'];
                if (!isset($byChild[$key])) {
                    $byChild[$key] = ['id' => $ch['id'], 'name' => $ch['name'],
                                      'ks_id' => $ch['ks_id'], 'days' => []];
                }
                $byChild[$key]['days'][] = $w;
            }
        }

        foreach (array_keys(WEEKDAYS) as $wd) {
            $bc = $r['cells'][$wd];

            // What Kidsoft holds on this weekday across the whole year, as a
            // series of one number per date. An unlinked room reports null
            // (unknown), never 0 - 0 reads as an empty room and would send
            // someone chasing places that are already taken.
            $booked = null;
            if ($ks && $r['linked'] && isset($ksByRoom[$rid])) {
                $series = $ks['occupied'][$ksByRoom[$rid]][$wd] ?? [];
                $booked = booked_year_summary($series, $bc['capacity'],
                                              count($ks['weekdays'][$wd] ?? []));
            }

            $ch   = $churn[$rid][$wd] ?? ['leaving' => 0, 'arriving' => 0,
                                          'free_from' => null, 'arrives_on' => null];
            $reqs = $requests[$rid][$wd] ?? [];
            $inProgress = $workByCell[$rid][$wd] ?? [];

            // Only worth computing where there is something to sell.
            $extra = ($bc['vacancies'] !== null && $bc['vacancies'] > 0)
                ? cell_extra_day_families($byChild, $wd)
                : ['list' => [], 'total' => 0];

            $cells[$wd] = [
                'capacity'  => $bc['capacity'],
                'placed'    => $bc['occupied'],
                'promised'  => $bc['promised'],
                // ...and the families those offers went to, so the panel can list
                // them the way it lists the roll rather than leaving the reader
                // with a count and no names. See board_load().
                'promised_children' => $bc['promised_children'] ?? [],
                // Kept for a child who has not answered re-enrolment. Already
                // subtracted from `free` by board_load(); carried through so the
                // cell can say WHY a place is not offerable.
                'held'      => $bc['held'] ?? 0,
                'held_children' => $bc['held_children'] ?? [],
                // Kept for a child who is enrolled here and is not booked on this
                // weekday - a Behaviour Support Plan, or any other arrangement
                // where the family is out for a while and the place stays theirs.
                // Already subtracted from `free` by board_load(); carried through
                // so the panel can say WHO the place is being kept for, which is
                // the rule promised_children and held_children both follow.
                'reserved'  => $bc['reserved'] ?? 0,
                'reserved_children' => $bc['reserved_children'] ?? [],
                'free'      => $bc['vacancies'],
                'over'      => $bc['over'],
                'pct'       => $bc['pct'],
                'subquotas' => $bc['subquotas'],
                // THE ROLL SAYS WHAT EACH "to" DATE MEANS - leaving care, giving
                // up this day, or moving room - see cells_end_kind(). Only rows
                // carrying a `to` are touched, and a provisional child (no
                // Kidsoft id, no bookings to read) is left as it is.
                'children'  => array_map(static function (array $x) use ($rid, $wd, $coverage, $exits, $roomNames) {
                    if (empty($x['to']) || empty($x['ks_id'])) return $x;
                    $e = cells_end_kind((string)$x['ks_id'], $rid, (int)$wd, $x['to'], $coverage, $exits);
                    $x['ends'] = ['kind'   => $e['kind'],
                                  'room'   => $e['room_id'] !== null ? ($roomNames[$e['room_id']] ?? null) : null,
                                  'reason' => $e['reason']];
                    return $x;
                }, $bc['children']),
                // WHO is behind `leaving` on this cell, so the panel can name them
                // under its "+N free" badge. Gross, like `leaving`; each carries
                // `refilled` where an arrival already covers the cell.
                'departures' => $departByCell[$rid][$wd] ?? [],
                // Kidsoft across the year: typical/min/max booked, and how many
                // of the year's weeks actually have a place going spare. That
                // last number - free_days - is what the team acts on: an empty
                // place-day is a child who could have been in that room.
                'booked'    => $booked,
                'gap'       => $booked === null ? null : $booked['typical'] - $bc['occupied'],
                // NO `unsold_value`. It priced free_days at the room's median fee
                // and the placement board rendered it as dollars. Removed 10 Aug
                // 2026: this is a child enrolment tool and revenue belongs to
                // another team entirely. free_days is the honest unit here and
                // needs no rate attached to be worth chasing.
                // Enrolments changing hands during the year.
                'leaving'   => $ch['leaving'],
                // The first date the place is free - see the churn loop.
                'free_from' => $ch['free_from'],
                // NO `arriving` / `arrives_on` ON THE PAYLOAD (21 Aug 2026).
                // They existed to feed one badge - "N places already taken from
                // DD/MM" - which was the only place a child starting after the
                // board's reference month could be mentioned, because the board
                // could not show them. It shows them now, on the roll, with the
                // date on their own row. The count stays INSIDE this engine,
                // where `coming_free` nets it against departures.
                // PLACE-DAYS THAT ARE ACTUALLY GOING ON SALE: departures with no
                // arrival already covering them, on this cell. `leaving` on its
                // own announced work that does not exist - measured at Niagara
                // Park 2026, all SIX of the place-days the banner offered had a
                // replacement booked into the same cell within 1 to 3 weeks, on a
                // grid reading 489 of 490 places taken. Every one of those cells
                // showed `free` 0, correctly, because `holding` is the peak to
                // December and counts the replacement. A banner disagreeing with
                // the free figure beside it is two sources for one number.
                //
                // This is ADDITIONAL to `free`, never a restatement of it: `free`
                // is what is spare at the fullest moment of the rest of the year,
                // this is what opens up afterwards. A cell reading "0 free" and
                // "1 from 3/09" is saying both truthfully.
                'coming_free' => max(0, $ch['leaving'] - $ch['arriving']),
                'candidates'     => array_map(static function (array $x) use ($workByWho) {
                    $w = $workByWho['w' . (int)$x['waitlist_id']] ?? null;
                    $x['work'] = $w ? ['id' => $w['id'], 'stage' => $w['stage'],
                                       'stage_label' => $w['stage_label']] : null;
                    return $x;
                }, $f['cells'][$wd]['candidates'] ?? []),
                'eligible_count' => $f['cells'][$wd]['eligible_count'] ?? 0,
                'requests'       => array_map(static function (array $x) use ($workByWho, $byChild) {
                    $w = empty($x['kidsoft_child_id']) ? null
                        : ($workByWho['k' . $x['kidsoft_child_id']] ?? null);
                    $x['work'] = $w ? ['id' => $w['id'], 'stage' => $w['stage'],
                                       'stage_label' => $w['stage_label']] : null;
                    // WHICH DAYS THEY ALREADY HAVE IN THIS ROOM. A family asking
                    // for a third day is a different conversation from one asking
                    // for their first, and the panel should not make somebody
                    // cross-check the roster to tell them apart. Free: $byChild
                    // is already built for this room.
                    $key = empty($x['kidsoft_child_id']) ? null : 'k' . $x['kidsoft_child_id'];
                    $x['current_days'] = $key !== null && isset($byChild[$key])
                        ? $byChild[$key]['days'] : [];
                    return $x;
                }, $reqs),
                'requests_n'     => count($reqs),
                // Families being worked toward THIS spot who are not yet holding
                // it. Advisory only: `free` above is deliberately unchanged, or
                // a family who went quiet at `contacted` would sterilise a place.
                'in_progress'    => $inProgress,
                'in_progress_n'  => count($inProgress),
                // Families already in this room on other days - the demand nobody
                // has asked for yet. See cell_extra_day_families().
                'extra_day'      => $extra['list'],
                'extra_day_n'    => $extra['total'],
                // Someone asked for this day and there is a place free. No data
                // entry stands between the team and the conversation.
                'grantable'      => (bool)$reqs && $bc['vacancies'] !== null && $bc['vacancies'] > 0,
            ];

            if ($bc['capacity'] !== null) $totals[$wd]['capacity'] += $bc['capacity'];
            $totals[$wd]['placed']   += $bc['occupied'];
            $totals[$wd]['promised'] += $bc['promised'];
            $totals[$wd]['free']     += (int)$bc['vacancies'];
            $totals[$wd]['requests']    += count($reqs);
            $totals[$wd]['in_progress'] += count($inProgress);
            $totals[$wd]['leaving']  += $ch['leaving'];
            if ($booked) $totals[$wd]['weeks_free'] += $booked['weeks_free'];
            if ($cells[$wd]['grantable']) $totals[$wd]['grantable'] += count($reqs);

            // NAME THE CELL. "6 place-days come free" with no room and no weekday
            // is unactionable: a departure earns no grid dot (that slot belongs to
            // demand) and cannot show in `free` either, so the only way to find one
            // was to hover all 25 cells. Broc, 13 Aug 2026: "freeing 6 place-days
            // ... where?"
            //
            // BY THE MONTH EACH PLACE FREES, not the cell's soonest (10 Nov 2026).
            // One entry per cell filed every place under its FIRST month: Niagara
            // Park's Mighty Meteors Monday frees one place on 23/11 and four in
            // December, and the banner read "20 in Nov" where 11 were, while the
            // Dec chip did not light that cell at all. Where an arrival covers
            // part of a cell, which leaver it replaces is never guessed, so that
            // cell keeps one entry at its soonest date - the sum is always
            // `coming_free`.
            if ($cells[$wd]['coming_free'] > 0) {
                $byMonth = [];
                if (($ch['arriving'] ?? 0) === 0) {
                    foreach ($departByCell[$rid][$wd] ?? [] as $x) {
                        if ($x['free_from'] === null) continue;
                        $m = substr($x['free_from'], 0, 7);
                        $byMonth[$m]['n']    = ($byMonth[$m]['n'] ?? 0) + 1;
                        $byMonth[$m]['from'] = min($byMonth[$m]['from'] ?? '9999-99-99', $x['free_from']);
                    }
                }
                if (array_sum(array_column($byMonth, 'n')) !== $cells[$wd]['coming_free']) {
                    $byMonth = [substr((string)$ch['free_from'], 0, 7) =>
                                ['n' => $cells[$wd]['coming_free'], 'from' => $ch['free_from']]];
                }
                ksort($byMonth);
                foreach ($byMonth as $g) {
                    $comingFree[] = [
                        'room_id' => $rid,
                        'room'    => $r['name'],
                        'weekday' => $wd,
                        'n'       => $g['n'],
                        'from'    => $g['from'],
                    ];
                }
                // Every month this cell has a place freeing in, so each of the
                // banner's month chips lights it.
                $cells[$wd]['free_months'] = array_values(array_filter(array_keys($byMonth)));
            }
        }

        $rooms[] = [
            'id'             => $rid,
            'name'           => $r['name'],
            // Per weekday, because capacity genuinely differs by day. The
            // scalar high-water mark comes too: the room header wants one
            // number, and rendering the MAP there is what produced the
            // "[object Object]/day" on screen.
            'capacity'       => $r['capacity'],
            'capacity_max'   => $r['capacity_max'] ?? null,
            'capacity_min'   => $r['capacity'] ? min($r['capacity']) : null,
            'min_age_months' => $r['min_age_months'],
            'max_age_months' => $r['max_age_months'],
            'linked'         => $r['linked'],
            'subquotas'      => $r['subquotas'],
            // Full placement rows, so the editor can open a child without a
            // second round trip. The cells carry only display chips.
            'placements'     => $r['placements'],
            // The days each child in this room is waiting on, keyed the same way
            // the board keys a child ('k' . ChildID, or 'p' . our row). The
            // roster is where a wish is RECORDED - every child's Mon-Fri strip
            // already shows the days they do not attend, so the empty boxes are
            // literally the thing being asked for and clicking one is the whole
            // interaction.
            'wishes'         => $wishesByRoom[$rid] ?? [],
            // And the days being HELD for a child in this room - the same empty
            // boxes, carrying the opposite fact. Keyed identically, because the
            // roster reads both off one child's Mon-Fri strip and two key shapes
            // for one child is the null-key trap all over again.
            'holds'          => $holdsByRoom[$rid] ?? [],
            'cells'          => $cells,
            'year_free'      => $r['week_vacancies'],
            'year_requests'  => array_sum(array_map(static fn($c) => $c['requests_n'], $cells)),
            'year_leaving'   => array_sum(array_map(static fn($c) => $c['leaving'], $cells)),
            'year_extra_day' => array_sum(array_map(static fn($c) => $c['extra_day_n'], $cells)),
        ];
    }

    foreach ($totals as $wd => $t) {
        $totals[$wd]['pct'] = board_pct($t['placed'], $t['capacity']);
    }

    // A YEAR STILL BEING ENROLLED IS NOT A FAULT. Kidsoft holds next year's
    // bookings only as the centres process each re-enrolment, so a future year's
    // board is legitimately part-full: measured 6 Aug 2026, 2027 held 220 children
    // group-wide against 2026's 861, and three centres had processed none at all
    // while their parents had already accepted. Say it once, as a state of the
    // year, rather than letting a half-empty grid read as broken.
    $enrolled = 0;
    foreach ($board['rooms'] as $r) $enrolled += count($r['placements']);

    // MEASURED AGAINST THIS CENTRE'S CURRENT ROLL, NOT AGAINST A DATE.
    //
    // The first version of this fired on `year > date('Y')` alone, which meant it
    // would still have been announcing "2027 is still being enrolled" in December
    // when 2027 was effectively full - the caption-that-ages-into-a-lie this
    // file warns about in the Tenure section. It also required `enrolled > 0`, so
    // a centre that had processed NOTHING - the case most in need of explaining,
    // Niagara Park with 92 acceptances and no bookings - got no note at all.
    //
    // The baseline is the same centre's roll in the current year, which is what
    // a full year looks like for them. It self-corrects: as the centres work
    // through the round the gap closes and the note stops, with no threshold
    // anybody has to remember to change.
    $thisYear = (int)date_create('today', new DateTimeZone('Australia/Sydney'))->format('Y');
    $enrolledNow = null;
    if ($year > $thisYear) {
        $now = board_load($centreId, $thisYear);   // cached; ~0.05s
        $enrolledNow = 0;
        foreach ($now['rooms'] as $r) $enrolledNow += count($r['placements']);
    }
    // 90%: a year is "still being enrolled" until it is nearly as full as the one
    // running now. Below that the grid is legitimately part-empty.
    $notEnrolledYet = $enrolledNow !== null && $enrolledNow > 0
        && $enrolled < $enrolledNow * 0.9;

    return [
        'centre'       => $board['centre'],
        'year'         => $year,
        // The date this board counts from (board_from()), and whether somebody
        // picked it rather than it being today.
        'from'         => $board['from'],
        'from_chosen'  => !empty($opts['from']),
        // How many of each weekday the year holds - the denominator behind
        // every "unsold on 42 of 53 Thursdays" figure on the grid.
        'weekday_counts' => array_map('count', year_weekday_dates($year)),
        'rooms'        => $rooms,
        'totals'       => $totals,
        'requests_total'  => array_sum(array_column($totals, 'requests')),
        'grantable_total' => array_sum(array_column($totals, 'grantable')),
        // WHO IS PART-WAY THROUGH ENROLLING FOR THIS YEAR, for the summary tile
        // that replaced "Can be granted" (14 Oct 2026). That tile read 0 on 15 of
        // the 16 centre-year boards the day it went - casual requests stopped
        // counting as demand on 13 Aug and it was left announcing nothing - and
        // the grantable banner still fires on the day it is true.
        //
        // THE SAME ROWS THE IN PROGRESS SCREEN LISTS for this centre and year,
        // read through work_progress_list(), so the two cannot count differently.
        // ALL of them, roomed or not: 9 of the 15 open items had no room when this
        // was built (the Waitlist raises them without one, on purpose) and so
        // could reach no cell - this is the only place on the board they show.
        // `places` is their weekdays summed, in the same unit as "Places free".
        'enrolling'    => (static function () use ($centreId, $year) {
            $rows = work_progress_list($centreId, $year);
            return [
                'families' => count($rows),
                'places'   => array_sum(array_map(static fn($w) => count($w['days'] ?? []), $rows)),
            ];
        })(),
        // The green tones on the roster: the band labels, and the day they are
        // read on. See STAFFING_AGE_BANDS in rooms.php.
        'age_bands'    => [
            'on'    => syd_date(staffing_band_on($year, $opts['from'] ?? null)),
            'bands' => array_map(static fn($b) => ['label' => $b['label'], 'turns' => $b['turns']],
                                 STAFFING_AGE_BANDS),
        ],
        // Place-days changing hands, and the CHILDREN behind them - a child
        // leaving three days is one phone call and three places, and the banner
        // needs to say both.
        //
        // THREE FIGURES, NOT ONE, because they answer different questions and the
        // banner used to run them together (13 Aug 2026):
        //   leaving_total     gross place-days ending - churn, including the ones
        //                     an arrival already covers
        //   coming_free_total what is actually going ON SALE, netted per cell
        //   coming_free_cells which cells AND WHEN, so it can be acted on
        // At Niagara Park 2026 those read 6, 0 and none: every departure was
        // already replaced, and the banner was work that did not exist.
        'leaving_total'    => array_sum(array_column($totals, 'leaving')),
        // Children LEAVING THE SERVICE, and children keeping their place but
        // giving up a day. Never add these together and call the sum "stop
        // attending" - that was the bug.
        'leaving_children' => count($leavingKids),
        'dropping_children' => count($droppingKids),
        // ONE ROW PER CHILD, for the banner's list (10 Nov 2026): a child is one
        // phone call, and the team could not tell from a count whether a child
        // was "dropping one day, or leaving care all together". Leaving first,
        // then giving up days, each soonest first. Rooms carry the days `ending`
        // (amber), the days the child `keeps` (green), and the ending days whose
        // cell an arrival has already `refilled`.
        'departures' => (static function () use ($departing, $keeps, $roomNames, $nameOf, $refilled): array {
            $out = [];
            foreach ($departing as $cid => $d) {
                $byRoom = [];
                foreach ($d['days'] as $x) {
                    $g = &$byRoom[$x['room_id']];
                    $g ??= ['room_id' => $x['room_id'], 'room' => $roomNames[$x['room_id']] ?? null,
                            'ending' => [], 'keeps' => $keeps[$cid][$x['room_id']] ?? [],
                            'refilled' => [], 'free_from' => null];
                    $g['ending'][] = $x['wd'];
                    if ($refilled($x['room_id'], $x['wd'])) $g['refilled'][] = $x['wd'];
                    if ($x['free_from'] !== null && ($g['free_from'] === null || $x['free_from'] < $g['free_from'])) {
                        $g['free_from'] = $x['free_from'];
                    }
                    unset($g);
                }
                foreach ($byRoom as &$g) { sort($g['ending']); sort($g['refilled']); }
                unset($g);
                $byRoom = array_values($byRoom);
                $from = min(array_map(static fn($g) => $g['free_from'] ?? '9999-12-31', $byRoom));
                $out[] = ['ks_id' => (string)$cid, 'name' => $nameOf((string)$cid),
                          'kind' => $d['kind'], 'reason' => $d['reason'],
                          'free_from' => $from === '9999-12-31' ? null : $from,
                          'rooms' => $byRoom];
            }
            usort($out, static fn($a, $b) =>
                [$a['kind'] !== 'leaving', $a['free_from'] ?? '9999', $a['name']] <=>
                [$b['kind'] !== 'leaving', $b['free_from'] ?? '9999', $b['name']]);
            return $out;
        })(),
        // NO `leaving_from`, and it is the same fault as the gross count it sat
        // beside (removed 14 Aug 2026). It was the soonest of ALL departures,
        // including the ones an arrival already covers, and the banner printed it
        // as the date of the netted `coming_free_total` - a date and a count
        // drawn from two different populations, which is exactly how a figure
        // starts disagreeing with itself. Every date the banner needs is on the
        // row it belongs to: `coming_free_cells[].from`.
        'coming_free_total' => array_sum(array_column($comingFree, 'n')),
        'coming_free_cells' => $comingFree,
        // (child x free day) pairs: one family may appear on several free days of
        // their own room, and each of those is a separate day that could be sold.
        'extra_day_total' => array_sum(array_map(
            static fn($r) => $r['year_extra_day'], $rooms)),
        // CELLS, not rooms: the unit of work is a room x weekday, and one room can
        // have a spare Friday and a full Monday.
        'extra_day_cells' => array_sum(array_map(
            static fn($r) => count(array_filter($r['cells'], static fn($c) => $c['extra_day_n'] > 0)),
            $rooms)),
        // No `unsold_value_total` - see the cell-level note above.
        'queue'        => $fill['totals']['queue'] ?? 0,
        'enrolled'     => $enrolled,
        // Where the board came from: reference month, whether Kidsoft answered,
        // and how many children it named. A part-full future year is a state to
        // report, not an error to hide.
        'source'       => $board['source'],
        'not_enrolled_yet' => $notEnrolledYet,
        // The current year's roll at this centre, so the grid can state the gap
        // as a measured comparison rather than an assertion about the calendar.
        'enrolled_now'     => $enrolledNow,
        'kidsoft_error'    => $ksError,
    ];
}

/**
 * Reduce a year of one cell's daily booked counts to what a person can read.
 *
 * `typical` is the MEDIAN, not the mean: a room that runs at 15 all year but
 * closes for two weeks over Christmas averages below its real standing number,
 * and the standing number is the one being compared against the board.
 *
 * `weeks_free` is the point of the whole screen - how many of the year's
 * Mondays this room has a place going spare on. Capacity is per weekday and per
 * year, so a cell with no capacity recorded cannot answer it and says null
 * rather than guessing.
 *
 * `weeks` IS ITS DENOMINATOR, and it used to be a different number entirely
 * (fixed 13 Aug 2026). It held the count of dates Kidsoft returned a booking row
 * for, while `weeks_free` counts out of every weekday in the year, missing dates
 * included - so the tooltip printed "empty on 52 of 36". Both of this field's
 * only two readers pair it with `weeks_free`, and both want the year's count, so
 * the field was wrong rather than the sentences. The old meaning was read by
 * nothing and is not preserved: a fraction whose halves come from two different
 * populations is not a fraction.
 */
function booked_year_summary(array $series, ?int $capacity, int $weekdaysInYear): array {
    if (!$series) {
        return ['typical' => 0, 'min' => 0, 'max' => 0, 'weeks' => $weekdaysInYear,
                'weeks_free' => $capacity === null ? null : $weekdaysInYear,
                'weeks_over' => 0, 'free_days' => $capacity === null ? null : $capacity * $weekdaysInYear];
    }
    $vals = array_values($series);
    sort($vals);
    $n = count($vals);

    $weeksFree = $weeksOver = 0;
    $freeDays = 0;
    if ($capacity !== null) {
        foreach ($vals as $v) {
            if ($v < $capacity) { $weeksFree++; $freeDays += $capacity - $v; }
            if ($v > $capacity) $weeksOver++;
        }
        // Dates with no booking row at all are still days of the year with the
        // room standing empty, so they count as free rather than vanishing.
        $missing = max(0, $weekdaysInYear - $n);
        $weeksFree += $missing;
        $freeDays  += $missing * $capacity;
    }

    return [
        'typical'    => $vals[intdiv($n, 2)],
        'min'        => $vals[0],
        'max'        => $vals[$n - 1],
        // How many of this weekday the year has - the denominator weeks_free is
        // counted against. NOT count($series): see the docblock.
        'weeks'      => $weekdaysInYear,
        'weeks_free' => $capacity === null ? null : $weeksFree,
        // Dates actually booked OVER capacity. Necessarily out of the dates
        // Kidsoft returned rows for - a date with no rows cannot be over.
        'weeks_over' => $weeksOver,
        // Unsold place-days on this cell across the year: the money number.
        'free_days'  => $capacity === null ? null : $freeDays,
    ];
}
