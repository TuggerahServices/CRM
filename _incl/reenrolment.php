<?php
// Who has answered next year's re-enrolment offer.
//
// CLAUDE.md listed this as an OPEN QUESTION - "the annual re-enrolment
// continuation flow is run in the Kidsoft UI and is NOT confirmed to surface
// here" - and it is now answered: it surfaces through Notification/getNotifications
// with Categories=['ReEnrolment'], as "Re-enrolment Accepted" and
// "Re-enrolment Declined". Measured group-wide 3 Aug 2026 from 1 January:
// **66 accepted, 1 declined.**
//
// WHY IT MATTERS OUT OF PROPORTION TO THOSE NUMBERS. A decline is the only
// FORWARD-LOOKING vacancy signal in the whole API. Everything else this app can
// see is retrospective: `Children.ExitDate` is never future-dated, enrolment
// `EndDate`/`CeasedDate` had ZERO future dates across 257 active children, and
// `coverage_map()` infers a departure only after the bookings stop. A family
// declining re-enrolment is a place coming free NEXT YEAR, known now, while there
// is still time to sell it. And the August round - "the entire point" of the
// roll-over per CLAUDE.md - is happening as this is written, so the 1 decline is
// the start of the round, not the size of it.
//
// THREE PARAMETERS THAT ARE NOT OPTIONAL IN PRACTICE, all of which default in ways
// that quietly lie:
//   * `Status` defaults to "NotAcknowledged". An accepted re-enrolment is a fact
//     about next year whether or not somebody ticked it off in Kidsoft, so this
//     passes "All". Reading the default and concluding "163 of these are
//     unactioned" is a mistake I made before checking the docs.
//   * `IssuedFrom` defaults to the start of the week two weeks ago, so the default
//     window silently hides the whole round.
//   * `Categories` must be a real ARRAY (`Categories[]=`) of the `Value` field from
//     getNotificationCategories - a comma-joined string returns
//     "Categories is not array", and the human-readable `Label` ("Re-Enrolment")
//     matches nothing. Without it this endpoint is 6,645 rows of which 3,464 are
//     parents marking absences.
//
// WHAT THIS DELIBERATELY DOES NOT DO: it does not match the child named in the
// notification to a row on the board. The name arrives inside a sentence of free
// text ("... for child Margetson, Cove."), and a parsed-then-fuzzy-matched name is
// exactly how one child's decline would get attributed to another - the failure
// `name_is_near()` is written to avoid. The name is shown as Kidsoft wrote it and a
// human finds the child. If the sentence ever stops matching, the raw text is kept
// and the outcome recorded as unknown; nothing is guessed.

require_once __DIR__ . '/kidsoft-cache.php';
require_once __DIR__ . '/identity.php';   // name_case()
require_once __DIR__ . '/rooms.php';

/** Kidsoft notification categories that carry re-enrolment outcomes. */
require_once __DIR__ . '/board.php';   // board_load(), age_months()
require_once __DIR__ . '/drift.php';   // name_fold() - exact folding only, no distance
require_once __DIR__ . '/occupancy.php'; // bookings_year() - the true first day

const REENROL_CATEGORIES = ['ReEnrolment'];

/**
 * NSW SCHOOL AGE IS TWO DIFFERENT RULES AND THEY MUST NOT BE CONFLATED.
 *
 * https://www.nsw.gov.au/education-and-training/primary-and-high-school/starting-primary-school
 *
 *   MAY start  - a child may start Kindergarten in a year if they turn 5 on or
 *                before 31 JULY of that year. This is a FAMILY CHOICE and most
 *                of this group do not take it: measured 8 Sep 2026, of 181
 *                children old enough to start school in 2027, 110 are already
 *                booked into a room here for 2027.
 *   MUST start - a child must be enrolled in compulsory schooling by their 6th
 *                BIRTHDAY. This is not a choice and not a judgement the centre
 *                makes, so a place cannot be held for them.
 *
 * CONFLATING THE TWO IS EXACTLY THE MISTAKE THIS FILE MADE. `school_age` alone
 * was reported as "probably going to school", corrected to "probably staying"
 * on the MAY figure, and the truth is that the group contains both: 51 of the 95
 * holds on 9 Sep 2026 were children who MUST be at school in 2027.
 *
 * The room-age test cannot stand in for this. It asks whether the child is past
 * every room the CENTRE runs, and Tuggerah runs Mighty Meteors Silver licensed
 * to 72 months - so all 51 sat inside a legal room and none were excluded, while
 * the same children at any other centre would have been. A licence is not a law.
 */
function school_may_start(?string $dob, int $year): bool {
    $age = age_months($dob, $year . '-07-31');
    return $age !== null && $age >= 60;
}

/**
 * Must this child be in compulsory schooling at some point during $year?
 *
 * True once they turn 6 during that year or have already turned 6, so there is
 * no full preschool year left to sell them. School years run January to
 * December, so a child whose 6th birthday falls in November still has to start
 * that January.
 */
function school_must_start(?string $dob, int $year): bool {
    $age = age_months($dob, $year . '-12-31');
    return $age !== null && $age >= 72;
}

/**
 * Re-enrolment answers for a centre, newest first.
 *
 * @param int    $year  the year being rolled OUT of; the round for year+1 runs
 *                      during it, so the window opens at 1 January of this year.
 * @return array{accepted:int,declined:int,unknown:int,list:array}
 */
