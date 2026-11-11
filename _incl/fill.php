<?php
// Filling vacancies - matching free room x weekday places to the waiting queue.
//
// This is the app's whole commercial purpose. The board shows where the holes
// are and the waitlist shows who is waiting, but under 10% of children attend
// all five days, so pairing them is a packing problem: a family wanting Mon/Wed
// can only fill a Mon/Wed hole, and the room has to accept their age. Doing that
// by eye across 16 rooms and 200 waiting families is what the spreadsheets made
// impossible.
//
// Every candidate offered here is one a human could have reached anyway - the
// queue order and the eligibility rules are the same ones used everywhere else.
// Nothing is auto-assigned; the output is a shortlist with an Offer button.

require_once __DIR__ . '/simple-mysqli.php';
require_once __DIR__ . '/rooms.php';
require_once __DIR__ . '/waitlist.php';
require_once __DIR__ . '/board.php';

/**
 * Age a child would be, in months, at the point they would start in $year.
 *
 * Rooms are banded by age and children age through the year, so "eligible" has
 * to be pinned to a moment. We use the later of 1 January and the date the
 * family actually needs care - offering a place a child is still too young for
 * on the day they would start is worse than offering it a month late.
 */
function fill_age_at_start(?string $dob, int $year, ?string $requiredFrom): ?int {
    if (!$dob) return null;
    $start = $year . '-01-01';
    if ($requiredFrom && substr($requiredFrom, 0, 10) > $start) $start = substr($requiredFrom, 0, 10);
    return age_months($dob, $start);
}

/**
 * How long after a place comes free the family taking it may start: 90 days
 * (Broc, 6 Nov 2026), and never past 31 December of the place's own year. The
 * board and Marketing both pass it as `start_window`; see that option.
 */
const FILL_START_WINDOW_DAYS = 90;

/**
 * Free places and ranked candidates for every room x weekday at a centre.
 *
 * @param array $opts  'min_free' => only cells with at least this many free
 *                     'limit'    => candidates listed per cell (default 5)
 *                     'board'    => a board_load() result to reuse, so a caller
 *                                   that already has one does not derive it twice
 *                     'start_window' => days after the place comes free (the
 *                                   board's `from`, or the day the centre opens)
 *                                   that a family may start and still be
 *                                   ELIGIBLE, capped at 31 December of $year. A
 *                                   later starter becomes a near miss with the
 *                                   reason on it, not a missing row. ABSENT
 *                                   MEANS NO WINDOW. cells_year() and
 *                                   marketing_centre() pass it, so the board and
 *                                   Marketing agree about who is waiting. See
 *                                   the note in the body. (It replaced
 *                                   `starts_by`, the year-end cut Marketing used
 *                                   to pass on its own.)
 * @return array{year:int, rooms:array, totals:array}
 */
