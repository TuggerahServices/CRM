<?php
// TENURE - how long children stay, and every movement that cuts it short.
//
// The organisation's objective is that a child enrols once, stays until they age
// out, and every place stays filled. This screen measures the distance from that:
// how many movements the year actually took, in BOTH directions, per room.
//
// It is deliberately gross flow and not net change. A centre that loses 27
// children and gains 32 reads as "+5" on a net measure and looks like a good
// year; it is 59 movements, 59 phone calls and 59 enrolment packs, and that is
// the work the team is trying to eliminate. See the header of _incl/tenure.php for
// what is measured, what was measured and rejected, and why there are no dollar
// figures on this screen.

declare(strict_types=1);

require_once __DIR__ . '/_incl/auth.php';
$user = auth_require();

require_once __DIR__ . '/_incl/board.php';
require_once __DIR__ . '/_incl/layout.php';

$centres = centres_visible();

$h = static fn($v) => htmlspecialchars((string)$v, ENT_QUOTES);

// Like the Board: 0 is the whole-organisation view, a centre id is its ladder.
$centreId = centre_current($centres, true);

// THE YEAR STICKS ACROSS SCREENS and this screen does not get to break that.
// Churn can only describe a year that has already happened, while the Board is
// planning board_default_year() (2027), so a user arriving from the Board carries
// a year churn cannot answer. Two things NOT done here:
//
//   * defaulting to a different year - year_current() writes to the session, so
//     churn would silently move the year the Board is planning, which is exactly
//     the failure the year rules in CLAUDE.md exist to prevent;
//   * silently clamping - showing 2026 while the chrome says 2027 makes the
//     screen lie about what it is displaying.
//
// So the mismatch is stated, with one click to the latest year that has data.
$year    = year_current(board_default_year());
$maxYear = (int)date('Y');
$tooNew  = $year > $maxYear;
$centre = null;
foreach ($centres as $c) if ((int)$c['id'] === $centreId) $centre = $c;

layout_header('Tenure', $user, 'tenure', [
    'centres'   => $centres,
    'centre_id' => $centreId,
    'all_label' => user_all_centres() ? 'All centres' : null,
    'year'      => $year,
]);
?>

<div class="page-head">
  <div class="page-head-text">
    <h1 class="h3 mb-1"><?= $centre ? $h($centre['name']) : 'Tenure' ?></h1>
    <p class="text-muted mb-0">
      <?= $centreId
          ? 'Every movement in and out, room by room. In the ideal year children enter only at the bottom and leave only at the top.'
          : 'Every centre, most movement first. Gross flow, not net change.' ?>
    </p>
  </div>
  <div class="page-head-actions">
    <?php layout_year_nav('year-nav'); ?>
    <?php if ($centreId): ?>
      <button class="btn btn-outline-secondary" id="refresh"
              title="Re-read this centre's readings from Kidsoft. Takes around half a minute.">
        <i class="fa-solid fa-rotate"></i><span class="visually-hidden">Refresh from Kidsoft</span>
      </button>
    <?php endif; ?>
  </div>
</div>

<div id="init-centre" data-centre="<?= (int)$centreId ?>" data-year="<?= (int)$year ?>" hidden></div>

<div id="banner"></div>

<?php if ($tooNew): ?>
  <div class="alert alert-info d-flex align-items-start">
    <i class="fa-solid fa-clock-rotate-left me-2 mt-1"></i>
    <div>
      <strong><?= (int)$year ?> has not happened yet.</strong>
      Tenure is measured from what was actually booked, so it needs a year that has run.
      <a href="/tenure?centre=<?= (int)$centreId ?>&amp;year=<?= $maxYear ?>" class="alert-link">Show <?= $maxYear ?></a>,
      or <a href="/tenure?centre=<?= (int)$centreId ?>&amp;year=<?= $maxYear - 1 ?>" class="alert-link"><?= $maxYear - 1 ?></a>
      for a complete year.
    </div>
  </div>
<?php endif; ?>

