<?php
// SUPPORT PLANS - every child on one, where the process has got to, and what is
// being held for them.
//
// Asked for by a centre, 10 Sep 2026, in the same message that asked how to hold
// a child's Friday: "Maybe even a tab on the right for children on BSP might be
// good for administrators to monitor. Or even for supervisors to make sure they
// are following through all the steps and communicating with families
// correctly."
//
// TWO PLANS, NOT ONE (17 Sep 2026). The first build knew only about the
// Behaviour Support Plan. Emily Kite's two process documents describe a second,
// the Individual Support Plan, for a child with a diagnosed disability or one
// under assessment - and the ISP document is explicit that the two RUN TOGETHER:
// "The BSP does not replace the ISP. Instead, it works in partnership with the
// ISP." So they are two independent flags with two different lifecycles, not one
// flag with two values: a BSP is a ten week process that ends, and an ISP runs
// for the duration of the enrolment.
//
// WHAT IS STORED, AND WHAT IS STILL NOT:
//
//   who is on a plan   placements.has_behaviour_plan   the flags a centre ticks
//                      placements.has_individual_plan
//   which days they    the derived board               Kidsoft, live
//     actually attend
//   which days are     day_holds                       ours; Kidsoft cannot hold it
//     being kept
//   who has spoken     communications                  ours, and reachable by
//     to the family                                      placement_id since it was
//                                                        built
//   where the process  support_plans                   ours, and new 17 Sep 2026
//     has got to       support_plan_steps
//
// THE STEP LADDER IS NEW AND THE FLAG IS STILL NOT COPIED. support-plans.md
// recorded that a checklist must not be invented, and must be built only once
// the team named the steps and we recorded who said them and when. Emily Kite
// provided both process documents on 17 Sep 2026 and they are kept verbatim in
// _incl/docs/support_plans/. What the ladder stores is where a child is UP TO,
// which existed nowhere; who is on a plan is still the flag and only the flag.
// See _incl/support-plan-steps.php.
//
// ORDERED BY WHAT IS BEING MISSED. Until the ladder existed there was one kind
// of neglect and the rule was work_list()'s: never contacted first, then longest
// silence. There are now two, and an overdue step is the harder fact - a week 10
// decision nobody made is a child still in limbo, which outranks a family nobody
// happens to have logged a call for. So: most overdue first, then never
// contacted, then longest silence, then name for a stable order. A plan that has
// been closed sinks to the bottom whatever its dates say.

require_once __DIR__ . '/simple-mysqli.php';
require_once __DIR__ . '/board.php';
require_once __DIR__ . '/day-holds.php';
require_once __DIR__ . '/support-plan-steps.php';
require_once __DIR__ . '/users.php';
require_once __DIR__ . '/dates.php';

/**
 * Every child on a support plan of either kind that the caller may see.
 *
 * ONE ROW PER FLAGGED PLACEMENT, not per child, because that is the unit the
 * flags are stored at and the unit the days and the held places belong to. A
 * child flagged in two years is two rows, each true of its own year - which is
 * the honest shape, and the summary counts distinct CHILDREN so the tile and the
 * list cannot disagree about what they are counting.
 *
 * A child carrying BOTH flags is still ONE row with two plans on it, because
 * they are one child in one room and splitting them would double every held day
 * and every contact underneath.
 *
 * SCOPE IS RE-READ HERE, not taken from the caller. $centreId 0 means every
 * centre this user may see, which for a supervisor is their own - the endpoint
 * still refuses a group request from one, and this is the second line rather
 * than the only one.
 *
 * @param int $centreId 0 = every centre the user may see
 */
