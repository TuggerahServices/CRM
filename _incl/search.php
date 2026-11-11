<?php
// Group-wide search: "a parent is on the phone - who are they?"
//
// WHY THIS EXISTS. The one thing this app could not do was answer the call that
// starts the enrolments team's day. A parent rings and the team has a caller ID
// and nothing else, or a half-heard surname; every screen in this app is scoped
// to a centre and a year, so answering meant guessing which centre, opening the
// Board, opening the Waitlist, and reading. The data was all here - 12,699
// guardians with 16,591 phone numbers, 707 waitlist applications with a parent
// mobile each - and none of it was reachable by the identifier the caller
// actually gives you.
//
// NOTHING IS COPIED INTO OUR TABLES for this, and it does not need to be.
// Measured 3 Aug 2026: every guardian and every child-to-guardian link for all
// SEVEN centres reads back out of kidsoft_cache in **0.12s** (43MB peak), because
// _incl/cron-nightly.php already warms child_contacts() per centre for the cell
// panel - which is exactly the two calls this needs. So search reads Kidsoft the
// way CLAUDE.md says to: live and cached. A search_index table would have been a
// copy that goes stale the moment a family changes their number, to save a tenth
// of a second.
//
// THE FUZZINESS HERE IS DELIBERATELY THE OPPOSITE OF _incl/drift.php's.
// name_is_near() is timid on purpose - it allows ONE edit, refuses a substitution
// at five characters, and would rather miss than pair Isaac with Issac - because
// a false pair there silently attributes one child's attendance to another. In a
// SEARCH BOX the asymmetry runs the other way: every result is read by a human
// before anything happens, a wrong row costs them a glance, and a missing row
// costs them the phone call. So this scores prefixes, substrings and edit
// distance generously, and RANKS rather than rejects. Do not "fix" one to match
// the other - they are answering different questions.
//
// WHOSE NUMBER IS IT is a first-class part of the answer, not a detail. 3,184
// guardians hold more than one number and 1,591 numbers are attached to more
// than one guardian, and the Type free-text includes Grandmother, Aunt,
// Neighbour and Case Worker. So a phone hit reports TWO contacts: `matched` --
// the person whose handset that number is, so staff know who they are actually
// talking to - and `contact` - the best person to ring back, down the same
// ladder child_contacts() uses. A call from a grandmother's phone about a spare
// Friday should not end with the team ringing the grandmother back.

require_once __DIR__ . '/simple-mysqli.php';
require_once __DIR__ . '/contacts.php';
require_once __DIR__ . '/waitlist.php';
require_once __DIR__ . '/drift.php';      // name_fold(), osa_distance() - reused, never re-implemented
require_once __DIR__ . '/siblings.php';   // sibling_phone_key() - the household identity rule
require_once __DIR__ . '/users.php';   // user_all_centres(), user_centre_ids() - see search_scope_results()
require_once __DIR__ . '/do-not-enrol.php';   // dne_match(), dne_for_child_links()

/** Most results anyone reads. Past this, refine the query rather than scroll. */
const SEARCH_LIMIT = 25;

/**
 * How long Kidsoft's CLOSED waitlist history is cached.
 *
 * 36 h, the same number and for the same reason as the nightly-warmed entries in
 * KIDSOFT_TTL: it has to EXCEED THE 24 h GAP BETWEEN WARMS, or the first search
 * each morning pays the cold fetch. The shared 'Waitlist/getWaitingList' TTL is
 * 900 s, which is right for the live queue the importer reads and wrong for
 * 3,500 rows of dead history - an archived 2014 application does not go stale.
 * Passed explicitly rather than added to KIDSOFT_TTL, because the two callers
 * want different answers from one endpoint and the config keys on Options only.
 */
const SEARCH_HISTORY_TTL = 129600;

/** Most closed applications listed on one result before the rest are counted. */
const SEARCH_HISTORY_PER_RESULT = 6;

/** Shortest query worth running. Two letters or four digits of a phone number. */
const SEARCH_MIN_TEXT   = 2;
const SEARCH_MIN_DIGITS = 4;

/**
 * Digits of a phone number, comparable across the formats this data holds.
 *
 * Kidsoft stores "0435714228", "+61 412 345 678", "(02) 4399 1332" and bare
 * "43991332" for the same kinds of number, so everything but the digits goes,
 * and a 61 country prefix becomes the leading 0 it stands for. Deliberately NOT
 * sibling_phone_key(), which truncates to the last 9 digits: that is right for
 * asserting two families are one household, and wrong here, where somebody types
 * the four digits they can read off a handset.
 */
function search_phone_digits(?string $s): string {
    $d = preg_replace('/\D/', '', (string)$s) ?? '';
    if (strlen($d) > 9 && str_starts_with($d, '61')) $d = '0' . substr($d, 2);
    return $d;
}

/**
 * What the user typed, classified.
 *
 * A query is treated as a phone number only when it is digits and the punctuation
 * phone numbers actually carry (+ - space brackets); "0412" is a phone, "2026" is
 * a phone too, and that is fine - it is scored, not switched to.
 *
 * @return array{raw:string, digits:string, is_phone:bool, is_email:bool, tokens:array}
 */
function search_parse(string $q): array {
    $q = trim(preg_replace('/\s+/', ' ', $q) ?? '');

    $digits  = search_phone_digits($q);
    $isPhone = $q !== '' && preg_match('/^[0-9+\-\s()]+$/', $q) === 1
               && strlen($digits) >= SEARCH_MIN_DIGITS;

    $tokens = [];
    foreach (preg_split('/[^A-Za-z]+/', $q) ?: [] as $t) {
        $t = name_fold($t);
        if ($t !== '') $tokens[] = $t;
    }

    return [
        'raw'      => $q,
        'digits'   => $digits,
        'is_phone' => $isPhone,
        'is_email' => str_contains($q, '@'),
        'tokens'   => $tokens,
    ];
}

/** Is this query long enough to run at all? */
function search_is_runnable(array $p): bool {
    if ($p['is_phone']) return true;
    $len = 0;
    foreach ($p['tokens'] as $t) $len += strlen($t);
    return $len >= SEARCH_MIN_TEXT;
}

/**
 * Split names into comparable parts, the SAME way the query is split.
 *
 * This symmetry is load-bearing and its absence was a real bug: a query is split
 * on non-letters, so "Summer-Le Farrugia" typed in comes out as three tokens
 * (summer, le, farrugia), while name_fold() on the stored "Summer-Le" collapses
 * it to one part (summerle). The token "le" then matched nothing and the whole
 * name scored no hit - searching a real parent's name returned nothing at all.
 * Splitting both sides the same way makes hyphens, apostrophes and middle names
 * ("O'Brien", "Mary Jane") agree instead of quietly failing.
 */
function search_name_parts(string ...$names): array {
    $out = [];
    foreach ($names as $n) {
        foreach (preg_split('/[^A-Za-z]+/', $n) ?: [] as $t) {
            $t = name_fold($t);
            if ($t !== '') $out[] = $t;
        }
    }
    return $out;
}

/**
 * How well a set of query tokens matches a name, lower being better; null = no.
 *
 * EVERY token must find a home somewhere in the name, so "ava ng" matches Ava
 * Nguyen while "ava smith" does not match either of them. Within a token the
 * ladder is exact, then prefix, then substring, then edit distance - a prefix
 * scoring better than a substring is what keeps "ben" showing Ben Whitmore above
 * Rueben Clarke without hiding Rueben.
 *
 * Edit distance is only attempted on parts of a similar length (a 3-letter query
 * token is not a typo of a 9-letter surname), which is also what keeps this cheap
 * enough to run over 17,000 guardian links: osa_distance() is O(n*m) and would
 * dominate if it were reached for every pair.
 *
 * @param array $parts folded name parts - first, last, nickname, whatever exists
 */
function search_name_score(array $tokens, array $parts): ?float {
    $parts = array_values(array_filter($parts, static fn($p) => $p !== ''));
    if (!$tokens || !$parts) return null;

    $total = 0.0;
    foreach ($tokens as $t) {
        $best = null;
        foreach ($parts as $p) {
            if ($t === $p)                     { $best = 0.0; break; }
            if (str_starts_with($p, $t))       { $best = min($best ?? 9.9, 0.25); continue; }
            if (strlen($t) >= 3 && str_contains($p, $t)) { $best = min($best ?? 9.9, 0.6); continue; }

            // TYPO TOLERANCE, AND ITS FLOOR IS THE WHOLE POINT (14 Aug 2026).
            //
            // This used to allow one edit from FOUR characters, which made
            // "kite" a typo of "kate" - and every Kate in the organisation came
            // back. Measured on the real query Broc reported: **67 results, of
            // which exactly ONE (Alby Kite) was a solid match and the other 66
            // were reachable only through edit distance.** "alby" was the same
            // shape: 17 results, 3 solid, 14 typos (Abby, Ally).
            //
            // A substitution at four letters is not a typo, it is a different
            // name, and this codebase already knows that: name_is_near() refuses
            // Eva/Ava and Indy/Ivy at exactly this length. So the SAME 6-character
            // floor and the SAME transposition exemption are reused here rather
            // than a second set of numbers being invented - a swap preserves the
            // letter multiset and is something fingers do (Nielsen/Neilsen,
            // Ryan/Ryna), while a substitution at short length is somebody else.
            //
            // This does NOT reconcile the two matchers, and must not be read as
            // doing so: search still RANKS rather than rejects, still scores
            // prefixes and substrings, and still allows two edits on a long token.
            // Only the floor moved, and it moved onto a number already justified.
            $lt = strlen($t); $lp = strlen($p);
            if ($lt < 4 || abs($lt - $lp) > 2) continue;
            if (min($lt, $lp) < 6 && !is_single_transposition($t, $p)) continue;
            $allow = $lt >= 8 ? 2 : 1;
            $d = osa_distance($t, $p);
            if ($d <= $allow) $best = min($best ?? 9.9, 1.0 + $d * 0.4);
        }
        if ($best === null) return null;    // this token matched nothing: not a hit
        $total += $best;
    }
    return $total / count($tokens);
}

