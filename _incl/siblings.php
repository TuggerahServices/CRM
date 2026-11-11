<?php
// Sibling detection: which waitlist families already have a child with us.
//
// WHY THIS EXISTS. priority_rank() puts siblings ahead of external families, and
// `is_sibling` is a checkbox a human ticks. Measured 3 Aug 2026 across the group:
// **89 of 702 open applications are siblings of a currently-enrolled child, and NOT
// ONE of them was ticked.** So the queue - the thing the whole screen exists to
// order - was in the wrong order for 13% of the families in it, and no amount of
// care from the team would have fixed that, because nothing showed them the link.
// Kidsoft has known all along: guardians attach to several children, so a waitlist
// family whose parent is already a parent here is a sibling by construction.
//
// HOW THE MATCH IS MADE, and every part of this is deliberately conservative,
// because a FALSE sibling promotes one family over another that was there first --
// the same asymmetry that makes name matching in _incl/drift.php timid:
//
//   * On the PARENT'S EMAIL or MOBILE, never on a name. Names repeat; a shared
//     mailbox or mobile is a shared household. Phones are normalised to their last
//     9 digits so 0412 345 678, +61412345678 and 61412345678 all agree.
//
//   * Only against guardians who are the ENROLLING guardian or a Mother/Father of
//     the enrolled child. This is the single most important filter: it drops 54% of
//     guardian links, and without it a GRANDMOTHER'S or a CASE WORKER'S number
//     sitting on two unrelated families invents a sibling between them. Measured,
//     it cut the match count from 129 to 89 and removed every cross-family sample
//     (a "Tbc Graham" matched both a Graham and a Herdman child before the filter,
//     and only the Graham after).
//
//   * THE CHILD THEMSELVES IS NOT THEIR OWN SIBLING. 31 open applications belong to
//     a child who is ALREADY enrolled - families apply again for extra days or for
//     next year - and matching on family identifiers finds them first. Excluded on
//     folded first+last name.
//
// THE DETECTION IS THE AUTHORITY; THE COLUMN IS AN OVERRIDE (11 Aug 2026). This
// used to end "NOTHING IS TICKED AUTOMATICALLY" - the matches were displayed, a
// banner counted the ones nobody had ticked, and a bulk button copied them into
// `is_sibling`, which priority_rank() then read. Measured before the change:
// **100 of 719 open applications detected, ZERO ever ticked, and not one
// `waitlist.sibling` row in the audit log** - so the button had never been
// pressed in the eight days it existed, the queue had been in the wrong order
// for 14% of the families in it the whole time, and the screen had been
// announcing that daily instead of fixing it.
//
// The argument for the button ("staff may have unticked one deliberately") could
// not have applied, because nothing had ever been ticked. And the app already
// held the counter-example: `in_care`, the rung directly ABOVE sibling in
// priority_rank(), is the same shape of fact - Kidsoft-derived, promotes a
// family up the queue - and waitlist_mark_in_care() computes it live on every
// read with no column, no banner and no button, naming its reason on the row.
// Two adjacent rungs of one ladder had opposite architectures.
//
// So sibling_matches_for_centre() now runs inside waitlist_for_centre() before
// the sort, priority_rank() reads the live match, and priority_reasons() names
// the sibling - "Sibling of Emily Cook at Niagara Park" - so a human still
// sees what the machine saw, which was always the real value of displaying it.
//
// `is_sibling` SURVIVES as a manual override for a sibling the phone/email rule
// cannot see (the one deliberate miss this file accepts). It is ours under the
// ownership split in _incl/waitlist-import.php, it is additive only, and it is
// no longer the authority. A row can be promoted by either.
//
// ONE CONSEQUENCE WORTH KNOWING: a Kidsoft outage now changes the queue ORDER
// for the length of the outage, not just the badges. That is already true of
// in_care, which catches per centre and carries on, and this does the same - but
// it is a real behaviour change and not merely a cosmetic one.

require_once __DIR__ . '/contacts.php';
require_once __DIR__ . '/rooms.php';
require_once __DIR__ . '/users.php';    // user_can_centre() - a sibling at a centre this user does not hold
require_once __DIR__ . '/drift.php';    // name_digit_key() - reused, never re-implemented

