<?php
// Nightly maintenance. Install via /etc/cron.d/crm-nightly.
//
//   php _incl/cron-nightly.php             # do the work
//   php _incl/cron-nightly.php --dry-run   # report only, change nothing
//   php _incl/cron-nightly.php --contacts  # ONLY the intra-day contact warm
//   php _incl/cron-nightly.php --warm      # ONLY the hourly board warm
//   php _incl/cron-nightly.php --birthdays # ONLY the Monday birthday email
//                                          #   (--to=a@b sends a test copy there instead;
//                                          #    --from=YYYY-MM-DD re-sends a missed Monday)
//
// --contacts is a separate, much cheaper job on its own schedule (see
// /etc/cron.d/crm-contacts) and does none of the work below. It exists
// because the two calls behind "who do I ring" run a 3 h TTL where everything
// else warmed here has 36 h: they back the search palette, and a parent's new
// mobile number being unfindable until tomorrow defeats the point of the
// feature. Warming every 2 h keeps search answering from fresh data all day.
//
// Three jobs, in order of how much it hurts when they are skipped:
//
//   1. BACK UP THE DATABASE. This database is the system of record for the
//      waitlist, the board and the contact log - none of which exists anywhere
//      else. Kidsoft can rebuild bookings; it cannot rebuild any of ours.
//   2. Prune the Kidsoft response cache. Every centre-year anyone looks at
//      leaves ~4MB of gzipped bookings behind and nothing removed them, so the
//      table grew without bound. It is excluded from the dump in job 1.
//   3. Sweep expired offers. The app already does this on page load, so this is
//      a safety net for a quiet week rather than the primary mechanism.
//   4. Refresh linked children's names and DOBs from Kidsoft. The board caches
//      them so it can render when Kidsoft is down; this keeps the cache honest
//      for centres nobody happened to open that day.
//   5. Refresh room capacities from Kidsoft (Service/getUtilisation reports
//      OperationalPositions per room per weekday). Nobody types capacities.
//   6. Refresh the new-enrolment queue from Waitlist/getWaitingList, so a family
//      who applied overnight is in the morning's queue. 7 calls, ~3s.
//   7. Copy contact notes to Kidsoft for families who have since become visible
//      there. THE ONLY STEP IN THIS FILE THAT WRITES TO KIDSOFT. It runs after
//      job 6 because that is what makes a family writable: the ChildID arrives
//      when their application converts.
//   8. Warm a year of bookings per centre, so the board's first load of the day
//      is a tenth of a second rather than the 33s a cold year costs.
//
// Exits non-zero if any job fails, so cron mail (or the log) shows a real error
// rather than a silent no-op.

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

// Pins Australia/Sydney. This file used to call date_default_timezone_set()
// itself; it is in dates.php now, so the CLI jobs and the web path cannot drift
// apart - which they had, the web path having never pinned it at all.
require_once __DIR__ . '/dates.php';
require_once __DIR__ . '/simple-mysqli.php';
require_once __DIR__ . '/waitlist.php';
require_once __DIR__ . '/waitlist-import.php';
require_once __DIR__ . '/identity.php';
require_once __DIR__ . '/capacity.php';
require_once __DIR__ . '/rooms-sync.php';  // rooms_sync_all() - rooms are Kidsoft's, not ours
require_once __DIR__ . '/centres-sync.php';
require_once __DIR__ . '/occupancy.php';   // bookings_year() for the nightly warm
require_once __DIR__ . '/contacts.php';    // child_contacts() for the nightly warm
require_once __DIR__ . '/search.php';      // search_history_warm() for the nightly warm
require_once __DIR__ . '/tenure.php';
require_once __DIR__ . '/reenrolment.php';  // the round, warmed for the Dashboard       // tenure_snapshot() for the nightly warm
require_once __DIR__ . '/rooms.php';       // centres_all(); loaded transitively, named here on purpose
require_once __DIR__ . '/board.php';               // board_load() for the wish sweep
require_once __DIR__ . '/internal-requests.php';   // internal_requests_close_met()
require_once __DIR__ . '/comments.php';            // comment_push_backlog() - the one write
require_once __DIR__ . '/birthdays.php';           // the Monday birthday email (--birthdays)
require_once __DIR__ . '/graph.php';               // graph_send_mail()
require_once __DIR__ . '/audit.php';

$dryRun = in_array('--dry-run', $argv, true);

// Outside the web root on purpose: a dump of this database is a file of
// children's names, dates of birth and guardians' contact details, and inside
// /var/www it would be downloadable.
const BACKUP_DIR   = '/var/backups/crm';
const BACKUP_KEEP  = 14;    // nightly dumps retained
const CACHE_KEEP_DAYS = 3;  // longest cache TTL is 36h, so 3 days is already dead

