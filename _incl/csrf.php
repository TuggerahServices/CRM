<?php
// CSRF protection for state-changing API calls.
//
// The token lives in the session and is published to the page as window.CSRF by
// layout_header(). static/js/app.js attaches it to every jQuery AJAX request as
// the X-CSRF-Token header, so individual endpoints only need csrf_require().
//
// Session cookies are SameSite=Lax, which already blocks cross-site POSTs from
// forms; this is the second layer, and it also covers same-site subdomain risk.

require_once __DIR__ . '/auth.php';

function csrf_token(): string {
    auth_boot();
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
    return $_SESSION['csrf'];
}

/**
 * Guard a mutating endpoint. Answers 403 (a status the UI can never legitimately
 * trip, so nginx swallowing the body per api.php is fine) and exits on mismatch.
 */
function csrf_require(): void {
    auth_boot();
    $sent = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($_POST['csrf'] ?? '');
    if (empty($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], (string)$sent)) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'CSRF token invalid; reload the page.']);
        exit;
    }
}
