<?php
// Centres, read from Kidsoft.
//
// A CENTRE EXISTS BECAUSE KIDSOFT SAYS SO. `Service/getServices` is the list, and
// this file is what keeps our `centres` table equal to it. Same rule as rooms and
// capacity; see _incl/rooms-sync.php, which this is modelled on.
//
// UNTIL 11 AUG 2026 NOTHING SYNCED CENTRES AT ALL. `centres` was written by
// exactly one thing - `seed.php`, CLI-only, needing an SSH session as root - so
// it was the one piece of Kidsoft reference data whose refresh was a developer.
// A centre opening could not reach this tool without one. Three consequences,
// all fixed here:
//
//   1. sort_order defaulted to 0, and centres sort by it, so a brand-new centre
//      landed at the FRONT of the centre bar ahead of Tuggerah at 1. Worse,
//      centre_current() falls back to the first centre, so an empty centre with
//      no children silently became the default landing centre for every session
//      with no choice in it. New centres are appended now, never prepended.
//   2. A centre going Archived in Kidsoft was never deactivated here: the seeder
//      looped ACTIVE services only, so an archived one was simply never visited
//      and kept active = 1 for ever.
//   3. The friendly name lived in a PHP file, so renaming a centre was a code
//      change. It is a column now, editable on the Rooms screen's All centres tab.
//
// THE OWNERSHIP LINE: Kidsoft owns whether a centre exists and what it calls
// itself (`kidsoft_service_id`, `kidsoft_name`, `active`). We own the SHORT NAME
// the team reads, the ORDER the centre bar puts them in, and THE DAY THE CENTRE
// OPENS - none of the three is anything Kidsoft holds. So `name`, `sort_order`
// and `opens_on` are seeded once on insert and NEVER touched again by this file.
// A sync that reverted a rename would be the silent-destruction-of-edits failure
// this project warns about everywhere.
//
// `opens_on` IS OURS BY MEASUREMENT, NOT BY ASSUMPTION (21 Sep 2026).
// `Service/getServices` returns nine fields and the only date among them is
// UpdatedDateTime - probed with both its documented Options (Addresses,
// PhoneNumbers), all 16 services. `Service/getOpeningHours` DOES give a per-date
// Open/Closed status for the service and for every room, and it is the obvious
// candidate - but for Warnervale it reports the service and all four rooms as
// OPEN FROM 15 JUNE 2026, which is the day the record was set up (its address
// was last touched that day) and seven months before the centre trades. Every
// other centre reads open from 1 January. So the API holds an answer to a
// different question. See _incl/docs/rooms-centres.md.
//
// A CENTRE CANNOT BE PRE-CREATED BEFORE KIDSOFT HAS IT, and that is deliberate.
// A centre with no ServiceID has no rooms, no capacity, no bookings and no queue,
// so every screen would show it empty and every group aggregate would need to
// special-case it - the Warnervale lesson, but with nothing to measure at all.
// The moment Kidsoft has the service, the nightly run picks it up, and the Rooms
// screen has a button for anyone who will not wait.
//
// Status is a TWO-VALUE field: measured 11 Aug 2026 over all 16 services, the
// only values are `Active` (7) and `Archived` (9). There is no pending or draft
// state, so a new centre appears as Active on the day it is created. The 9
// archived ones are FORMER centres, not shells - they carry real rooms and
// hundreds of children each, and were all archived on 24 Nov 2024.

require_once __DIR__ . '/kidsoft-cache.php';
require_once __DIR__ . '/rooms.php';
require_once __DIR__ . '/audit.php';

/**
 * The short name staff read, derived for a centre we have never seen before.
 *
 * `_incl/centre-names.php` is consulted first and is now purely a SEED for the
 * derivation below - once a centre row exists, its `name` column is the truth
 * and the team edits it on screen. Stripping the brand prefix gets the new
 * suburb centres right with no configuration: "Little Miracles Blaxland" ->
 * "Blaxland".
 */
function centre_friendly_name(string $serviceId, string $kidsoftName): string {
    static $map = null;
    $map ??= require __DIR__ . '/centre-names.php';
    if (isset($map[$serviceId])) return $map[$serviceId];
    $n = trim(preg_replace('/^Little Miracles( Preschool (&|and) Long Day\s?care)?/i', '', $kidsoftName));
    $n = trim($n, " \t\n\r\0\x0B-(),");
    return $n !== '' ? $n : $kidsoftName;
}