$log = static function (string $msg): void {
    echo date('Y-m-d H:i:s'), '  ', $msg, "\n";
};
$failures = 0;

// --- Intra-day contact warm (--contacts) ------------------------------------
// Its own job, its own schedule, and it returns before any of the nightly work
// below. child_contacts(refresh: true) reads BOTH halves of the answer to "who
// do I ring about this child" live - Guardian/getGuardians (the numbers) and
// Children/getChildren?Options=Guardian (which guardian belongs to which child).
// Both run a 3 h TTL against everything else's 36 h, and this is what keeps that
// honest. It does NOT touch the plain-shaped getChildren the board engines read.
//
// A Kidsoft outage is NOT a failure. Search keeps answering from the last good
// copy, which is the whole reason the cache exists - exiting non-zero would
// mail the team about a problem they cannot act on and that costs them nothing.
if (in_array('--contacts', $argv, true)) {
    $log($dryRun ? 'contact warm (DRY RUN)' : 'contact warm');
    $centres = centres_all();
    if ($dryRun) {
        $log('would refresh contacts for ' . count($centres) . ' centre(s)');
        exit(0);
    }
    $t0 = microtime(true);
    $ok = $tried = 0;
    foreach ($centres as $c) {
        $tried++;
        try {
            child_contacts($c['kidsoft_service_id'], true);   // true = read live
            $ok++;
        } catch (Throwable $e) {
            $log("  {$c['name']}: " . $e->getMessage());
        }
    }
    $log(sprintf('contacts: %d of %d centre(s) in %.0fs', $ok, $tried, microtime(true) - $t0));
    exit(0);
}

// --- Hourly board warm (--warm) ---------------------------------------------
// The other intra-day job, and like --contacts it returns before any of the
// nightly work below. It reads ONE MONTH of bookings per centre per year live --
// board_reference_month(), the month the placement board is actually derived
// from - so that "who is in which room on which day" is close to what Kidsoft
// says rather than up to a day behind it.
//
// WHY THIS EXISTS AT ALL (13 Aug 2026, Broc, on the 36 h figure: it "seems kind
// of long in relation to the purpose and goals of this project"). It was long
// because this call SHARES its KIDSOFT_TTL entry with bookings_year(), whose
// twelve monthly reads per centre-year are the expensive thing that entry was
// written for. The board is not that. Measured live the same day:
//
//   the board's reference month, all 7 centres   14.0s   10,953 bookings
//   the largest single centre (Tuggerah)          4.0s    3,347 bookings
//   Warnervale, which is not trading              0.2s        0 bookings
//
// Two years each, so 19s a run measured end to end, and about 4 minutes of API
// time across the working day.
// board_live_children() carries the matching 15-minute TTL for the current year.
//
// IT BREAKS THE "TTL MUST EXCEED THE WARM INTERVAL" RULE ON PURPOSE, exactly as
// the re-enrolment warm in job 8 does, and for the same reason: the two are
// doing different jobs. The 15-minute TTL sets how STALE the board may ever be;
// this hourly run only guarantees a row EXISTS and is recent. An expired row is
// served instantly and refreshed after the response
// (kidsoft_cache_run_deferred()), so expiry costs a background fetch and no
// user-visible wait - a MISSING row is a 4-second stall in front of whoever
// opens that centre first. Warm for existence, short TTL for freshness.
//
// A Kidsoft outage is NOT a failure here, for the same reason it is not one in
// --contacts: yesterday's board still renders, and mailing the team about an
// outage they cannot act on is noise.
if (in_array('--warm', $argv, true)) {
    $log($dryRun ? 'board warm (DRY RUN)' : 'board warm');
    $centres  = centres_all();
    $thisYear = (int)date('Y');
    $years    = [$thisYear, $thisYear + 1];
    if ($dryRun) {
        $log('would warm ' . count($centres) . ' centre(s) x ' . count($years)
             . ' year(s) of board bookings (one reference month each)');
        exit(0);
    }
    $t0 = microtime(true);
    $ok = $tried = 0;
    $skipped = 0;
    foreach ($centres as $c) {
        foreach ($years as $y) {
            // A CENTRE THAT HAS NOT OPENED IN THIS YEAR IS NOT WARMED. There is
            // no board to read and no screen left that will ask for one - the
            // placement grid, tenure and the dashboard all return before they
            // reach Kidsoft now (see centre_not_open_yet()). This runs EVERY
            // HOUR, so it is the one place the saving repeats: Warnervale 2026
            // was a live Kidsoft round trip, 24 times a day, for a centre with
            // no doors open.
            if (centre_not_open_yet($c, $y)) { $skipped++; continue; }
            $tried++;
            try {
                // refresh => bypass the cache, which is what makes this a warm
                // rather than a read of whatever is already there.
                board_live_children((int)$c['id'], $y, ['refresh' => true]);
                $ok++;
            } catch (Throwable $e) {
                $log("  {$c['name']} {$y}: " . $e->getMessage());
            }
        }
    }
    $log(sprintf('board warm: %d of %d centre-year(s) in %.0fs%s', $ok, $tried,
                 microtime(true) - $t0,
                 $skipped ? ", {$skipped} not open yet" : ''));
    exit(0);
}

