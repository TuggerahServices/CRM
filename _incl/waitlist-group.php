<?php
// The waitlist across the whole organisation: one row per CHILD, not one per
// application.
//
// WHY THIS SCREEN DID NOT EXIST. waitlist.php carried the comment "one centre's
// queue at a time - priority order only means anything within a centre - so no
// All centres tab", and the first half of that is still true: there is no
// group-wide queue and this view does not invent one. What it is instead is a
// REGISTER - every family the organisation is currently holding, with the place
// they hold in each centre's queue shown next to that centre's name.
//
// WHAT IT IS ACTUALLY FOR: families apply at several centres at once, and until
// now nothing in this app could see that. Measured 6 Aug 2026 over the 715 open
// applications: they belong to 620 children, and 73 of those children (11.8%)
// are waiting at more than one centre, accounting for 166 of the applications.
// Two consequences the team could not previously act on --
//
//   * Ringing a family about a Tuesday at Wamberal without knowing they are also
//     third in line at Terrigal is half a conversation. 45 of the 73 shared
//     families are exactly that pair.
//   * Placing a child at one centre leaves their other applications sitting in
//     the queue, ahead of families who are still waiting for anything at all.
//
// ONE FAMILY, ONE ROW - AND THE GROUPING RULE IS THE WHOLE DIFFICULTY.
// An application carries no cross-centre identity: kidsoft_child_id is set on 2
// of the 715 open rows, because a family on a waiting list is not enrolled
// anywhere yet and so has no ChildID. What is left is what the parent typed into
// each application, and it is typed afresh each time. Measured across the 77
// name+DOB collisions in the live queue:
//
//   * PHONE ALONE SPLITS REAL FAMILIES. Anika Struthers is one child with one
//     DOB and one family email, applied for at three centres on two different
//     parent mobiles; Riley Klonaris and William Naylor are each one child whose
//     two applications carry mobiles differing by a single mistyped digit. Keyed
//     on the phone they read as six separate families instead of three. (This is
//     why search.php's per-row key is not reused here: it answers "is this the
//     person on the phone", where a duplicated row costs a glance. Here a
//     duplicated row is a wrong headline figure.)
//   * NAME + DOB ALONE MERGES DIFFERENT FAMILIES. Parents applying before the
//     birth enter placeholder names: the live queue holds three separate "TBA
//     Anderson"/"TBA Smith"/"TBA Johnson" children, two "TBA TBA" and two "Baby
//     Tweedie", all real, all different families.
//
// So the rule is BOTH: the same folded name AND the same date of birth AND a
// shared parent mobile or email. Every one of the placeholder collisions above
// differs on DOB, and 76 of the 77 collisions share a phone or an email, so the
// rule costs exactly one miss in the live data - two parents of one child who
// each applied at Terrigal under their own mobile and their own address, which
// nothing short of guessing could join. Erring towards a miss is deliberate and
// is the same asymmetry name_is_near() is built on: a missed merge shows one
// child on two rows, which a human reads and understands, while a false merge
// silently tells the team that two different children are one and hides a family
// from the register entirely.
//
// A row with no DOB (1 of 715) never merges with anything. Matching kidsoft_child_ids
// merge outright - that is an identity, not an inference.
//
// Nothing here is stored. Grouping is computed per request from the same
// waitlist_for_centre() the centre view uses, so queue positions, priority
// reasons, expiry countdowns and open offers all have ONE implementation and
// cannot disagree between the two tabs.

require_once __DIR__ . '/waitlist.php';
require_once __DIR__ . '/drift.php';      // name_key() / name_fold()
require_once __DIR__ . '/siblings.php';   // sibling_phone_key() - the household rule
require_once __DIR__ . '/users.php';   // user_can_centre() - what a supervisor is told about another centre's queue

/**
 * Group applications belonging to the same child.
 *
 * Union-find rather than a single composite key, because the joins are
 * transitive: Anika Struthers' Tuggerah and Terrigal rows share a mobile, her
 * Wamberal row shares only the email, and all three are one child. A one-pass
 * key would have produced two families out of that and reported the wrong number
 * of centres for both.
 *
 * @param array $apps applications, each with id / child names / child_dob /
 *                    guardian_phone / guardian_email / kidsoft_child_id
 * @return array<int,array<int,array>> groups of applications, keyed arbitrarily
 */
