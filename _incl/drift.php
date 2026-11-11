<?php
// Drift: where the placement board and Kidsoft disagree.
//
// This is the reason the app exists. The board is the plan the enrolments team
// owns; Kidsoft is what is actually booked and billed. The spreadsheets drifted
// from Kidsoft silently, and nobody found out until a room was over-full or a
// place sat empty for a term. This surfaces the disagreements while they are
// still cheap to fix.
//
// Kidsoft remains STRICTLY READ-ONLY. Nothing here writes upstream; a drift is
// resolved either by editing our board or by someone fixing Kidsoft by hand.
//
// MATCHING is the hard part. A placement is a name typed by staff; a Kidsoft
// booking carries a ChildID. Once a placement is linked to a ChildID the match is
// exact and permanent. Until then we match on name - and the real workbooks
// contain genuine typos ("Knox Blackmore" vs "Knox Bloackmore"), so an exact
// match alone would report a false difference for every misspelling. Near
// matches are therefore surfaced as SUGGESTIONS for a human to confirm, never
// applied automatically: silently pairing two similar names could attribute one
// child's attendance to another.

require_once __DIR__ . '/simple-mysqli.php';
require_once __DIR__ . '/board.php';
require_once __DIR__ . '/occupancy.php';

/** Fold one name part for comparison: lowercase, strip anything but letters. */
function name_fold(string $s): string {
    return preg_replace('/[^a-z]/', '', strtolower($s));
}

/** Folded "first|last" key. Exact equality of this is an exact name match. */
function name_key(string $first, string $last): string {
    return name_fold($first) . '|' . name_fold($last);
}

/**
 * The digits in a name, as a sorted multiset, for keys that decide IDENTITY.
 *
 * `name_fold()` strips everything but letters, which is right for comparing and
 * ranking names - a digit is not a letter anybody typed on purpose - but it is
 * WRONG the moment a key is being used to decide that two records are the same
 * child, because the digit is sometimes the only thing telling them apart:
 *
 *     name_key('Twin 1', 'Moffitt') === name_key('Twin 2', 'Moffitt')
 *
 * Parents applying before the birth type placeholder names, and a family
 * expecting twins types the only two names they have. Found 20 Aug 2026 while
 * folding duplicate history lines in search.php: three sets of twins - Moffitt
 * at Tuggerah, Wolfenden at Niagara Park, Pan at Point Clare - each sharing a
 * surname, a date of birth, a household phone AND a folded name, which is every
 * discriminator the merge rules have. **The Wolfendens are two OPEN applications
 * and read as one row on the group register**, so the team was told to find one
 * place where two children need two.
 *
 * SORTED, AND SEPARATE FROM THE LETTERS, both deliberately. Terrigal holds
 * `Baby Haigh 2` in the queue and Kidsoft's archive holds `Baby 2 Haigh` - the
 * same child, typed twice, with the digit landing in a different part each time.
 * Keeping the digits in place would split that child in two; a sorted multiset
 * appended to the letter key ignores WHERE the digit sits (typing noise) without
 * ignoring WHICH digit it is (the discriminator).
 *
 * **A name with no digit gets an empty suffix, so every other key in the app is
 * byte-identical to what `name_key()` already produced.** Censused 20 Aug 2026:
 * 6 of 715 open applications carry a digit, 20 Kidsoft waitlist rows do, and
 * **no Kidsoft child record and no placement does at all** - which is why the
 * indexes keyed off Kidsoft names (`enrolled_days_index()`,
 * `waitlist_mark_in_care()`, `tenure.php`, `reenrolment.php`) are left on
 * `name_key()`: they cannot see one.
 */
function name_digit_key(string ...$parts): string {
    $digits = preg_replace('/[^0-9]/', '', implode('', $parts)) ?? '';
    if ($digits === '') return '#';
    $d = str_split($digits);
    sort($d);
    return '#' . implode('', $d);
}

/**
 * `name_key()` for a key that asserts two records are the SAME CHILD.
 *
 * Use this, not `name_key()`, wherever a miss costs a duplicate row and a false
 * match hides a family - the merge asymmetry the table at the foot of
 * docs/search-identity.md sets out. `name_key()` remains correct for comparing,
 * ranking and suggesting, which is every other caller.
 */
function name_identity_key(string $first, string $last): string {
    return name_key($first, $last) . name_digit_key($first, $last);
}