function fill_suggestions(int $centreId, int $year, array $opts = []): array {
    $limit = max(1, (int)($opts['limit'] ?? 5));
    // rooms_for_centre() rather than the board's rooms, because the sub-quota
    // rows are needed RAW here - board_load() maps them for display and drops
    // the age bounds subquota_matches() tests.
    $rooms = rooms_for_centre($centreId, $year);
    if (!$rooms) return ['year' => $year, 'rooms' => [], 'totals' => ['free' => 0, 'cells' => 0]];

    // OCCUPANCY COMES FROM THE BOARD, WHICH COMES FROM KIDSOFT (11 Aug 2026).
    // This counted `placements` rows and their DOBs, exactly as
    // room_day_vacancies() did until 6 Aug - and that table has been an
    // ANNOTATION store since, holding one row per child somebody has flagged or
    // noted, which today is none of them. So every cell computed as fully free
    // and every sub-quota as empty: this function was offering places in rooms
    // that are full, and its "blocked" reason - the only place a sub-quota is
    // ever enforced, since the offer path checks vacancies alone - could not
    // fire at all. Nothing was visibly wrong only because the panel gates the
    // shortlist on the board's own free figure; the day a sub-quota is
    // configured on the Rooms screen, it would have been.
    //
    // Taking both from board_load() also buys what that change bought: fill and
    // the board agree by construction rather than by two calculations happening
    // to match, which is what put a different free count on two screens before.
    $board = $opts['board'] ?? board_load($centreId, $year);

    // A PLACE HELD FOR A FAMILY WHO STARTS IN SIX MONTHS IS A PLACE EMPTY FOR SIX
    // MONTHS (6 Nov 2026). Tuggerah, Little Angels Pink, Wednesday 2027: the
    // first name under "Could take this day" was Enzo Galli, #3 in the queue,
    // who does not start until 12/07/2027. Start enrolling him and the place
    // sits empty from January to July. On the same board Baby Penn, starting
    // 4/01/2028, was offered 2027 places in two rooms. Measured that day across
    // the group's 2027 boards: 141 of the 638 cards on cells with a place free
    // started after 1/04/2027, and 79 of those 146 shortlists changed. None was
    // left empty - every cell still had somebody who could start in time.
    //
    // Counted from the day the PLACE COMES FREE, which is the board's own `from`
    // (today on this year's board, 1 January on next year's, or the date
    // somebody picked), not from today. In November a 2027 place is empty from
    // 1 January whoever takes it, so a family starting 1/03/2027 leaves it
    // empty two months, not four. A finished year has no `from` and no window.
    // A centre that opens part-way through the year has nothing free before it
    // opens, so its places come free on that day - centre_year_start().
    //
    // AND NEVER PAST 31 DECEMBER (Broc, 6 Nov 2026): "very few (if any) children
    // continue in the same room across years ... there should be a hard
    // delimitation between the years." On the 2026 board in November the
    // 90 days ran to 4/02/2027, so Erina's 2026 places listed families starting
    // in February 2027 - children who will be in a 2027 room, if this one at
    // all. A 2027 start is the 2027 board's question, and that board asks it.
    //
    // A ROW WITH NO START DATE IS KEPT, for the reason waitlist_filter_start()
    // gives: the absence is silence, not an answer.
    $opensOn = null;
    $startBy = null;
    if (!empty($board['from'])) {
        $opensOn = max((string)$board['from'], (string)centre_year_start($board['centre'], $year));
    }
    if (isset($opts['start_window']) && $opensOn !== null) {
        $startBy = min($year . '-12-31', (new DateTimeImmutable($opensOn))
            ->modify('+' . (int)$opts['start_window'] . ' days')->format('Y-m-d'));
    }

    $cellsByRoom = [];   // room => weekday => board cell
    $ages        = [];   // room => weekday => [ageMonths, ...]
    foreach ($board['rooms'] as $r) {
        $rid = (int)$r['id'];
        $cellsByRoom[$rid] = $r['cells'];
        foreach ($r['placements'] as $p) {
            // Same rule board_load() counts by: a waitlisted or no-position row
            // is on the board but is not sitting in a place.
            if (in_array($p['status'], ['waitlist', 'no_position'], true)) continue;
            foreach ($p['days'] as $wd) $ages[$rid][$wd][] = $p['age_months'];
        }
    }

    // The queue: anyone still wanting days. Already in priority order.
    $queue = array_values(array_filter(
        waitlist_for_centre($centreId),
        // NOT a family Kidsoft already has an offer out to (15 Oct 2026). Our
        // own offers took a family out of this list by changing their status to
        // `offered`; a Kidsoft offer leaves the status at `waitlisted`, so it is
        // tested here instead - otherwise the cell that holds their place would
        // list them as a candidate for it.
        static fn($w) => in_array($w['status'], ['waitlisted', 'partially_placed'], true) && $w['days']
                         && empty($w['kidsoft_offer'])
    ));

    $out = [];
    $totalFree = 0;
    $totalCells = 0;

    foreach ($rooms as $room) {
        $rid = (int)$room['id'];
        $cells = [];

        foreach (array_keys(WEEKDAYS) as $wd) {
            // Capacity is PER WEEKDAY - a room can have places free on Tuesday
            // and none on Wednesday purely because it is licensed for fewer.
            $cell = $cellsByRoom[$rid][$wd] ?? null;
            $capacity = $room['capacity'][$wd] ?? null;
            $occ  = (int)($cell['occupied'] ?? 0);
            // Already counted by the board: a pending offer has promised the
            // place, so it is not free to promise again.
            $prom = (int)($cell['promised'] ?? 0);
            // NOT `occupied`: the fullest the cell gets between today and
            // December, which is the only figure a place can honestly be sold
            // against. It accounts for later starters and for children whose
            // notice is in. This MUST match board_load() - fill.php is the ONLY
            // place a sub-quota is ever enforced, so a cell that looks free here
            // builds an Offer button for a place the offer endpoint will refuse.
            $hold = (int)($cell['holding'] ?? $occ);
            // Unknown capacity is not zero vacancies - it is an unanswerable
            // question, and is reported as such rather than as a full room.
            $free = $capacity === null ? null : max(0, $capacity - $hold - $prom);

            $candidates = [];
            if ($free) {
                foreach ($queue as $w) {
                    if (!in_array($wd, $w['days'], true)) continue;

                    $age = fill_age_at_start($w['child_dob'], $year, $w['required_from']);
                    $ageOk = room_accepts_age($room, $age);

                    // Sub-quotas cap part of a room by age, so a place can be
                    // free in the room and still closed to this particular child.
                    $blockedBy = null;
                    foreach ($room['subquotas'] as $q) {
                        if (!subquota_matches($q, $age)) continue;
                        $used = 0;
                        foreach ($ages[$rid][$wd] ?? [] as $a) if (subquota_matches($q, $a)) $used++;
                        if ($used >= (int)$q['max_children']) { $blockedBy = $q['label']; break; }
                    }

                    $startsOn = substr((string)($w['required_from'] ?? ''), 0, 10);
                    $late = $startBy !== null && $startsOn !== '' && $startsOn > $startBy;
                    // A later YEAR is said first and in those words: it is not
                    // this board's family at all, so the age they will be in
                    // 2028 is not the reason worth printing on a 2027 card.
                    $nextYear = $late && $startsOn > $year . '-12-31';

                    $candidates[] = [
                        'waitlist_id' => (int)$w['id'],
                        'name'        => trim($w['child_first_name'] . ' ' . $w['child_last_name']),
                        'guardian'    => $w['guardian_name'],
                        // A shortlist you cannot act on is a list, not a
                        // shortlist. The queue's phone numbers come from Kidsoft
                        // (99% of applications carry a parent mobile), so the one
                        // thing this panel exists to prompt - a phone call - no
                        // longer needs a second screen to look the number up.
                        'guardian_phone' => $w['guardian_phone'],
                        'guardian_email' => $w['guardian_email'],
                        'queue'       => (int)$w['queue_position'],
                        'reasons'     => $w['reasons'],
                        // The same reasons in pieces, so a sibling it names opens
                        // that child - see priority_reason_parts().
                        'reason_parts'=> $w['reason_parts'] ?? null,
                        // A family on the Do Not Enrol list stays a candidate -
                        // the match can be wrong - but the card says so before
                        // anybody rings them. See _incl/do-not-enrol.php.
                        'dne'         => $w['dne'] ?? null,
                        // Wanted a place long ago and still waiting - a prompt
                        // to check, never a reason to skip. See waitlist_stale().
                        'stale'       => $w['stale'] ?? null,
                        'days'        => $w['days'],
                        'age_months'  => $age,
                        'date_added'  => $w['date_added'],
                        // Only a start AFTER the place comes free: a family who
                        // needed care from August and is still waiting can
                        // start the day it is offered, and "starts 3/08/2026"
                        // on a November card would say otherwise.
                        'starts_on'   => $startsOn !== '' && $opensOn !== null
                                         && $startsOn > $opensOn ? $startsOn : null,
                        'eligible'    => $ageOk && $blockedBy === null && !$late,
                        'blocked'     => $nextYear
                            ? 'Starts in ' . substr($startsOn, 0, 4)
                            : (!$ageOk ? 'Outside this room\'s age range'
                            : ($blockedBy !== null ? $blockedBy . ' is full on this day'
                            : ($late ? 'Starts more than ' . (int)$opts['start_window'] . ' days out' : null))),
                    ];
                }
                // Whoever can actually take the place, in queue order.
                $eligible = array_values(array_filter($candidates, static fn($c) => $c['eligible']));
                usort($eligible, static fn($a, $b) => $a['queue'] <=> $b['queue']);

                if ($eligible) {
                    // Mixing in families who cannot take the place is noise: on a
                    // real board most of the queue is the wrong age for any given
                    // room, and it buries the two names that matter.
                    $candidates = array_slice($eligible, 0, $limit);
                } else {
                    // Nothing fits. Show the near misses WITH the reason, so an
                    // empty shortlist does not read as an empty waitlist - the
                    // answer "three families want Friday but all are too old" is
                    // the useful one.
                    $blocked = array_values(array_filter($candidates, static fn($c) => !$c['eligible']));
                    usort($blocked, static fn($a, $b) => $a['queue'] <=> $b['queue']);
                    $candidates = array_slice($blocked, 0, 3);
                }
            }

            $cells[$wd] = [
                'occupied'   => $occ,
                'promised'   => $prom,
                'holding'    => $hold,
                'capacity'   => $capacity,
                'free'       => $free,
                'candidates' => $candidates,
                'eligible_count' => count(array_filter($candidates, static fn($c) => $c['eligible'])),
            ];
            if ($free) { $totalFree += $free; $totalCells++; }
        }

        $out[] = [
            'id'             => $rid,
            'name'           => $room['name'],
            'capacity'       => $room['capacity'],
            'capacity_max'   => $room['capacity_max'],
            'min_age_months' => $room['min_age_months'],
            'max_age_months' => $room['max_age_months'],
            'subquotas'      => array_map(static fn($q) => [
                'label' => $q['label'], 'describe' => subquota_describe($q),
            ], $room['subquotas']),
            'cells'          => $cells,
            'week_free'      => $room['capacity_max'] === null
                ? null
                : array_sum(array_map(static fn($c) => (int)$c['free'], $cells)),
        ];
    }

    return [
        'year'   => $year,
        'rooms'  => $out,
        'totals' => ['free' => $totalFree, 'cells' => $totalCells, 'queue' => count($queue)],
    ];
}
