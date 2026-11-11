<?php
// TOURS IN THE CENTRE'S OUTLOOK CALENDAR (16 Oct 2026).
//
// Broc: "Possible to link tour bookings to our internal Outlook calendars, so when
// a tour is booked it adds an entry into the Outlook calendar for that centre
// automatically? Each centre has its own mailbox/calendar (terrigal@...)."
//
// THE TOUR IS OURS AND THIS IS A ONE-WAY COPY OF IT. work_items.tour_at is the
// record (tours have no Kidsoft surface at all - see work-layer.md), and the
// calendar entry follows it: booked -> created, moved -> updated, cancelled or the
// family lost before the visit -> removed. Nothing is ever read BACK from Outlook;
// somebody dragging the entry in Outlook does not move the tour here.
//
// WHERE: centres.calendar_mailbox, seeded 16 Oct 2026 from Graph, where all eight
// centre accounts resolve as <name>@littlemiracles.com.au. Ours, because Kidsoft
// holds no centre email (Service/getServices: nine fields; its only Options are
// Addresses and PhoneNumbers). NULL = this centre gets no calendar entries.
//
// WHAT: "Tour: <child>", the booked time for TOUR_CALENDAR_MINUTES (Broc's
// answer: 30), and a body naming the parent, how to reach them, the days and year
// asked for, and a link to the item. NO ATTENDEES unless somebody ticks "Send the
// invite to the parent" (29 Oct 2026): an attendee is an email to a family, and it
// goes only when a person asks for it. Ticked, the entry BECOMES the parent's
// invite - see THE PARENT'S INVITE below.
//
// A FAILED WRITE IS NOT A FAILED SAVE - the createComment rule. The tour commits
// first; /api/work syncs AFTER the response is sent, and the outcome is recorded
// on the row (calendar_error) so the modal can say what did not travel.
// tour_calendar_sweep(), every 15 minutes in the day (cron-nightly.php
// --calendar), retries whatever is out of step. That is also how existing
// upcoming tours reach the calendar on the day the permission lands.
//
// RETRIES ARE SAFE BECAUSE OF THE TRANSACTION ID. Unlike createComment, Graph has
// update and delete, so a sync is a reconciliation that can run any number of
// times. The one unsafe moment is a create whose answer never arrived: it may
// have landed. Graph's `transactionId` exists for exactly that - a second POST
// with the same id returns the event the first one made instead of a duplicate -
// so the id is stored BEFORE the POST and reused until a create succeeds.
//
// PERMISSION: Graph APPLICATION permission Calendars.ReadWrite, with admin
// consent. GRANTED 19 Oct 2026 09:00; Exchange answered 403 for about four minutes
// after the token already carried the role, then took the first entry. Like
// Mail.Send, the permission reaches EVERY mailbox in the tenant unless Exchange
// narrows it (an application access policy, or RBAC for Applications scoped to the
// eight centre mailboxes). Check the token, not the portal: entra_app_token()'s
// `roles` claim.
//
// ONE VISIT, ONE ENTRY (19 Oct 2026). Brothers and sisters touring together are
// several items with one tour time; the centre's diary gets ONE entry naming them
// all ("Tour: Louis and Lucas Hahn"), carried by one of the items - the LEAD -
// while the others hold none. See tour_visit() for who is on a visit, and
// tour_calendar_members() for which item carries it.

declare(strict_types=1);

require_once __DIR__ . '/simple-mysqli.php';
require_once __DIR__ . '/dates.php';
require_once __DIR__ . '/graph.php';
require_once __DIR__ . '/audit.php';
require_once __DIR__ . '/siblings.php';   // sibling_phone_key() / sibling_email_key() - who is one family
require_once __DIR__ . '/kidsoft-cache.php';  // the centre's street address, for the parent's invite

/** How long a tour's calendar entry runs. Broc, 16 Oct 2026. */
const TOUR_CALENDAR_MINUTES = 30;

/**
 * Graph's name for Sydney. Stored times are Sydney wall-clock (CLAUDE.md), so they
 * are sent as-is with the zone named, never converted - Exchange applies DST.
 */
const TOUR_CALENDAR_TZ = 'AUS Eastern Standard Time';

/** Where the entry links back to. The app's public address, from the sign-in config. */
function tour_calendar_app_url(): string {
    $u = parse_url((string)(entra_cfg()['redirect_uri'] ?? ''));
    return isset($u['scheme'], $u['host']) ? $u['scheme'] . '://' . $u['host'] : '';
}

