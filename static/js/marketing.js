/**
 * MARKETING - places going spare that nobody in the queue can take.
 *
 * Read-only. Every figure comes from _incl/marketing.php, which measures the
 * gap per room x weekday cell and reports it per centre x room tier; see that
 * file's header for why those are two different levels and for the measurements
 * behind each rule here.
 *
 * THE GROUP VIEW AND THE CENTRE VIEW ARE DIFFERENT SHAPES, not one table
 * filtered - the rule this app has had to relearn on three screens, and the
 * Dashboard's Places to fill made the same split for the same reason:
 *
 *   group   an OVERVIEW. Headline figures, then the three cuts that run ACROSS
 *           the ranking - by centre, by age group, by weekday - then the
 *           ranking itself, cut down to the rows that HAVE a gap. Rows are
 *           centre x age group, because an advertising decision is
 *           "three-year-olds at Kanwal" and the whole point is that it outranks
 *           Tuggerah's; the weekdays ride along on the row as a strip.
 *   centre  the matrix, and EVERY tier it has, gap or no gap. There is only one
 *           centre, so the comparison becomes age group against WEEKDAY, which
 *           is the cell the team actually fills - and the reader is asking about
 *           this centre, so a tier whose queue covers it is part of the answer
 *           rather than a row in the way of one.
 *
 * Which one is built is decided by the server-resolved #init-centre, never by a
 * control, so only one set of containers is ever in the DOM.
 */
