<?php
// RETIRED 13 Aug 2026. Loaded by nothing. Kept because the comparison logic is
// hard-won and may be wanted again, not because anything calls it.
//
// TWO reasons it went, in order:
//
// 1. IT WAS ALREADY DEAD. drift_week() and drift_year() had no callers anywhere
//    once the board became derived from Kidsoft (6 Aug 2026): with the board read
//    live there are no disagreements to report by construction, and cells.php
//    reaches for coverage_year() instead. drift_compare() and
//    drift_drop_shadowed_casuals() were only ever called by those two.
//
// 2. IT REASONED ABOUT CASUAL DAYS, and this tool is about long-term permanent
//    enrolments ONLY (Broc, 13 Aug 2026). It held casuals aside as context and
//    captioned a day difference "plus a casual Friday". Live or not, that is a
//    consideration this codebase no longer has.
//
// What is still LIVE in _incl/drift.php: the name helpers (name_fold, name_key,
// osa_distance, name_distance, name_is_near) and the coverage functions
// (coverage_year, coverage_map, coverage_mark_handovers, coverage_label).
//
// These functions call into _incl/drift.php, _incl/board.php and _incl/rooms.php.
// Nothing here runs as it stands; it is a record, not a module.

declare(strict_types=1);

/**
 * Compare the board against Kidsoft for one centre and one week.
 *
 * KEPT FOR THE WEEK-SHAPED CALLERS ONLY. The board screen is a calendar year now
 * (see drift_year), because the enrolments team sells a place for the year: a
 * family booked on Mondays pays for every Monday, so "is this Monday sold" is a
 * year question and asking it one week at a time answered something nobody was
 * asking. This remains because a single week is still the right unit for
 * reconciling one specific week against Kidsoft.
 *
 * The board is per YEAR and Kidsoft is per DATE, so the week's year selects the
 * board. A week spanning New Year would straddle two boards; that is reported
 * rather than guessed at.
 *
 * @return array{
 *   centre:array, monday:string, year:int, dates:array, rooms:array,
 *   summary:array, unmatched_kidsoft:array
 * }
 */
function drift_week(int $centreId, string $monday): array {
    $centre = centre_get($centreId);
    if (!$centre) throw new InvalidArgumentException('unknown centre');
    $serviceId = $centre['kidsoft_service_id'];
    $allDates = week_dates($monday);
    $year     = (int)substr($allDates[1], 0, 4);

    // A week straddling New Year covers two BOARDS, and the board is the unit of
    // truth here. Comparing January bookings against December's board reports
    // every new starter as "attending, not on the board" - and the one-click fix
    // would then add them to the wrong year. So the comparison is CLIPPED to the
    // days that fall in $year, and the caller is told which days were dropped so
    // the UI can point at the other week.
    $dates = [];
    $droppedDates = [];
    foreach ($allDates as $wd => $d) {
        if ((int)substr($d, 0, 4) === $year) $dates[$wd] = $d;
        else $droppedDates[$wd] = $d;
    }
    $spansYears = (bool)$droppedDates;

    // --- Kidsoft bookings for the week --------------------------------------
    $bookings = kidsoft_rows(
        kidsoft_cached('Bookings', 'getBookings', [
            'ServiceID' => $serviceId,
            // Only the in-year days; $dates may be clipped, so take its ends
            // rather than assuming Monday..Friday are both present.
            'DateFrom'  => reset($dates),
            'DateTo'    => end($dates),
        ]),
        'Bookings', $serviceId
    );

    // A CASUAL booking is a one-off day, not a standing arrangement, so the board
    // is not expected to carry it. Comparing against it reports a permanent
    // Mon/Wed child who came in for one extra Friday as drifting - every week,
    // until someone "corrects" the board to a day the family never asked for.
    // Casual days are held aside and shown as context instead.
    //
    // Measured at Tuggerah (week of 20 Jul 2026): 12 of 801 bookings are casual,
    // and every one belongs to a child who ALSO holds permanent days that week.
    // So no child leaves the comparison by doing this - only spurious days do.
    //
    // childId => [roomKidsoftId => [weekday => true]]
    $ksChild  = [];   // permanent bookings - what the board is compared against
    $ksCasual = [];   // casual bookings - context only
    foreach ($bookings as $b) {
        // Defensive: a row missing any of these cannot be attributed to a child
        // in a room on a day, and silently grouping such rows under an empty key
        // would invent a synthetic child.
        if (empty($b['ChildID']) || empty($b['RoomID']) || empty($b['Date'])) continue;
        $date = substr((string)$b['Date'], 0, 10);
        $wd = array_search($date, $dates, true);
        if ($wd === false) continue;
        if (!empty($b['Casual'])) $ksCasual[$b['ChildID']][$b['RoomID']][$wd] = true;
        else                      $ksChild[$b['ChildID']][$b['RoomID']][$wd] = true;
    }
    $ksCasual = drift_drop_shadowed_casuals($ksChild, $ksCasual);

    return drift_compare($centreId, $year, $ksChild, $ksCasual) + [
        'monday'      => $monday,
        'dates'       => $dates,
        'spans_years' => $spansYears,
        // The weekdays excluded because they fall in the next calendar year, so
        // the UI can say which days were not checked and where to look instead.
        'dropped_days' => array_keys($droppedDates),
        'other_year'   => $spansYears ? $year + 1 : null,
    ];
}

