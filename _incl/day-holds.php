<?php
// HELD DAYS - a place-day kept for a child who is not booked on it.
//
// "Tuggerah SS Silver has a boy who is on a Behaviour Support Plan but currently
// has dropped the Friday... how do we hold his Friday? The bookings are removed
// as the parents do pay for those days as it is while we try and work together."
// - a centre, 10 Sep 2026.
//
// MEASURED THE SAME MORNING, and it is the whole argument for the feature.
// Logan Camilleri holds Shining Stars Silver Mon/Tue/Wed and is flagged for a
// Behaviour Support Plan. That room's FRIDAY read "1 place free" on the 2026
// board - his place. Every other cell in the room was full, so it was also the
// only Friday in that room anybody could have been offered, which is precisely
// the cell fill.php would have put at the top of a shortlist.
//
// KIDSOFT CANNOT ANSWER THIS AND NEVER WILL. The only record of a place being
// held is the booking, and the booking is exactly what has been removed. This is
// ours by the same test as offers, work items and the internal day waitlist:
// nobody could type it anywhere else.
//
// IT IS SUPPLY, NOT DEMAND - the whole distinction from a wish.
// internal_day_requests records a family ASKING for a day they do not have, and
// it makes a cell `grantable`, the loudest state on the grid. A hold is the
// opposite fact: this day is already spoken for. So it SUBTRACTS from the free
// figure the way a pending offer and a re-enrolment hold do, and it never puts a
// dot on the grid - the dot means somebody is waiting, and nobody is.
//
// NOTHING EXPIRES (Broc, 10 Sep 2026, choosing between three options). A hold
// runs until a person releases it. The one automatic close is REALITY, the rule
// work_autoclose() and internal_requests_close_met() already follow: when the
// derived board shows the child booked in that room on that weekday again, the
// hold has done its job. See day_holds_close_booked().

require_once __DIR__ . '/simple-mysqli.php';
require_once __DIR__ . '/audit.php';
require_once __DIR__ . '/dates.php';

/**
 * Every open hold at a centre for a year, grouped by room and weekday.
 *
 * @return array ourRoomId => weekday => list of holds
 */
function day_holds_year(int $centreId, int $year): array {
    $rows = dbx()->query(
        "SELECT * FROM day_holds
          WHERE centre_id = ? AND year = ? AND status = 'open'
          ORDER BY child_name, weekday",
        [$centreId, $year], 'ii'
    )->fetchAll();

    $out = [];
    foreach ($rows as $r) {
        $out[(int)$r['room_id']][(int)$r['weekday']][] = day_hold_shape($r);
    }
    return $out;
}

/**
 * One row as every screen reads it.
 *
 * DATES ARE FORMATTED HERE. These are Sydney wall-clock strings and running one
 * through new Date() in the viewer's browser reinterprets it in their timezone -
 * the rule _incl/dates.php exists for. `held_from` is kept raw beside the
 * display string because the support-plans screen sorts on it.
 */
function day_hold_shape(array $r): array {
    return [
        'id'           => (int)$r['id'],
        'room_id'      => (int)$r['room_id'],
        'year'         => (int)$r['year'],
        'weekday'      => (int)$r['weekday'],
        'ks_id'        => $r['kidsoft_child_id'],
        'placement_id' => $r['placement_id'] === null ? null : (int)$r['placement_id'],
        'name'         => $r['child_name'],
        'reason'       => $r['reason'],
        'held_from'    => substr((string)$r['held_from'], 0, 10),
        'held_from_display' => syd_date($r['held_from']),
        'recorded_by'  => $r['created_by'],
    ];
}

/**
 * The weekdays currently held for one child in one room and year, so the dialog
 * that records them opens on what is already there.
 *
 * Keyed on the Kidsoft ChildID where there is one, and on our placements row
 * only for a provisional child. NEVER on a name - the identity trap this app has
 * hit twice, in both languages, is a key that quietly matches everybody.
 */
