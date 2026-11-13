<?php
// Waitlist and offers.
//
// CORRECTED 3 Aug 2026. This header used to read "the Kidsoft waitlist API is
// effectively anonymous - it carries no name fields at all ... so nothing here
// can be imported". That was measured WITHOUT the endpoint's four detail
// options, and it was wrong: with WithDetails/WithRequestedDays/WithOfferedDays/
// WithCareTypeMap, Waitlist/getWaitingList hands over names, DOBs, requested
// weekdays and parent mobiles at ~100% coverage. _incl/waitlist-import.php has
// fed this table since 31 Jul 2026 and the queue holds 708 real families where
// it held nothing.
//
// So the ownership line is NOT "we type everything" but: **Kidsoft owns what the
// family declared, we own every decision.** waitlist_applications is a CACHE of
// the former and the SYSTEM OF RECORD for the latter - the priority ladder,
// offers, the enquiry funnel and the contact log. WAITLIST_KIDSOFT_OWNED in
// _incl/waitlist-import.php is the one place that split is written down; read
// that file's header before changing any of this.

require_once __DIR__ . '/simple-mysqli.php';
require_once __DIR__ . '/board.php';   // board_load() - the one occupancy figure
require_once __DIR__ . '/rooms.php';
require_once __DIR__ . '/drift.php';      // name_key() - exact folding, no distance
require_once __DIR__ . '/identity.php';   // kidsoft_children()
require_once __DIR__ . '/siblings.php';   // sibling_matches_for_centre() - read, not stored
require_once __DIR__ . '/do-not-enrol.php';   // waitlist_attach_dne() - a Kidsoft tag, read, not stored
require_once __DIR__ . '/waitlist-duplicates.php';   // one child applied for twice at one centre

/** Priority of Access categories (the statutory ladder). */
const POA_LABELS = [
    1 => 'At risk',
    2 => 'Work / study test',
    3 => 'Other',
];

const WAITLIST_STATUS = [
    'waitlisted'       => 'Waitlisted',
    'offered'          => 'Offered',
    'partially_placed' => 'Partially placed',
    'placed'           => 'Placed',
    'withdrawn'        => 'Withdrawn',
    'expired'          => 'Expired',
    // `archived` IS DELIBERATELY NOT HERE, and this is the only thing stopping
    // it being set by hand: api/waitlist.php's set_status refuses any status
    // missing from this map. Archiving is Kidsoft's act, inferred from the row
    // disappearing out of getWaitingList, so there is nothing for a human to
    // choose - and a hand-archived row would come straight back as `waitlisted`
    // on the next import, because the reconciliation only ever looks at rows
    // Kidsoft has stopped sending. See waitlist-import.php.
];

/**
 * What to call one application's status on screen.
 *
 * ONE OF OUR STATUSES IS TWO OF KIDSOFT'S. `expired` is where both
 * `ExpiredWaitlist` and `ExpiredOffer` land, because both mean the same thing to
 * every engine here - the application is closed, it holds no queue position and
 * it holds no place. They do NOT mean the same thing to the team:
 *
 *   * a waiting place expires when a parent stops answering Kidsoft's reminder
 *     emails, which is attrition;
 *   * an OFFER expires when a place was put in front of a family and nobody
 *     closed it out, which is this team's own work coming back.
 *
 * Broc, 9 Sep 2026: "Offer Expired are not showing up in the waitlist." They
 * were two ways at once - five of the ten group-wide were never imported at all
 * (fixed in waitlist-import.php) and the other five read "Expired", the same
 * word as the 496 lapsed waiting places they sit among. So the LABEL splits
 * where the status deliberately does not.
 *
 * `kidsoft_status` is the raw Kidsoft value on the last sync, and it is null on
 * a hand-keyed row - which has no Kidsoft status to have, and falls through to
 * the plain label. Everything else on this screen reads `status`; only the words
 * change here.
 */
function waitlist_status_label(array $w): string {
    if (waitlist_is_offer_expired($w)) return 'Offer expired';
    // AN OFFER SENT IN KIDSOFT (15 Oct 2026). Our status stays `waitlisted` --
    // there is no `offers` row - but the family is not waiting in the queue any
    // more, and calling them Waitlisted is what made Stella Knox's row look like
    // nobody had acted. Kidsoft's words, in the team's spelling.
    if (waitlist_kidsoft_offer_open($w)) return KIDSOFT_OPEN_OFFER[$w['kidsoft_status']]['label'];
    $status = (string)($w['status'] ?? '');
    return WAITLIST_STATUS[$status] ?? $status;
}

/**
 * Is this application one where a place was OFFERED and the offer lapsed?
 *
 * THREE CALLERS AND ONE DEFINITION: the label above, the "Offer expired"
 * segment's filter in waitlist_for_centre(), and the group register's family
 * narrowing. Two copies of a rule is how they drift, and this one is a
 * two-column test that reads as an implementation detail everywhere it appears.
 */
function waitlist_is_offer_expired(array $w): bool {
    return ($w['status'] ?? '') === 'expired'
        && ($w['kidsoft_status'] ?? null) === 'ExpiredOffer';
}

const OFFER_STATUS = [
    'pending'            => 'Pending',
    'accepted'           => 'Accepted',
    'partially_accepted' => 'Partially accepted',
    'declined'           => 'Declined',
    'expired'            => 'Expired',
    'withdrawn'          => 'Withdrawn',
];

/** How long an offer stands before it lapses, unless overridden case by case. */
const OFFER_DEFAULT_DAYS = 14;

/**
 * Normalise what the Expires field sent into a stored Sydney datetime.
 *
 * ONE FUNCTION BECAUSE THERE ARE TWO DOORS. `create_offer` and `extend_offer`
 * both take this field and both used to check only that it PARSED, so an offer
 * could be written already dead and "extend" could silently shorten a live one
 * to nothing. Two copies of a rule is how they drift, and this rule was missing
 * from both.
 *
 * WHAT IT COST, measured 3 Sep 2026: **3 of the 7 offers ever made were born
 * expired** - Marli Kerslake once and Gus Mclean twice, inside seven minutes,
 * all with an expiry ten days in the past. The screen said "Offer sent. It
 * expires 24/08/2026." and then `offers_expire_due()` killed each one on the
 * next page load, reset the application from `offered` back to `waitlisted` and
 * pushed the work item back from `offered` to `contacted`. Nothing reads a
 * non-pending offer row, so the queue showed an untouched-looking family and the
 * only surviving evidence was the audit log. **The same staffer made the same
 * mistake three times in a row, which says the form invited it.**
 *
 * A BARE DATE RUNS TO THE END OF ITS DAY. The field is `<input type="date">`, so
 * it sends `2026-09-17` and `date_create()` makes that MIDNIGHT - the first
 * instant of the day, not the last. Picking today therefore produced an expiry
 * that had passed before lunch, and "expires on the 17th" gave the family none
 * of the 17th. The empty-field default keeps the time of day (`now + 14 days`),
 * so the two paths disagreed about what a date means; they no longer do. A value
 * that carries its own time is left alone.
 *
 * The timezone is Sydney because `_incl/dates.php` pins it and every entry point
 * reaches it through `auth.php` - so `time()` here is the same wall clock the
 * value is compared against and stored in.
 *
 * @param  string      $raw   what the form sent
 * @param  string|null $error set to a message the user can act on when null is returned
 * @return string|null 'Y-m-d H:i:s', or null when it must be refused
 */
function offer_expiry_normalise(string $raw, ?string &$error = null): ?string {
    $error = null;
    $raw = trim($raw);
    // date_create('') is NOW, not a failure, so the empty case cannot be left to
    // the parse check below - it would quietly produce an already-passed expiry.
    if ($raw === '') {
        $error = 'Give an expiry date.';
        return null;
    }
    $dt = date_create($raw);
    if (!$dt) {
        $error = 'That expiry date is not valid.';
        return null;
    }
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw)) $dt->setTime(23, 59, 59);
    if ($dt->getTimestamp() <= time()) {
        $error = 'That expiry has already passed. Give a date in the future.';
        return null;
    }
    return $dt->format('Y-m-d H:i:s');
}

