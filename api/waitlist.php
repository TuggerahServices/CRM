<?php
// Waitlist and offers.
//
// Readable failures go through api_fail() -> HTTP 200 with ok:false, because
// nginx discards the body of a real 4xx under /api/. See _incl/api.php.

declare(strict_types=1);

require_once __DIR__ . '/../_incl/auth.php';
require_once __DIR__ . '/../_incl/csrf.php';
require_once __DIR__ . '/../_incl/api.php';
require_once __DIR__ . '/../_incl/waitlist.php';
require_once __DIR__ . '/../_incl/work.php';   // the pipeline reflects offers, never makes them
require_once __DIR__ . '/../_incl/waitlist-group.php';
require_once __DIR__ . '/../_incl/waitlist-history.php';  // past applications, search's own lines
require_once __DIR__ . '/../_incl/waitlist-import.php';  // the refresh action
require_once __DIR__ . '/../_incl/board.php';
require_once __DIR__ . '/../_incl/audit.php';

$user = auth_require_api();
$action = $_REQUEST['action'] ?? '';

$readOnly = ['list', 'group', 'vacancies', 'get'];
if (!in_array($action, $readOnly, true)) {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { http_response_code(405); exit; }
    csrf_require();
}

// A lapsed offer must stop holding a place the moment anyone looks, not whenever
// a cron next runs.
offers_expire_due();

function posted_days(string $key = 'days'): array {
    $raw = $_POST[$key] ?? [];
    if (!is_array($raw)) $raw = [$raw];
    $d = array_values(array_unique(array_filter(
        array_map('intval', $raw), static fn($w) => $w >= 1 && $w <= 5
    )));
    sort($d);
    return $d;
}

/**
 * The segmented "Which applications to show" control, resolved once.
 *
 * Three answers to ONE question, which is why it is one control and one
 * parameter rather than a toggle plus a chip: `open` is the live queue, `all`
 * adds the closed history, `offer_expired` is the slice of that history the
 * team asked to be able to reach (Broc, 9 Sep 2026) - a place this organisation
 * offered and nobody closed out, which is not the same fact as a waiting place
 * a parent let lapse.
 *
 * Read by BOTH the per-centre queue and the group register, so it lives here:
 * two copies of a rule is how they drift, and this file has already paid for
 * that lesson in offer_expiry_normalise().
 */
function waitlist_show_opts(): array {
    $show = (string)($_GET['show'] ?? 'open');
    return [
        // An expired offer IS closed, so the closed rows have to be in the set
        // before the slice can be taken out of them.
        'include_closed' => $show === 'all' || $show === 'offer_expired',
        'offer_expired'  => $show === 'offer_expired',
    ];
}

/**
 * The start-date range (start_from / start_to), resolved once. See
 * waitlist_start_range() - no dates is no filter.
 *
 * One place, because BOTH the per-centre queue and the group register read it -
 * the same reason waitlist_show_opts() exists.
 */
function waitlist_start_opts(): array {
    return waitlist_start_range($_REQUEST);
}

