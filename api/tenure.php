<?php
// Tenure - gross movement in and out, for one centre-year or for the group.
//
// READ-ONLY. Churn is a fact about Kidsoft's bookings; there is nothing here for
// a human to change, so there is no mutating action and no CSRF check (GET only,
// like /api/search).

declare(strict_types=1);

require_once __DIR__ . '/../_incl/auth.php';
require_once __DIR__ . '/../_incl/api.php';
require_once __DIR__ . '/../_incl/tenure.php';

$user = auth_require_api();

$action = $_REQUEST['action'] ?? 'year';
$year   = (int)($_GET['year'] ?? 0) ?: board_default_year();
if ($year < 2000 || $year > 2100) api_fail('That year looks wrong.');

$opts = ['refresh' => !empty($_GET['refresh'])];

try {
    if ($action === 'overview') {
        // The group shape - every centre's churn side by side. Same guard as
        // board.php's 'overview' and waitlist.php's 'group'.
        if (!user_all_centres()) api_fail('Choose a centre.');
        $ov   = tenure_overview($year, $opts);
        $data = ['overview' => $ov, 'group' => tenure_group_summary($ov)];
    } elseif ($action === 'year') {
        $centreId = (int)($_GET['centre_id'] ?? 0);
        api_require_centre($centreId);
        // BEFORE THE FIVE MONTHLY READS: a centre that has not opened in this
        // year has no tenure to measure, and this is the second most expensive
        // question in the app. Measured 21 Sep 2026, Warnervale 2026: 7.1s to
        // draw a full ladder over a turnover figure reading "-- not trading".
        // tenure_steady() already refused to rank it - this refuses to compute
        // it, and says why instead. Same guard, same helper, as /api/cells.
        $notOpen = centre_not_open_yet(centre_get($centreId), $year);
        if ($notOpen) {
            $data = ['not_open' => $notOpen];
        } else {
            $data = tenure_year($centreId, $year, $opts);
        }
    } else {
        api_fail('Unknown action.');
    }
} catch (RuntimeException $e) {
    // Kidsoft being unreachable is something staff need to SEE, not an empty ladder.
    api_fail('Could not load bookings from Kidsoft: ' . $e->getMessage());
}

// The stepper must not offer a year churn cannot answer. A future year has no
// bookings to read, so stepping into one lands on an empty screen with no hint
// why - the arrow simply disables at this year instead.
$maxYear = board_default_year();
$years   = array_values(array_filter(
    year_list([$maxYear, $maxYear - 1]),
    static fn($y) => $y <= $maxYear
));

echo json_encode([
    'ok'      => true,
    'year'    => $year,
    'centres' => array_map(static fn($c) => ['id' => (int)$c['id'], 'name' => $c['name']], centres_all()),
    'years'   => $years,
] + $data, JSON_UNESCAPED_SLASHES);