/**
 * How well a phone number matches, lower being better; null = no.
 *
 * A full number typed or pasted from caller ID scores best, the last few digits
 * read off a handset next, and digits appearing anywhere in the number last.
 */
function search_phone_score(string $needle, ?string $hay): ?float {
    $h = search_phone_digits($hay);
    if ($h === '' || $needle === '') return null;
    if ($h === $needle) return 0.0;
    // Same number, different national prefix ("0412..." vs "412...").
    if (strlen($h) >= 9 && strlen($needle) >= 9 && substr($h, -9) === substr($needle, -9)) return 0.05;
    if (str_ends_with($h, $needle))   return 0.2;
    if (str_contains($h, $needle))    return 0.5;
    return null;
}

/** How well an email matches, lower being better; null = no. */
function search_email_score(string $needle, ?string $hay): ?float {
    $h = strtolower(trim((string)$hay));
    $n = strtolower($needle);
    if ($h === '' || $n === '') return null;
    if ($h === $n)                return 0.0;
    if (str_starts_with($h, $n))  return 0.25;
    if (str_contains($h, $n))     return 0.5;
    return null;
}

/**
 * Is this guardian link a PARENT, for the purpose of RANKING a typed NAME?
 *
 * WHY THIS EXISTS (14 Aug 2026, Broc's call). An active child here carries
 * between 2 and 8 guardians, and across 17,417 links `Grandmother` (3,070) and
 * `Aunt` (1,548) are ordinary entries - so typing a surname matched every
 * grandmother, aunt, godmother, neighbour and case worker who happened to share
 * it. Searching "Smith" returned 65 non-parent guardian hits against 20 parent
 * ones.
 *
 * IT WAS A FILTER AND IS NOW A RANK (29 Oct 2026, Broc: "need to be able to
 * search parent/guardian names"). As a filter it made those people unfindable
 * by name at all: "Susan Noble" returned NOTHING, because she is Noah Noble's
 * Grandmother, and the only way to her grandson was already knowing his name.
 * A grandmother who does the drop-offs is somebody the team looks up. So the
 * non-parent still matches, with a bigger penalty than a parent, which keeps
 * the Smith ordering - children, then parents, then everybody else - without
 * hiding anyone. Ranking rather than rejecting is this module's rule anyway.
 *
 * **A PHONE OR EMAIL MATCH CARRIES NO PENALTY AT ALL, and that asymmetry is
 * the feature, not an oversight.** Any handset in the family can ring: the
 * documented Farrugia case - a call arriving from the AUNT's number, answered
 * with the mother's name on the call-back button - is exactly what search_all()
 * is for. A name is something you type; a number is something that rings you.
 *
 * Mother/Father, OR the guardian holding the enrolment. The second limb matters:
 * 5,560 links are the signer, 90.5% of them Mother or Father, and the remaining
 * ~530 are the grandmother or aunt who is the child's actual carer and signs for
 * their days. Kinship carers are the family, whatever `Relationship` says.
 */
function search_guardian_is_parent(array $link): bool {
    if (!empty($link['Enrolment'])) return true;
    $rel = strtolower(trim((string)($link['Relationship'] ?? '')));
    return $rel === 'mother' || $rel === 'father';
}

/** Keep the lowest score seen, and remember why it was scored. */
function search_keep(?array &$best, ?float $score, string $why): void {
    if ($score === null) return;
    if ($best !== null && $best['score'] <= $score) return;
    $best = ['score' => $score, 'why' => $why];
}

/**
 * The SECOND parent on a Kidsoft application, or null when the form names none.
 *
 * WHY THIS IS READ (29 Oct 2026). Our queue table holds one guardian per
 * application - ParentGuardianOne, the parent who filled the form in - so the
 * other parent's name was in Kidsoft and reachable by nothing. Measured: 1,281
 * of the 5,450 applications Kidsoft holds name a second parent, 156 of them on
 * the 1,015 we hold in the queue. Their `RelationshipToChild` is an unlabelled
 * id, so nothing here claims Mother or Father; it is the form's "Parent/Guardian
 * Two", a parent by construction, and ranks as one.
 */
function search_second_parent(array $enrolmentData): ?array {
    $g = $enrolmentData['ParentGuardianTwo'] ?? [];
    $name = trim(name_case(trim((string)($g['FirstName'] ?? ''))) . ' '
               . name_case(trim((string)($g['LastName'] ?? ''))));
    if ($name === '') return null;
    $phone = trim((string)($g['MobilePhone'] ?? ''));
    $email = trim((string)($g['Email'] ?? ''));
    return ['name' => $name, 'phone' => $phone !== '' ? $phone : null, 'email' => $email !== '' ? $email : null];
}

/**
 * Score a typed name against an application's second parent, as `matched`.
 *
 * Returned as the card's `matched` line because the card's call-back strip names
 * the FIRST parent: a search for the father that lands on a card naming only the
 * mother reads as a wrong result unless the card says whose name it was.
 *
 * @return array{0:?float, 1:?array} the score with the parent penalty, and `matched`
 */
function search_second_parent_hit(array $p, ?array $two): array {
    if (!$two || !$p['tokens'] || $p['is_email']) return [null, null];
    $s = search_name_score($p['tokens'], search_name_parts($two['name']));
    if ($s === null) return [null, null];
    $s += 0.4;
    return [$s, [
        'score'        => $s,
        'via'          => 'guardian',
        'guardian'     => $two['name'],
        'relationship' => null,
        'phone'        => $two['phone'],
        'phone_type'   => null,
        'email'        => $two['email'],
        'is_enrolling' => false,
    ]];
}

/**
 * Second parents of the applications we HOLD, keyed on Kidsoft's WaitListID.
 *
 * Read from the same cached history payload search_attach_kidsoft_history()
 * reads, because that is the one getWaitingList entry warmed nightly WITH
 * details. It is decoded a second time for this, 133 ms warm across the group,
 * because the queue is attached before the history and the history needs the
 * queue's cards in place to join them. Only on a name query. A centre Kidsoft
 * will not answer for is skipped here and reported by the history pass.
 */
function search_second_parents_held(array $centres, array $held): array {
    $out = [];
    foreach ($centres as $c) {
        $sid = $c['kidsoft_service_id'];
        try {
            $res = kidsoft_cached('Waitlist', 'getWaitingList',
                search_history_params($sid), SEARCH_HISTORY_TTL);
        } catch (RuntimeException $e) {
            continue;
        }
        foreach (kidsoft_rows($res, 'WaitingList', $sid) as $w) {
            $id = (string)($w['WaitListID'] ?? '');
            if ($id === '' || !isset($held[$id])) continue;
            $two = search_second_parent($w['Details']['EnrolmentData'] ?? []);
            if ($two) $out[$id] = $two;
        }
        unset($res);
    }
    return $out;
}

/**
 * Search everything the enrolments team might be asked about.
 *
 * Three sources, merged on the child so one call produces one answer:
 *   1. Kidsoft's children and their guardians - who is enrolled, and every
 *      phone number and email attached to the family.
 *   2. Our placements - which room and which weekdays, for each board year.
 *   3. Our waitlist applications and their open offers - where a family sits in
 *      the queue and what has been offered.
 *
 * Then two passes over what those produced: search_merge_children() folds a
 * child's several records into one card, and search_attach_rooms() says which
 * room an enrolled child is actually in, so the card has somewhere to go.
 *
 * @param array $opts limit
 * @return array{ok:bool, results:array, counts:array, took_ms:int, degraded:array}
 */
