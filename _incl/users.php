<?php
// WHO MAY SIGN IN, AND WHAT THEY MAY SEE. (19 Aug 2026)
//
// Three roles, and the difference between them is deliberately small:
//
//   administrator  every centre, plus this screen - who gets in, and as what
//   manager        every centre; identical to an administrator everywhere else
//   supervisor     one or more ASSIGNED centres, and the full job inside them
//
// THE ROLE DECIDES WHERE, NOT WHAT. A supervisor makes offers, logs contact
// notes, claims work items and edits sub-quotas exactly as a manager does; they
// simply cannot see a centre that is not theirs. That was chosen over a
// read-only supervisor because a person who can see a family waiting and cannot
// act on it rings somebody else to do it, which is more manual work, not less --
// and this whole tool exists to remove manual work.
//
// SCOPE IS RE-READ EVERY REQUEST, NOT COPIED INTO THE SESSION. An administrator
// removing somebody, or moving them to another centre, has to take effect on
// that person's next click - not on their next sign-in, which on silent SSO
// with a 24-minute idle window could be days. The row is cached for the life of
// ONE request (users_cache_clear() drops it after a write), so the cost is one
// indexed lookup per page.
//
// THE CENTRE CHECK IS SERVER-SIDE OR IT IS NOTHING. Hiding the centre bar is a
// display decision; /api/waitlist?centre_id=3 is the actual door. Every endpoint
// that takes a centre id runs it through api_require_centre() - see the header
// of _incl/api.php.

require_once __DIR__ . '/simple-mysqli.php';
require_once __DIR__ . '/rooms.php';   // centres_all(), centre_get()

/** Role value => label, in descending order of reach. Drives the modal's menu. */
const USER_ROLES = [
    'administrator' => 'Administrator',
    'manager'       => 'Manager',
    'supervisor'    => 'Supervisor',
];

/** One line each, shown under the role menu so nobody has to guess. */
const USER_ROLE_HINTS = [
    'administrator' => 'Every centre, and can manage who has access.',
    'manager'       => 'Every centre. Cannot manage access.',
    'supervisor'    => 'Only the centres assigned below.',
];

/**
 * BREAK GLASS. These emails are admitted as administrators even with no row in
 * app_users, or with one that is disabled.
 *
 * The whole allowlist now lives in a table that this app can edit, which means
 * one bad click - disabling the last administrator, or a botched restore --
 * locks every person out of an app with no other way in. There is no console
 * here, no framework CLI and no version control; the recovery would be a hand
 * written UPDATE by whoever has the database password, at whatever hour it
 * happened.
 *
 * It is a floor, not a grant: it cannot let anybody in who is not already in
 * Entra and past the OIDC checks, and it does not bypass sign-in. Keep it to
 * accounts that could fix the database anyway, so it hands out nothing they do
 * not already have.
 *
 * Lives here rather than in entra-config.php because it is an app authorisation
 * decision, not a tenant one, and every reader of it is in this file.
 */
const USER_BREAK_GLASS = ['broc@littlemiracles.com.au'];

/**
 * THE ONLY DOMAIN AN ACCESS ROW MAY CARRY. (20 Aug 2026)
 *
 * Measured before it was hard-coded: every one of the 103 accounts in the
 * Little Miracles tenant has a userPrincipalName at this domain, so this rule
 * refuses nothing that exists today. It is written down rather than left to
 * judgement because the address is typed by hand into a screen that decides who
 * reads children's personal data, and a personal address typed there is a row
 * that can never sign in but sits in the list looking like access.
 *
 * IT IS NOT THE CONTROL, and must not be mistaken for one. auth_is_allowed()
 * admits an ACTIVE row and nothing else, so a row is what grants access; this
 * simply stops one being created for an address the tenant cannot own. Domain
 * ownership is not read from Entra (Domain.Read.All is not granted - /domains
 * answers 403 - and asking for it would be a tenant change for a constant that
 * changes when the company is renamed).
 */
const USER_EMAIL_DOMAIN = 'littlemiracles.com.au';

/** Is this a Little Miracles work address? Assumes user_email_norm() first. */
function user_email_domain_ok(string $email): bool {
    return str_ends_with($email, '@' . USER_EMAIL_DOMAIN);
}

/** Normalise an email the one way it is ever stored or compared. */
function user_email_norm(?string $email): string {
    return strtolower(trim((string)$email));
}

/**
 * The app_users row for an email, with its centre ids, or null.
 *
 * Returns disabled rows too - the callers below decide what that means, and
 * the modal has to be able to show somebody whose access was removed. Cached
 * per request; see the file header.
 *
 * A break-glass account with no row gets a synthetic one so the rest of the app
 * has something to read. It is never written, so the moment a real row exists
 * that row wins.
 */
