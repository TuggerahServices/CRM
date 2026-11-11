<?php
// SSO session glue: OIDC auth-code + PKCE against Entra, with access control
// restricted to the configured groups/users. Include this from protected pages
// and the /auth endpoints.

require_once __DIR__ . '/entra.php';
require_once __DIR__ . '/graph.php';
// WHO MAY SIGN IN, AND AS WHAT. The allowlist used to be a hard-coded array in
// entra-config.php; it is now a table an administrator edits from the app. This
// file is required by every page and every endpoint, which is precisely why the
// role and centre scope are read here rather than by each screen for itself.
require_once __DIR__ . '/users.php';
// PINS THE TIMEZONE, which is why it is required from here rather than from
// wherever a date happens to be formatted. php.ini leaves date.timezone unset on
// this box, so without it every request answers in UTC while MySQL answers in
// Sydney - see the header of dates.php for what that was costing. Every page
// and every /api endpoint requires auth.php, so this is the one place that
// reaches all of them.
require_once __DIR__ . '/dates.php';

// WHERE SESSION FILES LIVE, and it is not the default. Audited 6 Aug 2026.
//
// PHP's default save_path on this box is /var/lib/php/sessions, mode 1733 --
// world-writable, shared by every PHP app on the server. That combination
// quietly cancels the use_strict_mode defence set up below, because "an id PHP
// issued" is decided by the files handler asking one question: does sess_<id>
// already exist in save_path? Verified directly: with strict mode ON, a session
// id this app never minted was accepted and its file's contents were read
// straight into $_SESSION, purely because the file was there.
//
// So any process on this box that can write to that directory - which is every
// local account, and every other app here - could drop in a session file
// carrying a populated $_SESSION['user'], set the matching cookie, and be inside
// this app holding children's personal data, with no Entra sign-in at any point.
// A private directory owned by the pool user is what makes strict mode mean what
// its comment says it means.
//
//   install -d -o www-data -g www-data -m 0700 /var/lib/crm-sessions
//
// The cost of moving is that Debian's phpsessionclean timer no longer sweeps
// these files: it reads save_path out of php.ini and cannot see a runtime
// ini_set. php.ini also ships session.gc_probability = 0, which is why Debian
// needs that timer at all. Both are therefore turned back on below, or sessions
// here would never expire and the 24-minute idle window would silently become
// forever.
const AUTH_SESSION_PATH = '/var/lib/crm-sessions';

function auth_boot(): void {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        // Refuse to run rather than fall back to the shared directory. A silent
        // fallback would reopen the hole above at exactly the moment nobody is
        // watching, and the only thing a hard stop costs is one obvious outage
        // that a single mkdir fixes.
        if (!is_dir(AUTH_SESSION_PATH) || !is_writable(AUTH_SESSION_PATH)) {
            error_log('crm: session store ' . AUTH_SESSION_PATH . ' is missing or not writable');
            auth_page(500, 'Sign-in unavailable',
                'The session store is not available, so nobody can be signed in right now. This needs a server administrator.');
        }
        session_save_path(AUTH_SESSION_PATH);
        // Debian's sweeper cannot see this path, so PHP does its own collection.
        // 1-in-100 requests against a directory this small is nothing.
        ini_set('session.gc_probability', '1');
        ini_set('session.gc_divisor', '100');

        // STRICT MODE, and it is the load-bearing one of the three settings here.
        // php.ini has session.use_strict_mode = Off, which means PHP will ACCEPT
        // a session id it never issued and open a session under it - the session
        // fixation primitive. An attacker plants a known id in the victim's
        // browser, the victim signs in through Entra, and the attacker is holding
        // a live authenticated session. On, PHP discards an unrecognised id and
        // mints a fresh one instead.
        //
        // The vector is realistic here rather than theoretical: cookies are scoped
        // by DOMAIN, not by port or subdomain, and this box serves a dozen other
        // apps under *.tuggerahservices.com.au. Any of them able to set a cookie
        // on the parent domain could plant the id.
        //
        // Set here rather than in php.ini for the same reason the CSP lives in
        // this app's vhost and not the shared snippet: the other apps on this box
        // have not been audited, and this one must not depend on a global they
        // could change.
        //
        // AND IT IS WORTH NOTHING WITHOUT THE PRIVATE save_path SET ABOVE. "An id
        // PHP issued" means "sess_<id> exists in save_path" and nothing more, so
        // on the shared default directory this line was decoration. The two
        // settings are one control; do not move either without the other.
        ini_set('session.use_strict_mode', '1');

        // Renaming the cookie is SECURITY BY OBSCURITY and is recorded as such --
        // it stops the laziest scanners grepping for PHPSESSID and nothing else,
        // since PHP is identifiable from headers and behaviour regardless. It is
        // here because it costs one line, not because it is a control. Do not
        // count it as one. auth_logout() reads session_name(), so it follows.
        session_name('lm_crm');

        session_set_cookie_params(['httponly' => true, 'secure' => true, 'samesite' => 'Lax', 'path' => '/']);
        session_start();
    }
}

