<?php
// Waitlist queue and offers.
//
// The family data here is IMPORTED from Waitlist/getWaitingList - names, DOBs,
// requested weekdays and parent mobiles, all at ~100% coverage. This app stays
// the system of record for the decisions taken on top: the priority ladder,
// offers, the enquiry funnel and the contact log. See _incl/waitlist-import.php
// for which columns belong to which side.

declare(strict_types=1);

require_once __DIR__ . '/_incl/auth.php';
$user = auth_require();

require_once __DIR__ . '/_incl/waitlist.php';
require_once __DIR__ . '/_incl/board.php';
require_once __DIR__ . '/_incl/waitlist-import.php';
require_once __DIR__ . '/_incl/layout.php';

$centres = centres_visible();

$h = static fn($v) => htmlspecialchars((string)$v, ENT_QUOTES);



// This screen used to refuse an "All centres" tab, on the grounds that priority
// order only means anything within a centre. That is still true, and the group
// tab does not pretend otherwise: it is a REGISTER of every family the
// organisation is holding, one row per child, showing the place they hold in
// each centre's own queue. What it buys is the thing no centre tab can show --
// families waiting at several centres at once, 74 of them in the live data.
// See _incl/waitlist-group.php.
$centreId = centre_current($centres, true);

// Opening this screen imports the queue, if nothing has lately. There used to be
// an "Import from Kidsoft" button here; asking a human to press it was asking
// them to maintain something Kidsoft already knows. Throttled and non-fatal --
// see waitlist_import_on_view(). Runs before the header so the first /api/waitlist
// call, which fires after the page renders, already sees the new rows.
//
// The group tab imports every centre, because it claims to show every centre: a
// tab headed "All centres" that quietly held one centre's overnight applications
// and not another's would be worse than one that is uniformly a day old. Each
// centre is throttled separately (900s), so this costs the full ~2.7s group
// import at most once a quarter of an hour.
foreach ($centreId ? [$centreId] : array_map(static fn($c) => (int)$c['id'], $centres) as $cid) {
    waitlist_import_on_view($cid);
}

// WHICH FILTERS ARE ON, IN THE URL - the same rule In progress follows, and for
// the same complaint: they were browser-only state that started at their
// defaults on every load, so switching centre - the one navigation this screen
// exists to make easy - threw away the question somebody had just asked and
// silently answered a different one.
//
// Every control here whose id ends in `-filter` travels: the three of "which
// applications to show", the start-date range, the register's "2+ centres", and
// the DOB / age range with the unit it is in. **The search box does not**, and
// that is deliberate rather than an omission: `?q=` already means something on
// this screen (the palette deep-links into it, and that link also forces Open ->
// All so a closed match is not an empty table). A search is a question about one
// family, asked and finished; a filter is the shape of the list.
//
// A DEFAULT IS LEFT OUT OF THE URL, so a plain /waitlist is the plain queue and
// an unknown value falls back rather than 500ing on a hand-typed link.
$flt = [];

$show = (string)($_GET['show'] ?? 'open');
if (!in_array($show, ['open', 'all', 'offer_expired'], true)) $show = 'open';
if ($show !== 'open') $flt['show'] = $show;

// THE START-DATE RANGE. Cleaned by the same function the API uses, so the
// boxes cannot show a range the table is not filtered on.
$startRange = waitlist_start_range($_GET);
$startFrom  = (string)($startRange['starts_from'] ?? '');
$startTo    = (string)($startRange['starts_by'] ?? '');
if ($startFrom !== '') $flt['start_from'] = $startFrom;
if ($startTo !== '')   $flt['start_to']   = $startTo;

// THE YEARS "This year" AND "Next year" FILL IN. Sydney's year from the server,
// so it moves with the calendar and nobody's browser decides it.
$thisYear = board_default_year();
$startPresets = [
    'this' => ['This year', "$thisYear-01-01", "$thisYear-12-31"],
    'next' => ['Next year', ($thisYear + 1) . '-01-01', ($thisYear + 1) . '-12-31'],
];
$startPreset = $startFrom === '' && $startTo === '' ? 'any' : 'dates';
foreach ($startPresets as $k => [, $a, $b]) {
    if ($startFrom === $a && $startTo === $b) $startPreset = $k;
}