function search_all(string $q, array $opts = []): array {
    $t0    = microtime(true);
    $p     = search_parse($q);
    $limit = (int)($opts['limit'] ?? SEARCH_LIMIT);

    if (!search_is_runnable($p)) {
        return ['ok' => true, 'query' => $p, 'results' => [], 'counts' => [],
                'took_ms' => 0, 'degraded' => []];
    }

    $centres = centres_all();
    $out     = [];       // merge key => result
    $degraded = [];      // centres Kidsoft would not answer for

    // DO NOT ENROL, read before section 1 rather than lazily inside it: the index
    // walks every centre's guardians, and building it while section 1 holds one
    // centre's would stack the two in memory. 0.08s. See _incl/do-not-enrol.php.
    dne_index();

    // ---- 1. Enrolled children and their families, from Kidsoft ---------------
    foreach ($centres as $c) {
        $sid = $c['kidsoft_service_id'];
        try {
            $kids = kidsoft_rows(kidsoft_cached('Children', 'getChildren', [
                'ServiceID' => $sid, 'Options' => 'Guardian',
            ]), 'Children', $sid);
            $gmap = guardians_by_id($sid);
        } catch (RuntimeException $e) {
            // One unreachable centre must not blank the whole search - but the
            // caller has to be told, or a missing family reads as "not with us".
            $degraded[] = $c['name'];
            continue;
        }

        foreach ($kids as $k) {
            $childId = (string)($k['ChildID'] ?? '');
            if ($childId === '') continue;

            $first = name_case(trim((string)($k['FirstName'] ?? '')));
            $last  = name_case(trim((string)($k['LastName'] ?? '')));
            $nick  = trim((string)($k['NickName'] ?? ''));

            // Match the child themselves. NickName is included for the same
            // reason drift matching uses it: staff and families use the name the
            // family uses ("Alex" for ALEXANDER COOK).
            $best = null;
            if ($p['tokens']) {
                search_keep($best, search_name_score($p['tokens'],
                    search_name_parts($first, $last, $nick)), 'name');
            }

            // Match the family. Ranked first so that when several guardians could
            // match, the one reported as "whose number this is" is the one Kidsoft
            // would have you ring anyway.
            $links   = guardian_links_ranked($k['Guardian'] ?? []);
            $matched = null;      // the guardian the QUERY hit
            $contact = null;      // the guardian to ring back

            foreach ($links as $l) {
                $g = $gmap[$l['GuardianID'] ?? ''] ?? null;
                if (!$g) continue;

                if ($contact === null) $contact = guardian_contact($l, $g);

                $gName = trim(name_case(trim((string)($l['GuardianFirstName'] ?? ''))) . ' '
                            . name_case(trim((string)($l['GuardianLastName'] ?? ''))));
                $hit = null;
                $hitNumber = null;      // the handset that matched, kept explicitly

                if ($p['is_phone']) {
                    foreach ($g['PhoneNumbers'] ?? [] as $ph) {
                        $s = search_phone_score($p['digits'], $ph['PhoneNumber'] ?? null);
                        if ($s === null) continue;
                        if ($hitNumber !== null && $hitNumber['score'] <= $s) continue;
                        $type = trim((string)($ph['Type'] ?? ''));
                        // The number that MATCHED, not the family's best number:
                        // saying "that is the grandmother's mobile" is the whole
                        // point of reporting this separately.
                        $hitNumber = [
                            'score'  => $s,
                            'number' => trim((string)$ph['PhoneNumber']),
                            'type'   => $type === '' ? null : $type,
                        ];
                    }
                    if ($hitNumber) search_keep($hit, $hitNumber['score'], 'phone');
                }
                if ($p['is_email']) {
                    search_keep($hit, search_email_score($p['raw'], $g['Email'] ?? null), 'email');
                }
                if ($p['tokens'] && !$p['is_email']) {
                    // A guardian-name hit is a weaker answer than a child-name hit
                    // - "Nguyen" should list the children before it lists every
                    // parent called Nguyen - so it carries a penalty, and anybody
                    // who is not a parent carries a bigger one, so they rank below
                    // the parents rather than being dropped. See
                    // search_guardian_is_parent() for why it was once a filter.
                    $ns = search_name_score($p['tokens'], search_name_parts(
                        (string)($l['GuardianFirstName'] ?? ''), (string)($l['GuardianLastName'] ?? '')));
                    search_keep($hit, $ns === null ? null
                        : $ns + (search_guardian_is_parent($l) ? 0.4 : 0.8), 'guardian');
                }
                if (!$hit) continue;

                search_keep($best, $hit['score'], $hit['why']);
                if ($matched === null || $hit['score'] < $matched['score']) {
                    $gPhone = $hit['why'] === 'phone' ? null : guardian_phone($g);
                    $matched = [
                        'score'        => $hit['score'],
                        'via'          => $hit['why'],
                        'guardian'     => $gName,
                        'relationship' => $l['Relationship'] ?? null,
                        // For a phone hit this is the handset that rang, which may
                        // not be this guardian's best number.
                        'phone'        => $hit['why'] === 'phone' ? $hitNumber['number'] : ($gPhone['number'] ?? null),
                        'phone_type'   => $hit['why'] === 'phone' ? $hitNumber['type']   : ($gPhone['type']   ?? null),
                        'email'        => $g['Email'] ?? null,
                        'is_enrolling' => !empty($l['Enrolment']),
                    ];
                }
            }
            if (!$best) continue;

            $dob = !empty($k['DOB']) ? substr((string)$k['DOB'], 0, 10) : null;
            $out['k:' . $childId] = [
                'key'              => 'k:' . $childId,
                'kidsoft_child_id' => $childId,
                'name'             => trim($first . ' ' . $last),
                'nickname'         => $nick !== '' ? name_case($nick) : null,
                'dob'              => $dob,
                'age_months'       => age_months($dob),
                'centre_id'        => (int)$c['id'],
                'centre'           => $c['name'],
                'enrolled'         => !empty($k['Active']),
                // WHERE THEY ARE ENROLLED, as a list, because ChildID is per
                // service and search_merge_children() folds a child's several
                // records into one card. Two children are active at two centres
                // at once right now (measured 17 Aug 2026); a merged card that
                // named only the heading record's centre would say one of them
                // is at Niagara Park and not say they are also at Kanwal.
                //
                // Each entry carries the ChildID Kidsoft issued AT THAT CENTRE,
                // which is what search_attach_rooms() then looks the child's
                // bookings up by - the ids are per service, so a merged card's
                // single `kidsoft_child_id` would find them at one centre and
                // lose them at the other.
                'enrolled_centres' => !empty($k['Active'])
                    ? [['centre_id' => (int)$c['id'], 'centre' => $c['name'], 'child_id' => $childId]]
                    : [],
                // Always present, on every path. A field that only one of three
                // constructors emits reads as `undefined` in the browser and
                // silently means "false" - the exact shape mismatch CLAUDE.md
                // says to catch by diffing the API's keys against the JS.
                'provisional'      => false,
                // A FAMILY WE ENDED CARE FOR, when the child's own parent is
                // tagged in Kidsoft. This is the card a call from them brings up:
                // the child has left, so the record is inactive, and it is the
                // one place the warning has to be when the phone rings. Every
                // constructor emits it, like the fields above.
                'dne'              => dne_for_child_links($k['Guardian'] ?? []),
                'contact'          => $contact,
                'matched'          => $matched,
                'placements'       => [],
                'waitlist'         => [],
                'history'          => [],
                'history_total'    => 0,
                'score'            => $best['score'],
                'via'              => $best['why'],
            ];
        }
        unset($kids, $gmap);   // one centre at a time: 43MB peak, not seven times it
    }

    // ---- 2. Our board -------------------------------------------------------
    search_attach_placements($out, $p);

    // ---- 3. Our queue -------------------------------------------------------
    search_attach_waitlist($out, $p, $centres);

    // ---- 4. Kidsoft's CLOSED waitlist history -------------------------------
    search_attach_kidsoft_history($out, $p, $centres, $degraded);

    // ---- 5. One child, one card ---------------------------------------------
    $out = search_merge_children($out);

    // ---- Rank ---------------------------------------------------------------
    // Match quality first, then usefulness: a child sitting on a board or in the
    // queue right now is more likely to be the person on the phone than one
    // archived three years ago, so an inactive record with nothing live attached
    // sinks below the live ones rather than being hidden.
    foreach ($out as &$r) {
        $live = $r['enrolled'] || $r['placements'] || $r['waitlist'];
        $r['sort'] = $r['score'] + ($live ? 0 : 4);

        // Which centres this person touches. A family applies at several centres
        // at once - one real family holds five open applications - so a single
        // centre name in the heading would be a third of the truth.
        // Enrolments come from the list, not from the heading record's single
        // centre: after the merge one card can hold two enrolments, and a
        // placement or a queue row already names its own centre below.
        $names = array_column($r['enrolled_centres'], 'centre');
        foreach ($r['placements'] as $pl) $names[] = $pl['centre'];
        foreach ($r['waitlist'] as $w)     $names[] = $w['centre'];
        $r['centres'] = array_values(array_unique($names ?: [$r['centre']]));
    }
    unset($r);

    $results = array_values($out);
    usort($results, static fn($a, $b) => [$a['sort'], $a['name']] <=> [$b['sort'], $b['name']]);

    // `past` is split out of `archived` deliberately. They are different answers:
    // "not enrolled" is a child Kidsoft holds who is not active - a family who
    // has left, and one you can ask back - while a past application is a family
    // who never got in. Counting 57 lapsed applications as 57 former families
    // overstates the first and hides the second.
    $counts = ['total' => count($results), 'enrolled' => 0, 'waitlist' => 0,
               'past' => 0, 'archived' => 0];
    foreach ($results as $r) {
        if ($r['enrolled'])                          $counts['enrolled']++;
        elseif ($r['waitlist'])                      $counts['waitlist']++;
        elseif (!$r['placements'] && $r['history'])  $counts['past']++;
        else                                         $counts['archived']++;
    }

    return [
        'ok'       => true,
        'query'    => $p,
        // Rooms are attached to the SLICE, not to everything found: the lookup is
        // per centre, and only the centres actually on screen are worth reading.
        // Scoped LAST, after the rooms are on, so a supervisor's own centre keeps
        // its room line and the other centres' are removed with everything else.
        'results'  => search_scope_results(
                          search_attach_rooms(array_slice($results, 0, $limit),
                                              (int)($opts['year'] ?? board_default_year()))),
        'counts'   => $counts,
        'took_ms'  => (int)round((microtime(true) - $t0) * 1000),
        'degraded' => $degraded,
    ];
}

