<?php
// Vacancy-to-queue matching.

declare(strict_types=1);

require_once __DIR__ . '/../_incl/auth.php';
require_once __DIR__ . '/../_incl/api.php';
require_once __DIR__ . '/../_incl/fill.php';

$user = auth_require_api();

// Read-only: offers are made through /api/waitlist, which owns the checks that
// stop the same place being promised twice.
if (($_REQUEST['action'] ?? '') !== 'list') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'unknown action']);
    exit;
}

$centreId = (int)($_GET['centre_id'] ?? 0);
$year     = (int)($_GET['year'] ?? board_default_year());
if (!centre_get($centreId))       api_fail('Unknown centre.');
if ($year < 2000 || $year > 2100) api_fail('That year looks wrong.');

// A lapsed offer must release its days before we decide what is free.
offers_expire_due();

echo json_encode([
    'ok'    => true,
    'years' => centre_years($centreId, [board_default_year(), board_default_year() + 1]),
] + fill_suggestions($centreId, $year), JSON_UNESCAPED_SLASHES);
