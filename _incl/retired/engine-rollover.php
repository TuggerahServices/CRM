<?php
// Annual roll-over: carrying a year's board into the next one.
//
// This is the enrolments team's peak workload. Per the re-enrolment procedure,
// offers go out to current families in early August, each keeping their existing
// days but transitioning into an age-appropriate room, and whatever is left goes
// to the waitlist from late August.
//
// The hard part is the transition. Age bands OVERLAP heavily - at Tuggerah a
// 30-month-old is accepted by seven rooms - so there is no single correct
// destination and nothing here assigns one. What it does is sort every child
// into the three questions the team actually has to answer:
//
//   stays   - their current room still accepts them next year. Nothing to decide.
//   moves   - their room no longer accepts them, but others do. Pick one.
//   leaves  - no room at the centre accepts them. They are going to school, and
//              need an end-of-care letter (the workbook's "Children Leaving" sheet).
//
// Only 'stays' is safe to apply in bulk. The rest are shown for a human.

require_once __DIR__ . '/simple-mysqli.php';
require_once __DIR__ . '/rooms.php';
require_once __DIR__ . '/board.php';

/**
 * The colour stream a room belongs to, or null.
 *
 * Tuggerah runs parallel streams - "Shining Stars Pink", "Amazing Asteroids
 * Pink" - and a child moving up tends to stay in their stream. Used ONLY to
 * order suggestions; it never decides anything, and centres without coloured
 * rooms (Terrigal, Kanwal) are simply unaffected.
 */
function room_stream(string $name): ?string {
    if (preg_match('/\b(pink|silver|purple|blue|green|gold)\b/i', $name, $m)) {
        return strtolower($m[1]);
    }
    return null;
}

/**
 * Plan the transition of every placement in $fromYear into $toYear.
 *
 * @return array{from:int, to:int, groups:array, counts:array, target_exists:int}
 */
function rollover_plan(int $centreId, int $fromYear, int $toYear): array {
    $centre = centre_get($centreId);
    if (!$centre) throw new InvalidArgumentException('unknown centre');

    // Rooms as they will be in the TARGET year - a room can close, or change
    // capacity, between years.
    $targetRooms = rooms_for_centre($centreId, $toYear);
    $roomById = [];
    foreach ($targetRooms as $r) $roomById[(int)$r['id']] = $r;

    $existing = (int)dbx()->query(
        'SELECT COUNT(*) c FROM placements WHERE centre_id = ? AND year = ?',
        [$centreId, $toYear], 'ii'
    )->fetch()['c'];

    $rows = dbx()->query(
        "SELECT p.*, r.name AS room_name,
                GROUP_CONCAT(pd.weekday ORDER BY pd.weekday) AS days
         FROM placements p
         JOIN rooms r ON r.id = p.room_id
         LEFT JOIN placement_days pd ON pd.placement_id = p.id
         WHERE p.centre_id = ? AND p.year = ?
         GROUP BY p.id
         ORDER BY p.child_last_name, p.child_first_name",
        [$centreId, $fromYear], 'ii'
    )->fetchAll();

    $groups = ['stays' => [], 'moves' => [], 'leaves' => [], 'unknown' => []];

    foreach ($rows as $p) {
        $days = $p['days'] === null ? [] : array_map('intval', explode(',', $p['days']));

        // Someone already marked as finishing is not a transition question.
        if ($p['status'] === 'ending_care') {
            $groups['leaves'][] = rollover_row($p, $days, null, [], 'Already marked as ending care');
            continue;
        }

        // Age is judged at the START of the target year. Children age through the
        // year, so 'ages_out_at' below reports when the fit stops holding.
        $ageStart = age_months($p['child_dob'], $toYear . '-01-01');
        if ($ageStart === null) {
            $groups['unknown'][] = rollover_row($p, $days, null, [], 'No date of birth recorded');
            continue;
        }

        $currentRoom = $roomById[(int)$p['room_id']] ?? null;
        $staysPut = $currentRoom && room_accepts_age($currentRoom, $ageStart);

        // Every room that would take them, best suggestion first.
        $candidates = [];
        foreach ($targetRooms as $r) {
            if ((int)$r['id'] === (int)$p['room_id']) continue;
            if (!room_accepts_age($r, $ageStart)) continue;
            $candidates[] = [
                'room_id'   => (int)$r['id'],
                'name'      => $r['name'],
                'capacity'  => $r['capacity_max'],
                'min'       => $r['min_age_months'],
                'max'       => $r['max_age_months'],
                'same_stream' => room_stream($r['name']) !== null
                                 && room_stream($r['name']) === room_stream((string)$p['room_name']),
                // How long the fit lasts: a room they age out of in March is a
                // worse suggestion than one that carries them all year.
                'months_left' => $r['max_age_months'] === null
                    ? null : max(0, (int)$r['max_age_months'] - $ageStart),
            ];
        }
        usort($candidates, static function ($a, $b) {
            // Same colour stream first (the team's own convention), then the room
            // that keeps them longest.
            return [$a['same_stream'] ? 0 : 1, -($a['months_left'] ?? 999)]
               <=> [$b['same_stream'] ? 0 : 1, -($b['months_left'] ?? 999)];
        });

        if ($staysPut) {
            $groups['stays'][] = rollover_row($p, $days, $currentRoom, $candidates, null, $ageStart);
        } elseif ($candidates) {
            $groups['moves'][] = rollover_row($p, $days, null, $candidates, null, $ageStart);
        } else {
            $groups['leaves'][] = rollover_row(
                $p, $days, null, [],
                'Too old for every room at this centre in ' . $toYear, $ageStart
            );
        }
    }

    return [
        'from'          => $fromYear,
        'to'            => $toYear,
        'centre'        => ['id' => $centreId, 'name' => $centre['name']],
        'groups'        => $groups,
        'counts'        => array_map('count', $groups),
        'target_exists' => $existing,
        'rooms'         => array_map(static fn($r) => [
            'id' => (int)$r['id'], 'name' => $r['name'],
            'min' => $r['min_age_months'], 'max' => $r['max_age_months'],
            'capacity' => $r['capacity_max'],
        ], $targetRooms),
    ];
}

