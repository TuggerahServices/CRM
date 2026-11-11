<?php
// THE DASHBOARD - the enrolment process end to end.
//
// The other five screens each answer one question well. This is the one that
// crosses them, because the actual job crosses them: an empty place, a family
// who might take it, the work of getting them there, and Kidsoft finally showing
// the child in the room. The organisation is paid only for that last step.
//
// It reads two things that used to have no screen in common:
//   * the WINDOW  - Kidsoft, derived live (_incl/board-live.php): who really
//                    holds a place, in every centre, for the year.
//   * the WORK    - our work items (_incl/work.php): what anybody is doing
//                    about the places nobody holds. Kidsoft has no field for
//                    any of it, which is why the spreadsheets outlived the app.
//
// See the header of _incl/dashboard.php for what is deliberately NOT on here --
// principally stage-to-stage conversion rates, which cannot honestly be computed
// from this data yet.

declare(strict_types=1);

require_once __DIR__ . '/_incl/auth.php';
$user = auth_require();

require_once __DIR__ . '/_incl/board.php';
require_once __DIR__ . '/_incl/layout.php';
require_once __DIR__ . '/_incl/week-ahead.php';   // week_changes_bounds() for the dialog's date boxes

$centres = centres_visible();

$h = static fn($v) => htmlspecialchars((string)$v, ENT_QUOTES);

// 0 = the whole organisation, which is the point of the screen; a centre id
// narrows it. Like the Board, both views are real, so All centres is allowed.
$centreId = centre_current($centres, true);

// THERE IS NO YEAR PICKER ON THIS SCREEN, DELIBERATELY (6 Aug 2026).
//
// One was built and removed the same day, because "the year" does not mean one
// thing here. This screen crosses four things and they are scoped differently:
//
//   places to fill / filled %  - THIS year. A free place now is a lost sale now.
//   the re-enrolment round     - NEXT year, by definition. That is what a
//                                 re-enrolment is.
//   the queue                  - NOT year-scoped. It is ONE queue: 722 open
//                                 applications, and a family who nominally asked
//                                 for 2026 and is still unplaced is a live
//                                 candidate for 2027. `for_year` is a
//                                 Kidsoft-seeded judgement that is never updated
//                                 (see the waitlist ownership rules), so
//                                 filtering the headline by it would hide most of
//                                 the queue and call it accuracy.
//   work in flight             - ALL open work, whatever year it targets.
//
// A single stepper would have moved the first and the last and silently left the
// middle two alone, which is worse than no control at all. Each figure names its
// own year on screen instead. To study a specific year's board, that is the
// Placement screen's job.
//
// year_current() is deliberately NOT used, for the reason tenure.php documents:
// it writes to the session, so this screen would silently move the year the
// Placement board is planning.
$year = (int)date_create('today', new DateTimeZone('Australia/Sydney'))->format('Y');

layout_header('Dashboard', $user, 'dashboard', [
    'centres'   => $centres,
    'centre_id' => $centreId,
    'all_label' => user_all_centres() ? 'All centres' : null,
    // Carried so the centre tabs and the sidebar keep whatever year the other
    // screens are on - this screen just does not offer a control for it.
    'year'      => year_current(board_default_year()),
]);
?>

<div class="page-head">
  <div class="page-head-text">
    <h1 class="h3 mb-1">Dashboard</h1>
    <p class="text-muted mb-0">
      <?= $centreId
            ? 'Every empty place, every family waiting, and the work in between.'
            : 'The whole organisation: where the places are, who is waiting for them, '
              . 'and how next year is forming.' ?>
    </p>
  </div>
</div>

<?php /* Anything true of the whole view AND that the team cannot know unaided --
         which in practice means one thing: a centre Kidsoft would not answer
         for, because that changes whether the figures can be trusted at all. A
         closed centre is not news to the people who run it and gets no banner.
         Everything else that used to be an alert here is now a ranked row in
         "Needs attention" below, where the counts can be compared instead of
         shouting at equal volume. */ ?>
<div id="dash-notices"></div>

<?php /* ================= THE TWO VIEWS ARE DIFFERENT SHAPES =================
     Decided here, server-side, like every other either/or in this app, so only
     one set of containers is ever in the DOM and the JS has nothing to hide.

     ALL CENTRES is the screen the team opens the day on, so it leads with one
     figure (the places the organisation has to sell), then the work those
     figures imply, then the comparison across centres. A CENTRE is a working
     screen and keeps the shape it already had - four tiles and its own lists.
     ========================================================================= */ ?>
