<?php
// USER ACCESS - read and edit who may sign in, and as what. (19 Aug 2026)
//
// ADMINISTRATORS ONLY, enforced by auth_require_admin_api() on every action
// including the read. The modal is not rendered for anybody else, but that is
// tidiness; this is the door. Hiding a control while the endpoint still accepts
// the POST is not a restriction - it is a restriction somebody can skip, which
// is the same note api/rooms.php carries about the capacity fields.
//
// EVERY ACTION HERE IS AUDITED, including the reads' subject matter by way of
// the writes: this is the one endpoint that decides who sees children's personal
// data, so "who granted this, and when" has to survive the person who did it.
//
// Readable failures go through api_fail() (HTTP 200 + ok:false) because nginx
// discards the body of a real 4xx under /api/. See _incl/api.php.

declare(strict_types=1);

require_once __DIR__ . '/../_incl/auth.php';
require_once __DIR__ . '/../_incl/csrf.php';
require_once __DIR__ . '/../_incl/api.php';
require_once __DIR__ . '/../_incl/users.php';
require_once __DIR__ . '/../_incl/graph.php';   // graph_find_user(), graph_suggest_users()
require_once __DIR__ . '/../_incl/audit.php';

$user  = auth_require_admin_api();
$me    = user_email_norm($user['email'] ?? '');
$action = $_REQUEST['action'] ?? '';

// 'lookup' joins 'list' as a read: it changes nothing and answers a question the
// add form asks on every keystroke-pause. Still administrators only - that is
// auth_require_admin_api() above, and it is the guard that matters, because this
// one reads the staff directory.
if ($action !== 'list' && $action !== 'lookup') {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { http_response_code(405); exit; }
    csrf_require();
}

/** Centre ids posted as centre_ids[]; anything not a real centre is dropped later. */
function posted_centres(): array {
    $raw = $_POST['centre_ids'] ?? [];
    if (!is_array($raw)) $raw = [$raw];
    return array_values(array_filter(array_map('intval', $raw)));
}

/** A role we recognise, or a readable refusal. */
function posted_role(): string {
    $role = (string)($_POST['role'] ?? '');
    if (!isset(USER_ROLES[$role])) api_fail('Pick a role.');
    return $role;
}

/**
 * The row an action names, or a readable refusal.
 *
 * A BREAK-GLASS ACCOUNT CANNOT BE EDITED FROM HERE. Its access does not come
 * from this table (see USER_BREAK_GLASS in users.php), so demoting or disabling
 * it would appear to work, change the row, and leave the person an administrator
 * - a screen saying one thing while the app does another. Better to say plainly
 * that it cannot be done here.
 */
function target_user(): array {
    $id  = (int)($_POST['id'] ?? 0);
    $row = dbx()->query('SELECT * FROM app_users WHERE id = ?', [$id], 'i')->fetch();
    if (!$row) api_fail('That user is no longer in the list.');
    if (in_array(user_email_norm($row['email']), USER_BREAK_GLASS, true)) refuse_break_glass();
    $row['id'] = (int)$row['id'];
    return $row;
}

/**
 * Refuse to remove the last way back in.
 *
 * Called before anything that could reduce the number of ACTIVE administrators:
 * disabling one, or demoting one. Without it the app has a one-click path to
 * nobody being able to grant access - and this box has no console, no framework
 * CLI and no version control, so the recovery is a hand-written UPDATE by
 * whoever has the database password.
 *
 * USER_BREAK_GLASS exists for exactly that case and is deliberately NOT counted
 * here. It is a floor under a mistake, not a licence to make one: if it were
 * counted, an administrator could empty this table entirely and the app would
 * quietly become a one-person tool without ever saying so.
 */
function guard_last_admin(array $row): void {
    if ($row['role'] !== 'administrator' || (int)$row['active'] !== 1) return;
    if (users_admin_count() > 1) return;
    api_fail('This is the only administrator left. Make somebody else an administrator first, or nobody will be able to manage access.');
}

/**
 * The two guards that protect a row from being changed into something the app
 * cannot recover from. Called by BOTH set_role and add.
 *
 * ADD USED TO SKIP ALL OF THIS, and that was the hole. Typing an address that
 * already has a row does not create anything - it UPDATEs the existing row's
 * role and re-enables it (see user_add()) - so the add form was a second,
 * unguarded way to demote the last administrator or to demote yourself, both of
 * which the role menu two inches below it refuses. One of them empties the app
 * of anybody who can grant access.
 */