/**
 * An application added within this many days is "Newly added" - the Waitlist's
 * tile, and the "new" tag under the row's date added.
 *
 * IT REPLACED "EXPIRING SOON" (11 Nov 2026, Broc: the "Waitlist expire" option
 * is turned off in Kidsoft). That tile counted applications within 30 days of
 * Kidsoft's ExpiryDate, and with the clock off it counted a lapse that will not
 * happen: 229 of 703 open Waitlisted rows still carried an old ExpiryDate that
 * day, 52 inside the 30 days. New applications had mostly stopped getting one
 * already (Sep 6 of 41, Oct 4 of 56, Nov 1 of 27).
 *
 * 14 days: a fortnight is what survives somebody being away for a week. That
 * day it was 1 to 7 per trading centre and 36 group-wide.
 */
const WAITLIST_NEW_DAYS = 14;

/**
 * The first date that counts as newly added, as yyyy-mm-dd. Decided HERE, in
 * Sydney, and published to the browser, which compares it against the row's
 * date_added as a plain string - no new Date() anywhere near it
 * (_incl/dates.php).
 */
function waitlist_new_since(): string {
    return date('Y-m-d', strtotime('-' . WAITLIST_NEW_DAYS . ' days'));
}

/**
 * Sort key for the waitlist queue, lowest first.
 *
 * The ladder, in order: re-enrolments, then staff children, then families ALREADY
 * IN CARE, then Priority of Access (1 at-risk before 2 work/study before 3 other),
 * then siblings ahead of external families, and finally first-come-first-served on
 * date added.
 *
 * Returned as an array so it can be compared element by element and shown to
 * staff - a queue nobody can explain is a queue nobody trusts.
 */
function priority_rank(array $w): array {
    return [
        $w['is_reenrolment'] ? 0 : 1,
        $w['is_staff_child'] ? 0 : 1,
        // FAMILIES ALREADY IN CARE HAVE PREFERENCE (Broc, 7 Aug 2026) - for extra
        // days and for next year. Added because 35 of 722 open applications
        // (4.8%) belong to a child who is ALREADY ENROLLED: 17 at the same centre
        // wanting more days or next year, 18 transferring from another Little
        // Miracles centre. Nothing ranked them, so they queued behind external
        // families purely on date_added, which inverts the policy. Above sibling
        // because the child themselves being in care is a stronger claim than
        // having a brother or sister who is.
        !empty($w['in_care']) ? 0 : 1,
        // AND ABOVE PRIORITY OF ACCESS SINCE 11 Aug 2026 (Broc's call). This used
        // to sit one rung higher, carrying the comment "statutory, so it stays
        // above anything this organisation decides" - and the consequence was
        // that an external family on the activity test outranked a family already
        // in the room asking for one more day. Measured before the change:
        // **28 of the 34 in-care families group-wide were outranked by at least
        // one of them**, all 12 at Tuggerah, so it was the normal case rather
        // than an edge one.
        //
        // WHAT MADE IT AN EASY CALL IS THE FIELD ITSELF, not a view about the
        // statute. Censused live over 709 open Kidsoft applications:
        // **600 (85%) carry no PriorityOfAccess at all**, 83 read 2, 23 read 1,
        // and 3 read 5 (off the end of the ladder - see waitlist-import.php,
        // which refuses to guess). So "Other" is overwhelmingly an ABSENCE of a
        // Kidsoft answer rather than a judgement that the family is low priority,
        // and the 83 are the families who happened to mention it, not the
        // families who meet the test. Ranking a policy above a field that blank
        // is not honouring the policy, it is honouring the data entry. It is also
        // seeded on insert and never re-read, so it is a snapshot besides.
        //
        // If the field ever gets filled in reliably, this is the rung to revisit.
        (int)$w['priority_of_access'],
        // DETECTED OR TICKED, and the detection is the one that fires in practice
        // - measured 11 Aug 2026, 100 applications detected and zero ever ticked.
        // This used to read the column alone, so a Kidsoft-visible sibling only
        // reached the queue if somebody pressed a bulk button, which nobody ever
        // did. See _incl/siblings.php for the whole measurement; the column stays
        // as an additive manual override for a sibling the match cannot see.
        (!empty($w['sibling_match']) || $w['is_sibling']) ? 0 : 1,
        $w['date_added'],
    ];
}

/**
 * Flag applications whose child is ALREADY an active Kidsoft child anywhere in
 * the group, so priority_rank() can give them preference.
 *
 * Matched on folded name + DOB against ACTIVE children only. Deliberately
 * stricter than siblings.php's phone/email rule, because this promotes a family
 * over others who applied earlier - the same asymmetry name_is_near() is built
 * on. A miss costs a family their preference (recoverable, and visible on the
 * screen); a false match moves someone up the queue who should not be there.
 *
 * `is_reenrolment`, the rung above, is set on ZERO rows group-wide - nothing has
 * ever written it. Left in place rather than removed: the re-enrolment round runs
 * in Kidsoft, so if it ever does feed this table that rung is where it belongs.
 *
 * A DOB A FEW DAYS OUT IS STILL THE CHILD, WHEN A PARENT IS SHARED TOO (12 Nov
 * 2026). Broc, on Sunny Trinca: waiting at Point Clare, enrolled at Kanwal, and
 * flagged nowhere - *"a lot of families put the estimated DOB for unborn babies
 * when submitting the waitlist ... Maybe within a 3 month window?"* The
 * application said 22/11/2024 and Kidsoft's child record 25/11/2024, so this
 * missed her, and siblings.php dropped her as well, because a same-named child
 * is the applicant themselves and not a brother or sister. She fell between
 * the two rules and read "Standard".
 *
 * So the name still has to match exactly, and a DOB within
 * WAITLIST_IN_CARE_DOB_DAYS counts when the application's email or mobile also
 * reaches THAT ChildID through siblings.php's parent index. That index is the
 * strict one (enrolling guardian, mother or father; no work numbers), and the
 * extra test is the waitlist family merge rule's "both": name + DOB alone has
 * merged different families before. Measured 12 Nov 2026 over 885 open
 * applications against every active child: an exact DOB matched 6, all
 * sharing a parent; a DOB 1 to 92 days out matched 2, Sunny Trinca and Finley
 * Evans (Terrigal, enrolled at Wamberal), both 3 days out and both sharing a
 * parent; and NO same-named child of another family at any gap. So the
 * contact test costs nothing today, and it is there for the first Oliver Smith
 * born a month after another one.
 */
const WAITLIST_IN_CARE_DOB_DAYS = 92;

function waitlist_mark_in_care(array $rows): array {
    if (!$rows) return $rows;
    static $index = null;
    // WHICH RECORD, beside which centre (6 Nov 2026): the same matches, with the
    // centre id and the ChildID Kidsoft issued there, so "Already in care at
    // Point Clare" can open that child's dialog. A second map rather than a
    // change to $index, which in_care_at and nothing else reads.
    static $who = null;
    // By name alone, for the near-DOB match above: name_key => [[dob, key], ...]
    static $byName = null;
    static $services = [];
    if ($index === null) {
        $index = $who = $byName = [];
        foreach (centres_all() as $c) {
            $services[$c['name']] = $c['kidsoft_service_id'];
            try { $kids = kidsoft_children($c['kidsoft_service_id']); }
            catch (Throwable $e) { continue; }   // an outage must not reorder the queue
            foreach ($kids as $k) {
                if (empty($k['Active'])) continue;
                $nk  = name_key((string)($k['FirstName'] ?? ''), (string)($k['LastName'] ?? ''));
                $dob = substr((string)($k['DOB'] ?? ''), 0, 10);
                $ik  = $nk . '|' . $dob;
                if (!isset($index[$ik]) && $dob !== '') $byName[$nk][] = ['dob' => $dob, 'key' => $ik];
                $index[$ik][] = $c['name'];
                $who[$ik][] = ['centre' => $c['name'], 'centre_id' => (int)$c['id'],
                               'ks_id' => (string)($k['ChildID'] ?? '')];
            }
        }
    }
    $booked = enrolled_days_index();
    foreach ($rows as &$w) {
        $nk  = name_key((string)$w['child_first_name'], (string)$w['child_last_name']);
        $dob = substr((string)$w['child_dob'], 0, 10);
        $key = $nk . '|' . $dob;
        $at = $w['child_dob'] ? ($index[$key] ?? null) : null;
        $kids = $at ? $who[$key] : [];
        // WHICH ENROLMENT'S KEY the green days are read under: Kidsoft's DOB,
        // which on a near match is not the one the family typed.
        $keys = $at ? [$key] : [];
        if (!$at && $dob !== '' && isset($byName[$nk])) {
            foreach ($byName[$nk] as $cand) {
                if (abs(strtotime($cand['dob']) - strtotime($dob)) / 86400 > WAITLIST_IN_CARE_DOB_DAYS) continue;
                foreach ($who[$cand['key']] as $i => $c) {
                    if (!waitlist_shares_parent($w, $c['ks_id'], $services)) continue;
                    $kids[] = $c;
                    $at[]   = $index[$cand['key']][$i];
                    $keys[$cand['key']] = $cand['key'];
                }
            }
        }
        $w['in_care']    = (bool)$at;
        $w['in_care_at'] = $at ? implode(', ', array_unique($at)) : null;
        $w['in_care_children'] = $kids;
        // WHICH DAYS THEY ALREADY HOLD AT THE CENTRE THEY ARE APPLYING TO. See
        // enrolled_days_index() for why this is the centre's own bookings and not
        // the group's.
        $days = [];
        foreach ($keys as $k) $days = array_merge($days, $booked[$k][(int)$w['centre_id']] ?? []);
        $days = array_values(array_unique($days));
        sort($days);
        $w['attending_days'] = $days;
    }
    unset($w);
    return $rows;
}

