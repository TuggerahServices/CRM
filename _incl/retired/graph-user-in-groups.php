<?php
// RETIRED 19 Aug 2026, from _incl/graph.php. Loaded by nothing.
//
// The fallback for the SSO groups-claim overage, back when an Entra security
// group could grant access to this app. It cannot any more: who may sign in is
// a row in `app_users`, with a role and (for a supervisor) a set of centres,
// and a group grants neither. See _incl/docs/auth-security-ops.md.
//
// Kept because it is the only worked example in this codebase of calling Graph
// with the app-only certificate credential for something other than mail - if
// a future feature needs checkMemberGroups or a similar POST, start here.

/**
 * Fallback for the SSO groups-claim overage (user in too many groups for the
 * token to carry them): ask Graph which of $groupIds the user belongs to.
 * Needs an app permission that allows checkMemberGroups; returns [] if not
 * permitted (caller then denies).
 *
 * @return string[] the subset of $groupIds the user is a member of
 */
function graph_user_in_groups(string $userOid, array $groupIds): array {
    if (!$groupIds) return [];
    $token = entra_app_token();
    $url = rtrim(entra_cfg()['graph_base'], '/') . '/users/' . rawurlencode($userOid) . '/checkMemberGroups';
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode(['groupIds' => array_values($groupIds)]),
        CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $token, 'Content-Type: application/json'],
        CURLOPT_TIMEOUT        => 30,
    ]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    if ($body === false || $code >= 300) return [];
    $data = json_decode((string)$body, true);
    return is_array($data['value'] ?? null) ? $data['value'] : [];
}