// CARRIED THROUGH A CENTRE TAB THAT CANNOT SHOW IT. "2+ centres" exists only on
// the register, so a centre page renders no control for it - but it has to keep
// the value in its own links or a there-and-back through a centre would drop it,
// which is the bug being fixed rather than a smaller version of it.
$multi = ($_GET['multi'] ?? '') === '1' ? '1' : '0';
if ($multi === '1') $flt['multi'] = '1';

// The unit, and only the pair of boxes that unit uses. The hidden pair is not
// carried for the same reason it is not sent to the server: a bound nobody can
// see must not be narrowing the table (see waitlist_age_bounds()).
$ageMode = ($_GET['age_mode'] ?? '') === 'age' ? 'age' : 'dob';
if ($ageMode === 'age') $flt['age_mode'] = 'age';

// Validated, not trusted: a date is rendered back into a value= and a number
// into min/max, so a hand-edited URL must not be able to put anything else
// there. Blank stays blank, which is NO BOUND at that end.
$dobBox = static function (string $k): string {
    $v = trim((string)($_GET[$k] ?? ''));
    if ($v === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) return '';
    [$y, $m, $d] = array_map('intval', explode('-', $v));
    return checkdate($m, $d, $y) ? $v : '';
};
$ageBox = static function (string $k): string {
    $v = trim((string)($_GET[$k] ?? ''));
    if ($v === '' || !ctype_digit($v)) return '';
    $n = (int)$v;
    return ($n >= 0 && $n <= 240) ? (string)$n : '';
};

$dobFrom = $ageMode === 'dob' ? $dobBox('dob_from') : '';
$dobTo   = $ageMode === 'dob' ? $dobBox('dob_to')   : '';
$ageMin  = $ageMode === 'age' ? $ageBox('age_min')  : '';
$ageMax  = $ageMode === 'age' ? $ageBox('age_max')  : '';
foreach (['dob_from' => $dobFrom, 'dob_to' => $dobTo,
          'age_min' => $ageMin, 'age_max' => $ageMax] as $k => $v) {
    if ($v !== '') $flt[$k] = $v;
}

// ' d-none' for the pair of boxes the chosen unit is not using.
$hide = static fn(string $unit): string => $ageMode === $unit ? '' : ' d-none';

layout_header('Waitlist', $user, 'waitlist', [
    'centres'   => $centres,
    'centre_id' => $centreId,
    'all_label' => user_all_centres() ? 'All centres' : null,
    'params'    => $flt,
]);
?>