// --- Tours into the centres' Outlook calendars (--calendar) -----------------
// Its own job (/etc/cron.d/crm-calendar, every 15 minutes 06:00-20:45).
// /api/work writes a tour's calendar entry straight after each save; this is the
// retry for whatever that missed - Microsoft not answering, or the permission not
// granted yet - and it is how every upcoming tour reaches the calendar on the day
// Calendars.ReadWrite is consented. Items already in step cost no call. See
// _incl/tour-calendar.php.
//
// A failure is NOT a non-zero exit: it is recorded on the item and shown in its
// modal, which is where somebody can act on it, and until the permission is
// granted every run would otherwise fail.
//
// SUPPORT PLAN BOOKED DAYS ride the same job (29 Oct 2026): the same mailboxes,
// the same permission, the same retry. It is also what takes an upcoming entry
// out when a child's plan flag is unticked on the placement board, which writes
// through /api/board and knows nothing of this. See support-plan-calendar.php.
if (in_array('--calendar', $argv, true)) {
    require_once __DIR__ . '/tour-calendar.php';
    require_once __DIR__ . '/support-plan-calendar.php';
    foreach (['tour calendar' => 'tour_calendar_sweep', 'support plan calendar' => 'plan_calendar_sweep'] as $what => $sweep) {
        $r = $sweep($dryRun);
        if ($dryRun) {
            $log("{$what} (DRY RUN): would check {$r['checked']} row(s)");
        } elseif ($r['failed'] || $r['removed'] || $r['checked'] !== $r['in'] + $r['none']) {
            // Quiet when nothing changed - this runs 60 times a day.
            $log("{$what}: {$r['checked']} checked, {$r['in']} in the calendar, "
               . "{$r['removed']} removed, {$r['failed']} failed");
        }
    }
    exit(0);
}

// --- Monday birthday email (--birthdays) ------------------------------------
// Its own job on its own schedule (/etc/cron.d/crm-birthdays, 07:00
// Monday). Children in care with a birthday in the next 14 days, to the parents
// accounts team, who move each child to the next fee band by hand. See
// _incl/birthdays.php.
//
// A centre Kidsoft does not answer for IS a failure here, unlike the warms: the
// email still goes, carrying a banner naming the centre, but the exit status is
// non-zero so the log shows it. Nothing else will ever show it - an empty table
// for a centre reads exactly like "no birthdays".
//
// --to=a@b,c@d sends to those addresses instead, for a test copy. Without it the
// email goes to BIRTHDAY_REPORT_TO and nowhere else.
//
// --from=2026-10-05 starts the 14 days there instead of today: the email a missed
// Monday should have sent. The first real Monday failed (Mail.Send not yet
// consented) and was re-sent this way on 6 Oct 2026.
if (in_array('--birthdays', $argv, true)) {
    $to   = BIRTHDAY_REPORT_TO;
    $from = date('Y-m-d');
    foreach ($argv as $a) {
        if (str_starts_with($a, '--to=')) $to = array_values(array_filter(array_map('trim', explode(',', substr($a, 5)))));
        if (str_starts_with($a, '--from=')) {
            $from = substr($a, 7);
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) || !checkdate((int)substr($from, 5, 2), (int)substr($from, 8, 2), (int)substr($from, 0, 4))) {
                $log("birthday email: --from wants YYYY-MM-DD, got {$from}");
                exit(1);
            }
        }
    }
    $log($dryRun ? 'birthday email (DRY RUN)' : 'birthday email');
    $t0 = microtime(true);
    $report = birthdays_upcoming($from);
    foreach ($report['centres'] as $name => $rows) $log("  {$name}: " . count($rows));
    foreach ($report['failed'] as $name) $log("  {$name}: Kidsoft did not answer");
    $n = birthdays_count($report);
    if ($dryRun) {
        $log(sprintf('would send %d child(ren), %s to %s, to %s', $n, $report['from'], $report['to'], implode(', ', $to)));
        exit($report['failed'] ? 1 : 0);
    }
    try {
        graph_send_mail($to, birthdays_email_subject($report), birthdays_email_html($report));
    } catch (Throwable $e) {
        $log('birthday email FAILED: ' . $e->getMessage());
        exit(1);
    }
    // Children's names and birthdays leaving the app for a mailbox, so it is
    // recorded - the count and where it went, not the children.
    audit('report.birthdays_sent', 'report', 'birthdays', [
        'to' => $to, 'from' => $report['from'], 'until' => $report['to'],
        'children' => $n, 'missing_centres' => $report['failed'],
    ]);
    $log(sprintf('birthday email: %d child(ren) sent to %s in %.0fs', $n, implode(', ', $to), microtime(true) - $t0));
    exit($report['failed'] ? 1 : 0);
}