/**
 * SEARCH STAYS GROUP-WIDE FOR EVERYBODY, AND SHOWS LESS TO A SUPERVISOR.
 * (19 Aug 2026, with the supervisor role.)
 *
 * Every other screen in this app is pinned to one centre for a supervisor. This
 * one is not, and that is deliberate: search exists because a parent rings and
 * all you have is a number, and a parent ringing the centre nearest their work
 * about a child enrolled somewhere else is the ordinary case, not the edge one.
 * Scoping it to one centre would leave the person who answered the phone unable
 * to say anything at all - so it keeps answering, and answers with less.
 *
 * What a supervisor sees for a child OUTSIDE their centres is the name and which
 * centre. Not the date of birth, not the guardian's phone or email, not the room
 * or the days, not the queue position, not the history. Enough to say "they are
 * at Niagara Park, let me put you through", which is the whole job. There is no
 * link either: `centre_id` is nulled so the palette renders the card as text --
 * every link on a card opens a screen that would refuse them anyway, and a link
 * that fails is worse than no link.
 *
 * A CARD CAN SPAN CENTRES, and that is why this trims per row rather than
 * dropping whole cards. Two children are enrolled at two centres at once, and
 * families apply at several; a Tuggerah supervisor looking at such a family must
 * see their Tuggerah enrolment in full and the rest as a centre name. So each
 * list is filtered to what is theirs, and `centres` - the heading line - keeps
 * every name, because "also at Niagara Park" is the fact worth having.
 */
function search_scope_results(array $results): array {
    if (user_all_centres()) return $results;
    $mine = user_centre_ids();
    $ours = static fn($row) => in_array((int)($row['centre_id'] ?? 0), $mine, true);

    foreach ($results as &$r) {
        // Every centre this person touches, worked out BEFORE anything is
        // dropped, so the heading still says where else they are.
        $allCentres = $r['centres'] ?? [];

        $inScope = false;
        foreach (['enrolled_centres', 'placements', 'waitlist', 'history', 'in_rooms'] as $k) {
            if (!isset($r[$k]) || !is_array($r[$k])) continue;
            $kept = array_values(array_filter($r[$k], $ours));
            if ($kept) $inScope = true;
            $r[$k] = $kept;
        }
        // The heading record's own centre counts too: a child with a Kidsoft
        // record at their centre and nothing else attached is still theirs.
        if (in_array((int)($r['centre_id'] ?? 0), $mine, true)) $inScope = true;

        $r['centres'] = $allCentres;

        if ($inScope) continue;

        // NOTHING HERE IS THEIRS. Reduce the card to a name and a place.
        // history_total goes with history: a count of records they cannot see is
        // the same disclosure in a smaller font.
        // `matched` names the guardian whose phone or email the query hit, which
        // is a contact detail of a family at another centre - the same thing
        // `contact` is, one line further up the card.
        // `dne` too: which families we have ended care for at a centre they do
        // not hold is that centre's to know. If this family applies at THEIR
        // centre, the queue's own check flags it there.
        foreach (['dob', 'age_months', 'contact', 'nickname', 'matched', 'dne'] as $k) $r[$k] = null;
        $r['history_total'] = 0;
        // `enrolled` is deliberately LEFT ALONE. That this child is currently
        // enrolled somewhere is not a detail about them, it is the answer to the
        // question the phone call asked - "they are at Niagara Park, let me put
        // you through" needs it. Falsifying it would make the card say they are
        // nobody's, which is worse than saying nothing.
        // Nulled so search.js renders the card as plain text rather than a link
        // into a centre this user cannot open.
        $r['centre_id']     = null;
        $r['out_of_scope']  = true;
    }
    unset($r);

    return $results;
}

/**
 * WHICH ROOM AN ENROLLED CHILD IS IN, and on which days.
 *
 * WHY (17 Aug 2026, Broc): a card for a child who is enrolled and not waiting had
 * nothing on it to act on - a phone number, an email, and no way through to the
 * one screen that could say anything more about them. Every other line on a card
 * opens the screen that owns it; the enrolment did not, and it is the commonest
 * result there is.
 *
 * READ, NOT COPIED, and read from the same place the board is derived from:
 * `board_live_children()`, which is Kidsoft's BOOKINGS for the board's reference
 * month, so a casual day is already excluded and a room the child came out of
 * stops being reported the moment Kidsoft says so. Nothing about where a child
 * sits belongs in our tables.
 *
 * WHY IT IS AFFORDABLE, measured 17 Aug 2026: a warm read of all seven centres is
 * **0.06s and 20MB**, because `cron-nightly.php --warm` refetches this hourly for
 * this year and next - it is the same call the Placement board makes. Cold it is
 * 14s for the group, which is exactly why this only ever reads the centres that
 * appear in the results being returned (usually one), and why an expired row is
 * served stale and refreshed after the response rather than being waited on.
 *
 * THE CURRENT BOARD YEAR ONLY, and that is measured rather than assumed: of 862
 * active children group-wide, **16 have no booking in this year's reference
 * month, and NOT ONE of those 16 has one next year either.** A second year of
 * lookups would have doubled the work to find nobody.
 *
 * A Kidsoft failure here loses the room line and nothing else. The rest of the
 * card is already built and a search that answers slightly less is better than
 * one that answers nothing.
 */
function search_attach_rooms(array $results, int $year): array {
    $wanted = [];
    foreach ($results as $r) {
        foreach ($r['enrolled_centres'] as $e) $wanted[$e['centre_id']] = true;
    }

    $byCentre = [];
    foreach (array_keys($wanted) as $centreId) {
        try {
            $live = board_live_children($centreId, $year);
        } catch (Throwable $e) {
            continue;
        }
        $names = [];
        foreach (rooms_for_centre($centreId, $year) as $room) $names[(int)$room['id']] = $room['name'];

        // ChildID => room id => weekdays. board_live_children() hands back the
        // other way round (room => children), which is what a board needs and the
        // opposite of what a search result does.
        $index = [];
        foreach ($live['rooms'] as $roomId => $kids) {
            foreach ($kids as $k) $index[$k['ks_id']][(int)$roomId] = $k['days'];
        }
        $byCentre[$centreId] = ['index' => $index, 'names' => $names];
        unset($live);   // one centre's bookings at a time, as everything else here does
    }

    foreach ($results as &$r) {
        // Always present, on every card, so the browser never reads `undefined`
        // as "no rooms" on one code path and an array on another.
        $r['in_rooms'] = [];
        foreach ($r['enrolled_centres'] as $e) {
            $c = $byCentre[$e['centre_id']] ?? null;
            if (!$c) continue;
            foreach ($c['index'][$e['child_id']] ?? [] as $roomId => $days) {
                $r['in_rooms'][] = [
                    'centre_id' => $e['centre_id'],
                    'centre'    => $e['centre'],
                    'room_id'   => $roomId,
                    'room'      => $c['names'][$roomId] ?? 'Room',
                    'year'      => $year,
                    'days'      => $days,
                ];
            }
        }
        usort($r['in_rooms'], static fn($a, $b) => [$a['centre'], $a['room']] <=> [$b['centre'], $b['room']]);
    }
    unset($r);

    return $results;
}

/**
 * ONE CHILD, ONE CARD.
 *
 * Each of the four sources above keys its results the best way it can, and those
 * keys disagree the moment a link is missing:
 *
 *   - **Kidsoft's ChildID is per SERVICE**, so a child who has been at two of our
 *     centres holds two of them and read as two people.
 *   - An application nobody has linked to a child record has no ChildID at all
 *     and keys on the household instead, so the same child read once as their
 *     enrolment and again as their queue place - which is exactly the pair the
 *     person searching is trying to see together.
 *
 * Measured 17 Aug 2026: `Ezra Williams` came back as three cards (two Kidsoft
 * records and an application), `Austin Wilson` as four. The board rows, the queue
 * place and the history sat on different cards, so reading one card was reading a
 * third of the answer.
 *
 * WHAT COUNTS AS THE SAME CHILD: the folded name PLUS positive evidence - the
 * same DOB, or the same household phone. **Name alone is never enough**, and this
 * is not theoretical: the queue is full of `TBA Smith` and `Baby Jones`
 * placeholders entered before a child is born, and they are not names. Five
 * separate families are on the list as `TBA TBA` right now.
 *
 * MEASURED BEFORE IT WAS ALLOWED, because a false merge HIDES a family and that
 * is the one failure this screen must not have (see the asymmetry table in
 * search-identity.md - this is the generous matcher, but generous about RANKING,
 * never about identity). Across all 5,974 applications the group can see, our
 * queue table plus Kidsoft's full archived list:
 *
 *   - 4,060 distinct name+DOB pairs;
 *   - **97 held by more than one household number** - every one a real child
 *     whose two parents applied separately, both McCormacks, both Milnes, both
 *     Gounders, so the merge is right on all 97;
 *   - **0 of the 97 carry a placeholder name.** The placeholders collide on name
 *     constantly and never once on DOB.
 *
 * If a placeholder DOB convention ever appears (a whole centre entering
 * 01/01/next-year, say), re-run that census - it is the only thing standing
 * between this rule and two unborn children becoming one card.
 */
