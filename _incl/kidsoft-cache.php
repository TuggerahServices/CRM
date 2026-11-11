<?php
// Cached wrapper around the read-only Kidsoft client.
//
// getBookings is the hot path for the board: a YEAR for one centre is ~40,000
// rows and 20.6MB of JSON (measured at Tuggerah, 2026), so every page load
// would otherwise re-fetch it. Rows in kidsoft_cache are disposable - delete
// any of them to force a refresh.
//
// PAYLOADS ARE GZIPPED. A year of bookings per centre is 20.6MB raw, and with
// seven centres over two years the cache would run to hundreds of megabytes --
// on a table of children's names and attendance. gzip takes that to roughly a
// tenth. Rows written before this change are plain JSON and are still readable:
// the reader sniffs the gzip magic bytes rather than assuming. The column is
// LONGBLOB, not LONGTEXT, because compressed bytes are not text.
//
// Still strictly read-only: this only ever calls kidsoft(), which itself refuses
// any non-get action.
//
// THREE RULES KEEP A USER OFF THE KIDSOFT WIRE (added 4 Aug 2026, after the team
// reported >20s page loads and a search box that stalled every morning):
//
// 1. A TTL SHORTER THAN THE GAP BETWEEN WARMS MAKES THE WARM POINTLESS. The
//    nightly cron warms bookings and contacts at 02:00, once a day, and these
//    TTLs were 6 h - so the cache went cold at 08:00, which is when the team
//    arrives. Measured 4 Aug: 156 booking rows written by cron at 02:00, then
//    rows written at 08:00 and 09:00 by STAFF SITTING THERE WAITING. Search was
//    worse than the board, because it reads all seven centres on every query
//    while the board reads one: 6 of the 7 were expired, ~25-30s to refill.
//    Anything the cron warms must therefore outlive the gap to the next warm.
// 2. STALE IS SERVED, THEN REFRESHED (kidsoft_cache_run_deferred). Expiry used
//    to be a cliff: the unlucky request that found an expired row paid the full
//    live fetch in front of a user. Now that request gets the stale row
//    immediately and the refresh runs after fastcgi_finish_request(), so no page
//    load ever blocks on Kidsoft while ANY copy exists.
// 3. ONE FETCH PER KEY AT A TIME (GET_LOCK). Two staff opening Tuggerah at 08:05
//    both used to fetch the same year of bookings and both write the same rows,
//    doubling the load on an API that had already slowed 2.8x. The second caller
//    now waits for the first and takes its result.
//
// The read-only guarantee is untouched: every path below still goes through
// kidsoft(), which refuses any action not matching ^get[A-Z0-9].

require_once __DIR__ . '/simple-mysqli.php';
require_once __DIR__ . '/kidsoft.php';

// How long a row may be served stale while its refresh runs behind the response.
// Beyond this the data is old enough that showing it without comment is worse
// than making someone wait, so the fetch goes back to being synchronous. This is
// the Kidsoft-is-down ceiling, not a normal operating value - the nightly warm
// means nothing should ever reach a day old.
const KIDSOFT_STALE_MAX = 604800;      // 7 days

// How long a caller waits for whoever is already fetching this key. A year of
// bookings is ~91s at current API speed, so this does NOT cover the whole fetch;
// it covers the common case of near-simultaneous clicks. On timeout the caller
// fetches for itself rather than stalling further.
const KIDSOFT_LOCK_WAIT = 20;

// A ttl=0 caller (Refresh button, cron warm) wants live data - but if another
// request fetched this exact key seconds ago, that IS live. Sharing it turns two
// people hitting Refresh together into one API call instead of two sequential
// 91s ones.
const KIDSOFT_SHARE_FRESH = 60;

