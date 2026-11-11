<?php
// Placement board CRUD.
//
// Readable failures go through api_fail() -> HTTP 200 with ok:false, because
// nginx discards the body of a real 4xx under /api/. See _incl/api.php.

declare(strict_types=1);

require_once __DIR__ . '/../_incl/auth.php';
require_once __DIR__ . '/../_incl/csrf.php';
require_once __DIR__ . '/../_incl/api.php';
require_once __DIR__ . '/../_incl/board.php';
require_once __DIR__ . '/../_incl/audit.php';
require_once __DIR__ . '/../_incl/identity.php';
require_once __DIR__ . '/../_incl/internal-requests.php';
require_once __DIR__ . '/../_incl/day-holds.php';
require_once __DIR__ . '/../_incl/support-plan-steps.php'; // plan_settling_warnings()

$user = auth_require_api();
$action = $_REQUEST['action'] ?? '';

$reads = ['load', 'overview', 'children'];   // GET, no CSRF; everything else mutates
if (!in_array($action, $reads, true)) {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { http_response_code(405); exit; }
    csrf_require();
}

/** Weekdays posted as days[] - deduplicated and bounded to Mon-Fri. */
function posted_days(): array {
    $raw = $_POST['days'] ?? [];
    if (!is_array($raw)) $raw = [$raw];
    $d = array_values(array_unique(array_filter(
        array_map('intval', $raw), static fn($w) => $w >= 1 && $w <= 5
    )));
    sort($d);
    return $d;
}