switch ($action) {

case 'list': {
    $centreId = (int)($_GET['centre_id'] ?? 0);
    api_require_centre($centreId);

    $rows = waitlist_for_centre($centreId,
        waitlist_age_bounds($_GET) + waitlist_show_opts() + waitlist_start_opts() + [
        'q'      => trim((string)($_GET['q'] ?? '')),
        'status' => (string)($_GET['status'] ?? ''),
    ]);

    // WHERE ELSE EACH CHILD IS WAITING (17 Aug 2026). Attached here rather than
    // inside waitlist_for_centre(), because it is a GROUP-WIDE fact and that
    // function is the per-centre one - waitlist_group_all() calls it once per
    // centre, so computing it in there would recurse. Costs ~0.02s on top of the
    // queue it is decorating; see waitlist_elsewhere().
    $elsewhere = waitlist_elsewhere($centreId);
    // AND THEIR BROTHERS AND SISTERS WHO ARE ALSO WAITING (16 Oct 2026), off the
    // same memoised groups - see waitlist_siblings_waiting(). An array, never
    // null: the browser maps over it on every row.
    $siblings = waitlist_siblings_waiting($centreId);
    // AND WHERE THEY APPLIED BEFORE (11 Nov 2026): the closed and archived
    // applications search shows, so a family rejected or deleted elsewhere is
    // not read as new because they are on one queue. See waitlist_history().
    // Null when there is none, and the browser tests for it.
    $history = waitlist_history($rows);
    foreach ($rows as &$w) {
        $w['elsewhere']        = $elsewhere[(int)$w['id']] ?? [];
        $w['siblings_waiting'] = $siblings[(int)$w['id']] ?? [];
        $w['history']          = $history[(int)$w['id']] ?? null;
    }
    unset($w);

    // Families who already have a child with us used to be detected HERE, purely
    // for the row badges, while priority_rank() ranked on a column nobody filled
    // in. Since 11 Aug 2026 waitlist_for_centre() does the detection itself and
    // the ORDER of these rows already reflects it - so there is nothing to
    // reconcile, no `sibling_unticked` count and no bulk button. See
    // _incl/siblings.php.
    echo json_encode([
        'ok'        => true,
        'waitlist'  => $rows,
        'statuses'  => WAITLIST_STATUS,
        'poa'       => POA_LABELS,
        // One definition of "newly added", shared with the browser: the first
        // date that counts, as a Sydney date, and the window for the caption.
        'new_since' => waitlist_new_since(),
        'new_days'  => WAITLIST_NEW_DAYS,
        // WHERE THE START-DATE CUT SITS, whichever segment is in force - so the
        // row can name the late starters when they are on screen without the
        // browser working out what "next year" means. Same reason `today` and
        // `default_year` are here: a Sydney date is decided on the server.
        'starts_by_default' => waitlist_starts_by_default(),
        // The year to file a new In progress entry under when the application
        // does not name one. From the SERVER, so it is Sydney's year and not the
        // viewer's - the same reason /api/cells publishes `today`.
        'default_year' => board_default_year(),
        // TODAY IN SYDNEY, for the same reason /api/cells publishes it. The Add
        // form used to default date_added from new Date().toISOString(), which
        // is UTC - so an application keyed between midnight and 10am local was
        // dated YESTERDAY, and date_added is the final tie-breaker for the whole
        // queue. It silently jumped the family ahead of everyone who applied the
        // previous afternoon.
        'today' => date('Y-m-d'),
        // The pipeline vocabulary, so this screen never keeps its own copy of
        // the stage labels. One list, defined in _incl/work.php.
        'work_stages' => WORK_STAGES,
        'rooms'     => array_map(static fn($r) => [
            'id' => (int)$r['id'], 'name' => $r['name'],
            'min_age_months' => $r['min_age_months'], 'max_age_months' => $r['max_age_months'],
        ], rooms_for_centre($centreId, board_default_year())),
    ], JSON_UNESCAPED_SLASHES);
    break;
}

// ONE application, for the shared family modal (_incl/waitlist-modal.php).
//
// The Waitlist queue is holding the row already; the PLACEMENT BOARD is not, and
// it opens the same modal from "Could take this day". One loading path rather
// than two is what stops the two screens drifting into showing different things
// about the same family.
//
// Deliberately NOT the list row. Queue position, priority reasons, the sibling
// match and the open offer are all computed across the whole centre by
// waitlist_for_centre(), and none of them is editable here - so this returns
// what the FORM needs and pays for nothing else.
//
// With no id it is the Add form: the rooms to choose from and today's date, and
// no application.
case 'get': {
    $id       = (int)($_GET['id'] ?? 0);
    $centreId = (int)($_GET['centre_id'] ?? 0);

    $w = null;
    if ($id) {
        $w = waitlist_get($id);
        if (!$w) api_fail('Waitlist application not found.');
        // THE APPLICATION'S OWN CENTRE, not one the caller passed. The rooms in
        // the preferred-room list have to be the rooms of the centre the family
        // applied to, whichever screen opened this.
        $centreId = (int)$w['centre_id'];
        // CAST WHAT waitlist_for_centre() CASTS. waitlist_get() returns the raw
        // row, where these are the strings "0" and "1" - and "0" is TRUTHY in
        // JS, so a straight hand-off ticks Re-enrolment, Staff's child and
        // Sibling on every application in the queue.
        foreach (['is_reenrolment', 'is_staff_child', 'is_sibling', 'guide_provided'] as $f) {
            $w[$f] = (bool)$w[$f];
        }
        // WHICH DAYS THEY ALREADY HOLD HERE, through the same function the queue
        // uses, on this one row. The modal used to show the requested days alone
        // while the queue drew the held ones green, so the two screens described
        // one family differently - found on Aleigha Grant at Tuggerah, who
        // attends a Friday her application does not ask for. Cheap: the index is
        // memoised for the request and `elsewhere` below has already built it.
        $w = waitlist_mark_in_care([$w])[0];
        // Do Not Enrol, for the same reason: the modal is opened from search and
        // the Placement board, where no queue row has carried it here.
        $w = waitlist_attach_dne([$w])[0];
        // And the duplicate flag, the queue row's own - see waitlist_duplicates().
        $w['duplicate'] = waitlist_duplicates([(int)$w['centre_id']])[(int)$w['id']] ?? null;
        // The offer Kidsoft holds, if any - the queue row's own definition. The
        // dialog shows the days asked for, and Kidsoft's offer screen shows the
        // days OFFERED, which is how Stella Knox's Tue/Fri here and Tue/Wed
        // there looked like the two systems disagreeing.
        $w['kidsoft_offer'] = waitlist_kidsoft_offers([$w])[(int)$w['id']] ?? null;
    }
    api_require_centre($centreId);

    echo json_encode([
        'ok'          => true,
        'application' => $w,
        'centre_id'   => $centreId,
        // THE TOUR, WHICH IS THE WORK ITEM'S AND NOT THIS ROW'S. The family
        // modal used to edit waitlist_applications.tour_booked_at, a second
        // record of the same appointment that nothing kept in step - see
        // work_for_waitlist(), where what that cost is written down. Null when
        // nobody is being worked yet, which the modal reads as "booking a tour
        // starts them".
        'work'        => $w ? work_for_waitlist((int)$w['id']) : null,
        // The other centres this child is waiting at, for "What they want".
        // THE SAME waitlist_elsewhere() THE QUEUE COLUMN USES: the modal is
        // opened from the Placement board as well, where there is no queue row to
        // read it off, and a second grouping rule for the same question is how
        // the two screens would come to disagree about whether a family is one
        // family. Empty on the Add form, which has no child to group yet.
        'elsewhere'   => $w ? (waitlist_elsewhere((int)$w['centre_id'])[$id] ?? []) : [],
        // Brothers and sisters also waiting - the queue column's own answer.
        'siblings_waiting' => $w ? (waitlist_siblings_waiting((int)$w['centre_id'])[$id] ?? []) : [],
        // Their past applications - the queue column's own, from the same
        // waitlist_history(). The Placement board opens this modal too.
        'history'     => $w ? (waitlist_history([$w])[$id] ?? null) : null,
        // THE SAME CHILDREN AT THIS CENTRE, with their In progress item - what the
        // tour box's "also on this tour" and the contact form's "also on their
        // history" ticks are built from. work_siblings_here(), the work modal's
        // own read, so the two dialogs offer one family the same ticks.
        'family'      => $w ? (work_siblings_here([$id])[$id]['siblings'] ?? []) : [],
        // TODAY IN SYDNEY, for the Add form's date_added - see the note on
        // `list`. It is the queue's final tie-breaker and the browser's clock
        // has already got it wrong once.
        'today'        => date('Y-m-d'),
        'default_year' => board_default_year(),
        'rooms'        => array_map(static fn($r) => [
            'id' => (int)$r['id'], 'name' => $r['name'],
            'min_age_months' => $r['min_age_months'], 'max_age_months' => $r['max_age_months'],
        ], rooms_for_centre($centreId, board_default_year())),
    ], JSON_UNESCAPED_SLASHES);
    break;
}

// The whole organisation's queue, one row per CHILD rather than one per
// application - the "All centres" tab. Read-only by design: an offer needs a
// room, a room belongs to one centre, so offering happens on a centre tab where
// room_day_vacancies() can be checked. See _incl/waitlist-group.php.
//
// No sibling matching here. That is a per-centre tool (it needs Kidsoft's
// guardian index for the centre's enrolled children) and it acts on the queue
// order, which this view deliberately does not claim to hold.
case 'group': {
    // The group shape. Same guard, and the same reasoning, as board.php's
    // 'overview': a supervisor has no All centres tab to ask for this from.
    if (!user_all_centres()) api_fail('Choose a centre.');
    $g = waitlist_group_all(
        waitlist_age_bounds($_GET) + waitlist_show_opts() + waitlist_start_opts() + [
        'q'          => trim((string)($_GET['q'] ?? '')),
        'multi_only' => !empty($_GET['multi_only']),
    ]);
    echo json_encode([
        'ok'               => true,
        'families'         => $g['families'],
        'centres'          => $g['centres'],
        'pairs'            => $g['pairs'],
        'totals'           => $g['totals'],
        'statuses'         => WAITLIST_STATUS,
        'new_since'        => waitlist_new_since(),
        'new_days'         => WAITLIST_NEW_DAYS,
    ], JSON_UNESCAPED_SLASHES);
    break;
}

// Free days in a room for a year - drives the offer dialog so a day that is
// already full (or already promised) cannot be offered by accident.
case 'vacancies': {
    $roomId = (int)($_GET['room_id'] ?? 0);
    $year   = (int)($_GET['year'] ?? board_default_year());
    $room = room_get($roomId);
    if (!$room) api_fail('Room not found.');
    api_require_centre((int)$room['centre_id']);
    echo json_encode([
        'ok' => true, 'room' => $room['name'], 'year' => $year,
        'days' => room_day_vacancies($roomId, $year),
    ], JSON_UNESCAPED_SLASHES);
    break;
}

case 'save': {
    $id       = (int)($_POST['id'] ?? 0);
    $centreId = (int)($_POST['centre_id'] ?? 0);
    $first    = trim((string)($_POST['child_first_name'] ?? ''));
    $last     = trim((string)($_POST['child_last_name'] ?? ''));
    $dob      = trim((string)($_POST['child_dob'] ?? ''));
    $added    = trim((string)($_POST['date_added'] ?? ''));
    $days     = posted_days();
    $poa      = (int)($_POST['priority_of_access'] ?? 3);
    $gName    = trim((string)($_POST['guardian_name'] ?? ''))  ?: null;
    $gEmail   = trim((string)($_POST['guardian_email'] ?? '')) ?: null;
    $gPhone   = trim((string)($_POST['guardian_phone'] ?? '')) ?: null;
    $reqFrom  = null;   // filled from $optDates below, or from Kidsoft's copy

    // KIDSOFT'S COLUMNS ARE NOT TYPEABLE. On a row imported from
    // Waitlist/getWaitingList, what the family declared belongs to Kidsoft, so
    // anything the browser posts for those fields is discarded and the stored
    // value re-used - the same rule save_placement applies to the name and DOB
    // of a child linked to a Kidsoft id. Without this the form would happily
    // accept an edit that the next sync silently reverted, which is worse than
    // refusing it. The form disables these inputs, so a disabled input's absence
    // from the POST is the normal case here, not an error.
    $existing = $id ? waitlist_get($id) : null;
    // NO NEW APPLICATIONS ARE TYPED HERE (Broc, 6 Nov 2026). Every one comes
    // from Waitlist/getWaitingList; a hand-keyed row cannot be matched to the
    // family's Kidsoft application and shows as a second row once it arrives.
    // Refused here, not only by hiding the button.
    if (!$id) api_fail('Waitlist applications come from Kidsoft. Add the application in Kidsoft, then press Refresh.');
    $fromKidsoft = $existing && $existing['source'] === 'kidsoft';
    if ($fromKidsoft) {
        $first   = $existing['child_first_name'];
        $last    = $existing['child_last_name'];
        $dob     = (string)($existing['child_dob'] ?? '');
        $added   = (string)$existing['date_added'];
        $reqFrom = $existing['required_from'];
        $gName   = $existing['guardian_name'];
        $gEmail  = $existing['guardian_email'];
        $gPhone  = $existing['guardian_phone'];
        // Priority of access joined this list on 13 Aug 2026, when the importer
        // started REFRESHING it instead of seeding it on insert. Leaving it
        // typeable would have recreated exactly the failure this block exists to
        // prevent: an edit the form accepts and the next sync silently reverts.
        // A hand-keyed row (source != 'kidsoft') keeps the dropdown, because
        // there is no Kidsoft value to sync for one and it is the fallback path
        // for a family who rang before they applied.
        $poa     = (int)$existing['priority_of_access'];
        // Kidsoft owns the requested days only when it HAS some. One real
        // Terrigal application asks for no particular weekday, and locking its
        // toggles while still demanding at least one day would leave that row
        // permanently unsaveable - a validation error nobody could clear.
        if ($existing['days']) $days = $existing['days'];
    }

    api_require_centre($centreId);
    if ($first === '' || $last === '')   api_fail("Enter the child's first and last name.");
    if (!$days)                          api_fail('Choose at least one requested day.');
    if (!isset(POA_LABELS[$poa]))        api_fail('Unknown priority of access.');
    if ($dob !== '' && !date_create($dob))   api_fail('That date of birth is not a valid date.');
    if ($added !== '' && !date_create($added)) api_fail('That date added is not a valid date.');

    // The remaining optional dates go straight into DATE columns; without this a
    // bad value raises an uncaught mysqli exception (a bare 500) instead of a
    // message the user can act on.
    //
    // `tour_booked_at` IS NOT IN THIS LIST ANY MORE, AND NOT IN THE UPDATE
    // EITHER (10 Sep 2026). The tour lives on the work item, where it has a TIME
    // and an answer afterwards; this column was a second record of the same
    // appointment with nothing keeping the two in step, and it had already come
    // apart on the second family who ever had one - see work_for_waitlist().
    // The column is left in the table with the one value anybody ever typed
    // into it, and is simply no longer written: dropping it from the UPDATE as
    // well as from the form is the point, because a field the form stops posting
    // is a field the UPDATE quietly nulls.
    $optDates = [];
    foreach (['required_from' => 'Needed from',
              'orientation_at' => 'Orientation'] as $key => $label) {
        $v = trim((string)($_POST[$key] ?? ''));
        if ($v === '') { $optDates[$key] = null; continue; }
        if (!date_create($v)) api_fail('"' . $label . '" is not a valid date.');
        $optDates[$key] = substr($v, 0, 10);
    }
    // Kidsoft's required_from wins on an imported row, set above.
    if ($fromKidsoft) $optDates['required_from'] = $reqFrom;

    // date_added is the tie-breaker for the whole queue, so it defaults to today
    // rather than being silently left empty.
    $added = $added === '' ? date('Y-m-d') : substr($added, 0, 10);
    $dob   = $dob === '' ? null : substr($dob, 0, 10);

    $roomId = (int)($_POST['preferred_room_id'] ?? 0) ?: null;
    if ($roomId !== null && !room_get($roomId)) api_fail('Preferred room not found.');

    $forYear = (int)($_POST['for_year'] ?? 0) ?: null;
    if ($forYear !== null && ($forYear < 2000 || $forYear > 2100)) api_fail('That year looks wrong.');

    $fields = [
        $centreId, $first, $last, $dob,
        $gName, $gEmail, $gPhone,
        trim((string)($_POST['lead_source'] ?? ''))    ?: null,
        $added,
        $optDates['required_from'],
        $forYear, $roomId,
        !empty($_POST['is_reenrolment']) ? 1 : 0,
        !empty($_POST['is_staff_child']) ? 1 : 0,
        !empty($_POST['is_sibling']) ? 1 : 0,
        $poa,
        !empty($_POST['guide_provided']) ? 1 : 0,
        $optDates['orientation_at'],
        trim((string)($_POST['notes'] ?? '')) ?: null,
    ];

    $before = $existing;   // loaded above, before the Kidsoft-owned fields were resolved
    if (!$before) api_fail('Application not found.');
    dbx()->query(
        'UPDATE waitlist_applications SET centre_id = ?, child_first_name = ?, child_last_name = ?,
                child_dob = ?, guardian_name = ?, guardian_email = ?, guardian_phone = ?, lead_source = ?,
                date_added = ?, required_from = ?, for_year = ?, preferred_room_id = ?,
                is_reenrolment = ?, is_staff_child = ?, is_sibling = ?, priority_of_access = ?,
                guide_provided = ?, orientation_at = ?, notes = ?
         WHERE id = ?',
        array_merge($fields, [$id])
    );
    waitlist_set_days($id, $days);
    audit('waitlist.update', 'waitlist', $id, audit_diff($before, waitlist_get($id)));
    echo json_encode(['ok' => true, 'id' => $id]);
    break;
}

// REFRESH. Opening the screen already imports the queue - waitlist.php calls
// waitlist_import_on_view(), which reads Kidsoft live and is throttled to
// WAITLIST_IMPORT_VIEW_INTERVAL - and the nightly cron imports every centre, so
// a screen nobody opens is covered too. This action exists for the gap that
// throttle leaves: a PAM who archives an application in Kidsoft, or takes one
// over the phone, and wants our queue to agree NOW rather than within the
// quarter hour.
//
// It is NOT the freshness mechanism and must not become one. An earlier "Import
// from Kidsoft" button was removed on 11 Aug 2026 for the right reason - a
// screen that stays stale until a human presses something is asking staff to
// maintain what Kidsoft already knows - and nothing here reinstates that. The
// button is an override of the throttle, not the thing that keeps the queue true.
//
// It bypasses the throttle deliberately (waitlist_import_centre() directly, not
// waitlist_import_on_view()), because "I just changed something and want to see
// it" is exactly the request the throttle would otherwise refuse. Cost is a live
// read at 0.4s per centre, and KIDSOFT_SHARE_FRESH collapses two people pressing
// it together into one API call.
case 'refresh': {
    $centreId = (int)($_POST['centre_id'] ?? 0);

    // The group tab refreshes every centre it is showing. api_require_centre()
    // with $allowGroup settles who may ask for that; centres_visible() settles
    // which centres they get, so a supervisor can never refresh past their own.
    api_require_centre($centreId, true);
    $centres = $centreId
        ? [['id' => $centreId, 'name' => (string)(centre_get($centreId)['name'] ?? '')]]
        : centres_visible();

    $tot = ['added' => 0, 'updated' => 0, 'closed' => 0, 'reopened' => 0, 'archived' => 0];
    $failed = [];
    foreach ($centres as $c) {
        try {
            $r = waitlist_import_centre((int)$c['id'], ['refresh' => true]);
            foreach ($tot as $k => $_) $tot[$k] += (int)($r[$k] ?? 0);
        } catch (Throwable $e) {
            // Kidsoft being unreachable is a normal Tuesday. Yesterday's queue is
            // still the queue, so this reports which centre could not be reached
            // rather than failing the whole press.
            $failed[] = (string)$c['name'];
            error_log('waitlist refresh (' . (int)$c['id'] . '): ' . $e->getMessage());
        }
    }

    // SAY WHAT CHANGED, INCLUDING WHEN NOTHING DID. The commonest honest answer
    // is "nothing", and that is the answer the person pressing it wants - a
    // button that looks identical before and after gets pressed five more times.
    $bits = [];
    if ($tot['added'])    $bits[] = $tot['added'] . ' new';
    if ($tot['closed'])   $bits[] = $tot['closed'] . ' no longer waiting';
    if ($tot['reopened']) $bits[] = $tot['reopened'] . ' reactivated';
    if ($tot['archived']) $bits[] = $tot['archived'] . ' archived in Kidsoft';
    $msg = $bits ? ucfirst(implode(', ', $bits)) . '.' : 'No change. The queue matches Kidsoft.';
    if ($failed) {
        $msg .= ' Kidsoft would not answer for ' . implode(', ', $failed) . '.';
    }

    echo json_encode([
        'ok'      => true,
        'message' => $msg,
        // Only a changed queue needs the table redrawn. Nothing to redraw is the
        // common case and a needless reload would throw away the user's filters.
        // 'updated' is deliberately NOT in this test: every open row counts as
        // updated on every successful import, so including it would make
        // 'changed' permanently true and reload the table on every press.
        'changed' => (bool)($tot['added'] + $tot['closed'] + $tot['reopened'] + $tot['archived']),
        'counts'  => $tot,
    ]);
    break;
}

// There is no mark_siblings action either, and for the same reason (11 Aug 2026).
// It ticked `is_sibling` on every detected sibling so that priority_rank() would
// pick them up; the ranking reads the detection directly now, so the button had
// nothing left to do. It had never once been pressed. See _incl/siblings.php.

case 'set_status': {
    $id     = (int)($_POST['id'] ?? 0);
    $status = (string)($_POST['status'] ?? '');
    $w = waitlist_get($id);
    if (!$w)                              api_fail('Application not found.');
    api_require_centre((int)$w['centre_id']);
    if (!isset(WAITLIST_STATUS[$status])) api_fail('Unknown status.');

    dbx()->query('UPDATE waitlist_applications SET status = ? WHERE id = ?', [$status, $id]);
    audit('waitlist.status', 'waitlist', $id, ['from' => $w['status'], 'to' => $status]);
    echo json_encode(['ok' => true]);
    break;
}

case 'delete': {
    $id = (int)($_POST['id'] ?? 0);
    $w = waitlist_get($id);
    if (!$w) api_fail('Application not found.');
    api_require_centre((int)$w['centre_id']);
    dbx()->query('DELETE FROM waitlist_applications WHERE id = ?', [$id], 'i');
    audit('waitlist.delete', 'waitlist', $id, [
        'name' => trim($w['child_first_name'] . ' ' . $w['child_last_name']),
    ]);
    echo json_encode(['ok' => true]);
    break;
}

// --- Offers ----------------------------------------------------------------

case 'make_offer': {
    // RETIRED 15 Oct 2026, AND REFUSED RATHER THAN DELETED. Offers are sent in
    // Kidsoft - that is where the family's offer email comes from - and this
    // app now reads them (KIDSOFT_OPEN_OFFER in board.php): Kidsoft reports the
    // room, the days and the deadline of every one. Making the offer here as
    // well was the same act typed twice, and the copy drifted: 0 of the 23
    // offers open in Kidsoft that morning had been made here, and 12 of the 13
    // ever made here had lapsed on this app's own 14-day clock while Kidsoft
    // carried the family on to enrolment.
    //
    // No screen posts this any more. The body below is kept, unreachable, so
    // what it checked (vacancies under a lock, Do Not Enrol, the expiry rules)
    // is still readable. respond_offer, withdraw_offer and extend_offer stay
    // live for the offers made before it was retired.
    api_fail('Offers are sent in Kidsoft now. This screen shows the offer once Kidsoft has it.');

    $waitlistId = (int)($_POST['waitlist_id'] ?? 0);
    $roomId     = (int)($_POST['room_id'] ?? 0);
    $year       = (int)($_POST['year'] ?? 0);
    $days       = posted_days();
    $startDate  = trim((string)($_POST['start_date'] ?? ''));
    $expiresAt  = trim((string)($_POST['expires_at'] ?? ''));

    $w = waitlist_get($waitlistId);
    $room = room_get($roomId);
    if (!$w)    api_fail('Application not found.');
    if (!$room) api_fail('Room not found.');
    // The application's centre. The room is checked against it a few lines down
    // for its own reasons, so guarding one guards both.
    api_require_centre((int)$w['centre_id']);
    if (!$days) api_fail('Choose at least one day to offer.');
    if ($year < 2000 || $year > 2100) api_fail('That year looks wrong.');
    // THE DATE IS VALIDATED WITH THE OTHER CHEAP INPUT, before room_day_vacancies()
    // does any work. An empty field takes the standing 14 days; anything typed
    // goes through offer_expiry_normalise(), shared with extend_offer, which
    // refuses a date that has already passed and runs a bare date to the END of
    // its day. See its header for what the missing check cost - 3 of the first 7
    // offers were written already dead.
    if ($expiresAt === '') {
        $expiresAt = date_create('now', new DateTimeZone('Australia/Sydney'))
            ->modify('+' . OFFER_DEFAULT_DAYS . ' days')->format('Y-m-d H:i:s');
    } else {
        $expiresAt = offer_expiry_normalise($expiresAt, $expiryError);
        if ($expiresAt === null) api_fail($expiryError);
    }
    // The offer stores the application's centre_id but the room's room_id. If
    // they disagree, the resulting placement is counted in one centre and shown
    // in another (board_load selects by room, the roll-over selects by centre).
    if ((int)$room['centre_id'] !== (int)$w['centre_id']) {
        api_fail('That room belongs to a different centre.');
    }

    $open = dbx()->query(
        "SELECT id FROM offers WHERE waitlist_id = ? AND status = 'pending'", [$waitlistId], 'i'
    )->fetch();
    if ($open) api_fail('This family already has an offer open. Withdraw it before making another.');

    // DO NOT ENROL. Refused until whoever is offering confirms it has been
    // checked - a confirmation, not a block, because the match can be wrong (see
    // _incl/do-not-enrol.php). Read fresh here rather than trusted from the
    // form, so a screen loaded before the tag was added still cannot skip it.
    $dne = dne_match($w);
    if ($dne && ($_POST['dne_checked'] ?? '') !== '1') {
        api_fail('Do Not Enrol: ' . $dne['text'] . ' Confirm this has been checked before offering.');
    }

    // An offer may legitimately cover FEWER days than were requested (partial
    // offers are normal), but never days they never asked for.
    $extra = array_diff($days, $w['days']);
    if ($extra) {
        api_fail('They did not ask for ' . implode(', ', array_map(fn($d) => WEEKDAYS[$d], $extra)) . '.');
    }

    // Cheap pre-check so the common failure gives a clear message before we take
    // any locks. The authoritative check is repeated inside the transaction.
    $vac = room_day_vacancies($roomId, $year);
    $full = [];
    foreach ($days as $d) {
        if ($vac[$d]['capacity'] === null) {
            api_fail('No capacity is recorded for ' . $room['name'] . ' in ' . $year . '. Set it before offering.');
        }
        if ($vac[$d]['free'] < 1) $full[] = WEEKDAYS[$d];
    }
    if ($full) api_fail('No place free on ' . implode(', ', $full) . ' in ' . $room['name'] . ' for ' . $year . '.');

    $offerId = 0;
    $taken = [];
    dbx()->transaction(function ($db) use (
        $waitlistId, $w, $roomId, $year, $days, $startDate, $expiresAt, $user, &$offerId, &$taken
    ) {
        // Re-check under a lock. The pre-check above races: two staff offering the
        // last Tuesday place at the same moment would both see it free and both
        // offers would be created, double-promising one seat. Locking the room's
        // capacity row serialises offers for this room+year, so the second one
        // sees the first's rows and backs out.
        $db->query(
            'SELECT capacity FROM room_year_capacity WHERE room_id = ? AND year = ? FOR UPDATE',
            [$roomId, $year]
        );
        // $fresh, or this re-read is answered out of the memo the pre-check above
        // filled BEFORE the lock was taken, and the whole transaction is theatre
        // - which is what it was until 13 Aug 2026. See room_day_vacancies().
        $vac = room_day_vacancies($roomId, $year, true);
        foreach ($days as $d) {
            if ($vac[$d]['capacity'] === null || $vac[$d]['free'] < 1) $taken[] = WEEKDAYS[$d];
        }
        if ($taken) return;

        $db->query(
            'INSERT INTO offers (waitlist_id, centre_id, room_id, year, start_date, expires_at, offered_by)
             VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$waitlistId, $w['centre_id'], $roomId, $year,
             $startDate !== '' ? substr($startDate, 0, 10) : null, $expiresAt, $user['email'] ?? null]
        );
        $offerId = $db->insertId();
        foreach ($days as $d) {
            $db->query('INSERT INTO offer_days (offer_id, weekday) VALUES (?, ?)', [$offerId, $d]);
        }
        $db->query("UPDATE waitlist_applications SET status = 'offered' WHERE id = ?", [$waitlistId]);
    });

    if ($taken) {
        api_fail('Someone just took the last place on ' . implode(', ', $taken)
               . ' in ' . $room['name'] . '. Reload and try again.');
    }

    // Reflect the offer onto the work item, if anyone is tracking this family.
    // The offer is made HERE, under the lock above; the pipeline only points at
    // it. See work_link_offer().
    work_link_offer($waitlistId, $offerId);

    audit('offer.create', 'offer', $offerId, [
        'waitlist_id' => $waitlistId, 'room_id' => $roomId, 'year' => $year,
        'days' => $days, 'expires_at' => $expiresAt,
        // Who confirmed a Do Not Enrol match, and what it matched, on the row.
        'dne_checked' => $dne ? $dne['text'] : null,
    ]);
    echo json_encode(['ok' => true, 'id' => $offerId, 'expires_at' => $expiresAt]);
    break;
}

