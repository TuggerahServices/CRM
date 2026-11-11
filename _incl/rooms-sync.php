<?php
// Rooms, read from Kidsoft.
//
// A ROOM IS NOT OURS AND NEVER WAS. Its name, its age band and whether it is
// operating all live in Service/getRooms, and this file is what stops a human
// typing them in. Same rule as the board and as capacity: anything Kidsoft can
// answer, READ from Kidsoft, because a copy drifts the moment Kidsoft changes.
//
// The Rooms screen used to offer Add room, an editable name, editable age bands,
// Archive and a Kidsoft mapping dropdown. Measured 11 Aug 2026 before removing
// them: all 40 rooms were linked, and every stored name and age band agreed with
// Kidsoft exactly (0 differences group-wide). Our 4 inactive rooms were exactly
// the 4 Kidsoft reports Active='No'. So the whole editable surface was a copy
// that happened to be right, maintained by nobody, one typo away from attaching
// bookings to a room nobody could find.
//
// WHAT THIS FILE OWNS: name, min/max age, active, and the row's existence. What
// it must never touch: notes and sub-quotas, which are ours because Kidsoft has
// no equivalent.
//
// TWO TRAPS, both paid for once already:
//
// 1. `Active` IS THE STRING 'Yes'/'No', NOT A BOOLEAN. The old code tested
//    `!($r['Active'] ?? false)`, and 'No' is a truthy PHP string - so every
//    inactive room passed the test as active. That is very likely the whole
//    origin of this project's long-standing "Kidsoft lists both halves of its
//    near-duplicate pairs as active" claim: measured today, Tuggerah's phantom
//    "Amazing Asteroids Purple" reads Active='No' and the group is a clean
//    36 Yes / 5 No. room_ks_active() compares properly.
//
// 2. A ROOM IS ONLY CREATED IF IT IS ACTUALLY OPERATING, which is decided by
//    getUtilisation reporting operational positions for it - never by
//    getRooms.Active alone. That is the documented test, and it is what kept the
//    phantom near-duplicate off the board when the centres were first seeded.
//    Creating a room that does not exist is the expensive direction: it dilutes
//    every fill figure in the app and invites somebody to sell a place in it.
//    Not creating one costs a night's wait. So when the utilisation read comes
//    back empty - a Kidsoft outage, or a centre it will not answer for --
//    NOTHING is created or retired on that run.
//
// A room we hold that Kidsoft does not return at all is left completely alone
// and reported. It is far more likely to be a scoping change or a bad response
// than a room that has ceased to exist, and retiring it would silently take its
// children off the board.

require_once __DIR__ . '/capacity.php';   // capacity_util_year()
require_once __DIR__ . '/kidsoft-cache.php';
require_once __DIR__ . '/rooms.php';
require_once __DIR__ . '/audit.php';

/** Kidsoft's Active flag, which is the STRING 'Yes'/'No'. See trap 1 above. */
function room_ks_active(array $k): bool {
    $v = $k['Active'] ?? null;
    if (is_bool($v)) return $v;
    if (is_int($v))  return $v === 1;
    return is_string($v) && strcasecmp(trim($v), 'yes') === 0;
}

/** Kidsoft age bound in months, or null when unbounded. */
function room_ks_age(mixed $v): ?int {
    return ($v === null || $v === '') ? null : (int)$v;
}

/**
 * Refresh one centre's rooms from Kidsoft.
 *
 * $util lets the caller share the utilisation read with capacity_sync_year();
 * pass null and it is read here.
 *
 * @return array{checked:int, created:int, renamed:int, aged:int, retired:int,
 *               restored:int, missing:int, unlinked:int, clashes:list<string>}
 */