function user_load(?string $email): ?array {
    static $cache = [];
    static $epoch = 0;

    // A write bumps the epoch; the whole cache goes rather than trying to work
    // out which entries a role change touched.
    if ($epoch !== users_cache_epoch()) { $cache = []; $epoch = users_cache_epoch(); }

    $email = user_email_norm($email);
    if ($email === '') return null;
    if (array_key_exists($email, $cache)) return $cache[$email];

    $row = dbx()->query('SELECT * FROM app_users WHERE email = ?', [$email])->fetch() ?: null;

    if ($row) {
        $row['id']     = (int)$row['id'];
        $row['active'] = (int)$row['active'];
        $row['centre_ids'] = array_map('intval', dbx()
            ->query('SELECT centre_id FROM app_user_centres WHERE user_id = ?', [$row['id']], 'i')
            ->fetchAll('col'));
        // A supervisor's scope is their rows; everybody else's is every centre,
        // and that is answered by user_all_centres() rather than by writing
        // seven rows that go stale the day an eighth centre opens.
        $row['break_glass'] = in_array($email, USER_BREAK_GLASS, true);

        // BREAK GLASS OVERRIDES THE ROW IT FINDS, and this is the half that was
        // missing when it was first written (fixed the same day, 19 Aug 2026).
        // The constant's promise is "admitted as an administrator even with a row
        // that is disabled", and only the NO-ROW case below implemented it - so
        // a break-glass account whose row said `manager`, or `active = 0`, was
        // simply a manager or simply locked out. Verified by demoting the row and
        // watching /api/users answer 403 to the one account that exists to make
        // that impossible. The escape hatch has to be the thing a bad row cannot
        // reach, or it is not an escape hatch.
        //
        // The rest of the row is kept as it stands (the name, the last-seen date,
        // the id the modal lists it under); only the two fields that decide
        // access are forced.
        if ($row['break_glass']) {
            $row['role']   = 'administrator';
            $row['active'] = 1;
        }
    } elseif (in_array($email, USER_BREAK_GLASS, true)) {
        $row = ['id' => 0, 'email' => $email, 'name' => null, 'entra_oid' => null,
                'role' => 'administrator', 'active' => 1, 'invited_by' => null,
                'last_seen_at' => null, 'centre_ids' => [], 'break_glass' => true];
    }

    return $cache[$email] = $row;
}

/**
 * Drop the per-request cache. Call after any write that changes a user.
 *
 * A static inside user_load() cannot be reset from out here, so the cache is
 * versioned instead: bump a counter both functions can see, and the next read
 * misses. It matters within a single request - api/users.php writes a role and
 * then re-reads the list to answer with, and without this the answer would be
 * the state from before the write.
 */
function users_cache_clear(): void {
    users_cache_epoch(users_cache_epoch() + 1);
}

/** @internal Shared epoch so user_load() can be invalidated from outside. */
function users_cache_epoch(?int $set = null): int {
    static $epoch = 0;
    if ($set !== null) $epoch = $set;
    return $epoch;
}

/**
 * IS THIS THE SYSTEM RATHER THAN A PERSON?
 *
 * cron-nightly.php has no session and never will: it backs up, syncs rooms,
 * refreshes the queue and warms every centre's cache. With the scope rules
 * below and no signed-in user, it would resolve to the narrowest role with no
 * centres - and then sibling_matches_for_centre(), waitlist_elsewhere() and
 * search_scope_results() would quietly trim EVERYTHING it touched, on a job
 * nobody watches, with no error to notice.
 *
 * PHP_SAPI IS THE TEST, and it is chosen because it cannot be reached from a
 * request. This vhost is served by php-fpm, so PHP_SAPI is 'fpm-fcgi' for every
 * HTTP request that ever arrives; 'cli' means somebody with a shell on this box
 * ran the script, and they had the database password anyway. A header, an
 * environment variable or a constant set by the caller could each be wrong or
 * be set by the wrong thing - this cannot.
 *
 * It is NOT a bypass for the web: every page and endpoint goes through
 * auth_require() / auth_require_api() first, which guarantee a session user
 * before any of this is consulted.
 */
function user_is_system(): bool {
    return PHP_SAPI === 'cli';
}

/** The signed-in user's app_users row (or null when not signed in / not listed). */
function user_current(): ?array {
    return user_load($_SESSION['user']['email'] ?? null);
}

/** 'administrator' | 'manager' | 'supervisor'. Defaults to the narrowest. */
function user_role(): string {
    $u = user_current();
    return $u['role'] ?? 'supervisor';
}

/** May this user manage access? Administrators only, by definition. */
function user_is_admin(): bool {
    return user_role() === 'administrator';
}