/**
 * Does this application's email or mobile reach this enrolled ChildID through a
 * parent? siblings.php's index, so the same parent filter decides it.
 */
function waitlist_shares_parent(array $w, string $ksId, array $services): bool {
    if ($ksId === '') return false;
    try { $fam = enrolled_family_index($services); }
    catch (Throwable $e) { return false; }
    $lists = [];
    if (($e = sibling_email_key($w['guardian_email'] ?? null)) !== null) $lists[] = $fam['email'][$e] ?? [];
    if (($p = sibling_phone_key($w['guardian_phone'] ?? null)) !== null) $lists[] = $fam['phone'][$p] ?? [];
    foreach ($lists as $list) {
        foreach ($list as $c) if ($c['kidsoft_child_id'] === $ksId) return true;
    }
    return false;
}

/**
 * The weekdays every enrolled child in the group is ALREADY booked for, by centre.
 *
 * Feeds the green days on the queue: a family already in care asking for more days
 * is 17 of the 714 open applications, and their row used to show five badges that
 * could not tell the days they hold from the days they are asking for. The purple
 * ones now mean what they should always have meant - **the days that are actually
 * being asked for and are not already theirs.** Four of the seventeen turn out to
 * be asking for nothing they do not already have, which is visible at a glance
 * once the row has no purple left on it.
 *
 * PER CENTRE, AND ONLY THE APPLICATION'S OWN CENTRE IS GREEN. 15 further
 * applications belong to a child attending a DIFFERENT Little Miracles centre, and
 * a Monday held at Point Clare is not a Monday held at Tuggerah - it is a
 * transfer, and the day is still a place this centre has to sell. Colouring it
 * would hide real demand. That family is not left unexplained: `in_care_at`
 * already prints "Already in care at Point Clare" in the Priority column.
 *
 * READ FROM board_live_children(), which is the app's one definition of the
 * standing weekly arrangement - so this cannot disagree with the Placement board
 * about which days a child holds, and casual bookings are excluded there rather
 * than tested again here. Current year: the question is what they attend now.
 *
 * COST: 0.06s for all seven centres on a warm cache, because the board reads ONE
 * reference month per centre and the current year's copy is refreshed every 15
 * minutes (hourly warm in cron-nightly.php). A cold cache costs nothing visible
 * either - kidsoft_cached() serves the stale copy and refreshes after
 * fastcgi_finish_request(), and board_live_children() returns an empty centre
 * rather than throwing when there is no copy at all. The badge is then simply not
 * green, which is the same way in_care and the sibling match degrade.
 *
 * @return array<string,array<int,int[]>> "name|dob" => centre id => weekdays
 */
function enrolled_days_index(): array {
    static $index = null;
    if ($index !== null) return $index;

    $index = [];
    $year  = board_default_year();
    foreach (centres_all() as $c) {
        try { $live = board_live_children((int)$c['id'], $year); }
        catch (Throwable $e) { continue; }
        foreach ($live['rooms'] as $kids) {
            foreach ($kids as $k) {
                if ($k['dob'] === null) continue;   // nothing to match on
                $key = name_key((string)$k['first'], (string)$k['last']) . '|' . $k['dob'];
                foreach ($k['days'] as $d) $index[$key][(int)$c['id']][(int)$d] = true;
            }
        }
    }
    // A child can hold days in two rooms of one centre (they move up mid-year),
    // so the weekdays are unioned per centre and then sorted.
    foreach ($index as $key => $byCentre) {
        foreach ($byCentre as $cid => $days) {
            $d = array_keys($days);
            sort($d);
            $index[$key][$cid] = $d;
        }
    }
    return $index;
}

/**
 * Attach detected siblings to a centre's rows, BEFORE ranking.
 *
 * The same shape as waitlist_mark_in_care() one rung above it: a fact Kidsoft
 * holds, read on every load rather than copied into a column somebody has to
 * maintain. See _incl/siblings.php for why that changed and what it measured.
 *
 * A Kidsoft outage costs the preference for the length of the outage rather than
 * blanking the queue - again as in_care already does. Yesterday's order is not
 * available to fall back on, because there is nothing stored to fall back to.
 */
function waitlist_attach_siblings(int $centreId, array $rows): array {
    $matches = [];
    try {
        $matches = sibling_matches_for_centre($centreId);
    } catch (Throwable $e) {
        $matches = [];
    }
    foreach ($rows as &$w) {
        $w['sibling_match'] = $matches[(int)$w['id']] ?? null;
    }
    unset($w);
    return $rows;
}

/** Plain-English reasons this application sits where it does in the queue. */
function priority_reasons(array $w): array {
    return priority_reasons_text(priority_reason_parts($w));
}

/** The sentences, from the parts - see priority_reason_parts(). */
function priority_reasons_text(array $parts): array {
    return array_map(static fn(array $p) => implode('', array_column($p, 't')), $parts);
}

/**
 * The same reasons as PIECES, so a screen can make the children they name open
 * their own dialog (6 Nov 2026, Broc: names "mentioned or listed" everywhere
 * should open the child). Each reason is a list of ['t' => text] pieces; a piece
 * naming an enrolled child also carries 'child' => [centre_id, ks_id], and only
 * when this user may open that centre. priority_reasons() joins the text, so
 * the wording has ONE definition and every reader that wants a sentence - the
 * group register, search - still gets exactly the sentence it always did.
 */
