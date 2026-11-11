<?php
// THE STEPS OF A SUPPORT PLAN, and where a child is up to in them.
//
// PROVENANCE: the two process documents were written by Emily Kite and provided
// 17 Sep 2026 - "Behaviour Support Plan (BSP) process" and "Individual Support
// Plan Process (ISP)", both kept verbatim in _incl/docs/support_plans/. Every
// step below is transcribed from them. THIS IS THE WHOLE REASON THE LADDER
// EXISTS AT ALL: support-plans.md recorded that a step list must not be invented
// and must be built only once the team named the steps and we recorded who said
// them and when. That has now happened. If the steps change, they change HERE,
// in one list, and the documents in docs/support_plans/ are replaced with them.
//
// A TRACKER, NOT A SYSTEM OF RECORD (Broc, 17 Sep 2026: "Purely a tracker,
// assume that all the steps needed to get a child on a BSP/ISP have already been
// completed if they are marked as on one"). So a step is a tick, a date, a
// person and an optional note - nothing more. The strategies, the goals, the
// Family Collaboration Form, the Challenging Behaviour Forms and the progress
// notes all live on paper in the room's Emergency Folder, and pulling them in
// here would be building a compliance system for educators rather than an
// enrolments tool.
//
// IT IS NOT A LADDER YOU CAN ONLY CLIMB. Three of the policy's own exits fire at
// ANY point: the child improves and the Area Manager ceases the plan, the child
// is released immediately over a risk of significant harm, or the family chooses
// to cease care. A plain progress bar would render the commonest GOOD outcome -
// improved in week 3 - as a process somebody abandoned. So an outcome can be
// recorded at any time and it closes the plan wherever it had got to.
//
// NOTHING IS COPIED. The flag on the placement is still the only record of who
// is on a plan; this stores the process state, which existed nowhere. See
// _incl/support-plans.php for why a table listing WHO is on a plan would have
// been the stale-copy failure this codebase has measured three times.

require_once __DIR__ . '/simple-mysqli.php';
require_once __DIR__ . '/audit.php';
require_once __DIR__ . '/dates.php';
require_once __DIR__ . '/rooms.php';      // room_type_key() - the age tier, from the room's first word
require_once __DIR__ . '/occupancy.php';  // bookings_year() - the TRUE first day, which the forward board cannot give

const PLAN_TYPES = [
    'bsp' => 'Behaviour Support Plan',
    'isp' => 'Individual Support Plan',
];

/**
 * The ISP review's booked day lives in support_plan_steps under this key. It is
 * NOT a step - plan_steps() never lists it, so nothing counts it, ticks it or
 * calls it overdue - but its day goes into the centre calendar exactly as a
 * step's does, and one table holding every booked day is what lets one sweep
 * keep them all in step. The same key the review page carries in the modal.
 */
const PLAN_REVIEW_KEY = '__review';

/**
 * THE BEHAVIOUR SUPPORT PLAN, step by step, from Emily Kite's process document.
 *
 * `week` is the policy's own week number and null before the plan commences.
 * NOTHING DATES THE FIRST FOUR STEPS, and that is the document's position rather
 * than an omission: a family meeting happens when it can be arranged. They are
 * chased by how long the child has been flagged, not by a date we made up.
 *
 * `anchor` marks the one step that starts the clock. The policy: "The BSP will
 * begin on the FIRST Monday after the families have been emailed the child's
 * BSP." Every dated step below is derived from that one date.
 */
