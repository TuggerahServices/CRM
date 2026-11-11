<?php
// The work layer's API: everything the team is DOING about an empty place.
//
// MOVED HERE FROM /api/dashboard ON 10 AUG 2026. These five actions lived under
// the Dashboard because that was the only screen that displayed them, which left
// the placement board posting work_advance to an endpoint named after a screen it
// is not on. The work layer now has its own module (In progress) and its own
// endpoint; the Dashboard keeps only `load`, which is what it actually owns.
//
// `list` and `get` are READ-ONLY (GET, no CSRF, like /api/search and
// /api/tenure). Everything else mutates and takes POST + csrf_require().
//
// Readable failures go through api_fail() - HTTP 200 with ok:false - because
// nginx discards the body of a real 4xx under /api/. See _incl/api.php.

declare(strict_types=1);

require_once __DIR__ . '/../_incl/auth.php';
require_once __DIR__ . '/../_incl/csrf.php';
require_once __DIR__ . '/../_incl/api.php';
require_once __DIR__ . '/../_incl/work.php';
require_once __DIR__ . '/../_incl/rooms.php';
require_once __DIR__ . '/../_incl/audit.php';
require_once __DIR__ . '/../_incl/do-not-enrol.php';   // dne_match() - see `create`

$user   = auth_require_api();
$action = $_REQUEST['action'] ?? 'list';

$reads = ['list', 'get', 'tours'];
if (!in_array($action, $reads, true)) {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { http_response_code(405); exit; }
    csrf_require();
}

/**
 * The work item an action names, guarded by the caller's centre scope.
 *
 * ONE HELPER RATHER THAN A CHECK PER ACTION. Nine actions here address an item
 * by id and every one of them has to ask the same two questions - does it
 * exist, and is its centre mine. Written out nine times, the ninth is the one
 * somebody forgets, and forgetting it here means a supervisor editing another
 * centre's family. Actions that already load the item pass it back in.
 */
function work_item_scoped(int $id): array {
    $w = work_get($id);
    if (!$w) api_fail('Work item not found.');
    api_require_centre((int)$w['centre_id']);
    return $w;
}

/**
 * The next step, as ONE fact: what to do and when, or neither.
 *
 * THE TWO HALVES WERE INDEPENDENT OPTIONAL BOXES AND THE DATA SHOWS WHAT THAT
 * COST. `next_action` is NULL on all 15 live items; five carry a
 * `next_action_at`, and the audit log has them being typed on 3 Sep as
 * 08/09, 01/09, 25/08, 24/08 and 24/08 - four already in the past when they were
 * entered, one by ten days. They are the OFFERS' expiry dates, re-keyed by hand
 * into a box meant for "when will somebody act". The Dashboard tile then reads
 * "Past their date: 5" and not one of those five says what the step is, while
 * the In progress list renders an empty Next step column on every row.
 *
 * A date with nothing against it cannot be acted on and cannot be judged; a step
 * with no date never surfaces, because work_urgency_sort() only bands on the
 * date. So it is both or neither, and this is refused server-side rather than
 * only in the browser - the same reason work_cancel() refuses a worked item
 * instead of trusting a hidden button.
 *
 * @return array{0: ?string, 1: ?string} [what, when]
 */
function work_posted_next_step(): array {
    $what = trim((string)($_POST['next_action'] ?? ''));
    $when = trim((string)($_POST['next_action_at'] ?? ''));
    if ($what !== '' && $when === '') api_fail('Say when the next step is due, or clear it.');
    if ($what === '' && $when !== '') api_fail('Say what that date is for, or clear it.');
    return [$what ?: null, $when ?: null];
}

/**
 * Who a posted next step is for: `next_action_for`, or the person saving it.
 *
 * Only somebody who can sign in and see this centre - work_people_for_centre().
 * The reminder is that person's Mine view and nothing else, so a typed address
 * that is not one of them would be a step reminding nobody, saved as though it
 * reminded somebody.
 */
