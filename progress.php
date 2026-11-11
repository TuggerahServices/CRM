<?php
// IN PROGRESS - every family part-way through enrolling, and what is happening
// with each of them.
//
// THIS IS THE OLD OFFERS SCREEN, WIDENED (10 Aug 2026). Offers was a list of one
// thing: pending offers, soonest to lapse. But an offer is not a separate kind
// of thing - it is a STAGE of a work item. WORK_STAGES contains 'offered', and
// work_link_offer() sets offer_id and advances the item into it. So the module
// was showing one stage of the work layer and calling it the whole subject.
//
// The finding that changed it came from UAT: a user went to Offers looking for
// the work in flight and found only offers. That is the mental model being
// right and the module being drawn too narrow - so the module widened rather
// than a seventh one being added to an already crowded sidebar (Broc, 10 Aug).
//
// WHY THIS SCREEN HAD TO EXIST AT ALL. The work layer was built on 6-7 August
// with a display and a modal and no way in: the Dashboard's table was not
// clickable, there was no group view, closed items were unreachable, and the
// only route to an item was Placement -> the right centre -> the right year ->
// the right room -> the right weekday cell -> Update. Eight steps, every one of
// which needed you to already know where the item was.
//
// ONE LIST, STAGE AS A COLUMN (Broc's call). Not offers in their own block: they
// are the same objects. The risk that buys is that a lapsing offer gets pushed
// down a long page, so work_urgency_sort() puts every hard deadline above every
// soft one - see the comment on that function. Nothing without a clock can ever
// outrank something with one.

declare(strict_types=1);

require_once __DIR__ . '/_incl/auth.php';
$user = auth_require();

require_once __DIR__ . '/_incl/waitlist.php';   // offers_expire_due()
require_once __DIR__ . '/_incl/work.php';
require_once __DIR__ . '/_incl/layout.php';

// Lapse anything past its deadline before rendering, so the page never shows a
// dead offer as live. Same sweep the old Offers screen did on load.
offers_expire_due();

$centres = centres_visible();

// Chased across the whole group by default: a family part-way through enrolling
// is one wherever they are, and "which centre" is a filter on that rather than a
// precondition for asking. This is one of the screens where All centres is a
// real answer, not a fallback.
$centreId = centre_current($centres, true);

// WHICH FILTER, IN THE URL. It used to be browser-only state that started at
// Open on every load, so switching centre - the one thing somebody chasing
// tours across the group does constantly - threw the filter away and answered a
// different question than the one they had asked. It also meant the Dashboard's
// "Open the tour list" could only land on Open, with the tours it had just
// listed nowhere on screen.
//
// So it is a query parameter like the centre and the year: the URL stays
// truthful, back works, the link pastes into Teams, and the centre bar carries
// it across a switch below. `open` is the default and is left OUT of the URL,
// so a plain /progress is the plain list.
$filters = ['open', 'due', 'offered', 'tours', 'completed', 'all'];
$filter  = (string)($_GET['filter'] ?? 'open');
// MINE IS NOT A CHIP ANY MORE (22 Oct 2026, the enrolments meeting: "it seems
// weird to have that as a filter when all the other filters are stages"). It
// answers WHOSE, the chips answer WHICH, so it is a switch beside them that
// narrows whichever chip is on - "my tours", "my follow-ups due". Its own
// parameter, so the two travel in the URL independently. Links made before
// carry ?filter=mine, which still lands on the same rows.
$mine = !empty($_GET['mine']) || $filter === 'mine';
// `overdue` was the chip's old value and the Dashboard's links still say it;
// "Due" is the same list with today included (see work_list()).
if ($filter === 'overdue') $filter = 'due';
if (!in_array($filter, $filters, true)) $filter = 'open';

$params = $filter === 'open' ? [] : ['filter' => $filter];
if ($mine) $params['mine'] = 1;
layout_header('In progress', $user, 'progress', [
    'centres'   => $centres,
    'centre_id' => $centreId,
    'all_label' => user_all_centres() ? 'All centres' : null,
    'params'    => $params,
]);
?>

