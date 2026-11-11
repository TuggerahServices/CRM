/**
 * The Dashboard - the enrolment process end to end.
 *
 * Two sources, one screen: what Kidsoft holds (the window) and what the team is
 * doing about the empty places (the work). Every number here is a step in the
 * chain that ends with a child in a room, which is the only step the
 * organisation is paid for.
 *
 * Read-only apart from the work items. The centre and the year live in the
 * chrome and are resolved server-side, so this file reads them once from fixed
 * inputs and never from a control.
 */
(function () {
  'use strict';

  var centreId = parseInt($('#init-centre').val(), 10) || 0;   // 0 = whole group
  // The year the ROOMS are measured in - always the current one. There is no
  // year control here on purpose; see the header of dashboard.php.
  var year     = parseInt($('#init-year').val(), 10);
  var data     = null;

  var esc     = window.esc     || function (s) { return $('<div>').text(s == null ? '' : s).html(); };
  var toast   = window.toast   || function (m) { console.log(m); };
  var spinner = window.spinner || function (m) { return '<div class="text-center text-muted py-5">' + esc(m) + '</div>'; };


  function plural(n, one, many) { return n === 1 ? one : (many || one + 's'); }

  /**
   * The centre on screen, when it has not opened in this year - else null.
   *
   * ONLY THE ROOM HALF OF THIS SCREEN IS AFFECTED, which is why this is a test
   * the render functions ask rather than an early return around all of them. The
   * queue, the pipeline, the tours and the work items are not year-scoped and
   * are the ONLY thing an unopened centre has: Warnervale has 17 families
   * waiting today. Hiding those would be the opposite of useful.
   */
  function notOpen() {
    return (centreId && data && data.centres && data.centres[0] &&
            data.centres[0].not_open) || null;
  }

  /** 1=Mon..5=Fri, matching WEEKDAYS in _incl/rooms.php. */
  var DAY_NAME = { 1: 'Mon', 2: 'Tue', 3: 'Wed', 4: 'Thu', 5: 'Fri' };

  function card(title, body, cls) {
    return '<div class="card mb-3 ' + (cls || '') + '"><div class="card-body">' +
      (title ? '<h2 class="h6 text-uppercase text-muted mb-3">' + esc(title) + '</h2>' : '') +
      body + '</div></div>';
  }

  // The shared stat tile, from app.js. There used to be five copies of this
  // across the modules and one of them looked different; see window.statTile.
  var tile = window.statTile;

  // A row of figures inside a card - the same shape wherever a card has a
  // handful of headline numbers rather than a table. It lived here until 18 Sep
  // 2026 and is now window.statStrip in app.js, because the Marketing screen's
  // by-weekday cut is the same shape and a second copy is how the Dashboard and
  // the Waitlist ended up with two different marks for one fact. Aliased rather
  // than renamed at all four call sites - the same treatment the marks got.
  var statStrip = window.statStrip;

  /**
   * Mon-Fri as five boxes, the ones in the set filled.
   *
   * "Wed Thu Fri" as running text is what the declines list used to print, and
   * five of them stacked read as a paragraph rather than as a pattern - which
   * weekday is free is the whole question. Boxes in a fixed position let the eye
   * compare one row against the next without reading a word. The full day names
   * stay in a title and in the aria-label, because M/T/W/T/F is ambiguous on its
   * own for anyone who has to read it out.
   */
  // NEUTRAL, NOT GREEN (Broc, 4 Sep 2026). These days ARE days the child holds,
  // so the strip's green was literally true - and on a card headed "Places
  // coming free" it read as "a place is held", which is the opposite of what the
  // card is for. This strip answers "which weekdays" and nothing else, so it
  // takes the state that means exactly that. See window.dayStrip().
  function freeDays(days) {
    return dayStrip({ plain: days || [], plainSaid: 'coming free' });
  }

  // --- Marks -----------------------------------------------------------------
  //
  // Four shapes, and every one of them obeys the same specs so the group view
  // reads as one system: thin marks (7-9px), a 4px rounded data-end that is
  // SQUARE at the baseline, a 2px gap in the surface colour separating touching
  // fills, and never a border drawn round a mark to do that separating.
  //
  // Colour does exactly one job per mark, and WHICH job decides the palette:
  //
  //   meter  -> SEVERITY. Status steps, and the unfilled track is a lighter step
  //             of the same hue so the state reads across the whole bar rather
  //             than only the filled part.
  //   bar    -> one series, so slot 1 of the identity pair (--lm-mark) and no
  //             legend: the column header names what is plotted.
  //   stack  -> ORDER. Answered -> yet to answer -> school age and no answer
  //             runs from resolved to unresolved-and-unknown, and swapping two
  //             of them would change the meaning. That makes it ordinal, so it
  //             takes a one-hue lightness ramp (--lm-ramp-1..3), NOT the
  //             purple/pink identity pair.
  //
  // Built with the pair, this stack also read as INVERTED against the waitlist's
  // overlap bar, which draws its highlighted subset pink-first at the baseline:
  // two thin stacked bars in the same app, the same two hues, opposite order,
  // for a reason no reader could see. The ramp settles it - purple and pink now
  // mean one thing each - and the ramp's gates are measured, not eyeballed
  // (monotone L, dL +0.202 / +0.183, light end 2.06:1, hue spread 3.2 deg).

  /** Thousands-separated integer. Never fed a date - see the tz rules. */
  function num(n) {
    if (n == null) return '--';
    return String(n).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
  }

  /**
   * How full, as a meter: fill plus the figure, and the track a lighter step of
   * the fill's own hue.
   *
   * Thresholds are the SAME as the summary tiles use, deliberately - a centre
   * reading amber in the table and green in a tile above it would be the
   * Board/Fill disagreement all over again, in colour.
   */
  function meter(pct, title) {
    if (pct == null) return '<span class="text-muted">--</span>';
    var cls = pct >= 98 ? 'dm-good' : (pct >= 90 ? 'dm-warn' : 'dm-bad');
    // Clamped, because a centre can read over 100% - Terrigal sits at 101 --
    // and a fill wider than its track would spill out of the rounded end.
    var w = Math.max(0, Math.min(100, pct));
    return '<span class="dm" title="' + esc(title || '') + '">' +
      '<span class="dm-track ' + cls + '"><span style="width:' + w + '%"></span></span>' +
      '<span class="dm-v">' + pctText(pct) + '</span></span>';
  }

  /**
   * The bar, the stack and the legend are SHARED with the Waitlist and live in
   * app.js (markBar / markStack / markLegend) - these three were duplicated
   * here in a second geometry, so the same kind of mark looked like two
   * different components depending on which screen you were on. Aliased rather
   * than renamed at every call site; the specs are in the MARKS block in
   * app.css, and the segment COLOURS stay here on purpose, because the round is
   * an ordered scale and takes a ramp where the Waitlist's overlap is a whole
   * and its part and takes the identity pair.
   */
  var bar = window.markBar, stack = window.markStack, legend = window.markLegend;

  // --- Notices ---------------------------------------------------------------

  /**
   * Only what the team cannot know unaided. A centre being closed is not news to
   * them and gets no banner (see the Tenure rules in CLAUDE.md); Kidsoft
   * refusing to answer IS, because it changes whether the numbers can be
   * trusted at all.
   */
  function renderNotices() {
    var out = '', t = data.totals;

    if (t.unreachable) {
      var names = data.centres.filter(function (c) { return c.state === 'unreachable'; })
                              .map(function (c) { return c.centre; });
      out += '<div class="alert alert-danger py-2"><i class="fa-solid fa-plug-circle-xmark me-2"></i>' +
        'Kidsoft did not answer for <strong>' + esc(names.join(', ')) + '</strong>, so ' +
        (names.length === 1 ? 'that centre is' : 'those centres are') +
        ' left out of the figures below rather than counted as empty.</div>';
    }

    // No banner about which year this is. Every figure names its own year in its
    // own label, because they genuinely differ - rooms are this year, the
    // re-enrolment round is next year, and the queue is not year-scoped at all.

    // ON THE GROUP VIEW EVERYTHING BELOW IS A RANKED ROW INSTEAD, and that is the
    // point of the change. Four alerts stacked at the top of the screen shout at
    // exactly equal volume, so the one with a date that has already passed looked
    // no more urgent than the one with a month to run - and two more of the same
    // kind of figure were meanwhile buried as columns in a table further down.
    // "Needs attention" puts all of them in one list, in deadline order.
    // A centre keeps its banners: it is a working screen, not a morning overview.
    if (!centreId) { $('#dash-notices').html(out); return; }

    // NO "APPLICATIONS LAPSE WITHIN 30 DAYS" BANNER (11 Nov 2026): Kidsoft's
    // waitlist expiry is turned off - see the note at the top of
    // _incl/dashboard.php. Same for its "Needs attention" row, the Lapsing
    // tile and the Lapsing column below.

    if (t.work_overdue) {
      out += '<div class="alert alert-danger d-flex flex-wrap align-items-center gap-2 py-2">' +
        '<i class="fa-solid fa-bell"></i><div class="flex-grow-1"><strong>' + t.work_overdue +
        '</strong> ' + plural(t.work_overdue, 'item is', 'items are') + ' past the date someone ' +
        'set for the next step.</div></div>';
    }
    $('#dash-notices').html(out);
  }

  // --- Summary ---------------------------------------------------------------

  function renderSummary() {
    var t = data.totals;
    var free  = t.free == null ? '--' : t.free;
    var ratio = t.free > 0 ? Math.round(t.waiting / t.free * 10) / 10 : null;

    // TWO OF THESE FOUR ARE ABOUT ROOMS and cannot be answered for a centre that
    // has not opened - they read "Places to fill 0, 0 room-day cells spare" and
    // "Filled -- , 0 of 0 place-days", which is a measurement nobody made. They
    // say the date instead. The other two are the queue and the work, which are
    // real today and are all this centre has.
    var n = notOpen();
    $('#dash-summary').html(
      tile('Places to fill', n ? '--' : free,
           n ? 'opens ' + n.opens
             : t.open_cells + ' room-day ' + plural(t.open_cells, 'cell') + ' spare in ' + year,
           n ? '' : (t.free ? 'text-danger' : 'text-success')) +
      tile('Families waiting', t.waiting,
           ratio ? ratio + ' waiting for every free place' : 'across the whole queue') +
      tile('In progress', t.work_open,
           t.work_overdue ? t.work_overdue + ' past their next-step date' : 'across every year',
           t.work_overdue ? 'text-danger' : '') +
      tile('Filled (' + year + ')', n ? '--' : pctText(t.pct),
           n ? 'not open in ' + year : t.placed + ' of ' + t.capacity + ' place-days',
           n ? '' : (t.pct >= 98 ? 'text-success' : (t.pct >= 90 ? 'text-warning' : 'text-danger')))
    );
  }

  // --- The pipeline ----------------------------------------------------------

  /**
   * Where every family stands right now.
   *
   * A SNAPSHOT, NOT A FUNNEL. There are no percentages between stages and there
   * must not be: the historical data cannot support them (see the header of
   * _incl/dashboard.php), and a rate on this screen would be read as this
   * organisation's performance when it would actually be measuring Kidsoft's
   * admin hygiene. Counts only, until the work layer has enough history of its
   * own.
   */
  function renderPipeline() {
    // Archived (6 Nov 2026) is closed without an outcome, so it is no more a
    // pipeline stage than Lost is.
    var stages = data.pipeline.filter(function (s) { return s.stage !== 'lost' && s.stage !== 'archived'; });
    var max = Math.max.apply(null, stages.map(function (s) { return s.n; }).concat([1]));
    var anyWork = data.totals.work_open > 0 ||
                  stages.some(function (s) { return s.n > 0; });

    var body;
    if (!anyWork) {
      // An empty pipeline is the honest state on day one, and saying so beats a
      // row of zeroes that looks like a broken screen. It does NOT explain what
      // a work item is or where to start one: the team knows their own job, and
      // an empty state is not the place to argue for the feature.
      body = '<p class="text-muted mb-0">Nobody is part-way through enrolling yet.</p>';
    } else {
      body = '<div class="d-flex flex-wrap gap-2 align-items-stretch pipeline">' +
        stages.map(function (s) {
          var pct = Math.round(s.n / max * 100);
          return '<div class="pipe-stage flex-fill" title="' + esc(s.help) + '">' +
            '<div class="small text-muted text-truncate">' + esc(s.label) + '</div>' +
            '<div class="fs-4 fw-semibold">' + s.n + '</div>' +
            '<div class="pipe-bar"><span style="width:' + pct + '%"></span></div></div>';
        }).join('') + '</div>' +
        '<p class="small text-muted mb-0 mt-2">Where each family stands today. ' +
        'Deliberately no conversion rates: nothing in the history can support one honestly yet. ' +
        '<strong>Enrolled</strong> is set by Kidsoft showing the child in the room, never by hand.</p>';
    }
    $('#dash-pipeline').html(card('Families part-way through enrolling', body));
  }

  // --- Centres ---------------------------------------------------------------

  /**
   * ONE CENTRE IS NOT A ONE-ROW TABLE. A table is a comparison device; with
   * nothing to compare against, the header row is pure overhead and four of its
   * six figures are already sitting in the tiles at the top of the page. So a
   * single centre gets what the tiles do NOT say - how hard the queue is
   * pressing on the free places, and how long it has been waiting - as a stat
   * strip. (It also said how much of the queue was about to lapse, until
   * Kidsoft's waitlist expiry was turned off on 11 Nov 2026.)
   *
   * The GROUP version of this comparison is renderCentresGroup(), further down.
   * There is deliberately no one function branching between the two: they are
   * different shapes, not one shape with a filter, and the page emits only one
   * set of containers anyway.
   */
  /** One centre: the pressure on its free places, not a table of one row. */
  function renderCentreOne(c) {
    // free is null when the centre could not be read at all, and 0 when it is
    // genuinely full - two different answers, and a ratio exists for neither.
    var ratio = c.free > 0 ? Math.round(c.waiting / c.free * 10) / 10 : null;
    var pressure;
    if (ratio != null) {
      pressure = { label: 'Waiting per free place', value: ratio.toFixed(1),
                   hint: c.waiting + ' waiting, ' + c.free + ' ' +
                         plural(c.free, 'place') + ' to fill' };
    } else if (c.free === 0) {
      pressure = { label: 'Waiting per free place', value: '--',
                   hint: c.waiting + ' waiting, nothing free in ' + year };
    } else {
      pressure = { label: 'Waiting per free place', value: '--',
                   hint: c.state === 'unreachable'
                           ? 'Kidsoft did not answer for this centre'
                           // A CENTRE THAT HAS NOT OPENED SAYS SO, with its date.
                           // It used to read "no places recorded for 2026", which
                           // is true and sounds like a data fault - see
                           // centre_year_state().
                           : (c.state === 'unopened' && c.opens_disp
                                ? 'opens ' + c.opens_disp
                                : 'no places recorded for ' + year) };
    }

    var body = statStrip([
      pressure,
      { label: 'Average wait', value: c.avg_wait == null ? '--' : c.avg_wait + 'd',
        hint: 'of the families in the queue' },
      { label: 'In progress', value: c.work_open || 0,
        cls: c.work_overdue ? 'text-danger' : '',
        hint: c.work_overdue ? c.work_overdue + ' past their next-step date'
                             : 'across every year' }
    ]);

    $('#dash-centres').html(card('Supply against demand (' + year + ')', body));
  }

  // --- Re-enrolment ----------------------------------------------------------

  /**
   * Next year forming, live.
   *
   * Two things nothing else in the app can see: a DECLINE is a place coming free
   * next year while there is still time to sell it - the only forward-looking
   * vacancy signal in the whole API - and the gap between families who have
   * accepted and families who actually have a booking, which is families who
   * believe they have a place.
   */
  function renderReenrolment() {
    var r = data.reenrolment;
    if (r.error && !r.centres.length) {
      $('#dash-reenrolment').html(card('Next year',
        '<p class="text-muted mb-0">Kidsoft could not be reached for the re-enrolment round.</p>'));
      return;
    }
    if (!r.centres.length) { $('#dash-reenrolment').html(''); return; }

    var forYear = r.centres[0].for_year;
    var gap = r.centres.reduce(function (n, c) { return n + c.unbooked; }, 0);

    var body =
      '<div class="mb-3">' + statStrip([
        { label: 'Accepted', value: r.accepted, hint: 'a place next year' },
        // No "in 2027" on the hints - the card is headed with the year, and the
        // extra four words were what pushed the fourth figure onto its own line
        // in the narrow half of the row.
        { label: 'Declined', value: r.declined, cls: 'text-warning',
          hint: r.place_days + ' place-days free' },
        // Phase one's work list: families who have not answered. Derived, not
        // read - Kidsoft only raises an event when somebody answers.
        { label: 'Yet to answer', value: r.to_chase, cls: r.to_chase ? 'text-danger' : '',
          hint: r.school_age + ' more are school age' },
        { label: 'Not yet booked', value: gap, cls: gap ? 'text-danger' : '',
          hint: 'said yes, no booking' }
      ]) + '</div>' +
      '<div class="table-scroll"><table class="table table-sm align-middle mb-0"><thead><tr>' +
      '<th>Centre</th><th class="text-center">To chase</th><th class="text-center">Accepted</th>' +
      '<th class="text-center">Booked</th><th class="text-center">Gap</th>' +
      '<th class="text-center">Declined</th></tr></thead><tbody>' +
      r.centres.map(function (c) {
        return '<tr><th class="fw-normal">' + esc(c.centre) + '</th>' +
          '<td class="text-center' + (c.to_chase ? ' fw-semibold' : '') + '">' +
            (c.to_chase || '-') +
            (c.school_age ? ' <span class="text-muted">+' + c.school_age + '</span>' : '') + '</td>' +
          '<td class="text-center">' + c.accepted + '</td>' +
          '<td class="text-center">' + c.booked + '</td>' +
          '<td class="text-center' + (c.unbooked ? ' text-danger fw-semibold' : '') + '">' +
            (c.unbooked || '-') + '</td>' +
          '<td class="text-center">' + (c.declined || '-') + '</td></tr>';
      }).join('') + '</tbody></table></div>' +
      declinesBlock(r, forYear) +
      '<p class="small text-muted mb-0 mt-2"><strong>To chase</strong> is families on the roll who ' +
      'have not answered; the grey figure beside it is school-age children who may not be ' +
      'returning at all. Kidsoft raises an event only when somebody answers, so this one is ' +
      'worked out rather than read. A <strong>decline</strong> is a place coming free in ' + forYear +
      ', knowable now. The <strong>gap</strong> is families who accepted and whose booking has ' +
      'not been created yet; they believe they have a place.</p>';

    $('#dash-reenrolment').html(card('Next year (' + forYear + ')', body));
  }

  /**
   * The declines themselves, named, with the room and the weekdays they free.
   *
   * This is the whole reason the endpoint is worth reading: a place coming free
   * next year, knowable in August, while there is still time to sell it. So it
   * is the one list on this screen that has to be scannable.
   *
   * It used to be a run of bold-name / grey-centre / room / "Wed Thu Fri" all on
   * one line, which turned thirteen sellable places into a wall of text. Four
   * aligned columns and a Mon-Fri strip let the eye pick out the Fridays without
   * reading a single word - and the centre column disappears when there is only
   * one centre on screen, because a column of one repeated value is noise.
   */
  function declinesBlock(r, forYear) {
    var list = r.declines_detail || [];
    if (!list.length) return '';

    return '<h3 class="h6 text-uppercase text-muted mt-4 mb-2">Places coming free in ' +
      forYear + '</h3>' + freeTable(list);
  }

  /**
   * The declines table itself. ONE implementation, used by the centre card and by
   * the group view's own card, so the two cannot drift - which is the mistake
   * that produced four screens rendering one grid from four sources.
   */
  function freeTable(list, nocap) {
    var showCentre = !centreId;
    return '<div class="table-scroll' + (nocap ? ' ts-nocap' : '') + '"><table class="table table-sm align-middle mb-0 dash-free">' +
      '<thead><tr><th>Child</th>' + (showCentre ? '<th>Centre</th>' : '') +
      '<th>Room</th><th>Days</th></tr></thead><tbody>' +
      list.map(function (d) {
        return '<tr>' +
          // THE NAME OPENS THE CHILD (6 Nov 2026) - the ChildID the decline was
          // matched to. An ambiguous or unmatched name has none and stays text.
          '<th class="fw-semibold">' + childName({ centre_id: d.centre_id || centreId, ks_id: d.ks_id,
                                                   name: d.child || 'Name not parsed' }) + '</th>' +
          (showCentre ? '<td class="text-muted">' + esc(d.centre) + '</td>' : '') +
          // Never guessed: an ambiguous or unmatched name is reported as such
          // rather than pinned on the wrong child, so it takes both columns and
          // says which of the two it is.
          (d.room
            ? '<td>' + roomIcon(d.room) + esc(d.room) + '</td><td class="dash-free-days">' + freeDays(d.days) + '</td>'
            : '<td colspan="2" class="text-muted fst-italic">' +
              (d.ambiguous ? 'two children share that name; check by hand'
                           : 'not matched to a room') + '</td>') +
          '</tr>';
      }).join('') + '</tbody></table></div>';
  }

  // --- The work list ---------------------------------------------------------

  /**
   * What has gone quiet. Ordered by neglect rather than by age, because an item
   * raised yesterday and already chased is in better shape than one raised a
   * month ago and forgotten - and a family nobody has rung for three weeks is
   * precisely the failure the spreadsheets had and nothing could see.
   */
  /**
   * Through to the module that can actually open these.
   *
   * `filter` NAMES THE CHIP the other screen should arrive on, because a link
   * out of a card that lists one kind of row and into a list showing a different
   * kind is a link that loses what it was pointing at - the tour card sent
   * people to the full open list with the tours they had just read nowhere on
   * it. Omitted means Open, In progress's own default.
   */
  function progLink(label, filter) {
    return '<a class="small" href="/progress?centre=' + centreId +
      (filter ? '&filter=' + filter : '') + '">' + label +
      ' <i class="fa-solid fa-arrow-right fa-xs"></i></a>';
  }

  /**
   * A child's name on a work item or a tour, as a way to THAT ITEM (6 Nov 2026):
   * In progress opens it on arrival, the same `?item=` the Outlook tour entries
   * use. The item, not the child's dialog, because the row is about the family
   * part-way through enrolling and most have no Kidsoft record yet.
   */
  function itemLink(w) {
    return '<a class="dash-item" href="/progress?centre=' + (+w.centre_id) + '&amp;item=' + (+w.id) + '">' +
      esc(w.child_name) + '</a>';
  }

  function renderWork() {
    // Not rendered at all on All centres - a work item belongs to a room, and a
    // room belongs to one centre, so the group view has no such list. dashboard.php
    // leaves the container out entirely rather than have this print a card whose
    // only content is an excuse for being empty.
    if (!centreId || !$('#dash-work').length) return;
    $('#dash-work').html(card('Enrolments needing attention', spinner('Loading...')));
    $.getJSON('/api/work', { action: 'list', centre_id: centreId, overdue: 1 })
      .done(function (res) {
        if (!res.ok) { $('#dash-work').html(card('Enrolments needing attention',
          '<p class="text-danger mb-0">' + esc(res.error) + '</p>')); return; }
        // PASSES overdue:1 NOW, so the card means what its title says. It used
        // to list everything open under the heading "needing attention", which
        // was already slightly untrue and would get worse with volume.
        if (!res.items.length) {
          $('#dash-work').html(card('Enrolments needing attention',
            '<p class="text-muted mb-1">Nothing is past its date at this centre.</p>' +
            progLink('See everything in progress')));
          return;
        }
        var body = '<div class="table-scroll"><table class="table table-sm align-middle mb-0"><thead><tr>' +
          '<th>Child</th><th>Room</th><th>Days</th><th>Stage</th><th class="text-center">Idle</th>' +
          '<th>Next step</th></tr></thead><tbody>' +
          res.items.map(function (w) {
            return '<tr' + (w.overdue ? ' class="table-warning"' : '') + '>' +
              // THE NAME OPENS THE ITEM on In progress, which opens it on arrival
              // (?item=). This screen does not load the work modal - see Tours.
              '<th class="fw-semibold">' + itemLink(w) + '</th>' +
              '<td>' + (w.room_name ? roomIcon(w.room_name) + esc(w.room_name) : '--') + '</td>' +
              // THE SAME ROWS In progress LISTS, so the same strip. It was
              // running text ("Mon Tue Wed") in a column headed Days while the
              // screen this card links to drew boxes for it.
              '<td>' + dayStrip({ want: w.days || [], empty: '<span class="text-muted">--</span>' }) + '</td>' +
              '<td><span class="badge text-bg-light border">' + esc(w.stage_label) + '</span></td>' +
              '<td class="text-center' + (w.idle_days >= 14 ? ' text-danger fw-semibold' : '') + '">' +
                (w.idle_days == null ? '-' : w.idle_days + 'd') + '</td>' +
              '<td class="small">' + esc(w.next_action || '') +
                (w.next_action_at ? ' <span class="text-muted">' +
                  (w.overdue ? 'was due ' : 'due ') + esc(w.next_action_at) + '</span>' : '') +
              '</td></tr>';
          }).join('') + '</tbody></table></div>';
        // The card is a SUMMARY, not the whole list - this was the only place
        // work items appeared and it was not clickable, so an item you could see
        // was still an item you could not open.
        //
        // IT LANDS ON THE SAME QUESTION IT ASKED. These rows are overdue:1, so
        // the link carries the Overdue chip and the label says so - it used to
        // read "the full list" and open everything, which dropped the very
        // filter the card was built on the moment you followed it.
        $('#dash-work').html(card('Enrolments needing attention',
          body + '<div class="mt-2">' + progLink('Open the due list', 'due') + '</div>'));
      })
      .fail(function () {
        $('#dash-work').html(card('Enrolments needing attention',
          '<p class="text-danger mb-0">Could not load the list.</p>'));
      });
  }

  // --- The tour diary ---------------------------------------------------------

  /**
   * TOURS: who is coming, and which visits nobody has answered.
   *
   * Rendered on BOTH views, unlike the work list beside it. A work item belongs
   * to a room and a room to one centre, so there is no group-wide version of
   * that - but a tour belongs to a CENTRE and nothing narrower (Broc, 14 Aug
   * 2026: centre only, no room), so "who is visiting the organisation this week"
   * is a question the group view can ask and the one the team opens the day on.
   *
   * READ-ONLY HERE, deliberately. Answering a tour is one click in the shared
   * work modal, which this screen does not load - and a second implementation of
   * that write would be a second chance to get the no-show rule wrong. The card
   * links through.
   *
   * The two blocks are two different jobs: `upcoming` is a reminder, `unresolved`
   * is a question that nothing else in the app will ever ask again.
   */
  function renderTours() {
    if (!$('#dash-tours').length) return;
    $('#dash-tours').html(card('Tours', spinner('Loading...')));
    var q = { action: 'tours' };
    if (centreId) q.centre_id = centreId;
    $.getJSON('/api/work', q)
      .done(function (res) {
        if (!res.ok) {
          $('#dash-tours').html(card('Tours', '<p class="text-danger mb-0">' + esc(res.error) + '</p>'));
          return;
        }
        var t = res.tours;
        if (!t.upcoming.length && !t.unresolved.length) {
          // Empty is the honest state on day one - the diary starts here, with
          // nothing to backfill. Saying so beats explaining what a tour is.
          $('#dash-tours').html(card('Tours',
            '<p class="text-muted mb-1">Nobody is booked in to visit.</p>' +
            progLink('Book one', 'tours')));
          return;
        }

        var line = function (x, ask) {
          return '<tr' + (ask ? ' class="table-warning"' : '') + '>' +
            '<td class="small text-nowrap">' + esc(fmtDateTime(x.tour_at)) + '</td>' +
            '<th class="fw-semibold">' + itemLink(x) +
              (x.contact_name ? '<div class="small text-muted fw-normal">' +
                esc(x.contact_name) + '</div>' : '') + '</th>' +
            (centreId ? '' : '<td class="small">' + esc(x.centre_name || '') + '</td>') +
            '<td class="small">' + esc(x.contact_phone || '') + '</td></tr>';
        };

        // THIS CARD IS A SUMMARY, so it caps - and it SAYS WHAT IT DROPPED
        // rather than trailing off, because a list that stops without saying so
        // reads as the whole list. `ts-nocap` is what stops it clipping instead:
        // the plain .table-scroll caps at 20rem of viewport-relative height,
        // which is right for a full-page table and cuts a row in half inside a
        // dashboard card.
        var CAP = 6;
        var block = function (list, cls) {
          var shown = list.slice(0, CAP);
          return '<div class="table-scroll ts-nocap ' + cls + '">' +
            '<table class="table table-sm align-middle mb-0"><tbody>' +
            shown.map(function (x) { return line(x, cls === 'mb-3'); }).join('') +
            '</tbody></table></div>' +
            (list.length > CAP
              ? '<p class="small text-muted mt-1 mb-0">and ' + (list.length - CAP) + ' more</p>'
              : '');
        };

        var body = '';
        if (t.unresolved.length) {
          body += '<p class="small mb-1"><strong>' + t.unresolved.length + '</strong> ' +
            plural(t.unresolved.length, 'tour has', 'tours have') +
            ' been and gone with nobody saying whether the family came.</p>' +
            block(t.unresolved, 'mb-3');
        }
        if (t.upcoming.length) {
          body += '<p class="small text-muted mb-1">Coming up</p>' + block(t.upcoming, '');
        }
        $('#dash-tours').html(card('Tours',
          body + '<div class="mt-2">' + progLink('Open the tour list', 'tours') + '</div>'));
      })
      .fail(function () {
        $('#dash-tours').html(card('Tours', '<p class="text-danger mb-0">Could not load the tours.</p>'));
      });
  }

  // ===========================================================================
  // THE GROUP VIEW
  //
  // This is the screen the team opens the day on, and it is a different job from
  // a centre's working screen: not "what is going on here" but "where should
  // anybody look first". So it is built in one order and it does not vary --
  //
  //   1. ONE figure. The places the organisation has to sell.
  //   2. What that obliges somebody to do, ranked by how hard the deadline is.
  //   3. Where those places are, against the demand pressing on them.
  //   4. Next year forming, which in August is most of the work there is.
  //   5. The two forward-looking lists, side by side.
  //
  // Every figure still names its own year, because they genuinely differ - see
  // the header of dashboard.php for why there is no year picker here.
  // ===========================================================================

  /**
   * The centres the figures are measured over: open, or opening this year, and
   * readable. `c.counts` is decided once by centre_year_state() in PHP - this
   * file must not re-derive it, or the hero and the engine's own totals would be
   * measured over two different sets of centres.
   */
  function comparable() {
    return data.centres.filter(function (c) { return c.counts; });
  }

  // --- 1. The lead figure ----------------------------------------------------

  /**
   * ONE hero figure per view, and this is it: the place-days the organisation is
   * not being paid for. Everything else on the screen is either a component of
   * it or an obstacle to closing it.
   *
   * It is a HERO rather than one of four equal tiles because four equal tiles say
   * "these matter the same amount", and on a morning overview they do not. The
   * fill percentage beside it is the same fact as a rate, which is what makes the
   * absolute number readable - 111 free place-days means nothing without 2,720.
   */
  function renderHero() {
    var t = data.totals, q = data.queue || {};
    var open = comparable().length;

    // Where those places actually are. Named rather than characterised: "biggest
    // gaps" is true whatever the distribution, where "mostly at" would quietly
    // become false the day the spread evens out.
    var top = comparable().slice().sort(function (a, b) { return b.free - a.free; })
                .filter(function (c) { return c.free > 0; }).slice(0, 2);
    var where = top.length
      ? 'Biggest gaps: ' + top.map(function (c) { return c.centre + ' ' + c.free; }).join(', ')
      : '';

    var ratio = t.free > 0 ? Math.round(t.waiting / t.free * 10) / 10 : null;

    var body =
      '<div class="dash-hero">' +
        '<div class="dhero-fig">' +
          '<div class="text-muted small">Places to fill in ' + year + '</div>' +
          '<div class="dhero-n">' + num(t.free) + '</div>' +
          '<div class="small text-muted">place-days a week, over ' + t.open_cells +
            ' room-day ' + plural(t.open_cells, 'cell') + ' in ' + open + ' ' +
            plural(open, 'centre') + '</div>' +
          (where ? '<div class="small text-muted mt-1">' + esc(where) + '</div>' : '') +
        '</div>' +
        '<div class="dhero-rest">' +
          '<div class="dhero-stat">' +
            '<div class="text-muted small">Filled in ' + year + '</div>' +
            '<div class="dhero-meter">' + meter(t.pct,
              num(t.placed) + ' of ' + num(t.capacity) + ' place-days') + '</div>' +
            '<div class="small text-muted">' + num(t.placed) + ' of ' + num(t.capacity) +
              ' place-days</div>' +
          '</div>' +
          // FAMILIES, NOT APPLICATIONS - and only the group view can tell the
          // difference. A centre's own queue holds few duplicates (a handful,
          // flagged red on the Waitlist - waitlist_duplicates()); across seven of
          // them, families apply in several places at once, so summing the seven
          // overstates the families being held. The merge rule lives in
          // waitlist-group.php and is deliberately not the search palette's.
          '<div class="dhero-stat">' +
            '<div class="text-muted small">Families waiting</div>' +
            '<div class="dhero-v">' + num(q.families != null ? q.families : t.waiting) + '</div>' +
            '<div class="small text-muted">' +
              (q.families != null
                ? num(q.applications) + ' applications, ' + q.multi + ' at more than one centre'
                : 'across the whole queue') + '</div>' +
          '</div>' +
          '<div class="dhero-stat">' +
            '<div class="text-muted small">Waiting per free place</div>' +
            '<div class="dhero-v">' + (ratio == null ? '--' : ratio.toFixed(1)) + '</div>' +
            '<div class="small text-muted">' +
              (ratio == null ? 'nothing free to measure against'
                             : 'families for each free place-day') + '</div>' +
          '</div>' +
        '</div>' +
      '</div>';

    $('#dash-hero').html('<div class="card mb-3"><div class="card-body">' + body + '</div></div>');
  }

  // --- 2. What needs doing ---------------------------------------------------

  /**
   * The ranked list that replaced the stack of banners.
   *
   * ORDERED BY HOW HARD THE DEADLINE IS, NOT BY SIZE. A date somebody set and has
   * already missed comes first; then a 30-day clock Kidsoft is running on our
   * behalf; then the round, which has a season; then the two opportunities, which
   * have no clock at all. Ranking these by their counts instead would put 253 at
   * the top, and whether that 253 is normal batching or a backlog is a judgement
   * the team makes - CLAUDE.md is explicit that the screen must not guess.
   *
   * Every row carries an icon and words as well as a colour, because these are
   * status colours and a status is never encoded as colour alone.
   */
  function renderAttention() {
    var t = data.totals, r = data.reenrolment, rows = [];

    if (t.work_overdue) {
      // No group-wide work list exists to link to - a work item belongs to a
      // room and a room to one centre - so the centres holding them are named
      // and each is a link into its own dashboard.
      var late = data.centres.filter(function (c) { return c.work_overdue > 0; });
      rows.push({
        sev: 'bad', icon: 'fa-bell', n: t.work_overdue,
        text: plural(t.work_overdue, 'item is', 'items are') + ' past the date somebody set ' +
              'for the next step.',
        links: late.map(function (c) {
          return { href: '/dashboard?centre=' + c.centre_id,
                   label: c.centre + ' (' + c.work_overdue + ')' };
        })
      });
    }

    if (r.to_chase) {
      rows.push({
        sev: 'warn', icon: 'fa-comment-dots', n: r.to_chase,
        text: 'families on the roll have not answered next year\'s offer' +
              (r.school_age ? ', and ' + r.school_age + ' more are school age' : '') +
              '. Kidsoft raises an event only when somebody answers, so this is worked ' +
              'out rather than read.',
        anchor: { href: '#dash-round', label: 'The round' }
      });
    }

    // Reported, never characterised. Six days into a round this is batching and
    // in November it is a backlog; the team knows which and the screen does not.
    var gap = r.centres.reduce(function (n, c) { return n + c.unbooked; }, 0);
    if (gap) {
      rows.push({
        sev: 'info', icon: 'fa-file-circle-question', n: gap,
        text: 'families have accepted a place for ' + nextYear() +
              ' and have no booking against it yet.',
        anchor: { href: '#dash-gap', label: 'By centre' }
      });
    }

    if (r.declined) {
      rows.push({
        sev: 'info', icon: 'fa-door-open', n: r.declined,
        text: 'families have declined ' + nextYear() + ', freeing ' + r.place_days +
              ' place-days. It is the only notice this API gives of a place before it ' +
              'is empty.',
        anchor: { href: '#dash-free', label: 'Which places' }
      });
    }

    if (!rows.length) {
      $('#dash-attention').html(card('Needs attention',
        '<p class="text-muted mb-0">Nothing with a date against it.</p>'));
      return;
    }

    var body = '<div class="dattn">' + rows.map(function (x) {
      var act = '';
      if (x.btn) {
        act = '<a class="btn btn-sm btn-outline-primary" href="' + x.btn.href + '">' +
              esc(x.btn.label) + '</a>';
      } else if (x.anchor) {
        act = '<a class="dattn-jump" href="' + x.anchor.href + '">' + esc(x.anchor.label) +
              ' <i class="fa-solid fa-arrow-down-long"></i></a>';
      } else if (x.links) {
        act = '<span class="dattn-links">' + x.links.map(function (l) {
          return '<a href="' + l.href + '">' + esc(l.label) + '</a>';
        }).join('') + '</span>';
      }
      return '<div class="dattn-row">' +
        '<span class="dattn-ico dattn-' + x.sev + '"><i class="fa-solid ' + x.icon + '"></i></span>' +
        // Coloured for the two states that mean something is going wrong; ink
        // for the informational rows, where purple would read as a link.
        '<span class="dattn-n' + (x.sev === 'info' ? '' : ' dattn-n-' + x.sev) + '">' +
          num(x.n) + '</span>' +
        '<span class="dattn-txt">' + x.text + '</span>' +
        '<span class="dattn-act">' + act + '</span></div>';
    }).join('') + '</div>';

    $('#dash-attention').html(card('Needs attention', body));
  }

  /** The year the re-enrolment round decides. Named by the data, never date+1. */
  function nextYear() {
    return (data.reenrolment.centres[0] || {}).for_year || (year + 1);
  }

  // --- 3. Supply against demand ----------------------------------------------

  /**
   * The comparison the Placement overview cannot make, because it only sees one
   * side. Ranked by places to fill, so the top of the list is where a phone call
   * can actually put a child - but the queue is beside it, because a centre with
   * nothing free and 125 families waiting is a completely different problem from
   * one with 40 free and 60 waiting, and ranking on either figure alone gives the
   * opposite instruction to ranking on the other.
   *
   * TWO MEASURES OF DIFFERENT SCALE NEVER SHARE AN AXIS. Free place-days (0-56)
   * and families waiting (9-222) are not the same unit and one of them would have
   * to be squashed against the other's maximum, inventing a comparison that is
   * not in the data. They get a column each, each scaled to its own maximum and
   * named in its own header.
   */
  function renderCentresGroup() {
    // THE CENTRE BAR'S ORDER, with a centre not open this year last (Broc, 27
    // Oct 2026). The server sends centres_all() order and sort() is stable, so
    // only those rows move. It was ranked by places to fill.
    var rows = data.centres.slice().sort(function (a, b) {
      return (a.state === 'unopened') - (b.state === 'unopened');
    });

    // Scaled over COMPARABLE centres only, the way the tenure ranking is: a
    // centre that is not trading must not stretch every real centre's bar. The
    // queue is real whether or not the rooms are open, so it scales over all.
    var maxFree = Math.max.apply(null, comparable().map(function (c) { return c.free; }).concat([1]));
    var maxWait = Math.max.apply(null, data.centres.map(function (c) { return c.waiting; }).concat([1]));

    var body = '<div class="table-scroll ts-nocap"><table class="table align-middle mb-0 dgrid">' +
      '<thead><tr>' +
      '<th>Centre</th>' +
      '<th class="dgrid-mark">Filled</th>' +
      '<th class="dgrid-mark">To fill</th>' +
      '<th class="dgrid-mark">Waiting</th>' +
      '<th class="text-end" title="Families waiting for each free place-day">Per place</th>' +
      '<th class="text-end" title="How long the families in this queue have been waiting">' +
        'Avg wait</th>' +
      '<th class="text-end">Working</th>' +
      '</tr></thead><tbody>' +
      rows.map(function (c) {
        // A centre we cannot measure gets no figures, no marks and no rank. It
        // gets NO MARKER EITHER: a centre being shut is not news to the people
        // who run it. A row of "--" says it without commentary. A centre Kidsoft
        // would not answer for keeps a reason in a title, because that one is
        // about the DATA and it is also named in the banner at the top.
        var why = c.state === 'unreachable' ? 'Kidsoft did not answer for this centre'
                : (c.state === 'unopened' && c.opens_disp ? 'Opens ' + c.opens_disp : null);
        var dim = !c.counts;
        var ratio = c.free > 0 ? (c.waiting / c.free) : null;

        return '<tr' + (dim ? ' class="text-muted"' : '') + '>' +
          '<th class="fw-semibold"><a href="/placement?centre=' + c.centre_id + '&year=' + year +
            '" class="ov-centre-link">' + esc(c.centre) + '</a></th>' +
          '<td class="dgrid-mark">' + (c.pct == null
            ? '<span title="' + esc(why || '') + '">--</span>'
            : meter(c.pct, c.centre + ': ' + num(c.placed) + ' of ' + num(c.capacity) +
                           ' place-days filled')) + '</td>' +
          '<td class="dgrid-mark">' + (c.free == null
            ? '--'
            : bar(c.free, maxFree, c.free + ' place-days free over ' + c.open_cells +
                                   ' room-day ' + plural(c.open_cells, 'cell'))) + '</td>' +
          '<td class="dgrid-mark">' + bar(c.waiting, maxWait,
            c.waiting + ' open ' + plural(c.waiting, 'application') + ' at ' + c.centre) + '</td>' +
          '<td class="text-end dgrid-fig' + (ratio != null && ratio >= 10 ? ' fw-semibold' : '') + '">' +
            (ratio == null
              ? '<span title="' + (c.free === 0 ? 'nothing free in ' + year : esc(why || 'not measurable')) +
                '">--</span>'
              : (Math.round(ratio * 10) / 10).toFixed(1)) + '</td>' +
          '<td class="text-end dgrid-fig">' + (c.avg_wait == null ? '-' : c.avg_wait + 'd') + '</td>' +
          '<td class="text-end dgrid-fig">' + (c.work_open || '-') +
            (c.work_overdue ? ' <span class="badge text-bg-danger">' + c.work_overdue + '</span>' : '') +
          '</td></tr>';
      }).join('') + '</tbody></table></div>';

    $('#dash-centres').html(card('Supply against demand (' + year + ')', body));
  }

  // --- 4. Next year forming --------------------------------------------------

  /**
   * How far through the re-enrolment round each centre is.
   *
   * In August this is most of the work there is: the round opened on 1 August and
   * offers next year to the families already in care first, because minimising
   * churn is a standing objective and they have first claim on the places. So it
   * gets a bar per centre rather than a column of counts.
   *
   * THE BAR IS BUILT ON `answered`, NOT ON `accepted` + `declined`, and the
   * difference is not cosmetic - see the comment in _incl/dashboard.php. Only
   * `answered` satisfies roll = answered + ageing out + to chase + school age
   * exactly, and a part-to-whole mark whose segments do not sum to its own
   * denominator is the one thing this form must never be.
   *
   * The denominator is the roll LESS the children ageing out, because a child
   * with no room to move up into was never part of the round - counting them as
   * "yet to answer" would invent 138 families nobody is waiting on.
   */
  function renderRound() {
    var r = data.reenrolment;
    if (r.error && !r.centres.length) {
      $('#dash-round').html(card('Next year',
        '<p class="text-muted mb-0">Kidsoft could not be reached for the re-enrolment round.</p>'));
      return;
    }
    if (!r.centres.length) { $('#dash-round').html(''); return; }

    var forYear = nextYear();
    var gap = r.centres.reduce(function (n, c) { return n + c.unbooked; }, 0);
    var inRound = r.roll - r.cannot_stay;

    var keys = legend([
      { cls: 'dseg-done',   label: 'Answered' },
      { cls: 'dseg-todo',   label: 'Yet to answer' },
      { cls: 'dseg-school', label: 'School age, no answer' }
    ]);

    var body =
      '<div class="mb-3">' + statStrip([
        { label: 'Accepted', value: num(r.accepted), hint: 'a place next year' },
        // NOT text-warning, for two reasons. The house amber resolves to #8a6100
        // to be legible as text, and at this size that dark yellow is mustard --
        // the same complaint as the attention counts. And a decline is not a
        // warning: it is a place coming free while there is still time to sell
        // it, which is the most useful thing this card says.
        { label: 'Declined', value: r.declined,
          hint: r.place_days + ' place-days free' },
        { label: 'Yet to answer', value: r.to_chase, cls: r.to_chase ? 'text-danger' : '',
          hint: r.school_age + ' more are school age' },
        { label: 'Not yet booked', value: gap, cls: gap ? 'text-danger' : '',
          hint: 'said yes, no booking' }
      ]) + '</div>' +

      '<div class="d-flex flex-wrap justify-content-between align-items-end gap-2 mb-2">' +
        '<h3 class="h6 text-uppercase text-muted mb-0">Round progress</h3>' + keys + '</div>' +

      '<div class="table-scroll ts-nocap"><table class="table align-middle mb-0 dgrid">' +
      '<thead><tr><th>Centre</th>' +
      '<th class="dgrid-stack" title="Share of the families who could be offered a place: ' +
        'this centre\'s roll less the children ageing out">Progress</th>' +
      '<th class="text-end">Answered</th><th class="text-end">To chase</th>' +
      '<th class="text-end">School age</th>' +
      '<th class="text-end" title="Past every room this centre runs, so never part of the round">' +
        'Ageing out</th>' +
      '<th class="text-end">Roll</th></tr></thead><tbody>' +
      r.centres.map(function (c) {
        var inr = c.roll - c.cannot_stay;
        return '<tr' + (c.roll ? '' : ' class="text-muted"') + '>' +
          '<th class="fw-normal">' + esc(c.centre) + '</th>' +
          '<td class="dgrid-stack">' + stack([
            { n: c.answered,   cls: 'dseg-done',   label: 'Answered' },
            { n: c.to_chase,   cls: 'dseg-todo',   label: 'Yet to answer' },
            { n: c.school_age, cls: 'dseg-school', label: 'School age, no answer' }
          ]) + '</td>' +
          '<td class="text-end dgrid-fig">' + (c.answered || '-') +
            (inr > 0 ? ' <span class="text-muted small">' +
              pctText(window.fillPct(c.answered, inr)) + '</span>' : '') + '</td>' +
          '<td class="text-end dgrid-fig' + (c.to_chase ? ' fw-semibold' : '') + '">' +
            (c.to_chase || '-') + '</td>' +
          '<td class="text-end dgrid-fig">' + (c.school_age || '-') + '</td>' +
          '<td class="text-end dgrid-fig">' + (c.cannot_stay || '-') + '</td>' +
          '<td class="text-end dgrid-fig">' + (c.roll || '-') + '</td></tr>';
      }).join('') + '</tbody></table></div>' +

      '<p class="small text-muted mb-0 mt-2"><strong>' + num(r.answered) + ' of ' +
        num(inRound) + '</strong> families who can be offered a place next year have ' +
        'answered. <strong>Answered</strong> counts children on this year\'s roll matched to ' +
        'an answer, so it can sit a family or two either side of the Accepted and Declined ' +
        'figures above, which count Kidsoft\'s notifications instead.</p>';

    $('#dash-round').html(card('Next year (' + forYear + ')', body));
  }

  // --- 5. The two forward-looking lists --------------------------------------

  /**
   * Families who accepted next year and have no booking behind them.
   *
   * An acceptance is a promise; a booking is the place. Nothing in this app, or
   * in Kidsoft's own screens, showed the difference before - and the figure is
   * lopsided in a way only the group view exposes: measured 10 Aug 2026, Terrigal
   * had 50 acceptances and no bookings at all while Tuggerah had 161 of 166 done.
   * That is a per-centre fact, so it is a per-centre list.
   */
  function renderGap() {
    var r = data.reenrolment;
    // In the centre bar's order, as dash_reenrolment() sends them (27 Oct 2026).
    var rows = r.centres.filter(function (c) { return c.unbooked > 0; });
    if (!rows.length) {
      $('#dash-gap').html(card('Accepted, not yet booked',
        '<p class="text-muted mb-0">Every acceptance has a booking against it.</p>'));
      return;
    }
    var max = Math.max.apply(null, rows.map(function (c) { return c.unbooked; }));

    var body = '<div class="table-scroll ts-nocap">' +
      '<table class="table table-sm align-middle mb-0 dgrid"><thead><tr>' +
      '<th>Centre</th><th class="dgrid-mark">Not booked</th>' +
      '<th class="text-end">Accepted</th><th class="text-end">Booked</th>' +
      '</tr></thead><tbody>' +
      rows.map(function (c) {
        return '<tr><th class="fw-normal">' + esc(c.centre) + '</th>' +
          '<td class="dgrid-mark">' + bar(c.unbooked, max, c.centre + ': ' + c.unbooked +
            ' of ' + c.accepted + ' acceptances have no booking') + '</td>' +
          '<td class="text-end dgrid-fig">' + c.accepted + '</td>' +
          '<td class="text-end dgrid-fig">' + c.booked + '</td></tr>';
      }).join('') + '</tbody></table></div>' +
      '<p class="small text-muted mb-0 mt-2">These families believe they have a place next ' +
      'year and next year\'s bookings do not say so yet.</p>';

    $('#dash-gap').html(card('Accepted, not yet booked', body));
  }

  /**
   * The declines, named, with the room and the weekdays they free.
   *
   * The only forward-looking vacancy signal in the whole API: every other
   * departure signal this app can see is retrospective. So it is the one list
   * that has to be scannable, which is what the Mon-Fri strip is for.
   */
  function renderFree() {
    var r = data.reenrolment;
    var list = r.declines_detail || [];
    var title = 'Places coming free in ' + nextYear();
    if (!list.length) {
      $('#dash-free').html(card(title,
        '<p class="text-muted mb-0">Nobody has declined next year yet.</p>'));
      return;
    }
    $('#dash-free').html(card(title, freeTable(list, true) +
      '<p class="small text-muted mb-0 mt-2">' + list.length + ' ' +
      plural(list.length, 'child', 'children') + ', ' + r.place_days +
      ' place-days, knowable now rather than in February.</p>'));
  }

  // --- What's changing this fortnight -----------------------------------------

  /**
   * The week-to-week movement, as a list of children: every change dated from
   * Monday of this week to Friday of next (Broc, 4 Nov 2026 - it was "What
   * changes next week" until then). The rows are the one-week card's for three
   * weeks running, filtered to the fortnight server-side; see week_ahead().
   *
   * THERE IS NO PROJECTED % HERE AND THAT IS THE WHOLE DESIGN. Broc asked for one
   * (15 Sep 2026) and it was measured before it was built: group fill reads 95.2%
   * this week and 95.1% next, six of seven centres move by 0 or 1 place-day, and
   * it would have been a SECOND fill figure disagreeing with the board's - which
   * reports what is SOLD for the year, not who is in the room next week. See the
   * header of _incl/week-ahead.php. The movements underneath it are real and are
   * visible on no other screen: Niagara Park has 8 of next week's 12 booking
   * changes and its fill % is identical in both weeks.
   *
   * THE DAY STRIP CARRIES THE DIRECTION, which is the one thing this card needs
   * colour for. Days a child TAKES UP are green - the strip's "attends" state,
   * literally true - and days they GIVE UP are the neutral grey the "Places
   * coming free" list uses. That is the opposite of the trap the declines list
   * fell into, where green on a card about vacancies read as "a place is held":
   * here both directions appear on the same card, so the pair IS the meaning.
   */
  var WEEK_KIND = {
    leaving:    { label: 'Ending care',      icon: 'fa-right-from-bracket', cls: 'text-danger'  },
    starting:   { label: 'Starting',         icon: 'fa-right-to-bracket',   cls: 'text-success' },
    moving:     { label: 'Moving rooms',     icon: 'fa-arrow-right-arrow-left', cls: 'text-body-secondary' },
    fewer_days: { label: 'Dropping a day',   icon: 'fa-minus',              cls: 'text-warning' },
    more_days:  { label: 'Taking more days', icon: 'fa-plus',               cls: 'text-success' }
  };

  var WEEK_TITLE = "What's changing this fortnight";

  /**
   * The room a child is moving FROM, in the team's shorthand: "RR Silver" for
   * Radiant Rainbows Silver (Broc, 4 Nov 2026, to narrow this card). Only the
   * muted half of a move is shortened - the room they are going to stays in
   * full, with its mark, and the full name of this one is on hover.
   *
   * Keyed on the FIRST WORD, the same rule room_type_key() uses to group the
   * tiers, so Tuggerah's "Amazing Asteroid Purple" and Warnervale's "Amazing
   * Asterouds" both read AA. Every active room is two family words and an
   * optional colour, so the colour is what survives. A family not listed here
   * prints in full rather than guessing.
   */
  var ROOM_ABBR = { little: 'LA', radiant: 'RR', shining: 'SS', amazing: 'AA', mighty: 'MM' };
  function roomAbbr(name) {
    var words = String(name == null ? '' : name).trim().split(/\s+/);
    var abbr = ROOM_ABBR[words[0].toLowerCase()];
    return abbr ? [abbr].concat(words.slice(2)).join(' ') : words.join(' ');
  }

  /**
   * The figures above the list. In the dialog they are the WHOLE RANGE, never
   * the page on screen - a page is an arbitrary slice of a list sorted by kind,
   * and its own counts would say nothing.
   */
  function weekHead(t) {
    t = t || {};
    return statStrip([
      { label: 'Starting',    value: t.starting   || 0, cls: t.starting   ? 'text-success' : 'text-muted' },
      { label: 'Ending care', value: t.leaving    || 0, cls: t.leaving    ? 'text-danger'  : 'text-muted' },
      { label: 'Moving room', value: t.moving     || 0, cls: 'text-body' },
      { label: 'Day changes', value: (t.more_days || 0) + (t.fewer_days || 0), cls: 'text-body' },
      // The net effect on what can be sold, which is the only figure on this
      // card that is not a count of children. Signed, because the sign is the
      // whole point: a week that frees two place-days and a week that sells two
      // are the same size and opposite instructions.
      { label: 'Place-days',
        value: (t.place_days > 0 ? '+' : '') + (t.place_days || 0),
        cls: t.place_days > 0 ? 'text-success' : (t.place_days < 0 ? 'text-danger' : 'text-muted') }
    ]);
  }

  /** The changes table - the card's, and one page of the dialog's. */
  function weekTable(list, showCentre) {
    return '<div class="table-scroll ts-nocap mt-3"><table class="table table-sm align-middle mb-0 dash-free dweek">' +
      '<thead><tr><th>Child</th>' + (showCentre ? '<th>Centre</th>' : '') +
      '<th>Room</th><th>Days</th><th>When</th><th>Change</th></tr></thead><tbody>' +
      list.map(function (r) {
        var k = WEEK_KIND[r.kind] || { label: r.kind, icon: 'fa-circle', cls: '' };
        // Green for days taken up, neutral for days given up - see above.
        var gives = (r.kind === 'leaving' || r.kind === 'fewer_days');
        var strip = gives
          ? dayStrip({ plain: r.days, plainSaid: 'coming free' })
          : dayStrip({ has: r.days });
        // POINT AT THE CELL. A room and a weekday named in a table above a grid
        // that has a square for exactly that is the shopping-list fault the
        // coming-free banner had to unlearn twice. ON THE BOARD OF THE CHANGE'S
        // OWN YEAR: a January move belongs to next year's board, and the
        // dialog's range runs to the end of next year. `on` is a Y-m-d string,
        // so its first four characters are the year - no Date involved.
        var href = '/placement?centre=' + r.centre_id + '&year=' + (r.on ? r.on.substr(0, 4) : year) +
                   (r.room_id ? '&room=' + r.room_id : '') +
                   (r.days && r.days.length ? '&wd=' + r.days[0] : '');
        var room = (r.from_room
              ? '<span class="text-muted" title="' + esc(r.from_room) + '">' +
                  esc(roomAbbr(r.from_room)) + '</span>' +
                ' <i class="fa-solid fa-arrow-right fa-2xs text-muted"></i> '
              : '') + roomIcon(r.room) + esc(r.room || '');

        // THE NAME OPENS THE CHILD (6 Nov 2026) - who is starting, leaving or
        // changing days, with their parents and next year one click away. The
        // room beside it still points at the cell. week_ahead() already carries
        // the ChildID Kidsoft issued at this row's centre.
        return '<tr>' +
          '<th class="fw-semibold">' + childName({ centre_id: r.centre_id, ks_id: r.ks_id, name: r.name }) + '</th>' +
          (showCentre ? '<td class="text-muted">' + esc(r.centre) + '</td>' : '') +
          '<td><a class="ov-centre-link" href="' + href + '">' + room + '</a></td>' +
          '<td class="dash-free-days">' + strip + '</td>' +
          // The date is ALREADY FORMATTED server-side - Sydney wall-clock, never
          // through new Date(). A departure is dated by its last day and
          // everything else by its first, so the cell carries which in its
          // title; the Change column beside it says the same thing in words.
          '<td class="dgrid-fig" title="' + esc(r.on_said || '') + '">' +
            esc(r.on_disp || '--') + '</td>' +
          '<td class="small ' + k.cls + ' text-nowrap">' +
            '<i class="fa-solid ' + k.icon + ' fa-xs"></i> ' + esc(k.label) +
            // Kidsoft's exit reason, when the child has been exited - see
            // week_ahead(). Under the label rather than beside it: this is the
            // column that absorbs the spare width, and a reason can run to
            // "Change of mind before commencing care".
            (r.exit_reason ? '<div class="dweek-reason text-body-secondary">' + esc(r.exit_reason) + '</div>' : '') +
          '</td>' +
          '</tr>';
      }).join('') + '</tbody></table></div>';
  }

  /**
   * A centre the weeks could not be read for, and a centre shut for a week of
   * the range, are DIFFERENT ANSWERS and neither may be reported as children
   * leaving - the same three-state rule dash_trading() follows. The shutdown
   * case is the one that would otherwise fire every December: bookings stop
   * dead after the 24th, so every child in the organisation would read as
   * ending care.
   */
  function weekNotes(w, span) {
    var out = '';
    (w.unreadable || []).forEach(function (c) {
      out += '<p class="small text-muted mb-0 mt-2">Kidsoft would not answer for ' +
        esc(c.centre) + ', so ' + span + ' is not counted here.</p>';
    });
    (w.closed || []).forEach(function (c) {
      out += '<p class="small text-muted mb-0 mt-2">' + esc(c.centre) +
        ' has no bookings in the week of ' + esc(c.week_disp) + '.</p>';
    });
    return out;
  }

  /**
   * The card's title line, with "View more..." at its right (Broc, 6 Nov
   * 2026) - the shared title-and-extra row the conventions name, so the title
   * itself stays the muted uppercase heading every other card has.
   */
  function weekCard(title, body) {
    return card('',
      '<div class="d-flex flex-wrap justify-content-between align-items-end gap-2 mb-3">' +
        '<h2 class="h6 text-uppercase text-muted mb-0">' + title + '</h2>' +
        '<a href="#" class="small text-nowrap" role="button" id="wk-more">View more...</a>' +
      '</div>' + body);
  }

  function renderWeek() {
    var w = data.week || {};
    var list = w.changes || [];

    if (w.error) {
      $('#dash-week').html(card(WEEK_TITLE,
        '<p class="text-danger mb-0">' + esc(w.error) + '</p>'));
      return;
    }

    var head = weekHead(w.totals);

    // The dates are ALREADY FORMATTED server-side - Sydney wall-clock, never
    // through new Date().
    var title = esc(WEEK_TITLE) + ' (' + esc(w.from_disp) + ' to ' + esc(w.to_disp) + ')';

    if (!list.length) {
      $('#dash-week').html(weekCard(title,
        head + '<p class="text-muted small mb-0 mt-3">No booking changes this fortnight.</p>'));
      return;
    }

    $('#dash-week').html(weekCard(title,
      head + weekTable(list, !centreId) + weekNotes(w, 'its fortnight')));
  }

  // --- What's changing, for any range: the "View more..." dialog -------------

  /**
   * The card's table for the dates the team picks (Broc, 6 Nov 2026): "a longer
   * range of dates, or a specific range of dates", a page at a time, because two
   * years across the group is 1,810 rows.
   *
   * ONE REQUEST PER RANGE, PAGED HERE. The server has to build the whole range
   * to sort and total it, so it sends every row and a page is a slice - turning
   * a page costs nothing and asks Kidsoft nothing. See the `changes` action in
   * api/dashboard.php.
   *
   * THE TWO DATE BOXES CANNOT HOLD A REVERSED RANGE - the same rule as the
   * Waitlist's start dates. Each bounds the other through min/max, so the
   * calendar greys out the wrong side, and a date typed past it fails
   * checkValidity(), turns red, and is never sent; the table keeps the last good
   * answer rather than emptying under somebody mid-keystroke. Both are also held
   * to week_changes_bounds() (this year and next), and the server refuses a
   * reversed or out-of-bounds pair anyway.
   *
   * A CHILD'S NAME BRINGS YOU BACK. Bootstrap 5 does not stack dialogs, so the
   * name steps this one aside for the child's (window.childName(), handled in
   * child-modal.js); when that closes, this reopens on the same dates and the
   * same page, which is the whole reason to have been paging through it.
   */
  var WK_PAGE = 25;
  var wk = { w: null, page: 1, seq: 0, timer: null, returning: false };

  function wkModal() { return bootstrap.Modal.getOrCreateInstance($('#wk-modal')[0]); }

  /** A box's own value as a bound for the other - only once it is a whole date inside the years allowed. */
  function wkBound(v, fallback) {
    var $f = $('#wk-from');
    return (/^\d{4}-\d{2}-\d{2}$/.test(v) && v >= $f.data('lo') && v <= $f.data('hi')) ? v : fallback;
  }

  /** Each box bounds the other, inside the years allowed. */
  function wkSync() {
    var lo = $('#wk-from').data('lo'), hi = $('#wk-from').data('hi');
    $('#wk-from').attr({ min: lo, max: wkBound($('#wk-to').val(), hi) });
    $('#wk-to').attr({ min: wkBound($('#wk-from').val(), lo), max: hi });
  }

  /** Are both boxes a whole date, inside the bounds, not crossed? Marks the offender. */
  function wkValid() {
    var ok = true;
    $('#wk-from, #wk-to').each(function () {
      var good = !!this.value && this.checkValidity();
      $(this).toggleClass('is-invalid', !good);
      if (!good) ok = false;
    });
    return ok;
  }

  function wkLoad() {
    var mine = ++wk.seq;
    $('#wk-foot').addClass('d-none');
    $('#wk-body').html(spinner('Reading Kidsoft...'));
    $.getJSON('/api/dashboard', { action: 'changes', centre_id: centreId,
                                  from: $('#wk-from').val(), to: $('#wk-to').val() })
      .done(function (res) {
        if (mine !== wk.seq) return;    // the dates moved on while this was out
        if (!res.ok) {
          wk.w = null;
          $('#wk-body').html('<p class="text-danger mb-0">' + esc(res.error) + '</p>');
          return;
        }
        wk.w = res.week;
        wk.page = 1;
        wkRender();
      })
      .fail(function () {
        if (mine !== wk.seq) return;
        wk.w = null;
        $('#wk-body').html('<p class="text-danger mb-0">Could not load the changes.</p>');
      });
  }

  /**
   * Bootstrap's pagination: first and last always, two either side of the
   * current page, and "..." where pages are skipped - 73 numbered buttons for
   * two years of the group is not a control.
   */
  function wkPager(page, pages) {
    var item = function (p, label, opts) {
      opts = opts || {};
      return '<li class="page-item' + (opts.active ? ' active' : '') + (opts.off ? ' disabled' : '') + '">' +
        '<button type="button" class="page-link" data-page="' + p + '"' +
          (opts.active ? ' aria-current="page"' : '') +
          (opts.off ? ' disabled' : '') +
          (opts.aria ? ' aria-label="' + opts.aria + '"' : '') + '>' + label + '</button></li>';
    };
    var gap = '<li class="page-item disabled"><span class="page-link">...</span></li>';
    var out = item(page - 1, '<i class="fa-solid fa-chevron-left fa-xs"></i>',
                   { off: page === 1, aria: 'Previous page' });
    var shown = 0;
    for (var p = 1; p <= pages; p++) {
      if (p !== 1 && p !== pages && Math.abs(p - page) > 2) continue;
      if (shown && p > shown + 1) out += gap;
      out += item(p, String(p), { active: p === page });
      shown = p;
    }
    out += item(page + 1, '<i class="fa-solid fa-chevron-right fa-xs"></i>',
                { off: page === pages, aria: 'Next page' });
    return '<nav aria-label="Pages of changes"><ul class="pagination pagination-sm mb-0">' + out + '</ul></nav>';
  }

  function wkRender() {
    var w = wk.w, list = w.changes || [];
    var pages = Math.max(1, Math.ceil(list.length / WK_PAGE));
    wk.page = Math.min(Math.max(1, wk.page), pages);
    var first = (wk.page - 1) * WK_PAGE;

    $('#wk-body').html(weekHead(w.totals) +
      (list.length
        ? weekTable(list.slice(first, first + WK_PAGE), !centreId)
        : '<p class="text-muted small mb-0 mt-3">No booking changes from ' + esc(w.from_disp) +
          ' to ' + esc(w.to_disp) + '.</p>') +
      weekNotes(w, 'this range')).scrollTop(0);

    $('#wk-foot').toggleClass('d-none', !list.length).html(list.length
      ? '<div class="small text-muted">' + num(first + 1) + ' to ' +
          num(Math.min(first + WK_PAGE, list.length)) + ' of ' + num(list.length) + ' ' +
          plural(list.length, 'change') + '</div>' +
        (pages > 1 ? wkPager(wk.page, pages) : '')
      : '');
  }

  $(document).on('click', '#wk-more', function (e) {
    e.preventDefault();
    // FIRST OPENING: the card's own Monday to the end of this year, so the
    // dialog opens on more than the card already shows. After that it keeps
    // whatever dates and page it was left on.
    if (!$('#wk-from').val() && !$('#wk-to').val()) {
      var w = data.week || {}, lo = $('#wk-from').data('lo'), hi = $('#wk-from').data('hi');
      var from = w.from && w.from >= lo ? w.from : lo;
      var to   = year + '-12-31';
      if (w.to && w.to > to) to = w.to;
      $('#wk-from').val(from);
      $('#wk-to').val(to > hi ? hi : to);
      wkSync();
      if (wkValid()) wkLoad();
    }
    wkModal().show();
  });

  // 'change' as well as 'input', because a date picked out of the calendar
  // rather than typed does not always arrive as an 'input'.
  $('#wk-from, #wk-to').on('input change', function () {
    wkSync();
    // clearTimeout on the bad path as well, or a load queued by the keystroke
    // BEFORE the one that broke the range still fires.
    clearTimeout(wk.timer);
    if (wkValid()) wk.timer = setTimeout(wkLoad, 350);
  });

  $('#wk-foot').on('click', '.page-link[data-page]', function () {
    var p = +$(this).data('page');
    if (!wk.w || !p || p === wk.page) return;
    wk.page = p;
    wkRender();
  });

  $('#wk-modal')
    .on('click', '.ch-name', function () { wk.returning = true; })
    .on('show.bs.modal', function () { wk.returning = false; });
  $(document).on('hidden.bs.modal', '#ch-modal', function () {
    if (!wk.returning) return;
    wk.returning = false;
    wkModal().show();
  });

  // --- Places to fill, by room and centre ------------------------------------

  /**
   * The weekly report to management: where the free place-days actually are.
   *
   * TWO CUTS OF ONE NUMBER, because it is asked two questions. The matrix says
   * which room at which centre; the weekday strip says which DAY, and that one
   * runs ACROSS the matrix so no cell of it can show it. Measured 15 Sep 2026
   * over 115 free place-days: Mon 32 and Fri 35 are 58% of the whole gap.
   *
   * THE GROUP VIEW AND THE CENTRE VIEW ARE DIFFERENT SHAPES, not one table
   * filtered - the rule this app has had to relearn on three screens. Across the
   * group the comparison is room tier against CENTRE, because that is what a
   * marketing decision is made on. Inside one centre there is only one column,
   * so the comparison becomes room against WEEKDAY, which is the cell the team
   * actually fills.
   *
   * A ZERO IS A DASH. A grid of 0s reads as data; a grid of dashes reads as
   * nothing to do there, which is what it means.
   */
  function fillFig(n) {
    return n > 0 ? num(n) : '<span class="text-muted">-</span>';
  }

  /**
   * A MARGIN FIGURE: how many to fill, out of how many spots there are.
   *
   * Asked for 16 Sep 2026 - "the total number of spots somewhere, so the team
   * can see how many to fill out of how many total". It goes on the EDGES of the
   * matrix only: the All column, the All row, and the weekday strip under it.
   * The interior stays bare counts, because a fraction in all thirty-five cells
   * of a room-tier x centre grid is a wall of digits and the edge is where a
   * total belongs anyway.
   *
   * SAME SHAPE AS THE PLACEMENT BOARD'S "Free per week" COLUMN - figure in
   * front, the total it came out of behind a muted slash. A room reads 1/75 in
   * both places, which is the point: the two screens report the same fact and
   * must not dress it differently.
   *
   * A ZERO IS NOT A DASH HERE, and that is deliberate against the rule the
   * interior follows. "-" in a grid means nothing to do there, which is right
   * for a cell; on the edge the row's own SIZE is half of what was asked for, so
   * Little Angels reads 0/394 rather than vanishing into a dash next to 98.7%.
   * No capacity recorded means no denominator, and the count stands alone.
   */
  function fillFrac(free, cap) {
    if (cap == null || cap <= 0) return fillFig(free);
    return '<span' + (free > 0 ? '' : ' class="text-muted"') + '>' + num(free) + '</span>' +
      '<span class="dfill-cap">/' + num(cap) + '</span>';
  }

  /**
   * An All-row margin with its Filled % underneath (asked for 6 Nov 2026).
   *
   * The Filled column gives each ROW its percentage; this gives each COLUMN
   * its own, in the one row that totals them. It is the same board_pct()
   * figure, through the same pctText(), so a centre's percentage here matches
   * its row in the centre table above. Not used on the All and Filled columns,
   * which are already the row's own totals.
   */
  function fillFracPct(free, cap, pct) {
    return fillFrac(free, cap) + '<br><span class="dfill-sub">' + pctText(pct) + '</span>';
  }

  /**
   * How full, as a percentage, in its own column.
   *
   * THROUGH window.pctText(), NEVER CONCATENATED. It carries one decimal place
   * because a whole number cannot resolve one place-day at this scale, and JS
   * drops a trailing zero - so a raw 94.0 prints as "94%" in a column of 95.5s
   * and 92.7s and reads as the odd one out. Same rule as the hero meter and the
   * centre table, which is also why the figures here can be compared with them.
   *
   * NOT COLOURED, AND NOT A MARK. Two reasons, and the first is not taste:
   * .dm-good / .dm-warn / .dm-bad are meter TRACK backgrounds, not text colours,
   * and --lm-warn is 2.2:1 on white - its own comment calls it "label relief",
   * which is what a colour is when it sits behind a label rather than being
   * one. There is no amber in this palette that may be set as text.
   *
   * And a bar per row would turn a matrix somebody reads down and hands to
   * management into a chart. Five figures in one column rank themselves; the
   * meter in the centre table above already does the graphic job, on the same
   * board_pct() figures, so these can be compared against it directly.
   */
  function fillPct(pct) {
    return '<td class="text-end dgrid-fig dfill-pct' + (pct == null ? ' text-muted' : '') + '">' +
      pctText(pct) + '</td>';
  }

  /**
   * @param f   a dash_fill_report() payload - data.fill (this year) or
   *            data.fill_next (the year ahead)
   * @param sel the container to draw into
   *
   * ONE FUNCTION, TWO YEARS, and the year comes off the PAYLOAD rather than off
   * the page. Both cards are the same report asked about different years, so
   * two copies of this would be two copies of every rule in it - the same
   * five-implementations fault window.statTile() exists to have fixed. Every
   * year that reaches the screen from here is f.year: the module-level `year`
   * is this year and is what the hero and the meters are, and using it in the
   * next-year card would have deep-linked every room on it to the wrong board.
   */
  function renderFill(f, sel) {
    f = f || {};
    var types = f.types || [];
    var fYear = f.year || year;

    // NOTHING TO REPORT IS NOT THE SAME AS NOTHING LEFT TO SELL, and conflating
    // them made this card tell a confident lie on its first look at Warnervale:
    // 385 place-days of capacity, zero children, and the card read "Every place
    // is filled." A centre that is shut - or one Kidsoft would not answer for -
    // contributes no row at all, so the card simply does not render. That is the
    // same treatment the rest of this screen gives a closed centre: `--` and no
    // commentary, because Broc runs these centres and does not need the app to
    // point out that one of them is shut.
    if (!f.centres || !f.centres.length) { $(sel).html(''); return; }

    // Every place genuinely sold, at a centre that is genuinely trading.
    if (!f.total) {
      $(sel).html(card('Places to fill (' + fYear + ')',
        '<p class="text-muted mb-0">Every place is filled.</p>'));
      return;
    }

    var body;
    if (!centreId) {
      // Room tier x centre.
      var cs = f.centres || [];
      body = '<div class="table-scroll ts-nocap"><table class="table table-sm align-middle mb-0 dgrid dfill">' +
        '<thead><tr><th>Room</th>' +
        cs.map(function (c) {
          return '<th class="text-end">' +
            '<a class="ov-centre-link" href="/placement?centre=' + c.centre_id + '&year=' + fYear +
            '">' + esc(c.centre) + '</a></th>';
        }).join('') + '<th class="text-end dfill-all">All</th>' +
        '<th class="text-end dfill-pct" title="Places held against places that exist, ' +
          'across every centre">Filled</th></tr></thead><tbody>' +
        types.map(function (t) {
          return '<tr><th class="fw-semibold">' + roomIcon(t.label) + esc(t.label) + '</th>' +
            cs.map(function (c) {
              return '<td class="text-end dgrid-fig">' + fillFig(c.by_type[t.key] || 0) + '</td>';
            }).join('') +
            '<td class="text-end dgrid-fig dfill-all">' +
              fillFrac(f.by_type[t.key] || 0, f.cap_by_type ? f.cap_by_type[t.key] : null) + '</td>' +
            fillPct(f.pct_by_type ? f.pct_by_type[t.key] : null) + '</tr>';
        }).join('') +
        '</tbody><tfoot><tr class="dfill-all"><th>All</th>' +
        cs.map(function (c) {
          return '<td class="text-end dgrid-fig">' + fillFracPct(c.total, c.capacity, c.pct) + '</td>';
        }).join('') +
        '<td class="text-end dgrid-fig">' + fillFrac(f.total, f.capacity) + '</td>' +
        fillPct(f.pct) + '</tr></tfoot></table></div>';
    } else {
      // One centre: room x weekday, which is the cell the team fills.
      var one = (f.centres || [])[0];
      if (!one) { $(sel).html(''); return; }
      // EVERY ROOM THE CENTRE RUNS, full or not. This filtered on `total > 0`
      // and Tuggerah's Little Angels Pink - 100.0% sold, nothing free -
      // disappeared off the report entirely (reported 16 Sep 2026). A room that
      // is not on the list reads as a room the centre does not have, which is
      // the opposite of a room with nothing left to sell, and the Filled column
      // beside it says which. The test is CAPACITY, so a room Kidsoft has no
      // positions for still drops out rather than printing a line of dashes.
      var rooms = (one.rooms || []).filter(function (r) { return r.capacity > 0 || r.total > 0; });
      body = '<div class="table-scroll ts-nocap"><table class="table table-sm align-middle mb-0 dgrid dfill">' +
        '<thead><tr><th>Room</th>' +
        [1, 2, 3, 4, 5].map(function (wd) {
          return '<th class="text-end">' + esc(DAY_NAME[wd]) + '</th>';
        }).join('') + '<th class="text-end dfill-all">All</th>' +
        '<th class="text-end dfill-pct" title="Places held against places that exist, ' +
          'across the week">Filled</th></tr></thead><tbody>' +
        rooms.map(function (r) {
          return '<tr><th class="fw-semibold">' +
            '<a class="ov-centre-link" href="/placement?centre=' + one.centre_id +
            '&year=' + fYear + '&room=' + r.room_id + '">' +
            roomIcon(r.room) + esc(r.room) + '</a></th>' +
            [1, 2, 3, 4, 5].map(function (wd) {
              return '<td class="text-end dgrid-fig">' + fillFig(r.days[wd] || 0) + '</td>';
            }).join('') +
            '<td class="text-end dgrid-fig dfill-all">' + fillFrac(r.total, r.capacity) + '</td>' +
            fillPct(r.pct) + '</tr>';
        }).join('') +
        '</tbody><tfoot><tr class="dfill-all"><th>All</th>' +
        [1, 2, 3, 4, 5].map(function (wd) {
          return '<td class="text-end dgrid-fig">' +
            fillFracPct(one.by_day[wd] || 0, one.cap_by_day ? one.cap_by_day[wd] : null,
              one.pct_by_day ? one.pct_by_day[wd] : null) + '</td>';
        }).join('') +
        '<td class="text-end dgrid-fig">' + fillFrac(one.total, one.capacity) + '</td>' +
        fillPct(one.pct) + '</tr></tfoot></table></div>';
    }

    // WHICH DAY, across everything above. On the group view this is the cut the
    // matrix cannot make; on a centre it is already the matrix's own footer, so
    // it is not drawn twice.
    if (!centreId) {
      body += '<h3 class="h6 text-uppercase text-muted mt-4 mb-2">By weekday</h3>' +
        statStrip([1, 2, 3, 4, 5].map(function (wd) {
          var cap = f.cap_by_day ? f.cap_by_day[wd] : null;
          return { label: DAY_NAME[wd], value: num(f.by_day[wd] || 0),
                   // The strip is a margin too, and it is the cut a marketing
                   // spend is decided on - "Mon 32" means nothing without the
                   // 544 Mondays it came out of. statStrip() already has a place
                   // for the second line, so no new shape is needed.
                   hint: cap ? 'of ' + num(cap) : null };
        }));
    }

    $(sel).html(card('Places to fill (' + fYear + ')', body));
  }

  /**
   * BOTH YEARS, from one function - and the year ahead on its own request.
   *
   * This year is the hero's own breakdown and comes with the page. The year
   * ahead is one more derived board per centre (1.8s across the group, against
   * 4.2s for everything else on this screen), so it is fetched separately the
   * way the work list and the tours list already are: the screen the team opens
   * the day on does not get 40% slower for a card that is not the lead.
   *
   * A YEAR THAT WILL NOT LOAD COSTS ITS OWN CARD AND NOTHING ELSE. `res.ok` is
   * checked in .done(), not only .fail() - nginx discards the body of a real 4xx
   * under /api/, so a readable failure arrives as HTTP 200 with ok:false.
   */
  function fillCards() {
    renderFill(data.fill, '#dash-fill');
    if (!$('#dash-fill-next').length) return;
    $('#dash-fill-next').html(card('Places to fill (' + (year + 1) + ')',
      spinner('Reading next year...')));
    $.getJSON('/api/dashboard', { action: 'fill_next', centre_id: centreId })
      .done(function (res) {
        if (!res.ok || !res.fill) { $('#dash-fill-next').html(''); return; }
        renderFill(res.fill, '#dash-fill-next');
      })
      .fail(function () { $('#dash-fill-next').html(''); });
  }

  // --- Load ------------------------------------------------------------------

  function load() {
    // The lead container differs between the two views, so the spinner and any
    // failure message go to whichever one this page actually emitted.
    var $lead = centreId ? $('#dash-summary') : $('#dash-hero');
    $lead.html(spinner(centreId ? 'Loading...' : 'Reading every centre...'));
    $.getJSON('/api/dashboard', { action: 'load', centre_id: centreId })
      .done(function (res) {
        if (!res.ok) {
          $lead.html('<div class="text-center text-danger py-5">' + esc(res.error) + '</div>');
          return;
        }
        data = res;
        renderNotices();
        if (centreId) {
          // A centre is a working screen and keeps the shape it had.
          renderSummary();
          renderPipeline();
          if (data.centres.length) renderCentreOne(data.centres[0]);
          var no = notOpen();
          if (no) {
            // FOUR CARDS DESCRIBE ROOMS IN A YEAR THIS CENTRE DID NOT EXIST FOR:
            // places to fill, the same for next year, what's changing this fortnight, and
            // the re-enrolment round. One answer replaces the pair and the rest
            // are not drawn - four empty cards stacked down a page read as a
            // screen that failed to load. Tours and work still render below,
            // because a centre opening in January is being worked NOW.
            $('#dash-centre-pair').html('<div class="col-12"><div class="card"><div class="card-body pb-0">' +
              window.notOpenState(no, {
                what: 'room figures',
                href: function (y) { return '/placement?centre=' + centreId + '&year=' + y; }
              }) + '</div></div></div>');
            // AND THE COLUMN THE ROUND WOULD HAVE FILLED. Emptying it left a
            // 7-column hole with the tours and work cards stranded in the narrow
            // half beside it - a page that reads as broken rather than as quiet.
            // The two cards that DO have something to say take the full width.
            $('#dash-reenrolment').closest('[class*="col-"]').addClass('d-none');
            $('#dash-tours').closest('[class*="col-"]')
              .removeClass('col-xl-5').addClass('col-12');
          } else {
            fillCards();
            renderWeek();
            renderReenrolment();
          }
          renderTours();
          renderWork();
        } else {
          // The group view: one figure, then the work it implies, then where.
          renderHero();
          renderAttention();
          // Tours sit high on the morning overview because they are the only
          // thing on this screen with somebody physically turning up to it.
          renderTours();
          renderWeek();
          renderCentresGroup();
          fillCards();
          renderRound();
          renderGap();
          renderFree();
          renderPipeline();
        }
      })
      .fail(function () {
        $lead.html('<div class="text-center text-danger py-5">' +
          'Could not load the dashboard.</div>');
      });
  }

  load();
})();