function plan_steps_bsp(): array {
    return [
        ['key' => 'family_meeting', 'phase' => 'setup', 'week' => null,
         'title' => 'First family meeting',
         'who'   => 'Nominated Supervisor, with the Room Leader',
         'detail' => 'Held before the plan is written. The Area Manager coordinates the '
                   . 'planning with the Nominated Supervisor. In person or by video; if the '
                   . 'family cannot attend, the process and the plan go to them by email '
                   . 'instead. Conversations are documented on a Family Collaboration Form.'],

        ['key' => 'plan_created', 'phase' => 'setup', 'week' => null,
         'title' => 'Behaviour Support Plan written',
         'who'   => 'Nominated Supervisor, Area Manager and Room Leader',
         'detail' => 'Family and child input included where it was offered. The family\'s '
                   . 'signed Social Covenant is attached to the finished plan.'],

        ['key' => 'plan_conversation', 'phase' => 'setup', 'week' => null,
         'title' => '10 minute conversation with the family',
         'who'   => 'Nominated Supervisor and Room Leader',
         'detail' => 'The week after the plan is written. Tells the family the commencement '
                   . 'date and the strategies educators will be using.'],

        ['key' => 'plan_emailed', 'phase' => 'setup', 'week' => null, 'anchor' => true,
         'title' => 'Plan and process package emailed to the family',
         'who'   => 'Nominated Supervisor, Area Manager BCC\'d',
         'detail' => 'The child\'s plan, plus the Behaviour Support Plan Process package of '
                   . 'blank forms. THE PLAN COMMENCES THE FIRST MONDAY AFTER THIS EMAIL, so '
                   . 'this is the date every date below is worked out from.'],

        ['key' => 'week1_visit', 'phase' => 'weeks1_4', 'week' => 1, 'title' => 'An hour in the room',
         'who'   => 'Nominated Supervisor',
         'detail' => 'At least one hour on the floor, checking every educator is actually '
                   . 'running the plan\'s strategies.'],
        ['key' => 'week2_visit', 'phase' => 'weeks1_4', 'week' => 2, 'title' => 'An hour in the room',
         'who' => 'Nominated Supervisor', 'detail' => 'At least one hour on the floor.'],
        ['key' => 'week3_visit', 'phase' => 'weeks1_4', 'week' => 3, 'title' => 'An hour in the room',
         'who' => 'Nominated Supervisor', 'detail' => 'At least one hour on the floor.'],
        ['key' => 'week4_visit', 'phase' => 'weeks1_4', 'week' => 4, 'title' => 'An hour in the room',
         'who' => 'Nominated Supervisor', 'detail' => 'At least one hour on the floor.'],

        ['key' => 'review_1', 'phase' => 'week5', 'week' => 5,
         'title' => 'Review of the strategies, and the decision that follows',
         'who'   => 'Nominated Supervisor, Area Manager and Room Leader',
         'detail' => 'The Area Manager then decides one of two things: end the plan, in '
                   . 'which case a letter goes to the family, or write a second plan for '
                   . 'four more weeks and carry on to week 6.'],

        ['key' => 'family_meeting_2', 'phase' => 'weeks6_9', 'week' => 6,
         'title' => 'Second family meeting',
         'who'   => 'Area Manager, with the Nominated Supervisor',
         'detail' => 'Progress so far and the current impact on the child, their peers and '
                   . 'the educators. Options for continued care are discussed here, '
                   . 'including reduced hours and a reduction of days. External support too: '
                   . 'NDIS, specialist referral, Inclusion Support Funding. The family is '
                   . 'told plainly what happens at the week 10 review.'],

        ['key' => 'plan_2', 'phase' => 'weeks6_9', 'week' => 7,
         'title' => 'Second plan written, conversation offered, and emailed',
         'who'   => 'Nominated Supervisor, Area Manager and Room Leader',
         'detail' => 'Strategies updated or replaced with family and child input. A 10 '
                   . 'minute conversation is offered the following week, then the updated '
                   . 'plan goes to the family by email with the Area Manager BCC\'d.'],

        ['key' => 'week7_visit', 'phase' => 'weeks6_9', 'week' => 7, 'title' => 'An hour in the room',
         'who' => 'Nominated Supervisor', 'detail' => 'At least one hour on the floor.'],
        ['key' => 'week8_visit', 'phase' => 'weeks6_9', 'week' => 8, 'title' => 'An hour in the room',
         'who' => 'Nominated Supervisor', 'detail' => 'At least one hour on the floor.'],
        ['key' => 'week9_visit', 'phase' => 'weeks6_9', 'week' => 9, 'title' => 'An hour in the room',
         'who' => 'Nominated Supervisor', 'detail' => 'At least one hour on the floor.'],

        ['key' => 'review_2', 'phase' => 'weeks6_9', 'week' => 9,
         'title' => 'Review of the second plan and how well it worked',
         'who'   => 'Nominated Supervisor, Area Manager and Room Leader',
         'detail' => 'The last review before the decision.'],

        ['key' => 'decision', 'phase' => 'week10', 'week' => 10,
         'title' => 'The Area Manager decides',
         'who'   => 'Area Manager, after consulting the Nominated Supervisor and the room',
         'detail' => 'Continued care and the plan ends, or the child\'s care is released. '
                   . 'Record which below as the outcome.'],
    ];
}

/**
 * THE INDIVIDUAL SUPPORT PLAN, from Emily Kite's process document.
 *
 * NO WEEKS AND NO COMPUTED DATES, and that is the document, not a gap: "Monitor
 * and review. No specified date. Let them choose how often or if something comes
 * up they can do it." The ISP also RUNS FOR THE DURATION OF THE ENROLMENT rather
 * than finishing, so there is no week 10 and no ladder to the end of.
 *
 * Reviews are therefore not steps. The plan carries one date, next_review_on,
 * typed from the timeframe the ISP itself names - the team's own document is the
 * clock and the tool does not get to invent one.
 */
function plan_steps_isp(): array {
    return [
        ['key' => 'identified', 'week' => null,
         'title' => 'Need identified',
         'who'   => 'Nominated Supervisor',
         'detail' => 'Either a diagnosed disability picked up during enrolment, or an '
                   . 'assessment or diagnosis while the child is already with us.'],

        ['key' => 'family_meeting', 'week' => null,
         'title' => 'Collaborative family meeting',
         'who'   => 'Nominated Supervisor, with the Room Leader',
         'detail' => 'Strengths and interests first, then where support would help. Any '
                   . 'professional reports reviewed, and shared goals and strategies agreed. '
                   . 'External support is raised here: NDIS, specialist referral, Inclusion '
                   . 'Support Funding. External professionals may attend with family consent.'],

        ['key' => 'plan_created', 'week' => null,
         'title' => 'Individual Support Plan written',
         'who'   => 'Nominated Supervisor',
         'detail' => 'Needs, child profile, goals and strategies, with a risk assessment '
                   . 'developed alongside it.'],

        ['key' => 'plan_issued', 'week' => null,
         'title' => 'Plan emailed to the family and the Area Manager, and given to the room',
         'who'   => 'Nominated Supervisor',
         'detail' => 'The room\'s copy goes in the Emergency Contact Folder, tab 4.'],
    ];
}

/**
 * THE ROOM THE EARLY INTERVENTION APPLICATION IS MADE FROM.
 *
 * The ISP document's own pathway: LA -> RR -> SS -> **AA (application goes in,
 * around August/September)** -> MM (child starts attending) -> Primary School.
 * AA is Amazing Asteroids, and `room_type_key()` already resolves a room name to
 * its tier by the first word - measured over all 40 rooms 15 Sep 2026, which is
 * how it absorbs Tuggerah's "Amazing Asteroid Purple" and Warnervale's
 * "Amazing Asterouds", a typo in Kidsoft's own room name.
 *
 * MATCHING ON THE TIER RATHER THAN THE ROOM NAME is the whole reason this is one
 * line and not a list of room ids that goes stale the next time a centre renames
 * a room.
 */