<div class="page-head">
  <div class="page-head-text">
    <h1 class="h3 mb-1">In progress</h1>
    <p class="text-muted mb-0">
      Families part-way through enrolling: rung, toured, offered, waiting on a
      booking. Most urgent first.
    </p>
  </div>
  <div class="page-head-actions">
    <?php /* THE WAY A TOUR GETS INTO THE APP. A parent rings, and until this
             button existed there was nowhere to put them: work_create() demanded
             a queue application or a Kidsoft child, and an enquirer has neither.
             It lives here rather than on the Waitlist because a tour is not a
             place in the queue - filing one there would hand the family a queue
             position and renumber everybody behind them. */ ?>
    <button type="button" class="btn btn-primary" id="prog-new">
      <i class="fa-solid fa-plus me-1"></i>Book a tour
    </button>
    <a class="btn btn-outline-primary" href="/waitlist<?= $centreId ? '?centre=' . (int)$centreId : '' ?>">
      <i class="fa-solid fa-arrow-left me-1"></i>Open the waitlist
    </a>
  </div>
</div>

<?php /* THERE IS NO YEAR CONTROL, for the reason the Dashboard documents: open
         work is open work, whichever year it targets. A family being chased
         today for a January start is work today, and hiding them behind a year
         stepper is precisely how an item goes quiet unnoticed. The year each
         item targets is a column. */ ?>

<div id="prog-summary" class="row g-3 mb-3"></div>

<?php /* Filters are chips rather than links because they are a view of one list
         rather than a different question, so switching one re-fetches in place
         without a page load. But WHICH ONE IS ON IS IN THE URL (?filter=), the
         same as the centre and the year: it survives a centre switch, it can be
         linked to, and the chip the server renders as checked is the one the
         script starts on. See the block above centre_current(). */ ?>
<div class="d-flex flex-wrap gap-2 align-items-center mb-3" id="prog-filters">
  <div class="btn-group btn-group-sm" role="group" aria-label="Which items to show">
    <input type="radio" class="btn-check" name="pf" id="pf-open" value="open"<?= $filter === 'open' ? ' checked' : '' ?>>
    <label class="btn btn-outline-primary" for="pf-open">Open</label>
    <?php /* DUE, not Overdue (22 Oct 2026): a next step whose date has come,
             TODAY INCLUDED. The reminder lives in the app and nowhere else, so a
             follow-up set for Friday has to be on this list on Friday. */ ?>
    <input type="radio" class="btn-check" name="pf" id="pf-due" value="due"<?= $filter === 'due' ? ' checked' : '' ?>>
    <label class="btn btn-outline-primary" for="pf-due" title="A next step due today or already past">Due</label>
    <input type="radio" class="btn-check" name="pf" id="pf-offers" value="offered"<?= $filter === 'offered' ? ' checked' : '' ?>>
    <label class="btn btn-outline-primary" for="pf-offers">Offers</label>
    <?php /* Filtered SERVER-SIDE like every other chip here, not by hiding rows
             in the browser, so the tiles above count the rows below. A figure
             that contradicts its own caption is the recurring bug this app keeps
             having to fix. */ ?>
    <input type="radio" class="btn-check" name="pf" id="pf-tours" value="tours"<?= $filter === 'tours' ? ' checked' : '' ?>>
    <label class="btn btn-outline-primary" for="pf-tours">Tours</label>
    <?php /* WHERE A FINISHED FAMILY GOES (16 Oct 2026). Broc asked for an item to
             "move to Completed" once Kidsoft enrols the family; it closes as
             Enrolled by itself now (work_close_kidsoft_enrolled()), and this is
             the place to see it went. Newest first - see work_progress_list(). */ ?>
    <input type="radio" class="btn-check" name="pf" id="pf-completed" value="completed"<?= $filter === 'completed' ? ' checked' : '' ?>>
    <label class="btn btn-outline-primary" for="pf-completed">Completed</label>
    <?php /* Closed items were unreachable before this: include_closed existed in
             the engine and the API and nothing in the browser ever passed it, so
             an enrolled or lost family took its contact history with it. */ ?>
    <input type="radio" class="btn-check" name="pf" id="pf-all" value="all"<?= $filter === 'all' ? ' checked' : '' ?>>
    <label class="btn btn-outline-primary" for="pf-all">All, incl. closed</label>
  </div>
  <div class="form-check form-switch mb-0 ms-1" title="Families you look after, and next steps set for you">
    <input class="form-check-input" type="checkbox" role="switch" id="pf-mine"<?= $mine ? ' checked' : '' ?>>
    <label class="form-check-label small" for="pf-mine">Only mine</label>
  </div>