$log($dryRun ? 'nightly maintenance (DRY RUN)' : 'nightly maintenance');

// --- 1. Database backup -----------------------------------------------------
try {
    $cfg = require __DIR__ . '/db-config.php';

    if (!is_dir(BACKUP_DIR)) {
        if ($dryRun) {
            $log('would create ' . BACKUP_DIR);
        } elseif (!mkdir(BACKUP_DIR, 0700, true) && !is_dir(BACKUP_DIR)) {
            throw new RuntimeException('cannot create ' . BACKUP_DIR);
        }
    }
    if (!$dryRun) chmod(BACKUP_DIR, 0700);

    $file = BACKUP_DIR . '/crm-' . date('Y-m-d') . '.sql.gz';

    if ($dryRun) {
        $log('would dump to ' . $file);
    } else {
        // Dump to a temp file and only move it into place once it has been
        // verified. Writing straight to $file would let a FAILED run destroy the
        // good backup already taken today - and a few days of that, plus
        // rotation, silently leaves nothing but junk.
        $tmp = $file . '.part';
        @unlink($tmp);

        // The password goes in a 0600 defaults-file, never on the command line:
        // anything in argv is visible to every user on the box via ps.
        $defaults = tempnam(sys_get_temp_dir(), 'attdump');
        if ($defaults === false) throw new RuntimeException('cannot create temp defaults file');
        chmod($defaults, 0600);
        file_put_contents($defaults, "[client]\nuser=" . $cfg['user'] . "\npassword=\"" . $cfg['pass'] . "\"\nhost=" . $cfg['host'] . "\n");

        // PIPESTATUS, not $?: the exit status of a pipeline is gzip's, and gzip
        // happily succeeds at compressing mysqldump's error message.
        //
        // kidsoft_cache is EXCLUDED, structure kept. It is a disposable copy of
        // data Kidsoft still holds - restoring it would be pointless, and it is
        // now by far the largest table: the board reads a whole year of bookings
        // per centre, which is 20.6MB of JSON before compression. Carrying that
        // into all 14 retained dumps would bloat every one of them, and these
        // dumps are files of children's names, dates of birth and attendance, so
        // making them larger and slower to write is the opposite of the point.
        // Two passes into one stream: everything but the cache's rows, then the
        // cache's STRUCTURE ONLY. --ignore-table alone would drop the CREATE
        // TABLE as well, and a restore would come back missing a table the app
        // needs. With `&&` the group's status is the first failure, so
        // PIPESTATUS[0] still catches a broken dump, and the second pass also
        // ends with "Dump completed" so the marker check below still holds.
        $base = 'mysqldump --defaults-extra-file=' . escapeshellarg($defaults) .
                ' --single-transaction --quick --no-tablespaces';
        $db    = escapeshellarg($cfg['name']);
        $cache = escapeshellarg($cfg['name'] . '.kidsoft_cache');
        $cmd = sprintf(
            '{ %s --routines --ignore-table=%s %s && %s --no-data %s kidsoft_cache; } 2>&1 | gzip -9 > %s; exit ${PIPESTATUS[0]}',
            $base, $cache, $db, $base, $db, escapeshellarg($tmp)
        );
        exec('/bin/bash -c ' . escapeshellarg($cmd), $out, $rc);
        unlink($defaults);

        try {
            if ($rc !== 0) throw new RuntimeException('mysqldump exit ' . $rc);
            $size = @filesize($tmp);
            if ($size === false || $size < 512) {
                throw new RuntimeException('dump looks truncated (' . (int)$size . ' bytes)');
            }
            // mysqldump writes this as its last line; anything else means the dump
            // stopped early, however valid the gzip wrapper looks.
            $tail = shell_exec('gzip -dc ' . escapeshellarg($tmp) . ' 2>/dev/null | tail -c 200');
            if (!is_string($tail) || !str_contains($tail, 'Dump completed')) {
                throw new RuntimeException('dump is incomplete - no completion marker');
            }
        } catch (Throwable $e) {
            @unlink($tmp);   // never leave a bad dump where a good one should be
            throw $e;
        }

        chmod($tmp, 0600);
        if (!rename($tmp, $file)) throw new RuntimeException('cannot move dump into place');
        $log(sprintf('backup ok: %s (%.1f KB)', basename($file), $size / 1024));
    }

    // Rotate.
    $old = glob(BACKUP_DIR . '/crm-*.sql.gz') ?: [];
    sort($old);
    $excess = max(0, count($old) - BACKUP_KEEP);
    foreach (array_slice($old, 0, $excess) as $f) {
        if ($dryRun) { $log('would remove ' . basename($f)); continue; }
        unlink($f);
        $log('removed old backup ' . basename($f));
    }
} catch (Throwable $e) {
    $failures++;
    $log('BACKUP FAILED: ' . $e->getMessage());
}

