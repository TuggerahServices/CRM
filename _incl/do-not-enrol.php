<?php
// Do Not Enrol: families Little Miracles has ended care for and will not take
// back, checked against every application in the queue.
//
// THE LIST IS A KIDSOFT TAG, NOT A TABLE OF OURS (Broc, 7 Oct 2026). Staff tag
// the guardian in Kidsoft when they end care, in the same place they record why
// the child left, and this file reads it. Any guardian tag whose name BEGINS
// "Do Not Enrol" counts - so one tag works, and so does one per reason ("Do Not
// Enrol - Behaviour", "Do Not Enrol - Finance"), whose full name is what the
// screen shows. "Enroll" is accepted too. Nothing here can write a tag: Kidsoft
// is read-only, so removing a family from the list is done in Kidsoft as well.
//
// Why a tag works, measured 7 Oct 2026 (see _incl/docs/kidsoft-api.md): tags come
// back on getGuardians with Options=Tags, a tag name has ONE TagID across all
// seven services, and inactive guardians keep their tags. Kidsoft held no such
// signal before - no tag, and none of the 13 child ExitReason values.
//
// THE MATCH IS THE SIBLING MATCH, pointed at the tagged guardians instead of the
// enrolled ones, and it is group-wide for the same reason: a family that left
// Terrigal can apply at Tuggerah. Measured the same day over 604 open
// applications, 45 already share an email or mobile with a guardian who has LEFT
// Little Miracles, and 19 of those left a DIFFERENT centre. Kidsoft's own
// GuardianID link reaches only 14 of the 45, so it is used but is not enough:
//
//   * the GuardianID Kidsoft put on the application, when it is a tagged one;
//   * the parent's email;
//   * the parent's mobile, against the tagged guardian's HOUSEHOLD numbers only -
//     guardian_household_phone_keys(), the sibling rule, which drops work numbers
//     and anybody else's (a grandmother's, a case worker's).
//
// A MATCH WARNS, IT NEVER DECIDES. A mobile number gets reissued and a family
// shares a mailbox, so a false match would turn away a family who did nothing.
// The row says who it matched and how; make_offer, and starting work on them
// (api/work.php `create`, 12 Oct 2026), refuse until whoever is acting confirms
// it has been checked, and the audit row records that they did. Search shows it
// too - see dne_for_child_links(). Nothing is hidden or re-ranked.
//
// A SUPERVISOR is told the fact, the centre and the tag for a family at a centre
// they do not hold, not the name - the siblings.php rule.
//
// A Kidsoft outage with no cached copy skips that centre's tags rather than
// blanking the queue, as every other Kidsoft-derived flag here does. The cache is
// served stale for days before that can happen.

require_once __DIR__ . '/siblings.php';   // sibling_email_key(), sibling_phone_key(), guardian_household_phone_keys()

// Overridable ONLY so a CLI probe can point the check at a tag that exists - the
// real one may not yet. Nothing in the app defines it.
if (!defined('DNE_TAG_PATTERN')) define('DNE_TAG_PATTERN', '/^\s*do\s*not\s*enroll?\b/i');

/** The Do Not Enrol tag names on one Kidsoft guardian record. */
function dne_tags(array $g): array {
    $tags = $g['Tags'] ?? [];
    // A single tag can arrive as a bare object - see comments.php.
    if (isset($tags['TagID'])) $tags = [$tags];
    $out = [];
    foreach ($tags as $t) {
        $name = trim((string)($t['Name'] ?? ''));
        if ($name !== '' && preg_match(DNE_TAG_PATTERN, $name)) $out[] = $name;
    }
    return $out;
}

/**
 * Every tagged guardian in the group, by GuardianID, email and household phone.
 * Memoised for the request: the All centres register calls this seven times.
 */
