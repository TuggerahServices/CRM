<?php
// WORK ITEMS - tracking the work of filling an empty place.
//
// WHY THIS EXISTS. Kidsoft is the window: it tells you who actually holds a
// place. What it does not tell you is what anybody is DOING about the places
// nobody holds. Measured 6 Aug 2026 by pairing Kidsoft's own two dated enrolment
// events across 339 families, the stretch between a family joining the waiting
// list and their enrolment form being finalised runs a MEDIAN OF 21 DAYS - 43%
// take longer than 30 days, 24% longer than 90, the longest 463 - and for that
// whole time the only thing Kidsoft says about them is "Waitlisted". Of 1,593
// families who ever raised a waiting list entry, only 604 ever finalised a form.
// A thousand families went somewhere and nothing recorded where.
//
// That gap is the spreadsheets. It is why they survived this app: the app
// rebuilt the part Kidsoft was already doing and never built this part at all.
// Before this file, `offers` held 0 rows, `communications` held 0 rows, and the
// tour columns on waitlist_applications (tour_booked_at, guide_provided,
// orientation_at) were 0% populated - the funnel was modelled and never built.
//
// WHAT A WORK ITEM IS. One candidate family being worked toward one spot
// (centre, year, room, weekdays), moving through stages like a ticket. THREE
// kinds of candidate, and the difference matters:
//
//   * A NEW family from the queue (waitlist_id). 722 open applications.
//   * A child ALREADY IN CARE who has ASKED for more days - a wishlist entry in
//     Kidsoft (kidsoft_child_id). These families have PREFERENCE over the
//     external queue, which is policy, not convenience.
//   * A TOUR ENQUIRY (contact_phone / contact_email), added 14 Aug 2026. Somebody
//     who rang the centre and has applied for nothing yet. No year, no room, and
//     neither id above, because Kidsoft issues a ChildID only when an application
//     converts to an enrolment.
//
// TOURS ARE OURS, MEASURED RATHER THAN ASSUMED (14 Aug 2026). "Tour" and
// "orientation" appear ZERO times in the whole Kidsoft API reference - 15
// controllers, 30 get* actions, 65,641 bytes of Documentation/getMarkdown - and
// no notification category covers one. In Kidsoft a tour exists only as prose
// somebody typed into a waitlist note. Nor was the spreadsheet doing it: the
// Enquiries-Tours sheet in both surviving Tuggerah workbooks is a ruled template
// with TWELVE COLUMNS AND ZERO ROWS, which is exactly why the columns this file's
// next paragraph mentions were never populated - they mirror an empty sheet.
// So the diary starts here, and it starts empty on purpose: there is no history
// to backfill and inventing one would be a fiction.
//
// Note the second one is demand the family VOLUNTEERED. Little Miracles does not
// upsell, so nothing here goes looking for children to sell an extra day to; see
// the no-upselling correction in CLAUDE.md.
//
// THE DEFINITION OF DONE IS KIDSOFT, NOT A CHECKBOX. An item is not closed by a
// staff member saying so. A queue family's item closes the moment Kidsoft calls
// their application Enrolled (work_close_kidsoft_enrolled(), 16 Oct 2026); a
// child already in care being worked toward an extra day closes when the derived
// board shows them booked on those days (work_autoclose()). That is also the
// only honest way to measure the pipeline, because a stage somebody forgot to
// advance would otherwise read as a family still being worked.

declare(strict_types=1);

require_once __DIR__ . '/simple-mysqli.php';
require_once __DIR__ . '/dates.php';          // syd_datetime() for history lines
require_once __DIR__ . '/board.php';
require_once __DIR__ . '/audit.php';
require_once __DIR__ . '/offer-policy.php';   // OFFER_URGENT_HOURS - one definition
// comment_kidsoft_target() - WHERE a note about this family may be written in
// Kidsoft, which work_get() publishes so the modal can say whether a note will
// travel BEFORE it is typed. Required rather than re-derived: "only IDs Kidsoft
// itself supplies" is the rule that keeps one family's confidential note off
// another family's permanent record, and it gets exactly one implementation.
require_once __DIR__ . '/comments.php';
// sibling_phone_key() / sibling_email_key() - the one normalisation of a
// parent's contact, used to notice a tour enquiry and a queue application are
// the same family. Reused, never re-implemented. See work_queue_matches().
require_once __DIR__ . '/siblings.php';
require_once __DIR__ . '/waitlist-duplicates.php';   // the Waitlist's duplicate flag, on the item
// tour_calendar_status() - whether a tour reached the centre's Outlook calendar.
require_once __DIR__ . '/tour-calendar.php';

/**
 * The pipeline, in order. THIS ARRAY IS THE ORDERING - nothing else may encode
 * it, or two places will disagree about whether a tour comes before an offer.
 *
 * 'enrolled' and 'lost' are terminal. Everything before them is open work.
 */
const WORK_STAGES = [
    'identified' => ['label' => 'Identified',  'help' => 'A candidate for this place. Nobody has rung them yet.'],
    // 22 Oct 2026, from the enrolments meeting: a voicemail left or a text not
    // answered. It was being filed as Identified (as if nobody had tried) or as
    // Contacted (as if somebody had got through), and neither is true.
    'attempted_contact' => ['label' => 'Attempted contact', 'help' => 'We have tried them and not got through yet.'],
    'contacted'  => ['label' => 'Contacted',   'help' => 'We have spoken to the family.'],
    'tour_booked'=> ['label' => 'Tour booked', 'help' => 'A visit to the centre is in the diary.'],
    'tour_done'  => ['label' => 'Toured',      'help' => 'They have seen the room.'],
    'offered'    => ['label' => 'Offered',     'help' => 'A place is formally offered and is holding the days.'],
    'accepted'   => ['label' => 'Accepted',    'help' => 'They said yes. Waiting on Kidsoft to show the booking.'],
    'enrolled'   => ['label' => 'Enrolled',    'help' => 'Kidsoft shows the family enrolled. Closed by Kidsoft, not by hand.'],
    'lost'       => ['label' => 'Lost',        'help' => 'Not proceeding.'],
    // 6 Nov 2026 (Broc): CLOSED WITHOUT AN OUTCOME. Lennox Dransfield's item sat
    // at Toured on a leftover application Wamberal had archived in Kidsoft, after
    // a DIFFERENT application of his had enrolled. Lost would say we failed to get
    // a family we got; Enrolled is Kidsoft's to say; "Created by mistake" deletes
    // real history. Archived keeps the history and counts as neither. Set only by
    // the Archive button (work_archive()), never picked from the stage list.
    'archived'   => ['label' => 'Archived',    'help' => 'Closed without an outcome, e.g. a duplicate application. The history is kept.'],
];

/**
 * Terminal stages - an item here is closed and off the work list.
 *
 * THE SQL IN THIS FILE SPELLS THE SAME LIST OUT, as NOT IN ("enrolled","lost",
 * "archived"), ten times. Adding a terminal stage means grepping for every one
 * of them, and the schema.sql ENUM, and the JS tests of w.stage in progress.js
 * and dashboard.js - the list that had to be walked when `archived` was added.
 */
const WORK_CLOSED_STAGES = ['enrolled', 'lost', 'archived'];

/** Position in the pipeline, for ordering and for "how far did this get". */
function work_stage_rank(string $stage): int {
    return (int)array_search($stage, array_keys(WORK_STAGES), true);
}

function work_is_open(string $stage): bool {
    return !in_array($stage, WORK_CLOSED_STAGES, true);
}

/**
 * Create a work item.
 *
 * Enforces in code what the schema could not: an item must name somebody. See
 * the note on ck_work_who in schema.sql for why that check cannot be a CHECK.
 *
 * THREE WAYS TO NAME SOMEBODY, not two (14 Aug 2026). A queue application, a
 * Kidsoft child, or - for a TOUR ENQUIRY - a name plus at least one way of
 * ringing the parent back. A parent who phones to book a tour has neither id:
 * Kidsoft creates a child record only when an application converts to an
 * enrolment, and they have not applied for anything yet. Requiring an id would
 * have meant no tour could be booked until the family was already in the queue,
 * which is backwards - the tour is usually what puts them there.
 *
 * IDEMPOTENT ON (who, room, year) while an item is still OPEN, because the
 * obvious way to create duplicates is two staff opening the same cell on the
 * same morning and both clicking the same candidate. AN ENQUIRY CANNOT BE
 * DE-DUPED and deliberately is not tried: it has no id, and matching on a typed
 * name would join two different families - the same trap waitlist-group.php
 * measured, where "TBA Anderson" and two "TBA TBA" children are all real and all
 * different. Two staff taking one call make two rows; a human can see that.
 *
 * WHAT IS CAUGHT INSTEAD (14 Oct 2026) is an enquiry for a family ALREADY IN THE
 * QUEUE, which is what both enquiries ever booked turned out to be. That is not
 * done here and not on a name: /api/work's `create` finds the open applications
 * sharing a parent's phone or email (work_queue_matches(), and the reverse,
 * work_enquiry_matches()) and a person says which child. This function stays
 * idempotent on ids alone.
 *
 * @param array $d centre_id, room_id?, year?, waitlist_id?, kidsoft_child_id?,
 *                 child_name, contact_name?, contact_phone?, contact_email?,
 *                 days[], stage?, tour_at?, owner_email?, next_action?,
 *                 next_action_at?
 * @return int work item id
 */
function work_create(array $d): int {
    $centreId = (int)($d['centre_id'] ?? 0);
    // NULL year is legitimate now: an enquiry has not named one, and every reader
    // already treats null as "every year". 0 and '' both mean unset here.
    $year     = ($d['year'] ?? null) === null || $d['year'] === '' || (int)$d['year'] === 0
                    ? null : (int)$d['year'];
    $roomId   = work_clean_room_id($d['room_id'] ?? null, $centreId);
    $wlId     = !empty($d['waitlist_id']) ? (int)$d['waitlist_id'] : null;
    $ksId     = trim((string)($d['kidsoft_child_id'] ?? '')) ?: null;
    $name     = trim((string)($d['child_name'] ?? ''));
    $stage    = (string)($d['stage'] ?? 'identified');
    $cName    = trim((string)($d['contact_name'] ?? ''))  ?: null;
    $cPhone   = trim((string)($d['contact_phone'] ?? '')) ?: null;
    $cEmail   = trim((string)($d['contact_email'] ?? '')) ?: null;
    $tourAt   = work_clean_tour_at($d['tour_at'] ?? null);

    if (!$centreId)                            throw new InvalidArgumentException('centre required');
    if ($year !== null && ($year < 2000 || $year > 2100)) {
        throw new InvalidArgumentException('year looks wrong');
    }
    if ($name === '')                          throw new InvalidArgumentException('child name required');
    if (!$wlId && !$ksId && !$cPhone && !$cEmail) {
        throw new InvalidArgumentException(
            'A work item must name somebody. For an enquiry that means a phone number or an email, '
          . 'so somebody can ring them back.');
    }
    if (!isset(WORK_STAGES[$stage]))           throw new InvalidArgumentException('unknown stage');

    // Booking a tour IS reaching the tour stage. Setting a date and leaving the
    // stage at `identified` would put a family in the diary and off the pipeline.
    if ($tourAt && work_stage_rank($stage) < work_stage_rank('tour_booked')) $stage = 'tour_booked';

    // Already being worked? Hand back the existing item rather than a second one.
    // <=> not = on year, or a null-year item never matches itself.
    if ($wlId || $ksId) {
        $existing = dbx()->query(
            'SELECT id FROM work_items
              WHERE (year <=> ?) AND stage NOT IN ("enrolled","lost","archived")
                AND ((waitlist_id IS NOT NULL AND waitlist_id = ?)
                  OR (kidsoft_child_id IS NOT NULL AND kidsoft_child_id = ?))
                AND (room_id <=> ?)
              LIMIT 1',
            [$year, $wlId, $ksId, $roomId]
        )->fetch();
        if ($existing) return (int)$existing['id'];
    }

    dbx()->query(
        'INSERT INTO work_items (centre_id, room_id, year, waitlist_id, kidsoft_child_id,
                child_name, contact_name, contact_phone, contact_email,
                stage, tour_at, owner_email, next_action, next_action_at, next_action_for, created_by)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
        [$centreId, $roomId, $year, $wlId, $ksId, $name, $cName, $cPhone, $cEmail,
         $stage, $tourAt, $d['owner_email'] ?? null, $d['next_action'] ?? null,
         $d['next_action_at'] ?? null, $d['next_action_for'] ?? null, $d['created_by'] ?? null]
    );
    $id = dbx()->insertId();
    work_set_days($id, $d['days'] ?? []);
    work_log($id, null, $stage, $d['note'] ?? null, $d['created_by'] ?? 'staff');
    audit('work.create', 'work_item', $id, [
        'centre_id' => $centreId, 'room_id' => $roomId, 'year' => $year,
        'child' => $name, 'stage' => $stage, 'tour_at' => $tourAt,
    ]);
    return $id;
}

/**
 * Normalise a posted tour datetime to 'Y-m-d H:i:s', or null.
 *
 * Accepts what <input type="datetime-local"> sends ('2026-08-18T10:00'). The
 * string is stored AS TYPED, in Sydney wall-clock, like every other datetime in
 * this app - see _incl/dates.php. It is deliberately NOT passed through
 * strtotime()->format() with a timezone, because that is the reinterpretation
 * that rule exists to forbid.
 */
function work_clean_tour_at($v): ?string {
    $v = trim((string)($v ?? ''));
    if ($v === '') return null;
    $v = str_replace('T', ' ', $v);
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})[ ](\d{2}):(\d{2})(?::(\d{2}))?$/', $v, $m)) {
        throw new InvalidArgumentException('That tour date did not look like a date and time.');
    }
    if (!checkdate((int)$m[2], (int)$m[3], (int)$m[1])) {
        throw new InvalidArgumentException('That tour date is not a real date.');
    }
    return sprintf('%s-%s-%s %s:%s:%s', $m[1], $m[2], $m[3], $m[4], $m[5], $m[6] ?? '00');
}

/** Replace the weekdays an item is working toward. */
function work_set_days(int $id, array $weekdays): void {
    $clean = array_values(array_unique(array_filter(
        array_map('intval', $weekdays), static fn($w) => $w >= 1 && $w <= 5)));
    sort($clean);
    dbx()->query('DELETE FROM work_item_days WHERE work_item_id = ?', [$id], 'i');
    foreach ($clean as $w) {
        dbx()->query('INSERT INTO work_item_days (work_item_id, weekday) VALUES (?,?)', [$id, $w], 'ii');
    }
}

/** Append to an item's history. */
function work_log(int $id, ?string $from, string $to, ?string $note, string $actor = 'staff'): void {
    dbx()->query(
        'INSERT INTO work_item_events (work_item_id, from_stage, to_stage, note, actor) VALUES (?,?,?,?,?)',
        [$id, $from, $to, $note !== null && $note !== '' ? $note : null, $actor]
    );
}

/**
 * Move an item to a stage.
 *
 * Moving BACKWARDS is allowed and deliberate - a family who declined an offer
 * but stays interested goes back to 'contacted', and refusing that would mean
 * closing the item and losing its history, which is exactly what the spreadsheet
 * did badly.
 *
 * @param string $actor 'staff' normally; 'kidsoft' when the mirror closed it.
 */
