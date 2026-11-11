<?php
// SUPPORT PLAN STEPS IN THE CENTRE'S OUTLOOK CALENDAR (29 Oct 2026).
//
// Broc: "For support plans, I want to link the next scheduled action to the
// centre's calendar; the same way tours are linked as appointments. Once an
// appointment time has passed, the next scheduled action needs to be setup as
// another appointment; or the previous one moved/edited maybe?"
//
// THE DAY IS BOOKED BY A PERSON, NEVER WORKED OUT. A step's due date is a
// deadline - "by the end of week 6" - and says nothing about when the family can
// come in, so a step gets a booked day the team types on its page and only a
// booked step reaches Outlook. Broc chose that over an automatic entry on every
// due date, then chose A DAY AND NEVER A TIME: each entry is ALL-DAY, and shows
// as free so it does not block the centre's whole day for anybody looking for a
// slot in it.
//
// ONE ENTRY PER STEP, NEVER ONE PER PLAN THAT MOVES ALONG - the answer to the
// "or moved" half of the question. The week 5 review and the week 6 meeting are
// two appointments, and an entry moved on to the next step would erase the
// record that the first one happened. The next step gets its own entry when
// somebody books it - the wizard lands on its page as the last one is ticked,
// and the card says "not booked" until then.
//
// A PASSED DAY IS HISTORY and its entry is never moved, edited or removed: it is
// what the day was, the rule a tour's entry follows after a no-show. Rebooking a
// row whose day has gone lets go of the old entry and makes a new one - see
// plan_booking_set(), which is also why the ISP's recurring review leaves a trail.
//
// AN UPCOMING ONE COMES OUT when the booking is cleared, the plan ends before
// the day, or the child is no longer flagged - nobody is coming. Ticking the step
// done does NOT remove it; see plan_booking_wanted(). An unticked flag is caught
// by the 15 minute sweep rather than by /api/board, which knows nothing of this.
//
// WHAT IT SAYS: the step, the child, the room, the week, who the process
// document says runs it, and a link to the plan. NEVER A NOTE: support plan
// notes are kept off the child's Kidsoft record on purpose because they are
// behaviour and diagnosis material (support-plans.md), and a centre calendar is
// read by more people than a Kidsoft record. NO ATTENDEES: an attendee is an
// invitation, an invitation is an email, and this entry is for the centre.
//
// EVERYTHING ELSE IS THE TOUR'S, deliberately: the mailbox
// (centres.calendar_mailbox), the Calendars.ReadWrite permission, the
// transaction id that makes a retried create safe, the write after the response
// has gone, the 15 minute sweep. The create / update / remove protocol is ONE
// function, calendar_row_write() in tour-calendar.php.

declare(strict_types=1);

require_once __DIR__ . '/simple-mysqli.php';
require_once __DIR__ . '/tour-calendar.php';        // calendar_row_write(), TOUR_CALENDAR_TZ, the app's URL
require_once __DIR__ . '/support-plan-steps.php';   // the steps, and the booking rules