function support_plans_list(int $centreId = 0): array {
    $ids = $centreId ? [$centreId] : user_centre_ids();
    if (!$ids) return ['rows' => [], 'summary' => support_plans_summary([]), 'unreachable' => []];

    $marks = dbx()->whereIn($ids);
    $rows = dbx()->query(
        "SELECT p.id, p.centre_id, p.room_id, p.year, p.kidsoft_child_id,
                p.child_first_name, p.child_last_name, p.child_dob, p.status, p.notes,
                p.has_behaviour_plan, p.has_individual_plan,
                p.updated_at, c.name AS centre_name, c.calendar_mailbox
           FROM placements p
           JOIN centres c ON c.id = p.centre_id
          WHERE (p.has_behaviour_plan = 1 OR p.has_individual_plan = 1)
            AND p.centre_id IN($marks)
          ORDER BY c.name, p.child_last_name, p.child_first_name, p.year",
        $ids
    )->fetchAll();
    if (!$rows) return ['rows' => [], 'summary' => support_plans_summary([]), 'unreachable' => []];

    // ONE BOARD PER CENTRE-YEAR THAT ACTUALLY HAS SOMEBODY ON IT. A plan is rare
    // - two children group-wide the day this was built - so reading every centre
    // would spend seven Kidsoft round trips to answer a question about one.
    // board_load() is cached and warmed nightly; a cold miss costs ~2.6s.
    $boards = []; $unreachable = [];
    foreach ($rows as $r) {
        $key = (int)$r['centre_id'] . ':' . (int)$r['year'];
        if (array_key_exists($key, $boards)) continue;
        try {
            $boards[$key] = board_load((int)$r['centre_id'], (int)$r['year']);
        } catch (Throwable $e) {
            // THE SCREEN MUST RENDER WHEN KIDSOFT WILL NOT ANSWER, and it must
            // say so rather than showing a child with no days as though they
            // attended none. Everything else on the row is ours and still true.
            $boards[$key] = null;
            $unreachable[] = $r['centre_name'] . ' ' . (int)$r['year'];
        }
    }

    // THE SAME SWEEP cells_year() RUNS, and for the same reason it runs there
    // rather than at the endpoint: the board is already in hand, and deriving a
    // second one would be two answers inside one response. A hold whose child is
    // booked back onto the day is over - and without this the screen would be
    // the one place in the app that still showed it, because board_load()'s own
    // guard protects the FIGURES and this list reads day_holds directly.
    foreach ($boards as $b) {
        if ($b !== null) day_holds_close_booked($b);
    }

    $holds    = support_plans_holds($rows);
    $contacts = support_plans_last_contact($rows);
    // TWO QUERIES FOR THE WHOLE LIST rather than two per child, which is the
    // n+1 that only shows itself once somebody flags a dozen children.
    $plans    = plans_for_placements(array_map(static fn($r) => (int)$r['id'], $rows),
                                     support_plans_ctx($rows));

    $out = [];
    foreach ($rows as $r) {
        $key = (int)$r['centre_id'] . ':' . (int)$r['year'];
        $b   = $boards[$key];
        // NULL MEANS UNKNOWN AND [] MEANS NONE, and telling them apart is the
        // whole point here. A child on a plan is often one who has been REMOVED
        // FROM CARE pending an improvement in behaviour - their bookings are
        // gone, so the board answers correctly and shows them nowhere. Reported
        // as "Kidsoft would not answer" that reads as a data fault and sends
        // somebody to check an API that is working perfectly.
        $roomName = null;
        $days = $b === null ? null : [];
        if ($b) {
            foreach ($b['rooms'] as $room) {
                if ((int)$room['id'] !== (int)$r['room_id']) continue;
                $roomName = $room['name'];
                foreach ($room['placements'] as $p) {
                    $match = $r['kidsoft_child_id'] !== null
                        ? $p['kidsoft_child_id'] === $r['kidsoft_child_id']
                        : (int)$p['id'] === (int)$r['id'];
                    if ($match) { $days = $p['days']; break; }
                }
                break;
            }
        }
        if ($roomName === null) {
            $room = room_get((int)$r['room_id']);
            $roomName = $room['name'] ?? 'Room ' . (int)$r['room_id'];
        }

        $h = $holds['p' . (int)$r['id']] ?? ($r['kidsoft_child_id'] !== null
            ? ($holds['k' . $r['kidsoft_child_id'] . ':' . (int)$r['year']
                       . ':' . (int)$r['room_id']] ?? []) : []);
        $c = $contacts['p' . (int)$r['id']] ?? ($r['kidsoft_child_id'] !== null
            ? ($contacts['k' . $r['kidsoft_child_id']] ?? null) : null);

        // ONE LADDER PER FLAG THAT IS ACTUALLY TICKED. A blank shape rather than
        // null for a flag with no row yet, so the screen renders the steps for a
        // child nobody has touched without the tool writing a row to make that
        // possible - see plan_shape().
        $rowPlans = [];
        foreach (['bsp' => 'has_behaviour_plan', 'isp' => 'has_individual_plan'] as $t => $col) {
            if (!(int)$r[$col]) continue;
            $rowPlans[$t] = $plans[(int)$r['id']][$t]
                ?? plan_blank($t, ['year' => (int)$r['year'],
                                   'room_tier' => room_type_key($roomName),
                                   'calendar' => !empty($r['calendar_mailbox'])]);
        }

        // THE ROW'S OWN WORST OVERDUE, across both plans and counting an ISP
        // review date beside a BSP step, because the list is ordered on one
        // number and a child late on either is late.
        $overdue = null; $allClosed = true;
        foreach ($rowPlans as $p) {
            if (!$p['closed']) $allClosed = false;
            foreach ([$p['worst_overdue'], $p['review_overdue_days']] as $d) {
                if ($d !== null) $overdue = max($overdue ?? 0, $d);
            }
        }

        $out[] = [
            'placement_id' => (int)$r['id'],
            'centre_id'    => (int)$r['centre_id'],
            'centre_name'  => $r['centre_name'],
            // Whether a booked day can go into this centre's Outlook calendar at
            // all - centres.calendar_mailbox, NULL for none. See support-plan-calendar.php.
            'has_calendar' => !empty($r['calendar_mailbox']),
            'room_id'      => (int)$r['room_id'],
            'room_name'    => $roomName,
            'year'         => (int)$r['year'],
            'ks_id'        => $r['kidsoft_child_id'],
            'name'         => trim($r['child_first_name'] . ' ' . $r['child_last_name']),
            'status'       => $r['status'],
            'note'         => $r['notes'],
            // NULL only when Kidsoft would not answer; [] when it answered and
            // this child holds no booking in this room. An empty day strip and an
            // unknown one look identical and mean opposite things.
            'days'         => $days,
            'holds'        => array_values($h),
            'held_days'    => array_values(array_map(static fn($x) => $x['weekday'], $h)),
            'last_contact' => $c,
            // Days since anybody logged speaking to this family, and null when
            // nobody ever has. The second sort key, and the one figure on the row
            // that is about the STAFF rather than the child.
            'quiet_days'   => $c === null ? null : support_plans_days_since($c['occurred_at']),
            'plans'        => $rowPlans,
            'overdue_days' => $overdue,
            'all_closed'   => $allClosed,
        ];
    }

    // ORDERED BY WHAT IS BEING MISSED, hardest fact first. An overdue step beats
    // a quiet family because it is a thing somebody was supposed to DO on a date
    // that has passed; a quiet family is an absence of evidence. Never contacted
    // still outranks every length of silence, for the reason it always did:
    // there is no weaker claim on somebody's attention than "nobody has ever
    // rung them". Name last so the order is stable between loads.
    usort($out, static function ($a, $b) {
        $ca = $a['all_closed'] ? 1 : 0;
        $cb = $b['all_closed'] ? 1 : 0;
        if ($ca !== $cb) return $ca <=> $cb;
        $oa = $a['overdue_days'] ?? 0;
        $ob = $b['overdue_days'] ?? 0;
        if ($oa !== $ob) return $ob <=> $oa;
        $qa = $a['quiet_days'] === null ? PHP_INT_MAX : $a['quiet_days'];
        $qb = $b['quiet_days'] === null ? PHP_INT_MAX : $b['quiet_days'];
        if ($qa !== $qb) return $qb <=> $qa;
        return strcmp($a['name'], $b['name']);
    });

    return ['rows' => $out, 'summary' => support_plans_summary($out),
            'unreachable' => array_values(array_unique($unreachable))];
}

