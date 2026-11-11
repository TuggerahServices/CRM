<?php
// Support plans - the list, and the step ladder's writes.
//
// THE WRITES THAT LIVE HERE ARE THE ONES NOTHING ELSE OWNS. A held day still
// goes through /api/board (hold_save, hold_release) and a contact note still
// goes through /api/comms - both already own their checks, and a second way to
// write either would be two implementations of one record, the mistake
// /api/comms was deliberately NOT rebuilt to avoid when the Kidsoft bridge
// landed. What is new here is where a child has got to in the process, which no
// other endpoint has ever stored.
//
// EVERY ACTION GUARDS OFF THE PLACEMENT'S OWN CENTRE, after looking the row up -
// the rule for every endpoint that names a room, placement, offer or work item
// rather than taking a centre id. Hiding the screen from a supervisor is not a
// restriction; ?placement_id= is the door.
//
// Readable failures go through api_fail() -> HTTP 200 with ok:false, because
// nginx discards the body of a real 4xx under /api/. See _incl/api.php.

declare(strict_types=1);

require_once __DIR__ . '/../_incl/auth.php';
require_once __DIR__ . '/../_incl/csrf.php';
require_once __DIR__ . '/../_incl/api.php';
require_once __DIR__ . '/../_incl/support-plans.php';
require_once __DIR__ . '/../_incl/comments.php';   // COMM_DIRECTIONS, COMM_CHANNELS for the report
require_once __DIR__ . '/../_incl/support-plan-calendar.php';

$user = auth_require_api();
$action = $_REQUEST['action'] ?? 'list';

$reads = ['list', 'report'];   // GET, no CSRF; everything else mutates
if (!in_array($action, $reads, true)) {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { http_response_code(405); exit; }
    csrf_require();
}

// The plan whose booked days go to the centre's calendar once the answer has
// gone - see the end of this file. Set by every write that names a plan.
$calendarPlan = null;

/**
 * The placement a write names, and the permission to touch it, in one step.
 *
 * THE GUARD RUNS OFF THE ROW, not off anything posted. A supervisor who is given
 * somebody else's placement id gets "Unknown centre." rather than a row from a
 * centre they cannot see, and the plan type is checked against the flag so a
 * post cannot open a BSP ladder on a child who is only on an ISP.
 */
function plan_target(): array {
    $id = (int)($_POST['placement_id'] ?? 0);
    $type = (string)($_POST['plan_type'] ?? '');
    if (!isset(PLAN_TYPES[$type])) api_fail('Unknown plan type.');

    $p = dbx()->query('SELECT * FROM placements WHERE id = ?', [$id], 'i')->fetch();
    if (!$p) api_fail('That child is not on the board any more.');
    api_require_centre((int)$p['centre_id']);

    $col = $type === 'isp' ? 'has_individual_plan' : 'has_behaviour_plan';
    if (!(int)$p[$col]) {
        api_fail(trim($p['child_first_name'] . ' ' . $p['child_last_name'])
            . ' is not flagged for ' . (($type === 'isp') ? 'an ' : 'a ')
            . PLAN_TYPES[$type] . '. Tick it on the placement board first.');
    }
    return [$id, $type, $p];
}

