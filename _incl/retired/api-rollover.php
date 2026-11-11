<?php
// Annual roll-over: preview the transition into next year, then apply it.

declare(strict_types=1);

require_once __DIR__ . '/../_incl/auth.php';
require_once __DIR__ . '/../_incl/csrf.php';
require_once __DIR__ . '/../_incl/api.php';
require_once __DIR__ . '/../_incl/rollover.php';
require_once __DIR__ . '/../_incl/reenrolment.php';
require_once __DIR__ . '/../_incl/audit.php';

$user = auth_require_api();
$action = $_REQUEST['action'] ?? '';

if ($action !== 'plan') {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { http_response_code(405); exit; }
    csrf_require();
}

$centreId = (int)($_REQUEST['centre_id'] ?? 0);
$fromYear = (int)($_REQUEST['from_year'] ?? 0);
$toYear   = (int)($_REQUEST['to_year'] ?? 0);

if (!centre_get($centreId))                       api_fail('Unknown centre.');
if ($fromYear < 2000 || $fromYear > 2100)         api_fail('That source year looks wrong.');
if ($toYear < 2000 || $toYear > 2100)             api_fail('That target year looks wrong.');
if ($fromYear === $toYear)                        api_fail('Choose two different years.');

switch ($action) {

case 'plan': {
    // What families have already answered next year's re-enrolment offer. Read
    // from Kidsoft, which runs that flow - see _incl/reenrolment.php. A DECLINE is
    // the only forward-looking vacancy signal the API has, so it belongs on the one
    // screen that is about next year. A Kidsoft outage costs this panel, not the
    // plan.
    $reenrol = ['accepted' => 0, 'declined' => 0, 'unknown' => 0, 'list' => []];
    try {
        $reenrol = reenrolment_answers($centreId, $fromYear);
    } catch (RuntimeException $e) {
        // left at zeroes; the screen simply shows no panel
    }

    echo json_encode(
        ['ok' => true, 'reenrolment' => $reenrol] + rollover_plan($centreId, $fromYear, $toYear),
        JSON_UNESCAPED_SLASHES
    );
    break;
}

// Apply a set of per-child decisions. The client sends only what the user
// actually confirmed, so a half-reviewed plan can be applied in stages and
// finished later - this is a fortnight of work in the real cycle, not one sitting.
case 'apply': {
    $decisions = $_POST['decisions'] ?? '';
    $decisions = is_string($decisions) ? json_decode($decisions, true) : $decisions;
    if (!is_array($decisions) || !$decisions) api_fail('Nothing to apply.');
    if (count($decisions) > 2000) api_fail('Too many rows in one go.');

    // Index the source board once; every decision is validated against it rather
    // than trusting the ids that came back from the browser.
    $source = dbx()->query(
        'SELECT p.*, GROUP_CONCAT(pd.weekday ORDER BY pd.weekday) AS days
         FROM placements p LEFT JOIN placement_days pd ON pd.placement_id = p.id
         WHERE p.centre_id = ? AND p.year = ? GROUP BY p.id',
        [$centreId, $fromYear], 'ii'
    )->fetchAll();
    $byId = [];
    foreach ($source as $s) $byId[(int)$s['id']] = $s;

    $applied = ['stay' => 0, 'move' => 0, 'leave' => 0];
    $skipped = [];

    dbx()->transaction(function ($db) use ($decisions, $byId, $toYear, $centreId, &$applied, &$skipped) {
        foreach ($decisions as $d) {
            $pid  = (int)($d['placement_id'] ?? 0);
            $what = (string)($d['action'] ?? '');
            $p    = $byId[$pid] ?? null;

            if (!$p) { $skipped[] = "placement {$pid} is not on the {$toYear} source board"; continue; }
            $days = $p['days'] === null ? [] : array_map('intval', explode(',', $p['days']));
            $name = trim($p['child_first_name'] . ' ' . $p['child_last_name']);

            if ($what === 'leave') {
                // Not copied forward. Marking the SOURCE row is what puts them on
                // the "children leaving" list and triggers the end-of-care letter.
                $db->query("UPDATE placements SET status = 'ending_care' WHERE id = ?", [$pid]);
                $applied['leave']++;
                continue;
            }

            if ($what !== 'stay' && $what !== 'move') {
                $skipped[] = "{$name}: unknown action";
                continue;
            }

            $roomId = $what === 'stay' ? (int)$p['room_id'] : (int)($d['room_id'] ?? 0);
            $room = $roomId ? room_get($roomId) : null;
            if (!$room)                                 { $skipped[] = "{$name}: no room chosen"; continue; }
            if ((int)$room['centre_id'] !== $centreId)  { $skipped[] = "{$name}: room is at another centre"; continue; }
            if (!$days)                                 { $skipped[] = "{$name}: has no days"; continue; }

            rollover_place($p, $roomId, $toYear, $days);
            $applied[$what]++;
        }
    });

    audit('board.rollover', 'centre', $centreId, [
        'from' => $fromYear, 'to' => $toYear, 'applied' => $applied, 'skipped' => count($skipped),
    ]);

    $total = array_sum($applied);
    echo json_encode([
        'ok'      => true,
        'applied' => $applied,
        'skipped' => $skipped,
        'message' => sprintf(
            '%d child%s carried into %d (%d staying, %d moving room). %d marked as ending care.'
            . ' Everyone carried over is "Not set" until the family confirms.',
            $applied['stay'] + $applied['move'], ($applied['stay'] + $applied['move']) === 1 ? '' : 'ren',
            $toYear, $applied['stay'], $applied['move'], $applied['leave']
        ),
    ], JSON_UNESCAPED_SLASHES);
    break;
}

default:
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'unknown action']);
}
