<?php
/**
 * PAST APPLICATIONS ON THE WAITLIST (11 Nov 2026, Broc).
 *
 * "Can we please sync the search bar 'Waitlist archive or rejected' info in the
 * waitlists so we can see that info if they are left on 1 waitlist. Currently
 * waitlist shows only if they are currently on the waitlist elsewhere."
 *
 * Search has shown a family's closed and archived applications since 14 Aug 2026
 * (search_attach_kidsoft_history()); the queue showed only OPEN applications at
 * other centres (waitlist_elsewhere()), so a family rejected at Tuggerah and
 * deleted at Terrigal read on Wamberal's queue as a family nobody had dealt with.
 *
 * THE SAME LINES SEARCH PRINTS, built by the same search_history_line() from the
 * same cached Kidsoft read - nothing is copied into our tables, for the reasons
 * written there. The same exclusions follow from that: an enrolment is not a past
 * application, and a row the queue table shows is not history.
 *
 * MATCHED ON THE SAME EVIDENCE SEARCH JOINS A CARD ON, and nothing looser:
 * Kidsoft's ChildID, or the child's name PLUS their date of birth, or the name
 * PLUS the household phone (sibling_phone_key()). Never the name alone - the
 * queue is full of `Baby Jones` placeholders. Followed transitively through the
 * history itself, as search_merge_children() does through a card's records: an
 * old application that carries the phone but not the DOB still lands on the
 * child the next one proved by DOB.
 *
 * ALL CENTRES, THIS ONE INCLUDED: "rejected by Wamberal last year" is the most
 * useful line there is on Wamberal's own queue.
 *
 * A SUPERVISOR is given the lines at their own centres only, as search gives
 * them: what another centre decided about a family is that centre's business.
 *
 * Cost: one warm read of seven cached payloads, 0.15 s, measured 11 Nov 2026.
 */

require_once __DIR__ . '/search.php';   // search_history_*() - one definition of a past application
require_once __DIR__ . '/drift.php';    // name_identity_key()
require_once __DIR__ . '/siblings.php'; // sibling_phone_key()
require_once __DIR__ . '/users.php';    // user_can_centre()

/**
 * The evidence that a record is this child, as signatures: two records sharing
 * any one of them are the same child. See the header.
 */
function waitlist_history_sigs(?string $childId, string $first, string $last,
                               ?string $dob, ?string $phone): array {
    $sigs = [];
    if ($childId !== null && $childId !== '') $sigs[] = 'k:' . $childId;
    // No letters, no name - and a name is half of every other signature.
    if (name_fold($first . $last) === '') return $sigs;
    $n = name_identity_key($first, $last);
    if ($dob !== null && $dob !== '') $sigs[] = 'd:' . $n . ':' . substr($dob, 0, 10);
    $ph = sibling_phone_key($phone);
    if ($ph !== null) $sigs[] = 'p:' . $n . ':' . $ph;
    return $sigs;
}

/**
 * Every past application in the group, indexed by signature. Memoised for the
 * request: the list and the modal's get both ask.
 *
 * @return array{lines: array<int,array>, sigs: array<int,string[]>, by: array<string,int[]>}
 */
function waitlist_history_index(): array {
    static $memo = null;
    if ($memo !== null) return $memo;

    $held = search_history_held();
    $lines = $sigs = $by = [];
    foreach (centres_all() as $c) {
        $sid = $c['kidsoft_service_id'] ?? null;
        if (!$sid) continue;
        try {
            $res = kidsoft_cached('Waitlist', 'getWaitingList',
                search_history_params($sid), SEARCH_HISTORY_TTL);
        } catch (RuntimeException $e) {
            // Served from a 36 h cache warmed nightly, so this is a cold cache AND
            // Kidsoft down at once. A missing past is not a claim the queue makes;
            // the queue itself still loads.
            continue;
        }
        foreach (kidsoft_rows($res, 'WaitingList', $sid) as $w) {
            $line = search_history_line($w, $c, $held);
            if ($line === null) continue;
            $d     = $w['Details']['EnrolmentData'] ?? [];
            $child = $d['ChildDetails'] ?? [];
            $s = waitlist_history_sigs(
                !empty($w['ChildID']) ? (string)$w['ChildID'] : null,
                trim((string)($child['FirstName'] ?? '')),
                trim((string)($child['LastName']  ?? '')),
                !empty($child['DOB']) ? (string)$child['DOB'] : null,
                (string)($d['ParentGuardianOne']['MobilePhone'] ?? ''));
            if (!$s) continue;
            $i = count($lines);
            $lines[$i] = $line;
            $sigs[$i]  = $s;
            foreach ($s as $sig) $by[$sig][] = $i;
        }
        unset($res);   // 16.9 MB across the group cold: one centre at a time
    }
    return $memo = ['lines' => $lines, 'sigs' => $sigs, 'by' => $by];
}

/**
 * The past applications for each of these queue rows.
 *
 * Takes rows, not a centre, because the list and the modal's `get` hold
 * different ones: waitlist_for_centre()'s and waitlist_get()'s, which share the
 * columns read here.
 *
 * @return array<int,array{lines: array, total: int}> application id => its past;
 *         absent when there is none
 */
function waitlist_history(array $apps): array {
    $ix = waitlist_history_index();
    if (!$ix['lines']) return [];

    $out = [];
    foreach ($apps as $a) {
        $todo = waitlist_history_sigs(
            $a['kidsoft_child_id'] ?? null,
            (string)$a['child_first_name'], (string)$a['child_last_name'],
            $a['child_dob'] ?? null, $a['guardian_phone'] ?? null);

        // Followed through the history: a line found by DOB brings its phone
        // signature with it, and so on until nothing new turns up.
        $seen = $got = [];
        while ($todo) {
            $sig = array_pop($todo);
            if (isset($seen[$sig])) continue;
            $seen[$sig] = true;
            foreach ($ix['by'][$sig] ?? [] as $i) {
                if (isset($got[$i])) continue;
                $got[$i] = true;
                foreach ($ix['sigs'][$i] as $s) $todo[] = $s;
            }
        }

        $rows = [];
        foreach (array_keys($got) as $i) {
            $l = $ix['lines'][$i];
            if (!user_can_centre($l['centre_id'])) continue;
            $rows[] = $l;
        }
        if (!$rows) continue;

        $rows = search_history_order($rows);
        $out[(int)$a['id']] = [
            // Only what the lines print. `fold` and the raw status were for
            // getting here.
            'lines' => array_map(static fn($l) => [
                'centre_id'    => $l['centre_id'],
                'centre'       => $l['centre'],
                'status_label' => $l['status_label'],
                'archived'     => $l['archived'],
                'date_added'   => $l['date_added'],
            ], array_slice($rows, 0, SEARCH_HISTORY_PER_RESULT)),
            'total' => count($rows),
        ];
    }
    return $out;
}