function waitlist_group_families(array $apps): array {
    $parent = [];
    foreach ($apps as $a) $parent[(int)$a['id']] = (int)$a['id'];

    $root = static function (int $x) use (&$parent): int {
        while ($parent[$x] !== $x) {
            $parent[$x] = $parent[$parent[$x]];   // path halving
            $x = $parent[$x];
        }
        return $x;
    };
    $union = static function (int $a, int $b) use (&$parent, $root): void {
        $ra = $root($a); $rb = $root($b);
        if ($ra !== $rb) $parent[$rb] = $ra;
    };

    // A shared Kidsoft ChildID is an identity, so it joins on its own. Rare (2 of
    // 715 open rows) and free to honour.
    $byChild = [];
    foreach ($apps as $a) {
        $ks = $a['kidsoft_child_id'];
        if ($ks === null || $ks === '') continue;
        if (isset($byChild[$ks])) $union($byChild[$ks], (int)$a['id']); else $byChild[$ks] = (int)$a['id'];
    }

    // Everything else: same name and same DOB is the gate, a shared parent mobile
    // or email is the evidence. Both are required - see the header.
    $buckets = [];
    foreach ($apps as $a) {
        if ($a['child_dob'] === null) continue;     // nothing to gate on: stays its own family
        // name_identity_key(), NOT name_key(). This is the gate on a merge that
        // "hides a family from the register entirely" - the header's own words -
        // and name_fold() strips digits, so `Twin 1 Wolfenden` and `Twin 2
        // Wolfenden` folded to one name, matched on DOB and matched on their
        // mother's mobile. Two open applications at Niagara Park, positions 25
        // and 26, reported as one child waiting twice. Found 20 Aug 2026.
        //
        // The placeholder census in the header is unchanged by this and still
        // holds: it tested placeholders in DIFFERENT households, which differ on
        // DOB. Two placeholders inside ONE household is the case it never had.
        $key = name_identity_key((string)$a['child_first_name'], (string)$a['child_last_name'])
             . '|' . substr((string)$a['child_dob'], 0, 10);
        $buckets[$key][] = $a;
    }
    foreach ($buckets as $group) {
        if (count($group) < 2) continue;
        $seen = [];
        foreach ($group as $a) {
            foreach ([
                'p:' . (sibling_phone_key($a['guardian_phone']) ?? ''),
                'e:' . sibling_email_key($a['guardian_email']),
            ] as $ident) {
                if ($ident === 'p:' || $ident === 'e:') continue;   // no identifier of that kind
                if (isset($seen[$ident])) $union($seen[$ident], (int)$a['id']);
                else $seen[$ident] = (int)$a['id'];
            }
        }
    }

    $out = [];
    foreach ($apps as $a) $out[$root((int)$a['id'])][] = $a;
    return $out;
}

/**
 * Which centres a family is waiting at, and everything about the family that is
 * true across all of their applications.
 */
