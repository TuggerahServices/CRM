<?php
// Communications log - who said what to which family, and when.
//
// The point is that any staffer can see prior parent contact before they pick up
// the phone. Entries are therefore attached to the FAMILY (via the waitlist
// application or placement, and the Kidsoft child id where known), not to
// whichever offer happened to prompt the call.

declare(strict_types=1);

require_once __DIR__ . '/../_incl/auth.php';
require_once __DIR__ . '/../_incl/csrf.php';
require_once __DIR__ . '/../_incl/api.php';
require_once __DIR__ . '/../_incl/waitlist.php';
require_once __DIR__ . '/../_incl/board.php';
require_once __DIR__ . '/../_incl/audit.php';
require_once __DIR__ . '/../_incl/comments.php';   // the one-way bridge to Kidsoft
require_once __DIR__ . '/../_incl/work.php';       // work_siblings_here() - who a note may also show on

$user = auth_require_api();
$action = $_REQUEST['action'] ?? '';

if ($action !== 'list') {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { http_response_code(405); exit; }
    csrf_require();
}

// COMM_DIRECTIONS and COMM_CHANNELS live in _incl/comments.php since 29 Oct
// 2026, when the Support plans report became a second reader of the labels.

/**
 * The family a handle names, for both `add` and `list`.
 *
 * THREE HANDLES, ONE RESOLUTION. A waitlist application, a child on the board,
 * or a work item - and the work item is the one with a rule attached: it
 * **widens to its own application wherever it has one**, because `work_items`
 * says a candidate is EITHER a queue application or a child already in care, and
 * where it is the former the notes belong to the FAMILY rather than to this one
 * spot. `work_contact_history()` and the family modal both look up by
 * `waitlist_id`; a note filed only against the item would be invisible to both.
 *
 * Written once because `add` and `list` must agree on it. They had drifted
 * within a day of `add` learning the work-item handle: `list` would still have
 * refused one, so the modal that writes a note could not read back what Kidsoft
 * holds for the same family.
 *
 * `rec` is what `comment_kidsoft_target()` and `comment_kidsoft_existing()` are
 * handed, so it must carry `centre_id` and whichever Kidsoft ids exist. For an
 * item with no application it is assembled from the item itself; for a tour
 * enquiry it carries neither id and both of those correctly answer "nowhere".
 *
 * @return array{rec: array, centre_id: int, waitlist_id: ?int, placement_id: ?int,
 *               work_item_id: ?int, child_id: ?string, app: ?array, placement: ?array}
 */
function comms_resolve(?int $waitlistId, ?int $placementId, ?int $workItemId): array {
    $out = ['rec' => [], 'centre_id' => 0, 'waitlist_id' => $waitlistId,
            'placement_id' => $placementId, 'work_item_id' => $workItemId,
            'child_id' => null, 'app' => null, 'placement' => null];

    if ($workItemId) {
        $wi = dbx()->query('SELECT * FROM work_items WHERE id = ?', [$workItemId], 'i')->fetch();
        if (!$wi) api_fail('Work item not found.');
        $out['centre_id'] = (int)$wi['centre_id'];
        $out['child_id']  = $wi['kidsoft_child_id'];
        $out['rec']       = ['centre_id' => (int)$wi['centre_id'],
                             'kidsoft_child_id' => $wi['kidsoft_child_id']];
        $out['waitlist_id'] = $out['waitlist_id']
            ?: ($wi['waitlist_id'] ? (int)$wi['waitlist_id'] : null);
        $waitlistId = $out['waitlist_id'];
    }
    if ($waitlistId) {
        $w = waitlist_get($waitlistId);
        if (!$w) api_fail('Waitlist application not found.');
        $out['app']       = $w;
        $out['rec']       = $w;
        $out['centre_id'] = (int)$w['centre_id'];
        $out['child_id']  = $w['kidsoft_child_id'] ?: $out['child_id'];
    }
    if ($placementId) {
        $p = placement_get($placementId);
        if (!$p) api_fail('Placement not found.');
        $out['placement'] = $p;
        $out['rec']       = $p;
        $out['centre_id'] = (int)$p['centre_id'];
        $out['child_id']  = $out['child_id'] ?: $p['kidsoft_child_id'];
    }
    // THE CONTACT LOG IS THE MOST PERSONAL THING IN THIS APP - what a parent said
    // on the phone, written down. It is reached by a RECORD id and never by a
    // centre id, so the guard comes off the row, here, once, for every caller.
    api_require_centre($out['centre_id']);
    return $out;
}