/**
 * Compare the board against a WHOLE CALENDAR YEAR of Kidsoft bookings.
 *
 * This is what the board screen uses. A place is sold for the year - a family
 * booked on Mondays pays for every Monday whether the child turns up or not --
 * so the question is "is this room's Monday sold for the year", and a week at a
 * time could not answer it. Measured at Tuggerah: 41% of (child, room, weekday)
 * arrangements cover only PART of 2026, and a week view showed each of them as
 * either wholly present or wholly absent depending on the week you landed on.
 *
 * The comparison itself is the same one drift_week runs - see drift_compare,
 * which is deliberately keyed on weekday rather than date so both can share it.
 * What a year adds is COVERAGE: a child booked on 10 of 52 Mondays agrees with
 * the board on "Monday" and still needs saying out loud, because those ten
 * Mondays ended in March and the place has been free ever since.
 */
function drift_year(int $centreId, int $year, bool $refresh = false, ?array $ks = null): array {
    $centre = centre_get($centreId);
    if (!$centre) throw new InvalidArgumentException('unknown centre');

    // The caller may already hold the year's bookings (the grid composes drift
    // and occupancy from the same read); fetching them twice is 20MB of JSON
    // decoded for nothing.
    $ks ??= bookings_year($centre['kidsoft_service_id'], $year, $refresh);

    // Flatten the spans to the presence map drift_compare expects. A weekday is
    // "booked" if it was booked at all during the year; WHEN, and how often, is
    // carried separately and attached to the issues afterwards, so the matching
    // rules stay untouched.
    $ksChild = $ksCasual = [];
    foreach ($ks['permanent'] as $cid => $rooms) {
        foreach ($rooms as $rid => $wds) foreach (array_keys($wds) as $wd) $ksChild[$cid][$rid][$wd] = true;
    }
    foreach ($ks['casual'] as $cid => $rooms) {
        foreach ($rooms as $rid => $wds) foreach (array_keys($wds) as $wd) $ksCasual[$cid][$rid][$wd] = true;
    }
    $ksCasual = drift_drop_shadowed_casuals($ksChild, $ksCasual);

    $out = drift_compare($centreId, $year, $ksChild, $ksCasual, ['rooms_may_change' => true]);

    // Coverage for every arrangement, whether or not it produced an issue: a
    // place that frees up in March is the single most valuable thing on this
    // screen and it is NOT a disagreement with the board.
    //
    // Re-keyed to OUR room ids, because that is what the grid and every caller
    // downstream works in. A Kidsoft room we have not mapped is dropped - there
    // is no cell for it to belong to.
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

    // Hang each issue's coverage off the issue, so "different days" can say
    // WHICH days and until when, instead of leaving a reader to guess whether a
    // Friday was dropped in June or never existed.
    foreach ($out['issues'] as &$i) {
        $cid = $i['kidsoft_child_id'] ?? null;
        $rid = $i['room_id'] ?? null;
        if ($cid && $rid && isset($coverage[$cid][$rid])) $i['coverage'] = $coverage[$cid][$rid];
    }
    unset($i);

    $out['coverage'] = $coverage;
    $out['year']     = $year;
    return $out;
}

