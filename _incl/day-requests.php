<?php
// Day requests - existing families asking for an EXTRA PERMANENT DAY, read live
// from Kidsoft.
//
// Source: Bookings/getWaitlistedBookings. Despite living under Bookings, these are
// not bookings: they are requests for a place on a specific date in a specific
// room that could not be granted at the time. Every row carries ChildID,
// GuardianID, RoomID, Date and Status, all 100% populated, so it resolves to a
// named child, a named guardian with a phone number, and one of our mapped rooms.
//
// MOST OF THIS ENDPOINT IS CASUAL AND NONE OF THAT BELONGS HERE. This file was
// written as though every row were a family asking for a standing extra day.
// Measured 13 Aug 2026: 63 of the 65 open future-dated rows group-wide are
// requests for one-off CASUAL days, and 34 of the 46 rows the team saw asked for
// a SINGLE DATE. Every one made its cell `grantable` - the loudest state on the
// grid - for a day that could never be sold for the year. See
// casual_booking_types() for how they are told apart and what was measured.
//
// This is a DIFFERENT POPULATION from Waitlist/getWaitingList, with zero overlap
// by ChildID or EnrolmentID: that one is the new-enrolment queue (families with
// no place yet - see _incl/waitlist-import.php), this one is families who
// already attend asking for an extra day.
//
// This is the API source for the workbook's "Existing children wanting days"
// block, which the enrolments team still keeps by hand. Nothing here is copied
// into our tables: Kidsoft owns these rows and a copy would drift the moment a
// family was granted a day. It is read live (cached) and displayed.
//
// STATUS, verified by cross-checking each row against the real bookings for the
// same child and date:
//   Booked     - granted; the child has a real booking in the same room. Not shown.
//   Waitlisted - UNMET. No booking that day. This is open demand.
//   Notified   - family told a place may exist; not taken up yet. Still open.
//   Cancelled  - withdrawn. Not shown.
//
// A REQUEST OUTLIVES THE FAMILY, and nothing here noticed (fixed 10 Aug 2026,
// reported from UAT: "it is showing Asked for this day for families that are
// archived"). KIDSOFT NEVER CANCELS A WAITLISTED BOOKING WHEN A CHILD LEAVES --
// the row stays `Waitlisted` for ever, so Kidsoft Enrol files the family under
// Archive while this endpoint goes on reporting them as live demand. There is no
// archive, expiry or closed marker anywhere on the row itself: the full census of
// all 2026 rows group-wide returns exactly four statuses (Waitlisted 352, Booked
// 187, Cancelled 174, Notified 34), so the child record is the only thing that
// can say the request is dead.
//
// Measured over all of 2026, group-wide: **26 of 386 open requests (6.7%) belong
// to a child who has since been archived** - 17 of them at Terrigal alone, where
// two siblings left on 26 Jul still ask for a day. They read as demand nobody can
// meet: ringing one is a call to a family who left, and counting one makes a cell
// grantable on the strength of a family who is gone, which is the double-booking
// this app exists to prevent pointed the other way.
//
// `Children.Active` is the archive flag - it and `ExitDate` are mutually
// exclusive in this data (see _incl/drift.php). An UNKNOWN child is still shown:
// a missing child record is a gap in our copy, not a statement that the family
// left, and hiding real demand is the worse error.
//
// Rows are DATE-SPECIFIC, not standing weekday patterns: some families repeat
// weekly, some ask for a single date. So a request belongs to the cell for its
// actual date, which is exactly the unit the grid works in.

require_once __DIR__ . '/kidsoft-cache.php';
require_once __DIR__ . '/identity.php';   // name_case()
require_once __DIR__ . '/contacts.php';   // guardians_by_id(), guardian_phone()

/** Statuses that represent demand still waiting to be met. */
const DAY_REQUEST_OPEN = ['Waitlisted', 'Notified'];

/**
 * Has the child behind this request left? See the header: Kidsoft leaves the
 * request `Waitlisted` for ever, so the child record is the only thing that says.
 *
 * A child we hold NO record for is NOT archived. That is a hole in our copy of
 * getChildren, and treating it as a departure would delete real demand on the
 * strength of a cache miss - the same asymmetry name_is_near() is built on.
 *
 * @param array $children ChildID => Kidsoft child row
 */
function day_request_child_archived(array $children, ?string $childId): bool {
    if ($childId === null || $childId === '') return false;
    $c = $children[$childId] ?? null;
    return $c !== null && empty($c['Active']);
}

