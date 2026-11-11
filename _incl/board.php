<?php
// The placement board - the app's core artifact and the direct replacement for
// the per-centre TUG_Attendances_<year>.xlsx workbooks.
//
// One board = one centre, one year. Per room: the weekdays across, the children
// placed on each, and the capacity for that year. A cell's occupancy is simply
// how many placements include that weekday - the workbook's numbered slots are
// spreadsheet rows, not seats, so nothing is assigned to a slot number here.
//
// board_overview() aggregates every centre's board into the group-wide landing
// view (the Board's default screen); a specific centre drills back down to the
// single-centre board above.

require_once __DIR__ . '/simple-mysqli.php';
require_once __DIR__ . '/rooms.php';
require_once __DIR__ . '/board-live.php';
require_once __DIR__ . '/day-holds.php';  // day_holds_year() - places kept for a child who is not booked on them
require_once __DIR__ . '/dates.php';      // syd_date() - Sydney wall-clock strings are formatted here, never in the browser

/**
 * The Kidsoft waitlist statuses that mean AN OFFER IS OUT AND THE PLACE IS TAKEN,
 * with what the team calls each one and whether the family has already answered.
 *
 * OFFERS ARE SENT IN KIDSOFT, and since 15 Oct 2026 this app reads them rather
 * than asking staff to make them twice. Measured that morning over all seven
 * centres with `WithOfferedDays=true`: 23 applications sat at one of these
 * statuses and 23 of 23 carried OfferedDays AND a CareTypeMap.RoomID that maps to
 * one of our rooms - 70 place-days. 0 of the 23 had an offer made with this app's
 * own Offer button, so the board was showing every one of those 70 as free to
 * sell again. Meanwhile 12 of the 13 offers ever made here had lapsed on our
 * 14-day clock while Kidsoft carried the same families on to Form Submitted or
 * Enrolled. Broc chose to read Kidsoft and retire the button.
 *
 * ONE LIST, here, because the board is what holds the place and every other
 * reader (the queue, Fill, search, the work layer) composes from it.
 *
 *   * `Offered` / `OfferedExpiryReminder` - out, waiting on the family. Kidsoft's
 *     ExpiryDate is the offer's own deadline (Stella Knox: offered 14 Oct,
 *     expires 19 Oct).
 *   * `Form Submitted` - the family returned the enrolment form; the next move is
 *     the centre's. Its ExpiryDate is NOT a live clock: 9 of the 11 Wamberal rows
 *     were past it and Kidsoft still had them at this status, so the hold does
 *     not lapse with it.
 *   * `Re-submit Required` - one row, Heidi Gow at Terrigal: OfferedDays present
 *     and a 21-day DateAdded->ExpiryDate window, the offer side of the funnel by
 *     the measure waitlist-import.php uses. Read as the form sent back for
 *     correction, so the place is still theirs. THAT READING IS AN INFERENCE
 *     from one row; holding a place that is not promised under-sells one seat,
 *     not holding one that is promised sells it twice, so it holds.
 *
 * `Enrolled` is NOT here: by then the child's own bookings hold the place.
 */
const KIDSOFT_OPEN_OFFER = [
    'Offered'               => ['label' => 'Offered in Kidsoft', 'answered' => false],
    'OfferedExpiryReminder' => ['label' => 'Offered in Kidsoft', 'answered' => false],
    'Form Submitted'        => ['label' => 'Form submitted',     'answered' => true],
    'Re-submit Required'    => ['label' => 'Re-submit required', 'answered' => true],
];

/**
 * The year a screen opens on when nothing is remembered: THE CURRENT CALENDAR
 * YEAR (Australia/Sydney).
 *
 * The year choice STICKS in the session (`year_current()`), so this is only ever
 * the starting point for a fresh session - pick a year and it follows you
 * between screens and centres until you change it.
 *
 * CHANGED 6 Aug 2026, from "the latest year we hold capacities for". That was the
 * year being PLANNED, on the reasoning that rooms are set up a year ahead and the
 * August round plans next year. It stopped being right when the board became
 * derived from Kidsoft: a future year is genuinely empty until each centre
 * processes its re-enrolments, so a fresh session landed on a board reading 0%
 * at every centre, and every figure on the landing screen was vacuous. The
 * current year is where the children, the bookings and the sellable places
 * actually are.
 *
 * IT MUST STAY THE ONE DEFINITION. Every screen calls this, and the last time two
 * screens disagreed about the default - the Board on the planning year, Rooms on
 * `date('Y')` - simply moving between them silently changed which year you were
 * editing. Do not reintroduce a second default anywhere.
 *
 * The planning year has not gone away; it is just not the DEFAULT. `year_list()`
 * makes sure the stepper can always reach it and one year beyond.
 */
function board_default_year(): int {
    return (int)date_create('today', new DateTimeZone('Australia/Sydney'))->format('Y');
}

/**
 * A fill percentage that cannot contradict the free figure printed beside it.
 *
 * ROUNDING TURNED "1 PLACE FREE" INTO "100% FILLED" (10 Sep 2026). Niagara Park
 * 2026 holds 489 of 490 place-days, which is 99.796% and rounds straight up to
 * 100 - so the summary read "Filled 100%" directly next to "Places free 1", and
 * the group table said 100% for a centre with a place still to sell. Reported
 * from the board as: "Filled says 100%, but there is 1 place free."
 *
 * ONE DECIMAL PLACE, because a whole number cannot resolve one place-day at the
 * only level this figure is ever shown at. Every rendered fill % is a centre or
 * the group, where the denominator runs 293 (Terrigal) to 2,718 (group): one
 * place is 0.34% at the narrowest and 0.04% at the widest, so a whole number
 * spends the entire top of its range unable to tell full from nearly full.
 * 99.8% says what 99% only implies. Measured across all seven centres and both
 * years, 14 centre-years - see _incl/docs/placement-board.md.
 *
 * THE CLAMP IS STILL NEEDED AT ONE DECIMAL, it just bites later: the group total
 * of 2,717 of 2,718 place-days is 99.963% and still rounds to 100.0. So 100% is
 * reserved for a room where nothing is left - held at 99.9 while any place is
 * unsold, and at 0.1 while anybody is placed, so neither end can round away a
 * whole child. Over capacity still reports over capacity - `over` is a real
 * state and this must not hide it.
 *
 * Returns a NUMBER, never a string: the colour thresholds compare it. Format it
 * for display with window.pctText() in static/js/app.js, which is what keeps
 * 94.0% from printing as "94%" beside a 99.8% - JS drops a trailing zero and PHP
 * does not.
 *
 * @param ?int $capacity null or 0 = nothing known to measure against
 */
