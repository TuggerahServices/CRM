<?php
// Rooms: read from Kidsoft, plus the two things Kidsoft has no field for.
//
// THE ONLY WRITEABLE THINGS HERE ARE SUB-QUOTAS AND NOTES (11 Aug 2026). A room's
// name, age band, existence and capacity all come from Kidsoft, so there is no
// action that edits them - save_room, archive_room, set_capacity,
// reset_capacity and the kidsoft_rooms mapping dropdown are gone, not merely
// hidden in the UI. Hiding a control while the endpoint still accepts the POST
// is not a restriction; it is a restriction somebody can skip.
//
// Readable failures (bad input, name clash) go through api_fail() -> HTTP 200
// with ok:false, because nginx discards the body of a real 4xx here. Guards the
// UI cannot legitimately trip keep a real status. See _incl/api.php.

declare(strict_types=1);

require_once __DIR__ . '/../_incl/auth.php';
require_once __DIR__ . '/../_incl/csrf.php';
require_once __DIR__ . '/../_incl/api.php';
require_once __DIR__ . '/../_incl/rooms.php';
require_once __DIR__ . '/../_incl/board.php';   // year_list() - the one stepper list
require_once __DIR__ . '/../_incl/audit.php';
require_once __DIR__ . '/../_incl/capacity.php';
require_once __DIR__ . '/../_incl/rooms-sync.php';
require_once __DIR__ . '/../_incl/centres-sync.php';

$user = auth_require_api();
$action = $_REQUEST['action'] ?? '';

// Everything except the read actions mutates.
if (!in_array($action, ['list', 'centres'], true)) {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { http_response_code(405); exit; }
    csrf_require();
}

/** Read an int field, or null when absent/blank (blank means "unbounded"). */
function opt_int(string $key): ?int {
    $v = $_POST[$key] ?? '';
    return ($v === '' || $v === null) ? null : (int)$v;
}