function priority_reason_parts(array $w): array {
    $out = [];
    // Named so a human can see what the machine saw - the siblings.php pattern.
    // THE CENTRE OPENS THE CHILD: this application's own child, already enrolled
    // there. In centre order, once per centre, exactly as in_care_at reads.
    if (!empty($w['in_care'])) {
        $p = [['t' => 'Already in care at ']];
        $seen = [];
        foreach ($w['in_care_children'] ?? [] as $c) {
            if (isset($seen[$c['centre']])) continue;
            if ($seen) $p[] = ['t' => ', '];
            $seen[$c['centre']] = true;
            $p[] = ['t' => $c['centre']] + (user_can_centre($c['centre_id']) && $c['ks_id'] !== ''
                ? ['child' => ['centre_id' => $c['centre_id'], 'ks_id' => $c['ks_id']]] : []);
        }
        // No record list (a caller that did not run waitlist_mark_in_care()): the
        // sentence it always was, as one piece.
        if (!$seen) $p = [['t' => 'Already in care at ' . $w['in_care_at']]];
        $out[] = $p;
    }
    if ($w['is_reenrolment']) $out[] = [['t' => 'Re-enrolment']];
    if ($w['is_staff_child']) $out[] = [['t' => "Staff's child"]];
    if ((int)$w['priority_of_access'] < 3) $out[] = [['t' => POA_LABELS[(int)$w['priority_of_access']]]];
    // NAME THE SIBLING, for the reason in_care names the centre: this promotes a
    // family over others who applied earlier, and it now does so without anybody
    // pressing a button, so the row has to say who it matched. Naming the CENTRE
    // only when it is a different one - at their own centre it is noise, and 75
    // of the 100 live matches are same-centre.
    if (!empty($w['sibling_match'])) {
        $m = $w['sibling_match'];

        // A SIBLING AT A CENTRE THIS USER DOES NOT HOLD HAS NO NAME (19 Aug 2026).
        // siblings.php nulls it, and the reason names that centre instead. The
        // MATCH still has to appear: it is what promotes this family in the
        // supervisor's own queue, and a row that jumps the queue for no stated
        // reason is the one thing this function exists to prevent.
        $named   = array_values(array_filter($m['siblings'],
                       static fn($s) => ($s['name'] ?? null) !== null));
        $unnamed = array_values(array_unique(array_column(array_filter($m['siblings'],
                       static fn($s) => ($s['name'] ?? null) === null), 'centre')));

        if (!$named) {
            // Every match is somewhere this user cannot see. The fact and the
            // place, which is the whole of what they are told.
            $out[] = [['t' => $unnamed ? 'Sibling at ' . implode(' / ', $unnamed) : 'Sibling']];
        } else {
            // AT MOST THREE NAMES, AND THE CENTRE ONCE. Measured 11 Aug 2026 over
            // the 100 live matches: 82 name one sibling and 16 name two, so this
            // changes almost nothing - but one row names ELEVEN across SIX
            // surnames (see below), which rendered as five wrapped lines and set
            // the height of the whole Priority column. Repeating " at Kanwal" per
            // name did the same to the four cross-centre rows naming more than one.
            $shown = array_slice($named, 0, 3);
            $extra = count($named) - count($shown);
            // EACH NAME OPENS THAT CHILD. A named sibling is one this user may
            // see (siblings.php nulls the rest), and carries the ChildID Kidsoft
            // issued at their own centre.
            $p = [['t' => 'Sibling of ']];
            foreach ($shown as $i => $s) {
                if ($i) $p[] = ['t' => ', '];
                $p[] = ['t' => $s['name']] + (!empty($s['kidsoft_child_id']) && !empty($s['centre_id'])
                    ? ['child' => ['centre_id' => (int)$s['centre_id'], 'ks_id' => $s['kidsoft_child_id']]] : []);
            }
            if ($extra > 0) $p[] = ['t' => ' and ' . $extra . ' more'];
            // The centres of the siblings actually NAMED. Taking it off the whole
            // match list would print an unnamed sibling's centre here as well as
            // in the clause below, saying it twice.
            if (!$m['same_centre']) {
                $p[] = ['t' => ' at ' . implode(' / ', array_unique(array_column($named, 'centre')))];
            }
            if ($unnamed) $p[] = ['t' => ' (and at ' . implode(' / ', $unnamed) . ')'];
            $out[] = $p;
        }
        // THE ELEVEN-SIBLING ROW IS A COLLISION, NOT A FAMILY, and capping the
        // display does NOT fix it - that family is still promoted up the queue on
        // it. Six surnames on one email+phone is a shared contact (a carer, an
        // agency, a household number) that got past the Mother/Father/Enrolment
        // filter siblings.php relies on. It is exactly the "a false sibling
        // promotes one family over another that was there first" failure that file
        // is built to avoid. Reported rather than silently thresholded: one row in
        // 100 is not enough to tune a rule on, and refusing a match on COUNT would
        // be guessing at the shape of a real family. If it recurs, the honest fix
        // is upstream in the guardian filter, not a cap here.
    } elseif ($w['is_sibling']) {
        // Ticked by hand, with nothing detected behind it - so there is no name
        // to print and the bare label is the honest one.
        $out[] = [['t' => 'Sibling']];
    }
    return $out;
}

