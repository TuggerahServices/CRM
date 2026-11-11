<?php
// The new-enrolment queue, fed from Kidsoft instead of typed.
//
// Source: Waitlist/getWaitingList, with all four of its detail options passed.
// CLAUDE.md declared this endpoint anonymous and unimportable for months --
// "carries no name fields at all, ChildID set on 0.9% of rows, requested weekdays
// absent, nothing can be imported" - and that measurement was taken WITHOUT
// WithDetails / WithRequestedDays / WithOfferedDays / WithCareTypeMap, every one
// of which defaults to false. ChildID really is ~1%, and it does not matter: the
// identity lives in Details.EnrolmentData, not in that column.
//
// Measured 31 Jul 2026 across all seven centres: 1,692 rows, 698 open
// `Waitlisted`, in 7 calls and 2.6 seconds. Coverage on the open rows --
//   child first/last name  100%      requested weekdays     100%
//   child DOB              100%      CareTypeMap.RequiredFrom 100%
//   parent mobile           99%      family address          89%
//   parent email            99%      PriorityOfAccess        16%
// That is the entire content of waitlist_applications, which is why that table
// sat EMPTY: nobody will hand-key 200 families, and they never needed to.
//
// WHO OWNS WHICH COLUMN. This is the whole design, and getting it wrong destroys
// work rather than saving it. The line is: KIDSOFT OWNS WHAT THE FAMILY
// DECLARED, WE OWN EVERY DECISION.
//
//   KIDSOFT'S, refreshed on every import - child names, DOB, guardian name /
//   email / phone, date_added (the queue's ordering key), required_from, and the
//   requested weekdays. All facts the family stated on their application, none of
//   them ours to revise. save_application refuses to let a human type over these
//   on an imported row, the same way save_placement discards a typed name for a
//   child linked to a Kidsoft id.
//
//   KIDSOFT'S IDs are a third case, on their own terms: kidsoft_child_id and
//   kidsoft_guardian_id are taken on EVERY row we hold whatever its status, and
//   once held they are never overwritten or cleared. They are the only identity
//   Kidsoft itself supplies, and the ChildID arrives at the conversion - the
//   moment the row goes closed and the rest of this loop stops reading it.
//
//   OURS, never overwritten once the row exists - status (bar the one transition
//   below), for_year, preferred_room_id, the priority flags (is_reenrolment,
//   is_staff_child, is_sibling), the enquiry funnel (lead_source, tour_booked_at,
//   guide_provided, orientation_at), and notes. tour_booked_at IS NO LONGER
//   WRITTEN BY ANYTHING (10 Sep 2026): the tour lives on the work item, where it
//   has a time and an answer - see work_for_waitlist(). The import never touched
//   it and still does not; it is listed here only so nobody reads its silence as
//   an omission. Kidsoft SEEDS for_year on insert
//   and then never touches it again, because which year we plan a family into is
//   a judgement - and a sync that reverted it would be the
//   silent-destruction-of-considered-edits failure CLAUDE.md warns about.
//
//   priority_of_access WAS in that group and moved to Kidsoft's side on 13 Aug
//   2026, because the judgement it was being reserved for had never once been
//   made: 743 of 743 imported rows still held exactly what Kidsoft sent. The
//   freeze protected nothing and blocked a real correction - see the UPDATE.
//   THE TEST THAT MOVED IT is the one this codebase keeps arriving at: an
//   override nobody has ever exercised is not a safeguard, it is a copy going
//   stale. Same finding as the room capacity override and the sibling checkbox.
//
// Two of those need their reasoning stated:
//
//   * STATUS. Ours tracks OUR offer lifecycle and a pending offer HOLDS days in
//     room_day_vacancies(), so an import that reset a row to 'waitlisted' would
//     silently free a seat two staff could then promise to two families. The
//     import therefore only ever moves a row BETWEEN OPEN AND CLOSED: it closes a
//     row we still think is waiting when Kidsoft says the family withdrew or
//     lapsed, and reopens an expired row when Kidsoft reactivates it (Kidsoft does
//     this, and says so in StatusReason). 'offered', 'partially_placed' and
//     'placed' are never touched.
//
//   * NOTES. Kidsoft's Notes is populated on 5% of open rows and is genuinely
//     useful, but ours is where staff write what happened on the phone. So
//     Kidsoft's note fills an EMPTY notes column and never replaces one with
//     text in it. StatusReason is deliberately NOT imported: it is machine audit
//     text and one real Tuggerah row carries "Parent has confirmed interest in
//     staying on the waiting list." repeated 71 times, 4KB of it.
//
// Matching is on WaitListID (the uq_waitlist_kidsoft unique key), verified
// globally unique across all 1,692 rows group-wide. A hand-keyed row has no
// WaitListID, so it can never be matched and never be overwritten - but it CAN
// duplicate a family Kidsoft also holds, so the import reports likely duplicates
// for a human to resolve and never merges them itself.
//
// Still strictly read-only: Waitlist/createEntry exists and is out of bounds.

require_once __DIR__ . '/kidsoft-cache.php';
require_once __DIR__ . '/waitlist.php';
require_once __DIR__ . '/board.php';      // board_default_year()
require_once __DIR__ . '/identity.php';   // name_case()
require_once __DIR__ . '/audit.php';