<?php if (!$centreId): ?>

  <?php /* The lead: one hero figure, the fill it comes out of, and the demand
           pressing on it. Exactly one hero per view. */ ?>
  <div id="dash-hero"></div>

  <?php /* WHERE THAT FIGURE'S PLACES ARE, immediately under it - the weekly
           report to management: room tier against centre, plus the weekday cut
           that runs across the matrix. Moved above Needs attention 16 Sep 2026,
           at the team's request.

           IT IS THE HERO'S OWN BREAKDOWN, which is the argument for the move:
           the two are one number at two resolutions, built off the same cells in
           the same boards, and everything between them was making the reader
           carry 117 down the page to find out where it was. Needs attention is
           a list of DEADLINES and reads the same wherever it sits; this does
           not - it only means anything beside the figure it decomposes.

           AND THE YEAR AHEAD BESIDE IT, because they are different questions
           and not one question twice. This year says where a place is standing
           empty now; next year says where the re-enrolment round has not sold
           the room yet, and measured 16 Sep 2026 they point at opposite ends of
           the building - Little Angels is the FULLEST tier in 2026 (98.7%) and
           the emptiest in 2027 by a distance (38.6%, 223 place-days). Neither
           is a second hero: each card names its own year in its own title, the
           way every other figure on this screen does instead of there being a
           year picker. */ ?>
  <div class="row g-3">
    <div class="col-12 col-xxl-6"><div id="dash-fill"></div></div>
    <div class="col-12 col-xxl-6"><div id="dash-fill-next"></div></div>
  </div>

  <?php /* What the figures above oblige somebody to do, ranked by how hard the
           deadline is - past a date, then inside 30 days, then in season, then
           the opportunities. NOT ranked by size: which of these matters most is
           the team's judgement, and the screen must not pretend otherwise. */ ?>
  <div id="dash-attention"></div>

  <?php /* WHO IS TURNING UP. A tour belongs to a centre and nothing narrower, so
           unlike the work list this one HAS a group-wide version - "who is
           visiting the organisation this week" is exactly a morning-overview
           question. High on the page because it is the only block here with a
           person physically arriving at a door. */ ?>
  <?php /* THE TWO "THIS WEEK" CARDS, SIDE BY SIDE - who is turning up at a door,
           and who starts, stops or moves room this fortnight ("What's changing
           this fortnight", widened from next week only on 4 Nov 2026). Same argument as the declines and
           gap pair lower down: BOTH ARE NATURALLY NARROW (three columns and
           five), and stacked full-width they left half the row empty. Measured
           at 1920: the changes table needs 855px and was sitting in a 1888px
           card, which is what "squished over on the left" means.

           5 AND 7, NOT 6 AND 6, for the same reason the declines pair below is:
           the changes row carries a name, a centre, a room like "Little Angels
           -> Radiant Rainbows", a weekday strip AND a change label, where tours
           carries a time, a name and a centre. At 6/6 the change column was
           clipped mid-word ("Ending car"). The longer row takes the wide half.

           xxl, NOT xl. At 1280 the wide half is ~560px and the changes table
           would scroll horizontally inside it; xl starts at 1200 and would do
           exactly that. xxl starts at 1400, so the pair only forms once there is
           genuinely room for both, and below it they stack full width - which is
           the shape that already looked right.

           There is deliberately NO projected fill % on the changes card:
           measured 15 Sep 2026 the group moves 95.2% -> 95.1% between this week
           and next, and it would have been a second fill figure disagreeing with
           the board's. See the header of _incl/week-ahead.php. */ ?>
  <div class="row g-3" id="dash-thisweek">
    <div class="col-12 col-xxl-5"><div id="dash-tours"></div></div>
    <div class="col-12 col-xxl-7"><div id="dash-week"></div></div>
  </div>

  <?php /* Supply against demand, across the group: the ranking the Placement
           overview cannot make, because it only ever sees the supply side. */ ?>
  <div id="dash-centres"></div>

  <?php /* Next year forming, live - how far through the re-enrolment round each
           centre is. Phase one of the whole enrolment process, running now. */ ?>
  <div id="dash-round"></div>

  <?php /* The two forward-looking lists, side by side. Each is naturally narrow -
           six centres and a dozen children - and stacked full-width they
           left half the page empty, which is what the group view looked like
           before. */ ?>
  <?php /* 5 and 7, not 6 and 6. The gap list is four narrow columns of counts;
           the declines list carries a child's name, a centre, a room name like
           "Mighty Meteors Silver" and a weekday strip, and at half the page it
           wrapped nearly every row onto two lines - which made the taller card
           taller still and left the shorter one sitting over a block of white. */ ?>
  <div class="row g-3">
    <div class="col-12 col-xl-5"><div id="dash-gap"></div></div>
    <div class="col-12 col-xl-7"><div id="dash-free"></div></div>
  </div>

  <?php /* Last, because it is the only block that is empty until the team starts
           using it. It is the half Kidsoft does not do, so it stays on screen
           rather than being hidden while it is empty. */ ?>
  <div id="dash-pipeline"></div>