function guard_role_change(array $row, string $role, string $me): void {
    if ($role === $row['role']) return;
    if ($role !== 'administrator') guard_last_admin($row);
    if (user_email_norm($row['email']) === $me && $role !== 'administrator') {
        api_fail('You cannot change your own role. Ask another administrator to do it.');
    }
}

/**
 * "a manager", "an administrator".
 *
 * Written out because the obvious version was already wrong: both messages here
 * said "is now a administrator", which is the one word in the list that starts
 * with a vowel and the one an administrator sees most often.
 */
function role_phrase(string $role): string {
    $label = strtolower(USER_ROLES[$role]);
    return (strpos('aeiou', $label[0]) !== false ? 'an ' : 'a ') . $label;
}

/** The refusal a break-glass row gets, wherever it is reached from. */
function refuse_break_glass(): void {
    api_fail('This account is a permanent administrator and cannot be changed here. It is set in the server configuration.');
}

/**
 * WHO IS THIS ADDRESS, AND MAY IT BE ADDED? Asked of Entra.
 *
 * THE ONE PLACE THE RULES LIVE, because it has two callers that must never
 * disagree: the add form checks the field as it is typed and switches its own
 * Add button off, and `case 'add'` checks it again before writing a row. A UI
 * gate is not a control - the POST is the door - so the second check is the
 * real one and the first exists only so nobody types a whole form to be told no.
 * Two copies of these rules would drift, and the direction it would drift is a
 * button that stays lit over a refusal.
 *
 * @return array{status:string, message:string, email:string, name:?string,
 *                oid:?string, job_title:?string, suggestions:array}
 *
 *   ok           a real, enabled staff account. 'email' is the address to STORE.
 *   unreachable  we could not ask. Says nothing about the address; ADD ANYWAY.
 *   no_account   Entra says there is no such account. A typo, or somebody gone.
 *                'suggestions' carries the accounts it was NEARLY - see below.
 *   disabled     real account, switched off in Microsoft 365.
 *   guest        somebody else's account wearing our tenant.
 *   bad_domain   not a Little Miracles address.
 *   bad_email    not an email address at all.
 *
 * THE ADDRESS THAT COMES BACK IS THE ONE STORED, not the one typed. Entra is
 * asked for the userPrincipalName, which is what a sign-in later presents as
 * `preferred_username` and what auth_is_allowed() compares against; an
 * administrator who knows somebody by their mailbox address rather than their
 * sign-in name would otherwise create a row nobody can ever match.
 */
function directory_check(string $email): array {
    $out = ['status' => 'ok', 'message' => '', 'email' => $email,
            'name' => null, 'oid' => null, 'job_title' => null, 'suggestions' => []];
    $bad = static function (string $status, string $msg) use ($out): array {
        return array_merge($out, ['status' => $status, 'message' => $msg]);
    };

    // filter_var rather than a pattern of our own: the address is typed by hand
    // and a wrong one is a real person who cannot sign in and does not know why.
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return $bad('bad_email', 'That does not look like an email address.');
    }
    // WORK ADDRESSES ONLY. Sign-in is Entra against the Little Miracles tenant,
    // so an address at any other domain is a row that can never be matched by a
    // token - it sits in the list looking like access and is not. Checked
    // before the directory call so the commonest mistake gets the answer that
    // names it, rather than a generic "no such account", and so a typed personal
    // address is never sent to Graph at all.
    if (!user_email_domain_ok($email)) {
        return $bad('bad_domain', 'Only ' . USER_EMAIL_DOMAIN . ' addresses can be given access. The CRM signs people in with their Little Miracles work account.');
    }

    $res = graph_find_user($email);

    // A LOOKUP WE COULD NOT MAKE IS NOT A REFUSAL. Graph being unreachable says
    // nothing about the address, and blocking an administrator from granting
    // access because our own outbound call timed out would leave them stuck with
    // no way to add the person who needs the app today.
    if ($res['status'] === 'unavailable') {
        return $bad('unreachable', 'The staff directory could not be reached, so the name will be filled in when they first sign in.');
    }
    // NO ACCOUNT IS NOT THE END OF THE ANSWER. "There is no account for
    // cath@littlemiracles.com.au" is true and useless: it says what is wrong and
    // nothing about what is right, and the only way to find that out was to open
    // the Microsoft 365 admin portal - a second system, to answer a question we
    // had just asked Entra twice. So ask the second question here instead: which
    // accounts is that nearly? See graph_suggest_users().
    //
    // ONLY ADDRESSES AT OUR OWN DOMAIN are offered, for the same reason the
    // domain rule exists at all: a suggestion the add would then refuse is a
    // second dead end wearing the clothes of a way forward.
    if ($res['status'] !== 'found') {
        $miss = $bad('no_account', 'There is no Little Miracles account for ' . $email . '. Check the spelling; this is the address they sign in to Microsoft 365 with.');
        $miss['suggestions'] = array_values(array_filter(
            graph_suggest_users($email),
            static fn($s) => user_email_domain_ok($s['upn'])));
        return $miss;
    }

    $u = $res['user'];
    // A leaver's account is switched off in Entra long before anybody thinks to
    // come here, so this is the one place the two lists can be kept honest.
    if (!$u['enabled']) {
        return $bad('disabled', ($u['name'] ?: $email) . ' has a Little Miracles account, but it is disabled in Microsoft 365. They cannot sign in until IT re-enables it.');
    }
    // A guest is somebody else's account wearing our tenant. The domain rule
    // above would not catch one: its UPN is rewritten into the tenant when the
    // invitation is accepted.
    if ($u['guest']) {
        return $bad('guest', $email . ' is a guest account, not a Little Miracles staff account.');
    }

    $upn = $u['upn'] !== '' ? $u['upn'] : $email;
    if (!user_email_domain_ok($upn)) {
        return $bad('bad_domain', 'That account signs in as ' . $upn . ', which is not a ' . USER_EMAIL_DOMAIN . ' address.');
    }

    $out['email']     = $upn;
    $out['name']      = $u['name'];
    $out['oid']       = $u['oid'];
    $out['job_title'] = $u['job_title'];
    return $out;
}