function work_advance(int $id, string $stage, ?string $note = null, string $actor = 'staff'): bool {
    if (!isset(WORK_STAGES[$stage])) throw new InvalidArgumentException('unknown stage');
    $w = dbx()->query('SELECT * FROM work_items WHERE id = ?', [$id], 'i')->fetch();
    if (!$w) return false;
    if ($w['stage'] === $stage) return false;

    // "OFFERED" MUST MEAN A REAL OFFER THAT IS HOLDING THE DAYS. An offer row is
    // what room_day_vacancies() subtracts, so an item sitting at `offered` with
    // no offer behind it is a family who looks committed while their seat is
    // still being shown as free to everyone else - the exact double-booking the
    // offers table exists to prevent. Only a QUEUE family can hold one: `offers`
    // is keyed on waitlist_id, and for a child already in care an extra day is
    // granted in Kidsoft rather than offered.
    //
    // OFFERS ARE SENT IN KIDSOFT NOW (15 Oct 2026), so a Kidsoft offer counts:
    // board_load() holds its days exactly as it holds an `offers` row's. The
    // message used to point at this app's Offer button, which is gone.
    if ($stage === 'offered' && $actor !== 'kidsoft'
        && $w['waitlist_id'] && !$w['offer_id']) {
        $app = dbx()->query('SELECT status, kidsoft_status FROM waitlist_applications WHERE id = ?',
                            [(int)$w['waitlist_id']], 'i')->fetch();
        // The same test as waitlist_kidsoft_offer_open(), written out because
        // this file does not load the waitlist engine (it is loaded BY it).
        if (!$app || $app['status'] !== 'waitlisted'
            || !isset(KIDSOFT_OPEN_OFFER[(string)$app['kidsoft_status']])) {
            throw new InvalidArgumentException(
                'Send the offer in Kidsoft first. This moves to Offered by itself once Kidsoft has it.');
        }
    }

    $closing = !work_is_open($stage);
    dbx()->query(
        'UPDATE work_items SET stage = ?, outcome_note = ?, closed_at = ? WHERE id = ?',
        [$stage,
         in_array($stage, ['lost', 'archived'], true) ? ($note ?: $w['outcome_note']) : $w['outcome_note'],
         $closing ? date('Y-m-d H:i:s') : null,
         $id]
    );
    // Moving to Toured by hand answers the tour, so the diary does not keep
    // asking. Only when a tour was actually booked and nobody has answered it --
    // this must never overwrite a recorded no-show.
    if ($stage === 'tour_done' && $w['tour_at'] && $w['tour_outcome'] === null) {
        dbx()->query('UPDATE work_items SET tour_outcome = ? WHERE id = ?', ['toured', $id]);
    }
    work_log($id, $w['stage'], $stage, $note, $actor);
    audit('work.advance', 'work_item', $id, ['from' => $w['stage'], 'to' => $stage, 'actor' => $actor]);
    return true;
}

/**
 * Book, move or clear a tour.
 *
 * Booking one moves the item to `tour_booked` if it has not got that far, for the
 * same reason work_create() does: a family in the diary who is not in the
 * pipeline is a family the pipeline cannot see. Moving a tour that has already
 * been answered clears the answer - the new appointment has not happened yet.
 *
 * @param string|null $tourAt null clears it
 */
function work_set_tour(int $id, ?string $tourAt, ?string $note = null, string $actor = 'staff'): bool {
    $w = dbx()->query('SELECT * FROM work_items WHERE id = ?', [$id], 'i')->fetch();
    if (!$w) return false;

    $clean = work_clean_tour_at($tourAt);
    if (($w['tour_at'] ?? null) === $clean) return false;

    // A cleared tour takes the parent's invite tick with it, so the next tour
    // booked here does not send one nobody ticked - see work_set_tour_invite().
    dbx()->query('UPDATE work_items SET tour_at = ?, tour_outcome = NULL' . ($clean === null ? ', tour_invite = 0' : '')
               . ' WHERE id = ?', [$clean, $id]);
    audit('work.tour', 'work_item', $id, ['from' => $w['tour_at'], 'to' => $clean]);

    if ($clean === null) {
        // Cancelled. The stage is left alone deliberately: a family who called off
        // a visit has still been spoken to, and demoting them would lose that.
        work_log($id, $w['stage'], $w['stage'], $note ?: 'Tour cancelled.', $actor);
        return true;
    }
    // syd_datetime(), not the raw column: this line is READ BY THE TEAM in the
    // item's history, and "2026-08-21 09:00:00" is a database value, not a date
    // anybody writes down. Stored and displayed both in Sydney wall-clock, no
    // conversion - see _incl/dates.php.
    $when = function_exists('syd_datetime') ? syd_datetime($clean) : $clean;
    if (work_stage_rank($w['stage']) < work_stage_rank('tour_booked')) {
        work_advance($id, 'tour_booked', $note ?: 'Tour booked for ' . $when . '.', $actor);
    } else {
        work_log($id, $w['stage'], $w['stage'], $note ?: 'Tour set for ' . $when . '.', $actor);
    }
    return true;
}

/**
 * Tick or untick "Send the invite to the parent" (29 Oct 2026) - on EVERY child on
 * the visit, because a visit has one calendar entry and so one invite (see THE
 * PARENT'S INVITE in tour-calendar.php). Off when there is no tour. Nothing is
 * sent here: tour_calendar_sync() does that after the response has gone.
 *
 * @return int[] the items whose tick changed
 */
function work_set_tour_invite(int $id, bool $on): array {
    $w = dbx()->query('SELECT id, centre_id, tour_at FROM work_items WHERE id = ?', [$id], 'i')->fetch();
    if (!$w) return [];
    $on    = $on && $w['tour_at'] !== null;
    $ids   = array_map(static fn($r) => (int)$r['id'], tour_visit($w)) ?: [$id];
    $marks = dbx()->whereIn($ids);
    $changed = dbx()->query("SELECT id FROM work_items WHERE id IN ($marks) AND tour_invite <> ?",
                            array_merge($ids, [(int)$on]))->fetchAll('col');
    if (!$changed) return [];
    $changed = array_map('intval', $changed);
    dbx()->query('UPDATE work_items SET tour_invite = ? WHERE id IN (' . dbx()->whereIn($changed) . ')',
                 array_merge([(int)$on], $changed));
    foreach ($changed as $c) audit('work.tour_invite', 'work_item', $c, ['on' => $on]);
    return $changed;
}

/**
 * Record what happened at a tour: they came, or they did not.
 *
 * A NO-SHOW IS NOT A STAGE, and that is the load-bearing decision here.
 * WORK_STAGES is rank-ordered and read by work_stage_rank() all over this file,
 * and a family who did not turn up is not further along the pipeline than one
 * with a visit booked - it is the same place with a wasted appointment. So the
 * outcome is its own column and the item goes BACK to `contacted`, exactly the
 * treatment work_offer_answered() gives a declined or expired offer, for exactly
 * the same reason: a missed appointment is not a decision to stop chasing
 * somebody. Losing a family stays something a person does on purpose.
 *
 * @param string $outcome 'toured' or 'no_show'
 */
function work_tour_outcome(int $id, string $outcome, ?string $note = null, string $actor = 'staff'): bool {
    if (!in_array($outcome, ['toured', 'no_show'], true)) {
        throw new InvalidArgumentException('unknown tour outcome');
    }
    $w = dbx()->query('SELECT * FROM work_items WHERE id = ?', [$id], 'i')->fetch();
    if (!$w) return false;
    if (!$w['tour_at']) throw new InvalidArgumentException('There is no tour booked on this one.');

    dbx()->query('UPDATE work_items SET tour_outcome = ? WHERE id = ?', [$outcome, $id]);
    audit('work.tour_outcome', 'work_item', $id, ['outcome' => $outcome]);

    if ($outcome === 'toured') {
        // Only forwards. A family already offered a place has plainly toured, and
        // dragging them back to `tour_done` would undo real progress.
        if (work_stage_rank($w['stage']) < work_stage_rank('tour_done')) {
            work_advance($id, 'tour_done', $note ?: 'Toured the centre.', $actor);
        } else {
            work_log($id, $w['stage'], $w['stage'], $note ?: 'Toured the centre.', $actor);
        }
        return true;
    }

    if ($w['stage'] !== 'contacted') {
        work_advance($id, 'contacted', $note ?: 'Did not show for the tour.', $actor);
    } else {
        work_log($id, $w['stage'], $w['stage'], $note ?: 'Did not show for the tour.', $actor);
    }
    return true;
}

/**
 * The OPEN work item behind one waitlist application, or null.
 *
 * WHY THE FAMILY MODAL NEEDS IT. The Waitlist used to carry a tour date of its
 * own - `waitlist_applications.tour_booked_at`, a date with no time, one of the
 * twelve columns copied off the Enquiries-Tours spreadsheet sheet that was
 * itself empty - and In progress kept the real one here. Nothing joined them,
 * and the live data showed exactly what that costs: Jack and Sersha Donnelly
 * were booked into one 09:30 tour on 19 Sep 2026, and only Sersha's waitlist row
 * carried a date, without the time. Two records of one appointment, already
 * disagreeing on the second family who ever had one. The column is retired (see
 * api/waitlist.php's save) and this is what the modal reads instead.
 *
 * ONE ITEM, and which one is decided here rather than by the caller: an
 * application can have several open items (work_create() de-dupes on room and
 * year, so one family worked toward two rooms is two rows). The one carrying a
 * TOUR wins, then the earliest tour, then the oldest item - so booking from the
 * family modal moves the appointment that already exists rather than quietly
 * making a second one.
 *
 * Decorated like a work_list() row for the fields the modal reads, so "has the
 * tour happened" is answered on the server's Sydney clock in one place.
 */
function work_for_waitlist(int $waitlistId): ?array {
    $w = dbx()->query(
        'SELECT w.id, w.centre_id, w.room_id, w.year, w.stage, w.tour_at, w.tour_outcome,
                w.calendar_event_id, w.calendar_error, w.tour_invite, w.calendar_invited,
                c.name AS centre_name
           FROM work_items w
           JOIN centres c ON c.id = w.centre_id
          WHERE w.waitlist_id = ? AND w.stage NOT IN ("enrolled","lost","archived")
          ORDER BY w.tour_at IS NULL, w.tour_at, w.id
          LIMIT 1',
        [$waitlistId], 'i'
    )->fetch();
    if (!$w) return null;

    $w['id']           = (int)$w['id'];
    $w['centre_id']    = (int)$w['centre_id'];
    $w['room_id']      = $w['room_id'] === null ? null : (int)$w['room_id'];
    $w['year']         = $w['year'] === null ? null : (int)$w['year'];
    $w['stage_label']  = WORK_STAGES[$w['stage']]['label'] ?? $w['stage'];
    $w['tour_at']      = $w['tour_at'] ?: null;
    $w['tour_outcome'] = $w['tour_outcome'] ?: null;
    // Sydney wall-clock, compared as strings against the stored string - the
    // same rule work_list() and work_tours() follow, and the same field name the
    // work modal already reads.
    $w['tour_past']    = $w['tour_at'] !== null && $w['tour_at'] < date('Y-m-d H:i:s');
    // The tour's copy in the centre's Outlook calendar - see tour-calendar.php.
    $w['calendar']     = tour_calendar_status($w);
    $w['tour_invite']  = (bool)$w['tour_invite'];
    unset($w['calendar_event_id'], $w['calendar_error'], $w['calendar_invited']);
    return $w;
}

// ---------------------------------------------------------------------------
// ONE FAMILY, TWO ROWS: a tour enquiry and a queue application (14 Oct 2026).
//
// Reported from Terrigal: "I have 2 of the same child In progress". At 09:09 a
// tour was booked for Seren Willow Jones as a NEW ENQUIRY - a typed name and a
// phone number - and at 09:44 a colleague started the same child from the
// Waitlist, where her application had been sitting since 30 July under that same
// phone number. Nothing joined the two, because work_create() de-dupes on an id
// and an enquiry has none (see the note there - that rule stands).
//
// MEASURED BEFORE IT WAS GUARDED: BOTH enquiries ever booked were families
// already in a queue. Seren at the same centre; Albie Keogh's email matches an
// application at another centre. The enquiry form was being used for queue
// families because nothing on it said they were already in the queue.
//
// MATCHED ON A PARENT'S PHONE OR EMAIL, AND A PERSON DECIDES. The contact alone
// cannot decide it: the same phone matched Seren AND her brother Jacob, whose
// applications share it. So these functions only FIND candidates; the screen
// asks which child, and nothing is joined without somebody saying so. That is
// the waitlist family-merge rule from the other side - a shared contact is
// evidence of a family, never of a child.

/** Application statuses that are still in a queue. Placed, expired, withdrawn and archived are not. */
const WORK_QUEUE_OPEN = ['waitlisted', 'offered', 'partially_placed'];

/**
 * Open applications at a centre that share a parent's phone or email with what
 * was typed for a tour enquiry - each with the open work item already behind it,
 * so the screen can book the tour there instead of starting a second row.
 *
 * Compared with the same keys the sibling and family-merge checks use
 * (sibling_phone_key / sibling_email_key), so "0481 345 242" and "+61481345242"
 * are one number here exactly as they are everywhere else.
 */
function work_queue_matches(int $centreId, ?string $phone, ?string $email): array {
    $pk = sibling_phone_key($phone);
    $ek = sibling_email_key($email);
    if (!$pk && !$ek) return [];

    $marks = dbx()->whereIn(WORK_QUEUE_OPEN);
    $rows = dbx()->query(
        "SELECT a.id, a.child_first_name, a.child_last_name, a.child_dob, a.date_added,
                a.for_year, a.guardian_phone, a.guardian_email,
                GROUP_CONCAT(d.weekday ORDER BY d.weekday) AS days
           FROM waitlist_applications a
           LEFT JOIN waitlist_days d ON d.waitlist_id = a.id
          WHERE a.centre_id = ? AND a.status IN ($marks)
          GROUP BY a.id",
        array_merge([$centreId], WORK_QUEUE_OPEN)
    )->fetchAll();

    $out = [];
    foreach ($rows as $a) {
        $hit = ($pk && sibling_phone_key($a['guardian_phone']) === $pk)
            || ($ek && sibling_email_key($a['guardian_email']) === $ek);
        if (!$hit) continue;
        $item = work_for_waitlist((int)$a['id']);
        $out[] = [
            'id'         => (int)$a['id'],
            'name'       => trim($a['child_first_name'] . ' ' . $a['child_last_name']),
            'dob'        => syd_date($a['child_dob']),
            'date_added' => syd_date($a['date_added']),
            'for_year'   => $a['for_year'] === null ? null : (int)$a['for_year'],
            'days'       => $a['days'] === null ? [] : array_map('intval', explode(',', $a['days'])),
            'work'       => $item ? ['id' => $item['id'], 'stage' => $item['stage'],
                                     'stage_label' => $item['stage_label'],
                                     'tour_at' => $item['tour_at'] ? syd_datetime($item['tour_at']) : null]
                                  : null,
        ];
    }
    return $out;
}

/**
 * Open tour enquiries at an application's centre that share its parent's phone
 * or email - the other door into the same duplicate. A family who toured first
 * and applied in Kidsoft afterwards is the NORMAL order of events (the tour is
 * usually what puts a family in the queue), so this is the direction that will
 * keep happening after the enquiry form learns to look.
 */