// --- 2. Prune the Kidsoft cache ---------------------------------------------
try {
    $stale = dbx()->query(
        'SELECT COUNT(*) c, COALESCE(SUM(LENGTH(payload)),0) b FROM kidsoft_cache
         WHERE fetched_at < (NOW() - INTERVAL ? DAY)', [CACHE_KEEP_DAYS], 'i'
    )->fetch();
    if ($dryRun) {
        $log(sprintf('would prune %d cache rows (%.1f KB)', $stale['c'], $stale['b'] / 1024));
    } else {
        dbx()->query('DELETE FROM kidsoft_cache WHERE fetched_at < (NOW() - INTERVAL ? DAY)',
                     [CACHE_KEEP_DAYS], 'i');
        $log(sprintf('pruned %d cache rows (%.1f KB freed)', dbx()->affectedRows(), $stale['b'] / 1024));
    }
} catch (Throwable $e) {
    $failures++;
    $log('CACHE PRUNE FAILED: ' . $e->getMessage());
}

// --- 3. Expire lapsed offers ------------------------------------------------
try {
    if ($dryRun) {
        $due = dbx()->query(
            "SELECT COUNT(*) c FROM offers WHERE status = 'pending' AND expires_at < NOW()"
        )->fetch()['c'];
        $log('would expire ' . $due . ' offer(s)');
    } else {
        $n = offers_expire_due();
        $log('expired ' . $n . ' offer(s), releasing their held days');
    }
} catch (Throwable $e) {
    $failures++;
    $log('OFFER SWEEP FAILED: ' . $e->getMessage());
}

// --- 3b. Grant internal day-waitlist entries the board has already met -------
// A wish closes when Kidsoft shows the child booked on the day they were waiting
// for - never because somebody ticked it. Opening a centre's board does that
// sweep for the year on screen (cells_year()), so this picks up the centres and
// years nobody opened: without it, a family granted their Wednesday in October
// stays on the December board as unmet demand until somebody happens to look.
// Kidsoft being unreachable is not a failure - the wish simply stays open,
// which is the safe direction.
try {
    $years = [(int)date('Y'), (int)date('Y') + 1];
    if ($dryRun) {
        $open = dbx()->query(
            "SELECT COUNT(*) c FROM internal_day_requests WHERE status = 'open'"
        )->fetch()['c'];
        $log('would check ' . $open . ' open internal waitlist entr(y/ies) against the board');
    } else {
        $granted = 0;
        foreach (centres_all() as $c) {
            foreach ($years as $y) {
                // Per centre-year, so one unreachable centre does not cost the
                // others their sweep - the same rule the queue import follows.
                try { $granted += internal_requests_close_met(board_load((int)$c['id'], $y)); }
                catch (Throwable $e) { $log('  ' . $c['name'] . ' ' . $y . ': ' . $e->getMessage()); }
            }
        }
        $log('granted ' . $granted . ' internal waitlist day(s) that Kidsoft now shows as booked');
    }
} catch (Throwable $e) {
    $failures++;
    $log('INTERNAL WAITLIST SWEEP FAILED: ' . $e->getMessage());
}

// --- 4. Refresh linked child identities from Kidsoft ------------------------
// Kidsoft owns a linked child's name and DOB; placements hold a cache so the
// board still renders when Kidsoft is unreachable. Viewing a centre refreshes
// its rows, so this only picks up centres nobody opened. A Kidsoft outage is
// NOT a failure here - yesterday's cached names remain perfectly serviceable.
try {
    $stale = dbx()->query(
        'SELECT COUNT(*) c FROM placements
         WHERE kidsoft_child_id IS NOT NULL
           AND (identity_synced_at IS NULL OR identity_synced_at < (NOW() - INTERVAL ? HOUR))',
        [IDENTITY_TTL_HOURS], 'i'
    )->fetch()['c'];

    if ($dryRun) {
        $log('would refresh ' . $stale . ' linked child identity/identities from Kidsoft');
    } else {
        $changed = 0;
        foreach (centres_all() as $c) $changed += placements_sync_identity((int)$c['id']);
        $log("refreshed {$stale} stale identity/identities, {$changed} had changed in Kidsoft");
    }
} catch (Throwable $e) {
    $failures++;
    $log('IDENTITY REFRESH FAILED: ' . $e->getMessage());
}