function board_pct($occupied, ?int $capacity): ?float {
    if (!$capacity || $capacity <= 0) return null;
    $pct = round($occupied / $capacity * 100, 1);
    if ($pct >= 100 && $occupied < $capacity) return 99.9;
    if ($pct <= 0   && $occupied > 0)         return 0.1;
    return $pct;
}

/**
 * The years a stepper may travel between: every year anyone holds capacity for,
 * plus the current year, plus one beyond the furthest - so next year is always
 * reachable even before its capacities are seeded, which is how a year that has
 * nothing in it yet gets looked at at all.
 *
 * @param array $also extra years to guarantee are present (e.g. the one on screen)
 */
function year_list(array $also = []): array {
    $rows  = dbx()->query('SELECT DISTINCT year FROM room_year_capacity')->fetchAll('col');
    $years = array_filter(array_map('intval', array_merge($rows, $also, [board_default_year()])),
                          static fn($y) => $y >= 2000 && $y <= 2100);
    if (!$years) $years = [board_default_year()];
    // CONTIGUOUS, no holes. The stepper walks this array by INDEX, so a gap makes
    // one press skip a year - with capacities for 2026-2027 and a link pointing
    // at 2029, the naive union was [2026, 2027, 2029, 2030] and the arrow jumped
    // straight over 2028 while its tooltip cheerfully named the year it was
    // skipping to.
    return range(min($years), max($years) + 1);
}

/**
 * A board date from the query string, or null. Accepts only a real yyyy-mm-dd
 * inside the years a stepper may reach, so a junk value falls back to the board
 * as it reads today rather than to an error. See board_from().
 */
function board_parse_from($raw): ?string {
    $raw = trim((string)$raw);
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $raw, $m)) return null;
    if (!checkdate((int)$m[2], (int)$m[3], (int)$m[1])) return null;
    if ((int)$m[1] < 2000 || (int)$m[1] > 2100) return null;
    return $raw;
}

/**
 * The first and last day a board date may name: the years the stepper can reach
 * (year_list()), so the date box and the arrows cannot disagree about which years
 * exist. A year outside them has no rooms or capacity to read, and a past one
 * costs a cold read of every month in it (50s for Tuggerah 2025, measured).
 *
 * @return array{0:string, 1:string} [yyyy-mm-dd, yyyy-mm-dd]
 */
function board_from_bounds(): array {
    $years = year_list();
    return [min($years) . '-01-01', max($years) . '-12-31'];
}

/**
 * A chosen board date from the query string, checked against board_from_bounds():
 * null when none was given, and a readable refusal for a date outside the years
 * the board holds rather than a quiet fall back to today.
 */
function board_from_request($raw): ?string {
    if (trim((string)$raw) === '') return null;
    $from = board_parse_from($raw);
    if ($from === null) api_fail('That date looks wrong.');
    [$lo, $hi] = board_from_bounds();
    if ($from < $lo || $from > $hi) {
        api_fail('The board holds ' . substr($lo, 0, 4) . ' to ' . substr($hi, 0, 4) . '. Pick a date in those years.');
    }
    return $from;
}

/**
 * Build the board for a centre and year.
 *
 * THE CHILDREN COME FROM KIDSOFT, NOT FROM OUR TABLES (changed 6 Aug 2026).
 * Kidsoft is the window: it alone knows who actually holds a place. Our
 * `placements` table survives only as an annotation store for the things Kidsoft
 * has no field for - the workbook's cell-colour status, the four child-level
 * flags, a note - plus the rare child pencilled in before Kidsoft knows them.
 * See the header of _incl/board-live.php for the measurements behind this.
 *
 * @param array $opts 'refresh' => re-read this centre-year from Kidsoft now
 *                    'from'    => a date inside $year to read the board AS AT
 *                                 (board_parse_from() has checked it), rather
 *                                 than today - see board_from()
 * @return array{centre:array, year:int, from:?string, rooms:array, totals:array, source:array}
 */
