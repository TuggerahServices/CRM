<?php
// Microsoft Graph client - app-only (certificate) calls. Two things live here:
// the directory lookup that turns a typed work email into the person it belongs
// to (User access), and send mail - used by the Monday birthday email
// (birthdays.php, since 2 Oct 2026), the Report Issue button (issue-reports.php,
// 13 Oct 2026), and still waiting on the offer emails.
// The group-membership fallback that used to be the third is retired; see the
// note where it stood.

require_once __DIR__ . '/entra.php';

/**
 * Send an HTML email via Graph as the configured mailbox (app-only Mail.Send).
 * Throws on failure; returns nothing (Graph replies 202 Accepted).
 *
 * @param string[] $to       recipient addresses
 * @param string[] $replyTo  where a reply goes instead of the noreply sender -
 *                           an issue report sets the person who sent it, so
 *                           answering them is one click (issue-reports.php)
 */
function graph_send_mail(array $to, string $subject, string $html, ?string $from = null,
                         array $replyTo = []): void {
    $cfg = entra_cfg();
    $from = $from ?: $cfg['mail_from'];
    $token = entra_app_token();

    $addr = static fn($a) => ['emailAddress' => ['address' => $a]];
    $payload = [
        'message' => [
            'subject' => $subject,
            'body'    => ['contentType' => 'HTML', 'content' => $html],
            'toRecipients' => array_map($addr, $to),
        ],
        'saveToSentItems' => false,
    ];
    if ($replyTo) $payload['message']['replyTo'] = array_map($addr, $replyTo);
    $url = rtrim($cfg['graph_base'], '/') . '/users/' . rawurlencode($from) . '/sendMail';

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $token, 'Content-Type: application/json'],
        CURLOPT_TIMEOUT        => 30,
    ]);
    $body = curl_exec($ch);
    // curl_close() is deprecated from PHP 8.5 and a no-op since 8.0.
    if ($body === false) { $e = curl_error($ch); throw new RuntimeException("Graph sendMail failed: {$e}"); }
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    if ($code >= 300) throw new RuntimeException("Graph sendMail HTTP {$code}: " . substr((string)$body, 0, 300));
}

// graph_user_in_groups() WAS HERE AND IS RETIRED (19 Aug 2026).
//
// It was the fallback for the SSO groups-claim overage: a user in too many
// groups for the token to carry them was checked against the allowlist through
// Graph's checkMemberGroups. Nothing calls it now - access is a row in
// `app_users`, and the Entra group door was removed with the config array it
// served (see auth_is_allowed()).
//
// Removed rather than left sitting here, because a dead authorisation function
// is the one kind worth deleting: the next person to read this file would
// reasonably assume group membership still grants access somewhere. The copy in
// _incl/retired/graph-user-in-groups.php is the graveyard entry, per CLAUDE.md.
//
// The rest of this file stays: graph_send_mail() above sends the birthday email,
// and the offer emails will use it too.

// ---------------------------------------------------------------------------
// DIRECTORY LOOKUP - turn a typed email into the person it belongs to.
// (20 Aug 2026)
//
// WHY THIS EXISTS. An administrator adds somebody by typing an address and
// nothing else, so until that person first signs in the row is an email and a
// blank name - and a MISTYPED address is a row that stays that way forever
// while a real staff member hits the denial page not knowing why. The access
// screen's "Never signed in" was the only signal, and it is a signal nobody
// reads until somebody complains.
//
// Entra already knows the answer, so ask it: the address is checked against the
// directory at the moment it is typed, the row gains the person's real name
// immediately, and a typo is refused while the administrator is still looking
// at the field.
//
// PERMISSION MEASURED, NOT ASSUMED (20 Aug 2026). entra-config.php said the
// application permissions from step 4 were "no longer needed" after the group
// door was removed. They are still GRANTED: the app-only token comes back
// carrying roles ["User.Read.All","GroupMember.Read.All"], and
// GET /users/<upn> answers 200 for a real account and 404 for one that does not
// exist. Nothing had to change in Azure. Domain.Read.All is NOT granted
// (/domains answers 403) and is not needed - see USER_EMAIL_DOMAIN.
//
// FAILING TO REACH GRAPH IS NOT THE SAME AS NOT FINDING THE PERSON, and keeping
// those apart is the whole design of the return value. A 404 is Entra answering
// "no such account", which is worth refusing an add over. A timeout, a revoked
// permission or a certificate that has expired is US being broken, and blocking
// an administrator from granting access because our own outbound call failed
// would be the tool getting in the way of the one job this screen has.

