<?php
// ONE CHILD, TWO APPLICATIONS AT ONE CENTRE (6 Nov 2026).
//
// Broc, from Wamberal: Lennox Dransfield's parent held three applications there.
// One was offered and converted to Enrolled; the other two stayed on Kidsoft's
// list as Waitlisted, and the In progress item - which sat on one of the other
// two - never closed. Asked: "can it be bright red or something and say
// 'duplicate waitlist' so they can go in to Kidsoft and archive there?" With two
// conditions: a child already enrolled asking for MORE days is demand the team
// wants and must not be called a duplicate, and separated parents applying
// separately must be flagged differently from one parent applying twice.
//
// THE CLAIM THIS REPLACES: dashboard.php said "a centre's own queue has no
// duplicates". Nobody had measured it. Measured 6 Nov 2026 over every
// non-archived application, bucketed by centre + name_identity_key() + DOB with
// at least one still open: five children.
//
//   Raylan Lee (Tuggerah)      two open, identical parent details      -> duplicate
//   Billy Bovis (Tuggerah)     two open, same mobile                   -> duplicate
//   Elijah Bourgeois (Terrigal) two open, Jessica Henegan and Matthew
//                               Bourgeois, no contact in common        -> other parent
//   Zoe Lahanas (Kanwal)       enrolled Mon-Wed, the other asks Mon-Thu -> NOT flagged
//   Savannah Jarvis (Niagara)  an expired offer and a new application  -> NOT flagged
//
// And Lennox himself, before Wamberal archived his two by hand that morning:
// enrolled Thu on #1030, #771 and #1031 each asking Thu only -> duplicate, both.
//
// THE RULES, for an OPEN application (waitlisted / offered / partially placed):
//
//   1. Another application for this child HERE is Kidsoft `Enrolled`, and this
//      one asks for no day the enrolment does not already cover: DUPLICATE. If
//      it asks for a day beyond the enrolment it is a family wanting more days -
//      Zoe's Thursday - and gets no flag at all. The Priority column already says
//      "Already in care" for those, and a second mark would be noise.
//   2. Another OPEN application for this child here shares a parent: the same
//      Kidsoft GuardianID, mobile or email. DUPLICATE. Both rows are flagged:
//      which one to keep is the centre's call (the older holds the earlier place
//      in the queue, the newer may carry the days the family wants now), and the
//      title names the other's date and days so the call can be made from here.
//   3. Another OPEN application for this child here shares NO parent contact:
//      OTHER PARENT. Separated parents apply separately, and their days can
//      legitimately differ. Amber, never red, and it never asks for an archive.
//
// THE CHILD IS MATCHED ON name_identity_key() + DOB, AT ONE CENTRE. Narrower than
// the register's family rule in one way (one centre: applying at several centres
// is normal, 12% of the queue does it) and wider in another (rule 3 needs no
// shared contact - that is the whole point of it). name_identity_key() rather
// than name_key() because two twins fold to one name under name_key(); the
// placeholder names ("TBA Smith", "Baby Tweedie") are told apart by DOB. A row
// with no DOB is never matched.
//
// IT IS A FLAG, NEVER AN ACTION. Kidsoft is read-only, so the archive is done by a
// person in Kidsoft; the next import then drops the row (see the archive
// reconciliation in waitlist-import.php) and the flag goes with it. Nothing here
// changes the queue order or closes work.
//
// Pure SQL over our own copy, so it is free on every load and needs no Kidsoft
// read: everything it compares is a field the import already stores.

require_once __DIR__ . '/simple-mysqli.php';
require_once __DIR__ . '/drift.php';      // name_identity_key()
require_once __DIR__ . '/siblings.php';   // sibling_phone_key() / sibling_email_key()
require_once __DIR__ . '/dates.php';      // syd_date()

const WAITLIST_DUP_OPEN = ['waitlisted', 'offered', 'partially_placed'];

/**
 * The duplicate flag for every open application at these centres.
 *
 * @param int[] $centreIds
 * @return array<int,array{kind:string,label:string,text:string,other_id:int}>
 *         application id => flag; an application with nothing to say is absent
 */