/** Applications for a centre, in queue order, with days and open offer attached. */
function waitlist_for_centre(int $centreId, array $opts = []): array {
    $where = ['w.centre_id = ?'];
    $args  = [$centreId];

    if (!empty($opts['year'])) {
        $where[] = '(w.for_year IS NULL OR w.for_year = ?)';
        $args[] = (int)$opts['year'];
    }
    // ARCHIVED IS THE ONE EXCLUSION THAT BELONGS HERE, and it belongs here for
    // the very reason the rule below exists. An archived application was taken
    // off the list in Kidsoft; it is not a row the team may show or hide, it is
    // not in the queue at all. Filtering it at the BOTTOM like the display
    // filters would leave it counted while positions are assigned, so every
    // family behind it would be told a number one too high - and it was counted
    // until 8 Sep 2026, because a vanished row froze at `waitlisted`. See the
    // reconciliation block in waitlist-import.php.
    //
    // This is also what separates it from `expired`, which the team DOES read
    // and which is still filtered at the bottom with the rest.
    $where[] = "w.status <> 'archived'";
    // NO OTHER DISPLAY FILTER BELONGS IN THIS WHERE CLAUSE. Queue position is
    // assigned by counting down the sorted result set, so anything narrowed in
    // SQL renumbers the queue - and that number is the one thing on this screen a
    // parent might be told over the phone.
    //
    // The name search was moved out for exactly that reason ("struthers" showed
    // a family at #1 whatever their real place in line). THE OPEN/CLOSED FILTER
    // WAS LEFT BEHIND AND HAD THE SAME BUG, found 13 Aug 2026: switching the
    // segmented control from Open to All renumbered **108 of Tuggerah's 115**
    // live families and **217 of Wamberal's 220**, because the nine or so closed
    // applications were sorted in among them and given positions of their own.
    // So a family's place in line changed depending on a display toggle, and a
    // placed family was numbered as though still waiting.
    //
    // Everything is fetched, ranked and numbered; `status`, `include_closed` and
    // `q` are all applied at the BOTTOM of this function, after the positions
    // exist. `year` stays here because it is a scoping choice rather than a
    // display one - it says which queue you are looking at, not which rows of
    // it to show - and nothing passes it today in any case.

    $rows = dbx()->query(
        'SELECT w.*, r.name AS preferred_room_name,
                GROUP_CONCAT(DISTINCT wd.weekday ORDER BY wd.weekday) AS days
         FROM waitlist_applications w
         LEFT JOIN waitlist_days wd ON wd.waitlist_id = w.id
         LEFT JOIN rooms r ON r.id = w.preferred_room_id
         WHERE ' . implode(' AND ', $where) . '
         GROUP BY w.id',
        $args
    )->fetchAll();
    if (!$rows) return [];

    // Open offers, so the queue can show who is already holding one.
    $ids = array_column($rows, 'id');
    $marks = dbx()->whereIn($ids);
    $offers = dbx()->query(
        "SELECT o.*, rm.name AS room_name,
                TIMESTAMPDIFF(HOUR, NOW(), o.expires_at) AS hours_left,
                GROUP_CONCAT(od.weekday ORDER BY od.weekday) AS days
         FROM offers o
         JOIN rooms rm ON rm.id = o.room_id
         LEFT JOIN offer_days od ON od.offer_id = o.id
         WHERE o.status = 'pending' AND o.waitlist_id IN($marks)
         GROUP BY o.id",
        $ids
    )->fetchAll();
    $offerBy = [];
    foreach ($offers as $o) {
        $o['days'] = $o['days'] === null ? [] : array_map('intval', explode(',', $o['days']));
        // HOW LONG THIS OFFER HAS LEFT, from MySQL, which is the one clock in
        // this app that is reliably Sydney. The summary tile used to work it out
        // in the browser - new Date(expires_at) - new Date() - which reads a
        // Sydney wall-clock string in the VIEWER's timezone, the reinterpretation
        // _incl/dates.php forbids and the same fault that had the Add form dating
        // applications a day early.
        //
        // Named `hours_left` and computed exactly as work.php computes
        // `offer_hours_left`, so the three screens that grade an offer's urgency
        // (this one, In progress, the work modal) are grading the same number.
        $o['hours_left'] = $o['hours_left'] === null ? null : (int)$o['hours_left'];
        $offerBy[$o['waitlist_id']] = $o;
    }
    // And the offers sent IN KIDSOFT, which is where offers are made now. A
    // different key from `open_offer` because it is a different thing: there is
    // nothing here to answer or withdraw, Kidsoft owns all of it.
    $ksOfferBy = waitlist_kidsoft_offers($rows);

    // Flag the families already in care BEFORE ranking - they have preference,
    // and priority_rank() reads the flag.
    $rows = waitlist_mark_in_care($rows);
    // And the siblings, for exactly the same reason. Read from Kidsoft on every
    // load rather than taken from `is_sibling`, which nobody ever filled in.
    $rows = waitlist_attach_siblings($centreId, $rows);
    // And Do Not Enrol. NOT a rung: it ranks nobody up or down, it is a warning
    // on the row and a confirmation at make_offer. See _incl/do-not-enrol.php.
    $rows = waitlist_attach_dne($rows);
    // AND A DUPLICATE APPLICATION (6 Nov 2026): the same child twice at this
    // centre, or still waiting after enrolling here. NOT a rung either - a flag
    // asking a person to archive one in Kidsoft. See waitlist_duplicates().
    $dups = waitlist_duplicates([$centreId]);
    foreach ($rows as &$w) $w['duplicate'] = $dups[(int)$w['id']] ?? null;
    unset($w);

    // WHO IS ALREADY PART-WAY THROUGH ENROLLING. Composed from work_list()
    // rather than queried again, so the stage a row shows here is the same
    // object the In progress module and the cell panel show - three screens,
    // one definition.
    //
    // This closes the gap that made `untracked` offers the normal case: offers
    // are made from THIS screen, and until 10 Aug 2026 it had no way to put a
    // family into the pipeline at all, so an offer routinely existed with
    // nothing tracking it. See work_progress_list().
    $workBy = [];
    if (function_exists('work_list')) {
        foreach (work_list($centreId, null) as $wi) {
            if ($wi['waitlist_id']) $workBy[(int)$wi['waitlist_id']] = $wi;
        }
    }
    $touched = waitlist_last_touched($rows, $workBy);

    foreach ($rows as &$w) {
        $w['days'] = $w['days'] === null ? [] : array_map('intval', explode(',', $w['days']));
        foreach (['is_reenrolment', 'is_staff_child', 'is_sibling', 'guide_provided'] as $f) {
            $w[$f] = (bool)$w[$f];
        }
        $w['age_months']  = age_months($w['child_dob']);
        // Composed here so the queue, the group register and search all read one
        // definition - see waitlist_status_label().
        $w['status_label'] = waitlist_status_label($w);
        // Pieces for the screens that link the children named; the sentences for
        // everything else. Built once - see priority_reason_parts().
        $w['reason_parts'] = priority_reason_parts($w);
        $w['reasons']     = priority_reasons_text($w['reason_parts']);
        // THE RAW MATCH STAYS ON THE SERVER. It names every centre the child is
        // at, ChildIDs and all, whether or not this user holds that centre; the
        // parts above carry a ChildID only where they may open it.
        unset($w['in_care_children']);
        $w['open_offer']  = $offerBy[$w['id']] ?? null;
        $w['kidsoft_offer'] = $ksOfferBy[(int)$w['id']] ?? null;
        // Only what the row needs to render a chip and open the modal; the rest
        // of the item is one click away and does not belong in a queue payload.
        $wi = $workBy[(int)$w['id']] ?? null;
        $w['work'] = $wi ? ['id' => (int)$wi['id'], 'stage' => $wi['stage'],
                            'stage_label' => $wi['stage_label']] : null;
        // LAST CONTACTED (11 Nov 2026) - In progress's "Last interacted", see
        // waitlist_last_touched(). Same field names, so one renderer serves both.
        $w['last_touched_at'] = $touched[(int)$w['id']]['at'] ?? null;
        $w['last_touched_by'] = $touched[(int)$w['id']]['by'] ?? null;
        // After `work` and the offers, which it reads - see waitlist_stale().
        $w['stale']       = waitlist_stale($w);
        $w['_rank']       = priority_rank($w);
        // NO expires_in_days ANY MORE (11 Nov 2026): Kidsoft's waitlist expiry
        // is turned off, so a waiting application has no lapse date to count
        // down to - see WAITLIST_NEW_DAYS. An OFFER still has a deadline, and
        // that is on kidsoft_offer (waitlist_kidsoft_offers()), not here.
    }
    unset($w);

    usort($rows, static fn($a, $b) => $a['_rank'] <=> $b['_rank']);
    // NUMBERED OVER THE OPEN QUEUE ONLY, so the number means the same thing on
    // every view of this screen. A closed application gets NULL rather than a
    // position: it is not in the queue, and numbering it both invented a place
    // for a family who already has one and pushed everybody behind them down.
    $n = 0;
    foreach ($rows as &$w) {
        $open = !in_array($w['status'], ['placed', 'withdrawn', 'expired'], true);
        $w['queue_position'] = $open ? ++$n : null;
        unset($w['_rank']);
    }
    unset($w);

    // ---- Display filters, all AFTER the positions exist ---------------------
    // THE SLICE OF THE HISTORY THE TEAM READS. `expired` is two Kidsoft statuses
    // (see waitlist_status_label()) and only one of them is this organisation's
    // own work coming back, so the filter tests the raw status rather than ours.
    // First, because it is the narrowest: it already implies closed rows are in
    // the set, and running it after the include_closed branch would have that
    // branch throw the rows away before this one could see them.
    if (!empty($opts['offer_expired'])) {
        $rows = array_values(array_filter($rows, 'waitlist_is_offer_expired'));
    } elseif (!empty($opts['status'])) {
        $rows = array_values(array_filter($rows,
            static fn($w) => $w['status'] === $opts['status']));
    } elseif (empty($opts['include_closed'])) {
        // Default view is the live queue: closed applications are history.
        $rows = array_values(array_filter($rows,
            static fn($w) => $w['queue_position'] !== null));
    }
    if (!empty($opts['q'])) {
        $needle = mb_strtolower(trim((string)$opts['q']));
        $rows = array_values(array_filter($rows, static function ($w) use ($needle) {
            return str_contains(mb_strtolower(trim(
                $w['child_first_name'] . ' ' . $w['child_last_name'] . ' ' . (string)$w['guardian_name']
            )), $needle);
        }));
    }
    $rows = waitlist_filter_age($rows, $opts);
    // WHO IS YEARS AWAY. Last, and after the positions, like every other display
    // filter here - see waitlist_filter_start(). Absent from $opts means no
    // filter, so the callers that do not offer the control (the Placement
    // board's shortlist) are unchanged.
    $rows = waitlist_filter_start($rows, $opts['starts_by'] ?? null, $opts['starts_from'] ?? null);
    return $rows;
}

/**
 * WHEN A PERSON LAST DID SOMETHING ABOUT EACH APPLICATION, AND WHO (11 Nov 2026).
 *
 * Broc: the queue's "Contacted" column (asked for as "Last contacted") is "the same data as In progress".
 * So it IS that data, not a second definition of it:
 *
 *   - a family with an open In progress item takes the item's own
 *     last_touched_at / last_touched_by, from the work_list() row the queue
 *     already composes - so the two screens cannot disagree about one family;
 *   - every other row gets the half of work_last_touched() that needs no item:
 *     a contact note by a person on the application or on its Kidsoft child,
 *     created_at (when it was done HERE, as there).
 *
 * The note half runs for every row and the later wins, which changes nothing
 * for a row with an item (its value already includes those notes) and is what
 * a family whose item was closed falls back on. A note logged from In progress
 * carries the application's waitlist_id too, so nothing is missed by not
 * joining work_items. Kidsoft has no contact date to offer instead - measured,
 * see "No last contacted date" in docs/kidsoft-api.md.
 *
 * @param array $rows   waitlist_applications rows (id, kidsoft_child_id)
 * @param array $workBy waitlist id => work_list() row
 * @return array<int,array{at:string,by:string}> application id => latest touch
 */
