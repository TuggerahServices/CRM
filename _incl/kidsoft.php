<?php
// Client for the Kidsoft v1 API. READ-ONLY, WITH ONE NAMED EXCEPTION.
//
// kidsoft() hard-refuses any action that is not a get*, so a mutating endpoint
// cannot be reached through it even by mistake. DO NOT WEAKEN THAT GUARD, and do
// not add a second exception to the one below without the same authorisation.
//
// THE ONE EXCEPTION: Comment/createComment, authorised by Broc on 14 Aug 2026,
// reached ONLY through kidsoft_create_comment() and refused by everything else.
// It exists because Kidsoft is in maintenance and will not be adding the read
// endpoint that would have made a one-way sync unnecessary. What was measured
// before it was opened, and why the scope is this narrow:
//
//   - A Kidsoft CHILD record is created when an application converts to an
//     ENROLMENT, not when a family applies. Of 719 OPEN waitlist applications
//     group-wide, 2 carry a ChildID and 92 a GuardianID - so **87% of waiting
//     families cannot be written to at all**, and the local log in
//     _incl/comments.php is the record for them.
//   - A comment written against a WaitingListID is INVISIBLE afterwards. Proved
//     by having a PAM add one in the Kidsoft UI to a waitlist-only family and
//     failing to find it in getWaitingList (every flag), getChildren or
//     getGuardians, at any centre, over five minutes of polling. So this
//     function DELIBERATELY NEVER PASSES WaitingListID - writing there would
//     produce a note nobody, including Kidsoft's own children list, can read.
//   - Matching a family to a guardian record by phone or email would lift reach
//     from 13% to 24%, and was REFUSED (Broc, 14 Aug 2026). A wrong match writes
//     one family's confidential note permanently onto another family's record,
//     and there is no update or delete on this endpoint. Only IDs Kidsoft itself
//     supplies are ever used.
//
// createComment is the ONLY action on its controller: no update, no delete.
// Everything sent is permanent. Treat every call as irreversible.
//
// API REFERENCE: https://app.kidsoft.com.au/api/v1/Kidsoft/Documentation/getHTML

/**
 * Call a Kidsoft v1 get* endpoint and return the decoded JSON as an array.
 *
 * @param string $controller (case-sensitive)
 * @param string $action (must be a get* action)
 * @param array  $params (query params to merge with ServiceKey)
 * @throws RuntimeException on a non-get action, transport failure, non-JSON, or Status != OK
 */
function kidsoft(string $controller, string $action, array $params = []): array {
    static $cfg = null;
    if ($cfg === null) {
        $cfg = require __DIR__ . '/kidsoft-config.php';
    }

    // Read-only guard: the one line that enforces the project's hard rule.
    if (!preg_match('/^get[A-Z0-9]/', $action)) {
        throw new RuntimeException("Kidsoft client is READ-ONLY; refused non-get action '{$controller}/{$action}'");
    }

    $query = array_merge(['ServiceKey' => $cfg['service_key']], $params);
    $url = rtrim($cfg['base'], '/') . "/{$controller}/{$action}?" . http_build_query($query);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPGET        => true,
        CURLOPT_TIMEOUT        => 60,
        CURLOPT_CONNECTTIMEOUT => 15,
    ]);
    // curl_close() is deprecated from PHP 8.5 and has been a no-op since 8.0 --
    // the handle is freed when $ch goes out of scope.
    $body = curl_exec($ch);
    if ($body === false) {
        $err = curl_error($ch);
        throw new RuntimeException("Kidsoft request failed ({$controller}/{$action}): {$err}");
    }
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    $data = json_decode($body, true);
    if (!is_array($data)) {
        throw new RuntimeException("Kidsoft returned non-JSON (HTTP {$http}) for {$controller}/{$action}: " . substr($body, 0, 200));
    }
    if (($data['Status'] ?? null) !== 'OK') {
        $msg = $data['Message'] ?? 'unknown error';
        throw new RuntimeException("Kidsoft API error for {$controller}/{$action}: {$msg} (Status=" . ($data['Status'] ?? '?') . ")");
    }
    return $data;
}