/**
 * Reduce a caller-supplied return path to something that can only ever land back
 * on this site, or to '/'.
 *
 * "Starts with a slash" IS NOT ENOUGH, and that is what this used to check.
 * Browsers normalise backslashes to forward slashes in the authority position,
 * so `/\evil.example` is fetched as `//evil.example` - a protocol-relative URL
 * to somebody else's server. The `//` guard alone never saw it. A trailing
 * open-redirect on a sign-in flow is how a convincing credential-phishing page
 * gets to wear our domain in the address bar, so both slash characters are
 * treated identically here.
 */
function auth_safe_return(?string $path): string {
    $path = (string)$path;
    // Control characters and whitespace can split a header or hide a scheme.
    if ($path === '' || preg_match('/[\x00-\x20\x7f]/', $path)) return '/';
    if ($path[0] !== '/') return '/';
    // Second character a slash of either kind => authority follows => off-site.
    if (isset($path[1]) && ($path[1] === '/' || $path[1] === '\\')) return '/';
    return $path;
}

/**
 * Begin login: stash state/nonce/PKCE, redirect to Entra.
 *
 * $switchAccount sends prompt=select_account, which is what the "not authorised"
 * page links to. Without it Entra silently reuses the session that was just
 * refused, so a user who simply picked the wrong account has no way back.
 */
function auth_start_login(?string $returnTo = null, bool $switchAccount = false): void {
    auth_boot();
    $cfg = entra_cfg();
    $state    = bin2hex(random_bytes(16));
    $nonce    = bin2hex(random_bytes(16));
    $verifier = entra_b64url(random_bytes(32));
    $challenge = entra_b64url(hash('sha256', $verifier, true));
    $_SESSION['oidc'] = ['state' => $state, 'nonce' => $nonce, 'verifier' => $verifier,
                         'return_to' => auth_safe_return($returnTo)];

    $params = [
        'client_id'             => $cfg['client_id'],
        'response_type'         => 'code',
        'redirect_uri'          => $cfg['redirect_uri'],
        'response_mode'         => 'query',
        'scope'                 => 'openid profile email User.Read',
        'state'                 => $state,
        'nonce'                 => $nonce,
        'code_challenge'        => $challenge,
        'code_challenge_method' => 'S256',
        // Pre-select the tenant so a signed-in browser (Edge with a Primary
        // Refresh Token, or any browser with an existing Entra session) is
        // signed in silently - no account picker, no prompt. We deliberately
        // send NO `prompt` param: omitting it lets Entra SSO silently when it
        // can and fall back to an interactive login when it can't. Sending
        // prompt=none instead would force us to handle login_required errors
        // and risk a redirect loop.
        'domain_hint'           => 'littlemiracles.com.au',
    ];
    if ($switchAccount) $params['prompt'] = 'select_account';
    header('Location: ' . entra_authorize_endpoint() . '?' . http_build_query($params));
    exit;
}

