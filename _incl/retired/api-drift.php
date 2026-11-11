<?php
// Drift between the placement board and Kidsoft.
//
// Read actions compare; write actions only ever change OUR data. Kidsoft is
// strictly read-only - a drift caused by bad data upstream is resolved by a
// person fixing Kidsoft, never by this app.

declare(strict_types=1);

require_once __DIR__ . '/../_incl/auth.php';
require_once __DIR__ . '/../_incl/csrf.php';
require_once __DIR__ . '/../_incl/api.php';
require_once __DIR__ . '/../_incl/drift.php';
require_once __DIR__ . '/../_incl/audit.php';

$user = auth_require_api();
$action = $_REQUEST['action'] ?? '';

if ($action !== 'week') {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { http_response_code(405); exit; }
    csrf_require();
}

switch ($action) {

case 'week': {
    $centreId = (int)($_GET['centre_id'] ?? 0);
    $monday   = week_monday($_GET['week'] ?? null);
    if (!centre_get($centreId)) api_fail('Unknown centre.');

    if (!empty($_GET['refresh'])) kidsoft_cache_clear('Bookings/getBookings');

    try {
        $d = drift_week($centreId, $monday);
    } catch (RuntimeException $e) {
        api_fail('Could not load bookings from Kidsoft: ' . $e->getMessage());
    }
    echo json_encode(['ok' => true] + $d + ['types' => DRIFT_TYPES], JSON_UNESCAPED_SLASHES);
    break;
}

// Bind a board placement to a Kidsoft child. This is how a drift stops recurring:
// once linked, matching is by id and no longer depends on the spelling of a name.
case 'link': {
    $placementId = (int)($_POST['placement_id'] ?? 0);
    $childId     = trim((string)($_POST['kidsoft_child_id'] ?? ''));

    $p = placement_get($placementId);
    if (!$p)              api_fail('Placement not found.');
    if ($childId === '')  api_fail('No Kidsoft child given.');

    // Re-pointing an existing link silently would orphan the child it used to
    // name, who then reappears as "attending, not on the board" forever.
    if ($p['kidsoft_child_id'] && $p['kidsoft_child_id'] !== $childId) {
        api_fail('That board row is already linked to a different Kidsoft child. Unlink it first.');
    }
    if ($p['kidsoft_child_id'] === $childId) { echo json_encode(['ok' => true]); break; }

    // One Kidsoft child cannot be two placements in the same room and year --
    // that would double-count them against capacity. A unique index enforces
    // this too; the check is here to give a readable message instead of a 500.
    $clash = dbx()->query(
        'SELECT child_first_name, child_last_name FROM placements
         WHERE kidsoft_child_id = ? AND year = ? AND room_id = ? AND id <> ?',
        [$childId, $p['year'], $p['room_id'], $placementId]
    )->fetch();
    if ($clash) {
        api_fail('That Kidsoft child is already linked to "'
               . trim($clash['child_first_name'] . ' ' . $clash['child_last_name'])
               . '" in this room for ' . $p['year'] . '.');
    }

    dbx()->query('UPDATE placements SET kidsoft_child_id = ? WHERE id = ?', [$childId, $placementId]);
    audit('placement.link', 'placement', $placementId, [
        'kidsoft_child_id' => $childId,
        'name' => trim($p['child_first_name'] . ' ' . $p['child_last_name']),
    ]);
    echo json_encode(['ok' => true]);
    break;
}

case 'unlink': {
    $placementId = (int)($_POST['placement_id'] ?? 0);
    $p = placement_get($placementId);
    if (!$p) api_fail('Placement not found.');
    dbx()->query('UPDATE placements SET kidsoft_child_id = NULL WHERE id = ?', [$placementId], 'i');
    audit('placement.unlink', 'placement', $placementId, ['was' => $p['kidsoft_child_id']]);
    echo json_encode(['ok' => true]);
    break;
}

// Create a board placement straight from a Kidsoft booking - the fix for
// "attending, not on the board". Days and room come from Kidsoft, so the new row
// matches reality rather than a guess.
case 'add_from_kidsoft': {
    $roomId  = (int)($_POST['room_id'] ?? 0);
    $year    = (int)($_POST['year'] ?? 0);
    $childId = trim((string)($_POST['kidsoft_child_id'] ?? ''));
    $first   = trim((string)($_POST['child_first_name'] ?? ''));
    $last    = trim((string)($_POST['child_last_name'] ?? ''));
    $dob     = trim((string)($_POST['child_dob'] ?? ''));

    $raw = $_POST['days'] ?? [];
    if (!is_array($raw)) $raw = [$raw];
    $days = array_values(array_unique(array_filter(
        array_map('intval', $raw), static fn($w) => $w >= 1 && $w <= 5
    )));
    sort($days);

    $room = room_get($roomId);
    if (!$room)                        api_fail('Room not found. Map this Kidsoft room first.');
    if ($first === '' || $last === '') api_fail('That Kidsoft record has no usable name.');
    if (!$days)                        api_fail('No booked days to copy.');
    if ($year < 2000 || $year > 2100)  api_fail('That year looks wrong.');

    // Idempotent for a linked child: adding the same child to the same room and
    // year again is treated as "already done", not an error. This is what makes
    // the multi-step split fix safely re-runnable - a chain that failed part way
    // can simply be clicked again, and the steps that already landed are no-ops.
    if ($childId !== '') {
        $existing = dbx()->query(
            'SELECT id FROM placements WHERE kidsoft_child_id = ? AND year = ? AND room_id = ?',
            [$childId, $year, $roomId]
        )->fetch();
        if ($existing) {
            placement_set_days((int)$existing['id'], $days);
            echo json_encode(['ok' => true, 'id' => (int)$existing['id'], 'existing' => true]);
            break;
        }
    }

    $id = 0;
    dbx()->transaction(function ($db) use ($room, $roomId, $year, $childId, $first, $last, $dob, $days, &$id) {
        $db->query(
            'INSERT INTO placements (centre_id, room_id, year, kidsoft_child_id, child_first_name,
                    child_last_name, child_dob, status)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [$room['centre_id'], $roomId, $year, $childId ?: null, $first, $last,
             $dob !== '' ? substr($dob, 0, 10) : null,
             // They are demonstrably attending, so record that rather than
             // leaving a row the team has to triage a second time.
             'confirmed']
        );
        $id = $db->insertId();
        foreach ($days as $d) {
            $db->query('INSERT INTO placement_days (placement_id, weekday) VALUES (?, ?)', [$id, $d]);
        }
    });

    audit('placement.from_kidsoft', 'placement', $id, [
        'kidsoft_child_id' => $childId, 'room_id' => $roomId, 'year' => $year, 'days' => $days,
    ]);
    echo json_encode(['ok' => true, 'id' => $id]);
    break;
}