function board_load(int $centreId, int $year, array $opts = []): array {
    $centre = centre_get($centreId);
    if (!$centre) throw new InvalidArgumentException('unknown centre');

    // THE DATE THIS BOARD COUNTS FROM (16 Oct 2026). Null = today, as it always
    // was. Only what the board COUNTS moves with it - the forward peak, the
    // declared departures, the age bands. Offers, held days and work in progress
    // are things true NOW, and a board looked at for March still shows today's.
    //
    // THE REFERENCE MONTH DOES NOT FOLLOW IT. That read is only the fallback for
    // a Kidsoft outage, and the hourly warm keeps THIS month fresh; pointing it at
    // the chosen month made every pick a cold 4-second read (measured on
    // Tuggerah) for a list the forward roster replaces anyway.
    $chosen = isset($opts['from']) && substr((string)$opts['from'], 0, 4) === (string)$year
        ? (string)$opts['from'] : null;

    $rooms = rooms_for_centre($centreId, $year);
    if (!$rooms) {
        return ['centre' => $centre, 'year' => $year, 'from' => board_from($year, $chosen),
                'rooms' => [], 'totals' => board_empty_totals(),
                'source' => ['month' => board_reference_month($year), 'unreachable' => false,
                             'children' => 0, 'unmapped' => 0, 'provisional' => 0]];
    }

    $live = board_live_children($centreId, $year, $opts);
    $ann  = board_annotations($centreId, $year);
    // WHAT MAY BE SOLD IS NOT WHAT IS OCCUPIED, and the reference month cannot
    // answer the first question: it misses a child who starts next month, and it
    // double-counts a child who leaves mid-month against the one who replaces
    // them. Both are answered by asking what the room actually looks like on each
    // remaining date and taking the fullest. See board_forward_occupancy().
    $peak = board_forward_occupancy($centreId, $year, $chosen);
    // Children whose notice is in. Needed here as well as inside the peak, so a
    // cell can say a place is COMING FREE and when - the thing the team acts on.
    $exits = board_departures($centreId, $year, $chosen);

    // THE BOARD IS NOW THE FORWARD ROSTER, NOT THE REFERENCE MONTH (21 Aug 2026).
    //
    // The team's rule, straight from enrolments: a child enrolled into a spot
    // holds that spot from now, whatever their start date. The reference month
    // could not express it. Measured group-wide the morning it was reported:
    //
    //   * 14 children enrolled to start after the month, holding 30 cells,
    //     appeared in NO room on the board - Halle and Olive Dyer hold five days
    //     each at Kanwal from 28 September and were nowhere on it. `free` had
    //     already reserved their places (the peak sees them), so the grid was
    //     showing places gone with nobody occupying them.
    //   * 14 cells counted ONE place twice, because the month's union puts a
    //     child who left on the 12th beside the one who replaced them on the
    //     19th. Four of those cells wore a red OVER CAPACITY badge that was
    //     fiction: Terrigal Mighty Meteors holds exactly 15 on every Wednesday
    //     in August against a capacity of 15, and read 16.
    //
    // Both faults are the same fault - a month is not a moment, and "who holds a
    // place" is not "who was booked at some point in August". Reading from TODAY
    // to 31 December fixes both, and the leaver whose last day has already gone
    // simply drops out instead of having to be paired with anybody.
    //
    // The reference month survives as the FALLBACK, for the two cases with no
    // future to read: a past year, and a Kidsoft outage. It is still what
    // `source.month` reports, because that is provenance for the names.
    $holders = board_forward_roster($centreId, $year, $peak, $live);

    // One row per (child, room), exactly as before: a child can legitimately be
    // booked into two rooms in one week (~2% of real Tuggerah data), so the board
    // needs a row per room rather than per child.
    $rows = []; $provisionalCount = 0;
    foreach ($rooms as $room) {
        $rid = (int)$room['id'];
        foreach ($holders[$rid] ?? [] as $ch) {
            $a = $ann['by_child'][$ch['ks_id'] . '|' . $rid] ?? null;
            $rows[] = [
                'id'               => $a['id'] ?? null,
                'room_id'          => $rid,
                'kidsoft_child_id' => $ch['ks_id'],
                'child_first_name' => $ch['first'],
                'child_last_name'  => $ch['last'],
                'child_dob'        => $ch['dob'],
                'days'             => $ch['days'],
                // weekday => {first,last} in THIS room, today to 31 December.
                // Empty on the fallback paths, which have no forward dates.
                'dates'            => $ch['dates'] ?? [],
                // Booked and being billed means the place IS held, which is what
                // 'confirmed' means on this board. An annotation can say otherwise.
                'status'           => $a['status'] ?? 'confirmed',
                'notes'            => $a['notes'] ?? null,
                'flag_list'        => $a['flags'] ?? [],
                'provisional'      => false,
            ];
        }
        // Children this app knows about and Kidsoft does not yet - a family we
        // have offered a place to, or one pencilled in ahead of the paperwork.
        // We cannot write to Kidsoft, so these exist only here.
        foreach ($ann['provisional'][$rid] ?? [] as $p) {
            $rows[] = [
                'id'               => $p['id'],
                'room_id'          => $rid,
                'kidsoft_child_id' => null,
                'child_first_name' => $p['first'],
                'child_last_name'  => $p['last'],
                'child_dob'        => $p['dob'],
                'days'             => $p['days'],
                'dates'            => [],
                'status'           => $p['status'],
                'notes'            => $p['notes'],
                'flag_list'        => $p['flags'],
                'provisional'      => true,
            ];
            $provisionalCount++;
        }
    }
    usort($rows, static fn($a, $b) =>
        [$a['child_last_name'], $a['child_first_name']] <=> [$b['child_last_name'], $b['child_first_name']]);

    // A pending offer has already promised the place (see room_day_vacancies()),
    // so it is not free to promise again. Fill has always subtracted these; the
    // board did not, which meant the same cell read as free on one screen and
    // taken on the other. The board is the screen people trust, so it accounts
    // for them here - kept separate from `occupied`, because an offer is not a
    // placement until the family says yes.
    // NAMED, NOT JUST COUNTED - the same rule `held_children` follows below, and
    // it arrived from the same question (Broc, 9 Sep 2026: *"could the held days
    // for the ones we have offered be visible and display like the enrolled
    // children?"*). A cell reading "0 free" over a roll that plainly did not fill
    // it sends the reader hunting; "2 held by a pending offer" told them how many
    // and not which, and the offers screen is a different screen.
    //
    // ONE ROW PER OFFER, not per (room, weekday), because the day strip on the
    // panel shows the WHOLE set of days that offer holds and rings the one this
    // cell is about - a family offered Mon/Tue/Thu is one promise, not three, and
    // splitting it per weekday is what made the roll unreadable before it became
    // a table. The count is derived from the same rows, so the figure and the
    // list cannot disagree.
    $ids   = array_column($rooms, 'id');
    $marks = dbx()->whereIn($ids);
    $promised = $promisedWho = [];
    foreach (dbx()->query(
        "SELECT o.id, o.room_id, o.waitlist_id, o.expires_at, o.start_date,
                w.child_first_name, w.child_last_name,
                GROUP_CONCAT(od.weekday ORDER BY od.weekday) AS days
         FROM offers o
         JOIN offer_days od ON od.offer_id = o.id
         JOIN waitlist_applications w ON w.id = o.waitlist_id
         WHERE o.year = ? AND o.status = 'pending' AND o.room_id IN($marks)
           AND (od.accepted IS NULL OR od.accepted = 1)
         GROUP BY o.id",
        array_merge([$year], $ids)
    )->fetchAll() as $r) {
        $days = array_map('intval', explode(',', (string)$r['days']));
        $who = [
            // The offer's own id, and it IS an identity - unlike a derived
            // child's null `id`, see the trap in placement-board.md. The roster
            // groups this room's five cells back into one row per family and
            // needs a key it can trust to do it.
            'offer_id'    => (int)$r['id'],
            // Present on both kinds of promise so the panel reads one shape;
            // see the Kidsoft block below.
            'kidsoft'     => false,
            'state'       => null,
            'name'        => trim($r['child_first_name'] . ' ' . $r['child_last_name']),
            // The panel opens the family's application off this, exactly as the
            // candidate cards do - every action on one of these rows is a
            // conversation with a family, and the contact log is behind the name.
            'waitlist_id' => (int)$r['waitlist_id'],
            'days'        => $days,
            // Formatted HERE. These are Sydney wall-clock strings and new Date()
            // in the viewer's timezone is the reinterpretation _incl/dates.php
            // forbids - the same rule the hold_until below follows.
            'expires'     => syd_date($r['expires_at']),
            'starts'      => $r['start_date'] ? syd_date($r['start_date']) : null,
        ];
        foreach ($days as $wd) {
            $promised[(int)$r['room_id']][$wd] = ($promised[(int)$r['room_id']][$wd] ?? 0) + 1;
            $promisedWho[(int)$r['room_id']][$wd][] = $who;
        }
    }

    // ---- Offers made IN KIDSOFT ---------------------------------------------
    //
    // The same promise, read rather than recorded (15 Oct 2026) - see
    // KIDSOFT_OPEN_OFFER at the top of this file for the measurement. The room
    // and days are Kidsoft's (waitlist-import.php writes them off OfferedDays and
    // CareTypeMap); the year is the one the offered place starts in, never behind
    // this one.
    //
    // NOT TWICE FOR ONE FAMILY. An application still carrying a pending offer
    // made with the retired button is already counted above, so it is skipped
    // here whatever Kidsoft says about it.
    //
    // NOT TWICE FOR ONE CHILD, EITHER. A child already in care can be offered a
    // place (one of the 23 carried a ChildID); a day they are already booked
    // into in that same room is held by the booking and costs nothing more.
    $bookedHere = [];
    foreach ($rows as $p) {
        if (!$p['kidsoft_child_id']) continue;
        foreach ($p['days'] as $wd) $bookedHere[$p['kidsoft_child_id']][(int)$p['room_id']][(int)$wd] = true;
    }
    $ksStatuses = array_keys(KIDSOFT_OPEN_OFFER);
    $ksMarks    = dbx()->whereIn($ksStatuses);
    foreach (dbx()->query(
        "SELECT a.id, a.kidsoft_offer_room_id AS room_id, a.kidsoft_child_id,
                a.kidsoft_status, a.kidsoft_expires_at, a.required_from,
                a.child_first_name, a.child_last_name,
                GROUP_CONCAT(od.weekday ORDER BY od.weekday) AS days
         FROM waitlist_applications a
         JOIN waitlist_offered_days od ON od.waitlist_id = a.id
         WHERE a.status = 'waitlisted' AND a.kidsoft_status IN($ksMarks)
           AND a.kidsoft_offer_room_id IN($marks)
           -- Never behind the current year: the import's rule for for_year, for
           -- the same reason - a start date already passed is a family who still
           -- needs the place, not one who needed it last year.
           AND GREATEST(YEAR(a.required_from), YEAR(CURDATE())) = ?
           AND NOT EXISTS (SELECT 1 FROM offers o
                            WHERE o.waitlist_id = a.id AND o.status = 'pending')
         GROUP BY a.id",
        array_merge($ksStatuses, $ids, [$year])
    )->fetchAll() as $r) {
        $rid  = (int)$r['room_id'];
        $days = array_values(array_filter(
            array_map('intval', explode(',', (string)$r['days'])),
            static fn($wd) => !isset($bookedHere[$r['kidsoft_child_id'] ?? ''][$rid][$wd])
        ));
        if (!$days) continue;
        $state = KIDSOFT_OPEN_OFFER[$r['kidsoft_status']];
        $who = [
            // A STRING KEY, deliberately not an offers.id: there is no row in
            // `offers` for this promise, and the room sheet groups the five cells
            // back into one row per family on this field. Prefixed so it can
            // never collide with a real offer's integer id.
            'offer_id'    => 'k' . (int)$r['id'],
            'kidsoft'     => true,
            'state'       => $state['label'],
            'name'        => trim($r['child_first_name'] . ' ' . $r['child_last_name']),
            'waitlist_id' => (int)$r['id'],
            'days'        => $days,
            // Only while the family has still to answer. A submitted form's
            // ExpiryDate is not a deadline anybody is working to (see the
            // constant), and printing a past date as "expires" would read as an
            // offer that should have lapsed.
            'expires'     => (!$state['answered'] && $r['kidsoft_expires_at'])
                ? syd_date($r['kidsoft_expires_at']) : null,
            'starts'      => $r['required_from'] ? syd_date($r['required_from']) : null,
        ];
        foreach ($days as $wd) {
            $promised[$rid][$wd] = ($promised[$rid][$wd] ?? 0) + 1;
            $promisedWho[$rid][$wd][] = $who;
        }
    }

    // ---- Places kept for a child who has not re-enrolled --------------------
    //
    // Enrolments team rule, 8 Sep 2026: an enrolled child must be re-offered the
    // SAME DAYS, so until they answer or their clock runs out the room they move
    // up into is not free to sell to anybody else. See reenrolment_holds().
    //
    // ONLY ON THE YEAR BEING ROLLED INTO. A hold is derived from the roll in the
    // year before it, so any other year either has no round to hold against (the
    // current year is already booked) or would walk backwards through the years
    // for no purpose. That test is also what bounds the cost: the board for this
    // year, and every past year, never asks.
    //
    // reenrolment.php reads the roll off board_live_children()/forward_roster()
    // rather than board_load(), precisely so this call cannot come back round.
    // DO NOT "TIDY" IT TO USE board_load() - the recursion has no bottom, because
    // holds for Y come from the roll in Y-1.
    // NAMED, NOT JUST COUNTED. A cell saying "4 held" and nothing else sends the
    // reader to guess which four, and the whole point of the panel is that the
    // grid's figures open into the children behind them.
    $heldWho = [];
    if ($year === (int)date_create('today', new DateTimeZone('Australia/Sydney'))->format('Y') + 1) {
        try {
            require_once __DIR__ . '/reenrolment.php';
            require_once __DIR__ . '/dates.php';   // syd_date() - dates are formatted here, never in the browser
            foreach (reenrolment_holds($centreId, $year - 1)['holds'] as $h) {
                // A lapsed hold keeps the family on the list and keeps chasing
                // them, but stops reserving the place: "other options may be
                // available" (enrolments team, 9 Sep 2026). `holds_place` is the
                // only field that may be read here.
                if (!$h['holds_place'] || !$h['to_room_id']) continue;
                foreach ($h['days'] as $wd) {
                    $heldWho[$h['to_room_id']][(int)$wd][] = [
                        'name'       => $h['name'],
                        'ks_id'      => $h['ks_id'],
                        // Where they are THIS year, which is what makes the row
                        // make sense on next year's board: this child is not in
                        // the room yet, they are moving up into it.
                        'from_room'  => $h['from_room'],
                        'days'       => $h['days'],
                        'hold_until' => syd_date($h['hold_until']),
                        'chase_due'  => (bool)$h['chase_due'],
                        'school_age' => (bool)$h['school_age'],
                    ];
                }
            }
        } catch (Throwable $e) {
            // The board must render when Kidsoft will not answer. Holding
            // nothing shows places as free that are spoken for, which is the
            // wrong direction - but showing no board at all is worse, and the
            // same call fails loudly on the re-enrolment screen itself.
            $heldWho = [];
        }
    }

    // ---- Places kept for a child who is not booked on them ------------------
    //
    // A day the centre is holding while a family is not attending it - the case
    // that produced it is a child on a Behaviour Support Plan whose Friday
    // booking has been removed while the centre works with the family. Kidsoft
    // has no record of a held day at all: the booking IS the record, and the
    // booking is what has gone. See _incl/day-holds.php.
    //
    // SUPPLY, NOT DEMAND. It comes off the free figure exactly as a pending
    // offer and a re-enrolment hold do; it is not a wish and never makes a cell
    // grantable.
    $reservedWho = day_holds_year($centreId, $year);

    // EVERY WEEKDAY EACH CHILD IS HELD OUT OF, so a cell can show the whole week
    // rather than only the day it is about. A child held out of Thursday AND
    // Friday otherwise appears on two cells with no hint of the other, which is
    // the shape block 1a was given a full day strip to fix.
    $reservedDays = [];
    foreach ($reservedWho as $rid => $byWd) {
        foreach ($byWd as $wd => $list) {
            foreach ($list as $h) {
                $k = $h['ks_id'] !== null ? 'k' . $h['ks_id'] : 'p' . (int)$h['placement_id'];
                $reservedDays[$rid][$k][] = (int)$wd;
            }
        }
    }

    // The day the staffing bands are read on - see staffing_band_on().
    $bandOn = staffing_band_on($year, $chosen);

    $byRoom = [];
    foreach ($rows as $p) {
        // Flags arrive as a list from the annotation, not as four columns.
        $flags = $p['flag_list'];
        $p['is_sibling']          = in_array('sibling', $flags, true);
        $p['is_staff_child']      = in_array('staff', $flags, true);
        $p['needs_two_educators'] = in_array('two_educators', $flags, true);
        $p['has_behaviour_plan']  = in_array('behaviour', $flags, true);
        $p['has_individual_plan'] = in_array('individual', $flags, true);
        $p['age_months'] = age_months($p['child_dob'], $year . '-01-01');
        // The ratio band and the day it changes, for the green tones on the
        // roster and the roll. Computed here because it is a date, and dates are
        // decided on the server's Sydney clock, never in the browser.
        $p['age_band']     = staffing_band(age_months($p['child_dob'], $bandOn));
        $next              = staffing_band_next($p['child_dob'], $p['age_band'], $bandOn, $year);
        $p['band_next_on'] = $next ? syd_date($next) : null;
        $byRoom[$p['room_id']][] = $p;
    }

    $totals = board_empty_totals();
    $out = [];

    foreach ($rooms as $room) {
        $placements = $byRoom[$room['id']] ?? [];

        // Keyed the one way this app keys a child - 'k' . ChildID, or 'p' . our
        // row - so a held row can say which days the child still attends.
        $daysByKey = [];
        foreach ($placements as $p) {
            $k = $p['kidsoft_child_id'] ? 'k' . $p['kidsoft_child_id']
               : ($p['id'] === null ? null : 'p' . (int)$p['id']);
            if ($k !== null) $daysByKey[$k] = $p['days'];
        }

        $cells = [];
        foreach (array_keys(WEEKDAYS) as $wd) {
            // Capacity is PER WEEKDAY (Kidsoft's operational positions differ by
            // day). null = not recorded, which stays unknown rather than zero.
            $capacity = $room['capacity'][$wd] ?? null;
            // A placement only occupies a place while it is actually held. A
            // waitlisted or unavailable-position row is recorded on the board but
            // is not taking a seat, so counting it would hide a real vacancy.
            $onDay = array_values(array_filter($placements, static fn($p) =>
                in_array($wd, $p['days'], true) && !in_array($p['status'], ['waitlist', 'no_position'], true)
            ));
            // EVERYONE WHO HOLDS THIS CELL AT ANY POINT FROM TODAY, which is not
            // the same as how many places that costs - see $holding below. A
            // leaver and their replacement both hold the cell and share one
            // place, so this list can be one longer than the figure. The dates on
            // the rows are what make that legible rather than wrong.
            $roster = count($onDay);

            // How long anybody holds this cell, which is what makes one child's
            // own dates worth printing. Taken from the roster rather than from
            // the calendar - see board_span_edge().
            $bounds = ['first' => null, 'last' => null];
            foreach ($onDay as $p) {
                $d = $p['dates'][$wd] ?? null;
                if (!$d) continue;
                if ($bounds['first'] === null || $d['first'] < $bounds['first']) $bounds['first'] = $d['first'];
                if ($bounds['last']  === null || $d['last']  > $bounds['last'])  $bounds['last']  = $d['last'];
            }

            $quotas = [];
            foreach ($room['subquotas'] as $q) {
                $used = 0;
                foreach ($onDay as $p) if (subquota_matches($q, $p['age_months'])) $used++;
                $quotas[] = [
                    'id' => (int)$q['id'], 'label' => $q['label'],
                    'used' => $used, 'max' => (int)$q['max_children'],
                    'full' => $used >= (int)$q['max_children'],
                ];
            }

            $promise    = (int)($promised[$room['id']][$wd] ?? 0);
            $promiseWho = $promisedWho[$room['id']][$wd] ?? [];
            $keepWho = $heldWho[$room['id']][$wd] ?? [];
            $keep    = count($keepWho);

            // A HELD DAY THE CHILD IS BOOKED ON AGAIN COSTS NOTHING, and this
            // test is what stops one place being counted twice. The family came
            // back, the booking is in the peak below, and the hold is stale until
            // day_holds_close_booked() next runs and stamps it. Correctness
            // cannot wait for that sweep: it runs from cells_year(), and
            // board_load() answers fill.php, the offer path and the dashboard
            // without ever going through it.
            $bookedHere = [];
            foreach ($onDay as $p) {
                if ($p['kidsoft_child_id']) $bookedHere['k' . $p['kidsoft_child_id']] = true;
                if ($p['id'] !== null)      $bookedHere['p' . (int)$p['id']] = true;
            }
            $reservedFor = array_values(array_filter(
                $reservedWho[$room['id']][$wd] ?? [],
                static fn(array $h) => !isset($bookedHere['k' . (string)$h['ks_id']])
                                    && !isset($bookedHere['p' . (int)$h['placement_id']])
            ));
            $reserved = count($reservedFor);
            $reservedFor = array_map(static function (array $h) use ($daysByKey, $reservedDays, $room) {
                $k = $h['ks_id'] !== null ? 'k' . $h['ks_id'] : 'p' . (int)$h['placement_id'];
                // The days they still attend here, and every day being held for
                // them, so the panel can draw one week instead of one box.
                // EMPTY IS A REAL ANSWER for the first: a child removed from care
                // pending an improvement in behaviour holds no booking at all.
                $h['days']      = $daysByKey[$k] ?? [];
                $h['held_days'] = $reservedDays[(int)$room['id']][$k] ?? [];
                return $h;
            }, $reservedFor);

            // HOW MANY PLACES ARE REALLY GONE ON THIS CELL.
            //
            // The peak comes from Kidsoft bookings, so it knows nothing about the
            // two kinds of row that only exist here, and both have to be put back
            // by hand or the figure is wrong in the dangerous direction:
            //
            //   + PROVISIONAL children - pencilled in ahead of Kidsoft, or given
            //     a place by this app, which cannot write back. They hold a place
            //     and no booking exists to prove it.
            //   - ANNOTATED as `waitlist` / `no_position` - Kidsoft has them
            //     booked but the team says they are not holding the seat. Excluded
            //     from `occupied` above, so excluded here too.
            //
            // A past year has no forward dates at all, and there the reference
            // month IS the answer.
            $peakCell  = $peak[(int)$room['id']][$wd]['peak'] ?? null;
            $provOnDay = 0;
            foreach ($onDay as $p) if ($p['provisional']) $provOnDay++;
            $mutedKs = 0;
            foreach ($placements as $p) {
                if (!in_array($wd, $p['days'], true)) continue;
                if (!in_array($p['status'], ['waitlist', 'no_position'], true)) continue;
                if ($p['kidsoft_child_id']) $mutedKs++;
            }
            $holding = $peakCell === null
                ? $roster
                : max(0, $peakCell - $mutedKs) + $provOnDay;

            // PLACED IS HOLDING (21 Aug 2026). They were two numbers on one cell:
            // `occupied` counted the reference month and `holding` counted the
            // places actually gone, and the grid printed the first while `free`
            // was derived from the second - so placed + free did not add up to
            // capacity on 22 of 180 cells, in both directions. The team's rule
            // settles which one is right: a spot is taken from the moment a child
            // is enrolled into it, so the number on the cell is the number of
            // spots taken. `occupied` survives as a KEY because fill.php,
            // waitlist.php and the dashboard read it, not as a second definition.
            $occupied = $holding;


            $cells[$wd] = [
                'occupied'  => $occupied,
                // Held by a pending offer: not placed, but not offerable either.
                'promised'  => $promise,
                // And WHO the offer was made to. Same rule as held_children: the
                // grid's figures open into the children behind them, or the
                // reader is left counting a number they cannot check.
                'promised_children' => $promiseWho,
                // Kept for a child who has not answered re-enrolment. Counted
                // separately from `promised` because it is a different promise to
                // a different person - one is an offer this app made to a family
                // in the queue, the other is a place the organisation owes a
                // family it already has.
                'held'      => $keep,
                'held_children' => $keepWho,
                // Kept for a child who IS enrolled here and is not booked on this
                // weekday - a Behaviour Support Plan, or any other arrangement
                // where the family is out for a while and the place stays theirs.
                // A third kind of promise, counted apart from the other two for
                // the same reason they are counted apart from each other: an
                // offer can be withdrawn, a re-enrolment hold lapses on its own
                // clock, and this one ends only when a person says so or the
                // booking comes back. NAMED, NOT JUST COUNTED, like both of them.
                'reserved'  => $reserved,
                'reserved_children' => $reservedFor,
                // The fullest this cell gets between today and December, which is
                // what a place can actually be sold against. Differs from
                // `occupied` whenever somebody starts, or leaves, later in the
                // year. Equals it for a past year.
                'holding'   => $holding,
                'capacity'  => $capacity,
                'vacancies' => $capacity === null
                    ? null : max(0, $capacity - $holding - $promise - $keep - $reserved),
                // Over-capacity means more places are gone than exist. A pending
                // offer is still excluded - it may yet lapse - but a child who
                // has not walked in yet is NOT excluded any more: their place is
                // gone. The old test ran on the reference month and fired on four
                // cells group-wide that were never over capacity at all, all of
                // them a leaver counted beside their own replacement.
                'over'      => $capacity !== null && $occupied > $capacity,
                // Fill is places gone against places that exist, so it follows the
                // same figure. A room whose last two places were sold to children
                // starting in September now reads full, because it is.
                'pct'       => board_pct($occupied, $capacity),
                'subquotas' => $quotas,
                'children'  => array_map(static fn($p) => [
                    // NULL until somebody annotates this child: a derived row has
                    // no table row of its own, and inventing one for every booked
                    // child would recreate the copy this change removed.
                    'id'     => $p['id'] === null ? null : (int)$p['id'],
                    'provisional' => (bool)$p['provisional'],
                    'name'   => trim($p['child_first_name'] . ' ' . $p['child_last_name']),
                    // Carried so a cell can reach the family: cells_year() joins
                    // this to child_contacts() for the "already here, not on this
                    // day" call list. A provisional row has none, and simply gets
                    // no phone number rather than the wrong family's.
                    'ks_id'  => $p['kidsoft_child_id'] ?: null,
                    // WHEN THIS CHILD HOLDS THE CELL, where that is not simply
                    // "all of it". `from` is the whole reason this change exists:
                    // it is how a child enrolled to start in September appears in
                    // the room they have been enrolled into instead of in a badge
                    // beside it. `to` is its mirror, and together they are what
                    // explains a roster one longer than the figure above it.
                    'from'   => board_span_edge($p['dates'][$wd] ?? null, $bounds, 'first'),
                    'to'     => board_span_edge($p['dates'][$wd] ?? null, $bounds, 'last'),
                    'status' => $p['status'],
                    'flags'  => array_values(array_filter([
                        $p['is_sibling'] ? 'sibling' : null,
                        $p['is_staff_child'] ? 'staff' : null,
                        $p['needs_two_educators'] ? 'two_educators' : null,
                        $p['has_behaviour_plan'] ? 'behaviour' : null,
                        $p['has_individual_plan'] ? 'individual' : null,
                    ])),
                ], $onDay),
            ];

            $totals[$wd]['occupied'] += $occupied;
            $totals[$wd]['promised'] += $promise;
            $totals[$wd]['held']     += $keep;
            $totals[$wd]['reserved'] += $reserved;
            $totals[$wd]['holding']  += $holding;
            if ($capacity !== null) $totals[$wd]['capacity'] += $capacity;
            // FREE IS THE SUM OF THE CELLS, NOT A SUBTRACTION AT THE TOTALS ROW
            // (15 Sep 2026). Netting capacity against holding across every room
            // at once lets one room's OVER-capacity cell cancel another room's
            // free place, and a four-year-old cannot be sold a place in the
            // babies room. Measured group-wide the day it was found, exactly one
            // cell does this - Terrigal's Mighty Meteors Thursday holds 16
            // children against 15 operational positions - and it made the centre
            // read 6 free while its own rooms summed to 7.
            //
            // The Placement board was already right: cells_year() has always
            // summed `vacancies` per cell, so the two screens disagreed about
            // Terrigal by one place-day. `over` still reports the over-capacity
            // cell; this only stops it eating a sellable place somewhere else.
            $totals[$wd]['vacancies'] += (int)$cells[$wd]['vacancies'];
        }

        $out[] = [
            'id'             => (int)$room['id'],
            'name'           => $room['name'],
            'capacity'       => $room['capacity'],       // [weekday => ?int]
            'capacity_max'   => $room['capacity_max'],   // headline display only
            'min_age_months' => $room['min_age_months'],
            'max_age_months' => $room['max_age_months'],
            'linked'         => (bool)$room['kidsoft_room_id'],
            'subquotas'      => array_map(static fn($q) => [
                'id' => (int)$q['id'], 'label' => $q['label'],
                'max' => (int)$q['max_children'], 'describe' => subquota_describe($q),
            ], $room['subquotas']),
            'cells'          => $cells,
            'placements'     => $placements,
            'week_vacancies' => $room['capacity_max'] === null
                ? null
                : array_sum(array_map(static fn($c) => (int)$c['vacancies'], $cells)),
        ];
    }

    // `vacancies` is accumulated per cell in the loop above - see the note there.
    foreach ($totals as $wd => $t) {
        $totals[$wd]['pct'] = board_pct($t['occupied'], $t['capacity']);
    }

    return ['centre' => $centre, 'year' => $year, 'rooms' => $out, 'totals' => $totals,
            // The date the board counts from: today, 1 January, the date somebody
            // chose, or null for a finished year read off its reference month.
            'from' => board_from($year, $chosen),
            // ChildID => {on, reason} for everyone whose notice is in. Published
            // so cells_year() can prefer a DECLARED last day over the one it
            // infers from the shape of a child's bookings, without a second call.
            'exits' => $exits,
            // Provenance, so a screen can say WHERE this board came from rather
            // than presenting a Kidsoft outage as an empty centre.
            'source' => [
                'month'       => $live['month'],
                'unreachable' => $live['stale'],
                'children'    => $live['children'],
                'unmapped'    => $live['unmapped'],   // booked into a room we have not linked
                'provisional' => $provisionalCount,
            ]];
}