switch ($action) {

// Whole-organisation overview: every centre's fill for a year, no centre filter.
case 'overview': {
    // THE GROUP SHAPE, so it needs a user who has a group. A supervisor is never
    // offered the "All centres" tab that asks for this, so reaching it means a
    // hand-made request or a tab left open through a role change.
    if (!user_all_centres()) api_fail('Choose a centre.');
    $year = (int)($_GET['year'] ?? board_default_year());
    // A CHOSEN DATE DECIDES THE YEAR - see board_from() and board_from_request().
    $from = board_from_request($_GET['from'] ?? null);
    if ($from !== null) $year = (int)substr($from, 0, 4);
    if ($year < 2000 || $year > 2100) api_fail('That year looks wrong.');

    $ov = board_overview($year, $from);
    echo json_encode([
        'ok'       => true,
        'year'     => $year,
        'from'        => $ov['from'],
        'from_chosen' => $from !== null,
        'years'    => year_list([$year]),
        'centres'  => $ov['centres'],
        'totals'   => $ov['totals'],
    ], JSON_UNESCAPED_SLASHES);
    break;
}

case 'load': {
    $centreId = (int)($_GET['centre_id'] ?? 0);
    $year     = (int)($_GET['year'] ?? board_default_year());
    api_require_centre($centreId);
    if ($year < 2000 || $year > 2100) api_fail('That year looks wrong.');

    $board = board_load($centreId, $year);
    echo json_encode([
        'ok'       => true,
        'year'     => $year,
        'years'    => year_list([$year]),
        'centre'   => ['id' => (int)$board['centre']['id'], 'name' => $board['centre']['name']],
        'rooms'    => $board['rooms'],
        'totals'   => $board['totals'],
        'statuses' => PLACEMENT_STATUS,
    ], JSON_UNESCAPED_SLASHES);
    break;
}

// Kidsoft's ACTIVE children at a centre, for the placement editor's picker. This
// is how a child gets onto the board now: picked, not typed.
case 'children': {
    $centreId = (int)($_GET['centre_id'] ?? 0);
    api_require_centre($centreId);
    try {
        $rows = kidsoft_children_search($centreId, (string)($_GET['q'] ?? ''), 25);
    } catch (RuntimeException $e) {
        api_fail('Could not reach Kidsoft: ' . $e->getMessage());
    }
    echo json_encode(['ok' => true, 'children' => $rows], JSON_UNESCAPED_SLASHES);
    break;
}

case 'save_placement': {
    $id      = (int)($_POST['id'] ?? 0);
    $roomId  = (int)($_POST['room_id'] ?? 0);
    $year    = (int)($_POST['year'] ?? 0);
    $childId = trim((string)($_POST['kidsoft_child_id'] ?? ''));
    $first   = trim((string)($_POST['child_first_name'] ?? ''));
    $last    = trim((string)($_POST['child_last_name'] ?? ''));
    $dob     = trim((string)($_POST['child_dob'] ?? ''));
    $status  = (string)($_POST['status'] ?? 'unset');
    $notes   = trim((string)($_POST['notes'] ?? ''));
    $days    = posted_days();

    $room = room_get($roomId);
    if (!$room)                                api_fail('Room not found.');
    // THE ROOM DECIDES THE CENTRE, and the centre decides who may write here.
    // There is no centre_id in this POST to check - it arrives as a room id --
    // so the guard has to run after the lookup rather than before it.
    api_require_centre((int)$room['centre_id']);
    if ($year < 2000 || $year > 2100)          api_fail('That year looks wrong.');
    if (!isset(PLACEMENT_STATUS[$status]))     api_fail('Unknown status.');

    // WHICH DAYS A CHILD ATTENDS IS KIDSOFT'S ANSWER, NOT OURS (6 Aug 2026).
    // The board is derived from Kidsoft bookings now, so for a linked child this
    // endpoint saves ANNOTATIONS ONLY - status, the five flags, a note. Days and
    // room come back from Kidsoft on the next read regardless of what is posted,
    // and silently swallowing an edit is worse than refusing it, so posted days
    // are ignored explicitly and the caller is told. A PROVISIONAL child (one
    // Kidsoft has never heard of) is still fully ours to edit, because nothing
    // else in the world knows about them.
    $linked = $childId !== '';
    $ignoredDays = $ignoredStatus = false;

    $syncedAt = null;
    if ($linked) {
        $centre = centre_get((int)$room['centre_id']);
        try {
            $known = kidsoft_children($centre['kidsoft_service_id']);
        } catch (RuntimeException $e) {
            api_fail('Could not reach Kidsoft to confirm that child: ' . $e->getMessage());
        }
        $c = $known[$childId] ?? null;
        if (!$c) api_fail('That child is not in Kidsoft for this centre.');

        // The name is a CACHE of what Kidsoft holds, never typed. Typed names are
        // what produced every piece of drift this app ever had to reconcile.
        $first = name_case((string)($c['FirstName'] ?? ''));
        $last  = name_case((string)($c['LastName'] ?? ''));
        $dob   = !empty($c['DOB']) ? substr((string)$c['DOB'], 0, 10) : '';
        $syncedAt = date('Y-m-d H:i:s');
        if ($days) $ignoredDays = true;
        $days = [];

        // One annotation per (child, room, year). Find it by identity rather than
        // trusting a posted id, so a second annotation can never be created for a
        // child who already has one.
        $existing = dbx()->query(
            'SELECT id, status FROM placements WHERE kidsoft_child_id = ? AND room_id = ? AND year = ?',
            [$childId, $roomId, $year]
        )->fetch();
        if ($existing) $id = (int)$existing['id'];

        // STATUS IS KIDSOFT'S ANSWER TOO, and refusing it matters more than
        // refusing days. board.php drops a `waitlist` or `no_position` row from
        // the day's roll, so marking a linked child either would have taken them
        // out of the occupancy count while Kidsoft still had them booked - the
        // room would read one place emptier than it is, on a figure the whole app
        // is built to get right. The UI stopped offering the control; this is the
        // half that holds, because the control being hidden is a display decision
        // and a posted field is the door.
        $was = $existing ? (string)$existing['status'] : 'confirmed';
        if ($status !== $was) $ignoredStatus = true;
        $status = $was;
    } else {
        if (!$days) api_fail('Choose at least one day.');
    }

    if ($first === '' || $last === '') {
        api_fail($linked
            ? 'That Kidsoft record has no usable name.'
            : 'Pick the child from Kidsoft, or type a first and last name if they are not in Kidsoft yet.');
    }
    if ($dob !== '' && !date_create($dob))     api_fail('That date of birth is not a valid date.');
    $dob = $dob === '' ? null : substr($dob, 0, 10);

    $flags = [
        'is_sibling'          => !empty($_POST['is_sibling']) ? 1 : 0,
        'is_staff_child'      => !empty($_POST['is_staff_child']) ? 1 : 0,
        'needs_two_educators' => !empty($_POST['needs_two_educators']) ? 1 : 0,
        'has_behaviour_plan'  => !empty($_POST['has_behaviour_plan']) ? 1 : 0,
        'has_individual_plan' => !empty($_POST['has_individual_plan']) ? 1 : 0,
    ];

    // Warn, do not block, when a day would exceed capacity. Staff routinely
    // pencil someone in while a departure is being confirmed, and the spreadsheet
    // never stopped them. Occupancy is read from the BOARD (i.e. from Kidsoft),
    // not by counting placements rows - those are annotations now and counting
    // them would report almost every room as empty.
    $warnings = [];
    if ($days && !in_array($status, ['waitlist', 'no_position'], true)) {
        $board = board_load((int)$room['centre_id'], $year);
        $cells = [];
        foreach ($board['rooms'] as $r) if ((int)$r['id'] === $roomId) $cells = $r['cells'];
        if (!$cells || !array_filter(array_map(static fn($c) => $c['capacity'], $cells),
                                     static fn($v) => $v !== null)) {
            $warnings[] = 'No capacity is recorded for ' . $room['name'] . ' in ' . $year
                        . ', so occupancy cannot be checked.';
        } else {
            foreach ($days as $wd) {
                $capacity = $cells[$wd]['capacity'] ?? null;
                if ($capacity === null) {
                    $warnings[] = 'No capacity recorded for ' . $room['name'] . ' on '
                                . WEEKDAYS[$wd] . ' ' . $year . '.';
                    continue;
                }
                $occupied = (int)$cells[$wd]['occupied'];
                if ($occupied + 1 > $capacity) {
                    $warnings[] = WEEKDAYS[$wd] . ' is now over capacity ('
                                . ($occupied + 1) . '/' . $capacity . ').';
                }
            }
        }
    }

    // THE SETTLING-IN RULE, and it fires only as the flag GOES ON.
    // "Educators will not begin the Behaviour Support Process (BSP) within the
    // first 4 weeks of the year or when a child changes rooms" - Emily Kite's
    // process document, 17 Sep 2026. It WARNS rather than blocks, like the
    // capacity check above it, and it is checked here because ticking the flag is
    // the only moment this app learns that a plan is starting.
    //
    // The twelve-month booking read behind it is why this is gated on the tick
    // rather than run on the Support plans list. See plan_settling_warnings().
    $existing = $id ? placement_get($id) : null;
    if ($flags['has_behaviour_plan'] && !(int)($existing['has_behaviour_plan'] ?? 0)) {
        foreach (plan_settling_warnings([
            'centre_id'        => (int)$room['centre_id'],
            'year'             => $year,
            'kidsoft_child_id' => $linked ? $childId : ($existing['kidsoft_child_id'] ?? ''),
            'kidsoft_room_id'  => $room['kidsoft_room_id'] ?? '',
            'child_name'       => trim($first . ' ' . $last),
            'room_name'        => $room['name'] ?? '',
        ]) as $w) $warnings[] = $w;
    }

    if ($id) {
        $before = $existing;
        if (!$before) api_fail('Placement not found.');
        if ($linked && $before['kidsoft_child_id'] && $before['kidsoft_child_id'] !== $childId) {
            api_fail('That row is already linked to a different Kidsoft child.');
        }
        $newChildId = $linked ? $childId : $before['kidsoft_child_id'];

        dbx()->query(
            'UPDATE placements SET room_id = ?, centre_id = (SELECT centre_id FROM rooms WHERE id = ?),
                    year = ?, kidsoft_child_id = ?, child_first_name = ?, child_last_name = ?,
                    child_dob = ?, identity_synced_at = ?, status = ?, notes = ?,
                    is_sibling = ?, is_staff_child = ?,
                    needs_two_educators = ?, has_behaviour_plan = ?,
                    has_individual_plan = ?
             WHERE id = ?',
            [$roomId, $roomId, $year, $newChildId, $first, $last, $dob, $syncedAt, $status, $notes ?: null,
             $flags['is_sibling'], $flags['is_staff_child'],
             $flags['needs_two_educators'], $flags['has_behaviour_plan'],
             $flags['has_individual_plan'], $id]
        );
        // A linked row carries no days of its own - Kidsoft supplies them.
        if (!$linked) placement_set_days($id, $days);
        audit('placement.update', 'placement', $id, audit_diff($before, placement_get($id)));
    } else {
        dbx()->query(
            'INSERT INTO placements (centre_id, room_id, year, kidsoft_child_id, child_first_name,
                    child_last_name, child_dob, identity_synced_at, status, notes,
                    is_sibling, is_staff_child, needs_two_educators, has_behaviour_plan,
                    has_individual_plan)
             VALUES ((SELECT centre_id FROM rooms WHERE id = ?), ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$roomId, $roomId, $year, $childId ?: null, $first, $last, $dob, $syncedAt,
             $status, $notes ?: null,
             $flags['is_sibling'], $flags['is_staff_child'],
             $flags['needs_two_educators'], $flags['has_behaviour_plan'],
             $flags['has_individual_plan']]
        );
        $id = dbx()->insertId();
        if (!$linked) placement_set_days($id, $days);
        audit($linked ? 'placement.annotate' : 'placement.create', 'placement', $id, [
            'name' => "$first $last", 'kidsoft_child_id' => $childId ?: null,
            'room_id' => $roomId, 'year' => $year, 'days' => $days,
        ]);
    }
    if ($ignoredDays || $ignoredStatus) {
        $warnings[] = 'Days, room and status come from Kidsoft for a child who is enrolled '
                    . 'there, so they were not changed. The flags and the note were saved.';
    }
    echo json_encode(['ok' => true, 'id' => $id, 'linked' => $linked, 'warnings' => $warnings]);
    break;
}