/**
 * Optimal string alignment distance - Levenshtein plus adjacent transposition
 * as a single edit.
 *
 * Plain levenshtein() scores a swapped pair as 2 (a delete and an insert), so
 * "Nielsen" -> "Neilsen" looks as far apart as two unrelated edits. Transposing
 * adjacent letters is one of the commonest ways a name is mistyped, so it counts
 * as one edit here.
 */
function osa_distance(string $a, string $b): int {
    $la = strlen($a); $lb = strlen($b);
    if ($la === 0) return $lb;
    if ($lb === 0) return $la;

    $d = [];
    for ($i = 0; $i <= $la; $i++) $d[$i][0] = $i;
    for ($j = 0; $j <= $lb; $j++) $d[0][$j] = $j;

    for ($i = 1; $i <= $la; $i++) {
        for ($j = 1; $j <= $lb; $j++) {
            $cost = $a[$i - 1] === $b[$j - 1] ? 0 : 1;
            $d[$i][$j] = min(
                $d[$i - 1][$j] + 1,          // deletion
                $d[$i][$j - 1] + 1,          // insertion
                $d[$i - 1][$j - 1] + $cost   // substitution
            );
            if ($i > 1 && $j > 1 && $a[$i - 1] === $b[$j - 2] && $a[$i - 2] === $b[$j - 1]) {
                $d[$i][$j] = min($d[$i][$j], $d[$i - 2][$j - 2] + 1);   // transposition
            }
        }
    }
    return $d[$la][$lb];
}

/** Total edit distance across both name parts. 0 = identical. */
function name_distance(string $a, string $b): int {
    [$af, $al] = array_pad(explode('|', $a, 2), 2, '');
    [$bf, $bl] = array_pad(explode('|', $b, 2), 2, '');
    return osa_distance($af, $bf) + osa_distance($al, $bl);
}

/**
 * Close enough to SUGGEST as the same child?
 *
 * First and last names are judged separately and the tolerance scales with
 * length, because a one-letter edit means very different things at different
 * lengths. Real cases this must get right, all present in the workbooks:
 *
 *   Knox Blackmore / Knox Bloackmore   -> suggest (a genuine typo)
 *   Eva Lamrock    / Ava Lamrock       -> do NOT suggest (different children)
 *   Indy Park      / Ivy Park          -> do NOT suggest (siblings)
 *
 * Short names get zero tolerance: at three letters, one edit is a different
 * name, not a slip. Erring towards "no suggestion" is deliberate - a missed
 * suggestion costs a manual link, a wrong one attributes a child's attendance
 * to somebody else.
 */
/**
 * Do these two strings differ by exactly one swap of adjacent characters?
 *
 * A transposition is a far stronger typo signal than a substitution, and that
 * difference is what lets the tolerance below be relaxed safely. Swapping two
 * adjacent letters preserves the exact multiset of letters, so it is something
 * fingers do - "Isaac" -> "Issac". A substitution does not: you cannot reach
 * "Ava" from "Eva" by transposing anything, because they hold different letters.
 * Two genuinely different names almost never differ by one adjacent swap alone.
 */
function is_single_transposition(string $a, string $b): bool {
    $n = strlen($a);
    if ($n !== strlen($b) || $n < 2 || $a === $b) return false;

    $diff = [];
    for ($i = 0; $i < $n; $i++) {
        if ($a[$i] !== $b[$i]) {
            $diff[] = $i;
            if (count($diff) > 2) return false;
        }
    }
    // Exactly two differing positions, adjacent, and each holds the other's char.
    return count($diff) === 2
        && $diff[1] === $diff[0] + 1
        && $a[$diff[0]] === $b[$diff[1]]
        && $a[$diff[1]] === $b[$diff[0]];
}

function name_is_near(string $a, string $b): bool {
    if ($a === '' || $b === '') return false;

    [$af, $al] = array_pad(explode('|', $a, 2), 2, '');
    [$bf, $bl] = array_pad(explode('|', $b, 2), 2, '');
    // Checked BEFORE the equality shortcut: a name that folds away to nothing
    // (non-Latin script, or punctuation only) yields the key "|", and "|" === "|"
    // would otherwise declare two unrelated unnameable children the same person.
    if ($af === '' || $bf === '' || $al === '' || $bl === '') return false;
    if ($a === $b) return true;

    // Allow one edit only once a part is long enough for a typo to be plausible
    // - EXCEPT for an adjacent transposition, which is allowed at any length.
    //
    // The length floor exists to protect short names, where a single
    // substitution is usually a different child (Eva/Ava, Indy/Ivy, both real
    // pairs at these centres). But it also refused "Isaac"/"Issac" - five
    // letters, one swap, unmistakably the same child. A transposition cannot
    // turn Eva into Ava or Indy into Ivy, so relaxing it only for swaps admits
    // the typos without reopening the door to the sibling collisions.
    $tolerance = static fn(string $x, string $y) => (
        min(strlen($x), strlen($y)) >= 6 || is_single_transposition($x, $y)
    ) ? 1 : 0;

    $df = osa_distance($af, $bf);
    $dl = osa_distance($al, $bl);
    if ($df > $tolerance($af, $bf)) return false;
    if ($dl > $tolerance($al, $bl)) return false;

    // Never let both halves drift at once - that is two coincidences, and two
    // coincidences is usually two different people.
    return ($df + $dl) <= 1;
}



