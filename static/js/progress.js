/**
 * IN PROGRESS - every family part-way through enrolling.
 *
 * One list. An offer is not a different kind of row, it is a row whose stage is
 * "Offered", so it sits in the same table with its expiry in the urgency column.
 * The ordering that makes that safe lives server-side in work_urgency_sort():
 * every hard deadline outranks every soft one, so a lapsing offer can never be
 * pushed down the page by a pile of idle items.
 */
(function ($) {
  'use strict';

  var CENTRE = +$('#init-centre').val() || 0;

  var data = null;
  // THE SERVER DECIDES WHICH CHIP STARTS ON, from ?filter= - so a link into this
  // screen (the Dashboard's tour card) and a centre switch both arrive already
  // filtered. Reading the checked radio rather than the query string keeps one
  // definition of the whitelist, which is progress.php's.
  var filter = $('#prog-filters input[name=pf]:checked').val() || 'open';
  // "Only mine" narrows whichever chip is on - it is not one of them.
  var mine = $('#pf-mine').prop('checked');

  function query() {
    var q = { action: 'list' };
    if (CENTRE) q.centre_id = CENTRE;
    if (filter === 'due')     q.due = 1;
    if (filter === 'offered') q.stage = 'offered';
    if (filter === 'tours')   q.tours = 1;
    if (mine)                 q.mine = 1;
    if (filter === 'all')     q.include_closed = 1;
    if (filter === 'completed') { q.stage = 'enrolled'; q.include_closed = 1; }
    return q;
  }

  function load() {
    $('#prog-list').html(spinner('Loading...'));
    $.getJSON('/api/work', query())
      .done(function (res) {
        if (!res.ok) {
          $('#prog-list').html('<div class="alert alert-danger">' + esc(res.error) + '</div>');
          return;
        }
        data = res;
        renderSummary();
        renderList();
      })
      .fail(function () {
        $('#prog-list').html('<div class="alert alert-danger">Could not load the list.</div>');
      });
  }

  // --- The tiles -------------------------------------------------------------

  function renderSummary() {
    var s = data.summary;
    var h = '';
    // FIVE TILES, NOT FOUR, so the default `col-6 col-lg-3` would leave the fifth
    // alone on a row three-quarters empty. `col-lg` divides the row evenly
    // between however many there are, which is what the tile's `col` argument is
    // for - the alternative was dropping one, and both of the candidates
    // ("Past their date", "Gone quiet") name a failure nothing else on the screen
    // can see.
    var COL = 'col-6 col-lg';
    // The shared tile, so this screen cannot drift from the other six.
    // THE LABEL FOLLOWS THE FILTER. With closed items shown, "families being
    // worked" counts families nobody is working - a figure that contradicts its
    // own caption is the thing this app keeps having to fix.
    // COMPLETED GETS ONE TILE. Every row under it is closed, so the four clocks
    // below could only ever read 0 - in green, as though something were going
    // well. Withheld rather than shown meaning nothing.
    if (filter === 'completed') {
      $('#prog-summary').html(statTile('Completed', s.total, 'enrolled in Kidsoft', '', 'col-6 col-lg-3'));
      return;
    }
    h += (filter === 'all'
      ? statTile('Shown', s.total, 'including closed', '', COL)
      : filter === 'tours'
        ? statTile('Tours', s.total, 'booked and not yet answered', '', COL)
        : statTile('In progress', s.total, 'families part-way through enrolling', '', COL));
    // Within THREE days is the app's red band for an offer; a 30-day lapse gets
    // amber. Grading expiry one way throughout is a standing rule.
    h += statTile('Offers lapsing', s.lapsing, 'within 3 days, or already gone',
                  s.lapsing ? 'text-danger' : 'text-success', COL);
    // A tour that has passed with nobody saying whether the family came is the
    // way a tour disappears - the appointment is gone and nothing else will ever
    // ask. Amber, not red: nothing is lost yet, somebody just has to say.
    h += statTile('Tours to answer', s.tours_unresolved, 'been and gone, nobody has said',
                  s.tours_unresolved ? 'text-warning' : 'text-success', COL);
    h += statTile('Past their date', s.overdue, 'a next step somebody set has passed',
                  s.overdue ? 'text-warning' : 'text-success', COL);
    // Not a deadline anybody set - which is the point. Nothing before this
    // screen could see a family nobody had touched for three weeks.
    h += statTile('Gone quiet', s.idle, 'nothing recorded for 14 days',
                  s.idle ? 'text-warning' : '', COL);
    $('#prog-summary').html(h);
  }

  // --- The list --------------------------------------------------------------

  /**
   * The one column that says why a row is where it is. An offer shows its clock;
   * an item past its own date shows how far past; everything else shows how long
   * it has been ignored.
   */
  function urgency(w) {
    // A closed item has no urgency - it is history. Printing its idle counter
    // read as "0d", which looks like a live item somebody just touched.
    if (w.stage === 'enrolled' || w.stage === 'lost' || w.stage === 'archived') {
      return '<span class="text-muted">' + esc(w.stage_label.toLowerCase()) +
        (w.closed_at ? ' ' + esc(fmtDate(w.closed_at)) : '') + '</span>';
    }
    // Grading is shared (window.offerUrgency in app.js); only the wording is
    // this screen's. The thresholds come from _incl/offer-policy.php.
    var u = offerUrgency(w.offer_hours_left);
    if (u) {
      if (u.band === 'lapsed') return '<span class="' + u.cls + '">offer lapsed</span>';
      if (u.band === 'hours')  return '<span class="' + u.cls + '">offer expires in ' + u.hours + ' hours</span>';
      return '<span class="' + u.cls + '">offer expires in ' + u.days + ' days</span>';
    }
    // THE TOUR, which is the second hard clock on this screen. The bands here
    // must match work_urgency_sort() - an unanswered tour above an upcoming one,
    // both above a follow-up somebody set for themselves - or the column would
    // explain a row's position with the wrong reason.
    //
    // The two are worded differently on purpose, and both readings were driven by
    // the width measurement in renderList(). A PASSED tour gets the date alone and
    // no "tour" prefix: the hour it happened changes nothing now, and the Stage
    // column beside it already reads "Tour booked", so the word was 39px spent
    // saying what was already on the row. An UPCOMING one keeps the full date and
    // time, because somebody has to be at the door for it.
    if (w.tour_unresolved) {
      return '<span class="text-warning">' + esc(fmtDate(w.tour_at)) + ' unanswered</span>';
    }
    if (w.tour_upcoming) {
      return '<span class="text-primary">tour ' + esc(fmtDateTime(w.tour_at)) + '</span>';
    }
    if (w.overdue) {
      return '<span class="text-warning">was due ' + esc(fmtDate(w.next_action_at)) + '</span>';
    }
    // The reminder going off: the date somebody set is today.
    if (w.due) return '<span class="text-warning">due today</span>';
    if (w.next_action_at) return '<span class="text-muted">due ' + esc(fmtDate(w.next_action_at)) + '</span>';
    if (w.idle_days === null || w.idle_days === undefined) return '<span class="text-muted">-</span>';
    if (w.idle_days >= 14) {
      return '<span class="text-warning">' + w.idle_days + ' days quiet</span>';
    }
    return '<span class="text-muted">' + w.idle_days + 'd</span>';
  }

  // A STRIP WITH NOTHING ON IT IS A CLAIM, not an absence. Five grey letters read
  // as "this family asked for no days", and a tour enquiry has not been asked yet
  // - nobody rings up and names weekdays before they have seen the room. Same
  // reason the group waitlist takes a closed application's days rather than
  // rendering an empty strip. "--" matches the Room column beside it, which is
  // blank for exactly the same kind of row.
  function wantedDays(days) {
    // BLUE: a work item is a family being placed, so these are days asked for
    // and not yet held - the strip's `want`, the same hue as the grid's "asked
    // for this day" dot. See window.dayStrip().
    return dayStrip({ want: days || [], empty: '<span class="text-muted">--</span>' });
  }

  function row(w) {
    var closed = w.stage === 'enrolled' || w.stage === 'lost' || w.stage === 'archived';
    // AN OFFER NOBODY IS TRACKING IS NOT A WORK ITEM, and the row must not
    // pretend otherwise - there is no history to open and no stage to advance.
    // These WERE the common case, because offers are made from the Waitlist and
    // that screen had no way to start an item. Since 11 Aug 2026 making an offer
    // raises one (work_link_offer), so this is a legacy shape that will not
    // recur - kept because the offers made before that change still have none.
    var untracked = w.kind === 'offer';

    // EVERY ROW IS THE CONTROL, and there is no actions column. There was one,
    // holding an "Answer" link for untracked offers, and the eight columns
    // overflowed the content area - so the only action on the row was scrolled
    // off the right-hand edge, which is exactly the "you can see it but not
    // reach it" problem this whole screen exists to fix. It also made some rows
    // clickable and others not, for no reason a user could see.
    return '<tr class="prog-row' + (untracked ? ' prog-untracked' : '') +
      (closed ? ' prog-closed' : '') + '" tabindex="0" role="button"' +
      (untracked ? ' data-href="/waitlist?centre=' + w.centre_id + '"'
                 : ' data-id="' + w.id + '"') +
      ' title="' + esc(untracked
        ? 'Nobody is tracking this offer. Opens the waitlist, where the answer is recorded.'
        : 'Open ' + w.child_name) + '">' +
      '<th class="fw-semibold">' + esc(w.child_name) +
        (w.owner_email ? '<div class="small text-muted fw-normal">' +
          esc(staffName(w.owner_email)) + '</div>' : '') +
        // THE WAITLIST'S DUPLICATE FLAG (6 Nov 2026), on the application this
        // item is worked against - see waitlist_duplicates().
        (w.duplicate ? '<div class="fw-normal">' + dupBadge(w.duplicate) + '</div>' : '') +
        // AN OFFER TO ONE SIBLING AND NOT THE OTHER, same start (19 Oct 2026) -
        // work_offer_gap(). The first name only: the Child column is 11.5rem
        // exactly, and the sentence is on the title and in the modal.
        (w.offer_gap || []).map(function (x) {
          return '<div class="small text-warning-emphasis fw-normal" title="' + esc(x.offered
              ? x.name + ' has an offer in Kidsoft for the same start. ' + w.child_name + ' has none yet.'
              : x.name + ' has no offer in Kidsoft yet, for the same start.') + '">' +
            '<i class="fa-solid fa-triangle-exclamation me-1"></i>' +
            esc(x.first_name) + (x.offered ? ' has an offer' : ' has no offer') + '</div>';
        }).join('') + '</th>' +
      (CENTRE ? '' : '<td class="small">' + esc(w.centre_name || '') + '</td>') +
      // w.year IS NULL on a tour enquiry - nobody named one, which is a real
      // answer and not a gap. Printed raw it put the word "null" beside the room.
      '<td class="small">' + (w.room_name ? roomIcon(w.room_name) + esc(w.room_name) : '--') +
        (w.year ? ' <span class="text-muted">' + esc(String(w.year)) + '</span>' : '') + '</td>' +
      '<td>' + wantedDays(w.days || []) + '</td>' +
      // Shared with the Waitlist queue (window.workStageClass in app.js): amber
      // says the family has answered and the move is ours, green says Kidsoft
      // shows them in the room. The `untracked` badge beside it stays neutral -
      // it describes the ROW, not the stage.
      '<td><span class="badge text-wrap text-start ' + workStageClass(w.stage) + '">' + esc(w.stage_label) + '</span>' +
        (untracked ? ' <span class="badge text-bg-light border" ' +
          'title="Nothing is tracking this offer; nobody added the family to ' +
          'In progress before it was made">untracked</span>' : '') + '</td>' +
      '<td class="small">' + urgency(w) + '</td>' +
      '<td class="small">' + esc(w.next_action || '') +
        // WHO IT IS FOR, when that is not whoever looks after the family - the
        // name under the child already says that one.
        (w.next_action && w.next_action_for &&
         String(w.next_action_for).toLowerCase() !== String(w.owner_email || '').toLowerCase()
          ? '<div class="text-muted">for ' + esc(staffName(w.next_action_for)) + '</div>' : '') +
        '</td>' +
      // WHEN A PERSON LAST DID SOMETHING TO THE ITEM (6 Nov 2026) - see
      // work_last_touched(). Rendered by app.js, shared with the Waitlist.
      '<td class="small">' + lastTouchedCell(w) + '</td></tr>';
  }

  function renderList() {
    if (!data.items.length) {
      $('#prog-list').html('<div class="card"><div class="card-body">' +
        emptyState('fa-mug-hot',
          filter === 'tours' ? 'No tours booked'
            : filter === 'completed' ? 'Nothing completed yet' : 'Nothing in progress',
          mine
            ? 'Nothing here is yours: no family you look after, and no next step set for you.'
            : filter === 'open'
            ? 'No family is part-way through enrolling here. Book a tour, start one from a cell on the placement board, or offer a place from the waitlist.'
            : filter === 'tours'
              ? 'Nobody is booked in to visit. Book a tour when a parent rings.'
              : 'Nothing matches that filter.') +
        '</div></div>');
      return;
    }

    // THE WIDTHS ARE MEASURED (11 Aug 2026), and unlike the Waitlist this table
    // was never overflowing - 0px at 1280, 1440 and 1920 in both views. What it
    // was doing was spending its width on the wrong columns. Natural widths with
    // nothing wrapping: Child 187, Centre 77, Room 227, Days 138, Stage 156,
    // Urgency 166, Next step 371 - 1245px against 1030 available, so something
    // has to wrap and the only question is what.
    //
    // NEXT STEP IS THE COLUMN THAT SHOULD WRAP, and it held the most width while
    // Room and Urgency were the ones breaking. It is free prose ("Ring back
    // after 3pm about the Wednesday") and reads perfectly well over two lines.
    // A ROOM NAME IS AN IDENTIFIER: "Amazing Asteroids / Silver" broken across
    // two lines reads as a different room, which is the same reason the
    // Placement grid widened its own room column. And URGENCY is the most
    // time-critical text on the screen - "offer expires in 47 hours" belongs on
    // one line. So those two get declared what they measured and Next step is
    // declared small, taking whatever is left.
    //
    // STAGE IS DELIBERATELY LEFT AT 7rem, below its 156px natural. That figure
    // is the `untracked` row, which carries a second badge - and since making
    // an offer now raises the work item (work_link_offer), untracked is a
    // legacy case that will not recur. Buying 10rem for every row to keep a
    // vanishing one on a single line is the trade this comment block is about.
    // AND THE GROUP VIEW SPENDS IT DIFFERENTLY, because it carries a seventh
    // column. Given the centre view's widths it left Next step 128px, which
    // wrapped "Send the parent handbook and the fee schedule through" over FIVE
    // lines and took the row to ~100px - and a row that tall costs more
    // scanning than a room name breaking after its second word does. So on the
    // group view Room gives back 3rem and Next step takes it.
    // URGENCY WENT 10.5rem -> 12rem WHEN TOURS MOVED IN (14 Aug 2026), and Child
    // gave back the half-rem. 10.5 was measured against "offer expires in 47
    // hours"; a tour prints a full date and time and wrapped over two lines.
    //
    // MEASURED TWICE, because the first attempt took the width off the wrong row
    // and the column wrapped again on screen. The widest tour string is 172px --
    // "tour 20/08/2026 09:30 AM", where a two-digit hour with AM beats the
    // "02:30 PM" that was sampled first - so with cell padding the floor is
    // 188px and the declaration is 12rem. The unresolved wording drops the word
    // "tour" for the same reason: the Stage column already says it.
    //
    // THE DECLARED MINIMUMS MUST SUM TO UNDER THE 1030px CONTENT BOX or the group
    // view overflows at 1280. They come to 64rem = 1024px:
    //   Child 11.5 + Centre 5 + Room 10 + Days 8.5 + Stage 7 + Urgency 12 + Next 10
    //
    // THE 1.5rem URGENCY NEEDED CAME FROM CHILD AND ROOM, NOT FROM NEXT STEP, and
    // that was measured too: taking it from Next step instead dropped it to 9rem
    // and the tallest row at 1280 went to 122px, which is the same too-tall row
    // the 11 Aug measurement was about. Room is the honest source - on the group
    // view it is already declared 3rem below its 227px natural and already wraps,
    // so a fourth rem changes its degree and not its character. Child at 11.5rem
    // is measured, not shaved: the longest name present renders 168px and takes
    // 184 with padding, which is exactly 11.5rem. Days is exact as well - the
    // strip itself is 122px, 138 with padding - so it has nothing to give.
    //
    // "LAST INTERACTED" (6 Nov 2026) COST ~94px, and at 1280 the table had none
    // to spare: 1044 in a 1030 box on a centre, 1137 on All centres. Paid for
    // from what was declared above its content, measured that day over every
    // item group-wide: Child 11.5 -> 11rem (longest name 157 + 16), Urgency
    // 12 -> 11.25rem (longest is "tour 13/11/2026 09:30 AM", 161 + 16). On All
    // centres also Room 10 -> 8rem and Next step 10 -> 8rem (both already
    // wrapped there), and Stage 7 -> 6.5rem with the badge allowed to wrap: 6.5
    // keeps "Tour booked" on one line and lets "Attempted / contact" break.
    var head = '<th style="min-width:11rem">Child</th>' +
      (CENTRE ? '' : '<th style="min-width:5rem">Centre</th>') +
      '<th style="min-width:' + (CENTRE ? '14rem' : '8rem') + '">Room</th>' +
      '<th style="width:8.5rem">Days</th>' +
      '<th style="min-width:' + (CENTRE ? '7rem' : '6.5rem') + '">Stage</th><th style="min-width:11.25rem">Urgency</th>' +
      '<th style="min-width:' + (CENTRE ? '7rem' : '8rem') + '">Next step</th>' +
      '<th>Last interacted</th>';

    $('#prog-list').html(
      '<div class="card"><div class="table-scroll ts-nocap">' +
      '<table class="table table-hover align-middle mb-0 table-sticky">' +
      '<thead class="table-light"><tr>' + head + '</tr></thead><tbody>' +
      data.items.map(row).join('') +
      '</tbody></table></div></div>');
  }

  // --- Opening one ------------------------------------------------------------

  // THE WHOLE POINT OF THE SCREEN. The Dashboard's table listed these and was not
  // clickable, so the only route to an item was eight steps through the board --
  // and you had to already know which cell it came from.
  $('#prog-list').on('click keydown', '.prog-row', function (e) {
    if (e.type === 'keydown' && e.key !== 'Enter' && e.key !== ' ') return;
    e.preventDefault();
    var href = $(this).data('href');
    // An untracked offer has no item to open: the answer is recorded on the
    // waitlist, where the offer was made.
    if (href) { window.location = href; return; }
    window.openWorkItem(+$(this).data('id'), load);
  });

  /**
   * Put the chosen filter in the URL, and in the centre bar's links.
   *
   * replaceState, not pushState - a chip is a view of one list, so it is not a
   * back-button step. The centre tabs are ordinary server-rendered links and
   * carry the filter the page LOADED with, so without the second half of this a
   * filter chosen here would be dropped by the next centre switch, which is the
   * whole complaint. `open` is the default and is removed rather than written.
   */
  function syncUrl() {
    var set = function (u) {
      if (filter === 'open') u.searchParams.delete('filter');
      else u.searchParams.set('filter', filter);
      if (mine) u.searchParams.set('mine', '1');
      else u.searchParams.delete('mine');
      return u.pathname + u.search;
    };
    window.history.replaceState(null, '', set(new URL(window.location.href)));
    $('.centre-tabs a').each(function () {
      this.href = set(new URL(this.href, window.location.origin));
    });
  }

  $('#prog-filters input[name=pf]').on('change', function () {
    filter = $(this).val();
    syncUrl();
    load();
  });
  $('#pf-mine').on('change', function () {
    mine = $(this).prop('checked');
    syncUrl();
    load();
  });

  // --- Booking a tour ---------------------------------------------------------

  // THE ONLY PLACE IN THE APP THAT CREATES A FAMILY FROM NOTHING. Everywhere else
  // a work item is raised against a queue application or a Kidsoft child; a
  // parent who has only rung up has neither, and before this there was nowhere to
  // put them at all.
  var enqModal = null;
  function enq() {
    enqModal = enqModal || new bootstrap.Modal(document.getElementById('enq-modal'));
    return enqModal;
  }

  $('#prog-new').on('click', function () {
    $('#enq-form')[0].reset();
    clearMatches();
    // No address named: a family found in the queue gets it at the one on file.
    $('#enq-tour-invite').html(tourInviteBox('enq-invite', null, false));
    // The centre bar's centre, when the view has one. On All centres the select
    // keeps whatever it was left on and the person picks - a tour belongs to a
    // building, and guessing which would be worse than asking.
    if (CENTRE) $('#enq-centre').val(String(CENTRE));
    enq().show();
  });

  // --- Already in the queue? -------------------------------------------------
  //
  // THE SAME FAMILY ON TWO ROWS is what this form used to make (Terrigal, 14 Oct
  // 2026): a tour typed in as a new enquiry for a child whose application had
  // been in the queue since July under the same phone number, then that
  // application started from the Waitlist as well. The server now answers
  // `queue_matches` when the phone or email belongs to an open application here,
  // and the person picks the child - the same phone matched a brother and a
  // sister, so the match alone cannot.
  var matches = [];
  function clearMatches() {
    matches = [];
    $('#enq-matches').addClass('d-none').empty();
  }
  // A different number is a different question.
  $('#enq-phone, #enq-email, #enq-centre').on('input change', clearMatches);

  function showMatches(list) {
    matches = list;
    // One row per child, the answer button beside it rather than under it: the
    // question is "which child?", and each row is one possible answer.
    $('#enq-matches').removeClass('d-none').html(
      '<div class="fw-semibold mb-1">Already in the queue here with that contact. ' +
        (list.length === 1 ? 'Is it this child?' : 'Is it one of these?') + '</div>' +
      list.map(function (m, i) {
        return '<div class="d-flex align-items-center gap-2 py-2 border-top">' +
          '<div class="flex-grow-1" style="min-width:0">' +
            '<div><span class="fw-semibold">' + esc(m.name) + '</span>' +
            (m.work ? ' <span class="badge ' + workStageClass(m.work.stage) + '">' +
              '<i class="fa-solid fa-list-check fa-xs me-1"></i>' + esc(m.work.stage_label) +
              '</span>' : '') + '</div>' +
            '<div class="small">born ' + esc(m.dob || '--') + ', waiting since ' +
              esc(m.date_added) + '</div></div>' +
          '<button type="button" class="btn btn-sm btn-primary flex-shrink-0 enq-use" data-i="' + i +
            '" title="' + esc(m.work ? 'Book the tour on the row they already have in progress'
                                     : 'Book the tour on their queue application') + '">' +
            'This child</button></div>';
      }).join('') +
      '<div class="border-top pt-2">' +
        '<button type="button" class="btn btn-sm btn-outline-secondary" id="enq-new-anyway">' +
        'None of these - a different child</button></div>');
    $('#enq-matches')[0].scrollIntoView({ block: 'nearest' });
  }

  // BOOK THE TOUR WHERE THE FAMILY ALREADY IS. On their item if one is open --
  // the `tour` action moves the appointment and advances the stage exactly as
  // the family modal does - and otherwise on the application, which creates the
  // item at Tour booked. No tour typed and an item already open: there is
  // nothing to book, so their row opens instead, where what they said goes.
  $('#enq-matches').on('click', '.enq-use', function () {
    var m = matches[+$(this).data('i')];
    if (!m) return;
    var $f = $('#enq-form');
    var tourAt = $f.find('[name=tour_at]').val();
    var note = $.trim($f.find('[name=note]').val());
    var req;
    if (m.work && !tourAt) {
      enq().hide();
      window.openWorkItem(m.work.id, load);
      return;
    }
    if (m.work) {
      req = $.post('/api/work', { action: 'tour', id: m.work.id, tour_at: tourAt, note: note,
                                  tour_invite: $('#enq-invite').prop('checked') ? 1 : 0 });
    } else {
      // Asks first for a Do Not Enrol family - see postConfirmingDne() in app.js.
      req = postConfirmingDne('/api/work', {
        action: 'create',
        centre_id: $f.find('[name=centre_id]').val(),
        year: m.for_year || '',
        waitlist_id: m.id,
        child_name: m.name,
        days: m.days,
        tour_at: tourAt,
        tour_invite: $('#enq-invite').prop('checked') ? 1 : 0,
        note: note
      });
    }
    req.done(function (res) {
      if (res && res.cancelled) return;
      if (!res || !res.ok) { toast((res && res.error) || 'Could not save that.', 'danger'); return; }
      enq().hide();
      toast(tourAt ? 'Booked on ' + m.name + "'s application." : m.name + ' added to In progress.');
      load();
    }).fail(function () { toast('Could not save that.', 'danger'); });
  });

  $('#enq-matches').on('click', '#enq-new-anyway', function () {
    submitEnquiry(true);
  });

  $('#enq-form').on('submit', function (e) {
    e.preventDefault();
    submitEnquiry(false);
  });

  function submitEnquiry(queueChecked) {
    var $f = $('#enq-form');
    var phone = $.trim($f.find('[name=contact_phone]').val());
    var email = $.trim($f.find('[name=contact_email]').val());
    // Checked here so the message lands next to the boxes rather than as a toast
    // over the top of them. The server refuses it too - work_create() is where
    // the rule actually lives, and this is the courtesy.
    if (!phone && !email) {
      $('#enq-reach').addClass('text-danger')
        .text('Add a phone number or an email, so somebody can ring them back.');
      return;
    }
    $('#enq-reach').removeClass('text-danger');

    $.post('/api/work', {
      action: 'create',
      centre_id: $f.find('[name=centre_id]').val(),
      child_name: $.trim($f.find('[name=child_name]').val()),
      contact_name: $.trim($f.find('[name=contact_name]').val()),
      contact_phone: phone,
      contact_email: email,
      tour_at: $f.find('[name=tour_at]').val(),
      tour_invite: $('#enq-invite').prop('checked') ? 1 : 0,
      note: $.trim($f.find('[name=note]').val()),
      // Only after the person has seen who is in the queue and said it is
      // none of them. The server asks again on any post without it.
      queue_checked: queueChecked ? 1 : ''
    })
      .done(function (res) {
        if (res.queue_matches) { showMatches(res.queue_matches); return; }
        if (!res.ok) { toast(res.error, 'danger'); return; }
        enq().hide();
        toast('Booked. It is on the tour list.');
        load();
      })
      .fail(function () { toast('Could not save that.', 'danger'); });
  }

  load();

  // ?item=<id> OPENS THAT ITEM (16 Oct 2026). The link in a tour's Outlook
  // calendar entry lands here, on the family it is about rather than a list to
  // search. The id is a request, not a grant: /api/work's `get` checks the
  // item's centre against the user's scope like any other open. Removed from the
  // URL once used, so a reload or a centre switch does not open it again.
  var itemParam = parseInt(new URLSearchParams(window.location.search).get('item'), 10);
  if (itemParam > 0) {
    var u = new URL(window.location.href);
    u.searchParams.delete('item');
    window.history.replaceState(null, '', u.pathname + u.search);
    window.openWorkItem(itemParam, load);
  }
})(jQuery);
