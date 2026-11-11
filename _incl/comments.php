<?php
// Comments on a family: ours, Kidsoft's, and the one-way bridge between them.
//
// WHY THIS IS A HYBRID AND NOT A SYNC. The enrolments team's record of "we
// phoned, we toured, we offered" lives in Kidsoft Enrol Notes today, ticked
// confidential. The obvious move was to write ours there too and have one
// history. It cannot be done, and the reason was measured rather than assumed:
//
//   1. A Kidsoft CHILD record does not exist until an application converts to an
//      ENROLMENT. Of 719 OPEN applications group-wide, 2 carry a ChildID and 92 a
//      GuardianID - **625 (87%) have neither**, and those are exactly the
//      families the team is ringing.
//   2. A comment written against a WaitingListID cannot be read back by
//      anything. A PAM added one in the Kidsoft UI to a waitlist-only family and
//      it was then absent from getWaitingList (with every documented flag plus a
//      speculative Options=Comments), from getChildren and from getGuardians, at
//      every centre, polled over five minutes. Only getChildren, getGuardians and
//      getRollCalls return comments at all.
//   3. Kidsoft is in maintenance and is not adding endpoints, so waiting for a
//      read path is not a plan.
//
// So the split is: **OUR TABLE IS THE RECORD, ALWAYS, FOR EVERYONE.** Kidsoft
// gets a copy only where Kidsoft itself has told us which record to put it on,
// which is where a human opening Kidsoft would look for it. A family we cannot
// push to loses nothing here; they simply have no Kidsoft copy, and the screen
// says so rather than implying the note travelled.
//
// MATCHING A FAMILY TO A GUARDIAN BY PHONE OR EMAIL WOULD LIFT REACH FROM 13% TO
// 24% AND IS REFUSED (Broc, 14 Aug 2026). createComment has no update and no
// delete, so a wrong match writes one family's confidential note permanently onto
// another family's record. Only IDs Kidsoft supplies are used. Do not "improve"
// this with the sibling matcher - siblings.php is timid because a false positive
// reorders a queue; here it would be a disclosure that cannot be taken back.
//
// A FAILED PUSH IS NOT A FAILED SAVE. The local row is committed first and the
// push happens after; if Kidsoft is unreachable the note is still recorded and
// the row carries kidsoft_push_error so the screen can say what did not travel.
// Losing a staff member's note because a third party was down is the worse bug.

require_once __DIR__ . '/simple-mysqli.php';
require_once __DIR__ . '/dates.php';       // syd_datetime() for the stamp on a pushed note
require_once __DIR__ . '/kidsoft.php';
require_once __DIR__ . '/kidsoft-cache.php';
require_once __DIR__ . '/contacts.php';    // guardians_by_id()
require_once __DIR__ . '/audit.php';

/** What Kidsoft's `Type` field is stamped with, so ours are identifiable there. */
const COMMENT_KIDSOFT_TYPE = 'Enrolments';

/** Longest note we accept. `summary` is varchar(255); the body carries the rest. */
const COMMENT_MAX = 4000;

/**
 * The words for a logged contact's direction and channel. ONE COPY: api/comms
 * validates against them and hands them to the contact modals, and the Support
 * plans report prints them. They lived in api/comms.php until the report became
 * the second reader (29 Oct 2026).
 */
const COMM_DIRECTIONS = ['outbound' => 'We contacted them', 'inbound' => 'They contacted us', 'internal' => 'Internal note'];
const COMM_CHANNELS   = ['phone' => 'Phone', 'email' => 'Email', 'sms' => 'SMS', 'in_person' => 'In person', 'other' => 'Other'];

/**
 * Where a comment about this waitlist application can be written in Kidsoft.
 *
 * Returns null when it cannot be written anywhere, which is the common case (87%
 * of open applications) and is NOT an error.
 *
 * ONLY reads IDs Kidsoft supplied on the application itself. `WaitingListID` is
 * never returned - see the header for the measurement that ruled it out.
 *
 * @return array{target:string,id:string}|null
 */
function comment_kidsoft_target(array $app): ?array {
    if (!empty($app['kidsoft_child_id']))    return ['target' => 'child',    'id' => (string)$app['kidsoft_child_id']];
    if (!empty($app['kidsoft_guardian_id'])) return ['target' => 'guardian', 'id' => (string)$app['kidsoft_guardian_id']];
    return null;
}

/**
 * The text of a contact-log entry: the summary, and the body under it when there
 * is one.
 *
 * One definition, used by the endpoint that writes a note and by the sweep that
 * copies an old one, so a note that travels late reads exactly like a note that
 * travelled at the time.
 */
function comment_text(string $summary, ?string $body): string {
    $body = trim((string)$body);
    return $body !== '' ? trim($summary) . "\n\n" . $body : trim($summary);
}