const PLAN_EI_TIER = 'amazing';

/**
 * The steps of a plan, given what we know about where the child is.
 *
 * $ctx carries `room_tier` and `year`, and is what lets ONE step appear only
 * where it applies. Everything else in both ladders is unconditional.
 */
/**
 * THE PHASES OF A BSP, which are the process document's own Step 1 to Step 8
 * headings and not a grouping invented for the screen.
 *
 * They exist because sixteen flat rows say nothing about the SHAPE of a ten week
 * process - week 1 and week 10 look identical in a list - and because a phase
 * everybody has finished can then collapse to a single line. In week 8 that is
 * nine completed rows becoming three, leaving only live work on screen.
 *
 * AN ISP HAS NONE, and that is the document again: its four action steps are not
 * staged and carry no weeks at all, so a grouping would be decoration.
 *
 * @return array key => label, in order
 */
function plan_phases(string $type): array {
    if ($type === 'isp') return [];
    // `short` is for the stage strip along the top of the modal, where five full
    // labels will not fit at any modal width worth having. It is an abbreviation
    // of the same heading and never a different name for the stage.
    return [
        'setup'    => ['label' => 'Before it starts',                          'short' => 'Setup'],
        'weeks1_4' => ['label' => 'Weeks 1 to 4: the first plan',              'short' => 'Weeks 1-4'],
        'week5'    => ['label' => 'Week 5: review, and the decision that follows',
                       'short' => 'Week 5'],
        'weeks6_9' => ['label' => 'Weeks 6 to 9: the second plan',             'short' => 'Weeks 6-9'],
        'week10'   => ['label' => 'Week 10: the decision',                     'short' => 'Week 10'],
    ];
}

function plan_steps(string $type, array $ctx = []): array {
    if ($type !== 'isp') return plan_steps_bsp();

    $steps = plan_steps_isp();

    // THE ONE DATE IN THE WHOLE ISP DOCUMENT, and the one thing on this screen
    // that a family can permanently miss out on. It is NOT in the ISP's Action
    // Steps - it comes from "Transition to School & Accessing Early
    // Intervention", and it is here because the document says in its own words
    // that children miss out when it is left late: "sometimes only at school
    // orientation". Due 30 September, the end of the window the document names.
    //
    // ONLY IN THE AA ROOM. On a baby in Little Angels it would be a step nobody
    // can do for three years, sitting overdue, which is exactly how a list
    // teaches the team to ignore it.
    if (($ctx['room_tier'] ?? null) === PLAN_EI_TIER && !empty($ctx['year'])) {
        $steps[] = [
            'key' => 'early_intervention', 'week' => null,
            'due_date' => ((int)$ctx['year']) . '-09-30',
            'title' => 'Early intervention application for school',
            'who'   => 'Nominated Supervisor, with the family',
            'detail' => 'Applications for specialised early intervention classes go in around '
                      . 'August and September while the child is in this room. Places on the '
                      . 'Central Coast are limited and families may need to look across the '
                      . 'region, so this is the one date on an Individual Support Plan that '
                      . 'cannot be left late - the document warns that needs communicated only '
                      . 'at school orientation are communicated too late. Families apply '
                      . 'through the principal of their local public school, under Department '
                      . 'of Education Early Intervention Services.',
        ];
    }
    return $steps;
}

/**
 * What a placement tells us about which steps apply to it.
 *
 * Read from the row rather than posted, for the reason every id on this screen
 * is: a posted room tier would let somebody put the early intervention step on a
 * child who is not in that room.
 */
function plan_ctx(int $placementId): array {
    $r = dbx()->query(
        'SELECT p.year, r.name AS room_name, c.calendar_mailbox
           FROM placements p JOIN rooms r ON r.id = p.room_id
           JOIN centres c ON c.id = p.centre_id
          WHERE p.id = ?', [$placementId], 'i'
    )->fetch();
    if (!$r) return [];
    return ['year' => (int)$r['year'], 'room_tier' => room_type_key((string)$r['room_name']),
            'calendar' => !empty($r['calendar_mailbox'])];
}

/**
 * The ways a plan can end, all of which may be recorded at ANY point.
 *
 * The three sudden ones are the policy's, not ours: improvement, a risk of
 * significant harm, and the family's own decision. Two of them waive the four
 * week notice period, which the screen says because it is the part a person
 * reading this later will need and cannot work out from the label.
 */
function plan_outcomes(string $type): array {
    if ($type === 'isp') {
        return [
            'ended'  => ['label' => 'No longer required, plan ended',
                         'note'  => 'The child no longer needs the plan.'],
            'exited' => ['label' => 'Needs could not be safely met here, care ended',
                         'note'  => 'After every strategy, support and adjustment was '
                                  . 'reviewed and alternatives explored with the family.'],
        ];
    }
    return [
        'improved'  => ['label' => 'Behaviours improved, plan ended early',
                        'note'  => 'The Area Manager with the Nominated Supervisor may end '
                                 . 'the plan at any point once the behaviours have stopped.'],
        'continued' => ['label' => 'Week 10: care is sustainable, plan ended',
                        'note'  => 'Option 1 at the final review.'],
        'released'  => ['label' => 'Week 10: care released',
                        'note'  => 'Option 2 at the final review. The Area Manager takes the '
                                 . 'steps to end the child\'s care.'],
        'immediate' => ['label' => 'Released immediately, risk of significant harm',
                        'note'  => 'Available from any point. The four week notice period is '
                                 . 'waived.'],
        'ceased'    => ['label' => 'Family chose to cease care',
                        'note'  => 'The four week notice period is waived.'],
    ];
}