switch ($action) {

case 'list': {
    $centreId = (int)($_GET['centre_id'] ?? 0);
    $year     = (int)($_GET['year'] ?? 0);
    api_require_centre($centreId);
    if ($year < 2000 || $year > 2100) api_fail('That year looks wrong.');
    echo json_encode([
        'ok'    => true,
        'year'  => $year,
        // THE ROOMS STILL RENDER FOR A CENTRE THAT HAS NOT OPENED, and that is
        // deliberate - this is the screen you CHECK a centre's setup on in the
        // months before it trades: the age bands, the per-weekday places, the
        // sub-quotas. Placement, Tenure and Marketing all refuse the year; this
        // one answers it and says which year it is really for. What the reader
        // cannot know unaided is that the figures are Kidsoft's licensed
        // positions for a centre with no doors open yet, so that is a banner.
        'not_open' => centre_not_open_yet(centre_get($centreId), $year),
        // year_list(), not a second hand-rolled list: two screens offering
        // different years is how the year defaults drifted apart before.
        'years' => year_list([$year]),
        'rooms' => array_map(static function (array $r) use ($year) {
            $r['subquotas'] = array_map(static function (array $q) {
                $q['describe'] = subquota_describe($q);
                return $q;
            }, $r['subquotas']);
            // Per-weekday capacity WITH provenance, so the screen can show which
            // figures Kidsoft supplies (and will refresh) and which are overrides.
            $r['capacity_detail'] = room_capacity_detail((int)$r['id'], $year);
            return $r;
        // ACTIVE ROOMS ONLY. This passed false and rendered retired rooms greyed
        // out with an "Archived" badge, which made sense while Archive was a
        // button somebody here pressed - you want to see what you just did. It
        // is Kidsoft's Active flag now (rooms_sync_centre()), so a retired room
        // is not a decision of ours to review, it is a room that no longer
        // exists. Every other screen already reads rooms_for_centre() with its
        // activeOnly default; this was the one that did not.
        }, rooms_for_centre($centreId, $year)),
    ], JSON_UNESCAPED_SLASHES);
    break;
}

// The All centres tab: the register of every centre the organisation holds.
//
// Kidsoft's own Status is reported ALONGSIDE ours rather than instead of it, so
// a centre archived in Kidsoft since the last sync is visible as a disagreement
// rather than silently absent. A Kidsoft outage leaves ks_status null and the
// screen says nothing about it - an unknown must not read as "archived".
case 'centres': {
    $rooms = dbx()->query(
        'SELECT centre_id, COUNT(*) n FROM rooms WHERE active = 1 GROUP BY centre_id'
    )->fetchAll('keyPair');

    $ks = [];
    try {
        foreach (kidsoft_cached('Service', 'getServices')['Services'] ?? [] as $s) {
            $ks[(string)$s['ServiceID']] = (string)($s['Status'] ?? '');
        }
    } catch (RuntimeException $e) { /* leave ks_status null; see above */ }

    // NARROWED TO WHAT THIS USER MAY SEE. Every other read in the app is scoped
    // by a centre id that arrives in the request; this one is a LIST of centres
    // and would otherwise hand a supervisor the names, Kidsoft ids and room
    // counts of six centres they cannot open.
    $out = [];
    foreach (centres_visible(false) as $c) {
        $out[] = [
            'id'          => (int)$c['id'],
            'name'        => $c['name'],
            'kidsoft_name'=> $c['kidsoft_name'],
            'service_id'  => $c['kidsoft_service_id'],
            'active'      => (int)$c['active'],
            // THE FIRST DAY IT TRADES, ours and never synced. Null for a centre
            // that has always been open, which is every one of them but
            // Warnervale - see centre_year_state().
            'opens_on'    => $c['opens_on'],
            'opens_disp'  => empty($c['opens_on']) ? null : syd_date($c['opens_on']),
            'sort_order'  => (int)$c['sort_order'],
            'rooms'       => (int)($rooms[$c['id']] ?? 0),
            'ks_status'   => $ks[$c['kidsoft_service_id']] ?? null,
        ];
    }
    echo json_encode(['ok' => true, 'centres' => $out], JSON_UNESCAPED_SLASHES);
    break;
}

// Discover centres from Kidsoft. Nothing else has ever written this table
// automatically - see the header of _incl/centres-sync.php.
case 'sync_centres': {
    // WHICH CENTRES EXIST is an organisation-level fact, and this discovers new
    // ones from Kidsoft. A supervisor has no view of the group to check the
    // result against, so it is not theirs to run.
    if (!user_all_centres()) api_fail('Only a manager or administrator can sync the centre list.');
    try {
        $r = centres_sync(0);           // 0 = bypass the cache, read live
    } catch (RuntimeException $e) {
        api_fail('Could not reach Kidsoft: ' . $e->getMessage());
    }
    // A centre that has just appeared has no rooms and no capacities, and an
    // empty centre is indistinguishable from a broken one on every screen. Fill
    // it in the same request rather than leaving it blank until 02:00.
    $filled = 0;
    if ($r['created']) {
        $y = (int)date('Y');
        foreach ([$y, $y + 1] as $yy) {
            try { rooms_sync_all($yy, 0); capacity_sync_all($yy, 0); $filled++; }
            catch (RuntimeException $e) { /* reported by the counts below */ }
        }
    }
    echo json_encode(['ok' => true] + $r + ['years_filled' => $filled]);
    break;
}

case 'save_centre': {
    $id   = (int)($_POST['id'] ?? 0);
    $name = trim((string)($_POST['name'] ?? ''));
    $centre = centre_get($id);
    if (!$centre)      api_fail('Centre not found.');
    api_require_centre($id);
    if ($name === '')  api_fail('A centre needs a name.');
    if (mb_strlen($name) > 120) api_fail('That name is too long.');
    $clash = dbx()->query('SELECT id FROM centres WHERE name = ? AND id <> ?', [$name, $id])->fetch();
    if ($clash) api_fail('Another centre is already called "' . $name . '".');
    // THE OPENING DATE, when the form sends one. Absent from the POST leaves it
    // alone; an empty string CLEARS it, which is how a centre that turns out to
    // have been open all along gets back to null.
    //
    // A MANAGER OR ADMINISTRATOR ONLY, like reordering the centre bar: this one
    // decides whether a centre is counted in every group figure in the app, so
    // it is not a per-centre setting even though it sits on a centre. A
    // supervisor keeps the rename.
    $opens = $centre['opens_on'];
    if (array_key_exists('opens_on', $_POST)) {
        $raw = trim((string)$_POST['opens_on']);
        if ($raw !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw)) {
            api_fail('The opening date has to be a real date.');
        }
        // A date, not a datetime, and never parsed in the browser: the string
        // goes to MySQL exactly as the date input produced it. See _incl/dates.php.
        if ($raw !== '' && !checkdate((int)substr($raw, 5, 2), (int)substr($raw, 8, 2),
                                      (int)substr($raw, 0, 4))) {
            api_fail('There is no such date.');
        }
        $opens = $raw === '' ? null : $raw;
        if ((string)$opens !== (string)$centre['opens_on'] && !user_all_centres()) {
            api_fail('Only a manager or administrator can set a centre\'s opening date.');
        }
    }

    // The SHORT NAME and the OPENING DATE. Which centres exist, and what Kidsoft
    // calls them, are not ours to edit - see the ownership line in
    // _incl/centres-sync.php.
    dbx()->query('UPDATE centres SET name = ?, opens_on = ? WHERE id = ?',
                 [$name, $opens, $id]);
    if ($name !== $centre['name']) {
        audit('centre.rename', 'centre', $id, ['from' => $centre['name'], 'to' => $name]);
    }
    if ((string)$opens !== (string)$centre['opens_on']) {
        // Its own audit line, not folded into the rename: this one changes what
        // every group total in the app is measured over.
        audit('centre.opens_on', 'centre', $id,
              ['from' => $centre['opens_on'], 'to' => $opens]);
    }
    echo json_encode(['ok' => true]);
    break;
}

