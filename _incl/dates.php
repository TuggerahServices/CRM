<?php
// Date/time display helpers. All app datetimes are stored in Australia/Sydney
// wall-clock time, so we interpret the stored string AS Sydney and format it
// with no conversion. This is DST-safe: the correct AEST/AEDT offset was already
// applied when the value was written.
//
// Format: d/MM/yyyy hh:mm tt  (e.g. 8/07/2026 04:17 PM). Date-only: d/MM/yyyy.
//
// THE TIMEZONE IS PINNED HERE, AND UNTIL 13 Aug 2026 IT WAS NOT PINNED ON THE
// WEB PATH AT ALL. This header used to say "PHP CLI scripts pin the tz", which
// was true of exactly two files - cron-nightly.php and seed.php - and said
// nothing about the other half of the app. date.timezone is COMMENTED OUT in
// both /etc/php/8.5/fpm/php.ini and the CLI one, and PHP's fallback is UTC, so
// every page and every /api endpoint ran ten hours behind the clock on the wall
// while MySQL NOW() and CURRENT_TIMESTAMP used the box's Sydney system time.
// Two clocks, one database.
//
// What that cost, measured 13 Aug 2026 at 09:34 Sydney: date('Y-m-d') returned
// 2026-08-12, so waitlist_for_centre()'s expires_in_days counted from YESTERDAY
// and **all 113 Tuggerah applications carrying an expiry date read one day too
// long to live** - the "Expiring soon" tile and its red/amber banding with
// them. Every day from midnight to 10am Sydney, which is the first two hours of
// the working day. Anything else deriving "today" or "this year" on a request
// was wrong in the same window: board_default_year(), the date_added default in
// api/waitlist.php's save, the import's DateAddedTo bound.
//
// Set here rather than in php.ini because a dozen other apps share this box and
// none of them has been audited - the same reasoning that keeps the CSP in this
// vhost rather than the shared snippet. Set here rather than in each entry point
// because two copies of a rule is how they drift: auth.php pulls this in, and
// every page and API endpoint requires auth.php.
//
// If you add an entry point that does NOT go through auth.php, require this
// file. Nothing warns you - PHP just quietly answers in UTC.
date_default_timezone_set('Australia/Sydney');

function syd_datetime(?string $s): string {
    if ($s === null || $s === '') return '-';
    $dt = date_create($s, new DateTimeZone('Australia/Sydney'));
    return $dt ? $dt->format('j/m/Y h:i A') : $s;
}

function syd_date(?string $s): string {
    if ($s === null || $s === '') return '';
    $dt = date_create(substr($s, 0, 10), new DateTimeZone('Australia/Sydney'));
    return $dt ? $dt->format('j/m/Y') : $s;
}