/**
 * The booking types this service uses for CASUAL days.
 *
 * THIS TOOL IS ABOUT PERMANENT ENROLMENTS ONLY (Broc, 13 Aug 2026). A casual day
 * has no place anywhere in it - the board already drops casual BOOKINGS, and
 * until now nothing dropped casual REQUESTS, so a parent asking for two one-off
 * Tuesdays made a cell `grantable`, which is the loudest state on the grid.
 *
 * A waitlisted booking carries a `BookingTypeID` but NO `Casual` flag, so the
 * type is the only thing on the row that can say which kind of day is being
 * asked for. Service/getBookingTypes names them, and every casual type at every
 * centre is prefixed "CAS".
 *
 * MEASURED 13 Aug 2026 over all seven services and all of 2026 - 28 distinct
 * casual type names, every one "CAS"-prefixed. Classifying the 65 open future
 * requests by NAME gives the same 63 as classifying them by the `Casual` flag on
 * this year's real bookings of the same type: the two methods disagree on ZERO
 * request rows. (The 2 that survive are "1-2 Full Day" and "3-4 Full Day".)
 *
 * DO NOT USE THIS RULE ON A REAL BOOKING. A booking carries `Casual` itself and
 * that is authoritative; the name says what a type is FOR, not what a given row
 * turned out to be. Measured over the same data the two disagree on 34 booking
 * types - permanent-named types carry a few casual days (Kanwal "0-2 Full Day",
 * 3 casual against 3,725 permanent) and "CAS 2-3 (10 Hours)" carries 1 permanent.
 *
 * @return array BookingTypeID => true
 */
function casual_booking_types(string $serviceId, bool $refresh = false): array {
    static $cache = [];
    if (!$refresh && isset($cache[$serviceId])) return $cache[$serviceId];

    // Booking types are configuration, not movement - a day is plenty.
    $res = kidsoft_cached('Service', 'getBookingTypes', ['ServiceID' => $serviceId],
                          $refresh ? 0 : 86400);

    $out = [];
    foreach (kidsoft_rows($res, 'BookingTypes', $serviceId) as $t) {
        if (!empty($t['BookingTypeID']) && stripos((string)($t['Name'] ?? ''), 'CAS') === 0) {
            $out[(string)$t['BookingTypeID']] = true;
        }
    }
    return $cache[$serviceId] = $out;
}

/**
 * Every open day request for the REST OF A YEAR, grouped by room and weekday.
 *
 * The board used to show these one week at a time, which meant a family asking
 * for a Friday in November was invisible in July - and CLAUDE.md calls a
 * grantable cell the highest-value state in the app, so hiding one behind a date
 * picker was hiding the work. Volume makes this easy: Tuggerah carries 17 open
 * requests across six months, so the whole year's demand fits on one screen.
 *
 * ONLY FROM TODAY ONWARDS. A request for a day that has already passed cannot be
 * granted; showing it would put a phone call on the list that nobody can make.
 *
 * @return array ourRoomId => weekday => list of requests (each with its dates)
 */