<div class="page-head">
  <div class="page-head-text">
    <h1 class="h3 mb-1">Waitlist</h1>
    <p class="text-muted mb-0">
      <?= $centreId
          ? 'In priority order: re-enrolments, then staff children, then families already
             in care, then priority of access, then siblings, then first come first served.'
          : 'Every family the organisation is holding, one row per child, longest waiting first.
             Priority order belongs to a centre, so it is shown against each centre they applied to.' ?>
    </p>
  </div>
  <div class="page-head-actions">
    <?php /* A search box that looks like one: the magnifier says what to type in
             it without needing the placeholder to survive being typed over. */ ?>
    <div class="input-group" style="width:16rem">
      <span class="input-group-text"><i class="fa-solid fa-magnifying-glass text-muted"></i></span>
      <?php /* The group tab matches a phone number as well, so it says so - the
               team's commonest starting point is a number on a handset. */ ?>
      <input type="search" id="search" class="form-control"
             placeholder="<?= $centreId ? 'Search name...' : 'Name or phone...' ?>">
    </div>

    <?php /* THE BIRTH DATE ITSELF, or age in months - the dropdown picks which,
             and the pair of boxes beside it changes unit to match. DOB is the
             default.

             A birth date is what the row prints, what a parent reads out on the
             phone, and what the cohort question is asked in ("born in 2024, for
             next year's room"). Months is the specialist one: it is the unit
             rooms and sub-quotas are banded in, so it is what to reach for
             holding a child up against a room's band, and it answers "who have
             we got around two". Neither is a conversion of the other in
             anybody's head, which is why both boxes are here rather than one
             unit and a mental sum.

             Two boxes rather than a room-band picker: an application names a
             preferred room on 6% of rows, so the question the team actually asks
             is "who have we got around two", not "who fits Amazing Asteroids".
             Either box may be left blank, which is no bound at that end.

             THE DATES RUN THE OPPOSITE WAY TO THE AGES - the earlier date is the
             OLDER child - SO EITHER ORDER IS ACCEPTED (11 Nov 2026). Somebody
             thinking in ages types the younger end first ("1/1/2024 to
             31/12/2022"), and that is the same question as the other way round;
             see datePair() in waitlist.js.

             Only the pair in force is sent to the server; the other keeps
             whatever was typed in it, in plain sight, for a switch back. */ ?>
    <?php /* w-auto: .input-group is width:100% by default, which under a wrapping
             flex row makes it take a whole line to itself. The pair of boxes on
             show changes width with the unit, so the group sizes to its content
             rather than carrying a fixed width that would fit only one of them. */ ?>
    <div class="input-group w-auto" id="age-filter">
      <?php /* DOB IS THE DEFAULT (3 Sep 2026, Broc). It is the unit the row itself
               prints and the one a parent reads out; months is the specialist
               question, asked while holding a child up against a room's band.
               The markup starts in that state - the age boxes carry d-none here
               rather than being hidden by JS on load, so there is no flash of the
               wrong pair before syncAgeMode() runs. */ ?>
      <select class="form-select flex-grow-0 w-auto" id="age-mode"
              title="Filter by the child's date of birth, or by age today."
              aria-label="Filter by date of birth or by age">
        <option value="dob"<?= $ageMode === 'dob' ? ' selected' : '' ?>>DOB</option>
        <option value="age"<?= $ageMode === 'age' ? ' selected' : '' ?>>Age</option>
      </select>
      <?php /* d-none FOLLOWS THE UNIT IN THE URL, not a hard-coded default: a
               ?age_mode=age link arrives with these two on show and the date
               pair hidden, so there is still no flash of the wrong pair before
               syncAgeMode() runs - which is the whole reason this is in the
               markup rather than in JS. */ ?>
      <input type="number" class="form-control text-center px-1 age-box<?= $hide('age') ?>" style="width:4.25rem" id="age-min"
             min="0" max="240" step="1" placeholder="from" aria-label="Youngest, in months"
             value="<?= $h($ageMin) ?>">
      <input type="number" class="form-control text-center px-1 age-box<?= $hide('age') ?>" style="width:4.25rem" id="age-max"
             min="0" max="240" step="1" placeholder="to" aria-label="Oldest, in months"
             value="<?= $h($ageMax) ?>">
      <span class="input-group-text age-box<?= $hide('age') ?>">mo</span>
      <?php /* WIDE ENOUGH FOR dd/mm/yyyy AND THE CALENDAR BUTTON TOGETHER. At
               8.75rem the browser's own picker button sat hard against the last
               digit and read as decoration; the date and the way to pick one are
               the whole control, so neither gets squeezed.

               NO min / max ON THESE (11 Nov 2026). Each box used to bound the
               other, and Chromium will not take a typed year outside a box's
               min / max: the box blanked itself and the table filtered on the
               other date alone. See datePair() in waitlist.js. */ ?>
      <input type="date" class="form-control px-2 dob-box<?= $hide('dob') ?>" style="width:10.75rem" id="dob-from"
             aria-label="Born from" title="Born between this date and the next one, either way round"
             value="<?= $h($dobFrom) ?>">
      <input type="date" class="form-control px-2 dob-box<?= $hide('dob') ?>" style="width:10.75rem" id="dob-to"
             aria-label="Born to" title="Born between this date and the one before it, either way round"
             value="<?= $h($dobTo) ?>">
    </div>

    <?php /* Was a switch labelled "Show closed", which meant reading the label and
             then working out which way was on. Both states are now visible and
             named, and the one in force is the one that is filled in.

             THE THIRD ANSWER IS A THIRD SEGMENT, NOT A FOURTH CONTROL (9 Sep
             2026). "Which applications to show" is one question, and an expired
             offer is one of its answers - a place this organisation offered and
             nobody closed out, ten rows group-wide sitting among 496 lapsed
             waiting places under "All". Bolting a separate chip beside this
             would have put two controls on one axis, which is how a screen
             starts contradicting itself about what it is showing.

             The label is the badge's own words. "Offer expired" is what the row
             says once it is on screen, so the chip that fetches it says the
             same - see waitlist_status_label(). */ ?>
    <div class="btn-group seg" id="closed-filter" role="group" aria-label="Which applications to show">
      <input type="radio" class="btn-check" name="closed" id="closed-off" value="open"<?= $show === 'open' ? ' checked' : '' ?>>
      <label class="btn btn-outline-secondary" for="closed-off">Open</label>
      <input type="radio" class="btn-check" name="closed" id="closed-on" value="all"<?= $show === 'all' ? ' checked' : '' ?>>
      <label class="btn btn-outline-secondary" for="closed-on">All</label>
      <input type="radio" class="btn-check" name="closed" id="closed-offer" value="offer_expired"<?= $show === 'offer_expired' ? ' checked' : '' ?>>
      <label class="btn btn-outline-secondary" for="closed-offer"
             title="Applications where a place was offered and the offer lapsed without an answer">Offer expired</label>
    </div>

    <?php /* WHEN THEY NEED TO START: two dates and a dropdown (6 Nov 2026, Broc:
             "filtered by two specific dates ... a drop down for This year and
             Next year"). The dropdown only FILLS the boxes - the two dates are
             the filter, so what is in force is always on screen. Typing a date
             turns the dropdown to "Choose dates". Either box may be blank, which
             is no bound at that end; no dates is the whole queue.

             It was a set of fixed segments until today ("Starting by 2027" /
             "Any start date"), and "by 2027" was read as "by the start of 2027".
             See waitlist_start_range(). A row with no start date is always kept
             - absence is silence, not "they are years away". */ ?>
    <div class="input-group w-auto" id="start-filter">
      <select class="form-select flex-grow-0 w-auto" id="start-preset"
              title="When the family needs the place to start"
              aria-label="Filter by start date">
        <option value="any"<?= $startPreset === 'any' ? ' selected' : '' ?>>Any start date</option>
        <?php foreach ($startPresets as $k => [$label, $a, $b]): ?>
          <option value="<?= $k ?>" data-from="<?= $a ?>" data-to="<?= $b ?>"<?= $startPreset === $k ? ' selected' : '' ?>><?= $h($label) ?></option>
        <?php endforeach; ?>
        <option value="dates"<?= $startPreset === 'dates' ? ' selected' : '' ?>>Choose dates</option>
      </select>
      <input type="date" class="form-control px-2" style="width:10.75rem" id="start-from"
             aria-label="Starting on or after" title="Starting on or after this date"
             value="<?= $h($startFrom) ?>">
      <input type="date" class="form-control px-2" style="width:10.75rem" id="start-to"
             aria-label="Starting on or before" title="Starting on or before this date"
             value="<?= $h($startTo) ?>">
    </div>

    <?php if (!$centreId): ?>
      <?php /* Only on the group tab, because only there is it a question. The
               table is the whole register by default - see the sort rule in
               _incl/waitlist-group.php - and this narrows it to the families
               the register exists to reveal. */ ?>
      <div class="btn-group seg" id="spread-filter" role="group" aria-label="Which families to show">
        <input type="radio" class="btn-check" name="spread" id="spread-all" value="0"<?= $multi === '0' ? ' checked' : '' ?>>
        <label class="btn btn-outline-secondary" for="spread-all">All families</label>
        <input type="radio" class="btn-check" name="spread" id="spread-multi" value="1"<?= $multi === '1' ? ' checked' : '' ?>>
        <label class="btn btn-outline-secondary" for="spread-multi">2+ centres</label>
      </div>
    <?php endif; ?>
    <?php /* THERE IS NO ADD BUTTON (Broc, 6 Nov 2026): every application is
             pulled from Kidsoft. A hand-keyed row has no WaitListID, so when the
             family's real application arrived it sat beside it as a second row
             (Maisie Morton at Point Clare). The application is entered in
             Kidsoft and appears here on Refresh. */ ?>

    <?php /* REFRESH IS AN OVERRIDE OF THE THROTTLE, NOT THE WAY THE QUEUE STAYS
             TRUE. Opening this screen already imports live (waitlist_import_on_view(),
             throttled to 15 min) and the nightly covers every centre. This is for
             the gap that leaves: a PAM archives an application in Kidsoft and wants
             it gone from here now, not within the quarter hour.

             Secondary styling on purpose. The 11 Aug 2026 "Import from Kidsoft"
             button was removed because a primary-looking button reads as a step in
             the job, and the whole point is that keeping the queue current is not
             the team's job. */ ?>
    <button class="btn btn-outline-secondary" id="refresh-queue"
            title="<?= $centreId
                       ? 'Read this centre\'s queue from Kidsoft now, without waiting for the next automatic import.'
                       : 'Read every centre\'s queue from Kidsoft now, without waiting for the next automatic import.' ?>">
      <i class="fa-solid fa-rotate me-1"></i>Refresh
    </button>
  </div>
