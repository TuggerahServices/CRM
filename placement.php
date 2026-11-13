<?php
// PLACEMENT - the room x weekday board. Served at /placement (moved 9 Aug 2026
// from /, which now sends people to the Dashboard; index.php forwards any old
// deep-link carrying a room or weekday straight back here).
//
// It opens on a whole-organisation overview (every centre's fill for the year,
// emptiest first). Drilling into a centre gives the CELL GRID: rooms down,
// weekdays across, and in each room x weekday cell everything that was
// previously spread across four separate screens --
//
//   what we plan       (placements)          was: Board
//   what Kidsoft holds (bookings)            was: Occupancy
//   where those differ (drift)               was: Drift
//   who could fill it  (queue candidates)    was: Fill
//
// CLAUDE.md has always said the unit of work is a room x weekday cell. Splitting
// that cell across four tabs, one per data source, meant answering "Friday is
// empty in AA Purple, who can take it?" took four screens that could disagree
// with each other about the same cell. The engine behind this is _incl/cells.php.
//
// The children on this grid are read STRAIGHT FROM KIDSOFT (see
// _incl/board-live.php); our tables hold only what Kidsoft has no field for.
// Every mutation goes to the endpoint that owns it and its checks:
// /api/board (annotations), /api/waitlist (offers).

declare(strict_types=1);

require_once __DIR__ . '/_incl/auth.php';
$user = auth_require();

require_once __DIR__ . '/_incl/board.php';
require_once __DIR__ . '/_incl/layout.php';

$centres  = centres_visible();

$h = static fn($v) => htmlspecialchars((string)$v, ENT_QUOTES);

// Which centre is app-level state now, held by the centre bar in the chrome
// rather than a <select> on this screen. 0 = the whole-organisation overview.
$centreId = centre_current($centres, true);
// Remembered across screens, like the centre. board_default_year() is the year
// the team is planning, and every screen now agrees on it.
$year     = year_current(board_default_year());

// THE BOARD AS AT A DATE (16 Oct 2026) - see board_from(). A chosen date decides
// the year and is remembered as the year, exactly as the stepper's choice is; the
// date itself is NOT remembered - it lives in the URL, so the next visit reads as
// today. A date outside the years the board holds is dropped here (the endpoint
// would refuse it in words) rather than sending somebody to a page that fails.
[$fromMin, $fromMax] = board_from_bounds();
$fromChosen = board_parse_from($_GET['from'] ?? null);
if ($fromChosen !== null && ($fromChosen < $fromMin || $fromChosen > $fromMax)) $fromChosen = null;
if ($fromChosen !== null) {
    $year = (int)substr($fromChosen, 0, 4);
    $_SESSION['year'] = $year;
}
// What the box shows: the chosen date, or the one the board counts from anyway
// (today, or 1 January ahead). Empty for a finished year, which reads off a
// month rather than a date.
$fromShown = board_from($year, $fromChosen) ?? '';
$centre   = null;
foreach ($centres as $c) if ((int)$c['id'] === $centreId) $centre = $c;

layout_header('Placement', $user, 'board', [
    'centres'   => $centres,
    'centre_id' => $centreId,
    'all_label' => user_all_centres() ? 'All centres' : null,
    'year'      => $year,
    // A chosen date travels with a centre switch, like the year does.
    'params'    => $fromChosen !== null ? ['from' => $fromChosen] : [],
    // A CENTRE'S GRID IS WORTH SEEING WHOLE, so this screen - and only this
    // screen - asks for tighter vertical rhythm from the shell. Tuggerah is
    // eleven rooms plus a totals row and it did not fit any window the team has.
    // The overview is a list of centres and scrolls like any other page, so it
    // does not ask. See the "seeing a whole centre at once" block in app.css.
    'main_class' => $centreId ? 'board-tight' : '',
]);
?>