/** Handle the /auth/callback redirect: exchange code, validate id_token, gate access. */
function auth_handle_callback(): void {
    auth_boot();
    $cfg = entra_cfg();

    if (isset($_GET['error'])) auth_fail('Sign-in error: ' . ($_GET['error_description'] ?? $_GET['error']));
    $oidc = $_SESSION['oidc'] ?? null;
    if (!$oidc || !isset($_GET['state']) || !hash_equals($oidc['state'], (string)$_GET['state'])) auth_fail('Invalid sign-in state.');
    if (!isset($_GET['code'])) auth_fail('No authorization code returned.');

    $endpoint = entra_token_endpoint();
    $res = entra_http($endpoint, [
        'client_id'             => $cfg['client_id'],
        'grant_type'            => 'authorization_code',
        'code'                  => $_GET['code'],
        'redirect_uri'          => $cfg['redirect_uri'],
        'scope'                 => 'openid profile email User.Read',
        'code_verifier'         => $oidc['verifier'],
        'client_assertion_type' => 'urn:ietf:params:oauth:client-assertion-type:jwt-bearer',
        'client_assertion'      => entra_client_assertion($endpoint),
    ]);

    $claims = entra_validate_id_token($res['id_token'] ?? '', $oidc['nonce']);
    // A REAL STAFF ACCOUNT THAT IS NOT ON THE LIST IS NOT A SIGN-IN FAILURE, and
    // telling them it was sends them round the loop again to fail identically.
    // They signed in perfectly well; this app is simply not theirs. It gets its
    // own page, and the commonest cause - signing in on the wrong account - is
    // answered there by naming the account and offering the picker.
    if (!auth_is_allowed($claims)) auth_deny_unauthorised($claims);

    // NEW SESSION ID THE MOMENT THIS BECOMES AN AUTHENTICATED SESSION. Without
    // this the id established BEFORE sign-in survives THROUGH it, so anyone who
    // knew the pre-login id holds a signed-in session afterwards - the other
    // half of the fixation defence begun with use_strict_mode in auth_boot().
    //
    // Placed here deliberately: after the state and nonce have been checked and
    // the account has passed the allowlist, and before $_SESSION carries any
    // identity. `true` deletes the old session file rather than leaving it
    // readable on disk. $_SESSION survives the call, so `oidc` is still there for
    // the return_to read below.
    session_regenerate_id(true);

    // THE SESSION CARRIES IDENTITY, NEVER AUTHORISATION. There is deliberately
    // no role and no centre list in here: both are read from app_users on every
    // request instead, so revoking somebody or moving them between centres takes
    // effect on their next click rather than their next sign-in. See users.php.
    $_SESSION['user'] = [
        'oid'    => $claims['oid'] ?? null,
        'name'   => $claims['name'] ?? null,
        'email'  => $claims['preferred_username'] ?? ($claims['email'] ?? null),
        'groups' => $claims['groups'] ?? [],
    ];

    // An administrator types an email and nothing else, so this is where a row
    // gains a real name - and a last-seen date, which is what makes dormant
    // access visible on the access screen.
    user_touch_signin((string)$_SESSION['user']['email'],
                      $_SESSION['user']['name'], $_SESSION['user']['oid']);

    $return = $oidc['return_to'] ?? '/';
    unset($_SESSION['oidc']);
    // Re-checked on the way out as well as on the way in: the value has sat in
    // session storage in between, and this is the one place it becomes a header.
    header('Location: ' . auth_safe_return($return));
    exit;
}

/**
 * Allowed if there is an ACTIVE app_users row for this email.
 *
 * Nothing else. An Entra security group no longer grants access, and the group
 * machinery here - including the Graph overage fallback - was removed with the
 * config array it served (19 Aug 2026). Two reasons, and the second is the real
 * one:
 *
 *   1. Group membership is managed in Azure by whoever administers the tenant,
 *      which is not the same person as the enrolments team lead who actually
 *      knows who should see children's data.
 *   2. A group grants access with NO ROLE and NO CENTRE. Every user now has to
 *      resolve to a role, and a supervisor to a set of centres; a sign-in that
 *      arrives with neither has nothing sensible to be. Auto-provisioning them
 *      as an unscoped supervisor was considered and rejected - it means Entra
 *      group membership silently opens this app, and nobody here would see it
 *      happen.
 *
 * An administrator adds the email first; the person signs in afterwards. See
 * user_add() and the header of users.php.
 */
function auth_is_allowed(array $claims): bool {
    $email = user_email_norm($claims['preferred_username'] ?? $claims['email'] ?? '');
    if ($email === '') return false;
    $u = user_load($email);
    return $u !== null && (int)$u['active'] === 1;
}

/**
 * Is the signed-in session still allowed, and does it still make sense?
 *
 * Run on EVERY request, page and endpoint alike, because the session proves who
 * somebody is and app_users decides what that is worth today. Without it,
 * removing access would leave a live signed-in session working until it idled
 * out - up to a day for somebody who keeps a tab open, on an app holding
 * children's personal data.
 *
 * @return string '' when fine, otherwise which way it failed.
 */
function auth_access_state(): string {
    $u = user_current();
    if ($u === null || (int)$u['active'] !== 1) return 'revoked';
    // A supervisor is defined by the centres they hold. With none, every screen
    // in the app has nothing to render and every endpoint would refuse them one
    // at a time - so it is said once, here, in a sentence naming what is
    // missing. It is reachable: an administrator can demote somebody to
    // supervisor and be interrupted before assigning a centre.
    if ($u['role'] === 'supervisor' && !$u['centre_ids']) return 'no_centre';
    return '';
}

/** Guard a page: returns the user array, or redirects to login. */
function auth_require(): array {
    auth_boot();
    if (empty($_SESSION['user'])) auth_start_login($_SERVER['REQUEST_URI'] ?? '/');

    $state = auth_access_state();
    if ($state === 'revoked')   auth_deny_revoked();
    if ($state === 'no_centre') auth_deny_no_centre();

    return $_SESSION['user'];
}