function rooms_sync_centre(int $centreId, int $year, ?int $ttl = null, ?array $util = null): array {
    $out = ['checked' => 0, 'created' => 0, 'renamed' => 0, 'aged' => 0, 'retired' => 0,
            'restored' => 0, 'missing' => 0, 'unlinked' => 0, 'clashes' => []];

    $centre = centre_get($centreId);
    if (!$centre) return $out;
    $sid = $centre['kidsoft_service_id'];

    $ks = kidsoft_cached('Service', 'getRooms', ['ServiceID' => $sid], $ttl)['Rooms'][$sid] ?? [];
    if (!$ks) return $out;                       // no answer: change nothing

    $util ??= capacity_util_year($sid, $year, $ttl);
    // Trap 2: with no utilisation there is no "is it real" test, so this run
    // refreshes fields only.
    $canJudgeExistence = (bool)$util;
    $operating = static function (string $rid) use ($util): bool {
        $d = $util[$rid] ?? null;
        return $d !== null && array_filter($d, static fn($v) => $v !== null) !== [];
    };

    $byKs = [];
    foreach (dbx()->query('SELECT * FROM rooms WHERE centre_id = ?', [$centreId], 'i')->fetchAll() as $r) {
        if ($r['kidsoft_room_id']) $byKs[$r['kidsoft_room_id']] = $r;
        else $out['unlinked']++;                 // hand-created legacy row; not ours to judge
    }

    $detail = ['created' => [], 'renamed' => [], 'aged' => [], 'retired' => [], 'restored' => []];
    $seen = [];

    foreach ($ks as $k) {
        $rid = (string)($k['RoomID'] ?? '');
        if ($rid === '') continue;
        $seen[$rid] = true;
        $out['checked']++;

        $name = trim((string)($k['Name'] ?? ''));
        if ($name === '') continue;
        $min  = room_ks_age($k['MinAge'] ?? null);
        $max  = room_ks_age($k['MaxAge'] ?? null);
        // A room still reporting operational positions is operating, whatever the
        // flag says. The flag alone is the weaker signal of the two.
        $live = room_ks_active($k) || ($canJudgeExistence && $operating($rid));
        $mine = $byKs[$rid] ?? null;

        if (!$mine) {
            // See trap 2: existence is decided by utilisation, never by the flag.
            if (!$canJudgeExistence || !$operating($rid)) continue;
            try {
                dbx()->query(
                    'INSERT INTO rooms (centre_id, kidsoft_room_id, name, min_age_months,
                                        max_age_months, active, sort_order)
                     VALUES (?, ?, ?, ?, ?, 1, 500)',
                    [$centreId, $rid, $name, $min, $max]
                );
            } catch (Throwable $e) {
                // Almost certainly uq_rooms_centre_name: a room of this name is
                // already here under a different RoomID. Reported, never merged.
                $out['clashes'][] = $name;
                continue;
            }
            $out['created']++;
            $detail['created'][] = $name;
            continue;
        }

        $sets = [];
        $vals = [];
        if (trim((string)$mine['name']) !== $name) {
            $sets[] = 'name = ?'; $vals[] = $name;
            $out['renamed']++;
            $detail['renamed'][] = $mine['name'] . ' -> ' . $name;
        }
        if (room_ks_age($mine['min_age_months']) !== $min || room_ks_age($mine['max_age_months']) !== $max) {
            $sets[] = 'min_age_months = ?'; $vals[] = $min;
            $sets[] = 'max_age_months = ?'; $vals[] = $max;
            $out['aged']++;
            $detail['aged'][] = $name . ': ' . $mine['min_age_months'] . '-' . $mine['max_age_months']
                              . ' -> ' . $min . '-' . $max;
        }
        // Only judged when the utilisation read succeeded, so an outage cannot
        // empty a centre's board.
        if ($canJudgeExistence && (int)$mine['active'] !== ($live ? 1 : 0)) {
            $sets[] = 'active = ?'; $vals[] = $live ? 1 : 0;
            if ($live) { $out['restored']++; $detail['restored'][] = $name; }
            else       { $out['retired']++;  $detail['retired'][]  = $name; }
        }
        if (!$sets) continue;

        $vals[] = (int)$mine['id'];
        try {
            dbx()->query('UPDATE rooms SET ' . implode(', ', $sets) . ' WHERE id = ?', $vals);
        } catch (Throwable $e) {
            $out['clashes'][] = $name;
        }
    }

    foreach ($byKs as $rid => $r) if (!isset($seen[$rid])) $out['missing']++;

    if ($out['created'] || $out['renamed'] || $out['aged'] || $out['retired'] || $out['restored']) {
        audit('rooms.sync', 'centre', $centreId,
            ['year' => $year] + array_filter($detail, static fn($v) => $v !== []));
    }
    return $out;
}

/** Sync every active centre's rooms. One centre failing must not stop the rest. */
function rooms_sync_all(int $year, ?int $ttl = null): array {
    $t = ['checked' => 0, 'created' => 0, 'renamed' => 0, 'aged' => 0, 'retired' => 0,
          'restored' => 0, 'missing' => 0, 'unlinked' => 0, 'clashes' => []];
    foreach (centres_all() as $c) {
        try { $r = rooms_sync_centre((int)$c['id'], $year, $ttl); }
        catch (RuntimeException $e) { continue; }
        foreach ($t as $k => $v) $t[$k] = is_array($v) ? array_merge($v, $r[$k]) : $v + $r[$k];
    }
    return $t;
}