function reenrolment_answers(int $centreId, int $year, bool $refresh = false): array {
    $centre = centre_get($centreId);
    if (!$centre) throw new InvalidArgumentException('unknown centre');

    $res = kidsoft_cached('Notification', 'getNotifications', [
        'ServiceID'  => $centre['kidsoft_service_id'],
        'Status'     => 'All',
        'IssuedFrom' => sprintf('%d-01-01', $year),
        'Categories' => REENROL_CATEGORIES,
    ], $refresh ? 0 : null);

    // Notifications come back FLAT - each row carries its own ServiceID - not
    // nested under the service id like most endpoints. Reading it the usual way
    // returns an empty list and looks exactly like "this service has none".
    $rows = kidsoft_rows($res, 'Notifications');

    $out = ['accepted' => 0, 'declined' => 0, 'unknown' => 0, 'list' => []];
    foreach ($rows as $n) {
        $text = trim(preg_replace('/\s+/', ' ', (string)($n['Text'] ?? '')) ?? '');
        $sub  = (string)($n['SubCategory'] ?? '');

        // Outcome from the SubCategory, which is a controlled value, in preference
        // to the sentence. The text is only used for the name.
        if (stripos($sub, 'accept') !== false)      $outcome = 'accepted';
        elseif (stripos($sub, 'declin') !== false)  $outcome = 'declined';
        else                                        $outcome = 'unknown';

        $out[$outcome]++;

        $out['list'][] = [
            'outcome' => $outcome,
            'child'   => reenrolment_child_name($text),
            'date'    => substr((string)($n['Date'] ?? ''), 0, 19),
            // Kidsoft's own acknowledgement state, so the team can see what they
            // have already dealt with over there. We never write it back:
            // Notification/acknowledge is a write endpoint and out of bounds.
            'seen'    => !empty($n['Acknowledged']),
            'text'    => $text,
        ];
    }

    usort($out['list'], static fn($a, $b) => strcmp((string)$b['date'], (string)$a['date']));
    return $out;
}

/**
 * The child's name out of "... Re-enrolment for child Margetson, Cove."
 *
 * Returns null rather than a guess when the sentence does not match, because a
 * half-parsed name on a screen about next year's places is worse than no name --
 * the raw text travels alongside for exactly that case.
 */
function reenrolment_child_name(string $text): ?string {
    if (!preg_match('/for child\s+(.+?)\s*\.?\s*$/i', $text, $m)) return null;
    $name = trim($m[1]);
    if ($name === '') return null;

    // Kidsoft writes "Last, First". Flip it, and leave anything without a comma
    // exactly as it came.
    if (substr_count($name, ',') === 1) {
        [$last, $first] = array_map('trim', explode(',', $name));
        if ($first !== '' && $last !== '') {
            return name_case($first) . ' ' . name_case($last);
        }
    }
    return name_case($name);
}

/**
 * How far through the round one centre is: answers, and the GAP behind them.
 *
 * An acceptance is a promise; a booking is the place. Measured 6 Aug 2026, six
 * days into the 2027 round, 452 families group-wide had accepted and only 220
 * had a booking created - Niagara Park 92 accepted and none booked, Terrigal 41
 * and none. Those families believe they have a place next year and Kidsoft's
 * booking data does not yet say so. Nothing in this app, or in Kidsoft's own
 * screens, showed that gap before.
 *
 * `booked` counts children on NEXT YEAR's derived board, which is the same
 * figure the Placement grid shows - one definition, so the two cannot disagree.
 *
 * The gap is reported, never explained away: six days into a round it is normal
 * batching, and in November it is a backlog. The team knows which; the screen
 * should not guess.
 */
function reenrolment_round(int $centreId, ?int $year = null): array {
    $centre = centre_get($centreId);
    if (!$centre) throw new InvalidArgumentException('unknown centre');

    $thisYear = (int)date_create('today', new DateTimeZone('Australia/Sydney'))->format('Y');
    $year   ??= $thisYear;          // the round runs during THIS year...
    $forYear  = $year + 1;          // ...and decides the NEXT one.

    $ans = reenrolment_answers($centreId, $year);

    $board  = board_load($centreId, $forYear);
    $booked = 0;
    foreach ($board['rooms'] as $r) $booked += count($r['placements']);

    return [
        'centre_id' => $centreId,
        'centre'    => $centre['name'],
        'for_year'  => $forYear,
        'accepted'  => $ans['accepted'],
        'declined'  => $ans['declined'],
        // Places coming free next year that are knowable NOW. The whole reason
        // this endpoint is worth reading.
        'declines'  => array_values(array_filter($ans['list'],
                          static fn($x) => $x['outcome'] === 'declined')),
        'booked'    => $booked,
        // Accepted but not yet showing as a booking. Never negative: a centre can
        // legitimately book a child who never had to answer (a new enrolment).
        'unbooked'  => max(0, $ans['accepted'] - $booked),
    ];
}

/**
 * The round's start. Answers arrive in a burst from when it opens and tail off
 * over the following months, so "this round" is everything from July of the year
 * the round runs in - the previous round's tail (Sep-Dec of the year before)
 * falls outside `reenrolment_answers()`'s own 1-January window, and a genuinely
 * early start in July is still caught. Broc: the 2027 round opened 1 Aug 2026.
 */