// --- 5. Refresh rooms and their capacities from Kidsoft ---------------------
// Service/getRooms owns a room's name, age band and whether it is operating;
// Service/getUtilisation reports OperationalPositions per room per weekday and is
// the source of every capacity in this database. NOBODY TYPES ANY OF IT - the
// Rooms screen has no editor for a room, so this job is the only thing that keeps
// them true. Rooms are renamed, re-licensed and re-purposed, so a night's drift
// is a room somebody cannot find and a denominator that is quietly wrong.
// A Kidsoft outage is not a failure: the stored figures remain serviceable, and
// rooms_sync_centre() deliberately creates and retires NOTHING on a run where the
// utilisation read came back empty.
try {
    $thisYear = (int)date('Y');
    if ($dryRun) {
        $log('would look for centres Kidsoft has that we do not');
        $log("would refresh rooms and capacities for {$thisYear} and " . ($thisYear + 1) . ' from Kidsoft');
    } else {
        // CENTRES FIRST, because rooms_sync_all() iterates the centres we hold --
        // a centre discovered after it had run would sit empty until tomorrow.
        // This is the only automatic writer of that table; before 11 Aug 2026
        // there was none, and a centre opening needed somebody with SSH.
        $s = centres_sync(0);
        $log("centres: {$s['checked']} service(s), {$s['created']} created"
           . ($s['names'] ? ' (' . implode(', ', $s['names']) . ')' : '')
           . ", {$s['retired']} no longer active, {$s['restored']} reopened, "
           . "{$s['missing']} not returned by Kidsoft");
        foreach ([$thisYear, $thisYear + 1] as $y) {
            $q = rooms_sync_all($y, 0);      // 0 = bypass cache, read live
            $log("rooms {$y}: {$q['checked']} read, {$q['created']} created, "
               . "{$q['renamed']} renamed, {$q['aged']} age range(s) changed, "
               . "{$q['retired']} retired, {$q['restored']} reopened, "
               . "{$q['missing']} not returned by Kidsoft, {$q['unlinked']} unlinked"
               . ($q['clashes'] ? ', NAME CLASH: ' . implode(', ', $q['clashes']) : ''));
            $r = capacity_sync_all($y, 0);
            $log("capacity {$y}: {$r['rooms']} room(s), {$r['written']} written, "
               . "{$r['changed']} changed");
        }
    }
} catch (Throwable $e) {
    $failures++;
    $log('ROOM/CAPACITY REFRESH FAILED: ' . $e->getMessage());
}

// --- 6. Refresh the new-enrolment queue from Kidsoft ------------------------
// Waitlist/getWaitingList carries the whole queue - names, DOBs, requested
// weekdays, parent mobiles - and a family joining it overnight should be in the
// morning's queue without anybody pressing a button. Cheap: 7 calls and ~3s for
// the whole group. Our decisions (offers, funnel, notes, priority) are never
// touched; see the ownership split in _incl/waitlist-import.php.
//
// A Kidsoft outage is NOT a failure here: yesterday's queue is still the queue,
// and the Waitlist screen has an Import button for whoever needs it sooner.
try {
    if ($dryRun) {
        $log('would refresh the new-enrolment queue for ' . count(centres_all()) . ' centre(s)');
    } else {
        // 'archived' BELONGS IN HERE (9 Sep 2026). It was the one count left out,
        // so the nightly log had never once reported an archive since the feature
        // shipped on 8 Sep - which is precisely why a queue carrying 55 families
        // Kidsoft had already dropped went unnoticed until staff complained.
        // A count that nothing prints is a count nobody checks.
        $tot = ['added' => 0, 'updated' => 0, 'closed' => 0, 'reopened' => 0,
                'linked' => 0, 'archived' => 0, 'work_completed' => 0];
        $bad = 0;
        // A status the import does not recognise, and which centres carry it.
        // waitlist_import_centre() has always RETURNED this and nothing has ever
        // read it - so the two occasions Kidsoft grew its vocabulary
        // (`OfferedExpiryReminder`, then `Form Submitted`) were both found by
        // somebody going looking, months apart, while real families sat outside
        // the queue in between. The whole point of refusing to guess at an
        // unmapped status is that somebody is told instead; until this line that
        // second half was missing. See WAITLIST_KIDSOFT_STATUS.
        $unknown = [];
        foreach (centres_all() as $c) {
            try {
                $r = waitlist_import_centre((int)$c['id'], ['refresh' => true]);
                foreach ($tot as $k => $_) $tot[$k] += $r[$k];
                foreach ($r['unknown_status'] as $status => $n) {
                    $unknown[$status]['n'] = ($unknown[$status]['n'] ?? 0) + $n;
                    $unknown[$status]['centres'][] = $c['name'];
                }
            } catch (Throwable $e) {
                // One unreachable centre must not cost the other six their sync.
                $bad++;
                $log("waitlist import failed for {$c['name']}: " . $e->getMessage());
            }
        }
        $log("waitlist: {$tot['added']} added, {$tot['updated']} refreshed, "
           . "{$tot['closed']} no longer waiting, {$tot['reopened']} reactivated"
           . ($tot['archived'] ? ", {$tot['archived']} archived in Kidsoft and removed from the queue" : '')
           . ($tot['linked'] ? ", {$tot['linked']} newly linked to a Kidsoft record" : '')
           . ($tot['work_completed'] ? ", {$tot['work_completed']} In progress item(s) closed as Enrolled" : '')
           . ($bad ? ", {$bad} centre(s) unreachable" : ''));
        // Loud, and named, because the families behind these rows are invisible
        // on every screen until the status is mapped: a new one is not imported
        // at all and an existing one stops being refreshed.
        foreach ($unknown as $status => $u) {
            $log("WAITLIST: Kidsoft status \"{$status}\" is not in WAITLIST_KIDSOFT_STATUS; "
               . "{$u['n']} application(s) SKIPPED at " . implode(', ', array_unique($u['centres']))
               . '. Map it in _incl/waitlist-import.php or those families stay out of the queue.');
        }
    }
} catch (Throwable $e) {
    $log('WAITLIST IMPORT FAILED: ' . $e->getMessage());
}

