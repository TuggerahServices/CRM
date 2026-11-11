<?php
// Marketing - where there are places to sell and nobody waiting to take them.
//
// READ-ONLY, one action, GET and no CSRF - the same shape as /api/tenure and
// /api/search. There is nothing to write: every figure on the screen is derived
// from the board, the queue and the day requests, and the thing a reader DOES
// about it (ring a family, start a work item, make an offer) is owned by
// endpoints that already exist.
//
// Readable failures go through api_fail() - HTTP 200 with ok:false - because
// nginx discards the body of a real 4xx under /api/. See _incl/api.php.

declare(strict_types=1);

require_once __DIR__ . '/../_incl/auth.php';
require_once __DIR__ . '/../_incl/csrf.php';
require_once __DIR__ . '/../_incl/api.php';
require_once __DIR__ . '/../_incl/marketing.php';

$user   = auth_require_api();
$action = $_REQUEST['action'] ?? 'load';

switch ($action) {

case 'load': {
    $centreId = (int)($_GET['centre_id'] ?? 0);
    // GROUP IS THE REAL ANSWER HERE for an administrator or a manager: an
    // advertising decision is made across the organisation, and the whole point
    // of the ranking is that Kanwal's three-year-olds outrank Tuggerah's. A
    // supervisor is never offered the All centres tab and is refused it here as
    // well, because the tab strip is a display decision and ?centre_id=0 is the
    // door.
    api_require_centre($centreId, true);

    $year = (int)($_GET['year'] ?? 0) ?: board_default_year();
    if ($year < 2000 || $year > 2100) api_fail('That is not a year this app holds.');

    try {
        $data = marketing_load($centreId, $year);
    } catch (InvalidArgumentException $e) {
        api_fail($e->getMessage());
    } catch (RuntimeException $e) {
        api_fail('Could not reach Kidsoft: ' . $e->getMessage());
    }

    echo json_encode([
        'ok'    => true,
        // The stepper's own list, so it disables an arrow at either end rather
        // than stepping into a year with no capacities in it. year_list() is the
        // one list every screen with a stepper uses.
        'years' => year_list([$year]),
        'group' => $centreId === 0,
    ] + $data, JSON_UNESCAPED_SLASHES);
    break;
}

default:
    api_fail('Unknown action.');
}