case 'toggle_day': {
    $id = (int)($_POST['id'] ?? 0);
    $wd = (int)($_POST['weekday'] ?? 0);
    $p = placement_get($id);
    if (!$p)                     api_fail('Placement not found.');
    api_require_centre((int)$p['centre_id']);
    if ($wd < 1 || $wd > 5)      api_fail('Not a weekday.');
    // Kidsoft owns a linked child's days - toggling one here would be undone by
    // the next read, which is the silent no-op save_placement now refuses too.
    if ($p['kidsoft_child_id']) {
        api_fail('This child is enrolled in Kidsoft, so their days come from there. Change the booking in Kidsoft.');
    }

    $days = $p['days'];
    $on = in_array($wd, $days, true);
    $days = $on ? array_values(array_diff($days, [$wd])) : array_merge($days, [$wd]);
    if (!$days) api_fail('A placement needs at least one day. Remove it instead.');

    placement_set_days($id, $days);
    audit('placement.days', 'placement', $id, ['weekday' => $wd, 'now' => $on ? 'off' : 'on']);
    echo json_encode(['ok' => true, 'days' => $days]);
    break;
}

case 'set_status': {
    $id     = (int)($_POST['id'] ?? 0);
    $status = (string)($_POST['status'] ?? '');
    $p = placement_get($id);
    if (!$p)                               api_fail('Placement not found.');
    api_require_centre((int)$p['centre_id']);
    if (!isset(PLACEMENT_STATUS[$status])) api_fail('Unknown status.');

    dbx()->query('UPDATE placements SET status = ? WHERE id = ?', [$status, $id]);
    audit('placement.status', 'placement', $id, ['from' => $p['status'], 'to' => $status]);
    echo json_encode(['ok' => true]);
    break;
}

