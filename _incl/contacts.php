<?php
// Guardian contact details, read from Kidsoft.
//
// THERE ARE PHONE NUMBERS, and this app spent months believing there were not.
// The old claim - "Guardian/getGuardians returns no Mobile or Phone field at
// all, verified across all 4,630 Tuggerah guardians" - was measured without
// Options=PhoneNumbers. The flat response genuinely has no phone field; the
// option is how you ask for one. Re-measured 31 Jul 2026 over the same service:
// 1,197 of 1,214 ACTIVE guardians (99%) carry at least one number, against
// email at 38%. On a screen whose whole purpose is ringing a family about a
// free place, we were offering the worse channel.
//
// PICKING "THE" NUMBER IS THE ENTIRE DIFFICULTY, so it lives here once rather
// than being guessed at each call site. Measured over Tuggerah's active
// guardians: 307 of them hold more than one number (up to five), `Type` is FREE
// TEXT with 21 distinct values, and `Priority` is null on 89% of numbers. Naively
// taking PhoneNumbers[0] would dial a Grandmother, an Aunt, a Neighbour or a
// Case Worker - all real Type values in this data - while the parent's mobile
// sat in the same array. So guardian_phone() ranks, and it RETURNS THE TYPE with
// the number so the caller can say whose phone it is instead of implying it is
// the parent's.

require_once __DIR__ . '/kidsoft-cache.php';
require_once __DIR__ . '/identity.php';   // name_case()

/**
 * Every guardian at a service, keyed by GuardianID, WITH phone numbers.
 *
 * Options=PhoneNumbers costs ~4.6s cold against ~3s plain (4,631 rows at
 * Tuggerah) and is cached for 6 hours like the plain call, so the extra second
 * is paid once every six hours per centre. Note the option is part of the cache
 * key, so adding it here does not read a stale phone-less payload.
 *
 * AND WITH TAGS (7 Oct 2026), for the Do Not Enrol check - see
 * _incl/do-not-enrol.php. One read rather than a second one beside it: measured
 * at Tuggerah the tags add 0.8 MB of JSON (2.35 -> 3.18 MB, 489 -> 521 KB
 * gzipped) and no time on the wire, and every caller walks ONE centre at a time,
 * so the peak grows by one centre's tags, never seven. **The tags include billing
 * (DDR), ATSI status and court orders - nothing here may hand a guardian row to
 * the browser whole.**
 */
function guardians_by_id(string $serviceId, bool $refresh = false): array {
    $res = kidsoft_cached('Guardian', 'getGuardians', [
        'ServiceID' => $serviceId,
        'Options'   => 'PhoneNumbers,Tags',
    ], $refresh ? 0 : null);

    $out = [];
    foreach (kidsoft_rows($res, 'Guardians', $serviceId) as $g) {
        if (!empty($g['GuardianID'])) $out[$g['GuardianID']] = $g;
    }
    return $out;
}

/**
 * The best number to ring for a guardian, or null if they have none.
 *
 * The ladder, and why each rung is where it is:
 *
 *   0. Type contains "mobile" - catches "Mobile" (964), "Dad mobile" (34) and
 *      "mum mobile" (16). Matched case-insensitively on a SUBSTRING because the
 *      free text is inconsistent; matching the whole value would miss both of
 *      the lower-cased variants.
 *   1. SMS = true - Kidsoft's own "we text this number" flag, set on 1,071
 *      numbers. If the service texts it, a person answers it.
 *   2. Type starts Home / Work / Business - a landline for the family, still
 *      the family.
 *   3. Anything else. This rung is a number belonging to SOMEBODY ELSE
 *      (Grandmother, Aunt, Uncle, Neighbour, Case Worker, Family Friend are all
 *      real Type values here), so it is offered last and the caller is told the
 *      type, because ringing a case worker about a free Friday is a mistake that
 *      only looks like a phone call.
 *
 * `Priority` breaks ties within a rung when Kidsoft set one (11% of numbers,
 * lower first); otherwise Kidsoft's own array order stands.
 *
 * @return array{number:string,type:?string,sms:bool,is_parent:bool}|null
 */
function guardian_phone(array $g): ?array {
    $best = null;
    $bestRank = PHP_INT_MAX;
    $bestPriority = PHP_INT_MAX;

    foreach ($g['PhoneNumbers'] ?? [] as $p) {
        $number = trim((string)($p['PhoneNumber'] ?? ''));
        if ($number === '') continue;

        $type = trim((string)($p['Type'] ?? ''));
        if ($type !== '' && stripos($type, 'mobile') !== false)      $rank = 0;
        elseif (!empty($p['SMS']))                                   $rank = 1;
        elseif (preg_match('/^(home|work|business)/i', $type))       $rank = 2;
        else                                                         $rank = 3;

        // Absent Priority must not beat a real one, so null sorts last.
        $priority = $p['Priority'] === null ? PHP_INT_MAX : (int)$p['Priority'];

        if ($rank < $bestRank || ($rank === $bestRank && $priority < $bestPriority)) {
            $bestRank     = $rank;
            $bestPriority = $priority;
            $best = [
                'number' => $number,
                'type'   => $type === '' ? null : $type,
                'sms'    => !empty($p['SMS']),
                // Rung 3 is a number the parent does not answer. Say so rather
                // than letting a UI label it "Mobile" by omission.
                'is_parent' => $rank < 3,
            ];
        }
    }
    return $best;
}