/**
 * Copy a contact-log entry that is ALREADY SAVED into Kidsoft, where possible.
 *
 * Called by api/comms.php straight after its INSERT. Deliberately NOT an
 * insert-and-push of its own: `communications` already had a complete endpoint
 * (list / add / delete) and a modal on two screens before any of this existed,
 * and a second way to write a contact note would be two implementations of one
 * record - the failure this codebase keeps finding. This function does the one
 * thing that endpoint could not do.
 *
 * NEVER THROWS. A push failure is not a failed save: the note is committed
 * before this is called, and losing a staff member's typing because a third
 * party was unreachable is the worse bug. The outcome is written onto the row so
 * the screen can say exactly what did and did not travel.
 *
 * @param array   $rec        the waitlist application (or placement) the note hangs off
 * @param ?string $occurredAt when the contact happened, if not now - see the stamp
 * @return array{pushed:bool, target:?string, error:?string}
 */
function comment_push(int $commId, array $rec, string $text, string $staffEmail,
                      ?string $occurredAt = null): array {
    $target = comment_kidsoft_target($rec);
    if (!$target) return ['pushed' => false, 'target' => null, 'error' => null];

    $serviceId = dbx()->query('SELECT kidsoft_service_id FROM centres WHERE id = ?',
        [$rec['centre_id']])->fetch()['kidsoft_service_id'] ?? '';
    if ($serviceId === '') return ['pushed' => false, 'target' => $target['target'], 'error' => 'No Kidsoft service id for that centre.'];

    try {
        // Stamped with who and when. In Kidsoft this note sits among notes from
        // every other system and a bare sentence has no provenance - and unlike
        // ours, it cannot be edited afterwards to add one.
        //
        // WHEN MEANS WHEN THE CONTACT HAPPENED, not when the copy was made. This
        // used to be date('now') either way, which was already loose on the live
        // path (the add form accepts a backdated occurred_at) and is plainly
        // wrong on the backlog sweep, where a call made three weeks ago would
        // arrive in Kidsoft stamped tonight. A LATE COPY SAYS SO, because
        // KIDSOFT RETURNS NO DATE ON A COMMENT AT ALL - the reader has nothing
        // but this line and the order the notes went in, so the line has to
        // carry the whole answer.
        $when   = syd_datetime($occurredAt ?: date('Y-m-d H:i:s'));
        $late   = $occurredAt !== null && substr($occurredAt, 0, 10) !== date('Y-m-d')
                    ? ' (copied ' . syd_date(date('Y-m-d')) . ')' : '';
        $stamped = trim($text) . "\n\n-- " . $staffEmail . ', via Little Miracles CRM, ' . $when . $late;

        $commentId = kidsoft_create_comment($serviceId, $target['target'], $target['id'],
                                            $stamped, COMMENT_KIDSOFT_TYPE);
        dbx()->query(
            'UPDATE communications SET kidsoft_comment_id = ?, kidsoft_target = ?,
                    kidsoft_target_id = ?, kidsoft_pushed_at = NOW(), kidsoft_push_error = NULL
              WHERE id = ?',
            [$commentId ?: null, $target['target'], $target['id'], $commId]
        );
        audit('comm.push', 'communication', $commId,
              ['target' => $target['target'], 'target_id' => $target['id'],
               'kidsoft_comment_id' => $commentId]);

        // The centre's comments just changed, so the cached copy is wrong. Without
        // this the note would not appear in the Kidsoft column until the TTL
        // expired, and the staff member would file it again.
        kidsoft_cache_forget_comments($serviceId, $target['target']);

        return ['pushed' => true, 'target' => $target['target'], 'error' => null];
    } catch (Throwable $e) {
        $err = mb_substr($e->getMessage(), 0, 255);
        dbx()->query('UPDATE communications SET kidsoft_push_error = ? WHERE id = ?', [$err, $commId]);
        audit('comm.push_failed', 'communication', $commId, ['error' => $err]);
        return ['pushed' => false, 'target' => $target['target'], 'error' => $err];
    }
}

/**
 * Most notes that can travel in one run. A backstop, not a policy.
 *
 * The sweep below writes to Kidsoft, and createComment has no update and no
 * delete, so an unbounded loop over a table nobody has looked at is the wrong
 * shape. What is dropped is COUNTED AND LOGGED, never silently truncated, and
 * the next run picks it up.
 */
const COMMENT_BACKLOG_MAX = 200;