// ---------------------------------------------------------------------------
// The internal day waitlist. A parent rang and asked for a weekday that is not
// free yet; Kidsoft has nowhere to put that. See _incl/internal-requests.php.
// ---------------------------------------------------------------------------

case 'wish_save': {
    $roomId = (int)($_POST['room_id'] ?? 0);
    $year   = (int)($_POST['year'] ?? 0);
    $ksId   = trim((string)($_POST['kidsoft_child_id'] ?? ''));
    $placeId = (int)($_POST['placement_id'] ?? 0);

    $room = room_get($roomId);
    if (!$room) api_fail('Room not found.');
    api_require_centre((int)$room['centre_id']);

    // WHO THIS IS FOR IS RESOLVED SERVER-SIDE, from the board, never taken from
    // the browser - the recompute-server-side rule. The name posted alongside is
    // only ever a label; taking it at face value would let a wish be recorded
    // against a child who is not in the room at all.
    $board = board_load((int)$room['centre_id'], $year);
    $found = null;
    foreach ($board['rooms'] as $r) {
        if ((int)$r['id'] !== $roomId) continue;
        foreach ($r['placements'] as $p) {
            $matches = $ksId !== ''
                ? ($p['kidsoft_child_id'] === $ksId)
                : ($placeId > 0 && (int)$p['id'] === $placeId);
            if ($matches) { $found = $p; break 2; }
        }
    }
    if (!$found) api_fail('That child is not on this room\'s board for ' . $year . '.');

    $days = posted_days();
    // A DAY THEY ALREADY ATTEND IS NOT A WISH. Silently storing one would put a
    // request on a cell the child is already sitting in, and the cell would then
    // report demand for a place its own occupant holds.
    $clash = array_values(array_intersect($days, $found['days']));
    $days  = array_values(array_diff($days, $found['days']));
    if (!$days) {
        api_fail($clash
            ? $found['child_first_name'] . ' already attends on ' . implode(', ',
                array_map(static fn($w) => WEEKDAYS[$w], $clash)) . '.'
            : 'Pick at least one day they are waiting for.');
    }

    // AND THE MIRROR OF THE TEST hold_save MAKES. A day the centre is HOLDING for
    // this family is already off sale; recording them as waiting for it would put
    // demand on a cell whose place is theirs, and light it green as grantable.
    $heldDays = array_column(day_holds_for_child(
        $found['kidsoft_child_id'], $found['id'], $roomId, $year), 'weekday');
    $bothWays = array_values(array_intersect($days, $heldDays));
    if ($bothWays) {
        api_fail(trim($found['child_first_name'] . ' ' . $found['child_last_name']) . ' already has '
            . implode(', ', array_map(static fn($w) => WEEKDAYS[$w], $bothWays))
            . ' held for them, so the place is not one they are waiting for.');
    }

    try {
        $res = internal_request_save([
            'centre_id'        => (int)$room['centre_id'],
            'room_id'          => $roomId,
            'year'             => $year,
            'kidsoft_child_id' => $found['kidsoft_child_id'],
            'placement_id'     => $found['id'],
            'child_name'       => trim($found['child_first_name'] . ' ' . $found['child_last_name']),
            'days'             => $days,
            'note'             => $_POST['note'] ?? '',
            'requested_on'     => $_POST['requested_on'] ?? '',
        ]);
    } catch (InvalidArgumentException $e) {
        api_fail($e->getMessage());
    }

    echo json_encode(['ok' => true, 'id' => $res['id'], 'created' => $res['created'],
                      'days' => $days,
                      // Said back, because a day quietly dropped is a day the
                      // team believes they have recorded.
                      'skipped' => array_map(static fn($w) => WEEKDAYS[$w], $clash)]);
    break;
}