<?php else: ?>

  <div id="dash-summary" class="row g-3 mb-3"></div>

  <?php /* The pipeline: where every family stands right now. A snapshot, never a
           conversion funnel - see _incl/dashboard.php for why rates would lie. */ ?>
  <div id="dash-pipeline" class="mb-3"></div>

  <?php /* One centre is not a one-row table, so the JS renders a stat strip. */ ?>
  <div id="dash-centres"></div>

  <?php /* PLACES TO FILL is A DIFFERENT SHAPE FROM THE GROUP'S, not the same
           table filtered. Across the group the comparison is room tier against
           CENTRE, which is what a marketing decision is made on; inside one
           centre there is only one column, so it becomes room against WEEKDAY -
           the cell the team actually fills. Which one renders is decided by the
           JS off the same payload, because the containers are identical.

           PAIRED WITH THE WEEK, for the reason the tours pair above gives: on a
           centre the changes table loses its Centre column and is four columns
           of a handful of children, so full width at 1920 it was two rows
           floating in 1888px. 7 and 5 - the fill grid carries a room name and
           seven figures, the changes list four short columns. They stack below
           xxl, where full width is already the right shape for both. */ ?>
  <?php /* The year ahead sits UNDER this year, in the same column and in the
           same shape, rather than getting a row of its own: it is the same
           report asked about a different year, and a card that changes width
           when the year changes reads as a different card. The pair with the
           week is measured and unchanged - see above. */ ?>
  <div class="row g-3" id="dash-centre-pair">
    <div class="col-12 col-xxl-5">
      <div id="dash-fill"></div>
      <div id="dash-fill-next"></div>
    </div>
    <div class="col-12 col-xxl-7"><div id="dash-week"></div></div>
  </div>

  <div class="row g-3">
    <div class="col-12 col-xl-7">
      <?php /* Next year forming, live: who has not answered yet (phase one's work
               list), who has declined (places coming free, knowable now), and who
               has accepted without a booking - families who believe they have a
               place. Six columns, so it needs the WIDE half: at col-xl-5 the last
               column was clipped off the edge of the card. */ ?>
      <div id="dash-reenrolment"></div>
    </div>
    <div class="col-12 col-xl-5">
      <?php /* Who is coming to visit this centre, and which visits nobody has
               answered. Above the work list because a tour has a person arriving
               at a time, and the list below it has neither. */ ?>
      <div id="dash-tours" class="mb-3"></div>

      <?php /* The work that has gone quiet or is past its date. A work item
               belongs to a room and a room to one centre, so there is no
               group-wide version of this list and the container is simply not
               emitted above. */ ?>
      <div id="dash-work"></div>
    </div>
  </div>

<?php endif; ?>

<?php /* WHAT'S CHANGING, FOR ANY RANGE (Broc, 6 Nov 2026): opened by the
         card's "View more..." link, filled by dashboard.js from /api/dashboard
         `changes`. The same table as the card, a page at a time, for the dates
         in the two boxes at the top.

         THE BOXES CANNOT HOLD A REVERSED RANGE. Each bounds the other through
         min/max (set by the script, as the Waitlist's start dates do), and both
         are held to the two years week_changes_bounds() allows - the years the
         nightly warm keeps, so no range can turn into a cold Kidsoft read. The
         calendar greys out what is not allowed; a date typed past it fails
         checkValidity(), turns red and is never sent. */
      [$wkLo, $wkHi] = week_changes_bounds();
      // WHICH CENTRES THE LIST COVERS, in the title (Broc, 9 Nov 2026). The
      // dialog reads the centre on screen, the same as the card, so it says so.
      $wkWhere = 'across all centres';
      foreach ($centres as $c) {
          if ((int)$c['id'] === (int)$centreId) $wkWhere = 'at ' . $c['name'];
      } ?>
<div class="modal fade" id="wk-modal" tabindex="-1" aria-labelledby="wk-title">
  <div class="modal-dialog modal-xl modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header flex-wrap gap-2">
        <h5 class="modal-title me-auto" id="wk-title">What's changing <?= $h($wkWhere) ?></h5>
        <?php /* order-last on a phone puts the pair on its own line under the
                 title and the close button; from md it sits between them. See
                 #wk-modal .wk-dates in app.css. */ ?>
        <div class="input-group input-group-sm wk-dates order-last order-md-0">
          <span class="input-group-text">From</span>
          <input type="date" class="form-control px-2" id="wk-from" required
                 aria-label="Changes from" title="Changes on or after this date"
                 min="<?= $h($wkLo) ?>" max="<?= $h($wkHi) ?>"
                 data-lo="<?= $h($wkLo) ?>" data-hi="<?= $h($wkHi) ?>">
          <span class="input-group-text">To</span>
          <input type="date" class="form-control px-2" id="wk-to" required
                 aria-label="Changes to" title="Changes on or before this date"
                 min="<?= $h($wkLo) ?>" max="<?= $h($wkHi) ?>">
        </div>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body" id="wk-body"></div>
      <div class="modal-footer justify-content-between d-none" id="wk-foot"></div>
    </div>
  </div>
</div>

<input type="hidden" id="init-centre" value="<?= (int)$centreId ?>">
<input type="hidden" id="init-year" value="<?= (int)$year ?>">

<?php layout_footer(['/static/js/dashboard.js']); ?>