/**
 * COPY THE NOTES THAT COULD NOT TRAVEL WHEN THEY WERE WRITTEN.
 *
 * 87% of the families the team rings have no Kidsoft record to write to: a Child
 * record is created when an application CONVERTS TO AN ENROLMENT, not when the
 * family applies. So a note filed while they were waiting is filed here and
 * nowhere else, and comment_push() correctly returns "nowhere to copy to".
 *
 * THE MOMENT THAT CHANGES IS THE CONVERSION, and it is now visible: measured 17
 * Aug 2026 over all 1,699 waitlist rows group-wide, every one of the 17 at
 * status `Enrolled` carries a ChildID and a GuardianID against ~0% at every
 * other status, and waitlist-import.php takes those ids on every row it holds
 * whatever the status. So the family becomes writable overnight, with a history
 * sitting here that Kidsoft has never seen. This sweep is the other half of that
 * change: it runs AFTER the import in cron-nightly.php, oldest note first.
 *
 * OLDEST FIRST IS NOT A PREFERENCE. Kidsoft returns NO DATE on a comment --
 * comment_kidsoft_existing() has to render them undated for exactly this reason
 * - so the only ordering a human reading that record gets is the order the
 * notes went in. Pushing a family's backlog newest-first would put their history
 * in Kidsoft backwards, permanently.
 *
 * WHAT IT WILL AND WILL NOT RETRY, and the line is about what we KNOW:
 *
 *   - NEVER ATTEMPTED (no pushed_at, no error) - pushed. This is the whole
 *     point: there was nowhere to put it and now there is. UNLESS IT WAS ASKED
 *     TO STAY HERE (`local_only`, or an internal note): "never attempted" looks
 *     the same on the row, so the flag is what tells them apart.
 *   - KIDSOFT ANSWERED AND DECLINED (`Kidsoft refused the comment: ...`, which
 *     is Status != OK on a JSON reply) - pushed. The far end replied, so it did
 *     not write; the request is safe to make again.
 *   - TRANSPORT FAILED OR THE REPLY WAS NOT JSON - LEFT ALONE, and reported.
 *     Kidsoft may well have taken the comment and lost the answer on the way
 *     back, and there is no read path to check with (no endpoint is keyed on
 *     WaitingListID, and getChildren comments carry no date to match on). A
 *     retry would leave a permanent duplicate on the family's record. Somebody
 *     deciding that is fine, seeing the count, is the right way for it to
 *     happen. It is NOT something a nightly job should decide.
 *
 * A REFUSAL IS RETRIED EVERY NIGHT, deliberately. Kidsoft declining a comment
 * today says nothing about tomorrow, and the alternative - one strike and the
 * note never travels - fails silently. A note Kidsoft refuses permanently costs
 * one call a night and is counted as `failed` in the log, where somebody can see
 * it. The per-run cap bounds the worst case.
 *
 * Nothing here is guessed at: the target is resolved from the record the note
 * hangs off, through comment_kidsoft_target(), which reads ONLY ids Kidsoft
 * itself supplied. A note whose family still has neither id is simply not
 * selected, tonight or any other night, until Kidsoft says who they are.
 *
 * NEVER THROWS, for the same reason comment_push() does not: one unreachable
 * centre must not cost the rest of the run.
 *
 * @param array $opts 'dry_run' => report only, 'limit' => override the cap
 * @return array counts
 */