function search_merge_children(array $out): array {
    if (count($out) < 2) return $out;

    // Union-find over the result keys: A and B can be joined by a DOB while B and
    // C are joined by a phone, and all three are one child.
    $parent = [];
    foreach ($out as $k => $_) $parent[$k] = $k;
    $find = static function (string $k) use (&$parent): string {
        while ($parent[$k] !== $k) { $parent[$k] = $parent[$parent[$k]]; $k = $parent[$k]; }
        return $k;
    };

    $seen = [];
    foreach ($out as $k => $r) {
        foreach (search_child_signatures($r) as $sig) {
            if (!isset($seen[$sig])) { $seen[$sig] = $k; continue; }
            $a = $find($seen[$sig]);
            $b = $find($k);
            if ($a !== $b) $parent[$a] = $b;
        }
    }

    $groups = [];
    foreach ($out as $k => $r) $groups[$find($k)][] = $r;

    $merged = [];
    foreach ($groups as $root => $g) {
        $merged[$root] = count($g) === 1 ? $g[0] : search_merge_group($g);
    }
    return $merged;
}

/**
 * The evidence that two results are the same child: folded name plus a DOB, or
 * folded name plus a household number.
 *
 * The phone key is `sibling_phone_key()`, the household rule - NOT
 * `search_phone_digits()`, which is deliberately loose because somebody is typing
 * four digits off a handset. Loose is right for finding a family and wrong for
 * asserting two records are one child.
 */
function search_child_signatures(array $r): array {
    $n = name_fold((string)$r['name']);
    if ($n === '') return [];
    // The digits carry the whole discriminator for a placeholder name, and this
    // is a signature that says "these two results are one child". Without it the
    // two keys the attach steps now keep apart are joined straight back together
    // here: Twin 1 and Twin 2 Wolfenden fold to the same letters AND share a date
    // of birth, which is exactly the evidence this function accepts.
    $n .= name_digit_key((string)$r['name']);

    $sigs = [];
    if (!empty($r['dob'])) $sigs[] = 'd:' . $n . ':' . $r['dob'];
    // Both the number we would ring and the number that rang: a Kidsoft record
    // reports the guardian ladder's best, an application reports the parent who
    // filled it in, and they are often different handsets in one house.
    foreach ([$r['contact']['phone'] ?? null, $r['matched']['phone'] ?? null] as $ph) {
        $key = $ph === null ? null : sibling_phone_key((string)$ph);
        if ($key !== null) $sigs[] = 'p:' . $n . ':' . $key;
    }
    return $sigs;
}

/** Fold one child's several results into the single card the team reads. */
function search_merge_group(array $g): array {
    // The card is headed by whichever record has the most authority over the
    // child's own details: Kidsoft's active enrolment first, then anything linked
    // to a Kidsoft child, then the best match. A provisional board row must not
    // put a staff-typed spelling above the name Kidsoft holds.
    usort($g, static fn($a, $b) =>
        [$a['enrolled'] ? 0 : 1, $a['kidsoft_child_id'] === null ? 1 : 0, $a['score']]
        <=> [$b['enrolled'] ? 0 : 1, $b['kidsoft_child_id'] === null ? 1 : 0, $b['score']]);

    $m = array_shift($g);
    foreach ($g as $r) {
        $m['enrolled']         = $m['enrolled'] || $r['enrolled'];
        foreach ($r['enrolled_centres'] as $e) {
            foreach ($m['enrolled_centres'] as $have) {
                if ($have['centre_id'] === $e['centre_id'] && $have['child_id'] === $e['child_id']) continue 2;
            }
            $m['enrolled_centres'][] = $e;
        }
        $m['provisional']      = $m['provisional'] || $r['provisional'];
        $m['kidsoft_child_id'] ??= $r['kidsoft_child_id'];
        $m['dob']              ??= $r['dob'];
        $m['nickname']         ??= $r['nickname'];
        $m['contact']          ??= $r['contact'];
        $m['dne']              ??= $r['dne'];
        $m['placements']       = array_merge($m['placements'], $r['placements']);
        $m['waitlist']         = array_merge($m['waitlist'], $r['waitlist']);
        $m['history']          = array_merge($m['history'], $r['history']);
        // Each side's total, not the count of what it listed: both were capped at
        // SEARCH_HISTORY_PER_RESULT before they got here, and "and N older
        // applications" has to keep telling the truth after the merge.
        $m['history_total']    += $r['history_total'];

        // The card ranks by its BEST match, and says why that one matched. A child
        // found by their mother's mobile on an application, whose enrolment record
        // scored worse, must still show "that number is her mother's".
        if ($r['score'] < $m['score']) {
            $m['score'] = $r['score'];
            $m['via']   = $r['via'];
            if ($r['matched']) $m['matched'] = $r['matched'];
        }
        $m['matched'] ??= $r['matched'];
    }

    // A DOB may have arrived from a record other than the heading one.
    $m['age_months'] = age_months($m['dob']);

    if ($m['history']) {
        // Folded again here, not only where the rows were gathered: the two sides
        // of a merge are two KEYS for one child - Kidsoft's ChildID on the
        // application that converted, the household rule on one that did not - so
        // the same line can arrive once down each. Whatever goes is taken off the
        // total as well, or "and N older applications" counts rows that no longer
        // exist.
        $before = count($m['history']);
        $m['history'] = search_history_fold($m['history']);
        $m['history_total'] -= $before - count($m['history']);

        usort($m['history'], static fn($a, $b) => [$b['date_added'], $b['centre']]
                                              <=> [$a['date_added'], $a['centre']]);
        $m['history'] = array_slice($m['history'], 0, SEARCH_HISTORY_PER_RESULT);
    }
    return $m;
}

/**
 * Attach the applications Kidsoft has CLOSED or ARCHIVED.
 *
 * WHY THIS IS READ AND NOT IMPORTED. The queue table deliberately holds only
 * what the team still has a decision to make about: waitlist-import.php refuses
 * to pour closed rows in, because 957 of the 1,698 live-window rows are
 * `ExpiredWaitlist` and they would bury the 708 that matter. That rule is right
 * and is untouched. But "not in the queue" was being read by staff as "we have
 * no record", which is the opposite of true - so this reads the history
 * straight out of Kidsoft, live and cached, exactly as search_all() already
 * reads children and guardians. Nothing is copied into our tables. An archived
 * application is pure Kidsoft history: we hold no offer against it, make no
 * decision on it, and a copy would only go stale.
 *
 * `ShowArchived` IS THE WHOLE FINDING, and it is the META-RULE case again: it
 * defaults to FALSE, so every call this app has ever made was blind to it.
 * Censused 14 Aug 2026 across the group - default 1,698 rows, ShowArchived
 * 5,221, so **3,523 applications had never once been visible to this tool.**
 * Coverage on them is good enough to search: child name 99%, DOB 99%, parent
 * mobile 99%. Cost is 5.4 s and 16.9 MB cold for all seven centres, which is why
 * it is cached for 36 h and warmed nightly rather than fetched per keystroke.
 *
 * THE ARCHIVED SET CONTAINS STATUSES THE IMPORTER'S MAP HAS NEVER SEEN --
 * `RejectedByService` (555), `DeletedByService` (484), `RejectedByParent` (21).
 * They are NOT mapped here and must not be: this path never writes, so it can
 * show Kidsoft's own word for the outcome without deciding what it means. Only
 * the importer needs a mapping, and only for rows it takes ownership of.
 *
 * A row we already hold is skipped - search_attach_waitlist() has produced it
 * from our own table, with the queue position and priority reasons that only
 * exist there. This adds history, never a second copy of a live application.
 * EXCEPT one we hold as `archived`, which that function leaves out - see
 * search_history_held().
 */
/**
 * The parameters BOTH the search read and the nightly warm use.
 *
 * One definition, because a warm that fetched a different set of parameters
 * would write a different cache key and leave the read stone cold while the log
 * reported a successful warm - the failure mode is invisible from either end.
 *
 * TENURE READS THIS SAME ENTRY (29 Oct 2026) for what a family who started and
 * left between two readings had asked for - see tenure_short_stays_why(). That
 * is why WithRequestedDays is here: +6% on the payload (Tuggerah 828 KB -> 874
 * KB gzipped) against a second copy of 5,000 applications in the cache.
 */