function waitlist_family_summarise(array $apps, array $centreNames): array {
    // Oldest application first: the order tells the story of where they tried
    // first, and the earliest date added is the one that describes the wait.
    usort($apps, static fn($a, $b) => [$a['date_added'], $a['centre_id']] <=> [$b['date_added'], $b['centre_id']]);
    $first = $apps[0];

    // The best contact details the family gave on ANY of their applications.
    // Nobody rings "the Wamberal application", they ring the family, and one of
    // several applications may be the one carrying the mobile.
    $phone = $email = $guardian = null;
    // DO NOT ENROL, if ANY of their applications matches. They are one family on
    // this register, and the one match is what anybody reading the row needs.
    $dne = null;
    foreach ($apps as $a) {
        $dne ??= $a['dne'] ?? null;
        if ($phone === null    && trim((string)$a['guardian_phone']) !== '') { $phone = $a['guardian_phone']; $guardian ??= $a['guardian_name']; }
        if ($email === null    && trim((string)$a['guardian_email']) !== '') $email = $a['guardian_email'];
        if ($guardian === null && trim((string)$a['guardian_name'])  !== '') $guardian = $a['guardian_name'];
    }

    $days = [];
    $daySets = [];
    $openApps = [];
    $requiredFrom = null;
    $offers = 0;
    $rows = [];
    foreach ($apps as $a) {
        $open = !in_array($a['status'], ['placed', 'withdrawn', 'expired'], true);
        if ($open) {
            $openApps[] = $a;
            $daySets[implode(',', $a['days'])] = true;
            foreach ($a['days'] as $d) $days[$d] = true;
            // Plain yyyy-mm-dd strings, so min() on them is a date comparison.
            $rf = substr((string)($a['required_from'] ?? ''), 0, 10);
            if ($rf !== '') $requiredFrom = $requiredFrom === null ? $rf : min($requiredFrom, $rf);
        }
        // Either kind of offer: Kidsoft's, where they are made now, or one left
        // from before this app stopped making them.
        $hasOffer = $a['open_offer'] !== null || $a['kidsoft_offer'] !== null;
        if ($hasOffer) $offers++;

        $rows[] = [
            'id'              => (int)$a['id'],
            'centre_id'       => (int)$a['centre_id'],
            'centre'          => $centreNames[(int)$a['centre_id']] ?? '',
            'open'            => $open,
            'status'          => $a['status'],
            // waitlist_for_centre() already composed this - see
            // waitlist_status_label(); an expired OFFER is not the same word as
            // an expired waiting place.
            'status_label'    => $a['status_label'],
            // What the "Offer expired" segment filters families on. One
            // definition, shared with the label above - waitlist_is_offer_expired().
            'offer_expired'   => waitlist_is_offer_expired($a),
            // Null on a closed application - it holds no place in the queue.
            // Only ever rendered behind an `open` test, but a hard 0 leaking
            // onto a screen reads as a real position rather than as absence.
            'queue_position'  => $a['queue_position'] === null ? null : (int)$a['queue_position'],
            'days'            => $a['days'],
            'date_added'      => $a['date_added'],
            'for_year'        => $a['for_year'] === null ? null : (int)$a['for_year'],
            'reasons'         => $a['reasons'],
            'has_offer'       => $hasOffer,
            // The open In progress item on this application, if any, so the
            // other centres' queues can flag a family somebody is already
            // working - waitlist_elsewhere().
            'work'            => $a['work'] ?? null,
        ];
    }
    // A family with nothing open is history, shown only under "All". Their days
    // come from the closed applications, because an empty Mon-Fri strip reads as
    // "asked for no days" rather than "no longer waiting".
    if (!$openApps) {
        foreach ($apps as $a) foreach ($a['days'] as $d) $days[$d] = true;
    }
    ksort($days);

    $openCentres = array_values(array_unique(array_map(
        static fn($a) => (int)$a['centre_id'], $openApps
    )));

    return [
        'name'            => trim($first['child_first_name'] . ' ' . $first['child_last_name']),
        'child_dob'       => $first['child_dob'],
        'age_months'      => $first['age_months'],
        'guardian_name'   => $guardian,
        'guardian_phone'  => $phone,
        'guardian_email'  => $email,
        'dne'             => $dne,
        'days'            => array_keys($days),
        // Only worth saying when they asked for DIFFERENT days at different
        // centres - 15 of the 73 shared families do, and offering them the days
        // from the wrong application is how an offer gets declined.
        'days_differ'     => count($daySets) > 1,
        'date_added'      => $first['date_added'],
        // THE SOONEST THEY NEED A PLACE, across every application they hold.
        // What waitlist_filter_start() tests, under the name the centre queue's
        // rows carry, so one function serves both tabs the way waitlist_filter_age()
        // does. The EARLIEST, because a family asking Terrigal for January 2027
        // and Wamberal for January 2028 is a family who needs a place in 2027 -
        // hiding them on their furthest-off application would drop a row the
        // register exists to show.
        'required_from'   => $requiredFrom,
        'offers'          => $offers,
        'applications'    => $rows,
        'open_count'      => count($openApps),
        'centre_ids'      => $openCentres,
        'centre_count'    => count($openCentres),
    ];
}