// Add EVERY unambiguous "attending but not on the board" child at once.
//
// This is what makes an empty board usable: a centre has 100-260 children already
// attending, and adding them one modal at a time is the reason a tool like this
// gets abandoned. Kidsoft already knows the room and the days, so there is nothing
// for a human to decide - except in the two cases this deliberately skips.
//
// The drift is RECOMPUTED here rather than trusting a list posted by the browser:
// this writes hundreds of rows, and it should write exactly what the server can
// currently justify from Kidsoft.
case 'add_all_from_kidsoft': {
    $centreId = (int)($_POST['centre_id'] ?? 0);
    // A YEAR, matching the board. Recomputing this from one week would add only
    // the children who happened to be booked that week and would then miss
    // everyone who starts later in the year - and this is the button that
    // populates an empty board, so missing them is the whole cost.
    $year     = (int)($_POST['year'] ?? 0) ?: board_default_year();
    if (!centre_get($centreId)) api_fail('Unknown centre.');
    if ($year < 2000 || $year > 2100) api_fail('That year looks wrong.');

    try {
        $d = drift_year($centreId, $year);
    } catch (RuntimeException $e) {
        api_fail('Could not load bookings from Kidsoft: ' . $e->getMessage());
    }

    $added = 0; $already = 0;
    $skipped = ['unmapped' => 0, 'suggestion' => 0, 'no_name' => 0];

    dbx()->transaction(function ($db) use ($d, $year, &$added, &$already, &$skipped) {
        foreach ($d['issues'] as $i) {
            if ($i['type'] !== 'missing_from_board') continue;

            // A near-name match means this may be a child ALREADY on the board
            // under a misspelling. Adding would create a second row for one child
            // and quietly double-count them, so it stays a human decision.
            if (!empty($i['suggest_placement'])) { $skipped['suggestion']++; continue; }

            $first = trim((string)$i['first_name']);
            $last  = trim((string)$i['last_name']);
            if ($first === '' || $last === '') { $skipped['no_name']++; continue; }

            foreach ($i['ks_rooms'] as $r) {
                if (empty($r['room_id'])) { $skipped['unmapped']++; continue; }
                if (empty($r['days']))    continue;

                $room = room_get((int)$r['room_id']);
                if (!$room) { $skipped['unmapped']++; continue; }

                // Idempotent: re-running after a partial failure, or on a board
                // that is already half-populated, must not duplicate anyone.
                $exists = $db->query(
                    'SELECT id FROM placements WHERE kidsoft_child_id = ? AND room_id = ? AND year = ?',
                    [$i['kidsoft_child_id'], $r['room_id'], $year]
                )->fetch();
                if ($exists) { $already++; continue; }

                $db->query(
                    'INSERT INTO placements (centre_id, room_id, year, kidsoft_child_id,
                            child_first_name, child_last_name, child_dob, status)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                    [$room['centre_id'], $r['room_id'], $year, $i['kidsoft_child_id'],
                     $first, $last, $i['dob'] ?: null,
                     // They are demonstrably attending and being billed, so this
                     // is not a guess.
                     'confirmed']
                );
                $pid = $db->insertId();
                foreach ($r['days'] as $wd) {
                    $wd = (int)$wd;
                    if ($wd < 1 || $wd > 5) continue;
                    $db->query('INSERT INTO placement_days (placement_id, weekday) VALUES (?, ?)', [$pid, $wd]);
                }
                $added++;
            }
        }
    });

    audit('board.populate_from_kidsoft', 'centre', $centreId, [
        'year' => $year, 'week' => $monday, 'added' => $added, 'skipped' => $skipped,
    ]);

    $notes = [];
    if ($already)               $notes[] = $already . ' already on the board';
    if ($skipped['suggestion']) $notes[] = $skipped['suggestion'] . ' look like a misspelling of someone already there - review those by hand';
    if ($skipped['unmapped'])   $notes[] = $skipped['unmapped'] . ' in rooms not mapped to Kidsoft';
    if ($skipped['no_name'])    $notes[] = $skipped['no_name'] . ' have no usable name in Kidsoft';

    echo json_encode([
        'ok' => true, 'added' => $added, 'year' => $year, 'skipped' => $skipped,
        'message' => $added . ' child' . ($added === 1 ? '' : 'ren') . ' added to the ' . $year . ' board'
                   . ($notes ? '. Skipped: ' . implode('; ', $notes) . '.' : '.'),
    ], JSON_UNESCAPED_SLASHES);
    break;
}

