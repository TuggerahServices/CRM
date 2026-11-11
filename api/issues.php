<?php
// REPORT ISSUE - the button at the right of the centre bar. (13 Oct 2026)
// See _incl/issue-reports.php and _incl/docs/issue-reports.md.
//
// Every signed-in user may send one, whatever their role. NO CENTRE GUARD, and
// that is not an omission: nothing here reads a centre, a room or a family. The
// centre named in the report is the text of the tab that was active on screen,
// stored for a person to read, never looked up.
//
// Readable failures go through api_fail() (HTTP 200 + ok:false) because nginx
// discards the body of a real 4xx under /api/. See _incl/api.php.

declare(strict_types=1);

require_once __DIR__ . '/../_incl/auth.php';
require_once __DIR__ . '/../_incl/csrf.php';
require_once __DIR__ . '/../_incl/api.php';
require_once __DIR__ . '/../_incl/issue-reports.php';

$user = auth_require_api();
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { http_response_code(405); exit; }
csrf_require();

$action = $_POST['action'] ?? '';

switch ($action) {
    case 'report':
        $description = trim((string)($_POST['description'] ?? ''));
        if ($description === '') api_fail('Tell us a little about what happened first.');
        if (mb_strlen($description) > ISSUE_DESCRIPTION_MAX) {
            api_fail('That is longer than ' . ISSUE_DESCRIPTION_MAX . ' characters. Could you shorten it?');
        }

        // A path on this site, or nothing. It becomes a link in an email, so
        // anything that is not one - a full URL, a javascript: string - is
        // recorded as unknown rather than passed along.
        $page = mb_substr(trim((string)($_POST['page'] ?? '')), 0, 1000);
        if (!preg_match('#^/(?!/)#', $page)) $page = '/';

        $ctx = issue_context_clean(json_decode((string)($_POST['context'] ?? ''), true));
        issue_report_save($user, $page, $description, $ctx);
        echo json_encode(['ok' => true]);
        break;

    default:
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'unknown action']);
}
