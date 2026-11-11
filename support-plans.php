<?php
// SUPPORT PLANS - every child on one, where the process has got to, and what is
// being held for them.
//
// Asked for by a centre on 10 Sep 2026, in the same message that asked how to
// hold a child's Friday: "Maybe even a tab on the right for children on BSP
// might be good for administrators to monitor. Or even for supervisors to make
// sure they are following through all the steps and communicating with families
// correctly."
//
// A SCREEN RATHER THAN A PANEL, and a seventh item in a sidebar that has said
// "no seventh item" twice (Broc, 10 Sep 2026): "The BSP is almost like an 'in
// progress' child. The child has been removed from care pending an improvement
// in behaviour. There are specific follow-ups and tasks that need to be
// undertaken by the staff for that child. Just like before, managers should be
// able to see all BSP children, while supervisors should only see their centre."
//
// THE SECOND HALF OF THAT ASK LANDED 17 SEP 2026. "Following through all the
// steps" could not be built until somebody said what the steps were, and Emily
// Kite provided both process documents that day - Behaviour Support Plan and
// Individual Support Plan, kept verbatim in _incl/docs/support_plans/. The
// ladder on each card is a transcription of them and nothing else; see
// _incl/support-plan-steps.php, which holds the only copy of the step list.
//
// TWO PLANS THAT RUN TOGETHER. A child with a diagnosed disability is on an ISP
// for the whole of their enrolment, and may be put on a BSP alongside it if
// behaviours arise - the ISP document is explicit that one does not replace the
// other. So there are two flags on the placement and a card can carry two
// ladders.

declare(strict_types=1);

require_once __DIR__ . '/_incl/auth.php';
$user = auth_require();

require_once __DIR__ . '/_incl/support-plans.php';
require_once __DIR__ . '/_incl/layout.php';

$centres = centres_visible();

// Group by default, like In progress and for the same reason: a child on a plan
// is one wherever they are, and "which centre" is a filter on that rather than a
// precondition for asking. centre_current() refuses 0 for a supervisor, who is
// pinned to one of their own centres.
$centreId = centre_current($centres, true);

layout_header('Support plans', $user, 'support', [
    'centres'   => $centres,
    'centre_id' => $centreId,
    'all_label' => user_all_centres() ? 'All centres' : null,
]);
?>

<div class="page-head">
  <div class="page-head-text">
    <h1 class="h3 mb-1">Support plans</h1>
    <p class="text-muted mb-0">
      Children on a Behaviour Support Plan or an Individual Support Plan: where the
      process has got to, the days being held for them, and when the family was
      last spoken to. Furthest behind, first.
    </p>
  </div>
  <div class="page-head-actions">
    <?php /* The report for the tab on screen - a centre, or All centres. One
             child's report is the Print button on their card. */ ?>
    <a class="btn btn-outline-secondary" href="/support-plans-report?centre=<?= (int)$centreId ?>">
      <i class="fa-solid fa-print me-1"></i>Print report
    </a>
    <a class="btn btn-outline-primary" href="/placement<?= $centreId ? '?centre=' . (int)$centreId : '' ?>">
      <i class="fa-solid fa-people-roof me-1"></i>Open the placement board
    </a>
  </div>
</div>

<?php /* THERE IS NO YEAR CONTROL, the reason In progress and the Dashboard both
         document: a plan is being worked NOW, whichever year the child's
         placement row happens to be against, and a year stepper is precisely how
         a child goes quiet unnoticed. The year each row targets is a column. */ ?>

<div id="sp-summary" class="row g-3 mb-3"></div>

<?php /* Kidsoft refusing to answer for a centre-year changes whether the day
         strips can be trusted at all, which is the one thing the team cannot
         know unaided - so it is a banner and not a caption. */ ?>
<div id="sp-notices"></div>

<div id="sp-list"></div>

<?php /* ONE PLAN, ONE MODAL.
         The ladder used to expand inline on the card. It was 16 steps with a
         paragraph each, which ran a single card to about 1700px and pushed the
         other children off the screen - and the ORDERING of this list is its
         whole feature, so a card that swallows the viewport defeats the screen.
         The card now answers "where is this up to" in one line and this answers
         everything else.

         IT ALSO COLLAPSED A LEVEL. There used to be three: the card, the inline
         ladder, and a dialog for one step. The step editor now opens inside this
         modal, and so does the outcome form as a second pane - a modal stacked on
         a modal is a focus trap sitting on another focus trap, and neither
         Bootstrap nor the reader keeps their place.

         SCROLLABLE BODY, so the header keeps saying which child while the steps
         scroll and the footer keeps the outcome button in reach. */ ?>