switch ($action) {

// History for ONE family. Takes a single handle - a waitlist application or a
// placement - and widens to the Kidsoft child id where that record has one, so
// the thread follows a family from the queue onto the board.
//
// Deliberately not several handles at once: OR-ing unrelated ids together would
// merge two families' contact logs into one convincing but wrong thread.
case 'list': {
    $waitlistId  = (int)($_GET['waitlist_id'] ?? 0);
    $placementId = (int)($_GET['placement_id'] ?? 0);
    // THE WORK-ITEM HANDLE, matching `add` (18 Sep 2026). Without it the work
    // modal could WRITE a note here and not read back what Kidsoft holds for the
    // same family - the two actions would have disagreed about what a handle is
    // within a day of each other.
    $workItemId  = (int)($_GET['work_item_id'] ?? 0);
    if (!$waitlistId && !$placementId && !$workItemId) api_fail('Say whose history you want.');
    // Deliberately not several at once: OR-ing unrelated ids together would
    // merge two families' contact logs into one convincing but wrong thread.
    if ((int)(bool)$waitlistId + (int)(bool)$placementId + (int)(bool)$workItemId > 1) {
        api_fail('Ask for one record at a time.');
    }

    $h   = comms_resolve($waitlistId ?: null, $placementId ?: null, $workItemId ?: null);
    $rec = $h['rec'];
    // A work item that HAS an application is read as that application, so one
    // family reads the same from either screen. One with no application (a child
    // already in care, or a tour enquiry) is keyed on the item, which for an
    // enquiry is the only thing identifying them at all.
    if ($h['waitlist_id'])       { $where = ['waitlist_id = ?'];   $args = [$h['waitlist_id']]; }
    elseif ($h['placement_id'])  { $where = ['placement_id = ?'];  $args = [$h['placement_id']]; }
    else                         { $where = ['work_item_id = ?'];  $args = [$h['work_item_id']]; }
    // Same child, logged against their other record - same family, so include it.
    if (!empty($h['child_id'])) {
        $where[] = 'kidsoft_child_id = ?';
        $args[]  = $h['child_id'];
    }
    // A call logged on a brother or sister and shown on this child too (19 Oct
    // 2026) - see `add`. Read through the link, never by matching the family.
    if ($h['waitlist_id']) {
        $where[] = 'id IN (SELECT communication_id FROM communication_siblings WHERE waitlist_id = ?)';
        $args[]  = $h['waitlist_id'];
    }

    $rows = dbx()->query(
        'SELECT * FROM communications WHERE ' . implode(' OR ', $where) . ' ORDER BY occurred_at DESC, id DESC',
        $args
    )->fetchAll();
    // Which child each note was written on, or is also shown on - the work
    // modal's own answer, so one note reads the same in both dialogs.
    $rows = work_contacts_shared($rows, $h['waitlist_id'] ?: null);

    // KIDSOFT'S OWN COMMENTS, ALONGSIDE AND NOT MERGED IN. A note somebody here
    // typed and a note Kidsoft holds answer different questions, and Kidsoft's
    // side is mostly form capture ("Agreed to terms and conditions: Yes") rather
    // than contact history. They stay separate so neither is mistaken for the
    // other. Anything we pushed is filtered out by CommentID so it appears once.
    $pushed = [];
    foreach ($rows as $r) if (!empty($r['kidsoft_comment_id'])) $pushed[$r['kidsoft_comment_id']] = true;
    $kidsoft = array_values(array_filter(comment_kidsoft_existing($rec),
        static fn($c) => !isset($pushed[$c['id']])));

    $target = comment_kidsoft_target($rec);
    echo json_encode([
        'ok' => true, 'entries' => $rows,
        'directions' => COMM_DIRECTIONS, 'channels' => COMM_CHANNELS,
        'kidsoft' => $kidsoft,
        // Why a note will or will not travel. Published rather than re-derived in
        // JS, because "Kidsoft holds no record of this family yet" is the honest
        // answer for 87% of the queue and the screen must be able to say it.
        'kidsoft_push' => [
            'pushable' => $target !== null,
            'target'   => $target['target'] ?? null,
        ],
    ], JSON_UNESCAPED_SLASHES);
    break;
}

case 'add': {
    $summary   = trim((string)($_POST['summary'] ?? ''));
    $direction = (string)($_POST['direction'] ?? 'outbound');
    $channel   = (string)($_POST['channel'] ?? 'phone');
    $occurred  = trim((string)($_POST['occurred_at'] ?? ''));

    if ($summary === '')                      api_fail('Write a short summary of the contact.');
    if (!isset(COMM_DIRECTIONS[$direction]))  api_fail('Unknown direction.');
    if (!isset(COMM_CHANNELS[$channel]))      api_fail('Unknown channel.');
    if ($occurred !== '' && !date_create($occurred)) api_fail('That date is not valid.');

    $waitlistId  = (int)($_POST['waitlist_id'] ?? 0) ?: null;
    $placementId = (int)($_POST['placement_id'] ?? 0) ?: null;
    // A WORK ITEM IS THE THIRD HANDLE (18 Sep 2026). The work modal used to
    // write `communications` itself, through work_log_contact() - a second
    // implementation of one record, which is the failure this codebase keeps
    // finding. That path had no `body`, truncated the summary to 255 without
    // saying so, and never pushed to Kidsoft, so the identical sentence
    // travelled when typed in the family modal and did not when typed on the
    // screen whose whole job is chasing families. It posts here now, and
    // work_log_contact() is gone.
    $workItemId  = (int)($_POST['work_item_id'] ?? 0) ?: null;
    if (!$waitlistId && !$placementId && !$workItemId) {
        api_fail('Attach the note to a waitlist application, a child on the board, or a work item.');
    }

    // Centre, child and the record the Kidsoft copy hangs off, derived from
    // whichever handle this arrived on - never posted, so this is the centre the
    // note will actually be filed under. comms_resolve() runs
    // api_require_centre() off that row; see it for the work-item rule, and note
    // that BOTH keys are written below, exactly as the removed
    // work_log_contact() did.
    $h          = comms_resolve($waitlistId, $placementId, $workItemId);
    $centreId   = $h['centre_id'];
    $childId    = $h['child_id'];
    $waitlistId = $h['waitlist_id'];

    // Held in a variable because the Kidsoft copy is stamped with it: the form
    // accepts a backdated contact, and a note about Tuesday's call must not
    // arrive in Kidsoft claiming to be from today.
    $occurredAt = $occurred !== '' ? date_create($occurred)->format('Y-m-d H:i:s')
                                   : date('Y-m-d H:i:s');
    $bodyText   = trim((string)($_POST['body'] ?? '')) ?: null;
    // COMMENT_MAX WAS DECLARED AND NEVER ENFORCED. It matters from 18 Sep 2026,
    // because the work modal now posts here with a 3000-character detail field
    // where its old path capped the whole note at 500 - so an over-long note
    // would be a Kidsoft write that fails, or worse succeeds and cannot be
    // taken back. Checked on the joined text, which is what actually travels.
    if (mb_strlen(comment_text($summary, $bodyText)) > COMMENT_MAX) {
        api_fail('That note is too long to file. Keep it under ' . COMMENT_MAX . ' characters.');
    }

    // SUPPORT PLAN NOTES DO NOT TRAVEL (Broc, 17 Sep 2026, asked directly and
    // answering "for now"). createComment has no update and no delete, so a note
    // reading "second family meeting, discussed release of care" would be
    // permanent and unretractable on the child's Kidsoft record - a different
    // risk from an ordinary contact note, because this is behaviour and
    // diagnosis material. The caller asks for it rather than the endpoint
    // guessing from the screen, and the modal says so BEFORE the note is typed.
    $localOnly = !empty($_POST['local_only']);
    // AN INTERNAL NOTE IS ALSO local_only, AND THE CALLER SAYS SO. The work
    // modal's "Note" chip posts direction=internal with local_only=1: a note to
    // a colleague is not a record of contact with the family, and createComment
    // has no update and no delete, so "chase the paperwork" would sit on that
    // child's Kidsoft record permanently. Enforced here as well as asked for,
    // because the direction is the whole meaning of the word.
    if ($direction === 'internal') $localOnly = true;

    // THE BROTHERS AND SISTERS THIS NOTE IS ALSO SHOWN ON (19 Oct 2026). Broc
    // agreed "a logged call shows on both children here, but goes to Kidsoft only
    // on the child it was written for". Re-checked against the family the server
    // finds - work_siblings_here(), same centre, still waiting - rather than
    // trusted, so a post cannot hang a note on an arbitrary application. Checked
    // BEFORE the insert, so a refusal writes nothing.
    $alsoRaw = $_POST['also_waitlist'] ?? [];
    if (!is_array($alsoRaw)) $alsoRaw = [$alsoRaw];
    $also = array_values(array_unique(array_filter(array_map('intval', $alsoRaw))));
    if ($also) {
        if (!$waitlistId) api_fail('Only a family in the queue has brothers or sisters to show this on.');
        $fam = array_column(work_siblings_here([$waitlistId])[$waitlistId]['siblings'] ?? [], null, 'waitlist_id');
        foreach ($also as $sid) {
            if (!isset($fam[$sid])) api_fail('That brother or sister is not waiting at this centre.');
        }
    }

    // A DOUBLE-CLICKED SAVE FILES ONE NOTE (2 Nov 2026). A work modal Save went
    // twice in the same second and filed the same phone note twice. The screens
    // now hold a form while it saves (app.js, "One save at a time"); this is the
    // backstop for the one write here that cannot be taken back, because a note
    // copied to Kidsoft twice is there twice for good. The same words from the
    // same person on the same family within 30 seconds are the save they already
    // made, and get its answer. The lock makes that hold for two requests that
    // arrive together, which is what a double click is.
    $staff = $user['email'] ?? 'unknown';
    $lock  = 'comm-add-' . sha1(json_encode([$staff, $waitlistId, $placementId, $workItemId, $summary]));
    dbx()->query('SELECT GET_LOCK(?, 10)', [$lock]);
    try {
        $dup = dbx()->query(
            'SELECT * FROM communications
              WHERE staff_email = ? AND waitlist_id <=> ? AND placement_id <=> ? AND work_item_id <=> ?
                AND direction = ? AND channel = ? AND summary = ? AND body <=> ?
                AND created_at >= NOW() - INTERVAL 30 SECOND
              ORDER BY id LIMIT 1',
            [$staff, $waitlistId, $placementId, $workItemId, $direction, $channel, $summary, $bodyText]
        )->fetch();
        if (!$dup) {
            dbx()->query(
                'INSERT INTO communications (centre_id, kidsoft_child_id, waitlist_id, placement_id,
                        work_item_id, occurred_at, direction, channel, summary, body, staff_email, local_only)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [$centreId, $childId, $waitlistId, $placementId, $workItemId, $occurredAt,
                 $direction, $channel, $summary, $bodyText, $staff, $localOnly ? 1 : 0]
            );
            $id = dbx()->insertId();
            // ONE ROW, SHOWN ON SEVERAL CHILDREN - never a copy per child. The Kidsoft
            // copy hangs off the row's own waitlist_id (comment_push() below, and the
            // nightly backlog), which this table is never read by. So the note can reach
            // Kidsoft for the child it was written on and for nobody else: the sibling
            // match is a shared parent phone or email, which is exactly the evidence the
            // createComment rule refuses to write on (see comments.php).
            foreach ($also as $sid) {
                dbx()->query('INSERT INTO communication_siblings (communication_id, waitlist_id) VALUES (?, ?)',
                             [$id, $sid], 'ii');
            }
        }
    } finally {
        dbx()->query('SELECT RELEASE_LOCK(?)', [$lock]);
    }
    if ($dup) {
        echo json_encode([
            'ok'         => true,
            'id'         => (int)$dup['id'],
            'pushed'     => (bool)$dup['kidsoft_pushed_at'],
            'target'     => $dup['kidsoft_target'],
            'local_only' => (bool)$dup['local_only'],
            'warning'    => null,
            'duplicate'  => true,
        ], JSON_UNESCAPED_SLASHES);
        break;
    }
    audit('comm.create', 'communication', $id, [
        'waitlist_id' => $waitlistId, 'placement_id' => $placementId,
        'work_item_id' => $workItemId, 'channel' => $channel,
    ] + ($also ? ['also_on' => $also] : []) + ($localOnly ? ['local_only' => true] : []));

    // THE SUPPRESSION IS STAMPED ON THE ROW (`local_only`, 19 Oct 2026), and not
    // as an error. kidsoft_pushed_at and kidsoft_push_error are both for what
    // HAPPENED to an attempt, and an error here would put a warning triangle
    // beside a note that did exactly what it was told to. It used to be stamped
    // nowhere at all, and that was a hole: the nightly backlog reads "no
    // pushed_at, no error" as "had nowhere to go", and would have copied every
    // internal and support plan note to Kidsoft once the family had a record.

    // THE RECORD THE COPY HANGS OFF. $w is the application wherever there is one
    // - including for a work item, which resolved to its own application above -
    // so ONE family gets ONE answer about where their notes go, whichever screen
    // typed this. A work item with neither (a tour enquiry) falls through to []
    // and comment_push() correctly reports "nowhere to copy to", which is the
    // normal case and not an error.
    $rec = $h['rec'];
    // COPY TO KIDSOFT, WHERE KIDSOFT HAS SOMEWHERE TO PUT IT. Only ever after the
    // row is committed, and never fatal - see comment_push(). The note is filed
    // either way; `pushed` says whether a copy also reached Kidsoft.
    $push = $localOnly
        ? ['pushed' => false, 'target' => null, 'error' => null]
        : comment_push($id, $rec, comment_text($summary, $bodyText),
                       (string)($user['email'] ?? 'unknown'), $occurredAt);

    echo json_encode([
        'ok'      => true,
        'id'      => $id,
        'pushed'  => $push['pushed'],
        'target'  => $push['target'],
        'local_only' => $localOnly,
        // Returned as a WARNING on a successful save, not as an error: telling
        // somebody their note failed when it is safely stored would have them
        // type it again.
        'warning' => $push['error'] === null ? null
            : 'Saved here, but Kidsoft would not take the copy: ' . $push['error'],
    ], JSON_UNESCAPED_SLASHES);
    break;
}

