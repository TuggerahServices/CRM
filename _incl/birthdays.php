<?php
// The Monday birthday report to the parents accounts team. (2 Oct 2026)
//
// WHY THIS EXISTS. Fees are set by age band and Kidsoft does not move a child
// to the next band on their birthday - an account manager does, by hand. So
// every Monday the accounts team gets the children in care whose birthday
// falls in the next 14 days, read live from Kidsoft. Sent by cron-nightly.php
// --birthdays (see /etc/cron.d/crm-birthdays). It goes every Monday even
// when nobody is on it - Broc's choice, so a quiet Monday never looks broken.
//
// NARROWED TO ERINA, TURNING 2 (6 Oct 2026, Broc, on his manager's advice).
// That is the fee adjustment the accounts team has to make by hand in Kidsoft;
// the group-wide every-birthday version sent once, as a test, and was not what
// they need. Both are constants below, so widening it again is one line.
//
// TURNING 3 ADDED (26 Oct 2026, Broc). Still Erina only.
//
// "IN CARE" IS TWO TESTS, MEASURED 2 Oct 2026 across every centre:
//
//   Kidsoft `Active`      901 children. 28 have a birthday 5-18 Oct.
//   + a permanent booking 25 of those 28. The other three are all `Active`
//                         with no booking until January 2027 - enrolled, not
//                         yet in care, and nothing to bill.
//
// No inactive child held a permanent booking at any centre, so `Active` alone
// loses nobody; the booking test only removes children who have not started.
//
// CASUAL BOOKINGS DO NOT COUNT (CLAUDE.md). A child booked only casually is not
// in care as far as this tool is concerned. Zero such children in the measured
// fortnight, so the rule costs nothing today.
//
// "BOOKED AS" IS THE BOOKING TYPE'S NAME, and the names carry the age band
// ("2-3 Full Day", "4-5 Full Day"), which is the thing the account manager is
// about to change. Only the NAME is read: getBookingTypes also returns BaseFee
// and every booking row carries Fee, and neither is touched (no dollar figure,
// CLAUDE.md).
//
// A CHILD LEAVING BEFORE THEIR BIRTHDAY is left out: their ExitDate says the
// fee never changes.

require_once __DIR__ . '/dates.php';
require_once __DIR__ . '/simple-mysqli.php';
require_once __DIR__ . '/kidsoft-cache.php';
require_once __DIR__ . '/rooms.php';      // centres_all()
require_once __DIR__ . '/identity.php';   // name_case()

const BIRTHDAY_REPORT_TO   = ['lmgparentsaccounts@littlemiracles.com.au'];
const BIRTHDAY_REPORT_DAYS = 14;
const BIRTHDAY_REPORT_CENTRES = [9];   // centres.id - 9 is Erina
const BIRTHDAY_REPORT_TURNING = [2, 3];   // only children whose birthday makes them one of these ages

/**
 * Children in care at BIRTHDAY_REPORT_CENTRES turning one of
 * BIRTHDAY_REPORT_TURNING in [$from, $from + $days - 1], read live.
 *
 * @return array{from:string, to:string, scope:string[], centres:array<string,array>, failed:string[]}
 *         scope is the configured centres' names, whether or not anybody matched.
 *         centres is centre name => rows, each row
 *         {name, birthday, turning, rooms, types}, sorted by birthday then name.
 */