case 'move_centre': {
    $id  = (int)($_POST['id'] ?? 0);
    $dir = (int)($_POST['dir'] ?? 0);
    // Reorders the centre TAB STRIP, for everybody. A supervisor does not have
    // one, so this is not a control they own.
    if (!user_all_centres()) api_fail('Only a manager or administrator can reorder the centres.');
    if (!centre_get($id)) api_fail('Centre not found.');
    if ($dir === 0)       api_fail('Which way?');
    if (!centre_move($id, $dir)) api_fail('That centre is already at the end.');
    audit('centre.reorder', 'centre', $id, ['dir' => $dir < 0 ? 'up' : 'down']);
    echo json_encode(['ok' => true]);
    break;
}

// Re-read this centre's ROOMS AND CAPACITIES from Kidsoft, now. The nightly cron
// does the same; the button exists so a room renamed or re-licensed this morning
// can be picked up without waiting for tonight.
//
// One utilisation read, shared by both syncs. They each need it - capacity for
// the figures, rooms for the "is this room really operating" test - and with
// ttl=0 the cache cannot save a second pass.
case 'sync_kidsoft': {
    $centreId = (int)($_POST['centre_id'] ?? 0);
    $year     = (int)($_POST['year'] ?? 0);
    $centre = centre_get($centreId);
    if (!$centre)                     api_fail('Unknown centre.');
    api_require_centre($centreId);
    if ($year < 2000 || $year > 2100) api_fail('That year looks wrong.');
    try {
        // ttl 0 = bypass the cache; the whole point of the button is a live read.
        $util = capacity_util_year($centre['kidsoft_service_id'], $year, 0);
        $rooms = rooms_sync_centre($centreId, $year, 0, $util);
        $caps  = capacity_sync_year($centreId, $year, 0, $util);
    } catch (RuntimeException $e) {
        api_fail('Could not reach Kidsoft: ' . $e->getMessage());
    }
    audit('capacity.sync', 'centre', $centreId, ['year' => $year] + $caps);
    echo json_encode(['ok' => true, 'rooms' => $rooms, 'capacity' => $caps]);
    break;
}