/** Fold a name for comparison. Casing and punctuation never affect a match. */
function sibling_fold(string $s): string {
    return preg_replace('/[^a-z]/', '', strtolower($s)) ?? '';
}

/**
 * The name key BOTH halves of the self-exclusion are built from.
 *
 * There is exactly one name comparison in this file - "is this applicant the
 * enrolled child themselves, rather than their sibling?" - and until 21 Aug 2026
 * it folded with `sibling_fold()` alone, which strips digits:
 *
 *     sibling_fold('Twin 1') === sibling_fold('Twin 2')
 *
 * So a family expecting twins, who type the only two names they have, collide.
 * **If one twin enrols while the other is still waiting, the waiting twin reads
 * as the enrolled child re-applying and is dropped** - losing the sibling rung
 * of the family with the plainest claim to it, their twin already being in the
 * room. Three such pairs are on the books: Wolfenden at Niagara Park (both
 * applications OPEN), Moffitt at Tuggerah, Pan at Point Clare.
 *
 * **The digits are appended as a sorted multiset**, exactly as
 * `name_identity_key()` does and for the same reason: Terrigal's queue holds
 * `Baby Haigh 2` while Kidsoft's archive holds `Baby 2 Haigh`, one child with
 * the digit landing in a different part each time, and that child must keep
 * being excluded from their own sibling list.
 *
 * **It had never once misfired, and the reason is worth keeping:** no Kidsoft
 * child record carries a digit at all, so a placeholder name is corrected by the
 * time a child converts, and the enrolled half of the comparison never held a
 * digit to lose. This closes it before that stops being true rather than after.
 *
 * A name with no digit keys byte-identically to the old `sibling_fold()` pair,
 * so the exclusion can only ever become MORE precise. Verified: 101 matches
 * group-wide before and after, and `Millie Blackshaw\`` - a stray backtick, not
 * a digit - is still correctly excluded against the enrolled `Millie Blackshaw`.
 */
function sibling_name_key(string $first, string $last): string {
    return sibling_fold($first) . '|' . sibling_fold($last)
         . name_digit_key($first, $last);
}

/**
 * Normalise a phone number to a comparable key, or null.
 *
 * Last 9 digits, after turning a +61/61 prefix back into a leading 0. That makes
 * 0412345678 and +61412345678 the same key without pretending to parse every
 * international format - and 8 digits is the floor, so a mangled 4-digit
 * extension cannot collide with anything.
 */
function sibling_phone_key(?string $phone): ?string {
    $d = preg_replace('/\D/', '', (string)$phone) ?? '';
    if ($d === '') return null;
    if (strlen($d) > 9 && substr($d, 0, 2) === '61') $d = '0' . substr($d, 2);
    return strlen($d) >= 8 ? substr($d, -9) : null;
}

function sibling_email_key(?string $email): ?string {
    $e = strtolower(trim((string)$email));
    return $e === '' ? null : $e;
}

// ---------------------------------------------------------------------------
// TWO APPLICATIONS SHARING A PARENT: TWO CHILDREN, OR ONE CHILD TWICE?
//
// Moved here from waitlist-group.php on 19 Oct 2026, unchanged, because two
// callers now ask it: the Waitlist's "Also waiting" line (group-wide, through
// waitlist_siblings_waiting()) and the work layer's family actions for one
// centre (work_siblings_here()), which must not load the group-wide build to
// ask. The measurement it rests on is written beside waitlist_siblings_waiting():
// over 52 contact-sharing pairs, 0-96 days apart was always one child applied
// for twice, and real brothers and sisters started at 363 days.
// ---------------------------------------------------------------------------

/** Below this many days apart, two dates of birth are one child, not two. */
const WAITLIST_SIBLING_SAME_CHILD_DAYS = 270;

/** First names parents type before a child has one. Not "Twin" - those are two children. */
const WAITLIST_PLACEHOLDER_FIRST_NAMES = ['baby', 'bub', 'bubba', 'tba', 'tbc', 'tbd',
                                          'unborn', 'unknown', 'unnamed'];