function birthdays_upcoming(string $from, int $days = BIRTHDAY_REPORT_DAYS): array {
    $tz    = new DateTimeZone('Australia/Sydney');
    $start = new DateTimeImmutable($from, $tz);
    $end   = $start->modify('+' . ($days - 1) . ' days');
    $fromS = $start->format('Y-m-d');
    $toS   = $end->format('Y-m-d');

    $out = ['from' => $fromS, 'to' => $toS, 'scope' => [], 'centres' => [], 'failed' => []];
    foreach (BIRTHDAY_REPORT_CENTRES as $id) {
        if ($c = centre_get($id)) $out['scope'][] = (string)$c['name'];
    }

    foreach (centres_all() as $c) {
        if (!in_array((int)$c['id'], BIRTHDAY_REPORT_CENTRES, true)) continue;
        $sid = (string)($c['kidsoft_service_id'] ?? '');
        if ($sid === '') continue;
        try {
            // ttl 0: read live. This runs once a week and the Active flag is the
            // whole question, so yesterday's copy is not good enough.
            $kids = kidsoft_rows(kidsoft_cached('Children', 'getChildren', ['ServiceID' => $sid], 0),
                                 'Children', $sid);
            $bookings = kidsoft_rows(kidsoft_cached('Bookings', 'getBookings', [
                'ServiceID' => $sid, 'DateFrom' => $fromS, 'DateTo' => $toS,
            ], 0), 'Bookings', $sid);
            $typeNames = [];
            foreach (kidsoft_rows(kidsoft_cached('Service', 'getBookingTypes', ['ServiceID' => $sid], 86400),
                                  'BookingTypes', $sid) as $t) {
                if (!empty($t['BookingTypeID'])) $typeNames[(string)$t['BookingTypeID']] = trim((string)($t['Name'] ?? ''));
            }
        } catch (Throwable $e) {
            error_log("crm: birthday report, {$c['name']}: " . $e->getMessage());
            $out['failed'][] = (string)$c['name'];
            continue;
        }

        $roomNames = dbx()->query('SELECT kidsoft_room_id, name FROM rooms WHERE centre_id = ?',
                                  [(int)$c['id']], 'i')->fetchAll('keyPair');

        // ChildID => [rooms => set, types => set], permanent bookings only.
        $held = [];
        foreach ($bookings as $b) {
            if (empty($b['ChildID']) || !empty($b['Casual'])) continue;
            $id = (string)$b['ChildID'];
            $held[$id] ??= ['rooms' => [], 'types' => []];
            $room = $roomNames[(string)($b['RoomID'] ?? '')] ?? null;
            if ($room !== null) $held[$id]['rooms'][$room] = true;
            $type = $typeNames[(string)($b['BookingTypeID'] ?? '')] ?? '';
            if ($type !== '') $held[$id]['types'][$type] = true;
        }

        $rows = [];
        foreach ($kids as $k) {
            $id = (string)($k['ChildID'] ?? '');
            if ($id === '' || empty($k['Active']) || !isset($held[$id])) continue;
            $dob = substr((string)($k['DOB'] ?? ''), 0, 10);
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dob)) continue;

            $birthday = birthday_in_window($dob, $fromS, $toS);
            if ($birthday === null) continue;

            $turning = (int)substr($birthday, 0, 4) - (int)substr($dob, 0, 4);
            if (!in_array($turning, BIRTHDAY_REPORT_TURNING, true)) continue;

            $exit = substr((string)($k['ExitDate'] ?? ''), 0, 10);
            if ($exit !== '' && $exit < $birthday) continue;

            $rows[] = [
                'name'     => name_case(trim((string)($k['FirstName'] ?? '') . ' ' . (string)($k['LastName'] ?? ''))),
                'birthday' => $birthday,
                'turning'  => $turning,
                'rooms'    => array_keys($held[$id]['rooms']),
                'types'    => array_keys($held[$id]['types']),
            ];
        }
        usort($rows, static fn($a, $b) => [$a['birthday'], $a['name']] <=> [$b['birthday'], $b['name']]);
        if ($rows) $out['centres'][(string)$c['name']] = $rows;
    }
    return $out;
}

/**
 * The child's birthday inside [$from, $to] as Y-m-d, or null.
 *
 * DOB + N years, so a 29 February child's birthday is 1 March in a common year.
 * The window can straddle New Year, hence both years.
 */
function birthday_in_window(string $dob, string $from, string $to): ?string {
    $born = new DateTimeImmutable($dob);
    foreach (array_unique([(int)substr($from, 0, 4), (int)substr($to, 0, 4)]) as $year) {
        $age = $year - (int)$born->format('Y');
        if ($age < 1) continue;
        $d = $born->modify("+{$age} years")->format('Y-m-d');
        if ($d >= $from && $d <= $to) return $d;
    }
    return null;
}

