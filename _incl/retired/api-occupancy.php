<?php
// Occupancy grid data.

declare(strict_types=1);

require_once __DIR__ . '/../_incl/auth.php';
require_once __DIR__ . '/../_incl/api.php';
require_once __DIR__ . '/../_incl/occupancy.php';

$user = auth_require_api();

$centreId = (int)($_GET['centre_id'] ?? 0);
$monday   = week_monday($_GET['week'] ?? null);

if (!centre_get($centreId)) api_fail('Unknown centre.');

// A forced refresh drops this centre's cached bookings so the next call is live.
if (!empty($_GET['refresh'])) {
    kidsoft_cache_clear('Bookings/getBookings');
}

try {
    echo json_encode(['ok' => true] + occupancy_week($centreId, $monday), JSON_UNESCAPED_SLASHES);
} catch (RuntimeException $e) {
    // Kidsoft being unreachable is something staff need to SEE, not a blank grid.
    api_fail('Could not load bookings from Kidsoft: ' . $e->getMessage());
}