/**
 * A day carrying both a casual and a permanent booking is simply permanent;
 * leaving it in both sets would caption an ordinary day as a casual extra.
 */
function drift_drop_shadowed_casuals(array $ksChild, array $ksCasual): array {
    foreach ($ksCasual as $cid => $rooms) {
        foreach ($rooms as $rid => $wds) {
            foreach (array_keys($wds) as $wd) {
                if (isset($ksChild[$cid][$rid][$wd])) unset($ksCasual[$cid][$rid][$wd]);
            }
            if (!$ksCasual[$cid][$rid]) unset($ksCasual[$cid][$rid]);
        }
        if (!$ksCasual[$cid]) unset($ksCasual[$cid]);
    }
    return $ksCasual;
}

/**
 * The comparison itself: our board for a year against Kidsoft's bookings,
 * however those bookings were gathered.
 *
 * THIS IS DELIBERATELY DATE-FREE. Everything below keys on
 * (child, room, WEEKDAY), never on a date, which is what lets one body serve
 * both a single week (drift_week) and a whole calendar year (drift_year)
 * without the name-matching rules - the subtle, hard-won part - existing
 * twice and drifting apart.
 *
 * @param array $ksChild  childId => ksRoomId => weekday => true (permanent)
 * @param array $ksCasual same shape, one-off days, never compared
 * @param array $opts     'rooms_may_change' => true when the bookings span long
 *                        enough for a child to legitimately move room part-way
 *                        through (see below).
 */