/** One item with everything the entry is written from. */
function tour_calendar_item(int $id): ?array {
    $w = dbx()->query(
        'SELECT w.*, c.name AS centre_name, c.calendar_mailbox AS centre_mailbox, c.kidsoft_service_id,
                a.child_dob, a.guardian_name, a.guardian_phone, a.guardian_email,
                GROUP_CONCAT(d.weekday ORDER BY d.weekday) AS days
           FROM work_items w
           JOIN centres c ON c.id = w.centre_id
           LEFT JOIN waitlist_applications a ON a.id = w.waitlist_id
           LEFT JOIN work_item_days d ON d.work_item_id = w.id
          WHERE w.id = ? GROUP BY w.id', [$id], 'i')->fetch();
    return $w ?: null;
}

/**
 * The items ON ONE VISIT: the same centre, the same booked time, and a parent's
 * phone or email in common - the brothers and sisters booked into one tour
 * (work_family_tour()), or one child's enquiry and application if both were ever
 * given the time. Joined transitively (one sibling may share the mobile, the next
 * the email). The shared contact is what keeps two families who happen to be
 * booked at 9:30 apart.
 *
 * Read fresh every time, never stored: a sibling whose tour is moved off on its
 * own simply stops being on this visit.
 *
 * @param array $w needs id, centre_id, tour_at
 * @return array rows, lowest id first; [] when there is no tour
 */
function tour_visit(array $w): array {
    if (empty($w['tour_at']) || empty($w['centre_id']) || empty($w['id'])) return [];
    $rows = dbx()->query(
        'SELECT w.id, w.centre_id, w.waitlist_id, w.kidsoft_child_id, w.child_name, w.stage,
                w.tour_at, w.tour_outcome, w.contact_phone, w.contact_email,
                w.calendar_event_id, w.calendar_mailbox, w.calendar_error,
                w.tour_invite, w.calendar_invited,
                c.name AS centre_name, c.calendar_mailbox AS centre_mailbox,
                a.guardian_phone, a.guardian_email
           FROM work_items w
           JOIN centres c ON c.id = w.centre_id
           LEFT JOIN waitlist_applications a ON a.id = w.waitlist_id
          WHERE w.centre_id = ? AND w.tour_at = ?
          ORDER BY w.id', [(int)$w['centre_id'], (string)$w['tour_at']])->fetchAll();

    $self = (int)$w['id'];
    $keys = [];
    foreach ($rows as $r) {
        $k = [];
        foreach ([$r['guardian_phone'], $r['contact_phone']] as $p) {
            if (($x = sibling_phone_key($p)) !== null) $k['p:' . $x] = true;
        }
        foreach ([$r['guardian_email'], $r['contact_email']] as $e) {
            if (($x = sibling_email_key($e)) !== null) $k['e:' . $x] = true;
        }
        $keys[(int)$r['id']] = $k;
    }
    if (!isset($keys[$self])) return [];

    $in = [$self => true]; $reach = $keys[$self];
    do {
        $grew = false;
        foreach ($keys as $id => $k) {
            if (isset($in[$id]) || !array_intersect_key($k, $reach)) continue;
            $in[$id] = true; $reach += $k; $grew = true;
        }
    } while ($grew);
    return array_values(array_filter($rows, static fn($r) => isset($in[(int)$r['id']])));
}

/**
 * Should this item's visit be in the diary at all? Not without a tour or a
 * mailbox for the centre, and not for a family lost before the visit - nobody is
 * coming, so the centre's diary should not say so. A lost family whose tour HAS
 * happened keeps it: it is what the day was. A no-show keeps it for the same
 * reason. ARCHIVED IS TREATED THE SAME (6 Nov 2026): an item archived before its
 * visit is a duplicate or a leftover, and nobody is coming on its account.
 */
function tour_calendar_wanted(array $r): bool {
    if (empty($r['tour_at']) || empty($r['centre_mailbox'])) return false;
    return !(in_array($r['stage'], ['lost', 'archived'], true) && $r['tour_at'] > date('Y-m-d H:i:s'));
}

/**
 * The items one calendar entry is written for, THE LEAD FIRST - or [] when this
 * item wants no entry. The lead is whichever already holds an entry (so a sibling
 * joining a booked visit updates it rather than replacing it), else the oldest.
 */
function tour_calendar_members(array $w): array {
    $m = array_values(array_filter(tour_visit($w), 'tour_calendar_wanted'));
    if (!in_array((int)$w['id'], array_map(static fn($r) => (int)$r['id'], $m), true)) return [];
    usort($m, static fn($a, $b) => [empty($a['calendar_event_id']), (int)$a['id']]
                                 <=> [empty($b['calendar_event_id']), (int)$b['id']]);
    return $m;
}

/**
 * Children's names as one phrase: "Louis and Lucas Hahn" when they share a
 * surname, "Himmat Singh and Rehmat Kaur" when they do not. The same name twice
 * (an enquiry and an application for one child) is said once.
 */
function tour_calendar_names(array $names): string {
    $seen = [];
    foreach ($names as $n) {
        $n = trim(preg_replace('/\s+/', ' ', (string)$n) ?? '');
        if ($n !== '' && !isset($seen[strtolower($n)])) $seen[strtolower($n)] = $n;
    }
    $names = array_values($seen);
    if (count($names) <= 1) return $names[0] ?? '';
    $parts = array_map(static fn($n) => explode(' ', $n), $names);
    $lasts = array_map(static fn($p) => count($p) > 1 ? strtolower(end($p)) : '', $parts);
    $same  = $lasts[0] !== '' && count(array_unique($lasts)) === 1;
    $say   = $same ? array_map(static fn($p) => implode(' ', array_slice($p, 0, -1)), $parts) : $names;
    $list  = implode(', ', array_slice($say, 0, -1)) . ' and ' . end($say);
    return $same ? $list . ' ' . end($parts[0]) : $list;
}

/**
 * The event a visit SHOULD have in Outlook, or null for none.
 *
 * @param array $members tour_calendar_item() rows, the lead first - one child, or
 *                       the brothers and sisters on one visit
 */
function tour_calendar_desired(array $members): ?array {
    if (!$members || !tour_calendar_wanted($members[0])) return null;
    // The children are listed oldest item first, whichever of them carries the
    // entry, so the title does not change with who happens to hold it.
    usort($members, static fn($a, $b) => (int)$a['id'] <=> (int)$b['id']);
    $w = $members[0];

    // Wall-clock arithmetic, in UTC so no DST rule can move the half hour.
    $start = new DateTimeImmutable($w['tour_at'], new DateTimeZone('UTC'));
    $end   = $start->modify('+' . TOUR_CALENDAR_MINUTES . ' minutes');

    $names = [1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri'];
    $child = static fn($r) => trim($r['child_name'] . (!empty($r['child_dob']) ? ', born ' . syd_date($r['child_dob']) : ''));
    $days  = static fn($r) => implode(', ', array_map(static fn($d) => $names[$d] ?? (string)$d,
                 $r['days'] === null ? [] : array_map('intval', explode(',', (string)$r['days']))));
    // The same "who to ring" work_get() publishes: an enquiry's own details, or
    // the application's guardian. A child already in care has neither here. On a
    // visit, the first child who has each.
    $reach = static function ($r) {
        $enquiry = !$r['waitlist_id'] && !$r['kidsoft_child_id'];
        return $enquiry ? [$r['contact_name'], $r['contact_phone'], $r['contact_email']]
                        : [$r['guardian_name'], $r['guardian_phone'], $r['guardian_email']];
    };
    [$parent, $phone, $email] = $reach($w);
    foreach ($members as $r) {
        [$p, $ph, $e] = $reach($r);
        $parent = $parent ?: $p; $phone = $phone ?: $ph; $email = $email ?: $e;
    }

    // THE PARENT'S INVITE: the entry is theirs instead, so nothing below - the
    // phone, the DOB, the link into the CRM - is in it.
    if (tour_invite_wanted($members, $email)) {
        return tour_invite_desired($members, $start, $end, $parent, (string)$email);
    }

    if (count($members) === 1) {
        // ONE CHILD: exactly the entry written since 16 Oct, so an entry already
        // in Outlook is not rewritten for nothing.
        $rows = [['Child', $child($w)], ['Parent', $parent], ['Phone', $phone], ['Email', $email],
                 ['Days wanted', $days($w)], ['For', $w['year'] ? (string)$w['year'] : null]];
    } else {
        // A VISIT: a line per child - who, what they want, for when - then the
        // family's contact once.
        $rows = [];
        foreach ($members as $r) {
            $want = array_filter([$days($r), $r['year'] ? 'for ' . $r['year'] : null]);
            $rows[] = ['Child', $child($r) . ($want ? '. ' . implode(', ', $want) : '')];
        }
        $rows[] = ['Parent', $parent]; $rows[] = ['Phone', $phone]; $rows[] = ['Email', $email];
    }

    $h = static fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
    $html = '<table style="font-family:Segoe UI,Arial,sans-serif;font-size:14px">';
    foreach ($rows as [$k, $v]) {
        if ($v === null || trim((string)$v) === '') continue;
        $html .= '<tr><td style="color:#666;padding:2px 12px 2px 0">' . $h($k) . '</td><td>' . $h($v) . '</td></tr>';
    }
    $html .= '</table>';
    $base = tour_calendar_app_url();
    if ($base !== '') {
        $link = static fn($r) => $base . '/progress?centre=' . (int)$r['centre_id'] . '&item=' . (int)$r['id'];
        $html .= '<p style="font-family:Segoe UI,Arial,sans-serif;font-size:14px">';
        if (count($members) === 1) {
            $html .= '<a href="' . $h($link($w)) . '">Open in the CRM</a>';
        } else {
            $html .= 'Open in the CRM: ' . implode(' | ', array_map(static fn($r) =>
                '<a href="' . $h($link($r)) . '">' . $h($r['child_name']) . '</a>', $members));
        }
        $html .= '</p>';
    }
    $html .= '<p style="font-family:Segoe UI,Arial,sans-serif;font-size:12px;color:#666">'
           . 'Added by the Little Miracles CRM. Move or cancel the tour there; a change made here in Outlook is not read back.</p>';

    return [
        'subject'  => 'Tour: ' . tour_calendar_names(array_column($members, 'child_name')),
        'body'     => ['contentType' => 'HTML', 'content' => $html],
        'start'    => ['dateTime' => $start->format('Y-m-d\TH:i:s'), 'timeZone' => TOUR_CALENDAR_TZ],
        'end'      => ['dateTime' => $end->format('Y-m-d\TH:i:s'),   'timeZone' => TOUR_CALENDAR_TZ],
        'showAs'   => 'busy',
        'location' => ['displayName' => 'Little Miracles ' . $w['centre_name']],
    ];
}

// ---------------------------------------------------------------------------
// THE PARENT'S INVITE (29 Oct 2026).
//
// Broc: "can we also have a checkbox that sends the calendar invite to the parent
// as well? The calendar invite that goes to the parent should be little miracles
// branded and have the centre street address as the location."
//
// ONE ENTRY, NOT TWO - Broc's choice. Ticked, the centre's entry BECOMES the
// invite: an Exchange meeting request from the centre's own mailbox, with the
// parent as its one attendee. So the centre sees the parent's accept or decline
// on it, a reply lands in the centre's inbox, and a moved tour reaches the parent
// as Outlook's own update. The price, accepted when he chose it: everything in
// the entry goes to the parent, so it no longer carries the phone, the DOB, the
// days or the CRM link the centre's own entry has.
//
// WORK ITEMS ONLY. calendar_row_write() is shared with support plan steps, so the
// attendee and the logo are handled in tour_calendar_write() and nowhere below
// it - a support plan entry must never invite anybody.
//
// THE ORDER IS WHAT KEEPS INTERNAL DETAIL AWAY FROM THE PARENT. The branded
// content is written first, the logo attached, and only then is the parent added.
// Taking an invite back (unticked, or their address changed) DELETES the entry -
// Graph documents that deleting a meeting sends its attendees a cancellation -
// and writes it again fresh, so the centre's own content is never PATCHed onto a
// meeting the parent is still on (a PATCH to a meeting goes to its attendees).
//
// NEVER A NEW INVITE FOR A VISIT THAT HAS HAPPENED; one already sent stands, so a
// tour's time passing does not send anybody a cancellation. Lost before the visit
// removes the entry as before, which now also tells the parent it is off.
//
// DELETED IN OUTLOOK WHILE THE PARENT WAS ON IT: somebody cancelled the meeting
// there, and Outlook will have told the parent. Writing it again would invite them
// straight back 15 minutes later, so the tick comes off and the centre gets its
// own entry back instead. Ticking it again sends a fresh invite.
//
// THE HASH COVERS THE BRANDED CONTENT, so changing the wording below sends every
// parent with a tour still to come an updated invite. That is the price of a
// reconciliation; change it knowing that.

/** The logo, inline so it shows without the parent clicking "download pictures". */
const TOUR_INVITE_LOGO      = __DIR__ . '/littlemiracles_branding/logo-inline-600.png';
const TOUR_INVITE_LOGO_NAME = 'little-miracles.png';
const TOUR_INVITE_LOGO_CID  = 'lm-logo';

/**
 * Should this visit's entry be the parent's invite? Ticked on any child on it
 * (work_set_tour_invite() ticks them all), an address to send it to, and either
 * still to come or already sent.
 *
 * @param array $members tour_calendar_item() rows
 */
function tour_invite_wanted(array $members, ?string $email): bool {
    if (!filter_var(trim((string)$email), FILTER_VALIDATE_EMAIL)) return false;
    $ticked = false; $sent = false;
    foreach ($members as $r) {
        $ticked = $ticked || !empty($r['tour_invite']);
        $sent   = $sent   || !empty($r['calendar_invited']);
    }
    return $ticked && ($sent || (string)$members[0]['tour_at'] > date('Y-m-d H:i:s'));
}

/**
 * The centre's street address, READ from Kidsoft (Service/getServices,
 * Options=Addresses), never copied here. null when Kidsoft does not answer.
 *
 * Kidsoft holds up to four per centre, measured 29 Oct 2026: Postal and Mail are
 * head office's PO address at Tuggerah for most of them; Physical is the CCS
 * registration, in capitals, abbreviated, and naming Hamlyn Terrace for Kanwal;
 * Service or Business is the one the centre typed itself (each centre has one or
 * the other, never both). That one first, then Physical.
 *
 * @return array{street:string, city:string, state:string, postalCode:string, line:string}|null
 */
function tour_centre_address(?string $serviceId): ?array {
    if (!$serviceId) return null;
    try {
        $services = kidsoft_cached('Service', 'getServices', ['Options' => 'Addresses'])['Services'] ?? [];
    } catch (Throwable $e) {
        error_log('tour_centre_address: ' . $e->getMessage());
        return null;
    }
    foreach ($services as $s) {
        if (($s['ServiceID'] ?? '') !== $serviceId) continue;
        foreach (['Service', 'Business', 'Physical'] as $type) {
            foreach ($s['Addresses'] ?? [] as $a) {
                $street = implode(', ', array_filter([trim((string)($a['FirstAddressLine'] ?? '')),
                                                      trim((string)($a['SecondAddressLine'] ?? ''))]));
                if (($a['Type'] ?? '') !== $type || $street === '' || empty($a['Suburb'])) continue;
                $city = trim((string)$a['Suburb']); $state = trim((string)($a['State'] ?? ''));
                $post = trim((string)($a['PostCode'] ?? ''));
                return ['street' => $street, 'city' => $city, 'state' => $state, 'postalCode' => $post,
                        'line' => $street . ', ' . trim($city . ' ' . $state . ' ' . $post)];
            }
        }
    }
    return null;
}

/**
 * The parent's invite: branded, the street address as its location, the parent as
 * its one attendee. Inline styles and tables only - mail clients drop <style>.
 *
 * @param array $members tour_calendar_item() rows, oldest item first
 */
function tour_invite_desired(array $members, DateTimeImmutable $start, DateTimeImmutable $end,
                             ?string $parent, string $email): array {
    $w      = $members[0];
    $centre = 'Little Miracles ' . $w['centre_name'];
    $addr   = tour_centre_address($w['kidsoft_service_id'] ?? null);
    $first  = static fn($n) => explode(' ', trim(preg_replace('/\s+/', ' ', (string)$n) ?? ''))[0];
    $h      = static fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
    $when   = $start->format('l') . ' ' . syd_datetime($w['tour_at']);
    $where  = $h($centre) . ($addr ? '<br>' . $h($addr['line']) : '');
    $label  = 'padding:4px 16px;font-size:13px;font-weight:600;color:#2e3192;vertical-align:top;width:64px';

    $html = '<table role="presentation" cellpadding="0" cellspacing="0" width="100%" style="max-width:560px;'
          . 'border-collapse:collapse;font-family:Outfit,\'Segoe UI\',Arial,sans-serif;color:#2b2b3a">'
          . '<tr><td style="padding:20px 24px 12px;border-top:6px solid #ed387d;background:#ffffff">'
          . '<img src="cid:' . TOUR_INVITE_LOGO_CID . '" alt="Little Miracles" width="260"'
          . ' style="display:block;border:0;width:260px;height:auto"></td></tr>'
          . '<tr><td style="padding:8px 24px 24px;background:#ffffff;font-size:15px;line-height:1.5">'
          . '<h1 style="margin:0 0 16px;font-size:22px;line-height:1.3;font-weight:600;color:#2e3192">'
          . 'Your tour of ' . $h($centre) . '</h1>'
          . '<p style="margin:0 0 12px">' . ($parent ? 'Hi ' . $h($first($parent)) . ',' : 'Hello,') . '</p>'
          . '<p style="margin:0 0 16px">Thank you for booking a tour for '
          . $h(tour_calendar_names(array_map($first, array_column($members, 'child_name'))))
          . '. We look forward to meeting you and showing you around.</p>'
          . '<table role="presentation" cellpadding="0" cellspacing="0" width="100%"'
          . ' style="border-collapse:collapse;background:#e6e3fc">'
          . '<tr><td style="' . $label . ';padding-top:14px">When</td>'
          . '<td style="padding:14px 16px 4px 0">' . $h($when) . '</td></tr>'
          . '<tr><td style="' . $label . ';padding-bottom:14px">Where</td>'
          . '<td style="padding:4px 16px 14px 0">' . $where . '</td></tr>'
          . '</table>'
          . '<p style="margin:16px 0 0">If this time no longer suits, just reply to this invitation and we will find another.</p>'
          . '<p style="margin:16px 0 0">See you soon,<br>The team at ' . $h($centre) . '</p>'
          . '</td></tr>'
          . '<tr><td style="padding:12px 24px;background:#2e3192;color:#ffffff;font-size:12px">'
          . $h($centre) . ($addr ? ' | ' . $h($addr['line']) : '') . '</td></tr>'
          . '</table>';

    return [
        'subject'  => 'Tour of ' . $centre . ' - ' . tour_calendar_names(array_column($members, 'child_name')),
        'body'     => ['contentType' => 'HTML', 'content' => $html],
        'start'    => ['dateTime' => $start->format('Y-m-d\TH:i:s'), 'timeZone' => TOUR_CALENDAR_TZ],
        'end'      => ['dateTime' => $end->format('Y-m-d\TH:i:s'),   'timeZone' => TOUR_CALENDAR_TZ],
        'showAs'   => 'busy',
        'location' => $addr
            ? ['displayName' => $addr['line'], 'locationType' => 'streetAddress',
               'address' => ['street' => $addr['street'], 'city' => $addr['city'], 'state' => $addr['state'],
                             'postalCode' => $addr['postalCode'], 'countryOrRegion' => 'Australia']]
            : ['displayName' => $centre],
        // The tour is moved in the CRM. A time the parent proposes in Outlook
        // would move nothing here, so they reply instead, as the body says.
        'allowNewTimeProposals' => false,
        // Taken off by tour_calendar_write() and added LAST - see above.
        'attendees' => [['type' => 'required',
                         'emailAddress' => array_filter(['address' => trim($email), 'name' => $parent ?: null])]],
    ];
}

/**
 * Attach the logo to an entry, once. Before the parent is added, so the invite
 * carries it. Returns [http code, body] like graph_send(); 200 when already there.
 */
function tour_invite_logo(string $mailbox, string $eventId): array {
    $path = '/users/' . rawurlencode($mailbox) . '/events/' . rawurlencode($eventId) . '/attachments';
    [$code, $body] = graph_get($path . '?$select=name');
    if ($code !== 200) return [$code, $body];
    foreach ($body['value'] ?? [] as $a) {
        if (($a['name'] ?? '') === TOUR_INVITE_LOGO_NAME) return [200, null];
    }
    $png = @file_get_contents(TOUR_INVITE_LOGO);
    if ($png === false) {                          // an invite without the logo beats no invite
        error_log('tour_invite_logo: cannot read ' . TOUR_INVITE_LOGO);
        return [200, null];
    }
    return graph_send('POST', $path, [
        '@odata.type'  => '#microsoft.graph.fileAttachment',
        'name'         => TOUR_INVITE_LOGO_NAME,
        'contentType'  => 'image/png',
        'contentBytes' => base64_encode($png),
        'isInline'     => true,
        'contentId'    => TOUR_INVITE_LOGO_CID,
    ]);
}

/** Why Graph said no, in words the modal can show as they are. */
function tour_calendar_error_text(int $code, $body): string {
    // Read after "Not in the Terrigal calendar: " - see window.tourCalendarNote().
    if ($code === 0)   return 'Microsoft did not answer, so it will try again.';
    if ($code === 401 || $code === 403) {
        return 'the app is not allowed to write to it yet (Calendars.ReadWrite has not been granted).';
    }
    $gc = is_array($body) ? (string)($body['error']['code'] ?? '') : '';
    if ($code === 404) return 'Microsoft could not find it' . ($gc ? ' (' . $gc . ')' : '') . '.';
    return 'Microsoft answered HTTP ' . $code . ($gc ? ' ' . $gc : '') . ', so it will try again.';
}

/**
 * THE ROWS THAT CARRY AN OUTLOOK ENTRY, and what each is called in the audit.
 *
 * Two since 29 Oct 2026: a work item's tour, and a support plan step's booked
 * time (support-plan-calendar.php). They hold the same six calendar_* columns and
 * are reconciled by the SAME code below - one copy of the create / update /
 * remove protocol, because the transaction-id rule is exactly the kind of
 * subtlety two copies would drift on. `txn` keeps their transaction ids apart.
 *
 * A table not listed here cannot be written: its name goes into SQL.
 */
const CALENDAR_ROWS = [
    'work_items'         => ['entity' => 'work_item',         'audit' => 'work.calendar',
                             'txn' => 'lm-attendances-'],
    'support_plan_steps' => ['entity' => 'support_plan_step', 'audit' => 'support_plan.calendar',
                             'txn' => 'lm-attendances-sp-'],
];

/** Write the calendar columns WITHOUT touching updated_at - this is not an edit to the row. */
function calendar_row_store(string $table, int $id, array $set): void {
    if (!isset(CALENDAR_ROWS[$table])) throw new InvalidArgumentException('Unknown calendar table.');
    $cols = []; $args = [];
    foreach ($set as $k => $v) { $cols[] = "{$k} = ?"; $args[] = $v; }
    $args[] = $id;
    dbx()->query("UPDATE {$table} SET " . implode(', ', $cols) . ', updated_at = updated_at WHERE id = ?', $args);
}

/** Record a failure, auditing it only when the reason is new - the sweep retries every 15 minutes. */
function calendar_row_fail(string $table, array $w, string $error, string $action): string {
    $error = mb_substr($error, 0, 255);
    calendar_row_store($table, (int)$w['id'], ['calendar_error' => $error, 'calendar_synced_at' => date('Y-m-d H:i:s')]);
    if ($w['calendar_error'] !== $error) {
        audit(CALENDAR_ROWS[$table]['audit'], CALENDAR_ROWS[$table]['entity'], (int)$w['id'],
              ['action' => $action, 'ok' => false, 'mailbox' => $w['centre_mailbox'], 'error' => $error]);
    }
    return 'failed';
}

/**
 * Bring one item's calendar entry into line with its tour - and, when it is on a
 * visit with brothers and sisters, the visit's one entry.
 *
 * An item that is not the visit's lead holds no entry of its own: one left over
 * from before it joined the visit is removed, and the lead's is brought into line
 * (named for every child on it). Moving a sibling OFF a visit makes it a lead of
 * its own again, and the next sync of the visit it left renames that entry.
 *
 * @return string 'in' (the entry matches), 'none' (nothing wanted, nothing there),
 *                'removed', or 'failed' (calendar_error says why)
 */
function tour_calendar_sync(int $id): string {
    $w = tour_calendar_item($id);
    if (!$w) return 'none';
    $members = tour_calendar_members($w);
    if (!$members || (int)$members[0]['id'] === $id) {
        $full = array_values(array_filter(array_map(static fn($r) =>
            (int)$r['id'] === $id ? $w : tour_calendar_item((int)$r['id']), $members)));
        return tour_calendar_write($w, tour_calendar_desired($full));
    }
    $own  = tour_calendar_write($w, null);
    $lead = tour_calendar_item((int)$members[0]['id']);
    $full = array_values(array_filter(array_map(static fn($r) => tour_calendar_item((int)$r['id']), $members)));
    $res  = $lead ? tour_calendar_write($lead, tour_calendar_desired($full)) : 'failed';
    return $own === 'failed' || $res === 'failed' ? 'failed' : 'in';
}

/**
 * One work item's own entry. See calendar_row_write() - and THE PARENT'S INVITE
 * above, which is handled here and nowhere below, so a support plan step's entry
 * can never invite anybody.
 */
function tour_calendar_write(array $w, ?array $desired): string {
    $id        = (int)$w['id'];
    $attendees = $desired['attendees'] ?? [];
    $to        = $attendees[0]['emailAddress']['address'] ?? null;
    if ($desired !== null) unset($desired['attendees']);
    $events    = static fn(string $mb) => '/users/' . rawurlencode($mb) . '/events';

    // 1. AN INVITE TO TAKE BACK - unticked, or the parent's address changed. The
    //    entry is DELETED, which sends them a cancellation, and written again
    //    fresh below. A tour cleared or lost is calendar_row_write()'s own DELETE.
    if ($w['calendar_event_id'] && $w['calendar_invited'] !== null && $desired !== null
        && strcasecmp((string)$to, (string)$w['calendar_invited']) !== 0) {
        [$code, $body] = graph_send('DELETE', $events($w['calendar_mailbox']) . '/' . rawurlencode($w['calendar_event_id']));
        if ($code !== 204 && $code !== 404) {
            return calendar_row_fail('work_items', $w, tour_calendar_error_text($code, $body), 'uninvite');
        }
        $gone = ['calendar_event_id' => null, 'calendar_mailbox' => null, 'calendar_hash' => null,
                 'calendar_txn' => null, 'calendar_invited' => null];
        calendar_row_store('work_items', $id, $gone);
        audit('work.calendar', 'work_item', $id,
              ['action' => 'uninvited', 'ok' => true, 'mailbox' => $w['calendar_mailbox'], 'to' => $w['calendar_invited']]);
        $w = $gone + $w;
    }

    $before   = $w['calendar_event_id'];
    $inPlace  = $before && $w['calendar_mailbox'] === $w['centre_mailbox'];
    $res      = calendar_row_write('work_items', $w, $desired);
    $now      = dbx()->query('SELECT calendar_event_id, calendar_mailbox, calendar_invited, calendar_error
                                FROM work_items WHERE id = ?', [$id], 'i')->fetch();

    // 2. A DIFFERENT ENTRY (or none) INVITES NOBODY. When the one the parent was on
    //    vanished from Outlook in place, somebody cancelled it there - see above.
    if ($now['calendar_invited'] !== null && $now['calendar_event_id'] !== $before) {
        calendar_row_store('work_items', $id, ['calendar_invited' => null]);
        if ($inPlace && $desired !== null) {
            $visit = array_map(static fn($r) => (int)$r['id'], tour_visit($w)) ?: [$id];
            $marks = dbx()->whereIn($visit);
            dbx()->query("UPDATE work_items SET tour_invite = 0, updated_at = updated_at WHERE id IN ($marks)", $visit);
            audit('work.calendar', 'work_item', $id, ['action' => 'invite_withdrawn', 'ok' => true,
                  'mailbox' => $w['centre_mailbox'], 'to' => $now['calendar_invited'],
                  'why' => 'The invite was deleted in Outlook, so the parent was not invited again.']);
            return tour_calendar_sync($id);             // the centre's own entry again
        }
        $now['calendar_invited'] = null;
    }
    if ($res !== 'in' || $to === null || $now['calendar_invited'] !== null) return $res;

    // 3. INVITE THEM: the logo, then the parent. Exchange sends the request.
    $mailbox = (string)$now['calendar_mailbox'];
    $event   = (string)$now['calendar_event_id'];
    $w['calendar_error'] = $now['calendar_error'];
    [$code, $body] = tour_invite_logo($mailbox, $event);
    if ($code !== 200 && $code !== 201) {
        return calendar_row_fail('work_items', $w, tour_calendar_error_text($code, $body), 'invite');
    }
    // responseRequested IN THE SAME PATCH: measured 29 Oct 2026, adding the
    // attendee alone left it false, so the parent was not asked to accept and the
    // centre could not see an answer. Sent here, and only here, because a later
    // change to a meeting goes to the parent as an update.
    [$code, $body] = graph_send('PATCH', $events($mailbox) . '/' . rawurlencode($event),
                                ['attendees' => $attendees, 'responseRequested' => true]);
    if ($code !== 200) return calendar_row_fail('work_items', $w, tour_calendar_error_text($code, $body), 'invite');
    calendar_row_store('work_items', $id, ['calendar_invited' => $to, 'calendar_error' => null]);
    audit('work.calendar', 'work_item', $id, ['action' => 'invited', 'ok' => true, 'mailbox' => $mailbox, 'to' => $to]);
    return 'in';
}

/**
 * Make one row's own entry match $desired: create, update, move between
 * mailboxes, or (null) remove.
 *
 * @param string $table a CALENDAR_ROWS key
 * @param array  $w     the row's calendar_* columns, its id, and centre_mailbox -
 *                      the mailbox the entry SHOULD be in
 */
function calendar_row_write(string $table, array $w, ?array $desired): string {
    $id     = (int)$w['id'];
    $events = static fn(string $mb) => '/users/' . rawurlencode($mb) . '/events';
    $store  = static fn(array $set) => calendar_row_store($table, $id, $set);
    $fail   = static fn(int $code, $body, string $action) =>
                  calendar_row_fail($table, $w, tour_calendar_error_text($code, $body), $action);
    $ok     = static fn(string $action, string $mailbox) =>
                  audit(CALENDAR_ROWS[$table]['audit'], CALENDAR_ROWS[$table]['entity'], $id,
                        ['action' => $action, 'ok' => true, 'mailbox' => $mailbox]);

    // NOTHING WANTED. Remove what is there; 404 means somebody already did.
    if ($desired === null) {
        if (!$w['calendar_event_id']) {
            if ($w['calendar_error'] !== null || $w['calendar_hash'] !== null) {
                $store(['calendar_error' => null, 'calendar_hash' => null, 'calendar_txn' => null]);
            }
            return 'none';
        }
        [$code, $body] = graph_send('DELETE', $events($w['calendar_mailbox']) . '/' . rawurlencode($w['calendar_event_id']));
        if ($code !== 204 && $code !== 404) return $fail($code, $body, 'remove');
        $store(['calendar_event_id' => null, 'calendar_mailbox' => null, 'calendar_hash' => null,
                'calendar_txn' => null, 'calendar_error' => null,
                'calendar_synced_at' => date('Y-m-d H:i:s')]);
        $ok('removed', (string)$w['calendar_mailbox']);
        return 'removed';
    }

    $mailbox = (string)$w['centre_mailbox'];
    $hash    = sha1(json_encode([$mailbox, $desired], JSON_UNESCAPED_SLASHES));
    if ($w['calendar_event_id'] && $w['calendar_mailbox'] === $mailbox
        && $w['calendar_hash'] === $hash && $w['calendar_error'] === null) {
        return 'in';                                   // nothing to send
    }

    // The centre's mailbox changed under an existing entry: take it out of the old one.
    if ($w['calendar_event_id'] && $w['calendar_mailbox'] !== $mailbox) {
        [$code, $body] = graph_send('DELETE', $events($w['calendar_mailbox']) . '/' . rawurlencode($w['calendar_event_id']));
        if ($code !== 204 && $code !== 404) return $fail($code, $body, 'move');
        $store(['calendar_event_id' => null, 'calendar_mailbox' => null]);
        $w['calendar_event_id'] = null;
    }

    // An entry exists: update it in place. Gone from Outlook (404) means somebody
    // deleted it there - the booking here still stands, so it is written again.
    if ($w['calendar_event_id']) {
        [$code, $body] = graph_send('PATCH', $events($mailbox) . '/' . rawurlencode($w['calendar_event_id']), $desired);
        if ($code === 200) {
            $store(['calendar_hash' => $hash, 'calendar_error' => null, 'calendar_synced_at' => date('Y-m-d H:i:s')]);
            $ok('updated', $mailbox);
            return 'in';
        }
        if ($code !== 404) return $fail($code, $body, 'update');
        $store(['calendar_event_id' => null, 'calendar_mailbox' => null]);
    }

    // Create. The transaction id is stored FIRST and reused until a create
    // succeeds, so a POST whose answer was lost cannot leave two entries.
    $txn = $w['calendar_txn'] ?: (CALENDAR_ROWS[$table]['txn'] . $id . '-' . bin2hex(random_bytes(8)));
    if ($w['calendar_txn'] !== $txn) $store(['calendar_txn' => $txn]);
    [$code, $body] = graph_send('POST', $events($mailbox), $desired + ['transactionId' => $txn]);
    if (($code === 201 || $code === 200) && !empty($body['id'])) {
        $store(['calendar_event_id' => (string)$body['id'], 'calendar_mailbox' => $mailbox,
                'calendar_hash' => $hash, 'calendar_txn' => null, 'calendar_error' => null,
                'calendar_synced_at' => date('Y-m-d H:i:s')]);
        $ok('created', $mailbox);
        return 'in';
    }
    return $fail($code, $body, 'create');
}

/**
 * An item is being DELETED (work_cancel): take its entry out of Outlook first,
 * from the row as it stood. Nothing is left to record a failure on, so a failure
 * is audited with the event id - the one place somebody can find it to remove by
 * hand. In practice unreachable: work_cancel() refuses an item past `identified`,
 * and booking a tour moves it to `tour_booked`.
 */
function tour_calendar_forget(array $w): void {
    if (empty($w['calendar_event_id']) || empty($w['calendar_mailbox'])) return;
    [$code, ] = graph_send('DELETE', '/users/' . rawurlencode($w['calendar_mailbox']) . '/events/'
                                     . rawurlencode($w['calendar_event_id']));
    audit('work.calendar', 'work_item', (int)$w['id'], [
        'action' => 'removed', 'ok' => $code === 204 || $code === 404, 'mailbox' => $w['calendar_mailbox'],
    ] + ($code === 204 || $code === 404 ? [] : ['orphaned_event_id' => $w['calendar_event_id'], 'http' => $code]));
}

/**
 * Every item whose entry may be out of step: a tour from yesterday onwards (an
 * older one is history and is not chased), or an entry whose tour was cleared.
 * Items already in step cost a hash and no call.
 *
 * @return array{checked:int, in:int, removed:int, failed:int, none:int}
 */
function tour_calendar_sweep(bool $dryRun = false): array {
    $ids = dbx()->query(
        'SELECT id FROM work_items
          WHERE tour_at >= NOW() - INTERVAL 1 DAY
             OR (tour_at IS NULL AND calendar_event_id IS NOT NULL)
          ORDER BY tour_at'
    )->fetchAll('col');
    $out = ['checked' => count($ids), 'in' => 0, 'removed' => 0, 'failed' => 0, 'none' => 0];
    if ($dryRun) return $out;
    foreach ($ids as $id) {
        try {
            $out[tour_calendar_sync((int)$id)]++;
        } catch (Throwable $e) {
            $out['failed']++;
            error_log('tour_calendar_sync(' . $id . '): ' . $e->getMessage());
        }
    }
    return $out;
}

/**
 * What the modals say about the entry, or null to say nothing. Published on the
 * item by work_get() and work_for_waitlist() so the browser renders, never decides.
 */
function tour_calendar_status(array $w): ?array {
    if (empty($w['tour_at'])) return null;
    $centre = (string)($w['centre_name'] ?? '');
    // ON A VISIT the entry is the lead's, and so is its state; `with` names the
    // other children on it. Present and empty otherwise.
    $src = $w; $with = [];
    $members = tour_calendar_members($w);
    if (count($members) > 1) {
        $src = $members[0];
        foreach ($members as $r) if ((int)$r['id'] !== (int)$w['id']) $with[] = $r['child_name'];
    }
    // Who the entry has invited, once Exchange took it - THE PARENT'S INVITE.
    $invited = !empty($src['calendar_invited']) ? ['invited' => $src['calendar_invited']] : [];
    if (!empty($src['calendar_error'])) {
        return ['state' => 'failed', 'centre' => $centre, 'text' => $src['calendar_error'], 'with' => $with] + $invited;
    }
    if (!empty($src['calendar_event_id'])) return ['state' => 'in', 'centre' => $centre, 'with' => $with] + $invited;
    return null;
}