/**
 * A year's coverage, re-keyed to OUR room ids - without the reconciliation.
 *
 * This is what the cell grid needs and all it needs (6 Aug 2026). It used to take
 * drift_year()'s whole output and use one field of it. Now that the board is
 * derived from Kidsoft there is nothing to reconcile, so the comparison is not
 * run at all - but WHEN each arrangement starts and stops is still the most
 * valuable thing on the screen, because an arrangement ending in March is a place
 * going on sale.
 *
 * A Kidsoft room we have not mapped is dropped: there is no cell for it.
 *
 * @return array childId => our room id => weekday => coverage
 */
function coverage_year(int $centreId, int $year, array $ks): array {
    $ourByKs = [];
    foreach (rooms_for_centre($centreId, $year) as $rm) {
        if ($rm['kidsoft_room_id']) $ourByKs[$rm['kidsoft_room_id']] = (int)$rm['id'];
    }
    $coverage = [];
    foreach (coverage_map($ks) as $cid => $rooms) {
        foreach ($rooms as $ksRoomId => $wds) {
            if (isset($ourByKs[$ksRoomId])) $coverage[$cid][$ourByKs[$ksRoomId]] = $wds;
        }
    }
    return $coverage;
}

/**
 * Per (child, room, weekday), when the arrangement actually runs.
 *
 * Judged on the SPAN, not the count: a child booked 44 of 52 Mondays could be a
 * full-year child who missed the Christmas fortnight or one who started in
 * March, and only the dates say which. Public holidays and centre closures mean
 * even a year-round child never reaches 52, so a raw percentage cannot carry
 * this on its own.
 *
 * @return array childId => roomId => weekday => {n, weeks, first, last,
 *                                                starts_late, ends_early, gappy, label}
 */
/**
 * How far from either end of the year an arrangement may begin or finish before
 * it stops being "all year". Three weeks absorbs the Christmas shutdown and a
 * slow January without absorbing a real mid-term departure.
 */
const COVERAGE_EDGE_WEEKS = 3;

/**
 * Does an arrangement that stops on $last stop EARLY - with enough of the year
 * left that the place is worth selling?
 *
 * ONE TEST FOR BOTH KINDS OF DEPARTURE (10 Nov 2026). coverage_map() asks it of
 * the last day a child is booked; cells_year() asks it of a DECLARED exit. Until
 * then a declaration skipped it and was always "early", so every child going to
 * school with an ExitDate of 27/12 was announced as a place coming free later in
 * the year - 21 day-places at Terrigal alone. Broc: every enrolment ends at the
 * end of the year, so a place freed by the year ending is not a place coming free.
 *
 * $weekdayDates is year_weekday_dates()[$wd], the calendar, sorted. $last need not
 * be in it (an ExitDate is often a Sunday).
 */
function coverage_ends_early(string $last, array $weekdayDates): bool {
    $after = 0;
    foreach ($weekdayDates as $d) if ($d > $last) $after++;
    return $after >= COVERAGE_EDGE_WEEKS;
}

/**
 * The first date of this weekday on which a place is FREE: the next one after the
 * child's last day there. Null when the year has none left.
 *
 * $weekdayDates is year_weekday_dates()[$wd]. A shut day is never returned for a
 * departure anybody counts: coverage_ends_early() needs three of these dates still
 * ahead, so the next one is never inside the Christmas shutdown.
 */
function coverage_free_from(string $lastThere, array $weekdayDates): ?string {
    foreach ($weekdayDates as $d) if ($d > $lastThere) return $d;
    return null;
}