/**
 * One app-only Graph GET. Returns [http code, decoded body]; never throws.
 *
 * $path is relative to graph_base, or a whole URL - which is what an
 * @odata.nextLink is, and pasting one onto the base would ask for a page that
 * does not exist.
 */
function graph_get(string $path, int $timeout = 10): array {
    try {
        $token = entra_app_token();
    } catch (Throwable $e) {
        error_log('crm: Graph token failed: ' . $e->getMessage());
        return [0, null];
    }
    $url = str_starts_with($path, 'https://') ? $path : rtrim(entra_cfg()['graph_base'], '/') . $path;
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $token, 'Accept: application/json'],
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_CONNECTTIMEOUT => 5,
    ]);
    $body = curl_exec($ch);
    // curl_close() is deprecated from PHP 8.5 and a no-op since 8.0.
    if ($body === false) {
        error_log('crm: Graph GET ' . $path . ' failed: ' . curl_error($ch));
        return [0, null];
    }
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    return [$code, json_decode((string)$body, true)];
}

/**
 * One app-only Graph write - POST, PATCH or DELETE with a JSON body. Returns
 * [http code, decoded body]; never throws. Code 0 means the request did not
 * complete, which is NOT the same as Graph refusing it: a POST that timed out may
 * still have landed. Used by the tour calendar (tour-calendar.php).
 */
function graph_send(string $method, string $path, ?array $json = null, int $timeout = 15): array {
    try {
        $token = entra_app_token();
    } catch (Throwable $e) {
        error_log('crm: Graph token failed: ' . $e->getMessage());
        return [0, null];
    }
    $url = rtrim(entra_cfg()['graph_base'], '/') . $path;
    $ch = curl_init($url);
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $token, 'Accept: application/json',
                                   'Content-Type: application/json'],
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_CONNECTTIMEOUT => 5,
    ];
    if ($json !== null) $opts[CURLOPT_POSTFIELDS] = json_encode($json, JSON_UNESCAPED_SLASHES);
    curl_setopt_array($ch, $opts);
    $body = curl_exec($ch);
    if ($body === false) {
        error_log('crm: Graph ' . $method . ' ' . $path . ' failed: ' . curl_error($ch));
        return [0, null];
    }
    return [(int)curl_getinfo($ch, CURLINFO_HTTP_CODE), json_decode((string)$body, true)];
}

/**
 * Look one staff email up in the directory.
 *
 * Three outcomes, and the caller must treat them differently:
 *
 *   found        'user' carries id, name, upn, mail, enabled, job title
 *   not_found    Entra says there is no such account. A typo, or somebody gone.
 *   unavailable  we could not ask. Says nothing about the address.
 *
 * TWO LOOKUPS, because they answer different questions. GET /users/<address>
 * matches the userPrincipalName only - the address somebody actually signs in
 * with, which is what auth_is_allowed() will later compare against. The $filter
 * on `mail` catches the case where the address an administrator knows is the
 * mailbox rather than the sign-in name; the caller then stores the UPN, so the
 * row matches the token when that person arrives.
 *
 * @return array{status:string, user:?array}
 */
function graph_find_user(string $email): array {
    $email = strtolower(trim($email));
    if ($email === '') return ['status' => 'not_found', 'user' => null];

    $select = '?$select=id,displayName,givenName,surname,mail,userPrincipalName,jobTitle,accountEnabled,userType';

    [$code, $body] = graph_get('/users/' . rawurlencode($email) . $select);
    if ($code === 200 && is_array($body)) return ['status' => 'found', 'user' => graph_user_shape($body)];
    if ($code !== 404) return ['status' => 'unavailable', 'user' => null];

    // Not a sign-in name. Try it as a mailbox address before calling it a typo.
    [$code, $body] = graph_get('/users' . $select . '&$filter=' . rawurlencode("mail eq '" . str_replace("'", "''", $email) . "'"));
    if ($code !== 200 || !is_array($body)) return ['status' => 'unavailable', 'user' => null];

    $hits = $body['value'] ?? [];
    // Exactly one, or it is not an answer: two accounts sharing a mailbox
    // address is not something to guess at when the outcome is who reads
    // children's personal data.
    if (count($hits) !== 1) return ['status' => 'not_found', 'user' => null];
    return ['status' => 'found', 'user' => graph_user_shape($hits[0])];
}

