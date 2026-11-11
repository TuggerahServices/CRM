<?php
// ISSUE REPORTS - the "Report Issue" button at the right-hand end of the centre
// bar on every screen. (13 Oct 2026, asked for by Broc.)
//
// Somebody describes what went wrong; it is saved to `issue_reports` and emailed
// to ISSUE_REPORT_TO. Markup in issue-modal.php, behaviour (including the trail
// of recent activity) in static/js/issues.js, endpoint api/issues.php. See
// _incl/docs/issue-reports.md.
//
// THE ROW IS THE RECORD AND THE EMAIL IS THE NOTIFICATION. The row commits
// first and the email's outcome is written back onto it, so a Graph failure
// costs a notification, never the report - the same order as the Kidsoft
// comment copy in comments.php.

require_once __DIR__ . '/simple-mysqli.php';
require_once __DIR__ . '/dates.php';
require_once __DIR__ . '/graph.php';     // graph_send_mail(); pulls in entra.php
require_once __DIR__ . '/users.php';     // user_role()
require_once __DIR__ . '/audit.php';

/** Who is emailed about a new report. */
const ISSUE_REPORT_TO = ['broc@littlemiracles.com.au'];

/** The textarea's maxlength, checked again here because a browser attribute is not a control. */
const ISSUE_DESCRIPTION_MAX = 5000;

/** Most trail entries kept, matching TRAIL_MAX in issues.js. */
const ISSUE_TRAIL_MAX = 40;

/** Trail entry kinds issues.js records, and how the email names each. */
const ISSUE_TRAIL_KINDS = [
    'page'   => 'Opened',
    'click'  => 'Clicked',
    'change' => 'Changed',
    'api'    => 'Server',
    'toast'  => 'Message',
    'error'  => 'Script error',
];

/**
 * What the browser sent as context, reduced to known fields at bounded sizes.
 *
 * The browser is the only thing that can say which centre and year were on
 * screen or what was clicked, so it is asked - but it is not trusted for
 * anything beyond being read by a person. Unknown keys are dropped, strings are
 * cut, and the trail is capped, so a broken or hostile page cannot park a
 * megabyte in the table or in somebody's inbox.
 */
function issue_context_clean(mixed $raw): array {
    if (!is_array($raw)) return [];
    $str = static fn($v, int $max) => mb_substr(trim(is_scalar($v) ? (string)$v : ''), 0, $max);

    $ctx = [
        'title'    => $str($raw['title'] ?? '', 190),
        'centre'   => $str($raw['centre'] ?? '', 100),
        'year'     => $str($raw['year'] ?? '', 10),
        'viewport' => $str($raw['viewport'] ?? '', 20),
        'screen'   => $str($raw['screen'] ?? '', 20),
        'trail'    => [],
    ];

    $trail = is_array($raw['trail'] ?? null) ? array_slice($raw['trail'], -ISSUE_TRAIL_MAX) : [];
    foreach ($trail as $t) {
        if (!is_array($t) || !isset(ISSUE_TRAIL_KINDS[$t['k'] ?? ''])) continue;
        $ctx['trail'][] = [
            'k'   => $t['k'],
            's'   => $str($t['s'] ?? '', 300),
            'ago' => max(0, (int)($t['ago'] ?? 0)),
            'n'   => max(1, (int)($t['n'] ?? 1)),
            'bad' => !empty($t['bad']),
        ];
    }
    return array_filter($ctx, static fn($v) => $v !== '' && $v !== []);
}

/**
 * Save a report, then email it. Returns the new id.
 *
 * Throws only if the INSERT fails. The email is caught: its outcome goes onto
 * the row (emailed_at or email_error) and into the PHP error log, and the
 * person who sent the report is told it was received either way - it was.
 *
 * NO REPORT NUMBER LEAVES THIS FUNCTION (13 Oct 2026, Broc). The row's id is
 * the table's key and nothing more: the email, its subject and the reply to the
 * browser carry none, and a report is found by who sent it and when.
 */
function issue_report_save(array $user, string $page, string $description, array $ctx): void {
    $email = strtolower((string)($user['email'] ?? ''));
    $name  = trim((string)($user['name'] ?? '')) ?: null;
    $role  = user_role();
    $ua    = mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500) ?: null;

    dbx()->query(
        'INSERT INTO issue_reports (staff_email, staff_name, staff_role, page_url, description, context, user_agent)
         VALUES (?, ?, ?, ?, ?, ?, ?)',
        [$email, $name, $role, $page, $description,
         $ctx ? json_encode($ctx, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : null, $ua]
    );
    $id = (int)dbx()->insertId();
    audit('issue.report', 'issue_report', $id, ['page' => $page]);

    $row = dbx()->query('SELECT * FROM issue_reports WHERE id = ?', [$id], 'i')->fetch();
    try {
        graph_send_mail(ISSUE_REPORT_TO, issue_email_subject($row, $ctx), issue_email_html($row, $ctx),
                        null, $email !== '' ? [$email] : []);
        dbx()->query('UPDATE issue_reports SET emailed_at = NOW() WHERE id = ?', [$id], 'i');
    } catch (Throwable $e) {
        error_log('crm: issue report from ' . $email . ' saved, email failed: ' . $e->getMessage());
        dbx()->query('UPDATE issue_reports SET email_error = ? WHERE id = ?',
                     [mb_substr($e->getMessage(), 0, 500), $id]);
    }
}