/**
 * Bring `centres` into line with Service/getServices.
 *
 * @return array{checked:int, created:int, retired:int, restored:int,
 *               missing:int, names:list<string>}
 */
function centres_sync(?int $ttl = null): array {
    $out = ['checked' => 0, 'created' => 0, 'retired' => 0, 'restored' => 0,
            'missing' => 0, 'names' => []];

    $svc = kidsoft_cached('Service', 'getServices', [], $ttl)['Services'] ?? [];
    if (!$svc) return $out;                       // no answer: change nothing

    $ours = [];
    foreach (dbx()->query('SELECT * FROM centres')->fetchAll() as $c) {
        $ours[$c['kidsoft_service_id']] = $c;
    }
    // New centres go on the END of the bar. See consequence 1 in the header.
    $next = (int)(dbx()->query('SELECT COALESCE(MAX(sort_order), 0) m FROM centres')->fetch()['m'] ?? 0);

    $detail = ['created' => [], 'retired' => [], 'restored' => []];
    $seen = [];

    foreach ($svc as $s) {
        $sid = (string)($s['ServiceID'] ?? '');
        if ($sid === '') continue;
        $seen[$sid] = true;
        $out['checked']++;

        $ksName = trim((string)($s['Name'] ?? ''));
        $live   = ($s['Status'] ?? '') === 'Active';
        $mine   = $ours[$sid] ?? null;

        if (!$mine) {
            // An archived service we have never held is a centre that closed
            // before this app existed. Importing it would put a dead centre in
            // the bar with a decade of history behind it and nothing to do.
            if (!$live) continue;
            $name = centre_friendly_name($sid, $ksName);
            dbx()->query(
                'INSERT INTO centres (kidsoft_service_id, name, kidsoft_name, active, sort_order)
                 VALUES (?, ?, ?, 1, ?)',
                [$sid, $name, $ksName, ++$next]
            );
            $out['created']++;
            $out['names'][] = $name;
            $detail['created'][] = $name . ' (' . $ksName . ')';
            continue;
        }

        // kidsoft_name tracks Kidsoft. `name`, `sort_order` and `opens_on` are
        // OURS and are never written here - see the ownership line in the header.
        $sets = ['kidsoft_name = ?'];
        $vals = [$ksName];
        if ((int)$mine['active'] !== ($live ? 1 : 0)) {
            $sets[] = 'active = ?'; $vals[] = $live ? 1 : 0;
            if ($live) { $out['restored']++; $detail['restored'][] = $mine['name']; }
            else       { $out['retired']++;  $detail['retired'][]  = $mine['name']; }
        }
        if ($sets === ['kidsoft_name = ?'] && (string)$mine['kidsoft_name'] === $ksName) continue;
        $vals[] = (int)$mine['id'];
        dbx()->query('UPDATE centres SET ' . implode(', ', $sets) . ' WHERE id = ?', $vals);
    }

    foreach ($ours as $sid => $c) if (!isset($seen[$sid])) $out['missing']++;

    if ($out['created'] || $out['retired'] || $out['restored']) {
        audit('centres.sync', 'centre', null, array_filter($detail, static fn($v) => $v !== []));
    }
    return $out;
}

/**
 * Renumber sort_order 1..N in the current display order, then move one centre
 * one place. Renumbering first is what makes this safe against the duplicate
 * and zero values a hand-edited table accumulates - swapping two equal
 * sort_orders is a no-op that looks like a broken button.
 *
 * @param int $dir -1 to move earlier, +1 to move later
 */
function centre_move(int $centreId, int $dir): bool {
    $rows = dbx()->query('SELECT id FROM centres ORDER BY sort_order, name')->fetchAll('col');
    $n = count($rows);
    $i = array_search((string)$centreId, array_map('strval', $rows), true);
    if ($i === false) return false;
    $j = $i + ($dir < 0 ? -1 : 1);
    if ($j < 0 || $j >= $n) return false;         // already at the end

    [$rows[$i], $rows[$j]] = [$rows[$j], $rows[$i]];
    foreach ($rows as $k => $id) {
        dbx()->query('UPDATE centres SET sort_order = ? WHERE id = ?', [$k + 1, $id], 'ii');
    }
    return true;
}