</div>

<div id="prog-list"></div>

<?php /* The same modal the Placement board opens - one definition, in
         _incl/work-modal.php, behaviour in static/js/work-modal.js. */ ?>
<?php require __DIR__ . '/_incl/work-modal.php'; ?>

<?php /* ===================== BOOKING A TOUR =====================
     A parent who rings to book a tour has applied for nothing: no waitlist
     application, no Kidsoft child record (Kidsoft issues a ChildID only when an
     application converts to an enrolment). So this is the ONLY screen in the app
     that creates a family from nothing, and it asks for the least that makes the
     row useful afterwards: who is coming, which centre, when, and one way to
     ring them back.

     NO YEAR AND NO ROOM, on purpose (Broc, 14 Aug 2026). Somebody ringing in
     August about "sometime next year" has not decided either, and a required
     field that nobody can answer gets filled with a guess that then reads as
     fact. Both are set later, from the cell, when there is a place in mind.
     ============================================================ */ ?>
<div class="modal fade" id="enq-modal" tabindex="-1">
  <?php /* SCROLLABLE since the queue check (14 Oct 2026): with two matching
           children listed the dialog ran past the bottom of an 800px screen and
           took Save and "None of these" with it - the fault the work modal was
           rebuilt for on 18 Sep. */ ?>
  <div class="modal-dialog modal-dialog-scrollable">
    <form class="modal-content" id="enq-form">
      <div class="modal-header">
        <div>
          <h5 class="modal-title mb-0">Book a tour</h5>
          <div class="small text-muted">Somebody who has rung about a place.</div>
        </div>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div class="row g-3">
          <div class="col-md-7">
            <label class="form-label" for="enq-child">Child</label>
            <input class="form-control" name="child_name" id="enq-child" maxlength="160" required>
          </div>
          <div class="col-md-5">
            <label class="form-label" for="enq-centre">Centre</label>
            <select class="form-select" name="centre_id" id="enq-centre" required>
              <?php foreach ($centres as $c): ?>
                <option value="<?= (int)$c['id'] ?>"<?= (int)$c['id'] === (int)$centreId ? ' selected' : '' ?>>
                  <?= htmlspecialchars($c['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-5">
            <label class="form-label" for="enq-parent">Parent</label>
            <input class="form-control" name="contact_name" id="enq-parent" maxlength="160">
          </div>
          <div class="col-md-3">
            <label class="form-label" for="enq-phone">Phone</label>
            <input class="form-control" name="contact_phone" id="enq-phone" maxlength="40">
          </div>
          <div class="col-md-4">
            <label class="form-label" for="enq-email">Email</label>
            <input class="form-control" name="contact_email" id="enq-email" maxlength="190">
          </div>
          <div class="col-12">
            <div class="form-text" id="enq-reach">A phone number or an email, so somebody can ring them back.</div>
          </div>
          <div class="col-md-7">
            <label class="form-label" for="enq-tour">Tour</label>
            <input type="datetime-local" class="form-control" name="tour_at" id="enq-tour">
            <div class="form-text">Leave it empty to record the enquiry without a tour.</div>
            <?php /* "Send the invite to the parent" (29 Oct 2026) - window.tourInviteBox(),
                     tour-calendar.php. To the email typed above, or the family's own
                     when they turn out to be in the queue already. */ ?>
            <div id="enq-tour-invite"></div>
          </div>
          <div class="col-12">
            <label class="form-label" for="enq-note">What they said</label>
            <input class="form-control" name="note" id="enq-note" maxlength="500">
          </div>
        </div>
        <?php /* ALREADY IN THE QUEUE? Filled by progress.js from the server's
                 `queue_matches` when the phone or email typed above belongs to an
                 open application at this centre. Both enquiries ever booked
                 before this existed were families already in a queue - see
                 work_queue_matches(). */ ?>
        <div class="alert alert-warning mt-3 mb-0 d-none" id="enq-matches"></div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Close</button>
        <button type="submit" class="btn btn-primary">Save</button>
      </div>
    </form>
  </div>
</div>

<input type="hidden" id="init-centre" value="<?= (int)$centreId ?>">

<?php layout_footer(['/static/js/work-modal.js', '/static/js/progress.js']); ?>