function day_requests_year(int $centreId, int $year, bool $refresh = false): array {
    $centre = centre_get($centreId);
    if (!$centre) throw new InvalidArgumentException('unknown centre');
    $serviceId = $centre['kidsoft_service_id'];

    $today = date_create('today', new DateTimeZone('Australia/Sydney'))->format('Y-m-d');
    $from  = max($today, "$year-01-01");
    if ($from > "$year-12-31") return [];   // the year is behind us

    // Kidsoft room id => our room id. A request in a room we have not mapped has
    // no cell to live in.
    $ourByKs = [];
    foreach (rooms_for_centre($centreId, $year) as $rm) {
        if ($rm['kidsoft_room_id']) $ourByKs[$rm['kidsoft_room_id']] = (int)$rm['id'];
    }
    if (!$ourByKs) return [];

    // Names, and the archive flag the filter below turns on. Fetched BEFORE the
    // months rather than after them, because a request from a family who has left
    // must never be grouped, counted or made grantable in the first place.
    $children = [];
    foreach (kidsoft_rows(kidsoft_cached('Children', 'getChildren', ['ServiceID' => $serviceId]),
                          'Children', $serviceId) as $c) {
        if (!empty($c['ChildID'])) $children[$c['ChildID']] = $c;
    }

    // A CASUAL DAY IS NOT A PLACE. See casual_booking_types(): 63 of the 65 open
    // requests group-wide were requests for one-off casual days, and each one was
    // being counted as demand for a standing weekday place.
    //
    // Deliberately NOT wrapped in a try. If Kidsoft cannot say which types are
    // casual we must show NOTHING rather than everything, which is the opposite
    // of the unknown-child rule above and for the opposite reason: an unknown
    // child is one row in a population that is otherwise real demand, whereas an
    // unknown type list would let through a population that is 97% casual.
    // cells_year() already catches this and renders the grid without requests.
    $casualTypes = casual_booking_types($serviceId, $refresh);

    // Requests are date-shaped, so gather the remaining months and fold each
    // one onto the weekday of its date. Grouping by (child, room, weekday) is
    // what turns "asked for 3 Mondays" into one row on one cell instead of
    // three identical rows the team would ring about three times.
    $byCell = [];
    $start = (int)substr($from, 5, 2);
    for ($m = $start; $m <= 12; $m++) {
        $mFirst = sprintf('%d-%02d-01', $year, $m);
        $mLast  = date_create($mFirst)->modify('last day of this month')->format('Y-m-d');
        $dates  = [];
        for ($d = date_create(max($mFirst, $from)); $d->format('Y-m-d') <= $mLast; $d->modify('+1 day')) {
            $wd = (int)$d->format('N');
            if ($wd <= 5) $dates[$d->format('Y-m-d')] = $wd;
        }
        if (!$dates) continue;

        // The current month still moves during the day, so it keeps the short
        // TTL; months further out do not change minute to minute and would
        // otherwise cost a fetch each, every quarter hour, for nothing.
        $ttl = $refresh ? 0 : ($m === (int)date('n') && $year === (int)date('Y') ? null : 21600);
        try {
            $res = kidsoft_cached('Bookings', 'getWaitlistedBookings', [
                'ServiceID' => $serviceId, 'DateFrom' => $mFirst, 'DateTo' => $mLast,
            ], $ttl);
        } catch (RuntimeException $e) {
            continue;   // one unreachable month must not blank the year's demand
        }

        foreach (kidsoft_rows($res, 'WaitlistedBookings', $serviceId) as $r) {
            if (!in_array($r['Status'] ?? '', DAY_REQUEST_OPEN, true)) continue;
            $date = substr((string)($r['Date'] ?? ''), 0, 10);
            if (!isset($dates[$date]) || empty($r['RoomID'])) continue;
            if (!isset($ourByKs[$r['RoomID']])) continue;
            if (day_request_child_archived($children, $r['ChildID'] ?? null)) continue;
            if (isset($casualTypes[(string)($r['BookingTypeID'] ?? '')])) continue;

            $key = ($r['ChildID'] ?? '') . '|' . $r['RoomID'];
            $cell = &$byCell[$ourByKs[$r['RoomID']]][$dates[$date]][$key];
            if ($cell === null) $cell = ['row' => $r, 'dates' => []];
            $cell['dates'][] = $date;
            unset($cell);
        }
    }
    if (!$byCell) return [];

    // Contact details. A day request is worthless to staff without a name and a
    // way to ring the family; the names themselves are already in hand above.
    // WITH phone numbers - see _incl/contacts.php. This used to be a plain
    // getGuardians call and every request below carried guardian_phone => null.
    $guardians = guardians_by_id($serviceId, $refresh);

    $out = [];
    foreach ($byCell as $roomId => $byWd) {
        foreach ($byWd as $wd => $entries) {
            $list = [];
            foreach ($entries as $e) {
                $r = $e['row'];
                $c = $children[$r['ChildID'] ?? ''] ?? null;
                $g = $guardians[$r['GuardianID'] ?? ''] ?? null;
                $phone = $g ? guardian_phone($g) : null;
                sort($e['dates']);
                $list[] = [
                    'id'               => $r['WaitlistedBookingID'] ?? null,
                    // WHICH SYSTEM HOLDS THIS REQUEST. The cell shows these
                    // beside our own internal wishes (see cell_merge_requests()),
                    // and the two are not interchangeable: this one the parent
                    // raised in the portal for named dates and Kidsoft owns it,
                    // so there is nothing to edit here. Ours is a phone call
                    // somebody wrote down.
                    'source'           => 'kidsoft',
                    'kidsoft_child_id' => $r['ChildID'] ?? null,
                    'name'             => $c ? trim(name_case((string)($c['FirstName'] ?? '')) . ' '
                                                  . name_case((string)($c['LastName'] ?? ''))) : 'Unknown child',
                    'dob'              => $c['DOB'] ?? null,
                    'guardian'         => $g ? trim(name_case((string)($g['FirstName'] ?? '')) . ' '
                                                  . name_case((string)($g['LastName'] ?? ''))) : null,
                    'guardian_email'   => $g['Email'] ?? null,
                    // THERE ARE PHONE NUMBERS. This read `null` with a comment
                    // insisting Kidsoft holds none, "verified across all 4,630
                    // Tuggerah guardians" - verified without Options=PhoneNumbers,
                    // which is how you ask for one. 99% of active guardians have
                    // one, against 38% for email. guardian_phone() picks WHICH,
                    // because a guardian can hold five numbers and one of the
                    // Types in this data is "Case Worker".
                    'guardian_phone'      => $phone['number'] ?? null,
                    'guardian_phone_type' => $phone['type'] ?? null,
                    // False when the best number belongs to a grandmother, an
                    // aunt or a case worker rather than the parent, so the UI can
                    // say so instead of implying it is Mum's mobile.
                    'guardian_phone_is_parent' => $phone['is_parent'] ?? null,
                    'status'           => $r['Status'],
                    'requested_at'     => $r['CreatedDateTime'] ?? null,
                    // Every date this family asked for on this weekday, so one
                    // call can settle a standing request rather than one day.
                    'dates'            => $e['dates'],
                    'from'             => $e['dates'][0],
                ];
            }
            // Longest-waiting first, the queue-order principle used everywhere else.
            usort($list, static fn($a, $b) => (string)$a['requested_at'] <=> (string)$b['requested_at']);
            $out[$roomId][$wd] = $list;
        }
    }
    return $out;
}