function comment_push_backlog(array $opts = []): array {
    $limit = max(1, (int)($opts['limit'] ?? COMMENT_BACKLOG_MAX));
    $dry   = !empty($opts['dry_run']);

    $counts = ['candidates' => 0, 'pushable' => 0, 'pushed' => 0, 'failed' => 0,
               'deferred' => 0, 'unknown_outcome' => 0];

    // The record is resolved in the SELECT rather than per row: the target comes
    // from the waitlist application or the placement the note hangs off, never
    // from communications.kidsoft_child_id, which is a copy taken when the note
    // was written - and the whole reason this sweep exists is that the copy was
    // empty then and the record has since been given an id.
    $rows = dbx()->query(
        "SELECT c.id, c.occurred_at, c.summary, c.body, c.staff_email,
                COALESCE(w.centre_id, p.centre_id)               AS centre_id,
                COALESCE(w.kidsoft_child_id, p.kidsoft_child_id) AS kidsoft_child_id,
                w.kidsoft_guardian_id
           FROM communications c
           LEFT JOIN waitlist_applications w ON w.id = c.waitlist_id
           LEFT JOIN placements p            ON p.id = c.placement_id
          WHERE c.kidsoft_pushed_at IS NULL
            -- A NOTE ASKED TO STAY HERE STAYS HERE, TONIGHT AND EVERY NIGHT
            -- (19 Oct 2026). Until this line the sweep could not tell one: an
            -- internal note (the work modal's default chip) and a support plan
            -- note were skipped at the time they were written and left with no
            -- pushed_at and no error - exactly the shape of a note that had
            -- nowhere to go - so the first night after the family enrolled, or
            -- the very next night for a support plan note on a child in care,
            -- this would have copied them onto the child's Kidsoft record for
            -- good. None had been written when it was found (4 notes group-wide,
            -- all calls and emails). `local_only` is stored by api/comms.
            AND c.local_only = 0 AND c.direction <> 'internal'
            AND (c.waitlist_id IS NOT NULL OR c.placement_id IS NOT NULL)
            AND (c.kidsoft_push_error IS NULL
                 OR c.kidsoft_push_error LIKE 'Kidsoft refused the comment:%')
          ORDER BY c.occurred_at, c.id"
    )->fetchAll();

    // Attempted, and we do not know whether it landed. Reported, never retried.
    $counts['unknown_outcome'] = (int)dbx()->query(
        "SELECT COUNT(*) n FROM communications
          WHERE kidsoft_pushed_at IS NULL AND kidsoft_push_error IS NOT NULL
            AND kidsoft_push_error NOT LIKE 'Kidsoft refused the comment:%'"
    )->fetch()['n'];

    $counts['candidates'] = count($rows);

    // Only rows Kidsoft now has somewhere to put. Filtered before the cap, so the
    // cap counts writes rather than rows looked at.
    $due = array_values(array_filter($rows,
        static fn($r) => $r['centre_id'] !== null && comment_kidsoft_target($r) !== null));
    $counts['pushable'] = count($due);

    if (count($due) > $limit) {
        $counts['deferred'] = count($due) - $limit;
        $due = array_slice($due, 0, $limit);   // oldest first, so the tail is the newest
    }

    if ($dry) return $counts;

    foreach ($due as $r) {
        $res = comment_push(
            (int)$r['id'], $r,
            comment_text((string)$r['summary'], $r['body']),
            (string)$r['staff_email'],
            (string)$r['occurred_at']
        );
        // Either it travelled or it did not. comment_push() has already written
        // the outcome onto the row, which is what the modal reads.
        $counts[$res['pushed'] ? 'pushed' : 'failed']++;
    }
    return $counts;
}

/**
 * Kidsoft's own comments for this family, read from the cached child/guardian
 * payloads. Empty when the family has no Kidsoft record, which is the norm on
 * the waitlist.
 */
function comment_kidsoft_existing(array $app): array {
    $target = comment_kidsoft_target($app);
    if (!$target) return [];

    $serviceId = dbx()->query('SELECT kidsoft_service_id FROM centres WHERE id = ?',
        [$app['centre_id']])->fetch()['kidsoft_service_id'] ?? '';
    if ($serviceId === '') return [];

    try {
        if ($target['target'] === 'child') {
            $res  = kidsoft_cached('Children', 'getChildren',
                ['ServiceID' => $serviceId, 'Options' => 'Comments']);
            $rows = kidsoft_rows($res, 'Children', $serviceId);
            $idKey = 'ChildID';
        } else {
            $res  = kidsoft_cached('Guardian', 'getGuardians',
                ['ServiceID' => $serviceId, 'Options' => 'Comments']);
            $rows = kidsoft_rows($res, 'Guardians', $serviceId);
            $idKey = 'GuardianID';
        }
    } catch (RuntimeException $e) {
        return [];   // Kidsoft down: our own history still renders
    }

    $rec = null;
    foreach ($rows as $r) if (($r[$idKey] ?? '') === $target['id']) { $rec = $r; break; }
    if (!$rec) return [];

    // A single comment arrives as a bare object rather than a list of one.
    $cs = $rec['Comments'] ?? [];
    if (isset($cs['CommentID'])) $cs = [$cs];

    $out = [];
    foreach ($cs as $c) {
        $text = trim((string)($c['Comment'] ?? ''));
        if ($text === '') continue;
        $out[] = [
            'source'       => 'kidsoft',
            'id'           => (string)($c['CommentID'] ?? ''),
            // Kidsoft returns no date on a comment, so there is nothing to sort
            // or display. Saying "undated" beats inventing one.
            'occurred_at'  => null,
            'summary'      => mb_strlen($text) > 255 ? mb_substr($text, 0, 252) . '...' : $text,
            'body'         => $text,
            'type'         => trim((string)($c['Type'] ?? '')) ?: null,
            'confidential' => !empty($c['Confidential']),
            'on'           => $target['target'],
        ];
    }
    return $out;
}

/**
 * Drop the cached comment payload for a service after we have written to it.
 *
 * Without this the note the staff member just filed would not appear in the
 * Kidsoft column for up to the TTL, and they would file it again.
 */
function kidsoft_cache_forget_comments(string $serviceId, string $target): void {
    [$controller, $action] = $target === 'child'
        ? ['Children', 'getChildren'] : ['Guardian', 'getGuardians'];
    $params = ['ServiceID' => $serviceId, 'Options' => 'Comments'];
    ksort($params);
    $key = "{$controller}/{$action}:" . md5(json_encode($params));
    dbx()->query('DELETE FROM kidsoft_cache WHERE cache_key = ?', [$key]);
}