<div class="page-head">
  <div class="page-head-text">
    <h1 class="h3 mb-1"><?= $centre ? $h($centre['name']) : 'Placement board' ?></h1>
    <?php /* NO SUBTITLE ON A CENTRE (16 Sep 2026). It read "Every room, every
             day. Click a cell to see who is in it and who could be", and the
             legend under the grid already ends with "Click any cell to see the
             children enrolled in that room on that day" - the same sentence,
             said twice on one screen, 24px above the one table here that is
             worth seeing whole. The overview keeps its line: that screen is a
             list of centres and has no legend to say it instead. */ ?>
    <?php if (!$centreId): ?>
      <p class="text-muted mb-0">Every centre, every day. Fill the gaps, top to bottom.</p>
    <?php endif; ?>
  </div>
  <?php /* THE YEAR IS THE ONLY AXIS. There was a week picker here; it navigated
           a dimension the numbers do not vary on - a placement carries no start
           or end date, so every one of a centre's room x weekday cells reads
           identically in every week of the year. A place is sold for the year,
           so the year is what you steer. */ ?>
  <div class="page-head-actions">
    <?php layout_year_nav('year-nav'); ?>
    <?php /* THE BOARD AS AT A DATE (16 Oct 2026, Broc). Not a week picker come
             back: the board still counts every place held from this date to
             31 December - the year's rule, started somewhere other than today.
             Picking one NAVIGATES, like the year arrows, and stepping the year
             drops it. Bounded to the stepper's years. See board_from(). */ ?>
    <div class="input-group board-from">
      <label class="input-group-text" for="board-from">From</label>
      <input type="date" class="form-control" id="board-from" value="<?= $h($fromShown) ?>"
             min="<?= $h($fromMin) ?>" max="<?= $h($fromMax) ?>"
             title="Read the board from this date: who holds each place from then to 31 December.">
      <?php if ($fromChosen !== null): ?>
        <button type="button" class="btn btn-outline-secondary" id="board-from-clear" title="Clear the date">
          <i class="fa-solid fa-xmark"></i><span class="visually-hidden">Clear the date</span>
        </button>
      <?php endif; ?>
    </div>
    <?php if ($centreId): ?>
      <button class="btn btn-outline-secondary" id="refresh"
              title="Re-read every month of this year from Kidsoft. Takes around half a minute.">
        <i class="fa-solid fa-rotate"></i><span class="visually-hidden">Refresh from Kidsoft</span>
      </button>
    <?php endif; ?>
  </div>
</div>

<?php /* THE FOUR TILES, TIGHTENED - see the "seeing a whole centre at once"
         block in app.css. Label and figure share a line and the caption takes
         its own; nothing is dropped. Opted into here rather than being global,
         because every other screen's tiles sit above a dozen rows in a card and
         are better for the air. */ ?>
<?php /* gx-3 with NO gy: one row of tiles has nothing below it to be gutted
         from, and Bootstrap's vertical gutter was 8px of pure waste
         directly above the grid. */ ?>
<div id="board-summary" class="row gx-3 mb-3 tiles-tight"></div>

<?php /* Which of the two views this page is depends on the centre bar, which is
         a link, so the server already knows. Only the container in use is
         rendered - the JS no longer has to keep two hidden shells in step. */ ?>
<?php if (!$centreId): ?>
  <!-- Whole-organisation overview: every centre's fill for the year. -->
  <div id="overview"></div>
<?php else: ?>
  <!-- Anything that needs saying about the whole year: Kidsoft unreachable, a
       year not enrolled yet, or a backlog of children attending but unplaced. -->
  <div id="year-notices"></div>
  <!-- One centre's cell grid: rooms down, weekdays across, for the year. -->
  <div id="grid"></div>
  <div class="d-flex flex-wrap gap-3 mt-3 small text-muted align-items-center grid-tight-legend" id="grid-legend"></div>
<?php endif; ?>

<!-- Cell detail. One room, one weekday: who is placed, what Kidsoft holds,
     what disagrees, and who is waiting who could take a free place. -->