switch ($action) {

case 'list': {
    $centreId = (int)($_GET['centre_id'] ?? 0);
    // GROUP IS A REAL ANSWER HERE, for an administrator or a manager: "every
    // child on a plan" is the question the screen was asked for. A supervisor is
    // never offered the All centres tab and is refused it here as well, because
    // the tab strip is a display decision and ?centre_id=0 is the door.
    api_require_centre($centreId, true);

    $res = support_plans_list($centreId);
    echo json_encode([
        'ok'      => true,
        'rows'    => $res['rows'],
        'summary' => $res['summary'],
        // WHICH CENTRE-YEARS KIDSOFT WOULD NOT ANSWER FOR. A banner, not a
        // caption: whether the days on a row can be trusted at all is exactly
        // the thing the team cannot know unaided.
        'unreachable' => $res['unreachable'],
        'group'   => $centreId === 0,
        'outcomes' => ['bsp' => plan_outcomes('bsp'), 'isp' => plan_outcomes('isp')],
        // TODAY FROM THE SYDNEY CLOCK, never new Date() in the viewer's browser.
        // It is the default date on every dialog here, and a date this app orders
        // places by must not depend on where the reader is sitting.
        'today'   => plan_today(),
    ], JSON_UNESCAPED_SLASHES);
    break;
}

// THE PRINTABLE REPORT: the rows the list renders, each carrying the family's
// whole contact history rather than only the latest call. One child
// (placement_id) or every child the centre tab shows (centre_id, 0 = group).
//
// THE SAME ROWS, NOT A SECOND QUERY. A report that worked out "who is on a plan"
// or "what is overdue" for itself would be a second answer to the question the
// screen answers, and paper is where two answers do the most damage - it cannot
// be refreshed. So it filters support_plans_list() and adds the history.
case 'report': {
    $placementId = (int)($_GET['placement_id'] ?? 0);
    if ($placementId) {
        // The guard comes off the ROW, like every action naming a placement:
        // ?placement_id= is the door, whatever the screen offered.
        $p = dbx()->query('SELECT centre_id FROM placements WHERE id = ?', [$placementId], 'i')->fetch();
        if (!$p) api_fail('That child is not on the board any more.');
        $centreId = (int)$p['centre_id'];
        api_require_centre($centreId);
        $res = support_plans_list($centreId);
        $res['rows'] = array_values(array_filter($res['rows'],
            static fn($r) => $r['placement_id'] === $placementId));
        if (!$res['rows']) api_fail('That child is not on a support plan.');
        $res['summary'] = support_plans_summary($res['rows']);
        // The centre, not the child: the child's name heads their own section.
        $scope = $res['rows'][0]['centre_name'];
    } else {
        $centreId = (int)($_GET['centre_id'] ?? 0);
        api_require_centre($centreId, true);
        $res = support_plans_list($centreId);
        $scope = $centreId ? (string)(centre_get($centreId)['name'] ?? '') : 'All centres';
    }

    $history = support_plans_history($res['rows']);
    foreach ($res['rows'] as &$r) $r['history'] = $history[$r['placement_id']] ?? [];
    unset($r);

    echo json_encode([
        'ok'          => true,
        'rows'        => $res['rows'],
        'summary'     => $res['summary'],
        'unreachable' => $res['unreachable'],
        'group'       => !$placementId && $centreId === 0,
        'single'      => $placementId > 0,
        'scope'       => $scope,
        'directions'  => COMM_DIRECTIONS,
        'channels'    => COMM_CHANNELS,
        // AS AT WHEN, AND BY WHOM. Paper does not refresh: "week 8 of 10" and
        // "12 days since contact" are true on the day it was printed and on no
        // other, so the sheet has to say which day that was. Sydney, server-side.
        'printed'     => syd_datetime(date('Y-m-d H:i:s')),
        'printed_by'  => $user['name'] ?: $user['email'],
    ], JSON_UNESCAPED_SLASHES);
    break;
}

// Tick a step, untick it, redate it, note it, or move when it falls due.
case 'step_save': {
    [$id, $type] = plan_target();
    $key = trim((string)($_POST['step_key'] ?? ''));

    // due_on is only touched when the form actually sent it, so ticking a step
    // never silently drops somebody's hand-set date; '' is a deliberate clear
    // back to the date the policy implies.
    $d = [
        'done'    => !empty($_POST['done']),
        'done_on' => (string)($_POST['done_on'] ?? ''),
        'note'    => (string)($_POST['note'] ?? ''),
    ];
    if (array_key_exists('due_on', $_POST)) $d['due_on'] = (string)$_POST['due_on'];
    // The day it is booked for, under the same rule: '' clears it.
    if (array_key_exists('booked_on', $_POST)) $d['booked_on'] = (string)$_POST['booked_on'];

    try {
        $plan = plan_step_save($id, $type, $key, $d);
    } catch (InvalidArgumentException $e) {
        api_fail($e->getMessage());
    }
    $calendarPlan = [$id, $type];
    echo json_encode(['ok' => true, 'plan' => $plan], JSON_UNESCAPED_SLASHES);
    break;
}

// How the plan ended. Three of the five may be recorded at ANY point, which is
// the policy's own shape and not a convenience - see plan_outcomes().
case 'outcome_save': {
    [$id, $type] = plan_target();
    try {
        $plan = plan_outcome_save($id, $type, (string)($_POST['outcome'] ?? ''),
            (string)($_POST['outcome_on'] ?? ''), (string)($_POST['outcome_note'] ?? ''));
    } catch (InvalidArgumentException $e) {
        api_fail($e->getMessage());
    }
    // Ending the plan takes the days booked after it out of the calendar.
    $calendarPlan = [$id, $type];
    echo json_encode(['ok' => true, 'plan' => $plan], JSON_UNESCAPED_SLASHES);
    break;
}

// Undo a close. An outcome that cannot be corrected is one that gets recorded
// late or not at all.
case 'outcome_clear': {
    [$id, $type] = plan_target();
    $calendarPlan = [$id, $type];   // and reopening it puts them back
    echo json_encode(['ok' => true, 'plan' => plan_outcome_clear($id, $type)],
                     JSON_UNESCAPED_SLASHES);
    break;
}

// When the ISP is next due to be looked at. Typed from the ISP's own timeframe,
// never computed - the policy gives no interval on purpose.
case 'review_save': {
    [$id, $type] = plan_target();
    // AND THE DAY IT IS BOOKED FOR, which is not the day it is due by - the
    // review page carries both, as a step does. Only touched when it was sent.
    if (array_key_exists('booked_on', $_POST)) {
        try {
            plan_review_book($id, $type, (string)$_POST['booked_on']);
        } catch (InvalidArgumentException $e) {
            api_fail($e->getMessage());
        }
        $calendarPlan = [$id, $type];
    }
    echo json_encode(
        ['ok' => true, 'plan' => plan_review_save($id, $type, (string)($_POST['next_review_on'] ?? ''))],
        JSON_UNESCAPED_SLASHES);
    break;
}

default:
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'unknown action']);
}

// THE CENTRE'S OUTLOOK CALENDAR, AFTER THE ANSWER HAS GONE (29 Oct 2026) - the
// order /api/work follows for a tour. A booked day is saved the moment the row
// commits; copying it into Outlook is a Graph token and a round trip, and a
// failure there must not fail - or slow - the save. Every booked row of the plan
// is checked, because ending or reopening a plan moves all of them; a row already
// in step costs a hash and no call. plan_calendar_sweep() retries anything this
// misses. Reached only on success: api_fail() exits.
if ($calendarPlan) {
    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
    if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
    foreach (plan_calendar_ids($calendarPlan[0], $calendarPlan[1]) as $sid) {
        try { plan_calendar_sync($sid); }
        catch (Throwable $e) { error_log('plan_calendar_sync(' . $sid . '): ' . $e->getMessage()); }
    }
}