<div class="modal fade" id="sp-plan-modal" tabindex="-1">
  <div class="modal-dialog modal-lg modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <div>
          <h5 class="modal-title mb-0" id="sp-plan-title"></h5>
          <div class="small text-muted" id="sp-plan-subtitle"></div>
        </div>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">

        <?php /* Pane 1: where the plan is up to, and every step. */ ?>
        <div id="sp-plan-pane">
          <?php /* The state line and the stage strip. */ ?>
          <div id="sp-plan-summary"></div>
          <?php /* ONE STAGE, plus Back and Next. The strip above jumps anywhere;
                   these walk. What is deliberately NOT tracked is rendered with
                   the stage it belongs to rather than as a permanent footnote. */ ?>
          <div id="sp-plan-steps"></div>
          <?php /* The ISP's one date, typed from the plan's own timeframe. */ ?>
          <div id="sp-plan-review"></div>
        </div>

        <?php /* Pane 2: how it ended. A PANE AND NOT A SECOND MODAL.
                 Three of the five ways a BSP ends fire before week 10 - the child
                 improves, the child is released over a risk of significant harm,
                 or the family ceases care - so this is never gated on reaching the
                 bottom of the ladder. */ ?>
        <div id="sp-outcome-pane" class="d-none">
          <p class="small text-muted" id="sp-outcome-lead"></p>
          <div class="mb-3" id="sp-outcome-choices"></div>
          <div class="mb-3">
            <label class="form-label" for="sp-outcome-on">Ended on</label>
            <input type="date" class="form-control" id="sp-outcome-on">
          </div>
          <div class="mb-2">
            <label class="form-label" for="sp-outcome-note">Note</label>
            <textarea class="form-control" id="sp-outcome-note" rows="3" maxlength="500"></textarea>
          </div>
          <?php /* Held days are NOT released when a plan closes. Putting a place
                   back on sale has a family at the end of it, and doing it as a
                   side effect of recording an outcome is how a place gets sold out
                   from under somebody. Named here so the person closing the plan
                   knows to go and deal with it. */ ?>
          <div class="alert alert-warning py-2 small d-none" id="sp-outcome-held"></div>
        </div>
      </div>

      <div class="modal-footer">
        <div id="sp-foot-plan" class="d-flex flex-wrap gap-2 w-100 align-items-center">
          <?php /* BACK AND NEXT LIVE IN THE FOOTER, not under the page. A page
                   whose content runs past the fold put the wizard's own
                   navigation below it, which is the one thing that must always
                   be reachable. The footer does not scroll. */ ?>
          <div id="sp-foot-nav" class="d-flex align-items-center gap-2 me-auto"></div>
          <button type="button" class="btn btn-outline-primary btn-sm" id="sp-open-outcome"></button>
          <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Close</button>
        </div>
        <div id="sp-foot-outcome" class="d-none d-flex flex-wrap gap-2 w-100 align-items-center">
          <button type="button" class="btn btn-outline-secondary btn-sm me-auto" id="sp-outcome-back">Back to the steps</button>
          <button type="button" class="btn btn-primary btn-sm" id="sp-outcome-save">Record the outcome</button>
        </div>
      </div>
    </div>
  </div>
</div>

<?php /* THE CONTACT LOG FOR A CHILD WHO IS ALREADY ENROLLED, which the app has
         been able to store since the log was built and had never once offered:
         api/comms takes a placement_id, and the only screen that ever called it
         passed a waitlist_id.

         The same shape the family modal uses, and the same endpoint - a second
         way to write a contact note would be two implementations of one record.

         NOTES FROM THIS SCREEN DO NOT TRAVEL TO KIDSOFT (Broc, 17 Sep 2026).
         createComment has no update and no delete, and behaviour and diagnosis
         material written permanently onto a child's Kidsoft record is a
         different risk from an ordinary contact note. The screen posts
         local_only and says so before the note is typed. */ ?>