(function () {
  'use strict';

  var centreId = parseInt($('#init-centre').val(), 10) || 0;   // 0 = whole group
  var year     = parseInt($('#init-year').val(), 10);
  var data     = null;

  var esc       = window.esc;
  var toast     = window.toast;
  var spinner   = window.spinner;
  var tile      = window.statTile;
  var dayStrip  = window.dayStrip;
  var roomIcon  = window.roomIcon;

  /** 1=Mon..5=Fri, matching WEEKDAYS in _incl/rooms.php. */
  var DAY_NAME = { 1: 'Mon', 2: 'Tue', 3: 'Wed', 4: 'Thu', 5: 'Fri' };
  var DAYS = [1, 2, 3, 4, 5];

  function num(n) { return (n == null ? 0 : n).toLocaleString('en-AU'); }
  function plural(n, one, many) { return n === 1 ? one : (many || one + 's'); }

  function card(title, body, cls) {
    return '<div class="card mb-3 ' + (cls || '') + '"><div class="card-body">' +
      (title ? '<h2 class="h6 text-uppercase text-muted mb-3">' + esc(title) + '</h2>' : '') +
      body + '</div></div>';
  }

  /**
   * A ZERO IS A DASH, the rule the Dashboard's matrix follows: a grid of 0s
   * reads as data, a grid of dashes reads as nothing to do there, which is what
   * it means.
   */
  function fig(n) {
    return n > 0 ? num(n) : '<span class="text-muted">-</span>';
  }

  /**
   * The tier's age band, IN MONTHS.
   *
   * Months because that is the unit the rest of the app speaks - rooms are
   * banded in min_age_months / max_age_months, sub-quotas are written in months,
   * and the Waitlist's own age filter asks in months. It is also the only unit
   * that can say these bands without rounding: of the fourteen distinct bands
   * the organisation runs, six have a bound that is not a whole year (1, 10, 18,
   * 22, 30 and 42 months), so a years-only label would have had to invent a
   * figure. Same phrasing as subquota_describe().
   */
  function ageBand(min, max) {
    if (min == null && max == null) return '';
    if (min == null) return 'under ' + max + ' months';
    if (max == null) return min + ' months and over';
    return min + '-' + max + ' months';
  }

  /**
   * The rooms behind a row, as links into the cell they name.
   *
   * THE CAMPAIGN IS A TIER AND THE PLACE IS A ROOM, so the row has to say which
   * door the child comes in - at Tuggerah "Shining Stars" is three rooms.
   * /placement?room= opens that room on the board, the same deep link a search
   * result uses.
   */
  /**
   * THE WEEKDAY THIS ROW IS WORST ON, or 0 when it has no gap at all.
   *
   * It is what the deep link carries: the placement board needs BOTH `room` and
   * `wd` to open a cell (board.js builds `deepCell` from the pair). A link with
   * only the room opened nothing at all until 13 Nov 2026 - verified by
   * clicking one, /placement?centre=1&year=2027&room=3 opened the Kanwal board
   * with no panel - and now opens that room's sheet, every child all week.
   */
  function worstDay(r) {
    var wd = 0, best = 0;
    DAYS.forEach(function (d) {
      if ((r.gap_days[d] || 0) > best) { best = r.gap_days[d]; wd = d; }
    });
    return wd;
  }

  function roomLink(r, rm, text, cls) {
    var wd = worstDay(r);
    return '<a class="' + cls + '" href="/placement?centre=' + r.centre_id +
      '&year=' + (data.year || year) + '&room=' + rm.id +
      (wd ? '&wd=' + wd : '') + '" title="' + esc(rm.name +
        (wd ? ' on the board, on ' + DAY_NAME[wd] : ' on the board')) + '">' +
      esc(text) + '</a>';
  }

  /**
   * The age group cell: the tier's mark, its name, its band, and the rooms
   * behind it.
   *
   * THE ROOM LINE IS ONLY DRAWN WHEN IT SAYS SOMETHING. At five of the seven
   * centres a tier IS one room with the tier's own name, so the line under
   * "Mighty Meteors" read "Mighty Meteors" - a whole row of every table spent
   * repeating the heading. Where the names agree the HEADING becomes the link
   * instead, so the deep link into that room's cell is not lost. Where they do
   * not agree the line is real: Tuggerah's Shining Stars is Pink, Purple and
   * Silver, and Warnervale's one room is Kidsoft's own "Amazing Asterouds".
   */
  function tierCell(r) {
    var rooms = r.rooms || [];
    var names = rooms.map(function (rm) { return rm.name; }).join(', ');
    var same  = rooms.length === 1 && names === r.tier_label;

    return '<th class="fw-semibold mkt-tier">' + roomIcon(r.tier_label) +
      (same ? roomLink(r, rooms[0], r.tier_label, 'mkt-tier-link') : esc(r.tier_label)) +
      '<span class="mkt-band">' + esc(ageBand(r.min_age_months, r.max_age_months)) + '</span>' +
      (same ? '' : '<span class="mkt-rooms">' + rooms.map(function (rm) {
        return roomLink(r, rm, rm.name, 'mkt-room-link');
      }).join(', ') + '</span>') +
      '</th>';
  }

  /**
   * WHICH WEEKDAYS THE GAP IS ON, as the shared strip in its neutral state.
   *
   * `plain` and not a colour that means something else: these are not days a
   * child attends and not days a family has asked for, which are the two facts
   * every other colour in this strip carries. A colour that answers a question
   * the row is not asking is worse than no colour - the Dashboard's "Places
   * coming free" learned that one in green.
   */
  function gapStrip(r) {
    var days = DAYS.filter(function (wd) { return (r.gap_days[wd] || 0) > 0; });
    return dayStrip({
      plain: days,
      plainSaid: 'nobody waiting for this day',
      wide: true,
      // NOTHING AT ALL for a row with no gap, rather than a dash. The gap column
      // immediately to the left already carries one, and two dashes side by side
      // read as a stutter - "-  -" on thirteen of eighteen rows. A dash means
      // "nothing to do here"; saying it twice adds nothing.
      empty: ''
    });
  }

  // --- The figures above the list --------------------------------------------

  /**
   * FOUR TILES, and the first one is the screen.
   *
   * `gap` is amber when there is one and green when there is not - the
   * zero-is-good reading the rest of the app uses, through the shared
   * .text-warning / .text-success so an amber figure here is the same amber as
   * an amber figure anywhere else.
   *
   * NO DOLLAR FIGURE, and `place_days` is why the rule holds without one: an
   * uncovered place-day repeated over every remaining week of the year is the
   * honest scale of the gap, and it points at the same rooms a revenue figure
   * would without importing another team's job. See CLAUDE.md.
   */
  function renderSummary() {
    var s = data.summary;
    var n = s.rows;

    // WITHHOLD A FIGURE; DO NOT CAPTION IT. With no capacity recorded for the
    // year every one of these reads 0 out of 0 - four figures that look measured
    // and are not - and the list below says why in one sentence. Same treatment
    // a closed centre gets everywhere else in the app: nothing, not a zero.
    if (!data.priced) { $('#mk-summary').empty(); return; }
    $('#mk-summary').html(
      tile('Nobody waiting for', num(s.gap),
           'place-days a week' + (s.nobody ? ', ' + s.nobody + ' with an empty queue' : ''),
           s.gap > 0 ? 'text-warning' : 'text-success') +
      tile('Age groups to advertise', num(s.targets),
           'of ' + n + ' ' + plural(n, 'group') + ' with places free') +
      // THE QUEUE, AGAINST THE PLACES IT COULD TAKE. Without it the hero above
      // reads as "nobody is waiting", which is not what it says: 124 free
      // place-days in 2026 sit against 179 waiting families who ARE age-eligible
      // for one of them, and 38 place-days that none of them can reach. The
      // figure counts each application once, whatever it is eligible for.
      tile('Places free', num(s.free), 'place-days a week of ' + num(s.capacity) +
           '; ' + num(s.queue) + ' waiting ' + plural(s.queue, 'family', 'families') +
           ' could take one') +
      // THE SCALE OF THE GAP, and the reason no dollar figure is needed to show
      // it: one uncovered Friday is not one place-day, it is every remaining
      // Friday of the year. Named "unsold" rather than "still to sell" because
      // "still to sell" reads as the 124 places that ARE free - this is only the
      // part of them nobody is waiting for.
      tile('Unsold place-days', num(s.place_days),
           'left in ' + data.year + ' at this week\'s gap')
    );
  }

  /**
   * A BANNER IS FOR WHAT THEY CANNOT KNOW UNAIDED, and only two of the three
   * reasons a centre misses the ranking qualify. Kidsoft refusing to answer
   * changes whether the figures can be trusted at all; a centre that is open
   * with nobody in it is an alarm. A centre that has not opened yet is neither -
   * they know which centres are trading, so it says nothing here.
   */
  function renderNotices() {
    var out = '';
    if (data.unreachable && data.unreachable.length) {
      out += '<div class="alert alert-warning py-2">' +
        '<i class="fa-solid fa-triangle-exclamation me-2"></i>' +
        'Kidsoft would not answer for ' + esc(data.unreachable.join(', ')) +
        ', so ' + plural(data.unreachable.length, 'that centre is', 'those centres are') +
        ' not in this ranking.</div>';
    }
    // NOT OPEN YET IS NOT A NOTICE. Removed 21 Sep 2026, at Broc's request: a
    // centre that has not opened is the ONE of these three states that is not a
    // problem and not news - the team knows which of their centres are trading,
    // and a banner saying so on the All centres ranking every load is the
    // caption rule in CLAUDE.md, not the thing-they-cannot-know a banner is for.
    // `unopened` is still carried in the payload: the one-centre empty state in
    // renderList() below answers with the date, which is where it is useful.

    // AND THIS ONE IS AN ALARM. A centre that is open, with capacity, and has
    // nobody booked at all is not a marketing gap - it is a centre with no
    // children in it, and until there was an opening date to tell the two apart
    // this read as the ordinary case.
    if (data.closed && data.closed.length) {
      out += '<div class="alert alert-warning py-2">' +
        '<i class="fa-solid fa-triangle-exclamation me-2"></i>' +
        '<strong>' + esc(data.closed.join(', ')) + '</strong> ' +
        plural(data.closed.length, 'is', 'are') + ' open in ' + data.year +
        ' with no children booked at all, so ' +
        plural(data.closed.length, 'it is', 'they are') + ' not counted here.</div>';
    }
    $('#mk-notices').html(out);
  }

  // --- The cuts across the ranking (All centres only) -------------------------

  /**
   * WHERE THE GAP FALLS: the three cuts the ranking cannot make, because each
   * of them runs ACROSS the rows rather than down them.
   *
   *   by centre     Warnervale is 332 of 2027's 359 uncovered place-days and
   *                 holds four rows of twenty-nine. A ranked list cannot show a
   *                 concentration.
   *   by age group  2026's gap is 28 of 38 in the two OLDEST tiers and nothing
   *                 at all in the two youngest; 2027 is the other way up,
   *                 because next year's babies have not been enrolled yet. The
   *                 tiers are in room_types() order - youngest to oldest - so
   *                 that shape reads straight off the line.
   *   by weekday    Friday was half of 2026's gap against Tuesday's two.
   *
   * THREE LINES, UNDER THE TABLE, AND THAT SIZE IS THE POINT (22 Sep 2026).
   * These were three window.statStrip() blocks in a card of their own ABOVE the
   * ranking for one day, and it was the wrong answer to a right question: 17
   * figures at the same weight as the four tiles, in three grids of 7, 5 and 5
   * that lined up with nothing, took 445px of a 800px window and pushed the
   * report itself entirely below the fold. The screen showed ZERO rows of "Where
   * to advertise" where it used to show five. A cut is a footnote to the
   * ranking, not a second report in front of it - so it gets a footnote's
   * weight, and the ranking gets the fold back.
   *
   * EVERY FIGURE CARRIES ITS DENOMINATOR in `13/90`, which is the shape this
   * screen already uses for Free on every row and .mkt-cap already styles:
   * "Kanwal 13" means nothing without the 90 free places it came out of, and a
   * centre reading "0/78" is the useful opposite answer rather than an absence.
   * A zero is dropped back rather than dashed - in a distribution the zeros are
   * half the finding, and muting them is what makes the one big number visible.
   *
   * GROUP ONLY, because it is built inside groupTable(). On one centre the
   * matrix already runs age group down one edge and weekday across the other,
   * and a by-centre cut of one centre is the figure that is already in the tile.
   */
  function cutsBlock() {
    var s = data.summary;

    function line(label, items) {
      return '<div class="mkt-cut"><span class="mkt-cut-k">' + esc(label) + '</span>' +
        items.map(function (it) {
          return '<span class="mkt-cut-v' + (it.gap ? '' : ' mkt-cut-z') +
            '" title="' + esc(it.name + ': ' + num(it.gap) +
              ' place-days a week nobody is waiting for, of ' + num(it.free) +
              ' free') + '">' + esc(it.name) + ' <b>' + num(it.gap) +
            '</b><span class="mkt-cap">/' + num(it.free) + '</span></span>';
        }).join('') + '</div>';
    }

    return '<div class="mkt-cuts">' +
      line('By centre', (s.by_centre || []).map(function (c) {
        return { name: c.centre, gap: c.gap, free: c.free };
      })) +
      line('By age group', (s.by_tier || []).map(function (t) {
        return { name: t.label, gap: t.gap, free: t.free };
      })) +
      line('By weekday', DAYS.map(function (wd) {
        return { name: DAY_NAME[wd], gap: s.gap_days[wd] || 0,
                 free: s.free_days[wd] || 0 };
      })) +
      '</div>';
  }

  // --- The ranking -----------------------------------------------------------

  function renderList() {
    var rows = data.rows || [];

    // NOTHING TO REPORT IS NOT THE SAME AS NOTHING LEFT TO SELL, and conflating
    // them is how the Dashboard's fill card came to read "Every place is filled"
    // over a centre with 385 empty place-days. With no centre measured at all
    // the banner above is the whole answer.
    if (!data.measured) {
      // EXCEPT WHEN EVERY CENTRE IN SCOPE IS SIMPLY NOT OPEN YET, which is what
      // a Warnervale supervisor sees on the 2026 tab: a page with one grey
      // banner and nothing else reads as a screen that failed to load. It has an
      // answer - the year it opens - and unlike the no-capacity case there is
      // somewhere useful to send them, so the year is a link rather than a
      // sentence they have to act on themselves.
      // THROUGH THE SHARED COMPONENT (21 Sep 2026). This screen had the only
      // copy of this state, and Broc asked for it across the whole project - so
      // the markup moved to window.notOpenState() and this became a caller like
      // Placement, Tenure and the Dashboard. It keeps its own SENTENCE, because
      // "there is nothing to advertise" is the answer where the others can only
      // name what is absent.
      var u = data.unopened || [];
      if (u.length === 1 && !data.closed.length && !data.unreachable.length) {
        $('#mk-list').html(window.notOpenState({
          centre: u[0].centre, opens: u[0].opens, year: data.year,
          opens_year: parseInt(String(u[0].opens).slice(-4), 10)
        }, {
          said: 'There is nothing to advertise for ' + data.year + '.',
          href: function (y) { return '/marketing?centre=' + centreId + '&year=' + y; }
        }));
        return;
      }
      $('#mk-list').html('');
      return;
    }

    // A YEAR WITH NO CAPACITY IS UNKNOWN, NEVER SOLD OUT - the rule
    // room_year_capacity has carried from the start, and the two empty lists
    // look identical. The nightly job reads this year and next only, so stepping
    // to 2028 measured 2,725 place-days of nothing: "every place is filled"
    // there would be a confident lie about a question nobody has asked Kidsoft
    // yet. Said plainly, with no button to press - there is nothing the team can
    // do about it and the year will fill itself in next January.
    if (!data.priced) {
      $('#mk-list').html(window.emptyState('fa-arrows-to-dot',
        'No room capacities recorded for ' + data.year,
        'Kidsoft is read for this year and next, so there is nothing yet to ' +
        'measure ' + data.year + '\'s queue against.'));
      return;
    }

    if (!rows.length) {
      // TWO DIFFERENT ANSWERS, and they were one sentence until the group list
      // was cut down to the rows with a gap. `summary.rows` counts every tier
      // with a place free, so it survives the cut and tells them apart:
      //
      //   0 tiers with a place free   every place is sold. Nothing to advertise
      //                               because there is nothing to sell.
      //   some, none of them short    the places are there and the queue covers
      //                               all of them. Nothing to advertise because
      //                               the work layer can already fill them,
      //                               which is the opposite fact and sends the
      //                               reader somewhere else.
      var n = data.summary.rows;
      $('#mk-list').html(card('', '<p class="text-muted mb-0">' + (n
        ? 'Every free place in ' + data.year + ' has somebody waiting who could ' +
          'take it, across all ' + n + ' age ' + plural(n, 'group') +
          ' with places free. There is nothing to advertise.'
        : 'Every place is filled in ' + data.year + '. There is nothing to advertise.') +
        '</p>'));
      return;
    }

    $('#mk-list').html(card(centreId ? 'Where the gap is, by age group and weekday'
                                     : 'Where to advertise',
                            centreId ? centreTable(rows) : groupTable(rows)));
  }

  /**
   * THE GROUP RANKING. One row per centre x age group, most pressing first.
   *
   * The order is _incl/marketing.php's and every key in it is a measured count
   * rather than a score: uncovered place-days, then an empty queue ahead of a
   * queue that simply cannot reach the places, then the most places free. A
   * reader who disagrees with the ranking can see all three numbers on the row.
   */
  function groupTable(rows) {
    var head = '<thead><tr>' +
      '<th>Centre</th><th>Age group</th>' +
      '<th class="text-end">Free</th>' +
      '<th class="text-end">Waiting</th>' +
      // "Nobody waiting", not "Nobody waiting for": at .75rem uppercase and
      // letterspaced the longer phrase wrapped to two lines in a column sized to
      // its three-digit figures, and the second line sat beside the NEXT header -
      // so the two read as one sentence, "...for which days". The full sentence
      // is in the title, which is where a definition belongs.
      '<th class="text-end mkt-gap" title="Places free on a weekday that no waiting child could take">' +
        'Nobody waiting</th>' +
      '<th class="mkt-days">Which days</th></tr></thead>';

    var body = rows.map(function (r) {
      return '<tr>' +
        '<td><a class="ov-centre-link" href="/placement?centre=' + r.centre_id +
          '&year=' + data.year + '">' + esc(r.centre) + '</a></td>' +
        tierCell(r) +
        '<td class="text-end mkt-fig">' + num(r.free) +
          '<span class="mkt-cap">/' + num(r.capacity) + '</span></td>' +
        '<td class="text-end mkt-fig">' + waitingCell(r) + '</td>' +
        '<td class="text-end mkt-fig mkt-gap">' + fig(r.gap) + '</td>' +
        '<td class="mkt-days">' + gapStrip(r) + '</td>' +
        '</tr>';
    }).join('');

    // `ts-nocap` - THE PAGE SCROLLS, NOT THE TABLE, and it is the same call the
    // Dashboard's Places to fill makes about the same kind of report. Capped,
    // fitScrollers() correctly gave this one a full-window box with 438px of page
    // scroll UNDER it (four tiles and a banner sit above the table), so the
    // reader got two scrollbars a few pixels apart - and on the centre view the
    // box landed 24px short of its own totals row, which reads as a clipped row
    // rather than as a table you can scroll. One report, one scrollbar. See the
    // .table-scroll section of ui-conventions.md.
    // THE CUTS SIT UNDER THE TABLE, inside the same card, which is where the
    // weekday one always was. They are a footnote to this ranking and they read
    // as one - see cutsBlock() for the day they were a card of their own above
    // it and cost the report its fold.
    return '<div class="table-scroll ts-nocap"><table class="table table-sm align-middle mb-0 mkt-table">' +
      head + '<tbody>' + body + '</tbody></table></div>' + cutsBlock();
  }

  /**
   * ONE CENTRE: age group against WEEKDAY, which is the cell the team fills.
   *
   * The interior is the per-weekday gap and the edge carries the row's totals --
   * the same treatment the Dashboard's matrix gives its own edge, and for the
   * same reason: a fraction in every interior cell is a wall of digits, and a
   * total belongs on the edge anyway.
   */
  function centreTable(rows) {
    var head = '<thead><tr><th>Age group</th>' +
      DAYS.map(function (wd) {
        return '<th class="text-end">' + esc(DAY_NAME[wd]) + '</th>';
      }).join('') +
      '<th class="text-end mkt-gap" title="Places free on a weekday that no waiting child could take">' +
        'Nobody waiting</th>' +
      '<th class="text-end">Free</th><th class="text-end">Waiting</th></tr></thead>';

    var body = rows.map(function (r) {
      return '<tr>' + tierCell(r) +
        DAYS.map(function (wd) {
          // The day's own three figures in the title: a 0 in this column can
          // mean "nothing free" or "the queue covers it", and those are opposite
          // facts about the same cell.
          return '<td class="text-end mkt-fig" title="' + esc(DAY_NAME[wd] + ': ' +
              (r.free_days[wd] || 0) + ' free, ' + (r.waiting_days[wd] || 0) +
              ' waiting for this day') + '">' + fig(r.gap_days[wd] || 0) + '</td>';
        }).join('') +
        '<td class="text-end mkt-fig mkt-gap">' + fig(r.gap) + '</td>' +
        '<td class="text-end mkt-fig">' + num(r.free) +
          '<span class="mkt-cap">/' + num(r.capacity) + '</span></td>' +
        '<td class="text-end mkt-fig">' + waitingCell(r) + '</td>' +
        '</tr>';
    }).join('');

    var t = { free: 0, gap: 0, capacity: 0, days: {} };
    DAYS.forEach(function (wd) { t.days[wd] = 0; });
    rows.forEach(function (r) {
      t.free += r.free; t.gap += r.gap; t.capacity += r.capacity;
      DAYS.forEach(function (wd) { t.days[wd] += (r.gap_days[wd] || 0); });
    });

    var foot = '<tfoot><tr class="mkt-all"><th>All</th>' +
      DAYS.map(function (wd) {
        return '<td class="text-end mkt-fig">' + fig(t.days[wd]) + '</td>';
      }).join('') +
      '<td class="text-end mkt-fig mkt-gap">' + fig(t.gap) + '</td>' +
      '<td class="text-end mkt-fig">' + num(t.free) +
        '<span class="mkt-cap">/' + num(t.capacity) + '</span></td>' +
      // NO TOTAL FOR WAITING. One child is age-eligible for two tiers wherever
      // two rooms' bands overlap - Mighty Meteors Silver runs 36-72 months and
      // Amazing Asteroids 36-60 - so adding the column down would count that
      // family twice and print a queue bigger than the centre has. The tile
      // above counts each application once.
      '<td class="text-end text-muted">--</td>' +
      '</tr></tfoot>';

    return '<div class="table-scroll ts-nocap"><table class="table table-sm align-middle mb-0 mkt-table">' +
      head + '<tbody>' + body + '</tbody>' + foot + '</table></div>';
  }

  /**
   * CHILDREN WAITING, and it is a count of families rather than of place-days --
   * one family wanting three days is one phone call. Zero is the loudest thing
   * on the row: it is the state the screen was asked for, so it says the word
   * rather than printing a 0 that reads as a measurement nobody made.
   */
  function waitingCell(r) {
    if (r.waiting > 0) {
      return num(r.waiting) +
        (r.asked ? '<span class="mkt-cap" title="' +
           esc(r.asked + ' ' + plural(r.asked, 'family', 'families') +
               ' already here have asked for one of these days') + '">+' +
           num(r.asked) + '</span>' : '');
    }
    if (r.asked > 0) return '<span class="mkt-cap">+' + num(r.asked) + '</span>';
    return '<span class="mkt-none">nobody</span>';
  }

  // --- Load ------------------------------------------------------------------

  var yn = window.yearNav('#year-nav', function (y) {
    // A REAL NAVIGATION, not a re-fetch. The year lives server-side, so a page
    // load is what keeps the session, the URL, the sidebar links and the centre
    // tabs telling one story - see the year block in ui-conventions.md.
    window.location = '/marketing?centre=' + centreId + '&year=' + y;
  });

  function load() {
    $('#mk-list').html(spinner('Measuring every room against the queue...'));
    $('#mk-summary,#mk-notices').empty();

    $.getJSON('/api/marketing', { action: 'load', centre_id: centreId, year: year })
      .done(function (res) {
        // EVERY CALLER CHECKS res.ok IN .done(), because a readable failure comes
        // back as HTTP 200 with ok:false - nginx discards the body of a real 4xx
        // under /api/. See _incl/api.php.
        if (!res.ok) { toast(res.error, 'danger'); $('#mk-list').html(''); return; }
        data = res;
        yn.set(res.year, res.years);
        renderSummary();
        renderNotices();
        renderList();
      })
      .fail(function () {
        $('#mk-list').html('');
        toast('Could not load the marketing gaps.', 'danger');
      });
  }

  load();
})();
