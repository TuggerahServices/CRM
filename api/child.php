<?php
// One enrolled child - the dialog the Placement board opens from a child's name.
//
// READ-ONLY. Every field is read from an engine that owns it (see
// _incl/child-profile.php); the only things the dialog writes are the flags and
// the note, and those go to /api/board `save_placement`, which already owns them
// and their checks. So there is no mutating action here and no CSRF check (GET
// only, like /api/search and /api/tenure).

declare(strict_types=1);

require_once __DIR__ . '/../_incl/auth.php';
require_once __DIR__ . '/../_incl/api.php';
require_once __DIR__ . '/../_incl/child-profile.php';

$user = auth_require_api();

$action = $_REQUEST['action'] ?? 'get';
if ($action !== 'get') api_fail('Unknown action.');

$centreId = (int)($_GET['centre_id'] ?? 0);
// THE CENTRE GUARD COMES FIRST. A Kidsoft ChildID is per service, so the centre is
// part of who this child is - and a supervisor must not be able to read a child at
// a centre they do not hold by naming it. See api_require_centre().
api_require_centre($centreId);

$ksId = strtolower(trim((string)($_GET['child'] ?? '')));
if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $ksId)) {
    api_fail('Unknown child.');
}

$year   = (int)($_GET['year'] ?? 0);
$roomId = (int)($_GET['room_id'] ?? 0);

try {
    $p = child_profile($centreId, $ksId, ['year' => $year, 'room_id' => $roomId]);
} catch (RuntimeException $e) {
    api_fail('Could not read this child from Kidsoft: ' . $e->getMessage());
}
if ($p === null) api_fail('Kidsoft has no such child at this centre.');

echo json_encode(['ok' => true] + $p, JSON_UNESCAPED_SLASHES);