/**
 * Where else the child on each of ONE centre's applications is waiting.
 *
 * This is the group register's finding brought back to the centre queue, which is
 * where the calls actually get made: 75 of the 620 children on the list are
 * waiting at more than one centre and hold 171 of the 710 open applications, and
 * until now the only screen that could see it was the All centres tab. Ringing a
 * family about a Tuesday at Wamberal without knowing they are also third in line
 * at Terrigal is half a conversation, and placing a child leaves their other
 * applications sitting in queues ahead of families still waiting for anything.
 *
 * COMPOSED FROM waitlist_group_all(), not from a second join. The grouping rule is
 * the whole difficulty here (see the header) and a cheaper approximation on this
 * screen would mean the centre queue and the register disagreeing about who is one
 * family - which is exactly the disagreement `days_differ` exists to prevent.
 * Measured 17 Aug 2026: waitlist_for_centre() alone is 0.124s cold, the whole
 * group 0.142s, because the expensive part is the Kidsoft enrolled-family index
 * and waitlist_mark_in_care() already builds that for EVERY centre when one centre
 * is loaded. So knowing about the other six costs ~0.02s.
 *
 * ONLY OPEN APPLICATIONS ELSEWHERE, the same rule the register's aggregates use:
 * a placed or lapsed application at another centre is history, and reporting it as
 * "also waiting" is how a queue gets rung twice. Closed applications AT THIS
 * CENTRE still get their entry, because "placed here, still waiting at Terrigal"
 * is one of the two things this view is for.
 *
 * ONE CHIP PER CENTRE. A child with two open applications at one other centre is
 * real (Tuggerah holds one), but the question this column answers is WHICH
 * centres, so the earliest-added of them stands for that centre rather than
 * printing the name twice.
 *
 * @return array<int,array<int,array>> application id => the other centres, if any
 */
function waitlist_elsewhere(int $centreId): array {
    static $memo = [];
    if (isset($memo[$centreId])) return $memo[$centreId];

    // include_closed, so a family placed or lapsed HERE still resolves to their
    // other applications. The open test below is what decides what is shown.
    $g = waitlist_group_all(['include_closed' => true]);

    $out = [];
    foreach ($g['families'] as $f) {
        $mine = [];
        $others = [];
        // ANOTHER CENTRE ALREADY WORKING THIS FAMILY (22 Oct 2026, the enrolments
        // meeting: "can there be a flag if one centre identifies a family when it
        // is not for their centre?"). 12% of the queue is waiting at more than
        // one centre, so a family one centre has rung, toured or offered can sit
        // untouched-looking in another centre's queue and be rung again. Taken
        // from ANY open application at that centre, not only the earliest one the
        // chip stands for, so a second application there cannot hide it.
        $working = [];
        foreach ($f['applications'] as $a) {
            if ((int)$a['centre_id'] === $centreId || !$a['open'] || empty($a['work'])) continue;
            $working[(int)$a['centre_id']] ??= $a['work'];
        }
        foreach ($f['applications'] as $a) {
            if ((int)$a['centre_id'] === $centreId) { $mine[] = $a; continue; }
            if (!$a['open']) continue;
            if (isset($others[(int)$a['centre_id']])) continue;   // earliest wins
            // WHAT A SUPERVISOR IS TOLD ABOUT ANOTHER CENTRE'S QUEUE: the name of
            // the centre, and nothing else. (19 Aug 2026.)
            //
            // The fact itself STAYS - "this family is also waiting at Niagara
            // Park" is about a family already in their own queue, and it is one
            // of the two things that decide whether they offer a place. Hiding it
            // would leave their ranking disagreeing with a manager's on the same
            // row. What goes is the other centre's business: where the family
            // sits in that queue, which days they asked for there, when they
            // applied and whether an offer is open.
            //
            // `days` STAYS AN ARRAY when it is emptied, never null. app.js does
            // `o.days.join(',')` in two places, and a null there is a thrown
            // TypeError that takes the whole column with it - the null-as-array-key
            // trap CLAUDE.md names, in the other language.
            $scoped = user_can_centre((int)$a['centre_id']);
            $others[(int)$a['centre_id']] = [
                'centre_id'      => (int)$a['centre_id'],
                'centre'         => $a['centre'],
                'scoped'         => $scoped,
                'queue_position' => $scoped ? $a['queue_position'] : null,
                'days'           => $scoped ? $a['days'] : [],
                'date_added'     => $scoped ? $a['date_added'] : null,
                'has_offer'      => $scoped ? $a['has_offer'] : false,
                // THE FLAG ITSELF IS SHOWN TO EVERYBODY, a supervisor of this
                // centre included: "Wamberal is already working with them" is
                // about a family in their own queue and is the whole reason it was
                // asked for. How far along - the stage - is the other centre's
                // business, so only somebody who holds that centre gets it.
                'work'           => isset($working[(int)$a['centre_id']])
                    ? ['stage_label' => $scoped ? $working[(int)$a['centre_id']]['stage_label'] : null]
                    : null,
            ];
        }
        if (!$mine || !$others) continue;
        $others = array_values($others);
        foreach ($mine as $a) $out[(int)$a['id']] = $others;
    }
    return $memo[$centreId] = $out;
}