// Only the author may remove their own note, and only the same day. A contact
// log that anyone can quietly rewrite is not a record anybody can rely on.
//
// AN ADMINISTRATOR MAY REMOVE ANY NOTE, AT ANY AGE (Broc, 30 Oct 2026). The
// first removal was done by hand in the database the day before, at his
// request, because nobody else could. It is still never quiet: the audit row
// names the author and, where the note travelled, the Kidsoft comment it left.
case 'delete': {
    $id = (int)($_POST['id'] ?? 0);
    $c = dbx()->query('SELECT * FROM communications WHERE id = ?', [$id], 'i')->fetch();
    if (!$c) api_fail('Entry not found.');
    api_require_centre((int)$c['centre_id']);
    $admin = user_is_admin();
    if (!$admin) {
        if (strcasecmp($c['staff_email'], (string)($user['email'] ?? '')) !== 0) {
            api_fail('Only the person who logged this can remove it.');
        }
        if (strtotime($c['created_at']) < strtotime('-1 day')) {
            api_fail('This entry is over a day old. Add a correcting note instead of deleting it.');
        }
    }

    // THE SAME WORDS SIT ON THE STAGE CHANGE THEY WERE SAVED WITH. A work modal
    // save that moves the stage writes the summary twice - onto the stage event
    // through /api/work's `advance`, then here - and the timeline folds the two
    // into one line (6 of 19 notes when this was written). Deleting only this
    // row would leave the sentence on screen as the stage change's note. The
    // stage change stays; it is the item's own history. Same item, same exact
    // words, written within two minutes: one act.
    $events = $c['work_item_id'] ? dbx()->query(
        'SELECT e.id FROM work_item_events e
          WHERE e.work_item_id = ? AND LEFT(e.note, 255) = ? COLLATE utf8mb4_bin
            AND ABS(TIMESTAMPDIFF(SECOND, e.occurred_at, ?)) <= 120',
        [(int)$c['work_item_id'], $c['summary'], $c['created_at']]
    )->fetchAll('col') : [];

    dbx()->transaction(function ($db) use ($id, $events) {
        if ($events) {
            $db->query('UPDATE work_item_events SET note = NULL WHERE id IN (' . $db->whereIn($events) . ')', $events);
        }
        // communication_siblings cascades: one row, so it comes off every child it was shown on.
        $db->query('DELETE FROM communications WHERE id = ?', [$id], 'i');
    });

    // THE KIDSOFT COPY IS NOT TOUCHED AND CANNOT BE. createComment has no update
    // and no delete, and no other write is authorised. Once this row is gone the
    // audit is the only record of which Kidsoft comment came from here, so its id
    // is kept. The screen said so before the click.
    audit('comm.delete', 'communication', $id, [
        'summary' => $c['summary'], 'author' => $c['staff_email'],
    ] + ($admin && strcasecmp($c['staff_email'], (string)($user['email'] ?? '')) !== 0
            ? ['as_administrator' => true] : [])
      + ($c['kidsoft_pushed_at'] ? ['kidsoft_comment_id' => $c['kidsoft_comment_id'],
            'kidsoft_target' => $c['kidsoft_target'], 'kidsoft_target_id' => $c['kidsoft_target_id']] : [])
      + ($c['kidsoft_push_error'] ? ['kidsoft_push_error' => $c['kidsoft_push_error']] : [])
      + ($events ? ['event_notes_cleared' => array_map('intval', $events)] : []));
    echo json_encode(['ok' => true]);
    break;
}

default:
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'unknown action']);
}