function search_history_params(string $serviceId): array {
    return [
        'ServiceID'         => $serviceId,
        'DateAddedFrom'     => '2010-01-01',
        'DateAddedTo'       => date('Y-m-d', strtotime('+1 year')),
        'ShowArchived'      => 'true',
        'WithDetails'       => 'true',
        'WithRequestedDays' => 'true',
    ];
}

/**
 * Fetch one centre's closed waitlist history LIVE, to fill the cache.
 *
 * Called by cron-nightly.php. 5.4 s and 16.9 MB for the group cold, against
 * ~0.3 s warm, and SEARCH_HISTORY_TTL (36 h) is set to outlast the gap between
 * two nightly runs so the entry is never merely stale at 09:00.
 */
function search_history_warm(string $serviceId): void {
    kidsoft_cached('Waitlist', 'getWaitingList', search_history_params($serviceId), 0);
}

/**
 * The applications we hold, keyed on Kidsoft's own id, which is the only
 * identifier both sides agree on - split by whether anything ELSE shows them.
 *
 * `shown`: the queue table produces these, with the queue position and priority
 * reasons that only exist there, so a history line for one would be a second
 * copy of a live application.
 *
 * `archived`: a row we imported and Kidsoft has since archived. NOTHING ELSE
 * SHOWS THESE, and until 11 Nov 2026 nothing did: search_attach_waitlist() leaves
 * status `archived` out on purpose (a queue line for it would put a family back in
 * a queue they are not in) while the history read skipped every id we hold - so
 * the two rules, each right on its own, dropped all 254 of them between them,
 * 51 of them `RejectedByService`. They are history and print as history.
 * Archived TODAY is the common case while the team works through the queue, and
 * the history payload is up to 36 h old, so ours is the fresher word on whether
 * it was archived: search_history_line() takes it.
 *
 * @return array{shown: array<string,true>, archived: array<string,true>}
 */
function search_history_held(): array {
    static $memo = null;
    if ($memo !== null) return $memo;
    $memo = ['shown' => [], 'archived' => []];
    foreach (dbx()->query(
        'SELECT kidsoft_waitlist_id, status FROM waitlist_applications
          WHERE kidsoft_waitlist_id IS NOT NULL'
    )->fetchAll() as $r) {
        $memo[$r['status'] === 'archived' ? 'archived' : 'shown'][(string)$r['kidsoft_waitlist_id']] = true;
    }
    return $memo;
}

/**
 * ONE PAST APPLICATION AS THE LINE THAT IS PRINTED, or null when it is not one.
 *
 * Shared by search's cards and the Waitlist (waitlist_history(), 11 Nov 2026,
 * Broc: "sync the search bar's archive or rejected info in the waitlists"), so
 * what counts as a past application, and what it says, is decided once.
 *
 * Null for a row the queue table shows (see search_history_held()) and for an
 * ENROLMENT - see the note in search_attach_kidsoft_history().
 */
function search_history_line(array $w, array $c, array $held): ?array {
    $wlId = (string)($w['WaitListID'] ?? '');
    if ($wlId === '' || isset($held['shown'][$wlId])) return null;
    if ((string)($w['Status'] ?? '') === 'Enrolled') return null;

    // Archived by us is archived: our import saw it leave the feed, and that can
    // be hours newer than this payload.
    if (isset($held['archived'][$wlId])) $w['Archived'] = true;

    $child = $w['Details']['EnrolmentData']['ChildDetails'] ?? [];
    $first = trim((string)($child['FirstName'] ?? ''));
    $last  = trim((string)($child['LastName']  ?? ''));
    return [
        'centre_id'  => (int)$c['id'],
        'centre'     => $c['name'],
        // Kidsoft's own word for the outcome, spaced for reading. NOT
        // mapped onto our vocabulary: `RejectedByService` is not any of
        // our statuses and pretending otherwise would invent a decision.
        'status'     => (string)($w['Status'] ?? ''),
        'status_label' => search_history_label((string)($w['Status'] ?? '')),
        'archived'   => !empty($w['Archived']),
        'date_added' => !empty($w['DateAdded']) ? substr((string)$w['DateAdded'], 0, 10) : null,
        'expiry'     => !empty($w['ExpiryDate']) ? substr((string)$w['ExpiryDate'], 0, 10) : null,
        'fold'       => search_history_fold_key($w, (int)$c['id'], $first, $last),
    ];
}

/**
 * Folded, then newest first - what both callers do before they count and cap.
 *
 * FOLDED FIRST, so the count and the cap both describe what is on screen: the
 * total feeds "and N older applications", and counting rows that will never be
 * printed makes that sentence a lie.
 */
function search_history_order(array $rows): array {
    $rows = search_history_fold($rows);
    usort($rows, static fn($a, $b) => [$b['date_added'], $b['centre']]
                                  <=> [$a['date_added'], $a['centre']]);
    return $rows;
}

function search_attach_kidsoft_history(array &$out, array $p, array $centres, array &$degraded): void {
    $held = search_history_held();

    foreach ($centres as $c) {
        $sid = $c['kidsoft_service_id'];
        try {
            // Same window as waitlist-import.php on purpose: 2010 predates the
            // oldest row and the window runs a year forward, so a future date
            // typed by mistake is still seen rather than silently missing.
            $res = kidsoft_cached('Waitlist', 'getWaitingList',
                search_history_params($sid), SEARCH_HISTORY_TTL);
        } catch (RuntimeException $e) {
            // Same contract as the enrolled-children loop: a centre Kidsoft will
            // not answer for is REPORTED, because a family missing from a search
            // otherwise reads as a family we do not have.
            if (!in_array($c['name'], $degraded, true)) $degraded[] = $c['name'];
            continue;
        }

        foreach (kidsoft_rows($res, 'WaitingList', $sid) as $w) {
            $wlId = (string)($w['WaitListID'] ?? '');
            // A row the queue table shows, or an enrolment - see below. The
            // test itself is search_history_line()'s, shared with the Waitlist.
            $line = search_history_line($w, $c, $held);
            if ($line === null) continue;

            // AN ENROLMENT IS NOT A PAST APPLICATION (20 Aug 2026, Broc: the
            // Rose Howen card). Kidsoft issues the ChildID at the moment an
            // application converts, so the row it leaves behind is archived and
            // carries `Status = Enrolled` - it is the record of the enrolment
            // SUCCEEDING, not of a family who never got in, and this section is
            // for the latter. Every archived row was being filed here without
            // the outcome ever being read. Measured group-wide:
            //
            //   archived rows visible to search        4,465
            //   of those, Status = Enrolled            1,197
            //   ACTIVE children carrying one on a card   702 of 872  (81%)
            //
            // So Rose read "Enrolled - applied 14/08/2024 - Archived" directly
            // under the green Enrolled badge and the room she is in on Mondays,
            // and so did four cards in five across the app.
            //
            // AND IT BROKE THE BADGE THE OTHER WAY, which is the half that costs
            // a phone call: statusBadge() says "Past application" whenever any
            // history is present, meaning a family who never got in, as against
            // "Not enrolled", a family who LEFT and can be asked back. The ~495
            // converted rows belonging to children since gone made every one of
            // them read as the first when they are the second, and counts.past
            // reported them in the summary line to match.
            //
            // Dropped rather than relabelled: the card already says this twice
            // over - the badge, and the live room line above - and a third
            // telling adds nothing a caller needs. When the child has since
            // left, the history list correctly falls empty and the badge lands
            // on "Not enrolled". (The skip is in search_history_line().)

            $d     = $w['Details']['EnrolmentData'] ?? [];
            $child = $d['ChildDetails']      ?? [];
            $par   = $d['ParentGuardianOne'] ?? [];

            $first = trim((string)($child['FirstName'] ?? ''));
            $last  = trim((string)($child['LastName']  ?? ''));
            $phone = trim((string)($par['MobilePhone'] ?? ''));
            $email = trim((string)($par['Email'] ?? ''));
            $pName = trim(name_case(trim((string)($par['FirstName'] ?? ''))) . ' '
                        . name_case(trim((string)($par['LastName']  ?? ''))));

            $best = null;
            $matched = null;
            if ($p['tokens'] && !$p['is_email']) {
                search_keep($best, search_name_score($p['tokens'],
                    search_name_parts($first, $last)), 'name');
                // ParentGuardianOne is the applying parent by construction, so
                // this needs no search_guardian_is_parent() test - there is no
                // grandmother or case worker on this path to rank lower.
                $s = search_name_score($p['tokens'], search_name_parts($pName));
                search_keep($best, $s === null ? null : $s + 0.4, 'guardian');

                [$s2, $m2] = search_second_parent_hit($p, search_second_parent($d));
                if ($s2 !== null && ($best === null || $s2 < $best['score'])) $matched = $m2;
                search_keep($best, $s2, 'guardian');
            }
            if ($p['is_phone']) search_keep($best, search_phone_score($p['digits'], $phone), 'phone');
            if ($p['is_email']) search_keep($best, search_email_score($p['raw'], $email), 'email');

            // Same two-part key search_attach_waitlist() uses, so a closed
            // application lands on the family it belongs to rather than beside
            // them: Kidsoft's ChildID when the child was eventually enrolled
            // (34% of archived rows carry one), otherwise the household rule of
            // folded child name PLUS the parent's phone.
            $key = !empty($w['ChildID'])
                ? 'k:' . $w['ChildID']
                // name_identity_key(), NOT name_key(): this decides that two
                // records are one child, and name_fold() strips the digit that
                // is all there is between Twin 1 and Twin 2.
                : 'w:' . name_identity_key($first, $last) . ':'
                       . (sibling_phone_key($phone) ?? 'wl' . $wlId);

            // A closed application for somebody already in the results belongs on
            // that result even when the query did not match the application
            // itself - "they applied at Terrigal in 2024 and it lapsed" is the
            // context the person answering the phone is missing.
            if (!$best && !isset($out[$key])) continue;

            $dob = !empty($child['DOB']) ? substr((string)$child['DOB'], 0, 10) : null;

            if (!isset($out[$key])) {
                $name = trim(name_case($first) . ' ' . name_case($last));
                $out[$key] = [
                    'key'              => $key,
                    'kidsoft_child_id' => $w['ChildID'] ?? null,
                    // 1% of archived rows carry no name at all (a 2014 entry with
                    // a mobile and nothing else). An empty heading reads as a
                    // broken row, so say what it is.
                    'name'             => $name !== '' ? $name : 'Name not recorded',
                    'nickname'         => null,
                    'dob'              => $dob,
                    'age_months'       => age_months($dob),
                    'centre_id'        => (int)$c['id'],
                    'centre'           => $c['name'],
                    'enrolled'         => false,
                    // Always emitted, on every path: a field only one constructor
                    // sets reads as undefined the moment two records merge.
                    'enrolled_centres' => [],
                    'provisional'      => false,
                    // A past application from a tagged family's email or mobile -
                    // a younger child who only ever applied, say.
                    'dne'              => dne_match(['guardian_email' => $email, 'guardian_phone' => $phone]),
                    'contact'          => ($phone === '' && $email === '') ? null : [
                        'guardian'     => $pName !== '' ? $pName : null,
                        'relationship' => null,
                        'phone'        => $phone !== '' ? $phone : null,
                        'phone_type'   => null,
                        'is_parent'    => true,
                        'email'        => $email !== '' ? $email : null,
                    ],
                    'matched'          => $matched,
                    'placements'       => [],
                    'waitlist'         => [],
                    'history'          => [],
                    'history_total'    => 0,
                    'score'            => $best['score'],
                    'via'              => $best['why'],
                ];
            } elseif ($best && $best['score'] < $out[$key]['score']) {
                $out[$key]['score'] = $best['score'];
                $out[$key]['via']   = $best['why'];
                if ($matched) $out[$key]['matched'] = $matched;
            }

            $out[$key]['history'][] = $line;
        }
        unset($res);   // 16.9 MB across the group: one centre at a time, as above
    }

    // Newest first, and cap what any one result lists. A family can hold a dozen
    // closed applications across seven centres and four years; the recent ones
    // are the ones that answer "have we dealt with these people before".
    //
    // FOLDED FIRST, so the count and the cap both describe what is on screen:
    // history_total feeds "and N older applications", and counting rows the card
    // will never print makes that sentence a lie.
    foreach ($out as &$r) {
        if (empty($r['history'])) continue;
        $r['history'] = search_history_order($r['history']);
        $r['history_total'] = count($r['history']);
        $r['history'] = array_slice($r['history'], 0, SEARCH_HISTORY_PER_RESULT);
    }
    unset($r);
}