/**
 * Every application in the group, grouped into children - before anything is
 * summarised for a screen. Memoised for the request, because the register, the
 * queue's "Also waiting" column and its sibling line all want the same groups,
 * and building them is eight waitlist_for_centre() calls.
 *
 * Deliberately no 'q' here: a search narrows FAMILIES, after the grouping.
 * Filtering per centre first would drop the very applications that prove a
 * family is waiting in two places, which is what the register is for. AND
 * DELIBERATELY NO 'offer_expired' EITHER, for the same reason: it is a FAMILY
 * filter, applied after the grouping with `q` and `multi_only` - narrowing per
 * centre would hand back a family showing only their lapsed offer while the open
 * application they are still waiting on at another centre vanished.
 *
 * @return array<int,array<int,array>> groups of waitlist_for_centre() rows
 */
function waitlist_group_raw(bool $includeClosed): array {
    static $memo = [];
    $k = (int)$includeClosed;
    if (isset($memo[$k])) return $memo[$k];

    $apps = [];
    foreach (centres_all() as $c) {
        foreach (waitlist_for_centre((int)$c['id'], ['include_closed' => $includeClosed]) as $w) {
            $apps[] = $w;
        }
    }
    return $memo[$k] = waitlist_group_families($apps);
}

// ---------------------------------------------------------------------------
// A SIBLING WHO IS ALSO WAITING (16 Oct 2026).
//
// Broc: "Could we also in Waitlist pick up if there is another sibling on the
// waitlist?" The Sibling rung already finds a brother or sister ENROLLED with us
// (siblings.php). Two children of one family who are BOTH still waiting were
// invisible: nothing joined their rows, so one could be rung about a Tuesday at
// Niagara Park while the other sat further down the same queue, or at Terrigal.
//
// IT DOES NOT RANK. The ladder's Sibling rung is a sibling already in care, and
// a family with two children waiting has no place with us yet. This is said on
// the row, beside the other centres the child is waiting at, and nowhere else.
//
// THE MATCH IS A SHARED PARENT MOBILE OR EMAIL BETWEEN TWO DIFFERENT CHILDREN,
// where "child" is the register's own grouping (waitlist_group_families()), so a
// child applied for at three centres is one child here too. Measured 16 Oct 2026
// over 579 open applications, 498 children: 52 pairs share a contact, on both
// identifiers for 49 of them, and no contact is shared by more than three
// children - so the grandmother's-mobile problem the enrolled match had to filter
// out does not show up here (an application's contact is the parent who applied).
//
// THE HARD PART IS "DIFFERENT", and the dates of birth answer it. Sorted by the
// gap between the two DOBs, the 52 pairs fall into two clean groups:
//
//   0-96 days apart: ONE child applied for twice. A due date that moved (Caleb
//     Dougan at Tuggerah and Kanwal, 96 days; Tbc Childs, 19), a placeholder
//     beside the real name (Baby Sawyer / Elliot Sawyer, 2 days; Unborn Adam /
//     Charlotte Adam, 18), or the same name typed twice (Teddy Arber, Harvey
//     Knox). The register's merge needs the SAME DOB, so it rightly leaves these
//     apart - and calling them siblings would be wrong in the other direction.
//   363 days and up: real brothers and sisters.
//
// The only real siblings born within 270 days of each other are twins, born the
// SAME day - and at 0 days the name has to settle it: Pearl and Vera Woodward,
// Theo and Noah Kerrison, Himmat Singh and Rehmat Kaur, Twin 1 and Twin 2
// Wolfenden are twins; Millie Sharp / Millie Sharpe and Amelia / Amelia Grace
// Sheppard are one child each. So:
//
//   * DOBs 1-269 days apart: the same child. Not a sibling.
//   * Same DOB, or one missing: siblings only if the first names differ, neither
//     contains the other, and neither is a placeholder ("Baby", "TBA", ...).
//
// Through this function the same afternoon: 43 pairs, 80 children (one pair
// fewer because the Wolfenden twins' applications lapsed in between), and 71
// once a sibling the Priority column already names as ENROLLED is left off the
// line - see the note in waitlist_siblings_waiting(). A missed
// sibling costs a line nobody reads; a false one tells the team a family has two
// children waiting when it has one.
// ---------------------------------------------------------------------------