const REENROL_ROUND_OPENS_MONTH = 7;

/**
 * Every EXACT key a Kidsoft-written child name could legitimately match on.
 *
 * NO FUZZY MATCHING, DELIBERATELY. This file already refuses to pin a DECLINE on
 * a board row by fuzzy name, for the reason `name_is_near()` exists. Measured
 * 7 Aug 2026 across 472 answers group-wide, no fuzz is needed: exact rules match
 * **469 (99.4%)**, and the whole gap is two systematic shapes Kidsoft writes --
 *
 *   "Huey (Hugh) Scott"           - nickname, then the LEGAL first name in
 *                                    brackets. The bracket is the board name.
 *   "Savannah Horner Abousleiman"  - a middle name, or a double-barrelled
 *                                    surname written with a space.
 *
 * The 3 that still miss are middle-name cases whose surname is ambiguous. They
 * cost one unnecessary phone call each, which is the safe direction: this list is
 * "who have we not heard from", so a miss means ringing a family who already
 * answered, not missing one who did not.
 */
function reenrolment_name_keys(string $name): array {
    $keys = [];
    $legal = preg_replace('/^(\S+)\s*\(([^)]+)\)/', '$2', $name);
    foreach ([$name, $legal] as $v) {
        $v = trim((string)preg_replace('/\([^)]*\)/', ' ', (string)$v));
        $parts = array_values(array_filter(preg_split('/\s+/', $v)));
        if (count($parts) < 2) continue;
        $keys[] = name_fold($parts[0]) . '|' . name_fold((string)end($parts));
        $keys[] = name_fold($parts[0]) . '|' . name_fold(implode('', array_slice($parts, 1)));
    }
    return array_values(array_unique($keys));
}

/**
 * WHO HAS NOT ANSWERED THE RE-ENROLMENT OFFER YET - phase one's actual work list.
 *
 * Kidsoft raises a notification when a family ACCEPTS or DECLINES and nothing at
 * all when the offer goes out: verified 7 Aug 2026 over every notification
 * category since 2024, the only re-enrolment subcategories that have ever existed
 * are `Re-enrolment Accepted` and `Re-enrolment Declined`, and no endpoint in the
 * documented surface mentions re-enrolment at all. So silence is not readable --
 * it can only be DERIVED, by taking this year's roll and removing the families
 * who have answered and the children ageing out.
 *
 * That makes it an inference and it is labelled as one. The parts are sound: the
 * roll comes from Kidsoft's own bookings, the answers match exactly (99.4%), and
 * ageing out is decided on age at 1 January against the oldest room the centre
 * runs - the same test the roll-over screen used.
 *
 * @return array{outstanding:array, answered:int, ageing_out:int, roll:int, for_year:int}
 */
function reenrolment_outstanding(int $centreId, ?int $year = null): array {
    $centre = centre_get($centreId);
    if (!$centre) throw new InvalidArgumentException('unknown centre');

    $thisYear = (int)date_create('today', new DateTimeZone('Australia/Sydney'))->format('Y');
    $year   ??= $thisYear;
    $forYear  = $year + 1;

    $ans   = reenrolment_answers($centreId, $year);
    $since = sprintf('%d-%02d-01', $year, REENROL_ROUND_OPENS_MONTH);
    $round = array_values(array_filter($ans['list'],
                 static fn($x) => ($x['date'] ?? '') >= $since));

    $answered = [];
    foreach ($round as $x) {
        if ($x['child'] === null) continue;
        foreach (reenrolment_name_keys($x['child']) as $k) $answered[$k] = $x['outcome'];
    }

    // The oldest room this centre runs. A child past it at 1 January has nowhere
    // to go next year and is not offered re-enrolment.
    $maxAge = null;
    foreach (rooms_for_centre($centreId, $year) as $r) {
        if ($r['max_age_months'] !== null) $maxAge = max($maxAge ?? 0, (int)$r['max_age_months']);
    }

    $board = board_load($centreId, $year);
    $out = []; $ageing = 0; $roll = 0; $answeredN = 0;
    foreach ($board['rooms'] as $r) {
        foreach ($r['placements'] as $p) {
            $roll++;
            $key = name_fold($p['child_first_name']) . '|' . name_fold($p['child_last_name']);
            if (isset($answered[$key])) { $answeredN++; continue; }

            // Past EVERY room the centre runs: they cannot be offered a place at
            // all, so they were never part of the round. A hard fact, so excluded.
            $age = age_months($p['child_dob'], $forYear . '-01-01');
            if ($maxAge !== null && $age !== null && $age >= $maxAge) { $ageing++; continue; }

            // MUST be at school during the year being rolled into. Counted with
            // the children who cannot stay, because that is what they are: it is
            // the law rather than the centre's licence, and the licence test
            // above misses them wherever a centre runs a room past 60 months.
            if (school_must_start($p['child_dob'], $forYear)) { $ageing++; continue; }

            // SCHOOL AGE IS FLAGGED, NOT EXCLUDED, and the difference matters.
            // Children leave for school at 5, but Tuggerah runs one room licensed
            // to 72 months where every other centre stops at 60 - so the
            // room-age test alone excluded 40 children at Niagara Park and ONE at
            // Tuggerah, which is legally right and practically wrong. Whether a
            // school-age child is coming back is a judgement the centre makes,
            // not one this app can derive, so it is surfaced rather than assumed:
            // the NSW test is turning 5 by 31 July of the year in question.
            $schoolAge = school_may_start($p['child_dob'], $forYear);

            $out[] = [
                'name'      => trim($p['child_first_name'] . ' ' . $p['child_last_name']),
                'ks_id'     => $p['kidsoft_child_id'],
                'room'      => $r['name'],
                'room_id'   => (int)$r['id'],
                'days'      => $p['days'],
                'age_months'=> $age,
                'school_age'=> $schoolAge,
            ];
        }
    }
    // School-age last: they are the ones most likely not to be returning, so the
    // families genuinely worth chasing sit at the top.
    usort($out, static fn($a, $b) =>
        [$a['school_age'], $a['room'], $a['name']] <=> [$b['school_age'], $b['room'], $b['name']]);

    $school = count(array_filter($out, static fn($x) => $x['school_age']));
    return ['outstanding' => $out, 'answered' => $answeredN, 'ageing_out' => $ageing,
            'school_age' => $school, 'roll' => $roll, 'for_year' => $forYear];
}