/**
 * Are these two applications, sharing a parent's contact, the SAME child?
 * See the block beside waitlist_siblings_waiting() for the measurement behind
 * every line.
 */
function waitlist_same_child(array $a, array $b): bool {
    $da = substr((string)$a['child_dob'], 0, 10);
    $db = substr((string)$b['child_dob'], 0, 10);
    if ($da !== '' && $db !== '') {
        $gap = (int)round(abs(strtotime($da) - strtotime($db)) / 86400);
        if ($gap > 0) return $gap < WAITLIST_SIBLING_SAME_CHILD_DAYS;
    }
    // Born the same day, or a date is missing: only a different NAME makes them two.
    // Digits are kept, so Twin 1 and Twin 2 differ.
    $fa = preg_replace('/[^a-z0-9]/', '', strtolower((string)$a['child_first_name'])) ?? '';
    $fb = preg_replace('/[^a-z0-9]/', '', strtolower((string)$b['child_first_name'])) ?? '';
    if ($fa === '' || $fb === '') return true;
    if (str_contains($fa, $fb) || str_contains($fb, $fa)) return true;
    foreach ([$fa, $fb] as $f) {
        if (in_array(preg_replace('/[0-9]/', '', $f), WAITLIST_PLACEHOLDER_FIRST_NAMES, true)) return true;
    }
    return false;
}

/**
 * Index of currently-enrolled children by their PARENTS' email and mobile.
 *
 * @param array $serviceIds  Kidsoft service ids to include, name => id
 * @return array{email:array,phone:array}
 */
function enrolled_family_index(array $serviceIds): array {
    // MEMOISED FOR THE REQUEST, and it has to be now that this runs on every
    // queue read. The index is GROUP-WIDE while the caller is per centre, so the
    // All centres register - waitlist_group_all(), seven calls to
    // waitlist_for_centre() - rebuilt the whole thing seven times: measured
    // 0.80s against 0.12s. Same shape as the static index in
    // waitlist_mark_in_care(), and for the same reason.
    //
    // A partial index (a centre Kidsoft would not answer for is skipped below)
    // is cached too, deliberately: one page load then ranks every centre's queue
    // against the same set of families, rather than the ordering depending on
    // which retry happened to succeed.
    static $memo = [];
    $memoKey = implode('|', $serviceIds);
    if (isset($memo[$memoKey])) return $memo[$memoKey];

    $byEmail = $byPhone = [];

    foreach ($serviceIds as $centreName => $serviceId) {
        try {
            $kids = kidsoft_rows(kidsoft_cached('Children', 'getChildren', [
                'ServiceID' => $serviceId, 'Options' => 'Guardian',
            ]), 'Children', $serviceId);
            $gmap = guardians_by_id($serviceId);
        } catch (RuntimeException $e) {
            continue;   // one unreachable centre must not blank the whole index
        }

        foreach ($kids as $k) {
            if (empty($k['Active']) || empty($k['ChildID'])) continue;

            $child = [
                'kidsoft_child_id' => $k['ChildID'],
                'centre' => $centreName,
                'name'   => trim(name_case(trim((string)($k['FirstName'] ?? ''))) . ' '
                               . name_case(trim((string)($k['LastName'] ?? '')))),
                'key'    => sibling_name_key((string)($k['FirstName'] ?? ''),
                                            (string)($k['LastName'] ?? '')),
            ];

            foreach ($k['Guardian'] ?? [] as $l) {
                $rel = strtolower((string)($l['Relationship'] ?? ''));
                // The filter that makes this trustworthy - see the header.
                if (empty($l['Enrolment']) && $rel !== 'mother' && $rel !== 'father') continue;

                $g = $gmap[$l['GuardianID'] ?? ''] ?? null;
                if (!$g) continue;

                if ($e = sibling_email_key($g['Email'] ?? null)) $byEmail[$e][] = $child;
                foreach (guardian_household_phone_keys($g) as $n) $byPhone[$n][] = $child;
            }
        }
    }
    return $memo[$memoKey] = ['email' => $byEmail, 'phone' => $byPhone];
}