// Record the family's answer. Acceptance is PER DAY: a family may take some days
// and stay waitlisted for the rest, which is why accepted_days is separate from
// the offer's own status.
case 'respond_offer': {
    $offerId  = (int)($_POST['id'] ?? 0);
    $accepted = posted_days('accepted_days');
    $note     = trim((string)($_POST['response_note'] ?? ''));

    $o = offer_get($offerId);
    if (!$o) api_fail('Offer not found.');
    api_require_centre((int)$o['centre_id']);
    if ($o['status'] !== 'pending') api_fail('That offer has already been answered.');

    $offeredDays = array_map(static fn($d) => (int)$d['weekday'], $o['days']);
    $extra = array_diff($accepted, $offeredDays);
    if ($extra) api_fail('Those days were not part of the offer.');

    $w = waitlist_get((int)$o['waitlist_id']);
    $declined = !$accepted;

    // Two different questions, and conflating them loses families:
    //   - the OFFER is partially accepted if they took fewer days than offered;
    //   - the APPLICATION is only finished if they took every day they ASKED for.
    // A partial offer (Mon+Tue of a requested Mon/Tue/Wed) that is fully accepted
    // still leaves them wanting Wednesday, so it must not be marked 'placed' --
    // the default queue view hides 'placed', and they would silently disappear.
    $offerPartial = $accepted && count($accepted) < count($offeredDays);
    $stillWants   = array_diff($w['days'], $accepted);

    $offerStatus = $declined ? 'declined' : ($offerPartial ? 'partially_accepted' : 'accepted');
    // They keep their ORIGINAL date_added either way, so an offer never costs a
    // family their place in the queue.
    $wlStatus = $declined ? 'waitlisted' : ($stillWants ? 'partially_placed' : 'placed');

    $placementId = 0;
    $raced = false;
    dbx()->transaction(function ($db) use (
        $o, $offerId, $accepted, $offeredDays, $offerStatus, $wlStatus, $note, $w,
        $stillWants, &$placementId, &$raced
    ) {
        // Claim the offer atomically. The status check above is outside the
        // transaction, so two concurrent submits (two tabs, or a retried request)
        // could otherwise both pass it and each create a placement from one offer.
        // Whoever flips it off 'pending' first wins; the loser does nothing.
        $db->query(
            "UPDATE offers SET status = ?, responded_at = NOW(), response_note = ?
             WHERE id = ? AND status = 'pending'",
            [$offerStatus, $note ?: null, $offerId]
        );
        if ($db->affectedRows() === 0) { $raced = true; return; }
        foreach ($offeredDays as $d) {
            $db->query(
                'UPDATE offer_days SET accepted = ? WHERE offer_id = ? AND weekday = ?',
                [in_array($d, $accepted, true) ? 1 : 0, $offerId, $d]
            );
        }
        $db->query('UPDATE waitlist_applications SET status = ? WHERE id = ?', [$wlStatus, $o['waitlist_id']]);

        // An accepted day is a real placement - put the child on the board so
        // the plan and the queue cannot drift apart.
        if ($accepted) {
            $db->query(
                'INSERT INTO placements (centre_id, room_id, year, kidsoft_child_id, child_first_name,
                        child_last_name, child_dob, status, is_sibling, is_staff_child, source_offer_id)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [$o['centre_id'], $o['room_id'], $o['year'], $w['kidsoft_child_id'],
                 $w['child_first_name'], $w['child_last_name'], $w['child_dob'],
                 'waiting_paperwork', $w['is_sibling'], $w['is_staff_child'], $offerId]
            );
            $placementId = $db->insertId();
            foreach ($accepted as $d) {
                $db->query('INSERT INTO placement_days (placement_id, weekday) VALUES (?, ?)', [$placementId, $d]);
            }
        }

        // Keep only the days they still want on the waitlist.
        if ($accepted) {
            $db->query('DELETE FROM waitlist_days WHERE waitlist_id = ?', [$o['waitlist_id']]);
            foreach ($stillWants as $d) {
                $db->query('INSERT INTO waitlist_days (waitlist_id, weekday) VALUES (?, ?)', [$o['waitlist_id'], $d]);
            }
        }
    });

    if ($raced) api_fail('That offer was answered a moment ago. Reload to see the result.');

    // Move the work item to match what the family actually said.
    work_offer_answered((int)$o['waitlist_id'], $offerStatus);

    audit('offer.respond', 'offer', $offerId, [
        'status' => $offerStatus, 'accepted' => $accepted, 'placement_id' => $placementId ?: null,
    ]);

    $msg = $declined
        ? 'Offer declined. They stay on the waitlist with their original date added.'
        : ($stillWants
            ? 'Recorded. They are on the board for the days taken and remain waitlisted for '
              . implode(', ', array_map(static fn($d) => WEEKDAYS[$d], $stillWants)) . '.'
            : 'Offer accepted and added to the board as "Waiting for paperwork".');
    echo json_encode(['ok' => true, 'placement_id' => $placementId ?: null, 'message' => $msg]);
    break;
}