case 'wish_close': {
    $id     = (int)($_POST['id'] ?? 0);
    $status = (string)($_POST['status'] ?? 'withdrawn');
    // The request carries its own centre. Looked up here rather than inside
    // internal_request_close(), which is also called by the nightly close sweep
    // - that runs as no user at all, and a scope check there would refuse
    // everything.
    $wish = internal_request_get($id);
    if (!$wish) api_fail('That request is no longer there.');
    api_require_centre((int)$wish['centre_id']);
    try {
        if (!internal_request_close($id, $status, trim((string)($_POST['note'] ?? '')))) {
            api_fail('That request is not open.');
        }
    } catch (InvalidArgumentException $e) {
        api_fail($e->getMessage());
    }
    echo json_encode(['ok' => true]);
    break;
}

// ---------------------------------------------------------------------------
// HELD DAYS - the opposite fact to a wish, recorded from the same box on the
// room roster. A place-day kept for a child who is not booked on it, most often
// while a Behaviour Support Plan is worked through. See _incl/day-holds.php.
// ---------------------------------------------------------------------------

case 'hold_save': {
    $roomId  = (int)($_POST['room_id'] ?? 0);
    $year    = (int)($_POST['year'] ?? 0);
    $ksId    = trim((string)($_POST['kidsoft_child_id'] ?? ''));
    $placeId = (int)($_POST['placement_id'] ?? 0);

    $room = room_get($roomId);
    if (!$room) api_fail('Room not found.');
    api_require_centre((int)$room['centre_id']);

    // WHO THIS IS FOR IS RESOLVED SERVER-SIDE, from the board, exactly as
    // wish_save does it: the posted name is a label, and holding a place for a
    // child who is not in the room is how a place goes missing with nobody able
    // to say whose it was.
    $board = board_load((int)$room['centre_id'], $year);
    $found = null;
    foreach ($board['rooms'] as $r) {
        if ((int)$r['id'] !== $roomId) continue;
        foreach ($r['placements'] as $p) {
            $matches = $ksId !== ''
                ? ($p['kidsoft_child_id'] === $ksId)
                : ($placeId > 0 && (int)$p['id'] === $placeId);
            if ($matches) { $found = $p; break 2; }
        }
    }
    // A CHILD REMOVED FROM CARE HAS NO BOOKINGS AT ALL, so the derived board
    // cannot show them - and that is precisely when their place most needs
    // holding. "The child has been removed from care pending an improvement in
    // behaviour" (Broc, 10 Sep 2026) is the commoner shape of a support plan
    // than one dropped day, and until 15 Sep 2026 this endpoint answered it with
    // "that child is not on this room's board".
    //
    // The annotation row IS the record of such a child - it carries their flags
    // and survives whatever Kidsoft holds - so it is the fallback, and only ever
    // the fallback: the board wins whenever it can show them, so a booked child
    // is still resolved against reality rather than against our own table.
    //
    // STILL RESOLVED SERVER-SIDE, and still keyed on room AND year, so a posted
    // id cannot reach a row in another room or another year.
    if (!$found) {
        $ann = $ksId !== ''
            ? dbx()->query('SELECT * FROM placements WHERE kidsoft_child_id = ? AND room_id = ? AND year = ?',
                           [$ksId, $roomId, $year], 'sii')->fetch()
            : ($placeId > 0
                ? dbx()->query('SELECT * FROM placements WHERE id = ? AND room_id = ? AND year = ?',
                               [$placeId, $roomId, $year], 'iii')->fetch()
                : null);
        if ($ann) {
            $found = [
                'id'               => (int)$ann['id'],
                'kidsoft_child_id' => $ann['kidsoft_child_id'],
                'child_first_name' => (string)$ann['child_first_name'],
                'child_last_name'  => (string)$ann['child_last_name'],
                // NO BOOKINGS IS THE WHOLE POINT, so nothing can clash and every
                // weekday is holdable. A linked annotation row carries no days of
                // its own either - Kidsoft owns those - so this is [] both ways.
                'days'             => [],
            ];
        }
    }
    if (!$found) api_fail('That child is not on this room\'s board for ' . $year . '.');

    $days = posted_days();
    // A DAY THEY ARE BOOKED ON IS NOT HELD - Kidsoft is already holding it, and
    // counting ours as well would take a second place off the same cell. Named
    // back rather than swallowed, the rule wish_save follows.
    $clash = array_values(array_intersect($days, $found['days']));
    $days  = array_values(array_diff($days, $found['days']));

    // ONE DAY CANNOT BE BOTH WAITED FOR AND HELD. They are opposite claims about
    // the same cell - one adds demand, the other takes the place off sale - so a
    // day carrying both would be counted in two directions at once. Refused with
    // the day named, rather than quietly overwriting a colleague's entry.
    $childKey = $found['kidsoft_child_id'] !== null
        ? 'k' . $found['kidsoft_child_id']
        : 'p' . (int)$found['id'];
    $wishing = internal_requests_by_child((int)$room['centre_id'], $year)[$roomId][$childKey]['days'] ?? [];
    $both = array_values(array_intersect($days, $wishing));
    if ($both) {
        api_fail(trim($found['child_first_name'] . ' ' . $found['child_last_name']) . ' is already '
            . 'recorded as waiting for ' . implode(', ', array_map(static fn($w) => WEEKDAYS[$w], $both))
            . '. Take that off the waiting list first, then hold the day.');
    }

    // An EMPTY strip is a release of everything, which is what unticking the last
    // day means and is the only way the dialog can say "none of it any more".
    // Refused only when there was nothing to release either, since a save that
    // changes nothing and reports success is how a team learns to distrust a form.
    if (!$days && !day_holds_for_child($found['kidsoft_child_id'], $found['id'], $roomId, $year)) {
        api_fail($clash
            ? trim($found['child_first_name'] . ' ' . $found['child_last_name'])
                . ' is booked on ' . implode(', ', array_map(static fn($w) => WEEKDAYS[$w], $clash))
                . ', so there is nothing to hold.'
            : 'Pick at least one day to hold.');
    }

    try {
        $res = day_hold_save([
            'centre_id'        => (int)$room['centre_id'],
            'room_id'          => $roomId,
            'year'             => $year,
            'kidsoft_child_id' => $found['kidsoft_child_id'],
            'placement_id'     => $found['id'],
            'child_name'       => trim($found['child_first_name'] . ' ' . $found['child_last_name']),
            'days'             => $days,
            // ONE DIALOG MEANS ONE SET OF FIELD NAMES, so the wish's `note` and
            // `requested_on` inputs are what arrive here whichever kind was
            // chosen - the form is serialised whole and the browser does not
            // rename a field because a radio moved. Mapped to this engine's own
            // words at the door rather than by giving the two kinds two sets of
            // inputs to keep in step. It was NOT mapped on the first attempt and
            // the failure was silent: the hold saved with no reason and a
            // held-from of today, which is exactly the shape of bug a save that
            // reports success is worst at showing. Caught by reading the card,
            // not by any test.
            'reason'           => $_POST['note'] ?? '',
            'held_from'        => $_POST['requested_on'] ?? '',
        ]);
    } catch (InvalidArgumentException $e) {
        api_fail($e->getMessage());
    }

    echo json_encode(['ok' => true, 'held' => $res['held'], 'released' => $res['released'],
                      'skipped' => array_map(static fn($w) => WEEKDAYS[$w], $clash)]);
    break;
}

