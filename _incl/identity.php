<?php
// Child identity. Kidsoft owns it; this keeps our copy honest.
//
// THE POINT (see CLAUDE.md, "eliminate the team's manual work"): a placement's
// identity is its kidsoft_child_id. The name and DOB columns on `placements` are
// a SYSTEM-WRITTEN CACHE of what Kidsoft holds - refreshed here, never typed by
// a person while the row is linked. Staff typing names is what created every
// piece of residual drift on the board, so the cure is to stop them having to.
//
// A row with NO kidsoft_child_id is PROVISIONAL: a child pencilled in ahead of
// Kidsoft (11 such children sit on the 2026 Tuggerah board with surnames that
// appear nowhere in Kidsoft's 1,467 records). Those keep their typed names until
// somebody links them, which is what the picker in the placement editor is for.
//
// Kidsoft stays STRICTLY READ-ONLY. Everything here reads.

require_once __DIR__ . '/simple-mysqli.php';
require_once __DIR__ . '/kidsoft-cache.php';
require_once __DIR__ . '/rooms.php';

/** How long a cached name may go unrefreshed before we re-read Kidsoft. */
const IDENTITY_TTL_HOURS = 24;

/**
 * Present a name for reading.
 *
 * Kidsoft stores names in BLOCK CAPITALS ("BEAR CURRIE"). The workbooks this app
 * replaces used ordinary case, and a whole board in caps reads as broken, so the
 * cached copy is cased for display. This never affects logic: every comparison
 * in the app goes through name_fold(), which lowercases first.
 *
 * Deliberately conservative:
 *  - Input that is ALREADY mixed case is returned untouched. "van der Berg" and
 *    "DeSilva" are correct as typed and title-casing would wreck them.
 *  - "Mc" is capitalised (McDonald); "Mac" is NOT. This very dataset contains
 *    MACHADO, which a Mac rule would turn into "MacHado".
 */
function name_case(string $s): string {
    $s = trim($s);
    if ($s === '') return $s;
    if ($s !== mb_strtoupper($s, 'UTF-8') && $s !== mb_strtolower($s, 'UTF-8')) return $s;

    $s = mb_convert_case(mb_strtolower($s, 'UTF-8'), MB_CASE_TITLE, 'UTF-8');
    // O'Loughlin, D'Angelo - one or two letters, an apostrophe, then a letter.
    $s = preg_replace_callback("/\\b(\\p{L}{1,2}')(\\p{L})/u",
        static fn($m) => $m[1] . mb_strtoupper($m[2], 'UTF-8'), $s);
    // McDonald, McEwan. Not Mac (Machado, Mackenzie, Macey are all ordinary).
    $s = preg_replace_callback('/\bMc(\p{Ll})/u',
        static fn($m) => 'Mc' . mb_strtoupper($m[1], 'UTF-8'), $s);
    return $s;
}

/** ChildID => Kidsoft child record, for one service. Cached 6h upstream. */
function kidsoft_children(string $serviceId): array {
    static $memo = [];
    if (isset($memo[$serviceId])) return $memo[$serviceId];

    $map = [];
    foreach (kidsoft_rows(kidsoft_cached('Children', 'getChildren', ['ServiceID' => $serviceId]),
                          'Children', $serviceId) as $c) {
        if (!empty($c['ChildID'])) $map[$c['ChildID']] = $c;
    }
    return $memo[$serviceId] = $map;
}

/**
 * Refresh the cached name/DOB of this centre's LINKED placements from Kidsoft.
 *
 * Only rows that have never been synced or whose sync has gone stale are
 * touched, so the common case is an indexed query matching nothing. Safe to call
 * on page load; also called nightly.
 *
 * @return int rows actually updated
 */
