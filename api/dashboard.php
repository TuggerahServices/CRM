<?php
// The Dashboard: the enrolment process end to end, group-wide or for one centre.
//
// READ-ONLY (GET, no CSRF, like /api/search and /api/tenure). It has exactly one
// action. The work-item actions used to live here, because this was the only
// screen that displayed them - they moved to /api/work on 10 Aug 2026 when the
// work layer got its own module, so the placement board no longer posts to an
// endpoint named after a screen it is not on.
//
// Readable failures go through api_fail() - HTTP 200 with ok:false - because
// nginx discards the body of a real 4xx under /api/. See _incl/api.php.

declare(strict_types=1);

require_once __DIR__ . '/../_incl/auth.php';
require_once __DIR__ . '/../_incl/csrf.php';
require_once __DIR__ . '/../_incl/api.php';
require_once __DIR__ . '/../_incl/dashboard.php';
require_once __DIR__ . '/../_incl/work.php';

$user   = auth_require_api();
$action = $_REQUEST['action'] ?? 'load';

if (!in_array($action, ['load', 'fill_next', 'changes'], true)) {
    api_fail('Unknown action. The work-item actions moved to /api/work.');
}

try {
    switch ($action) {

    case 'load': {
        // The rooms are always measured in the CURRENT year - the Dashboard has
        // no year control, because the four things it crosses are scoped
        // differently and one stepper could only ever move some of them. See the
        // header of dashboard.php.
        $year = (int)date_create('today', new DateTimeZone('Australia/Sydney'))->format('Y');
        // 0 (or absent) is the whole group. A centre id scopes it.
        $centreId = (int)($_GET['centre_id'] ?? 0);
        // 0 is the whole group, which only a user who has one may ask for.
        api_require_centre($centreId, true);

        // Close anything reality has already finished, before reporting the
        // pipeline - otherwise the screen shows work in flight that is done.
        // Cheap: one derived board per centre in scope, already cached.
        foreach (($centreId ? [$centreId] : array_map(
                    static fn($c) => (int)$c['id'], centres_all())) as $cid) {
            // NULL, not $year: the Dashboard's year is always today's, and an
            // item aimed at next year - which every re-enrolment chase is -
            // would otherwise never close itself. See work_autoclose().
            try { work_autoclose($cid); }
            catch (Throwable $e) { /* a Kidsoft outage must not block the screen */ }
        }
        // And every queue family Kidsoft calls Enrolled, whatever is booked yet.
        try { work_close_kidsoft_enrolled($centreId ?: null); }
        catch (Throwable $e) { error_log('work_close_kidsoft_enrolled: ' . $e->getMessage()); }

        $data = dash_load($centreId ?: null, $year);
        echo json_encode(['ok' => true, 'stages' => WORK_STAGES] + $data, JSON_UNESCAPED_SLASHES);
        break;
    }

    // PLACES TO FILL FOR THE YEAR AHEAD, on its own request.
    //
    // It is one more derived board per centre - 1.8s on a group load that
    // already takes 4.2s - and the screen the team opens the day on must not get
    // 40% slower for a card that is not the lead. Same treatment the work list
    // and the tours list already get, and a Kidsoft outage costs this card
    // alone.
    case 'fill_next': {
        $year = (int)date_create('today', new DateTimeZone('Australia/Sydney'))->format('Y');
        $centreId = (int)($_GET['centre_id'] ?? 0);
        api_require_centre($centreId, true);
        echo json_encode(['ok' => true, 'fill' => dash_fill_next($centreId ?: null, $year)],
                         JSON_UNESCAPED_SLASHES);
        break;
    }

    // WHAT'S CHANGING, FOR ANY RANGE: the card's "View more..." dialog (Broc,
    // 6 Nov 2026). The same rows week_ahead() gives the card, from
    // week_changes().
    //
    // EVERY ROW IN ONE ANSWER, and the dialog pages through them. The whole
    // range has to be computed to sort it and total it anyway - 1.0s for two
    // full years across the group, 1,810 rows, 548 KB before nginx gzips it - so
    // asking again per page would only repeat that second on every click.
    //
    // A REVERSED RANGE IS REFUSED, NOT SWAPPED. The dialog never sends one (each
    // date box bounds the other), so one arriving here is not a typo to be
    // helpful about.
    case 'changes': {
        $centreId = (int)($_GET['centre_id'] ?? 0);
        api_require_centre($centreId, true);
        [$lo, $hi] = week_changes_bounds();
        $from = board_parse_from($_GET['from'] ?? '');
        $to   = board_parse_from($_GET['to'] ?? '');
        if ($from === null || $to === null) api_fail('Choose a From and a To date.');
        if ($to < $from) api_fail('The To date is before the From date.');
        if ($from < $lo || $to > $hi) {
            api_fail('Choose dates from ' . syd_date($lo) . ' to ' . syd_date($hi) . '.');
        }
        echo json_encode(['ok' => true, 'week' => week_changes($centreId ?: null, $from, $to, [$lo, $hi])],
                         JSON_UNESCAPED_SLASHES);
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
