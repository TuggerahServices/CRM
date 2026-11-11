<?php
// Group-wide search: the answer to "someone is on the phone".
//
// Read-only, so GET and no CSRF. Everything it can find is already visible on
// the screens this app has; this only makes it reachable by the identifier a
// caller actually gives you - a phone number, half a name, an email.

declare(strict_types=1);

require_once __DIR__ . '/../_incl/auth.php';
require_once __DIR__ . '/../_incl/api.php';
require_once __DIR__ . '/../_incl/search.php';

$user = auth_require_api();

$action = $_REQUEST['action'] ?? 'search';
if ($action !== 'search') api_fail('Unknown action.');

$q = trim((string)($_GET['q'] ?? ''));
if ($q === '') api_fail('Type something to search for.');
// A whole waitlist note pasted into the box is not a query, and would run the
// token scorer over every guardian in the group for nothing.
if (mb_strlen($q) > 120) $q = mb_substr($q, 0, 120);

try {
    $res = search_all($q, ['limit' => SEARCH_LIMIT]);
} catch (RuntimeException $e) {
    api_fail('Search failed: ' . $e->getMessage());
}

echo json_encode([
    'ok'       => true,
    'weekdays' => WEEKDAYS,
    'statuses' => PLACEMENT_STATUS,
    // The board year the rest of the app defaults to, so a result can link
    // straight to the cell rather than guessing which year to open.
    'year'     => board_default_year(),
] + $res, JSON_UNESCAPED_SLASHES);