function dne_index(): array {
    static $index = null;
    if ($index !== null) return $index;

    $index = ['id' => [], 'email' => [], 'phone' => []];
    foreach (centres_all() as $c) {
        try {
            $gmap = guardians_by_id($c['kidsoft_service_id']);
        } catch (Throwable $e) {
            continue;   // one unreachable centre must not blank the check
        }
        foreach ($gmap as $gid => $g) {
            $tags = dne_tags($g);
            if (!$tags) continue;
            $fam = [
                'centre_id' => (int)$c['id'],
                'centre'    => $c['name'],
                'name'      => trim(name_case(trim((string)($g['FirstName'] ?? ''))) . ' '
                                  . name_case(trim((string)($g['LastName'] ?? '')))),
                'tags'      => $tags,
            ];
            $index['id'][$gid] = $fam;
            if ($e = sibling_email_key($g['Email'] ?? null)) $index['email'][$e][$gid] = $fam;
            foreach (guardian_household_phone_keys($g) as $p) $index['phone'][$p][$gid] = $fam;
        }
    }
    return $index;
}

/**
 * The tagged families one application matches, or null.
 *
 * @param array $w a waitlist_applications row
 * @return array{families:array,via:string,text:string}|null
 */
function dne_match(array $w): ?array {
    $idx = dne_index();
    if (!$idx['id']) return null;

    $found = [];
    $via   = [];
    $gid = (string)($w['kidsoft_guardian_id'] ?? '');
    if ($gid !== '' && isset($idx['id'][$gid])) {
        $found[$gid] = $idx['id'][$gid];
        $via['Kidsoft record'] = true;
    }
    if ($e = sibling_email_key($w['guardian_email'] ?? null)) {
        foreach ($idx['email'][$e] ?? [] as $k => $f) { $found[$k] = $f; $via['email'] = true; }
    }
    if ($p = sibling_phone_key($w['guardian_phone'] ?? null)) {
        foreach ($idx['phone'][$p] ?? [] as $k => $f) { $found[$k] = $f; $via['mobile'] = true; }
    }
    return $found ? dne_result($found, array_keys($via)) : null;
}

/**
 * A Kidsoft child's own parents, when any of them is tagged - for search, where
 * a family we ended care for rings and their child's Kidsoft card comes up.
 *
 * Only the ENROLLING guardian or a Mother/Father counts, the sibling rule: a tag
 * on a grandmother's or a case worker's record is not a tag on this family.
 *
 * @param array $links the child's Guardian links (getChildren Options=Guardian)
 */
function dne_for_child_links(array $links): ?array {
    $idx = dne_index();
    if (!$idx['id']) return null;
    $found = [];
    foreach ($links as $l) {
        $rel = strtolower((string)($l['Relationship'] ?? ''));
        if (empty($l['Enrolment']) && $rel !== 'mother' && $rel !== 'father') continue;
        $gid = (string)($l['GuardianID'] ?? '');
        if ($gid !== '' && isset($idx['id'][$gid])) $found[$gid] = $idx['id'][$gid];
    }
    return $found ? dne_result($found, []) : null;
}

/**
 * The payload every screen renders, from the tagged families found.
 *
 * @param array    $found GuardianID => dne_index() entry
 * @param string[] $via   what matched; empty when the record IS the tagged family's
 */
function dne_result(array $found, array $via): array {
    $families = [];
    foreach ($found as $f) {
        if (!user_can_centre($f['centre_id'])) $f['name'] = null;
        unset($f['centre_id']);
        $families[] = $f;
    }

    $who = implode('; ', array_map(static fn($f) => ($f['name'] ?? 'a family') . ' at ' . $f['centre']
                                                  . ' (' . implode(', ', $f['tags']) . ')', $families));
    $how = count($via) > 1
        ? implode(', ', array_slice($via, 0, -1)) . ' and ' . end($via)
        : ($via[0] ?? null);

    return [
        'families' => $families,
        'via'      => implode(' + ', $via),
        // One sentence, composed here so the queue, the board, the family modal,
        // search and both refusals (make_offer, starting work) say the same thing.
        'text'     => $how === null ? 'Tagged in Kidsoft: ' . $who . '.'
                                    : 'Same ' . $how . ' as ' . $who . '.',
    ];
}

/** Attach the match to each row as `dne`. Read on every load, never stored. */
function waitlist_attach_dne(array $rows): array {
    foreach ($rows as &$w) {
        try {
            $w['dne'] = dne_match($w);
        } catch (Throwable $e) {
            $w['dne'] = null;
        }
    }
    unset($w);
    return $rows;
}