/**
 * The signature of a history line as the card actually PRINTS it, plus the name
 * on the application itself.
 *
 * `historyLines()` renders four things - the centre, the outcome, the date it
 * was applied, and the Archived tag - so two rows agreeing on all four produce
 * two lines a reader cannot tell apart. Lily Rodgers held two, thirteen days
 * apart in Kidsoft and identical on screen, and Kanwal's `Jelly Fish` test
 * family the same.
 *
 * AND THE CHILD'S OWN NAME IS PART OF THE KEY, WHICH IS THE WHOLE CARE IN THIS
 * FUNCTION (20 Aug 2026). Of the five collisions group-wide, THREE ARE TWINS -
 * `Twin 1 Moffitt` and `Twin 2 Moffitt` at Tuggerah, the Wolfendens at Niagara
 * Park, the Pans at Point Clare. Two children, two real applications, rendering
 * as two identical lines because the line does not print which twin it is.
 * Folding on the rendered fields alone would have deleted one child of each pair
 * from the only place they appear, which is the one failure this screen must not
 * have.
 *
 * **RAW, not `name_key()`**: `name_fold()` strips digits, so it reads `Twin 1`
 * and `Twin 2` as the same name - the very reason the pair share a card in the
 * first place. Case and spacing are folded; nothing else is.
 */
function search_history_fold_key(array $w, int $centreId, string $first, string $last): string {
    $name = preg_replace('/\s+/', ' ', trim(mb_strtolower($first . ' ' . $last))) ?? '';
    return implode('|', [
        $centreId,
        (string)($w['Status'] ?? ''),
        !empty($w['DateAdded']) ? substr((string)$w['DateAdded'], 0, 10) : '',
        !empty($w['Archived']) ? '1' : '0',
        $name,
    ]);
}

/**
 * Collapse history rows that would render as the same line, keeping the first.
 *
 * Run BOTH where the rows are gathered and again after a merge concatenates two
 * cards' lists, because a child can reach one card down two keys - Kidsoft's
 * `ChildID` on the application that converted, the household rule on one that
 * never did - and the same line can arrive on each.
 */
function search_history_fold(array $rows): array {
    $seen = [];
    $out  = [];
    foreach ($rows as $h) {
        // A row with no key is never folded away: silence beats guessing.
        $k = $h['fold'] ?? null;
        if ($k !== null) {
            if (isset($seen[$k])) continue;
            $seen[$k] = true;
        }
        $out[] = $h;
    }
    return $out;
}

/** Kidsoft's CamelCase outcome as something readable, without reinterpreting it. */
function search_history_label(string $status): string {
    if ($status === '') return 'Closed';
    $s = preg_replace('/(?<=[a-z])(?=[A-Z])/', ' ', $status) ?? $status;
    return ucfirst(strtolower($s));
}

/**
 * Attach board rows to the results, and add any placement the search matched
 * that Kidsoft could not have produced.
 *
 * The second half is not hypothetical: CLAUDE.md records 11 children pencilled
 * onto a Tuggerah board whose surnames appear nowhere in Kidsoft's 1,467 records.
 * A search that only walked Kidsoft would tell the team those children do not
 * exist, which is exactly the case where they most need to be found.
 */
function search_attach_placements(array &$out, array $p): void {
    $rows = dbx()->query(
        "SELECT p.id, p.kidsoft_child_id, p.year, p.status, p.centre_id, p.room_id,
                p.child_first_name, p.child_last_name, p.child_dob,
                r.name AS room_name, c.name AS centre_name,
                GROUP_CONCAT(pd.weekday ORDER BY pd.weekday) AS days
           FROM placements p
           JOIN rooms r   ON r.id = p.room_id
           JOIN centres c ON c.id = p.centre_id
      LEFT JOIN placement_days pd ON pd.placement_id = p.id
       GROUP BY p.id
       ORDER BY p.year DESC, r.sort_order, r.name"
    )->fetchAll();

    foreach ($rows as $row) {
        $key = $row['kidsoft_child_id'] !== null ? 'k:' . $row['kidsoft_child_id'] : null;

        if ($key === null || !isset($out[$key])) {
            // Unlinked (provisional) placement, or a linked one whose child did
            // not match: only worth showing if the typed name itself matches.
            if (!$p['tokens']) continue;
            $s = search_name_score($p['tokens'], search_name_parts(
                (string)$row['child_first_name'], (string)$row['child_last_name']));
            if ($s === null) continue;

            $key ??= 'p:' . $row['id'];
            if (!isset($out[$key])) {
                $out[$key] = [
                    'key'              => $key,
                    'kidsoft_child_id' => $row['kidsoft_child_id'],
                    'name'             => trim(name_case((string)$row['child_first_name']) . ' '
                                             . name_case((string)$row['child_last_name'])),
                    'nickname'         => null,
                    'dob'              => $row['child_dob'],
                    'age_months'       => age_months($row['child_dob']),
                    'centre_id'        => (int)$row['centre_id'],
                    'centre'           => $row['centre_name'],
                    // Not "enrolled": we hold a board row, Kidsoft holds nothing.
                    'enrolled'         => false,
                    // Always emitted, on every path: a field only one constructor
                    // sets reads as undefined the moment two records merge.
                    'enrolled_centres' => [],
                    'provisional'      => $row['kidsoft_child_id'] === null,
                    'dne'              => null,
                    'contact'          => null,
                    'matched'          => null,
                    'placements'       => [],
                    'waitlist'         => [],
                    'history'          => [],
                    'history_total'    => 0,
                    'score'            => $s,
                    'via'              => 'name',
                ];
            } elseif ($s < $out[$key]['score']) {
                $out[$key]['score'] = $s;
            }
        }

        $out[$key]['placements'][] = [
            'id'        => (int)$row['id'],
            'year'      => (int)$row['year'],
            'centre_id' => (int)$row['centre_id'],
            'centre'    => $row['centre_name'],
            'room_id'   => (int)$row['room_id'],
            'room'      => $row['room_name'],
            'status'    => $row['status'],
            'days'      => $row['days'] === null ? [] : array_map('intval', explode(',', $row['days'])),
        ];
    }
}