/**
 * Resolve any row that still has no name. Bounded, and never fatal.
 *
 * Capped because this runs on a modal open: a directory that has gone slow must
 * cost one wait, not one per row. Whatever is left over is picked up the next
 * time the screen is opened.
 */
function users_backfill_names(int $limit = 10): void {
    $rows = dbx()->query(
        "SELECT id, email FROM app_users
          WHERE name IS NULL OR name = '' ORDER BY id LIMIT " . max(1, $limit))->fetchAll();
    foreach ($rows as $r) {
        $res = graph_find_user((string)$r['email']);
        // 'not_found' is left alone deliberately. A row for an address the
        // directory does not know is the mistyped-address case this screen
        // exists to make visible, and it stays visible as "Never signed in"
        // with no name - writing a placeholder in would hide it.
        if ($res['status'] !== 'found') continue;
        user_set_directory((int)$r['id'], $res['user']['name'], $res['user']['oid']);
    }
}

switch ($action) {

// -------------------------------------------------------------------------
case 'list': {
    // FILL IN THE NAMES NOBODY HAS TYPED, once. Rows added before the directory
    // lookup existed - and any row whose add went through while Graph was
    // unreachable - carry an address and no name, which is exactly the row an
    // administrator cannot tell apart from a typo. Asking Entra here is bounded
    // and self-extinguishing: only nameless rows are looked up, each answer is
    // written back, and after one open there is nothing left to ask.
    //
    // It never blocks the list. A directory that cannot be reached leaves the
    // rows exactly as they were, which is the state this screen has always been
    // able to render.
    users_backfill_names();

    echo json_encode([
        'ok'      => true,
        'users'   => users_list(),
        'roles'   => USER_ROLES,
        'hints'   => USER_ROLE_HINTS,
        // The add form types a name and the domain is fixed text beside it, so
        // the JS has to join the two halves before asking about an address.
        // Sent from the constant rather than read out of the rendered span:
        // the span is display, and a display string is not somewhere to keep
        // the value a lookup is built from.
        'domain'  => USER_EMAIL_DOMAIN,
        'centres' => array_map(static fn($c) => ['id' => (int)$c['id'], 'name' => $c['name']],
                               centres_all()),
        // So the UI can refuse to let somebody strand themselves, and can label
        // the row rather than making them work out which one is theirs.
        'me'      => $me,
    ], JSON_UNESCAPED_SLASHES);
    break;
}

// -------------------------------------------------------------------------
// WHAT THE ADD FORM ASKS WHILE SOMEBODY IS TYPING.
//
// Answers the same question `add` will answer, from the same function, so the
// form can name the person before the administrator commits to them - and can
// say plainly which of the six ways an address is wrong it is wrong in, rather
// than leaving a lit button over a refusal.
//
// It also says whether the address is ALREADY on the list. That is not a
// directory fact and not a refusal - re-adding somebody is how a role is
// changed and how a person who left is let back in - but it is the thing an
// administrator most wants to know before pressing Add, and the answer that
// stops them wondering why nothing seemed to happen.
case 'lookup': {
    $typed = user_email_norm($_GET['email'] ?? '');
    $dir   = directory_check($typed);

    $already = null;
    if (in_array($dir['status'], ['ok', 'unreachable'], true)) {
        $row = dbx()->query('SELECT role, active FROM app_users WHERE email = ?', [$dir['email']])->fetch();
        if ($row) {
            $already = [
                'role'   => $row['role'],
                'active' => (int)$row['active'] === 1,
                // A break-glass row cannot be edited from this screen at all, so
                // the form has to be able to switch itself off for one.
                'locked' => in_array($dir['email'], USER_BREAK_GLASS, true),
            ];
        }
    }

    echo json_encode([
        'ok'        => true,
        'status'    => $dir['status'],
        'message'   => $dir['message'],
        'email'     => $dir['email'],
        'name'      => $dir['name'],
        'job_title' => $dir['job_title'],
        'already'   => $already,
        // At most three, and only ever on a refusal. The form turns them into
        // buttons that fill the field, because reading an address back off a
        // screen and typing it again is how "Cathryn.Montgomery" becomes
        // "Cathryn.Montgomerey".
        'suggestions' => $dir['suggestions'],
    ], JSON_UNESCAPED_SLASHES);
    break;
}

// -------------------------------------------------------------------------
case 'add': {
    $email = user_email_norm($_POST['email'] ?? '');
    $role  = posted_role();
    $centres = posted_centres();

    // A SUPERVISOR WITHOUT A CENTRE CANNOT USE THE APP AT ALL - every screen is
    // scoped to one, so they would sign in successfully and be shown a page
    // explaining they have nowhere to be. Refused here, where it can still be
    // fixed in the same breath, rather than discovered by them.
    if ($role === 'supervisor' && !$centres) {
        api_fail('Choose at least one centre for a supervisor. Without one there is nothing for them to see.');
    }

    // WHO IS THIS? Answered by Entra before a row is written, so the list shows
    // a person rather than an address, and so a typo is refused here instead of
    // becoming a row that reads "Never signed in" forever while a real staff
    // member lands on the denial page.
    //
    // THE FORM HAS ALREADY ASKED THIS AND SWITCHED ITS OWN BUTTON OFF. Asked
    // again anyway, because the button is not the door: the POST is, and a
    // disabled button is one line of console away from being enabled. Same rule
    // as the modal not rendering for a non-administrator while
    // auth_require_admin_api() does the actual refusing.
    $dir = directory_check($email);
    if (!in_array($dir['status'], ['ok', 'unreachable'], true)) {
        // The form has already offered these as buttons; this is the same answer
        // for the POST that arrived without them, so the two never disagree
        // about what the directory said.
        $near = array_map(static fn($s) => $s['name'] ? $s['name'] . ' (' . $s['upn'] . ')' : $s['upn'],
                          $dir['suggestions']);
        api_fail($dir['message'] . ($near ? ' Did you mean ' . implode(' or ', $near) . '?' : ''));
    }
    $email = $dir['email'];
    $name  = $dir['name'];
    $oid   = $dir['oid'];
    // Only the unreachable case has anything left to say; 'ok' says it by
    // naming the person in the message below.
    $note  = $dir['status'] === 'unreachable' ? ' ' . $dir['message'] : '';

    // GUARDS THE ADD FORM USED TO WALK STRAIGHT PAST. An address that already
    // has a row is not an insert: user_add() updates that row's role and turns
    // it back on. So every rule the role menu enforces has to be enforced here
    // too, against the row this address actually resolves to.
    $existing = dbx()->query('SELECT * FROM app_users WHERE email = ?', [$email])->fetch();
    if ($existing) {
        if (in_array(user_email_norm($existing['email']), USER_BREAK_GLASS, true)) refuse_break_glass();
        guard_role_change($existing, $role, $me);
    }

    [$id, $outcome] = user_add($email, $role, $centres, $me, $name, $oid);

    // The audit keeps its existing vocabulary rather than gaining a fourth verb:
    // an add form that lands on a row somebody already has IS a role write, and
    // reads better in the log next to the ones the role menu makes.
    $verbs = ['created' => 'user.add', 'restored' => 'user.restore', 'updated' => 'user.role'];
    $detail = ['email' => $email, 'role' => $role, 'centre_ids' => $centres, 'name' => $name];
    if ($outcome === 'updated') $detail['role'] = [$existing['role'], $role];
    audit($verbs[$outcome], 'app_user', $id, $detail);

    // Named, not just addressed. The directory answered with a person, and
    // saying that back is what tells an administrator they typed the address of
    // the person they meant - which is the whole reason for asking Entra.
    $who = $name ? $name . ' (' . $email . ')' : $email;

    echo json_encode([
        'ok'      => true,
        'outcome' => $outcome,
        'users'   => users_list(),
        // SAID BACK, AND THE THREE OUTCOMES ARE THREE DIFFERENT FACTS.
        // Re-admitting somebody who left is not the same as granting access for
        // the first time, and neither is typing the address of somebody who
        // already has it - which used to answer "Access restored", reading as
        // though something had been wrong with their account.
        'message' => ([
            'created'  => $who . ' can sign in now.',
            'restored' => 'Access restored for ' . $who . '.',
            'updated'  => $who . ' is already on the list, and is now ' . role_phrase($role) . '.',
        ][$outcome]) . $note,
    ], JSON_UNESCAPED_SLASHES);
    break;
}

// -------------------------------------------------------------------------
case 'set_role': {
    $row  = target_user();
    $role = posted_role();
    $centres = posted_centres();

    if ($role === 'supervisor' && !$centres) {
        api_fail('Choose at least one centre for a supervisor.');
    }
    // Demoting an administrator is one of the two ways to run out of them, and
    // demoting yourself is the misclick that makes this modal vanish mid-task.
    // Both live in guard_role_change() so the add form cannot skip them.
    guard_role_change($row, $role, $me);

    user_set_role($row['id'], $role, $centres);
    audit('user.role', 'app_user', $row['id'], [
        'email' => $row['email'],
        'role'  => [$row['role'], $role],
        'centre_ids' => $centres,
    ]);
    // SAY IT BACK. Changing a role is picking an option from a menu, and a menu
    // that simply stays on its new value is indistinguishable from one that
    // failed silently - the row re-renders either way. Naming both the person
    // and the role is also the fastest way to notice the wrong row was touched.
    echo json_encode([
        'ok'      => true,
        'users'   => users_list(),
        'message' => $row['email'] . ' is now ' . role_phrase($role) . '.',
    ], JSON_UNESCAPED_SLASHES);
    break;
}

// -------------------------------------------------------------------------
case 'set_centres': {
    $row = target_user();
    $centres = posted_centres();
    if ($row['role'] !== 'supervisor') {
        api_fail('Only a supervisor is assigned centres. An administrator or manager sees all of them.');
    }
    if (!$centres) api_fail('A supervisor needs at least one centre.');

    $before = array_map('intval', dbx()
        ->query('SELECT centre_id FROM app_user_centres WHERE user_id = ?', [$row['id']], 'i')
        ->fetchAll('col'));

    user_set_centres($row['id'], $centres);
    audit('user.centres', 'app_user', $row['id'],
          ['email' => $row['email'], 'centre_ids' => [$before, $centres]]);
    // Named, not counted, for the same reason the user menu names them: "2
    // centres" is the answer that sends somebody back to check which two.
    $names = dbx()->query(
        'SELECT c.name FROM app_user_centres uc JOIN centres c ON c.id = uc.centre_id
          WHERE uc.user_id = ? ORDER BY c.sort_order, c.name', [$row['id']], 'i')->fetchAll('col');
    echo json_encode([
        'ok'      => true,
        'users'   => users_list(),
        'message' => $row['email'] . ' now sees ' . implode(', ', $names) . '.',
    ], JSON_UNESCAPED_SLASHES);
    break;
}

// -------------------------------------------------------------------------
case 'set_active': {
    $row    = target_user();
    $active = ($_POST['active'] ?? '') === '1';

    if (!$active) {
        guard_last_admin($row);
        // Same reasoning as the self-demotion guard above, and here the misclick
        // signs you out of the app you are standing in.
        if (user_email_norm($row['email']) === $me) {
            api_fail('You cannot remove your own access.');
        }
    }

    user_set_active($row['id'], $active);
    audit($active ? 'user.restore' : 'user.remove', 'app_user', $row['id'],
          ['email' => $row['email'], 'role' => $row['role']]);
    echo json_encode([
        'ok'      => true,
        'users'   => users_list(),
        'message' => $active
            ? $row['email'] . ' can sign in again.'
            : 'Access removed for ' . $row['email'] . '.',
    ], JSON_UNESCAPED_SLASHES);
    break;
}

default:
    api_fail('Unknown action.');
}