/**
 * Kidsoft waitlist status -> ours, and whether the application is still open.
 *
 * Full census over all 1,692 rows group-wide, 31 Jul 2026: ExpiredWaitlist 948,
 * Waitlisted 698, ExpiredOffer 18, Enrolled 11, ExpiryReminderFirst 7,
 * DeletedByParent 7, ExpiryReminderSecond 2, "Re-submit Required" 1.
 *
 * Re-censused 10 Aug 2026 over 1,701 rows: ExpiredWaitlist 954, Waitlisted 711,
 * Enrolled 17, ExpiredOffer 8, ExpiryReminderFirst 4, ExpiryReminderSecond 4,
 * Offered 2, and a status this map had never seen - OfferedExpiryReminder 1.
 * The vocabulary grew in ten days, exactly as the note below predicts. Re-run
 * this census before trusting the list.
 *
 * Each entry is [our status, is it still open, note to seed when it needs one].
 * Three of the mappings are judgements rather than translations:
 *
 *   * The two ExpiryReminder statuses are OPEN. The family has been sent an "are
 *     you still interested?" nudge and has not gone anywhere; Kidsoft moves them
 *     back to Waitlisted when they reply and to ExpiredWaitlist when they do not.
 *     Dropping them would hide a waiting family, the more expensive of the two
 *     mistakes.
 *
 *   * "Re-submit Required" is OPEN. It was first read (one Niagara Park row) as an
 *     incomplete application and seeded with a note saying so. RE-READ 15 Oct
 *     2026 on the measure this file uses for the offer statuses: the one live row
 *     (Heidi Gow, Terrigal) carries OfferedDays and a 21-day DateAdded->
 *     ExpiryDate window, so it is the offer side of the funnel - most likely an
 *     enrolment form sent back for correction. It holds a place; see
 *     KIDSOFT_OPEN_OFFER in board.php.
 *
 *   * `Offered` maps to `waitlisted`, NOT to our `offered`. Our `offered` means
 *     "this app has an `offers` row", and an imported row has none. THE OFFER IS
 *     READ INSTEAD (15 Oct 2026): OfferedDays and the offer's room are stored
 *     alongside the requested days (waitlist_offered()), and while the status is
 *     one of KIDSOFT_OPEN_OFFER the board holds those days as promised and the
 *     queue labels the row with what Kidsoft says. This is no longer academic:
 *     23 applications were at an offer status that morning and the board was
 *     showing all 70 of their place-days as free.
 *
 *   * The space in that status is Kidsoft's, not a typo here.
 *
 * A status missing from this map is SKIPPED and reported, never guessed at: this
 * list is one Kidsoft release away from growing, and quietly filing an unknown
 * status as "waitlisted" would put a family in the queue on no evidence.
 */
const WAITLIST_KIDSOFT_STATUS = [
    'Waitlisted'           => ['waitlisted', true,  null],
    'ExpiryReminderFirst'  => ['waitlisted', true,  null],
    'ExpiryReminderSecond' => ['waitlisted', true,  null],
    // THE FOUR OFFER STATUSES SEED NO NOTE ANY MORE (15 Oct 2026). Each used to
    // write "Kidsoft has an offer open with this family. Check Kidsoft before
    // offering a place here." into an empty notes column, where it stayed after
    // the offer lapsed, was declined or turned into an enrolment - a caption
    // aging into something false. The row now SHOWS the offer, read off
    // OfferedDays and CareTypeMap (KIDSOFT_OPEN_OFFER in board.php), and there
    // is no Offer button left here to warn anybody off.
    'Re-submit Required'   => ['waitlisted', true, null],
    'Offered'              => ['waitlisted', true, null],
    // THE MAP CAUGHT A NEW STATUS, WHICH IS WHY IT REFUSES TO GUESS (10 Aug 2026).
    // `OfferedExpiryReminder` did not exist in the 31 Jul census and appeared on
    // ONE row group-wide, so that family had been skipped by every import since --
    // held here at whatever status they last had, never refreshed, invisible as a
    // problem. It is the offer-side twin of ExpiryReminderFirst/Second: an offer
    // is open and about to lapse, so it is OPEN and takes `Offered`'s note for
    // the same reason - our `offered` means an `offers` row that HOLDS days, and
    // an imported row holds nothing.
    'OfferedExpiryReminder' => ['waitlisted', true, null],
    // AND IT CAUGHT ANOTHER ONE (13 Aug 2026). `Form Submitted` appeared on 3
    // rows group-wide and had been skipped by every import since - one of them,
    // a Wamberal family who applied the day before, was not in the queue AT ALL,
    // and the other two were frozen at the status they last carried.
    //
    // It is the offer side of the funnel, and the payload says so rather than the
    // name: censused over all 1,701 rows, `Form Submitted` carries OfferedDays on
    // 3 of 3 with a median DateAdded->ExpiryDate window of **5 days**, which puts
    // it with `Enrolled` (18 of 18, 13 days) and `ExpiredOffer` (8 of 8, 23 days)
    // and nowhere near `Waitlisted` (4 of 710, 354 days). A short clock and an
    // offer out is an offer, whatever it is called. The reading is that the
    // parent has returned the enrolment form and the enrolment has not completed.
    //
    // So it is OPEN - it is not `Enrolled`, and a family part-way through the
    // paperwork is still a family the team is holding - and it takes `Offered`'s
    // note for the reason that entry gives: our `offered` means an `offers` row
    // that HOLDS days in room_day_vacancies(), and an imported row holds nothing.
    'Form Submitted'       => ['waitlisted', true, null],
    'Enrolled'             => ['placed',     false, null],
    'ExpiredWaitlist'      => ['expired',    false, null],
    'ExpiredOffer'         => ['expired',    false, null],
    'DeletedByParent'      => ['withdrawn',  false, null],
    // THE PARENT TURNED THE OFFER DOWN (mapped 15 Oct 2026). Until now it was
    // only seen among ARCHIVED rows, so the map never needed it - and then one
    // arrived in the default window. Ari Flew at Tuggerah went Offered ->
    // RejectedByParent on 14 Sep (OfferedDays Wed/Thu still on the row), was
    // skipped as unknown by every import after that, and sat in the queue at
    // "Offered" for a month. Once offers hold days on the board that frozen row
    // would have held two Tuggerah place-days for a family who had said no.
    // Closed, like DeletedByParent: the family has ended this application.
    'RejectedByParent'     => ['withdrawn',  false, null],
];