/** Sydney today, as Y-m-d. Never new Date() in the viewer's browser. */
function plan_today(): string {
    return date_create('today', new DateTimeZone('Australia/Sydney'))->format('Y-m-d');
}

/**
 * The FIRST Monday after the family was emailed the plan.
 *
 * STRICTLY after, so a plan emailed on a Monday commences the following one -
 * the policy says "the FIRST Monday after", and starting a child on a plan the
 * same morning their family was told about it is not what that sentence means.
 */
function plan_commencement(string $emailedOn): ?string {
    $d = date_create(substr($emailedOn, 0, 10), new DateTimeZone('Australia/Sydney'));
    if (!$d) return null;
    return $d->modify('+' . (8 - (int)$d->format('N')) . ' days')->format('Y-m-d');
}

/**
 * When a week-numbered step is due: the FRIDAY of its week.
 *
 * The policy dates most steps to a week rather than a day ("a review in week 5",
 * "Week 10 ... the Area Manager will decide"), and a centre week is Mon-Fri, so
 * the end of that week is the honest reading. Week 1 begins on commencement,
 * which is always a Monday.
 */
function plan_week_due(string $commencedOn, int $week): ?string {
    $d = date_create(substr($commencedOn, 0, 10), new DateTimeZone('Australia/Sydney'));
    if (!$d) return null;
    return $d->modify('+' . (($week - 1) * 7 + 4) . ' days')->format('Y-m-d');
}

/** Whole days from $date to today, negative when $date is still ahead. */
function plan_days_past(?string $date): ?int {
    if (!$date) return null;
    $tz  = new DateTimeZone('Australia/Sydney');
    $now = date_create('today', $tz);
    $was = date_create(substr($date, 0, 10), $tz);
    if (!$was) return null;
    return (int)$was->diff($now)->format('%r%a');
}

/*
 * BOOKED DAYS, AND THE CENTRE CALENDAR (29 Oct 2026).
 *
 * A step's DUE date is a deadline - the Friday of its week, or one somebody
 * typed. Its BOOKED day is when it is actually happening, and only a person can
 * know that: "by the end of week 6" says nothing about when the family can come
 * in. So it is typed, never worked out, and a booked day goes into the centre's
 * Outlook calendar as an all-day entry (support-plan-calendar.php).
 *
 * A DAY AND NEVER A TIME. Broc's answer, 29 Oct 2026, having first been offered
 * a timed appointment like a tour's.
 */

/** A posted booked day as stored: '' clears it, anything that is not a date is refused. */
function plan_booked_parse(string $s): ?string {
    $s = trim($s);
    if ($s === '') return null;
    $d = DateTimeImmutable::createFromFormat('!Y-m-d', substr($s, 0, 10));
    if (!$d || $d->format('Y-m-d') !== substr($s, 0, 10)) {
        throw new InvalidArgumentException('That booked day is not a date.');
    }
    return $d->format('Y-m-d');
}

/** Has the booked day been and gone? Sydney's today; a day is not over until it ends. */
function plan_booked_past(?string $on): bool {
    return $on !== null && substr($on, 0, 10) < plan_today();
}

/**
 * Should a booked day be in the calendar at all?
 *
 * Not once the plan has ended before it - nobody is coming, the rule a tour
 * follows when the family is lost before the visit. A day on or before the
 * outcome stays: recording the outcome the morning of the week 10 meeting must
 * not take that meeting out of the diary. TICKING THE STEP DONE DOES NOT REMOVE
 * IT, on purpose: a tick a day early would delete a meeting that is still
 * happening, and a stale entry is the cheaper mistake.
 *
 * (Whether the child is still flagged, and whether the centre has a calendar,
 * are the sync's to check - support-plan-calendar.php.)
 */
function plan_booking_wanted(?string $on, bool $closed, ?string $outcomeOn): bool {
    if ($on === null) return false;
    return !$closed || ($outcomeOn !== null && substr($on, 0, 10) <= substr($outcomeOn, 0, 10));
}

/**
 * The columns to write when $on replaces the booked day $existing holds.
 *
 * A PASSED APPOINTMENT IS HISTORY. Its Outlook entry is what the day was, so it
 * is never moved, edited or removed: rebooking a row whose day has gone LETS GO
 * of the old entry - the columns cleared, the entry left where it is - and the
 * new day gets an entry of its own. That is what makes the ISP review, one row
 * recurring for the life of the enrolment, leave a trail of reviews in the
 * diary rather than one entry dragged forward each time.
 */
function plan_booking_set(?array $existing, ?string $on): array {
    $set = ['booked_on' => $on];
    $was = $existing['booked_on'] ?? null;
    if ($existing && $was !== null && $was !== $on && plan_booked_past($was)
        && !empty($existing['calendar_event_id'])) {
        $set += ['calendar_event_id' => null, 'calendar_mailbox' => null, 'calendar_hash' => null,
                 'calendar_txn' => null, 'calendar_error' => null];
        audit('support_plan.calendar', 'support_plan_step', (int)$existing['id'], [
            'action' => 'kept', 'ok' => true, 'mailbox' => $existing['calendar_mailbox'],
            'booked_on' => $was, 'event_id' => $existing['calendar_event_id'],
        ]);
    }
    return $set;
}

/**
 * A booked day as the screen reads it, and whether it reached the calendar.
 *
 * `calendar` is null (nothing to say), 'pending' (wanted and not written yet -
 * the write runs a second after the save), 'in', or 'failed' with the reason in
 * words, the same states window.tourCalendarNote() already says for a tour.
 * A day more than one day gone that never got an entry says nothing: the sweep
 * has stopped chasing it, so "it will try again" would be untrue.
 */