/** "CRM issue - Placement - The offer button does nothing when..." */
function issue_email_subject(array $row, array $ctx): string {
    $first = preg_replace('/\s+/', ' ', trim((string)$row['description']));
    if (mb_strlen($first) > 70) $first = rtrim(mb_substr($first, 0, 67)) . '...';
    $where = ($ctx['title'] ?? '') !== '' ? ' - ' . $ctx['title'] : '';
    return 'CRM issue' . $where . ' - ' . $first;
}

/** Where the app lives, for the link back to the page. Read off the SSO redirect URI. */
function issue_app_base(): string {
    $u = parse_url((string)(entra_cfg()['redirect_uri'] ?? ''));
    return isset($u['scheme'], $u['host']) ? $u['scheme'] . '://' . $u['host'] : '';
}

/** "12s", "3m 05s", "1h 02m" - how long before the report was sent. */
function issue_ago(int $s): string {
    if ($s < 60)   return $s . 's';
    if ($s < 3600) return intdiv($s, 60) . 'm ' . sprintf('%02d', $s % 60) . 's';
    return intdiv($s, 3600) . 'h ' . sprintf('%02d', intdiv($s % 3600, 60)) . 'm';
}

function issue_email_html(array $row, array $ctx): string {
    $h    = static fn($v) => htmlspecialchars((string)$v, ENT_QUOTES);
    $who  = $row['staff_name'] ? $row['staff_name'] . ' (' . $row['staff_email'] . ')' : $row['staff_email'];
    $link = issue_app_base() . $row['page_url'];

    $facts = [
        'From'    => $who . ($row['staff_role'] ? ', ' . $row['staff_role'] : ''),
        'Sent'    => syd_datetime($row['created_at']),
        'Screen'  => $ctx['title'] ?? '',
        'Centre'  => $ctx['centre'] ?? '',
        'Year'    => $ctx['year'] ?? '',
        'Window'  => trim(($ctx['viewport'] ?? '') . (($ctx['screen'] ?? '') !== '' ? ' (screen ' . $ctx['screen'] . ')' : '')),
        'Browser' => $row['user_agent'] ?? '',
    ];

    $td  = 'padding:4px 12px 4px 0;vertical-align:top;font-size:13px;';
    $out = '<div style="font-family:Segoe UI,Arial,sans-serif;color:#1f2330;max-width:760px">'
         . '<h2 style="color:#2e3192;font-size:18px;margin:0 0 4px">Issue reported in the CRM</h2>'
         . '<p style="margin:0 0 14px;color:#666;font-size:13px">Reply to this email to answer ' . $h($row['staff_name'] ?: $row['staff_email']) . ' directly.</p>'
         . '<div style="border-left:3px solid #ed387d;background:#feeef4;padding:10px 14px;margin:0 0 16px;font-size:14px;white-space:pre-wrap">'
         . $h($row['description']) . '</div>'
         . '<table style="border-collapse:collapse;margin:0 0 16px">';
    $out .= '<tr><td style="' . $td . 'color:#666">Page</td><td style="' . $td . '"><a href="' . $h($link) . '">' . $h($row['page_url']) . '</a></td></tr>';
    foreach ($facts as $label => $value) {
        if ($value === '') continue;
        $out .= '<tr><td style="' . $td . 'color:#666">' . $h($label) . '</td><td style="' . $td . '">' . $h($value) . '</td></tr>';
    }
    $out .= '</table>';

    $trail = $ctx['trail'] ?? [];
    if ($trail) {
        $out .= '<h3 style="font-size:14px;margin:0 0 6px">What happened before it was sent</h3>'
              . '<table style="border-collapse:collapse;font-size:12px;font-family:Consolas,monospace">';
        // Most recent first: the last thing that happened is the likeliest cause.
        foreach (array_reverse($trail) as $t) {
            $colour = $t['bad'] ? 'color:#b02a37;' : '';
            $out .= '<tr><td style="' . $td . 'color:#666;white-space:nowrap;text-align:right">' . $h(issue_ago($t['ago'])) . ' before</td>'
                  . '<td style="' . $td . 'white-space:nowrap;' . $colour . '">' . $h(ISSUE_TRAIL_KINDS[$t['k']]) . '</td>'
                  . '<td style="' . $td . $colour . '">' . $h($t['s']) . ($t['n'] > 1 ? ' <span style="color:#666">(x' . (int)$t['n'] . ')</span>' : '') . '</td></tr>';
        }
        $out .= '</table>';
    }
    return $out . '</div>';
}