/** Guard for JSON/AJAX endpoints: 401 JSON instead of a redirect. */
function auth_require_api(): array {
    auth_boot();
    if (empty($_SESSION['user'])) {
        http_response_code(401);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'not authenticated']);
        exit;
    }
    // Same check as the page guard. A 403 here rather than api_fail(), because
    // this is not a message a user is meant to read and act on - app.js sees
    // the status and reloads, which puts them on the page that explains it.
    if (auth_access_state() !== '') {
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'access changed']);
        exit;
    }
    return $_SESSION['user'];
}

/** Endpoints only an administrator may call. Same shape as auth_require_api(). */
function auth_require_admin_api(): array {
    $user = auth_require_api();
    if (!user_is_admin()) {
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'administrators only']);
        exit;
    }
    return $user;
}

function auth_user(): ?array { auth_boot(); return $_SESSION['user'] ?? null; }

function auth_logout(): void {
    auth_boot();
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
}

/**
 * Render a branded sign-in page and stop.
 *
 * SELF-CONTAINED ON PURPOSE, the same way errors/error.php is: no database, no
 * layout.php, no session. This renders precisely when something in the sign-in
 * chain has already gone wrong, so it must not depend on any of it. The only
 * file it touches is the logo, and that is optional. It carries its own styles
 * because app.css may not be reachable either; CSP allows inline style, never
 * inline script, so there is none here.
 *
 * @param string $actions raw HTML for the button row (already escaped by caller)
 * @param string $account raw HTML naming the signed-in account, or ''
 */