function board_empty_totals(): array {
    $t = [];
    foreach (array_keys(WEEKDAYS) as $wd) {
        $t[$wd] = ['occupied' => 0, 'promised' => 0, 'held' => 0, 'reserved' => 0,
                   'holding' => 0, 'capacity' => 0, 'vacancies' => 0, 'pct' => null];
    }
    return $t;
}

/**
 * Whole-organisation overview for a year: one summary row per centre plus the
 * group totals, so the team sees every centre's fill at a glance before drilling
 * into a room board. The unit stays room x weekday - each centre carries its
 * per-weekday totals so the emptiest day is visible without opening the centre.
 *
 * Reuses board_load()'s occupancy rules (waitlist/no_position don't hold a seat,
 * unknown capacity stays unknown) rather than re-deriving them. At 7 centres /
 * ~40 rooms this is well under a tenth of a second.
 *
 * @return array{year:int, centres:array, totals:array}
 */
/**
 * The overview row for a centre that has not opened in this year.
 *
 * SAME KEYS, NULL FIGURES. `has_capacity` false is what the grid already uses
 * for "nothing known to measure against", so every cell, the percentage and the
 * free column fall back to the treatment they give an unreadable centre: a dash,
 * not a zero and not a licensed figure nobody can sell.
 */
function board_overview_unopened(array $centre, int $year, array $notOpen): array {
    $totals = [];
    foreach (array_keys(WEEKDAYS) as $wd) {
        $totals[$wd] = ['occupied' => 0, 'promised' => 0, 'held' => 0, 'reserved' => 0,
                        'holding' => 0, 'capacity' => 0, 'vacancies' => 0, 'pct' => null];
    }
    return [
        'id'               => (int)$centre['id'],
        'name'             => (string)$centre['name'],
        'rooms'            => (int)(dbx()->query(
            'SELECT COUNT(*) n FROM rooms WHERE centre_id = ? AND active = 1',
            [(int)$centre['id']], 'i')->fetch()['n'] ?? 0),
        'unknown_capacity' => 0,
        'totals'           => $totals,
        'occupied'         => 0,
        'capacity'         => 0,
        'free'             => 0,
        'trading'          => false,
        'state'            => 'unopened',
        'counts'           => false,
        'opens_on'         => $centre['opens_on'],
        'not_open'         => $notOpen,
        // The one flag the grid reads to decide between a figure and a dash.
        'has_capacity'     => false,
        'pct'              => null,
    ];
}