function work_enquiry_matches(int $waitlistId): array {
    $a = dbx()->query(
        'SELECT centre_id, guardian_phone, guardian_email FROM waitlist_applications WHERE id = ?',
        [$waitlistId], 'i')->fetch();
    if (!$a) return [];
    $pk = sibling_phone_key($a['guardian_phone']);
    $ek = sibling_email_key($a['guardian_email']);
    if (!$pk && !$ek) return [];

    $rows = dbx()->query(
        'SELECT id, child_name, contact_name, contact_phone, contact_email, stage, tour_at, created_at
           FROM work_items
          WHERE centre_id = ? AND waitlist_id IS NULL AND kidsoft_child_id IS NULL
            AND stage NOT IN ("enrolled","lost","archived")
          ORDER BY id',
        [(int)$a['centre_id']], 'i')->fetchAll();

    $out = [];
    foreach ($rows as $w) {
        $hit = ($pk && sibling_phone_key($w['contact_phone']) === $pk)
            || ($ek && sibling_email_key($w['contact_email']) === $ek);
        if (!$hit) continue;
        $out[] = [
            'id'          => (int)$w['id'],
            'child_name'  => $w['child_name'],
            'contact'     => trim(implode(' ', array_filter([$w['contact_name'],
                                 $w['contact_phone'] ?: $w['contact_email']]))),
            'stage_label' => WORK_STAGES[$w['stage']]['label'] ?? $w['stage'],
            'tour_at'     => $w['tour_at'] ? syd_datetime($w['tour_at']) : null,
            'created'     => syd_date($w['created_at']),
        ];
    }
    return $out;
}

/**
 * Make a tour enquiry the queue application's work item, rather than leaving
 * the family on two rows. The enquiry keeps its id, its stage, its tour and its
 * history - it is the older record of the same conversation - and gains the
 * application behind it.
 *
 * ONLY A HUMAN CALLS THIS, after saying which child. The year and the days are
 * the application's where the enquiry never named any; anything the enquiry
 * already says is left alone. The contact columns stay as typed (they render for
 * an enquiry only, and this row is no longer one) so nothing anybody wrote is
 * thrown away.
 *
 * Contact notes logged against the enquiry are NOT re-filed onto the
 * application. work_contact_history() finds them by the item id, and re-filing
 * them would put them in the nightly Kidsoft copy: createComment has no update
 * and no delete, and a join made on a phone match is exactly the kind of
 * evidence that rule refuses to write on.
 *
 * WHAT THE CALLER WAS DOING STILL APPLIES. The board's "Start with this family"
 * names a room and a weekday; the Waitlist names the application's days. Those
 * fill whatever the enquiry left empty - so a family joined from a cell lands ON
 * that cell - and never overwrite what it already says.
 *
 * @param array $with room_id?, year?, days[]? - what the caller posted
 * @return int the enquiry's id, now the family's item
 */
function work_join_enquiry(int $enquiryId, int $waitlistId, string $actor, array $with = []): int {
    $w = dbx()->query(
        'SELECT id, centre_id, room_id, year, stage FROM work_items
          WHERE id = ? AND waitlist_id IS NULL AND kidsoft_child_id IS NULL',
        [$enquiryId], 'i')->fetch();
    $a = dbx()->query(
        'SELECT centre_id, for_year, child_first_name, child_last_name FROM waitlist_applications WHERE id = ?',
        [$waitlistId], 'i')->fetch();
    if (!$w || !work_is_open($w['stage'])) throw new InvalidArgumentException('That enquiry is not open any more.');
    if (!$a) throw new InvalidArgumentException('Application not found.');
    if ((int)$w['centre_id'] !== (int)$a['centre_id']) {
        throw new InvalidArgumentException('That enquiry is at a different centre.');
    }

    $posted = (int)($with['year'] ?? 0) ?: null;
    $year   = $w['year'] ?? $posted ?? $a['for_year'];
    $roomId = $w['room_id'] ?? ((int)($with['room_id'] ?? 0) ?: null);
    if ($roomId !== null) {
        // The room has to be this centre's - the one thing posted here that an
        // id alone could get wrong.
        $ok = dbx()->query('SELECT 1 FROM rooms WHERE id = ? AND centre_id = ?',
                           [$roomId, (int)$w['centre_id']], 'ii')->fetch();
        if (!$ok) throw new InvalidArgumentException('That room is not at this centre.');
    }
    $days = array_values(array_filter(array_map('intval', $with['days'] ?? []),
                                      static fn($d) => $d >= 1 && $d <= 5));
    dbx()->transaction(function ($db) use ($enquiryId, $waitlistId, $year, $roomId, $days) {
        $db->query('UPDATE work_items SET waitlist_id = ?, year = ?, room_id = ? WHERE id = ?',
                   [$waitlistId, $year, $roomId, $enquiryId]);
        // Days only if the enquiry had none - an enquiry has not been asked.
        // The caller's, else the application's: the form is where the family said.
        $has = $db->query('SELECT COUNT(*) n FROM work_item_days WHERE work_item_id = ?',
                          [$enquiryId], 'i')->fetch()['n'];
        if (!(int)$has && $days) {
            foreach (array_unique($days) as $d) {
                $db->query('INSERT INTO work_item_days (work_item_id, weekday) VALUES (?, ?)',
                           [$enquiryId, $d], 'ii');
            }
        } elseif (!(int)$has) {
            $db->query('INSERT INTO work_item_days (work_item_id, weekday)
                        SELECT ?, weekday FROM waitlist_days WHERE waitlist_id = ?',
                       [$enquiryId, $waitlistId], 'ii');
        }
    });
    $name = trim($a['child_first_name'] . ' ' . $a['child_last_name']);
    work_log($enquiryId, $w['stage'], $w['stage'],
             'Joined to ' . $name . "'s queue application. Same family, one row.", $actor);
    audit('work.join', 'work_item', $enquiryId,
          ['waitlist_id' => $waitlistId, 'year' => $year, 'room_id' => $roomId]);
    return $enquiryId;
}

// ---------------------------------------------------------------------------
// BROTHERS AND SISTERS: ONE FAMILY, SEVERAL ITEMS (19 Oct 2026).
//
// Broc: "it would be very unlikely that after a tour that we offer a enrolment
// to one sibling and not the other... right?" Measured that morning over every
// pair of siblings ever imported at the same centre (75): of the 28 where either
// child got an offer or an enrolment, the 20 who asked to start within a month of
// each other were offered TOGETHER, every one; the 6 offered alone had all asked
// to start apart - a baby wanting a later year, a third child off to school. And
// a tour is one visit for the family whatever the start dates: Terrigal typed
// "Tour: Seren Jones (SS 2026. Sibling Jacob LA 2027)" into Outlook by hand.
//
// What he agreed, as four rules:
//
//   1. THE TOUR IS THE FAMILY'S. Booking, moving or cancelling it for one child
//      does the same for the brothers and sisters the person leaves ticked,
//      starting them in In progress if they are not there; answering it (came /
//      did not show) answers it for everybody on that visit; and the centre's
//      calendar gets ONE entry for the visit (tour-calendar.php, tour_visit()).
//   2. LOST ASKS. Marking one child Lost offers the others, unticked.
//   3. AN OFFER TO ONE AND NOT THE OTHER, for the same start, is flagged on the
//      item (work_offer_gap()). Offers are made in Kidsoft, so this app cannot
//      make the second one; it can say it is missing.
//   4. A NOTE SHOWS ON BOTH, travels on one: communication_siblings, in
//      api/comms. A sibling match is a shared parent phone or email - the evidence
//      the createComment rule refuses to write on - so the Kidsoft copy stays on
//      the child the note was written for.
//
// WHO COUNTS is the Waitlist's own rule (a shared parent phone or email, and not
// the same child applied for twice - waitlist_same_child(), siblings.php), asked
// for ONE CENTRE from our own table. The Waitlist's group-wide version
// (waitlist_siblings_waiting()) reads every centre's queue through Kidsoft and
// took 22.7s cold on 19 Oct; a modal cannot open behind that, and a family action
// only ever concerns the centre the family is touring. Still waiting only: a
// child already placed is not on the visit and has no item to move.
// ---------------------------------------------------------------------------

/**
 * Two siblings asking to start within this many days of each other are expected
 * to be offered together. 20 of 20 measured pairs inside it were; outside it, 6
 * of 8 were not - see the block above.
 */
const WORK_SIBLING_SAME_START_DAYS = 30;

/** Does Kidsoft (or an offer made here before 15 Oct 2026) show a place offered to this application? */
function work_app_has_offer(array $a): bool {
    return $a['status'] === 'offered' || $a['kidsoft_status'] === 'Enrolled'
        || isset(KIDSOFT_OPEN_OFFER[(string)$a['kidsoft_status']]);
}

/** One application, in the shape the family actions and the modals read. */
function work_sibling_shape(array $a): array {
    return [
        'waitlist_id'   => (int)$a['id'],
        'name'          => trim($a['child_first_name'] . ' ' . $a['child_last_name']),
        'first_name'    => trim((string)$a['child_first_name']),
        'required_from' => $a['required_from'] ?: null,
        'starts'        => $a['required_from'] ? syd_date($a['required_from']) : null,
        'for_year'      => $a['for_year'] === null ? null : (int)$a['for_year'],
        'days'          => $a['days'] === null ? [] : array_map('intval', explode(',', (string)$a['days'])),
        'open'          => in_array($a['status'], WORK_QUEUE_OPEN, true),
        'has_offer'     => work_app_has_offer($a),
        // A CHILD ALREADY IN CARE who also holds an open application - Wyatt
        // Smith is in care at Niagara Park and #2 in its queue. Read the way the
        // queue reads it (waitlist_mark_in_care(): name and DOB against Kidsoft's
        // children), or off a ChildID, which Kidsoft issues at the conversion. Six
        // of the 76 families with a sibling here on 19 Oct had one. Listed, never
        // ticked for them, and not counted as an offer missing: their place is
        // not new, and the centre is not new to them.
        'in_care'       => !empty($a['kidsoft_child_id']) || !empty($a['in_care']),
    ];
}

/**
 * The brothers and sisters still waiting AT THE SAME CENTRE as each application,
 * each with the open In progress item behind them, if any.
 *
 * Batch, because In progress asks it for every row on the screen at once: two
 * queries for the whole list, never one per row.
 *
 * One entry per sibling CHILD: a child applied for twice at this centre (Jack
 * Donnelly has two Terrigal applications) is one sibling, carried by their
 * earliest application.
 *
 * @param int[] $waitlistIds
 * @return array<int, array{app: array, siblings: array}> keyed by the asked-for id;
 *         an id that names no application is absent
 */