// WAITLIST_SIBLING_SAME_CHILD_DAYS, WAITLIST_PLACEHOLDER_FIRST_NAMES and
// waitlist_same_child() MOVED TO siblings.php (19 Oct 2026), unchanged, so the
// work layer can ask the same question for one centre without loading this
// file's group-wide build (22s cold - see work_siblings_here()). One rule, one
// definition; the measurement above is still what it rests on.

/**
 * The brothers and sisters ALSO WAITING, for each application at one centre.
 *
 * Open applications on both sides: a placed child is a sibling ENROLLED with us,
 * which is the Sibling rung's job, and a lapsed application is nobody waiting.
 *
 * One entry per sibling CHILD, however many centres they are waiting at. The
 * application shown for them is the one at THIS centre if they have one (their
 * place in the same queue is the useful fact), otherwise their earliest.
 *
 * A SIBLING WAITING AT A CENTRE THIS USER DOES NOT HOLD is "a sibling at
 * Terrigal" and nothing more - no name, no position, no id - the same line
 * sibling_matches_for_centre() draws for a sibling enrolled there (19 Aug 2026).
 *
 * @return array<int,array<int,array>> application id => siblings
 */
function waitlist_siblings_waiting(int $centreId): array {
    static $memo = [];
    if (isset($memo[$centreId])) return $memo[$centreId];

    $names = [];
    foreach (centres_all() as $c) $names[(int)$c['id']] = $c['name'];

    // Each child with something open, and every contact they were applied under.
    $kids = [];
    foreach (waitlist_group_raw(true) as $group) {
        $open = array_values(array_filter($group,
            static fn($a) => !in_array($a['status'], ['placed', 'withdrawn', 'expired', 'archived'], true)));
        if (!$open) continue;
        usort($open, static fn($a, $b) => [$a['date_added'], (int)$a['id']] <=> [$b['date_added'], (int)$b['id']]);
        $keys = [];
        foreach ($open as $a) {
            $p = sibling_phone_key($a['guardian_phone']);
            $e = sibling_email_key($a['guardian_email']);
            if ($p !== null) $keys['p:' . $p] = true;
            if ($e !== null) $keys['e:' . $e] = true;
        }
        $kids[] = ['apps' => $open, 'keys' => array_keys($keys)];
    }
    $byKey = [];
    foreach ($kids as $i => $k) foreach ($k['keys'] as $key) $byKey[$key][] = $i;

    $out = [];
    foreach ($kids as $i => $k) {
        $mine = array_filter($k['apps'], static fn($a) => (int)$a['centre_id'] === $centreId);
        if (!$mine) continue;

        // A SIBLING THE PRIORITY COLUMN ALREADY NAMES IS NOT SAID TWICE. A child
        // enrolled with us can also hold an application (Wyatt Smith at Niagara
        // Park is in care AND 2nd in the queue), and his brother's row already
        // reads "Sibling of Grace Smith, Wyatt Smith" off the Sibling rung. Same
        // name key that rung builds - sibling_name_key().
        $named = [];
        foreach ($mine as $a) {
            foreach ($a['sibling_match']['siblings'] ?? [] as $c) {
                if (!empty($c['key'])) $named[$c['key']] = true;
            }
        }

        $sibs = [];
        foreach ($k['keys'] as $key) {
            foreach ($byKey[$key] as $j) {
                if ($j === $i || isset($sibs[$j])) continue;
                if (waitlist_same_child($k['apps'][0], $kids[$j]['apps'][0])) continue;
                $first = $kids[$j]['apps'][0];
                if (isset($named[sibling_name_key((string)$first['child_first_name'],
                                                  (string)$first['child_last_name'])])) continue;

                $apps = $kids[$j]['apps'];
                $here = array_values(array_filter($apps, static fn($a) => (int)$a['centre_id'] === $centreId));
                $a    = $here[0] ?? $apps[0];
                $cid  = (int)$a['centre_id'];
                $scoped = user_can_centre($cid);
                // The FIRST name alone when the surname is this child's: the
                // column is ~7rem and "Sibling Wyatt #2" says it on one line.
                // Himmat Singh and Rehmat Kaur keep both halves.
                $same = sibling_fold((string)$a['child_last_name'])
                     === sibling_fold((string)$k['apps'][0]['child_last_name']);
                $sibs[$j] = [
                    'id'             => $scoped ? (int)$a['id'] : null,
                    'name'           => $scoped ? trim($a['child_first_name'] . ' ' . $a['child_last_name']) : null,
                    'short'          => $scoped ? trim($same ? $a['child_first_name']
                                                         : $a['child_first_name'] . ' ' . $a['child_last_name']) : null,
                    'centre_id'      => $cid,
                    'centre'         => $names[$cid] ?? '',
                    'here'           => $cid === $centreId,
                    'scoped'         => $scoped,
                    'queue_position' => $scoped && $a['queue_position'] !== null ? (int)$a['queue_position'] : null,
                ];
            }
        }
        if (!$sibs) continue;
        // Here before elsewhere, then by place in the queue: the one nearest the
        // top is the one somebody is about to ring.
        $sibs = array_values($sibs);
        usort($sibs, static fn($x, $y) =>
            [!$x['here'], $x['queue_position'] ?? PHP_INT_MAX, (string)$x['name']]
            <=> [!$y['here'], $y['queue_position'] ?? PHP_INT_MAX, (string)$y['name']]);
        foreach ($mine as $a) $out[(int)$a['id']] = $sibs;
    }
    return $memo[$centreId] = $out;
}