/**
 * What each placement's ladder needs to know about where the child is.
 *
 * Built from the rows already in hand rather than by plan_ctx() per placement,
 * which would be a query each - the n+1 plans_for_placements() exists to avoid.
 * The room NAME is the tier, via room_type_key(): the early intervention step
 * appears only for a child in an Amazing Asteroids room. See PLAN_EI_TIER.
 */
function support_plans_ctx(array $rows): array {
    $out = [];
    foreach ($rows as $r) {
        $room = room_get((int)$r['room_id']);
        $out[(int)$r['id']] = [
            'year'      => (int)$r['year'],
            'room_tier' => room_type_key((string)($room['name'] ?? '')),
            'calendar'  => !empty($r['calendar_mailbox']),
        ];
    }
    return $out;
}

/**
 * Open holds for the children on this list, keyed so a row can find its own.
 *
 * Keyed on ChildID AND year, because a hold belongs to a room-year: a child on a
 * plan in 2026 and 2027 has two rows here and each must show only its own year's
 * held places. Keyed on our placement row as well for a provisional child, who
 * has no ChildID to key on at all.
 */
function support_plans_holds(array $rows): array {
    $ks = $pl = [];
    foreach ($rows as $r) {
        if ($r['kidsoft_child_id'] !== null) $ks[$r['kidsoft_child_id']] = true;
        $pl[(int)$r['id']] = true;
    }
    $out = [];
    $where = []; $args = [];
    if ($ks) { $where[] = 'kidsoft_child_id IN(' . dbx()->whereIn(array_keys($ks)) . ')'; $args = array_keys($ks); }
    if ($pl) { $where[] = 'placement_id IN(' . dbx()->whereIn(array_keys($pl)) . ')'; $args = array_merge($args, array_keys($pl)); }
    if (!$where) return [];

    foreach (dbx()->query(
        "SELECT * FROM day_holds WHERE status = 'open' AND (" . implode(' OR ', $where) . ")
          ORDER BY weekday", $args
    )->fetchAll() as $h) {
        $shaped = day_hold_shape($h);
        if ($h['kidsoft_child_id'] !== null) {
            // ROOM AND YEAR, NOT JUST YEAR. A hold belongs to a room-year, and a
            // child can legitimately be booked into two rooms in one week (~2%
            // of real Tuggerah data). Keyed on the child alone, a plan in one
            // room showed the OTHER room's held days - measured with a probe row
            // 15 Sep 2026, which picked up a hold from a room it had nothing to
            // do with. The doc comment above already said "a hold belongs to a
            // room-year"; the key did not.
            $out['k' . $h['kidsoft_child_id'] . ':' . (int)$h['year']
                 . ':' . (int)$h['room_id']][] = $shaped;
        }
        if ($h['placement_id'] !== null) {
            $out['p' . (int)$h['placement_id']][] = $shaped;
        }
    }
    return $out;
}

