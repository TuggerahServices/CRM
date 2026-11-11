<?php
// Kick off SSO. Optional ?return=/path to come back to after login (local paths
// only - auth_safe_return() is the one place that judges that, so the rule
// cannot drift between here and the callback).
//
// ?switch=1 drops the current local session and asks Entra for the account
// picker. It is what the "not authorised" page links to: without it, silent SSO
// hands back the very account that was just refused and the user cannot get out
// of the loop.
require_once __DIR__ . '/../_incl/auth.php';

$switch = !empty($_GET['switch']);
if ($switch) auth_logout();

auth_start_login(auth_safe_return($_GET['return'] ?? '/'), $switch);