/**
 * A decline, matched to the place it frees up.
 *
 * A DECLINE IS THE ONLY FORWARD-LOOKING VACANCY SIGNAL IN THE WHOLE API. Every
 * other departure signal is retrospective - `Children.ExitDate` is never
 * future-dated, enrolment `EndDate`/`CeasedDate` had zero future dates across 257
 * active children, and coverage only infers a leaver once the bookings stop. So
 * "the Wilsons are not coming back" is worth knowing in August rather than
 * February, and it is only useful if you know WHICH room and WHICH days.
 *
 * THIS MATCHES THE NAME TO A BOARD ROW, WHICH THIS FILE PREVIOUSLY REFUSED TO DO,
 * and the refusal was right at the time: it said "a parsed-then-fuzzy-matched
 * name is precisely how one child's decline gets pinned on another". The rule was
 * about FUZZY matching. Measured 7 Aug 2026, exact keys match 469 of 472 answers
 * (99.4%) with no distance function anywhere near it, so the fuzziness is not
 * needed - see reenrolment_name_keys().
 *
 * Two things keep it honest:
 *   * EXACT ONLY. No edit distance, no near-name suggestion.
 *   * AN AMBIGUOUS NAME IS REFUSED, NOT GUESSED. If two children on the roll fold
 *     to the same key the decline is reported with no room attached, because
 *     pinning it on the wrong one tells the team a place is free that is not, and
 *     tells them nothing about the place that is.
 *
 * TWO DECLINES ARE NOT PLACES COMING FREE NEXT YEAR, and both were on the
 * Dashboard until 6 Nov 2026 (Broc). Measured that day over 26 declines:
 *   * BOOKED FOR NEXT YEAR. Ryder Scelly's family declined on 3 Aug and he has
 *     2027 bookings in Amazing Asteroids Pink on his same three days - he is
 *     moving up, not leaving. A booking is what the centre actually did; the
 *     notification is a click that nothing ever withdraws. Same test, same
 *     source as reenrolment_holds()'s "already has next year": the child's
 *     Kidsoft id on next year's roll. 1 of 26.
 *   * ALREADY GONE. A parsed name that matches nobody on this year's roll is a
 *     child who no longer holds a place, so that place came free THIS year and
 *     the board already counts it. All 6 were departures: five exited in
 *     Kidsoft in September (Beau and Madeleine Grant among them), and Logan
 *     Zaniol at Kanwal, whose bookings stopped 18 Sep while Kidsoft still had
 *     him Active. They rendered as "not matched to a room".
 * An UNPARSED name is still reported: that is "cannot tell", not "gone", and
 * dropping it would hide every decline the day Kidsoft rewords the sentence.
 * An AMBIGUOUS name is still reported, for the reason above.
 *
 * @return array{declines:array, place_days:int}
 */