case 'withdraw_offer': {
    $offerId = (int)($_POST['id'] ?? 0);
    $o = offer_get($offerId);
    if (!$o) api_fail('Offer not found.');
    api_require_centre((int)$o['centre_id']);
    if ($o['status'] !== 'pending') api_fail('That offer is no longer open.');

    dbx()->transaction(function ($db) use ($offerId, $o) {
        $db->query("UPDATE offers SET status = 'withdrawn', responded_at = NOW() WHERE id = ?", [$offerId]);
        $db->query(
            "UPDATE waitlist_applications SET status = 'waitlisted' WHERE id = ? AND status = 'offered'",
            [$o['waitlist_id']]
        );
    });
    work_offer_answered((int)$o['waitlist_id'], 'withdrawn');
    audit('offer.withdraw', 'offer', $offerId, ['waitlist_id' => $o['waitlist_id']]);
    echo json_encode(['ok' => true]);
    break;
}

// Extend a standing offer's deadline - the "adjustable case by case" rule.
case 'extend_offer': {
    $offerId = (int)($_POST['id'] ?? 0);
    $until   = trim((string)($_POST['expires_at'] ?? ''));
    $o = offer_get($offerId);
    if (!$o)                          api_fail('Offer not found.');
    api_require_centre((int)$o['centre_id']);
    if ($o['status'] !== 'pending')   api_fail('That offer is no longer open.');

    // The same guard as create_offer, and this door needed it more: the action
    // is called EXTEND, so a past date here does not just fail to lengthen the
    // offer, it shortens a live one to death on the next page load.
    $new = offer_expiry_normalise($until, $expiryError);
    if ($new === null) api_fail($expiryError);
    dbx()->query('UPDATE offers SET expires_at = ? WHERE id = ?', [$new, $offerId]);
    audit('offer.extend', 'offer', $offerId, ['from' => $o['expires_at'], 'to' => $new]);
    echo json_encode(['ok' => true, 'expires_at' => $new]);
    break;
}

default:
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'unknown action']);
}