/**
 * The phone keys on a guardian's record that identify THEIR HOUSEHOLD.
 *
 * Shared with the Do Not Enrol check (_incl/do-not-enrol.php), which asks the
 * same question of a different set of guardians - "is this applicant that
 * family?" - and must not come to answer it with a looser rule than this one.
 *
 * @return string[] sibling_phone_key() values
 */
function guardian_household_phone_keys(array $g): array {
    $out = [];
    foreach ($g['PhoneNumbers'] ?? [] as $p) {
        // A number belonging to somebody else (Grandmother, Case Worker)
        // is not a household identifier even on a parent's record.
        $t = strtolower(trim((string)($p['Type'] ?? '')));

        // A WORK NUMBER IS AN EMPLOYER'S SWITCHBOARD, NOT A HOUSEHOLD
        // (fixed 13 Aug 2026, found by Broc reading one row). `work`
        // was in the allowlist, and it is the one type that does not
        // behave like a household identifier at all. Measured over
        // every guardian in the group, the most-shared single number
        // per type: **mobile 4, home 5, work 16.** Colleagues are not
        // siblings.
        //
        // What it cost: one open application ("Baby Manhire",
        // Tuggerah) matched **11 children across 6 surnames** --
        // Cook, O'Loughlin, Tait, Luck, Wharton, Manhire - because
        // 15 Tuggerah guardians list 02 4355 4816 as their WORK
        // number. Only one of the eleven was real, and the EMAIL had
        // already found her. So the family was promoted up the queue
        // on ten false siblings, which is the exact failure this
        // file's conservatism exists to prevent, arriving through the
        // one door left open.
        //
        // Verified before changing it: excluding work leaves the
        // group-wide match count at **101, identical** - every real
        // sibling is matched by a mobile, a home number or the email
        // - while the busiest phone key falls from 11 to 6. It
        // removes false positives and nothing else.
        //
        // Matched on SUBSTRING, so "mum work", "dad work", "grandma
        // work" and "case worker" go with it. Checked: those nine
        // variants are every type containing "work" in the live data
        // and not one is a household number.
        if (str_contains($t, 'work')) continue;

        if ($t !== '' && stripos($t, 'mobile') === false && $t !== 'home') continue;
        if ($n = sibling_phone_key($p['PhoneNumber'] ?? null)) $out[] = $n;
    }
    return $out;
}

/**
 * Detected siblings for one centre's open applications.
 *
 * Group-wide by default: a family already using another Little Miracles centre is
 * still a family we have, and 14 of the 89 matches are only visible across centres.
 *
 * @return array waitlistId => array{siblings:array,same_centre:bool,via:string}
 */