function waitlist_duplicates(array $centreIds): array {
    static $memo = [];
    $want = array_values(array_unique(array_map('intval', $centreIds)));
    $need = array_values(array_filter($want, static fn($c) => !isset($memo[$c])));

    if ($need) {
        foreach ($need as $c) $memo[$c] = [];
        $marks = dbx()->whereIn($need);
        $rows = dbx()->query(
            "SELECT a.id, a.centre_id, a.child_first_name, a.child_last_name, a.child_dob,
                    a.guardian_name, a.guardian_phone, a.guardian_email, a.kidsoft_guardian_id,
                    a.status, a.kidsoft_status, a.date_added,
                    GROUP_CONCAT(d.weekday ORDER BY d.weekday) AS days
               FROM waitlist_applications a
               LEFT JOIN waitlist_days d ON d.waitlist_id = a.id
              WHERE a.centre_id IN($marks)
                AND a.status <> 'archived'
                AND a.child_dob IS NOT NULL
              GROUP BY a.id",
            $need
        )->fetchAll();

        $buckets = [];
        foreach ($rows as $r) {
            $r['id']   = (int)$r['id'];
            $r['days'] = $r['days'] === null ? [] : array_map('intval', explode(',', $r['days']));
            $buckets[(int)$r['centre_id'] . '|'
                . name_identity_key((string)$r['child_first_name'], (string)$r['child_last_name'])
                . '|' . substr((string)$r['child_dob'], 0, 10)][] = $r;
        }
        foreach ($buckets as $apps) {
            if (count($apps) < 2) continue;
            foreach (waitlist_duplicates_bucket($apps) as $id => $flag) {
                $memo[(int)$apps[0]['centre_id']][$id] = $flag;
            }
        }
    }

    $out = [];
    foreach ($want as $c) $out += $memo[$c] ?? [];
    return $out;
}

/** The rules in the header, over one child's applications at one centre. */
function waitlist_duplicates_bucket(array $apps): array {
    $isOpen = static fn(array $a): bool => in_array($a['status'], WAITLIST_DUP_OPEN, true);

    $enrolled = array_values(array_filter($apps, static fn($a) => $a['kidsoft_status'] === 'Enrolled'));
    $enrolledDays = [];
    foreach ($enrolled as $e) foreach ($e['days'] as $d) $enrolledDays[$d] = true;

    $out = [];
    foreach ($apps as $a) {
        if (!$isOpen($a)) continue;

        // 1. Enrolled here already, asking for nothing new.
        if ($enrolled && !array_diff($a['days'], array_keys($enrolledDays))) {
            $e = $enrolled[0];
            $out[$a['id']] = [
                'kind'     => 'duplicate',
                'label'    => 'Duplicate waitlist',
                'text'     => 'Already enrolled here' . waitlist_dup_days($e['days'], ' for ')
                            . ' on another application. Archive this one in Kidsoft.',
                'other_id' => $e['id'],
            ];
            continue;
        }

        // 2 and 3. Another open application for this child here.
        $same = $other = null;
        foreach ($apps as $b) {
            if ($b['id'] === $a['id'] || !$isOpen($b)) continue;
            if (waitlist_dup_share_parent($a, $b)) { $same ??= $b; } else { $other ??= $b; }
        }
        if ($same) {
            $out[$a['id']] = [
                'kind'     => 'duplicate',
                'label'    => 'Duplicate waitlist',
                'text'     => 'Applied for twice with the same parent details. The other application was added '
                            . syd_date($same['date_added']) . waitlist_dup_days($same['days'], ' for ')
                            . '. Archive one of them in Kidsoft.',
                'other_id' => $same['id'],
            ];
        } elseif ($other) {
            $who = trim((string)$other['guardian_name']);
            $out[$a['id']] = [
                'kind'     => 'other_parent',
                'label'    => 'Other parent applied',
                'text'     => ($who !== '' ? $who : 'Another parent') . ' also applied, on '
                            . syd_date($other['date_added']) . waitlist_dup_days($other['days'], ' for ')
                            . ', with different contact details.',
                'other_id' => $other['id'],
            ];
        }
    }
    return $out;
}

/** Kidsoft's own GuardianID, or a mobile or email in common. */
function waitlist_dup_share_parent(array $a, array $b): bool {
    if ($a['kidsoft_guardian_id'] && $a['kidsoft_guardian_id'] === $b['kidsoft_guardian_id']) return true;
    $pa = sibling_phone_key($a['guardian_phone']);
    if ($pa !== null && $pa === sibling_phone_key($b['guardian_phone'])) return true;
    $ea = sibling_email_key($a['guardian_email']);
    return $ea !== null && $ea === sibling_email_key($b['guardian_email']);
}

function waitlist_dup_days(array $days, string $prefix): string {
    if (!$days) return '';
    $n = [1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri'];
    return $prefix . implode(', ', array_map(static fn($d) => $n[$d] ?? (string)$d, $days));
}