function waitlist_last_touched(array $rows, array $workBy): array {
    $out = [];
    $take = static function (int $id, ?string $at, ?string $by) use (&$out): void {
        if ($at === null || $at === '') return;
        if (!isset($out[$id]) || $at > $out[$id]['at']) $out[$id] = ['at' => $at, 'by' => (string)$by];
    };
    $ids = $byChild = [];
    foreach ($rows as $r) {
        $id = (int)$r['id'];
        $ids[$id] = true;
        if (isset($workBy[$id])) $take($id, $workBy[$id]['last_touched_at'] ?? null, $workBy[$id]['last_touched_by'] ?? null);
        if (!empty($r['kidsoft_child_id'])) $byChild[(string)$r['kidsoft_child_id']][] = $id;
    }
    if (!$ids) return $out;

    $where = ['waitlist_id IN(' . dbx()->whereIn(array_keys($ids)) . ')'];
    $args  = array_keys($ids);
    if ($byChild) {
        $where[] = 'kidsoft_child_id IN(' . dbx()->whereIn(array_keys($byChild)) . ')';
        $args = array_merge($args, array_map('strval', array_keys($byChild)));
    }
    foreach (dbx()->query(
        'SELECT waitlist_id, kidsoft_child_id, created_at, staff_email
           FROM communications
          WHERE (' . implode(' OR ', $where) . ") AND staff_email LIKE '%@%'", $args)->fetchAll() as $c) {
        $hit = [];
        if ($c['waitlist_id'] !== null && isset($ids[(int)$c['waitlist_id']])) $hit[] = (int)$c['waitlist_id'];
        foreach ($byChild[(string)$c['kidsoft_child_id']] ?? [] as $id) $hit[] = $id;
        foreach (array_unique($hit) as $id) $take($id, (string)$c['created_at'], (string)$c['staff_email']);
    }
    return $out;
}

/**
 * Read a range off a request array: an age range in MONTHS, a birth date range,
 * or neither. The screen offers one or the other (the dropdown in the filter
 * bar), so in practice at most one pair arrives - but both are read, and both
 * are applied, so a hand-built URL carrying the two cannot slip past unfiltered.
 *
 * BOTH UNITS, because the team asks the question both ways. Months is what the
 * rest of the app speaks - rooms are banded in min_age_months / max_age_months
 * and sub-quotas are written in months - and it is the right unit for "who have
 * we got around two". A birth date is the right one for a specific child, for a
 * cohort ("born in 2024, for next year's room"), and it is what the row itself
 * prints since 3 Sep 2026.
 *
 * A blank box is no bound, not zero - "from 0" and "no minimum" are the same
 * answer here, but "to 0" and "no maximum" are emphatically not, and treating
 * an empty To as 0 would return nothing and look broken. A date that is not
 * yyyy-mm-dd is no bound either, for the same reason: a half-typed date must
 * not empty the table.
 *
 * Reversed bounds are SWAPPED rather than returning an empty list. 12 to 6 is a
 * typo with an obvious intent, and an empty table is a bad way to report one.
 * The dates swap on the same rule, and they need it MORE: the earlier date is
 * the OLDER child, so the two boxes run the opposite way round to the age ones
 * and reading them backwards is the normal mistake, not the careless one.
 */
function waitlist_age_bounds(array $src): array {
    $n = static function ($v): ?int {
        if ($v === null || trim((string)$v) === '') return null;
        $i = (int)$v;
        return $i < 0 ? null : $i;
    };
    $min = $n($src['age_min'] ?? null);
    $max = $n($src['age_max'] ?? null);
    if ($min !== null && $max !== null && $min > $max) [$min, $max] = [$max, $min];

    // Compared as plain yyyy-mm-dd strings against child_dob, which is a DATE
    // column read straight out of MySQL. Same shape, same order, no timezone in
    // sight - exactly what _incl/dates.php asks for. date_create() would drag
    // one in for nothing.
    $d = static function ($v): ?string {
        $s = substr(trim((string)($v ?? '')), 0, 10);
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $s) ? $s : null;
    };
    $from = $d($src['dob_from'] ?? null);
    $to   = $d($src['dob_to'] ?? null);
    if ($from !== null && $to !== null && $from > $to) [$from, $to] = [$to, $from];

    return ['age_min' => $min, 'age_max' => $max, 'dob_from' => $from, 'dob_to' => $to];
}

/**
 * Narrow rows to an age range, a birth date range, or both. Takes the whole
 * bounds array from waitlist_age_bounds() rather than loose arguments, so a
 * caller that forgets the new pair cannot silently filter on half of it.
 * Shared by the centre queue and the group register so the two tabs cannot
 * disagree about who is 3.
 *
 * THE AGE IS THE AGE TODAY - age_months as at today. NOT the age they would be
 * at `required_from`, which is what fill_suggestions() uses, deliberately:
 * eligibility for a place is a question about a START DATE, while this is a
 * search box. The DOB is the raw column, which is why both live here: one is
 * derived from the other, they can never disagree, and the row prints the DOB.
 *
 * A child with no DOB is DROPPED once any bound is set, because neither
 * question is one their row can answer. Measured 13 Aug 2026: 1 of 720 open
 * applications has no DOB, so this costs almost nothing - and with no bound set
 * nothing is filtered at all and they are still listed.
 */
function waitlist_filter_age(array $rows, array $bounds): array {
    $min  = $bounds['age_min']  ?? null;
    $max  = $bounds['age_max']  ?? null;
    $from = $bounds['dob_from'] ?? null;
    $to   = $bounds['dob_to']   ?? null;
    if ($min === null && $max === null && $from === null && $to === null) return $rows;

    return array_values(array_filter($rows, static function ($r) use ($min, $max, $from, $to) {
        // age_months is age_months($r['child_dob']), so this one test covers a
        // missing DOB for both units.
        $a = $r['age_months'] ?? null;
        if ($a === null) return false;
        if ($min !== null && $a < $min) return false;
        if ($max !== null && $a > $max) return false;
        if ($from !== null || $to !== null) {
            $dob = substr((string)($r['child_dob'] ?? ''), 0, 10);
            if ($dob === '') return false;
            if ($from !== null && $dob < $from) return false;
            if ($to !== null && $dob > $to) return false;
        }
        return true;
    }));
}

/**
 * The last START DATE the queue shows unless somebody asks for more: the end of
 * NEXT year.
 *
 * A waiting list runs further ahead than the work does. Measured 10 Sep 2026
 * over 628 open applications group-wide: 32 of them cannot start until 2028 or
 * later, and two not until 2029 - Baby Imrie at Terrigal is needed from
 * 3/01/2028 and is not born until March 2027. Those families sit in the priority
 * list with a queue number, above families the team is placing this term, and
 * nothing on the row said why.
 *
 * WHY NEXT YEAR AND NOT THIS ONE. The obvious cut - "starting this year" - would
 * have hidden the 2027 cohort, which in September 2026 is the year almost every
 * live In progress item is for: it is the round being worked right now. The cut
 * has to sit beyond the year being planned, not on it. At 31 Dec 2027 it hides
 * 32 rows group-wide and 7 at Terrigal, and nothing anybody is working.
 *
 * Derived from board_default_year() rather than written down, so it moves with
 * the calendar and there is no year to come back and edit. Sydney's year, from
 * the server - a date decided in the viewer's browser is what _incl/dates.php
 * forbids.
 */
function waitlist_starts_by_default(): string {
    return (board_default_year() + 1) . '-12-31';
}

/**
 * The start-date range the waitlist screen asked for, cleaned:
 * ['starts_from' => ?yyyy-mm-dd, 'starts_by' => ?yyyy-mm-dd], both inclusive.
 *
 * A RANGE OF TWO DATES since 6 Nov 2026 (Broc), with "This year" / "Next year"
 * as a dropdown that fills the two boxes. It replaced fixed segments - first
 * "Starting by 2027" / "Any", then "Before 2027 / Before 2028 / Any" for one
 * afternoon - because the team asks "who starts between March and June", and
 * no set of buttons answers that. "Starting by 2027" was also read as "by the
 * start of 2027", which is what started it.
 *
 * NO DATES IS NO FILTER, and that is now the default: the screen opens on the
 * whole queue. The families years away still say so on their own row ("Starts
 * 3/01/2028", see waitlist.js), which was the reason the old cut was on.
 *
 * An unreadable date is dropped rather than refused (blank is no bound at that
 * end) and a reversed pair is swapped - the same rules as waitlist_age_bounds().
 */
function waitlist_start_range(array $src): array {
    $d = static function ($v): ?string {
        $s = substr(trim((string)($v ?? '')), 0, 10);
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $s, $m)) return null;
        return checkdate((int)$m[2], (int)$m[3], (int)$m[1]) ? $s : null;
    };
    $from = $d($src['start_from'] ?? null);
    $to   = $d($src['start_to'] ?? null);
    if ($from !== null && $to !== null && $from > $to) [$from, $to] = [$to, $from];
    return ['starts_from' => $from, 'starts_by' => $to];
}