/** Shape one child for the plan. */
function rollover_row(array $p, array $days, ?array $stayRoom, array $candidates,
                      ?string $note = null, ?int $ageStart = null): array {
    return [
        'placement_id' => (int)$p['id'],
        'name'         => trim($p['child_first_name'] . ' ' . $p['child_last_name']),
        'first_name'   => $p['child_first_name'],
        'last_name'    => $p['child_last_name'],
        'dob'          => $p['child_dob'],
        'kidsoft_child_id' => $p['kidsoft_child_id'],
        'from_room'    => $p['room_name'],
        'from_room_id' => (int)$p['room_id'],
        'days'         => $days,
        'status'       => $p['status'],
        'age_start'    => $ageStart,
        'stay_room_id' => $stayRoom ? (int)$stayRoom['id'] : null,
        'candidates'   => $candidates,
        'note'         => $note,
        'flags'        => array_values(array_filter([
            $p['is_sibling'] ? 'sibling' : null,
            $p['is_staff_child'] ? 'staff' : null,
            $p['needs_two_educators'] ? 'two_educators' : null,
            $p['has_behaviour_plan'] ? 'behaviour' : null,
        ])),
    ];
}

/**
 * Copy one child into the target year.
 *
 * Status resets to 'unset' - carrying "Confirmed" across would assert that next
 * year's place is agreed when the family has not been asked yet, which is the
 * entire point of the August offer round. Returns the new placement id, or the
 * existing one if this child is already on the target board.
 */
function rollover_place(array $p, int $roomId, int $toYear, array $days): int {
    // Idempotent for a linked child, so re-running a partly-applied roll-over
    // does not double up.
    if (!empty($p['kidsoft_child_id'])) {
        $found = dbx()->query(
            'SELECT id FROM placements WHERE kidsoft_child_id = ? AND room_id = ? AND year = ?',
            [$p['kidsoft_child_id'], $roomId, $toYear]
        )->fetch();
        if ($found) return (int)$found['id'];
    }

    $room = room_get($roomId);
    if (!$room) throw new RuntimeException('room not found');

    dbx()->query(
        'INSERT INTO placements (centre_id, room_id, year, kidsoft_child_id, child_first_name,
                child_last_name, child_dob, status, is_sibling, is_staff_child,
                needs_two_educators, has_behaviour_plan, notes)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
        [$room['centre_id'], $roomId, $toYear, $p['kidsoft_child_id'] ?: null,
         $p['child_first_name'], $p['child_last_name'], $p['child_dob'], 'unset',
         $p['is_sibling'], $p['is_staff_child'], $p['needs_two_educators'],
         $p['has_behaviour_plan'], $p['notes']]
    );
    $id = dbx()->insertId();
    foreach ($days as $d) {
        $d = (int)$d;
        if ($d < 1 || $d > 5) continue;
        dbx()->query('INSERT INTO placement_days (placement_id, weekday) VALUES (?, ?)', [$id, $d]);
    }
    return $id;
}