function reenrolment_declines(int $centreId, ?int $year = null): array {
    $thisYear = (int)date_create('today', new DateTimeZone('Australia/Sydney'))->format('Y');
    $year   ??= $thisYear;

    $ans   = reenrolment_answers($centreId, $year);
    $since = sprintf('%d-%02d-01', $year, REENROL_ROUND_OPENS_MONTH);

    // The roll, keyed for exact lookup. A key held by MORE THAN ONE child is
    // marked ambiguous and will not be matched.
    $board = board_load($centreId, $year);
    $roll = [];
    foreach ($board['rooms'] as $r) {
        foreach ($r['placements'] as $p) {
            $k = name_fold($p['child_first_name']) . '|' . name_fold($p['child_last_name']);
            $roll[$k][] = ['room' => $r['name'], 'room_id' => (int)$r['id'], 'days' => $p['days'],
                           'ks_id' => $p['kidsoft_child_id'] ?? null];
        }
    }

    // Children with bookings NEXT year, by Kidsoft id - see above.
    $booked = [];
    foreach (reenrolment_roll($centreId, $year + 1) as $list) {
        foreach ($list as $p) if ($p['ks_id']) $booked[$p['ks_id']] = true;
    }

    $out = []; $placeDays = 0;
    foreach ($ans['list'] as $x) {
        if ($x['outcome'] !== 'declined' || ($x['date'] ?? '') < $since) continue;

        $hit = null; $ambiguous = false;
        foreach ($x['child'] !== null ? reenrolment_name_keys($x['child']) : [] as $k) {
            if (!isset($roll[$k])) continue;
            if (count($roll[$k]) > 1) { $ambiguous = true; break; }
            $hit = $roll[$k][0];
            break;
        }
        if ($x['child'] !== null && !$hit && !$ambiguous) continue;      // already gone
        if ($hit && $hit['ks_id'] && isset($booked[$hit['ks_id']])) continue;  // booked next year
        if ($hit) $placeDays += count($hit['days']);
        $out[] = [
            'child'     => $x['child'],
            'date'      => $x['date'],
            'room'      => $hit['room']    ?? null,
            'room_id'   => $hit['room_id'] ?? null,
            'days'      => $hit['days']    ?? [],
            'ambiguous' => $ambiguous,
            // WHO, so the Dashboard's name opens their dialog (6 Nov 2026). Null
            // when the name matched nobody or two children - never guessed.
            'ks_id'     => $hit['ks_id'] ?? null,
            // Kept so a row that could not be matched still shows what Kidsoft said.
            'text'      => $x['text'],
        ];
    }
    return ['declines' => $out, 'place_days' => $placeDays];
}

// ---------------------------------------------------------------------------
// HOLDING NEXT YEAR'S PLACE
//
// Enrolments team rule, 8 Sep 2026: a child already enrolled must be re-offered
// THE SAME DAYS, so the place they will move up into is not free to sell to an
// external applicant until they have answered or their clock has run out.
//
//   * enrolled AFTER the round went out -> held from their ENROLMENT date for
//     3 MONTHS, and chased from 2 WEEKS AFTER THEIR FIRST DAY (not their
//     enrolment date - the clock only starts once they have actually started and
//     are staying).
//   * sent the offer -> 2 weeks to reply plus 2 weeks grace, so 4 WEEKS from the
//     round, chased from 2 WEEKS.
//
// NOTHING IS STORED. Every part of a hold is derived - who, which days, which
// room, when it lapses - so there is no copy to go stale, and no row to write
// for 95 children the moment somebody opens a screen. CLAUDE.md has three
// instances of a stored override that nobody ever exercised; this is the same
// shape and it does not need a table until a human overrules it.
// ---------------------------------------------------------------------------

/**
 * The date the round's offers went out, INFERRED from the first answer.
 *
 * KIDSOFT RAISES NOTHING WHEN THE OFFER GOES OUT and never has: re-measured
 * 8 Sep 2026 across 1,925 ReEnrolment notifications since 1 Jan 2024, the only
 * subcategories that have ever existed are `Re-enrolment Accepted` (1,841) and
 * `Re-enrolment Declined` (84). So the two clocks the team set - "2 weeks to
 * reply" and "2 weeks grace" - have no date to count from that can be read.
 *
 * IT DOES NOT NEED ONE, BECAUSE THE ROUND GOES OUT GROUP-WIDE ON ONE DAY.
 * Measured over the 2027 round, the first answer at every one of the six trading
 * centres was the SAME DATE, 3 Aug 2026, with 28-67% of each centre's answers
 * landing inside three days. So the first answer of the round dates it to within
 * a day, needs nothing typed in, and is one date a year.
 *
 * GROUP-WIDE ON PURPOSE, not per centre: a small centre whose first family
 * happened to reply a week late would otherwise get a round date a week adrift
 * and hold every one of its places a week longer than the rest.
 *
 * Returns null when the round has not started, which is not an error - it means
 * there is nothing to hold yet, and every caller treats it that way.
 */
/**
 * The day this app started holding places.
 *
 * NOT RETROACTIVE, and that is the enrolments team's call (9 Sep 2026): the 2027
 * round opened on 3 Aug, so its 2 + 2 weeks ran out on 31 Aug, and switching the
 * holding on afterwards would have marked 34 of 44 places open to other families
 * the moment the screen first rendered. The supervisors are still working those
 * families by hand, so the round clock starts from the later of the round and
 * this date.
 *
 * THE CHASE CLOCK IS NOT MOVED - only the hold. Those families ARE overdue a
 * call and the list must keep saying so; what this defers is releasing their
 * place, not the work.
 *
 * IT EXPIRES BY ITSELF. Every future round opens after this date, so `max()`
 * picks the round and this constant stops having any effect. It is dated rather
 * than a setting for that reason - there is nothing to maintain and nothing that
 * can be left switched on.
 */
const REENROL_HOLDING_LIVE_FROM = '2026-09-09';

function reenrolment_round_opened(int $year): ?string {
    static $memo = [];
    if (array_key_exists($year, $memo)) return $memo[$year];

    $since = sprintf('%d-%02d-01', $year, REENROL_ROUND_OPENS_MONTH);
    $first = null;
    foreach (centres_all() as $c) {
        try { $ans = reenrolment_answers((int)$c['id'], $year); }
        catch (Throwable $e) { continue; }   // one unreachable centre must not move the date
        foreach ($ans['list'] as $x) {
            $d = substr((string)($x['date'] ?? ''), 0, 10);
            if ($d >= $since && ($first === null || $d < $first)) $first = $d;
        }
    }
    return $memo[$year] = $first;
}