/**
 * Does this user see the whole group?
 *
 * The one predicate the rest of the app asks. It decides whether the "All
 * centres" tab renders, whether centre_current() may resolve to 0, and whether
 * an endpoint accepts a group-wide request.
 */
function user_all_centres(): bool {
    // The nightly jobs are not a person and are not scoped - see user_is_system().
    if (user_is_system()) return true;
    return in_array(user_role(), ['administrator', 'manager'], true);
}

/**
 * The centre ids this user may see, ALWAYS as a real list.
 *
 * Never returns [] to mean "everything" - that ambiguity is how a scoping bug
 * turns into a leak, because an empty list read as "no filter" is exactly
 * backwards from an empty list read as "nothing". A supervisor with no centre
 * assigned gets [], and [] means [].
 */
function user_centre_ids(): array {
    if (user_all_centres()) return array_map(static fn($c) => (int)$c['id'], centres_all());
    $u = user_current();
    return $u['centre_ids'] ?? [];
}

/** May this user see this centre? 0 (the whole group) needs user_all_centres(). */
function user_can_centre(int $centreId): bool {
    if (user_is_system()) return true;
    if ($centreId === 0) return user_all_centres();
    if (user_all_centres()) return true;
    return in_array($centreId, user_centre_ids(), true);
}

/**
 * centres_all() reduced to what this user may see.
 *
 * What every screen passes to layout_header(), so the centre bar can only ever
 * offer a centre the user is allowed to open. centres_all() itself is left
 * alone: the nightly jobs, the sync and search legitimately loop every centre,
 * and a filter buried in there would silently break them.
 */
function centres_visible(bool $activeOnly = true): array {
    $all = centres_all($activeOnly);
    if (user_all_centres()) return $all;
    $mine = user_centre_ids();
    return array_values(array_filter($all, static fn($c) => in_array((int)$c['id'], $mine, true)));
}

/**
 * A supervisor's first centre, for pinning a screen that must show one.
 * 0 when they have none, which auth_require() has already refused.
 */
function user_default_centre(): int {
    $ids = user_centre_ids();
    return $ids ? (int)$ids[0] : 0;
}

// ---------------------------------------------------------------------------
// Reads for the access modal
// ---------------------------------------------------------------------------

/**
 * Every user, disabled ones included, with their centre names.
 *
 * Disabled rows are listed because "who used to have access" is half of what an
 * administrator opens this screen to check, and because removing access is
 * reversible only if you can still see the row.
 */