/** One booked row with everything its entry is written from. */
function plan_calendar_row(int $id): ?array {
    $r = dbx()->query(
        'SELECT s.*, sp.plan_type, sp.placement_id, sp.outcome, sp.outcome_on,
                p.centre_id, p.year, p.child_first_name, p.child_last_name,
                p.has_behaviour_plan, p.has_individual_plan,
                r.name AS room_name, c.name AS centre_name, c.calendar_mailbox AS centre_mailbox
           FROM support_plan_steps s
           JOIN support_plans sp ON sp.id = s.plan_id
           LEFT JOIN placements p ON p.id = sp.placement_id
           LEFT JOIN rooms r ON r.id = p.room_id
           LEFT JOIN centres c ON c.id = p.centre_id
          WHERE s.id = ?', [$id], 'i')->fetch();
    return $r ?: null;
}

/** The step a row is for - or the ISP review, which is not one - or null if it no longer applies. */
function plan_calendar_step(array $r): ?array {
    if ($r['step_key'] === PLAN_REVIEW_KEY) {
        return ['title' => 'Review', 'week' => null,
                'who' => 'Nominated Supervisor, who oversees the review meetings'];
    }
    $ctx = ['year' => (int)$r['year'], 'room_tier' => room_type_key((string)$r['room_name'])];
    foreach (plan_steps((string)$r['plan_type'], $ctx) as $d) {
        if ($d['key'] === $r['step_key']) return $d;
    }
    return null;   // e.g. the early intervention step, after the child left the AA room
}

/** Should this row's day be in the centre's calendar? */
function plan_calendar_wanted(array $r): bool {
    if (empty($r['centre_mailbox'])) return false;
    // STILL ON THE PLAN. The flag is the only record of who is (support-plans.md);
    // a placement row that has gone reads as unflagged through the LEFT JOIN.
    if (empty($r[$r['plan_type'] === 'isp' ? 'has_individual_plan' : 'has_behaviour_plan'])) return false;
    return plan_booking_wanted($r['booked_on'], $r['outcome'] !== null, $r['outcome_on']);
}

/** The event a row SHOULD have in Outlook, or null for none. */
function plan_calendar_desired(array $r): ?array {
    if (!plan_calendar_wanted($r) || !($def = plan_calendar_step($r))) return null;

    // ALL-DAY: midnight to midnight in Sydney, which is the only shape Graph
    // accepts for isAllDay. Built in UTC so no DST rule can touch the date.
    $day   = new DateTimeImmutable(substr((string)$r['booked_on'], 0, 10), new DateTimeZone('UTC'));
    $child = trim($r['child_first_name'] . ' ' . $r['child_last_name']);
    $label = PLAN_TYPES[$r['plan_type']];

    $rows = [
        ['Child', $child],
        ['Room',  trim((string)$r['room_name']) . ', ' . (int)$r['year']],
        ['Plan',  $label . ($def['week'] ? ', week ' . (int)$def['week'] . ' of 10' : '')],
        ['Step',  $def['title']],
        ['Who',   $def['who']],
    ];
    $h = static fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
    $font = 'font-family:Segoe UI,Arial,sans-serif;font-size:14px';
    $html = '<table style="' . $font . '">';
    foreach ($rows as [$k, $v]) {
        $html .= '<tr><td style="color:#666;padding:2px 12px 2px 0">' . $h($k) . '</td><td>' . $h($v) . '</td></tr>';
    }
    $html .= '</table>';
    $base = tour_calendar_app_url();
    if ($base !== '') {
        $link = $base . '/support-plans?centre=' . (int)$r['centre_id'] . '&plan=' . (int)$r['placement_id']
              . '&type=' . rawurlencode((string)$r['plan_type']);
        $html .= '<p style="' . $font . '"><a href="' . $h($link) . '">Open the plan in the CRM</a></p>';
    }
    $html .= '<p style="font-family:Segoe UI,Arial,sans-serif;font-size:12px;color:#666">'
           . 'Added by the Little Miracles CRM. Change or clear the booked day on the plan there; '
           . 'a change made here in Outlook is not read back.</p>';

    return [
        'subject'  => $def['title'] . ': ' . $child . ' (' . $label . ')',
        'body'     => ['contentType' => 'HTML', 'content' => $html],
        'isAllDay' => true,
        'start'    => ['dateTime' => $day->format('Y-m-d\T00:00:00'), 'timeZone' => TOUR_CALENDAR_TZ],
        'end'      => ['dateTime' => $day->modify('+1 day')->format('Y-m-d\T00:00:00'), 'timeZone' => TOUR_CALENDAR_TZ],
        'showAs'   => 'free',
        'location' => ['displayName' => 'Little Miracles ' . $r['centre_name']],
    ];
}

/**
 * Bring one row's entry into line with its booked day.
 *
 * @return string 'in', 'none', 'removed' or 'failed' - calendar_row_write()'s answers
 */
function plan_calendar_sync(int $id): string {
    $r = plan_calendar_row($id);
    if (!$r) return 'none';
    // A PASSED DAY IS HISTORY: nothing about its entry is sent again, whatever
    // has changed since - a renamed room, a closed plan, an unticked flag.
    if (!empty($r['calendar_event_id']) && plan_booked_past($r['booked_on'])) return 'in';
    return calendar_row_write('support_plan_steps', $r, plan_calendar_desired($r));
}

/** Every row of one plan that has, or had, a booked day - what a write to the plan may have moved. */
function plan_calendar_ids(int $placementId, string $type): array {
    return array_map('intval', dbx()->query(
        'SELECT s.id FROM support_plan_steps s JOIN support_plans sp ON sp.id = s.plan_id
          WHERE sp.placement_id = ? AND sp.plan_type = ?
            AND (s.booked_on IS NOT NULL OR s.calendar_event_id IS NOT NULL)',
        [$placementId, $type], 'is')->fetchAll('col'));
}

/**
 * Every row whose entry may be out of step: a day from yesterday onwards (an
 * older one is history and is not chased, the tour sweep's rule), or an entry
 * whose booking was cleared. Rows already in step cost a hash and no call.
 *
 * @return array{checked:int, in:int, removed:int, failed:int, none:int}
 */
function plan_calendar_sweep(bool $dryRun = false): array {
    $ids = dbx()->query(
        'SELECT id FROM support_plan_steps
          WHERE booked_on >= CURDATE() - INTERVAL 1 DAY
             OR (booked_on IS NULL AND calendar_event_id IS NOT NULL)
          ORDER BY booked_on'
    )->fetchAll('col');
    $out = ['checked' => count($ids), 'in' => 0, 'removed' => 0, 'failed' => 0, 'none' => 0];
    if ($dryRun) return $out;
    foreach ($ids as $id) {
        try {
            $out[plan_calendar_sync((int)$id)]++;
        } catch (Throwable $e) {
            $out['failed']++;
            error_log('plan_calendar_sync(' . $id . '): ' . $e->getMessage());
        }
    }
    return $out;
}
