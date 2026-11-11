<?php
// Entra redirect target (registered redirect URI: /auth/callback).
require_once __DIR__ . '/../_incl/auth.php';
auth_handle_callback();