function birthdays_count(array $report): int {
    return array_sum(array_map('count', $report['centres']));
}

/** ['Erina', '2 or 3'] - the filter, in the words the email says it in. */
function birthdays_scope(array $report): array {
    return [implode(', ', $report['scope']), implode(' or ', BIRTHDAY_REPORT_TURNING)];
}

function birthdays_email_subject(array $report): string {
    [$centres, $ages] = birthdays_scope($report);
    return sprintf('Turning %s at %s, %s to %s: %d %s', $ages, $centres,
                   syd_date($report['from']), syd_date($report['to']),
                   $n = birthdays_count($report), $n === 1 ? 'child' : 'children');
}

/** The email body. Inline styles only - mail clients drop <style>. */
function birthdays_email_html(array $report): string {
    $h    = static fn($v) => htmlspecialchars((string)$v, ENT_QUOTES);
    $th   = 'style="text-align:left;padding:6px 12px 6px 0;border-bottom:2px solid #c9c7d4;font-size:12px;color:#5c5977;font-weight:600"';
    $td   = 'style="padding:6px 12px 6px 0;border-bottom:1px solid #e4e1f3;font-size:14px;vertical-align:top"';

    [$centres, $ages] = birthdays_scope($report);

    $html = '<div style="font-family:Segoe UI,Arial,sans-serif;font-size:14px;color:#2b2b3a;line-height:1.4">'
          . '<p style="margin:0 0 16px">Children in care at ' . $h($centres) . ' turning ' . $h($ages)
          . ' between <b>' . $h(syd_date($report['from'])) . '</b> and <b>' . $h(syd_date($report['to'])) . '</b>.</p>';

    // The one thing the reader cannot know unaided: a centre missing from the
    // list because Kidsoft did not answer, not because nobody has a birthday.
    if ($report['failed']) {
        $html .= '<p style="margin:0 0 16px;padding:10px 12px;background:#feeef4;border:1px solid #ed387d;color:#2b2b3a">'
               . '<b>Kidsoft did not answer for ' . $h(implode(', ', $report['failed'])) . '.</b> '
               . 'Children at ' . (count($report['failed']) === 1 ? 'that centre' : 'those centres')
               . ' are missing from this list.</p>';
    }

    // Said only when every centre answered: under the banner it would be false.
    if (!$report['centres'] && !$report['failed']) {
        $html .= '<p style="margin:0 0 16px">No children in care at ' . $h($centres) . ' turn ' . $h($ages)
               . ' in these 14 days.</p>';
    }

    // ONE table, a heading row per centre, so the columns line up from centre
    // to centre - a table each sized itself to its own longest room name.
    if ($report['centres']) $html .= '<table cellpadding="0" cellspacing="0" style="border-collapse:collapse">';
    foreach ($report['centres'] as $centre => $rows) {
        $html .= '<tr><td colspan="5" style="padding:20px 0 4px;font-size:16px;font-weight:600;color:#2e3192">' . $h($centre) . '</td></tr>'
               . "<tr><th {$th}>Child</th><th {$th}>Birthday</th><th {$th}>Turning</th><th {$th}>Room</th><th {$th}>Booked as</th></tr>";
        foreach ($rows as $r) {
            $html .= '<tr>'
                   . "<td {$td}>" . $h($r['name']) . '</td>'
                   . "<td {$td}>" . $h(syd_date($r['birthday'])) . '</td>'
                   . "<td {$td}>" . (int)$r['turning'] . '</td>'
                   . "<td {$td}>" . $h(implode(', ', $r['rooms'])) . '</td>'
                   . "<td {$td}>" . $h(implode(', ', $r['types'])) . '</td>'
                   . '</tr>';
        }
    }
    if ($report['centres']) $html .= '</table>';

    $html .= '<p style="margin:24px 0 0;font-size:12px;color:#6c6a8a">Read from live Kidsoft data as of '
           . $h(syd_date(date('Y-m-d'))) . '</p></div>';
    return $html;
}