case 'hold_release': {
    $id = (int)($_POST['id'] ?? 0);
    // The hold carries its own centre, and the guard runs off that row rather
    // than off anything posted - the rule every endpoint here follows when the
    // request names a record instead of a centre.
    $h = dbx()->query("SELECT * FROM day_holds WHERE id = ?", [$id], 'i')->fetch();
    if (!$h) api_fail('That hold is no longer there.');
    api_require_centre((int)$h['centre_id']);
    if (!day_hold_release($id, trim((string)($_POST['note'] ?? '')) ?: 'Released.')) {
        api_fail('That day is not being held any more.');
    }
    echo json_encode(['ok' => true]);
    break;
}

case 'delete_placement': {
    $id = (int)($_POST['id'] ?? 0);
    $p = placement_get($id);
    if (!$p) api_fail('Placement not found.');
    api_require_centre((int)$p['centre_id']);
    dbx()->query('DELETE FROM placements WHERE id = ?', [$id], 'i');
    audit('placement.delete', 'placement', $id, [
        'name' => trim($p['child_first_name'] . ' ' . $p['child_last_name']),
        'room_id' => $p['room_id'], 'year' => $p['year'],
    ]);
    echo json_encode(['ok' => true]);
    break;
}

default:
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'unknown action']);
}