/**
 * The most recent contact logged for each family on this list.
 *
 * ATTACHED TO THE FAMILY, NOT TO THE ROW - the rule the contact log has always
 * followed. A note filed against a queue application before the child enrolled
 * is the same family's history, so the ChildID is looked up as well as our
 * placement row.
 */
function support_plans_last_contact(array $rows): array {
    $ks = $pl = [];
    foreach ($rows as $r) {
        if ($r['kidsoft_child_id'] !== null) $ks[$r['kidsoft_child_id']] = true;
        $pl[(int)$r['id']] = true;
    }
    $where = []; $args = [];
    if ($ks) { $where[] = 'kidsoft_child_id IN(' . dbx()->whereIn(array_keys($ks)) . ')'; $args = array_keys($ks); }
    if ($pl) { $where[] = 'placement_id IN(' . dbx()->whereIn(array_keys($pl)) . ')'; $args = array_merge($args, array_keys($pl)); }
    if (!$where) return [];

    $out = [];
    foreach (dbx()->query(
        'SELECT * FROM communications WHERE ' . implode(' OR ', $where) .
        ' ORDER BY occurred_at ASC, id ASC', $args
    )->fetchAll() as $c) {
        // Ascending, so the last write for each key is the most recent contact.
        $entry = [
            'occurred_at' => $c['occurred_at'],
            'when'        => syd_datetime($c['occurred_at']),
            'summary'     => $c['summary'],
            'channel'     => $c['channel'],
            'direction'   => $c['direction'],
            'by'          => $c['staff_email'],
        ];
        if ($c['kidsoft_child_id'] !== null) $out['k' . $c['kidsoft_child_id']] = $entry;
        if ($c['placement_id'] !== null)     $out['p' . (int)$c['placement_id']] = $entry;
    }
    return $out;
}

/**
 * EVERY contact logged for each family on the list, oldest first - the trail the
 * printed report carries, where the card carries only the latest.
 *
 * Takes the SHAPED rows support_plans_list() returns and finds a family the way
 * support_plans_last_contact() does: our placement row, and the ChildID, because a
 * note filed against the queue application before the child enrolled is the same
 * family's history. Matched per row on its own keys, never by OR-ing the whole
 * list together and handing every row the lot - that would merge two families'
 * logs into one convincing but wrong thread, the fault api/comms refuses for the
 * same reason.
 *
 * OLDEST FIRST, unlike the contact modal. A printed record is read top to bottom
 * as a history, and a trail that is not in date order is not a trail - the rule
 * the card's Recorded row already follows.
 *
 * @return array placement_id => list of entries
 */