function sibling_matches_for_centre(int $centreId, bool $groupWide = true): array {
    $centre = centre_get($centreId);
    if (!$centre) throw new InvalidArgumentException('unknown centre');

    $services = [];
    $centreIdsByName = [];
    foreach (centres_all() as $c) {
        $centreIdsByName[$c['name']] = (int)$c['id'];
        if ($groupWide || (int)$c['id'] === $centreId) {
            $services[$c['name']] = $c['kidsoft_service_id'];
        }
    }
    $index = enrolled_family_index($services);
    if (!$index['email'] && !$index['phone']) return [];

    $rows = dbx()->query(
        "SELECT id, child_first_name, child_last_name, guardian_email, guardian_phone
           FROM waitlist_applications
          WHERE centre_id = ? AND status NOT IN ('placed','withdrawn','expired','archived')",
        [$centreId], 'i'
    )->fetchAll();

    $out = [];
    foreach ($rows as $w) {
        // Digit-aware on BOTH sides - see sibling_name_key(). The two halves of
        // this comparison must be built the same way or every key stops matching.
        $selfKey = sibling_name_key($w['child_first_name'], $w['child_last_name']);

        // DEDUPED ON THE CHILDID, NOT THE NAME (21 Aug 2026). One enrolled child
        // is reached repeatedly here - once through the mother's email, again
        // through her mobile, again through the father's - and these two loops
        // exist to collapse that back to one. But the key was the FOLDED NAME,
        // which is not an identity: two enrolled children who share a name at one
        // centre collapse into one, and the second silently stops being a
        // sibling. Kidsoft hands us a ChildID on every one of these rows; using
        // anything weaker to say "same child" is the bug that put two twins on
        // one search card, in a third place.
        //
        // Terrigal holds the live example, and it is not a duplicate record:
        // MATILDA PAYNE b. 27/12/2021 (Chloe Charlton's) and MATILDA PAYNE b.
        // 02/07/2025 (Emily Payne's) are two children of two UNRELATED families -
        // different mothers, different CRNs, different rooms, both booked Tue and
        // Thu. **Measured across all 774 applications, no applicant currently
        // reaches both**, precisely because they are different households, so
        // nothing on screen changes today. It is fixed as a correctness matter,
        // not a repair: the collapse needs only one family with two same-named
        // children to bite, and it would then remove a sibling nobody would think
        // to look for.
        $found = [];
        $via   = [];
        if ($e = sibling_email_key($w['guardian_email'])) {
            foreach ($index['email'][$e] ?? [] as $c) {
                $found[$c['centre'] . '|' . $c['kidsoft_child_id']] = $c;
                $via['email'] = true;
            }
        }
        if ($p = sibling_phone_key($w['guardian_phone'])) {
            foreach ($index['phone'][$p] ?? [] as $c) {
                $found[$c['centre'] . '|' . $c['kidsoft_child_id']] = $c;
                $via['phone'] = true;
            }
        }
        if (!$found) continue;

        // Drop the applicant themselves: an enrolled child holding an open
        // application is asking for more days or for next year, not a sibling.
        $siblings = [];
        foreach ($found as $c) {
            if ($c['key'] === $selfKey) continue;
            $siblings[] = $c;
        }
        if (!$siblings) continue;

        $sameCentre = false;
        foreach ($siblings as $c) if ($c['centre'] === $centre['name']) $sameCentre = true;

        // WHAT A SUPERVISOR IS TOLD ABOUT A SIBLING AT ANOTHER CENTRE: that there
        // is one, and where. (19 Aug 2026.)
        //
        // The match itself STAYS, and it has to: it is what ranks this family in
        // the supervisor's OWN queue, and a match they cannot see is a ranking
        // they cannot explain - the row would jump the queue for no stated
        // reason, which is the one thing priority_reasons() exists to prevent.
        // What goes is the other child's name, because that child is enrolled at
        // a centre this user does not hold. `name` becomes null and
        // priority_reasons() prints the centre in its place.
        //
        // The index is keyed by centre NAME (see enrolled_family_index), so the
        // scope test goes through the same map.
        foreach ($siblings as &$c) {
            $cid = $centreIdsByName[$c['centre']] ?? 0;
            $c['scoped'] = $cid > 0 && user_can_centre($cid);
            // Which centre, by id: a ChildID is per service, so opening this
            // sibling's dialog needs both (priority_reason_parts()).
            $c['centre_id'] = $cid ?: null;
            // `key` GOES WITH THE NAME. It is the folded first|last plus the name's
            // digits - "azula|morante#" - so leaving it behind hands over the very
            // name the line above just removed, in a field nothing reads. Its one
            // remaining use, the drop-the-applicant test, has already run by this
            // point. (It was two uses until 21 Aug 2026; the dedup above now keys
            // on the ChildID, which is why that one is gone.)
            if (!$c['scoped']) {
                $c['name'] = null;
                $c['kidsoft_child_id'] = null;
                $c['key']  = null;
            }
        }
        unset($c);

        $out[(int)$w['id']] = [
            'siblings'    => $siblings,
            'same_centre' => $sameCentre,
            // Which identifier matched. An email match is the stronger of the two
            // (a mailbox is harder to share by accident than a handset), and the
            // screen says which so a human can weigh a phone-only match.
            'via'         => implode(' + ', array_keys($via)),
        ];
    }
    return $out;
}