/** The handful of fields this app uses, named the way it names them. */
function graph_user_shape(array $u): array {
    $name = trim((string)($u['displayName'] ?? ''));
    if ($name === '') {
        $name = trim(($u['givenName'] ?? '') . ' ' . ($u['surname'] ?? ''));
    }
    return [
        'oid'       => (string)($u['id'] ?? ''),
        'name'      => $name !== '' ? $name : null,
        'upn'       => strtolower((string)($u['userPrincipalName'] ?? '')),
        'mail'      => strtolower((string)($u['mail'] ?? '')),
        'job_title' => (string)($u['jobTitle'] ?? '') ?: null,
        // A leaver's account is disabled long before anybody thinks to come
        // here and remove their row, so this is worth refusing an add over.
        'enabled'   => ($u['accountEnabled'] ?? true) ? true : false,
        'guest'     => strtolower((string)($u['userType'] ?? 'Member')) === 'guest',
    ];
}

// ---------------------------------------------------------------------------
// "DID YOU MEAN?" - what to do when the directory says no. (20 Aug 2026)
//
// WHY THIS EXISTS. The refusal above is correct and it is still a dead end:
// "There is no Little Miracles account for cath@littlemiracles.com.au" tells an
// administrator that what they were told on the phone is wrong, and nothing at
// all about what is right. The actual case: Cath said her address was
// cath@littlemiracles.com.au, it is Cathryn.Montgomery@littlemiracles.com.au,
// and the only way to find that out was to open the Microsoft 365 admin portal
// - another system, another sign-in, to answer a question Entra had already
// been asked twice in the previous half second.
//
// SO ASK ENTRA THE SECOND QUESTION TOO. If there is no account at that address,
// which accounts is it nearly?
//
// THE WHOLE TENANT IS ONE PAGE, MEASURED (20 Aug 2026): 103 accounts, $top=999
// returns all of them with no @odata.nextLink, in 130-160ms. That is why the
// matching is done here in PHP over the whole list rather than pushed into a
// Graph $filter or $search - startswith() would find "cathryn" for "cath" and
// would not find it for "catherine" or "kathryn", which are the same mistake.
//
// NOTHING IS STORED. The list is held for the life of the one request that
// asked and is thrown away: a saved copy of the staff directory is a copy that
// drifts, and this is a screen used twice a year.
//
// IT IS NOT A DIRECTORY PICKER, deliberately. At most three names, only when an
// address has already failed, and only accounts that could actually be added -
// so nobody browses the tenant from here, and no suggestion is one the add
// would then refuse.

/** Every account in the tenant, shaped. ['status' => 'ok'|'unavailable', 'users' => []] */
function graph_directory(): array {
    static $cache = null;              // one request, one fetch. Not persisted.
    if ($cache !== null) return $cache;

    $select = '?$select=id,displayName,givenName,surname,mail,userPrincipalName,jobTitle,accountEnabled,userType&$top=999';
    $users = [];
    $path  = '/users' . $select;
    // 103 accounts today, so this loop runs once. It follows @odata.nextLink
    // anyway rather than quietly returning the first 999 of a bigger tenant:
    // a suggestion list that silently stops short reads as "no such person".
    for ($page = 0; $page < 5 && $path !== ''; $page++) {
        [$code, $body] = graph_get($path, 20);
        if ($code !== 200 || !is_array($body)) return $cache = ['status' => 'unavailable', 'users' => []];
        foreach (($body['value'] ?? []) as $u) $users[] = graph_user_shape($u);
        $path = (string)($body['@odata.nextLink'] ?? '');
    }
    if ($path !== '') error_log('crm: Graph directory has more pages than graph_directory() reads.');
    return $cache = ['status' => 'ok', 'users' => $users];
}

/** Letters and digits only, lowercased: "Cathryn.Montgomery" and "Cathryn Montgomery" become one string. */
function graph_name_norm(string $s): string {
    return (string)preg_replace('/[^a-z0-9]/', '', strtolower($s));
}

/**
 * How nearly is $a the same name as $b? 0 to 1, and everything is measured.
 *
 * The thresholds below were run against all 103 real accounts before being
 * written down (see _incl/docs/auth-security-ops.md); each one is here because
 * a looser version suggested somebody else's colleague.
 */
