<?php
// ONE ENROLLED CHILD, AND EVERYTHING THIS APP KNOWS ABOUT THEM (6 Nov 2026).
//
// Asked for by Broc after a family asking Kidsoft for an extra Wednesday showed
// on the Placement board as "Waitlisted", could be found nowhere on the Waitlist,
// and had a name that opened nothing. The only dialog an enrolled child had was
// the placement editor: a name, a date of birth, five ticks and a note. It
// answered "what have we flagged on this child" and nothing a person ringing the
// family needs - what they hold, what they are asking for, next year, who to
// ring, who else in the family is here or waiting - so it was rarely opened.
//
// NOTHING HERE IS NEW DATA, and nothing is copied. Every block is an engine the
// app already runs for some other screen, filtered to one ChildID:
//
//   enrolment, this year and next   board_load()            (the board itself)
//   leaving                          board_load()['exits']   (Kidsoft ExitDate)
//   re-enrolment hold                board_load(next)        (held_children)
//   re-enrolment answer              reenrolment_answers()   (name-matched, as there)
//   asking for days                  day_requests_year()     (casual already dropped)
//                                    internal_requests_by_child()
//   days held                        day_holds_by_child()
//   parents                          getChildren&Options=Guardian + guardians_by_id()
//   brothers and sisters enrolled    the same read - a SHARED GuardianID, which is
//                                    Kidsoft's own link, never a phone or a name
//   applications and waiting sibs    the household rule siblings.php uses
//   in progress                      work_items
//   support plans                    plans_for_placements()
//   flags and the note               board_annotations() via the board row
//
// So a figure on this dialog and the same figure on the screen that owns it
// cannot disagree: they are one function's answer.
//
// THE CHILD IS THE KIDSOFT ChildID, AND IT IS PER SERVICE. A child enrolled at two
// of our centres holds two ids and is two profiles - the same rule search.php
// documents. The centre is therefore part of the key, and the endpoint checks the
// caller may see it before any of this runs.
//
// NO DOLLAR FIGURE, NO CASUAL DAY. day_requests_year() and board_load() already
// drop casual requests and casual bookings; nothing here reads getBookings or
// getWaitlistedBookings directly, so there is no second place for either rule to
// be forgotten.

require_once __DIR__ . '/board.php';
require_once __DIR__ . '/day-requests.php';
require_once __DIR__ . '/internal-requests.php';
require_once __DIR__ . '/day-holds.php';
require_once __DIR__ . '/contacts.php';
require_once __DIR__ . '/siblings.php';          // household keys, waitlist_same_child()
require_once __DIR__ . '/reenrolment.php';       // reenrolment_answers(), school_*_start()
require_once __DIR__ . '/support-plan-steps.php';
require_once __DIR__ . '/work.php';              // WORK_STAGES, WORK_CLOSED_STAGES
require_once __DIR__ . '/users.php';             // user_can_centre()
require_once __DIR__ . '/dates.php';

/** Applications still in the queue. Same set siblings.php treats as open. */
const CHILD_PROFILE_OPEN_APPS = ['waitlisted', 'offered', 'partially_placed'];

/**
 * Is this guardian link a PARENT - the person the enrolments team talks to?
 *
 * The rule siblings.php is built on and measured: the enrolling guardian, or a
 * Mother or Father. Grandparents, neighbours and case workers are emergency
 * contacts, and across Tuggerah `Grandmother` outnumbers `Mother`, so anything
 * looser lists the wrong people first and invents brothers and sisters between
 * unrelated families who share a grandmother's number.
 */
function child_profile_is_parent(array $link): bool {
    $rel = strtolower(trim((string)($link['Relationship'] ?? '')));
    return !empty($link['Enrolment']) || $rel === 'mother' || $rel === 'father';
}

/**
 * Everything this app knows about one enrolled child at one centre.
 *
 * @param array $opts 'year'    => the board year the caller is looking at, which
 *                                 decides whose flags and note are edited
 *                    'room_id' => the room the caller opened them from, likewise
 * @return array|null null when Kidsoft has no such child at this centre
 */