function plan_booking_shape(?array $s, bool $closed, ?string $outcomeOn, bool $hasCalendar): array {
    $on = $s && $s['booked_on'] !== null ? substr((string)$s['booked_on'], 0, 10) : null;
    $cal = null;
    if ($on !== null) {
        $chased = $on >= date('Y-m-d', strtotime(plan_today() . ' -1 day'));
        $entry  = !empty($s['calendar_event_id']);
        // THE ERROR FIRST, as a tour's status reads it: an entry whose last
        // update failed is in Outlook with the OLD day on it. Except once the day
        // has passed - that entry is history and nothing will be sent again.
        if ($chased && !empty($s['calendar_error']) && !($entry && plan_booked_past($on))) {
            $cal = ['state' => 'failed', 'text' => $s['calendar_error']];
        } elseif ($entry) {
            $cal = ['state' => 'in'];
        } elseif ($chased && $hasCalendar && plan_booking_wanted($on, $closed, $outcomeOn)) {
            $cal = ['state' => 'pending'];
        }
    }
    return [
        'booked_on'      => $on,
        'booked_display' => $on ? syd_date($on) : null,
        'booked_past'    => plan_booked_past($on),
        'calendar'       => $cal,
    ];
}

/**
 * Read the plans for a set of placements in one go, shaped for the screen.
 *
 * TWO QUERIES FOR THE WHOLE LIST, not two per child. A plan is rare but the
 * screen is group-wide, and the n+1 this replaces is the kind that only shows up
 * once somebody flags a dozen children.
 *
 * @param int[] $placementIds
 * @return array placementId => ['bsp' => shape|null, 'isp' => shape|null]
 */
function plans_for_placements(array $placementIds, array $ctxById = []): array {
    $ids = array_values(array_unique(array_map('intval', $placementIds)));
    if (!$ids) return [];

    $marks = dbx()->whereIn($ids);
    $plans = dbx()->query(
        "SELECT * FROM support_plans WHERE placement_id IN($marks)", $ids
    )->fetchAll();
    if (!$plans) return [];

    $planIds = array_map(static fn($p) => (int)$p['id'], $plans);
    $sMarks  = dbx()->whereIn($planIds);
    $steps   = [];
    foreach (dbx()->query(
        "SELECT * FROM support_plan_steps WHERE plan_id IN($sMarks)", $planIds
    )->fetchAll() as $s) {
        $steps[(int)$s['plan_id']][$s['step_key']] = $s;
    }

    $out = [];
    foreach ($plans as $p) {
        $out[(int)$p['placement_id']][$p['plan_type']] =
            plan_shape($p, $p['plan_type'], $steps[(int)$p['id']] ?? [],
                       $ctxById[(int)$p['placement_id']] ?? []);
    }
    return $out;
}

/**
 * One plan as every screen reads it, including one that does not exist yet.
 *
 * A FLAGGED CHILD NOBODY HAS TOUCHED STILL HAS A LADDER, so $row may be null and
 * the shape comes back with every step blank. That is what stops the tool
 * writing a row for each of the two children who happen to be flagged today just
 * so a screen can render - the row is created by the first tick, and until then
 * there is genuinely nothing to store.
 */
