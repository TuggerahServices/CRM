// The Board - the app's single working screen. Two views on one page:
//
//   - No centre selected -> whole-organisation overview for a YEAR (every
//     centre's fill, emptiest first).
//   - A centre selected  -> that centre's CELL GRID for that YEAR: rooms down,
//     weekdays across. Each room x weekday cell carries what used to be spread
//     over four screens - what we plan, what Kidsoft has booked, where those
//     disagree, and who is waiting who could take a free place.
//
// The goal the whole app serves is every room, every day, filled, so both views
// lead with places still free.
//
// Data comes from /api/cells (read-only). Every mutation goes to the endpoint
// that already owns it and its checks: /api/board for annotations, /api/waitlist for
// linking and bulk add, /api/waitlist for offers.

(function ($) {
  'use strict';

  var DAYS = { 1: 'Mon', 2: 'Tue', 3: 'Wed', 4: 'Thu', 5: 'Fri' };
  // The unabbreviated form, for running text. "Every Fri in 2026" and "billed on
  // Fris" are the panel talking in column headings; a sentence says Friday.
  var DAYS_LONG = { 1: 'Monday', 2: 'Tuesday', 3: 'Wednesday', 4: 'Thursday', 5: 'Friday' };
  var placeModal = new bootstrap.Modal('#place-modal');
  var cellPanel  = new bootstrap.Offcanvas('#cell-panel');

  var year = parseInt($('#init-year').val(), 10);
  var data = null;       // current centre-year (from /api/cells)
  var overview = null;   // current org overview (from /api/board)
  var openCell = null;   // { roomId, wd } while the panel is up
  // A whole ROOM (every child, all five weekdays) opens in a dialog of its own,
  // the room sheet - see renderRoom(). Set while it is up, and while it has
  // stepped aside for another dialog it will come back to.
  var openRoom = null;
  // The banner's "Show who" list stays open across re-renders (every save
  // reloads the board) once somebody has opened it.
  var cfWhoOpen = false;

  // The centre is chosen in the centre bar, which is a set of ordinary links, so
  // it is fixed for the life of this page: 0 = the whole-organisation overview,
  // anything else = that centre's cell grid. Only one of the two containers is
  // rendered, so there is no view to toggle here any more.
  var CENTRE = parseInt($('#init-centre').val(), 10) || 0;
  function centreId() { return CENTRE; }

  // ?room=&wd= from a search result: which cell to open once the grid exists.
  var deepRoom = parseInt($('#init-room').val(), 10) || 0;
  var deepWd   = parseInt($('#init-wd').val(), 10) || 0;
  var deepCell = deepRoom && deepWd >= 1 && deepWd <= 5 ? { roomId: deepRoom, wd: deepWd } : null;
  // A room with no weekday opens that room's sheet. Until 13 Nov 2026 it did
  // nothing at all, which Marketing's room links had worked around.
  var deepSheet = deepRoom && !deepCell ? deepRoom : 0;

  // The year is the only axis on this screen now, in both views. Stepping it
  // NAVIGATES rather than re-fetching in place: the year is remembered
  // server-side (year_current), so a real page load is what keeps the session,
  // the URL, the sidebar links and the centre tabs all telling the same story.
  // Re-fetching in place meant the year you were looking at and the year every
  // link pointed at silently diverged.
  var yn = yearNav('#year-nav', function (y) {
    window.location = '/placement?centre=' + CENTRE + '&year=' + y;
  });

  // THE BOARD AS AT A DATE (16 Oct 2026) - see board_from() in board-live.php.
  // FROM is the date somebody CHOSE, '' when the board reads as today. Picking
  // one navigates, like the year arrows and for the same reason; the arrows drop
  // it. A pause before going, because a typed date passes through valid dates on
  // its way ("01" before "15"); the min/max test throws away a half-typed year.
  var FROM = $('#init-from').val() || '';
  var fromShown = $('#board-from').val() || '';
  var fromTimer = null;
  $('#board-from').on('input change', function () {
    var v = $(this).val() || '';
    clearTimeout(fromTimer);
    if (!v || v < this.min || v > this.max || v === fromShown) return;
    fromTimer = setTimeout(function () {
      window.location = '/placement?centre=' + CENTRE + '&from=' + v;
    }, 700);
  });
  $('#board-from-clear').on('click', function () {
    window.location = '/placement?centre=' + CENTRE + '&year=' + year;
  });
  /** This board's own address for a centre, carrying the year and any chosen date. */
  function placementHref(c) {
    return '/placement?centre=' + c + '&year=' + year + (FROM ? '&from=' + FROM : '');
  }

  // [icon, what the mark IS, what it SAYS about this child].
  //
  // Two forms of one fact, declared together rather than in two maps: the glyph
  // names the thing ("Behaviour Support Plan") because that is what a legend
  // answers, and the chip says it about the child ("On a support plan") because
  // that is what a hover over a name is asking. Two maps for one fact is how
  // they start disagreeing - the lesson the day strip and the stat tile both
  // cost this codebase.
  var FLAG_ICON = {
    sibling:       ['fa-people-group', 'Sibling', 'Sibling here'],
    staff:         ['fa-user-tie', "Staff's child", "Staff's child"],
    // NAME AND CHIP ARE THE SAME HERE, as they already are for staff.
    // "2 x educators required" was the one flag label written as a spec
    // rather than as a thing that is true of the child, and the "2 x"
    // notation appeared nowhere else in the app.
    two_educators: ['fa-user-plus', 'Needs two educators', 'Needs two educators'],
    behaviour:     ['fa-umbrella', 'Behaviour Support Plan', 'On a behaviour support plan'],
    // A SECOND PLAN, NOT A SECOND VALUE OF THE FIRST. The ISP process document
    // is explicit that a BSP runs ALONGSIDE an ISP rather than replacing it, so a
    // child can legitimately carry both glyphs and chipTitle() says both.
    individual:    ['fa-hands-holding-child', 'Individual Support Plan', 'On an individual support plan']
  };

  // Status labels are read back out of the placement editor's own <select>,
  // which index.php renders from PLACEMENT_STATUS. One vocabulary, defined in
  // PHP; a copy of the ten labels here would drift the first time one changed.
  var STATUS = {};
  $('#place-form [name=status] option').each(function () { STATUS[this.value] = $(this).text(); });

  /**
   * WHAT THE NAME CHIP SAYS ON HOVER.
   *
   * It used to say the STATUS and nothing else, which is **"Confirmed" on every
   * child in the app**: the board derives from Kidsoft, a booked child gets
   * `confirmed` by default, and all three annotation rows in the database carry
   * it too. So the largest hover target in the panel spent itself on a word true
   * of everybody, while the one thing that IS different about this child sat in
   * a 13px glyph beside their name. Reported as: the tooltip should say "On a
   * support plan", not "Confirmed".
   *
   * A STATUS THAT IS NOT THE DEFAULT STILL LEADS, and that is the whole reason
   * this is not simply a swap. `waitlist` and `no_position` mean the child is on
   * the board holding no place - the reason the roster fades their row and the
   * totals below them come out lower - and a flag must never be allowed to
   * swallow it.
   */
  function chipTitle(flags, status, p) {
    var said = (flags || []).map(function (f) {
      return FLAG_ICON[f] && FLAG_ICON[f][2];
    }).filter(Boolean);
    if (status !== 'confirmed') said.unshift(STATUS[status] || status);
    // Last, after anything unusual about the child: the band is true of
    // everybody, and it is what the green tone of this chip is saying.
    var band = bandText(p);
    if (band) said.push(band);
    return said.length ? said.join('. ') : (STATUS[status] || status);
  }

  /**
   * THE GREEN TONES: which staffing age band a child is in (14 Oct 2026).
   *
   * "Can the rooms with joint ages, when you click on them, show the children
   * different colours? Sometimes once enough kids get to a certain age, we can
   * adjust staffing requirements. On the spreadsheets the confirmed children are
   * green and to show the different ages their different toning colours of
   * green." The chip already IS the spreadsheet's green - `st-confirmed` came
   * from the workbook legend - so the band is a TONE of it, never a new colour:
   * lightest under 2, darkest 3 and over, where the NSW ratio steps.
   *
   * The band and the day it changes are decided on the server (STAFFING_AGE_BANDS
   * in rooms.php) - they are dates, and dates are Sydney's, not the viewer's.
   * Every room gets them, not only the mixed ones: a chip whose colour depended
   * on who else was in the room would say two things about one child.
   */
  function bandText(p) {
    var b = p && data && data.age_bands && data.age_bands.bands[p.age_band];
    if (!b) return '';
    return b.label + (p.band_next_on ? ', turns ' + b.turns + ' on ' + p.band_next_on : '');
  }

  function bandClass(p) {
    return p && p.age_band !== null && p.age_band !== undefined ? ' ab-' + p.age_band : '';
  }

  /**
   * The bands present among the children holding a place, in order - or [] when
   * there is only one, because a count of everybody under one heading is the
   * Placed figure again.
   */
  function bandsPresent(ps) {
    var seen = {};
    ps.forEach(function (p) {
      if (p.status === 'waitlist' || p.status === 'no_position') return;
      if (p.age_band !== null && p.age_band !== undefined) seen[p.age_band] = true;
    });
    var out = Object.keys(seen).map(Number).sort();
    return out.length > 1 ? out : [];
  }

  /**
   * Youngest band first, then by name - the order the tally over the roll reads
   * in, so the tones run light to dark down the list and each band's children
   * sit together under their count (Broc, 14 Oct 2026). A child with no band
   * (no date of birth) goes last: they are the exception, not the youngest.
   * Returns a copy; `bandOf` and `nameOf` read the two shapes of child the
   * roster and the cell panel carry.
   */
  function sortByBand(list, bandOf, nameOf) {
    function rank(x) {
      var b = bandOf(x);
      return b === null || b === undefined ? 99 : b;
    }
    return list.slice().sort(function (a, b) {
      return (rank(a) - rank(b)) || nameOf(a).localeCompare(nameOf(b));
    });
  }

  function placeName(p) {
    return ((p.child_first_name || '') + ' ' + (p.child_last_name || '')).trim();
  }

  /** A swatch in the band's tone, with its label - the key and the heading at once. */
  function bandKey(b) {
    return '<span class="place-key st-confirmed ab-' + b + '" aria-hidden="true"></span>' +
      esc(data.age_bands.bands[b].label);
  }

  function flagIcons(flags) {
    return (flags || []).map(function (f) {
      var d = FLAG_ICON[f];
      return d ? ' <i class="fa-solid ' + d[0] + ' place-flag" title="' + esc(d[1]) + '"></i>' : '';
    }).join('');
  }

  function room(id) {
    var found = null;
    (data ? data.rooms : []).forEach(function (r) { if (r.id === id) found = r; });
    return found;
  }

  // --- The cell grid ---------------------------------------------------------

  /**
   * How full is this, as a heat block. Shared by the room x weekday grid and
   * the centre x weekday overview so the two screens speak ONE language: a
   * colour, a number of places going spare, and the raw figures underneath.
   * The overview is where you decide which centre to open, so it must not need
   * relearning when you get there.
   *
   * `free` counts places, whatever the unit - one weekday for a room, one
   * weekday summed across a centre's rooms. `lowAt` is where amber stops and
   * red starts, which differs: 2 free in one room is a nudge, 2 free across a
   * whole centre is nearly full.
   */
  function heat(placed, capacity, free, lowAt, held) {
    if (capacity === null || capacity === 0) {
      return { cls: 'cell-unknown', big: '<span class="cell-big cell-na">--</span>',
               sub: placed + ' placed' };
    }
    // A CELL MUST NOT READ "Full 18/19". Places kept for a child who has not
    // answered re-enrolment are not sellable and not placed either, so without
    // this the word and the fraction contradict each other and the fraction is
    // the one people stop trusting. Kanwal's Mighty Meteors Thursday is the
    // case: 18 booked, 5 held, 19 places.
    var kept = held ? ', ' + held + ' held' : '';
    if (placed > capacity) {
      return { cls: 'cell-over', big: '<span class="cell-big">+' + (placed - capacity) + '</span>',
               sub: placed + '/' + capacity + kept };
    }
    if (free === 0) {
      return { cls: 'cell-full', big: '<span class="cell-big cell-word">Full</span>',
               sub: placed + '/' + capacity + kept };
    }
    // Three bands above "full", not one, so the grid is a heat scale rather
    // than a binary: the eye should land on the room that is six places light
    // before the one that is two light, and a single red for both hid that.
    var cls;
    if (free <= lowAt)         cls = 'cell-low';
    else if (free <= lowAt * 3) cls = 'cell-high';
    else                        cls = 'cell-vhigh';
    return { cls: cls,
             big: '<span class="cell-big">' + free + '</span>',
             sub: placed + '/' + capacity + kept };
  }

  /**
   * One cell: one room, one weekday, for the whole year.
   *
   * Everything beyond the heat block collapses into the tooltip, with the full
   * detail a click away, plus at most ONE dot for the rare case that somebody is
   * actually waiting. This replaced up to eight competing chips per cell: eight
   * signals in a cell is no signal, and the grid became a wall of colour with
   * nothing standing out, which is exactly what a board is supposed to fix.
   */
  function cellHtml(r, wd) {
    var c = r.cells[wd];
    // BOTH KINDS OF KEPT PLACE GO INTO THE SUB-LINE, for the reason heat()
    // documents: "Full 14/15" is a word and a fraction contradicting each other,
    // and the fraction is the one people stop trusting. The tooltip below tells
    // the two apart; the sub-line only has to stop the contradiction.
    var h = heat(c.placed, c.capacity, c.free, 2, (c.held || 0) + (c.reserved || 0));
    var cls = 'cell ' + h.cls, big = h.big, sub = h.sub;

    // Everything the old chips said, as words, in one tooltip. Nothing is lost
    // by collapsing them - it stops being shouted at you five columns at a time.
    var notes = [];
    // OWING MORE THAN THE ROOM HAS is the fact this whole feature exists to
    // surface, and `free` cannot carry it: it is clamped at zero, so a room one
    // place short and a room four short look identical.
    var short = (c.capacity === null || !c.held) ? 0
      : Math.max(0, c.placed + (c.promised || 0) + c.held - c.capacity);
    if (c.held) {
      notes.push(c.held + ' place' + (c.held === 1 ? '' : 's') +
        ' held for children who have not answered re-enrolment');
      if (short) {
        notes.push('that is ' + short + ' more than this room has - not everyone ' +
          'can keep the same day');
      }
    }
    if (c.requests_n) {
      notes.push(c.requests_n + ' famil' + (c.requests_n === 1 ? 'y has' : 'ies have') +
        ' asked for this day' + (c.grantable ? ' and a place is free' : ' (no place free)'));
    }
    // A PLACE-DAY ENDING IS NOT THE SAME AS A CHILD LEAVING. This said "N stop
    // attending later this year" for both, and two of Niagara Park's four were
    // children keeping their place and giving up one day. `coming_free` is the
    // half that is genuinely for sale - departures no arrival already covers --
    // so it is what gets said, and the gross churn stays in the panel.
    if (c.coming_free) {
      notes.push(c.coming_free + ' place' + (c.coming_free === 1 ? '' : 's') +
        ' come' + (c.coming_free === 1 ? 's' : '') + ' free' +
        (c.free_from ? ' from ' + fmtDate(c.free_from) : ' later this year') + ': ' +
        (c.departures || []).filter(function (x) { return !x.refilled; }).map(function (x) {
          return x.name + (x.kind === 'leaving' ? ' leaving care' : ' giving up this day');
        }).join(', '));
    }
    // NO "N places already taken from DD/MM" NOTE ANY MORE (21 Aug 2026). It was
    // written when the board showed the reference month and a child starting
    // after it was in no room at all, so a badge was the only place their place
    // could be mentioned. `placed` now counts whoever holds the spot and the
    // child is ON the cell with their start date, so the note restated a row
    // that says it better - and it was read exactly backwards in UAT, as a
    // countdown to the room filling up rather than as an explanation of a figure
    // that had already accounted for them. FIXING A FIGURE DELETES ITS CAPTION.
    if (c.eligible_count && c.free > 0) {
      notes.push(c.eligible_count + ' on the waitlist could take this day');
    }
    // Deliberately in the tooltip and NOT given a dot: this is true of 23 of
    // Tuggerah's 55 cells (42%), which is the normal condition of a childcare
    // week rather than an exception, and the dot rule below only survives if
    // common facts stay out of it.
    // `weeks` is the year's count of THIS weekday, so the two halves of this
    // fraction come from one population. They did not until 13 Aug 2026, when
    // this read "empty on 52 of 36" - weeks_free counted every Monday of the
    // year and weeks counted only the Mondays Kidsoft returned a row for. Fixed
    // in booked_year_summary(), not here; patching the sentence would have left
    // the panel below reading the same broken field.
    if (c.booked && c.booked.free_days > 0) {
      notes.push(c.booked.free_days + ' positions un-occupied across the year (empty on ' +
        c.booked.weeks_free + ' of ' + c.booked.weeks + ')');
    }
    if (c.promised) notes.push(c.promised + ' held by a pending offer');
    // A DIFFERENT PROMISE TO A DIFFERENT PERSON from either of the two above, so
    // it is said in its own words: this is a family already in the room whose
    // place is being kept while they are not booked on the day.
    if (c.reserved) {
      notes.push(c.reserved + ' place' + (c.reserved === 1 ? '' : 's') +
        ' held for ' + (c.reserved === 1 ? 'a child' : 'children') +
        ' enrolled here and not booked on this day');
    }
    // NOT held, which is the whole reason it has to be said out loud: the free
    // figure beside it counts these places as available. See work_by_cell().
    if (c.in_progress_n) {
      notes.push(c.in_progress_n + ' famil' + (c.in_progress_n === 1 ? 'y is' : 'ies are') +
        ' part-way through enrolling for this day (not holding a place yet)');
    }
    if ((c.subquotas || []).filter(function (q) { return q.full; }).length) {
      notes.push('a sub-quota is full on this day');
    }
    if (c.booked === null) notes.push('room not linked to Kidsoft');

    // ONE dot, and the rule is WHAT THE FACT IS, not how rare it is.
    //
    // It used to be frequency: measured over Tuggerah's 55 cells, unsold
    // place-days occurred on 100% and a child leaving on 98%, so flagging either
    // put a dot on every cell. That argument died on 11 Aug 2026 when leaving and
    // arriving were filtered to departures and starts still AHEAD of today --
    // re-measured over all 180 cells group-wide, leaving is now **16%** and
    // arriving **20%**, which is the same order as "a family asked for this day"
    // (16%) and rarer than nothing else on the tooltip. Rarity would now let them
    // in.
    //
    // They still do not get one, and the reason is worth keeping: THE DOT MEANS
    // SOMEBODY IS WAITING. It marks demand a person can act on by picking up the
    // phone, ranked grantable > asked > candidate. A departure or a late start is
    // SUPPLY - it changes what there is to sell, not who wants it - so it would
    // be a second, quieter meaning competing for one slot, and the grantable dot
    // is the one thing on this grid that must never be displaced. Unsold
    // place-days stay out on the original frequency ground: still 100% of cells.
    //
    // If you are tempted to add a dot, ask which of the three it outranks.
    var dot = '';
    if (c.grantable)          dot = '<i class="cell-dot dot-grant" aria-hidden="true"></i>';
    else if (c.requests_n)    dot = '<i class="cell-dot dot-req" aria-hidden="true"></i>';
    else if (c.eligible_count && c.free > 0) dot = '<i class="cell-dot dot-cand" aria-hidden="true"></i>';

    if (c.grantable) cls += ' cell-grantable';

    // SUPPLY GETS THE OTHER CORNER. A place coming free was the one fact on this
    // screen that had no home on the grid at all: it earns no dot (see the rule
    // above - the dot is demand, and the grantable dot must never be displaced),
    // and it cannot show in `free` either, because `holding` is the peak to
    // December and the leaver is still in the room today. So it was announced in
    // a banner that spelled the grid out again as a sentence - 13 room-and-day
    // groups at Tuggerah - and left the reader to find "Amazing Asteroid Purple,
    // Thursdays" by eye in a scroller showing three rooms at a time.
    //
    // It is not a second dot competing for one slot; it is a second CHANNEL, and
    // the grammar is worth keeping: TOP-LEFT is what there will be to sell,
    // TOP-RIGHT is who is waiting for it. A cell can carry both and say two
    // different things.
    //
    // The mark carries the month as well as the count, because "+2" alone would
    // be one glyph away from the over-capacity cell's big "+2" and because the
    // date is half the fact - a place opening on 20 December is not the same
    // piece of work as one opening in a fortnight. The exact date stays in the
    // tooltip and the panel.
    var cfMonth = c.coming_free && c.free_from ? c.free_from.slice(0, 7) : '';
    var cf = '';
    if (c.coming_free) {
      cf = '<span class="cell-cf" aria-hidden="true">+' + c.coming_free +
        (cfMonth ? ' ' + fmtMonth(cfMonth) : '') + '</span>';
    }

    // BEING FILLED GETS THE THIRD CORNER (14 Oct 2026). Asked for in UAT: "a
    // blue dot or something on the bottom right for rooms that have families in
    // progress, so everyone can see there are positions available, but they are
    // in the process of filling." The fact was only in the tooltip, and it is
    // the one that stops two people ringing about the same free place.
    //
    // NOT the top-right dot, for the rule above: in progress is not somebody
    // waiting, it is somebody already being looked after, and on all 14 cells
    // that carry it today the top-right slot is taken (a candidate dot on every
    // one). So it is a third channel: BOTTOM-RIGHT = already being filled.
    // Not counted on the mark - the number is in the tooltip and the panel.
    var prog = c.in_progress_n ? '<i class="cell-prog" aria-hidden="true"></i>' : '';

    var label = r.name + ', ' + DAYS[wd] + 's: ' +
      (c.capacity === null ? c.placed + ' placed, no capacity recorded'
                           : c.placed + ' of ' + c.capacity + ', ' + c.free + ' free');

    // Focusable and operable from the keyboard: this cell is the app's unit of
    // work, and it was a plain <td> that only a mouse could open.
    return '<td class="' + cls + '" data-room="' + r.id + '" data-wd="' + wd + '"' +
      // EVERY month a place here frees in, space-separated, so each month chip
      // lights it (matched with ~=). The corner mark still names the first.
      ((c.free_months || []).length ? ' data-cf-month="' + esc(c.free_months.join(' ')) + '"' : '') +
      (short ? ' data-over-held="' + short + '"' : '') +
      ' tabindex="0" role="button"' +
      ' title="' + esc(label + (notes.length ? '\n- ' + notes.join('\n- ') : '')) + '"' +
      ' aria-label="' + esc(label + (notes.length ? '. ' + notes.join('. ') : '')) + '">' +
      cf + dot + prog + big + '<span class="cell-sub">' + sub + '</span></td>';
  }

  function roomRow(r) {
    var age = (r.min_age_months === null && r.max_age_months === null) ? '' :
      '<span class="badge text-bg-light ms-2">' +
      esc((r.min_age_months === null ? '0' : r.min_age_months) + '-' +
          (r.max_age_months === null ? '+' : r.max_age_months) + ' mo') + '</span>';
    var link = r.linked ? '' :
      '<i class="fa-solid fa-link-slash text-danger ms-2" title="Not linked to Kidsoft; bookings cannot be compared"></i>';
    // r.capacity is a MAP of weekday => places, so rendering it here printed
    // "[object Object]/day". The headline wants the scalar, and a room whose
    // days differ says so rather than picking one silently.
    var cap;
    if (r.capacity_max === null || r.capacity_max === undefined) {
      cap = '<div class="small text-warning">no ' + year + ' capacity</div>';
    } else {
      cap = '<div class="small text-muted">' +
        (r.capacity_min !== null && r.capacity_min !== r.capacity_max
          ? r.capacity_min + '-' + r.capacity_max
          : r.capacity_max) + ' places/day</div>';
    }

    // The name column opens the ROOM: every child in it, all five weekdays at
    // once. A cell answers "is this day sold"; that answers "who is actually in
    // here", which a count cannot - it is the workbook's per-room sheet, and
    // the one thing UAT found the count grid had taken away.
    return '<tr><th class="grid-room" data-room="' + r.id + '" tabindex="0" role="button"' +
      ' title="' + esc('Everyone in ' + r.name + ', all five days') + '">' +
      '<div class="fw-semibold">' + roomIcon(r.name) + esc(r.name) + age + link + '</div>' + cap + '</th>' +
      Object.keys(DAYS).map(function (wd) { return cellHtml(r, wd); }).join('') +
      freeCell(r.year_free, weekCapacity(r)) + '</tr>';
  }

  /**
   * A room's capacity for the WHOLE WEEK - the five cells added up.
   *
   * r.capacity_max is the high-water mark of one day and is the headline under
   * the room name; it is not this, and using it here would have printed a fifth
   * of the real denominator. A weekday whose capacity Kidsoft does not give us
   * makes the whole week unknown rather than being counted as zero, which is the
   * same rule dash_centre() applies to the Filled column: a fraction with a
   * guess in its denominator is worse than no fraction.
   */
  function weekCapacity(r) {
    var n = 0;
    var days = Object.keys(DAYS);
    for (var i = 0; i < days.length; i++) {
      var c = r.cells[days[i]];
      if (!c || c.capacity === null || c.capacity === undefined) return null;
      n += c.capacity;
    }
    return n;
  }

  /**
   * The Free per week column: what is going spare OUT OF WHAT THERE IS.
   *
   * Asked for 16 Sep 2026 ("add in the right Free per week column the total they
   * can have, e.g. 53/850"). A bare 53 is unreadable without the size of the
   * thing it came out of - 53 free is most of a small room and a rounding error
   * across eleven big ones, and the column sits at the end of a row of cells
   * that ALREADY read "placed / capacity", so the reader is being handed a
   * numerator with no denominator in the one place the shape leads them to
   * expect both.
   *
   * SAME SHAPE AS THE CELLS, deliberately: the figure in the foreground, the
   * total it is out of in muted grey behind the slash. The numerator differs
   * (free here, placed there) and the column heading is what says which; two
   * different treatments for one fraction would have been the worse trade.
   *
   * No capacity for some weekday means no denominator, and the count stands on
   * its own rather than being divided by a number nobody has.
   */
  function freeCell(free, cap, cls) {
    cls = 'text-center grid-free-fig ' + (cls || 'text-muted small');
    if (free === null || free === undefined) return '<td class="' + cls + '">-</td>';
    return '<td class="' + cls + '">' + free +
      (cap === null ? '' : '<span class="grid-free-cap">/' + cap + '</span>') + '</td>';
  }

  function totalsRow() {
    return '<tr class="grid-totals"><th>All rooms</th>' +
      Object.keys(DAYS).map(function (wd) {
        var t = data.totals[wd];
        return '<td class="text-center">' +
          '<div class="fw-semibold">' + t.placed + '<span class="text-muted">/' + t.capacity + '</span></div>' +
          '<div class="small ' + (t.free ? 'text-danger' : 'text-success') + '">' +
          (t.free ? t.free + ' free' : 'full') + '</div></td>';
      }).join('') +
      // The same fraction for the whole centre, off the same totals the weekday
      // cells above are drawn from, so the column cannot disagree with the row.
      freeCell(
        Object.keys(DAYS).reduce(function (n, wd) { return n + data.totals[wd].free; }, 0),
        Object.keys(DAYS).reduce(function (n, wd) {
          return n === null || data.totals[wd].capacity === null
            ? null : n + data.totals[wd].capacity;
        }, 0),
        'fw-semibold'
      ) + '</tr>';
  }

  function renderGrid() {
    if (!data.rooms.length) {
      $('#grid').html('<div class="card"><div class="card-body">' +
        emptyState('fa-door-closed', 'No active rooms at this centre',
                   'Rooms come from Kidsoft. Set them up to start planning.') +
        '<div class="text-center"><a class="btn btn-primary" href="/rooms?centre=' + CENTRE +
        '">Open Rooms</a></div></div></div>');
      $('#board-summary').empty();
      return;
    }
    // No dates in the heading any more: a column is a weekday of the whole year,
    // not a particular Tuesday.
    var head = '<th class="grid-room">Room</th>' +
      Object.keys(DAYS).map(function (wd) {
        return '<th class="text-center">' + DAYS[wd] + 's' +
          '<div class="small fw-normal text-muted">' + (data.weekday_counts ?
            data.weekday_counts[wd] + ' in ' + data.year : data.year) + '</div></th>';
      }).join('') + '<th class="text-center grid-free">Free<div class="small fw-normal text-muted">per week</div></th>';

    $('#grid').html('<div class="card"><div class="table-scroll">' +
      '<table class="table align-middle mb-0 grid-table table-sticky grid-tight"><thead><tr>' + head + '</tr></thead>' +
      '<tbody>' + data.rooms.map(roomRow).join('') + totalsRow() + '</tbody></table></div></div>');

    renderSummary();
    renderNotices();
    renderLegend();
  }

  function renderSummary() {
    var placed = 0, cap = 0, free = 0, unsold = 0;
    Object.keys(DAYS).forEach(function (wd) {
      var t = data.totals[wd];
      placed += t.placed; cap += t.capacity; free += t.free;
    });
    data.rooms.forEach(function (r) {
      Object.keys(DAYS).forEach(function (wd) {
        var b = r.cells[wd].booked;
        if (b && b.free_days) unsold += b.free_days;
      });
    });
    var pct = window.fillPct(placed, cap) || 0;

    $('#board-summary').html(
      // The plan's gap: seats nobody is pencilled into for the year.
      // With a date picked the free figure counts from THAT date, and the
      // caption says so - "every week of 2027" over a board read from March
      // would be a figure contradicting its own caption.
      tile('Places free', free, data.from_chosen
             ? 'unfilled every week from ' + fmtDate(data.from)
             : 'unfilled every week of ' + data.year,
           free ? 'text-danger' : 'text-success') +
      tile('Filled', cap ? pctText(pct) : '-', cap ? placed + ' of ' + cap + ' places' : 'no capacities set',
           pct >= 98 ? 'text-success' : (pct >= 90 ? 'text-warning' : 'text-danger')) +
      // Places that stood empty. This is the number the plan cannot see: a cell
      // can read full and still have stood empty half the year.
      //
      // NO DOLLAR FIGURE HERE, AND NOWHERE ELSE IN THIS APP. This tile used to
      // carry an estimated value and it should never have: revenue belongs to a
      // different team with different systems, and this is a child enrolment
      // tool. A place-day is the unit the enrolments team can actually act on.
      // See the same rule in _incl/tenure.php, which declined to build a second
      // money model for exactly this reason.
      // "UN-OCCUPIED POSITIONS", the team's own words for this (16 Sep 2026).
      // It read "Unsold place-days", which is the app's word for the unit and
      // not theirs; a position is what Kidsoft calls the thing and what the
      // centres call it. The FIGURE is unchanged, and the two other places that
      // report the same number - the cell tooltip and the cell panel - say it
      // the same way, because one fact under two names on one screen is how two
      // figures start disagreeing in a reader's head.
      tile('Un-occupied positions', unsold ? unsold.toLocaleString() : 0,
           'across ' + data.year + ', from Kidsoft bookings',
           unsold ? 'text-danger' : 'text-success') +
      // IN PROGRESS, WHERE "CAN BE GRANTED" WAS (14 Oct 2026). Asked of that
      // tile: "nobody waiting on a day - what is this meant to represent? Maybe
      // instead we could have how many positions are in progress?" It counted
      // families already enrolled who asked for an extra day with a place now
      // free, and read 0 on 15 of the 16 boards once casual requests stopped
      // counting as demand. The day it is not 0, the green banner below says so.
      //
      // PLACES, in the same unit as "Places free" two tiles left, so the two can
      // be read against each other; the families behind them underneath. The
      // same rows as the In progress screen for this centre and year - see
      // `enrolling` in cells_year().
      tile('In progress', data.enrolling.places,
           data.enrolling.families
             ? 'places, for ' + data.enrolling.families + ' famil' +
               (data.enrolling.families === 1 ? 'y' : 'ies') + ' enrolling'
             : 'nobody enrolling for ' + data.year + ' yet')
    );
  }

  // There is no money() helper here any more, and adding one back is the thing
  // to stop and question. Nothing in this app renders a currency figure.

  var tile = window.statTile;   // shared - see app.js

  /**
   * A SHOW-ME-WHERE control for a year notice.
   *
   * `sel` is matched inside #grid, so the notice names its own cells and the
   * click handler stays one piece of code for every notice that grows one. Both
   * the notices on this screen were describing the board in words - one listed
   * thirteen room-and-day groups, the other said "look for the green dot" - and
   * a button that lights the cells is the answer to both.
   *
   * The label is the whole button, so it stays short; `hint` carries the
   * sentence into the title.
   */
  function litButton(sel, label, hint) {
    return '<button type="button" class="lit-btn" data-lit="' + esc(sel) + '"' +
      ' aria-pressed="false" title="' + esc(hint) + '">' + label + '</button>';
  }

  /**
   * WHY those room-days do not fit, in the words of whichever promise caused it.
   *
   * The sentence used to be hard-wired to re-enrolment, which was true while
   * that was the only promise this notice counted. It is not any more, and a
   * banner that explains a held place as a re-enrolment problem sends somebody
   * to chase a family who has already answered.
   */
  function overWhy(over) {
    var held = 0, reserved = 0;
    over.forEach(function (x) { held += x.held; reserved += x.reserved; });
    var why = [];
    if (held) {
      why.push('Everyone still to answer re-enrolment is being kept the same days ' +
               'they have now');
    }
    if (reserved) {
      why.push('a place is being held for ' + (reserved === 1 ? 'a child' : 'children') +
               ' enrolled here and not booked on the day');
    }
    // Capitalised by position, so the two clauses read as one sentence whichever
    // of them is present.
    var s = why.join(', and ');
    if (held) return s + ', and on these days they will not all fit.';
    return s.charAt(0).toUpperCase() + s.slice(1) + ', and with those counted the days do not fit.';
  }

  /** Anything that applies to the whole year rather than to one cell. */
  function renderNotices() {
    var out = '';

    // ROOMS THAT OWE MORE PLACES THAN THEY HAVE.
    //
    // "Yeah show straight away if the rooms are going over. We may need to
    // adjust the children number in Kidsoft for the moment (move where the
    // capped positions are)" - enrolments team, 9 Sep 2026. So this is a banner
    // and not a tooltip: they cannot see it unaided, it needs doing today, and
    // the fix is in Kidsoft rather than here.
    //
    // It sits ABOVE the Kidsoft-unreachable notice only in the sense of being
    // built first; it is the same alert stack, and the one below still leads
    // when the data cannot be trusted at all.
    //
    // IT COUNTS EVERY KIND OF PROMISE, NOT JUST RE-ENROLMENT (fixed 15 Sep 2026).
    // It gated on `held` alone, so a cell over capacity only because a day was
    // being HELD for a child never fired it. Measured on the live board: hold a
    // Tuesday in Shining Stars Silver, whose Tuesday is 15 of 15, and 16 places
    // are spoken for against a capacity of 15 with nothing on the screen saying
    // so - the cell reads "Full 15/15, 1 held" and looks entirely correct.
    //
    // This notice is the ONE place all three promises are added up, which is why
    // the cell's own `over` badge is deliberately left alone: that one is about
    // who is IN the room and must keep reading the same figure as `placed` and
    // `pct`. A place that is promised rather than occupied belongs here.
    var over = [];
    (data.rooms || []).forEach(function (r) {
        Object.keys(r.cells || {}).forEach(function (wd) {
            var c = r.cells[wd];
            if (c.capacity === null) return;
            var kept = (c.held || 0) + (c.reserved || 0);
            // No promise, no story: a cell over capacity on its bookings alone
            // already wears the red badge and is a different fact.
            if (!kept) return;
            var n = c.placed + (c.promised || 0) + kept - c.capacity;
            if (n > 0) {
                over.push({ room: r.name, wd: wd, n: n,
                            held: c.held || 0, reserved: c.reserved || 0 });
            }
        });
    });
    if (over.length) {
      var shortTotal = 0;
      over.forEach(function (x) { shortTotal += x.n; });
      // Worst first: that is the room where a family is most likely to lose the
      // day they already have.
      over.sort(function (a, b) { return b.n - a.n; });
      var worst = over[0];
      out += '<div class="alert alert-danger py-2 mb-3">' +
        '<div class="d-flex flex-wrap align-items-center gap-2">' +
        '<i class="fa-solid fa-triangle-exclamation"></i>' +
        '<div class="flex-grow-1"><strong>' + over.length + '</strong> room-day' +
        (over.length === 1 ? '' : 's') + ' owe' + (over.length === 1 ? 's' : '') +
        ' more places than ' + (over.length === 1 ? 'it has' : 'they have') +
        ', <strong>' + shortTotal + '</strong> short in all. ' + overWhy(over) + ' Worst is ' +
        esc(worst.room) + ' on ' + DAYS[worst.wd] + 's, ' + worst.n + ' over.' +
        '</div>' +
        '<div class="d-flex flex-wrap gap-1">' +
        litButton('[data-over-held]', 'Show me',
                  'Light the ' + over.length + ' room-days that are over') +
        '</div></div></div>';
    }

    if (data.kidsoft_error) {
      out += '<div class="alert alert-danger py-2"><i class="fa-solid fa-plug-circle-xmark me-2"></i>' +
        'Kidsoft could not be reached, so nothing below is checked against real bookings. ' +
        'The plan itself is ours and is shown in full. <span class="text-muted small">' +
        esc(data.kidsoft_error) + '</span></div>';
    }

    // A year still being enrolled is legitimately part-full, and the board is read
    // straight from Kidsoft now, so that is not a fault to report - it is how far
    // through the re-enrolment round this centre is.
    //
    // It states a MEASURED COMPARISON against this centre's current roll, not a
    // claim about the calendar, and it stops on its own once the year is nearly
    // as full as the one running now. An earlier version fired on the year alone
    // and would have gone on saying "still being enrolled" in December.
    if (data.not_enrolled_yet) {
      out += '<div class="alert alert-info py-2"><i class="fa-solid fa-circle-info me-2"></i>' +
        '<strong>' + data.enrolled + '</strong> of this centre\'s <strong>' + data.enrolled_now +
        '</strong> children are enrolled for ' + data.year + ' so far. The grid fills as the ' +
        'centre works through its re-enrolments in Kidsoft; nothing needs doing here.</div>';
    }

    // The one thing on this screen that needs no data entry at all: a family has
    // already asked Kidsoft for a day, and that day has a place free.
    //
    // IT POINTS AT THE BOARD RATHER THAN DESCRIBING IT. This read "Look for the
    // green dot", which is an instruction to go and search a grid of up to 55
    // cells for a mark that is 8px across - on Tuggerah today, one cell in 55.
    // The Show button does the looking: same gesture as the month chips below,
    // same dimming, so the two notices on this screen behave alike.
    if (data.grantable_total) {
      out += '<div class="alert alert-success py-2 mb-3">' +
        '<div class="d-flex flex-wrap align-items-center gap-2">' +
        '<i class="fa-solid fa-person-arrow-up-from-line"></i><div class="flex-grow-1"><strong>' +
        data.grantable_total + '</strong> famil' + (data.grantable_total === 1 ? 'y has' : 'ies have') +
        ' asked for a day that has a place free.</div>' +
        litButton('.cell-grantable', 'Show',
                  data.grantable_total === 1 ? 'Show it on the grid' : 'Show them on the grid') +
        '</div></div>';
    }

    // NO BANNER FOR "spare days that could be offered to families already in the
    // room". There was one, and it was an instruction to upsell - which is not
    // this organisation's policy. Demand from families already in care reaches
    // this app the way the policy expects it to: they ask, Kidsoft records a
    // waitlisted booking, and it appears in the grantable banner above and at the
    // TOP of the cell panel, ahead of the external queue.

    // Places coming free LATER THIS YEAR. Nothing else in the app sees these: the
    // board is derived from one reference month, so it says the seat is taken all
    // year, and only the run of actual bookings reveals that the child stops in
    // October. Departures already behind us are deliberately not counted - those
    // children are off the board already and their seats are in the free figures
    // on the grid, so counting them here would announce the same place twice.
    // Stated in CHILDREN first, because that is the number of phone calls.
    //
    // NO "Children moving up a room are not counted." (Broc, 11 Aug 2026: children
    // only move up at the end of the year, so a reader would assume it anyway.)
    // The exclusion is real and stays in the engine - but once the figure is
    // future-only it removes THREE arrangements group-wide, all at one centre and
    // zero at the other six, so the sentence was explaining a rule that almost
    // never bites on the number beside it. Same rule as the Tenure screen: do not
    // caption a figure to the people who run these centres.
    // WHAT IS ACTUALLY GOING ON SALE, AND WHERE. Two faults, both reported by
    // Broc on 13 Aug 2026 and both fixed in cells_year() rather than here:
    //
    // 1. It counted departures GROSS. Niagara Park read "4 children stop
    //    attending, freeing 6 place-days" on a grid showing 489 of 490 places
    //    taken - and all six had a replacement booked into the same cell within
    //    three weeks. `coming_free_total` nets them off, so that banner is gone.
    // 2. It said "stop attending" of children who are not. Two of those four keep
    //    their place all year and gave up a single weekday.
    //
    // AND THE CELLS ARE NAMED ON THE GRID ITSELF, not in this sentence. "6
    // place-days ... where?" was the actual question, and the first answer to it
    // was a prose list of every room and weekday, which is the grid rewritten as
    // a paragraph directly above the grid. Measured at Tuggerah on 14 Aug 2026 it
    // ran to THIRTEEN room-and-day groups over three wrapped lines, naming rooms
    // ("Amazing Asteroid Purple") that were not among the three visible in the
    // scroller below it - so the reader still had to hunt, only now with a
    // shopping list in hand. Kanwal was worse: eleven groups, all on one date.
    //
    // The cells carry a `+N Mon` corner mark now (see cellHtml), so this says the
    // two things the grid cannot:
    //
    //   WHEN - one chip per month. The grid is flat over the whole year, so a
    //           cell has nowhere to say that Wamberal's Mighty Meteors Monday
    //           opens on 20 December and is next year's problem, while Kanwal's
    //           eleven all open on the same day in a fortnight. Notice is four
    //           weeks, so the month is what makes one of these urgent.
    //   WHO   - children, because that is the number of phone calls, and the two
    //           kinds are different calls.
    //
    // A chip FILTERS THE BOARD: it dims every cell that is not in that month and
    // scrolls the first one into view. That is the whole reason this banner sits
    // on top of a grid rather than beside a list.
    if (data.coming_free_total && !data.not_enrolled_yet) {
      // Grouped by MONTH and sorted, because `coming_free_cells` arrives in room
      // order. `from` is the cell's soonest departure and is always set where
      // coming_free > 0 (it takes the same branch in the engine), but a missing
      // one must not silently collapse into another month's chip.
      var cf = data.coming_free_cells || [];
      var months = [], undated = 0;
      cf.forEach(function (x) {
        var k = (x.from || '').slice(0, 7);
        if (!k) { undated += x.n; return; }
        var g = null;
        months.forEach(function (b) { if (b.key === k) g = b; });
        if (!g) { g = { key: k, n: 0 }; months.push(g); }
        g.n += x.n;
      });
      months.sort(function (a, b) { return a.key < b.key ? -1 : 1; });

      var chips = months.map(function (b) {
        return litButton('[data-cf-month~="' + b.key + '"]',
                         '<strong>' + b.n + '</strong> in ' + fmtMonth(b.key),
                         'Show these ' + b.n + ' on the grid');
      }).join('');

      var who = [];
      if (data.leaving_children) {
        who.push(data.leaving_children + ' child' +
          (data.leaving_children === 1 ? '' : 'ren') + ' leaving');
      }
      if (data.dropping_children) {
        who.push(data.dropping_children + ' keeping their place but giving up a day');
      }
      if (undated) who.push(undated + ' with no date recorded');

      // WHO, BY NAME (10 Nov 2026). "3 children leaving, 3 keeping their place
      // but giving up a day" could not be acted on: nobody could tell from it
      // which child was which, and the team asked exactly that. The list is the
      // engine's `departures`, one row per child - a child is one phone call.
      var gone = data.departures || [];
      var whoBtn = !gone.length ? '' :
        '<button type="button" class="btn btn-sm btn-link cf-who-btn" aria-controls="cf-who"' +
        ' aria-expanded="' + cfWhoOpen + '">' + whoBtnLabel() + '</button>';

      out += '<div class="alert alert-warning py-2 mb-3 cf-alert">' +
        '<div class="d-flex flex-wrap align-items-center gap-2">' +
        '<i class="fa-solid fa-arrow-right-from-bracket"></i>' +
        '<div class="flex-grow-1"><strong>' + data.coming_free_total + '</strong> place-day' +
        (data.coming_free_total === 1 ? ' comes' : 's come') + ' free later in ' + data.year + '.' +
        (who.length ? ' ' + who.join(', ') + '.' : '') + '</div>' +
        '<div class="d-flex flex-wrap gap-1">' + chips + '</div>' + whoBtn +
        '</div>' + departureList(gone) + '</div>';
    }

    $('#year-notices').html(out);
  }

  function whoBtnLabel() {
    return (cfWhoOpen ? 'Hide who' : 'Show who') +
      ' <i class="fa-solid fa-chevron-' + (cfWhoOpen ? 'up' : 'down') + ' fa-xs"></i>';
  }

  /**
   * The banner's list: LEAVING CARE, then GIVING UP DAYS, one row per child per
   * room, soonest first (the engine sorts). Amber = coming free, green = a day
   * they keep, grey = given up but the cell is already re-filled. The child's
   * name opens their dialog; the room opens the cell, the same as a grid click.
   */
  function departureList(gone) {
    if (!gone.length) return '';
    var groups = [
      { label: 'Leaving care',   rows: gone.filter(function (x) { return x.kind === 'leaving'; }) },
      { label: 'Giving up days', rows: gone.filter(function (x) { return x.kind !== 'leaving'; }) }
    ];
    // ONE TABLE, the groups as header rows, so the room and day columns line up
    // from "Leaving care" into "Giving up days" - two tables sized themselves.
    return '<div id="cf-who" class="cf-who"' + (cfWhoOpen ? '' : ' hidden') + '>' +
      '<div class="table-responsive"><table class="cf-table">' +
      groups.filter(function (g) { return g.rows.length; }).map(function (g) {
        return '<tbody><tr><th colspan="4" class="cf-group" scope="colgroup">' + g.label + '</th></tr>' +
          g.rows.map(function (x) {
            return x.rooms.map(function (rm, i) {
              var live = rm.ending.filter(function (d) { return rm.refilled.indexOf(d) === -1; });
              var openWd = live.length ? live[0] : rm.ending[0];
              var what;
              if (!live.length) {
                what = (x.kind === 'leaving' ? '' : 'gives up ' + dayList(rm.ending) + ', ') +
                  'already re-filled';
              } else if (x.kind === 'leaving') {
                what = 'free from ' + fmtDate(rm.free_from);
              } else {
                what = 'gives up ' + dayList(live) + ' from ' + fmtDate(rm.free_from) +
                  (rm.keeps.length ? ', keeps ' + dayList(rm.keeps) : '');
              }
              if (live.length && rm.refilled.length) {
                what += ' (' + dayList(rm.refilled) + ' already re-filled)';
              }
              return '<tr>' +
                '<td class="cf-name">' + (i ? '' :
                  '<button type="button" class="cp-name ch-link" data-ks="' + esc(x.ks_id) + '"' +
                  ' data-room="' + rm.room_id + '">' + esc(x.name) + '</button>') + '</td>' +
                '<td><button type="button" class="btn btn-link btn-sm p-0 cf-open" data-room="' +
                  rm.room_id + '" data-wd="' + openWd + '">' + esc(rm.room || '') + '</button></td>' +
                '<td>' + dayStrip({ has: rm.keeps, ending: live, plain: rm.refilled,
                                     hasSaid: 'keeps', endingSaid: 'coming free',
                                     plainSaid: 'given up, place already re-filled' }) + '</td>' +
                '<td>' + what + (i || !x.reason ? '' :
                  ' <span class="text-muted">- ' + esc(x.reason) + '</span>') + '</td>' +
                '</tr>';
            }).join('');
          }).join('') + '</tbody>';
      }).join('') + '</table></div></div>';
  }

  function renderLegend() {
    $('#grid-legend').html(
      '<span class="fw-semibold">Colour = places to fill</span>' +
      '<span><span class="legend-swatch sw-full"></span>full</span>' +
      '<span><span class="legend-swatch sw-low"></span>1-2</span>' +
      '<span><span class="legend-swatch sw-high"></span>3-6</span>' +
      '<span><span class="legend-swatch sw-vhigh"></span>7+</span>' +
      '<span><span class="legend-swatch sw-over"></span>over capacity</span>' +
      '<span><span class="legend-swatch sw-na"></span>no capacity set</span>' +
      '<span class="fw-semibold ms-2">Top right = somebody is waiting</span>' +
      '<span><i class="cell-dot dot-grant"></i>asked for it, place free</span>' +
      '<span><i class="cell-dot dot-req"></i>asked, nothing free</span>' +
      '<span><i class="cell-dot dot-cand"></i>waitlist could fill it</span>' +
      // The corner mark DOES earn a key, where the pulsing outline below does
      // not: the outline says nothing the green dot has not already said, and
      // this is a fact with no other mark anywhere on the grid. It is also the
      // only thing here that is not round and not a colour, so it needs one
      // line to say which corner means what.
      '<span class="fw-semibold ms-2">Top left = a place opens later</span>' +
      '<span><span class="cell-cf cf-legend">+1 Sep</span>free from that month</span>' +
      // Keyed for the same reason as the corner tag: it is a dot, but not one
      // of the three above, and the corner is what says so.
      '<span class="fw-semibold ms-2">Bottom right = being filled</span>' +
      '<span><i class="cell-prog"></i>families enrolling for that day</span>' +
      // NO LEGEND ENTRY FOR THE PULSING OUTLINE. It is an attention grabber, not
      // a data metric - it says nothing the green dot above does not already
      // say, so a key for it would be a second entry for one fact.
      '<span class="text-muted"><i class="fa-solid fa-hand-pointer me-1"></i>' +
      'Click any cell to see the children enrolled in that room on that day.</span>');
  }


  // --- Cell detail panel -----------------------------------------------------
  //
  // ONE GRAMMAR FOR THE WHOLE PANEL (14 Aug 2026).
  //
  // Every block used to invent its own shape. The roll was a wrap of coloured
  // chips; a request was a stack of sentences; a candidate was a card with two
  // filled buttons competing; and the question all three blocks are really about
  // - WHICH DAYS DOES THIS CHILD HAVE, AND WHICH DO THEY WANT - was written out
  // in prose three different ways on one screen ("3 days . Mon, Tue, Fri",
  // "Currently Mon, Wed", "Wants Wed, Thu, Fri"). Reading it meant parsing three
  // comma lists against the weekday in the title.
  //
  // It is now the same five boxes everywhere, window.dayStrip(), so the panel reads down
  // one edge: FILLED = attends, RINGED = the weekday this cell is about, BLUE =
  // asked for and has not got. That third state is the grid's own "asked for this
  // day" hue (--lm-req) and the roster's hourglass, so one fact keeps one colour
  // wherever it is read.
  //
  // What was taken OUT matters as much:
  //
  //   - "+ Add a child" (both here and on the roster). Measured 14 Aug 2026: zero
  //     placement.create / .update / .days / .status rows in an audit log of 749,
  //     and `placements` empty. It is a hand-typed copy of a roll Kidsoft owns,
  //     which is the shape this app exists to delete, and accepting an offer
  //     already writes a real placement row. The editor is untouched - clicking a
  //     name still annotates status, flags, notes and days.
  //   - "N place-days unsold in 20XX" as an amber alert. Measured on 95 of 95
  //     cells across three centres: it fired on ALL of them. An alert that is
  //     always on is not an alert - the same argument placement-board.md already
  //     makes for keeping this fact off the grid's dot. The figure survives as one
  //     grey line in the year strip.
  //   - "Someone waiting for Fri?", which closed the cell you were reading and
  //     dumped you on the room roster. The roster still records wishes, from the
  //     empty boxes that are its whole point.
  //
  // See placement-board.md. The BLOCK ORDER below is policy and must not move.

  /**
   * Mon to Fri as five boxes - the one element every block on this panel shares.
   * The boxes themselves are window.dayStrip(), shared with the Waitlist queue,
   * Search, the Dashboard and In progress; this wrapper only keeps the panel's
   * three call sites reading in their own terms.
   *
   * @param days  weekdays the child actually attends -> green
   * @param hi    the weekday this cell is about -> ringed purple, in every block,
   *              so the day under discussion never has to be re-read off the title
   * @param ask   weekdays asked for and not held -> blue, the "waiting" hue
   */
  function cellDays(days, hi, ask, ending) {
    // `days` and `ask` are disjoint by construction here - the queue cards hold
    // nothing in this room, and internal_requests only records a day a family
    // does NOT have - so there is no third "attends but did not ask" set to
    // split out, unlike the Waitlist queue. See window.dayStrip().
    // `ending`: days among `days` the child is giving up - amber, see dayStrip().
    return dayStrip({ has: days || [], want: ask || [], day: hi, ending: ending || [] });
  }

  /**
   * LEAVING CARE, OR GIVING UP A DAY - said the same way everywhere (10 Nov 2026).
   *
   * The team could not tell the two apart: the roll printed "to 7/12/2026" on a
   * child giving up Mondays and on a child leaving, and the banner counted them
   * without naming anybody. `kind` comes from cells_end_kind() in cells.php, one
   * rule for the roll, the panel and the banner.
   */
  function dayList(days) {
    return (days || []).map(function (d) { return DAYS[d]; }).join(', ');
  }

  /** Which of a child's days in room `r` are coming free: amber on their strip. */
  function endingDays(r, ksId, days) {
    if (!ksId) return [];
    return (days || []).filter(function (d) {
      var cell = r.cells[d];
      return cell && (cell.departures || []).some(function (x) {
        return x.ks_id === ksId && !x.refilled;
      });
    });
  }

  /** Months as the team says them: "2y 11m", "7m". */
  function ageLabel(months) {
    if (months === null || months === undefined) return '';
    var y = Math.floor(months / 12), m = months % 12;
    return (y ? y + 'y' + (m ? ' ' + m + 'm' : '') : m + 'm');
  }

  /**
   * The one action row, and it shows ONE thing at a time.
   *
   * "Offer" and "Add to In progress" used to sit side by side on every candidate,
   * which read as a choice between two ways of starting. They are not alternatives
   * - work_link_offer() creates the work item itself when an offer is made and
   * advances it to `offered`, so Offer already contained the other button. And
   * `offered` is stage five of eight: offering a place to a family nobody has rung
   * skips the call and the tour, which is the job this app exists to leave time
   * for.
   *
   * So the row follows the family instead of offering a menu:
   *
   *   nobody has rung them   -> "Start with this family"  (creates the item)
   *   on it, any stage       -> stage badge + Update      (log the call in there)
   *
   * "OFFER A PLACE" IS GONE (15 Oct 2026). It sat on contacted .. toured, and
   * offers are sent in Kidsoft: the board reads them back, holds their days and
   * moves the item to Offered by itself (work_follow_kidsoft_offers()).
   */
  /**
   * When a promised place stops being promised, in words, for the panel and the
   * room sheet. An offer sent IN KIDSOFT says what Kidsoft calls it and carries
   * Kidsoft's deadline; a submitted form has none - the family has answered and
   * the next move is the centre's. Formatted server-side; see board_load().
   */
  function offerWhen(o) {
    if (!o.kidsoft) return 'expires ' + esc(o.expires);
    return esc(o.state.charAt(0).toLowerCase() + o.state.slice(1)) +
      (o.expires ? ', expires ' + esc(o.expires) : '');
  }

  function actionRow(d) {
    if (!d.work) {
      return '<div class="cp-actions">' +
        '<button class="btn btn-sm btn-' + (d.lead ? '' : 'outline-') + 'primary work-start"' +
        (d.ks_id ? ' data-ks="' + esc(d.ks_id) + '"' : '') +
        (d.waitlist_id ? ' data-wl="' + d.waitlist_id + '"' : '') +
        ' data-room="' + (d.room_id || '') + '" data-wd="' + d.wd +
        '" data-name="' + esc(d.name) + '">' +
        '<i class="fa-solid fa-list-check me-1"></i>Start with this family</button></div>';
    }
    return '<div class="cp-actions">' +
      '<span class="badge text-bg-primary">' + esc(d.work.stage_label) + '</span>' +
      '<button class="btn btn-sm btn-outline-secondary work-open" data-id="' + d.work.id +
      '">Update</button></div>';
  }

  /** A phone number as the thing this panel exists to prompt: one tap to dial. */
  function callLink(num, label) {
    if (!num) return '';
    return '<a class="cp-tel" href="tel:' + esc(num.replace(/[^0-9+]/g, '')) + '">' +
      '<i class="fa-solid fa-phone fa-xs"></i>' + esc(num) + '</a>' +
      (label ? '<span class="text-muted small ms-1">(' + esc(label) + ')</span>' : '');
  }

  /**
   * One family who asked Kidsoft for this day. Read-only: Kidsoft owns the
   * request, so there is no Save here. The guardian's email and phone are the
   * point - the team's job is the conversation, not the paperwork.
   */
  /**
   * A CHILD KIDSOFT KNOWS, BY NAME, AS A WAY INTO THEIR OWN DIALOG (6 Nov 2026).
   *
   * Every name in this panel that carries a ChildID opens window.openChild() -
   * see static/js/child-modal.js. Until then the request cards, the re-enrolment
   * holds and the held days printed names that opened nothing, and a family
   * asking Kidsoft for a day read "Waitlisted" with no way to see that they are
   * enrolled, what they hold, or what else they have asked for. A provisional
   * child has no ChildID and stays a plain name.
   */
  function childLink(ksId, name) {
    if (!ksId || typeof window.openChild !== 'function') {
      return '<span class="cp-name">' + esc(name) + '</span>';
    }
    return '<button type="button" class="cp-name ch-link" data-ks="' + esc(ksId) + '">' +
      esc(name) + '</button>';
  }

  function requestBlock(q, room, wd) {
    // THE PHONE COMES FIRST, and it took a corrected API claim to get here: this
    // block was email-only on the grounds that "Kidsoft's guardian record has NO
    // phone field - verified across all 4,630 Tuggerah guardians". It has one,
    // behind Options=PhoneNumbers, on 99% of active guardians against 38% for
    // email. Ringing a family about a free day is the job; emailing is the
    // fallback.
    // A number that is not the parent's is labelled with whose it is. Kidsoft's
    // Type field is free text and includes Grandmother, Aunt and Case Worker,
    // and dialling one of those believing it is Mum is a worse outcome than
    // having no number at all.
    var contact = callLink(q.guardian_phone,
      q.guardian_phone_is_parent === false ? q.guardian_phone_type : null);
    if (q.guardian_email) {
      contact += '<a class="cp-mail text-truncate" href="mailto:' + esc(q.guardian_email) + '">' +
        '<i class="fa-solid fa-envelope fa-xs"></i>' + esc(q.guardian_email) + '</a>';
    }
    contact = contact
      ? '<div class="cp-contact">' + (q.guardian ? '<span class="cp-gn">' +
          esc(q.guardian) + '</span>' : '') + contact + '</div>'
      : (q.guardian ? '<div class="cp-contact"><span class="cp-gn">' + esc(q.guardian) +
          '</span></div>' : '');

    // Which dates they asked for. Grouped onto one row per family per weekday,
    // so a standing request reads as one call to make rather than as five.
    var dates = (q.dates || []);
    var when = dates.length > 1
      ? dates.length + ' dates from ' + fmtDate(q.from)
      : (dates.length ? fmtDate(dates[0]) : '');

    // OURS OR KIDSOFT'S. The two are not interchangeable and the panel says
    // which: a portal booking names real dates and Kidsoft owns it, so there is
    // nothing to edit here; an internal one is a phone call somebody wrote down,
    // it is standing rather than dated, and it can be amended. A row carrying
    // both is one family who did each - see cell_merge_requests().
    var internal = q.source === 'internal' || q.also_internal;
    var badge = q.source === 'internal'
      ? '<span class="badge text-bg-light border" title="Recorded here from a phone call. ' +
        'Kidsoft has no field for a standing request like this.">Internal</span>'
      : '<span class="badge text-bg-light border">' + esc(q.status) + '</span>' +
        (q.also_internal ? ' <span class="badge text-bg-light border">Also noted here</span>' : '');

    // A standing wish has no dates, so it says the weekdays instead. Every day
    // they are waiting on, not only the one whose cell is open - one call can
    // settle the lot.
    var wantDays = q.source === 'internal' ? (q.wish_days || []) : [wd];

    // WHAT THEY HOLD AND WHAT THEY WANT, IN ONE OBJECT. A family asking for a
    // third day is a different conversation from one asking for their first, and
    // this used to be two prose lines ("Wants Wed", "Currently Mon, Tue") that
    // the reader had to compare against the weekday in the panel title. The strip
    // states all three at once: what they have, what they are asking for, and
    // which of those is this cell.
    var strip = '<div class="cp-strip">' + cellDays(q.current_days || [], wd, wantDays) +
      '<span class="cp-strip-txt">' +
      ((q.current_days || []).length
        ? 'has ' + esc(q.current_days.map(function (d) { return DAYS[d]; }).join(', ')) + ', '
        : '') +
      'wants ' + esc(wantDays.map(function (d) { return DAYS[d]; }).join(', ')) +
      (when && q.source !== 'internal' ? ' <span class="text-muted">' + esc(when) + '</span>' : '') +
      '</span></div>';

    return '<div class="cp-card cp-req">' +
      '<div class="cp-head">' + childLink(q.kidsoft_child_id, q.name) +
      (q.dob ? '<span class="cp-sub">b. ' + fmtDate(q.dob) + '</span>' : '') +
      '<span class="cp-badges">' + badge + '</span></div>' +
      strip +
      contact +
      // THE NOTE IS THE POINT OF THE WHOLE FEATURE. What the parent actually
      // said, which nothing in Kidsoft and nothing in this app could hold until
      // now. It goes above the timestamps, not below them.
      //
      // ITS ATTRIBUTION BELONGS TO THE NOTE, NOT TO THE REQUEST, and putting it
      // on the "Asked" line was wrong on a merged row: it read "Asked 9/08,
      // recorded by <staff>" for a request the PARENT raised in the portal, so
      // it credited a member of staff with a family's booking. Two facts, two
      // lines: when the family asked, and who here wrote the note down.
      (q.note
        ? '<div class="small wish-note mt-1">' + esc(q.note) +
          (q.recorded_by
            ? '<div class="wish-by">Noted by ' + esc(q.recorded_by) +
              (q.recorded_at ? ', ' + fmtDate(q.recorded_at) : '') + '</div>'
            : '') +
          '</div>'
        : '') +
      (q.requested_at ? '<div class="cp-meta">Asked ' + fmtDate(q.requested_at) + '</div>' : '') +
      // LEAD: a filled button. This block is families already in care, who have
      // preference for extra days - the queue's cards below carry the same
      // action as an outline, so the ordering policy is visible and not only
      // documented. See placement-board.md, "Demand on a cell".
      actionRow({ ks_id: q.kidsoft_child_id, name: q.name, lead: true,
                  room_id: room ? room.id : null, wd: wd, work: q.work }) +
      // Amending is a link, not a button: the primary action on this family is
      // the phone call, and a second filled button beside the action row would
      // compete with it.
      (internal && q.internal_id
        ? '<div class="mt-1"><button class="btn btn-link btn-sm p-0 wish-edit" data-id="' +
          q.internal_id + '" data-ks="' + esc(q.kidsoft_child_id || '') +
          '" data-room="' + (room ? room.id : '') + '">Edit what they asked for</button></div>'
        : '') +
      '</div>';
  }

  /**
   * One family in the queue who could take this place.
   *
   * WHAT WAS MISSING. The card carried a name, a queue number, a phone number and
   * a comma list of weekdays - and then a second, unrelated comma list of reasons
   * glued on after a middot, which is where "Sibling of Azula Morante, Evelyn
   * Morante at Kanwal" ran into "Wants Wed, Thu, Fri" and the two became one
   * unreadable line. Three facts a person needs before ringing were not on it at
   * all: how old this child will be when they would start (the room has an age
   * band and fill.php has already computed it), how long they have been waiting,
   * and how much of what they want this one day would actually settle.
   *
   * A NEAR MISS IS THE SAME CARD, FADED, WITH THE REASON. An empty shortlist would
   * otherwise read as an empty waitlist - "three families want Friday but all are
   * too old" is the useful answer. It keeps the strip and the phone number,
   * because a blocked candidate is still a real family the age band may fit next
   * term.
   */
  function candidateBlock(c, r, wd) {
    var meta = [];
    // WHEN THEY START, only where that is after the place comes free - set
    // server-side by fill_suggestions(). Since 6 Nov 2026 a start more than 90
    // days out drops a family from this list, so the date that decided they are
    // on it belongs on the card.
    if (c.starts_on) meta.push('starts ' + fmtDate(c.starts_on));
    // Age AT THE DATE THEY WOULD START, which is what room_accepts_age() and the
    // sub-quotas are judged on - children age through the year, so their age
    // today is not the number that decides this.
    if (c.age_months !== null && c.age_months !== undefined) {
      meta.push(ageLabel(c.age_months) + ' at start');
    }
    if (c.date_added) meta.push('waiting since ' + fmtDate(c.date_added));

    // THE NAME OPENS THEIR APPLICATION AND THE CONTACT LOG - the same modal the
    // Waitlist queue opens from the same handle, one definition in
    // static/js/waitlist-modal.js. Every action on this card is a conversation
    // with a family (ring them, offer the place, start them enrolling), and
    // until 14 Aug 2026 the one thing you could not do from here was find out
    // what the last person had already told them: the log was on another screen,
    // behind a search for a name this card was already showing.
    return '<div class="cp-card' + (c.eligible ? '' : ' cp-blocked') + '">' +
      '<div class="cp-head">' +
      (c.waitlist_id
        ? '<button type="button" class="cp-name cp-open" data-wl="' + c.waitlist_id +
          '" title="Application and contact history">' + esc(c.name) + '</button>'
        : '<span class="cp-name">' + esc(c.name) + '</span>') +
      '<span class="cp-sub">#' + c.queue + ' in the queue</span></div>' +
      // Consider archiving rides with Do Not Enrol: both are things to know
      // before ringing, neither takes the family off this list. waitlist_stale().
      (c.dne || c.stale
        ? '<div class="cp-badges">' + dneBadge(c.dne) + staleBadge(c.stale) + '</div>' : '') +
      // Everything they want, with this cell's day ringed: granting Friday to a
      // family who asked for three days settles one third of a conversation, and
      // the strip is the only thing on the card that says so.
      '<div class="cp-strip">' + cellDays([], wd, c.days) +
      '<span class="cp-strip-txt">wants ' +
      esc(c.days.map(function (d) { return DAYS[d]; }).join(', ')) + '</span></div>' +
      (meta.length ? '<div class="cp-meta">' + esc(meta.join(' \u00b7 ')) + '</div>' : '') +
      // Reasons are SENTENCES from the waitlist ranker, not tags - one badge each
      // so they wrap as separate facts instead of running into one another.
      ((c.reasons || []).length
        ? '<div class="cp-badges cp-why">' + c.reasons.map(function (x, i) {
            // A sibling it names opens that child - see priority_reason_parts().
            return '<span class="badge text-bg-light border fw-normal">' +
              reasonHtml(c.reason_parts ? c.reason_parts[i] : null, x) + '</span>';
          }).join('') + '</div>'
        : '') +
      (c.guardian || c.guardian_phone
        ? '<div class="cp-contact">' +
          (c.guardian ? '<span class="cp-gn">' + esc(c.guardian) + '</span>' : '') +
          callLink(c.guardian_phone, null) + '</div>'
        : '') +
      (c.eligible
        ? actionRow({ waitlist_id: c.waitlist_id, name: c.name, room_id: r.id,
                      wd: wd, work: c.work })
        // No action on a family the room cannot take. The reason is the content.
        : '<div class="cp-blocked-why"><i class="fa-solid fa-ban fa-xs me-1"></i>' +
          esc(c.blocked) + '</div>') +
      '</div>';
  }

  /**
   * The roll: who actually holds a place in this room on this weekday.
   *
   * IT WAS A WRAP OF CHIPS AND IT HAD TO BECOME A LIST. Fifteen names flowing
   * three to a line is a paragraph of names - there is no column to scan, no way
   * to find one child, and every chip said exactly one thing (a name) when the
   * data behind it says four. The one fact worth the width is HOW MANY DAYS EACH
   * CHILD HOLDS: a room of five-day children and a room of Friday-only children
   * are different rooms to sell into, and nothing on this panel said which this
   * was.
   *
   * The days come off r.placements, which cells_year() already ships whole so the
   * editor can open without a round trip - no new field, no second request.
   * Keyed by placeKey() because a derived child's `id` is NULL and a null is not
   * an identity; see the trap documented in placement-board.md.
   */
  /**
   * WHEN THIS CHILD HOLDS THE DAY, printed only where it is not simply "for as
   * long as anybody does". board_span_edge() makes that call server-side against
   * the CELL's own span, so a row is dated exactly when the date tells it apart
   * from the rows around it - 69 rows group-wide out of 180 cells. Bounding it
   * by the calendar instead put "to 23/12" on every child in every room, the day
   * the centres shut, and buried the four that meant something.
   *
   * This is where a child enrolled to start in September now appears. Until 21
   * Aug 2026 they were in no room at all and the only trace of them was a badge
   * beside the figures; the team's rule is that their spot is taken from the
   * moment they are enrolled, so they belong on the roll with a date on them.
   */
  function heldWhen(ch) {
    if (!ch.from && !ch.to) return '';
    var bits = [];
    if (ch.from) bits.push('from ' + fmtDate(ch.from));
    // A "to" date SAYS WHAT IT IS (10 Nov 2026): leaving care, giving up this
    // day, or moving room. A provisional child has no bookings to read and keeps
    // the bare date.
    if (ch.to) {
      var e = ch.ends;
      if (!e) {
        bits.push('to ' + fmtDate(ch.to));
      } else {
        var what = e.kind === 'leaving' ? 'leaving care'
                 : e.kind === 'moving'  ? 'moving to ' + esc(e.room || 'another room')
                 : 'giving up this day';
        bits.push('<span class="cp-end"' + (e.reason ? ' title="' + esc(e.reason) + '"' : '') + '>' +
          what + '</span>, last day ' + (e.kind === 'moving' ? 'here ' : '') + fmtDate(ch.to));
      }
    }
    return ' <span class="cp-when">' + bits.join('; ') + '</span>';
  }

  function rollTable(c, r, wd) {
    var byKey = {};
    (r.placements || []).forEach(function (p) {
      byKey[placeKey(p.kidsoft_child_id, p.id)] = p;
    });

    // THIS DAY'S CHILDREN BY STAFFING BAND, in a room that holds more than one.
    // The ratio is worked out per day, and this is the day - so the figure the
    // tones ask the reader to count is counted for them. One line, not a table:
    // it qualifies the roll beneath it.
    //
    // THE ROOM'S BANDS, NOT THE DAY'S (14 Oct 2026, asked for "every day"). It
    // used to appear only when this day itself held two bands, so it vanished on
    // 7 of the 120 days in mixed rooms - Erina Little Angels Wed to Fri, Terrigal
    // Little Angels Tue and Amazing Asteroids Wed to Fri - the very day somebody wants to
    // read "no under-3s today". Same bands as the roster's rows, a 0 included.
    var onDay = c.children.map(function (ch) {
      return byKey[placeKey(ch.ks_id, ch.id)] || { status: ch.status, age_band: null };
    }).filter(function (p) { return p.status !== 'waitlist' && p.status !== 'no_position'; });
    var bands = bandsPresent(r.placements || []);
    var bandLine = !bands.length ? '' : '<div class="cp-bands">' + bands.map(function (b) {
      return '<span class="cp-band">' + bandKey(b) + ' <strong>' +
        onDay.filter(function (p) { return p.age_band === b; }).length + '</strong></span>';
    }).join('') + '</div>';

    var roll = sortByBand(c.children,
      function (ch) { var p = byKey[placeKey(ch.ks_id, ch.id)]; return p ? p.age_band : null; },
      function (ch) { return ch.name || ''; });

    // NOT .table-scroll. That caps its height and grows a second scrollbar
    // inside a panel that already scrolls - one list, one scrollbar.
    return '<div>' + bandLine + '<table class="table table-sm align-middle mb-0 cp-roll">' +
      '<thead><tr><th>Child</th><th class="cp-dcol">Days held</th></tr></thead>' +
      '<tbody>' + roll.map(function (ch) {
        var key = placeKey(ch.ks_id, ch.id);
        var p   = byKey[key];
        var days = p ? p.days : [wd];
        return '<tr><td><span class="place-chip st-' + esc(ch.status) + bandClass(p) + '" data-key="' + esc(key) +
          '" tabindex="0" role="button" title="' + esc(chipTitle(ch.flags, ch.status, p)) + '">' +
          esc(ch.name) + flagIcons(ch.flags) + '</span>' + heldWhen(ch) + '</td>' +
          '<td class="cp-dcol">' + cellDays(days, wd, null, endingDays(r, ch.ks_id, days)) +
          '<span class="cp-dn">' + days.length + '</span></td></tr>';
      }).join('') + '</tbody></table></div>';
  }

  /**
   * ONE ROOM, ONE WEEKDAY, FOR THE YEAR.
   *
   * The panel opens with the figures the grid cell was showing, so the block you
   * clicked and the panel that answers it cannot say different things - and then
   * it stops describing and starts listing. The block ORDER is policy, set out in
   * placement-board.md, and must not be rearranged:
   *
   *   0. who is part-way through enrolling here (qualifies "N free" above it,
   *      and is NOT a source of demand - nothing else may be inserted here)
   *   1. who is enrolled on this day
   *   1a. who has been OFFERED this day and has not answered
   *   1b. who this day is being kept for against re-enrolment
   *   2. who has asked for this exact day        <- existing families, they come first
   *   3. who else could take it, from the queue
   *   4. who is in this room on other days       <- a COUNT, context only
   *
   * 1a and 1b sit under the roll and above every source of demand because they
   * are the same kind of fact as the roll: the place is gone. They are not
   * candidates and must never be read as a shortlist.
   */
  function renderCell() {
    if (!openCell) return;
    var r = room(openCell.roomId);
    if (!r) { cellPanel.hide(); return; }
    var wd = openCell.wd;
    var c = r.cells[wd];

    // The title names the room; the weekday and the year are the subtitle, so the
    // heading is not a sentence that has to be read to the end to find out which
    // room it is. The figures moved into the body as tiles - a comma-separated
    // sentence of four numbers is the shape this panel had everywhere and is
    // exactly what made it unreadable.
    $('#cell-title').html(roomIcon(r.name) + esc(r.name));
    $('#cell-subtitle').text('Every ' + DAYS_LONG[wd] + ' in ' + data.year);
    // Opened from the room sheet's day head: the way back to the whole room.
    var back = rvBack ? room(rvBack) : null;
    $('#cell-back').toggleClass('d-none', !back)
      .attr('title', back ? 'Back to everyone in ' + back.name : '');

    var body = '';

    // --- The figures, as figures ---------------------------------------------
    // placed / free / what Kidsoft actually bills. `free` carries the heat
    // language of the grid: full is the good state, a place going spare is the
    // one to act on.
    var freeTxt = c.capacity === null ? '--' : c.free;
    body += '<div class="cp-stats">' +
      '<div class="cp-stat"><div class="cp-n">' + c.placed +
        (c.capacity === null ? '' : '<span class="cp-of">/' + c.capacity + '</span>') +
        '</div><div class="cp-l">placed</div></div>' +
      '<div class="cp-stat ' + (c.capacity === null ? '' : (c.free ? 'cp-act' : 'cp-good')) +
        '"><div class="cp-n">' + freeTxt + '</div><div class="cp-l">' +
        (c.capacity === null ? 'no capacity set' : 'free') + '</div></div>' +
      '<div class="cp-stat"><div class="cp-n">' +
        (c.booked === null ? '--' : c.booked.typical) +
        '</div><div class="cp-l">Kidsoft books</div></div>' +
      '</div>';

    // --- What qualifies those figures ----------------------------------------
    // Supply facts, all of them, and all of them badges rather than sentences:
    // each one is a number and a date, which is a badge's whole job.
    var q = [];
    if (c.over) {
      q.push('<span class="badge text-bg-danger">' + (c.placed - c.capacity) +
        ' over capacity</span>');
    }
    // NO "N held by a pending offer" BADGE ANY MORE (9 Sep 2026). It said how
    // many and not which, and the families are now listed under the roll in
    // block 1a - the same move `held` made, and for the same reason: a figure
    // that opens into the children behind it does not also need a caption.
    // NO "already taken" BADGE. See the matching note in cellNotes(): the child
    // is now in the roll below with their start date on them, which is where
    // somebody looking for the missing place actually goes.
    // ADDITIONAL to `free`, never a restatement of it: `free` is what is spare at
    // the fullest moment of the rest of the year, this is what opens up
    // afterwards. A cell reading "0 free" and "+1 from 3/09" says both truthfully.
    if (c.coming_free) {
      q.push('<span class="badge text-bg-warning">+' + c.coming_free + ' free' +
        (c.free_from ? ' from ' + fmtDate(c.free_from) : ' later this year') + '</span>');
    }
    // A place given up that an arrival has already covered. Worth saying, because
    // somebody will hear about the departure and come looking for the vacancy.
    if (c.leaving && !c.coming_free) {
      q.push('<span class="badge text-bg-light border">' + c.leaving + ' place' +
        (c.leaving === 1 ? '' : 's') + ' given up' +
        (c.free_from ? ' from ' + fmtDate(c.free_from) : '') + ', already re-filled</span>');
    }
    (c.subquotas || []).forEach(function (s) {
      q.push('<span class="badge ' + (s.full ? 'text-bg-danger' : 'text-bg-light border') + '">' +
        esc(s.label) + ' ' + s.used + '/' + s.max + '</span>');
    });
    if (q.length) body += '<div class="cp-badges cp-qual">' + q.join('') + '</div>';

    // WHO the "+N free" is (10 Nov 2026): the badge said how many and from when,
    // and the reader still had to work out from the roll below whether a child
    // was leaving or giving up this one day. Named here, in the same words as
    // the banner and the roll.
    var gone = c.departures || [];
    if (gone.length) {
      body += '<ul class="cp-leavers">' + gone.map(function (x) {
        var what = x.kind === 'leaving'
          ? 'is leaving care' + (x.reason ? ' (' + esc(x.reason) + ')' : '')
          : 'is giving up ' + DAYS_LONG[wd] + 's' +
            (x.keeps && x.keeps.length ? ', keeps ' + dayList(x.keeps) : '');
        // The badge carries the soonest date; with more than one child behind
        // it, each line says when THEIR place frees.
        var when = x.refilled ? ' <span class="text-muted">- already re-filled</span>'
                 : gone.length > 1 && x.free_from ? ' <span class="text-muted">- free from ' +
                   fmtDate(x.free_from) + '</span>' : '';
        return '<li>' + childLink(x.ks_id, x.name) + ' ' + what + when + '</li>';
      }).join('') + '</ul>';
    }

    // --- The year, in one grey line ------------------------------------------
    // THIS WAS AN AMBER ALERT AND IT FIRED ON EVERY CELL IN THE APP - measured
    // 95 of 95 across Kanwal, Tuggerah and Terrigal on 14 Aug 2026. An alert
    // that is always on is not an alert, it is a border; and the shape said
    // "something is wrong here" on cells where nothing was. The fact itself is
    // real and stays: an empty place-day is a child who could have been in that
    // room, and it is the reason this screen is a year rather than a week.
    //
    // `weeks` is the year's count of THIS weekday, so both halves of the
    // fraction come from one population - they did not until 13 Aug 2026, when
    // this read "empty on 52 of 36".
    if (c.booked) {
      body += '<p class="cp-year">Kidsoft billed ' +
        // A range whose ends are equal is not a range. Warnervale is not trading,
        // so every cell there read "billed 0 to 0", which is a sentence written
        // by a template rather than about a room.
        (c.booked.min === c.booked.max
          ? c.booked.min
          : c.booked.min + ' to ' + c.booked.max) +
        ' on ' + DAYS_LONG[wd] + 's' +
        (c.booked.free_days > 0
          ? '; a place stood empty on ' + c.booked.weeks_free + ' of ' + c.booked.weeks +
            ' (' + c.booked.free_days + ' un-occupied positions)'
          : '') + '.</p>';
    }

    // 0. IS SOMEBODY ALREADY ON THIS SPOT? Above everything else, because it
    //    qualifies the free figure rather than adding to the four blocks below --
    //    those are ordered by policy and this is not a source of demand. Only a
    //    formal offer holds a place, so the "N free" above counts places these
    //    families may already be most of the way toward taking.
    if (c.in_progress_n) {
      body += '<div class="alert alert-warning py-2 mb-3">' +
        '<div class="fw-semibold"><i class="fa-solid fa-list-check me-1"></i>' +
        c.in_progress_n + ' famil' + (c.in_progress_n === 1 ? 'y is' : 'ies are') +
        ' part-way through enrolling for this day</div>' +
        '<div class="small">Not holding a place yet, so the free figure above does ' +
        'not account for them.</div>' +
        '<div class="cp-badges mt-2">' + c.in_progress.map(function (w) {
          return '<button class="btn btn-sm btn-outline-secondary work-open" ' +
            'data-id="' + w.id + '">' + esc(w.child_name) +
            ' <span class="badge text-bg-light border">' + esc(w.stage_label) + '</span>' +
            (w.owner_email ? ' <span class="text-muted">' +
              esc(w.owner_email.split('@')[0]) + '</span>' : '') +
            (w.idle_days > 6 ? ' <span class="text-muted">idle ' + w.idle_days + 'd</span>' : '') +
            '</button>';
        }).join('') + '</div></div>';
    }

    // 1. WHO IS ENROLLED. The question the grid exists to answer, so it leads.
    body += '<h6 class="cp-h">Enrolled on this day <span>' + c.children.length + '</span></h6>';
    body += c.children.length
      ? rollTable(c, r, wd)
      : '<p class="text-muted small">Nobody is placed in this room on ' + DAYS_LONG[wd] + 's.</p>';

    // 1a. WHO HAS BEEN OFFERED THIS DAY AND HAS NOT ANSWERED YET.
    //     Straight under the roll and in the roll's own shape (Broc, 9 Sep 2026:
    //     "could the held days for the ones we have offered be visible and
    //     display like the enrolled children?"). These children are not on the
    //     roll and their days are already out of `free`, so without this the
    //     panel showed a day with places gone and nobody holding them - the same
    //     confusion 1b was built to head off, and it had a badge saying "2 held
    //     by a pending offer" that named neither family.
    //
    //     THE STRIP IS NEUTRAL, NOT GREEN. Green means attends, and none of
    //     these children do yet - the offer may still lapse. `plain` is the state
    //     that marks a day without claiming anything about it; see
    //     window.dayStrip().
    if (c.promised_children && c.promised_children.length) {
      // "NOT ENROLLED YET", not "waiting for an answer" (15 Oct 2026): a Kidsoft
      // offer at Form Submitted HAS been answered - the family said yes and sent
      // the form - and still holds the place until Kidsoft enrols them.
      body += '<h6 class="cp-h">Offered, not enrolled yet <span>' +
        c.promised_children.length + '</span></h6>';
      body += '<p class="text-muted small mb-2">Not on the roll above. The offer ' +
        'holds these days, so they are already out of the free figure and cannot ' +
        'be offered again unless the offer lapses or the family says no.</p>';
      body += '<div><table class="table table-sm align-middle mb-0 cp-roll">' +
        '<thead><tr><th>Child</th><th class="cp-dcol">Days held</th></tr></thead>' +
        '<tbody>' + c.promised_children.map(function (o) {
          // The name opens their application and the contact log, the same
          // handle the candidate cards carry - every action on one of these
          // rows is a conversation with a family.
          return '<tr><td><button type="button" class="cp-name cp-open" data-wl="' +
            o.waitlist_id + '" title="Application and contact history">' +
            esc(o.name) + '</button>' +
            ' <span class="cp-when">' + offerWhen(o) +
            (o.starts ? ', starts ' + esc(o.starts) : '') + '</span></td>' +
            '<td class="cp-dcol">' +
            dayStrip({ plain: o.days, plainSaid: 'held by this offer', day: wd }) +
            '<span class="cp-dn">' + o.days.length + '</span></td></tr>';
        }).join('') + '</tbody></table></div>';
    }

    // 1b. WHO THE CELL IS BEING KEPT FOR. Directly under the roll, because these
    //     children are not on it and that is exactly the confusion to head off:
    //     they are in another room THIS year and the place here is being kept
    //     against their re-enrolment. Without this the panel showed a day with
    //     "0 free" and a roll that plainly did not fill it.
    if (c.held_children && c.held_children.length) {
      body += '<h6 class="cp-h">Waiting for re-enrolment <span>' +
        c.held_children.length + '</span></h6>';
      var shortBy = (c.capacity === null) ? 0
        : Math.max(0, c.placed + (c.promised || 0) + c.held - c.capacity);
      body += '<p class="text-muted small mb-2">Not on the roll above. ' +
        'They hold this day now and must be re-offered the same days, so the ' +
        'place is not free to sell until they answer or the hold runs out.' +
        (shortBy ? ' <span class="text-danger fw-semibold">This day is ' + shortBy +
          ' over - they cannot all keep it.</span>' : '') + '</p>';
      // NOT `btn ... disabled`. These are facts, not controls, and the disabled
      // class greyed the child's own name out to the point of being the faintest
      // thing in a panel it is the subject of.
      body += '<div class="cp-held mb-3">' + c.held_children.map(function (h) {
        return '<div class="cp-held-row">' +
          childLink(h.ks_id, h.name) +
          '<span class="cp-held-from">' + esc(h.from_room) + ' now</span>' +
          '<span class="badge text-bg-light border">holds to ' + esc(h.hold_until) + '</span>' +
          (h.chase_due ? ' <span class="badge text-bg-danger">due a call</span>' : '') +
          (h.school_age ? ' <span class="cp-held-note">school age</span>' : '') +
          '</div>';
      }).join('') + '</div>';
    }
    // 1c. THE PLACE IS BEING KEPT FOR SOMEBODY WHO IS ON THE ROLL, just not on
    //     this day. A child enrolled in this room whose booking for this weekday
    //     has come off while an arrangement is worked through - a Behaviour
    //     Support Plan is the case that produced it. Third of the three "the
    //     place is gone but the roll does not show it" blocks and last of them,
    //     because it is the only one where the family is already in the room:
    //     the reader has seen the name three lines above, on their other days.
    if (c.reserved_children && c.reserved_children.length) {
      body += '<h6 class="cp-h">Held, not booked <span>' +
        c.reserved_children.length + '</span></h6>';
      body += '<p class="text-muted small mb-2">Enrolled in this room and not booked ' +
        'on this day. The place is being kept for them, so it is out of the free ' +
        'figure and cannot be offered to anybody else. It stays held until somebody ' +
        'releases it, or until the booking is back in Kidsoft.</p>';
      body += '<div class="cp-held mb-3">' + c.reserved_children.map(function (x) {
        // THE WHOLE WEEK, NOT THE ONE BOX, exactly as block 1a shows an offer's
        // full set of days and rings the one this cell is about. A child held out
        // of Thursday AND Friday otherwise appears on two cells with no hint of
        // the other, and the days they DO still attend - the first thing anybody
        // asks when deciding whether the hold is still right - were nowhere.
        // An empty green set is a real answer: a child removed from care pending
        // an improvement in behaviour holds no booking at all.
        return '<div class="cp-held-row">' +
          childLink(x.ks_id, x.name) +
          dayStrip({ has: x.days || [], held: x.held_days || [], day: wd }) +
          (x.reason ? '<span class="cp-held-note">' + esc(x.reason) + '</span>' : '') +
          '<span class="badge text-bg-light border">held since ' +
            esc(x.held_from_display) + '</span>' +
          // RELEASING IS THE ONE ACTION ON THIS ROW, and it belongs here as well
          // as on the roster: this panel is where somebody stares at "0 free" and
          // asks why, and the answer and the undo should not be on two screens.
          ' <button type="button" class="btn btn-sm btn-outline-secondary hold-release" ' +
            'data-id="' + x.id + '">Release this day</button>' +
          '</div>';
      }).join('') + '</div>';
    }

    // NO "+ Add a child" HERE ANY MORE. See the note at the top of this section:
    // never once used, and a hand-typed copy of a roll Kidsoft owns.

    // 2. FAMILIES WHO HAVE ALREADY ASKED FOR THIS EXACT DAY. Live from Kidsoft's
    //    waitlisted bookings plus our own internal day waitlist - nobody typed
    //    the first and nothing needs saving on either.
    //    THESE COME FIRST, AND THAT IS POLICY, NOT LAYOUT: families already in
    //    care have preference for extra days. A child enrolled two days whose
    //    parents want a third is on this list precisely because there was no
    //    space; when a space appears it is theirs before the external queue's.
    if (c.requests && c.requests.length) {
      body += '<h6 class="cp-h">Asked for this day <span>' + c.requests.length + '</span></h6>';
      if (c.grantable) {
        body += '<div class="alert alert-success py-2 small mb-2">' +
          '<i class="fa-solid fa-person-arrow-up-from-line me-1"></i>' + c.free + ' place' +
          (c.free === 1 ? '' : 's') + ' free on this day, and ' + c.requests.length +
          ' famil' + (c.requests.length === 1 ? 'y' : 'ies') + ' below asked for it.</div>';
      } else {
        body += '<p class="text-muted small mb-2">No place free, so these stay waiting. ' +
          'They are the demand to weigh if anyone drops a day.</p>';
      }
      body += c.requests.map(function (x) { return requestBlock(x, r, wd); }).join('');
    }

    // 3. Who else could take a free place, from our own queue. Near misses appear
    //    with their reason, so an empty shortlist does not read as an empty
    //    waitlist.
    if (c.free > 0) {
      // COUNT THE ONES WHO ACTUALLY COULD. fill.php returns near misses in the
      // same list when nothing fits, so a raw length put "3" beside a heading
      // reading "Could take this day" on a room where all three are the wrong
      // age - the heading and its own badge disagreeing about the same three
      // families.
      var fit = c.candidates.filter(function (x) { return x.eligible; }).length;
      body += '<h6 class="cp-h">Could take this day' +
        (fit ? ' <span>' + fit + '</span>' : '') + '</h6>';
      if (!c.candidates.length) {
        body += '<p class="text-muted small">Nobody in the queue wants ' + DAYS_LONG[wd] +
          ' at this centre.</p>';
      } else {
        // Near misses are shown WITH the reason, so an empty shortlist does not
        // read as an empty waitlist - "three families want Monday but all are
        // the wrong age for this room" is the useful answer.
        if (!fit) {
          body += '<p class="text-muted small mb-2">Nobody in the queue fits this room on ' +
            DAYS_LONG[wd] + 's. The nearest ' +
            (c.candidates.length === 1 ? 'is' : c.candidates.length + ' are') + ':</p>';
        }
        body += c.candidates.map(function (x) { return candidateBlock(x, r, wd); }).join('');
      }
    }

    // 4. CONTEXT ONLY, AND A COUNT ON PURPOSE.
    //
    //    THIS IS NOT A CALL LIST, AND IT USED TO BE ONE. It was built as "the
    //    second source of demand", ordered fewest-days-first with each family's
    //    phone number, and deliberately placed ABOVE the queue. That is upselling,
    //    and Little Miracles does not upsell as a policy (Broc, 7 Aug 2026). A
    //    family already in care who wants more days ASKS - and then they are in
    //    block 2 above, where they get preference.
    //
    //    Kept because it describes the room's shape, which is what says whether a
    //    day is genuinely sellable. It was three lines of prose explaining itself;
    //    the team knows their own rooms, so it is now the count and nothing else.
    if (c.extra_day_n) {
      body += '<p class="cp-also">' + c.extra_day_n + ' other child' +
        (c.extra_day_n === 1 ? '' : 'ren') + ' in this room, not on ' + DAYS_LONG[wd] + 's.</p>';
    }

    $('#cell-body').html(body);
  }

  // --- The room sheet ---------------------------------------------------------
  //
  // ONE ROOM, EVERY CHILD, ALL FIVE WEEKDAYS - IN A DIALOG OF ITS OWN (13 Nov 2026).
  //
  // This is the per-room sheet the workbooks kept, and the count grid
  // deliberately replaced it - a count is what says whether a day is SOLD, which
  // is the job. But a count cannot say who is in the room, and that is what a
  // ratio check, a room leader and a parent on the phone all ask for. It used to
  // be drawn into the cell panel, 560px wide, as a list of ticks with the
  // families who are not on the roll in tinted rows and four paragraphs of
  // footnotes under it explaining the marks.
  //
  // Broc asked for it as a dialog that says more about the room across the week:
  // the enrolled children AND everybody else whose name belongs on that sheet -
  // offered, waiting on paperwork, moving rooms, ending care, swapping days,
  // dropping days - in colour. So:
  //
  //   - EVERY FACT HERE IS ONE THE BOARD ALREADY SHIPS. Nothing is fetched and
  //     nothing is re-derived: who is ending what is cells_end_kind()'s answer
  //     (`ends` on the roll row), which places come free is `departures`, the
  //     offered families are `promised_children`, and so on. What is composed
  //     here is only what a ROW is about - "swapping days" is a day being given
  //     up and another day starting, in the same room, for the same child, and
  //     "moving rooms" is this room's start lining up with another room's move.
  //   - THE ROW STATUSES ARE THE WORKBOOK'S OWN LEGEND (.st-*). The team learned
  //     Ending care, Move rooms, Swap days, Dropping day, Offered and Waiting for
  //     paperwork as colours on their spreadsheets; here Kidsoft supplies the
  //     fact and nobody has to type the status.
  //   - THE DAY BLOCKS SPEAK window.dayStrip()'s LANGUAGE, bigger: green
  //     attends, amber coming free, pale amber held, blue asked for, neutral
  //     held by an offer. One fact keeps one colour on every screen.
  //   - THE LEGEND IS THE FILTER. Each status is a chip with its count, and
  //     pressing one shows only those rows - so "who is leaving this room" is one
  //     click rather than a scan of forty names.
  //   - THE COLUMN HEADS ARE THE GRID'S OWN FIGURES for that day, with a bar of
  //     one box per place, and they open the cell, which is where the shortlist
  //     and the actions are.

  /**
   * What a row can be about. `pill` is the class its label takes - the workbook
   * statuses keep their .st-* identity colours, and the four the workbook never
   * had take a step of the hue the same fact already wears elsewhere.
   */
  var RV_KIND = {
    enrolled:  { label: 'Enrolled',              pill: 'st-confirmed' },
    starting:  { label: 'Starting',              pill: 'rv-p-start',           icon: 'fa-right-to-bracket' },
    leaving:   { label: 'Ending care',           pill: 'st-ending_care',       icon: 'fa-right-from-bracket' },
    dropping:  { label: 'Dropping days',         pill: 'st-dropping_day',      icon: 'fa-calendar-minus' },
    swapping:  { label: 'Swapping days',         pill: 'st-swap_days',         icon: 'fa-right-left' },
    moving:    { label: 'Moving rooms',          pill: 'st-move_rooms',        icon: 'fa-arrows-turn-right' },
    offered:   { label: 'Offered',               pill: 'st-offered',           icon: 'fa-bookmark' },
    paperwork: { label: 'Waiting for paperwork', pill: 'st-waiting_paperwork', icon: 'fa-file-signature' },
    reenrol:   { label: 'Re-enrolment',          pill: 'rv-p-reenrol',         icon: 'fa-rotate' },
    held:      { label: 'Day held',              pill: 'rv-p-held',            icon: 'fa-lock' },
    asked:     { label: 'Asked for a day',       pill: 'rv-p-asked',           icon: 'fa-hourglass-half' },
    progress:  { label: 'In progress',           pill: 'rv-p-prog',            icon: 'fa-list-check' }
  };
  // A status somebody TYPED on the placement editor, for the few rows that carry
  // one. Kidsoft's answer wins wherever it has one; this only names a row it
  // says nothing about.
  var RV_STATUS_KIND = {
    ending_care: 'leaving', move_rooms: 'moving', swap_days: 'swapping',
    dropping_day: 'dropping', offered: 'offered', waiting_paperwork: 'paperwork'
  };
  var WDS = [1, 2, 3, 4, 5];

  var roomModal = new bootstrap.Modal('#room-modal');
  var rvFilter = null;  // the chip pressed, or null for everybody
  var rvAway = false;   // stepped aside for another dialog, and comes back when it shuts
  var rvBack = null;    // the room a cell was opened FROM, for the panel's way back

  function rvPill(kind, label, title) {
    var k = RV_KIND[kind];
    return '<span class="rv-pill ' + k.pill + '"' + (title ? ' title="' + esc(title) + '"' : '') + '>' +
      (k.icon ? '<i class="fa-solid ' + k.icon + '" aria-hidden="true"></i>' : '') +
      esc(label || k.label) + '</span>';
  }

  /**
   * A CHILD'S AGE ON THE DAY THEIR STAFFING BAND IS READ (`age_bands.on`, today
   * for the year running, 1 January for one being planned), so the age printed
   * beside a chip and the green tone of the chip are about the same day.
   * `age_months` on the row is NOT this: it is the age at 1 January, which is
   * what sub-quotas test, and it put "1y 9m" on a child of two and a half.
   * Plain arithmetic on the two strings - never new Date(), see app.js.
   */
  function ageOn(dob) {
    var on = data && data.age_bands && data.age_bands.on;
    if (!dob || !on) return null;
    var b = dob.split('-').map(Number), o = on.split('/').map(Number);   // o = [d, m, y]
    if (b.length !== 3 || o.length !== 3) return null;
    var m = (o[2] - b[0]) * 12 + (o[1] - b[1]) - (o[0] < b[2] ? 1 : 0);
    return m < 0 ? null : m;
  }

  /** The latest of a set of Y-m-d strings (they sort as text). */
  function rvLatest(list) {
    return list.reduce(function (a, b) { return !a || b > a ? b : a; }, null);
  }
  function rvEarliest(list) {
    return list.reduce(function (a, b) { return !a || b < a ? b : a; }, null);
  }

  /**
   * EVERYTHING ONE ROOM'S SHEET SAYS, worked out from the board on the page.
   * Returns { roll, offered, kept, asked, progress } - rows, each carrying the
   * tags its chips match, the label and detail lines, and one entry per weekday
   * saying what that day's box is.
   */
  function roomSheet(r) {
    // WHERE ELSE EACH CHILD IS, per weekday, off every other room's roll. A child
    // split across two rooms is common (a Monday in Radiant Rainbows and a
    // Tuesday and Thursday in Little Angels), and a row with three empty boxes
    // reads as a part-time child when they are in the building all week.
    var elsewhere = {};
    data.rooms.forEach(function (o) {
      if (o.id === r.id) return;
      WDS.forEach(function (wd) {
        ((o.cells[wd] || {}).children || []).forEach(function (ch) {
          if (!ch.ks_id) return;
          var m = elsewhere[ch.ks_id] = elsewhere[ch.ks_id] || {};
          (m[wd] = m[wd] || []).push({ room: o, ch: ch });
        });
      });
    });

    // Kidsoft day requests (with any internal wish merged onto them) by family,
    // per weekday. A child on the roll shows theirs in their own empty boxes;
    // anybody else gets a row in "Asked for a day".
    var asks = {};
    WDS.forEach(function (wd) {
      (r.cells[wd].requests || []).forEach(function (q) {
        var k = q.kidsoft_child_id ? 'k' + q.kidsoft_child_id : 'n' + q.name;
        (asks[k] = asks[k] || { q: q, days: {} }).days[wd] = q;
      });
    });

    var out = { roll: [], offered: [], kept: [], asked: [], progress: [] };
    var onRoll = {};

    sortByBand(r.placements || [], function (p) { return p.age_band; }, placeName).forEach(function (p) {
      var key  = placeKey(p.kidsoft_child_id, p.id);
      var ks   = p.kidsoft_child_id;
      onRoll[key] = true;
      var name = placeName(p) || 'Unnamed';
      var wish = (r.wishes || {})[key] || null;
      var hold = (r.holds  || {})[key] || null;
      var ask  = ks ? asks['k' + ks] || null : null;
      if (ask) ask.used = true;
      // Days whose place is genuinely going back on sale - the amber ones. A day
      // given up whose cell an arrival already covers is not one.
      var coming = endingDays(r, ks, p.days);
      var flags = [];
      if (p.is_sibling) flags.push('sibling');
      if (p.is_staff_child) flags.push('staff');
      if (p.needs_two_educators) flags.push('two_educators');
      if (p.has_behaviour_plan) flags.push('behaviour');
      if (p.has_individual_plan) flags.push('individual');

      var days = {}, ending = [], starting = [], kinds = {}, reason = null, toRoom = null;
      WDS.forEach(function (wd) {
        if (p.days.indexOf(wd) !== -1) {
          var ch = null;
          (r.cells[wd].children || []).forEach(function (x) {
            if (placeKey(x.ks_id, x.id) === key) ch = x;
          });
          var d = { on: true, from: ch ? ch.from : null, to: ch ? ch.to : null,
                    ends: ch ? ch.ends || null : null, coming: coming.indexOf(wd) !== -1 };
          if (d.to) ending.push(wd);
          if (d.from) starting.push(wd);
          if (d.ends) {
            kinds[d.ends.kind] = true;
            if (d.ends.reason) reason = d.ends.reason;
            if (d.ends.kind === 'moving' && d.ends.room) toRoom = d.ends.room;
          }
          days[wd] = d;
        } else {
          days[wd] = {
            on: false,
            hold: hold && hold.days.indexOf(wd) !== -1 ? hold : null,
            wish: wish && wish.days.indexOf(wd) !== -1 ? wish : null,
            ask: ask && ask.days[wd] ? ask.days[wd] : null,
            other: ks && elsewhere[ks] ? (elsewhere[ks][wd] || []) : []
          };
        }
      });

      // MOVING IN: they start here later, and the room they are in now has
      // them leaving it as a move. Preferably the move that names THIS room.
      var fromRoom = null;
      if (starting.length && ks && elsewhere[ks]) {
        WDS.forEach(function (wd) {
          (elsewhere[ks][wd] || []).forEach(function (e) {
            if (!e.ch.ends || e.ch.ends.kind !== 'moving') return;
            if (!fromRoom || e.ch.ends.room === r.name) fromRoom = e.room;
          });
        });
      }

      var holdless = p.status === 'waitlist' || p.status === 'no_position';
      var lastDay  = rvLatest(ending.map(function (wd) { return days[wd].to; }));
      var firstDay = rvEarliest(starting.map(function (wd) { return days[wd].from; }));
      var row = { type: 'roll', p: p, key: key, name: name, days: days, flags: flags,
                  tags: ['enrolled'], lines: [], count: p.days.length, faded: holdless };

      // ONE HEADLINE PER ROW, in the order the question matters: a child leaving
      // the service outranks the detail of which days, a move outranks a start.
      var kind = null, det = '';
      if (kinds.leaving) {
        kind = 'leaving';
        det = 'Last day ' + fmtDate(lastDay) + (reason ? ' (' + esc(reason) + ')' : '');
      } else if (kinds.moving) {
        kind = 'moving';
        var allMove = ending.length === p.days.length;
        det = (allMove ? 'To ' : dayList(ending) + ' to ') + esc(toRoom || 'another room') +
          ', last day here ' + fmtDate(lastDay);
      } else if (fromRoom) {
        kind = 'moving';
        det = 'From ' + esc(fromRoom.name) + ', starts here ' + fmtDate(firstDay);
      } else if (kinds.dropping && starting.length) {
        kind = 'swapping';
        det = dayList(ending) + ' until ' + fmtDate(lastDay) + ', then ' + dayList(starting) +
          ' from ' + fmtDate(firstDay);
      } else if (kinds.dropping) {
        kind = 'dropping';
        var keeps = p.days.filter(function (wd) { return ending.indexOf(wd) === -1; });
        det = 'Gives up ' + dayList(ending) + ', last day ' + fmtDate(lastDay) +
          (keeps.length ? '; keeps ' + dayList(keeps) : '');
      } else if (starting.length) {
        kind = 'starting';
        det = starting.length === p.days.length
          ? 'Starts ' + fmtDate(firstDay)
          : 'Adds ' + dayList(starting) + ' from ' + fmtDate(firstDay);
      } else if (RV_STATUS_KIND[p.status]) {
        kind = RV_STATUS_KIND[p.status];
      }
      if (holdless) {
        row.lines.push('<span class="rv-pill st-' + esc(p.status) + '">' +
          esc(STATUS[p.status] || p.status) + '</span>' +
          '<span class="rv-det">On the board, not holding a place</span>');
      }
      if (kind) {
        row.tags.push(kind);
        // A status typed on the editor says so in its own words.
        var said = !det && RV_STATUS_KIND[p.status] ? STATUS[p.status] : null;
        row.lines.push(rvPill(kind, kind === 'starting' && starting.length !== p.days.length
          ? 'Adding days' : said) + (det ? '<span class="rv-det">' + det + '</span>' : ''));
      }
      // THE OTHER TWO FACTS AN EMPTY BOX CAN CARRY, as lines of their own: a day
      // the centre is keeping for them, and a day they are waiting for.
      var heldDays = WDS.filter(function (wd) { return days[wd].hold; });
      if (heldDays.length) {
        row.tags.push('held');
        row.lines.push(rvPill('held', 'Held') + '<span class="rv-det">' + dayList(heldDays) +
          (hold.reason ? ', ' + esc(hold.reason) : '') + ', since ' + esc(hold.held_from_display) +
          '</span>');
      }
      // TWO SOURCES, SAID APART: a day asked of Kidsoft carries its dates, a day
      // noted here carries what the parent said - and a note about Thursdays
      // must not read as though it were about the Tuesday beside it.
      var askDays  = WDS.filter(function (wd) { return days[wd].ask && !days[wd].wish; });
      var wishDays = WDS.filter(function (wd) { return days[wd].wish; });
      if (askDays.length || wishDays.length) {
        row.tags.push('asked');
        var said2 = [];
        if (askDays.length) {
          var q0 = days[askDays[0]].ask;
          said2.push(dayList(askDays) + ' asked in Kidsoft' +
            (q0.requested_at ? ' ' + fmtDate(q0.requested_at) : ''));
        }
        if (wishDays.length) {
          said2.push(dayList(wishDays) + (wish.note ? ': ' + esc(wish.note) : ' noted here'));
        }
        row.lines.push(rvPill('asked', 'Waiting for a day') + '<span class="rv-det">' +
          said2.join('; ') + '</span>');
      }
      out.roll.push(row);
    });

    // OFFERED, NOT ENROLLED YET. One row per offer, its days gathered back off
    // the five cells. A Kidsoft offer whose form is in is the workbook's
    // "waiting for paperwork": the family has said yes and the next move is ours.
    var seen = {};
    WDS.forEach(function (wd) {
      (r.cells[wd].promised_children || []).forEach(function (o) {
        if (seen[o.offer_id]) return;
        seen[o.offer_id] = true;
        var answered = o.kidsoft && o.state !== 'Offered in Kidsoft';
        var kind = answered ? 'paperwork' : 'offered';
        var det = [];
        if (o.expires) det.push('expires ' + esc(o.expires));
        if (o.starts) det.push('starts ' + esc(o.starts));
        if (!o.kidsoft) det.push('offered here');
        out.offered.push({ type: 'offer', o: o, name: o.name, tags: [kind], count: o.days.length,
          dayset: o.days, mark: answered ? 'paperwork' : 'offer',
          lines: [rvPill(kind, answered ? o.state : 'Offered') +
            (det.length ? '<span class="rv-det">' + det.join(', ') + '</span>' : '')] });
      });
    });

    // PLACES KEPT: a child in another room this year whose days here are held
    // against their re-enrolment, and a child enrolled here whose day is held
    // while they are not booked on it - including one removed from care, who is
    // on no roll at all. Neither is on `r.placements`, which is why each needs
    // its own row: "0 free" over a roll that does not fill it sends the reader
    // hunting otherwise.
    var reBy = {};
    WDS.forEach(function (wd) {
      (r.cells[wd].held_children || []).forEach(function (h) {
        var k = h.ks_id || h.name;
        if (!reBy[k]) {
          reBy[k] = { type: 'reenrol', h: h, name: h.name, ks: h.ks_id, tags: ['reenrol'],
                      dayset: [], mark: 'reenrol' };
          out.kept.push(reBy[k]);
        }
        reBy[k].dayset.push(wd);
      });
    });
    out.kept.forEach(function (x) {
      var h = x.h, here = h.from_room === r.name;
      x.count = x.dayset.length;
      x.lines = [rvPill('reenrol', 'Waiting for re-enrolment') + '<span class="rv-det">' +
        (here ? 'In this room now' : 'In ' + esc(h.from_room) + ' now') +
        ', held to ' + esc(h.hold_until) + '</span>' +
        (h.chase_due ? '<span class="badge text-bg-danger">due a call</span>' : '') +
        (h.school_age ? '<span class="badge text-bg-light border">school age</span>' : '')];
      if (!here) x.tags.push('moving');
    });
    var heldSeen = {};
    WDS.forEach(function (wd) {
      (r.cells[wd].reserved_children || []).forEach(function (x) {
        var k = placeKey(x.ks_id, x.placement_id);
        // On the roll already: their padlock is in their own row.
        if (heldSeen[k] || onRoll[k]) return;
        heldSeen[k] = true;
        out.kept.push({ type: 'heldout', x: x, name: x.name, ks: x.ks_id, tags: ['held'],
          dayset: x.held_days || [], mark: 'hold', count: 0,
          lines: [rvPill('held', 'Place held') + '<span class="rv-det">' +
            esc(x.reason || 'Not booked') + ', since ' + esc(x.held_from_display) + '</span>'] });
      });
    });

    // ASKED FOR A DAY, and not on this room's roll - a child in another room, or
    // one Kidsoft knows and the board does not show here.
    Object.keys(asks).forEach(function (k) {
      var a = asks[k];
      if (a.used) return;
      var dayset = Object.keys(a.days).map(Number);
      var q = a.q;
      var src = q.source === 'internal' ? 'Noted here' : (q.status || 'Kidsoft');
      out.asked.push({ type: 'asked', q: q, name: q.name, ks: q.kidsoft_child_id, tags: ['asked'],
        dayset: dayset, mark: 'ask', count: dayset.length,
        lines: [rvPill('asked') + '<span class="rv-det">' + esc(src) +
          (q.requested_at ? ', asked ' + fmtDate(q.requested_at) : '') + '</span>'] });
    });

    // PART-WAY THROUGH ENROLLING. Nothing is held for them, so the free figures
    // above still count these places - which is exactly why they are listed:
    // a room can read as having places free while two colleagues are ringing
    // families about them. work_by_cell() leaves out Offered and beyond.
    var progBy = {};
    WDS.forEach(function (wd) {
      (r.cells[wd].in_progress || []).forEach(function (w) {
        if (!progBy[w.id]) {
          progBy[w.id] = { type: 'progress', w: w, name: w.child_name, tags: ['progress'],
                           dayset: [], mark: 'prog' };
          out.progress.push(progBy[w.id]);
        }
        progBy[w.id].dayset.push(wd);
      });
    });
    out.progress.forEach(function (x) {
      var w = x.w;
      x.count = x.dayset.length;
      x.lines = [rvPill('progress', w.stage_label) + '<span class="rv-det">' +
        (w.owner_email ? esc(staffName(w.owner_email)) : 'Nobody yet') +
        (w.idle_days > 6 ? ', untouched ' + w.idle_days + ' days' : '') + '</span>'];
    });

    function byName(a, b) { return a.name.localeCompare(b.name); }
    out.offered.sort(byName); out.asked.sort(byName); out.progress.sort(byName);
    out.kept.sort(function (a, b) { return (a.type < b.type ? -1 : a.type > b.type ? 1 : 0) || byName(a, b); });
    return out;
  }

  /** One weekday's box on a roll row. */
  function rvRollDay(row, wd) {
    var d = row.days[wd], name = row.name;
    if (d.on) {
      var cls = 'rv-on', ico = 'fa-check', txt = '', said = name + ' attends on ' + DAYS_LONG[wd] + 's';
      if (d.from && d.to) {
        txt = fmtDate(d.from) + ' to ' + fmtDate(d.to);
        said += ' from ' + fmtDate(d.from) + ' to ' + fmtDate(d.to);
      } else if (d.from) {
        cls = 'rv-from'; ico = 'fa-right-to-bracket'; txt = 'from ' + fmtDate(d.from);
        said += ' from ' + fmtDate(d.from);
      }
      if (d.to) {
        var e = d.ends, what = !e ? 'last day'
          : e.kind === 'leaving' ? 'leaving care, last day'
          : e.kind === 'moving'  ? 'moving to ' + (e.room || 'another room') + ', last day here'
          : 'giving up this day, last day';
        if (!d.from) txt = 'to ' + fmtDate(d.to);
        said = name + ' - ' + DAYS_LONG[wd] + ': ' + what + ' ' + fmtDate(d.to);
        // AMBER ONLY WHERE THE PLACE IS GOING BACK ON SALE - the board's "+1
        // free" colour. A day an arrival has already re-filled, or one that ends
        // in the last week of the year, keeps its green and says so in words.
        if (d.coming) {
          cls = 'rv-end';
          ico = e && e.kind === 'moving' ? 'fa-arrows-turn-right'
              : e && e.kind === 'dropping' ? 'fa-calendar-minus' : 'fa-right-from-bracket';
          said += '. The place comes free after that.';
        } else {
          ico = 'fa-check';
          said += '. Not a place coming free - it is already re-filled, or the year ends first.';
        }
      }
      return '<td class="rv-d"><span class="rv-b ' + cls + '" title="' + esc(said) + '">' +
        '<i class="fa-solid ' + ico + '" aria-hidden="true"></i>' +
        (txt ? '<span class="rv-bt">' + txt + '</span>' : '<span class="visually-hidden">attends</span>') +
        '</span></td>';
    }

    // AN EMPTY DAY IS A CONTROL: click it to record that the family is waiting
    // for it, or that it is being held for them. Kidsoft has nowhere to keep
    // either - see openWish(). A held day wins the box; the API refuses a day
    // that is both, because they are opposite claims on one place.
    var inner, title;
    if (d.hold) {
      inner = '<span class="rv-b rv-hold"><i class="fa-solid fa-lock" aria-hidden="true"></i>' +
        '<span class="rv-bt">held</span></span>';
      title = DAYS_LONG[wd] + ' is being held for ' + name +
        (d.hold.reason ? ' - ' + d.hold.reason : '') + ', since ' + d.hold.held_from_display +
        '. Nobody else can be offered it.';
    } else if (d.wish || d.ask) {
      var q = d.ask;
      var dates = q && q.dates && q.dates.length > 1 ? q.dates.length + ' dates from ' + fmtDate(q.from)
                : q && q.dates && q.dates.length ? fmtDate(q.dates[0]) : '';
      inner = '<span class="rv-b rv-want"><i class="fa-solid fa-hourglass-half" aria-hidden="true"></i>' +
        '<span class="rv-bt">waiting</span></span>';
      title = name + ' is waiting for a ' + DAYS_LONG[wd] +
        (d.wish && d.wish.note ? ' - ' + d.wish.note : '') +
        (q && q.source !== 'internal' ? ' (asked Kidsoft' + (dates ? ' for ' + dates : '') + ')' : '');
    } else if (d.other.length) {
      // In another room that day. Not a control that says anything new, so the
      // box keeps the "record a wish" click like any other empty day.
      var o = d.other[0].room;
      inner = '<span class="rv-b rv-else">' + roomIcon(o.name) + '<span class="rv-bt">' +
        esc(o.name) + '</span></span>';
      title = name + ' is in ' + o.name + ' on ' + DAYS_LONG[wd] + 's. ' +
        'Click to record a wish or a hold for this room.';
    } else {
      inner = '<span class="rv-b rv-off"><i class="fa-solid fa-plus" aria-hidden="true"></i></span>';
      title = 'Record that ' + name + ' is waiting for a ' + DAYS_LONG[wd] +
        ', or that a ' + DAYS_LONG[wd] + ' is being held for them';
    }
    return '<td class="rv-d rv-pick" data-key="' + esc(row.key) + '" data-wd="' + wd +
      '" tabindex="0" role="button" title="' + esc(title) + '">' + inner + '</td>';
  }

  /** One weekday's box on a row for a family who is not on the roll. */
  var RV_MARK = {
    offer:     ['rv-offer', 'fa-bookmark',        'offered',  ' is offered a %, waiting for the family'],
    paperwork: ['rv-offer', 'fa-file-signature',  'form in',  ' has accepted a % and the paperwork is with the centre'],
    reenrol:   ['rv-offer', 'fa-bookmark',        'kept',     '\'s % is held until they answer re-enrolment'],
    hold:      ['rv-hold',  'fa-lock',            'held',     '\'s % is being held. Nobody else can be offered it'],
    ask:       ['rv-want',  'fa-hourglass-half',  'asked',    ' has asked for a %'],
    prog:      ['rv-prog',  'fa-list-check',      'enrolling', ' is part-way through enrolling for a %. Nothing is held yet']
  };
  function rvOtherDay(row, wd) {
    if (row.dayset.indexOf(wd) === -1) return '<td class="rv-d"></td>';
    var m = RV_MARK[row.mark];
    return '<td class="rv-d"><span class="rv-b ' + m[0] + '" title="' +
      esc(row.name + m[3].replace('%', DAYS_LONG[wd])) + '">' +
      '<i class="fa-solid ' + m[1] + '" aria-hidden="true"></i><span class="rv-bt">' + m[2] +
      '</span></span></td>';
  }

  /** The name cell: who, how old, and what is happening to them. */
  function rvNameCell(row) {
    var who;
    if (row.type === 'roll') {
      var p = row.p;
      who = '<span class="place-chip st-' + esc(p.status) + bandClass(p) + '" data-key="' +
        esc(row.key) + '" tabindex="0" role="button" title="' +
        esc(chipTitle(row.flags, p.status, p)) + '">' + esc(row.name) + flagIcons(row.flags) +
        '</span>' +
        (ageOn(p.child_dob) !== null
          ? '<span class="rv-age" title="' + esc('Born ' + fmtDate(p.child_dob) + '; ' +
              ageLabel(ageOn(p.child_dob)) + ' on ' + data.age_bands.on) + '">' +
            esc(ageLabel(ageOn(p.child_dob))) + '</span>' : '');
    } else if (row.type === 'offer') {
      who = '<button type="button" class="cp-name cp-open" data-wl="' + row.o.waitlist_id +
        '" title="Application and contact history">' + esc(row.name) + '</button>';
    } else if (row.type === 'progress') {
      who = '<button type="button" class="cp-name work-open" data-id="' + row.w.id +
        '" title="Open their enrolment">' + esc(row.name) + '</button>';
    } else {
      who = childLink(row.ks, row.name);
    }
    return '<td class="rv-name"><div class="rv-who">' + who + '</div>' +
      (row.lines.length ? '<div class="rv-what">' + row.lines.join('</div><div class="rv-what">') + '</div>' : '') +
      '</td>';
  }

  /**
   * One weekday's column head: the grid cell's own figures, and a bar of one box
   * per place - green placed, grey kept for a family by an offer or a
   * re-enrolment, amber held, empty free, red over. The place-day is the unit of
   * this app, so it is drawn as one.
   */
  function rvDayHead(r, wd) {
    var c = r.cells[wd];
    var kept = (c.promised || 0) + (c.held || 0), held = c.reserved || 0;
    var bar = '', fig, state, tip = [];
    if (c.capacity === null) {
      fig = '<span class="rv-dfig">' + c.placed + '</span>';
      state = '<span class="text-muted">no capacity</span>';
      tip.push(c.placed + ' placed, no capacity recorded');
    } else {
      fig = '<span class="rv-dfig">' + c.placed + '<small>/' + c.capacity + '</small></span>';
      var n = Math.max(c.capacity, c.placed + kept + held), segs = [];
      for (var i = 0; i < n; i++) {
        var s = i < c.placed ? 'b-on' : i < c.placed + kept ? 'b-kept'
              : i < c.placed + kept + held ? 'b-held' : '';
        if (i >= c.capacity) s = 'b-over';
        segs.push('<i' + (s ? ' class="' + s + '"' : '') + '></i>');
      }
      bar = '<div class="rv-bar' + (n > 30 ? ' rv-bar-dense' : '') + '" aria-hidden="true">' +
        segs.join('') + '</div>';
      state = c.over ? '<span class="rv-st-over">' + (c.placed - c.capacity) + ' over</span>'
            : c.free ? '<span class="rv-st-free">' + c.free + ' free</span>'
            : '<span class="rv-st-full">Full</span>';
      tip.push(c.placed + ' of ' + c.capacity + ' placed');
      if (c.free) tip.push(c.free + ' free');
    }
    if (c.promised) tip.push(c.promised + ' held by an offer');
    if (c.held) tip.push(c.held + ' held for re-enrolment');
    if (held) tip.push(held + ' held for a child not booked on the day');
    // In the board's own words for each: an offer holds a place, a re-enrolment
    // or a held day holds one. "Kept" for both read as a third kind of thing.
    var tags = '';
    if (c.promised) tags += '<span class="rv-kept">' + c.promised + ' offered</span>';
    if ((c.held || 0) + held) tags += '<span class="rv-kept">' + ((c.held || 0) + held) + ' held</span>';
    if (c.coming_free) {
      tags += '<span class="rv-cf">+' + c.coming_free + (c.free_from ? ' ' + fmtMonth(c.free_from.slice(0, 7)) : '') + '</span>';
      tip.push(c.coming_free + ' coming free' + (c.free_from ? ' from ' + fmtDate(c.free_from) : ''));
    }
    if (c.in_progress_n) {
      tags += '<span class="rv-ip"><i class="cell-prog" aria-hidden="true"></i>' + c.in_progress_n + '</span>';
      tip.push(c.in_progress_n + ' famil' + (c.in_progress_n === 1 ? 'y' : 'ies') + ' part-way through enrolling');
    }
    if (c.eligible_count && c.free > 0) tip.push(c.eligible_count + ' on the waitlist could take this day');
    return '<th class="rv-dh" data-wd="' + wd + '" tabindex="0" role="button" title="' +
      esc(DAYS_LONG[wd] + 's: ' + tip.join(', ') + '.\nOpen the day for the shortlist and actions.') + '">' +
      '<div class="rv-dh-top"><span class="rv-dn">' + DAYS[wd] + '</span>' + fig + '</div>' + bar +
      '<div class="rv-dh-sub">' + state + tags + '</div></th>';
  }

  function rvSection(label, rows, kind) {
    if (!rows.length) return '';
    return '<tbody class="rv-group" data-kind="' + kind + '"><tr class="rv-sec"><th colspan="7">' +
      label + ' <span class="rv-n">' + rows.length + '</span></th></tr>' +
      rows.map(function (row) {
        return '<tr class="rv-row' + (row.faded ? ' rv-faded' : '') + '" data-tags="' +
          esc(row.tags.join(' ')) + '">' + rvNameCell(row) +
          WDS.map(function (wd) { return row.type === 'roll' ? rvRollDay(row, wd) : rvOtherDay(row, wd); }).join('') +
          '<td class="rv-n">' + row.count + '</td></tr>';
      }).join('') + '</tbody>';
  }

  function renderRoom() {
    if (!openRoom) return;
    var r = room(openRoom);
    if (!r) { roomModal.hide(); return; }
    var s = roomSheet(r);
    var all = s.roll.concat(s.offered, s.kept, s.asked, s.progress);

    // --- Heading: the room, and what it is -------------------------------------
    var centre = '';
    (data.centres || []).forEach(function (c) { if (c.id === CENTRE) centre = c.name; });
    var bits = [centre, String(data.year)];
    if (r.min_age_months !== null || r.max_age_months !== null) {
      bits.push('Ages ' + (r.min_age_months === null ? '0' : r.min_age_months) + '-' +
        (r.max_age_months === null ? '+' : r.max_age_months) + ' months');
    }
    if (r.capacity_max !== null && r.capacity_max !== undefined) {
      bits.push((r.capacity_min !== null && r.capacity_min !== r.capacity_max
        ? r.capacity_min + '-' + r.capacity_max : r.capacity_max) + ' places a day');
    }
    $('#rv-title').html(roomIcon(r.name) + esc(r.name));
    $('#rv-subtitle').html(bits.filter(Boolean).map(function (b) {
      return '<span>' + esc(b) + '</span>';
    }).join('') + (r.linked ? '' : '<span class="text-danger">Not linked to Kidsoft</span>'));
    var i = data.rooms.indexOf(r), n = data.rooms.length;
    var prev = data.rooms[(i - 1 + n) % n], next = data.rooms[(i + 1) % n];
    $('#room-modal .rv-step[data-step="-1"]').attr('title', 'Previous room: ' + prev.name);
    $('#room-modal .rv-step[data-step="1"]').attr('title', 'Next room: ' + next.name);
    $('#room-modal .rv-steps').toggleClass('d-none', n < 2);

    // --- The week in figures, and the legend that filters ------------------------
    var placed = 0, cap = 0, unknown = false, free = 0, coming = 0, firstFree = null;
    WDS.forEach(function (wd) {
      var c = r.cells[wd];
      placed += c.placed;
      free += c.free || 0;
      coming += c.coming_free || 0;
      if (c.coming_free && c.free_from && (!firstFree || c.free_from < firstFree)) firstFree = c.free_from;
      if (c.capacity === null) unknown = true; else cap += c.capacity;
    });
    var figs = '<div class="rv-figs">' +
      '<span class="rv-fig"><b>' + s.roll.length + '</b> ' + (s.roll.length === 1 ? 'child' : 'children') + '</span>' +
      '<span class="rv-fig"><b>' + placed + '</b>' + (unknown ? '' : '<small>/' + cap + '</small>') +
        ' place-days filled</span>' +
      (unknown ? '' : '<span class="rv-fig ' + (free ? 'rv-fig-free' : 'rv-fig-full') + '"><b>' + free +
        '</b> free</span>') +
      (coming ? '<span class="rv-fig rv-fig-cf" title="' + esc('The first from ' + fmtDate(firstFree)) +
        '"><b>+' + coming + '</b> coming free</span>' : '') +
      '</div>';

    var counts = {};
    all.forEach(function (row) { row.tags.forEach(function (t) { counts[t] = (counts[t] || 0) + 1; }); });
    if (rvFilter && !counts[rvFilter]) rvFilter = null;
    var chips = Object.keys(RV_KIND).filter(function (k) { return counts[k]; }).map(function (k) {
      return '<button type="button" class="rv-chip" data-kind="' + k + '" aria-pressed="' +
        (rvFilter === k) + '"><span class="rv-sw ' + RV_KIND[k].pill + '" aria-hidden="true"></span>' +
        esc(RV_KIND[k].label) + ' <b>' + counts[k] + '</b></button>';
    }).join('');
    // A FILTER WITH ONE THING IN IT FILTERS NOTHING: a room where everybody is
    // simply enrolled gets the figures and no chips.
    var mixed = Object.keys(counts).some(function (k) { return counts[k] !== all.length; });
    $('#rv-top').html(figs + (mixed
      ? '<div class="rv-chips" role="group" aria-label="Show only">' +
        '<button type="button" class="rv-chip rv-chip-all" data-kind="" aria-pressed="' + !rvFilter +
        '">Everyone <b>' + all.length + '</b></button>' + chips + '</div>'
      : ''));

    // --- The sheet ---------------------------------------------------------------
    var $body = $('#rv-body'), keep = $body.data('room') === r.id ? $body.scrollTop() : 0;
    if (!all.length) {
      $body.html('<p class="text-muted p-3 mb-0">Nobody is on the ' + data.year +
        ' board for this room.</p>').data('room', r.id);
      $('#rv-foot-key').empty();
      return;
    }

    // THE STAFFING BANDS, COUNTED PER DAY, when the room holds more than one -
    // under the day heads and pinned with them, because the ratio is set per
    // day and this is the figure the green tones exist to let somebody count.
    var ps = r.placements || [];
    var bands = bandsPresent(ps);
    var bandRows = bands.map(function (b, i) {
      return '<tr class="rv-band' + (i === bands.length - 1 ? ' rv-band-last' : '') + '">' +
        '<th class="rv-name">' + bandKey(b) + '</th>' +
        WDS.map(function (wd) {
          var k = ps.filter(function (p) {
            return p.age_band === b && p.days.indexOf(wd) !== -1 &&
              p.status !== 'waitlist' && p.status !== 'no_position';
          }).length;
          return '<td class="rv-bandn">' + (k || '<span class="text-muted">-</span>') + '</td>';
        }).join('') + '<td></td></tr>';
    }).join('');

    var head = '<thead><tr class="rv-hd"><th class="rv-name rv-hname">Child</th>' +
      WDS.map(function (wd) { return rvDayHead(r, wd); }).join('') +
      '<th class="rv-n rv-hn" title="Days each child attends; the week\'s place-days filled underneath">' +
      'Days<div>' + placed + '</div></th></tr>' + bandRows + '</thead>';

    var table = '<table class="rv-table">' +
      '<colgroup><col class="rv-c-name">' + WDS.map(function () { return '<col class="rv-c-day">'; }).join('') +
      '<col class="rv-c-n"></colgroup>' + head +
      rvSection('Enrolled', s.roll, 'roll') +
      rvSection('Offered, not enrolled yet', s.offered, 'offered') +
      rvSection('Places kept', s.kept, 'kept') +
      rvSection('Asked for a day', s.asked, 'asked') +
      rvSection('In progress', s.progress, 'progress') +
      '</table>';
    $body.html(table).data('room', r.id);
    rvApplyFilter();
    $body.scrollTop(keep);

    // THE DAY BLOCKS' KEY, only for the marks this room actually has - the chips
    // above are the statuses, this is what a coloured box in a day means.
    var used = {};
    $body.find('.rv-b').each(function () {
      ['rv-on', 'rv-from', 'rv-end', 'rv-hold', 'rv-want', 'rv-offer', 'rv-prog', 'rv-else'].forEach(function (k) {
        if ($(this).hasClass(k)) used[k] = true;
      }, this);
    });
    var KEY = [['rv-on', 'fa-check', 'attends'], ['rv-from', 'fa-right-to-bracket', 'starts later'],
               ['rv-end', 'fa-right-from-bracket', 'coming free'], ['rv-hold', 'fa-lock', 'held'],
               ['rv-want', 'fa-hourglass-half', 'waiting for the day'],
               ['rv-offer', 'fa-bookmark', 'kept by an offer or re-enrolment'],
               ['rv-prog', 'fa-list-check', 'being enrolled, nothing held'],
               ['rv-else', null, 'in another room']];
    $('#rv-foot-key').html(KEY.filter(function (k) { return used[k[0]]; }).map(function (k) {
      return '<span class="rv-key"><span class="rv-b ' + k[0] + '" aria-hidden="true">' +
        (k[1] ? '<i class="fa-solid ' + k[1] + '"></i>' : '') + '</span>' + esc(k[2]) + '</span>';
    }).join('') + '<span class="rv-key rv-key-hint">Click an empty day to record that a family is ' +
      'waiting for it, or that it is held for them.</span>');
  }

  /** Show only the rows carrying the pressed chip's tag, and their section heads. */
  function rvApplyFilter() {
    var $body = $('#rv-body');
    $body.find('tr.rv-row').each(function () {
      var tags = ($(this).attr('data-tags') || '').split(' ');
      $(this).prop('hidden', !!rvFilter && tags.indexOf(rvFilter) === -1);
    });
    $body.find('tbody.rv-group').each(function () {
      $(this).prop('hidden', !$(this).find('tr.rv-row:not([hidden])').length);
    });
    $('#rv-top .rv-chip').each(function () {
      $(this).attr('aria-pressed', String(($(this).attr('data-kind') || null) === rvFilter));
    });
  }

  /** Open a room's sheet. */
  function openRoomSheet(id) {
    if (!room(id)) return;
    if (openRoom !== id) { rvFilter = null; $('#rv-body').removeData('room'); }
    openRoom = id;
    rvBack = null;
    renderRoom();
    roomModal.show();
  }

  // --- Whole-organisation overview -------------------------------------------

  function fillClass(pct) {
    if (pct === null) return '';
    return pct >= 98 ? 'text-success' : (pct >= 90 ? 'text-warning' : 'text-danger');
  }

  /**
   * One centre, one weekday - the same heat block the room grid uses.
   *
   * This was a small pill reading "full" / "3" / "2 over" while the grid a
   * click away used colour blocks, so the screen you choose a centre FROM and
   * the screen you land on described fullness two different ways.
   *
   * A centre pools every room, so the amber band is wider: two free places in
   * one room is a nudge, two across a whole centre is essentially full.
   */
  function overviewCell(c, wd) {
    var t = c.totals[wd];
    var h = heat(t.occupied, t.capacity, t.vacancies, Math.max(2, Math.round(t.capacity * 0.05)), (t.held || 0) + (t.reserved || 0));
    return '<td class="cell ' + h.cls + '" title="' +
      esc(c.name + ', ' + DAYS[wd] + 's: ' +
          (t.capacity ? t.occupied + ' of ' + t.capacity + ' places, ' + t.vacancies + ' free' +
                        (t.held ? ', ' + t.held + ' held for children who have not answered re-enrolment' : '') +
                        (t.reserved ? ', ' + t.reserved + ' held for children who are enrolled and not booked on the day' : '')
                      : 'no capacity recorded')) + '">' +
      h.big + '<span class="cell-sub">' + h.sub + '</span></td>';
  }

  function centreRow(c) {
    // No progress bar. The row already carried a percentage, a bar, a free
    // count and five pills - four encodings of one fact competing for the eye.
    // "SET 2026 CAPACITY" IS A PROMPT TO FIX SOMETHING, and for a centre that
    // has not opened there is nothing to fix - Kidsoft holds its capacities
    // perfectly well, the centre simply does not trade that year. It read as a
    // job on the one row that has none. A dash, and the reason stays in the
    // title on the mark beside the name.
    var pct = c.state === 'unopened'
      ? '<span class="text-muted">--</span>'
      : (c.has_capacity
          ? '<span class="ov-pct ' + fillClass(c.pct) + '">' + pctText(c.pct) + '</span>'
          : '<a href="/rooms?centre=' + c.id + '" class="small ov-cap-link">Set ' + year + ' capacity</a>');

    var warn = c.unknown_capacity
      ? ' <i class="fa-solid fa-triangle-exclamation text-warning" title="' +
        c.unknown_capacity + ' room(s) with no ' + year + ' capacity"></i>' : '';
    // Withheld, not captioned: the reason sits in a title, because the team knows
    // their own centres and a sentence explaining one back to them is noise.
    // `c.counts`, not `c.trading` (21 Sep 2026). A centre that OPENS later this
    // year is counted in the totals with nobody booked yet, and one that has not
    // opened at all is left out and says which day it opens rather than
    // "not trading" - two facts that used to arrive as the same one. The reason
    // still sits in a title: the team knows their own centres.
    if (!c.counts) {
      warn += ' <i class="fa-solid fa-circle-minus text-muted" title="' +
        esc(c.state === 'unreachable'
              ? 'Kidsoft did not answer for this centre'
              : (c.state === 'unopened'
                   ? 'Opens ' + fmtDate(c.opens_on) + ' - left out of the totals'
                   : 'No children booked in ' + year + ' - left out of the group totals')) +
        '"></i>';
    }

    return '<tr class="ov-centre-row" data-centre="' + c.id + '">' +
      '<th class="ov-name"><a href="' + placementHref(c.id) +
        '" class="ov-centre-link">' + esc(c.name) + '</a>' + warn +
        '<div class="small text-muted fw-normal">' + c.rooms + ' room' +
        (c.rooms === 1 ? '' : 's') + '</div></th>' +
      '<td class="text-center">' + pct + '</td>' +
      Object.keys(DAYS).map(function (wd) { return overviewCell(c, wd); }).join('') +
      // Free OUT OF WHAT THERE IS, the same fraction the centre grid's own Free
      // per week column carries - the two screens share the column and must not
      // read differently in it. c.capacity is already the week's total.
      freeCell(c.has_capacity ? c.free : null, c.has_capacity ? c.capacity : null,
               'fw-semibold ' + (c.free ? 'text-danger' : 'text-success')) + '</tr>';
  }

  function overviewTotalsRow() {
    var total = 0, capTotal = 0;
    return '<tr class="grid-totals"><th>Whole group</th><td></td>' +
      Object.keys(DAYS).map(function (wd) {
        var t = overview.totals[wd];
        total += t.vacancies;
        capTotal += t.capacity;
        return '<td class="text-center">' +
          '<div class="fw-semibold">' + t.occupied + '<span class="text-muted">/' + t.capacity +
          '</span></div><div class="small ' + (t.vacancies ? 'text-danger' : 'text-success') + '">' +
          (t.vacancies ? t.vacancies + ' free' : 'full') + '</div></td>';
      }).join('') +
      freeCell(total, capTotal, 'fw-semibold') + '</tr>';
  }

  function renderOverview() {
    // ONLY TRADING CENTRES COUNT TOWARDS THE HEADLINES. Warnervale carries 385
    // recorded place-days a week with no bookings and no children: counted in, it
    // was 77% of "places free" and pulled group fill from 96% to 84%, so the two
    // biggest numbers on the screen were mostly a centre that is shut.
    var occ = 0, cap = 0, free = 0, needCap = 0, closed = 0, unknown = 0;
    overview.centres.forEach(function (c) {
      if (c.state === 'unreachable') { unknown++; return; }
      if (!c.counts) { closed++; return; }
      occ += c.occupied; cap += c.capacity; free += c.free;
      if (!c.has_capacity) needCap++;
    });
    var pct = window.fillPct(occ, cap) || 0;
    $('#board-summary').html(
      tile('Places free', free, overview.from_chosen
             ? 'across the group from ' + fmtDate(overview.from)
             : 'across the group in ' + year, free ? 'text-danger' : 'text-success') +
      tile('Filled', cap ? pctText(pct) : '-', cap ? occ + ' of ' + cap + ' places' : 'no capacities set',
           fillClass(cap ? pct : null)) +
      tile('Placed', occ, 'child-days in a full week') +
      tile('Centres', overview.centres.length - closed - unknown,
           (closed || unknown)
             ? 'counted; ' + [closed ? closed + ' not open yet or empty' : null,
                              unknown ? unknown + ' Kidsoft would not answer for' : null]
                             .filter(Boolean).join(', ')
             : (needCap ? needCap + ' need ' + year + ' capacity set' : 'all have capacity'),
           (needCap || unknown) ? 'text-warning' : '')
    );

    // THE CENTRE BAR'S ORDER, top to bottom, with a centre not open at From last
    // (Broc, 27 Oct 2026) - decided by board_overview(), not re-sorted here. It
    // was emptiest-first, so the rows reshuffled from year to year and never sat
    // where the bar a few pixels above put them.
    var rows = overview.centres;
    // Column order matches the room grid: name, then the five weekdays, then the
    // week's total. "Filled" sits next to the name because it is the centre's
    // whole week, not something you read per day.
    // ov-filled is not decoration: it is the width that keeps the weekday blocks
    // the same size as the centre grid's. See .ov-table th.ov-name in app.css.
    var head = '<th class="ov-name">Centre</th><th class="text-center ov-filled">Filled</th>' +
      Object.keys(DAYS).map(function (wd) {
        return '<th class="text-center">' + DAYS[wd] + 's</th>';
      }).join('') + '<th class="text-center grid-free">Free<div class="small fw-normal text-muted">per week</div></th>';

    $('#overview').html(
      '<div class="card"><div class="table-scroll">' +
      '<table class="table align-middle mb-0 ov-table grid-table table-sticky"><thead><tr>' + head +
      '</tr></thead><tbody>' + rows.map(centreRow).join('') + overviewTotalsRow() +
      '</tbody></table></div></div>' +
      '<p class="text-muted small mt-2 mb-0">' +
      'Each block is one centre on one weekday: the number is places still to fill, ' +
      'and the figures under it are placed out of capacity. ' +
      'These are the PLANNED places; open a centre to compare them against what ' +
      'Kidsoft has actually booked.</p>');
  }

  // --- Loading / view switch -------------------------------------------------

  function loadOverview() {
    $('#overview').html(spinner('Reading every centre...'));
    $.getJSON('/api/board', FROM ? { action: 'overview', year: year, from: FROM }
                                  : { action: 'overview', year: year })
      .done(function (res) {
        if (!res.ok) {
          $('#overview').html('<div class="text-center text-danger py-5">' + esc(res.error) + '</div>');
          return;
        }
        overview = res;
        yn.set(year, res.years);
        renderOverview();
      })
      .fail(function () {
        $('#overview').html('<div class="text-center text-danger py-5">Could not load the overview.</div>');
      });
  }

  function loadGrid(refresh) {
    // A refresh re-reads twelve months from Kidsoft and takes around half a
    // minute, so say so rather than leaving a spinner that looks stuck.
    $('#grid').html(spinner(refresh
      ? 'Re-reading every month of ' + year + ' from Kidsoft. This takes around half a minute...'
      : 'Reading ' + year + '...'));
    var q = { action: 'year', centre_id: centreId(), year: year };
    if (FROM) q.from = FROM;
    if (refresh) q.refresh = 1;

    $.getJSON('/api/cells', q)
      .done(function (res) {
        if (!res.ok) {
          // Kidsoft being unreachable must be visible, not a blank grid.
          $('#grid').html('<div class="alert alert-danger">' + esc(res.error) + '</div>');
          return;
        }
        // NOT OPEN YET: there is no year here to draw, and the endpoint has
        // already returned without deriving one. The tiles, the banners and the
        // legend all describe a grid, so they come off with it - a row of
        // zeroes over an empty page is the "0 of 0" reading this change exists
        // to delete. The stepper stays live, pointed at the year that is real.
        if (res.not_open) {
            data = null;
            yn.set(year, res.years);
            $('#board-summary, #year-notices, #grid-legend').empty();
            // AND THE REFRESH BUTTON GOES WITH THEM. There is nothing to
            // re-read - the endpoint returns before it reaches Kidsoft - so it
            // is a control that cannot change anything, which is the same
            // argument that keeps a one-tab centre bar off the screen.
            $('#refresh').addClass('d-none');
            // The way out lands ON the opening day, the first date this centre
            // has a board, whether the year or the From date ruled it out.
            $('#grid').html(window.notOpenState(res.not_open, {
              what: 'placement board',
              href: function (y, n) { return '/placement?centre=' + CENTRE + '&from=' + n.opens_on; }
            }));
            syncUrl();
            return;
        }
        data = res;
        year = res.year;
        yn.set(year, res.years);
        renderGrid();
        // A ?room=&wd= deep link (search results use one) opens that cell as
        // soon as there is a grid to open it on. Consumed once: after this the
        // grid and the panel are the only state, so a later refresh does not
        // spring the panel open again under someone.
        if (deepCell) {
          var d = deepCell; deepCell = null;
          if (room(d.roomId)) { openCell = d; cellPanel.show(); }
        }
        if (deepSheet) {
          var ds = deepSheet; deepSheet = 0;
          openRoomSheet(ds);
        }
        syncUrl();
        // The panel and the room sheet are redrawn from the fresh data, so
        // saving something updates whatever you saved it from. Both, because a
        // cell opened from a room keeps the room to go back to.
        if (openCell) renderCell();
        if (openRoom) renderRoom();
      })
      .fail(function () {
        $('#grid').html('<div class="text-center text-danger py-5">Could not load ' + year + '.</div>');
      });
  }

  // Reflect the current view in the URL so a refresh or a shared link lands in
  // the same place (replaceState - no history entry per click).
  // The centre is always named, even in the overview (centre=0), so the URL is
  // literal rather than leaning on what the session happens to remember.
  function syncUrl() {
    window.history.replaceState(null, '', placementHref(CENTRE));
  }

  function load(refresh) {
    if (CENTRE === 0) { syncUrl(); loadOverview(); } else loadGrid(refresh);
  }

  // --- Placement editor ------------------------------------------------------

  function roomOptions(selected) {
    return data.rooms.map(function (r) {
      return '<option value="' + r.id + '"' + (r.id === selected ? ' selected' : '') + '>' +
             esc(r.name) + '</option>';
    }).join('');
  }

  /**
   * The one identity a child has on this board: 'k' + Kidsoft ChildID, or 'p' +
   * our row for one pencilled in ahead of Kidsoft. Prefixed so the two can never
   * collide, and the same key _incl/cells.php builds server-side.
   *
   * THE ROW ID IS NOT AN IDENTITY. The board is derived from Kidsoft, so a child
   * only has a `placements` row once somebody annotates them - today that is
   * NONE of them. Keying a chip on `id` therefore wrote data-id="null" on every
   * child, and a lookup by `p.id == null` matched every unannotated child in
   * every room and returned the last one walked: clicking any name opened the
   * editor for a different child, titled with their name.
   */
  function placeKey(ksId, rowId) {
    return ksId ? 'k' + ksId : 'p' + rowId;
  }

  /** The full placement behind a chip, found by identity. */
  function findPlacementByKey(key) {
    var found = null;
    if (!key) return null;
    data.rooms.forEach(function (r) {
      r.placements.forEach(function (p) {
        if (placeKey(p.kidsoft_child_id, p.id) === key) found = p;
      });
    });
    return found;
  }

  /** By our own row id. Only the delete path has one, and it is never null. */
  function findPlacement(id) {
    var found = null;
    if (!id) return null;
    data.rooms.forEach(function (r) {
      r.placements.forEach(function (p) { if (p.id && p.id == id) found = p; });
    });
    return found;
  }

  // --- Kidsoft child picker --------------------------------------------------
  // A child is PICKED, not typed. Kidsoft owns the name and DOB; typing them is
  // what produced every drift row this app has had to reconcile.

  var searchTimer = null;
  var searchSeq = 0;        // guards against a slow reply overwriting a newer one

  // The results menu OVERLAYS the form rather than sitting in its flow, so
  // opening it must not change the modal's height. Everything below just keeps
  // the input and the menu agreeing about whether it is open.
  function menuOpen() { return $('#child-results').hasClass('show'); }

  function openMenu() {
    if (!$('#child-results').children().length) return;
    $('#child-results').addClass('show');
    $('#child-search').attr('aria-expanded', 'true');
  }

  function closeMenu() {
    $('#child-results').removeClass('show');
    $('#child-search').attr('aria-expanded', 'false').removeAttr('aria-activedescendant');
    $('#child-results .active').removeClass('active').attr('aria-selected', 'false');
  }

  function showChosen(id, name, meta) {
    $('#place-form [name=kidsoft_child_id]').val(id || '');
    if (id) {
      $('#chosen-name').text(name);
      $('#chosen-meta').text(meta || '');
      $('#child-chosen').removeClass('d-none');
      $('#child-picker, #manual-name, #toggle-manual').addClass('d-none');
      closeMenu();
    } else {
      $('#child-chosen').addClass('d-none');
      $('#child-picker, #toggle-manual').removeClass('d-none');
      closeMenu();
      $('#child-results').empty();
      $('#child-search').val('');
    }
  }

  /** Provisional mode: the child is not in Kidsoft, so a name must be typed. */
  function showManual(on) {
    $('#manual-name').toggleClass('d-none', !on);
    $('#child-picker').toggleClass('d-none', on);
    $('#toggle-manual').text(on ? 'Find them in Kidsoft instead'
                                : 'This child is not in Kidsoft yet');
  }

  /**
   * @param {boolean} [reveal] open the menu once the results land. False when
   *   pre-loading on modal open: the list is fetched so the first keystroke is
   *   instant, but a menu that opened by itself would cover the form underneath.
   */
  function searchChildren(q, reveal) {
    var show = reveal !== false;
    var seq = ++searchSeq;
    $.getJSON('/api/board', { action: 'children', centre_id: centreId(), q: q })
      .done(function (res) {
        // A reply for an older keystroke must not replace a newer one: the
        // requests are debounced, not serialised, so they can land out of order.
        if (seq !== searchSeq) return;
        if (!res.ok) {
          $('#child-results').html('<div class="px-3 py-2 text-danger small">' +
            esc(res.error) + '</div>');
          if (show) openMenu();
          return;
        }
        if (!res.children.length) {
          $('#child-results').html('<div class="px-3 py-2 text-muted small">' +
            'No active child at this centre matches. If they are new, use ' +
            '"not in Kidsoft yet".</div>');
          if (show) openMenu();
          return;
        }
        $('#child-results').html(res.children.map(function (c, i) {
          var age = c.age_months === null ? '' :
            Math.floor(c.age_months / 12) + 'y ' + (c.age_months % 12) + 'm';
          // Already on a board here: offering them again would double-count.
          var on = c.placed_in.length
            ? '<div class="small text-warning">Already on ' + esc(c.placed_in.join(', ')) + '</div>' : '';
          return '<button type="button" class="dropdown-item pick-child" role="option" ' +
            'id="child-opt-' + i + '" aria-selected="false" ' +
            'data-id="' + esc(c.id) + '" data-name="' + esc(c.name) + '" ' +
            'data-meta="' + esc((c.dob ? fmtDate(c.dob) : 'no DOB') + (age ? ' - ' + age : '')) + '">' +
            '<div class="fw-semibold">' + esc(c.name) +
            (c.nickname ? ' <span class="text-muted small">(' + esc(c.nickname) + ')</span>' : '') + '</div>' +
            '<div class="small text-muted">' + (c.dob ? fmtDate(c.dob) : 'no DOB') +
            (age ? ' &middot; ' + age : '') + '</div>' + on + '</button>';
        }).join(''));
        if (show) openMenu();
      });
  }

  $('#child-search').on('input', function () {
    var q = $(this).val();
    clearTimeout(searchTimer);
    searchTimer = setTimeout(function () { searchChildren(q); }, 200);
  });

  // Clicking the field reopens what was already fetched, so a stray click
  // outside does not cost a round trip to get the list back. Deliberately NOT
  // bound to `focus`: the modal focuses this input on open, and a menu that
  // opened itself there would cover the days and room straight away. Typing and
  // ArrowDown open it too, so no interaction actually needs the mouse.
  $('#child-search').on('click', function () { openMenu(); });

  // Keyboard: this is a combobox, and the staff using it are typing names, so
  // reaching the right child must not require the mouse.
  $('#child-search').on('keydown', function (e) {
    var $items = $('#child-results .pick-child');
    if (e.key === 'Escape') { if (menuOpen()) { e.stopPropagation(); closeMenu(); } return; }
    if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
      e.preventDefault();
      if (!menuOpen()) { openMenu(); }
      if (!$items.length) return;
      var at = $items.index($items.filter('.active'));
      at = e.key === 'ArrowDown' ? (at + 1) % $items.length
                                 : (at <= 0 ? $items.length - 1 : at - 1);
      $items.removeClass('active').attr('aria-selected', 'false');
      var $sel = $items.eq(at).addClass('active').attr('aria-selected', 'true');
      $('#child-search').attr('aria-activedescendant', $sel.attr('id'));
      // Keep the highlighted row in view inside the scrolling menu.
      $sel[0].scrollIntoView({ block: 'nearest' });
      return;
    }
    if (e.key === 'Enter') {
      var $act = $items.filter('.active');
      // Only swallow Enter when it is actually choosing someone - otherwise it
      // must still submit the form.
      if (menuOpen() && $act.length) { e.preventDefault(); $act.trigger('click'); }
    }
  });

  $('#child-results').on('click', '.pick-child', function () {
    var $b = $(this);
    showChosen($b.data('id'), $b.data('name'), $b.data('meta'));
  });

  // Outside click closes it. Scoped to the modal so it costs nothing elsewhere.
  $('#place-modal').on('mousedown', function (e) {
    if (menuOpen() && !$(e.target).closest('#child-combo').length) closeMenu();
  });

  // Bootstrap moves focus itself while the modal animates, so the picker can
  // only be focused once that has finished.
  $('#place-modal')
    .on('shown.bs.modal', function () {
      if (!$('#child-picker').hasClass('d-none')) $('#child-search').trigger('focus');
    })
    .on('hidden.bs.modal', closeMenu);

  // "Change" empties the picker, so re-load it and hand focus back - otherwise
  // the one thing you pressed Change to do needs a keystroke before it can start.
  $('#chosen-clear').on('click', function () {
    showChosen(null);
    searchChildren('', false);
    $('#child-search').trigger('focus');
  });
  $('#toggle-manual').on('click', function () {
    showManual($('#manual-name').hasClass('d-none'));
  });

  function openPlacement(roomId, placement, presetDay) {
    var $f = $('#place-form');
    $f[0].reset();
    $f.find('[name=year]').val(year);
    $f.find('[name=room_id_select]').html(roomOptions(roomId));
    $f.find('[name=id]').val(placement ? placement.id : '');
    showChosen(null);
    showManual(false);

    if (placement) {
      var nm = ((placement.child_first_name || '') + ' ' + (placement.child_last_name || '')).trim();
      if (placement.kidsoft_child_id) {
        // Linked: Kidsoft owns this identity, so it is shown, not editable.
        showChosen(placement.kidsoft_child_id, nm,
          (placement.child_dob ? fmtDate(placement.child_dob) : 'no DOB') + ' - from Kidsoft');
        $('#chosen-clear').addClass('d-none');
      } else {
        // Provisional: typed, and still waiting to be linked.
        showManual(true);
        $('#child-picker').removeClass('d-none');
      }
      $f.find('[name=child_first_name]').val(placement.child_first_name);
      $f.find('[name=child_last_name]').val(placement.child_last_name);
      $f.find('[name=child_dob]').val(placement.child_dob || '');
      $f.find('[name=status]').val(placement.status);
      $f.find('[name=notes]').val(placement.notes || '');
      $('#f-sib').prop('checked', placement.is_sibling);
      $('#f-staff').prop('checked', placement.is_staff_child);
      $('#f-two').prop('checked', placement.needs_two_educators);
      $('#f-beh').prop('checked', placement.has_behaviour_plan);
      $('#f-isp').prop('checked', placement.has_individual_plan);
      placement.days.forEach(function (d) { $('#day-' + d).prop('checked', true); });
      $('.modal-title', '#place-modal').text(placement.child_first_name + ' ' + placement.child_last_name);
    } else {
      if (presetDay) $('#day-' + presetDay).prop('checked', true);
      $('.modal-title', '#place-modal').text('Add child');
      $('#chosen-clear').removeClass('d-none');
      // Pre-load the picker so choosing is instant, but leave the menu shut --
      // it overlays the form, and opening it unasked would hide the days and
      // room the user came here to set.
      searchChildren('', false);
    }
    /* KIDSOFT IS THE SINGLE SOURCE OF TRUTH FOR DAYS, ROOM AND STATUS, so a
       LINKED child is not offered them at all. The board is derived from Kidsoft
       bookings, so editing any of the three here could only disagree with it -
       and two of them did worse than disagree:

         - STATUS FEEDS OCCUPANCY. board.php drops a `waitlist` or `no_position`
           row from the day's roll, so marking a linked child either would have
           taken them out of the count while Kidsoft still had them booked, and
           the room would read one place emptier than it is.
         - THE ANNOTATION IS KEYED ON (child, room, year). Changing the room moved
           our row to a room the child is not in, where board_annotations can
           never match it again - so their flags and their note silently stopped
           applying to them.

       What is left for a linked child is what is genuinely OURS: the flags and
       the note. A PROVISIONAL child - one Kidsoft has never heard of - keeps all
       of it, because nothing else in the world knows about them. */
    var linked = !!(placement && placement.kidsoft_child_id);
    $('.pf-ours').toggleClass('d-none', linked);
    // The endpoint still needs a room to find the annotation and to check the
    // centre against, and the select it normally comes from is now hidden.
    if (linked) $f.find('[name=room_id_select]').val(placement.room_id || roomId);

    // Only OUR row can be removed, and a child Kidsoft supplies has none until
    // somebody annotates them. Offering the button there swallowed the click
    // (the delete handler returns on an empty id), which reads as the app
    // ignoring you - and the honest answer is that there is nothing to remove.
    //
    // It is hidden for a linked child even once they HAVE a row: deleting it
    // does not take them off the board - they come straight back from Kidsoft on
    // the next read, minus their flags and their note - so the button promised
    // something it could not do. Unticking the flags is the same act, said
    // honestly.
    $('#place-delete').toggleClass('d-none', linked || !(placement && placement.id));
    placeModal.show();
  }

  $('#place-form').on('submit', function (e) {
    e.preventDefault();
    var $f = $(this);
    $f.find('[name=room_id]').val($f.find('[name=room_id_select]').val());
    var $btn = $f.find('[type=submit]').prop('disabled', true);

    $.post('/api/board', $f.serialize() + '&action=save_placement')
      .done(function (res) {
        if (!res.ok) { toast(res.error, 'danger'); return; }
        placeModal.hide();
        // Over-capacity is allowed but must not pass silently.
        (res.warnings || []).forEach(function (w) { toast(w, 'warning'); });
        if (!(res.warnings || []).length) toast('Saved.');
        load();
      })
      .fail(function () { toast('Could not save.', 'danger'); })
      .always(function () { $btn.prop('disabled', false); });
  });

  $('#place-delete').on('click', function () {
    var id = $('#place-form [name=id]').val();
    var p = findPlacement(id);
    if (!id) return;
    confirmModal({
      title: 'Remove ' + (p ? p.child_first_name + ' ' + p.child_last_name : 'this child') +
             ' from the ' + year + ' board?',
      ok: 'Remove', danger: true
    }).done(function () {
      $.post('/api/board', { action: 'delete_placement', id: id })
        .done(function (res) {
          if (!res.ok) { toast(res.error, 'danger'); return; }
          placeModal.hide();
          toast('Removed from the board.');
          load();
        });
    });
  });

  // --- Work items ------------------------------------------------------------
  // --- The internal day waitlist ---------------------------------------------
  //
  // Opened from an empty box on the room sheet, which is where the days a child
  // does NOT attend are already on screen. Everything else in this app reads a
  // fact out of Kidsoft; this writes down something a parent said on the phone,
  // because Kidsoft's two waitlists cover permanent positions and per-date portal
  // bookings, and neither can hold "a Wednesday whenever one opens".

  var wishModal = null;
  // The child the dialog is open on, so flipping the radio can reload the form
  // from the other record without re-deriving who this is from the DOM.
  var wishCtx = null;

  $('#rv-body').on('click keydown', 'td.rv-pick', function (e) {
    if (e.type === 'keydown' && e.key !== 'Enter' && e.key !== ' ') return;
    e.preventDefault();
    openWish(openRoom, $(this).attr('data-key'), +$(this).data('wd'));
  });

  // THE CELL NO LONGER OFFERS A WAY IN (14 Aug 2026). "Someone waiting for Fri?"
  // closed the cell you were reading and rendered the room roster over it, which
  // is a long way to travel from a question to a name. Wishes are recorded where
  // the empty days already are: the room sheet, reached from the room name, where
  // clicking the empty box IS the whole interaction. A recorded wish still comes
  // BACK to this panel - it lands in "Asked for this day" beside the Kidsoft
  // requests, merged onto one row per family by cell_merge_requests().
  //
  // From the cell: amend a wish already recorded. The room comes off the button
  // because openRoom is null while a CELL is open - the panel shows one or the
  // other, never both.
  $('#cell-body').on('click', '.wish-edit', function () {
    var ks = $(this).data('ks');
    openWish(+$(this).data('room'), ks ? 'k' + ks : null, openCell ? openCell.wd : 0);
  });

  /**
   * @param roomId which room's sheet this child sits in.
   * @param key    'k' + Kidsoft ChildID, or 'p' + our placement row.
   * @param wd     the weekday whose box was clicked - ticked to start with, since
   *               it is the day the person is looking at. The others stay
   *               available: a parent asking for a Wednesday will often take a
   *               Thursday.
   */
  function openWish(roomId, key, wd, kind) {
    var r = room(roomId);
    if (!r || !key) return;

    var p = null;
    (r.placements || []).forEach(function (x) {
      var k = placeKey(x.kidsoft_child_id, x.id);
      if (k === key) p = x;
    });
    if (!p) return;

    wishCtx = { room: r, p: p, key: key, wd: wd };
    // WHICH FACT THIS BOX ALREADY CARRIES DECIDES WHICH WAY THE DIALOG OPENS.
    // A held day opens on "we are holding this day", a wished one on "waiting
    // for it", and a plain empty box on waiting - the older and far commoner of
    // the two. Passing a kind in overrides that, which is what the radio does.
    var wish = (r.wishes || {})[key] || null;
    var hold = (r.holds  || {})[key] || null;
    if (!kind) {
      if (hold && hold.days.indexOf(+wd) !== -1)      kind = 'hold';
      else if (wish && wish.days.indexOf(+wd) !== -1) kind = 'wish';
      else                                            kind = 'wish';
    }
    $('#wish-form [name=kind][value="' + kind + '"]').prop('checked', true);

    var name = ((p.child_first_name || '') + ' ' + (p.child_last_name || '')).trim() || 'This child';
    var have = p.days.map(function (d) { return DAYS[d]; }).join(', ');

    $('#wish-subtitle').text(name + ' - ' + r.name + ', ' + data.year +
      (have ? ' - currently ' + have : ''));
    $('#wish-form [name=room_id]').val(r.id);
    $('#wish-form [name=kidsoft_child_id]').val(p.kidsoft_child_id || '');
    $('#wish-form [name=placement_id]').val(p.id || '');

    wishFill(kind);
    // ON TOP OF THE ROOM SHEET rather than in its place: it is one question
    // about one box on it, and the answer shows up in that box. See
    // window.showOnTop() in app.js; from the cell panel it simply opens.
    wishModal = window.showOnTop(document.getElementById('wish-modal'));
  }

  /**
   * Load the form from whichever of the two records the chosen kind means, and
   * relabel every field that says something different about it.
   *
   * The two are stored apart and a child may hold one of each - waiting for a
   * Thursday while their Friday is held - so flipping the radio has to reload
   * the days, the note and the date, not merely rename them. Reading one kind's
   * boxes into the other's save is how a held day would quietly become a wish.
   */
  function wishFill(kind) {
    if (!wishCtx) return;
    var r = wishCtx.room, p = wishCtx.p, wd = wishCtx.wd;
    var held = kind === 'hold';
    var rec  = held ? ((r.holds || {})[wishCtx.key] || null)
                    : ((r.wishes || {})[wishCtx.key] || null);
    var name = ((p.child_first_name || '') + ' ' + (p.child_last_name || '')).trim() || 'This child';

    $('#wish-title').text(held ? 'Holding a day' : 'Waiting for a day');
    $('#wish-days-label').text(held ? 'Days held for them' : 'Days they are waiting for');
    $('#wish-note-label').text(held ? 'Why the place is being held' : 'What they said');
    $('#wish-note-help').text(held
      ? 'Read by anyone who opens this cell and asks why it is not for sale.'
      : 'The part Kidsoft has nowhere to keep.');
    $('#wish-when-label').text(held ? 'Held since' : 'When they asked');
    $('#wish-when-help').text(held
      ? 'The day the booking came off, not the day this was typed in.'
      : 'Decides who comes first if two families want the same place.');
    $('#wish-remove').text(held ? 'Stop holding these days' : 'No longer waiting');

    $('#wish-note').val(rec ? ((held ? rec.reason : rec.note) || '') : '');
    // Today comes from the SERVER (Sydney), never from new Date() - the viewer's
    // clock is not the one these dates are read against.
    $('#wish-when').val(rec ? (held ? rec.held_from : rec.requested_on) : (data.today || ''));
    $('#wish-form [name=id]').val(!held && rec ? rec.id : '');

    // Only the days they do not already attend: a day they are booked on is
    // neither waited for nor held - Kidsoft is holding it.
    var on = rec ? rec.days : [];
    $('#wish-days').html(Object.keys(DAYS).filter(function (d) {
      return p.days.indexOf(+d) === -1;
    }).map(function (d) {
      var ticked = +d === wd || on.indexOf(+d) !== -1;
      return '<input type="checkbox" class="btn-check" name="days[]" value="' + d +
        '" id="wish-d' + d + '"' + (ticked ? ' checked' : '') + '>' +
        '<label class="btn btn-sm btn-outline-primary" for="wish-d' + d + '">' + DAYS[d] + '</label>';
    }).join(''));

    $('#wish-remove').toggleClass('d-none', !rec);
  }

  $('#wish-form').on('change', '[name=kind]', function () {
    wishFill($(this).val());
  });

  $('#wish-form').on('submit', function (e) {
    e.preventDefault();
    var $f = $(this);
    var held = $f.find('[name=kind]:checked').val() === 'hold';
    $.post('/api/board', $f.serialize() +
      '&action=' + (held ? 'hold_save' : 'wish_save') + '&year=' + data.year)
      .done(function (res) {
        if (!res.ok) { toast(res.error, 'danger'); return; }
        wishModal.hide();
        // A day silently dropped is a day the team believes they recorded.
        (res.skipped || []).forEach(function (d) {
          toast(d + ' was not added; they already attend that day.', 'warning');
        });
        if (held) {
          // SAID BACK AS PLACE-DAYS, because that is what changed on the grid.
          // A hold is the only thing on this screen a person can do that takes a
          // place OFF sale, and a bare "Saved" would not say so.
          if ((res.held || []).length) {
            toast((res.held || []).map(function (d) { return DAYS[d]; }).join(', ') +
              ' held. Nobody else can be offered ' +
              (res.held.length === 1 ? 'that place.' : 'those places.'));
          }
          (res.released || []).forEach(function (d) {
            toast(DAYS[d] + ' is no longer held and is back on the board.', 'warning');
          });
        } else {
          toast(res.created ? 'Recorded.' : 'Updated.');
        }
        load();
      })
      .fail(function () { toast('Could not save that.', 'danger'); });
  });

  // "No longer waiting" / "Stop holding these days", not "delete": both are
  // things that happened rather than mistakes to erase. The row stays either
  // way, so the next person can see the day was asked for, or kept, and when.
  $('#wish-remove').on('click', function () {
    var $f = $('#wish-form');
    if ($f.find('[name=kind]:checked').val() === 'hold') {
      // An empty strip IS the release - the same statement a save makes, so it
      // goes through the same endpoint rather than a second one that could
      // disagree with it about what "none" means.
      $.post('/api/board', {
        action: 'hold_save', year: data.year,
        room_id: $f.find('[name=room_id]').val(),
        kidsoft_child_id: $f.find('[name=kidsoft_child_id]').val(),
        placement_id: $f.find('[name=placement_id]').val()
      })
        .done(function (res) {
          if (!res.ok) { toast(res.error, 'danger'); return; }
          wishModal.hide();
          toast('No longer held. The place is back on the board.');
          load();
        })
        .fail(function () { toast('Could not do that.', 'danger'); });
      return;
    }
    var id = $f.find('[name=id]').val();
    if (!id) return;
    $.post('/api/board', { action: 'wish_close', id: id, status: 'withdrawn' })
      .done(function (res) {
        if (!res.ok) { toast(res.error, 'danger'); return; }
        wishModal.hide();
        toast('Taken off the internal waitlist.');
        load();
      })
      .fail(function () { toast('Could not do that.', 'danger'); });
  });

  // Release one held day, from the cell panel's block 1c. Day-level on purpose:
  // a child held out of Thursday and Friday may be coming back on one of them.
  $('#cell-body').on('click', '.hold-release', function () {
    $.post('/api/board', { action: 'hold_release', id: $(this).data('id') })
      .done(function (res) {
        if (!res.ok) { toast(res.error, 'danger'); return; }
        toast('No longer held. The place is back on the board.');
        load();
      })
      .fail(function () { toast('Could not do that.', 'danger'); });
  });

  // The only part of this app Kidsoft has no opinion about: what the team is
  // DOING about an empty place. Everything else on this panel is a fact read from
  // Kidsoft; this is the record of the work.


  /** Start tracking a family against this cell. */
  $('#cell-body').on('click', '.work-start', function () {
    var $b = $(this).prop('disabled', true);
    // Asks first for a Do Not Enrol family - see postConfirmingDne() in app.js.
    postConfirmingDne('/api/work', {
      action: 'create',
      centre_id: centreId(), year: data.year,
      room_id: $b.data('room') || '', wd: $b.data('wd'),
      days: [$b.data('wd')],
      waitlist_id: $b.data('wl') || '',
      kidsoft_child_id: $b.data('ks') || '',
      child_name: $b.data('name')
    }).done(function (res) {
      if (res.cancelled) { $b.prop('disabled', false); return; }
      if (!res.ok) { toast(res.error, 'danger'); $b.prop('disabled', false); return; }
      toast($b.data('name') + ' added to In progress.');
      load();
    }).fail(function () {
      toast('Could not add that.', 'danger');
      $b.prop('disabled', false);
    });
  });

  /** Open an existing item to move it on. */
  $('#cell-body').on('click', '.work-open', function () {
    openWork($(this).data('id'));
  });

  // The modal itself lives in static/js/work-modal.js and its markup in
  // _incl/work-modal.php, because the In progress module opens the same thing.
  // It used to be defined here, which is what made a cell on this board the ONLY
  // route to a work item anywhere in the app.
  function openWork(id) { window.openWorkItem(id, function () { load(); }); }

  /**
   * The family behind a shortlist card: their application and every contact we
   * have had with them. Same modal, same file, same reason as openWork() above.
   *
   * load() on a write, because a saved application can change what this card
   * says - the days they want, the year, the room they asked for, and whether
   * they still fit this cell at all. The panel is re-rendered from the reload
   * rather than patched, which is how every other write on this screen behaves.
   */
  $('#cell-body').on('click', '.cp-open', function () {
    window.openWaitlistFamily(+$(this).data('wl'), function () { load(); });
  });

  // --- Cell panel actions ----------------------------------------------------
  // Everything here edits OUR records only. Kidsoft is strictly read-only, and
  // the board is now read FROM it - so there are no "fix" buttons any more.
  // There is nothing to fix: what Kidsoft says is what the board shows.

  // THE ONLY WAY INTO THE EDITOR IS A CHILD WHO IS ALREADY THERE. The three
  // buttons that used to open it empty - #cell-add, #room-add, and a dead
  // .open-place handler nothing had emitted for months - went on 14 Aug 2026:
  // measured zero uses ever, and typing a child onto a roll Kidsoft owns is the
  // work this app exists to remove. Annotating one it already knows (status,
  // flags, notes, days) is a different act and is untouched.
  //
  // A CHILD KIDSOFT KNOWS OPENS THEIR OWN DIALOG (6 Nov 2026), which carries
  // the flags and the note the editor did and everything around them - see
  // static/js/child-modal.js. Only a PROVISIONAL child, whom nothing can be
  // read for, still opens the placement editor.
  function openPerson(p) {
    if (p.kidsoft_child_id && typeof window.openChild === 'function') {
      window.openChild({ centre_id: CENTRE, ks_id: p.kidsoft_child_id, year: year,
                         room_id: p.room_id,
                         name: ((p.child_first_name || '') + ' ' + (p.child_last_name || '')).trim() },
                       function () { load(); });
      return;
    }
    openPlacement(p.room_id, p, null);
  }

  // THE SAME DIALOG OPENED FROM THE SEARCH PALETTE, saved while this board sat
  // behind it. search.js fires `child:changed` rather than knowing which screen
  // it is on; the board is the one that shows flags, so it re-reads.
  $(document).on('child:changed', function () { load(); });

  $('#cell-body')
    .on('click', '.place-chip', function () {
      var p = findPlacementByKey($(this).attr('data-key'));
      if (p) openPerson(p);
    })
    .on('keydown', '.place-chip', function (e) {
      if (e.key !== 'Enter' && e.key !== ' ' && e.key !== 'Spacebar') return;
      e.preventDefault();
      var p = findPlacementByKey($(this).attr('data-key'));
      if (p) openPerson(p);
    })
    // A name with a ChildID and no board row of its own to hand - a request
    // card, a re-enrolment hold, a held day. See childLink().
    .on('click', '.ch-link', function () {
      window.openChild({ centre_id: CENTRE, ks_id: String($(this).data('ks')), year: year,
                         room_id: openCell ? openCell.roomId : null, name: $(this).text() },
                       function () { load(); });
    });


  // --- Events ----------------------------------------------------------------

  function openCellFrom(el) {
    var c = { roomId: parseInt($(el).data('room'), 10), wd: parseInt($(el).data('wd'), 10) };
    // There used to be an `in_year` guard here. It existed because a WEEK could
    // straddle New Year, so a weekday could fall outside the board's year; a
    // year cannot straddle itself, so cells_year() stopped emitting the field --
    // and this guard then read undefined and swallowed EVERY click, which left
    // the grid looking like buttons that went nowhere and no way at all to see
    // who is in a room on a day. Nothing here is conditional any more.
    if (!room(c.roomId)) return;
    openCell = c;
    openRoom = null;
    renderCell();
    cellPanel.show();
  }

  // The room name in the left column opens the room sheet, its own dialog.
  function openRoomFrom(el) {
    openRoomSheet(parseInt($(el).data('room'), 10));
  }

  $('#grid').on('click', '.cell', function () { rvBack = null; openCellFrom(this); });
  $('#grid').on('click', '.grid-room', function () { openRoomFrom(this); });
  // Enter and Space open a cell, the way the role="button" on it promises.
  $('#grid').on('keydown', '.cell', function (e) {
    if (e.key !== 'Enter' && e.key !== ' ' && e.key !== 'Spacebar') return;
    e.preventDefault();          // Space would otherwise scroll the grid
    rvBack = null;
    openCellFrom(this);
  });
  $('#grid').on('keydown', '.grid-room', function (e) {
    if (e.key !== 'Enter' && e.key !== ' ' && e.key !== 'Spacebar') return;
    e.preventDefault();
    openRoomFrom(this);
  });
  $('#cell-panel').on('hidden.bs.offcanvas', function () {
    openCell = null;
    // "Back to the room" closes the panel and the room sheet comes back.
    if (rvBack && rvBackNow) { var id = rvBack; rvBack = null; rvBackNow = false; openRoomSheet(id); }
    rvBack = null;
    rvBackNow = false;
  });

  // --- The room sheet's controls ------------------------------------------------

  var rvBackNow = false;   // the panel is closing BECAUSE "Back to the room" was pressed

  /** A day head on the sheet opens that cell - the shortlist and the actions are there. */
  function rvToCell(wd) {
    var id = openRoom;
    if (!room(id)) return;
    // Leaving on purpose: the sheet must not spring back when the panel's own
    // dialogs close, and openRoom is cleared when it has gone.
    rvAway = false;
    $('#room-modal').one('hidden.bs.modal', function () {
      openCell = { roomId: id, wd: wd };
      rvBack = id;
      renderCell();
      cellPanel.show();
    });
    roomModal.hide();
  }

  $('#cell-back').on('click', function () {
    rvBackNow = true;
    cellPanel.hide();
  });

  $('#room-modal')
    .on('hidden.bs.modal', function () {
      // Stepped aside for another dialog: keep the room, it is coming back.
      if (!rvAway) openRoom = null;
    })
    .on('click', '.rv-dh', function () { rvToCell(+$(this).data('wd')); })
    .on('keydown', '.rv-dh', function (e) {
      if (e.key !== 'Enter' && e.key !== ' ' && e.key !== 'Spacebar') return;
      e.preventDefault();
      rvToCell(+$(this).data('wd'));
    })
    .on('click', '.rv-chip', function () {
      var k = $(this).attr('data-kind') || null;
      rvFilter = rvFilter === k ? null : k;
      rvApplyFilter();
    })
    .on('click', '.rv-step', function () { rvStep(+$(this).data('step')); })
    // Left and right arrows walk the rooms, the way the buttons do - unless a
    // field has the keyboard, or the sheet is scrolled sideways and the arrows
    // are what move it.
    .on('keydown', function (e) {
      if (e.key !== 'ArrowLeft' && e.key !== 'ArrowRight') return;
      if (e.altKey || e.ctrlKey || e.metaKey || e.shiftKey) return;
      if ($(e.target).is('input, textarea, select, [contenteditable]')) return;
      var b = document.getElementById('rv-body');
      if (b && b.scrollWidth > b.clientWidth + 1) return;
      e.preventDefault();
      rvStep(e.key === 'ArrowLeft' ? -1 : 1);
    })
    // The names: each opens what the cell panel's same names open.
    .on('click', '.place-chip', function () {
      var p = findPlacementByKey($(this).attr('data-key'));
      if (p) openPerson(p);
    })
    .on('keydown', '.place-chip', function (e) {
      if (e.key !== 'Enter' && e.key !== ' ' && e.key !== 'Spacebar') return;
      e.preventDefault();
      var p = findPlacementByKey($(this).attr('data-key'));
      if (p) openPerson(p);
    })
    .on('click', '.ch-link', function () {
      window.openChild({ centre_id: CENTRE, ks_id: String($(this).data('ks')), year: year,
                         room_id: openRoom, name: $(this).text() }, function () { load(); });
    })
    .on('click', '.cp-open', function () {
      window.openWaitlistFamily(+$(this).data('wl'), function () { load(); });
    })
    .on('click', '.work-open', function () { openWork($(this).data('id')); });

  /** The room before or after this one in the grid's order, wrapping round. */
  function rvStep(step) {
    var r = room(openRoom);
    if (!r || !data.rooms.length) return;
    var n = data.rooms.length, i = data.rooms.indexOf(r);
    var next = data.rooms[(i + step + n) % n];
    rvFilter = null;
    openRoom = next.id;
    $('#rv-body').removeData('room');
    renderRoom();
    $('#rv-body').scrollTop(0);
  }

  // ANOTHER DIALOG TAKES THE ROOM SHEET'S PLACE, AND GIVES IT BACK.
  //
  // Every name on the sheet opens a dialog of its own - the child, the family's
  // application, the enrolment - and Bootstrap 5 does not stack modals: two
  // backdrops, one focus trap, and Esc closing the wrong one. So whichever
  // dialog starts to open while the sheet is up is held back, the sheet steps
  // aside, and the dialog opens once it has gone. That covers every way in -
  // the names here, the search palette, a dialog that hands off to another -
  // without any of them having to know the sheet exists.
  //
  // AND WHEN THE LAST OF THEM SHUTS, THE SHEET COMES BACK, on the same room,
  // redrawn from whatever the dialog saved. Somebody who opened a child from
  // the sheet was in the middle of reading the room.
  //
  // Not the two that stack ON it on purpose: a question (confirmModal, .modal-ask)
  // and the wish dialog (window.showOnTop(), .modal-over) are about something on
  // the sheet and are answered over it.
  $(document).on('show.bs.modal', '.modal', function (e) {
    if (e.target !== this || this.id === 'room-modal' || $(this).is('.modal-ask, .modal-over')) return;
    if (!roomModal._isShown) return;
    e.preventDefault();
    var el = this;
    rvAway = true;
    $('#room-modal').one('hidden.bs.modal', function () {
      bootstrap.Modal.getOrCreateInstance(el).show();
    });
    // Mid-fade, Bootstrap ignores hide(); wait for the sheet to finish appearing.
    if (roomModal._isTransitioning) $('#room-modal').one('shown.bs.modal', function () { roomModal.hide(); });
    else roomModal.hide();
  });

  $(document).on('hidden.bs.modal', '.modal', function (e) {
    if (e.target !== this || this.id === 'room-modal' || !rvAway) return;
    // After the handlers that open the NEXT dialog have run - a dialog that
    // hands off to another closes first and opens the other from its own
    // hidden event, and the sheet must wait for that one too.
    setTimeout(function () {
      if (!rvAway) return;
      var up = $('.modal').toArray().some(function (m) {
        if (m.id === 'room-modal') return false;
        var inst = bootstrap.Modal.getInstance(m);
        return $(m).hasClass('show') || !!(inst && inst._isShown);
      });
      if (up) return;
      rvAway = false;
      if (openRoom && room(openRoom)) { renderRoom(); roomModal.show(); }
      else openRoom = null;
    }, 0);
  });

  /**
   * SHOW ME WHERE. One handler for every notice with a lit-btn in it: dim the
   * board down to the cells that notice is talking about, ring them, and scroll
   * them into view. The button names its own cells in `data-lit`, so a new
   * notice needs a selector and nothing here.
   *
   * The scroll is the half that matters. The grid is a scroller showing about
   * three of eleven rooms, so at Tuggerah every cell the banner is talking about
   * was off-screen while the banner was on it - highlighting cells nobody can
   * see is the same failure as naming rooms nobody can see. Scrolling the inner
   * container by hand rather than calling scrollIntoView(), which also drags the
   * page under the banner you just clicked.
   *
   * AND THE VISIBLE BAND IS NOT THE SCROLLER. A sticky header sits at the top of
   * it and the totals row is pinned to the bottom, so on Tuggerah only 306px of
   * the scroller's 413 actually shows a room. Scrolling to the first hit alone
   * put the other four September cells underneath the totals row, where the only
   * sign of them was 3px of outline poking out above it - which reads as a
   * rendering fault, not as four places to fill. So the whole run is centred in
   * the BAND when it fits, and only falls back to the first of them when it
   * genuinely cannot.
   *
   * ONE SET AT A TIME, ACROSS ALL THE NOTICES, and clicking the lit button
   * clears it: this is a "show me where" gesture, not a filter with state worth
   * accumulating. Two notices lit at once would dim nothing much and say less.
   */
  // The banner's named list (see departureList()).
  $('#year-notices')
    .on('click', '.cf-who-btn', function () {
      cfWhoOpen = !cfWhoOpen;
      $('#cf-who').prop('hidden', !cfWhoOpen);
      $(this).attr('aria-expanded', String(cfWhoOpen)).html(whoBtnLabel());
    })
    .on('click', '.cf-open', function () { openCellFrom(this); })
    .on('click', '.ch-link', function () {
      window.openChild({ centre_id: CENTRE, ks_id: String($(this).data('ks')), year: year,
                         room_id: parseInt($(this).data('room'), 10) || null,
                         name: $(this).text() }, function () { load(); });
    });

  $('#year-notices').on('click', '.lit-btn', function () {
    var $b   = $(this);
    var lit  = $b.attr('aria-pressed') === 'true';
    var $tbl = $('#grid .grid-table');

    $('#year-notices .lit-btn').attr('aria-pressed', 'false');
    $('#grid .cell').removeClass('cell-lit');
    $tbl.removeClass('grid-lit');
    if (lit) return;                       // clicking the lit button turns it off

    $b.attr('aria-pressed', 'true');
    var $hits = $('#grid .cell' + $b.attr('data-lit')).addClass('cell-lit');
    $tbl.addClass('grid-lit');

    var $sc = $('#grid .table-scroll');
    if ($hits.length && $sc.length) {
      var scTop  = $sc.offset().top;
      var head   = $sc.find('thead').outerHeight() || 0;
      var $last  = $hits.last();
      // Where the pinned totals row starts covering. Derived from its HEIGHT and
      // not from its offset: it is the row's CELLS that are position:sticky, not
      // the <tr>, so the row's own offset is its unpinned place at the foot of
      // the whole table and reading it here scrolled the grid into the weeds.
      var band   = $sc.height() - ($sc.find('.grid-totals').outerHeight() || 0);
      var top    = $hits.first().offset().top - scTop;
      var bottom = $last.offset().top + $last.outerHeight() - scTop;
      var delta  = (bottom - top <= band - head)
        ? (top + bottom) / 2 - (head + band) / 2
        : top - head - 6;

      // NEVER LEAVE A ROW STRADDLING THE TOTALS ROW. Tuggerah's nine September
      // place-days span more of the grid than the band can hold, so the second
      // room landed with 5px of itself showing above the pinned totals - enough
      // for the tops of four marks to poke out as brown stubs and nothing else.
      // A row that does not fit should be honestly off-screen, not a sliver that
      // reads as a rendering fault. All the cells of one row share a top, so at
      // most one row straddles and one correction covers it.
      var fix = 0;
      $hits.each(function () {
        var t = $(this).offset().top - scTop - delta;
        // +1 for the rounding: at exactly flush, one pixel of the 2px ring still
        // showed above the totals row.
        if (t < band && t + $(this).outerHeight() > band) fix = Math.max(fix, band - t + 1);
      });
      $sc.scrollTop($sc.scrollTop() + delta - fix);
    }
  });

  // Drilling into a centre is a real navigation now (the centre bar is links),
  // so the row behaves like the link it looks like: modifier-clicks and middle
  // clicks open a tab, and browser back returns to the overview.
  $('#overview').on('click', '.ov-centre-row', function (e) {
    if (e.metaKey || e.ctrlKey || e.shiftKey || e.which === 2) return;
    if ($(e.target).closest('a.ov-cap-link').length) return;
    e.preventDefault();
    window.location = placementHref($(this).data('centre'));
  });

  $('#refresh').on('click', function () { load(true); });


  load();
})(jQuery);