function child_profile(int $centreId, string $ksId, array $opts = []): ?array {
    $centre = centre_get($centreId);
    if (!$centre || !$centre['kidsoft_service_id']) return null;
    $serviceId = $centre['kidsoft_service_id'];

    $today    = date_create('today', new DateTimeZone('Australia/Sydney'));
    $todayStr = $today->format('Y-m-d');
    $thisYear = (int)$today->format('Y');
    $years    = [$thisYear, $thisYear + 1];
    $askYear  = (int)($opts['year'] ?? 0);
    $askRoom  = (int)($opts['room_id'] ?? 0);

    // ---- Who ---------------------------------------------------------------
    // ONE READ GIVES THE CHILD AND THEIR GUARDIAN LINKS. It is the read
    // child_contacts() and the sibling index already warm, so it is in cache.
    $kids = [];
    foreach (kidsoft_rows(kidsoft_cached('Children', 'getChildren', [
                 'ServiceID' => $serviceId, 'Options' => 'Guardian',
             ]), 'Children', $serviceId) as $c) {
        if (!empty($c['ChildID'])) $kids[(string)$c['ChildID']] = $c;
    }
    $k = $kids[$ksId] ?? null;
    if (!$k) return null;

    $first = name_case((string)($k['FirstName'] ?? ''));
    $last  = name_case((string)($k['LastName'] ?? ''));
    $dob   = !empty($k['DOB']) ? substr((string)$k['DOB'], 0, 10) : null;

    // ---- Where, this year and next -----------------------------------------
    // board_load() is the app's one definition of who holds which place, so the
    // rooms and days here are the board's, with its start and finish dates. A
    // year the centre has not opened for has no board to read.
    $boards = [];
    foreach ($years as $y) {
        $boards[$y] = centre_not_open_yet($centre, $y) ? null : board_load($centreId, $y);
    }

    $enrolment = [];
    $annotations = [];   // year => roomId => the board row, for flags and the note
    foreach ($years as $y) {
        $b = $boards[$y];
        $rooms = [];
        $held  = [];
        foreach ($b['rooms'] ?? [] as $r) {
            foreach ($r['placements'] as $p) {
                if ((string)$p['kidsoft_child_id'] !== $ksId) continue;
                $annotations[$y][(int)$r['id']] = $p + ['room_name' => $r['name']];
                // The child's own start and finish where they differ from the
                // room's, exactly as the roll prints them - see board_span_edge().
                $starts = $ends = null;
                foreach ($p['days'] as $wd) {
                    foreach ($r['cells'][$wd]['children'] ?? [] as $cc) {
                        if (($cc['ks_id'] ?? null) !== $ksId) continue;
                        if ($cc['from'] && ($starts === null || $cc['from'] < $starts)) $starts = $cc['from'];
                        if ($cc['to'] && ($ends === null || $cc['to'] > $ends)) $ends = $cc['to'];
                    }
                }
                $rooms[] = [
                    'room_id'   => (int)$r['id'],
                    'room_name' => $r['name'],
                    'days'      => array_map('intval', $p['days']),
                    'starts_on' => $starts ? syd_date($starts) : null,
                    'ends_on'   => $ends ? syd_date($ends) : null,
                ];
            }
            // HELD FOR A RE-ENROLMENT ANSWER. Next year's board names them; see
            // reenrolment_holds(). Only a hold still reserving the place is here.
            foreach ($r['cells'] ?? [] as $wd => $cell) {
                foreach ($cell['held_children'] ?? [] as $h) {
                    if (($h['ks_id'] ?? null) !== $ksId) continue;
                    $held[(int)$r['id']] ??= ['room_id' => (int)$r['id'], 'room_name' => $r['name'],
                                              'days' => [], 'hold_until' => $h['hold_until']];
                    $held[(int)$r['id']]['days'][] = (int)$wd;
                }
            }
        }
        $exit = $b['exits'][$ksId] ?? null;
        $enrolment[] = [
            'year'     => $y,
            'not_open' => $b === null,
            'rooms'    => $rooms,
            'reenrolment_hold' => array_values($held),
            'leaving'  => $exit ? ['on' => syd_date($exit['on']), 'reason' => $exit['reason'] ?: null] : null,
            // MAY is a family's choice and mostly not taken; MUST is the law. Two
            // facts, never one - see school_may_start().
            'school'   => $dob === null ? null
                        : (school_must_start($dob, $y) ? 'must' : (school_may_start($dob, $y) ? 'may' : null)),
        ];
    }

    // THE RE-ENROLMENT ANSWER for next year. Kidsoft says it only as a
    // notification naming the child, so it is matched on the name the way the
    // re-enrolment screen matches it, and only answers since the round opened.
    $reenrol = null;
    try {
        $since = sprintf('%d-%02d-01', $thisYear, REENROL_ROUND_OPENS_MONTH);
        $mine  = [name_fold($first) . '|' . name_fold($last)];
        foreach (reenrolment_answers($centreId, $thisYear)['list'] as $x) {
            if ($x['child'] === null || ($x['date'] ?? '') < $since) continue;
            if (!array_intersect($mine, reenrolment_name_keys($x['child']))) continue;
            if ($reenrol === null || $x['date'] > $reenrol['date']) {
                $reenrol = ['outcome' => $x['outcome'], 'date' => $x['date']];
            }
        }
    } catch (Throwable $e) {
        $reenrol = null;   // a missing answer is shown as no answer, never as a failure
    }
    if ($reenrol) {
        $reenrol = ['outcome' => $reenrol['outcome'], 'on' => syd_date($reenrol['date']),
                    'for_year' => $thisYear + 1];
    }

    // ---- What they are asking for ------------------------------------------
    $roomNames = [];
    foreach ($years as $y) {
        foreach (rooms_for_centre($centreId, $y) as $r) $roomNames[(int)$r['id']] = $r['name'];
    }

    $asking = [];
    foreach ($years as $y) {
        if ($boards[$y] === null) continue;
        // KIDSOFT'S OWN DAY REQUESTS. Casual ones and archived children are
        // already gone - day_requests_year() is the only reader of that endpoint.
        try {
            foreach (day_requests_year($centreId, $y) as $roomId => $byWd) {
                foreach ($byWd as $wd => $list) {
                    foreach ($list as $q) {
                        if (($q['kidsoft_child_id'] ?? null) !== $ksId) continue;
                        $asking[] = [
                            'source'    => 'kidsoft',
                            'year'      => $y,
                            'room_id'   => (int)$roomId,
                            'room_name' => $roomNames[(int)$roomId] ?? '',
                            'days'      => [(int)$wd],
                            'dates'     => array_map('syd_date', $q['dates']),
                            'status'    => $q['status'],
                            'asked_on'  => $q['requested_at'] ? syd_date($q['requested_at']) : null,
                            'note'      => null,
                        ];
                    }
                }
            }
        } catch (RuntimeException $e) {
            // Fails closed, as on the board: no requests rather than casual ones.
        }
        // OUR OWN: a standing wish somebody wrote down from a phone call.
        foreach (internal_requests_by_child($centreId, $y) as $roomId => $byKey) {
            $w = $byKey['k' . $ksId] ?? null;
            if (!$w) continue;
            $asking[] = [
                'source'    => 'internal',
                'year'      => $y,
                'room_id'   => (int)$roomId,
                'room_name' => $roomNames[(int)$roomId] ?? '',
                'days'      => $w['days'],
                'dates'     => [],
                'status'    => null,
                'asked_on'  => $w['requested_on'] ? syd_date($w['requested_on']) : null,
                'note'      => $w['note'],
            ];
        }
    }
    usort($asking, static fn($a, $b) => [$a['year'], $a['room_name'], $a['days'][0] ?? 0]
                                    <=> [$b['year'], $b['room_name'], $b['days'][0] ?? 0]);

    // DAYS THE CENTRE IS KEEPING FOR THEM while they are not booked on them.
    $holds = [];
    foreach ($years as $y) {
        foreach (day_holds_by_child($centreId, $y) as $roomId => $byKey) {
            $h = $byKey['k' . $ksId] ?? null;
            if (!$h) continue;
            sort($h['days']);
            $holds[] = [
                'year'      => $y,
                'room_id'   => (int)$roomId,
                'room_name' => $roomNames[(int)$roomId] ?? '',
                'days'      => $h['days'],
                'reason'    => $h['reason'],
                'from'      => $h['held_from_display'],
            ];
        }
    }

    // ---- Family -------------------------------------------------------------
    $gmap  = guardians_by_id($serviceId);
    $links = guardian_links_ranked($k['Guardian'] ?? []);

    // PARENTS ONLY, in Kidsoft's own order. See child_profile_is_parent(). Each
    // guardian's BEST number, with its type so a number that is not theirs says
    // whose it is. NEVER the guardian row: it carries billing, ATSI and court-order
    // tags, and nothing may hand those to the browser - see guardians_by_id().
    $parents = [];
    $myGuardianIds = [];
    $householdEmail = $householdPhone = [];
    foreach ($links as $l) {
        if (!child_profile_is_parent($l)) continue;
        $gid = (string)($l['GuardianID'] ?? '');
        if ($gid === '') continue;
        $myGuardianIds[$gid] = true;
        $g = $gmap[$gid] ?? null;
        $phone = $g ? guardian_phone($g) : null;
        if ($g) {
            if ($e = sibling_email_key($g['Email'] ?? null)) $householdEmail[$e] = true;
            foreach (guardian_household_phone_keys($g) as $n) $householdPhone[$n] = true;
        }
        $parents[] = [
            'name'         => trim(name_case(trim((string)($l['GuardianFirstName'] ?? ''))) . ' '
                                 . name_case(trim((string)($l['GuardianLastName'] ?? '')))),
            'relationship' => $l['Relationship'] ?? null,
            'enrolling'    => !empty($l['Enrolment']),
            'phone'        => $phone['number'] ?? null,
            'phone_type'   => $phone['type'] ?? null,
            'phone_is_parent' => $phone['is_parent'] ?? null,
            'email'        => $g['Email'] ?? null,
        ];
    }

    // BROTHERS AND SISTERS ENROLLED HERE: another active child at this service
    // linked to one of the SAME parent records. A GuardianID is Kidsoft's own
    // link, so this needs no phone or name match at all. Other centres hold other
    // ChildIDs and other GuardianIDs, so they are not reachable this way - the
    // household match below covers the queue, which is where it matters.
    $thisBoard = $boards[$thisYear] ?? null;
    $placedNow = [];
    foreach ($thisBoard['rooms'] ?? [] as $r) {
        foreach ($r['placements'] as $p) {
            if (!$p['kidsoft_child_id']) continue;
            $placedNow[(string)$p['kidsoft_child_id']][] = ['room_name' => $r['name'],
                                                           'days' => array_map('intval', $p['days'])];
        }
    }
    $siblings = [];
    if ($myGuardianIds) {
        foreach ($kids as $cid => $c) {
            if ($cid === $ksId || empty($c['Active'])) continue;
            $shared = false;
            foreach ($c['Guardian'] ?? [] as $l) {
                if (isset($myGuardianIds[(string)($l['GuardianID'] ?? '')]) && child_profile_is_parent($l)) {
                    $shared = true; break;
                }
            }
            if (!$shared) continue;
            $cdob = !empty($c['DOB']) ? substr((string)$c['DOB'], 0, 10) : null;
            $siblings[] = [
                'ks_id'      => $cid,
                'name'       => trim(name_case((string)($c['FirstName'] ?? '')) . ' '
                                   . name_case((string)($c['LastName'] ?? ''))),
                'age_months' => age_months($cdob),
                'rooms'      => $placedNow[$cid] ?? [],
                'applications' => [],
                // What waitlist_same_child() compares, so an application of
                // THEIRS lands on them below. Not sent to the browser.
                '_same' => ['child_dob' => $cdob, 'child_first_name' => (string)($c['FirstName'] ?? '')],
            ];
        }
        usort($siblings, static fn($a, $b) => ($b['age_months'] ?? 0) <=> ($a['age_months'] ?? 0));
    }

    // ---- In the queue: their own applications, and waiting brothers and sisters
    //
    // THE HOUSEHOLD RULE FROM siblings.php, run the other way round. There it
    // asks "which enrolled family does this application belong to"; here, "which
    // applications belong to this enrolled family". Same keys (a parent's email,
    // or a mobile or home number - never a work number), same parent-only filter,
    // and the same test for "this is the child themselves": waitlist_same_child(),
    // the rule measured on 52 contact-sharing pairs. An enrolled child with an
    // open application is asking for more days or for next year - 31 of them when
    // siblings.php was written - and those are exactly the rows this dialog is for.
    $ownApps = $waitingSibs = [];
    if ($householdEmail || $householdPhone) {
        $marks = dbx()->whereIn(CHILD_PROFILE_OPEN_APPS);
        $apps = dbx()->query(
            "SELECT w.id, w.centre_id, w.child_first_name, w.child_last_name, w.child_dob,
                    w.guardian_email, w.guardian_phone, w.for_year, w.required_from,
                    w.status, w.kidsoft_status, w.date_added, c.name AS centre_name,
                    (SELECT GROUP_CONCAT(d.weekday ORDER BY d.weekday)
                       FROM waitlist_days d WHERE d.waitlist_id = w.id) AS days
               FROM waitlist_applications w
               JOIN centres c ON c.id = w.centre_id
              WHERE w.status IN($marks)",
            CHILD_PROFILE_OPEN_APPS
        )->fetchAll();

        $me = ['child_dob' => $dob, 'child_first_name' => $first];
        foreach ($apps as $a) {
            $hit = (($e = sibling_email_key($a['guardian_email'])) && isset($householdEmail[$e]))
                || (($p = sibling_phone_key($a['guardian_phone'])) && isset($householdPhone[$p]));
            if (!$hit) continue;

            // A CENTRE THIS PERSON DOES NOT HOLD gets its name and nothing else,
            // the rule search follows: a supervisor learns the family is waiting
            // there, not who or for what.
            $scoped = user_can_centre((int)$a['centre_id']);
            $row = [
                'id'          => $scoped ? (int)$a['id'] : null,
                'centre_id'   => (int)$a['centre_id'],
                'centre_name' => $a['centre_name'],
                'scoped'      => $scoped,
                'name'        => $scoped ? trim(name_case((string)$a['child_first_name']) . ' '
                                              . name_case((string)$a['child_last_name'])) : null,
                'days'        => $scoped && $a['days'] !== null
                                   ? array_map('intval', explode(',', (string)$a['days'])) : [],
                'for_year'    => $scoped && $a['for_year'] ? (int)$a['for_year'] : null,
                'from'        => $scoped && $a['required_from'] ? syd_date($a['required_from']) : null,
                'status'      => $scoped ? ($a['kidsoft_status'] ?: ucfirst(str_replace('_', ' ', $a['status']))) : null,
                'added_on'    => $scoped && $a['date_added'] ? syd_date($a['date_added']) : null,
            ];
            if (waitlist_same_child($a, $me)) { $ownApps[] = $row; continue; }

            // A BROTHER OR SISTER WHO IS ENROLLED AND ALSO WAITING is one child,
            // not two lines: Wyatt Smith holds Mon and Fri at Niagara Park and has
            // an application for 2028, and listing him twice read as a fourth
            // child. Same test, against each enrolled sibling.
            foreach ($siblings as &$sib) {
                if (waitlist_same_child($a, $sib['_same'])) { $sib['applications'][] = $row; continue 2; }
            }
            unset($sib);
            $waitingSibs[] = $row;
        }
    }
    foreach ($siblings as &$sib) unset($sib['_same']);
    unset($sib);

    // ---- In progress ----------------------------------------------------------
    // An item is about this child when it names their ChildID, or when it hangs
    // off one of their own applications at this centre.
    $appIds = array_values(array_filter(array_map(
        static fn($a) => $a['centre_id'] === $centreId ? $a['id'] : null, $ownApps)));
    $closed = dbx()->whereIn(WORK_CLOSED_STAGES);
    $params = array_merge([$centreId], WORK_CLOSED_STAGES, [$ksId]);
    $byApp  = '';
    if ($appIds) {
        $byApp  = ' OR waitlist_id IN(' . dbx()->whereIn($appIds) . ')';
        $params = array_merge($params, $appIds);
    }
    $work = [];
    foreach (dbx()->query(
        "SELECT id, stage, year, room_id, tour_at, next_action_at
           FROM work_items
          WHERE centre_id = ? AND stage NOT IN($closed)
            AND (kidsoft_child_id = ?$byApp)
          ORDER BY created_at",
        $params
    )->fetchAll() as $w) {
        $work[] = [
            'id'        => (int)$w['id'],
            'stage'     => $w['stage'],
            'stage_label' => WORK_STAGES[$w['stage']]['label'] ?? $w['stage'],
            'year'      => $w['year'] ? (int)$w['year'] : null,
            'room_name' => $w['room_id'] ? ($roomNames[(int)$w['room_id']] ?? null) : null,
        ];
    }

    // ---- Support plans ------------------------------------------------------
    // Keyed on our annotation row, which is what a plan hangs off. A flag ticked
    // with no step yet recorded is still a plan - plan_shape() draws it blank.
    $planRows = dbx()->query(
        "SELECT id, year, room_id, has_behaviour_plan, has_individual_plan
           FROM placements
          WHERE kidsoft_child_id = ? AND centre_id = ?
            AND (has_behaviour_plan = 1 OR has_individual_plan = 1)
          ORDER BY year DESC",
        [$ksId, $centreId], 'si'
    )->fetchAll();
    $plans = [];
    $shapes = plans_for_placements(array_map(static fn($r) => (int)$r['id'], $planRows));
    foreach ($planRows as $pr) {
        foreach (['bsp' => 'has_behaviour_plan', 'isp' => 'has_individual_plan'] as $type => $flag) {
            if (empty($pr[$flag])) continue;
            $s = $shapes[(int)$pr['id']][$type] ?? plan_blank($type);
            $next = null; $overdue = 0;
            foreach ($s['steps'] ?? [] as $st) {
                if ($st['overdue_days']) $overdue++;
                if ($next === null && !$st['done_on'] && !$s['outcome']) $next = $st['title'];
            }
            $plans[] = [
                'type'         => $type,
                'label'        => $s['type_label'],
                'placement_id' => (int)$pr['id'],
                'year'         => (int)$pr['year'],
                'commenced'    => $s['commenced_display'],
                'outcome'      => $s['outcome_label'],
                'next_step'    => $next,
                'overdue'      => $overdue,
            ];
        }
    }

    // ---- Ours: the flags and the note --------------------------------------
    // Stored per (child, room, year), because that is what the board annotates.
    // The one edited here is the room and year the caller opened them from; with
    // no room named, the first room in that year, then the first this year.
    $edit = null;
    foreach (array_unique(array_filter([$askYear, $thisYear, $thisYear + 1])) as $y) {
        $byRoom = $annotations[$y] ?? [];
        if (!$byRoom) continue;
        $row = $byRoom[$askRoom] ?? reset($byRoom);
        $edit = [
            'year'      => $y,
            'room_id'   => (int)$row['room_id'],
            'room_name' => $row['room_name'],
            // Only worth saying when there is more than one it could be.
            'ambiguous' => count($byRoom) > 1,
            // Sent back unchanged on save. Kidsoft owns a linked child's status
            // and save_placement keeps the row's own whatever is posted; posting
            // that same value is what stops it warning that nothing changed.
            'status'    => $row['status'],
            'is_sibling'          => (bool)$row['is_sibling'],
            'is_staff_child'      => (bool)$row['is_staff_child'],
            'needs_two_educators' => (bool)$row['needs_two_educators'],
            'has_behaviour_plan'  => (bool)$row['has_behaviour_plan'],
            'has_individual_plan' => (bool)$row['has_individual_plan'],
            'notes'     => $row['notes'],
        ];
        break;
    }

    return [
        'child' => [
            'ks_id'       => $ksId,
            'name'        => trim($first . ' ' . $last),
            'first'       => $first,
            'last'        => $last,
            'dob'         => $dob ? syd_date($dob) : null,
            'age_months'  => age_months($dob),
            'active'      => !empty($k['Active']),
            'centre_id'   => $centreId,
            'centre_name' => $centre['name'],
        ],
        'today'      => syd_date($todayStr),
        'enrolment'  => $enrolment,
        'reenrolment'=> $reenrol,
        'asking'     => $asking,
        'holds'      => $holds,
        'parents'    => $parents,
        'siblings'   => $siblings,
        'waiting_siblings' => $waitingSibs,
        'applications' => $ownApps,
        'work'       => $work,
        'plans'      => $plans,
        'edit'       => $edit,
    ];
}