// Adopt Kidsoft's days for a placement - the one-click fix for a day mismatch
// where Kidsoft is right and the board is stale.
case 'accept_days': {
    $placementId = (int)($_POST['placement_id'] ?? 0);
    $raw = $_POST['days'] ?? [];
    if (!is_array($raw)) $raw = [$raw];
    $days = array_values(array_unique(array_filter(
        array_map('intval', $raw), static fn($w) => $w >= 1 && $w <= 5
    )));
    sort($days);

    $p = placement_get($placementId);
    if (!$p)    api_fail('Placement not found.');
    if (!$days) api_fail('No days to apply.');

    placement_set_days($placementId, $days);
    audit('placement.accept_days', 'placement', $placementId, ['from' => $p['days'], 'to' => $days]);
    echo json_encode(['ok' => true]);
    break;
}

// Move a placement to the room Kidsoft has it in.
case 'accept_room': {
    $placementId = (int)($_POST['placement_id'] ?? 0);
    $roomId      = (int)($_POST['room_id'] ?? 0);
    $p = placement_get($placementId);
    $room = room_get($roomId);
    if (!$p)    api_fail('Placement not found.');
    if (!$room) api_fail('Room not found. Map this Kidsoft room first.');
    if ((int)$p['room_id'] === $roomId) { echo json_encode(['ok' => true]); break; }

    // Moving a child into a room where they already have a row would double
    // count them - occupancy is a raw count of placements. This is the same
    // invariant 'link' enforces; without it the split-fix chain could bypass it.
    if ($p['kidsoft_child_id']) {
        $dupe = dbx()->query(
            'SELECT id FROM placements WHERE kidsoft_child_id = ? AND room_id = ? AND year = ? AND id <> ?',
            [$p['kidsoft_child_id'], $roomId, $p['year'], $placementId]
        )->fetch();
        if ($dupe) api_fail('That child already has a row in ' . $room['name'] . ' for ' . $p['year'] . '.');
    }
    // A move across centres would take the child off the board they are shown on.
    if ((int)$room['centre_id'] !== (int)$p['centre_id']) {
        api_fail('That room belongs to a different centre.');
    }

    // centre_id must follow room_id, or the child is counted in one centre and
    // shown in another.
    dbx()->query(
        'UPDATE placements SET room_id = ?, centre_id = (SELECT centre_id FROM rooms WHERE id = ?)
         WHERE id = ?',
        [$roomId, $roomId, $placementId]
    );
    audit('placement.accept_room', 'placement', $placementId, [
        'from' => $p['room_id'], 'to' => $roomId,
    ]);
    echo json_encode(['ok' => true]);
    break;
}

default:
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'unknown action']);
}
