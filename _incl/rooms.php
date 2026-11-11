<?php
// Rooms, per-year capacities and sub-quotas - the reference data this app owns
// outright. Kidsoft has no capacity field anywhere, so these numbers are the
// denominator for every occupancy figure in the app.
//
// Capacity is PER YEAR: Tuggerah runs 194 places/day in 2026 and 170 in 2027.
// A year with no capacity row is UNKNOWN, not zero, and is reported as such.

require_once __DIR__ . '/simple-mysqli.php';

/** Weekday labels, 1=Mon .. 5=Fri (childcare runs weekdays only). */
const WEEKDAYS = [1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri'];

/** Placement statuses, from the workbook's colour legend. Value => label. */
const PLACEMENT_STATUS = [
    'unset'             => 'Not set',
    'confirmed'         => 'Confirmed',
    'waiting_paperwork' => 'Waiting for paperwork',
    'offered'           => 'Offered / tour booked',
    'move_rooms'        => 'Move rooms',
    'ending_care'       => 'Ending care',
    'swap_days'         => 'Swap days',
    'dropping_day'      => 'Dropping day',
    'waitlist'          => 'Waitlist',
    'no_position'       => 'Position not available',
];

function centres_all(bool $activeOnly = true): array {
    $sql = 'SELECT * FROM centres' . ($activeOnly ? ' WHERE active = 1' : '') . ' ORDER BY sort_order, name';
    return dbx()->query($sql)->fetchAll();
}

function centre_get(int $id): ?array {
    return dbx()->query('SELECT * FROM centres WHERE id = ?', [$id], 'i')->fetch() ?: null;
}

/**
 * Rooms for a centre with their capacity FOR $year and their sub-quotas.
 *
 * capacity is PER WEEKDAY: [1 => ?int, .. 5 => ?int]. Kidsoft reports operational
 * positions for each day and they genuinely differ (Wamberal's Little Angels runs
 * 7/8/7/8/8 Mon-Fri), so one number per room would misreport free places on the
 * very day someone is trying to fill. A null for a weekday means UNKNOWN - either
 * no figure was read, or the room is closed that day - and is reported as such
 * rather than as a room with no places.
 *
 * capacity_max is the largest of the five, for headline display only.
 */
function rooms_for_centre(int $centreId, int $year, bool $activeOnly = true): array {
    $sql = 'SELECT r.* FROM rooms r
            WHERE r.centre_id = ?' . ($activeOnly ? ' AND r.active = 1' : '') . '
            ORDER BY r.sort_order, r.name';
    $rooms = dbx()->query($sql, [$centreId], 'i')->fetchAll();
    if (!$rooms) return [];

    $ids = array_column($rooms, 'id');
    $marks = dbx()->whereIn($ids);

    $caps = [];
    foreach (dbx()->query(
        "SELECT room_id, weekday, capacity FROM room_year_capacity
         WHERE year = ? AND room_id IN($marks)",
        array_merge([$year], $ids)
    )->fetchAll() as $c) {
        $caps[(int)$c['room_id']][(int)$c['weekday']] = (int)$c['capacity'];
    }

    $quotas = dbx()->query(
        "SELECT * FROM room_subquotas WHERE active = 1 AND room_id IN($marks) ORDER BY id",
        $ids
    )->fetchAll();
    $byRoom = [];
    foreach ($quotas as $q) $byRoom[$q['room_id']][] = $q;

    foreach ($rooms as &$r) {
        $days = [];
        foreach (array_keys(WEEKDAYS) as $wd) $days[$wd] = $caps[(int)$r['id']][$wd] ?? null;
        $r['capacity']     = $days;
        $known             = array_filter($days, static fn($v) => $v !== null);
        $r['capacity_max'] = $known ? max($known) : null;
        $r['subquotas']    = $byRoom[$r['id']] ?? [];
    }
    return $rooms;
}

function room_get(int $id): ?array {
    return dbx()->query('SELECT * FROM rooms WHERE id = ?', [$id], 'i')->fetch() ?: null;
}

/**
 * Capacity for a room in a year on ONE weekday, or null if none is recorded.
 * Capacity varies by day, so a weekday is required.
 */
function room_capacity(int $roomId, int $year, int $weekday): ?int {
    $r = dbx()->query(
        'SELECT capacity FROM room_year_capacity WHERE room_id = ? AND year = ? AND weekday = ?',
        [$roomId, $year, $weekday], 'iii'
    )->fetch();
    return $r ? (int)$r['capacity'] : null;
}

/** All five weekdays' capacity for a room in a year: [1..5 => ?int]. */
function room_capacity_week(int $roomId, int $year): array {
    $out = array_fill_keys(array_keys(WEEKDAYS), null);
    foreach (dbx()->query(
        'SELECT weekday, capacity FROM room_year_capacity WHERE room_id = ? AND year = ?',
        [$roomId, $year], 'ii'
    )->fetchAll() as $r) {
        $out[(int)$r['weekday']] = (int)$r['capacity'];
    }
    return $out;
}

/* centre_years() was here. Retired 13 Aug 2026 to retired/fn-centre-years.php --
   year_list() in board.php replaced it (a contiguous range, not a list with
   holes the year stepper walked by index). It was defined and uncalled. */

/**
 * Is a child of this age (in months) eligible for this room?
 * Eligibility is purely the age cut-off - children are never split across rooms.
 */
function room_accepts_age(array $room, ?int $ageMonths): bool {
    if ($ageMonths === null) return true;
    if ($room['min_age_months'] !== null && $ageMonths < (int)$room['min_age_months']) return false;
    if ($room['max_age_months'] !== null && $ageMonths > (int)$room['max_age_months']) return false;
    return true;
}

/** Age in whole months at $on (defaults to today, Sydney). */
function age_months(?string $dob, ?string $on = null): ?int {
    if (!$dob) return null;
    $tz = new DateTimeZone('Australia/Sydney');
    $b = date_create(substr($dob, 0, 10), $tz);
    $d = date_create($on ? substr($on, 0, 10) : 'today', $tz);
    if (!$b || !$d) return null;
    $diff = $b->diff($d);
    return $diff->invert ? 0 : ($diff->y * 12 + $diff->m);
}

/**
 * STAFFING AGE BANDS - where the NSW educator-to-child ratio steps (14 Oct 2026).
 *
 * Birth to 24 months is 1:4, 24 to 36 months 1:5, 36 months to preschool age
 * 1:10. Asked for as: "sometimes once enough kids get to a certain age, we can
 * adjust staffing requirements. On the spreadsheets the confirmed children are
 * green and to show the different ages their different toning colours of green."
 *
 * BY THE RATIO BAND, NOT BY BIRTH YEAR, though birth year is what the workbooks
 * shaded on (the MM Pink sheet headed "D.O.B: 2021" tints its 2022 births). A
 * birth year is a cohort and the ratio is an age: measured 14 Oct 2026, Tuggerah
 * Shining Stars Silver is ALL 2023 births - one green in the spreadsheet - while
 * 7 of its 22 children are under 3 and 15 are over. 16 of the 35 occupied rooms
 * group-wide held more than one band that day; all 900 children carry a DOB.
 *
 * `label` is what the screen prints; `turns` is the birthday that ends the band.
 */
const STAFFING_AGE_BANDS = [
    ['upto' => 24,   'label' => 'Under 2',    'turns' => 2],
    ['upto' => 36,   'label' => '2 to 3',     'turns' => 3],
    ['upto' => null, 'label' => '3 and over', 'turns' => null],
];

/** Index into STAFFING_AGE_BANDS for a child of this age, or null with no DOB. */
function staffing_band(?int $months): ?int {
    if ($months === null) return null;
    foreach (STAFFING_AGE_BANDS as $i => $b) {
        if ($b['upto'] === null || $months < $b['upto']) return $i;
    }
    return null;
}

/**
 * The day a board's bands are read on: TODAY for the year running, 1 January
 * for a year being planned, 31 December for one that has finished - or the day
 * the board was asked to read from, when somebody picked one (board_from()). A
 * ratio is a question about a room on a day, and those are the days each board
 * is about.
 */
function staffing_band_on(int $year, ?string $chosen = null): string {
    if ($chosen !== null) return $chosen;
    $today = date_create('today', new DateTimeZone('Australia/Sydney'))->format('Y-m-d');
    $first = $year . '-01-01';
    $last  = $year . '-12-31';
    return $today < $first ? $first : ($today > $last ? $last : $today);
}

/**
 * When a child moves into their NEXT band, if that falls after $on and inside
 * the board's year - the "once enough kids get to a certain age" the team plans
 * staffing around. Null when it is next year's news, or there is no next band.
 */
function staffing_band_next(?string $dob, ?int $band, string $on, int $year): ?string {
    if (!$dob || $band === null) return null;
    $upto = STAFFING_AGE_BANDS[$band]['upto'] ?? null;
    if ($upto === null) return null;
    $at = date_create(substr($dob, 0, 10), new DateTimeZone('Australia/Sydney'));
    if (!$at) return null;
    $d = $at->modify('+' . $upto . ' months')->format('Y-m-d');
    return ($d > $on && $d <= $year . '-12-31') ? $d : null;
}

/** Does a sub-quota apply to a child of this age? */
function subquota_matches(array $q, ?int $ageMonths): bool {
    if ($ageMonths === null) return false;
    if ($q['age_under_months'] !== null && $ageMonths >= (int)$q['age_under_months']) return false;
    if ($q['age_over_months']  !== null && $ageMonths <  (int)$q['age_over_months'])  return false;
    return true;
}

/** Human description of a sub-quota rule, e.g. "max 5 aged under 36 months". */
function subquota_describe(array $q): string {
    $bits = [];
    if ($q['age_under_months'] !== null) $bits[] = 'under ' . (int)$q['age_under_months'] . ' months';
    if ($q['age_over_months']  !== null) $bits[] = (int)$q['age_over_months'] . ' months and over';
    return 'max ' . (int)$q['max_children'] . ' aged ' . implode(', ', $bits) . ' per day';
}

/**
 * The age tier a room belongs to, as the team names it: the FIRST WORD of the
 * room name.
 *
 * Little Miracles names its rooms in five age-graded families - Little Angels,
 * Radiant Rainbows, Shining Stars, Amazing Asteroids, Mighty Meteors - and the
 * name IS the age group everybody uses. Broc's own phrasing for the weekly
 * management report is "4 3-4 in TUG", which is Amazing Asteroids.
 *
 * WHY THE FIRST WORD AND NOT THE AGE BAND. Kidsoft has no age-group field at
 * all: `Service/getRooms` returns MinAge/MaxAge and nothing else (probed 15 Sep
 * 2026, all 16 Tuggerah rooms, every field listed). And the bands do NOT bucket
 * cleanly - measured the same day over all 40 rooms there are THIRTEEN distinct
 * (min,max) pairs group-wide, and four rooms straddle whichever boundary you
 * pick: Kanwal's Shining Stars is licensed 24-60m while every other Shining
 * Stars stops at 36, and Tuggerah's Mighty Meteors Silver runs 36-72m. Bucketing
 * by band puts Kanwal's ten free 2-3 places in the 3-5 row; bucketing by the
 * name does not.
 *
 * MEASURED BEFORE IT WAS RELIED ON (15 Sep 2026, all 40 rooms): the first word
 * is unique per family - Little / Radiant / Shining / Amazing / Mighty, plus the
 * retired "Cool Comets" - so it collapses Tuggerah's Pink/Silver/Purple variants
 * AND absorbs Warnervale's "Amazing Asterouds", which is a typo in Kidsoft's own
 * room name that any whole-name rule would have filed as a sixth family.
 *
 * A room whose first word matches nothing simply becomes its own group; nothing
 * is ever silently dropped into another tier.
 */
function room_type_key(string $roomName): string {
    $first = strtok(trim($roomName), " \t\n");
    return $first === false ? '' : strtolower($first);
}

/**
 * Every room tier in the organisation, YOUNGEST FIRST, with the label to print.
 *
 * NEITHER THE LIST NOR THE ORDER IS HARD-CODED, so a centre that opens a room in
 * a new family is picked up with no code change - the same rule dash_trading()
 * follows for a centre that starts trading.
 *
 *   label - the most common full room name in the tier, so the report prints
 *           "Amazing Asteroids" (5 centres spell it that way) rather than
 *           Tuggerah's "Amazing Asteroid Purple" or Warnervale's typo.
 *   order - the MEDIAN min_age_months of the tier's rooms. Measured 15 Sep 2026:
 *           Little 0, Radiant 12, Shining 24, Amazing 36, Mighty 48, which is
 *           the order the team reads them in. A median rather than a minimum
 *           because one room set up with an unusually low floor (Tuggerah's
 *           Mighty Meteors Silver at 36m) must not drag its whole tier down the
 *           list.
 *
 * @return array key => {key, label, min_age}
 */
function room_types(): array {
    static $memo = null;
    if ($memo !== null) return $memo;

    $groups = [];
    foreach (dbx()->query('SELECT name, min_age_months FROM rooms WHERE active = 1')->fetchAll() as $r) {
        $k = room_type_key((string)$r['name']);
        if ($k === '') continue;
        $groups[$k]['names'][(string)$r['name']] = ($groups[$k]['names'][(string)$r['name']] ?? 0) + 1;
        if ($r['min_age_months'] !== null) $groups[$k]['mins'][] = (int)$r['min_age_months'];
    }

    $out = [];
    foreach ($groups as $k => $g) {
        arsort($g['names']);
        $mins = $g['mins'] ?? [];
        sort($mins);
        $out[$k] = [
            'key'     => $k,
            'label'   => (string)array_key_first($g['names']),
            // A tier nobody recorded an age for sorts last rather than first.
            'min_age' => $mins ? $mins[intdiv(count($mins), 2)] : PHP_INT_MAX,
        ];
    }
    uasort($out, static fn($a, $b) => [$a['min_age'], $a['label']] <=> [$b['min_age'], $b['label']]);
    return $memo = $out;
}