function work_posted_next_for(int $centreId, array $user): string {
    $for = strtolower(trim((string)($_POST['next_action_for'] ?? '')));
    if ($for === '') return (string)($user['email'] ?? '');
    foreach (work_people_for_centre($centreId) as $p) {
        if (strtolower($p['email']) === $for) return $p['email'];
    }
    api_fail('Nobody with that email can open this centre in the CRM, so they would never see the reminder.');
}

/** Weekdays posted as days[] - deduplicated and bounded to Mon-Fri. */
function work_posted_days(): array {
    $raw = $_POST['days'] ?? [];
    if (!is_array($raw)) $raw = [$raw];
    $d = array_values(array_unique(array_filter(
        array_map('intval', $raw), static fn($w) => $w >= 1 && $w <= 5)));
    sort($d);
    return $d;
}

/**
 * The ids on an item's visit AS IT STANDS, taken before a write moves it: a
 * sibling left behind by a tour moved on its own still holds the visit's one
 * calendar entry, which has to be renamed - see tour_calendar_sync().
 */
function work_visit_ids(array $w): array {
    return array_map(static fn($r) => (int)$r['id'], tour_visit($w));
}

/** What a family action did to the brothers and sisters, for the toast. */
function work_family_reply(array $fam): array {
    return ['family' => $fam['names'], 'family_skipped' => $fam['skipped']];
}

// TOURS THAT NEED THEIR OUTLOOK ENTRY BROUGHT INTO LINE, written AFTER the
// response has gone - see the end of this file and tour-calendar.php.
$calendarSync   = [];
$calendarForget = [];