function plan_shape(?array $row, string $type, array $stepRows, array $ctx = []): array {
    $commenced = $row['commenced_on'] ?? null;
    $commenced = $commenced ? substr((string)$commenced, 0, 10) : null;
    $outcome   = $row['outcome'] ?? null;
    $closed    = $outcome !== null;
    $outcomes  = plan_outcomes($type);
    $outcomeOn = isset($row['outcome_on']) && $row['outcome_on'] !== null
                   ? substr((string)$row['outcome_on'], 0, 10) : null;
    $calendar  = !empty($ctx['calendar']);

    $steps = [];
    $done = $overdue = 0; $worst = null; $next = null;
    foreach (plan_steps($type, $ctx) as $def) {
        $s  = $stepRows[$def['key']] ?? null;
        $on = $s && $s['done_on'] !== null ? substr((string)$s['done_on'], 0, 10) : null;

        // AN OVERRIDE, NOT A COPY. A stored due_on is somebody's correction; with
        // none, the date is derived from commencement every time it is read, so
        // fixing the commencement date moves the whole schedule and cannot
        // silently leave a hand-set date behind.
        $override = $s && $s['due_on'] !== null ? substr((string)$s['due_on'], 0, 10) : null;
        // A step may carry its own absolute date instead of a week number - the
        // early intervention application is due 30 September whatever else is
        // happening, because it answers to the school calendar and not to a plan.
        $due = $override
            ?? ($def['due_date'] ?? null)
            ?? (($commenced && $def['week'] !== null)
                    ? plan_week_due($commenced, (int)$def['week']) : null);

        // A CLOSED PLAN IS NEVER OVERDUE. The policy's own exits fire early, so
        // a plan ended in week 3 must not spend the rest of the year reporting
        // seven steps nobody did - that is the reading that would teach the team
        // to ignore the list.
        $late = (!$on && !$closed && $due !== null) ? plan_days_past($due) : null;
        $late = ($late !== null && $late > 0) ? $late : null;

        if ($on) $done++;
        if ($late !== null) { $overdue++; $worst = max($worst ?? 0, $late); }
        if (!$on && $next === null && !$closed) $next = $def['key'];

        $steps[] = [
            'key'          => $def['key'],
            'phase'        => $def['phase'] ?? null,
            'week'         => $def['week'],
            'title'        => $def['title'],
            'who'          => $def['who'],
            'detail'       => $def['detail'],
            'anchor'       => !empty($def['anchor']),
            'due_on'       => $due,
            'due_display'  => $due ? syd_date($due) : null,
            'due_override' => $override !== null,
            'done_on'      => $on,
            'done_display' => $on ? syd_date($on) : null,
            'done_by'      => $s['done_by'] ?? null,
            'note'         => $s['note'] ?? null,
            'overdue_days' => $late,
        ] + plan_booking_shape($s, $closed, $outcomeOn, $calendar);
    }

    // WHICH WEEK THE PLAN IS IN, worked out HERE and published, because the
    // browser must never subtract two Sydney date strings - a reader in another
    // timezone would get a different week to the person sitting next to them.
    // It is allowed past week 10: a plan nobody closed is a plan still running,
    // and clamping it would hide exactly the case the screen exists to catch.
    $weekNow = null;
    if ($commenced && !$closed) {
        $since = plan_days_past($commenced);
        $weekNow = $since === null ? null : (int)floor($since / 7) + 1;
        if ($weekNow < 1) $weekNow = null;   // commences in the future
    }

    $review = $row['next_review_on'] ?? null;
    $review = $review ? substr((string)$review, 0, 10) : null;
    $reviewLate = (!$closed && $review) ? plan_days_past($review) : null;

    return [
        'type'          => $type,
        'type_label'    => PLAN_TYPES[$type],
        'id'            => isset($row['id']) ? (int)$row['id'] : null,
        'commenced_on'  => $commenced,
        'commenced_display' => $commenced ? syd_date($commenced) : null,
        'next_review_on'      => $review,
        'next_review_display' => $review ? syd_date($review) : null,
        'review_overdue_days' => ($reviewLate !== null && $reviewLate > 0) ? $reviewLate : null,
        // The day the next review is booked for, which is not the day it is due
        // by - the same two dates a step carries. An ISP's only; null on a BSP.
        'review_booking' => $type === 'isp'
            ? plan_booking_shape($stepRows[PLAN_REVIEW_KEY] ?? null, $closed, $outcomeOn, $calendar) : null,
        'outcome'       => $outcome,
        'outcome_label' => $outcome !== null ? ($outcomes[$outcome]['label'] ?? $outcome) : null,
        'outcome_on'    => isset($row['outcome_on']) && $row['outcome_on'] !== null
                             ? substr((string)$row['outcome_on'], 0, 10) : null,
        'outcome_display' => isset($row['outcome_on']) && $row['outcome_on'] !== null
                             ? syd_date($row['outcome_on']) : null,
        'outcome_note'  => $row['outcome_note'] ?? null,
        'outcome_by'    => $row['outcome_by'] ?? null,
        'phases'        => plan_phases($type),
        'week_now'      => $weekNow,
        'closed'        => $closed,
        'steps'         => $steps,
        'done'          => $done,
        'total'         => count($steps),
        'overdue'       => $overdue,
        'worst_overdue' => $worst,
        'next'          => $next,
    ];
}

/** An empty plan of this type, for a flagged child nobody has recorded anything for. */
function plan_blank(string $type, array $ctx = []): array {
    return plan_shape(null, $type, [], $ctx);
}

/** The row, created on the first write and never before it. */
function plan_ensure(int $placementId, string $type): array {
    $row = dbx()->query('SELECT * FROM support_plans WHERE placement_id = ? AND plan_type = ?',
                        [$placementId, $type], 'is')->fetch();
    if ($row) return $row;

    dbx()->query('INSERT INTO support_plans (placement_id, plan_type) VALUES (?, ?)',
                 [$placementId, $type], 'is');
    $id = dbx()->insertId();
    audit('support_plan.open', 'support_plan', $id,
          ['placement_id' => $placementId, 'type' => $type]);
    return dbx()->query('SELECT * FROM support_plans WHERE id = ?', [$id], 'i')->fetch();
}

/**
 * Tick a step, untick it, date it, note it, or move when it is due.
 *
 * THE ANCHOR STEP MOVES THE WHOLE SCHEDULE. Ticking "plan emailed" sets the
 * commencement date, and unticking it takes every derived date away again rather
 * than leaving a schedule hanging off a date the team has just said is wrong.
 *
 * @param array $d done(bool), done_on, note, due_on ('' clears the override)
 */
function plan_step_save(int $placementId, string $type, string $stepKey, array $d): array {
    // VALIDATED AGAINST THE ROW'S OWN CONTEXT, so the early intervention step is
    // accepted for a child in an AA room and refused for everybody else - the
    // same list the screen rendered, derived the same way.
    $ctx  = plan_ctx($placementId);
    $defs = [];
    foreach (plan_steps($type, $ctx) as $def) $defs[$def['key']] = $def;
    if (!isset($defs[$stepKey])) throw new InvalidArgumentException('Unknown step.');

    $plan = plan_ensure($placementId, $type);
    $planId = (int)$plan['id'];

    $done   = !empty($d['done']);
    $doneOn = $done
        ? (($d['done_on'] ?? '') !== '' ? substr((string)$d['done_on'], 0, 10) : plan_today())
        : null;
    $note = trim((string)($d['note'] ?? ''));
    $note = $note === '' ? null : mb_substr($note, 0, 500);

    // '' is a deliberate clear, back to the date the policy implies; absent means
    // leave whatever is there alone.
    $dueGiven = array_key_exists('due_on', $d);
    $due = $dueGiven && (string)$d['due_on'] !== '' ? substr((string)$d['due_on'], 0, 10) : null;

    // The booked day, under the same rule: only touched when it was sent.
    $bookGiven = array_key_exists('booked_on', $d);
    $book = $bookGiven ? plan_booked_parse((string)$d['booked_on']) : null;

    $by = $done ? ($_SESSION['user']['email'] ?? null) : null;

    $existing = dbx()->query('SELECT * FROM support_plan_steps WHERE plan_id = ? AND step_key = ?',
                             [$planId, $stepKey], 'is')->fetch();
    $set = ['done_on' => $doneOn, 'done_by' => $by, 'note' => $note];
    if ($dueGiven)  $set['due_on'] = $due;
    if ($bookGiven) $set += plan_booking_set($existing ?: null, $book);
    plan_step_row_write($planId, $stepKey, $existing ?: null, $set);

    // THE STEP THAT STARTS THE CLOCK. "The BSP will begin on the FIRST Monday
    // after the families have been emailed the child's BSP" - so this one date
    // decides eleven others, and it is the only step whose tick changes anything
    // beyond its own row.
    if (!empty($defs[$stepKey]['anchor'])) {
        $commenced = $doneOn !== null ? plan_commencement($doneOn) : null;
        dbx()->query('UPDATE support_plans SET commenced_on = ? WHERE id = ?',
                     [$commenced, $planId], 'si');
    }

    audit('support_plan.step', 'support_plan', $planId, [
        'placement_id' => $placementId, 'type' => $type, 'step' => $stepKey,
        'done_on' => $doneOn, 'due_on' => $dueGiven ? $due : 'unchanged',
        'booked_on' => $bookGiven ? $book : 'unchanged',
    ]);

    return plan_read($placementId, $type);
}

