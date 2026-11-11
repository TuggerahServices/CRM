<?php
// HTML error page, rendered internally by nginx for 4xx/5xx (see the vhost).
//
// DELIBERATELY SELF-CONTAINED: no database, no session, no auth. This page may
// well be rendering *because* one of those failed, and an error page that can
// itself error is worse than useless. The only file it touches is the logo SVG,
// and that is optional.
//
// It also says nothing about what went wrong internally - the visitor gets a
// plain description and a way back; the detail belongs in the error log.

declare(strict_types=1);

$code = (int)($_GET['code'] ?? 500);

$messages = [
    400 => ['Bad request',         'The browser sent something this page could not read.'],
    401 => ['Sign-in needed',      'Sign in with your Little Miracles account to continue.'],
    403 => ['Not allowed',         'Your account does not have access to this page.'],
    404 => ['Page not found',      'That address does not exist. It may have been renamed.'],
    405 => ['Not allowed here',    'That action is not available on this page.'],
    422 => ['Could not process',   'Something in the request did not make sense.'],
    429 => ['Too many requests',   'Slow down for a moment, then try again.'],
    500 => ['Something broke',     'An error on our side stopped this page loading.'],
    502 => ['Service unavailable', 'The application is not responding right now.'],
    503 => ['Temporarily down',    'The service is briefly unavailable. Try again shortly.'],
];
[$title, $detail] = $messages[$code] ?? ['Error', 'Something unexpected happened.'];

http_response_code($code);
header('Content-Type: text/html; charset=utf-8');

// Read the brand mark directly. _incl is nginx-blocked, so it cannot be linked
// as a URL; inlining is the only way to show it. Failure here is not fatal.
$logo = '';
$logoPath = __DIR__ . '/../_incl/littlemiracles_branding/Logo Inline.svg';
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
<title><?= $h($code) ?> - <?= $h($title) ?> | Little Miracles CRM</title>
<meta name="robots" content="noindex, nofollow">
<link rel="icon" href="/favicon.ico" sizes="any">
<link rel="stylesheet" href="/static/css/app.css">
<style>
/* Inline so the page still renders legibly if the stylesheet is missing --
   plausible when everything else has already gone wrong. */
body{margin:0;min-height:100vh;display:grid;place-items:center;background:#f7f6fb;
  font-family:Outfit,system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;color:#2b2b3a}
.err{max-width:32rem;padding:2.5rem 2rem;text-align:center}
.err svg{height:44px;width:auto;margin-bottom:1.75rem}
.err-code{font-size:3.5rem;font-weight:700;line-height:1;color:#2e3192;margin:0}
.err-title{font-size:1.25rem;font-weight:600;margin:.5rem 0 .35rem}
.err-detail{color:#6c6a8a;margin:0 0 1.75rem}
.err a{display:inline-block;padding:.55rem 1.25rem;border-radius:.4rem;background:#2e3192;
  color:#fff;text-decoration:none;font-weight:500}
.err a:hover{background:#262a7d}
</style>
</head>
<body>
<div class="err">
  <?= $logo ?>
  <p class="err-code"><?= $h($code) ?></p>
  <p class="err-title"><?= $h($title) ?></p>
  <p class="err-detail"><?= $h($detail) ?></p>
  <a href="/">Back to the dashboard</a>
</div>
</body>
</html>