/**
 * Narrow rows to families who need a place by a given date.
 *
 * A DISPLAY FILTER, applied after queue positions exist - the same rule
 * waitlist_filter_age() follows, and it matters more here because this one is ON
 * by default. Baby Imrie is #63 at Terrigal whether or not the row is on screen,
 * so switching to "Any start date" reveals the row rather than renumbering the
 * queue around it.
 *
 * A row with NO start date is KEPT. Kidsoft populates RequiredFrom on 100% of
 * open applications, so this is the hand-keyed row taken over the phone, and a
 * family nobody has written a date for has not said they are years away - the
 * absence is silence, not an answer.
 *
 * Compared as plain yyyy-mm-dd strings against required_from, a DATE column read
 * straight out of MySQL: same shape, same order, no timezone anywhere near it.
 *
 * @param string|null $by    last start date kept; null = no upper bound
 * @param string|null $after first start date kept; null = no lower bound
 */
function waitlist_filter_start(array $rows, ?string $by, ?string $after = null): array {
    if ($by === '') $by = null;
    if ($after === '') $after = null;
    if ($by === null && $after === null) return $rows;
    return array_values(array_filter($rows, static function ($r) use ($by, $after) {
        $from = substr((string)($r['required_from'] ?? ''), 0, 10);
        if ($from === '') return true;
        return ($by === null || $from <= $by) && ($after === null || $from >= $after);
    }));
}

/**
 * CONSIDER ARCHIVING: wanted a place more than six months ago and still waiting
 * (Broc, 6 Nov 2026).
 *
 * Found measuring the 90-day start window on the placement board: 271 of the 636
 * cards on 2027's shortlists were families whose start date had passed more than
 * six months before 1 January. Open applications group-wide, by how long ago the
 * family wanted to start, that day: 40 more than two years, 13 one to two years,
 * 34 six to twelve months. Thirty-two are one Wamberal batch added 30-31 Aug 2023
 * wanting places from February 2022; Baby Howells at Niagara Park wanted one from
 * 22/12/2023. Almost none carry a Kidsoft `ExpiryDate`, so Kidsoft's own lapse
 * clock (two reminder emails, then ExpiredWaitlist) never started on them, and
 * nothing else would ever take them off the list.
 *
 * A FLAG, NEVER A FILTER OR A RUNG. The family keeps its place in the queue and
 * its card on the board: some are still waiting for this centre while using
 * another, and only a phone call can tell which. Kidsoft is read-only, so the
 * archive is a person's job in Kidsoft, and the next import drops the row.
 *
 * NOT on a family somebody is already talking to - an In progress item or an
 * offer out, the same test that washes the queue row (`engaged` in waitlist.js) -
 * because "consider archiving" a family part-way through enrolling is the wrong
 * thing to put in front of whoever is ringing them. Not on a family already
 * enrolled here and asking for more days (`partially_placed`): their start date
 * is the day they started.
 *
 * Today is Sydney's, from the server - see _incl/dates.php.
 */
const WAITLIST_STALE_MONTHS = 6;

function waitlist_stale(array $w): ?array {
    static $cut = null;
    $cut ??= date_create('today', new DateTimeZone('Australia/Sydney'))
        ->modify('-' . WAITLIST_STALE_MONTHS . ' months')->format('Y-m-d');
    if (($w['status'] ?? null) !== 'waitlisted') return null;
    if (!empty($w['work']) || !empty($w['open_offer']) || !empty($w['kidsoft_offer'])) return null;
    $from = substr((string)($w['required_from'] ?? ''), 0, 10);
    if ($from === '' || $from >= $cut) return null;
    return [
        'label' => 'Consider archiving',
        'text'  => 'Wanted a place from ' . syd_date($from) . ' and is still waiting. '
                 . 'If they no longer need one, archive it in Kidsoft.',
    ];
}

function waitlist_get(int $id): ?array {
    $w = dbx()->query('SELECT * FROM waitlist_applications WHERE id = ?', [$id], 'i')->fetch();
    if (!$w) return null;
    $w['days'] = array_map('intval',
        dbx()->query('SELECT weekday FROM waitlist_days WHERE waitlist_id = ? ORDER BY weekday', [$id], 'i')
            ->fetchAll('col'));
    return $w;
}

function waitlist_set_days(int $waitlistId, array $weekdays): void {
    $clean = array_values(array_unique(array_filter(
        array_map('intval', $weekdays), static fn($w) => $w >= 1 && $w <= 5
    )));
    dbx()->query('DELETE FROM waitlist_days WHERE waitlist_id = ?', [$waitlistId], 'i');
    foreach ($clean as $w) {
        dbx()->query('INSERT INTO waitlist_days (waitlist_id, weekday) VALUES (?, ?)', [$waitlistId, $w], 'ii');
    }
}

/**
 * Replace the weekdays KIDSOFT OFFERED this family. Import only.
 *
 * Unlike the requested days above, an empty list DOES clear: nobody types these,
 * so there is no hand-entered value for a Kidsoft read without OfferedDays to
 * wipe, and a stale offer left behind would go on holding days on the board.
 */
function waitlist_set_offered_days(int $waitlistId, array $weekdays): void {
    $clean = array_values(array_unique(array_filter(
        array_map('intval', $weekdays), static fn($w) => $w >= 1 && $w <= 5
    )));
    dbx()->query('DELETE FROM waitlist_offered_days WHERE waitlist_id = ?', [$waitlistId], 'i');
    foreach ($clean as $w) {
        dbx()->query('INSERT INTO waitlist_offered_days (waitlist_id, weekday) VALUES (?, ?)', [$waitlistId, $w], 'ii');
    }
}

/**
 * Does Kidsoft have an offer out to this family that is still holding a place?
 *
 * Our own status has to be OPEN as well: a row we have closed or archived holds
 * nothing, whatever Kidsoft last said about it.
 */
function waitlist_kidsoft_offer_open(array $w): bool {
    return ($w['status'] ?? '') === 'waitlisted'
        && isset(KIDSOFT_OPEN_OFFER[(string)($w['kidsoft_status'] ?? '')]);
}

/**
 * The Kidsoft offer behind each of these applications, keyed by application id.
 *
 * What the queue row, search and the group register print: the room and the
 * days KIDSOFT offered, which are not always the days the family asked for --
 * Stella Knox asked for Tue/Fri and Terrigal offered Tue/Wed, and the two
 * screens looked like they disagreed. The board holds the same rows; see
 * board_load().
 *
 * Dates are formatted here, server-side - they are Sydney wall-clock strings and
 * the browser must never reinterpret them (_incl/dates.php).
 *
 * @param array $rows applications carrying id, status and kidsoft_status
 * @return array<int, array>
 */
function waitlist_kidsoft_offers(array $rows): array {
    $ids = [];
    foreach ($rows as $w) if (waitlist_kidsoft_offer_open($w)) $ids[] = (int)$w['id'];
    if (!$ids) return [];
    $marks = dbx()->whereIn($ids);
    $out = [];
    foreach (dbx()->query(
        "SELECT a.id, a.kidsoft_status, a.kidsoft_offer_room_id, a.kidsoft_expires_at,
                a.required_from, rm.name AS room_name,
                GROUP_CONCAT(od.weekday ORDER BY od.weekday) AS days,
                DATEDIFF(a.kidsoft_expires_at, CURDATE()) AS expires_in_days,
                -- To the END of the expiry day, the rule offer_expiry_normalise()
                -- applies to a bare date. From MySQL, which is Sydney, and in
                -- hours so window.offerUrgency() grades it exactly as it grades
                -- every other offer.
                TIMESTAMPDIFF(HOUR, NOW(), TIMESTAMP(a.kidsoft_expires_at, '23:59:59')) AS hours_left
         FROM waitlist_applications a
         LEFT JOIN rooms rm ON rm.id = a.kidsoft_offer_room_id
         LEFT JOIN waitlist_offered_days od ON od.waitlist_id = a.id
         WHERE a.id IN($marks)
         GROUP BY a.id",
        $ids
    )->fetchAll() as $r) {
        $state = KIDSOFT_OPEN_OFFER[$r['kidsoft_status']];
        $live  = !$state['answered'] && $r['kidsoft_expires_at'];
        $out[(int)$r['id']] = [
            'status'    => $r['kidsoft_status'],
            'label'     => $state['label'],
            'answered'  => $state['answered'],
            'room_id'   => $r['kidsoft_offer_room_id'] === null ? null : (int)$r['kidsoft_offer_room_id'],
            'room_name' => $r['room_name'],
            // The board's rule for which year the place is held in - see
            // board_load()'s Kidsoft block.
            'year'      => $r['required_from']
                ? max((int)substr($r['required_from'], 0, 4), (int)date('Y')) : null,
            'days'      => $r['days'] === null ? [] : array_map('intval', explode(',', $r['days'])),
            // The offer's own deadline, only while the family has still to answer.
            // See KIDSOFT_OPEN_OFFER for why a submitted form has none.
            'expires'         => $live ? syd_date($r['kidsoft_expires_at']) : null,
            'expires_in_days' => $live ? (int)$r['expires_in_days'] : null,
            'hours_left'      => $live ? (int)$r['hours_left'] : null,
            'starts'    => $r['required_from'] ? syd_date($r['required_from']) : null,
        ];
    }
    return $out;
}