function board_overview(int $year, ?string $from = null): array {
    $org = board_empty_totals();
    $rows = [];

    foreach (centres_all() as $c) {
        // A CENTRE THAT HAS NOT OPENED IN THIS YEAR IS NOT DERIVED AT ALL. The
        // test is one already-loaded row (centre_not_open_yet()), so the board,
        // the Kidsoft read and the annotation join are all skipped - and the row
        // that comes back carries NULLS rather than the 385 place-days a week
        // Kidsoft has licensed for a centre with no doors open. Same treatment,
        // same reason, as the Dashboard's own row. AS AT THE FROM DATE, not the
        // year (27 Oct 2026) - see centre_not_open_yet().
        $notOpen = centre_not_open_yet($c, $year, board_from($year, $from));
        if ($notOpen) { $rows[] = board_overview_unopened($c, $year, $notOpen); continue; }

        $b = board_load((int)$c['id'], $year, ['from' => $from]);
        // NOT `board_trading() === true` any more (21 Sep 2026). A centre that
        // opens LATER THIS YEAR counts from the day it opens, even with nobody
        // booked yet - its places are real and unsold, and leaving them out is
        // how a new centre opens with an empty roll. See centre_year_state().
        $state     = centre_year_state($b);
        $isTrading = centre_state_counts($state);

        $occ = 0; $cap = 0; $free = 0; $unknown = 0;
        foreach (array_keys(WEEKDAYS) as $wd) {
            $occ  += $b['totals'][$wd]['occupied'];
            $cap  += $b['totals'][$wd]['capacity'];
            $free += $b['totals'][$wd]['vacancies'];
            // Only a centre that is OPEN - or about to be, this year -
            // contributes to the group row. A centre that has not opened yet,
            // and a closed one, have no places anybody can sell.
            if (!$isTrading) continue;
            $org[$wd]['occupied'] += $b['totals'][$wd]['occupied'];
            $org[$wd]['promised'] += $b['totals'][$wd]['promised'];
            $org[$wd]['held']     += $b['totals'][$wd]['held'];
            $org[$wd]['reserved'] += $b['totals'][$wd]['reserved'];
            $org[$wd]['capacity'] += $b['totals'][$wd]['capacity'];
            // Summed, not re-subtracted, for the reason board_load() gives: an
            // over-capacity cell in one room must not cancel a sellable place in
            // another. Re-deriving it here would reintroduce exactly that at the
            // group level, one layer up.
            $org[$wd]['vacancies'] += $b['totals'][$wd]['vacancies'];
        }
        foreach ($b['rooms'] as $r) if ($r['capacity_max'] === null) $unknown++;

        // A CENTRE THAT IS NOT TRADING IS NOT AN EMPTY CENTRE. Warnervale carries
        // 385 recorded place-days a week with zero bookings and zero children:
        // counted in, it is 77% of the group's "places free", the top row of an
        // emptiest-first list, and it drags group fill from 96% to 84%. It is
        // reported here and left out of the totals; see board_trading().
        $trading = board_trading($b);
        $rows[] = [
            'id'               => (int)$c['id'],
            'name'             => $c['name'],
            'rooms'            => count($b['rooms']),
            'unknown_capacity' => $unknown,          // rooms with no capacity for $year
            'totals'           => $b['totals'],      // per-weekday occ/cap/vac/pct
            'occupied'         => $occ,              // child-days placed this week
            'capacity'         => $cap,              // place-days available this week
            'free'             => $free,             // place-days still to fill
            'trading'          => $trading,          // true / false / null = Kidsoft would not say
            // WHAT THE CENTRE IS DOING, and whether it is in the totals - decided
            // once, here, so no screen re-derives the rule. `trading` stays for
            // what it always meant (are there children in it).
            'state'            => $state,
            'counts'           => $isTrading,
            'opens_on'         => $c['opens_on'] ?? null,
            'has_capacity'     => $cap > 0,          // false = nothing known to measure against
            'pct'              => board_pct($occ, $cap),
        ];
    }

    foreach ($org as $wd => $t) {
        $org[$wd]['pct'] = board_pct($t['occupied'], $t['capacity']);
    }

    // THE CENTRE BAR'S ORDER, with a centre NOT OPEN AT THE FROM DATE last
    // (Broc, 27 Oct 2026). centres_all() already returns the bar's order and
    // usort() is stable, so only those rows move. Not today's date: Warnervale
    // opens 1 Oct 2027, so it is last in 2027 from 1 January and in its bar
    // position from 1 October, where it is open and counted.
    $unopened = static fn(array $r): bool => $r['state'] === 'unopened';
    usort($rows, static fn(array $a, array $b): int => $unopened($a) <=> $unopened($b));

    return ['year' => $year, 'from' => board_from($year, $from), 'centres' => $rows, 'totals' => $org];
}

/** One placement with its weekdays, or null. */
function placement_get(int $id): ?array {
    $p = dbx()->query('SELECT * FROM placements WHERE id = ?', [$id], 'i')->fetch();
    if (!$p) return null;
    $p['days'] = array_map('intval',
        dbx()->query('SELECT weekday FROM placement_days WHERE placement_id = ? ORDER BY weekday', [$id], 'i')
            ->fetchAll('col'));
    return $p;
}

/** Replace a placement's weekdays. */
function placement_set_days(int $placementId, array $weekdays): void {
    $clean = array_values(array_unique(array_filter(
        array_map('intval', $weekdays), static fn($w) => $w >= 1 && $w <= 5
    )));
    dbx()->query('DELETE FROM placement_days WHERE placement_id = ?', [$placementId], 'i');
    foreach ($clean as $w) {
        dbx()->query('INSERT INTO placement_days (placement_id, weekday) VALUES (?, ?)', [$placementId, $w], 'ii');
    }
}