<?php if ($centreId): ?>
  <div class="row g-3 mb-3" id="tiles"></div>

  <?php /* THE SAME CARD SHELL AS THE All centres VIEW AND AS EVERY OTHER SCREEN
           (21 Sep 2026). This was the last Bootstrap .card-header in the app -
           a grey bar with an icon, where the rest of the project titles a card
           with a muted uppercase heading inside the body. See ui-conventions.md.

           THE TABLE STAYS INSIDE THE BODY, unlike the Placement grid's card,
           and that was measured rather than assumed: the body's 2rem costs the
           ladder 32px of a 1030px table at 1280 and its widest column set needs
           about 870, so nothing compresses and no scroller appears. If a centre
           ever grows a column, check that again before reaching for full bleed. */ ?>
  <div class="card mb-3">
    <div class="card-body">
      <div class="d-flex flex-wrap justify-content-between align-items-end gap-2 mb-3">
        <h2 class="h6 text-uppercase text-muted mb-0">The ladder</h2>
        <?php /* A KEY FOR OUR OWN ENCODING, not a caption on the team's business.
                 Which cells are quiet and which carry colour is the whole argument
                 the table is making, and it is the one thing on this screen nobody
                 can work out unaided. The readings sit on the same line.

                 .mk-key / .mk-sw, the shared legend from the MARKS block in
                 app.css. tenure.js used to carry a byte-identical private copy
                 (.tn-key / .tn-sw); only the FILLS are still local, because a
                 swatch's colour belongs to the scale it stands for. */ ?>
        <span class="small text-muted d-flex align-items-center flex-wrap gap-3">
          <span class="mk-key"><i class="mk-sw tn-sw-exp"></i>Expected</span>
          <span class="mk-key"><i class="mk-sw tn-sw-ramp"></i>A place lost or backfilled</span>
          <span id="ladder-sub"></span>
        </span>
      </div>
      <div class="table-scroll">
        <table class="table table-sm table-sticky mb-0 align-middle" id="ladder">
          <?php /* TWO COLUMN GROUPS, and until they were banded the table had two
                   columns called "Left" and two called "Joined" with nothing but a
                   centred caption to tell them apart. The group name is carried
                   down the body by the rules on .tn-sep, and arrivals are split
                   from departures inside each group by .tn-mid-rule.

                   "Between the years" replaces "Over the summer" (17 Aug 2026):
                   the season is right for the southern hemisphere and the phrase
                   still reads as the northern mid-year break. */ ?>
          <thead>
            <tr>
              <th rowspan="2" class="tn-room">Room</th>
              <th rowspan="2" class="text-end tn-roll"
                  title="Children on the roll at the last reading of the year.">On the roll</th>
              <th colspan="5" class="text-center tn-grp tn-sep">Between the years
                <span class="fw-normal text-muted" id="bhead"></span></th>
              <th colspan="3" class="text-center tn-grp tn-sep">During the year</th>
            </tr>
            <tr>
              <th class="text-end tn-sub-h tn-sep"
                  title="Moved up into this room from the room below. This is the ladder working.">Up from<br>below</th>
              <th class="text-end tn-sub-h"
                  title="Started in this room from outside the centre. In the bottom room that is a family starting at the beginning; any room above it is a place churn had opened being backfilled.">Joined<br>here</th>
              <th class="text-end tn-sub-h tn-mid-rule"
                  title="Moved up out of this room into the next one. This is the ladder working.">Up to<br>next</th>
              <th class="text-end tn-sub-h"
                  title="Left this room for school. The intended way off the top of the ladder.">To<br>school</th>
              <th class="text-end tn-sub-h"
                  title="Left the centre over the changeover and did not go to school.">Left</th>
              <th class="text-end tn-sub-h tn-sep"
                  title="Joined this room part-way through the year.">Joined</th>
              <th class="text-end tn-sub-h tn-mid-rule"
                  title="Left the centre part-way through the year and did not go to school.">Left</th>
              <th class="text-end tn-sub-h"
                  title="Moved to another Little Miracles centre part-way through the year. Still enrolled with us.">Moved<br>centre</th>
            </tr>
          </thead>
          <tbody id="ladder-body"></tbody>
        </table>
      </div>
    </div>
  </div>

  <?php /* STARTED AND LEFT BETWEEN READINGS (29 Oct 2026). A reading is a month
           of bookings four times a year, so a family whose whole stay fell
           between two of them was in no reading and counted nowhere. They are
           in the ladder's Joined and Left now; this card is the WHY, asked for
           by Broc: what they asked for against what they were given, how long
           they had waited, how much of it they came for, and Kidsoft's exit
           reason. Shown only when there is somebody in it. */ ?>
  <div class="card mb-3 d-none" id="short-card">
    <div class="card-body">
      <h2 class="h6 text-uppercase text-muted mb-3">Started and left between readings</h2>
      <div class="table-scroll ts-nocap">
        <table class="table table-sm align-middle mb-0">
          <thead>
            <tr>
              <th>Child</th>
              <th>Room</th>
              <th title="Green: asked for and given. Blue: asked for, not given. Ringed: given, not asked for.">Days</th>
              <th>Stayed</th>
              <th class="text-end" title="Days they came, of the days they were booked.">Came</th>
              <th class="text-end" title="From applying to their first day.">Waited</th>
              <th title="Kidsoft's exit reason, as picked when they were exited. A move to another of our centres is named instead.">Reason</th>
            </tr>
          </thead>
          <tbody id="short-body"></tbody>
        </table>
      </div>
    </div>
  </div>
