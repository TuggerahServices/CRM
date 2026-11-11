<?php
// Clear the local session. (Does not sign out of Entra itself.)
require_once __DIR__ . '/../_incl/auth.php';
auth_logout();
header('Location: /');