/** Our statuses that represent a decision the import must not overwrite. */
const WAITLIST_OURS_TO_KEEP = ['offered', 'partially_placed', 'placed'];

/**
 * Closed Kidsoft statuses imported EVEN WHEN WE DO NOT ALREADY HOLD THE ROW.
 *
 * The general rule below is that a closed application we never imported is
 * history and stays out, because `ExpiredWaitlist` would bury the queue it is
 * filed next to. `ExpiredOffer` is the exception, and it is an exception on the
 * numbers as much as on the meaning:
 *
 * **Measured 9 Sep 2026, live across all seven centres:** 496 `ExpiredWaitlist`
 * against **10 `ExpiredOffer`** group-wide, and 5 of those 10 were held here
 * while 5 were not - Niagara Park's four (Freddie Landis, Savannah Jarvis,
 * Sienna Scuglia, Niamh Howlett) and Wamberal's Kiera Ryan were in Kidsoft and
 * in no screen of this app at all, because they were already closed the first
 * time the import saw them. That is what Broc reported: "Offer Expired are not
 * showing up in the waitlist."
 *
 * Ten rows bury nothing, and they are not the same fact as a lapsed waiting
 * place. A waiting place expires because a parent stopped answering Kidsoft's
 * reminder emails; an OFFER expires because a place was put in front of a family
 * and nobody closed it out. The second is the enrolments team's own work, and it
 * is the one they asked to be able to see.
 *
 * They are still CLOSED, so they carry no queue position and appear only under
 * "All" - the same treatment `ExpiredWaitlist` gets, and the same line Broc drew
 * on 8 Sep between an expired application and an archived one.
 */
const WAITLIST_IMPORT_WHEN_CLOSED = ['ExpiredOffer'];

/**
 * The columns Kidsoft owns on an imported row, refreshed on every sync.
 *
 * Used by BOTH sides of the contract, which is why it is a constant and not a
 * comment: the import writes exactly these, and api/waitlist.php's save refuses
 * to let a human overwrite exactly these. Requested weekdays are on this list too
 * and are handled separately, being their own table.
 */
const WAITLIST_KIDSOFT_OWNED = [
    'child_first_name', 'child_last_name', 'child_dob',
    'guardian_name', 'guardian_email', 'guardian_phone',
    'date_added', 'required_from', 'kidsoft_expires_at',
    // ADDED 13 Aug 2026, and it moved OUT of the seed-on-insert group below.
    // See the UPDATE for the measurement; the short version is that the
    // judgement this column was being reserved for has never once been made.
    'priority_of_access',
];

/**
 * The weekdays a family asked for.
 *
 * Shape is {CareType: {RosterCare: bool, Days: {WeekNumber: [DayName, ...]}}},
 * and the union is taken across BOTH levels. Care types first because 37 of the
 * 698 open applications carry two (Standard + Kindergarten) and a child wanting
 * Monday kindergarten and Tuesday standard care wants both days. Week numbers
 * second because the key is a fortnight position - every row in this data is
 * week "1", but a fortnightly roster would otherwise silently lose half its days
 * and nothing would report it.
 *
 * @return int[] weekdays 1=Mon .. 5=Fri, ascending
 */
function waitlist_requested_weekdays(array $row): array {
    static $names = [
        'Monday' => 1, 'Tuesday' => 2, 'Wednesday' => 3, 'Thursday' => 4, 'Friday' => 5,
    ];
    $out = [];
    foreach ($row['RequestedDays'] ?? [] as $careType => $block) {
        foreach ($block['Days'] ?? [] as $week => $dayNames) {
            foreach ((array)$dayNames as $name) {
                if (isset($names[$name])) $out[$names[$name]] = true;
            }
        }
    }
    ksort($out);
    return array_keys($out);
}

/**
 * Earliest date any of a row's care types is needed from, and the latest to.
 *
 * Earliest, not first: a row with two care types has two RequiredFrom dates and
 * the family needs a place from whichever comes first.
 *
 * @return array{from:?string,to:?string,room:?string}
 */
function waitlist_care_window(array $row): array {
    $from = $to = $room = null;
    foreach ($row['CareTypeMap'] ?? [] as $careType => $m) {
        $f = substr((string)($m['RequiredFrom'] ?? ''), 0, 10);
        $t = substr((string)($m['RequiredTo'] ?? ''), 0, 10);
        if ($f !== '' && ($from === null || $f < $from)) $from = $f;
        if ($t !== '' && ($to === null || $t > $to))     $to   = $t;
        if ($room === null && !empty($m['RoomID']))      $room = $m['RoomID'];
    }
    return ['from' => $from, 'to' => $to, 'room' => $room];
}