<?php else: ?>
  <div class="row g-3 mb-3" id="tiles"></div>

  <?php /* THE SAME CARD THE OTHER TWO GROUP VIEWS USE.
           This wore a Bootstrap .card-header - a grey bar with an icon in it,
           which no card outside this file has ever had - and was the only
           ranked centre table whose column headings were full-size body ink in
           sentence case. (The ladder above keeps its header; only the All
           centres view was asked about, and the two are different shapes on
           purpose.)

           The Dashboard's "Supply against demand" and "Round progress" and the
           Placement overview are
           the same shape as this one (one row per centre, a mark, then figures),
           so it takes their shell: a muted uppercase heading inside the card
           body with the legend on its line, and .dgrid for the table.

           A legend is present because there are two series. The values also
           appear as numbers in the columns beside each bar, so identity is never
           carried by colour alone. */ ?>
  <div class="card mb-3">
    <div class="card-body">
      <div class="d-flex flex-wrap justify-content-between align-items-end gap-2 mb-3">
        <h2 class="h6 text-uppercase text-muted mb-0">Movement by centre</h2>
        <span class="mk-legend">
          <span class="mk-key"><span class="mk-sw tn-out"></span>Left</span>
          <span class="mk-key"><span class="mk-sw tn-in"></span>Joined</span>
        </span>
      </div>
      <?php /* ts-nocap, like the Dashboard's centre tables: there are seven rows,
               so capping the box and pinning a header buys nothing and only puts
               a scroller round a table that fits. */ ?>
      <div class="table-scroll ts-nocap">
        <table class="table align-middle mb-0 dgrid" id="overview">
          <thead>
            <tr>
              <th>Centre</th>
              <?php /* SHORT, WITH THE DETAIL IN A title=, which is what every
                       other .dgrid column does ("Per place", "Avg wait"). The
                       full sentence set in .75rem uppercase with letter-spacing
                       ran the words together. */ ?>
              <th class="dgrid-stack"
                  title="Left and joined during the year, each as a share of this centre's average roll. Scaled across the centres that can be compared.">Movement</th>
              <th class="text-end">Left</th>
              <th class="text-end">Joined</th>
              <th class="text-end">Roll</th>
              <th class="text-end">Turnover</th>
            </tr>
          </thead>
          <tbody id="overview-body"></tbody>
        </table>
      </div>
    </div>
  </div>
<?php endif; ?>

<?php layout_footer(['/static/js/tenure.js']); ?>