<div class="offcanvas offcanvas-end" tabindex="-1" id="cell-panel" style="width:min(560px,100vw)">
  <div class="offcanvas-header border-bottom">
    <div>
      <h5 class="offcanvas-title mb-0" id="cell-title">Cell</h5>
      <div class="small text-muted" id="cell-subtitle"></div>
    </div>
    <?php /* Shown only when the cell was opened from the room sheet's day head. */ ?>
    <button type="button" class="btn btn-sm btn-outline-secondary ms-auto me-2 d-none" id="cell-back">
      <i class="fa-solid fa-table-list me-1"></i>Whole room</button>
    <button type="button" class="btn-close" data-bs-dismiss="offcanvas"></button>
  </div>
  <div class="offcanvas-body" id="cell-body"></div>
</div>

<?php /* THE ROOM SHEET (13 Nov 2026): one room, every child, all five weekdays,
         opened from the room's name on the grid. It was drawn into the cell panel
         above until then; Broc asked for a dialog that says more about the room
         across the week - who is enrolled, and everybody else whose name belongs
         on that sheet: offered, waiting on paperwork, moving rooms, ending care,
         swapping and dropping days. Rendered by renderRoom() in board.js from the
         board already on the page; nothing is fetched. See placement-board.md. */ ?>
<div class="modal fade" id="room-modal" tabindex="-1" aria-labelledby="rv-title">
  <div class="modal-dialog modal-dialog-scrollable modal-fullscreen-lg-down rv-dialog">
    <div class="modal-content">
      <div class="modal-header rv-head">
        <div class="rv-heading">
          <h5 class="modal-title" id="rv-title">Room</h5>
          <div class="rv-subtitle" id="rv-subtitle"></div>
        </div>
        <div class="btn-group btn-group-sm rv-steps" role="group" aria-label="Other rooms">
          <button type="button" class="btn btn-outline-secondary rv-step" data-step="-1">
            <i class="fa-solid fa-chevron-left"></i><span class="visually-hidden">Previous room</span></button>
          <button type="button" class="btn btn-outline-secondary rv-step" data-step="1">
            <i class="fa-solid fa-chevron-right"></i><span class="visually-hidden">Next room</span></button>
        </div>
        <button type="button" class="btn-close ms-2" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="rv-top" id="rv-top"></div>
      <div class="modal-body rv-body" id="rv-body"></div>
      <div class="modal-footer rv-foot">
        <div class="rv-keys" id="rv-foot-key"></div>
        <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Close</button>
      </div>
    </div>
  </div>
</div>