function graph_name_score(string $a, string $b): float {
    if ($a === '' || $b === '') return 0.0;
    if ($a === $b) return 1.0;
    // What was typed is the start of a real name: "cath" -> "cathryn", the case
    // this whole function was written for.
    if (str_starts_with($b, $a)) return 0.90;

    $min = min(strlen($a), strlen($b));
    // A typo, not a different name. ONE edit under six letters, because two
    // edits on a four-letter word is half the word: it matched "park" for
    // "sara" and offered the Niagara Park mailbox to somebody typing a name.
    if ($min >= 4 && levenshtein($a, $b) <= ($min < 6 ? 1 : 2)) return 0.80;

    // Same first four letters and much the same length: "catherine" for
    // "cathryn", "ashley" for "ashleigh" - a name heard rather than spelled.
    // THE LENGTH GUARD IS THE POINT. Without it "emilymerjane" scored on
    // "emilykite", which is a different person entirely.
    $i = 0;
    while ($i < $min && $a[$i] === $b[$i]) $i++;
    if ($i >= 4 && abs(strlen($a) - strlen($b)) <= 2) return 0.78;

    similar_text($a, $b, $pct);
    return $pct / 100 * 0.9;
}

/** The strings an account could reasonably be typed as. */
function graph_match_keys(array $u): array {
    $keys = [];
    foreach ([$u['upn'], $u['mail']] as $addr) {
        $local = explode('@', (string)$addr)[0];
        if ($local === '') continue;
        $keys[] = graph_name_norm($local);
        // "Cathryn.Montgomery" is also "cathryn" and "montgomery": eight of the
        // 103 accounts are firstname.lastname and the rest are a first name, so
        // a first name typed on its own has to reach both shapes.
        foreach (preg_split('/[._-]+/', $local) as $part) {
            $part = graph_name_norm($part);
            if (strlen($part) >= 2) $keys[] = $part;
        }
    }
    if ($u['name']) {
        $keys[] = graph_name_norm($u['name']);
        // A surname alone finds them too - which is how you look somebody up
        // when the first name is the part you are unsure of.
        foreach (preg_split('/\s+/', $u['name']) as $part) {
            $part = graph_name_norm($part);
            if (strlen($part) >= 2) $keys[] = $part;
        }
    }
    return array_unique($keys);
}

/**
 * Accounts a failed address was nearly. Best first, at most $limit.
 *
 * ONLY ACCOUNTS THAT COULD BE ADDED: a disabled account or a guest is refused
 * by directory_check(), so offering one would be a suggestion that turns into a
 * second refusal when it is taken. There are 24 disabled accounts in the tenant
 * and several of them share a first name with somebody still working here.
 *
 * @return array<array{upn:string,name:?string,job_title:?string}>
 */
function graph_suggest_users(string $email, int $limit = 3): array {
    $typed = graph_name_norm(explode('@', strtolower(trim($email)))[0]);
    if (strlen($typed) < 2) return [];

    $dir = graph_directory();
    if ($dir['status'] !== 'ok') return [];

    // Under four characters, only a real prefix counts. "mel" or "bec" against
    // a hundred names by similarity is a fistful of strangers, and a wrong
    // suggestion made confidently is worse than the honest "no such account".
    $prefixOnly = strlen($typed) < 4;

    $hits = [];
    foreach ($dir['users'] as $u) {
        if (!$u['enabled'] || $u['guest'] || $u['upn'] === '') continue;
        $best = 0.0;
        foreach (graph_match_keys($u) as $key) {
            $score = $prefixOnly
                ? (str_starts_with($key, $typed) ? 0.90 : 0.0)
                : graph_name_score($typed, $key);
            if ($score > $best) $best = $score;
        }
        if ($best >= 0.75) $hits[] = ['score' => $best, 'user' => $u];
    }
    // Ties broken by the shorter address, so "tuggerah@" comes before
    // "tuggerahdirector@" - the closest thing to what was typed goes first.
    usort($hits, static fn($x, $y) =>
        [$y['score'], strlen($x['user']['upn'])] <=> [$x['score'], strlen($y['user']['upn'])]);

    return array_map(static fn($h) => [
        'upn'       => $h['user']['upn'],
        'name'      => $h['user']['name'],
        'job_title' => $h['user']['job_title'],
    ], array_slice($hits, 0, $limit));
}