/** Default freshness per controller/action, in seconds. */
const KIDSOFT_TTL = [
    // 36 h, and the number that matters is that it EXCEEDS THE 24 h GAP BETWEEN
    // NIGHTLY WARMS - see rule 1 in the header. At 6 h this expired at 08:00
    // every day and the team refilled it by hand, one 91s page load at a time.
    //
    // This was 15 minutes before that, which made sense when the grid showed ONE
    // WEEK of "what is booked right now". The board is a calendar YEAR now, and
    // re-reading twelve months every quarter hour per centre is not a thing
    // anyone can pay for. Nothing on the year view is time-critical: free places
    // come from OUR placements, and Kidsoft's bookings feed the reconciliation
    // check, which is not harmed by being a few hours old. The Refresh button
    // passes ttl=0 when someone genuinely needs it now.
    //
    // The real staleness ceiling is 24 h regardless of what this says, because
    // the 02:00 warm re-reads every centre-year live.
    //
    // THIS ENTRY NO LONGER GOVERNS THE PLACEMENT BOARD (13 Aug 2026). It is the
    // YEAR-WIDE callers' TTL - bookings_year()'s twelve monthly reads, which is
    // what makes it expensive and what the 36 h was written for. The board reads
    // ONE month (4.0s for the largest centre, 14.0s for the whole group), so
    // board_live_children() passes its own override: 15 min for the current
    // year, 1 h for a future one, 36 h for a past one, and there is an hourly
    // cron warm behind it. Two callers were sharing one number and only one of
    // them was being described by it.
    //
    // Shortening this entry itself would drag bookings_year() onto a 12-call
    // refetch cycle - do not; give the caller an override, as the board does.
    'Bookings/getBookings'  => 129600,
    // 36 h. Names and DOBs barely change, and this plain shape is what drift,
    // occupancy, day-requests and identity read.
    'Children/getChildren'  => 129600,
    // 3 h. SAME ENDPOINT, DIFFERENT QUESTION - and it needs its own entry rather
    // than shortening the line above. Options=Guardian carries the child-to-
    // guardian LINK, which is what search resolves a caller's number through, so
    // a guardian newly attached to a child should surface within hours. The five
    // plain-shaped call sites want no such thing and dragging them to 3 h would
    // be pure extra load on an API that is already slow. Warmed 2-hourly
    // alongside the guardians themselves.
    'Children/getChildren?Options=Guardian' => 10800,
    // 3 h, and deliberately the SHORTEST of the warmed endpoints. This one backs
    // the search palette, whose whole job is answering a ringing phone - a
    // number changed this morning being unfindable this afternoon is a failure
    // of the feature, not a cache detail. 36 h (what the others use) would have
    // meant a day's delay.
    //
    // It is affordable only because expiry stopped being a cliff: a stale row is
    // served instantly and refreshed behind the response, so a short TTL costs
    // background refreshes, not user-visible waits. It stays LONGER than the 2 h
    // intra-day warm below, per rule 1.
    'Guardian/getGuardians' => 10800,
    'Service/getRooms'      => 21600,
    'Service/getServices'   => 86400,
    // NOT USED, and nothing should start using it for fees: this app renders no
    // currency figure anywhere, by decision (10 Aug 2026). Harmless if some future
    // caller wants the type list for something other than money.
    'Service/getBookingTypes' => 86400,
    'Waitlist/getWaitingList' => 900,
    // Re-enrolment answers arrive through the August round a few at a time, and a
    // decline is a place coming free - worth being close to live.
    'Notification/getNotifications' => 900,
    // Families ask for days during the day, and a request that can be granted
    // now is the most perishable thing on the board.
    'Bookings/getWaitlistedBookings' => 900,
];

/**
 * Call Kidsoft through the cache.
 *
 * Fresh row  -> returned.
 * Stale row  -> returned NOW, refreshed after the response (rule 2).
 * No row     -> fetched, holding a lock so concurrent callers share it (rule 3).
 *
 * @param int|null $ttl override the default freshness; 0 forces a live call.
 */
function kidsoft_cached(string $controller, string $action, array $params = [], ?int $ttl = null): array {
    // An Options-specific entry wins over the bare endpoint, because the same
    // endpoint answers different questions depending on what you ask it for and
    // those answers go stale at different rates - getChildren plain is names and
    // DOBs, getChildren?Options=Guardian is who to ring. Falls back to the
    // endpoint, then to 15 minutes.
    if ($ttl === null && isset($params['Options'])) {
        $ttl = KIDSOFT_TTL["{$controller}/{$action}?Options={$params['Options']}"] ?? null;
    }
    $ttl ??= KIDSOFT_TTL["{$controller}/{$action}"] ?? 900;

    ksort($params);
    $key = "{$controller}/{$action}:" . md5(json_encode($params));

    if ($ttl > 0) {
        $row = kidsoft_cache_row($key);
        if ($row !== null) {
            $data = kidsoft_cache_decode($row['payload']);
            if ($data !== null) {
                if ($row['age'] <= $ttl) return $data;
                // Stale but usable. Hand it over and refresh behind the response
                // - but only if we can genuinely detach, otherwise deferring
                // would just be a synchronous fetch wearing a different hat.
                if ($row['age'] <= KIDSOFT_STALE_MAX
                    && kidsoft_cache_defer($controller, $action, $params, $key)) {
                    return $data;
                }
            }
        }
        return kidsoft_cache_fetch($controller, $action, $params, $key, $ttl);
    }

    // ttl=0: caller wants live, but will share a fetch that finished seconds ago.
    return kidsoft_cache_fetch($controller, $action, $params, $key, KIDSOFT_SHARE_FRESH);
}