<!-- Placement editor -->
<div class="modal fade" id="place-modal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <form class="modal-content" id="place-form">
      <div class="modal-header">
        <h5 class="modal-title">Child</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <input type="hidden" name="id">
        <input type="hidden" name="room_id">
        <input type="hidden" name="year">
        <input type="hidden" name="kidsoft_child_id">

        <?php /* The child is PICKED from Kidsoft, not typed. Kidsoft owns the
                 name and DOB; typing them is what created every drift this app
                 has had to reconcile. Manual entry survives only for a child
                 who is not in Kidsoft yet. */ ?>
        <?php /* A COMBOBOX, not an inline list. The results used to be a
                 .list-group rendered in the flow of the form: 25 three-line
                 items pushed Days, Status, Room and Flags off the bottom of the
                 modal, so the one screen you came here to fill in was the part
                 you could not see. The menu now OVERLAYS the form, so the
                 modal is the same height whether the picker is open or shut. */ ?>
        <div id="child-picker" class="mb-3">
          <label class="form-label" for="child-search">Child</label>
          <div class="position-relative" id="child-combo">
            <input class="form-control" id="child-search" autocomplete="off"
                   role="combobox" aria-expanded="false" aria-autocomplete="list"
                   aria-controls="child-results"
                   placeholder="Start typing a name to find them in Kidsoft...">
            <div id="child-results" class="dropdown-menu w-100" role="listbox"
                 aria-label="Children at this centre"></div>
          </div>
          <div class="form-text">Kidsoft supplies the name, date of birth and age.</div>
        </div>

        <?php /* Shown once a child is chosen: read-only proof of who this is. */ ?>
        <div id="child-chosen" class="alert alert-light border d-none mb-3">
          <div class="d-flex justify-content-between align-items-start gap-2">
            <div>
              <div class="fw-semibold" id="chosen-name"></div>
              <div class="small text-muted" id="chosen-meta"></div>
            </div>
            <button type="button" class="btn btn-sm btn-outline-secondary" id="chosen-clear">Change</button>
          </div>
        </div>

        <?php /* Fallback for a child pencilled in ahead of Kidsoft. 11 such
                 children sit on the 2026 Tuggerah board today. */ ?>
        <div class="row g-3 d-none" id="manual-name">
          <div class="col-12">
            <div class="alert alert-warning py-2 small mb-0">
              Not in Kidsoft yet, so this row stays <strong>provisional</strong>: the name is typed
              and must be linked later. Prefer the picker whenever the child exists in Kidsoft.
            </div>
          </div>
          <div class="col-md-5">
            <label class="form-label">First name</label>
            <input class="form-control" name="child_first_name" maxlength="80">
          </div>
          <div class="col-md-4">
            <label class="form-label">Last name</label>
            <input class="form-control" name="child_last_name" maxlength="80">
          </div>
          <div class="col-md-3">
            <label class="form-label">Date of birth</label>
            <input type="date" class="form-control" name="child_dob">
            <div class="form-text">Used for age checks.</div>
          </div>
        </div>

        <div class="mb-3">
          <button type="button" class="btn btn-sm btn-link p-0" id="toggle-manual">
            This child is not in Kidsoft yet
          </button>
        </div>

        <div class="row g-3">

          <?php /* KIDSOFT OWNS THESE THREE, so they are only offered for a
                   PROVISIONAL child - one Kidsoft has never heard of, who is
                   entirely ours. For a linked child the board derives days, room
                   and status from Kidsoft, and editing them here could only
                   disagree with it. See openPlacement() in board.js. */ ?>
          <div class="col-12 pf-ours">
            <label class="form-label">Days</label>
            <div class="d-flex flex-wrap gap-2" id="day-toggles">
              <?php foreach (WEEKDAYS as $wd => $label): ?>
                <input type="checkbox" class="btn-check" name="days[]" value="<?= $wd ?>" id="day-<?= $wd ?>">
                <label class="btn btn-outline-primary" for="day-<?= $wd ?>"><?= $h($label) ?></label>
              <?php endforeach; ?>
            </div>
          </div>

          <div class="col-md-6 pf-ours">
            <label class="form-label">Status</label>
            <select class="form-select" name="status">
              <?php foreach (PLACEMENT_STATUS as $v => $label): ?>
                <option value="<?= $h($v) ?>"><?= $h($label) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-6 pf-ours">
            <label class="form-label">Room</label>
            <select class="form-select" name="room_id_select"></select>
            <div class="form-text">Moving a child here moves them on the board.</div>
          </div>

          <div class="col-12">
            <label class="form-label">Flags</label>
            <?php /* TWO ROWS, and the break is the grouping. The first three are
                     things that are TRUE OF THE CHILD; the support plans are a
                     different kind of thing and get their own line. As one
                     wrapping row of five they were undifferentiated and ragged -
                     72, 107, 182, 192 and 187px wide - so nothing lined up and
                     Individual Support Plan was orphaned on a line of its own. */ ?>
            <div class="place-flags">
              <div class="place-flags-row">
                <div class="form-check">
                  <input class="form-check-input" type="checkbox" name="is_sibling" value="1" id="f-sib">
                  <label class="form-check-label" for="f-sib">Sibling</label>
                </div>
                <div class="form-check">
                  <input class="form-check-input" type="checkbox" name="is_staff_child" value="1" id="f-staff">
                  <label class="form-check-label" for="f-staff">Staff's child</label>
                </div>
                <div class="form-check">
                  <input class="form-check-input" type="checkbox" name="needs_two_educators" value="1" id="f-two">
                  <label class="form-check-label" for="f-two">Needs two educators</label>
                </div>
              </div>
              <?php /* TWO PLANS ON ONE LINE, AND A CHILD CAN BE ON BOTH. The ISP
                       process document is explicit that a Behaviour Support Plan
                       runs ALONGSIDE an Individual Support Plan rather than
                       replacing it, so these are two independent ticks and never
                       a choice of one. Both feed /support-plans. */ ?>
              <div class="place-flags-row">
                <div class="form-check">
                  <input class="form-check-input" type="checkbox" name="has_behaviour_plan" value="1" id="f-beh">
                  <label class="form-check-label" for="f-beh">Behaviour Support Plan</label>
                </div>
                <div class="form-check">
                  <input class="form-check-input" type="checkbox" name="has_individual_plan" value="1" id="f-isp">
                  <label class="form-check-label" for="f-isp">Individual Support Plan</label>
                </div>
              </div>
            </div>
          </div>

          <div class="col-12">
            <label class="form-label">Notes</label>
            <input class="form-control" name="notes" maxlength="500">
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-danger me-auto d-none" id="place-delete">Remove from board</button>
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="submit" class="btn btn-primary">Save</button>
      </div>
    </form>
  </div>