/** Update a step row, or insert it with these columns - the row is created by its first write. */
function plan_step_row_write(int $planId, string $key, ?array $existing, array $set): void {
    if ($existing) {
        dbx()->query('UPDATE support_plan_steps SET '
            . implode(', ', array_map(static fn($c) => "{$c} = ?", array_keys($set))) . ' WHERE id = ?',
            array_merge(array_values($set), [(int)$existing['id']]));
        return;
    }
    $set = ['plan_id' => $planId, 'step_key' => $key] + $set;
    dbx()->query('INSERT INTO support_plan_steps (' . implode(', ', array_keys($set)) . ') VALUES ('
        . dbx()->whereIn(array_values($set)) . ')', array_values($set));
}

/**
 * Book the day of the ISP's next review, or clear it ('').
 *
 * Its own row under PLAN_REVIEW_KEY so it reaches the calendar the way a step's
 * day does; see that constant. An ISP's only - a BSP has no review page.
 */
function plan_review_book(int $placementId, string $type, string $on): void {
    if ($type !== 'isp') throw new InvalidArgumentException('Only an Individual Support Plan has a review to book.');
    $book = plan_booked_parse($on);
    $plan = plan_ensure($placementId, $type);
    $existing = dbx()->query('SELECT * FROM support_plan_steps WHERE plan_id = ? AND step_key = ?',
                             [(int)$plan['id'], PLAN_REVIEW_KEY], 'is')->fetch();
    if (($existing['booked_on'] ?? null) === $book) return;
    plan_step_row_write((int)$plan['id'], PLAN_REVIEW_KEY, $existing ?: null, plan_booking_set($existing ?: null, $book));
    audit('support_plan.review', 'support_plan', (int)$plan['id'],
          ['placement_id' => $placementId, 'type' => $type, 'booked_on' => $book]);
}

/** Record how the plan ended. Available at ANY point - three of the five are. */
function plan_outcome_save(int $placementId, string $type, string $outcome,
                           ?string $on, ?string $note): array {
    if (!isset(plan_outcomes($type)[$outcome])) {
        throw new InvalidArgumentException('Unknown outcome.');
    }
    $plan = plan_ensure($placementId, $type);
    $on   = ($on ?? '') !== '' ? substr((string)$on, 0, 10) : plan_today();
    $note = trim((string)$note);
    $note = $note === '' ? null : mb_substr($note, 0, 500);

    dbx()->query(
        'UPDATE support_plans SET outcome = ?, outcome_on = ?, outcome_note = ?, outcome_by = ?
          WHERE id = ?',
        [$outcome, $on, $note, $_SESSION['user']['email'] ?? null, (int)$plan['id']]
    );
    audit('support_plan.close', 'support_plan', (int)$plan['id'], [
        'placement_id' => $placementId, 'type' => $type,
        'outcome' => $outcome, 'outcome_on' => $on,
    ]);
    return plan_read($placementId, $type);
}

/** Undo a close. A wrong outcome must be correctable or it will not be recorded at all. */
function plan_outcome_clear(int $placementId, string $type): array {
    $plan = dbx()->query('SELECT * FROM support_plans WHERE placement_id = ? AND plan_type = ?',
                         [$placementId, $type], 'is')->fetch();
    if ($plan) {
        dbx()->query(
            'UPDATE support_plans SET outcome = NULL, outcome_on = NULL,
                    outcome_note = NULL, outcome_by = NULL WHERE id = ?',
            [(int)$plan['id']], 'i'
        );
        audit('support_plan.reopen', 'support_plan', (int)$plan['id'],
              ['placement_id' => $placementId, 'type' => $type]);
    }
    return plan_read($placementId, $type);
}

/**
 * When the ISP is next due to be reviewed.
 *
 * TYPED, NEVER COMPUTED. The policy gives no interval on purpose - the ISP names
 * its own - so this is the team copying their document in, and an empty value is
 * a legitimate answer meaning the plan says nothing.
 */
function plan_review_save(int $placementId, string $type, ?string $on): array {
    $plan = plan_ensure($placementId, $type);
    $on   = ($on ?? '') !== '' ? substr((string)$on, 0, 10) : null;
    dbx()->query('UPDATE support_plans SET next_review_on = ? WHERE id = ?',
                 [$on, (int)$plan['id']], 'si');
    audit('support_plan.review', 'support_plan', (int)$plan['id'],
          ['placement_id' => $placementId, 'type' => $type, 'next_review_on' => $on]);
    return plan_read($placementId, $type);
}