</div>

<?php /* Which of the two views this page is depends on the centre bar, which is
         made of links, so the server already knows - the same rule the Board
         follows. Only the container in use is rendered, so no JS has to keep two
         hidden shells in step. */ ?>
<?php if (!$centreId): ?>

  <div id="wl-group-summary" class="row g-3 mb-3"></div>

  <?php /* The overlap. Left: how long each centre's own queue is. Right: which
           centres are holding the SAME families, ranked - one pair carries most
           of it, which a matrix of mostly-empty cells would have buried. */ ?>
  <div class="row g-3 mb-3" id="wl-overlap"></div>

  <div class="card">
    <div class="table-scroll">
      <table class="table table-hover align-middle mb-0 table-sticky" id="wl-group-table">
        <thead class="table-light">
          <tr>
            <th style="min-width:14rem">Child</th>
            <th style="min-width:12rem">Guardian</th>
            <?php /* 13rem, not the centre table's 11: five .dbox badges are
                     10.9rem on their own and wrapped Friday onto a second line,
                     which reads as a different set of days. The strip is shared
                     now (window.dayStrip) and .dstrip-wide holds that 10.9rem
                     deliberately, so this stays measured against the same
                     number. */ ?>
            <th style="min-width:13rem">Days wanted</th>
            <?php /* 19.5rem, not 20: measured 11 Aug 2026, the five columns came
                     to 1037px inside a 1030px box at a 1280px viewport, and this
                     is the widest of them and the one with slack. Guardian holds
                     192px because 12rem is declared here, not because of the
                     email - its cap (.wl-email) is 11rem so it fills the column
                     exactly rather than setting it. */ ?>
            <th style="min-width:19.5rem">Waiting at</th>
            <th style="width:9rem">Waiting since</th>
          </tr>
        </thead>
        <tbody><tr><td colspan="5" class="text-center text-muted py-5"><div class="spinner-border spinner-border-sm text-primary me-2" role="status"></div>Loading every centre...</td></tr></tbody>
      </table>
    </div>
  </div>