</div>

<?php /* THE OFFER DIALOG IS GONE (15 Oct 2026). Offers are sent in Kidsoft, and the
         board reads them back and holds their days - see KIDSOFT_OPEN_OFFER in
         _incl/board.php. */ ?>

<?php /* The work-item modal is shared with the In progress module - one copy,
         in _incl/work-modal.php. It used to live inline here, which made this
         page the only way to reach a work item at all. */ ?>
<?php require __DIR__ . '/_incl/work-modal.php'; ?>

<?php /* The family modal, shared with the Waitlist queue. "Could take this day"
         is a shortlist of families in that queue, and every action offered on
         one of those cards - ring them, offer the place, start them enrolling -
         is better taken by somebody who has read what was already said to
         them. POA_LABELS comes from _incl/waitlist.php, which board.php does not
         pull in. */ ?>
<?php require_once __DIR__ . '/_incl/waitlist.php'; ?>
<?php require __DIR__ . '/_incl/waitlist-modal.php'; ?>

<?php /* The child dialog (window.openChild) is NOT included here: it is chrome,
         rendered by layout_footer() on every page with child-modal.js, because
         the search palette opens it too. Including it here as well would put two
         #ch-modal elements on this page and bind its Save twice. */ ?>

<!-- The internal day waitlist. A family already in the room has asked for a day
     that is not free yet. Kidsoft has nowhere to put this: its waiting list is
     for permanent positions, and its waitlisted bookings are per-date requests a
     parent raised in the portal, so a phone call asking for "a Wednesday when
     one comes free" leaves no record anywhere. See _incl/internal-requests.php.

     There is no status field and no stage: a wish is DEMAND, not work. It sits
     on its cell until the day comes free, and it is granted by Kidsoft showing
     the child booked on that day - never by somebody ticking it here. -->
<?php /* A DAY THIS CHILD IS NOT BOOKED ON, which turns out to be two opposite
         facts and not one (10 Sep 2026). The roster's empty boxes already
         recorded a WISH - the family is waiting for that day. A centre then
         asked the other question: "he is on a Behaviour Support Plan but has
         dropped the Friday... how do we hold his Friday?" That is the reverse.
         A wish adds DEMAND and lights the cell green when a place comes free; a
         hold takes the place OFF SALE so nobody can be offered it.

         ONE DIALOG, because it is one gesture on one box and the team should
         not have to know which of two controls they wanted before they click.
         The two options are spelled out in full rather than named, because the
         consequence is the part that matters and the labels alone would not
         carry it. See _incl/day-holds.php. */ ?>