function placements_sync_identity(int $centreId): int {
    $centre = centre_get($centreId);
    if (!$centre) return 0;

    $due = dbx()->query(
        'SELECT id, kidsoft_child_id, child_first_name, child_last_name, child_dob
         FROM placements
         WHERE centre_id = ? AND kidsoft_child_id IS NOT NULL
           AND (identity_synced_at IS NULL OR identity_synced_at < (NOW() - INTERVAL ? HOUR))',
        [$centreId, IDENTITY_TTL_HOURS], 'ii'
    )->fetchAll();
    if (!$due) return 0;

    try {
        $children = kidsoft_children($centre['kidsoft_service_id']);
    } catch (RuntimeException $e) {
        // Kidsoft unreachable: keep serving the cached names. That is precisely
        // why the cache exists, so this is not an error worth surfacing.
        return 0;
    }
    if (!$children) return 0;

    $updated = 0;
    foreach ($due as $p) {
        $c = $children[$p['kidsoft_child_id']] ?? null;

        // Linked to a child Kidsoft no longer returns (archived, or moved
        // service). The cached name is now the ONLY record of who this row is,
        // so it is left exactly as it is - but the row is stamped so we do not
        // re-ask on every page load.
        if (!$c) {
            dbx()->query('UPDATE placements SET identity_synced_at = NOW() WHERE id = ?', [$p['id']], 'i');
            continue;
        }

        $first = name_case((string)($c['FirstName'] ?? ''));
        $last  = name_case((string)($c['LastName'] ?? ''));
        $dob   = !empty($c['DOB']) ? substr((string)$c['DOB'], 0, 10) : null;

        // A Kidsoft record with no usable name would blank the board.
        if ($first === '' || $last === '') {
            dbx()->query('UPDATE placements SET identity_synced_at = NOW() WHERE id = ?', [$p['id']], 'i');
            continue;
        }

        $changed = $first !== (string)$p['child_first_name']
                || $last  !== (string)$p['child_last_name']
                || $dob   !== ($p['child_dob'] === null ? null : substr((string)$p['child_dob'], 0, 10));

        dbx()->query(
            'UPDATE placements
             SET child_first_name = ?, child_last_name = ?, child_dob = ?, identity_synced_at = NOW()
             WHERE id = ?',
            [$first, $last, $dob, $p['id']], 'sssi'
        );
        if ($changed) $updated++;
    }
    return $updated;
}

/**
 * Kidsoft children at a centre, for the placement editor's picker.
 *
 * Scoped to ACTIVE children: Tuggerah holds 1,467 records but only 261 active,
 * and offering the archived 1,206 makes the picker useless. $q filters on name
 * so the browser never has to hold the whole list.
 *
 * @return array{id:string, name:string, dob:?string, age_months:?int, placed_in:array}
 */
function kidsoft_children_search(int $centreId, string $q = '', int $limit = 25): array {
    $centre = centre_get($centreId);
    if (!$centre) return [];

    $children = kidsoft_children($centre['kidsoft_service_id']);
    $needle = strtolower(trim($q));

    // Which children already sit on a board here, so the picker can say so
    // rather than letting someone create a duplicate row.
    $placed = [];
    foreach (dbx()->query(
        'SELECT p.kidsoft_child_id, p.year, r.name AS room
         FROM placements p JOIN rooms r ON r.id = p.room_id
         WHERE p.centre_id = ? AND p.kidsoft_child_id IS NOT NULL',
        [$centreId], 'i'
    )->fetchAll() as $row) {
        $placed[$row['kidsoft_child_id']][] = $row['year'] . ' ' . $row['room'];
    }

    $out = [];
    foreach ($children as $id => $c) {
        if (empty($c['Active'])) continue;
        $first = name_case((string)($c['FirstName'] ?? ''));
        $last  = name_case((string)($c['LastName'] ?? ''));
        if ($first === '' || $last === '') continue;

        $name = $first . ' ' . $last;
        if ($needle !== '') {
            $hay = strtolower($name . ' ' . (string)($c['NickName'] ?? ''));
            if (!str_contains($hay, $needle)) continue;
        }

        $dob = !empty($c['DOB']) ? substr((string)$c['DOB'], 0, 10) : null;
        $out[] = [
            'id'         => $id,
            'name'       => $name,
            'nickname'   => $c['NickName'] ?: null,
            'dob'        => $dob,
            'age_months' => age_months($dob),
            'placed_in'  => $placed[$id] ?? [],
        ];
    }

    usort($out, static fn($a, $b) => $a['name'] <=> $b['name']);
    return array_slice($out, 0, $limit);
}