<?php else: ?>

  <div id="wl-summary" class="row g-3 mb-3"></div>

  <?php /* There is no notices container here any more (11 Aug 2026). It held one
           thing - the sibling backlog - and that was an alert reporting that the
           table underneath it was in the wrong order, with a button to put it
           right. priority_rank() reads the detection itself now, so the order is
           right and the alert has nothing to say. See _incl/siblings.php. */ ?>

  <div class="card">
    <div class="table-scroll">
      <table class="table table-hover align-middle mb-0 table-sticky" id="wl-table">
        <thead class="table-light">
          <?php /* THE WIDTHS ARE MEASURED, NOT CHOSEN (11 Aug 2026), and the
                   thing to understand before touching them is that a declared
                   min-width BELOW a column's own min-content floor is harmless -
                   the content wins and the browser gives it the floor anyway.
                   So every rem declared above that floor is pure spend, and this
                   row was spending 89.5rem of it: at a 1280px viewport with the
                   sidebar out the table ran 1292px inside a 1030px box, and
                   because table-layout auto squeezes proportionally, ACTIONS --
                   the column a row is opened from, and the worst one to lose --
                   was handed 71px for 191px of buttons and sat off the
                   right-hand edge entirely.

                   Measured floors for this table: # 49, Child 100, Guardian 160,
                   Days 73, Priority 199, Added 93, Status 89, Actions 71 = 833px
                   all told, comfortably inside 1030. Each column is now declared
                   at or just above its own floor, and the two that genuinely
                   need more than it (Days, so five badges stay on one line;
                   Actions, so the split button is not squeezed) say so. Priority
                   and Added are left declared BELOW their floors deliberately:
                   their content sets the width and a bigger number here would
                   only take room off their neighbours.

                   RE-MEASURE rather than reason about it - scratchpad has the
                   Playwright script that reads given / natural / floor per
                   column. */ ?>
          <tr>
            <th style="width:3.5rem">#</th>
            <?php /* THE ONE WIDTH THAT IS NOT DECLARED HERE. It needs a media
                     query - 7rem at the measured 1280 case, 9.5rem above 1400
                     where there is slack to spare - and an inline style beats a
                     stylesheet whatever the selector says, so it lives in
                     app.css beside the query. See .wl-child there. */ ?>
            <th class="wl-child">Child</th>
            <?php /* STATUS SITS BESIDE THE NAME, not out at the far end (14 Aug
                     2026). Where a family has got to is the second thing anybody
                     wants after knowing who they are - and pinned Actions meant
                     the old position put it against the right-hand edge, the
                     furthest point on the row from the name it describes.
                     5.5rem, not 13. The common row is one badge and a small
                     line, and it measured 89px of natural width. The wide case
                     is a live offer (room, days, expiry), which wraps to a few
                     lines - fine on the rare row, where making all 115 others
                     pay 7rem for it was not. */ ?>
            <th style="min-width:5.5rem">Status</th>
            <?php /* Guardian and Days wanted are declared in app.css (.wl-guardian,
                     .wl-days), because below 1400px they narrow and an inline
                     style would beat the media query. Days is a real requirement
                     rather than a floor: five .dbox badges are 10.9rem on their
                     own, so a cell asking for exactly 11rem has no room left for
                     its own padding and wraps Friday onto a second line. 12rem
                     at the usual padding; 11.5rem where app.css tightens it. */ ?>
            <th class="wl-guardian">Guardian</th>
            <th class="wl-days">Days wanted</th>
            <?php /* WHERE ELSE THIS CHILD IS WAITING (17 Aug 2026). 54 of
                     Wamberal's 222 rows carry one, and the register tab was the
                     only screen that could see it - which is the wrong screen,
                     because the calls are made from here. Blank on the ordinary
                     row, deliberately: a family waiting at one centre has nothing
                     to say here, and a "-" per row would be 9 rows in 10 saying
                     nothing. 8rem is one chip plus its queue number; a second
                     chip wraps, which is what the widest cases (2 families at
                     five centres) should do rather than setting the column.
                     A BROTHER OR SISTER also waiting is a line under the chips
                     (16 Oct 2026) - see waitlist_siblings_waiting(). */ ?>
            <th style="min-width:7rem">Also waiting</th>
            <?php /* LAST CONTACTED (11 Nov 2026) cost ~90px in a table that was
                     already 9-31px past 1030 at four centres at 1280, so these
                     three are declared BELOW their floors and the content sets
                     them (Priority 87-95, Added 85, Contacted 71-82
                     measured), and the Status badges wrap. Re-measured: see
                     waitlist.md, "Contacted". */ ?>
            <th style="min-width:5rem">Priority</th>
            <th style="width:5rem">Added</th>
            <?php /* In progress's "Last interacted", the same data - see
                     waitlist_last_touched(). "CONTACTED", ONE WORD, ONE LINE
                     (Broc, 11 Nov 2026): "Last contacted" wrapped onto two lines,
                     and held on one it was 120px against a ~88px date - 30-40px
                     the table does not have at 1280. */ ?>
            <th style="width:4.5rem">Contacted</th>
            <th class="text-end wl-actions" style="width:7.5rem">Actions</th>
          </tr>
        </thead>
        <tbody><tr><td colspan="10" class="text-center text-muted py-5"><div class="spinner-border spinner-border-sm text-primary me-2" role="status"></div>Loading the queue...</td></tr></tbody>
      </table>
    </div>
  </div>