function day_holds_for_child(?string $ksId, ?int $placementId, int $roomId, int $year): array {
    if ($ksId === null && $placementId === null) return [];
    $rows = $ksId !== null
        ? dbx()->query("SELECT * FROM day_holds
                         WHERE kidsoft_child_id = ? AND room_id = ? AND year = ? AND status = 'open'
                         ORDER BY weekday", [$ksId, $roomId, $year], 'sii')->fetchAll()
        : dbx()->query("SELECT * FROM day_holds
                         WHERE placement_id = ? AND room_id = ? AND year = ? AND status = 'open'
                         ORDER BY weekday", [$placementId, $roomId, $year], 'iii')->fetchAll();
    return array_map('day_hold_shape', $rows);
}

/**
 * Open holds for one centre-year keyed by child, for the room roster.
 *
 * The mirror of internal_requests_by_child(), and keyed identically ('k' .
 * ChildID for a Kidsoft child, 'p' . our row for a provisional one) because the
 * roster reads the two side by side off one child's Mon-Fri strip. Two key
 * shapes for one child is the null-key trap that once collapsed every child in a
 * room into a single entry.
 *
 * `ids` is per weekday: a hold is one row per day, so releasing Friday from the
 * strip has to reach Friday's row and no other.
 *
 * @return array roomId => childKey => ['days'=>[wd,...],'ids'=>[wd=>id],'reason'=>?string,...]
 */
function day_holds_by_child(int $centreId, int $year): array {
    $rows = dbx()->query(
        "SELECT * FROM day_holds
          WHERE centre_id = ? AND year = ? AND status = 'open'
          ORDER BY weekday",
        [$centreId, $year], 'ii'
    )->fetchAll();

    $out = [];
    foreach ($rows as $r) {
        $key = $r['kidsoft_child_id'] !== null
            ? 'k' . $r['kidsoft_child_id']
            : 'p' . (int)$r['placement_id'];
        $rid = (int)$r['room_id'];
        $wd  = (int)$r['weekday'];
        if (!isset($out[$rid][$key])) {
            $out[$rid][$key] = [
                'days' => [], 'ids' => [],
                // The reason and the start date belong to the arrangement, so
                // every day of it carries the same pair and the last row read
                // wins - day_hold_save() writes them to all of them together.
                'reason'    => $r['reason'],
                'held_from' => substr((string)$r['held_from'], 0, 10),
                'held_from_display' => syd_date($r['held_from']),
            ];
        }
        $out[$rid][$key]['days'][] = $wd;
        $out[$rid][$key]['ids'][$wd] = (int)$r['id'];
    }
    return $out;
}

/**
 * Set the days held for one child in one room and year to exactly $days.
 *
 * The dialog shows the whole Mon-Fri strip, so a save is a statement about all
 * five days, not an increment: a day dropped from the strip is a day released.
 * Releasing that way records WHY in the same words as the manual button, because
 * "the box was unticked" is not a reason anybody can read back later.
 *
 * @param array $d centre_id, room_id, year, days[], kidsoft_child_id|placement_id,
 *                 child_name, reason, held_from, created_by
 * @return array{held:int[], released:int[]}
 */
function day_hold_save(array $d): array {
    $centreId = (int)($d['centre_id'] ?? 0);
    $roomId   = (int)($d['room_id'] ?? 0);
    $year     = (int)($d['year'] ?? 0);
    $ksId     = ($d['kidsoft_child_id'] ?? '') !== '' ? (string)$d['kidsoft_child_id'] : null;
    $placeId  = (int)($d['placement_id'] ?? 0) ?: null;
    $name     = trim((string)($d['child_name'] ?? ''));
    $days     = array_values(array_unique(array_filter(
        array_map('intval', $d['days'] ?? []), static fn($w) => $w >= 1 && $w <= 5)));
    sort($days);

    if (!$centreId || !$roomId || !$year) throw new InvalidArgumentException('centre, room and year are required');
    if ($name === '') throw new InvalidArgumentException('A hold has to name a child.');
    if ($ksId === null && $placeId === null) {
        throw new InvalidArgumentException('A hold has to point at a child on the board.');
    }

    $reason = trim((string)($d['reason'] ?? ''));
    $reason = $reason === '' ? null : mb_substr($reason, 0, 500);
    $from   = (string)($d['held_from'] ?? '') !== ''
        ? substr((string)$d['held_from'], 0, 10)
        : date_create('today', new DateTimeZone('Australia/Sydney'))->format('Y-m-d');

    $open = [];
    foreach (day_holds_for_child($ksId, $placeId, $roomId, $year) as $h) $open[$h['weekday']] = $h;

    $held = $released = [];
    foreach ($open as $wd => $h) {
        if (in_array($wd, $days, true)) continue;
        day_hold_release($h['id'], 'No longer held.');
        $released[] = $wd;
    }
    foreach ($days as $wd) {
        if (isset($open[$wd])) {
            // The reason and the start date belong to the arrangement, not to
            // one weekday, so editing either updates every day it covers.
            dbx()->query('UPDATE day_holds SET reason = ?, held_from = ? WHERE id = ?',
                         [$reason, $from, $open[$wd]['id']], 'ssi');
            $held[] = $wd;
            continue;
        }
        dbx()->query(
            'INSERT INTO day_holds (centre_id, room_id, year, weekday, kidsoft_child_id,
                    placement_id, child_name, reason, held_from, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$centreId, $roomId, $year, $wd, $ksId, $placeId, mb_substr($name, 0, 160),
             $reason, $from, $d['created_by'] ?? ($_SESSION['user']['email'] ?? null)]
        );
        audit('day_hold.create', 'day_hold', dbx()->insertId(), [
            'child' => $name, 'room_id' => $roomId, 'year' => $year,
            'weekday' => $wd, 'reason' => $reason,
        ]);
        $held[] = $wd;
    }
    if ($open && ($held || $released)) {
        // One entry for the amendment as a whole, beside the per-day creates:
        // "the reason changed" is a fact about the arrangement.
        audit('day_hold.update', 'day_hold', (int)reset($open)['id'], [
            'held' => $held, 'released' => $released, 'reason' => $reason,
        ]);
    }
    sort($held);
    sort($released);
    return ['held' => $held, 'released' => $released];
}

/**
 * Give one held day back to the board.
 *
 * The row is KEPT and marked released rather than deleted: a place that was out
 * of use for two months, and why, is the history the support-plans screen exists
 * to show, and a delete would take it with them.
 */
function day_hold_release(int $id, ?string $note): bool {
    $h = dbx()->query("SELECT * FROM day_holds WHERE id = ? AND status = 'open'", [$id], 'i')->fetch();
    if (!$h) return false;
    $note = $note !== null ? mb_substr(trim($note), 0, 500) : null;
    dbx()->query(
        "UPDATE day_holds SET status = 'released', released_note = ?, released_at = NOW() WHERE id = ?",
        [$note ?: null, $id], 'si'
    );
    audit('day_hold.release', 'day_hold', $id, [
        'child' => $h['child_name'], 'room_id' => (int)$h['room_id'],
        'weekday' => (int)$h['weekday'], 'note' => $note,
    ]);
    return true;
}

/**
 * RELEASED BY KIDSOFT, NOT BY A CHECKBOX - work_autoclose()'s rule, and the same
 * one internal_requests_close_met() follows.
 *
 * When the derived board shows the child booked in that room on that weekday
 * again, the arrangement this hold recorded is over: the family is back and the
 * place is theirs through the booking, which is a stronger claim than ours. Left
 * open it would go on subtracting a place that is already counted in `placed`,
 * and the cell would read one short for ever.
 *
 * DAY-LEVEL, because a child who has come back on Thursday and not yet on Friday
 * is half back. Matched on kidsoft_child_id ONLY, never a name - closing the wrong
 * family's hold puts a place they are being kept back on sale, which is the one
 * outcome this whole feature exists to prevent.
 *
 * A PROVISIONAL CHILD IS SKIPPED, not missed. They have no Kidsoft booking that could
 * come back, so there is nothing for reality to close against; their hold ends when a
 * person releases it. board_load()'s own guard still keeps the figure right if their
 * annotation grows the day.
 *
 * Called from cells_year(), which already holds a board - deriving a second one
 * here would be two answers inside one response, the fault board_load() and
 * fill.php were merged to remove.
 *
 * @return int how many holds were released
 */
function day_holds_close_booked(array $board): int {
    $centreId = (int)($board['centre']['id'] ?? 0);
    $year     = (int)($board['year'] ?? 0);
    if (!$centreId || !$year) return 0;

    $rows = dbx()->query(
        "SELECT * FROM day_holds
          WHERE centre_id = ? AND year = ? AND status = 'open' AND kidsoft_child_id IS NOT NULL",
        [$centreId, $year], 'ii'
    )->fetchAll();
    if (!$rows) return 0;

    // (room, weekday) => set of ChildIDs the board says are booked there.
    $booked = [];
    foreach ($board['rooms'] as $r) {
        foreach ($r['placements'] as $p) {
            if (empty($p['kidsoft_child_id'])) continue;
            foreach ($p['days'] as $wd) $booked[(int)$r['id'] . ':' . (int)$wd][$p['kidsoft_child_id']] = true;
        }
    }

    $n = 0;
    foreach ($rows as $h) {
        $key = (int)$h['room_id'] . ':' . (int)$h['weekday'];
        if (!isset($booked[$key][$h['kidsoft_child_id']])) continue;
        if (day_hold_release((int)$h['id'], 'Booked in Kidsoft again.')) $n++;
    }
    return $n;
}