function users_list(): array {
    $rows = dbx()->query(
        'SELECT * FROM app_users ORDER BY active DESC, FIELD(role, ?, ?, ?), COALESCE(name, email)',
        ['administrator', 'manager', 'supervisor'])->fetchAll();
    if (!$rows) return [];

    $ids   = array_map(static fn($r) => (int)$r['id'], $rows);
    $marks = dbx()->whereIn($ids);
    $pairs = dbx()->query(
        "SELECT uc.user_id, c.id, c.name
           FROM app_user_centres uc JOIN centres c ON c.id = uc.centre_id
          WHERE uc.user_id IN($marks) ORDER BY c.sort_order, c.name", $ids)->fetchAll();

    $byUser = [];
    foreach ($pairs as $p) $byUser[(int)$p['user_id']][] = ['id' => (int)$p['id'], 'name' => $p['name']];

    foreach ($rows as &$r) {
        $r['id']          = (int)$r['id'];
        $r['active']      = (int)$r['active'];
        $r['centres']     = $byUser[$r['id']] ?? [];
        $r['break_glass'] = in_array(user_email_norm($r['email']), USER_BREAK_GLASS, true);
    }
    unset($r);
    return $rows;
}

/** How many active administrators there are. The guard against locking the app. */
function users_admin_count(): int {
    return (int)dbx()->query(
        "SELECT COUNT(*) FROM app_users WHERE active = 1 AND role = 'administrator'")->fetch('col');
}

// ---------------------------------------------------------------------------
// Writes. Every one of these is called from api/users.php, which audits.
// ---------------------------------------------------------------------------

/**
 * Add somebody, or re-enable a row that already exists for that email.
 *
 * RE-ENABLE RATHER THAN REFUSE, because rows are disabled and never deleted:
 * "that email is already in the list" is a useless answer when the row it names
 * is one an administrator cannot see any reason for. Adding back somebody who
 * left and returned is the ordinary case.
 *
 * THREE OUTCOMES, NOT TWO, and the third was reported as the second until it
 * was seen on screen (20 Aug 2026). Any existing row answered 'restored', so
 * re-typing the address of somebody who had never lost access was said back as
 * "Access restored for Emily Kite" - which reads as though something had been
 * wrong with her account. Whether the row was OFF is the fact that separates
 * them, so that is what is tested.
 *
 * @return array [user id, 'created'|'restored'|'updated']
 */
function user_add(string $email, string $role, array $centreIds, string $byEmail,
                 ?string $name = null, ?string $oid = null): array {
    $email = user_email_norm($email);
    $existing = dbx()->query('SELECT id, active FROM app_users WHERE email = ?', [$email])->fetch();

    // The name and the oid come from the directory, not from the administrator
    // (see graph_find_user()). COALESCE(NULLIF(...)) so a lookup that could not
    // be made leaves whatever is already there rather than blanking it - the
    // same rule user_touch_signin() follows for the same two columns.
    if ($existing) {
        $id = (int)$existing['id'];
        dbx()->query(
            'UPDATE app_users
                SET role = ?, active = 1, invited_by = ?,
                    name      = COALESCE(NULLIF(?, \'\'), name),
                    entra_oid = COALESCE(NULLIF(?, \'\'), entra_oid)
              WHERE id = ?',
            [$role, $byEmail, (string)$name, (string)$oid, $id]);
        $outcome = (int)$existing['active'] === 1 ? 'updated' : 'restored';
    } else {
        dbx()->query(
            'INSERT INTO app_users (email, role, invited_by, name, entra_oid)
             VALUES (?, ?, ?, NULLIF(?, \'\'), NULLIF(?, \'\'))',
            [$email, $role, $byEmail, (string)$name, (string)$oid]);
        $id = dbx()->insertId();
        $outcome = 'created';
    }
    user_set_centres($id, $role === 'supervisor' ? $centreIds : []);
    users_cache_clear();
    return [$id, $outcome];
}

/** Change a role, and the centres that go with it. */
function user_set_role(int $id, string $role, array $centreIds): void {
    dbx()->query('UPDATE app_users SET role = ? WHERE id = ?', [$role, $id]);
    // A promotion to manager or administrator DROPS the assignments rather than
    // keeping them parked. Their scope is now the role, and a stale row here
    // would come back to life on a later demotion as a centre nobody chose.
    user_set_centres($id, $role === 'supervisor' ? $centreIds : []);
    users_cache_clear();
}

/** Replace a user's assigned centres. Ignores ids that are not real centres. */
function user_set_centres(int $id, array $centreIds): void {
    $clean = [];
    foreach ($centreIds as $c) {
        $c = (int)$c;
        if ($c > 0 && centre_get($c)) $clean[$c] = true;
    }
    dbx()->transaction(function ($db) use ($id, $clean) {
        $db->query('DELETE FROM app_user_centres WHERE user_id = ?', [$id], 'i');
        foreach (array_keys($clean) as $c) {
            $db->query('INSERT INTO app_user_centres (user_id, centre_id) VALUES (?, ?)', [$id, $c]);
        }
    });
    users_cache_clear();
}

/** Turn access on or off. The row itself is never deleted; see schema.sql. */
function user_set_active(int $id, bool $active): void {
    dbx()->query('UPDATE app_users SET active = ? WHERE id = ?', [$active ? 1 : 0, $id]);
    users_cache_clear();
}

/**
 * Record what the directory says about somebody, without a sign-in.
 *
 * The companion to user_touch_signin(): that one runs when the person arrives
 * and this one runs when an administrator adds them, or when the access screen
 * notices a row that still has no name. It deliberately does NOT touch
 * last_seen_at - "Never signed in" is the signal that an address was mistyped
 * or that access was granted and never used, and filling a name in must not
 * quietly erase it.
 */
function user_set_directory(int $id, ?string $name, ?string $oid): void {
    dbx()->query(
        'UPDATE app_users
            SET name      = COALESCE(NULLIF(?, \'\'), name),
                entra_oid = COALESCE(NULLIF(?, \'\'), entra_oid)
          WHERE id = ?',
        [(string)$name, (string)$oid, $id]);
    users_cache_clear();
}

/**
 * Record what Entra says about somebody, on sign-in.
 *
 * The name and oid are NOT typed by an administrator - they type an email and
 * nothing else - so this is where a row stops being just an address. last_seen
 * is what makes dormant access visible on the modal, which is the access
 * actually worth revoking.
 */
function user_touch_signin(string $email, ?string $name, ?string $oid): void {
    dbx()->query(
        'UPDATE app_users
            SET last_seen_at = NOW(),
                name      = COALESCE(NULLIF(?, \'\'), name),
                entra_oid = COALESCE(NULLIF(?, \'\'), entra_oid)
          WHERE email = ?',
        [(string)$name, (string)$oid, user_email_norm($email)]);
    users_cache_clear();
}