function offer_get(int $id): ?array {
    $o = dbx()->query('SELECT * FROM offers WHERE id = ?', [$id], 'i')->fetch();
    if (!$o) return null;
    $o['days'] = dbx()->query(
        'SELECT weekday, accepted FROM offer_days WHERE offer_id = ? ORDER BY weekday', [$id], 'i'
    )->fetchAll();
    return $o;
}

/**
 * Vacancies for a room and year, per weekday, from the placement board.
 *
 * This is what makes an offer safe to make: it is the same calculation the board
 * shows, so staff cannot offer a day that is already full. Waitlisted and
 * unavailable-position placements do not consume a place.
 *
 * THE MEMO BELOW MUST BE BYPASSED UNDER A LOCK, and for three months it was not
 * (fixed 13 Aug 2026). It was added so fill_suggestions() could call this once
 * per room without reloading the board eleven times, and it silently disarmed
 * the only defence against promising one seat twice: make_offer() pre-checks
 * vacancies, opens a transaction, takes FOR UPDATE on the capacity row and then
 * re-reads - straight out of the memo its own pre-check had just filled, from
 * BEFORE the lock. Two staff offering the last Tuesday place both passed. The
 * lock serialised them and then handed the second one the first one's stale
 * answer, and nothing downstream catches it: `offers` carries no unique key.
 *
 * Verified before the fix by inserting a pending offer on all five days of a
 * room between two calls - `pending` stayed 0 and `free` never moved.
 *
 * So: $fresh re-reads and re-seeds the memo. Anything deciding whether a place
 * may be promised must pass it; a read for display need not.
 *
 * WHAT MAY BE SOLD IS NOT WHAT IS OCCUPIED (13 Aug 2026). Every place is sold for
 * the full year - the team does not accept an end date from a parent, so a family
 * enrols January to December and leaves only by giving notice. So a booking that
 * starts in October is a place already sold, and a child whose notice is in has
 * already released theirs. `free` below is therefore measured against `holding`,
 * the fullest the cell gets between today and December, NOT against the reference
 * month's occupancy. It arrives from board_load() rather than being recounted
 * here, so this figure and the grid's cannot disagree. See
 * board_forward_occupancy() for why a peak needs no pairing heuristic.
 *
 * @param bool $fresh ignore the per-request memo - REQUIRED inside the offer
 *                    transaction, where a stale read double-books a place
 * @return array<int,array{occupied:int,pending:int,holding:int,capacity:?int,free:?int}>
 */
function room_day_vacancies(int $roomId, int $year, bool $fresh = false): array {
    // OCCUPANCY COMES FROM THE BOARD, WHICH COMES FROM KIDSOFT (6 Aug 2026).
    // This used to COUNT placements rows. That table is an annotation store now,
    // so counting it would report almost every room as empty and this function is
    // exactly the one that must never be wrong - it is what stops two staff
    // promising the same seat. Delegating to board_load() also keeps the old
    // guarantee that made offers safe: the figure here is the same figure the
    // board shows, by construction rather than by two calculations agreeing.
    //
    // board_load() is memoised per (centre, year) because fill_suggestions()
    // calls this once per room and a centre has up to eleven.
    static $memo = [];

    $room = room_get($roomId);
    if (!$room) return [];
    $centreId = (int)$room['centre_id'];
    $key = $centreId . ':' . $year;
    if ($fresh || !isset($memo[$key])) $memo[$key] = board_load($centreId, $year);

    $cells = [];
    foreach ($memo[$key]['rooms'] as $r) if ((int)$r['id'] === $roomId) $cells = $r['cells'];

    $out = [];
    foreach (array_keys(WEEKDAYS) as $wd) {
        $cell = $cells[$wd] ?? null;
        // 'promised' already counts the pending offers that hold a day, which is
        // what stops the same seat being offered twice.
        $occupied = (int)($cell['occupied'] ?? 0);
        $pending  = (int)($cell['promised'] ?? 0);
        // Kept for a child who has not answered re-enrolment. Subtracted for the
        // same reason a pending offer is: the place is spoken for, and offering
        // it to a queue family would promise one seat twice. Counted apart from
        // `pending` because an offer can be withdrawn here and a hold cannot -
        // it lapses on its own clock. See reenrolment_holds().
        $keep     = (int)($cell['held'] ?? 0);
        // Kept for a child who is enrolled here and is not booked on this
        // weekday, most often while a Behaviour Support Plan is being worked
        // through. Subtracted for the same reason as the two above: the place is
        // spoken for, and this function is the one that must never be wrong - it
        // is what stops two staff promising one seat. See _incl/day-holds.php.
        $reserved = (int)($cell['reserved'] ?? 0);
        // The fullest this cell gets from today to December. Falls back to the
        // reference month only if the board could not produce one.
        $holding  = (int)($cell['holding'] ?? $occupied);
        // This day's own licensed places - offering against another day's figure
        // is how a place gets promised twice.
        $capacity = $cell['capacity'] ?? null;
        $out[$wd] = [
            'occupied' => $occupied,
            'pending'  => $pending,
            'held'     => $keep,
            'reserved' => $reserved,
            'holding'  => $holding,
            'capacity' => $capacity,
            'free'     => $capacity === null
                ? null : max(0, $capacity - $holding - $pending - $keep - $reserved),
        ];
    }
    return $out;
}

/**
 * Expire offers whose deadline has passed, and release the days they held.
 * Idempotent and safe to call on page load - an offer that has lapsed must stop
 * holding a place immediately, not whenever a cron next runs.
 *
 * @return int how many offers were expired
 */
function offers_expire_due(): int {
    $due = dbx()->query(
        "SELECT id, waitlist_id FROM offers WHERE status = 'pending' AND expires_at < NOW()"
    )->fetchAll();
    if (!$due) return 0;

    // LOADED HERE, NOT TESTED FOR (14 Oct 2026). This used to be guarded by
    // function_exists('work_offer_answered'), which is true on every web page
    // that expires an offer and FALSE in cron-nightly.php, which never loaded
    // the work layer. An offer expiring at 23:59:59 is swept overnight, so every
    // one of them left its item sitting at "Offered" holding nothing: six items
    // group-wide when it was found, none since 9 Sep had moved back. The board
    // hides anything at Offered or past from the cell (the offer is meant to be
    // holding those days), so those families were on no screen but In progress.
    require_once __DIR__ . '/work.php';

    foreach ($due as $o) {
        dbx()->query("UPDATE offers SET status = 'expired' WHERE id = ?", [$o['id']], 'i');
        // The family goes back into the queue keeping their original date_added,
        // so an expired offer never costs them their place in line.
        dbx()->query(
            "UPDATE waitlist_applications SET status = 'waitlisted'
             WHERE id = ? AND status = 'offered'",
            [$o['waitlist_id']], 'i'
        );
        // And the pipeline, or the item sits at "offered" for ever holding an
        // offer that lapsed - which is precisely the stale state that made the
        // spreadsheets untrustworthy.
        work_offer_answered((int)$o['waitlist_id'], 'expired');
    }
    return count($due);
}
