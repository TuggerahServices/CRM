<?php
// The front door. It sends you to the DASHBOARD (moved 9 Aug 2026).
//
// The Placement board lived here and was the landing screen, from back when it
// was the app's only working screen. It is not any more: the Dashboard is the
// one view that crosses the whole enrolment process - empty places, families
// waiting, the work in flight against them, and next year's round - and that is
// what the app should open on. Placement now answers to /placement.
//
// A REDIRECT RATHER THAN RENDERING THE DASHBOARD HERE, so there is exactly one
// URL per screen. Two paths rendering the same page means the centre bar, the
// sidebar and any link somebody pastes into Teams disagree about where they are.
//
// OLD PLACEMENT DEEP-LINKS STILL WORK. Search results, the retired Fill / Drift /
// Occupancy redirects and anything bookmarked before today point at
// /?centre=&year=&room=&wd=, so a request carrying a room or a weekday is
// Placement's and is forwarded there intact - otherwise a search hit would land
// on the Dashboard and quietly lose the cell it was taking somebody to.

declare(strict_types=1);

require_once __DIR__ . '/_incl/auth.php';
auth_require();

$q = $_GET ?? [];

// Placement-shaped? Those two params mean nothing on the Dashboard.
$toPlacement = isset($q['room']) || isset($q['wd']);
$target = $toPlacement ? '/placement' : '/dashboard';

// The centre and the year are app state and travel with either screen. Anything
// else is Placement's business and is dropped on the way to the Dashboard.
$keep = $toPlacement
    ? $q
    : array_intersect_key($q, array_flip(['centre', 'year']));

// THE ROOT MEANS THE WHOLE ORGANISATION (10 Aug 2026).
//
// It used to mean "wherever you were last", because it forwarded no centre at
// all and the Dashboard then fell through to the session. Combined with the
// sidebar logo - which was rendering href="/?centre=4" - there was no way left
// to ask for the group view except to click the All centres tab: going home from
// Tuggerah landed back on Tuggerah, which is not what a home link means.
//
// The sticky centre is untouched everywhere else, and that is the point of doing
// it here rather than in centre_current(): moving between SCREENS keeps your
// centre, deliberately. Coming back to the front door is a different act, and it
// says group.
//
// An explicit ?centre= still wins, so a shared or bookmarked /?centre=4 is as
// literal as it ever was - only the ABSENCE of one now means All centres rather
// than "ask the session".
//
// EXCEPT FOR SOMEBODY WHO HAS NO GROUP (19 Aug 2026). A supervisor's front door
// cannot say "the whole organisation" - there is no such view for them, and
// centre=0 is a value centre_current() will not let them hold. Forwarding it
// anyway is harmless but dishonest: the URL would name a scope they do not have,
// on every visit, and be silently rewritten by the next screen. Their front door
// says their own centre, which for them IS the whole thing.
if (!$toPlacement && ($keep['centre'] ?? '') === '') {
    $keep['centre'] = user_all_centres() ? 0 : user_default_centre();
}

header('Location: ' . $target . ($keep ? '?' . http_build_query($keep) : ''), true, 302);
