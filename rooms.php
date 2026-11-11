<?php
// Rooms, capacities and sub-quotas.
//
// NOTHING KIDSOFT OWNS IS EDITABLE HERE (11 Aug 2026). The rooms themselves,
// their names, their age bands and their capacities all come from Kidsoft --
// Service/getRooms and Service/getUtilisation - and both are refreshed nightly
// and by the Refresh button. This screen DISPLAYS them, with the date each
// figure was read.
//
// What is editable is what Kidsoft has no field for: SUB-QUOTAS and NOTES. If
// you are about to add a control here, the test is that one, and it is not a
// close call for anything on a room.

declare(strict_types=1);

require_once __DIR__ . '/_incl/auth.php';
$user = auth_require();

require_once __DIR__ . '/_incl/rooms.php';
require_once __DIR__ . '/_incl/board.php';   // board_default_year()
require_once __DIR__ . '/_incl/layout.php';

$centres = centres_visible();

$h = static fn($v) => htmlspecialchars((string)$v, ENT_QUOTES);

// A centre tab is that centre's rooms; the All centres tab is the CENTRE
// REGISTER - which centres the organisation holds, what the team calls each one
// and what order they sit in the bar. That is reference data too, and it had no
// home at all before 11 Aug 2026: `centres` was written only by the CLI seeder,
// so a centre opening needed a developer. It lives here rather than in a seventh
// sidebar item because the rail is already tight, and because this is the
// reference-data screen.
$centreId = centre_current($centres, true);
// Was date('Y') while the Board used board_default_year(), so moving between
// the two silently changed the year. One default, remembered across screens.
$year = year_current(board_default_year());

layout_header('Rooms', $user, 'rooms', [
    'centres'   => $centres,
    'centre_id' => $centreId,
    'all_label' => user_all_centres() ? 'All centres' : null,
    'year'      => $year,
]);
?>

<div class="page-head">
  <div class="page-head-text">
    <h1 class="h3 mb-1"><?= $centreId ? 'Rooms &amp; capacity' : 'Centres' ?></h1>
    <p class="text-muted mb-0">
      <?= $centreId
          ? 'Every room, its age range, and how many places it runs on each weekday.'
          : 'Every centre, with its rooms and the places they run.' ?>
    </p>
  </div>
  <div class="page-head-actions">
    <?php if ($centreId): ?>
      <?php layout_year_nav('year-nav'); ?>
      <button class="btn btn-outline-secondary" id="sync-kidsoft"
              title="Re-read this centre's rooms and operational positions from Kidsoft now">
        <i class="fa-solid fa-rotate me-1"></i>Refresh from Kidsoft
      </button>
    <?php else: ?>
      <?php /* No year stepper on this tab. A centre register is not year-scoped,
               and a control that moves nothing on screen is worse than no
               control - the reason the Dashboard has no year picker either. */ ?>
      <button class="btn btn-outline-secondary" id="sync-centres"
              title="Look for centres Kidsoft has that this tool does not">
        <i class="fa-solid fa-rotate me-1"></i>Check Kidsoft for new centres
      </button>
    <?php endif; ?>
  </div>
</div>

<?php /* Which of the two views this page is depends on the centre bar, which is
         made of links, so the server already knows. Only the container in use is
         rendered, so no JS has to keep two hidden shells in step. */ ?>
<?php if (!$centreId): ?>

<div id="centres-summary" class="row g-3 mb-3"></div>

<div class="card">
  <div class="table-scroll">
    <table class="table table-hover align-middle mb-0 table-sticky" id="centres-table">
      <thead class="table-light">
        <tr>
          <?php /* No Status column. It would read "-" on every row in the normal
                   case, which is the noise the room table's old Kidsoft-link
                   column was; a centre that is retired, or that Kidsoft has
                   archived since the last sync, is badged beside its name. */ ?>
          <th style="min-width:16rem">Centre</th>
          <th style="min-width:18rem">Kidsoft name</th>
          <th class="text-center" style="width:6rem">Rooms</th>
          <th class="text-end" style="width:11rem">Order</th>
          <?php /* EDIT, ITS OWN COLUMN (22 Oct 2026). The centre's name used to be
                   the button that opened the editor, and Broc found that nobody
                   would think to click it there: on every other screen a centre
                   name in a table goes to that centre. So the name is a link to the
                   centre's rooms, and editing is the pencil at the end of the row. */ ?>
          <th style="width:3.5rem" aria-label="Edit"></th>
        </tr>
      </thead>
      <tbody><tr><td colspan="5" class="text-center text-muted py-5"><div class="spinner-border spinner-border-sm text-primary me-2" role="status"></div>Loading centres...</td></tr></tbody>
    </table>
  </div>
</div>

<!-- The short name is ours. Kidsoft's own name is shown beside it, read-only,
     because that is how you tell two similarly-named services apart. -->