// --- 7. Copy contact notes to Kidsoft for families who can now be written to -
// THE ONLY THING IN THIS FILE THAT WRITES TO A THIRD PARTY, and the only write
// this project makes anywhere (Comment/createComment, authorised by Broc on 14
// Aug 2026). It sits directly after the queue refresh because that is the step
// that makes a family writable: a Kidsoft Child record is created when their
// application converts to an enrolment, and the import now takes the ChildID it
// arrives with.
//
// What it is for: 87% of the families the team rings have nowhere in Kidsoft to
// put a note at the time of the call, so their history lives here alone. When
// they enrol, that history can finally travel - oldest note first, because
// Kidsoft returns no date on a comment and insertion order is the only ordering
// a human reading the record gets.
//
// createComment has no update and no delete, so this is deliberately timid: it
// pushes what was never attempted and what Kidsoft explicitly declined, and it
// LEAVES ALONE anything whose outcome is unknown (a transport failure may have
// landed). Those are reported so a person can decide, which is not a decision a
// nightly job should be making. See _incl/comments.php.
try {
    $c = comment_push_backlog(['dry_run' => $dryRun]);
    if ($dryRun) {
        $log("would copy {$c['pushable']} contact note(s) to Kidsoft"
           . ($c['candidates'] > $c['pushable']
                ? " ({$c['candidates']} waiting, the rest have no Kidsoft record yet)" : '')
           . ($c['deferred'] ? ", {$c['deferred']} held back by the per-run cap" : ''));
    } elseif ($c['pushed'] || $c['failed'] || $c['deferred']) {
        $log("kidsoft comments: {$c['pushed']} copied"
           . ($c['failed'] ? ", {$c['failed']} refused" : '')
           . ($c['deferred'] ? ", {$c['deferred']} left for tomorrow (per-run cap "
                . COMMENT_BACKLOG_MAX . ')' : ''));
    }
    // Named and loud, because nothing else will ever mention them again: these
    // notes are stored here and MAY OR MAY NOT also be in Kidsoft, and only a
    // person looking at the family's record in Kidsoft can tell.
    if ($c['unknown_outcome']) {
        $log("KIDSOFT COMMENTS: {$c['unknown_outcome']} note(s) failed mid-push and are NOT retried; "
           . 'Kidsoft may have taken them and lost the reply, and a retry would leave a permanent '
           . 'duplicate on the family record. Check the family in Kidsoft before pushing one by hand.');
    }
} catch (Throwable $e) {
    // Not a failure of the run: the notes are safe here, which is the point.
    $log('kidsoft comment copy failed: ' . $e->getMessage());
}