/**
 * Order a child's guardian links so the best person to ring comes first.
 *
 * Extracted so that anything walking child-to-guardian links - child_contacts()
 * below, and search_all() in _incl/search.php - ranks them the SAME way. "Who do
 * I ring about this child" is a judgement with real consequences (see the ladder
 * documented on child_contacts), and two copies of it would drift apart.
 *
 * Sorted in place and returned. See child_contacts() for why each rung is there.
 */
function guardian_links_ranked(array $links): array {
    // Always a list in this data (2-8 links per active child), but a single
    // guardian arriving as a bare object would otherwise be sorted as if its
    // FIELDS were the guardians.
    if (isset($links['GuardianID'])) $links = [$links];

    usort($links, static function ($a, $b) {
        $ae = empty($a['Enrolment']) ? 1 : 0;
        $be = empty($b['Enrolment']) ? 1 : 0;
        if ($ae !== $be) return $ae <=> $be;
        // Absent priority sorts last, not first - null must never outrank a
        // guardian Kidsoft explicitly put at position 1.
        $ap = $a['EmergencyPriority'] ?? PHP_INT_MAX;
        $bp = $b['EmergencyPriority'] ?? PHP_INT_MAX;
        if ($ap !== $bp) return $ap <=> $bp;
        $rank = static function ($l) {
            $rel = strtolower((string)($l['Relationship'] ?? ''));
            if ($rel === 'mother' || $rel === 'father') return 0;
            return empty($l['Legal']) ? 2 : 1;
        };
        return $rank($a) <=> $rank($b);
    });
    return $links;
}

/**
 * One guardian link plus their best number, in the shape child_contacts() returns.
 * Null when that guardian has no number we could ring.
 */
function guardian_contact(array $link, ?array $guardian): ?array {
    if (!$guardian) return null;
    $phone = guardian_phone($guardian);
    if (!$phone) return null;

    return [
        // Cased for reading. These arrive inconsistently - "Lyn Farrugia" on one
        // link and "MADELEINE JACKSON" on the next - and a panel that shows both
        // spellings of the same convention looks broken. name_case() leaves
        // already-mixed-case names alone.
        'guardian'     => trim(name_case(trim((string)($link['GuardianFirstName'] ?? ''))) . ' '
                             . name_case(trim((string)($link['GuardianLastName'] ?? '')))),
        'relationship' => $link['Relationship'] ?? null,
        'phone'        => $phone['number'],
        'phone_type'   => $phone['type'],
        'is_parent'    => $phone['is_parent'],
        'email'        => $guardian['Email'] ?? null,
    ];
}

/**
 * Who to ring about a given child: ChildID => contact, for a whole service.
 *
 * Two cached calls, both 6 h: Children/getChildren with Options=Guardian for the
 * child-to-guardian links, and guardians_by_id() for the numbers. Measured at
 * Tuggerah 3 Aug 2026: **257 of 257 active children resolve to a phone number,
 * and in every single case the best number belongs to a parent.**
 *
 * PICKING THE RIGHT GUARDIAN IS THE POINT, and it is not the first one. Active
 * children carry between 2 and 8 guardians each (median 4), and across the
 * service `Relationship` = "Grandmother" (269) OUTNUMBERS "Mother" (258). Taking
 * the first link would therefore ring a grandmother about a place more often than
 * it rang a parent. The ladder:
 *
 *   1. `Enrolment` = true - the guardian who holds the enrolment, i.e. the person
 *      who signs for days and pays for them. Set for 100% of active children,
 *      which is what makes this reliable rather than a guess. (11 children have
 *      two; either will do, so the next rungs settle it.)
 *   2. Lowest `EmergencyPriority` - Kidsoft's own "ring this one first" order,
 *      populated on 1,082 guardian links here.
 *   3. `Relationship` of Mother or Father, then a `Legal` guardian.
 *
 * A guardian with no usable number is SKIPPED rather than returned empty, so the
 * ladder falls through to the next contact who can actually be reached - which is
 * how coverage gets to 100% instead of stalling on a parent with no phone on file.
 *
 * @return array<string,array{guardian:string,relationship:?string,phone:?string,
 *                            phone_type:?string,is_parent:bool,email:?string}>
 */
function child_contacts(string $serviceId, bool $refresh = false): array {
    $res = kidsoft_cached('Children', 'getChildren', [
        'ServiceID' => $serviceId,
        'Options'   => 'Guardian',
    ], $refresh ? 0 : null);

    $guardians = guardians_by_id($serviceId, $refresh);

    $out = [];
    foreach (kidsoft_rows($res, 'Children', $serviceId) as $c) {
        $childId = $c['ChildID'] ?? '';
        $links   = $c['Guardian'] ?? [];
        if ($childId === '' || !$links) continue;

        foreach (guardian_links_ranked($links) as $l) {
            $contact = guardian_contact($l, $guardians[$l['GuardianID'] ?? ''] ?? null);
            if (!$contact) continue;   // unreachable: try the next contact down
            $out[$childId] = $contact;
            break;
        }
    }
    return $out;
}

/**
 * A phone number as a tel: href value.
 *
 * Australian numbers arrive in this data as bare digits ("0435714228",
 * "43991332") but not reliably, so everything a dialler cannot use is stripped
 * while + is kept for the handful of international ones.
 */
function phone_tel(string $number): string {
    $clean = preg_replace('/[^0-9+]/', '', $number) ?? '';
    return $clean === '' ? '' : 'tel:' . $clean;
}
