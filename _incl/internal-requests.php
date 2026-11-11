<?php
// The internal day waitlist - a standing request for a weekday, recorded by us.
//
// "Someone is in Shining Stars Mon, Tues, and want a Wednesday when it becomes
// available. There isn't an option for this on Kidsoft, that is for the
// permanent positions." - Broc, 10 Aug 2026, from UAT.
//
// THIS IS THE ONE PIECE OF DEMAND THE MIRROR CANNOT CARRY, and it took UAT to
// find it, because on paper the app already showed extra-day demand. Kidsoft has
// two waitlists and neither one holds this:
//
//   Waitlist/getWaitingList        the NEW-ENROLMENT queue - a family with no
//                                  place yet, applying for a permanent position.
//                                  Imported into waitlist_applications.
//   Bookings/getWaitlistedBookings DATE-SPECIFIC requests raised by a parent in
//                                  the portal ("Wed 3 Sep"), read live by
//                                  day_requests_year(). A parent who RINGS THE
//                                  CENTRE and says "any Wednesday, whenever one
//                                  opens" has raised nothing at all - there is
//                                  no row in Kidsoft to read, ever.
//
// CLAUDE.md's rule is "anything Kidsoft can answer, READ from Kidsoft" and its
// second question is "why is a human typing this?". The answer here is that
// nobody can type it anywhere else: this is a phone call, and the only record of
// it was the enrolments team's memory. It is ours by the same test as offers and
// work items.
//
// IT IS DEMAND, NOT WORK, and the distinction decides where everything lives. A
// wish is DORMANT: there is nothing to do about "wants a Wednesday" until a
// Wednesday comes free, so it must not sit in work_list() being ranked by
// neglect - that list is ordered by what has gone un-chased, and an item nobody
// can action would nag forever. So a wish behaves like a day request: it lands on
// its cell, it counts toward `grantable`, and when the place appears the team
// starts a work_item from it. Same journey a Kidsoft request already takes.
//
// GRANTED IS DECIDED BY KIDSOFT, NOT BY A CHECKBOX - work_autoclose()'s rule.
// internal_requests_close_met() stamps a day the moment the derived board shows
// the child booked in that room on that weekday. Day-level: a family who got the
// Wednesday and also wanted the Thursday is still waiting.

require_once __DIR__ . '/simple-mysqli.php';
require_once __DIR__ . '/rooms.php';      // centre_get()
require_once __DIR__ . '/contacts.php';   // child_contacts()
require_once __DIR__ . '/audit.php';

/** Statuses that still represent somebody waiting. */
const INTERNAL_REQUEST_OPEN = ['open'];

/**
 * Every open wish at a centre for a year, grouped by room and weekday.
 *
 * DELIBERATELY THE SAME ROW SHAPE as day_requests_year(), because the cell panel
 * shows the two in one list and a family should not read differently depending
 * on which system happens to hold their request. The fields that only this one
 * can carry - the note, who recorded it, the days the child already attends --
 * are additions, not a different vocabulary.
 *
 * @param array $opts 'contacts' => false to skip the two Kidsoft calls that
 *                    resolve a phone number (they are cached, but a caller that
 *                    only needs counts should not pay for them at all).
 * @return array ourRoomId => weekday => list of requests
 */