/**
 * Each child's enrolment start date at one centre, from Kidsoft.
 *
 * `Enrolments[].StartDate` behind `Options=Enrolments` - the date the team meant
 * by "enrolment date", and NOT the same as the child's first day. Zoe Shearer's
 * enrolment starts 17 Aug 2026 and her first booking is 8 Sep, three weeks
 * apart, which is exactly why the team put the hold clock on one and the chase
 * clock on the other.
 *
 * The EARLIEST start is taken: a child who has been re-enrolled carries several
 * enrolment rows and the first one is when they joined.
 *
 * @return array<string,string> ChildID => YYYY-MM-DD
 */
function reenrolment_enrolled_at(string $serviceId): array {
    $res = kidsoft_cached('Children', 'getChildren',
        ['ServiceID' => $serviceId, 'Options' => 'Enrolments'], 6 * 3600);

    $out = [];
    foreach (kidsoft_rows($res, 'Children', $serviceId) as $c) {
        $id = (string)($c['ChildID'] ?? '');
        if ($id === '') continue;
        foreach ($c['Enrolments'] ?? [] as $e) {
            $d = substr((string)($e['StartDate'] ?? ''), 0, 10);
            if ($d === '') continue;
            if (!isset($out[$id]) || $d < $out[$id]) $out[$id] = $d;
        }
    }
    return $out;
}

/**
 * The room a child moves up into next year - A SUGGESTION, NEVER A DECISION.
 *
 * Rule: move up one room by sort order if that room accepts their age at
 * 1 January, otherwise stay where they are. Measured 8 Sep 2026 over all 597
 * real 2026 -> 2027 moves group-wide:
 *
 *   Wamberal 100%, Terrigal 97%, Point Clare 97%, Niagara Park 96%, Kanwal 95%,
 *   **Tuggerah 37%**.
 *
 * TUGGERAH CANNOT BE DERIVED AND IS A THIRD OF ALL MOVERS (189 of 597). It runs
 * 11 rooms in parallel colour streams with heavily overlapping bands - Amazing
 * Asteroid Purple 30-42 sits beside Mighty Meteors Silver 36-72 - and collapsing
 * the colour to guess only the LEVEL is still 48%, so it is not a naming problem.
 * That centre allocates by judgement and the screen must let a human say so.
 *
 * Two rules that look just as reasonable and are much worse, both measured
 * rather than argued: "lowest accepting room at or above current" is 67%
 * group-wide and only 51% at Kanwal, because the bands overlap so far (Shining
 * Stars 24-60 beside Amazing Asteroids 36-60 and Mighty Meteors 48-60) that it
 * parks everybody exactly where they already are. "Most senior accepting room"
 * ties with this one at 78% and is no simpler.
 *
 * STAYING PUT IS A REAL ANSWER, not a failure to find one: a shared-age room
 * keeps a child for a second year, and so does a child who does not go to school
 * as planned - Kanwal's Ivy Park is in Mighty Meteors two years running.
 *
 * @param array $rooms rooms_for_centre() output, sorted by sort_order
 */
function reenrolment_next_room(array $rooms, int $fromRoomId, ?int $ageMonths): ?array {
    $i = null;
    foreach ($rooms as $k => $r) if ((int)$r['id'] === $fromRoomId) { $i = $k; break; }
    if ($i === null) return null;
    $up = $rooms[$i + 1] ?? null;
    return ($up && room_accepts_age($up, $ageMonths)) ? $up : $rooms[$i];
}

/**
 * A centre-year's roll, read from the PURE KIDSOFT LAYER rather than board_load().
 *
 * WHY NOT board_load(): because the held place has to be subtracted inside
 * board_load() itself - if it were added higher up, the board and Fill would
 * report different free counts, which is the exact bug that made pending offers
 * show as free on one screen and taken on the other.
 *
 * That makes board_load() a caller of this file, so this file must not call it
 * back. It is not merely a cycle: holds for year Y come off the roll in Y-1, so
 * board_load(Y) -> holds(Y-1) -> board_load(Y-1) -> holds(Y-2) regresses down
 * the years without a bottom.
 *
 * These three functions are the layer board_load() itself builds its placements
 * from, so this is the same roll by construction, minus the annotation store's
 * provisional children - which is correct here anyway: a place is not held for a
 * child who has only been pencilled in.
 *
 * KEPT FOR THE REQUEST (6 Nov 2026). reenrolment_holds() and
 * reenrolment_declines() both read next year's roll for the same centre on one
 * Dashboard load; deriving it twice cost 0.5s across the group.
 *
 * @return array our room id => [ {ks_id, first, last, dob, days, dates}, ... ]
 */
function reenrolment_roll(int $centreId, int $year): array {
    static $memo = [];
    if (isset($memo["$centreId|$year"])) return $memo["$centreId|$year"];
    $live = board_live_children($centreId, $year);
    $peak = board_forward_occupancy($centreId, $year);
    return $memo["$centreId|$year"] = board_forward_roster($centreId, $year, $peak, $live);
}