<?php endif; ?>

<?php /* Everything below edits ONE application, and an application belongs to a
         centre, so none of it is rendered on the group tab. */ ?>
<?php if ($centreId): ?>

<?php /* THE FAMILY MODAL LIVES IN _incl/waitlist-modal.php, because the
         Placement board opens the same one from "Could take this day" - a
         family you can ring, offer and start enrolling from that shortlist is a
         family somebody needs to read the contact log for first. One markup, one
         behaviour, the same split work-modal.php makes. */ ?>
<?php require __DIR__ . '/_incl/waitlist-modal.php'; ?>

<?php /* THE OFFER DIALOG IS GONE (15 Oct 2026). Offers are sent in Kidsoft, and the
         queue reads them back - see KIDSOFT_OPEN_OFFER in _incl/board.php. The
         answer dialog below stays for offers made here before that. */ ?>

<!-- Respond to offer -->
<div class="modal fade" id="respond-modal" tabindex="-1">
  <div class="modal-dialog">
    <form class="modal-content" id="respond-form">
      <div class="modal-header">
        <h5 class="modal-title">Record their answer</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <input type="hidden" name="id">
        <p class="text-muted small">
          Tick the days they accepted. Leave all unticked to record a decline --
          they keep their original place in the queue either way.
        </p>
        <div id="respond-days" class="d-flex flex-wrap gap-2 mb-3"></div>
        <label class="form-label">Note</label>
        <input class="form-control" name="response_note" maxlength="500">
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="submit" class="btn btn-primary">Save answer</button>
      </div>
    </form>
  </div>
</div>

<?php endif; ?>

<input type="hidden" id="init-centre" value="<?= (int)$centreId ?>">

<?php /* The same In progress modal the Placement board and that module open --
         one definition, in _incl/work-modal.php. Offers are made from THIS
         screen, so this is where a family most often enters the pipeline. */ ?>
<?php require __DIR__ . '/_incl/work-modal.php'; ?>

<?php /* Both modals FIRST: waitlist.js calls window.openWaitlistFamily() and
         window.openWorkItem(). The family modal is loaded only where its markup
         is - the group register has no editor, because an application belongs
         to a centre and that tab is every centre at once. */ ?>
<?php layout_footer(array_values(array_filter([
    '/static/js/work-modal.js',
    $centreId ? '/static/js/waitlist-modal.js' : null,
    '/static/js/waitlist.js',
]))); ?>