function internal_requests_year(int $centreId, int $year, array $opts = []): array {
    $rows = dbx()->query(
        "SELECT r.*, GROUP_CONCAT(d.weekday ORDER BY d.weekday) AS days
           FROM internal_day_requests r
           JOIN internal_day_request_days d
             ON d.request_id = r.id AND d.granted_at IS NULL
          WHERE r.centre_id = ? AND r.year = ? AND r.status = 'open'
          GROUP BY r.id
          ORDER BY r.requested_on, r.id",
        [$centreId, $year], 'ii'
    )->fetchAll();
    if (!$rows) return [];

    // Who to ring, for the children that actually appear on this list. A wish
    // with no number is still worth showing - the note is the point - so a
    // Kidsoft outage costs the phone column and nothing else.
    $contacts = [];
    if (($opts['contacts'] ?? true) !== false) {
        try {
            $centre = centre_get($centreId);
            if ($centre && $centre['kidsoft_service_id']) {
                $contacts = child_contacts($centre['kidsoft_service_id']);
            }
        } catch (RuntimeException $e) {
            $contacts = [];
        }
    }

    $out = [];
    foreach ($rows as $r) {
        $days = array_map('intval', explode(',', (string)$r['days']));
        $c    = $contacts[$r['kidsoft_child_id'] ?? ''] ?? null;

        // One row PER WEEKDAY, because the cell is the unit of work everywhere in
        // this app. A wish for Wednesday and Thursday is two calls to make only
        // if the two days come free separately, which is exactly when it appears
        // on two cells.
        foreach ($days as $wd) {
            $out[(int)$r['room_id']][$wd][] = [
                'id'               => (int)$r['id'],
                // WHERE THIS ROW CAME FROM. The panel says so on screen: a team
                // that cannot tell "the parent asked Kidsoft" from "we wrote this
                // down" cannot tell which of the two to trust when they disagree.
                'source'           => 'internal',
                'kidsoft_child_id' => $r['kidsoft_child_id'],
                'placement_id'     => $r['placement_id'] === null ? null : (int)$r['placement_id'],
                'name'             => $r['child_name'],
                'dob'              => null,
                'guardian'         => $c['guardian'] ?? null,
                'guardian_email'   => $c['email'] ?? null,
                'guardian_phone'      => $c['phone'] ?? null,
                'guardian_phone_type' => $c['phone_type'] ?? null,
                'guardian_phone_is_parent' => $c['is_parent'] ?? null,
                'status'           => 'Waiting',
                'requested_at'     => $r['requested_on'],
                // THE FEATURE. What the parent said, in their words.
                'note'             => $r['note'],
                'recorded_by'      => $r['created_by'],
                'recorded_at'      => $r['created_at'],
                // Every weekday this child is waiting on, so one call can settle
                // the lot rather than the day whose cell happens to be open.
                'wish_days'        => $days,
                'dates'            => [],
                'from'             => null,
            ];
        }
    }
    return $out;
}

/**
 * One wish, with its days. Null when it does not exist.
 */
function internal_request_get(int $id): ?array {
    $r = dbx()->query('SELECT * FROM internal_day_requests WHERE id = ?', [$id], 'i')->fetch();
    if (!$r) return null;
    $r['id']    = (int)$r['id'];
    $r['days']  = array_map('intval', dbx()->query(
        'SELECT weekday FROM internal_day_request_days WHERE request_id = ? AND granted_at IS NULL ORDER BY weekday',
        [$id], 'i')->fetchAll('col'));
    $r['granted_days'] = array_map('intval', dbx()->query(
        'SELECT weekday FROM internal_day_request_days WHERE request_id = ? AND granted_at IS NOT NULL ORDER BY weekday',
        [$id], 'i')->fetchAll('col'));
    return $r;
}

/**
 * Record or amend a wish.
 *
 * ONE OPEN WISH PER (CHILD, ROOM, YEAR), and it is merged rather than duplicated:
 * two staff taking the same call on the same morning is the obvious way to get
 * two rows, and it is the same reason work_create() is idempotent. Amending is
 * therefore the normal path - the parent rings back and adds Thursday.
 *
 * @param array $d centre_id, room_id, year, days[], plus kidsoft_child_id OR
 *                 placement_id, child_name, note, requested_on
 * @return array{id:int, created:bool}
 */