/**
 * Next year's place held for every child who has not re-enrolled.
 *
 * THE TEST IS "NO BOOKINGS NEXT YEAR AND NOT DECLINED", which is the team's and
 * is NOT the same as reenrolment_outstanding()'s "has not answered". The two
 * disagree in both directions, measured 8 Sep 2026 group-wide: the answer-based
 * test MISSES 7 families who accepted and whom nobody has booked, and WRONGLY
 * FLAGS 5 who never answered but are already booked for next year. A place is
 * held because it is empty, not because a form is outstanding.
 *
 * Children past every room the centre runs are excluded - they have nowhere to
 * be held. School age is FLAGGED, never excluded, for the reason
 * reenrolment_outstanding() gives: whether a five-year-old is coming back is the
 * centre's judgement and Tuggerah runs a room licensed to 72 months.
 *
 * @return array{holds:array, round_opened:?string, for_year:int}
 */
function reenrolment_holds(int $centreId, ?int $year = null): array {
    $centre = centre_get($centreId);
    if (!$centre) throw new InvalidArgumentException('unknown centre');

    $today    = date_create('today', new DateTimeZone('Australia/Sydney'));
    $thisYear = (int)$today->format('Y');
    $year   ??= $thisYear;
    $forYear  = $year + 1;
    $todayStr = $today->format('Y-m-d');

    $opened = reenrolment_round_opened($year);

    // Declines are the one answer that ENDS a hold: the family has said no, so
    // the place is genuinely free. An acceptance does not end it - they are
    // coming back and the days still have to be kept.
    $ans   = reenrolment_answers($centreId, $year);
    $since = sprintf('%d-%02d-01', $year, REENROL_ROUND_OPENS_MONTH);
    $declined = [];
    foreach ($ans['list'] as $x) {
        if ($x['outcome'] !== 'declined' || ($x['date'] ?? '') < $since) continue;
        if ($x['child'] === null) continue;
        foreach (reenrolment_name_keys($x['child']) as $k) $declined[$k] = true;
    }

    $rooms = rooms_for_centre($centreId, $forYear);
    usort($rooms, static fn($a, $b) => $a['sort_order'] <=> $b['sort_order']);
    $maxAge = null;
    foreach ($rooms as $r) {
        if ($r['max_age_months'] !== null) $maxAge = max($maxAge ?? 0, (int)$r['max_age_months']);
    }

    $roll     = reenrolment_roll($centreId, $year);
    $nextRoll = reenrolment_roll($centreId, $forYear);
    $booked = [];
    foreach ($nextRoll as $list) {
        foreach ($list as $p) if ($p['ks_id']) $booked[$p['ks_id']] = true;
    }

    // Room names for the year being rolled OUT of - a centre can open or rename a
    // room between the two years, so this is not the $rooms list above.
    $fromRoomName = [];
    foreach (rooms_for_centre($centreId, $year) as $r) $fromRoomName[(int)$r['id']] = $r['name'];

    $enrolledAt = $centre['kidsoft_service_id']
        ? reenrolment_enrolled_at($centre['kidsoft_service_id']) : [];

    $holds = [];
    foreach ($roll as $rid => $list) {
        foreach ($list as $p) {
            $ks = $p['ks_id'];
            if ($ks && isset($booked[$ks])) continue;          // already has next year

            $key = name_fold($p['first']) . '|' . name_fold($p['last']);
            if (isset($declined[$key])) continue;              // said no; the place is free

            $age = age_months($p['dob'], $forYear . '-01-01');
            if ($maxAge !== null && $age !== null && $age >= $maxAge) continue;  // nowhere to go

            // COMPULSORY SCHOOL AGE IS A HARD FACT, SO IT IS EXCLUDED, exactly as
            // "past every room the centre runs" is. A child who turns 6 during
            // the year has to be at school; there is no place to keep for them
            // and chasing the family would be a wasted and confusing call.
            // 51 of 95 holds on 9 Sep 2026, every one at Tuggerah, all sitting
            // legally inside Mighty Meteors Silver's 72-month licence.
            if (school_must_start($p['dob'], $forYear)) continue;

            // `dates` is the FORWARD roster's span, which starts at today for
            // everybody still here - it is not the day they started, and it is
            // only a placeholder until the pass below replaces it for the
            // children whose first day the chase clock actually depends on.
            $firstDay = null;
            foreach ($p['dates'] ?? [] as $d) {
                $f = substr((string)($d['first'] ?? ''), 0, 10);
                if ($f !== '' && ($firstDay === null || $f < $firstDay)) $firstDay = $f;
            }

            $enrolled = $ks ? ($enrolledAt[$ks] ?? null) : null;

            // WHICH CLOCK. A child whose enrolment started after the round went
            // out was never sent it - Zoe Shearer enrolled on 17 Aug, two weeks
            // into a round that opened on the 3rd - so they get the late-enroller
            // rule. Everyone else was in the round.
            $late = $opened !== null && $enrolled !== null && $enrolled > $opened;

            if ($late) {
                $holdFrom  = $enrolled;
                $holdUntil = date('Y-m-d', strtotime($enrolled . ' +3 months'));
                $chaseFrom = $firstDay
                    ? date('Y-m-d', strtotime($firstDay . ' +2 weeks')) : null;
            } elseif ($opened !== null) {
                // The hold runs from the round, or from the day this app started
                // holding, whichever is LATER - see REENROL_HOLDING_LIVE_FROM.
                // The chase still runs from the round itself.
                $holdFrom  = max($opened, REENROL_HOLDING_LIVE_FROM);
                $holdUntil = date('Y-m-d', strtotime($holdFrom . ' +4 weeks'));
                $chaseFrom = date('Y-m-d', strtotime($opened . ' +2 weeks'));
            } else {
                // The round has not opened. Nothing is being held yet and nothing
                // is overdue; the child is listed so the screen can say so.
                $holdFrom = $holdUntil = $chaseFrom = null;
            }

            $to = reenrolment_next_room($rooms, (int)$rid, $age);

            $holds[] = [
                'name'         => trim($p['first'] . ' ' . $p['last']),
                'ks_id'        => $ks,
                'from_room'    => $fromRoomName[$rid] ?? '',
                'from_room_id' => (int)$rid,
                'to_room'      => $to ? $to['name'] : null,
                'to_room_id'   => $to ? (int)$to['id'] : null,
                'same_room'    => $to && (int)$to['id'] === (int)$rid,
                'days'         => $p['days'],
                'age_months'   => $age,
                // MAY start school, which is a family choice and mostly not
                // taken - flagged, never excluded. See school_may_start().
                'school_age'   => school_may_start($p['dob'], $forYear),
                'enrolled_at'  => $enrolled,
                'first_day'    => $firstDay,
                'late_enroller'=> $late,
                'hold_from'    => $holdFrom,
                'hold_until'   => $holdUntil,
                'chase_from'   => $chaseFrom,
                // PAST THE CLOCK IS NOT OFF THE LIST. Enrolments team, 9 Sep
                // 2026: "if they get past the 2 weeks + 2 weeks grace, we note
                // they haven't accepted or declined but other options may be
                // available". So a lapsed hold keeps the family listed and keeps
                // chasing them weekly; what it stops is the place being reserved
                // for them alone. `holds_place` is the half that feeds free
                // places - never `lapsed`, and never mere membership of this
                // list.
                'lapsed'       => $holdUntil !== null && $holdUntil < $todayStr,
                'holds_place'  => $holdUntil !== null && $holdUntil >= $todayStr,
                'chase_due'    => $chaseFrom !== null && $chaseFrom <= $todayStr,
            ];
        }
    }

    // THE LATE ENROLLERS' TRUE FIRST DAY, which the forward roster cannot give.
    //
    // Broc, 8 Sep 2026: chase "2 weeks after their 1st day (to make sure they
    // started and are staying)". board_load()'s roster is bounded by TODAY, so
    // its `first` reads as today for every child who is already here - which
    // would start the clock two weeks from now however long ago they actually
    // began, and quietly make the chase permanently late.
    //
    // bookings_year() keeps the real first date per child per weekday over the
    // whole year, so it is asked instead - but ONLY when this centre has a late
    // enroller to answer for, because it reads twelve months where the board
    // reads four. Nine children group-wide, at four centres; the other three
    // centres never pay for it.
    $lateIds = [];
    foreach ($holds as $h) if ($h['late_enroller'] && $h['ks_id']) $lateIds[$h['ks_id']] = true;
    if ($lateIds && $centre['kidsoft_service_id']) {
        try {
            $by = bookings_year($centre['kidsoft_service_id'], $year);
            $firstByChild = [];
            foreach ($by['permanent'] as $ks => $byRoom) {
                if (!isset($lateIds[$ks])) continue;
                foreach ($byRoom as $wds) {
                    foreach ($wds as $span) {
                        $f = substr((string)($span['first'] ?? ''), 0, 10);
                        if ($f === '') continue;
                        if (!isset($firstByChild[$ks]) || $f < $firstByChild[$ks]) {
                            $firstByChild[$ks] = $f;
                        }
                    }
                }
            }
            foreach ($holds as &$h) {
                if (!$h['late_enroller'] || !isset($firstByChild[$h['ks_id']])) continue;
                $h['first_day'] = $firstByChild[$h['ks_id']];
                $h['chase_from'] = date('Y-m-d', strtotime($h['first_day'] . ' +2 weeks'));
                $h['chase_due']  = $h['chase_from'] <= $todayStr;
            }
            unset($h);
        } catch (Throwable $e) {
            // The roster placeholder stands. A chase that starts a little late is
            // the safe direction; refusing to list the child is not.
        }
    }

    // EVERYBODY ELSE'S first_day IS DROPPED RATHER THAN SHOWN. The roster
    // placeholder reads as today for every child who is already here, so
    // rendering it would tell the team a child who started in 2024 began this
    // morning. Nothing uses it for them - their clocks run off the round - and a
    // withheld figure beats a confidently wrong one.
    foreach ($holds as &$h) if (!$h['late_enroller']) $h['first_day'] = null;
    unset($h);

    // Lapsed holds first - those are places the team is about to lose the right
    // to keep - then the ones already due a chase, then by name.
    usort($holds, static fn($a, $b) =>
        [!$a['lapsed'], !$a['chase_due'], $a['name']]
        <=> [!$b['lapsed'], !$b['chase_due'], $b['name']]);

    return ['holds' => $holds, 'round_opened' => $opened, 'for_year' => $forYear];
}