function work_siblings_here(array $waitlistIds): array {
    $ids = array_values(array_unique(array_filter(array_map('intval', $waitlistIds))));
    if (!$ids) return [];
    $cols = 'a.id, a.centre_id, a.child_first_name, a.child_last_name, a.child_dob, a.date_added,
             a.required_from, a.for_year, a.status, a.kidsoft_status, a.kidsoft_child_id,
             a.guardian_phone, a.guardian_email,
             (SELECT GROUP_CONCAT(d.weekday ORDER BY d.weekday) FROM waitlist_days d
               WHERE d.waitlist_id = a.id) AS days';
    $base = dbx()->query("SELECT $cols FROM waitlist_applications a WHERE a.id IN (" . dbx()->whereIn($ids) . ')',
                         $ids)->fetchAll();
    if (!$base) return [];

    $centres = array_values(array_unique(array_map(static fn($b) => (int)$b['centre_id'], $base)));
    $pool = dbx()->query(
        "SELECT $cols FROM waitlist_applications a
          WHERE a.centre_id IN (" . dbx()->whereIn($centres) . ')
            AND a.status IN (' . dbx()->whereIn(WORK_QUEUE_OPEN) . ')',
        array_merge($centres, WORK_QUEUE_OPEN))->fetchAll();

    // The one normalisation of a parent's contact - siblings.php.
    $keys = static function (array $a): array {
        $k = [];
        if (($p = sibling_phone_key($a['guardian_phone'])) !== null) $k[] = 'p:' . $p;
        if (($e = sibling_email_key($a['guardian_email'])) !== null) $k[] = 'e:' . $e;
        return $k;
    };
    $byKey = [];
    foreach ($pool as $i => $a) {
        foreach ($keys($a) as $k) $byKey[(int)$a['centre_id'] . '|' . $k][] = $i;
    }

    $found = [];
    foreach ($base as $b) {
        $cands = [];
        foreach ($keys($b) as $k) {
            foreach ($byKey[(int)$b['centre_id'] . '|' . $k] ?? [] as $i) {
                $a = $pool[$i];
                if ((int)$a['id'] === (int)$b['id'] || isset($cands[(int)$a['id']])) continue;
                if (waitlist_same_child($b, $a)) continue;
                $cands[(int)$a['id']] = $a;
            }
        }
        uasort($cands, static fn($x, $y) =>
            [(string)$x['date_added'], (int)$x['id']] <=> [(string)$y['date_added'], (int)$y['id']]);
        $kept = [];
        foreach ($cands as $a) {
            foreach ($kept as $k) if (waitlist_same_child($k, $a)) continue 2;
            $kept[] = $a;
        }
        $found[(int)$b['id']] = [$b, $kept];
    }

    // IN CARE, marked the queue's way - one memoised index of Kidsoft's children,
    // 70ms warm. An outage leaves the ChildID test alone, never an error.
    $mark = [];
    foreach ($found as $id => [$b, $kept]) {
        $mark[(int)$b['id']] = $b;
        foreach ($kept as $a) $mark[(int)$a['id']] = $a;
    }
    try {
        require_once __DIR__ . '/waitlist.php';
        foreach (waitlist_mark_in_care(array_values($mark)) as $a) $mark[(int)$a['id']] = $a;
    } catch (Throwable $e) {
        error_log('work_siblings_here in_care: ' . $e->getMessage());
    }
    foreach ($found as $id => [$b, $kept]) {
        $found[$id] = [$mark[(int)$b['id']], array_map(static fn($a) => $mark[(int)$a['id']], $kept)];
    }

    // The open item behind each sibling - work_for_waitlist()'s preference: the
    // one carrying a tour, then the earliest tour, then the oldest - and whether
    // the only one they ever had was closed as Lost.
    $sibIds = [];
    foreach ($found as [, $kept]) foreach ($kept as $a) $sibIds[(int)$a['id']] = true;
    $open = []; $lost = [];
    if ($sibIds) {
        $rows = dbx()->query(
            'SELECT id, waitlist_id, stage, tour_at, tour_outcome FROM work_items
              WHERE waitlist_id IN (' . dbx()->whereIn(array_keys($sibIds)) . ')
              ORDER BY tour_at IS NULL, tour_at, id', array_keys($sibIds))->fetchAll();
        foreach ($rows as $w) {
            $wl = (int)$w['waitlist_id'];
            if ($w['stage'] === 'lost') { $lost[$wl] = true; continue; }
            if ($w['stage'] === 'enrolled' || $w['stage'] === 'archived' || isset($open[$wl])) continue;
            $open[$wl] = ['id' => (int)$w['id'], 'stage' => $w['stage'],
                          'stage_label' => WORK_STAGES[$w['stage']]['label'] ?? $w['stage'],
                          'tour_at' => $w['tour_at'] ?: null, 'tour_outcome' => $w['tour_outcome'] ?: null];
        }
    }

    $out = [];
    foreach ($found as $id => [$b, $kept]) {
        $out[$id] = [
            'app'      => work_sibling_shape($b),
            'siblings' => array_map(static function ($a) use ($open, $lost) {
                $wl = (int)$a['id'];
                return work_sibling_shape($a) + [
                    'item'     => $open[$wl] ?? null,
                    'was_lost' => !isset($open[$wl]) && isset($lost[$wl]),
                ];
            }, $kept),
        ];
    }
    return $out;
}

/**
 * An offer to one child and not the other, when they asked to start together:
 * the slip Broc asked about. One entry per sibling on the other side of it.
 *
 * Both directions are reported, so whichever child's item is open says so -
 * "Louis has no offer yet" on the child who has one, "Lucas has an offer" on
 * the child who does not. A child already in care is left out on either side:
 * their place is not new, and a re-enrolment is not this kind of offer.
 *
 * @param array $entry one value of work_siblings_here()
 */
function work_offer_gap(array $entry): array {
    $b = $entry['app'];
    if (!$b['open'] || $b['in_care'] || !$b['required_from']) return [];
    $out = [];
    foreach ($entry['siblings'] as $s) {
        if ($s['in_care'] || !$s['required_from'] || $s['has_offer'] === $b['has_offer']) continue;
        $days = abs(strtotime($s['required_from']) - strtotime($b['required_from'])) / 86400;
        if ($days > WORK_SIBLING_SAME_START_DAYS) continue;
        $out[] = ['waitlist_id' => $s['waitlist_id'], 'name' => $s['name'], 'first_name' => $s['first_name'],
                  'offered' => $s['has_offer'], 'starts' => $s['starts']];
    }
    return $out;
}

/**
 * The siblings a request names, checked against the family the server finds -
 * never trusted, so a post cannot reach an application that is not this child's
 * brother or sister at this centre. Throws BEFORE anything is written.
 *
 * @param mixed $posted the raw `siblings[]` (or one id)
 * @return array sibling entries from work_siblings_here()
 */
function work_family_pick(?int $waitlistId, $posted): array {
    $raw = is_array($posted) ? $posted : (($posted === null || $posted === '') ? [] : [$posted]);
    $ids = array_values(array_unique(array_filter(array_map('intval', $raw))));
    if (!$ids) return [];
    if (!$waitlistId) {
        throw new InvalidArgumentException('Only a family in the queue has brothers or sisters to include.');
    }
    $fam = [];
    foreach (work_siblings_here([$waitlistId])[$waitlistId]['siblings'] ?? [] as $s) $fam[$s['waitlist_id']] = $s;
    $out = [];
    foreach ($ids as $id) {
        if (!isset($fam[$id])) throw new InvalidArgumentException('That brother or sister is not waiting at this centre.');
        $out[] = $fam[$id];
    }
    return $out;
}

/**
 * Give the ticked brothers and sisters THIS item's tour - booked, moved or
 * cancelled - after it has been written on this item.
 *
 * A sibling with an open item has the tour set on it through work_set_tour(),
 * the one way a tour is written. A sibling with none is started in In progress,
 * exactly as booking a tour from the family modal starts one: the application's
 * days and year, no room. NOT a sibling who matches a Do Not Enrol family -
 * starting work on them is the step that question guards, and it is asked one
 * family at a time on the Waitlist; they are skipped and named instead.
 *
 * @param array $sibs entries from work_family_pick()
 * @return array{ids:int[], names:string[], skipped:array<string,string>}
 */
function work_family_tour(int $itemId, array $sibs, string $actor): array {
    $out = ['ids' => [], 'names' => [], 'skipped' => []];
    if (!$sibs) return $out;
    $w = dbx()->query('SELECT id, centre_id, child_name, tour_at FROM work_items WHERE id = ?',
                      [$itemId], 'i')->fetch();
    if (!$w) return $out;
    $tour = $w['tour_at'] ?: null;
    $note = $tour ? 'Tour booked with ' . $w['child_name'] . ', one visit: ' . syd_datetime($tour) . '.'
                  : 'Tour cancelled with ' . $w['child_name'] . '.';

    $created = false;
    foreach ($sibs as $s) {
        if ($s['item']) {
            if ($s['item']['tour_at'] === $tour) continue;     // already on this visit
            work_set_tour((int)$s['item']['id'], $tour, $note, $actor);
            $out['ids'][] = (int)$s['item']['id'];
            $out['names'][] = $s['name'];
            continue;
        }
        if ($tour === null) continue;                           // nothing booked, nothing to start
        require_once __DIR__ . '/do-not-enrol.php';
        $app = dbx()->query('SELECT kidsoft_guardian_id, guardian_email, guardian_phone
                               FROM waitlist_applications WHERE id = ?', [$s['waitlist_id']], 'i')->fetch();
        if ($app && dne_match($app)) {
            $out['skipped'][$s['name']] = 'matches a Do Not Enrol family, so start them from the Waitlist once checked';
            continue;
        }
        $out['ids'][] = work_create([
            'centre_id'   => (int)$w['centre_id'],
            'year'        => $s['for_year'],
            'waitlist_id' => $s['waitlist_id'],
            'child_name'  => $s['name'],
            'days'        => $s['days'],
            'tour_at'     => $tour,
            'note'        => $note,
            'owner_email' => $actor !== 'staff' ? $actor : null,
            'created_by'  => $actor,
        ]);
        $out['names'][] = $s['name'];
        $created = true;
    }
    // Started after Kidsoft already offered them a place: at the stage Kidsoft
    // says, as `create` does.
    if ($created) work_follow_kidsoft_offers((int)$w['centre_id']);
    return $out;
}

/**
 * Record a tour's outcome for EVERYBODY ON THAT VISIT - the brothers and sisters
 * booked into the same appointment (tour_visit()). One family came or did not.
 *
 * Only items still open and still unanswered follow: an answer already recorded
 * stays, and a Lost item is never reopened by a tour it did not come to.
 *
 * @return string[] the other children it was recorded for
 */
function work_tour_outcome_visit(int $id, string $outcome, ?string $note, string $actor): array {
    $w = dbx()->query('SELECT id, centre_id, tour_at, child_name FROM work_items WHERE id = ?', [$id], 'i')->fetch();
    if (!$w) return [];
    $others = array_filter(tour_visit($w), static fn($r) =>
        (int)$r['id'] !== $id && work_is_open($r['stage']) && $r['tour_outcome'] === null);
    work_tour_outcome($id, $outcome, $note, $actor);
    $names = [];
    foreach ($others as $r) {
        work_tour_outcome((int)$r['id'], $outcome,
            $note ?: (($outcome === 'toured' ? 'Toured the centre' : 'Did not show for the tour')
                      . ', with ' . $w['child_name'] . '.'), $actor);
        $names[] = $r['child_name'];
    }
    return $names;
}

/**
 * THE TOUR DIARY: what is booked, and what has happened and gone unanswered.
 *
 * Two lists, because they are two different jobs. `upcoming` is a reminder --
 * somebody is coming and the room should be tidy. `unresolved` is a question:
 * the appointment has passed and nobody has said whether the family turned up,
 * which is the only way a tour silently disappears.
 *
 * ALL YEARS AND ALL STAGES, including closed ones. A tour that happened last week
 * for a family who has since been lost is still a tour that happened, and the
 * counts on the dashboard are counts of tours, not of live pipeline.
 *
 * @param int|null $centreId null = the whole group
 * @param int $daysAhead how far forward `upcoming` reaches
 */
function work_tours(?int $centreId, int $daysAhead = 14): array {
    $where = ['w.tour_at IS NOT NULL'];
    $args  = [];
    if ($centreId !== null) { $where[] = 'w.centre_id = ?'; $args[] = $centreId; }

    $rows = dbx()->query(
        'SELECT w.id, w.centre_id, w.child_name, w.contact_name, w.contact_phone,
                w.stage, w.tour_at, w.tour_outcome, w.owner_email, w.year,
                c.name AS centre_name, r.name AS room_name
           FROM work_items w
           JOIN centres c ON c.id = w.centre_id
           LEFT JOIN rooms r ON r.id = w.room_id
          WHERE ' . implode(' AND ', $where) . '
          ORDER BY w.tour_at',
        $args
    )->fetchAll();

    // Sydney wall-clock throughout: these strings were stored as typed and are
    // compared as typed. Nothing here goes near a timezone conversion.
    $now   = date('Y-m-d H:i:s');
    $today = substr($now, 0, 10);
    $limit = date('Y-m-d 23:59:59', strtotime($today . ' +' . $daysAhead . ' days'));

    $out = ['upcoming' => [], 'unresolved' => [], 'counts' => [
        'upcoming' => 0, 'unresolved' => 0, 'this_week' => 0, 'toured' => 0, 'no_show' => 0]];

    // The week the team is standing in: Monday to Sunday, Sydney.
    $monday = date('Y-m-d', strtotime('monday this week', strtotime($today)));
    $sunday = date('Y-m-d', strtotime($monday . ' +6 days'));

    foreach ($rows as $t) {
        $t['id']        = (int)$t['id'];
        $t['centre_id'] = (int)$t['centre_id'];
        $t['year']      = $t['year'] === null ? null : (int)$t['year'];
        $t['day']       = substr((string)$t['tour_at'], 0, 10);
        $t['stage_label'] = WORK_STAGES[$t['stage']]['label'] ?? $t['stage'];

        if ($t['day'] >= $monday && $t['day'] <= $sunday) $out['counts']['this_week']++;
        if ($t['tour_outcome'] === 'toured')  $out['counts']['toured']++;
        if ($t['tour_outcome'] === 'no_show') $out['counts']['no_show']++;

        if ($t['tour_outcome'] === null && $t['tour_at'] < $now) {
            $out['unresolved'][] = $t;
            $out['counts']['unresolved']++;
        } elseif ($t['tour_outcome'] === null && $t['tour_at'] <= $limit) {
            $out['upcoming'][] = $t;
            $out['counts']['upcoming']++;
        }
    }
    // Oldest unanswered first: the one nobody has dealt with longest.
    return $out;
}

/**
 * CLOSE EVERY ITEM THE MIRROR SAYS IS DONE.
 *
 * This is the join between the two halves of the app: our record of the work,
 * and Kidsoft's record of reality. An item is finished when the child it names
 * is actually in the room it names - not when somebody remembers to tick it.
 *
 * Matching is by KIDSOFT CHILD ID ONLY, never by name. That is the right way
 * round: closing the wrong family's item would tell the team a place is filled
 * when it is empty, and the empty place is the thing the whole organisation is
 * paid to notice. A miss just costs somebody closing it by hand.
 *
 * A QUEUE FAMILY'S ID IS PICKED UP THE MOMENT KIDSOFT ISSUES ONE (17 Aug 2026).
 * This function used to skip every item with no ChildID on it, which is almost
 * every item raised from the queue - a waiting family is not enrolled anywhere,
 * so Kidsoft has not issued one (2 of 696 open applications). But Kidsoft issues
 * it at the CONVERSION: measured over all 1,699 waitlist rows group-wide, every
 * one of the 17 at status `Enrolled` carries a ChildID and a GuardianID, against
 * ~0% at every other status. So the id turns up on the application row exactly
 * when the family becomes real in Kidsoft, and this reads it through the item's
 * own waitlist_id.
 *
 * IT IS READ, NOT COPIED ONTO THE ITEM. work_items says a family is EITHER a
 * queue application or a child already in care, and stamping the ChildID onto a
 * queue item would blur which kind of work it is for a fact that is one join
 * away. The join is also self-healing: the day the import stores the id, the
 * next sweep sees it.
 *
 * FOR A QUEUE FAMILY THIS IS NOW THE SLOWER OF TWO CLOSES. Since 16 Oct 2026 an
 * application Kidsoft calls `Enrolled` closes its item outright - see
 * work_close_kidsoft_enrolled(), which says why the "every day booked" test
 * below lost for them. This function still decides for a child already in care
 * being worked toward an extra day, who has no application to read.
 *
 * Day-level: the item closes only if EVERY day it was working toward is now
 * booked. A family who took Monday but not the Wednesday they also wanted is
 * still work in progress.
 *
 * SWEEPS EVERY YEAR THAT HAS OPEN ITEMS WHEN $year IS NULL, and that is now the
 * only way it is called. It used to be handed the CURRENT year and nothing else,
 * because api/dashboard.php hard-codes today's year (the Dashboard has no year
 * control). An item aimed at NEXT year therefore never closed itself: the family
 * re-enrolled, Kidsoft showed them booked, and the item kept nagging for the rest
 * of the year. work-layer.md flagged that as "worth knowing"; the re-enrolment
 * chase is the first feature to actually trip over it, because every one of its
 * items is aimed at next year by construction.
 *
 * Cost is one derived board per (centre, YEAR WITH OPEN WORK) rather than per
 * centre - in practice one or two, and board_load() is already cached.
 *
 * @param ?int $year a single year, or null for every year with open items
 * @return int items closed
 */
function work_autoclose(int $centreId, ?int $year = null): int {
    if ($year === null) {
        $years = dbx()->query(
            'SELECT DISTINCT year FROM work_items
              WHERE centre_id = ? AND year IS NOT NULL
                AND stage NOT IN ("enrolled","lost","archived")
              ORDER BY year',
            [$centreId], 'i'
        )->fetchAll('col');
        $closed = 0;
        foreach ($years as $y) $closed += work_autoclose($centreId, (int)$y);
        return $closed;
    }

    $open = dbx()->query(
        'SELECT w.id, w.room_id, w.child_name,
                COALESCE(w.kidsoft_child_id, a.kidsoft_child_id) AS kidsoft_child_id,
                GROUP_CONCAT(d.weekday ORDER BY d.weekday) AS days
           FROM work_items w
           -- The application behind a queue item, for the id Kidsoft issues when
           -- that application converts. See the header: this is the only way an
           -- item raised from the queue can ever close itself.
           LEFT JOIN waitlist_applications a ON a.id = w.waitlist_id
           LEFT JOIN work_item_days d ON d.work_item_id = w.id
          WHERE w.centre_id = ? AND w.year = ?
            AND w.stage NOT IN ("enrolled","lost","archived")
            AND COALESCE(w.kidsoft_child_id, a.kidsoft_child_id) IS NOT NULL
          GROUP BY w.id',
        [$centreId, $year], 'ii'
    )->fetchAll();
    if (!$open) return 0;

    // What Kidsoft actually holds, via the same derivation the board renders.
    $board = board_load($centreId, $year);
    $booked = [];   // ksChildId => roomId => [weekday => true]
    foreach ($board['rooms'] as $r) {
        foreach ($r['placements'] as $p) {
            if (!$p['kidsoft_child_id']) continue;
            foreach ($p['days'] as $wd) $booked[$p['kidsoft_child_id']][(int)$r['id']][$wd] = true;
        }
    }

    $closed = 0;
    foreach ($open as $w) {
        $ks   = $w['kidsoft_child_id'];
        $want = array_filter(array_map('intval', explode(',', (string)$w['days'])));
        if (!$want) continue;   // nothing specified: cannot judge, leave it alone

        // A NULL room means the room was never pinned down, so any room counts:
        // the family got a place, which is what the item was for.
        $rooms = $w['room_id'] ? [(int)$w['room_id']] : array_keys($booked[$ks] ?? []);
        $got = false;
        foreach ($rooms as $rid) {
            $have = $booked[$ks][$rid] ?? [];
            if (!array_diff($want, array_keys($have))) { $got = true; break; }
        }
        if (!$got) continue;

        work_advance((int)$w['id'], 'enrolled',
            'Closed automatically: Kidsoft shows ' . $w['child_name'] . ' booked on every day this was working toward.',
            'kidsoft');
        $closed++;
    }
    return $closed;
}

/**
 * CLOSE EVERY QUEUE ITEM WHOSE APPLICATION KIDSOFT CALLS `Enrolled` (16 Oct 2026).
 *
 * Broc's call: *"once the waitlist child becomes imported on Kidsoft 'Enrolled'
 * [as it] normally shows on the waitlist. Can that be for the in progress too? Or
 * move to 'Completed' automatically?"* - and, asked which, "close it straight
 * away".
 *
 * THIS REVERSES "BEING VISIBLE IN KIDSOFT IS NOT THE CLOSE" for a queue family,
 * and the reason that rule gave no longer wins. It held an item open until the
 * board showed the child booked on EVERY day it was working toward, so the
 * Waitlist said Enrolled the moment the import saw the conversion while In
 * progress went on listing the family as work - for days, because the sweep only
 * ran when somebody opened the Dashboard, and for as long as next year's bookings
 * were not entered. The enrolments team's part ends at the conversion; booking
 * the days is the centre's. A family who took fewer days than they asked for is
 * not this item's business any more: a day they still want arrives as a wishlist
 * request, like any enrolled family's.
 *
 * work_autoclose() STAYS for the other kind of item - a child already in care
 * being worked toward an extra day has no application to read a status from, so
 * the board is still the only thing that can say it is done.
 *
 * Reads only our own table (the import stores Kidsoft's raw status on every row
 * it holds, `kidsoft_status`), so it is free to call on any screen load.
 *
 * @param ?int $centreId null for the whole group
 * @return int items closed
 */
function work_close_kidsoft_enrolled(?int $centreId = null): int {
    $rows = dbx()->query(
        "SELECT w.id, w.child_name
           FROM work_items w
           JOIN waitlist_applications a ON a.id = w.waitlist_id
          WHERE w.stage NOT IN ('enrolled','lost','archived')
            AND a.kidsoft_status = 'Enrolled'"
          . ($centreId !== null ? ' AND w.centre_id = ?' : ''),
        $centreId !== null ? [$centreId] : []
    )->fetchAll();

    $closed = 0;
    foreach ($rows as $w) {
        if (work_advance((int)$w['id'], 'enrolled',
                'Closed automatically: Kidsoft shows ' . $w['child_name'] . ' as Enrolled.',
                'kidsoft')) {
            $closed++;
        }
    }
    return $closed;
}

/**
 * OPEN WORK PER (ROOM, WEEKDAY) - so a spot can say whether anyone is already on it.
 *
 * THE GAP THIS CLOSES. Only a formal offer HOLDS a place: room_day_vacancies()
 * subtracts pending offers as `promised`, and an accepted one keeps the seat
 * through the provisional placement respond_offer writes. Everything BEFORE the
 * offer - identified, contacted, tour booked, toured - holds nothing, which is
 * deliberate (see work_advance(): an item at `offered` with no offer behind it is
 * a family who looks committed while their seat still reads as free). But that
 * pre-offer window is exactly the paperwork, the checks and the tour, and this
 * file's own header measures it at a MEDIAN OF 21 DAYS, 43% of them over 30. For
 * that whole time the cell rendered the same free count as before and nothing on
 * any screen said a colleague was already working it, so two staff could start
 * two families down the same 21-day path against one place and only find out at
 * the offer, where one of them loses.
 *
 * It is ADVISORY, not a lock. A work item still holds nothing - `free` is
 * unchanged and the offer transaction remains the only authority on what is
 * promised - because a family who goes quiet at `contacted` would otherwise
 * sterilise a place indefinitely. Same rule as over-capacity: warn, never block.
 *
 * STAGES AT OR PAST `offered` ARE EXCLUDED, and that is the load-bearing line.
 * Those are already counted against the cell, as `promised` or as occupancy, so
 * showing them here as well would report one family twice against one place --
 * the double-count this function exists to prevent, in the other direction.
 *
 * An item with no room is skipped: it cannot be attributed to a cell. Those are
 * the ones raised from the Waitlist, which deliberately sets no room (a family in
 * the queue has not been matched to one). They are visible on In progress.
 *
 * Takes the list work_list() already built rather than querying again, so the
 * stage shown against a cell is the same object the module and the panel show.
 *
 * @param array $items rows from work_list()
 * @return array<int,array<int,array>> roomId => weekday => compact items
 */
function work_by_cell(array $items): array {
    $heldFrom = work_stage_rank('offered');
    $out = [];
    foreach ($items as $w) {
        if (empty($w['room_id'])) continue;
        if (work_stage_rank($w['stage']) >= $heldFrom) continue;
        foreach ($w['days'] as $wd) {
            $out[(int)$w['room_id']][(int)$wd][] = [
                'id'          => (int)$w['id'],
                'child_name'  => $w['child_name'],
                'stage'       => $w['stage'],
                'stage_label' => $w['stage_label'],
                'owner_email' => $w['owner_email'],
                'idle_days'   => $w['idle_days'],
            ];
        }
    }
    return $out;
}

/**
 * The open work list for a centre and year.
 *
 * @param array $opts 'stage' => filter, 'overdue' => bool, 'include_closed' => bool
 */
function work_list(?int $centreId, ?int $year, array $opts = []): array {
    // $year null = every year. Open work is open work: a family being chased for
    // next January is work today, and hiding it behind a year control is how an
    // item goes quiet unnoticed.
    //
    // $centreId null = THE WHOLE GROUP. This used to be required, which is why
    // the Dashboard's work card rendered on a centre and returned early on All
    // centres - so "what is in flight across the organisation" could not be
    // asked at all. A work item belongs to one centre, but a LIST of them does
    // not have to.
    $where = [];
    $args  = [];
    if ($centreId !== null) { $where[] = 'w.centre_id = ?'; $args[] = $centreId; }
    if ($year !== null) { $where[] = 'w.year = ?'; $args[] = $year; }
    if (empty($opts['include_closed'])) $where[] = 'w.stage NOT IN ("enrolled","lost","archived")';
    if (!empty($opts['stage']))        { $where[] = 'w.stage = ?'; $args[] = $opts['stage']; }
    if (!empty($opts['overdue']))        $where[] = 'w.next_action_at IS NOT NULL AND w.next_action_at < CURDATE()';
    // DUE = the date has come round, today included (22 Oct 2026). A reminder
    // set for Friday has to surface ON Friday, and `overdue` only finds it on
    // Monday. The reminder lives in the app and nowhere else (Broc's call), so
    // this is the whole of how it reaches anybody.
    if (!empty($opts['due']))            $where[] = 'w.next_action_at IS NOT NULL AND w.next_action_at <= CURDATE()';
    // The tour diary as a filter on this same list, so the tiles above it count
    // the rows below it. An answered tour is history and drops out: what this
    // filter is for is the appointments still carrying a question.
    if (!empty($opts['tours']))          $where[] = 'w.tour_at IS NOT NULL AND w.tour_outcome IS NULL';
    // "Mine". The caller passes the session's own email - see /api/work, where
    // this is deliberately not read from the query string.
    if (!empty($opts['owner_email']))  { $where[] = 'w.owner_email = ?'; $args[] = $opts['owner_email']; }
    // MINE = looking after the family, OR named on its next step. The second
    // half is the reminder: "ring back Friday, for Emily" lands in Emily's view
    // whoever owns the item.
    if (!empty($opts['mine'])) {
        $where[] = '(w.owner_email = ? OR w.next_action_for = ?)';
        $args[] = $opts['mine']; $args[] = $opts['mine'];
    }

    // THE KIDSOFT OFFER'S DEADLINE when there is no offer of ours (15 Oct 2026).
    // Offers are sent in Kidsoft now, so without this every new offer would sit
    // on this list with no clock and sort as though it had none. Only while the
    // family has still to answer - see KIDSOFT_OPEN_OFFER - and to the END of
    // Kidsoft's ExpiryDate, the rule offer_expiry_normalise() gives a bare date.
    $ksLive = array_keys(array_filter(KIDSOFT_OPEN_OFFER, static fn($k) => !$k['answered']));
    $ksExp  = "CASE WHEN a.status = 'waitlisted' AND a.kidsoft_status IN('"
            . implode("','", $ksLive) . "') AND a.kidsoft_expires_at IS NOT NULL
                    THEN TIMESTAMP(a.kidsoft_expires_at, '23:59:59') END";
    $rows = dbx()->query(
        'SELECT w.*, r.name AS room_name, c.name AS centre_name,
                COALESCE(o.expires_at, ' . $ksExp . ') AS offer_expires_at, o.status AS offer_status,
                TIMESTAMPDIFF(HOUR, NOW(), COALESCE(o.expires_at, ' . $ksExp . ')) AS offer_hours_left,
                GROUP_CONCAT(d.weekday ORDER BY d.weekday) AS days,
                (SELECT MAX(occurred_at) FROM work_item_events e WHERE e.work_item_id = w.id) AS last_event_at
           FROM work_items w
           LEFT JOIN rooms r ON r.id = w.room_id
           JOIN centres c ON c.id = w.centre_id
           -- The live offer, when the item has one. Pulled here rather than
           -- looked up per row: an expiry is a HARD deadline and it is what
           -- orders this whole list, so it cannot be an afterthought.
           LEFT JOIN offers o ON o.id = w.offer_id AND o.status = \'pending\'
           LEFT JOIN waitlist_applications a ON a.id = w.waitlist_id
           LEFT JOIN work_item_days d ON d.work_item_id = w.id'
          . ($where ? "\n          WHERE " . implode(' AND ', $where) : '') . '
          GROUP BY w.id',
        $args
    )->fetchAll();

    $today  = date_create('today', new DateTimeZone('Australia/Sydney'));
    // Sydney wall-clock, compared as a string against stored strings - the same
    // rule work_tours() follows. No timezone conversion anywhere near these.
    $nowStr = date('Y-m-d H:i:s');
    $touched = work_last_touched($rows);
    foreach ($rows as &$w) {
        $w['id']    = (int)$w['id'];
        // WHO LAST DID SOMETHING TO IT, AND WHEN (6 Nov 2026) - the In progress
        // "Last interacted" column. A PERSON only; see work_last_touched().
        $w['last_touched_at'] = $touched[$w['id']]['at'] ?? null;
        $w['last_touched_by'] = $touched[$w['id']]['by'] ?? null;
        $w['days']  = $w['days'] === null ? [] : array_map('intval', explode(',', $w['days']));
        $w['rank']  = work_stage_rank($w['stage']);
        $w['open']  = work_is_open($w['stage']);
        $w['stage_label'] = WORK_STAGES[$w['stage']]['label'] ?? $w['stage'];
        // Days since anything happened. THE POINT OF THE SCREEN: a family nobody
        // has touched for three weeks is the failure mode the spreadsheet had,
        // and neither Kidsoft nor this app could previously see it.
        //
        // AND A PERSON'S TOUCH COUNTS TOO (6 Nov 2026). This read stage events
        // alone, so a next step edited or a call logged left the counter running:
        // Milla Mae Duffy (Tuggerah) was updated by a person on 6 Nov and still
        // counted from 30 Oct. With "Last interacted" on the same row that is a
        // row contradicting itself, so the later of the two wins. Kidsoft's own
        // moves still count here (they are events) - a family whose offer Kidsoft
        // just sent is not quiet - while the column names only a person.
        $last = max((string)($w['last_event_at'] ?: $w['created_at']), (string)$w['last_touched_at']);
        $w['idle_days'] = $last
            ? (int)$today->diff(date_create(substr((string)$last, 0, 10)))->days : null;
        $w['overdue'] = $w['next_action_at'] !== null
            && $w['next_action_at'] < $today->format('Y-m-d');
        // Today counts as due, not overdue - see the `due` filter above.
        $w['due'] = $w['next_action_at'] !== null
            && $w['next_action_at'] <= $today->format('Y-m-d');
        $w['next_action_for'] = $w['next_action_for'] ?: null;

        // Fields the In progress module orders on. Computed here so there is ONE
        // definition of "how urgent is this" - see work_urgency_sort().
        $w['kind']             = 'work';
        $w['centre_id']        = (int)$w['centre_id'];
        // NULL is a real answer on an enquiry - nobody named a year - so it is
        // published as null rather than 0. Every consumer must print it as "--".
        $w['year']             = $w['year'] === null ? null : (int)$w['year'];
        $w['offer_id']         = $w['offer_id'] === null ? null : (int)$w['offer_id'];
        $w['offer_hours_left'] = $w['offer_hours_left'] === null ? null : (int)$w['offer_hours_left'];
        $w['owner_email']      = $w['owner_email'] ?: null;

        // THE TOUR IS A SECOND HARD CLOCK, and the two states it produces are
        // different questions. `tour_unresolved` means the appointment has passed
        // and nobody has said whether the family came - the way a tour goes
        // missing. `tour_upcoming` means somebody is turning up.
        $w['tour_at']         = $w['tour_at'] ?: null;
        $w['tour_outcome']    = $w['tour_outcome'] ?: null;
        $w['tour_unresolved'] = $w['tour_at'] !== null && $w['tour_outcome'] === null
                                && $w['tour_at'] < $nowStr;
        $w['tour_upcoming']   = $w['tour_at'] !== null && $w['tour_outcome'] === null
                                && $w['tour_at'] >= $nowStr;
    }
    unset($w);

    // Overdue first, then longest idle: the work list is ordered by neglect,
    // not by age, because an item created yesterday and already chased is in
    // better shape than one raised a month ago and forgotten.
    usort($rows, static function ($a, $b) {
        if ($a['overdue'] !== $b['overdue']) return $a['overdue'] ? -1 : 1;
        return ($b['idle_days'] ?? 0) <=> ($a['idle_days'] ?? 0);
    });
    return $rows;
}

/**
 * EVERYTHING IN PROGRESS: work items and any offer that has none behind it.
 *
 * This backs the In progress module (formerly Offers). The two are ONE list
 * because an offer is not a separate kind of thing - it is a STAGE of a work
 * item: `WORK_STAGES` contains 'offered', and work_link_offer() sets offer_id
 * and advances the item into it. The old module showed that one stage and
 * nothing else, which is why a user in UAT went looking for work in flight there
 * and found only offers.
 *
 * A UNION, NOT A SUPERSET, and this is the trap. work_link_offer() returns early
 * when nobody is tracking the family, and offers are made from the WAITLIST
 * screen, which has no "start working this" button at all - so an offer with no
 * work item behind it is the NORMAL case today, not an edge one. Listing only
 * work items would silently drop live offers off the one screen that chases
 * them, which for a 14-day deadline is the worst bug this module could have.
 *
 * @param array $opts 'include_closed', 'stage', 'overdue', 'owner_email',
 *                    'offers_only'
 */
function work_progress_list(?int $centreId, ?int $year, array $opts = []): array {
    $rows = work_list($centreId, $year, $opts);

    // Offers nobody is tracking. Excluded when the caller is filtering by a
    // stage other than 'offered', by owner (an orphan has none) or by overdue
    // (an orphan has no next action) - in each case they are not what was asked
    // for, and padding a filtered list with them would misreport the filter.
    // ...and by tour, which an orphan offer cannot have: nothing is tracking it,
    // so there is no item to have booked one on.
    $wantOrphans = empty($opts['owner_email']) && empty($opts['overdue'])
        && empty($opts['due']) && empty($opts['mine'])
        && empty($opts['tours'])
        && (empty($opts['stage']) || $opts['stage'] === 'offered');

    if ($wantOrphans) {
        $where = ['o.status = \'pending\'', 'w.id IS NULL'];
        $args  = [];
        if ($centreId !== null) { $where[] = 'o.centre_id = ?'; $args[] = $centreId; }
        if ($year !== null)     { $where[] = 'o.year = ?';      $args[] = $year; }

        foreach (dbx()->query(
            'SELECT o.id AS offer_id, o.centre_id, o.room_id, o.year, o.waitlist_id,
                    o.expires_at AS offer_expires_at, o.status AS offer_status, o.created_at,
                    TIMESTAMPDIFF(HOUR, NOW(), o.expires_at) AS offer_hours_left,
                    c.name AS centre_name, r.name AS room_name,
                    TRIM(CONCAT(a.child_first_name, \' \', a.child_last_name)) AS child_name,
                    GROUP_CONCAT(od.weekday ORDER BY od.weekday) AS days
               FROM offers o
               JOIN centres c ON c.id = o.centre_id
               JOIN rooms r ON r.id = o.room_id
               JOIN waitlist_applications a ON a.id = o.waitlist_id
               LEFT JOIN offer_days od ON od.offer_id = o.id
                     AND (od.accepted IS NULL OR od.accepted = 1)
               LEFT JOIN work_items w ON w.offer_id = o.id
              WHERE ' . implode(' AND ', $where) . '
              GROUP BY o.id',
            $args
        )->fetchAll() as $o) {
            $rows[] = [
                // NOT a work item, and the row says so rather than pretending.
                // There is no history to open and no stage to advance: the answer
                // is recorded on the Waitlist, where the offer was made.
                'kind'             => 'offer',
                'id'               => null,
                'offer_id'         => (int)$o['offer_id'],
                'centre_id'        => (int)$o['centre_id'],
                'centre_name'      => $o['centre_name'],
                'room_id'          => (int)$o['room_id'],
                'room_name'        => $o['room_name'],
                'year'             => (int)$o['year'],
                'waitlist_id'      => (int)$o['waitlist_id'],
                'kidsoft_child_id' => null,
                'child_name'       => $o['child_name'],
                'stage'            => 'offered',
                'stage_label'      => WORK_STAGES['offered']['label'],
                'rank'             => work_stage_rank('offered'),
                'open'             => true,
                'days'             => $o['days'] === null ? [] : array_map('intval', explode(',', $o['days'])),
                'offer_expires_at' => $o['offer_expires_at'],
                'offer_status'     => $o['offer_status'],
                'offer_hours_left' => (int)$o['offer_hours_left'],
                'next_action'      => null,
                'next_action_at'   => null,
                'next_action_for'  => null,
                'overdue'          => false,
                'due'              => false,
                'owner_email'      => null,
                'created_at'       => $o['created_at'],
                'idle_days'        => null,
                'untracked'        => true,
                // An offer with no item behind it has no tour and no contact
                // details. Present and null rather than absent: work_urgency_sort()
                // and the browser both read these on every row, and a missing key
                // became `!undefined` once already and swallowed every click on
                // the placement grid.
                'tour_at'          => null,
                'tour_outcome'     => null,
                'tour_unresolved'  => false,
                'tour_upcoming'    => false,
                'contact_name'     => null,
                'contact_phone'    => null,
                'contact_email'    => null,
            ];
        }
    }

    // AN OFFER TO ONE SIBLING AND NOT THE OTHER, for the same start - see
    // work_offer_gap(). One batch for the whole list. PRESENT AND EMPTY on every
    // row, never absent: a missing key became `!undefined` once already and
    // swallowed every click on the placement grid.
    $wl = array_values(array_filter(array_map(static fn($r) => (int)($r['waitlist_id'] ?? 0), $rows)));
    $fams = $wl ? work_siblings_here($wl) : [];
    foreach ($rows as &$r) {
        $f = $fams[(int)($r['waitlist_id'] ?? 0)] ?? null;
        $r['offer_gap'] = $f && ($r['open'] ?? true) ? work_offer_gap($f) : [];
    }
    unset($r);

    // THE WAITLIST'S DUPLICATE FLAG, on the item worked against that application
    // (6 Nov 2026) - Lennox Dransfield's item sat on a leftover application at
    // Wamberal while another one had already enrolled. Null, never absent.
    $dups = waitlist_duplicates(array_column($rows, 'centre_id'));
    foreach ($rows as &$r) {
        $r['duplicate'] = ($r['open'] ?? true) && !empty($r['waitlist_id'])
            ? ($dups[(int)$r['waitlist_id']] ?? null) : null;
    }
    unset($r);

    // A finished family has no urgency, so the Completed list reads newest first:
    // "who has enrolled lately" is the only question it answers.
    if (($opts['stage'] ?? null) === 'enrolled') {
        usort($rows, static fn($a, $b) => (string)$b['closed_at'] <=> (string)$a['closed_at']);
    } else {
        usort($rows, 'work_urgency_sort');
    }
    return $rows;
}

/**
 * The one ordering for the In progress list: most urgent first.
 *
 * A HARD DEADLINE ALWAYS OUTRANKS A SOFT ONE, and that is deliberate. The module
 * shows offers in the same list as everything else rather than in their own
 * block, so nothing may push a lapsing offer down the page: an offer expiring is
 * a place lost and a family let down. A follow-up somebody set for themselves is
 * a softer thing, however overdue.
 *
 * A TOUR IS THE SECOND EXTERNALLY-IMPOSED CLOCK (14 Aug 2026). This comment used
 * to say an offer was the only one, and that was true until tours moved in here.
 * A tour is an appointment with a parent who is going to arrive whether or not
 * anybody looked at this screen, so it outranks a follow-up somebody set for
 * themselves - the same test, applied to a new fact.
 *
 *   1. a live offer, soonest to lapse (already lapsed sorts first)
 *   2. a tour that has HAPPENED and nobody has said what came of it, oldest first
 *   3. a tour coming up, soonest first
 *   4. a next action whose date has come (today included), longest overdue
 *   5. everything else, longest idle - the neglect ordering
 *
 * Band 2 sits above band 3 because an unanswered tour is the way a tour vanishes:
 * the appointment is gone, and if nobody records the outcome nothing will ever
 * ask again. An upcoming one still has the diary working for it.
 */
function work_urgency_sort(array $a, array $b): int {
    $band = static function (array $w): int {
        if ($w['offer_hours_left'] !== null) return 0;
        if (!empty($w['tour_unresolved']))   return 1;
        if (!empty($w['tour_upcoming']))     return 2;
        // DUE, not overdue (22 Oct 2026): a next step dated today is the
        // reminder going off, and it belongs above the idle rows on its day.
        if (!empty($w['due']))               return 3;
        return 4;
    };
    $ba = $band($a); $bb = $band($b);
    if ($ba !== $bb) return $ba <=> $bb;

    if ($ba === 0) return $a['offer_hours_left'] <=> $b['offer_hours_left'];
    if ($ba === 1 || $ba === 2) return (string)$a['tour_at'] <=> (string)$b['tour_at'];
    if ($ba === 3) return (string)$a['next_action_at'] <=> (string)$b['next_action_at'];
    return ($b['idle_days'] ?? 0) <=> ($a['idle_days'] ?? 0);
}

/**
 * Headline counts for the module, over the SAME rows it is about to list.
 *
 * Derived from the list rather than from their own queries, so a figure at the
 * top of the page can never disagree with the table under it.
 */
function work_progress_summary(array $rows): array {
    $s = ['total' => count($rows), 'lapsing' => 0, 'overdue' => 0, 'idle' => 0, 'offers' => 0,
          'tours_upcoming' => 0, 'tours_unresolved' => 0];
    foreach ($rows as $w) {
        if ($w['offer_hours_left'] !== null) {
            $s['offers']++;
            // Within THREE days is the app's red band for an offer - see the
            // expiry grading rule in CLAUDE.md. Already lapsed counts too.
            if ($w['offer_hours_left'] <= OFFER_URGENT_HOURS) $s['lapsing']++;
        }
        if (!empty($w['overdue']))                     $s['overdue']++;
        if (!empty($w['tour_upcoming']))                $s['tours_upcoming']++;
        if (!empty($w['tour_unresolved']))              $s['tours_unresolved']++;
        if (($w['idle_days'] ?? 0) >= 14 && $w['open']) $s['idle']++;
    }
    return $s;
}

/**
 * The last time a PERSON did anything to each item, and who (6 Nov 2026).
 *
 * Broc: a "last interacted with" column on In progress, "so we can see when
 * someone last modified the entry". Two sources, the latest wins:
 *
 *   - audit_log rows on the item by a person - every stage move, next-step
 *     edit, tour, invite, archive. A person is an email: Kidsoft's automatic
 *     moves and the nightly jobs write 'kidsoft' / 'system' and are left out,
 *     because the question is whether anybody has looked at the family.
 *   - a contact note on the item or on the family's application / child, the
 *     same scope as the modal's timeline (work_contact_history()) without the
 *     notes a brother or sister's call was also shown on. created_at, not
 *     occurred_at: when somebody did it HERE, which is what was asked.
 *
 * NOT work_items.updated_at: ON UPDATE CURRENT_TIMESTAMP moves on every calendar
 * sync and every Kidsoft-driven stage change, so it reads "touched today" on
 * items nobody has opened. Two batched queries for the whole list.
 *
 * @return array<int,array{at:string,by:string}> item id => latest person touch
 */
function work_last_touched(array $rows): array {
    $ids = $byWl = $byChild = [];
    foreach ($rows as $r) {
        $id = (int)$r['id'];
        $ids[] = $id;
        if (!empty($r['waitlist_id']))      $byWl[(int)$r['waitlist_id']][] = $id;
        if (!empty($r['kidsoft_child_id'])) $byChild[(string)$r['kidsoft_child_id']][] = $id;
    }
    if (!$ids) return [];
    $out = [];
    $take = static function (int $id, string $at, string $by) use (&$out): void {
        if (!isset($out[$id]) || $at > $out[$id]['at']) $out[$id] = ['at' => $at, 'by' => $by];
    };

    // entity_id is a VARCHAR, so the ids go in as strings - an integer compare
    // there converts every row and cannot use ix_audit_entity.
    $sids = array_map('strval', $ids);
    foreach (dbx()->query(
        "SELECT entity_id, MAX(created_at) AS at,
                SUBSTRING_INDEX(GROUP_CONCAT(staff_email ORDER BY created_at DESC, id DESC), ',', 1) AS who
           FROM audit_log
          WHERE entity_type = 'work_item' AND entity_id IN(" . dbx()->whereIn($sids) . ")
            AND staff_email LIKE '%@%'
          GROUP BY entity_id", $sids)->fetchAll() as $a) {
        $take((int)$a['entity_id'], (string)$a['at'], (string)$a['who']);
    }

    $where = ['work_item_id IN(' . dbx()->whereIn($ids) . ')'];
    $args  = $ids;
    if ($byWl)    { $where[] = 'waitlist_id IN(' . dbx()->whereIn(array_keys($byWl)) . ')';
                    $args = array_merge($args, array_keys($byWl)); }
    if ($byChild) { $where[] = 'kidsoft_child_id IN(' . dbx()->whereIn(array_keys($byChild)) . ')';
                    $args = array_merge($args, array_map('strval', array_keys($byChild))); }
    foreach (dbx()->query(
        'SELECT work_item_id, waitlist_id, kidsoft_child_id, created_at, staff_email
           FROM communications WHERE ' . implode(' OR ', $where), $args)->fetchAll() as $c) {
        if (strpos((string)$c['staff_email'], '@') === false) continue;
        $hit = [];
        if ($c['work_item_id'] !== null && in_array((int)$c['work_item_id'], $ids, true)) $hit[] = (int)$c['work_item_id'];
        foreach ($byWl[(int)$c['waitlist_id']] ?? [] as $id) $hit[] = $id;
        foreach ($byChild[(string)$c['kidsoft_child_id']] ?? [] as $id) $hit[] = $id;
        foreach (array_unique($hit) as $id) $take($id, (string)$c['created_at'], (string)$c['staff_email']);
    }
    return $out;
}

/**
 * Archive an item: close it WITHOUT an outcome, keeping its history (6 Nov 2026).
 *
 * The third way out, between the two that already existed. work_cancel() deletes
 * a mis-click and is refused once anything has happened; Lost records that we
 * chased a family and did not get them. Neither fits the case it was built for:
 * Lennox Dransfield's item, at Toured, on a leftover application Wamberal had
 * archived in Kidsoft after another of his applications enrolled. Marking that
 * Lost would put a family we DID get into the record as one we did not.
 *
 * Reopened the way Lost is: pick an earlier stage in the modal and save -
 * work_advance() clears closed_at on any move to an open stage.
 */
function work_archive(int $id, ?string $note, string $actor = 'staff'): bool {
    $w = dbx()->query('SELECT stage FROM work_items WHERE id = ?', [$id], 'i')->fetch();
    if (!$w) throw new InvalidArgumentException('That item no longer exists.');
    if (!work_is_open($w['stage'])) {
        throw new InvalidArgumentException('That item is already closed (' .
            (WORK_STAGES[$w['stage']]['label'] ?? $w['stage']) . ').');
    }
    return work_advance($id, 'archived', $note, $actor);
}

/**
 * Delete an item created by mistake.
 *
 * NOT the same as 'lost', and the difference matters enough to be its own verb.
 * 'lost' is a real outcome - we chased this family and did not get them - and
 * it belongs in the history. A mis-click is not an outcome, and recording one as
 * a lost family would put a fiction into the only record of what the team
 * actually did. (A UAT tester clicked "Start working this" by accident on
 * 10 Aug 2026 and the only exit available was to mark the family lost.)
 *
 * REFUSED ONCE THE ITEM HAS A REAL HISTORY. If somebody has advanced it, logged
 * a call or attached an offer, it is no longer a mis-click and deleting it would
 * destroy a record of work that happened. Those close as 'lost' like anything
 * else. The test is deliberately generous about WHEN - an item found a week
 * later is still a mis-click if nothing was ever done to it.
 */
function work_cancel(int $id): array {
    $w = work_get($id);
    if (!$w) return ['ok' => false, 'error' => 'That item no longer exists.'];

    if ($w['offer_id']) {
        return ['ok' => false, 'error' =>
            'There is an offer attached to this family, so it is not a mis-click. '
          . 'Withdraw the offer on the Waitlist first.'];
    }
    $events = count($w['events'] ?? []);
    if ($w['stage'] !== 'identified' || $events > 1) {
        return ['ok' => false, 'error' =>
            'This item has a history; somebody has worked it. Move it to Lost instead, '
          . 'so the record of what happened survives.'];
    }
    if (!empty($w['contacts'])) {
        return ['ok' => false, 'error' =>
            'Contact with this family has been logged against this item. Move it to Lost instead.'];
    }

    // The days and the history rows cascade; the contact log does not belong to
    // the item (it is the FAMILY's) and there is none by the test above.
    dbx()->query('DELETE FROM work_items WHERE id = ?', [$id], 'i');
    audit('work.cancel', 'work_item', $id, [
        'child' => $w['child_name'], 'room_id' => $w['room_id'], 'year' => $w['year'],
        'reason' => 'created in error',
    ]);
    return ['ok' => true];
}

/**
 * The dates behind the "by ..." buttons on a next step, plus today.
 *
 * COMPUTED HERE AND PUBLISHED, never worked out in the browser. "Tomorrow" is a
 * date, and every route to one in JavaScript goes through `new Date()`, which
 * reads the viewer's timezone rather than Sydney - the rule the whole Dates
 * block in app.js exists to hold, and the reason `tour_past` and the offer
 * countdowns are already server-side. A staff member in Perth setting a chase
 * for "tomorrow" must get Sydney's tomorrow, because that is the clock
 * `work_list()` compares it against.
 *
 * Adding days to a 'Y-m-d' string in JS without a Date is possible and would be
 * a month-boundary bug waiting to happen. strtotime does it correctly and the
 * timezone is already pinned by dates.php.
 *
 * ONE / THREE / SEVEN because that is the cadence the team actually works to:
 * chase, chase again, then weekly (see the re-enrolment rules in
 * dashboard.md). They are a shortcut, not a policy - the date box beside them
 * still takes anything.
 */
function work_due_presets(): array {
    $out = ['today' => date('Y-m-d'), 'options' => []];
    foreach ([1 => 'Tomorrow', 3 => 'In 3 days', 7 => 'Next week'] as $days => $label) {
        $out['options'][] = ['label' => $label, 'date' => date('Y-m-d', strtotime("+{$days} days"))];
    }
    return $out;
}

/**
 * One item with its full history, for the detail panel.
 *
 * THE waitlist_applications JOIN ALIASES ITS TWO KIDSOFT IDS, and it must.
 * `w.*` already selects `work_items.kidsoft_child_id`, and a second column of
 * that name would overwrite it in the fetched row - which is the value
 * `$w['is_enquiry']` is decided on. One name, two tables, opposite meanings: on
 * the ITEM it says "this candidate is a child already in care", on the
 * APPLICATION it says "this application has converted to an enrolment".
 */
function work_get(int $id): ?array {
    $w = dbx()->query(
        'SELECT w.*, r.name AS room_name, c.name AS centre_name,
                a.guardian_name, a.guardian_phone, a.guardian_email,
                a.kidsoft_child_id    AS app_child_id,
                a.kidsoft_guardian_id AS app_guardian_id,
                GROUP_CONCAT(d.weekday ORDER BY d.weekday) AS days
           FROM work_items w
           LEFT JOIN rooms r ON r.id = w.room_id
           JOIN centres c ON c.id = w.centre_id
           LEFT JOIN waitlist_applications a ON a.id = w.waitlist_id
           LEFT JOIN work_item_days d ON d.work_item_id = w.id
          WHERE w.id = ? GROUP BY w.id', [$id], 'i')->fetch();
    if (!$w) return null;
    $w['id']   = (int)$w['id'];
    $w['days'] = $w['days'] === null ? [] : array_map('intval', explode(',', $w['days']));
    $w['year'] = $w['year'] === null ? null : (int)$w['year'];
    // "Has the tour happened" is decided HERE, on the server's Sydney clock, and
    // published as a flag. The browser must not work it out from the string: any
    // route to that answer in JS goes through new Date(), which reinterprets a
    // Sydney wall-clock time in the viewer's timezone. Same rule as the expiry
    // countdowns - see _incl/dates.php.
    $w['tour_past'] = $w['tour_at'] !== null && $w['tour_at'] < date('Y-m-d H:i:s');
    // WHETHER THE TOUR IS IN THE CENTRE'S OUTLOOK CALENDAR, said beside the tour
    // box. Decided here; the modal renders it. See tour-calendar.php.
    $w['calendar'] = tour_calendar_status($w);
    $w['tour_invite'] = (bool)$w['tour_invite'];
    // An enquiry is the row nothing else identifies: no queue application, no
    // Kidsoft child. It is the only kind whose contact details live on the item.
    $w['is_enquiry'] = !$w['waitlist_id'] && !$w['kidsoft_child_id'];
    // WHO TO RING, which this screen tells you to do and then did not say.
    //
    // 851 of 855 applications carry a guardian phone and 850 an email, all of it
    // already in our own tables, and none of it reached the modal - so the one
    // action every stage of this pipeline comes down to sent the user back to the
    // Waitlist to look up a number. Published as ONE shape whichever kind of
    // candidate this is, so the browser renders it rather than deciding it.
    //
    // READ-ONLY, and that distinction is the whole reason it can be here at all.
    // The editable boxes below it are still shown for an enquiry ONLY (see
    // $w['is_enquiry'] and the block in work-modal.php): a queue family's number
    // belongs to Kidsoft, and an input holding it invites somebody to key it
    // again, which is the work this tool exists to remove. Showing it is not
    // that; asking for it again is.
    //
    // A CHILD ALREADY IN CARE GETS NOTHING HERE, deliberately. Their guardians
    // are reachable, but only through guardians_by_id() - a live Kidsoft call
    // per open - and the family modal already refuses to put a Kidsoft round
    // trip behind every click on a name. There are 0 such items in live data;
    // when there is one, the honest fix is the deferred read that modal uses,
    // not a blocking call here.
    $reach = $w['is_enquiry']
        ? ['name' => $w['contact_name'], 'phone' => $w['contact_phone'],
           'email' => $w['contact_email'], 'source' => 'enquiry']
        : ['name' => $w['guardian_name'], 'phone' => $w['guardian_phone'],
           'email' => $w['guardian_email'], 'source' => 'guardian'];
    // Nothing to ring on is null, not an empty row - the block is hidden rather
    // than rendering a heading over three blanks.
    $w['reach'] = ($reach['phone'] || $reach['email'] || $reach['name']) ? $reach : null;

    // WHETHER A NOTE TYPED HERE WILL ALSO REACH KIDSOFT, answered before it is
    // typed rather than after it is saved.
    //
    // WITHHOLDING THIS IS THE BUG, not showing it: Kidsoft only creates a child
    // record when an application converts to an enrolment, so for 87% of the
    // queue there is nowhere to copy a note to - and a staff member who assumes
    // it travelled stops keeping the Kidsoft note they still need. The family
    // modal has said this since 14 Aug; this modal wrote to the same table and
    // said nothing.
    //
    // THE RECORD TESTED IS THE APPLICATION'S, not the item's, wherever there is
    // one - so the same family gets the same answer whichever modal is open.
    // An item for a child already in care falls back to its own id; an enquiry
    // has neither and correctly comes back "nowhere to copy to", which is not
    // an error.
    $target = comment_kidsoft_target(
        $w['waitlist_id']
            ? ['kidsoft_child_id'    => $w['app_child_id'],
               'kidsoft_guardian_id' => $w['app_guardian_id']]
            : ['kidsoft_child_id' => $w['kidsoft_child_id']]
    );
    $w['kidsoft_push'] = ['pushable' => $target !== null, 'target' => $target['target'] ?? null];
    $w['events'] = dbx()->query(
        'SELECT from_stage, to_stage, note, actor, occurred_at
           FROM work_item_events WHERE work_item_id = ? ORDER BY occurred_at, id', [$id], 'i')->fetchAll();
    // Kept separate from the stage history on purpose: a stage change is what the
    // ITEM did, a contact is what happened with the FAMILY, and the family's
    // history outlives any one item.
    $w['contacts'] = work_contact_history($w);
    $w['offer']    = work_offer_detail($w['offer_id'] === null ? null : (int)$w['offer_id']);
    // BROTHERS AND SISTERS WAITING HERE, for the modal's "also on this tour",
    // "also lost" and "also on their history" ticks, and the offer one of them
    // has and the other does not. Empty for an enquiry or a child already in
    // care: neither has an application to find a family through.
    $fam = $w['waitlist_id'] ? (work_siblings_here([(int)$w['waitlist_id']])[(int)$w['waitlist_id']] ?? null) : null;
    $w['family']    = $fam ? $fam['siblings'] : [];
    $w['offer_gap'] = $fam ? work_offer_gap($fam) : [];
    // The Waitlist's duplicate flag on this item's application - see waitlist_duplicates().
    $w['duplicate'] = $w['waitlist_id']
        ? (waitlist_duplicates([(int)$w['centre_id']])[(int)$w['waitlist_id']] ?? null) : null;
    // FOR THE "ROOM AND DAYS" ROW AND THE NEXT STEP'S "FOR" (22 Oct 2026).
    // Published with the item rather than fetched by the modal, because both
    // lists depend on the item's centre and the modal opens on three screens.
    $w['next_action_for'] = $w['next_action_for'] ?: null;
    $w['rooms']  = array_map(static fn($r) => ['id' => (int)$r['id'], 'name' => (string)$r['name']],
                             rooms_for_centre((int)$w['centre_id'], (int)($w['year'] ?? date('Y'))));
    $w['people'] = work_people_for_centre((int)$w['centre_id']);
    // Kidsoft's offer decides the room and the days from Offered on, so the
    // modal shows them and does not offer to change them. work_room_days_locked().
    $w['room_days_locked'] = work_room_days_locked($w['stage']);
    return $w;
}

/**
 * Who a next step can be for: everybody who can open this centre's families.
 *
 * THE REMINDER IS IN THE APP AND NOWHERE ELSE (Broc, 22 Oct 2026) - it is that
 * person's Mine view showing the item once the date comes round. So a name is
 * only worth offering if that person can sign in AND see this centre: a
 * supervisor of another centre would never be shown it, and the step would sit
 * there reminding nobody. Administrators and managers see every centre; a
 * supervisor only the ones assigned to them.
 *
 * @return array<int,array{email:string,name:string}>
 */
function work_people_for_centre(int $centreId): array {
    $rows = dbx()->query(
        "SELECT u.email, u.name FROM app_users u
          WHERE u.active = 1
            AND (u.role IN ('administrator','manager')
                 OR EXISTS (SELECT 1 FROM app_user_centres uc
                             WHERE uc.user_id = u.id AND uc.centre_id = ?))
          ORDER BY COALESCE(u.name, u.email)", [$centreId], 'i')->fetchAll();
    return array_map(static fn($r) => ['email' => (string)$r['email'],
                                       'name'  => (string)($r['name'] ?: $r['email'])], $rows);
}

/**
 * From Offered on, the room and the days are KIDSOFT'S. Offers are sent in
 * Kidsoft and read back (15 Oct 2026), and work_follow_kidsoft_offers() sets the
 * item's room and days from the offer - so a change typed here would be a
 * second answer that the next import overwrites without telling anybody.
 */
function work_room_days_locked(string $stage): bool {
    return work_stage_rank($stage) >= work_stage_rank('offered');
}

/** A room id posted for an item, checked to belong to the item's centre. */
function work_clean_room_id($roomId, int $centreId): ?int {
    $roomId = (int)($roomId ?? 0);
    if (!$roomId) return null;
    $r = room_get($roomId);
    if (!$r || (int)$r['centre_id'] !== $centreId) {
        throw new InvalidArgumentException('That room is not at this centre.');
    }
    return $roomId;
}

/**
 * The offer behind an item, for the modal to show.
 *
 * WHY IT IS NOT THE ITEM'S OWN DAYS. They are seeded from the offer when
 * work_link_offer() raises the item, so on a fresh one they agree - but they
 * are two different facts and they come apart in the two cases that matter
 * most. An item raised from a CELL carries the days that cell was working
 * toward, and a partial offer against it is deliberately narrower. And once the
 * family answers, `offer_days.accepted` is per DAY: taking the Monday and
 * turning down the Wednesday is normal, and the item's days do not record it.
 * So the modal must show what was OFFERED, not what was aimed at.
 *
 * ANY STATUS, not just pending - unlike work_list(), which joins pending only
 * because it is ordering by a live deadline. A declined or expired offer sends
 * the item back to `contacted` rather than closing it (see work_offer_answered),
 * precisely so the history of what this family already refused survives. Hiding
 * it here would throw away the reason they are back in the list.
 *
 * @return array|null null when the item has no offer, which is every item before
 *                    the offer stage and every item for a child already in care.
 */
function work_offer_detail(?int $offerId): ?array {
    if (!$offerId) return null;
    $o = dbx()->query(
        'SELECT id, status, start_date, expires_at, responded_at,
                TIMESTAMPDIFF(HOUR, NOW(), expires_at) AS hours_left
           FROM offers WHERE id = ?', [$offerId], 'i')->fetch();
    if (!$o) return null;

    return [
        'id'           => (int)$o['id'],
        'status'       => $o['status'],
        'start_date'   => $o['start_date'],
        'expires_at'   => $o['expires_at'],
        'responded_at' => $o['responded_at'],
        // Only meaningful while the offer is live. A lapsed offer's "hours left"
        // is a large negative number, and the modal should say "expired", not
        // count backwards from it.
        'hours_left'   => $o['status'] === 'pending' ? (int)$o['hours_left'] : null,
        // accepted is NULL until the family answers, then 1/0 PER DAY.
        'days'         => array_map(
            static fn($d) => [
                'weekday'  => (int)$d['weekday'],
                'accepted' => $d['accepted'] === null ? null : (bool)$d['accepted'],
            ],
            dbx()->query(
                'SELECT weekday, accepted FROM offer_days WHERE offer_id = ? ORDER BY weekday',
                [$offerId], 'i')->fetchAll()
        ),
    ];
}

/**
 * Pipeline counts for a centre-year (or the whole group when $centreId is null).
 *
 * Reported as a SNAPSHOT of where families stand right now, never as
 * stage-to-stage conversion rates. There is no trustworthy historical rate to
 * compare against: measured 6 Aug 2026 over all 1,693 Kidsoft waitlist rows,
 * the outcome statuses (Enrolled, Offered, ExpiryReminder*) ALL first appear in
 * 2026Q3, and 89 of the 949 rows filed as "ExpiredWaitlist" had in fact become
 * enrolled children. A conversion percentage over that history would measure
 * Kidsoft admin hygiene, not this organisation. Rates become real from the point
 * this table starts being used - so the clock starts here rather than being
 * back-filled with a fiction.
 */
function work_pipeline(?int $centreId, ?int $year): array {
    // $year null = every year, which is what the Dashboard asks for: the pipeline
    // is "where does every family stand", and a family's target year is a detail
    // of their item, not a filter on the question.
    $where = ['1=1']; $args = [];
    if ($year !== null)     { $where[] = 'year = ?';      $args[] = $year; }
    if ($centreId !== null) { $where[] = 'centre_id = ?'; $args[] = $centreId; }
    $counts = dbx()->query(
        'SELECT stage, COUNT(*) n FROM work_items WHERE ' . implode(' AND ', $where) . ' GROUP BY stage',
        $args
    )->fetchAll('keyPair');

    $out = [];
    foreach (WORK_STAGES as $k => $meta) {
        $out[$k] = ['stage' => $k, 'label' => $meta['label'], 'help' => $meta['help'],
                    'n' => (int)($counts[$k] ?? 0), 'open' => work_is_open($k)];
    }
    return $out;
}

/**
 * Attach a real offer to the work item for this family, and move it to `offered`.
 *
 * THE OFFER IS MADE BY /api/waitlist, NEVER HERE, and that is the point. That
 * path takes a lock on the room's capacity row and re-checks vacancies inside the
 * transaction, because two staff offering the last Tuesday place at the same
 * moment would otherwise both succeed and one seat would be promised twice. A
 * second offer-making implementation behind the work modal would be a second
 * chance to get that wrong. So the pipeline REFLECTS the offer rather than
 * creating it: `offers` stays the single authority on what is promised, and
 * `work_items.offer_id` is a pointer to it.
 *
 * Called after a successful make_offer.
 *
 * IT CREATES THE ITEM WHEN THERE IS NONE (11 Aug 2026), and that is a change of
 * policy this comment used to state the other way round ("silent no-op when
 * nobody is tracking the family"). AN OFFER IS A STAGE OF A WORK ITEM --
 * WORK_STAGES contains `offered` - so an offer with no item behind it was a
 * stage existing without the thing it is a stage of. It was not an edge case
 * either: offers are made from the Waitlist, which had no way to start an item
 * until 10 Aug 2026, so untracked was the NORMAL path, and work_progress_list()
 * unions raw offers in to cover for it.
 *
 * Nothing on screen appears that did not appear before - those offers were
 * already listed on In progress - but the row stops being a faded `untracked`
 * dead end and becomes openable, ownable and loggable, during the 14-day window
 * that is the most time-critical stretch in the whole pipeline. It also gets the
 * family an auto-close: work_autoclose() only ever looks at items, so an
 * accepted offer with none behind it never closed itself.
 *
 * NOTHING HERE IS GUESSED. Every field is decided by the offer that was just
 * made under the lock - centre, year, days, and the ROOM, which is the one
 * thing the Waitlist's own "Add to In progress" button deliberately leaves
 * unset because a queue family has not been matched to a spot yet. Making the
 * offer is what decides it. No double-count on the cell: work_by_cell()
 * excludes `offered` and past, since those days are already held as `promised`.
 *
 * The item is created at `identified` and then advanced, rather than inserted at
 * `offered` directly, so the two paths share one line of advancement and the
 * event log tells the truth: we had not recorded this family until the offer.
 */
function work_link_offer(int $waitlistId, int $offerId): void {
    $w = dbx()->query(
        'SELECT id, stage FROM work_items
          WHERE waitlist_id = ? AND stage NOT IN ("enrolled","lost","archived")
          ORDER BY id DESC LIMIT 1', [$waitlistId], 'i')->fetch();

    $id = $w ? (int)$w['id'] : work_create_for_offer($waitlistId, $offerId);
    if (!$id) return;

    dbx()->query('UPDATE work_items SET offer_id = ? WHERE id = ?', [$offerId, $id], 'ii');
    work_advance($id, 'offered', 'A place was formally offered.', 'staff');
}

/**
 * Raise a work item for a family who has just been offered a place and was not
 * being tracked. See work_link_offer(), which is the only caller.
 *
 * Queried directly rather than through offer_get()/waitlist_get(), to keep this
 * file's dependencies where they are: work.php is required BY the waitlist code,
 * not the other way round.
 *
 * @return int the new item's id, or 0 if the offer or application has vanished
 *             underneath us - an offer that cannot be described is not worth
 *             failing the request that made it.
 */
function work_create_for_offer(int $waitlistId, int $offerId): int {
    $o = dbx()->query(
        'SELECT centre_id, room_id, year, offered_by FROM offers WHERE id = ?', [$offerId], 'i')->fetch();
    if (!$o) return 0;

    $a = dbx()->query(
        'SELECT child_first_name, child_last_name FROM waitlist_applications WHERE id = ?',
        [$waitlistId], 'i')->fetch();
    if (!$a) return 0;

    $name = trim(($a['child_first_name'] ?? '') . ' ' . ($a['child_last_name'] ?? ''));
    if ($name === '') return 0;   // work_create() would throw, and an offer is not the place to find out.

    // THE OFFER'S DAYS, NOT THE APPLICATION'S. A partial offer is normal, and
    // work_autoclose() closes on every day the item is working toward - so an
    // item carrying days that were never offered would never close itself.
    $days = array_map('intval', dbx()->query(
        'SELECT weekday FROM offer_days WHERE offer_id = ? ORDER BY weekday', [$offerId], 'i'
    )->fetchAll('col'));

    return work_create([
        'centre_id'   => (int)$o['centre_id'],
        'room_id'     => (int)$o['room_id'],
        'year'        => (int)$o['year'],
        'waitlist_id' => $waitlistId,
        'child_name'  => $name,
        'days'        => $days,
        'owner_email' => $o['offered_by'] ?: null,
        'created_by'  => $o['offered_by'] ?: null,
        'note'        => 'Raised automatically when a place was offered.',
    ]);
}

/**
 * Reflect a family's answer to an offer back onto their work item.
 *
 * Accepted -> `accepted`: they said yes, and the item then waits for Kidsoft to
 * show the booking, at which point work_autoclose() finishes it. Declined or
 * expired -> back to `contacted`, NOT to `lost`: a family who turned down one
 * room on one day is very often still worth ringing about another, and closing
 * the item would lose the history that says what they already said no to. Losing
 * a family is a decision somebody makes, not something an expiry does.
 */
function work_offer_answered(int $waitlistId, string $offerStatus): void {
    $w = dbx()->query(
        'SELECT id, stage FROM work_items
          WHERE waitlist_id = ? AND stage NOT IN ("enrolled","lost","archived")
          ORDER BY id DESC LIMIT 1', [$waitlistId], 'i')->fetch();
    if (!$w) return;

    if (in_array($offerStatus, ['accepted', 'partially_accepted'], true)) {
        work_advance((int)$w['id'], 'accepted',
            $offerStatus === 'partially_accepted'
                ? 'Accepted some of the days offered.'
                : 'Accepted the offer.', 'staff');
        return;
    }
    if (in_array($offerStatus, ['declined', 'expired', 'withdrawn'], true)) {
        work_advance((int)$w['id'], 'contacted',
            'Offer ' . $offerStatus . '. Still on the list; decide whether to keep working this.',
            'staff');
    }
}

/**
 * Move this centre's open work items to match the offers KIDSOFT holds.
 *
 * THE OFFER STAGE REFLECTS AN OFFER, and offers are sent in Kidsoft now (15 Oct
 * 2026). work_link_offer() and work_offer_answered() did this for offers made
 * with the retired button; this is the same two moves for the ones Kidsoft
 * reports, run by the waitlist import after it has stored them.
 *
 * Measured the morning it was written, eight items were behind Kidsoft: four
 * Wamberal families at `contacted` whose enrolment forms were already submitted
 * (Rosa Daris, Brodie Woods, Eloise Bowman, Iloura Tweedie - moved back on
 * 14 Oct because THIS app's copy of their offer had lapsed), Sersha Donnelly at
 * `tour_done` with a submitted form, Baby Collings at `contacted` and Stella
 * Knox at `identified` with offers out, and Heidi Gow at `identified` at
 * Re-submit Required.
 *
 *   FORWARD, to `offered` while the family has still to answer and to
 *   `accepted` once Kidsoft has their form. Never backward on this path, and
 *   the offer decides the room and the days, as work_create_for_offer() does:
 *   a partial offer is normal, and work_autoclose() closes on the item's days.
 *
 *   BACK TO `contacted` when the offer ends without an enrolment, for the reason
 *   work_offer_answered() gives - losing a family is a decision somebody makes,
 *   not something an expiry does. `offered` goes back as soon as Kidsoft has no
 *   offer out (an item there with nothing holding the place is the double-book
 *   work_advance() refuses); `accepted` only when Kidsoft says the offer expired
 *   or was turned down, because staff set `accepted` by hand when a family says
 *   yes on the phone, and Kidsoft saying Waitlisted is not that family saying no.
 *
 * Items behind an offer made with the retired button are left to that path.
 * Nothing here creates an item.
 *
 * @return int items moved
 */
function work_follow_kidsoft_offers(int $centreId): int {
    $items = dbx()->query(
        "SELECT w.id, w.stage, w.room_id, w.year,
                a.status AS app_status, a.kidsoft_status, a.kidsoft_offer_room_id,
                a.required_from, rm.name AS offer_room_name,
                (SELECT GROUP_CONCAT(od.weekday ORDER BY od.weekday)
                   FROM waitlist_offered_days od WHERE od.waitlist_id = a.id) AS offered_days,
                o.status AS local_offer_status
           FROM work_items w
           JOIN waitlist_applications a ON a.id = w.waitlist_id
           LEFT JOIN rooms rm ON rm.id = a.kidsoft_offer_room_id
           LEFT JOIN offers o ON o.id = w.offer_id
          WHERE w.centre_id = ? AND w.stage NOT IN ('enrolled','lost','archived')",
        [$centreId], 'i'
    )->fetchAll();

    $moved = 0;
    foreach ($items as $w) {
        if (in_array($w['local_offer_status'], ['pending', 'accepted', 'partially_accepted'], true)) continue;
        $id   = (int)$w['id'];
        $rank = work_stage_rank($w['stage']);
        $ks   = (string)$w['kidsoft_status'];
        $open = $w['app_status'] === 'waitlisted' && isset(KIDSOFT_OPEN_OFFER[$ks]);

        if ($open) {
            $target = KIDSOFT_OPEN_OFFER[$ks]['answered'] ? 'accepted' : 'offered';
            if ($rank >= work_stage_rank($target)) continue;

            $days = $w['offered_days'] === null ? [] : array_map('intval', explode(',', $w['offered_days']));
            $where = '';
            if ($w['kidsoft_offer_room_id'] && $days) {
                $year = $w['required_from']
                    ? max((int)substr($w['required_from'], 0, 4), (int)date('Y')) : $w['year'];
                dbx()->query('UPDATE work_items SET room_id = ?, year = ? WHERE id = ?',
                             [(int)$w['kidsoft_offer_room_id'], $year, $id], 'iii');
                work_set_days($id, $days);
                $where = ': ' . $w['offer_room_name'] . ', '
                       . implode(', ', array_map(static fn($d) => WEEKDAYS[$d] ?? $d, $days));
            }
            work_advance($id, $target, match ($ks) {
                'Form Submitted'     => 'Enrolment form submitted in Kidsoft' . $where . '.',
                'Re-submit Required' => 'Re-submit required in Kidsoft' . $where . '.',
                default              => 'Offered in Kidsoft' . $where . '.',
            }, 'kidsoft');
            $moved++;
            continue;
        }

        $ended = in_array($ks, ['ExpiredOffer', 'RejectedByParent'], true);
        if (($w['stage'] === 'offered' && $w['app_status'] !== 'placed') ||
            ($w['stage'] === 'accepted' && $ended)) {
            work_advance($id, 'contacted', match ($ks) {
                'ExpiredOffer'     => 'The Kidsoft offer expired. Still on the list; decide whether to keep working this.',
                'RejectedByParent' => 'Turned the offer down in Kidsoft. Decide whether to keep working this.',
                default            => 'Kidsoft no longer has an offer out to this family.',
            }, 'kidsoft');
            $moved++;
        }
    }
    return $moved;
}

// work_log_contact() WAS HERE AND IS GONE (18 Sep 2026).
//
// It INSERTed into `communications` directly, which made this file the SECOND
// writer of that table alongside api/comms - and the two did not agree. This one
// had no `body`, silently truncated the summary to 255 with mb_substr(), and
// never called comment_push(), so the same sentence reached Kidsoft when typed
// in the family modal and did not when typed in the work modal, with neither
// screen saying which. Its only caller was /api/work's `advance`.
//
// The work modal now posts the note to /api/comms with a work_item_id, which is
// the third handle that endpoint learned. ONE WRITER, ONE SHAPE, one answer
// about where a note goes. Do not reintroduce a shortcut here: a contact note is
// the most personal thing this app stores and the Kidsoft copy is irreversible.

/**
 * Every recorded contact with this family, newest first.
 *
 * Looked up by WHO, not by work item: a family worked for a Tuesday last term and
 * a Friday this one is one family with one history.
 *
 * EXCEPT FOR A TOUR ENQUIRY, where the item IS the identity (14 Aug 2026). A
 * parent who has only rung to book a tour has no application and no Kidsoft
 * child, so before this every call made to them was unfindable - the log had
 * nothing to key on and returned an empty array. The item id is the last resort,
 * used only when the other two are absent, so nothing about how an established
 * family's history is assembled changes.
 */
function work_contact_history(array $w, int $limit = 20): array {
    $where = []; $args = [];
    if ($w['waitlist_id'])      { $where[] = 'waitlist_id = ?';      $args[] = (int)$w['waitlist_id']; }
    if ($w['kidsoft_child_id']) { $where[] = 'kidsoft_child_id = ?'; $args[] = $w['kidsoft_child_id']; }
    if (!$where && !empty($w['id'])) { $where[] = 'work_item_id = ?'; $args[] = (int)$w['id']; }
    if (!$where) return [];
    // A NOTE A BROTHER OR SISTER'S CALL WAS ALSO SHOWN ON (19 Oct 2026) - see
    // communication_siblings in api/comms.
    if ($w['waitlist_id']) {
        $where[] = 'id IN (SELECT communication_id FROM communication_siblings WHERE waitlist_id = ?)';
        $args[]  = (int)$w['waitlist_id'];
    }
    $args[] = $limit;
    // `body` AND THE PUSH OUTCOME, added 18 Sep 2026 with the timeline. The
    // modal now writes through api/comms, which stores the detail under the
    // summary and records whether a copy reached Kidsoft - and a list that reads
    // neither shows a truncated headline and implies every note travelled.
    $rows = dbx()->query(
        'SELECT id, occurred_at, direction, channel, summary, body, staff_email,
                kidsoft_pushed_at, kidsoft_push_error, waitlist_id
           FROM communications
          WHERE ' . implode(' OR ', $where) . ' ORDER BY occurred_at DESC, id DESC LIMIT ?',
        $args
    )->fetchAll();
    return work_contacts_shared($rows, $w['waitlist_id'] ? (int)$w['waitlist_id'] : null);
}

/**
 * Say, on each note, which other child it belongs to or is also shown on.
 *
 *   shared_from  the child the note was WRITTEN on, when it reached this child
 *                only by being shown on them. Its Kidsoft copy (if any) is on
 *                that child's record, not this one's, so the screens drop the
 *                "copied to Kidsoft" mark for it rather than imply otherwise.
 *   shared_with  the brothers and sisters it is also shown on.
 *
 * Both present on every row (null / []), for the reason work_progress_list()
 * gives. Used by work_contact_history() and api/comms's `list`, so the work
 * modal and the family modal say the same thing about one note.
 */
function work_contacts_shared(array $rows, ?int $waitlistId): array {
    foreach ($rows as &$r) { $r['shared_from'] = null; $r['shared_with'] = []; }
    unset($r);
    if (!$rows) return $rows;
    $ids = array_map(static fn($r) => (int)$r['id'], $rows);
    $links = dbx()->query(
        'SELECT cs.communication_id, cs.waitlist_id,
                TRIM(CONCAT(a.child_first_name, " ", a.child_last_name)) AS name
           FROM communication_siblings cs
           JOIN waitlist_applications a ON a.id = cs.waitlist_id
          WHERE cs.communication_id IN (' . dbx()->whereIn($ids) . ')', $ids)->fetchAll();
    if (!$links) return $rows;
    $on = []; $with = [];
    foreach ($links as $l) {
        if ((int)$l['waitlist_id'] === $waitlistId) $on[(int)$l['communication_id']] = true;
        else $with[(int)$l['communication_id']][] = $l['name'];
    }
    $owners = array_values(array_unique(array_filter(array_map(
        static fn($r) => isset($on[(int)$r['id']]) ? (int)$r['waitlist_id'] : 0, $rows))));
    $names = $owners ? dbx()->query(
        'SELECT id, TRIM(CONCAT(child_first_name, " ", child_last_name)) AS name
           FROM waitlist_applications WHERE id IN (' . dbx()->whereIn($owners) . ')', $owners)->fetchAll('keyPair') : [];
    foreach ($rows as &$r) {
        $cid = (int)$r['id'];
        if (isset($on[$cid]) && (int)$r['waitlist_id'] !== $waitlistId) {
            $r['shared_from'] = $names[(int)$r['waitlist_id']] ?? null;
        }
        $r['shared_with'] = $with[$cid] ?? [];
    }
    unset($r);
    return $rows;
}