// --- 8. Warm a year of bookings for every centre ----------------------------
// The board compares the plan against a WHOLE CALENDAR YEAR of Kidsoft
// bookings: ~40,000 rows and 20.6MB of JSON per centre, which takes about 33
// seconds to fetch cold and a tenth of a second once cached. Warming it here
// means the first person in each morning gets the fast path instead of waiting
// out twelve API calls.
//
// Deliberately NOT a failure condition for the run: a Kidsoft outage overnight
// costs a slow first page load, nothing more, and should not mark the backup
// job as failed or page anyone.
try {
    $thisYear = (int)date('Y');
    $centres  = centres_all();
    if ($dryRun) {
        $log('would warm ' . count($centres) . ' centre(s) x 2 years of Kidsoft bookings, '
             . 'plus 14 months of last year\'s tenure readings each');
    } else {
        $t0 = microtime(true);
        $ok = $warmed = 0;
        foreach ($centres as $c) {
            // Who to ring about a spare day: two more cached calls the cell panel
            // needs (~9s cold together, 0.05s warm). Same reasoning as the
            // bookings below - pay for it here, not in front of a user.
            //
            // THE SEARCH PALETTE DEPENDS ON THIS TOO, and more sharply. It reads
            // these same two calls for ALL SEVEN centres on every query - 0.12s
            // warm across the group, tens of seconds cold - which is what lets
            // _incl/search.php answer from Kidsoft live instead of keeping a
            // copied index that would go stale the moment a family changes their
            // number. Drop this warm and the first search each morning stalls.
            try {
                child_contacts($c['kidsoft_service_id'], true);
            } catch (Throwable $e) {
                $log("  warm contacts {$c['name']}: " . $e->getMessage());
            }

            // Kidsoft's CLOSED and ARCHIVED waitlist history, which the palette
            // searches but the queue table deliberately does not hold. 3,523 of
            // these rows exist group-wide and getWaitingList hides them unless
            // ShowArchived is passed. 5.4s and 16.9MB cold for the group, ~0.3s
            // warm - the same bargain as the contacts warm above, and the same
            // consequence if it is dropped: the first search each morning waits.
            try {
                search_history_warm($c['kidsoft_service_id']);
            } catch (Throwable $e) {
                $log("  warm waitlist history {$c['name']}: " . $e->getMessage());
            }

            // The re-enrolment round, for the Dashboard.
            //
            // THIS ONE BREAKS THE "TTL MUST EXCEED THE WARM INTERVAL" RULE ON
            // PURPOSE. Notification/getNotifications is cached for 15 minutes and
            // warmed once a night, which for any other endpoint would make the
            // warm pointless. It is not pointless here because the two are doing
            // different jobs: the 15 minutes keeps a round that moves by dozens of
            // families a day genuinely current (452 -> 460 -> 472 accepted in a
            // single day, measured), while the warm exists only to guarantee a row
            // EXISTS - an expired row is served stale and refreshed after the
            // response, but a MISSING one is a ~4s wait in front of whoever opens
            // the Dashboard first each morning. Warm for existence, short TTL for
            // freshness.
            try {
                reenrolment_answers((int)$c['id'], $thisYear, true);
            } catch (Throwable $e) {
                $log("  warm re-enrolment {$c['name']}: " . $e->getMessage());
            }
            foreach ([$thisYear, $thisYear + 1] as $y) {
                try {
                    bookings_year($c['kidsoft_service_id'], $y, true);   // true = read live
                    $ok++;
                } catch (Throwable $e) {
                    $log("  warm {$c['name']} {$y}: " . $e->getMessage());
                }
                $warmed++;
            }
            // THE CHURN SCREEN READS THE YEAR BEFORE LAST, which nothing else
            // warms. bookings_year() above covers the current and next year, and
            // tenure_year() samples the same monthly cache keys - so this year is
            // already free. What is not free is the year just gone, which is the
            // year churn OPENS ON (a completed year is the only one that has a
            // boundary transition and four readings in it), plus the November
            // before it for the progression comparison.
            //
            // EVERY MONTH, NOT JUST THE READINGS, since 29 Oct 2026: a child who
            // started and left between two readings is found in the months in
            // between (tenure_apply_short_stays()), and last December is this
            // year's changeover window, which nothing else warms either. So
            // November of the year before last through December of last year:
            // fourteen month-reads per centre where there were five. Measured
            // cold for the eight centres: 132s.
            for ($t = mktime(0, 0, 0, 11, 1, $thisYear - 2); $t <= mktime(0, 0, 0, 12, 1, $thisYear - 1);
                 $t = strtotime('+1 month', $t)) {
                $y = (int)date('Y', $t);
                $m = (int)date('n', $t);
                try {
                    // Empty person map on purpose: the cache row is written by the
                    // fetch inside, and building the map here would read every
                    // centre's children once per centre for nothing.
                    tenure_snapshot($c['kidsoft_service_id'], $y, $m, [], true);
                } catch (Throwable $e) {
                    $log("  warm churn {$c['name']} {$y}-{$m}: " . $e->getMessage());
                }
            }
        }
        $log(sprintf('warmed %d of %d centre-years in %.0fs', $ok, $warmed, microtime(true) - $t0));
    }
} catch (Throwable $e) {
    $log('BOOKING WARM SKIPPED: ' . $e->getMessage());
}

$log($failures ? "finished with {$failures} failure(s)" : 'finished ok');
exit($failures ? 1 : 0);