function internal_request_save(array $d): array {
    $centreId = (int)($d['centre_id'] ?? 0);
    $roomId   = (int)($d['room_id'] ?? 0);
    $year     = (int)($d['year'] ?? 0);
    $ksId     = ($d['kidsoft_child_id'] ?? '') !== '' ? (string)$d['kidsoft_child_id'] : null;
    $placeId  = (int)($d['placement_id'] ?? 0) ?: null;
    $name     = trim((string)($d['child_name'] ?? ''));
    $days     = array_values(array_unique(array_filter(
        array_map('intval', $d['days'] ?? []), static fn($w) => $w >= 1 && $w <= 5)));
    sort($days);

    if (!$centreId || !$roomId || !$year) throw new InvalidArgumentException('centre, room and year are required');
    if (!$days)  throw new InvalidArgumentException('Pick at least one day they are waiting for.');
    if ($name === '') throw new InvalidArgumentException('A request has to name a child.');
    // "Exactly one identifier" is enforced here rather than by a CHECK, for the
    // reason work_items records: placement_id is an ON DELETE CASCADE foreign
    // key, and a CHECK over it would fight the cascade from the outside.
    if ($ksId === null && $placeId === null) {
        throw new InvalidArgumentException('A request has to point at a child on the board.');
    }

    $existing = $ksId !== null
        ? dbx()->query("SELECT id FROM internal_day_requests
                         WHERE kidsoft_child_id = ? AND room_id = ? AND year = ? AND status = 'open'",
                       [$ksId, $roomId, $year], 'sii')->fetch()
        : dbx()->query("SELECT id FROM internal_day_requests
                         WHERE placement_id = ? AND room_id = ? AND year = ? AND status = 'open'",
                       [$placeId, $roomId, $year], 'iii')->fetch();

    $requested = (string)($d['requested_on'] ?? '') !== ''
        ? (string)$d['requested_on']
        : date_create('today', new DateTimeZone('Australia/Sydney'))->format('Y-m-d');
    $note = trim((string)($d['note'] ?? ''));
    $note = $note === '' ? null : mb_substr($note, 0, 500);

    if ($existing) {
        $id = (int)$existing['id'];
        dbx()->query('UPDATE internal_day_requests SET note = ?, requested_on = ? WHERE id = ?',
                     [$note, $requested, $id], 'ssi');
        internal_request_set_days($id, $days);
        audit('internal_request.update', 'internal_day_request', $id,
              ['days' => $days, 'note' => $note]);
        return ['id' => $id, 'created' => false];
    }

    dbx()->query(
        'INSERT INTO internal_day_requests
           (centre_id, room_id, year, kidsoft_child_id, placement_id, child_name,
            note, requested_on, created_by)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
        [$centreId, $roomId, $year, $ksId, $placeId, mb_substr($name, 0, 160),
         $note, $requested, $d['created_by'] ?? ($_SESSION['user']['email'] ?? null)]
    );
    $id = dbx()->insertId();
    internal_request_set_days($id, $days);
    audit('internal_request.create', 'internal_day_request', $id,
          ['child' => $name, 'room_id' => $roomId, 'year' => $year, 'days' => $days]);
    return ['id' => $id, 'created' => true];
}

/**
 * Replace the days a wish is waiting on.
 *
 * A DAY ALREADY GRANTED IS NEVER RE-OPENED by an edit. The stamp says Kidsoft
 * showed the child in the room on that day; clearing it because somebody
 * re-ticked the box would tell the board a place is still wanted when it has
 * already been taken - and that place would then read as demand on a cell that
 * is full.
 */
function internal_request_set_days(int $id, array $days): void {
    $granted = array_map('intval', dbx()->query(
        'SELECT weekday FROM internal_day_request_days WHERE request_id = ? AND granted_at IS NOT NULL',
        [$id], 'i')->fetchAll('col'));

    dbx()->query('DELETE FROM internal_day_request_days WHERE request_id = ? AND granted_at IS NULL',
                 [$id], 'i');
    foreach ($days as $wd) {
        if (in_array((int)$wd, $granted, true)) continue;
        dbx()->query('INSERT IGNORE INTO internal_day_request_days (request_id, weekday) VALUES (?, ?)',
                     [$id, (int)$wd], 'ii');
    }
}

/**
 * Close a wish by hand: the family took the day elsewhere, or no longer wants it.
 *
 * 'granted' is available here but is NOT how a wish normally ends - see
 * internal_requests_close_met(). A person closing one as granted is recording
 * something that happened outside this app, and the audit row says who said so.
 */
function internal_request_close(int $id, string $status, ?string $note = null): bool {
    if (!in_array($status, ['granted', 'withdrawn'], true)) {
        throw new InvalidArgumentException('A request closes as granted or withdrawn.');
    }
    $r = internal_request_get($id);
    if (!$r || $r['status'] !== 'open') return false;

    dbx()->query('UPDATE internal_day_requests SET status = ?, closed_note = ?, closed_at = NOW() WHERE id = ?',
                 [$status, $note === '' ? null : $note, $id], 'ssi');
    audit('internal_request.close', 'internal_day_request', $id,
          ['status' => $status, 'child' => $r['child_name']]);
    return true;
}

/**
 * Grant every wish the board has already met.
 *
 * THE WHOLE POINT: nobody marks these done. When the derived board shows the
 * child booked in that room on that weekday, the day they were waiting for has
 * arrived and the wish for it closes itself - which is also the moment the
 * organisation starts being paid for that place, the only honest definition of
 * success this app uses anywhere.
 *
 * MATCHED ON kidsoft_child_id ONLY, never on a name, and a provisional child is
 * therefore never closed automatically. Same asymmetry as work_autoclose():
 * closing the wrong family's request says a place is spoken for when it is free,
 * and an empty place nobody can see is the failure this app exists to prevent. A
 * miss costs one manual close.
 *
 * @param array $board The already-loaded board_load() result - passed in rather
 *                     than re-read, so this costs one query and no Kidsoft call.
 * @return int how many days were granted
 */