function support_plans_history(array $rows): array {
    $byPl = $byKs = [];
    foreach ($rows as $r) {
        $byPl[(int)$r['placement_id']][] = (int)$r['placement_id'];
        if ($r['ks_id'] !== null) $byKs[$r['ks_id']][] = (int)$r['placement_id'];
    }
    if (!$byPl) return [];

    $where = ['placement_id IN(' . dbx()->whereIn(array_keys($byPl)) . ')'];
    $args  = array_keys($byPl);
    if ($byKs) {
        $where[] = 'kidsoft_child_id IN(' . dbx()->whereIn(array_keys($byKs)) . ')';
        $args = array_merge($args, array_map('strval', array_keys($byKs)));
    }

    $out = [];
    foreach (dbx()->query(
        'SELECT * FROM communications WHERE ' . implode(' OR ', $where) .
        ' ORDER BY occurred_at ASC, id ASC', $args
    )->fetchAll() as $c) {
        $to = array_merge(
            $c['placement_id'] !== null ? ($byPl[(int)$c['placement_id']] ?? []) : [],
            $c['kidsoft_child_id'] !== null ? ($byKs[$c['kidsoft_child_id']] ?? []) : []);
        foreach (array_unique($to) as $pid) {
            $out[$pid][] = [
                // A DATE WHEN ONLY A DATE WAS TYPED. The contact form takes a day,
                // so most entries are stored at midnight, and "12:00 AM" on paper
                // reads as a time somebody recorded.
                'when'      => substr((string)$c['occurred_at'], 11, 8) === '00:00:00'
                                 ? syd_date($c['occurred_at']) : syd_datetime($c['occurred_at']),
                'direction' => $c['direction'],
                'channel'   => $c['channel'],
                'summary'   => $c['summary'],
                'body'      => $c['body'],
                'by'        => $c['staff_email'],
            ];
        }
    }
    return $out;
}

/**
 * Whole days between a Sydney wall-clock stamp and today.
 *
 * Both sides are Sydney, and the string is NEVER handed to the browser to
 * subtract - a viewer in another timezone would get a different answer to the
 * same question. See _incl/dates.php.
 */
function support_plans_days_since(string $stamp): int {
    $tz  = new DateTimeZone('Australia/Sydney');
    $now = date_create('today', $tz);
    $was = date_create(substr($stamp, 0, 10), $tz);
    if (!$was) return 0;
    return max(0, (int)$now->diff($was)->days);
}

/**
 * The figures above the list, counted off the same rows the list renders.
 *
 * CHILDREN, NOT ROWS, because the list is one row per flagged placement and a
 * tile reading "3" over two children is the figure-contradicting-its-caption bug
 * this app keeps having to fix. A child carrying both flags is ONE child and two
 * plans, which is why the plan counts are kept apart from the child count rather
 * than being three tiles that do not add up.
 */
function support_plans_summary(array $rows): array {
    $children = []; $centres = []; $heldDays = 0; $quiet = 0; $never = 0;
    $bsp = []; $isp = []; $overdue = []; $steps = 0;
    foreach ($rows as $r) {
        $child = $r['ks_id'] ?? 'p' . $r['placement_id'];
        $children[$child] = true;
        $centres[$r['centre_id']] = true;
        $heldDays += count($r['held_days']);
        if ($r['quiet_days'] === null) $never++;
        elseif ($r['quiet_days'] >= 14) $quiet++;
        if (isset($r['plans']['bsp'])) $bsp[$child] = true;
        if (isset($r['plans']['isp'])) $isp[$child] = true;
        if ($r['overdue_days'] !== null) $overdue[$child] = true;
        foreach ($r['plans'] as $p) $steps += $p['overdue'];
    }
    return [
        'children'  => count($children),
        'rows'      => count($rows),
        'centres'   => count($centres),
        'held_days' => $heldDays,
        'bsp'       => count($bsp),
        'isp'       => count($isp),
        // CHILDREN behind, and the number of steps behind, because one child
        // eight steps behind and eight children one step behind are different
        // problems and the tile that showed only one of them would hide the other.
        'overdue'       => count($overdue),
        'overdue_steps' => $steps,
        // Two separate facts and not one total: "nobody has ever rung them" is a
        // different thing from "nobody has rung them lately", and summing the two
        // would hide the worse one inside the commoner one.
        'never'     => $never,
        'quiet'     => $quiet,
    ];
}