/**
 * The weekdays KIDSOFT OFFERED, and the Kidsoft room it offered them in.
 *
 * OfferedDays is the same shape as RequestedDays and is unioned the same way.
 * The room is the CareTypeMap.RoomID of a care type that was actually offered
 * days - present on 23 of 23 open offers on 15 Oct 2026, against 6% of rows that
 * are merely waiting. Rafael Leon at Wamberal is offered both Standard and
 * Kindergarten care, in the same room.
 *
 * @return array{days:int[],room:?string}
 */
function waitlist_offered(array $row): array {
    $days = waitlist_requested_weekdays(['RequestedDays' => $row['OfferedDays'] ?? []]);
    $room = null;
    foreach ($row['OfferedDays'] ?? [] as $careType => $block) {
        $id = $row['CareTypeMap'][$careType]['RoomID'] ?? null;
        if ($id) { $room = $id; break; }
    }
    return ['days' => $days, 'room' => $room];
}

/**
 * Import one centre's new-enrolment queue from Kidsoft.
 *
 * Idempotent: re-running refreshes the Kidsoft-owned columns and leaves every
 * decision this app has recorded alone.
 *
 * @param  array $opts  refresh => bypass the cache
 * @return array counts and warnings, for the screen to report
 */
function waitlist_import_centre(int $centreId, array $opts = []): array {
    $centre = centre_get($centreId);
    if (!$centre) throw new InvalidArgumentException('unknown centre');
    $serviceId = $centre['kidsoft_service_id'];

    // DateAddedFrom/DateAddedTo are required by the endpoint and are the only way
    // to bound it. 2010 predates the group's oldest row (earliest DateAdded seen
    // is 2024) and the window runs a year forward, so a row dated in the future
    // by a data-entry slip is still seen rather than silently missing.
    $res = kidsoft_cached('Waitlist', 'getWaitingList', [
        'ServiceID'          => $serviceId,
        'DateAddedFrom'      => '2010-01-01',
        'DateAddedTo'        => date('Y-m-d', strtotime('+1 year')),
        'WithDetails'        => 'true',
        'WithRequestedDays'  => 'true',
        'WithOfferedDays'    => 'true',
        'WithCareTypeMap'    => 'true',
    ], !empty($opts['refresh']) ? 0 : null);

    $rows = kidsoft_rows($res, 'WaitingList', $serviceId);

    $counts = [
        'fetched'    => count($rows),
        'open'       => 0,
        'added'      => 0,
        'updated'    => 0,
        'closed'     => 0,   // we thought they were waiting; Kidsoft says not
        'archived'   => 0,   // gone from the feed entirely; see the block below
        'reopened'   => 0,
        'linked'     => 0,   // Kidsoft issued a ChildID/GuardianID we did not hold
        'skipped'    => 0,   // closed in Kidsoft and never imported here
        'no_days'    => 0,
        'no_name'    => 0,
        'unknown_status' => [],
    ];

    // Existing imported rows, so this can tell an insert from an update without a
    // query per row.
    $existing = dbx()->query(
        'SELECT id, kidsoft_waitlist_id, status, notes
           FROM waitlist_applications
          WHERE centre_id = ? AND kidsoft_waitlist_id IS NOT NULL',
        [$centreId], 'i'
    )->fetchAll();
    $byKidsoftId = [];
    foreach ($existing as $e) $byKidsoftId[$e['kidsoft_waitlist_id']] = $e;

    // A requested room, when Kidsoft names one (6% of open rows). Mapped through
    // our confirmed room mapping - an unmapped room means we have no row to
    // point at, not that the request is invalid.
    $ourRoomByKs = [];
    foreach (rooms_for_centre($centreId, board_default_year()) as $rm) {
        if ($rm['kidsoft_room_id']) $ourRoomByKs[$rm['kidsoft_room_id']] = (int)$rm['id'];
    }

    $thisYear = (int)date('Y');

    dbx()->transaction(function ($db) use (
        $rows, $centreId, &$counts, $byKidsoftId, $ourRoomByKs, $thisYear
    ) {
        foreach ($rows as $r) {
            $wlId = trim((string)($r['WaitListID'] ?? ''));
            if ($wlId === '') { $counts['skipped']++; continue; }

            $ksStatus = (string)($r['Status'] ?? '');
            if (!isset(WAITLIST_KIDSOFT_STATUS[$ksStatus])) {
                // Recorded, not guessed at. An unmapped status must be visible in
                // the result rather than quietly becoming 'waitlisted'.
                $counts['unknown_status'][$ksStatus] =
                    ($counts['unknown_status'][$ksStatus] ?? 0) + 1;
                $counts['skipped']++;
                continue;
            }
            [$ourStatus, $isOpen, $statusNote] = WAITLIST_KIDSOFT_STATUS[$ksStatus];
            if ($isOpen) $counts['open']++;

            // A closed status this app still reads in full - see
            // WAITLIST_IMPORT_WHEN_CLOSED. It is imported and refreshed exactly
            // like an open row; the only thing `$isOpen` still decides for it is
            // that it holds no place in the queue, which waitlist_for_centre()
            // works out from our own status and not from here.
            $keepClosed = in_array($ksStatus, WAITLIST_IMPORT_WHEN_CLOSED, true);

            $have = $byKidsoftId[$wlId] ?? null;

            // A closed application we never imported is history: 944 of the 1,692
            // rows group-wide are ExpiredWaitlist, and pouring those into the
            // team's queue table would bury the 698 that matter.
            if (!$isOpen && !$have && !$keepClosed) { $counts['skipped']++; continue; }

            // --- The one status transition the import is allowed to make -------
            if ($have) {
                $mine = $have['status'];
                if (!$isOpen && $mine === 'waitlisted') {
                    $db->query(
                        'UPDATE waitlist_applications SET status = ? WHERE id = ?',
                        [$ourStatus, $have['id']]
                    );
                    $counts['closed']++;
                } elseif ($isOpen && $mine === 'expired') {
                    // Kidsoft reactivates lapsed applications when a parent
                    // confirms interest, and the family keeps their original
                    // date_added, so they lose no queue position by it.
                    $db->query(
                        "UPDATE waitlist_applications SET status = 'waitlisted' WHERE id = ?",
                        [$have['id']], 'i'
                    );
                    $counts['reopened']++;
                }
                // Anything in WAITLIST_OURS_TO_KEEP is left exactly as it is.
            }

            // --- THE IDs ARE ADOPTED WHATEVER THE STATUS (17 Aug 2026) --------
            //
            // A Kidsoft CHILD record is created when an application CONVERTS TO
            // AN ENROLMENT, so the ChildID arrives at exactly the moment this
            // row's Kidsoft status becomes `Enrolled` - which maps to `placed`,
            // which is CLOSED, which is where `continue` used to fire. The one
            // read that could have handed us the family's identity was the one
            // read this loop threw away.
            //
            // MEASURED 17 Aug 2026 over all 1,699 rows group-wide, cross-tabbing
            // Status against ChildID: `Enrolled` 17 of 17 carry BOTH a ChildID
            // and a GuardianID (100%); every other status is ~0 (`Waitlisted` 2
            // of 696, `ExpiredWaitlist` 2 of 963, the rest zero). Nine of those
            // 17 are families we had tracked through the queue, and all nine
            // still read NULL here. That is what this block is for.
            //
            // What it buys, none of it available before: comment_push() can
            // reach the family (it targets kidsoft_child_id, then
            // kidsoft_guardian_id, and had nothing for these rows), and
            // work_autoclose() can finish their In progress item, which matches
            // on ChildID alone and therefore could never close one raised from
            // the queue.
            //
            // COALESCE, NOT ASSIGNMENT. An id Kidsoft issues is permanent, and a
            // later read that omits it must not wipe the link - the closed
            // statuses send no ChildID at all, so plain assignment would hand us
            // the identity on the conversion and take it back on the next sync.
            if ($have && (!empty($r['ChildID']) || !empty($r['GuardianID']))) {
                $db->query(
                    'UPDATE waitlist_applications
                        SET kidsoft_child_id    = COALESCE(kidsoft_child_id, ?),
                            kidsoft_guardian_id = COALESCE(kidsoft_guardian_id, ?)
                      WHERE id = ?
                        AND (kidsoft_child_id IS NULL OR kidsoft_guardian_id IS NULL)',
                    [$r['ChildID'] ?: null, $r['GuardianID'] ?: null, $have['id']]
                );
                if ($db->affectedRows() > 0) $counts['linked']++;
            }

            // KIDSOFT'S OWN STATUS, VERBATIM, FOR EVERY ROW WE HOLD - open or
            // closed, and whatever we have decided about it ourselves. Our
            // `expired` is TWO Kidsoft statuses collapsed into one, and the team
            // reads them as different things: `ExpiredWaitlist` is a parent who
            // stopped answering the reminder emails, `ExpiredOffer` is a place
            // this organisation put in front of a family and never closed out.
            // The screen cannot tell them apart without the word Kidsoft used,
            // and the import is the only read that ever sees it.
            //
            // Written for CLOSED rows too, which is why it sits above the return
            // below, alongside the ids and for the same reason - one statement
            // owning one fact, on every row, whatever its status.
            if ($have) {
                $db->query(
                    'UPDATE waitlist_applications SET kidsoft_status = ?
                      WHERE id = ? AND (kidsoft_status IS NULL OR kidsoft_status <> ?)',
                    [$ksStatus, $have['id'], $ksStatus]
                );
            }

            // Nothing else to refresh on a closed row - unless it is one of the
            // closed statuses this app reads in full, which is imported and kept
            // current exactly like an open one.
            if (!$isOpen && !$keepClosed) continue;

            // --- Kidsoft-owned family data ------------------------------------
            $e   = $r['Details']['EnrolmentData'] ?? [];
            $cd  = $e['ChildDetails'] ?? [];
            $pg1 = $e['ParentGuardianOne'] ?? [];

            // name_case() is conservative and returns already-mixed-case input
            // untouched, so applying it costs nothing on this endpoint (which
            // returns "Josie Lennon", not getChildren's block capitals) and still
            // handles the rows that do arrive shouting.
            $first = name_case(trim((string)($cd['FirstName'] ?? '')));
            $last  = name_case(trim((string)($cd['LastName'] ?? '')));
            if ($first === '' && $last === '') {
                // ck-style invariant: a row with no name at all has nothing to
                // show a human, and the column is NOT NULL.
                $counts['no_name']++;
                $counts['skipped']++;
                continue;
            }

            $dob  = substr((string)($cd['DOB'] ?? ''), 0, 10) ?: null;
            $days = waitlist_requested_weekdays($r);
            $care = waitlist_care_window($r);

            $guardianName = trim(name_case(trim((string)($pg1['FirstName'] ?? ''))) . ' '
                               . name_case(trim((string)($pg1['LastName'] ?? ''))));
            $phone = trim((string)($pg1['MobilePhone'] ?? ''));

            // PriorityOfAccess is populated on 16% of open rows and its values are
            // 1, 2 and - for three real rows - 5, which is off the end of our
            // statutory 1/2/3 ladder. Only the two values that mean something here
            // are taken; anything else leaves whatever staff set, which defaults
            // to 3 (Other). Guessing that 5 means "high" would reorder a queue.
            $poa = $r['PriorityOfAccess'] ?? null;
            $poa = ($poa === 1 || $poa === 2 || $poa === '1' || $poa === '2') ? (int)$poa : null;

            // A family whose RequiredFrom has already passed still needs a place --
            // 52 of Tuggerah's 116 open rows are in exactly that position, which
            // is backlog, not stale data. So the year they are queued for is never
            // behind the current one, or the year filter would hide them entirely.
            $forYear = $care['from'] ? max((int)substr($care['from'], 0, 4), $thisYear) : null;

            $roomId = ($care['room'] && isset($ourRoomByKs[$care['room']]))
                ? $ourRoomByKs[$care['room']] : null;

            // What Kidsoft OFFERED, if anything. Kidsoft-owned like the requested
            // days and refreshed with them; board_load() holds it as promised
            // while the status is one of KIDSOFT_OPEN_OFFER.
            $offered     = waitlist_offered($r);
            $offerRoomId = ($offered['room'] && isset($ourRoomByKs[$offered['room']]))
                ? $ourRoomByKs[$offered['room']] : null;

            $dateAdded = substr((string)($r['DateAdded'] ?? ''), 0, 10)
                      ?: substr((string)($r['WaitListedDate'] ?? ''), 0, 10)
                      ?: date('Y-m-d');

            // When Kidsoft will drop this application. Present on 89% of open rows.
            // Nothing in this app knew about it before, so a family could lapse off
            // the queue - losing both them and the place they wanted - while
            // Kidsoft quietly emailed the parent and nobody here followed it up.
            $expires = substr((string)($r['ExpiryDate'] ?? ''), 0, 10) ?: null;

            // Kidsoft's own note, plus the explanation a status like "Re-submit
            // Required" needs. Only ever used to fill an EMPTY notes column, so
            // it cannot bury what a human wrote after a phone call.
            $ksNote = trim(trim((string)($r['Notes'] ?? '')) . ' ' . (string)$statusNote) ?: null;

            if ($have) {
                $db->query(
                    'UPDATE waitlist_applications
                        SET child_first_name = ?, child_last_name = ?, child_dob = ?,
                            guardian_name = ?, guardian_email = ?, guardian_phone = ?,
                            date_added = ?, required_from = ?, kidsoft_expires_at = ?,
                            priority_of_access = ?, kidsoft_offer_room_id = ?,
                            notes = COALESCE(NULLIF(notes, \'\'), ?),
                            source = ?, kidsoft_synced_at = NOW()
                      WHERE id = ?',
                    [
                        $first, $last, $dob,
                        $guardianName ?: null,
                        trim((string)($pg1['Email'] ?? '')) ?: null,
                        $phone ?: null,
                        // kidsoft_child_id / kidsoft_guardian_id are NOT here:
                        // they are written once, above, for every row we hold
                        // whatever its status. Writing them here as well would
                        // mean two statements owning one fact, and this one
                        // would null a held id on the reads that omit it.
                        $dateAdded, $care['from'], $expires,
                        // REFRESHED, NOT SEEDED (13 Aug 2026). This used to be set
                        // on insert alone, on the reasoning that where a family
                        // sits on the statutory ladder is a judgement staff might
                        // make here and a sync must never revert. Measured before
                        // changing it: across 743 imported applications the stored
                        // value agreed with Kidsoft's current value on ALL 743 --
                        // zero divergences, so that judgement has never once been
                        // made and the freeze was protecting nothing.
                        //
                        // What it was COSTING is what forced this. The centres had
                        // been using PriorityOfAccess=1 to tag siblings, because
                        // Kidsoft had no other way to record one (Broc, 13 Aug
                        // 2026), and the enrolments team is now clearing those.
                        // Frozen on insert, that cleanup could never reach this
                        // app: all 23 affected rows already existed, so the import
                        // would have gone on re-reading them at 1 for ever and the
                        // team's work would have appeared to do nothing.
                        //
                        // Safe because the queue no longer leans on this field for
                        // siblings: 22 of those 23 are ranked by live sibling or
                        // in-care detection and keep their place without it.
                        $poa ?? 3,
                        $offerRoomId,
                        $ksNote,
                        'kidsoft',
                        $have['id'],
                    ]
                );
                $id = (int)$have['id'];
                $counts['updated']++;
            } else {
                $db->query(
                    'INSERT INTO waitlist_applications
                        (centre_id, kidsoft_waitlist_id, kidsoft_child_id, kidsoft_guardian_id,
                         child_first_name, child_last_name, child_dob,
                         guardian_name, guardian_email, guardian_phone,
                         date_added, required_from, kidsoft_expires_at, for_year, preferred_room_id,
                         kidsoft_offer_room_id,
                         priority_of_access, status, kidsoft_status, notes, source, kidsoft_synced_at)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())',
                    [
                        $centreId, $wlId, $r['ChildID'] ?: null, $r['GuardianID'] ?: null,
                        $first, $last, $dob,
                        $guardianName ?: null,
                        trim((string)($pg1['Email'] ?? '')) ?: null,
                        $phone ?: null,
                        $dateAdded, $care['from'], $expires, $forYear, $roomId,
                        $offerRoomId,
                        $poa ?? 3, $ourStatus, $ksStatus, $ksNote, 'kidsoft',
                    ]
                );
                $id = $db->insertId();
                $counts['added']++;
            }

            // Requested days are Kidsoft's, so they are replaced wholesale - but
            // only when Kidsoft actually sent some. waitlist_set_days() deletes
            // first, so calling it with an empty list on the one row in 698 that
            // has no RequestedDays would wipe days a human had put in.
            if ($days) {
                waitlist_set_days($id, $days);
            } else {
                $counts['no_days']++;
            }
            // Offered days are replaced EVEN WHEN EMPTY - see
            // waitlist_set_offered_days(). Nobody types them.
            waitlist_set_offered_days($id, $offered['days']);
        }

        // ---- Rows Kidsoft no longer returns AT ALL --------------------------
        //
        // ARCHIVING AN APPLICATION REMOVES IT FROM getWaitingList. There is no
        // `Archived` status and there never was: censused 8 Sep 2026 over all
        // 1,727 rows group-wide, the vocabulary is ExpiredWaitlist 975,
        // Waitlisted 657, Enrolled 52, ExpiryReminderFirst 13, ExpiredOffer 10,
        // Form Submitted 9, Offered 6, DeletedByParent 2, OfferedExpiryReminder
        // 2, ExpiryReminderSecond 1, and nothing else.
        //
        // THIS IS THE SAME FAILURE SHAPE THE STATUS MAP GUARDS AGAINST, for
        // disappearance instead of an unmapped value. The loop above only ever
        // visits rows Kidsoft SENT, so a row that vanished was never touched
        // again and stayed frozen at whatever status it last carried, with a
        // stale kidsoft_synced_at as the only trace. Measured 8 Sep 2026: 31 of
        // 823 imported rows were gone from the feed and 27 were still sitting in
        // the queue as open - Kanwal 14, Tuggerah 6, Point Clare 3, Wamberal 2,
        // Terrigal 1, Warnervale 1. Amara Walker was archived at Terrigal AND
        // Tuggerah and showed as waiting at both, which is how it was found.
        //
        // ARCHIVED IS HIDDEN EVERYWHERE, INCLUDING UNDER "All", AND THAT IS WHAT
        // SEPARATES IT FROM `expired`. Broc, 8 Sep 2026: "We don't want visible
        // Archived waitlist, but yes to Waiting Place Expired." An expired
        // application is history the team still reads; an archived one was taken
        // off the list on purpose and must not come back as a queue row.
        //
        // ABSENCE IS ONLY EVIDENCE WHEN THE FETCH ACTUALLY SUCCEEDED. A throw
        // never reaches here, and an EMPTY response is refused rather than read
        // as "this centre archived its entire queue" - one bad night would
        // otherwise blank a whole centre, and the rows cannot be recovered from
        // Kidsoft because they are no longer in the feed to re-import.
        //
        // Our own decisions are never overwritten, the same rule the status
        // transition above follows: a family this app has offered a place to, or
        // placed, keeps that status whatever Kidsoft does with its own row.
        if ($rows) {
            // Every id Kidsoft returned, whatever its status - a row carrying a
            // status this map has never seen is still PRESENT in Kidsoft and
            // must not be archived for it.
            $seen = [];
            foreach ($rows as $r) {
                $wl = trim((string)($r['WaitListID'] ?? ''));
                if ($wl !== '') $seen[$wl] = true;
            }
            $vanished = [];
            foreach ($byKidsoftId as $ksId => $e) {
                if (isset($seen[$ksId])) continue;
                if ($e['status'] === 'archived') continue;
                if (in_array($e['status'], WAITLIST_OURS_TO_KEEP, true)) continue;
                $vanished[] = (int)$e['id'];
            }
            if ($vanished) {
                $marks = $db->whereIn($vanished);
                $db->query(
                    "UPDATE waitlist_applications SET status = 'archived'
                      WHERE id IN($marks)",
                    $vanished
                );
                $counts['archived'] = count($vanished);
            }
        }
    });

    // Hand-keyed rows that look like a family Kidsoft also holds. Reported, never
    // merged: two applications for one child is also a real thing in this data
    // (Tuggerah has one child with two open WaitListIDs), so a machine cannot
    // tell a duplicate from a second genuine application.
    $counts['possible_duplicates'] = waitlist_import_duplicates($centreId);

    // THE PIPELINE FOLLOWS KIDSOFT'S OFFERS, now that this is where they are
    // read (15 Oct 2026) - see work_follow_kidsoft_offers(). After the commit,
    // because it reads what was just written, and swallowed on failure: an
    // import that fetched and stored the queue has done its job, and a stage
    // that moves on the next run is not worth losing the queue over.
    try {
        require_once __DIR__ . '/work.php';
        $counts['work_followed'] = work_follow_kidsoft_offers($centreId);
    } catch (Throwable $e) {
        error_log('work_follow_kidsoft_offers(' . $centreId . '): ' . $e->getMessage());
    }
    // AND CLOSES THE ITEM WHEN KIDSOFT ENROLS THE FAMILY (16 Oct 2026), here
    // because this is the read that stores `Enrolled` - so In progress and the
    // Waitlist change on the same import. See work_close_kidsoft_enrolled().
    try {
        $counts['work_completed'] = work_close_kidsoft_enrolled($centreId);
    } catch (Throwable $e) {
        $counts['work_completed'] = 0;
        error_log('work_close_kidsoft_enrolled(' . $centreId . '): ' . $e->getMessage());
    }

    audit('waitlist.import', 'centre', $centreId, $counts);

    return $counts;
}

// How long a centre's queue may go unimported before merely OPENING the Waitlist
// screen imports it.
//
// THIS USED TO BE JUSTIFIED AS "the same 900s as Waitlist/getWaitingList's
// KIDSOFT_TTL, because a shorter window would re-run 117 upserts against a cache
// that cannot have changed". The cache CAN have changed, and that reasoning cost
// the team a working day of a stale queue (9 Sep 2026). They are two independent
// clocks, so by the time the throttle lets an import run the cache is at or past
// its own expiry - which put every single import on kidsoft_cached()'s
// stale-while-revalidate path, committing the OLD snapshot and leaving the fresh
// one it fetched sitting in kidsoft_cache with nothing to import it.
//
// The import now reads LIVE (see below), so this number is only "how often is a
// live read worth making", not a statement about the cache.
const WAITLIST_IMPORT_VIEW_INTERVAL = 900;

/**
 * Import this centre's queue if nothing has imported it lately. Never throws.
 *
 * THIS REPLACED AN "IMPORT FROM KIDSOFT" BUTTON, and the button was the bug: the
 * queue is Kidsoft's, so a screen that shows it stale until a human asks for it
 * is asking staff to maintain something Kidsoft already knows. Opening the screen
 * IS the request to see the queue.
 *
 * IT READS LIVE (refresh => true), and that is the whole point of it rather than
 * an optimisation detail. Measured 0.4s per centre, at most once per 900s per
 * centre, and KIDSOFT_SHARE_FRESH already collapses simultaneous viewers into one
 * call. It used to read the cache, which was wrong for a reason worth stating:
 * this import's archive detection is an ABSENCE test, and absence in a stale
 * snapshot is not absence. Measured 9 Sep 2026, mid-afternoon: 55 of 690 open
 * rows group-wide were families already archived in Kidsoft - Wamberal 39,
 * Tuggerah 11 - every one of them carrying a kidsoft_synced_at from that same
 * day, because the import had faithfully re-imported a payload that still
 * contained them. Nothing Kidsoft OWNS was ever wrong (0 name, DOB, phone or
 * weekday mismatches across 635 open rows); only the disappearances lagged, and
 * they lagged until the 02:00 nightly, which is the one run that read live.
 *
 * The throttle reads audit_log rather than a new column, because audit() is the
 * LAST statement of a completed import, so the timestamp already means exactly
 * "when this centre last finished importing" - for the nightly cron equally as
 * for a page view. Compared in SQL so both sides are Sydney wall-clock (dates.php).
 *
 * A failure is swallowed on purpose: Kidsoft being unreachable is a normal
 * Tuesday, yesterday's queue is still the queue, and a screen that 500s because a
 * background refresh failed is strictly worse than one showing this morning's
 * data. It goes to the error log so it is still diagnosable.
 */
function waitlist_import_on_view(int $centreId): void {
    $recent = dbx()->query(
        "SELECT 1 FROM audit_log
          WHERE action = 'waitlist.import' AND entity_type = 'centre' AND entity_id = ?
            AND created_at > NOW() - INTERVAL ? SECOND
          LIMIT 1",
        [(string)$centreId, WAITLIST_IMPORT_VIEW_INTERVAL], 'si'
    )->fetch();
    if ($recent) return;

    try {
        waitlist_import_centre($centreId, ['refresh' => true]);
    } catch (Throwable $e) {
        error_log('waitlist_import_on_view(' . $centreId . '): ' . $e->getMessage());
    }
}

/**
 * Hand-keyed applications whose child name and DOB match an imported one.
 *
 * Compared on folded name plus DOB, which is the same standard the placement
 * board uses for an exact identity match - deliberately NOT the near-name
 * matcher, because attributing one family's queue position to another is worse
 * than showing a duplicate.
 *
 * @return array<array{id:int,name:string,imported_id:int}>
 */
function waitlist_import_duplicates(int $centreId): array {
    return dbx()->query(
        "SELECT m.id, CONCAT(m.child_first_name, ' ', m.child_last_name) AS name,
                k.id AS imported_id
           FROM waitlist_applications m
           JOIN waitlist_applications k
             ON k.centre_id = m.centre_id
            AND k.source = 'kidsoft'
            AND LOWER(k.child_first_name) = LOWER(m.child_first_name)
            AND LOWER(k.child_last_name)  = LOWER(m.child_last_name)
            AND (k.child_dob = m.child_dob OR (k.child_dob IS NULL AND m.child_dob IS NULL))
            -- An archived Kidsoft row is not something the hand-keyed row
            -- duplicates any more; reporting it would send the team to reconcile
            -- against an application that is no longer on Kidsoft's list.
            AND k.status <> 'archived'
          WHERE m.centre_id = ? AND m.source = 'manual'
            AND m.status NOT IN ('placed','withdrawn','expired','archived')",
        [$centreId], 'i'
    )->fetchAll();
}