/**
 * Write ONE comment to a Kidsoft child or guardian record. THE ONLY WRITE THIS
 * PROJECT MAKES.
 *
 * Separate from kidsoft() on purpose: that function's `^get[A-Z0-9]` guard is
 * the thing that makes "read-only" true rather than merely intended, so the
 * exception is a different function with its own hard-coded endpoint rather than
 * a flag threaded through the guard. There is nothing to weaken here - the
 * controller and action are literals, so this cannot be pointed at
 * Waitlist/createEntry or Discount/createDiscount by passing an argument.
 *
 * POST, not GET: the comment body is free text of unbounded length and would not
 * survive a query string. The documented parameters are ServiceID, ChildID /
 * GuardianID / WaitingListID, Comment, Type and Confidential.
 *
 * **`Confidential` IS ALWAYS TRUE AND IS NOT A PARAMETER** (Broc, 14 Aug 2026:
 * every comment must be confidential). A caller cannot ask for a public one, so
 * there is no path by which a note about a family becomes visible to a parent
 * portal because somebody left a checkbox clear. This is deliberately NOT an
 * option: an override nobody varies is the pattern this codebase keeps deleting.
 *
 * **WaitingListID is deliberately unsupported.** See the header: a comment
 * written there cannot be read back by anything, so it would be a note the team
 * believes they have filed and nobody can ever retrieve.
 *
 * @param string $target 'child' or 'guardian'
 * @param string $id     the ChildID or GuardianID, as supplied BY KIDSOFT
 * @return string the new CommentID
 * @throws RuntimeException on a bad target, transport failure, or a non-OK Status
 */
function kidsoft_create_comment(string $serviceId, string $target, string $id,
                                string $comment, string $type = 'Enrolments'): string {
    static $cfg = null;
    if ($cfg === null) $cfg = require __DIR__ . '/kidsoft-config.php';

    if ($target !== 'child' && $target !== 'guardian') {
        throw new RuntimeException("kidsoft_create_comment: target must be child or guardian, got '{$target}'");
    }
    if (trim($id) === '')      throw new RuntimeException('kidsoft_create_comment: empty record id');
    if (trim($comment) === '') throw new RuntimeException('kidsoft_create_comment: refusing to write an empty comment');

    $fields = [
        'ServiceKey'   => $cfg['service_key'],
        'ServiceID'    => $serviceId,
        ($target === 'child' ? 'ChildID' : 'GuardianID') => $id,
        'Comment'      => $comment,
        // Text(20) per the docs, so it is truncated here rather than being
        // silently dropped or rejected by the far end.
        'Type'         => substr($type, 0, 20),
        'Confidential' => 'true',
    ];

    $ch = curl_init(rtrim($cfg['base'], '/') . '/Comment/createComment');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query($fields),
        CURLOPT_TIMEOUT        => 60,
        CURLOPT_CONNECTTIMEOUT => 15,
    ]);
    $body = curl_exec($ch);
    if ($body === false) {
        throw new RuntimeException('Kidsoft comment write failed: ' . curl_error($ch));
    }
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    $data = json_decode($body, true);
    if (!is_array($data)) {
        throw new RuntimeException("Kidsoft returned non-JSON (HTTP {$http}) for Comment/createComment: "
            . substr((string)$body, 0, 200));
    }
    if (($data['Status'] ?? null) !== 'OK') {
        throw new RuntimeException('Kidsoft refused the comment: ' . ($data['Message'] ?? 'unknown error')
            . ' (Status=' . ($data['Status'] ?? '?') . ')');
    }

    // Documented as CommentID: [Array<GUID>], and it arrives as one. Take the
    // first either way rather than assuming which shape turned up.
    $cid = $data['CommentID'] ?? '';
    if (is_array($cid)) $cid = reset($cid) ?: '';
    return (string)$cid;
}