function drift_compare(int $centreId, int $year, array $ksChild, array $ksCasual, array $opts = []): array {
    // OVER A WHOLE YEAR A CHILD LEGITIMATELY CHANGES ROOM. Rooms are age-banded
    // and children age through the year, which is the entire premise of the
    // roll-over. Measured at Tuggerah 2026: 26 children move room mid-year, and
    // requiring the board's rooms to EQUAL Kidsoft's made every one of the 25
    // wrong_room issues a false positive - the board records where a child is
    // for the year, and Kidsoft records that plus where they were before.
    //
    // Within one week nobody progresses a room, so a week still demands exact
    // agreement; anything else there is genuinely wrong.
    $roomsMayChange = !empty($opts['rooms_may_change']);
    $centre = centre_get($centreId);
    if (!$centre) throw new InvalidArgumentException('unknown centre');
    $serviceId = $centre['kidsoft_service_id'];
    $board = board_load($centreId, $year);

    // Names for those children, so a drift row can say who it is about.
    $children = [];
    foreach (kidsoft_rows(kidsoft_cached('Children', 'getChildren', ['ServiceID' => $serviceId]),
                          'Children', $serviceId) as $c) {
        if (empty($c['ChildID'])) continue;
        $children[$c['ChildID']] = $c;
    }

    // Our rooms, both directions.
    $roomByKs = [];
    $roomById = [];
    foreach ($board['rooms'] as $r) {
        $roomById[$r['id']] = $r;
        $full = room_get($r['id']);
        if ($full && $full['kidsoft_room_id']) $roomByKs[$full['kidsoft_room_id']] = $r['id'];
    }

    // --- Index our placements -----------------------------------------------
    $placements = [];
    foreach ($board['rooms'] as $r) {
        foreach ($r['placements'] as $p) {
            $p['room_id']   = (int)$r['id'];
            $p['room_name'] = $r['name'];
            $p['key']       = name_key($p['child_first_name'], $p['child_last_name']);
            $placements[(int)$p['id']] = $p;
        }
    }

    // Index by (child, room) and (name, room). A child can legitimately be in
    // two rooms in one week - observed in real Tuggerah data - so keying by
    // child alone would let one placement shadow the other and the drift could
    // never be reconciled.
    $byChildRoom = [];
    $byNameRoom  = [];
    foreach ($placements as $id => $p) {
        if ($p['kidsoft_child_id']) {
            $byChildRoom[$p['kidsoft_child_id']][$p['room_id']] = $id;
            continue;   // a linked row is claimed by its id, never by its name
        }
        $byNameRoom[$p['key']][$p['room_id']][] = $id;
    }

    // Names that more than one unlinked placement shares in the same room. The
    // name fallback must not touch these: picking one arbitrarily would attribute
    // a child's attendance to a namesake, and would mark the other placement
    // matched so it never surfaces as missing. They are reported instead.
    $ambiguousNames = [];
    foreach ($byNameRoom as $k => $rooms) {
        foreach ($rooms as $rid => $ids) {
            if (count($ids) > 1) $ambiguousNames[$k] = true;
        }
    }

    // The name keys a Kidsoft child can legitimately be known by: the legal name,
    // and the NickName if one is recorded. Kidsoft holds "ALEXANDER COOK" with
    // NickName "Alex"; the board is typed by staff who use the name the family
    // uses. Matching the legal name alone leaves those children permanently
    // unlinked and reported as drift every week.
    $ksKeysFor = static function (?array $c): array {
        if (!$c) return [];
        $last = (string)($c['LastName'] ?? '');
        $keys = [];
        $legal = name_key((string)($c['FirstName'] ?? ''), $last);
        if ($legal !== '|') $keys[] = $legal;
        if (!empty($c['NickName'])) {
            $nick = name_key((string)$c['NickName'], $last);
            if ($nick !== '|' && $nick !== $legal) $keys[] = $nick;
        }
        return $keys;
    };

    // Folded names that SOME booked Kidsoft child carries. Used to keep the
    // fuzzy suggester off any board row that an exact match will claim later in
    // the loop - otherwise a suggestion depends on iteration order.
    //
    // Also counts OWNERS per key: adding nicknames creates a new way for two
    // children to collide (one child's nickname is another's legal name, which
    // among siblings sharing a surname is entirely possible). A key claimed by
    // more than one booked child is unusable - picking either would attribute
    // one child's attendance to the other.
    $ksKeysExact = [];
    $ksKeyOwners = [];
    foreach (array_keys($ksChild) as $cid) {
        foreach ($ksKeysFor($children[$cid] ?? null) as $k) {
            $ksKeysExact[$k] = true;
            $ksKeyOwners[$k][$cid] = true;
        }
    }

    $issues = [];
    $matchedPlacements = [];

    foreach ($ksChild as $childId => $rooms) {
        $c = $children[$childId] ?? null;
        $ksName = $c ? trim(($c['FirstName'] ?? '') . ' ' . ($c['LastName'] ?? '')) : 'Unknown child';
        $ksKey  = $c ? name_key((string)($c['FirstName'] ?? ''), (string)($c['LastName'] ?? '')) : '';
        // Legal name first, then nickname. Only keys this child alone carries --
        // a shared key is a coin toss, and the fuzzy path below is no better, so
        // such a child is simply reported unmatched for a human to resolve.
        $ksMatchKeys = array_values(array_filter(
            $ksKeysFor($c),
            static fn($k) => count($ksKeyOwners[$k] ?? []) === 1
        ));

        // Kidsoft room id => sorted weekdays.
        $ksRooms = [];
        foreach ($rooms as $ksRoomId => $wds) {
            $d = array_keys($wds);
            sort($d);
            $ksRooms[$ksRoomId] = $d;
        }

        // Which of OUR rooms do those bookings correspond to?
        $ksOurRooms = [];      // ourRoomId => days
        $unmappedKs = [];
        foreach ($ksRooms as $ksRoomId => $d) {
            if (isset($roomByKs[$ksRoomId])) $ksOurRooms[$roomByKs[$ksRoomId]] = $d;
            else $unmappedKs[$ksRoomId] = $d;
        }

        // Casual days for this child, in our room ids. Never compared - carried
        // so a day difference can be captioned "plus a casual Friday" rather
        // than leaving the reader to wonder where the extra day went.
        $casualOur = [];       // ourRoomId => days
        foreach ($ksCasual[$childId] ?? [] as $ksRoomId => $wds) {
            if (!isset($roomByKs[$ksRoomId])) continue;
            $d = array_keys($wds);
            sort($d);
            $casualOur[$roomByKs[$ksRoomId]] = $d;
        }

        // --- Locate this child's placements, room by room --------------------
        $ourRooms   = [];      // ourRoomId => placementId
        $confidence = null;
        $ambiguous  = false;
        if (!empty($byChildRoom[$childId])) {
            $ourRooms = $byChildRoom[$childId];
            $confidence = 'linked';
        } else {
            // Try each name this child is known by, legal name first, and stop
            // at the first that finds board rows.
            foreach ($ksMatchKeys as $key) {
                if (empty($byNameRoom[$key])) continue;
                if (isset($ambiguousNames[$key])) {
                    // Two board rows share this name - refuse to guess.
                    $ambiguous = true;
                    break;
                }
                // Claim each room's row once. A name already consumed by an
                // earlier Kidsoft child is skipped, so two same-named children
                // cannot both be pointed at the same placement.
                foreach ($byNameRoom[$key] as $rid => $ids) {
                    $pid = $ids[0];
                    if (isset($matchedPlacements[$pid])) continue;
                    $ourRooms[$rid] = $pid;
                }
                if ($ourRooms) {
                    // Recorded distinctly: a nickname match is a weaker claim
                    // than the legal name and a reviewer should see which it was.
                    $confidence = $key === $ksKey ? 'name' : 'nickname';
                    break;
                }
            }
        }

        $allKsDays = [];
        foreach ($ksRooms as $d) foreach ($d as $w) $allKsDays[$w] = true;
        $allKsDays = array_keys($allKsDays);
        sort($allKsDays);

        // --- Nowhere on the board -------------------------------------------
        if (!$ourRooms) {
            $suggestion = null;
            if ($ksKey !== '' && $ksKey !== '|' && !$ambiguous) {
                $best = null; $bestD = PHP_INT_MAX; $tie = false;
                foreach ($placements as $id => $p) {
                    if (isset($matchedPlacements[$id])) continue;
                    if ($p['kidsoft_child_id']) continue;   // already claimed by id
                    // A row whose name exactly matches SOME Kidsoft child will be
                    // claimed by that child later in this loop; offering it here
                    // depends purely on iteration order and would link the wrong
                    // pair (Bloackmore stealing Blackmore's row).
                    if (isset($ksKeysExact[$p['key']])) continue;
                    if (!name_is_near($ksKey, $p['key'])) continue;
                    $d = name_distance($ksKey, $p['key']);
                    if ($d < $bestD)      { $bestD = $d; $best = $id; $tie = false; }
                    elseif ($d === $bestD) { $tie = true; }
                }
                // Two equally-close candidates is not a suggestion, it is a
                // coin toss. Say nothing and let a human decide.
                if ($best !== null && !$tie) $suggestion = $best;
            }
            $firstOur = $ksOurRooms ? array_key_first($ksOurRooms) : null;
            $issues[] = [
                'type'    => 'missing_from_board',
                'child'   => $ksName,
                // Discrete parts, because splitting the display name on the first
                // space turns "Mary Jane Smith" into first "Mary", last "Jane Smith".
                'first_name' => (string)($c['FirstName'] ?? ''),
                'last_name'  => (string)($c['LastName'] ?? ''),
                'kidsoft_child_id' => $childId,
                'dob'     => $c['DOB'] ?? null,
                // Every one of this child's rooms may be unmapped, in which case
                // there is no "our room" to take days from - fall back to the
                // union across all their bookings.
                'ks_days' => $firstOur !== null ? $ksOurRooms[$firstOur] : $allKsDays,
                // Each room carries its OWN days, so a child booked in two rooms
                // can be added to both rather than half-added to the first.
                'ks_rooms' => array_merge(
                    array_map(static fn($rid) => [
                        'room_id' => $rid, 'name' => $roomById[$rid]['name'],
                        'days'    => $ksOurRooms[$rid],
                    ], array_keys($ksOurRooms)),
                    array_map(static fn($d2) => [
                        'room_id' => null, 'name' => 'Unmapped Kidsoft room', 'days' => $d2,
                    ], array_values($unmappedKs))
                ),
                'room_id' => $firstOur,
                'suggest_placement' => $suggestion ? [
                    'id'   => $suggestion,
                    'name' => trim($placements[$suggestion]['child_first_name'] . ' ' .
                                   $placements[$suggestion]['child_last_name']),
                    'room' => $placements[$suggestion]['room_name'],
                ] : null,
            ];
            continue;
        }

        foreach ($ourRooms as $rid => $pid) $matchedPlacements[$pid] = true;

        $boardRoomIds = array_keys($ourRooms);
        $ksRoomIds    = array_keys($ksOurRooms);
        sort($boardRoomIds);
        sort($ksRoomIds);

        // --- The rooms themselves disagree -----------------------------------
        // Reported as one issue for the child rather than per room: "the board
        // has them somewhere else" is a single decision, and splitting it across
        // rooms would ask the same question two or three times.
        //
        // Where rooms may change (a year), the test is CONTAINMENT rather than
        // equality: every room the board claims must be one Kidsoft actually has
        // the child in. Kidsoft holding an extra room is the child's previous
        // room earlier in the year, which is progression, not drift. The board
        // claiming a room Kidsoft has no booking for is still a real problem.
        $roomsDisagree = $roomsMayChange
            ? (bool)array_diff($boardRoomIds, $ksRoomIds)
            : $boardRoomIds !== $ksRoomIds;

        if ($roomsDisagree || $unmappedKs) {
            $firstPid = reset($ourRooms);
            $issues[] = [
                'type'         => 'wrong_room',
                'child'        => trim($placements[$firstPid]['child_first_name'] . ' ' .
                                       $placements[$firstPid]['child_last_name']),
                'first_name'   => $placements[$firstPid]['child_first_name'],
                'last_name'    => $placements[$firstPid]['child_last_name'],
                'child_dob'    => $placements[$firstPid]['child_dob'],
                'placement_id' => $firstPid,
                'kidsoft_child_id' => $childId,
                'confidence'   => $confidence,
                'board_room'   => implode(', ', array_map(
                    static fn($rid) => $roomById[$rid]['name'], $boardRoomIds)),
                'room_id'      => $boardRoomIds[0] ?? null,
                'ks_rooms'     => array_merge(
                    array_map(static fn($rid) => [
                        'room_id' => $rid, 'name' => $roomById[$rid]['name'],
                        'days' => $ksOurRooms[$rid],
                    ], $ksRoomIds),
                    array_map(static fn($d2) => ['room_id' => null, 'name' => 'Unmapped Kidsoft room',
                                                 'days' => $d2],
                              array_values($unmappedKs))
                ),
                'board_days'   => $placements[$firstPid]['days'],
                'ks_days'      => $allKsDays,
                'split'        => count($ksRoomIds) + count($unmappedKs) > 1,
            ];
            continue;
        }

        // --- Same rooms; check status and days per room ----------------------
        foreach ($ourRooms as $rid => $pid) {
            $p = $placements[$pid];
            $ksDays = $ksOurRooms[$rid];

            if (in_array($p['status'], ['waitlist', 'no_position'], true)) {
                $issues[] = [
                    'type'         => 'booked_but_not_placed',
                    'child'        => trim($p['child_first_name'] . ' ' . $p['child_last_name']),
                    'placement_id' => $pid,
                    'kidsoft_child_id' => $childId,
                    'confidence'   => $confidence,
                    'status'       => $p['status'],
                    'room_id'      => $rid,
                    'board_room'   => $p['room_name'],
                    'ks_days'      => $ksDays,
                ];
                continue;
            }

            $boardDays = $p['days'];
            sort($boardDays);
            if ($boardDays === $ksDays) continue;

            $issues[] = [
                'type'         => 'day_mismatch',
                'child'        => trim($p['child_first_name'] . ' ' . $p['child_last_name']),
                'placement_id' => $pid,
                'kidsoft_child_id' => $childId,
                'confidence'   => $confidence,
                'room_id'      => $rid,
                'board_room'   => $p['room_name'],
                'board_days'   => $boardDays,
                'ks_days'      => $ksDays,
                'extra_in_kidsoft'   => array_values(array_diff($ksDays, $boardDays)),
                'missing_in_kidsoft' => array_values(array_diff($boardDays, $ksDays)),
                // One-off days, deliberately excluded from the comparison above.
                'casual_days'        => $casualOur[$rid] ?? [],
                // Context so a child who is simply in another room for the rest
                // of the week does not read as having lost days.
                'also_in' => array_values(array_map(
                    static fn($r2) => ['room_id' => $r2, 'name' => $roomById[$r2]['name'],
                                       'days' => $ksOurRooms[$r2]],
                    array_values(array_diff($ksRoomIds, [$rid]))
                )),
            ];
        }
    }

    // --- On the board, but no booking at all --------------------------------
    //
    // Kidsoft knows why most of these are missing: the child has left. Active
    // and ExitDate are mutually exclusive there (0 of 1467 children at Tuggerah
    // carry both), so an exit date is a definite departure, not a plan - and it
    // turns "planned, not booked" from a shrug into "exited 30 June, free the
    // seat", which is the whole point of the screen.
    //
    // Only a CONFIRMED link, or a folded name that exactly one child in the
    // service carries, is used to find that child. A wrong pairing here would
    // tell the team to release a place that is still occupied, which is worse
    // than saying nothing - the same reason the matcher above stays timid.
    $uniqueByName = [];
    foreach ($children as $cid => $c) {
        $k = name_key((string)($c['FirstName'] ?? ''), (string)($c['LastName'] ?? ''));
        if ($k === '|') continue;
        $uniqueByName[$k] = array_key_exists($k, $uniqueByName) ? null : $cid;
    }

    foreach ($placements as $id => $p) {
        if (isset($matchedPlacements[$id])) continue;
        // Waitlist / no-position rows are not expected to have bookings.
        if (in_array($p['status'], ['waitlist', 'no_position'], true)) continue;

        $ksc = null;
        if ($p['kidsoft_child_id']) {
            $ksc = $children[$p['kidsoft_child_id']] ?? null;
        } elseif (!empty($uniqueByName[$p['key']])) {
            $ksc = $children[$uniqueByName[$p['key']]] ?? null;
        }
        $exitedOn = ($ksc && !empty($ksc['ExitDate'])) ? substr((string)$ksc['ExitDate'], 0, 10) : null;

        // Someone whose care is ending legitimately stops appearing.
        $issues[] = [
            'type'         => 'missing_from_kidsoft',
            'child'        => trim($p['child_first_name'] . ' ' . $p['child_last_name']),
            'placement_id' => $id,
            'status'       => $p['status'],
            'room_id'      => $p['room_id'],
            'board_room'   => $p['room_name'],
            'board_days'   => $p['days'],
            'expected'     => $p['status'] === 'ending_care',
            // Present only when Kidsoft positively identifies the child as gone.
            'exited_on'    => $exitedOn,
            'exit_reason'  => $exitedOn ? ($ksc['ExitReason'] ?: null) : null,
            'exit_source'  => $exitedOn ? ($p['kidsoft_child_id'] ? 'linked' : 'name') : null,
        ];
    }

    // Order: the ones that cost money or break compliance first.
    $rank = [
        'booked_but_not_placed' => 0,
        'missing_from_board'    => 1,
        'wrong_room'            => 2,
        'day_mismatch'          => 3,
        'missing_from_kidsoft'  => 4,
    ];
    usort($issues, static fn($a, $b) => [$rank[$a['type']], $a['child']] <=> [$rank[$b['type']], $b['child']]);

    $summary = array_fill_keys(array_keys($rank), 0);
    foreach ($issues as $i) $summary[$i['type']]++;

    return [
        'centre'      => ['id' => $centreId, 'name' => $centre['name']],
        'year'        => $year,
        'issues'      => $issues,
        'summary'     => $summary,
        'total'       => count($issues),
        'board_size'  => count($placements),
        'kidsoft_size'=> count($ksChild),
    ];
}