/**
 * The cached row for a key REGARDLESS of freshness, with its age in seconds.
 *
 * Age is computed by the database rather than in PHP, because fetched_at is
 * written with MySQL's NOW() - comparing it against PHP's clock would drift.
 * Clamped at 0: a row from the future (clock skew) is not a negative age.
 */
function kidsoft_cache_row(string $key): ?array {
    $row = dbx()->query(
        'SELECT payload, TIMESTAMPDIFF(SECOND, fetched_at, NOW()) AS age
           FROM kidsoft_cache WHERE cache_key = ?',
        [$key]
    )->fetch();
    if (!$row) return null;
    return ['payload' => $row['payload'], 'age' => max(0, (int)$row['age'])];
}

/** Decode a stored payload, or null if it is unreadable (treat as a miss). */
function kidsoft_cache_decode(string $payload): ?array {
    $data = json_decode(kidsoft_cache_inflate($payload), true);
    return is_array($data) ? $data : null;
}

/** Write a response into the cache. */
function kidsoft_cache_store(string $key, array $data): void {
    dbx()->query(
        'INSERT INTO kidsoft_cache (cache_key, payload) VALUES (?, ?)
         ON DUPLICATE KEY UPDATE payload = VALUES(payload), fetched_at = NOW()',
        [$key, gzencode(json_encode($data, JSON_UNESCAPED_SLASHES), 6)]
    );
}

/**
 * Fetch with a lock, so N simultaneous callers cost ONE API call (rule 3).
 *
 * $acceptAge is what we will settle for after acquiring the lock: whoever held
 * it before us has very likely just written the row we were about to fetch, and
 * re-fetching it would be the exact duplication the lock exists to stop.
 */
function kidsoft_cache_fetch(
    string $controller, string $action, array $params, string $key, int $acceptAge
): array {
    $lock = kidsoft_cache_lock($key);
    $got  = kidsoft_lock_get($lock, KIDSOFT_LOCK_WAIT);

    if (!$got) {
        // Whoever holds it is taking longer than we are willing to wait. Fetch
        // for ourselves rather than stalling the page indefinitely - a duplicate
        // call is bad, a page that never renders is worse.
        $data = kidsoft($controller, $action, $params);
        kidsoft_cache_store($key, $data);
        return $data;
    }

    try {
        if ($acceptAge > 0) {
            $row = kidsoft_cache_row($key);
            if ($row !== null && $row['age'] <= $acceptAge) {
                $data = kidsoft_cache_decode($row['payload']);
                if ($data !== null) return $data;
            }
        }
        $data = kidsoft($controller, $action, $params);
        kidsoft_cache_store($key, $data);
        return $data;
    } finally {
        kidsoft_lock_release($lock);
    }
}

/**
 * Refresh a key in the background. Unlike kidsoft_cache_fetch() this SKIPS when
 * the lock is busy: nobody is waiting on this result, so if another worker is
 * already refreshing the same key there is nothing to add by doing it twice.
 */
function kidsoft_cache_refresh(string $controller, string $action, array $params, string $key): void {
    $lock = kidsoft_cache_lock($key);
    if (!kidsoft_lock_get($lock, 0)) return;
    try {
        kidsoft_cache_store($key, kidsoft($controller, $action, $params));
    } finally {
        kidsoft_lock_release($lock);
    }
}

/**
 * Lock name for a cache key. MySQL caps these at 64 characters and our keys are
 * longer, so hash rather than truncate - two keys sharing a truncated prefix
 * would serialise fetches that have nothing to do with each other.
 */
function kidsoft_cache_lock(string $key): string {
    return 'ks:' . md5($key);
}