// Notes are OURS - Kidsoft has no field for them - so this is one of exactly
// two things on this screen a human may change.
case 'save_notes': {
    $roomId = (int)($_POST['room_id'] ?? 0);
    $notes  = trim((string)($_POST['notes'] ?? ''));
    $room = room_get($roomId);
    if (!$room) api_fail('Room not found.');
    api_require_centre((int)$room['centre_id']);
    if (mb_strlen($notes) > 500) api_fail('Notes are limited to 500 characters.');
    dbx()->query('UPDATE rooms SET notes = ? WHERE id = ?', [$notes !== '' ? $notes : null, $roomId]);
    audit('room.notes', 'room', $roomId, ['from' => $room['notes'], 'to' => $notes ?: null]);
    echo json_encode(['ok' => true]);
    break;
}

case 'save_subquota': {
    $id        = (int)($_POST['id'] ?? 0);
    $roomId    = (int)($_POST['room_id'] ?? 0);
    $label     = trim((string)($_POST['label'] ?? ''));
    $under     = opt_int('age_under_months');
    $over      = opt_int('age_over_months');
    $max       = (int)($_POST['max_children'] ?? 0);
    $year      = (int)($_POST['year'] ?? 0);

    $room = room_get($roomId);
    if (!$room)        api_fail('Room not found.');
    api_require_centre((int)$room['centre_id']);
    if ($label === '') api_fail('Give the sub-quota a label, e.g. "Under 3s".');
    if ($under === null && $over === null) {
        api_fail('Set an age bound. A sub-quota with no age limit would match every child and just shadow the room capacity.');
    }
    if ($under !== null && $over !== null && $over >= $under) {
        api_fail('The "under" age must be greater than the "from" age to describe a band.');
    }
    if ($max < 0) api_fail('Maximum cannot be negative.');
    // Compare against the displayed year's capacity where one is recorded. A
    // sub-quota larger than the room could never bind.
    // Compare against the LARGEST weekday capacity: a quota only "could never
    // bind" if it exceeds the room even on its biggest day.
    $capWeek = $year ? room_capacity_week($roomId, $year) : [];
    $known = array_filter($capWeek, static fn($v) => $v !== null);
    $cap = $known ? max($known) : null;
    if ($cap !== null && $max > $cap) {
        api_fail('Sub-quota (' . $max . ') exceeds the ' . $year . ' capacity (' . $cap . '), so it would never bind.');
    }

    if ($id) {
        dbx()->query(
            'UPDATE room_subquotas SET label = ?, age_under_months = ?, age_over_months = ?, max_children = ?
             WHERE id = ? AND room_id = ?',
            [$label, $under, $over, $max, $id, $roomId]
        );
        audit('subquota.update', 'subquota', $id, ['room_id' => $roomId, 'label' => $label, 'max' => $max]);
    } else {
        dbx()->query(
            'INSERT INTO room_subquotas (room_id, label, age_under_months, age_over_months, max_children)
             VALUES (?, ?, ?, ?, ?)',
            [$roomId, $label, $under, $over, $max]
        );
        $id = dbx()->insertId();
        audit('subquota.create', 'subquota', $id, ['room_id' => $roomId, 'label' => $label, 'max' => $max]);
    }
    echo json_encode(['ok' => true, 'id' => $id]);
    break;
}

case 'delete_subquota': {
    $id = (int)($_POST['id'] ?? 0);
    $q = dbx()->query('SELECT * FROM room_subquotas WHERE id = ?', [$id], 'i')->fetch();
    if (!$q) api_fail('Sub-quota not found.');
    $qRoom = room_get((int)$q['room_id']);
    if (!$qRoom) api_fail('Sub-quota not found.');
    api_require_centre((int)$qRoom['centre_id']);
    dbx()->query('DELETE FROM room_subquotas WHERE id = ?', [$id], 'i');
    audit('subquota.delete', 'subquota', $id, ['room_id' => $q['room_id'], 'label' => $q['label']]);
    echo json_encode(['ok' => true]);
    break;
}

default:
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'unknown action']);
}