/** One plan, read back after a write. */
function plan_read(int $placementId, string $type): array {
    $ctx = plan_ctx($placementId);
    $row = dbx()->query('SELECT * FROM support_plans WHERE placement_id = ? AND plan_type = ?',
                        [$placementId, $type], 'is')->fetch();
    if (!$row) return plan_blank($type, $ctx);
    $steps = [];
    foreach (dbx()->query('SELECT * FROM support_plan_steps WHERE plan_id = ?',
                          [(int)$row['id']], 'i')->fetchAll() as $s) {
        $steps[$s['step_key']] = $s;
    }
    return plan_shape($row, $type, $steps, $ctx);
}

/**
 * "Educators will not begin the Behaviour Support Process (BSP) within the first
 * 4 weeks of the year or when a child changes rooms to allow any children to
 * transition and settle into new learning environments."
 *   - Emily Kite's BSP process document, 17 Sep 2026.
 *
 * WARNS, NEVER BLOCKS, the rule every other check on this board follows. The
 * centre knows things the tool does not, and a rule that stops the team
 * recording what is actually happening gets worked around rather than followed.
 *
 * FIRED ONLY WHEN THE FLAG IS NEWLY TICKED. Ticking it is the only moment the
 * tool learns a plan is beginning, and an alert that re-fires on every later save
 * of the same child is a border, not an alert - the fault `.cp-year` was rebuilt
 * to remove.
 *
 * BOTH DATES ARE MEASURED, NEITHER IS ASSUMED. The year's first day is the
 * earliest permanent booking anybody in the centre holds - 5 January in Tuggerah
 * 2026, NOT 1 January, so a calendar constant would have under-warned by the best
 * part of a week. The child's own first day is the earliest permanent booking
 * they hold IN THIS ROOM, which is what makes a room change visible at all.
 *
 * IT HAS TO BE bookings_year() AND NOT THE BOARD. board_load()'s roster is
 * bounded by TODAY, so every child's `first` reads as today however long ago they
 * actually started - measured 17 Sep 2026, all 301 Tuggerah children read
 * 2026-09-17. reenrolment.php hit this exact trap and documents it. The cost is
 * twelve monthly reads against the board's four, which is why this is gated on
 * the tick and not run on the list.
 *
 * A PROVISIONAL CHILD IS SKIPPED, not guessed at. No ChildID means no bookings to
 * read, and "we cannot tell" must not render as "this is fine".
 *
 * @param array $d centre_id, year, kidsoft_child_id, kidsoft_room_id, child_name, room_name
 * @return string[] warnings, in the same shape save_placement already toasts
 */
function plan_settling_warnings(array $d): array {
    $ksId    = (string)($d['kidsoft_child_id'] ?? '');
    $ksRoom  = (string)($d['kidsoft_room_id'] ?? '');
    $year    = (int)($d['year'] ?? 0);
    if ($ksId === '' || $ksRoom === '' || !$year) return [];

    $centre = centre_get((int)($d['centre_id'] ?? 0));
    $svc    = $centre['kidsoft_service_id'] ?? null;
    if (!$svc) return [];

    try {
        $by = bookings_year($svc, $year);
    } catch (Throwable $e) {
        // Kidsoft would not answer. The flag still saves; this check is advice.
        return [];
    }
    $perm = $by['permanent'] ?? [];
    $out  = [];

    $name = trim((string)($d['child_name'] ?? '')) ?: 'This child';
    $room = trim((string)($d['room_name'] ?? '')) ?: 'this room';

    // (a) THE FIRST FOUR WEEKS OF THE YEAR. Only asked about the year we are
    // actually in - flagging a 2027 placement in September 2026 is planning
    // ahead, and "the year has only just started" would be nonsense there.
    if ($year === (int)substr(plan_today(), 0, 4)) {
        $yearFirst = null;
        foreach ($perm as $byRoom) {
            foreach ($byRoom as $wds) {
                foreach ($wds as $sp) {
                    $f = substr((string)($sp['first'] ?? ''), 0, 10);
                    if ($f !== '' && ($yearFirst === null || $f < $yearFirst)) $yearFirst = $f;
                }
            }
        }
        $since = $yearFirst === null ? null : plan_days_past($yearFirst);
        if ($since !== null && $since < 28) {
            $out[] = $centre['name'] . ' only came back on ' . syd_date($yearFirst)
                . '. The process document says a Behaviour Support Plan is not begun in the '
                . 'first 4 weeks of the year, to let children settle.';
        }
    }

    // (b) FOUR WEEKS IN THIS ROOM. `< 28` and not `between 0 and 28`, because a
    // child whose first day here is still ahead of them has settled even less
    // than one who arrived last week.
    $childFirst = null;
    foreach (($perm[$ksId][$ksRoom] ?? []) as $sp) {
        $f = substr((string)($sp['first'] ?? ''), 0, 10);
        if ($f !== '' && ($childFirst === null || $f < $childFirst)) $childFirst = $f;
    }
    $inRoom = $childFirst === null ? null : plan_days_past($childFirst);
    if ($inRoom !== null && $inRoom < 28) {
        $out[] = $name . ($inRoom < 0 ? ' does not start in ' : ' has only been in ') . $room
            . ($inRoom < 0 ? ' until ' : ' since ') . syd_date($childFirst)
            . '. The process document says a Behaviour Support Plan is not begun within 4 weeks '
            . 'of a child changing rooms, to let them settle into a new environment.';
    }

    return $out;
}