/**
 * Attach waitlist applications and their open offers.
 *
 * Queue position comes from waitlist_for_centre(), not from a second sort here:
 * the priority ladder is a decision the team has to be able to explain, and two
 * implementations of it would eventually disagree in front of a parent. Only
 * open applications have a position - a closed one is history.
 */
function search_attach_waitlist(array &$out, array $p, array $centres): void {
    $rows = dbx()->query(
        "SELECT w.*, c.name AS centre_name, r.name AS preferred_room_name,
                GROUP_CONCAT(DISTINCT wd.weekday ORDER BY wd.weekday) AS days
           FROM waitlist_applications w
           JOIN centres c ON c.id = w.centre_id
      LEFT JOIN waitlist_days wd ON wd.waitlist_id = w.id
      LEFT JOIN rooms r ON r.id = w.preferred_room_id
          -- Archived in Kidsoft, so it is not an application any more. Search
          -- deliberately shows CLOSED applications - a parent rings about one
          -- that lapsed and the team needs to find it - but an archived row was
          -- taken off the list on purpose and answering the phone with it would
          -- put a family back in a queue they are not in.
          WHERE w.status <> 'archived'
       GROUP BY w.id"
    )->fetchAll();
    if (!$rows) return;

    $second = ($p['tokens'] && !$p['is_email'])
        ? search_second_parents_held($centres, array_flip(array_filter(
              array_column($rows, 'kidsoft_waitlist_id'), static fn($id) => $id !== null)))
        : [];

    $hits = [];
    foreach ($rows as $w) {
        $best = null;
        $matched = null;
        if ($p['tokens'] && !$p['is_email']) {
            search_keep($best, search_name_score($p['tokens'], search_name_parts(
                (string)$w['child_first_name'], (string)$w['child_last_name'])), 'name');
            $s = search_name_score($p['tokens'], search_name_parts((string)$w['guardian_name']));
            search_keep($best, $s === null ? null : $s + 0.4, 'guardian');

            [$s2, $m2] = search_second_parent_hit($p, $second[(string)$w['kidsoft_waitlist_id']] ?? null);
            if ($s2 !== null && ($best === null || $s2 < $best['score'])) $matched = $m2;
            search_keep($best, $s2, 'guardian');
        }
        if ($p['is_phone']) search_keep($best, search_phone_score($p['digits'], $w['guardian_phone']), 'phone');
        if ($p['is_email']) search_keep($best, search_email_score($p['raw'], $w['guardian_email']), 'email');

        // An application for a child already in the results belongs on that
        // result even when the query did not match the application itself --
        // that a family on the phone also has an open application is precisely
        // what the person answering needs to know.
        //
        // An UNLINKED application has no ChildID to merge on, and families apply
        // at several centres at once: one real Tuggerah family holds five open
        // applications, which listed one child five times over as five separate
        // people. They merge on folded child name PLUS the parent's phone,
        // deliberately using sibling_phone_key() - the household rule - rather
        // than name alone, so two different children who share a name stay apart.
        $key = $w['kidsoft_child_id'] !== null
            ? 'k:' . $w['kidsoft_child_id']
            // name_identity_key(), NOT name_key() - see the twins note there.
            : 'w:' . name_identity_key((string)$w['child_first_name'], (string)$w['child_last_name'])
                   . ':' . (sibling_phone_key($w['guardian_phone']) ?? 'id' . $w['id']);
        if (!$best && !isset($out[$key])) continue;

        $hits[] = [$w, $best, $key, $matched];
    }
    if (!$hits) return;

    // Queue positions, one call per centre that actually has a hit.
    $positions = [];
    $wanted = array_unique(array_map(static fn($h) => (int)$h[0]['centre_id'], $hits));
    foreach ($centres as $c) {
        if (!in_array((int)$c['id'], $wanted, true)) continue;
        foreach (waitlist_for_centre((int)$c['id']) as $row) {
            $positions[(int)$row['id']] = (int)$row['queue_position'];
        }
    }

    // Open offers for the hits.
    $ids = array_map(static fn($h) => (int)$h[0]['id'], $hits);
    $marks = dbx()->whereIn($ids);
    $offers = [];
    foreach (dbx()->query(
        "SELECT o.*, rm.name AS room_name,
                GROUP_CONCAT(od.weekday ORDER BY od.weekday) AS days
           FROM offers o
           JOIN rooms rm ON rm.id = o.room_id
      LEFT JOIN offer_days od ON od.offer_id = o.id
          WHERE o.status = 'pending' AND o.waitlist_id IN($marks)
       GROUP BY o.id", $ids
    )->fetchAll() as $o) {
        $o['days'] = $o['days'] === null ? [] : array_map('intval', explode(',', $o['days']));
        $offers[(int)$o['waitlist_id']] = $o;
    }
    // And the offers sent IN KIDSOFT, which is where offers are made now - the
    // queue's own definition, so the tag below cannot disagree with the row.
    $ksOffers = waitlist_kidsoft_offers(array_map(static fn($h) => $h[0], $hits));

    foreach ($hits as [$w, $best, $key, $matched]) {
        if (!isset($out[$key])) {
            $out[$key] = [
                    'key'              => $key,
                    'kidsoft_child_id' => $w['kidsoft_child_id'],
                    'name'             => trim(name_case((string)$w['child_first_name']) . ' '
                                             . name_case((string)$w['child_last_name'])),
                    'nickname'         => null,
                    'dob'              => $w['child_dob'],
                    'age_months'       => age_months($w['child_dob']),
                    'centre_id'        => (int)$w['centre_id'],
                    'centre'           => $w['centre_name'],
                    'enrolled'         => false,
                    // Always emitted, on every path: a field only one constructor
                    // sets reads as undefined the moment two records merge.
                    'enrolled_centres' => [],
                    'provisional'      => false,
                    'dne'              => null,   // set below, for every application on the card
                    'contact'          => $w['guardian_phone'] === null && $w['guardian_email'] === null ? null : [
                        'guardian'     => $w['guardian_name'],
                        'relationship' => null,
                        'phone'        => $w['guardian_phone'],
                        'phone_type'   => null,
                        // The waitlist mobile comes from the application's own
                        // ParentGuardianOne, so it is a parent's by construction.
                        'is_parent'    => true,
                        'email'        => $w['guardian_email'],
                    ],
                    'matched'          => $matched,
                    'placements'       => [],
                    'waitlist'         => [],
                    'history'          => [],
                    'history_total'    => 0,
                    'score'            => $best['score'],
                    'via'              => $best['why'],
                ];
        } elseif ($best && $best['score'] < $out[$key]['score']) {
            $out[$key]['score'] = $best['score'];
            $out[$key]['via']   = $best['why'];
            if ($matched) $out[$key]['matched'] = $matched;
        }

        // The same check the queue runs, on the same row - so a card built from
        // Kidsoft that this application merely joins still learns it here.
        $out[$key]['dne'] ??= dne_match($w);

        $offer = $offers[(int)$w['id']] ?? null;
        $out[$key]['waitlist'][] = [
            'id'              => (int)$w['id'],
            'centre_id'       => (int)$w['centre_id'],
            'centre'          => $w['centre_name'],
            // THE APPLICATION'S OWN NAME, not the card's. A card is headed by
            // Kidsoft's legal name whenever a Kidsoft record was merged in, and
            // the queue screen filters on the name the FAMILY wrote on the form
            // - a nickname, or "Baby Jones" before the child was born. The card
            // name is what the deep link used to carry, which made the link only
            // as good as the two names agreeing.
            'child_name'      => trim(name_case((string)$w['child_first_name']) . ' '
                                    . name_case((string)$w['child_last_name'])),
            'status'          => $w['status'],
            'status_label'    => waitlist_status_label($w),
            'for_year'        => $w['for_year'] === null ? null : (int)$w['for_year'],
            'date_added'      => $w['date_added'],
            'required_from'   => $w['required_from'],
            'preferred_room'  => $w['preferred_room_name'],
            'days'            => $w['days'] === null ? [] : array_map('intval', explode(',', $w['days'])),
            'queue_position'  => $positions[(int)$w['id']] ?? null,
            'reasons'         => priority_reasons($w),
            'open_offer'      => $offer === null ? null : [
                'id'         => (int)$offer['id'],
                'room'       => $offer['room_name'],
                'days'       => $offer['days'],
                'expires_at' => $offer['expires_at'],
            ],
            // Present and null rather than absent, like every field here.
            'kidsoft_offer'   => $ksOffers[(int)$w['id']] ?? null,
        ];
    }
}
