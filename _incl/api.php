<?php
// JSON API helpers.
//
// WHY api_fail() EXISTS: /api/ runs with fastcgi_intercept_errors on, and nginx
// has an error_page for 400/401/403/404/405/422/429/500..503 (snippets/
// error-pages-json.conf). So if PHP answers a 4xx/5xx, nginx THROWS AWAY the body
// and substitutes its own generic JSON error - the message never reaches the
// browser, and the UI can only show "Unprocessable Entity".
//
// The rule, therefore:
//   - Guards the UI cannot trip (wrong method, missing CSRF header, not signed in,
//     unknown id) keep a real HTTP status. Nobody reads their body.
//   - Anything a PAM is MEANT TO READ - their input was rejected, Kidsoft failed,
//     the request was already approved - goes through api_fail(): HTTP 200 with
//     ok:false and the message, so it survives.
//
// Every JS caller of an api_fail()-capable endpoint must therefore check
// `res.ok === false` in its success handler, not just .fail().

// DECLARE THE CONTENT TYPE, AND IT IS LOAD-BEARING FOR EVERY MUTATION IN THE APP.
// PHP-FPM's default_mimetype is "text/html" and no endpoint overrode it, so every
// /api/ response went out as text/html. jQuery only parses a response as JSON when
// the response SAYS it is JSON: $.getJSON forces it, but a bare $.post sniffs the
// Content-Type and hands the callback a raw STRING. Every mutating handler in this
// app is written as `if (!res.ok) { toast(res.error, 'danger'); return; }` - on a
// string, `res.ok` is undefined and `res.error` is undefined, so EVERY write
// succeeded server-side and then showed the user a BLANK RED TOAST and skipped its
// refresh. Reads looked fine throughout, because they all use $.getJSON. Diagnosed
// 4 Aug 2026 from the Waitlist import button: audit_log recorded the import running
// (213 fetched, 117 refreshed) at the same second the screen reported a blank error.
//
// The vhost's `default_type application/json` inside `location ^~ /api/` does NOT
// cover this - default_type only applies when upstream sends no Content-Type at
// all, and PHP always sends one. It has to be set here.
//
// Set at include time so it covers every endpoint (all of them require this file)
// and every exit path, including api_fail() and any uncaught throw.
header('Content-Type: application/json');

/** Answer a readable failure: HTTP 200 + {ok:false, error, ...$extra}. Exits. */
function api_fail(string $msg, array $extra = []): void {
    echo json_encode(array_merge(['ok' => false, 'error' => $msg], $extra), JSON_UNESCAPED_SLASHES);
    exit;
}

/**
 * THE CENTRE GUARD. Every endpoint taking a centre id runs it through here.
 * (19 Aug 2026, with the supervisor role.)
 *
 * It replaced `if (!centre_get($id)) api_fail('Unknown centre.')`, which asked
 * only whether the centre EXISTS. That was the whole check on every endpoint in
 * the app, and once one role could see less than another it stopped being
 * enough: hiding the centre bar decides what a supervisor is offered, and
 * /api/waitlist?centre_id=3 is what a supervisor is actually served. The tab is
 * cosmetic; this is the door.
 *
 * "Unknown centre" IS THE ANSWER FOR BOTH CASES, deliberately. A centre that is
 * not theirs and a centre id that does not exist read identically, so the reply
 * does not confirm which other centres are real - and there is nothing useful a
 * supervisor could do with the difference anyway, since they never see a link to
 * one.
 *
 * $allowGroup is for endpoints where 0 means the whole organisation. A user who
 * does not see the group gets a readable refusal instead: they never see the
 * "All centres" tab, so this only fires on a hand-made request or a stale tab
 * left open through a role change.
 */
function api_require_centre(int $centreId, bool $allowGroup = false): void {
    if ($centreId === 0) {
        if ($allowGroup && user_all_centres()) return;
        if ($allowGroup) api_fail('Choose a centre.');
    }
    if (!$centreId || !centre_get($centreId) || !user_can_centre($centreId)) {
        api_fail('Unknown centre.');
    }
}