try {
    switch ($action) {

    // Everything in progress: work items plus any offer with none behind it.
    case 'list': {
        // 0 or absent is THE WHOLE GROUP. A work item belongs to one centre; a
        // list of them does not, and refusing the group view is what hid the
        // work layer on the screen the team opens the day on.
        $centreId = (int)($_GET['centre_id'] ?? 0) ?: null;
        // Null here means the whole group, which only a user who HAS a group may
        // ask for. api_require_centre(0, true) says exactly that, and says
        // "Choose a centre" to anybody else.
        api_require_centre((int)$centreId, true);
        // Null year: open work is open work, whichever year it targets. A family
        // being chased today for a January start is work today.
        $year = (int)($_GET['year'] ?? 0) ?: null;

        $stage = (string)($_GET['stage'] ?? '');
        if ($stage !== '' && !isset(WORK_STAGES[$stage])) api_fail('Unknown stage.');

        // A family Kidsoft has enrolled is finished work, and this screen must
        // not list them while the Waitlist already says Enrolled. Reads only our
        // own table, so it costs one query. See work_close_kidsoft_enrolled().
        try { work_close_kidsoft_enrolled($centreId); }
        catch (Throwable $e) { error_log('work_close_kidsoft_enrolled: ' . $e->getMessage()); }

        $rows = work_progress_list($centreId, $year, [
            'stage'          => $stage ?: null,
            'overdue'        => !empty($_GET['overdue']),
            'due'            => !empty($_GET['due']),
            'tours'          => !empty($_GET['tours']),
            'include_closed' => !empty($_GET['include_closed']),
            // "Mine" is resolved from the SESSION, never from the query string:
            // whose work it is is not something a URL gets to assert. Since
            // 22 Oct 2026 it is the families you look after OR whose next step
            // names you - see work_list().
            'mine'           => !empty($_GET['mine']) ? ($user['email'] ?? '') : null,
        ]);

        echo json_encode([
            'ok'      => true,
            'items'   => $rows,
            'summary' => work_progress_summary($rows),
            'stages'  => WORK_STAGES,
            'centres' => array_map(static fn($c) => ['id' => (int)$c['id'], 'name' => $c['name']],
                                   centres_all()),
        ], JSON_UNESCAPED_SLASHES);
        break;
    }

    case 'get': {
        $w = work_item_scoped((int)($_GET['id'] ?? 0));
        // `due` rides with the item rather than being a constant on the page:
        // these are DATES, and a modal left open across midnight would otherwise
        // offer yesterday's "tomorrow". Published because the browser must not
        // compute one - see work_due_presets().
        echo json_encode(['ok' => true, 'item' => $w, 'stages' => WORK_STAGES,
                          'due' => work_due_presets()], JSON_UNESCAPED_SLASHES);
        break;
    }

    case 'create': {
        // The one action here that names its centre outright rather than
        // inheriting it from an item that already exists.
        api_require_centre((int)($_POST['centre_id'] ?? 0));

        // DO NOT ENROL, the same confirmation make_offer asks for, at the first
        // step instead of the last: starting work on a family is where somebody
        // picks up the phone. Every way in carries a waitlist_id - the board's
        // "Start with this family", the queue's and the family modal's "Add to In
        // progress", and booking a tour there - so this one check covers them all.
        // `dne` goes back on the refusal so the browser can ask and re-send; see
        // window.postConfirmingDne() in app.js. Read here, never from the form.
        $dne = null;
        $dneApp = (int)($_POST['waitlist_id'] ?? 0);
        if ($dneApp) {
            $row = dbx()->query(
                'SELECT kidsoft_guardian_id, guardian_email, guardian_phone
                   FROM waitlist_applications WHERE id = ?', [$dneApp], 'i')->fetch();
            $dne = $row ? dne_match($row) : null;
            if ($dne && ($_POST['dne_checked'] ?? '') !== '1') {
                api_fail('Do Not Enrol: ' . $dne['text']
                       . ' Confirm this has been checked before starting with this family.',
                         ['dne' => $dne]);
            }
        }

        // BROTHERS AND SISTERS ON THE SAME TOUR (19 Oct 2026), from the family
        // modal's tour box - checked against the family the server finds before
        // anything is written. See work_family_tour().
        $sibs = work_family_pick((int)($_POST['waitlist_id'] ?? 0) ?: null, $_POST['siblings'] ?? null);

        // ONE FAMILY, ONE ROW - both doors into the duplicate Terrigal reported
        // (14 Oct 2026). See work_queue_matches() for the measurement. In each
        // case the server FINDS and the person DECIDES: a shared phone is how a
        // family is recognised and not how a child is, so nothing is joined on
        // the match alone. The refusal carries the candidates back, the way `dne`
        // does, and the browser asks and re-sends.
        $wlPosted = (int)($_POST['waitlist_id'] ?? 0);
        $ksPosted = trim((string)($_POST['kidsoft_child_id'] ?? ''));

        // 1. A tour enquiry for a family who is already in this centre's queue.
        if (!$wlPosted && $ksPosted === '' && ($_POST['queue_checked'] ?? '') !== '1') {
            $m = work_queue_matches((int)$_POST['centre_id'],
                                    (string)($_POST['contact_phone'] ?? ''),
                                    (string)($_POST['contact_email'] ?? ''));
            if ($m) {
                api_fail('This family is already in the queue here.', ['queue_matches' => $m]);
            }
        }

        // 2. A queue family who already booked a tour as an enquiry. `join_enquiry`
        //    is the answer "same child": the enquiry becomes this application's
        //    item - its tour, stage and history kept - instead of a second row.
        //    Re-checked against the match list rather than trusted, so a post
        //    cannot join an arbitrary item to an application.
        if ($wlPosted) {
            $join = (int)($_POST['join_enquiry'] ?? 0);
            if ($join) {
                $ids = array_column(work_enquiry_matches($wlPosted), 'id');
                if (!in_array($join, $ids, true)) api_fail('That enquiry does not match this family.');
                $id = work_join_enquiry($join, $wlPosted, $user['email'] ?? 'staff', [
                    'room_id' => $_POST['room_id'] ?? null,
                    'year'    => $_POST['year'] ?? null,
                    'days'    => work_posted_days(),
                ]);
                // Whatever the caller came to do still happens, on the one row:
                // booking from the family modal MOVES the tour already in the
                // diary, as it does for any family with an item.
                if (trim((string)($_POST['tour_at'] ?? '')) !== '') {
                    work_set_tour($id, (string)$_POST['tour_at'], null, $user['email'] ?? 'staff');
                }
                if ($dne) {
                    audit('work.dne_checked', 'work_item', $id,
                          ['waitlist_id' => $dneApp, 'dne_checked' => $dne['text']]);
                }
                $fam = work_family_tour($id, $sibs, $user['email'] ?? 'staff');
                if (isset($_POST['tour_invite'])) work_set_tour_invite($id, $_POST['tour_invite'] === '1');
                $calendarSync = array_merge($calendarSync, [$id], $fam['ids']);
                echo json_encode(['ok' => true, 'id' => $id, 'joined' => true] + work_family_reply($fam));
                break;
            }
            if (($_POST['enquiry_checked'] ?? '') !== '1') {
                $m = work_enquiry_matches($wlPosted);
                if ($m) {
                    api_fail('A tour enquiry may be this family.', ['enquiry_matches' => $m]);
                }
            }
        }

        $nextStep = work_posted_next_step();
        $id = work_create([
            'centre_id'        => (int)($_POST['centre_id'] ?? 0),
            'room_id'          => $_POST['room_id'] ?? null,
            // Passed straight through, NOT cast to int here: work_create() takes
            // null to mean "nobody has named a year", and (int) would turn that
            // into 0 and then into a thrown "year looks wrong".
            'year'             => $_POST['year'] ?? null,
            'waitlist_id'      => $_POST['waitlist_id'] ?? null,
            'kidsoft_child_id' => $_POST['kidsoft_child_id'] ?? null,
            'child_name'       => trim((string)($_POST['child_name'] ?? '')),
            // A tour enquiry: the only thing identifying the family is what the
            // parent said on the phone.
            'contact_name'     => trim((string)($_POST['contact_name'] ?? '')),
            'contact_phone'    => trim((string)($_POST['contact_phone'] ?? '')),
            'contact_email'    => trim((string)($_POST['contact_email'] ?? '')),
            'tour_at'          => $_POST['tour_at'] ?? null,
            'days'             => work_posted_days(),
            // What the parent said on the phone, kept as the item's first history
            // line. Without this the enquiry's own log opened with nothing but
            // "Identified", and the reason they rang was lost at the door.
            'note'             => trim((string)($_POST['note'] ?? '')) ?: null,
            // Both or neither - see work_posted_next_step(). No caller sends
            // these today, and the guard goes on anyway: an endpoint that
            // accepts a half-filled next step is how the five orphan dates got
            // in, and the next caller should not have to know that.
            'next_action'      => $nextStep[0],
            'next_action_at'   => $nextStep[1],
            'next_action_for'  => $nextStep[0] !== null ? work_posted_next_for((int)($_POST['centre_id'] ?? 0), $user) : null,
            'owner_email'      => $user['email'] ?? null,
            'created_by'       => $user['email'] ?? null,
        ]);
        // Who confirmed a Do Not Enrol match, and what it matched - beside the
        // work.create row work_create() has just written.
        if ($dne) {
            audit('work.dne_checked', 'work_item', $id,
                  ['waitlist_id' => $dneApp, 'dne_checked' => $dne['text']]);
        }
        // A FAMILY KIDSOFT HAS ALREADY OFFERED A PLACE TO starts at the stage
        // Kidsoft says, with the offer's room and days, rather than at
        // Identified until the next import (15 Oct 2026). Same function the
        // import runs; it touches only items that are behind Kidsoft.
        if ($wlPosted) work_follow_kidsoft_offers((int)$_POST['centre_id']);
        $fam = work_family_tour($id, $sibs, $user['email'] ?? 'staff');
        // "Send the invite to the parent" - see work_set_tour_invite(). Absent
        // means this form has no such box, and leaves it alone.
        if (isset($_POST['tour_invite'])) work_set_tour_invite($id, $_POST['tour_invite'] === '1');
        $calendarSync = array_merge($calendarSync, [$id], $fam['ids']);
        echo json_encode(['ok' => true, 'id' => $id] + work_family_reply($fam));
        break;
    }

    // THE TOUR DIARY. Read-only in effect but POSTed like the rest of the
    // mutating actions would be - no: it is a read, so it joins $reads above.
    case 'tours': {
        $centreId = (int)($_GET['centre_id'] ?? 0) ?: null;
        api_require_centre((int)$centreId, true);
        echo json_encode(['ok' => true, 'tours' => work_tours($centreId)], JSON_UNESCAPED_SLASHES);
        break;
    }

    // Book, move or cancel a tour. An empty tour_at clears it.
    case 'tour': {
        $id = (int)($_POST['id'] ?? 0);
        $w  = work_item_scoped($id);
        // The brothers and sisters the person left ticked go onto the same visit
        // - see work_family_tour(). Checked before anything is written.
        $sibs = work_family_pick($w['waitlist_id'] ? (int)$w['waitlist_id'] : null, $_POST['siblings'] ?? null);
        $calendarSync = array_merge($calendarSync, work_visit_ids($w));
        $moved = work_set_tour($id, (string)($_POST['tour_at'] ?? '') ?: null,
                               trim((string)($_POST['note'] ?? '')) ?: null,
                               $user['email'] ?? 'staff');
        $fam = work_family_tour($id, $sibs, $user['email'] ?? 'staff');
        if (isset($_POST['tour_invite'])) work_set_tour_invite($id, $_POST['tour_invite'] === '1');
        $calendarSync = array_merge($calendarSync, [$id], $fam['ids']);
        echo json_encode(['ok' => true, 'moved' => $moved] + work_family_reply($fam));
        break;
    }

    // What happened at the tour. NOT a stage - see work_tour_outcome(), where
    // the reason a no-show must not become one is written down.
    case 'tour_outcome': {
        $id = (int)($_POST['id'] ?? 0);
        work_item_scoped($id);
        // FOR EVERYBODY ON THAT VISIT (19 Oct 2026): brothers and sisters booked
        // into one tour came, or did not, together. See work_tour_outcome_visit().
        $with = work_tour_outcome_visit($id, (string)($_POST['outcome'] ?? ''),
                                        trim((string)($_POST['note'] ?? '')) ?: null,
                                        $user['email'] ?? 'staff');
        echo json_encode(['ok' => true, 'family' => $with]);
        break;
    }

    case 'advance': {
        $id    = (int)($_POST['id'] ?? 0);
        $stage = (string)($_POST['stage'] ?? '');
        $w     = work_item_scoped($id);
        if (!isset(WORK_STAGES[$stage])) api_fail('Unknown stage.');

        // LOST ASKS ABOUT THE BROTHERS AND SISTERS (19 Oct 2026): the modal offers
        // each one with an open item, unticked, and posts the ones ticked. Only an
        // open item of a sibling waiting here - re-checked, never trusted - and
        // only alongside a move to Lost.
        $alsoRaw  = $_POST['also_lost'] ?? [];
        $alsoLost = array_values(array_unique(array_filter(array_map('intval',
                        is_array($alsoRaw) ? $alsoRaw : [$alsoRaw]))));
        if ($alsoLost) {
            if ($stage !== 'lost') api_fail('Only a move to Lost can take a brother or sister with it.');
            $allowed = [];
            $wl = $w['waitlist_id'] ? (int)$w['waitlist_id'] : 0;
            foreach ($wl ? (work_siblings_here([$wl])[$wl]['siblings'] ?? []) : [] as $s) {
                if ($s['item']) $allowed[$s['item']['id']] = $s['name'];
            }
            foreach ($alsoLost as $sid) {
                if (!isset($allowed[$sid])) api_fail('That brother or sister has no open item here to close.');
            }
        }
        // 'enrolled' is CLOSED BY KIDSOFT, not by hand. Letting a person set it
        // would make the one honest number on the screen - how many places were
        // actually filled - a matter of opinion.
        if ($stage === 'enrolled') {
            api_fail('An item is marked enrolled when Kidsoft shows the child in the room, not by hand.');
        }
        // ARCHIVED HAS ITS OWN ACTION (6 Nov 2026), so there is one door to it and
        // it always goes through work_archive()'s checks. Staying archived while a
        // note is added is fine - that is the same "a note on this stage" path.
        if ($stage === 'archived' && $w['stage'] !== 'archived') {
            api_fail('Use the Archive button to archive an item.');
        }
        // OFFERED AND ACCEPTED ARE KIDSOFT'S TOO (22 Oct 2026, the enrolments
        // meeting). Offers are sent in Kidsoft and read back, and
        // work_follow_kidsoft_offers() moves the item to Offered when the offer
        // is out and to Accepted when the family's form is in. Picked by hand,
        // either one is a stage that says something Kidsoft does not. Staying at
        // the stage it is already at is allowed - that is a note being added.
        if (($stage === 'offered' || $stage === 'accepted') && $w['stage'] !== $stage) {
            api_fail($stage === 'offered'
                ? 'Offered follows Kidsoft. Send the offer in Kidsoft and this moves to Offered by itself.'
                : 'Accepted follows Kidsoft. This moves to Accepted by itself once the family\'s form is in.');
        }
        $note = trim((string)($_POST['note'] ?? ''));
        $moved = work_advance($id, $stage, $note ?: null, $user['email'] ?? 'staff');

        // A NOTE ON AN ITEM THAT DID NOT MOVE WAS BEING THROWN AWAY. work_advance()
        // returns early when the item is already at that stage, and the note goes
        // with it - so "already at that stage is swallowed, because the point of
        // the save is often the note" was true of the ERROR and not of the note,
        // which survived only if a channel happened to be picked. Booking a tour
        // makes this the common path: work_set_tour() has already moved the item
        // to `tour_booked` by the time this runs. Recorded as an event against the
        // stage it is already at, which is what "just a note on this item" means.
        if (!$moved && $note !== '') {
            work_log($id, $stage, $stage, $note, $user['email'] ?? 'staff');
        }

        // THE CONTACT IS NO LONGER WRITTEN HERE (18 Sep 2026). This used to call
        // work_log_contact(), which INSERTed into `communications` itself - a
        // second writer of that table, with no `body`, a silent truncation to
        // 255 and no Kidsoft copy. The note now posts to /api/comms with a
        // work_item_id, so a contact note has ONE implementation whichever
        // screen typed it. `channel` and `direction` are no longer read by this
        // action at all; sending them is harmless and does nothing.
        //
        // What stays here is the ITEM's own history: `note` is what explains
        // THIS stage change, and it is written by work_advance() above.
        if (!$moved && $note === '') api_fail('That item is already at that stage.');
        $lostWith = [];
        if ($moved && $stage === 'lost') {
            foreach ($alsoLost as $sid) {
                if (work_advance($sid, 'lost', $note !== '' ? $note
                        : 'Not proceeding, the same as ' . $w['child_name'] . '.', $user['email'] ?? 'staff')) {
                    $lostWith[] = $allowed[$sid];
                    $calendarSync[] = $sid;
                }
            }
        }
        // Lost before the visit takes the tour out of the centre's calendar - and
        // a sibling still coming keeps the visit's one entry, renamed.
        $calendarSync = array_merge($calendarSync, work_visit_ids($w), [$id]);
        echo json_encode(['ok' => true, 'moved' => $moved, 'family' => $lostWith]);
        break;
    }

    case 'update': {
        $id = (int)($_POST['id'] ?? 0);
        $w  = work_item_scoped($id);
        $touched = [];
        // The work modal's "also on this tour" ticks - see `tour`. Checked first,
        // so a refusal cannot land after half the save.
        $sibs = work_family_pick($w['waitlist_id'] ? (int)$w['waitlist_id'] : null, $_POST['siblings'] ?? null);
        $calendarSync = array_merge($calendarSync, work_visit_ids($w));

        // EVERY FIELD HERE IS GUARDED ON isset(), because this endpoint is
        // shared and absence has to mean "this form does not edit that".
        //
        // `next_action`, `next_action_at` and `owner_email` were NOT, and were
        // written unconditionally: a caller that did not carry them blanked
        // them. The modal always sends all three, so nothing in the app was
        // wrong - but the next caller would have had to know that, and the tour
        // and contact blocks below had been guarded since the day they were
        // added. Proven rather than supposed: a curl to this action naming only
        // an id cleared `owner_email` on a live item during testing on
        // 18 Sep 2026.
        //
        // THE NEXT STEP IS ONE FACT AND IS GUARDED AS ONE. Naming EITHER key
        // means the caller edits the pair, and work_posted_next_step() then
        // refuses a half - so a caller cannot set a date by sending the date
        // alone, which is exactly the shape of the five orphan dates in the
        // table. Refused BEFORE anything is written, so a bad pair cannot land
        // alongside a successful tour or owner change and leave the caller
        // unsure which half of the save took.
        if (isset($_POST['next_action']) || isset($_POST['next_action_at'])) {
            [$nextWhat, $nextWhen] = work_posted_next_step();
            // WHO IT IS FOR is part of the same fact (22 Oct 2026): a step with
            // nobody against it reminds nobody, so it defaults to whoever set it.
            $nextFor = $nextWhat !== null ? work_posted_next_for((int)$w['centre_id'], $user) : null;
            dbx()->query('UPDATE work_items SET next_action = ?, next_action_at = ?, next_action_for = ? WHERE id = ?',
                         [$nextWhat, $nextWhen, $nextFor, $id]);
            $touched[] = 'next_step';
        }
        // THE ROOM AND DAYS BEING TALKED ABOUT (22 Oct 2026, the enrolments
        // meeting): "can we select from there what room, and what days we are
        // talking to the family for?" They are the item's own room and days -
        // the spot it is worked toward - so a room chosen here also puts the
        // family on that room's cells on the Placement board. Refused from
        // Offered on, when Kidsoft's offer decides both. Checked before either is
        // written, so a refusal cannot leave half of it saved.
        if (isset($_POST['room_id']) || isset($_POST['days'])) {
            if (work_room_days_locked($w['stage'])) {
                api_fail('The offer in Kidsoft sets the room and days now.');
            }
            if (isset($_POST['room_id'])) {
                $roomId = work_clean_room_id($_POST['room_id'], (int)$w['centre_id']);
                dbx()->query('UPDATE work_items SET room_id = ? WHERE id = ?', [$roomId, $id]);
                $touched[] = 'room';
            }
        }
        if (isset($_POST['owner_email'])) {
            dbx()->query('UPDATE work_items SET owner_email = ? WHERE id = ?',
                         [trim((string)$_POST['owner_email']) ?: null, $id]);
            $touched[] = 'owner_email';
        }
        // The parent's details, for an enquiry that has no application behind it.
        // Only written when the form actually carried them, so a save from the
        // Placement board - whose modal has no such fields - cannot blank them.
        if (isset($_POST['contact_phone']) || isset($_POST['contact_email'])
            || isset($_POST['contact_name'])) {
            dbx()->query(
                'UPDATE work_items SET contact_name = ?, contact_phone = ?, contact_email = ? WHERE id = ?',
                [trim((string)($_POST['contact_name'] ?? ''))  ?: null,
                 trim((string)($_POST['contact_phone'] ?? '')) ?: null,
                 trim((string)($_POST['contact_email'] ?? '')) ?: null,
                 $id]
            );
            $touched[] = 'contact';
        }
        // THE TOUR GOES THROUGH work_set_tour(), never a bare UPDATE here: it is
        // what moves the item into `tour_booked` and writes the history line, and
        // a second way to set the date would be a second way to get that wrong.
        // Same guard - absent means "this form does not edit tours".
        if (isset($_POST['tour_at'])) {
            work_set_tour($id, (string)$_POST['tour_at'] ?: null, null, $user['email'] ?? 'staff');
            $touched[] = 'tour_at';
        }
        $fam = work_family_tour($id, $sibs, $user['email'] ?? 'staff');
        if ($fam['ids']) $touched[] = 'family_tour';
        if (isset($_POST['tour_invite']) && work_set_tour_invite($id, $_POST['tour_invite'] === '1')) {
            $touched[] = 'tour_invite';
        }
        // Days and contact details are written into the calendar entry too.
        $calendarSync = array_merge($calendarSync, [$id], $fam['ids']);
        if (isset($_POST['days'])) {
            work_set_days($id, work_posted_days());
            $touched[] = 'days';
        }
        // WHAT WAS ACTUALLY EDITED, now that absence means "left alone". This
        // used to log `next_action_at` whether or not the caller had sent one,
        // so the audit trail could not tell a cleared date from an untouched
        // one - and with the guard above that distinction is the whole point.
        audit('work.update', 'work_item', $id, ['fields' => $touched]);
        echo json_encode(['ok' => true] + work_family_reply($fam));
        break;
    }

    // Delete an item created by mistake. Refused once it has a real history --
    // see work_cancel(), which is where that judgement lives.
    case 'archive': {
        // CLOSED WITHOUT AN OUTCOME (6 Nov 2026) - see work_archive(). The centre
        // guard runs inside work_item_scoped(), off the item's own centre_id.
        $id   = (int)($_POST['id'] ?? 0);
        $w    = work_item_scoped($id);
        $note = trim((string)($_POST['note'] ?? ''));
        if (mb_strlen($note) > 500) api_fail('Keep the reason under 500 characters.');
        work_archive($id, $note !== '' ? $note : null, $user['email'] ?? 'staff');
        // An archived item wants no tour in the diary if the visit is still ahead
        // (tour_calendar_wanted()), and a brother or sister on the same visit
        // takes the entry over - the same sync a move to Lost triggers.
        $calendarSync = array_merge($calendarSync, work_visit_ids($w), [$id]);
        echo json_encode(['ok' => true]);
        break;
    }

    case 'cancel': {
        $id = (int)($_POST['id'] ?? 0);
        $before = work_item_scoped($id);
        $left   = array_values(array_diff(work_visit_ids($before), [$id]));
        $res = work_cancel($id);
        if (!$res['ok']) api_fail($res['error']);
        // The row is gone, so its entry is removed from what it WAS - and a
        // brother or sister still on that visit takes the entry over.
        if (!empty($before['calendar_event_id'])) $calendarForget[] = $before;
        $calendarSync = array_merge($calendarSync, $left);
        echo json_encode(['ok' => true]);
        break;
    }

    default:
        api_fail('Unknown action.');
    }
} catch (InvalidArgumentException $e) {
    api_fail($e->getMessage());
} catch (RuntimeException $e) {
    api_fail('Could not reach Kidsoft: ' . $e->getMessage());
}

// THE CENTRE'S OUTLOOK CALENDAR, AFTER THE ANSWER HAS GONE (16 Oct 2026). A tour
// is saved the moment the row commits; copying it into Outlook is a Graph token
// and a round trip, and a failure there must not fail - or slow - the save. So
// the response is finished first, the session released (so the next click is not
// queued behind this), and the outcome lands on the row for the modal to show
// next time. tour_calendar_sweep() retries anything this misses. Reached only on
// success: api_fail() exits.
if ($calendarSync || $calendarForget) {
    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
    if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
    foreach ($calendarForget as $gone) {
        try { tour_calendar_forget($gone); }
        catch (Throwable $e) { error_log('tour_calendar_forget: ' . $e->getMessage()); }
    }
    foreach (array_unique($calendarSync) as $cid) {
        try { tour_calendar_sync((int)$cid); }
        catch (Throwable $e) { error_log('tour_calendar_sync(' . $cid . '): ' . $e->getMessage()); }
    }
}