<div class="modal fade" id="centre-modal" tabindex="-1">
  <div class="modal-dialog">
    <form class="modal-content" id="centre-form">
      <div class="modal-header">
        <h5 class="modal-title">Centre</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <input type="hidden" name="id">
        <label class="form-label">Short name</label>
        <input class="form-control" name="name" required maxlength="120">
        <div class="form-text">
          What the team reads, in the centre bar and on every screen. Kidsoft's full
          name is not ours to change and is not used for display.
        </div>
        <?php /* THE FIRST DAY THE CENTRE TRADES (21 Sep 2026). Kidsoft holds no
                 such date - getServices has none, and getOpeningHours says
                 Warnervale has been open since the day its record was created --
                 so this is ours, like the short name above it. It decides
                 whether the centre is counted in a year's figures at all, which
                 is why only a manager or administrator may set it. */ ?>
        <label class="form-label mt-3" for="centre-opens">First day of operation</label>
        <input type="date" class="form-control" name="opens_on" id="centre-opens">
        <div class="form-text">
          Leave this blank for a centre that has always been open. A date here keeps
          the centre out of every figure for the years before it, and brings its
          places into the year it opens - so an empty new centre is a place to fill
          rather than a centre that looks broken.
        </div>
        <div class="alert alert-light border small mt-3 mb-0" id="centre-kidsoft"></div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="submit" class="btn btn-primary">Save</button>
      </div>
    </form>
  </div>
</div>

<?php else: ?>

<?php /* A centre that has not opened in this year still shows its rooms - see
         the `list` action in api/rooms.php - and this is where it says so. */ ?>
<div id="rooms-notice"></div>

<div id="rooms-summary" class="row g-3 mb-3"></div>

<div class="card">
  <div class="table-scroll">
    <table class="table table-hover align-middle mb-0 table-sticky" id="rooms-table">
      <thead class="table-light">
        <tr>
          <th style="min-width:16rem">Room</th>
          <?php /* Capacity is PER WEEKDAY and comes from Kidsoft. */ ?>
          <?php foreach (WEEKDAYS as $wd => $label): ?>
            <th class="text-center" style="width:3.5rem"><?= $h($label) ?></th>
          <?php endforeach; ?>
          <th style="width:11rem">Age range</th>
          <?php /* The two columns that are OURS, and the only ones with controls in
                   them. The old "Kidsoft link" column is gone: every room is created
                   linked by RoomID now, so it read "Linked" on every row. An unlinked
                   legacy row is badged beside its name instead. */ ?>
          <th style="min-width:14rem">Sub-quotas</th>
          <th style="min-width:14rem">Notes</th>
        </tr>
      </thead>
      <tbody><tr><td colspan="9" class="text-center text-muted py-5"><div class="spinner-border spinner-border-sm text-primary me-2" role="status"></div>Loading rooms...</td></tr></tbody>
    </table>
  </div>
</div>

<!-- Notes. The one room-level field that is ours: everything else about a room
     is read from Kidsoft, so there is no room editor any more. -->
<div class="modal fade" id="notes-modal" tabindex="-1">
  <div class="modal-dialog">
    <form class="modal-content" id="notes-form">
      <div class="modal-header">
        <h5 class="modal-title">Notes</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <input type="hidden" name="room_id">
        <p class="text-muted small" id="notes-context"></p>
        <textarea class="form-control" name="notes" rows="3" maxlength="500"
                  placeholder="Anything about this room the team needs to know."></textarea>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="submit" class="btn btn-primary">Save notes</button>
      </div>
    </form>
  </div>
</div>

<!-- Sub-quota editor -->
<div class="modal fade" id="quota-modal" tabindex="-1">
  <div class="modal-dialog">
    <form class="modal-content" id="quota-form">
      <div class="modal-header">
        <h5 class="modal-title">Sub-quota</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <input type="hidden" name="id">
        <input type="hidden" name="room_id">
        <input type="hidden" name="year">
        <p class="text-muted small">
          A cap on part of a room's daily places, defined by age. For example a
          2.5-4yr room that may take at most 5 children under 3 on any day:
          label "Under 3s", under 36 months, maximum 5.
        </p>
        <div class="mb-3">
          <label class="form-label">Label</label>
          <input class="form-control" name="label" required maxlength="120">
        </div>
        <div class="row g-3">
          <div class="col-6">
            <label class="form-label">Aged under (months)</label>
            <input type="number" class="form-control" name="age_under_months" min="0" max="120">
          </div>
          <div class="col-6">
            <label class="form-label">Aged from (months)</label>
            <input type="number" class="form-control" name="age_over_months" min="0" max="120">
          </div>
          <div class="col-12">
            <div class="form-text">
              Set one for a simple cap, or both to describe a band. At least one is required.
            </div>
          </div>
          <div class="col-6">
            <label class="form-label">Maximum children/day</label>
            <input type="number" class="form-control" name="max_children" min="0" required>
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-danger me-auto d-none" id="quota-delete">Delete</button>
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="submit" class="btn btn-primary">Save sub-quota</button>
      </div>
    </form>
  </div>
</div>

<?php endif; ?>

<input type="hidden" id="init-centre" value="<?= (int)$centreId ?>">
<input type="hidden" id="init-year" value="<?= (int)$year ?>">
<?php /* TODAY, FROM THE SYDNEY CLOCK ON THE SERVER. The centre register compares
         an opening date against it to decide whether to badge a centre as not
         open yet, and a date decided in the viewer's browser is exactly the
         reinterpretation _incl/dates.php forbids. Same mechanism as every other
         server-computed date in this app. */ ?>
<input type="hidden" id="init-today"
       value="<?= $h(date_create('today', new DateTimeZone('Australia/Sydney'))->format('Y-m-d')) ?>">

<?php layout_footer(['/static/js/rooms.js']); ?>
