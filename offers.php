<?php
// RENAMED - this module became "In progress" on 10 Aug 2026. See progress.php.
//
// It listed pending offers and nothing else. But an offer is not a separate kind
// of thing: it is a STAGE of a work item (WORK_STAGES contains 'offered', and
// work_link_offer() sets offer_id and advances the item into it), so this module
// was showing one stage of the work layer and calling it the whole subject.
//
// The finding came from UAT: a user went to Offers looking for the work in
// flight and found only offers. Rather than add a seventh module to an already
// crowded sidebar, this one widened to what the user already believed it was.
// Everything the old screen did is still here - the same offers, the same
// expiry countdown, the same red band inside three days - with everything else
// in progress alongside them.
//
// Kept as a redirect rather than deleted so bookmarks and any link sent round
// the team while this was live still land somewhere useful.

declare(strict_types=1);

require_once __DIR__ . '/_incl/auth.php';
auth_require();

$q = [];
if (!empty($_GET['centre'])) $q['centre'] = (int)$_GET['centre'];

header('Location: /progress' . ($q ? '?' . http_build_query($q) : ''), true, 301);
exit;