function auth_page(int $code, string $title, string $detail, string $actions = '', string $account = ''): void {
    http_response_code($code);
    header('Content-Type: text/html; charset=utf-8');
    // Sign-in outcomes are per-account and must never be cached or shared.
    header('Cache-Control: no-store, private');
    header('X-Robots-Tag: noindex, nofollow');

    $logo = '';
    $logoPath = __DIR__ . '/littlemiracles_branding/Logo Inline.svg';
    if (is_readable($logoPath)) {
        $svg = file_get_contents($logoPath);
        if ($svg !== false) $logo = preg_replace('/^.*?(<svg\b)/is', '$1', $svg, 1);
    }
    $h = static fn($v) => htmlspecialchars((string)$v, ENT_QUOTES);
    ?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= $h($title) ?> | Little Miracles CRM</title>
<meta name="robots" content="noindex, nofollow">
<link rel="icon" href="/favicon.ico" sizes="any">
<link rel="stylesheet" href="/static/css/app.css">
<style>
body{margin:0;min-height:100vh;display:grid;place-items:center;background:#f7f6fb;
  font-family:Outfit,system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;color:#2b2b3a}
.au{max-width:34rem;padding:2.5rem 2rem;text-align:center}
.au svg{height:44px;width:auto;margin-bottom:1.75rem}
.au-title{font-size:1.5rem;font-weight:700;margin:0 0 .6rem;color:#2e3192}
.au-detail{color:#6c6a8a;margin:0 0 1.5rem;line-height:1.55}
.au-acct{display:inline-flex;flex-direction:column;gap:.1rem;padding:.7rem 1.1rem;margin:0 0 1.5rem;
  border:1px solid #e0dcf4;border-radius:.5rem;background:#fff;text-align:left}
.au-acct-lbl{font-size:.72rem;text-transform:uppercase;letter-spacing:.05em;color:#8b88a8}
.au-acct-name{font-weight:600}
.au-acct-mail{font-size:.9rem;color:#6c6a8a;word-break:break-all}
.au-actions{display:flex;gap:.6rem;justify-content:center;flex-wrap:wrap}
.au-actions a{display:inline-block;padding:.55rem 1.25rem;border-radius:.4rem;text-decoration:none;font-weight:500}
/* a.au-btn, NOT .au-btn, and the hover colour is stated rather than inherited.
   app.css:19 carries `a:hover{color:var(--lm-pink)}` for ordinary links, which is
   specificity (0,1,1). A bare `.au-btn` is (0,1,0) and LOSES to it, so the button
   kept its white text at rest and turned brand PINK under the pointer - which no
   button on the real site does. Same trap CLAUDE.md documents for `.cell.cell-full`
   against Bootstrap's table selector: one class alone never beats a selector that
   carries an element. Naming the element reaches (0,1,1) and wins on source order,
   and `a.au-btn:hover` at (0,2,1) then wins outright rather than by luck. */
a.au-btn{background:#2e3192;color:#fff}
a.au-btn:hover{background:#262a7d;color:#fff}
</style>
</head>
<body>
<div class="au">
  <?= $logo ?>
  <p class="au-title"><?= $h($title) ?></p>
  <?= $account ?>
  <p class="au-detail"><?= $h($detail) ?></p>
  <div class="au-actions"><?= $actions ?></div>
</div>
</body>
</html>
<?php
    exit;
}

/**
 * A valid Little Miracles account that this app does not admit.
 *
 * Names the account deliberately. Everybody here has more than one Microsoft
 * login floating around a browser, and silent SSO means they never chose one --
 * so "you are not allowed" with no name attached leaves them unable to tell a
 * policy decision from having landed on the wrong identity. Naming it turns the
 * commonest cause into something they can fix themselves with the button below.
 *
 * No email address is printed for support. The team is small, they know who to
 * ask, and an address baked into a page is wrong the day the admin changes.
 */
function auth_deny_unauthorised(array $claims): void {
    $h = static fn($v) => htmlspecialchars((string)$v, ENT_QUOTES);
    $email = (string)($claims['preferred_username'] ?? $claims['email'] ?? '');
    $name  = (string)($claims['name'] ?? '');

    $account = '';
    if ($email !== '' || $name !== '') {
        $account = '<div class="au-acct"><span class="au-acct-lbl">Signed in as</span>'
                 . ($name !== '' ? '<span class="au-acct-name">' . $h($name) . '</span>' : '')
                 . ($email !== '' ? '<span class="au-acct-mail">' . $h($email) . '</span>' : '')
                 . '</div>';
    }

    // ?switch=1 clears the local session and asks Entra for the account picker.
    // A plain /auth/login here would be a loop: silent SSO would hand back the
    // same account and land on this page again.
    $actions = '<a class="au-btn" href="/auth/login?switch=1">Try a different account</a>';

    auth_page(403, 'You do not have access to the CRM',
        'The CRM is limited to the enrolments team, because it holds children\'s and families\' personal information. '
      . 'If you need it for your work, ask the enrolments team to request access for you.',
        $actions, $account);
}

/**
 * Access was removed while they were signed in.
 *
 * DIFFERENT FROM auth_deny_unauthorised() ON PURPOSE, even though both mean "not
 * allowed". That page's advice is "you may have signed in on the wrong account",
 * which is right when somebody never had access and wrong here - these users
 * had access a moment ago and picking a different account will not bring it
 * back. Telling them to try another account would send them round a loop they
 * cannot win.
 *
 * The local session is destroyed first, so the browser is not left holding a
 * signed-in cookie for an account this app no longer admits.
 */
function auth_deny_revoked(): void {
    $email = (string)($_SESSION['user']['email'] ?? '');
    auth_logout();
    $h = static fn($v) => htmlspecialchars((string)$v, ENT_QUOTES);
    $account = $email !== ''
        ? '<div class="au-acct"><span class="au-acct-lbl">Signed in as</span>'
          . '<span class="au-acct-mail">' . $h($email) . '</span></div>'
        : '';
    auth_page(403, 'Your access to the CRM has changed',
        'This account no longer has access. If that is not what you expected, ask the enrolments team to check it.',
        '<a class="au-btn" href="/auth/login">Sign in again</a>', $account);
}

/**
 * A supervisor with no centre assigned.
 *
 * Says the one thing that is actually wrong, rather than letting them in to
 * seven blank screens or refusing them a centre at a time. NOT a sign-out: the
 * account is fine and an administrator assigning a centre fixes it in seconds,
 * so the button reloads rather than sending them back through Entra.
 */
function auth_deny_no_centre(): void {
    $h = static fn($v) => htmlspecialchars((string)$v, ENT_QUOTES);
    $email = (string)($_SESSION['user']['email'] ?? '');
    $account = $email !== ''
        ? '<div class="au-acct"><span class="au-acct-lbl">Signed in as</span>'
          . '<span class="au-acct-mail">' . $h($email) . '</span></div>'
        : '';
    auth_page(403, 'No centre has been assigned to you yet',
        'Your account works, but it is not attached to a centre, so there is nothing to show you. '
      . 'Ask the enrolments team to assign one.',
        '<a class="au-btn" href="/">Try again</a>', $account);
}

/**
 * A sign-in that genuinely failed: bad state, an expired code, Entra returning
 * an error. Distinct from auth_deny_unauthorised() - here trying again is the
 * right advice, and there it is exactly the wrong advice.
 */
function auth_fail(string $msg): void {
    auth_page(403, 'Sign-in failed', $msg,
        '<a class="au-btn" href="/auth/login">Try again</a>');
}
