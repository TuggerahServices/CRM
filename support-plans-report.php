<?php
// SUPPORT PLANS, PRINTED - every child the Support plans tab shows, or one child.
//
// Asked for by Broc, 29 Oct 2026: "I want to be able to generate printable
// reports for Support Plans." The screen answers "who is on a plan and where has
// it got to" one card at a time, with the steps behind a click and only the
// latest call on the card; paper has no clicks, so the report is the card with
// everything behind it laid out flat - every step with its date, who ticked it
// and its note, the held days, and the family's whole contact history.
//
//   /support-plans-report?centre=4        every child at Tuggerah
//   /support-plans-report?centre=0        every child group-wide
//   /support-plans-report?placement=3356  one child
//
// NOTHING NEW IS WORKED OUT FOR IT. The rows are support_plans_list()'s, in its
// order, filtered - see `report` in api/support-plans.php - and the plan facts
// are rendered by the same functions that draw the card. Paper cannot be
// refreshed, which makes a second answer to the same question worse here than
// on any screen.
//
// A PAGE IN THE APP, NOT A BARE PRINT VIEW. It keeps the sidebar and the centre
// tabs on screen, so the report can be read before it is printed and pointed at
// another centre; @media print in app.css strips the chrome. One child or a whole
// tab, the same renderer: a whole tab is a summary page, then each child on a page
// of their own.

declare(strict_types=1);

require_once __DIR__ . '/_incl/auth.php';
$user = auth_require();

require_once __DIR__ . '/_incl/support-plans.php';
require_once __DIR__ . '/_incl/layout.php';

$placementId = (int)($_GET['placement'] ?? 0);

// ONE CHILD HAS NO CENTRE TABS. The child's centre is the row's, and a tab strip
// above one child's report would offer to switch to a centre that does not have
// them. It also leaves the remembered centre alone: printing one child from the
// All centres tab must not move the user's Support plans screen to Tuggerah.
// Which child the caller may see is checked by the endpoint, off the row.
$opts = [];
$centreId = 0;
if (!$placementId) {
    $centres  = centres_visible();
    $centreId = centre_current($centres, true);
    $opts = [
        'centres'   => $centres,
        'centre_id' => $centreId,
        'all_label' => user_all_centres() ? 'All centres' : null,
    ];
}

layout_header('Support plans report', $user, 'support', $opts);

$back = '/support-plans' . ($placementId ? '' : '?centre=' . $centreId);
?>

<div class="page-head d-print-none">
  <div class="page-head-text">
    <h1 class="h3 mb-1">Support plans report</h1>
    <p class="text-muted mb-0">
      <?= $placementId
          ? 'One child, with every step, held day and contact logged.'
          : 'A summary page, then each child on a page of their own with every step, held day and contact logged.' ?>
    </p>
  </div>
  <div class="page-head-actions">
    <a class="btn btn-outline-secondary" href="<?= htmlspecialchars($back, ENT_QUOTES) ?>">
      <i class="fa-solid fa-arrow-left me-1"></i>Support plans
    </a>
    <?php /* Disabled until the report has loaded: printing an empty sheet that
             says "Loading..." is the one way this button could go wrong. */ ?>
    <button type="button" class="btn btn-primary" id="spr-print" disabled>
      <i class="fa-solid fa-print me-1"></i>Print
    </button>
  </div>
</div>

<?php /* THE SHEET. Bordered on screen so it reads as the paper it will become;
         on paper the border and the padding go and the page margins take over. */ ?>
<article class="spr-sheet">
  <header class="spr-head">
    <?php /* The STACKED logo, because the sidebar already inlines the inline one
             and svg_scope() keys its prefix on the file path - a second copy of
             the same file would repeat its ids on one page. */ ?>
    <?php svg_inline('littlemiracles_branding/Logo Stacked.svg', 'spr-logo', 'Little Miracles'); ?>
    <div class="spr-head-text">
      <div class="spr-kicker">Confidential</div>
      <div class="spr-title">Support plans</div>
      <div class="spr-scope" id="spr-scope"></div>
    </div>
    <div class="spr-printed" id="spr-printed"></div>
  </header>
  <?php /* Kidsoft refusing to answer for a centre-year: the screen's own banner,
           drawn by the same renderNotices(). */ ?>
  <div id="sp-notices"></div>
  <div id="spr-report"><div class="text-muted small">Loading...</div></div>
</article>

<input type="hidden" id="init-centre" value="<?= (int)$centreId ?>">
<input type="hidden" id="init-placement" value="<?= (int)$placementId ?>">

<?php layout_footer(['/static/js/support-plans.js']); ?>