/** GET_LOCK. Returns false on timeout (0) or error (NULL). */
function kidsoft_lock_get(string $name, int $wait): bool {
    $row = dbx()->query('SELECT GET_LOCK(?, ?) AS got', [$name, $wait], 'si')->fetch();
    return (int)($row['got'] ?? 0) === 1;
}

function kidsoft_lock_release(string $name): void {
    dbx()->query('SELECT RELEASE_LOCK(?) AS released', [$name]);
}

/**
 * Queue a stale key for refresh once the response has been sent.
 *
 * Returns false when this process cannot detach from the request, in which case
 * the caller must fetch synchronously instead - CLI included, which keeps the
 * nightly cron's behaviour exactly as it was.
 */
function kidsoft_cache_defer(string $controller, string $action, array $params, string $key): bool {
    if (!function_exists('fastcgi_finish_request')) return false;

    static $hooked = false;
    if (!$hooked) {
        $hooked = true;
        register_shutdown_function('kidsoft_cache_run_deferred');
    }
    kidsoft_deferred(['key' => $key, 'controller' => $controller, 'action' => $action, 'params' => $params]);
    return true;
}

/** Pending deferred refreshes, keyed so one request never queues a key twice. */
function kidsoft_deferred(?array $add = null, bool $flush = false): array {
    static $jobs = [];
    if ($flush) { $out = $jobs; $jobs = []; return $out; }
    if ($add !== null) $jobs[$add['key']] = $add;
    return $jobs;
}

/**
 * Send the page, THEN refresh what was served stale.
 *
 * Runs at shutdown. Two things have to happen before the slow part, and both
 * were bugs waiting to be written:
 *  - session_write_close(), because PHP holds the session file locked for the
 *    life of the request. Refreshing a year of bookings with that lock held
 *    would queue the user's NEXT click behind it - turning a fix for slow pages
 *    into a cause of them.
 *  - fastcgi_finish_request(), which is the whole point: it flushes the response
 *    and lets this worker keep running unobserved.
 */
function kidsoft_cache_run_deferred(): void {
    $jobs = kidsoft_deferred(null, true);
    if (!$jobs) return;

    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
    fastcgi_finish_request();

    ignore_user_abort(true);
    // Best effort only: the pool sets max_execution_time as a php_admin_value,
    // which set_time_limit() cannot raise. It does not matter - on Linux, time
    // spent waiting on a socket does not count towards the limit, and waiting on
    // Kidsoft is all this does. Silenced so a refused raise cannot log a warning
    // on a request whose response has already been sent.
    @set_time_limit(0);

    foreach ($jobs as $job) {
        try {
            kidsoft_cache_refresh($job['controller'], $job['action'], $job['params'], $job['key']);
        } catch (Throwable $e) {
            // Nobody is waiting on this and the stale row we served is still
            // serving. A failed background refresh must never become a visible
            // error - the response has already gone out.
            error_log('kidsoft deferred refresh failed for ' . $job['key'] . ': ' . $e->getMessage());
        }
    }
}

/**
 * Read a stored payload back to JSON.
 *
 * Sniffs the gzip magic bytes rather than assuming, so rows written before
 * payloads were compressed still decode instead of turning the whole cache into
 * a silent miss (or worse, a parse error on the hot path).
 */
function kidsoft_cache_inflate(string $payload): string {
    if (strncmp($payload, "\x1f\x8b", 2) !== 0) return $payload;   // legacy plain JSON
    $out = @gzdecode($payload);
    return $out === false ? '' : $out;
}

/** Drop cached responses (all, or those whose key starts with $prefix). */
function kidsoft_cache_clear(string $prefix = ''): int {
    if ($prefix === '') {
        dbx()->query('DELETE FROM kidsoft_cache');
    } else {
        dbx()->query('DELETE FROM kidsoft_cache WHERE cache_key LIKE ?', [$prefix . '%']);
    }
    return dbx()->affectedRows();
}

/**
 * Unwrap a Kidsoft response. Most endpoints nest the records under the
 * ServiceID (getServices is the exception and returns a flat list).
 */
function kidsoft_rows(array $response, string $dataKey, ?string $serviceId = null): array {
    $d = $response[$dataKey] ?? [];
    if ($serviceId !== null) return $d[$serviceId] ?? [];
    return $d;
}