<div class="modal fade" id="wish-modal" tabindex="-1">
  <div class="modal-dialog">
    <form class="modal-content" id="wish-form">
      <div class="modal-header">
        <div>
          <h5 class="modal-title mb-0" id="wish-title">A day they are not booked on</h5>
          <div class="small text-muted" id="wish-subtitle"></div>
        </div>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <input type="hidden" name="room_id">
        <input type="hidden" name="kidsoft_child_id">
        <input type="hidden" name="placement_id">
        <input type="hidden" name="id">

        <div class="mb-3 wish-kind">
          <div class="form-check">
            <input class="form-check-input" type="radio" name="kind" value="wish" id="k-wish" checked>
            <label class="form-check-label" for="k-wish">
              <span class="fw-semibold">The family is waiting for this day</span>
              <span class="d-block small text-muted">The place stays on sale. The cell
                turns green the moment one comes free, and they are first in line for it.</span>
            </label>
          </div>
          <div class="form-check">
            <input class="form-check-input" type="radio" name="kind" value="hold" id="k-hold">
            <label class="form-check-label" for="k-hold">
              <span class="fw-semibold">We are holding this day for them</span>
              <span class="d-block small text-muted">The place is theirs and comes off the
                free figure, so nobody else can be offered it. It stays held until somebody
                releases it, or until the booking is back in Kidsoft.</span>
            </label>
          </div>
        </div>

        <div class="mb-3">
          <label class="form-label" id="wish-days-label">Days they are waiting for</label>
          <div class="d-flex flex-wrap gap-2" id="wish-days"></div>
          <?php /* Only the days this child does NOT already attend are offered.
                   A day they are in the room for is neither a wish nor a hold:
                   the booking already holds it, and recording ours as well would
                   take a second place off the same cell. The API refuses it too,
                   not only the form. */ ?>
          <div class="form-text">Only days they do not already attend.</div>
        </div>
        <div class="mb-3">
          <label class="form-label" for="wish-note" id="wish-note-label">What they said</label>
          <textarea class="form-control" name="note" id="wish-note" rows="3" maxlength="500"></textarea>
          <div class="form-text" id="wish-note-help">The part Kidsoft has nowhere to keep.</div>
        </div>
        <div class="mb-2">
          <label class="form-label" for="wish-when" id="wish-when-label">When they asked</label>
          <input type="date" class="form-control" name="requested_on" id="wish-when">
          <?php /* Not "when it was typed in". A wish taken on the phone on Monday
                   and entered on Thursday is a Monday wish, and this is the queue
                   order when two families want the one place. A hold reads the
                   same field as the day the place stopped being booked, which is
                   how long it has been out of use rather than how long ago
                   somebody got round to recording it. */ ?>
          <div class="form-text" id="wish-when-help">Decides who comes first if two families want the same place.</div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-danger me-auto d-none" id="wish-remove">No longer waiting</button>
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="submit" class="btn btn-primary">Save</button>
      </div>
    </form>
  </div>
</div>

<input type="hidden" id="init-centre" value="<?= (int)$centreId ?>">
<input type="hidden" id="init-year" value="<?= (int)$year ?>">
<input type="hidden" id="init-from" value="<?= $h($fromChosen ?? '') ?>">
<?php /* A cell to open as soon as the grid has loaded. Search results link
         straight to the room x weekday they found a child in, rather than
         landing on the grid and leaving someone to find the row again by eye.
         Read once at load; the grid is the authority afterwards. */ ?>
<input type="hidden" id="init-room" value="<?= (int)($_GET['room'] ?? 0) ?>">
<input type="hidden" id="init-wd" value="<?= (int)($_GET['wd'] ?? 0) ?>">

<?php /* The shared modals FIRST: board.js calls window.openWorkItem() and
         window.openWaitlistFamily(). window.openChild() comes with the chrome. */ ?>
<?php layout_footer(['/static/js/work-modal.js', '/static/js/waitlist-modal.js',
                     '/static/js/board.js']); ?>