<div class="modal fade" id="sp-comm-modal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header">
        <div>
          <h5 class="modal-title mb-0">Contact history</h5>
          <div class="small text-muted" id="sp-comm-subtitle"></div>
        </div>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <form id="sp-comm-form" class="border rounded p-3 mb-3 bg-body-tertiary">
          <input type="hidden" name="placement_id">
          <input type="hidden" name="local_only" value="1">
          <div class="row g-2 align-items-end">
            <div class="col-md-3">
              <label class="form-label small mb-1">Direction</label>
              <select class="form-select form-select-sm" name="direction">
                <option value="outbound">We contacted them</option>
                <option value="inbound">They contacted us</option>
                <option value="internal">Internal note</option>
              </select>
            </div>
            <div class="col-md-3">
              <label class="form-label small mb-1">Channel</label>
              <select class="form-select form-select-sm" name="channel">
                <option value="phone">Phone</option>
                <option value="email">Email</option>
                <option value="sms">SMS</option>
                <option value="in_person">In person</option>
                <option value="other">Other</option>
              </select>
            </div>
            <div class="col-md-3">
              <label class="form-label small mb-1">When</label>
              <input type="date" class="form-control form-control-sm" name="occurred_at">
            </div>
            <div class="col-md-3">
              <button type="submit" class="btn btn-primary btn-sm w-100">Log contact</button>
            </div>
            <div class="col-12">
              <label class="form-label small mb-1">Summary</label>
              <input class="form-control form-control-sm" name="summary" maxlength="255" required>
            </div>
            <div class="col-12">
              <textarea class="form-control form-control-sm" name="body" rows="2"
                        placeholder="Detail (optional)"></textarea>
            </div>
          </div>
        </form>
        <?php /* Said BEFORE the note is typed rather than after it is saved. */ ?>
        <div id="sp-comm-note" class="small d-none mb-3"></div>
        <div id="sp-comm-list"></div>
        <?php /* Kidsoft's own comments, kept SEPARATE from ours - most are form
                 capture rather than contact history, and merging the two would
                 bury what the team did behind what a form recorded. */ ?>
        <div id="sp-comm-kidsoft" class="d-none">
          <h6 class="text-muted small text-uppercase mt-4 mb-2">Held in Kidsoft</h6>
          <div id="sp-comm-kidsoft-list"></div>
        </div>
      </div>
    </div>
  </div>
</div>

<?php /* HOLDING A DAY FOR A CHILD THE BOARD CANNOT SHOW.
         The placement board records a hold from the empty box on the room
         roster, which works for a child who has dropped ONE day - they are still
         on the roster, so the box exists. A child REMOVED FROM CARE pending an
         improvement in behaviour has no bookings at all, so they are on no
         roster and there is no box: the board cannot offer the control, and this
         screen is the only place they still appear.

         It serves an ISP just as well. A child waiting on a specialist
         appointment may drop days while that is arranged (Broc, 17 Sep 2026),
         which is the same fact about the same place and therefore the same
         record - day_holds was never gated on the behaviour flag.

         SAME ENDPOINT, /api/board hold_save, which owns the checks. A second way
         to write a hold would be two implementations of one record. */ ?>
<div class="modal fade" id="sp-hold-modal" tabindex="-1">
  <div class="modal-dialog">
    <form class="modal-content" id="sp-hold-form">
      <div class="modal-header">
        <div>
          <h5 class="modal-title mb-0">Days held for this child</h5>
          <div class="small text-muted" id="sp-hold-subtitle"></div>
        </div>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <input type="hidden" name="room_id">
        <input type="hidden" name="year">
        <input type="hidden" name="kidsoft_child_id">
        <input type="hidden" name="placement_id">
        <p class="text-muted small">
          A held day is a place nobody else can be offered. It comes off the free
          figure on the placement board and stays held until somebody releases it,
          or until the booking is back in Kidsoft.
        </p>
        <div class="mb-3">
          <label class="form-label">Days held for them</label>
          <div class="d-flex flex-wrap gap-2" id="sp-hold-days"></div>
          <?php /* Only days they are not booked on. A day they attend is already
                   held by the booking, and counting ours as well would take a
                   second place off the same cell. The API refuses it too. */ ?>
          <div class="form-text" id="sp-hold-dayhelp"></div>
        </div>
        <div class="mb-3">
          <label class="form-label" for="sp-hold-reason">Why the place is being held</label>
          <textarea class="form-control" name="note" id="sp-hold-reason" rows="3" maxlength="500"></textarea>
          <div class="form-text">Read by anyone who opens that cell and asks why it is not for sale.</div>
        </div>
        <div class="mb-2">
          <label class="form-label" for="sp-hold-since">Held since</label>
          <input type="date" class="form-control" name="requested_on" id="sp-hold-since">
          <div class="form-text">The day the booking came off, not the day this was typed in.</div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-danger me-auto d-none" id="sp-hold-clear">Stop holding these days</button>
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="submit" class="btn btn-primary">Save</button>
      </div>
    </form>
  </div>
</div>

<input type="hidden" id="init-centre" value="<?= (int)$centreId ?>">

<?php layout_footer(['/static/js/support-plans.js']); ?>