function internal_requests_close_met(array $board): int {
    $centreId = (int)$board['centre']['id'];
    $year     = (int)$board['year'];

    $open = dbx()->query(
        "SELECT r.id, r.room_id, r.kidsoft_child_id, r.child_name, d.weekday
           FROM internal_day_requests r
           JOIN internal_day_request_days d
             ON d.request_id = r.id AND d.granted_at IS NULL
          WHERE r.centre_id = ? AND r.year = ? AND r.status = 'open'
            AND r.kidsoft_child_id IS NOT NULL",
        [$centreId, $year], 'ii'
    )->fetchAll();
    if (!$open) return 0;

    // (room, weekday) => the Kidsoft children actually holding a place there.
    $held = [];
    foreach ($board['rooms'] as $room) {
        foreach ($room['cells'] as $wd => $cell) {
            foreach ($cell['children'] as $ch) {
                if ($ch['ks_id'] !== null) $held[(int)$room['id'] . '|' . (int)$wd][$ch['ks_id']] = true;
            }
        }
    }

    $n = 0; $touched = [];
    foreach ($open as $row) {
        $key = (int)$row['room_id'] . '|' . (int)$row['weekday'];
        if (!isset($held[$key][$row['kidsoft_child_id']])) continue;
        dbx()->query('UPDATE internal_day_request_days SET granted_at = NOW()
                       WHERE request_id = ? AND weekday = ?',
                     [(int)$row['id'], (int)$row['weekday']], 'ii');
        $touched[(int)$row['id']] = $row['child_name'];
        $n++;
    }
    if (!$n) return 0;

    // A wish with nothing left outstanding is finished. One with a day still
    // waiting stays open - a family who got the Wednesday and also wanted the
    // Thursday has not had their answer.
    foreach ($touched as $id => $name) {
        $left = (int)dbx()->query(
            'SELECT COUNT(*) AS n FROM internal_day_request_days WHERE request_id = ? AND granted_at IS NULL',
            [$id], 'i')->fetch()['n'];
        if ($left > 0) continue;
        dbx()->query("UPDATE internal_day_requests
                         SET status = 'granted', closed_at = NOW(),
                             closed_note = 'Kidsoft shows the child booked on every day they were waiting for.'
                       WHERE id = ? AND status = 'open'", [$id], 'i');
        audit('internal_request.granted', 'internal_day_request', $id,
              ['child' => $name, 'actor' => 'kidsoft']);
    }
    return $n;
}

/**
 * Open wishes for one centre-year keyed by child, for the room roster.
 *
 * The roster is where a wish is recorded - each child's Mon-Fri strip already
 * shows the days they do not attend, so an empty box is exactly the thing being
 * asked for. This tells it which boxes are already wished for.
 *
 * @return array roomId => childKey => ['id'=>int,'days'=>[wd,...],'note'=>?string]
 */
function internal_requests_by_child(int $centreId, int $year): array {
    $rows = dbx()->query(
        "SELECT r.id, r.room_id, r.kidsoft_child_id, r.placement_id, r.note, r.requested_on,
                GROUP_CONCAT(d.weekday ORDER BY d.weekday) AS days
           FROM internal_day_requests r
           JOIN internal_day_request_days d
             ON d.request_id = r.id AND d.granted_at IS NULL
          WHERE r.centre_id = ? AND r.year = ? AND r.status = 'open'
          GROUP BY r.id",
        [$centreId, $year], 'ii'
    )->fetchAll();

    $out = [];
    foreach ($rows as $r) {
        // Same key shape the board uses for a child: 'k' . ChildID for a Kidsoft
        // child, 'p' . row id for one pencilled in ahead of Kidsoft. Prefixed so
        // the two can never collide - see cells_year().
        $key = $r['kidsoft_child_id'] !== null
            ? 'k' . $r['kidsoft_child_id']
            : 'p' . (int)$r['placement_id'];
        $out[(int)$r['room_id']][$key] = [
            'id'           => (int)$r['id'],
            'days'         => array_map('intval', explode(',', (string)$r['days'])),
            'note'         => $r['note'],
            'requested_on' => $r['requested_on'],
        ];
    }
    return $out;
}