/**
 * The whole organisation's waitlist, one entry per child.
 *
 * @param array $opts include_closed, offer_expired, multi_only, q, age_min, age_max,
 *                    starts_by, starts_from
 * @return array{families:array, centres:array, pairs:array, totals:array}
 */
function waitlist_group_all(array $opts = []): array {
    $centres = centres_all();
    $names   = [];
    foreach ($centres as $c) $names[(int)$c['id']] = $c['name'];

    $families = [];
    foreach (waitlist_group_raw(!empty($opts['include_closed'])) as $group) {
        $families[] = waitlist_family_summarise($group, $names);
    }

    // ---- Aggregates, computed over the OPEN queue only ----------------------
    // A closed application is history: counting a placed family as "waiting at
    // two centres" would overstate both the register and the overlap.
    $perCentre = [];
    foreach ($centres as $c) {
        $perCentre[(int)$c['id']] = [
            'centre_id' => (int)$c['id'], 'centre' => $c['name'],
            'applications' => 0, 'families' => 0, 'shared' => 0,
        ];
    }
    $pairCount = [];
    $totals = ['families' => 0, 'applications' => 0, 'multi' => 0,
               'multi_applications' => 0, 'newly_added' => 0, 'offers' => 0];
    // A family is newly added when their FIRST application is - the date the
    // row prints. A family waiting at Terrigal since March who applied to
    // Wamberal last week is new to Wamberal's queue, not to the organisation.
    $newSince = waitlist_new_since();

    foreach ($families as $f) {
        if (!$f['centre_ids']) continue;              // nothing open: history only
        $totals['families']++;
        $totals['applications'] += $f['open_count'];
        $totals['offers']       += $f['offers'];
        if ($f['date_added'] >= $newSince) $totals['newly_added']++;
        $multi = $f['centre_count'] > 1;
        if ($multi) {
            $totals['multi']++;
            $totals['multi_applications'] += $f['open_count'];
        }
        foreach ($f['applications'] as $a) {
            if (!$a['open']) continue;
            $perCentre[$a['centre_id']]['applications']++;
        }
        foreach ($f['centre_ids'] as $cid) {
            $perCentre[$cid]['families']++;
            if ($multi) $perCentre[$cid]['shared']++;
        }
        if (!$multi) continue;
        $ids = $f['centre_ids'];
        sort($ids);
        for ($i = 0; $i < count($ids); $i++) {
            for ($j = $i + 1; $j < count($ids); $j++) {
                $pairCount[$ids[$i] . ':' . $ids[$j]] = ($pairCount[$ids[$i] . ':' . $ids[$j]] ?? 0) + 1;
            }
        }
    }

    $pairs = [];
    foreach ($pairCount as $k => $n) {
        [$a, $b] = array_map('intval', explode(':', $k));
        $pairs[] = ['a' => $names[$a] ?? '', 'a_id' => $a,
                    'b' => $names[$b] ?? '', 'b_id' => $b, 'n' => $n];
    }
    usort($pairs, static fn($x, $y) => [$y['n'], $x['a']] <=> [$x['n'], $y['a']]);

    // ---- Narrow to what was asked for --------------------------------------
    // Families with at least one lapsed offer, keeping ALL of their applications
    // on the row - see the note where $apps is built.
    if (!empty($opts['offer_expired'])) {
        $families = array_values(array_filter($families, static function ($f) {
            foreach ($f['applications'] as $a) if ($a['offer_expired']) return true;
            return false;
        }));
    }
    if (!empty($opts['multi_only'])) {
        $families = array_values(array_filter($families, static fn($f) => $f['centre_count'] > 1));
    }
    if (!empty($opts['q'])) {
        $needle = mb_strtolower(trim((string)$opts['q']));
        $digits = preg_replace('/\D/', '', $needle) ?? '';
        $families = array_values(array_filter($families, static function ($f) use ($needle, $digits) {
            $hay = mb_strtolower(trim($f['name'] . ' ' . (string)$f['guardian_name']
                                    . ' ' . (string)$f['guardian_email']));
            if (str_contains($hay, $needle)) return true;
            // A caller ID pasted into the box should find them here too, the way
            // it does in the search palette.
            return $digits !== '' && strlen($digits) >= 4
                && str_contains(preg_replace('/\D/', '', (string)$f['guardian_phone']) ?? '', $digits);
        }));
    }
    // Same age filter the centre queue uses, on the same shared function, so the
    // two tabs cannot disagree about who is three. A family's age here is the
    // one on their first application - they are one child, so their several
    // applications carry one DOB between them.
    $families = waitlist_filter_age($families, $opts);
    // And the same start-date filter, on the same shared function, so a family
    // who is years away is off both tabs or on both. Theirs is the EARLIEST date
    // across their applications - see waitlist_family_summarise().
    $families = waitlist_filter_start($families, $opts['starts_by'] ?? null, $opts['starts_from'] ?? null);

    // Longest waiting first, and nothing else. This is NOT a queue - the
    // priority ladder only means anything inside one centre - so the one axis
    // that is genuinely comparable across the group is how long a family has
    // been waiting for an answer from anybody. Floating the multi-centre
    // families to the top was tried and dropped: it is an opinion dressed as an
    // order, and the filter above says the same thing without hiding it.
    usort($families, static fn($a, $b) =>
        [$a['date_added'], $a['name']] <=> [$b['date_added'], $b['name']]);

    return [
        'families' => $families,
        'centres'  => array_values($perCentre),
        'pairs'    => $pairs,
        'totals'   => $totals,
    ];
}
