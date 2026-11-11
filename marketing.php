<?php
// MARKETING - where there are places to sell and nobody waiting to take them.
//
// Asked for by Broc, 18 Sep 2026: "Identify where there is a gap between
// available spots and waitlisted children, ordered by most pressing... if we had
// a room in a centre that had numerous spots across all days of the week, but
// noone was waiting to get in, we would want to start advertising efforts in
// order to reach people with children of that age-range."
//
// AN EIGHTH SIDEBAR ITEM, and this file has twice argued against a seventh. Both
// of those arguments turned on one test: is this one stage, or one corner, of a
// module that already exists? Offers widened into In progress rather than taking
// a row; User access hangs off the user button. This one passes the test in the
// other direction:
//
//   - Every other screen answers "who can we put in this place". This one
//     answers "there is nobody to put in it", which is the one conclusion that
//     sends the work OUTSIDE this app entirely - to whoever buys the
//     advertising. No screen that lists families can reach it.
//   - The Dashboard's Places to fill is the closest thing and it is deliberately
//     half of this: it reports where the free place-days are and says nothing
//     about whether anybody is waiting for them. Measured 18 Sep 2026, those two
//     halves point at different rooms - Tuggerah carries the most free places in
//     2027 (183) and almost no gap, while Kanwal's Amazing Asteroids carries 13
//     uncovered place-days out of 48 free.
//
// THE YEAR IS THE WHOLE QUESTION, so this screen has a stepper. The Dashboard
// deliberately has none - the four things it crosses are scoped differently -
// and it was measured on 16 Sep 2026 that this year and next point at opposite
// ends of the building: Little Angels is the FULLEST tier in 2026 and the
// emptiest by a distance in 2027, because next year's babies have not been
// enrolled yet. An advertising decision aimed at the wrong year is aimed at the
// wrong room.
//
// See _incl/docs/marketing.md for the measurements, and _incl/marketing.php for
// why the gap is measured per cell and reported per tier.

declare(strict_types=1);

require_once __DIR__ . '/_incl/auth.php';
$user = auth_require();

require_once __DIR__ . '/_incl/marketing.php';
require_once __DIR__ . '/_incl/layout.php';

$centres = centres_visible();

// Group by default, like In progress and Support plans: where to advertise is a
// decision made across the organisation, and the ranking is the point - Kanwal's
// three-year-olds outranking Tuggerah's is exactly what a per-centre view cannot
// say. centre_current() refuses 0 for a supervisor, who is pinned to one of their
// own centres and gets the per-centre shape.
$centreId = centre_current($centres, true);
// The same default every other screen passes, so moving between screens cannot
// silently change which year you are planning. See year_current().
$year = year_current(board_default_year());

layout_header('Marketing', $user, 'marketing', [
    'centres'   => $centres,
    'centre_id' => $centreId,
    'all_label' => user_all_centres() ? 'All centres' : null,
    'year'      => $year,
]);
?>

<div class="page-head">
  <div class="page-head-text">
    <h1 class="h3 mb-1">Marketing</h1>
    <p class="text-muted mb-0">
      Places going spare that nobody in the queue can take, by age group and
      centre. Biggest gap first.
    </p>
  </div>
  <div class="page-head-actions">
    <?php layout_year_nav('year-nav'); ?>
    <a class="btn btn-outline-primary" href="/waitlist<?= $centreId ? '?centre=' . (int)$centreId : '' ?>">
      <i class="fa-solid fa-list-ol me-1"></i>Open the waitlist
    </a>
  </div>
</div>

<div id="mk-summary" class="row g-3 mb-3"></div>

<?php /* A CENTRE THAT IS SHUT, OR ONE KIDSOFT WOULD NOT ANSWER FOR, IS ABSENT
         FROM THE RANKING - and which of the two it is changes whether the
         figures above can be trusted. That is the one thing the team cannot know
         unaided, so it is a banner and not a caption. Warnervale alone would
         contribute 333 uncovered place-days against the six trading centres' 38
         put together; see _incl/marketing.php. */ ?>
<div id="mk-notices"></div>

<div id="mk-list"></div>

<?php /* The centre and the year are resolved SERVER-SIDE and read once from
         these, never from a control - the rule every screen follows. The group
         view and the per-centre view are different shapes, and this is what
         decides which one is built. */ ?>
<input type="hidden" id="init-centre" value="<?= (int)$centreId ?>">
<input type="hidden" id="init-year" value="<?= (int)$year ?>">

<?php layout_footer(['/static/js/marketing.js']); ?>
