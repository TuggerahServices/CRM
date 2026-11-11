<?php
// The merged cell grid - who is in the room, what the year billed, and who is
// waiting, for one centre-YEAR.
//
// Read-only. Every mutation the grid offers goes to the endpoint that already
// owns it and its checks: /api/board (annotations), /api/waitlist (offers).
// /api/drift was retired on 6 Aug 2026: the board is derived from Kidsoft now,
// so there is nothing left to reconcile and nothing to link by hand.

declare(strict_types=1);

require_once __DIR__ . '/../_incl/auth.php';
require_once __DIR__ . '/../_incl/api.php';
require_once __DIR__ . '/../_incl/cells.php';
require_once __DIR__ . '/../_incl/waitlist.php';

$user = auth_require_api();

$action = $_REQUEST['action'] ?? 'year';
if ($action !== 'year') api_fail('Unknown action.');

$centreId = (int)($_GET['centre_id'] ?? 0);
$year     = (int)($_GET['year'] ?? 0) ?: board_default_year();
// THE BOARD AS AT A DATE (16 Oct 2026) - see board_from(). A chosen date decides
// the year, so the two cannot disagree; a date outside the years the board
// holds is refused in words (board_from_request()).
$from = board_from_request($_GET['from'] ?? null);
if ($from !== null) $year = (int)substr($from, 0, 4);
api_require_centre($centreId);
if ($year < 2000 || $year > 2100) api_fail('That year looks wrong.');

// BEFORE ANY OF THE WORK: is this year entirely before the centre opens?
//
// Nothing below can produce an honest answer for a centre that does not exist
// yet, and all of it is expensive. Measured 21 Sep 2026: Warnervale 2026 spent
// 7.2 seconds deriving a grid of 385 free places under a headline reading
// "20,097 un-occupied positions across 2026" - a full year of fiction about a
// centre with no doors open, computed from a board, a year of Kidsoft bookings,
// a coverage pass and a queue match.
//
// The test needs only the centre row and the date (centre_not_open_yet()), so it
// costs one already-loaded row and returns before the first Kidsoft call. The
// grid renders the answer instead. See _incl/docs/rooms-centres.md.
//
// AS AT THE FROM DATE, not the year (Broc, 27 Oct 2026): 2027 from 1 January
// has no Warnervale board, 2027 from 1 October does.
$notOpen = centre_not_open_yet(centre_get($centreId), $year, board_from($year, $from));
if ($notOpen) {
    echo json_encode([
        'ok'       => true,
        'not_open' => $notOpen,
        // The stepper still needs its list, or the year arrows would be dead on
        // the one screen a reader most wants to step OFF.
        'years'    => year_list([$year]),
        'weekdays' => WEEKDAYS,
    ], JSON_UNESCAPED_SLASHES);
    exit;
}

// A lapsed offer must release its days before we report what is free.
offers_expire_due();

// REFRESH RE-READS CAPACITY, NOT ONLY BOOKINGS.
//
// When a room owes more places than it has, the enrolments team's own remedy is
// to move the capped positions between rooms in Kidsoft (9 Sep 2026: "We may
// need to adjust the children number in Kidsoft for the moment"). Capacity is
// otherwise synced by the 02:00 job, so without this they would make the change,
// press Refresh, watch the board still say the room is over, and reasonably
// conclude the tool was wrong. Bookings and capacity are two different reads and
// only one of them was being refreshed.
if (!empty($_GET['refresh'])) {
    require_once __DIR__ . '/../_incl/capacity.php';
    // Never fatal: a capacity read that fails leaves last night's figures, which
    // is exactly what the button used to do every time.
    try { capacity_sync_year($centreId, $year, 0); } catch (Throwable $e) {}
}

try {
    $data = cells_year($centreId, $year, [
        'candidates' => empty($_GET['no_candidates']),
        // Re-read every month of the year from Kidsoft (~33s). The engine takes
        // this rather than the cache being cleared out from under it, so a
        // refresh of one centre-year cannot dump every other centre's cache.
        'refresh'    => !empty($_GET['refresh']),
        'from'       => $from,
    ]);
} catch (RuntimeException $e) {
    // Kidsoft being unreachable is something staff need to SEE, not a blank grid.
    api_fail('Could not load bookings from Kidsoft: ' . $e->getMessage());
}

echo json_encode([
    'ok'      => true,
    'centres' => array_map(static fn($c) => ['id' => (int)$c['id'], 'name' => $c['name']], centres_all()),
    'types'   => DRIFT_TYPES,
    'statuses'=> PLACEMENT_STATUS,
    'weekdays'=> WEEKDAYS,
    'years'   => year_list([$year]),
    // The pipeline vocabulary, so board.js never keeps its own copy of the
    // stage labels - one list, defined in _incl/work.php.
    'work_stages' => WORK_STAGES,
    // Today in SYDNEY, because the browser's today is the viewer's today. It
    // fills "when they asked" on a new internal waitlist entry, and that date is
    // the queue order when two families want one place - so it must not be a
    // day out for anybody working from home in another timezone.
    'today'   => date_create('today', new DateTimeZone('Australia/Sydney'))->format('Y-m-d'),
] + $data, JSON_UNESCAPED_SLASHES);