function coverage_map(array $ks): array {
    $EDGE = COVERAGE_EDGE_WEEKS;

    $out = [];
    foreach ($ks['permanent'] as $cid => $rooms) {
        foreach ($rooms as $rid => $wds) {
            foreach ($wds as $wd => $s) {
                $all = $ks['weekdays'][$wd] ?? [];
                if (!$all) continue;
                $firstIdx = array_search($s['first'], $all, true);
                $lastIdx  = array_search($s['last'], $all, true);
                if ($firstIdx === false || $lastIdx === false) continue;

                $weeks      = $lastIdx - $firstIdx + 1;   // weeks the span covers
                $startsLate = $firstIdx >= $EDGE;
                $endsEarly  = coverage_ends_early($s['last'], $all);
                // Booked far less often than the span implies: a sporadic
                // arrangement, not a standing one. Worth distinguishing, because
                // "books the odd Monday" is not a place sold for the year.
                $gappy = $weeks >= 4 && ($s['n'] / $weeks) < 0.7;

                $out[$cid][$rid][$wd] = [
                    'n' => $s['n'], 'weeks' => $weeks,
                    'first' => $s['first'], 'last' => $s['last'],
                    'starts_late' => $startsLate, 'ends_early' => $endsEarly, 'gappy' => $gappy,
                    // Filled in below, once every room this child holds is known.
                    'handover' => false,
                    'label' => coverage_label($startsLate, $endsEarly, $gappy, $s),
                ];
            }
        }
    }
    return coverage_mark_handovers($out);
}

/**
 * Mark arrangements that end early only because the child moved to another room.
 *
 * THIS IS THE DIFFERENCE BETWEEN A PLACE TO SELL AND A CHILD HAVING A BIRTHDAY.
 * Rooms are age-banded, so a child leaving the 2-3s in April and appearing in
 * the 3-4s the following week has not freed anything - they have progressed.
 * Measured at Tuggerah 2026: of 215 arrangements that end early, 80 are exactly
 * this and only 135 are real departures. Counting all 215 as places coming free
 * would overstate sellable capacity by more than half.
 */
function coverage_mark_handovers(array $cov): array {
    // How long a gap may sit between leaving one room and starting the next and
    // still read as one continuous enrolment. A move usually lands the following
    // week; three weeks covers a move either side of a school holiday.
    $GAP = 21 * 86400;

    foreach ($cov as $cid => $rooms) {
        if (count($rooms) < 2) continue;   // only ever in one room: nothing to hand over to
        foreach ($rooms as $rid => $wds) {
            foreach ($wds as $wd => $c) {
                if (!$c['ends_early']) continue;
                foreach ($rooms as $rid2 => $wds2) {
                    if ($rid2 === $rid || !isset($wds2[$wd])) continue;
                    $next = $wds2[$wd];
                    // Another room picks this child up, on this weekday, soon
                    // after this one stops.
                    $delta = strtotime($next['first']) - strtotime($c['last']);
                    if ($delta > 0 && $delta <= $GAP) {
                        $cov[$cid][$rid][$wd]['handover'] = true;
                        $cov[$cid][$rid][$wd]['label'] =
                            'moves room ' . date('j M', strtotime($next['first']));
                        break;
                    }
                }
            }
        }
    }
    return $cov;
}

/** Plain words for a coverage shape, in the terms the team acts on. */
function coverage_label(bool $startsLate, bool $endsEarly, bool $gappy, array $s): string {
    $from  = date('j M', strtotime($s['first']));
    $until = date('j M', strtotime($s['last']));
    if ($gappy)                    return 'occasional (' . $s['n'] . ' days)';
    if ($startsLate && $endsEarly) return $from . ' to ' . $until;
    if ($startsLate)               return 'from ' . $from;
    if ($endsEarly)                return 'until ' . $until;   // the place frees up
    return 'all year';
}



/** Human labels for each drift type, and what to do about it. */
const DRIFT_TYPES = [
    'booked_but_not_placed' => [
        'label' => 'Booked but not placed',
        'help'  => 'Kidsoft is billing for a child the board says has no place. Check the board status.',
    ],
    'missing_from_board' => [
        'label' => 'Attending, not on the board',
        'help'  => 'Kidsoft has bookings for a child the board does not know about. Add them, or link them to an existing row.',
    ],
    'wrong_room' => [
        'label' => 'Different room',
        'help'  => 'The board and Kidsoft disagree about which room this child is in.',
    ],
    'day_mismatch' => [
        'label' => 'Different days',
        'help'  => 'Same room, different days. Usually a swap or a dropped day that only reached one system.',
    ],
    'missing_from_kidsoft' => [
        'label' => 'Planned, not booked',
        'help'  => 'On the board with no bookings in Kidsoft. Expected if care has not started or is ending.',
    ],
];
