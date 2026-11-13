/* SUPPORT PLANS.
 *
 * One card per child on a plan: where they are, which days they attend, which
 * days are being held for them, where the process has got to, and when anybody
 * last spoke to the family.
 *
 * ORDERED BY WHAT IS BEING MISSED (server-side, see _incl/support-plans.php):
 * most overdue step first, then never contacted, then longest silence. The
 * ordering IS the feature and must not be re-sorted here - a list sorted in the
 * browser and counted on the server is two answers to one question, which is the
 * fault this app keeps having to fix.
 *
 * EVERY DATE ON THIS SCREEN WAS FORMATTED BY THE SERVER. The rows carry
 * `*_display` strings beside their raw values and nothing here goes near
 * new Date() or toLocaleString - these are Sydney wall-clock dates, and
 * reinterpreting one in the viewer's browser timezone is how "due Friday"
 * becomes "due Thursday" for somebody on a laptop set to UTC. Which week a plan
 * is in is computed server-side too, for the same reason.
 *
 * THE LADDER IS A TRANSCRIPTION, NOT A DESIGN. Every step, its wording and who
 * owns it come from Emily Kite's two process documents (17 Sep 2026), kept in
 * _incl/docs/support_plans/ and held in one list in _incl/support-plan-steps.php.
 * Nothing here invents a stage.
 */
(function ($) {
  'use strict';

  var CENTRE = parseInt($('#init-centre').val(), 10) || 0;
  var DAYS = { 1: 'Mon', 2: 'Tue', 3: 'Wed', 4: 'Thu', 5: 'Fri' };
  var PLAN_ICON = { bsp: 'fa-umbrella', isp: 'fa-hands-holding-child' };

  var data = null, commModal = null, holdModal = null, planModal = null;
  var holdRow = null;

  // --- Loading ---------------------------------------------------------------

  /**
   * @param bool quiet true when a write in the modal triggered this. The list
   *        behind the modal must NOT flash back to "Loading..." - the reader is
   *        looking at the modal and the list is being refreshed because the
   *        order and the tiles above it have just changed underneath.
   */
  function load(quiet) {
    if (!quiet) $('#sp-list').html('<div class="text-muted small">Loading...</div>');
    $.getJSON('/api/support-plans', { action: 'list', centre_id: CENTRE })
      .done(function (res) {
        // EVERY caller checks res.ok in .done(), not only .fail() - nginx
        // discards the body of a real 4xx under /api/, so a readable failure
        // arrives as HTTP 200 with ok:false. See _incl/api.php.
        if (!res.ok) { $('#sp-list').html('<div class="alert alert-danger">' + esc(res.error) + '</div>'); return; }
        data = res;
        renderSummary();
        renderNotices();
        renderList();
        openLinkedPlan();
      })
      .fail(function () {
        $('#sp-list').html('<div class="alert alert-danger">Could not load the plans.</div>');
      });
  }

  // ?plan=<placement id>&type=bsp|isp OPENS THAT PLAN (29 Oct 2026). The link in
  // a booked day's Outlook entry lands here, on the child rather than a list to
  // search - the same as /progress?item= for a tour. The id is a request, not a
  // grant: it opens only a row the list already returned, and the list is scoped
  // on the server. Removed from the URL once read, so a reload does not reopen it.
  var linked = (function () {
    var q = new URLSearchParams(window.location.search);
    var id = parseInt(q.get('plan'), 10);
    if (!(id > 0)) return null;
    var u = new URL(window.location.href);
    u.searchParams.delete('plan');
    u.searchParams.delete('type');
    window.history.replaceState(null, '', u.pathname + u.search);
    return { id: id, type: q.get('type') };
  })();

  function openLinkedPlan() {
    if (!linked) return;
    var r = findRow(linked.id), type = linked.type;
    linked = null;
    if (!r) return;
    if (!r.plans[type]) type = r.plans.bsp ? 'bsp' : 'isp';
    if (r.plans[type]) showPlan(r, type);
  }

  function findRow(id) {
    var row = null;
    (data ? data.rows : []).forEach(function (r) { if (r.placement_id === +id) row = r; });
    return row;
  }

  // --- The tiles -------------------------------------------------------------

  function renderSummary() {
    var s = data.summary;
    var COL = 'col-6 col-lg';
    var h = '';
    // CHILDREN, not rows. A child flagged in two years is two rows below, each
    // true of its own year, and a tile reading 3 over a list of two children is
    // a figure contradicting its own caption. The BSP and ISP counts are a
    // sub-line rather than two more tiles because a child can be on both and
    // three tiles that do not add up is worse than one that does not try.
    // THE SPLIT ONLY WHERE THERE IS ONE. With everybody on the same kind of plan
    // the sub-line would just restate the tile's own number back at it, which is
    // the caption-says-nothing fault this app has already deleted twice.
    var mix = [];
    if (s.bsp) mix.push(s.bsp + ' on a behaviour plan');
    if (s.isp) mix.push(s.isp + ' on an individual plan');
    if (mix.length === 1) mix = [];
    h += statTile('On a plan', s.children,
                  mix.length ? mix.join(', ')
                             : (s.children === 1 ? 'child' : 'children'), '', COL);
    // THE HARDEST FACT ON THE SCREEN and therefore the tile beside the count: a
    // step with a date that has passed is something somebody was supposed to do,
    // which is a stronger claim than a family nobody happens to have rung.
    // Children AND steps, because one child eight steps behind and eight
    // children one step behind are different problems.
    h += statTile('Behind', s.overdue,
                  s.overdue_steps + (s.overdue_steps === 1 ? ' step overdue' : ' steps overdue'),
                  s.overdue ? 'text-danger' : 'text-success', COL);
    // THE PLACE-DAY IS THE UNIT EVERYWHERE IN THIS APP. A held day is a place
    // nobody else can be offered, which is the cost of the arrangement and the
    // only number on this screen the placement board also carries.
    h += statTile('Place-days held', s.held_days, 'out of the free figures on the board',
                  s.held_days ? 'text-warning' : '', COL);
    // Two tiles, not one total: "nobody has ever rung them" is a different
    // failure from "nobody has rung them lately", and summing the two would hide
    // the worse one inside the commoner one.
    h += statTile('Never contacted', s.never, 'no call, email or note logged at all',
                  s.never ? 'text-danger' : 'text-success', COL);
    h += statTile('Gone quiet', s.quiet, 'nothing logged for 14 days',
                  s.quiet ? 'text-warning' : 'text-success', COL);
    $('#sp-summary').html(h);
  }

  // A BANNER IS FOR WHAT THEY CANNOT KNOW UNAIDED. Kidsoft refusing to answer
  // changes whether the day strips can be trusted at all; everything else on
  // these rows is ours and is still right.
  function renderNotices() {
    if (!data.unreachable.length) { $('#sp-notices').empty(); return; }
    $('#sp-notices').html('<div class="alert alert-warning py-2">' +
      '<i class="fa-solid fa-triangle-exclamation me-1"></i>' +
      'Kidsoft would not answer for ' + esc(data.unreachable.join(', ')) +
      ', so the days attended are not shown for those children. The held days, the ' +
      'plan steps and the contact history below are ours and are unaffected.</div>');
  }

  // --- The plan: one line on the card, everything else in the modal ---------

  /**
   * THE DATES, IN FOUR FIXED COLUMNS.
   *
   * They were labelled facts already, but each plan rendered only the facts it
   * had - so a running BSP showed four, one waiting to start showed three, one
   * with no date showed two and an ISP showed two different ones. Four cards,
   * four layouts, and "Next" landing at a different x on every row: nothing to
   * read DOWN, which is the whole job of a list.
   *
   * So the columns are the same every time and each state fills the ones it has:
   *
   *   1  when it started      Commenced / Starts / Ended / Next review
   *   2  where it is now      Week
   *   3  when it resolves     Decision due
   *   4  the sentence         Next, or the Outcome once it is closed
   *
   * A COLUMN WITH NOTHING TO SAY GETS `--`, which is this app's own convention
   * for a figure it cannot give (a centre that is shut gets `--`, not a sentence
   * explaining why). An EMPTY column would let the grid collapse and take the
   * alignment with it, which is the thing this is for.
   */
  function planFacts(p) {
    var col = [null, null, null, null];

    if (p.closed) {
      col[0] = ['Ended', esc(p.outcome_display || '--')];
      col[3] = ['Outcome', esc(p.outcome_label)];
    } else if (p.type === 'bsp') {
      if (p.commenced_on && p.week_now) {
        col[0] = ['Commenced', esc(p.commenced_display)];
        col[1] = ['Week', p.week_now > 10
          ? '<span class="sp-fact-late">' + p.week_now + ', past week 10</span>'
          : p.week_now + ' of 10'];
      } else if (p.commenced_on) {
        col[0] = ['Starts', esc(p.commenced_display)];
        col[1] = ['Week', '<span class="sp-fact-dim">--</span>'];
      } else {
        // The rule that sets the date lives in the modal, which says it in full.
        // Repeating it on every card is the caption this app deletes rather than
        // writes - the team knows a plan starts the Monday after the email.
        col[0] = ['Starts', '<span class="sp-fact-dim">not yet</span>'];
        col[1] = ['Week', '<span class="sp-fact-dim">--</span>'];
      }
      // The last step is the week 10 decision, and its due date is already
      // derived from commencement - no second calculation of the same thing.
      var end = p.commenced_on && p.steps.length ? p.steps[p.steps.length - 1] : null;
      col[2] = ['Decision due', end && end.due_display
        ? esc(end.due_display) : '<span class="sp-fact-dim">--</span>'];
    } else {
      // JUST THE DATE. How late it is has its own badge in the row above, and
      // repeating it here both said the same thing twice and wrapped the column
      // onto a second line, which made this one row taller than every other.
      col[0] = ['Next review', p.next_review_on
        ? esc(p.next_review_display)
        : '<span class="sp-fact-dim">not set</span>'];
      // An ISP has no weeks and does not end, so columns 2 and 3 are not facts
      // it is missing - they are facts it does not have. Held open so its Next
      // lines up with every other Next in the list.
      col[1] = ['', ''];
      col[2] = ['', ''];
    }

    if (!p.closed) col[3] = ['Next', nextFact(p)];

    return '<div class="sp-facts">' + col.map(function (c) {
      if (!c) c = ['', ''];
      return '<div class="sp-fact">' +
        (c[0] ? '<span class="sp-fact-label">' + esc(c[0]) + '</span>' : '') +
        '<span class="sp-fact-value">' + c[1] + '</span>' +
      '</div>';
    }).join('') + '</div>';
  }

  function nextStep(p) {
    var next = null;
    p.steps.forEach(function (s) { if (!next && !s.done_on) next = s; });
    return next;
  }

  /**
   * The value of the Next fact: the first step nobody has done, its week, when
   * it is due and how late. Its own function because the printed report's
   * summary table carries the same sentence, and one fact keeps one wording.
   */
  function nextFact(p) {
    var nx = nextStep(p);
    if (!nx) {
      // AN ISP DOES NOT END, so once its steps are done the next thing on it is
      // always the review - and whether that is booked is the question.
      return p.type === 'bsp'
        ? 'Every step is done. Record how the plan ended.'
        : 'Review' + bookedFact(p.review_booking);
    }
    var v = esc(nx.title);
    if (nx.week) v += ' <span class="sp-fact-dim">week ' + nx.week + '</span>';
    if (nx.due_display) v += ' <span class="sp-fact-dim">due ' + esc(nx.due_display) + '</span>';
    if (nx.overdue_days) {
      v += ' <span class="sp-fact-late">' + nx.overdue_days +
           (nx.overdue_days === 1 ? ' day over' : ' days over') + '</span>';
    }
    return v + bookedFact(nx);
  }

  /**
   * THE NEXT STEP'S BOOKED DAY, or that it has none. A booked day goes into the
   * centre's calendar (support-plan-calendar.php), and a step that has been
   * reached with nothing booked is the one somebody needs to arrange - so "not
   * booked" is said, not left blank. `booked_past` is the server's Sydney clock.
   */
  function bookedFact(b) {
    if (!b.booked_on) return ' <span class="sp-fact-dim">not booked</span>';
    return ' <span class="sp-fact-dim"><i class="fa-solid fa-calendar-day me-1"></i>' +
      (b.booked_past ? 'was booked ' : 'booked ') + esc(b.booked_display) + '</span>';
  }

  /**
   * Under a booked-day field: what booking does, or whether it reached the
   * calendar. The server's plan_booking_shape() decides; 'in' and 'failed' are
   * said exactly as a tour's are, by window.tourCalendarNote().
   */
  function bookedNote(b, r) {
    if (!r.has_calendar) return '';
    var where = 'the ' + esc(r.centre_name) + ' calendar';
    if (!b || !b.booked_on) {
      // Not on a closed plan: a day after the plan ended never goes in.
      return openPlan.plan && openPlan.plan.closed
        ? '' : '<div class="form-text">Adds an all-day entry to ' + where + '.</div>';
    }
    // A PASSED DAY IS HISTORY: its entry stays, and a new day gets its own.
    // Said here because nothing else on screen could tell anybody that.
    var past = b.booked_past
      ? '<div class="form-text">This day has been. Booking another adds a new entry and leaves this one.</div>'
      : '';
    var cal = b.calendar;
    if (!cal) return past;
    if (cal.state === 'pending') {
      return '<div class="form-text"><i class="fa-solid fa-calendar-day me-1"></i>Going into ' + where + '</div>' + past;
    }
    return tourCalendarNote({ state: cal.state, text: cal.text, centre: r.centre_name }) + past;
  }

  /**
   * The booked-day field and its own button. BOOK IS NEEDED BECAUSE SAVE IS
   * NOT ALWAYS THERE: the nav's Save waits on the step's tick (Broc, 18 Sep
   * 2026), and a step is booked BEFORE it is done - so without this a day typed
   * on the gate page, or on the last, could not be saved at all. It saves the
   * whole page, which is one form, and stays put. Enabled only when the day
   * differs from what is saved, and it says Clear when the box has been emptied.
   *
   * NO title= ON IT: it takes focus and its own click re-renders it - the
   * tooltip trap this screen has hit three times.
   */
  function bookedField(id, b, r) {
    var was = (b && b.booked_on) || '';
    return '<label class="form-label" for="' + id + '">Booked for</label>' +
      '<div class="input-group">' +
        '<input type="date" class="form-control sp-booked-in" id="' + id + '" value="' + esc(was) +
          '" data-was="' + esc(was) + '">' +
        '<button type="button" class="btn btn-outline-primary sp-book" disabled>Book</button>' +
      '</div>' +
      bookedNote(b, r);
  }

  function planBadges(p) {
    var h = '<span class="badge text-bg-light border">' + p.done + ' of ' + p.total + ' done</span>';
    if (p.overdue) {
      h += ' <span class="badge text-bg-danger">' + p.overdue +
        (p.overdue === 1 ? ' step overdue' : ' steps overdue') + '</span>';
    }
    if (p.review_overdue_days) {
      h += ' <span class="badge text-bg-danger">review ' + p.review_overdue_days +
        (p.review_overdue_days === 1 ? ' day over' : ' days over') + '</span>';
    }
    if (p.closed) h += ' <span class="badge text-bg-secondary">ended</span>';
    return h;
  }

  /* THE CARD CARRIES A SUMMARY, NOT THE LADDER. Sixteen steps with a paragraph
     each ran one card to 2968px and pushed every other child off the screen -
     and this list is ordered by what is being missed, so a card that swallows
     the viewport defeats the only thing the screen is for.

     THE WHOLE BLOCK IS THE TARGET, not a link at the far right. The link was
     the only way in and it sat at the opposite end of the row from the words
     somebody had just finished reading, so the eye and the pointer both had to
     travel the full width of the card to act on what they had read.

     role/tabindex/keydown is the pattern this app already uses for a clickable
     thing that is not an <a> - see .place-chip and .rv-dh in board.js. The
     chevron is an affordance, not a second target: there is nothing else
     interactive inside the block, which is what makes the whole of it safe to
     click. */
  /**
   * THE PLAN'S SHAPE, IN THE EMPTY HALF OF THE CARD.
   *
   * One cell per step, grouped into the plan's stages - so the gaps ARE the
   * stage boundaries and the fill is how far through it is. It reuses the
   * modal's vocabulary exactly: green is done, red is overdue, purple is where
   * the plan is up to. Somebody who has opened one plan can read every card.
   *
   * WIDTHS ARE PROPORTIONAL TO STEP COUNT, so Weeks 6-9 (six steps) is six times
   * the width of Week 10 (one). Equal-width groups would draw a ten-week process
   * as five equal parts, which is a picture of something that does not exist.
   *
   * DECORATION, AND LABELLED AS SUCH. The same facts are in words beside it - the
   * badges count the steps and the state line names the week - so the element is
   * role="img" with the count as its label and nothing depends on telling four
   * quiet colours apart. No title= on the cells: this list re-renders under the
   * pointer on every save, which is the tooltip trap this screen has hit three
   * times.
   */
  function planTrack(p) {
    var next = nextStep(p);
    var keys = stageKeys(p);
    var groups = keys.length
      ? keys.map(function (k) {
          return p.steps.filter(function (x) { return x.phase === k; });
        }).filter(function (g) { return g.length; })
      : [p.steps];
    if (!p.steps.length) return '';

    return '<span class="sp-track' + (p.closed ? ' sp-track-closed' : '') + '" role="img"' +
        ' aria-label="' + esc(p.done + ' of ' + p.total + ' steps done') + '">' +
      groups.map(function (g) {
        // NO inline flex here. It used to set the group's width proportional to
        // its step count, and `flex:4` means flex-basis:0 - which collapsed every
        // cell to its 3px minimum. Now that a cell is a constant width, a group
        // of six is six times a group of one without anything being told to be.
        return '<span class="sp-track-group">' +
          g.map(function (x) {
            var st = x.done_on ? ' sp-cell-done'
                   : x.overdue_days ? ' sp-cell-late'
                   : (next && x.key === next.key) ? ' sp-cell-next' : '';
            return '<span class="sp-cell' + st + '" title="' +
              esc(cellTip(x, next)) + '"></span>';
          }).join('') +
        '</span>';
      }).join('') +
    '</span>';
  }

  /**
   * WHAT THE BLOCK UNDER THE POINTER ACTUALLY IS.
   *
   * The track already said WHERE a plan has stalled - the red block sitting in
   * the fourth group - and that was the whole of what it could say. Which step,
   * what date it carries, who ticked it and what they wrote about it were all
   * one modal away, and the track is sixteen anonymous rectangles until you have
   * opened that modal at least once.
   *
   * A PLAIN `title=`, WHICH THIS SCREEN HAS THREE TIMES BEEN BITTEN BY, and the
   * rule it wrote is narrower than "no title here": an element that TAKES FOCUS
   * and is re-rendered by its own click must use aria-label, because Bootstrap
   * is then left holding an instance whose element has gone. A track cell takes
   * no focus and is not itself a target - the block around it is the one target
   * - and app.js drops a live tooltip on any click and whenever its trigger
   * leaves the document (the MutationObserver in the TOOLTIPS block). Driven to
   * check rather than reasoned about: hover a cell, click through to the modal,
   * save a step, close - no orphaned bubble and no console error.
   *
   * IT CANNOT REACH THE BLOCK'S ACCESSIBLE NAME either, which is the other half
   * of why the block itself still carries neither attribute: .sp-track is
   * role="img" with an aria-label, and name computation does not descend into
   * one - so the block goes on announcing its own content rather than reciting
   * sixteen step titles.
   */
  function cellTip(s, next) {
    var bits = [];
    if (s.week) bits.push('week ' + s.week);
    if (s.done_on) {
      bits.push('done ' + s.done_display +
        (s.done_by ? ' by ' + staffName(s.done_by) : ''));
    } else {
      // A HAND-SET DUE DATE IS THE ONE DATE HERE NOBODY CAN DERIVE. Every other
      // one moves when the commencement date moves; this one deliberately does
      // not, and the modal says so on a pencil glyph nobody sees from the list.
      bits.push(s.due_display
        ? 'due ' + s.due_display + (s.due_override ? ', set by hand' : '')
        : 'no date yet');
      if (s.overdue_days) {
        bits.push(s.overdue_days + (s.overdue_days === 1 ? ' day over' : ' days over'));
      }
      if (next && s.key === next.key) bits.push('the next step');
    }
    // esc() at the call site, html:false in app.js and pre-line in the CSS, so
    // the break is a break and the note is literal text however it was typed.
    return s.title + '\n' + bits.join(' - ') + (s.note ? '\n' + s.note : '');
  }

  /* HOW MANY OF EACH THE CARD CARRIES.
     A sixteen step BSP late in its run has fourteen recorded dates, and printing
     all of them is the 2968px card this screen already deleted once. Four is
     what fits on one line at the desktop widths this list is read at, and the
     ones that fall off are the oldest - the question a card answers is what has
     been done LATELY, and the whole trail is one click away. */
  var WORK_SHOWN  = 4;
  var NOTES_SHOWN = 2;
  var NOTES_LONG  = 80;

  /**
   * WHAT HAS ALREADY BEEN DONE, which the card could not say at all.
   *
   * Everything above this line is about what is MISSING - steps overdue, the
   * next one and its date, how long the family has been left alone. That is the
   * screen's job and it stays first, but a reader deciding whether a plan is
   * actually being worked had nothing to go on: "4 of 16 done" is a count with
   * no dates in it, and the dates and the notes somebody typed were only ever
   * visible inside the modal, one child at a time.
   *
   * NOTHING NEW IS STORED AND NOTHING NEW IS COMPUTED. Every field here was
   * already on the wire - plan_shape() has published done_on, done_by and note
   * per step since the ladder was built - so this is the same facts finally read
   * together, which is the test this whole screen was built on.
   *
   * THE LABEL COLUMN IS THE FACTS GRID'S OWN FIRST COLUMN (8.5rem), so RECORDED
   * and NOTES sit directly under COMMENCED and WEEK. Four cards, four layouts
   * was the fault the facts grid was built to fix, and a second block laid out
   * to its own widths would have put it straight back.
   *
   * A ROW WITH NOTHING TO SAY STILL RENDERS, for the same reason a fact column
   * does: the alignment down the list is the whole job of a list. It says
   * "nothing yet" rather than `--` because this one is not a figure we cannot
   * give - we can, and the answer is none.
   */
  function planWork(p) {
    // BY THE DATE SOMEBODY TYPED, not by position in the ladder. Steps are
    // regularly ticked out of order - the week numbers are expectations that
    // slip, which is the correction this screen already has on the record - and
    // a trail of dates that is not in date order is not a trail.
    var done = p.steps.filter(function (s) { return s.done_on; });
    done.sort(function (a, b) {
      return a.done_on < b.done_on ? -1 : a.done_on > b.done_on ? 1 : 0;
    });

    var cut  = Math.max(0, done.length - WORK_SHOWN);
    var recorded = (cut ? '<span class="sp-work-more">+' + cut + ' earlier</span>' : '') +
      done.slice(cut).map(function (s) {
        // The same tooltip the track cell carries, because it is the same step:
        // one fact keeps one wording, the rule that merged the day strip out of
        // four copies.
        return '<span class="sp-work-item" title="' + esc(cellTip(s, null)) + '">' +
          '<i class="fa-solid fa-check sp-work-tick"></i>' +
          '<span class="sp-work-when">' + esc(s.done_display) + '</span>' +
          '<span class="sp-work-what">' + esc(s.title) + '</span>' +
        '</span>';
      }).join('');

    // A NOTE DOES NOT NEED ITS STEP TO BE TICKED. plan_step_save() keeps the note
    // when the tick comes off, which is how "waiting on mum to confirm a date"
    // gets recorded against a step nobody has done - and that note is worth more
    // on this list than most of the ticked ones.
    var notes = p.steps.filter(function (s) { return s.note; }).map(function (s) {
      return { text: s.note, what: s.title, when: s.done_display };
    });
    // The outcome's own note, which lived only in the modal's closing banner.
    if (p.outcome_note) {
      notes.push({ text: p.outcome_note, what: p.outcome_label, when: p.outcome_display });
    }
    var nCut = Math.max(0, notes.length - NOTES_SHOWN);
    var written = (nCut ? '<span class="sp-work-more">+' + nCut + ' earlier</span>' : '') +
      notes.slice(nCut).map(function (n) {
        // THE FULL TEXT ON HOVER, but ONLY when there is a chance the two line
        // clamp is hiding some of it. A note is 500 characters at most and one of
        // them would otherwise make a single card four rows taller than every
        // other, which is the reading-down fault in a new shape - but a tooltip
        // repeating a line already on screen is the empty caption this app
        // deletes rather than writes. NOTES_LONG is two clamped lines at the
        // narrowest column this renders in, rounded down: err short and the cost
        // is a tooltip nobody needed, never a note nobody can read.
        var full = n.text.length > NOTES_LONG;
        return '<div class="sp-work-note">' +
          '<span class="sp-work-note-text"' +
            (full ? ' title="' + esc(n.text) + '"' : '') + '>' +
            esc(n.text) + '</span>' +
          '<span class="sp-work-note-meta">' + esc(n.what) +
            (n.when ? ' &middot; ' + esc(n.when) : '') + '</span>' +
        '</div>';
      }).join('');

    return '<div class="sp-work">' +
      '<div class="sp-work-row">' +
        '<span class="sp-fact-label">Recorded</span>' +
        '<div class="sp-work-items">' +
          (recorded || '<span class="sp-fact-dim">nothing yet</span>') +
        '</div>' +
      '</div>' +
      '<div class="sp-work-row">' +
        '<span class="sp-fact-label">Notes</span>' +
        '<div class="sp-work-notes">' +
          (written || '<span class="sp-fact-dim">none</span>') +
        '</div>' +
      '</div>' +
    '</div>';
  }

  function planBlock(r, p) {
    // NO title= AND NO aria-label. On a role="button" either one BECOMES the
    // accessible name and replaces the content, so a screen reader would announce
    // "Open the plan" and stop - dropping "8 steps overdue", which is the whole
    // reason the row is worth opening. Left off, the block announces everything it
    // shows. The native tooltip also sat on top of the day strip above it.
    return '<div class="sp-plan" role="button" tabindex="0" data-id="' + r.placement_id +
             '" data-type="' + p.type + '">' +
      '<div class="sp-plan-head">' +
        '<i class="fa-solid ' + PLAN_ICON[p.type] + ' text-muted"></i>' +
        '<span class="sp-plan-title">' + esc(p.type_label) + '</span>' +
        planBadges(p) +
        planTrack(p) +
        '<i class="fa-solid fa-chevron-right sp-plan-go"></i>' +
        planFacts(p) +
        planWork(p) +
      '</div>' +
    '</div>';
  }

  // --- The list --------------------------------------------------------------

  /** How long the family has been left alone. */
  function quiet(r) {
    if (r.quiet_days === null) {
      return '<span class="badge text-bg-danger">never contacted</span>';
    }
    if (r.quiet_days === 0) return '<span class="badge text-bg-success">spoken to today</span>';
    var cls = r.quiet_days >= 14 ? 'text-bg-warning' : 'text-bg-light border';
    return '<span class="badge ' + cls + '">' + r.quiet_days +
      (r.quiet_days === 1 ? ' day' : ' days') + ' since contact</span>';
  }

  function renderList() {
    if (!data.rows.length) {
      $('#sp-list').html('<div class="card"><div class="card-body text-muted small">' +
        'Nobody is on a support plan here. A plan is ticked on a child in the placement ' +
        'board - open a room, click a name, and tick Behaviour Support Plan or ' +
        'Individual Support Plan.' +
        '</div></div>');
      return;
    }

    $('#sp-list').html(data.rows.map(function (r) {
      // THE HELD DAYS AND THE ATTENDED DAYS IN ONE STRIP, because they are the
      // same week and reading them as two lists means comparing two rows of
      // commas. window.dayStrip() is the app's whole day vocabulary: green =
      // attends, amber padlock = held and out of the free figure.
      // UNKNOWN AND NONE ARE DIFFERENT ANSWERS. Kidsoft refusing is a data
      // fault; a child with no bookings at all is a common shape of a plan -
      // removed from care pending an improvement in behaviour - and is the
      // state this screen most exists to show.
      var strip = r.days === null
        ? '<span class="text-muted small">days unknown - Kidsoft would not answer</span>'
        : dayStrip({ has: r.days, held: r.held_days, wide: true }) +
          (r.days.length ? '' :
            '<span class="sp-nodays">not booked on any day in this room</span>');

      var holds = r.holds.map(function (h) {
        return '<div class="cp-held-row">' +
          '<span class="cp-held-name">' + DAYS[h.weekday] + ' held</span>' +
          (h.reason ? '<span class="cp-held-note">' + esc(h.reason) + '</span>' : '') +
          '<span class="badge text-bg-light border">since ' + esc(h.held_from_display) + '</span>' +
          ' <button type="button" class="btn btn-sm btn-outline-secondary sp-release" data-id="' +
            h.id + '">Release</button>' +
          '</div>';
      }).join('');

      var last = r.last_contact
        ? '<div class="sp-last"><i class="' +
            channelIcon(r.last_contact.channel) + ' me-2 text-muted"></i>' +
            esc(r.last_contact.summary) +
            '<span class="sp-last-meta">' + esc(r.last_contact.when) + ' &middot; ' +
            esc(r.last_contact.by) + '</span></div>'
        : '<div class="sp-last text-muted">No call, email or note has ever been logged for this family.</div>';

      // A CHILD MAY CARRY BOTH PLANS, and they are two lines on one card rather
      // than two cards: one child, in one room, with one set of held days and
      // one contact history underneath them.
      var plans = '';
      ['bsp', 'isp'].forEach(function (t) {
        if (r.plans[t]) plans += planBlock(r, r.plans[t]);
      });

      // THE NAME OPENS THE CHILD, THE ROOM GOES TO THE CELL (6 Nov 2026). The
      // name used to go to the cell because there was no detail to open; there
      // is now - window.openChild(), everything about the child and their
      // family. The cell is still one click away on the room, the same split
      // the search palette makes: who they are, and where they sit. The cell
      // link carries room and wd, a route index.php forwards to /placement
      // intact, landing on the roster where a day is held or released.
      var cellWd = r.held_days.length ? r.held_days[0] : (r.days && r.days.length ? r.days[0] : 1);
      var href = '/placement?centre=' + r.centre_id + '&year=' + r.year +
                 '&room=' + r.room_id + '&wd=' + cellWd;

      return '<div class="card mb-3"><div class="card-body">' +
        '<div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-2">' +
          '<div>' +
            (r.ks_id
              ? childName({ centre_id: r.centre_id, ks_id: r.ks_id, name: r.name, cls: 'sp-name' })
              : '<a class="sp-name" href="' + esc(href) + '">' + esc(r.name) + '</a>') +
            '<div class="small text-muted">' +
              (data.group ? esc(r.centre_name) + ' &middot; ' : '') +
              // window.roomIcon() - the mark every other screen puts in front of
              // a room name (tenure, rooms, waitlist, search, the dashboard, the
              // work modal). This was the one screen that did not.
              '<a class="sp-room" href="' + esc(href) + '" title="Open this room on the Placement board">' +
                roomIcon(r.room_name) + esc(r.room_name) + '</a> &middot; ' + r.year +
            '</div>' +
          '</div>' +
          '<div class="text-end">' + quiet(r) + '</div>' +
        '</div>' +
        '<div class="mb-2">' + strip + '</div>' +
        (r.note ? '<div class="wish-note small mb-2">' + esc(r.note) + '</div>' : '') +
        (holds ? '<div class="cp-held mb-2">' + holds + '</div>' : '') +
        plans +
        last +
        '<div class="mt-2 d-flex flex-wrap gap-2">' +
          '<button type="button" class="btn btn-sm btn-primary sp-log" data-id="' + r.placement_id +
            '" data-name="' + esc(r.name) + '">' +
            '<i class="fa-solid fa-phone me-1"></i>Log a call</button>' +
          (r.days === null
            ? '<button type="button" class="btn btn-sm btn-outline-secondary" disabled' +
              ' title="Kidsoft would not say which days this child attends, so a day' +
              ' cannot be held without risking one they are booked on.">' +
              '<i class="fa-solid fa-lock me-1"></i>Hold days</button>'
            : '<button type="button" class="btn btn-sm btn-outline-primary sp-hold" data-id="' +
              r.placement_id + '">' + '<i class="fa-solid fa-lock me-1"></i>' +
              (r.held_days.length ? 'Change held days' : 'Hold days') + '</button>') +
          // This child alone, every step and every contact - see
          // support-plans-report.php. A link, because it is a page.
          '<a class="btn btn-sm btn-outline-secondary" href="/support-plans-report?placement=' +
            r.placement_id + '"><i class="fa-solid fa-print me-1"></i>Print</a>' +
        '</div>' +
      '</div></div>';
    }).join(''));
  }

  // --- The plan modal --------------------------------------------------------

  /* WHAT IS DELIBERATELY NOT TRACKED, said out loud so nobody later reads the
     omission as an oversight and "fixes" it. This is a tracker: the forms live
     on paper in the room's Emergency Folder and pulling them in here would be
     building a compliance system for educators rather than an enrolments tool. */
  var BSP_THROUGHOUT =
    'Throughout, and not tracked here: the Room Leader completes a Challenging ' +
    'Behaviour Form and an Incident, Injury or Trauma report where one is needed, ' +
    'records it on the Challenging Behaviours Log, and raises it at the weekly room ' +
    'leader meeting. Two forms in one day and the Nominated Supervisor or Responsible ' +
    'Person decides whether the family is called to collect early. Those forms live in ' +
    'the room\'s Emergency Folder.';

  // Which plan the modal is showing, and which step row is open inside it.
  // `phases` holds only phases the reader has explicitly toggled; anything not
  // in it falls back to "collapsed when every step in it is done".
  // `at` is which page of the wizard the modal is showing.
  var openPlan = { id: null, type: null, plan: null, row: null, at: null };

  /**
   * ONE STEP, INSIDE THE STAGE IT BELONGS TO.
   *
   * The policy text is back on the row, which it could not afford to be when
   * this was a flat list of sixteen - a stage holds one to six steps, so the
   * paragraph that says HOW to do the thing is readable exactly where somebody
   * is about to do it.
   */
  /**
   * ONE LINE ABOVE THE STAGE STRIP - the only figures that are about the plan as
   * a whole rather than about the page you are on.
   */
  function headLine(p) {
    if (p.closed) {
      return '<div class="alert alert-secondary py-2 mb-3">' +
        '<i class="fa-solid fa-flag-checkered me-1"></i><strong>' + esc(p.outcome_label) +
        '</strong>' + (p.outcome_display ? ', ' + esc(p.outcome_display) : '') +
        (p.outcome_by ? ' &middot; ' + esc(p.outcome_by) : '') +
        (p.outcome_note ? '<div class="mt-1">' + esc(p.outcome_note) + '</div>' : '') +
        '<div class="mt-1">Nothing on a closed plan is overdue. Reopen it if this was wrong.</div>' +
      '</div>';
    }
    var late = [];
    if (p.overdue) late.push(p.overdue + (p.overdue === 1 ? ' step overdue' : ' steps overdue'));
    if (p.review_overdue_days) {
      late.push('review ' + p.review_overdue_days +
        (p.review_overdue_days === 1 ? ' day overdue' : ' days overdue'));
    }
    var bits = [late.length
      ? '<span class="sp-head-late"><i class="fa-solid fa-triangle-exclamation me-1"></i>' +
        esc(late.join(', ')) + '</span>'
      : '<span class="sp-head-ok"><i class="fa-solid fa-check me-1"></i>nothing overdue</span>'];
    bits.push('<span>' + p.done + ' of ' + p.total + ' steps done</span>');
    if (p.type === 'bsp') {
      bits.push('<span>' + (p.commenced_on
        ? (p.week_now ? 'week ' + p.week_now + ' of 10' : 'starts ' + esc(p.commenced_display))
        : 'not commenced') + '</span>');
    }
    return '<div class="sp-head-line">' + bits.join('') + '</div>';
  }

  // --- The pages -------------------------------------------------------------

  function stageKeys(p) { return Object.keys(p.phases || {}); }

  /**
   * EVERY PAGE OF THE WIZARD, IN ORDER: one per step.
   *
   * An ISP gets a final page that is not a step. "5. Monitor and review" IS one
   * of the ISP document's five action steps and the only one with nothing to
   * tick - it recurs for the life of the enrolment and carries a date instead.
   * Leaving it out of the ladder was right while the ladder was a checklist; as
   * a page it is the document's own step 5, put back.
   */
  function planPages(p) {
    var pages = [];
    p.steps.forEach(function (st) {
      pages.push({ kind: 'step', step: st });
      // Straight after the plan is emailed, which is where the document puts it.
      if (p.type === 'bsp' && st.anchor) pages.push({ kind: 'note' });
    });
    if (p.type === 'isp') pages.push({ kind: 'review' });
    return pages;
  }

  /**
   * WHERE THE WORK IS, which is not the same as which week it is. The page of
   * the first step nobody has done. A plan can be in calendar week 8 with the
   * week 6 meeting still outstanding, and opening on week 8 because the date
   * says so would walk straight past it.
   */
  function liveIndex(p) {
    var pages = planPages(p);
    for (var i = 0; i < pages.length; i++) {
      if (pages[i].kind === 'step' && !pages[i].step.done_on) return i;
    }
    return Math.max(0, pages.length - 1);
  }

  /**
   * THE FURTHEST PAGE THE READER MAY REACH - no skipping ahead (Broc, 17 Sep 2026).
   *
   * I argued against this on the grounds that a missed week 2 room visit could
   * never be ticked and would lock the plan for good. That was wrong about the
   * process: "Things may need to be pushed back a week or whatever" - the week
   * numbers are EXPECTATIONS THAT SLIP, not deadlines that lapse. A visit that
   * did not happen in week 2 happens later and is ticked then, so no step is ever
   * permanently uncompletable and the gate cannot trap anybody.
   *
   * BACK IS NEVER GATED, and neither is a stage already reached. This stops
   * somebody running ahead of the process, not from checking what they did.
   *
   * THE GRAIN DIFFERS BY PLAN, because the documents do:
   *   BSP - by STAGE. A stage groups work that genuinely runs together: the four
   *         weekly room visits sit alongside the week 6 family meeting, and the
   *         visit still happens in a week the meeting gets rearranged out of.
   *   ISP - by STEP. Its four action steps are strictly one after another -
   *         identify, meet, write, issue - and it has no stages to group.
   */
  function furthestPage(p) {
    var pages = planPages(p);
    var keys  = stageKeys(p);

    if (!keys.length) {
      for (var i = 0; i < pages.length; i++) {
        if (pages[i].kind === 'step' && !pages[i].step.done_on) return i;
      }
      return pages.length - 1;
    }

    var open = null;
    keys.forEach(function (k) {
      if (open) return;
      var st = p.steps.filter(function (x) { return x.phase === k; });
      if (st.some(function (x) { return !x.done_on; })) open = k;
    });
    if (!open) return pages.length - 1;

    // The last page belonging to the first unfinished stage. A page that is not
    // a step - the document's own recurring actions - rides with whatever came
    // before it, so it is never what blocks the way.
    var last = 0;
    pages.forEach(function (pg, i) {
      if (pg.kind === 'step' && pg.step.phase === open) last = i;
    });
    return last;
  }

  /** Which stage is the one currently being worked, for the strip. */
  function openStage(p) {
    var at = furthestPage(p), pages = planPages(p);
    for (var i = at; i >= 0; i--) if (pages[i].kind === 'step') return pages[i].step.phase;
    return null;
  }

  function pageAt(p) {
    var pages = planPages(p);
    var i = openPlan.at;
    if (i === null || i < 0 || i >= pages.length) i = liveIndex(p);
    return { i: i, page: pages[i], pages: pages };
  }

  /**
   * The strip across the top: the five stages, their state, and a way into any.
   *
   * IT NAVIGATES, IT DOES NOT CONSTRAIN. A wizard that made you finish a stage
   * to reach the next would stop the team recording what actually happened - the
   * room visits get done while a family meeting is still being arranged, and
   * three of the policy's exits fire at any point. Clicking a stage goes to its
   * first unfinished step, or its first step if it is done.
   */
  function stageStrip(p, at) {
    var keys = stageKeys(p);
    if (!keys.length) return '';
    var here = at.page.kind === 'step' ? at.page.step.phase : null;
    var open = openStage(p);

    return '<div class="sp-stages">' + keys.map(function (key, i) {
      var steps = p.steps.filter(function (x) { return x.phase === key; });
      var done  = steps.filter(function (x) { return x.done_on; }).length;
      var late  = steps.some(function (x) { return x.overdue_days; });
      var all   = steps.length && done === steps.length;
      var on    = key === here;
      var shut  = i > keys.indexOf(open);
      var mark  = all ? '<i class="fa-solid fa-check"></i>'
                : (shut ? '<i class="fa-solid fa-lock"></i>'
                : (late ? '<i class="fa-solid fa-exclamation"></i>' : String(i + 1)));

      return '<button type="button" class="sp-stage' + (all ? ' sp-stage-done' : '') +
               (late ? ' sp-stage-late' : '') + (on ? ' sp-stage-here' : '') +
               (shut ? ' sp-stage-shut' : '') +
               '" data-stage="' + esc(key) + '"' + (on ? ' aria-current="step"' : '') +
               (shut ? ' disabled' : '') +
               // aria-label AND NOT title=. Every plain title= in this app
               // becomes a Bootstrap tooltip, and these buttons take focus from
               // a click and are then destroyed by the re-render that same click
               // triggers - so closing the modal afterwards drove Bootstrap's
               // focus handling onto an orphaned instance and threw "Cannot
               // convert undefined or null to object". The identical trap the
               // step checkbox hit. The full stage name is on the page itself
               // once you are on it, so nothing is lost but a hover.
               ' aria-label="' + esc(p.phases[key].label + ' - ' +
                 (shut ? 'not reached yet' : done + ' of ' + steps.length + ' done')) + '">' +
        '<span class="sp-stage-mark">' + mark + '</span>' +
        '<span class="sp-stage-label">' + esc(p.phases[key].short) + '</span>' +
      '</button>';
    }).join('') + '</div>';
  }

  /**
   * ONE STEP, THE WHOLE PAGE.
   *
   * The fields are simply here - no click to open, no expanding row. The page IS
   * the editor, which is what a wizard buys: at one step per page there is room
   * for ordinary type instead of the 0.7rem everything had shrunk to trying to
   * fit sixteen of them on screen at once.
   */
  function stepPage(p, s) {
    var where = (s.phase && p.phases && p.phases[s.phase]) ? p.phases[s.phase].label : null;
    var meta = [];
    if (s.week) meta.push('<span class="sp-week">week ' + s.week + '</span>');
    if (s.anchor) {
      meta.push('<span class="sp-week sp-week-anchor">sets every date below it</span>');
    }
    if (s.done_on) {
      meta.push('<span class="sp-page-when"><i class="fa-solid fa-check text-success me-1"></i>done ' +
        esc(s.done_display) + (s.done_by ? ' by ' + esc(s.done_by) : '') + '</span>');
    } else if (s.overdue_days) {
      meta.push('<span class="badge text-bg-danger">' + s.overdue_days +
        (s.overdue_days === 1 ? ' day over' : ' days over') + '</span>');
      if (s.due_display) meta.push('<span class="sp-page-when">due ' + esc(s.due_display) + '</span>');
    } else if (s.due_display) {
      meta.push('<span class="sp-page-when">due ' + esc(s.due_display) + '</span>');
    }

    var dated = s.week !== null || !!s.due_on;

    return '<div class="sp-page" data-key="' + esc(s.key) + '">' +
      (where ? '<div class="sp-page-where">' + esc(where) + '</div>' : '') +
      '<h4 class="sp-page-title">' + esc(s.title) + '</h4>' +
      '<div class="sp-page-meta">' + meta.join('') + '</div>' +
      '<div class="sp-page-who"><i class="fa-solid fa-user-group me-2"></i>' + esc(s.who) + '</div>' +
      '<p class="sp-page-detail">' + esc(s.detail) + '</p>' +

      '<div class="sp-page-form">' +
        '<div class="form-check sp-page-check">' +
          '<input class="form-check-input" type="checkbox" id="sp-page-done"' +
            (s.done_on ? ' checked' : '') + '>' +
          '<label class="form-check-label" for="sp-page-done">This step has been done</label>' +
        '</div>' +

        '<div class="row g-3">' +
          '<div class="col-sm-6' + (s.done_on ? '' : ' d-none') + '" id="sp-page-donewrap">' +
            '<label class="form-label" for="sp-page-doneon">Done on</label>' +
            '<input type="date" class="form-control se-done" id="sp-page-doneon" value="' +
              esc(s.done_on || data.today || '') + '">' +
            '<div class="form-text">' + esc(s.anchor
              ? 'The day the plan was emailed to the family. It commences the first Monday after this, and every dated step is worked out from that.'
              : 'The day it actually happened.') + '</div>' +
          '</div>' +
          (dated
            ? '<div class="col-sm-6">' +
                '<label class="form-label" for="sp-page-due">Due</label>' +
                '<input type="date" class="form-control se-due" id="sp-page-due" value="' +
                  esc(s.due_override ? (s.due_on || '') : '') + '">' +
                '<div class="form-text">' + esc(
                  s.due_override
                    ? 'Set by hand, so it will not move if the commencement date changes.'
                    : (s.due_on
                        ? 'Blank uses the date the process document implies (' + s.due_display + ').'
                        : 'The plan has not commenced, so there is no date to fall back on.')) +
                '</div>' +
              '</div>'
            : '') +
          // THE DAY IT IS ACTUALLY HAPPENING, which the due date is not: "by the
          // end of week 6" is the policy, the 10th is when the family can come.
          '<div class="col-sm-6">' + bookedField('sp-page-booked', s, openPlan.row) + '</div>' +
          '<div class="col-sm-6">' +
            '<label class="form-label" for="sp-page-note">Note</label>' +
            '<textarea class="form-control se-note" id="sp-page-note" rows="2" maxlength="500">' +
              esc(s.note || '') + '</textarea>' +
          '</div>' +
        '</div>' +
      '</div>' +
    '</div>';
  }

  /**
   * The ISP's fifth action step, which never completes.
   *
   * "Monitor and review. No specified date. Let them choose how often." So the
   * page carries a date the team types from the ISP's own timeframe and nothing
   * to tick - the tool does not get to invent an interval the document withheld.
   */
  function reviewPage(p) {
    return '<div class="sp-page" data-key="__review">' +
      '<div class="sp-page-where">The plan runs for the rest of the enrolment</div>' +
      '<h4 class="sp-page-title">Monitoring and review</h4>' +
      '<div class="sp-page-meta">' +
        (p.review_overdue_days
          ? '<span class="badge text-bg-danger">' + p.review_overdue_days +
            (p.review_overdue_days === 1 ? ' day over' : ' days over') + '</span>'
          : '') +
        (p.next_review_on ? '<span class="sp-page-when">due ' + esc(p.next_review_display) + '</span>'
                          : '<span class="sp-page-when">no date set</span>') +
      '</div>' +
      '<div class="sp-page-who"><i class="fa-solid fa-user-group me-2"></i>' +
        'Nominated Supervisor, who oversees the review meetings</div>' +
      '<p class="sp-page-detail">Reviewed on whatever timeframe the plan itself names, or sooner ' +
        'if something changes. The service or the family may ask for a review at any time, and ' +
        'educators record progress notes on the plan between reviews. There is no fixed interval ' +
        'in the process document, so this is the one the plan gives.</p>' +
      '<div class="sp-page-form">' +
        '<div class="row g-3">' +
          '<div class="col-sm-6">' +
            '<label class="form-label" for="sp-review-on">Next review</label>' +
            '<input type="date" class="form-control" id="sp-review-on" value="' +
              esc(p.next_review_on || '') + '">' +
            '<div class="form-text">Blank if the plan names no date.</div>' +
          '</div>' +
          // The day the review is booked for. Due by and booked for are two
          // dates on a step, and the review is no different.
          '<div class="col-sm-6">' + bookedField('sp-review-booked', p.review_booking, openPlan.row) + '</div>' +
          '<div class="col-12">' +
            '<button type="button" class="btn btn-primary" id="sp-review-save">Save the review date</button>' +
          '</div>' +
        '</div>' +
      '</div>' +
    '</div>';
  }

  /**
   * THE BSP'S THIRD ACTION STEP, which never completes.
   *
   * "Step 3 - Communicate and Document - The Room Leader is to document when a
   * child is displaying significant challenging behaviours while on a Behaviour
   * Support Plan." It runs alongside everything from week 1 to week 10 and there
   * is nothing to tick, which is why it was left out of the ladder and then
   * floated around the modal as a footnote nobody had a home for. As a page it
   * is simply the document's step 3, sitting where the document puts it.
   *
   * IT IS STILL NOT TRACKED, and the page says so. That has to stay sayable or
   * somebody later reads the omission as an oversight and builds forms for it.
   */
  function notePage() {
    return '<div class="sp-page" data-key="__note">' +
      '<div class="sp-page-where">Throughout, from here to the end</div>' +
      '<h4 class="sp-page-title">Communicate and document</h4>' +
      '<div class="sp-page-meta">' +
        '<span class="sp-week">not tracked here</span>' +
      '</div>' +
      '<div class="sp-page-who"><i class="fa-solid fa-user-group me-2"></i>' +
        'Room Leader, raised with the Nominated Supervisor and Area Manager</div>' +
      '<p class="sp-page-detail">' + esc(BSP_THROUGHOUT) + '</p>' +
      '<div class="sp-page-form"><p class="sp-page-detail mb-0">' +
        'There is nothing to tick here. This runs alongside every step from week 1 ' +
        'onwards and the paperwork lives in the room, so the tool does not ask for ' +
        'it - it is on this page so nobody reads its absence as an oversight.' +
      '</p></div>' +
    '</div>';
  }

  /**
   * WHY NEXT HAS STOPPED, said where there is room to say it.
   *
   * A disabled button explains nothing, and this app cannot put a tooltip on one
   * anyway - a disabled control fires no mouse events in any browser, which
   * app.js documents. So the reason sits on the page: which stage is holding the
   * way, and how much of it is left.
   */
  function gateNote(p, at) {
    var gate = furthestPage(p);
    if (at.i !== gate || gate >= at.pages.length - 1) return '';

    var keys = stageKeys(p), open = openStage(p);
    if (!keys.length) {
      return '<div class="sp-gate">Mark this step done to move on to the next one.</div>';
    }
    var steps = p.steps.filter(function (x) { return x.phase === open; });
    var left  = steps.filter(function (x) { return !x.done_on; }).length;
    var nextKey = keys[keys.indexOf(open) + 1];
    return '<div class="sp-gate">' +
      '<i class="fa-solid fa-lock me-2"></i>' +
      esc(p.phases[open].label) + ' has ' + left + (left === 1 ? ' step' : ' steps') +
      ' still to do. Finish ' + (left === 1 ? 'it' : 'them') + ' to move on to ' +
      esc(p.phases[nextKey].short) + '.' +
    '</div>';
  }

  /**
   * SAVE IS WHAT NEXT BECOMES WHEN THERE IS NOWHERE TO GO, so it carries the
   * same chevron: it is the same forward action, and the same place on the page.
   *
   * IT IS DISABLED UNTIL THIS PAGE'S STEP IS TICKED (Broc, 18 Sep 2026: it
   * "should be disabled if there are steps missing completion"). Taken literally
   * that would disable it at the gate always - the gate exists BECAUSE steps are
   * missing, so the one page you went there to record would have no working
   * control at all. What it means in practice is this step: tick the box and the
   * button lights up, which is also why it is re-evaluated live rather than only
   * on render.
   *
   * A PAGE THAT IS NOT A STEP HAS NOTHING TO SAVE. The ISP's review page carries
   * its own control and the BSP's documenting page carries none, so a nav Save
   * there was a button that quietly did nothing.
   */
  function saveButton(p, at) {
    if (at.page.kind !== 'step') return '';
    var done = at.page.step.done_on;
    return '<button type="button" class="btn btn-primary btn-sm sp-page-save"' +
      (done ? '' : ' disabled') + '>' +
      'Save<i class="fa-solid fa-chevron-right ms-1"></i></button>';
  }

  /**
   * Back, where you are, and Next - and NEXT IS THE PRIMARY ACTION.
   *
   * It commits the page on the way through, which is the wizard idiom and one
   * control instead of two: a separate "Save and continue" sitting above a
   * "Next" that also saved was two buttons for one write, and it was what pushed
   * the page past the fold.
   *
   * On the last page there is nowhere to go, so it becomes Save.
   */
  function pageNav(p, at) {
    var gate = furthestPage(p);
    // Save rather than Next at the end of the plan, and also at the gate: there
    // is nowhere to go until this stage is finished, so offering Next would be
    // a control that does nothing.
    var last = at.i >= Math.min(at.pages.length - 1, gate);
    return '<button type="button" class="btn btn-outline-secondary btn-sm sp-page-back"' +
        (at.i > 0 ? '' : ' disabled') + '>' +
        '<i class="fa-solid fa-chevron-left me-1"></i>Back</button>' +
      // "Page 11 of 17" and not "step", because two of the pages are the
      // document's recurring actions and are not steps - the head line counts
      // steps, and two counters using the same word for different totals is the
      // fault this screen keeps having to fix.
      '<span class="sp-page-of">Page ' + (at.i + 1) + ' of ' + at.pages.length + '</span>' +
      (last ? saveButton(p, at) :
        '<button type="button" class="btn btn-primary btn-sm sp-page-next">' +
          'Next<i class="fa-solid fa-chevron-right ms-1"></i></button>');
  }

  function renderPlanModal(reset) {
    var p = openPlan.plan, r = openPlan.row;
    if (!p || !r) return;
    var $body = $('#sp-plan-modal .modal-body');
    var keep  = reset ? 0 : $body.scrollTop();

    $('#sp-plan-title').text(r.name + ' - ' + p.type_label);
    // .html() rather than .text(), because a mark is markup - so every part is
    // escaped by hand here. A child's name goes through this.
    $('#sp-plan-subtitle').html((data.group ? esc(r.centre_name) + ' - ' : '') +
      roomIcon(r.room_name) + esc(r.room_name) + ' - ' + r.year);

    // WHICH PAGE IS SHOWN IS DECIDED ONCE, and `reset` does NOT decide it -
    // reset means "put the scroll back to the top", which paging also wants.
    // Tying the two together made every Back and every jump on the strip
    // immediately re-derive the live page and land back where it started.
    var at = pageAt(p);
    openPlan.at = at.i;

    $('#sp-plan-summary').html(headLine(p) + stageStrip(p, at));
    $('#sp-plan-steps').html(
      (at.page.kind === 'review' ? reviewPage(p)
        : at.page.kind === 'note' ? notePage()
        : stepPage(p, at.page.step)) + gateNote(p, at));
    $('#sp-foot-nav').html(pageNav(p, at));
    // The page IS the form, so nothing is dirty the moment it is drawn.
    formClean();


    $('#sp-open-outcome').text(p.closed ? 'Reopen this plan' : 'Record how it ended');
    showPane('plan', true);
    $body.scrollTop(keep);

  }

  function showPane(which, keepScroll) {
    $('#sp-plan-pane').toggleClass('d-none', which !== 'plan');
    $('#sp-outcome-pane').toggleClass('d-none', which !== 'outcome');
    $('#sp-foot-plan').toggleClass('d-none', which !== 'plan');
    $('#sp-foot-outcome').toggleClass('d-none', which !== 'outcome').toggleClass('d-flex', which === 'outcome');
    if (!keepScroll) $('#sp-plan-modal .modal-body').scrollTop(0);
  }

  // Click or keyboard, the same guard board.js uses for .place-chip and .rv-dh.
  $('#sp-list').on('click keydown', '.sp-plan', function (e) {
    if (e.type === 'keydown' && e.key !== 'Enter' && e.key !== ' ' && e.key !== 'Spacebar') return;
    e.preventDefault();
    var $plan = $(this);
    var r = findRow($plan.data('id'));
    if (r) showPlan(r, $plan.data('type'));
  });

  function showPlan(r, type) {
    openPlan = { id: r.placement_id, type: type, plan: r.plans[type], row: r, at: null };
    renderPlanModal(true);
    planModal = planModal || new bootstrap.Modal('#sp-plan-modal');
    planModal.show();
  }

  /**
   * Every write returns the plan it changed, so the modal re-renders from THAT
   * rather than waiting for the list.
   *
   * The list is reloaded behind it, because a tick changes what is overdue,
   * which changes the ORDER and every tile above - and a list that disagrees
   * with the thing the person just did is two answers to one question. The
   * modal stays open on top of it.
   */
  function planPost(payload, okMsg, then) {
    payload.placement_id = openPlan.id;
    payload.plan_type    = openPlan.type;
    $.post('/api/support-plans', payload)
      .done(function (res) {
        if (!res.ok) { toast(res.error, 'danger'); return; }
        openPlan.plan = res.plan;
        if (okMsg) toast(okMsg);
        if (then) then();
        renderPlanModal();
        load(true);
      })
      .fail(function () { toast('Could not save that.', 'danger'); });
  }

  // --- Moving between pages, and not losing what was typed ------------------

  // What the page was showing when it was drawn, so navigating can tell whether
  // anything was actually changed.
  var formWas = null;

  function formNow() {
    var $p = $('#sp-plan-steps .sp-page');
    var k = $p.data('key');
    if (!$p.length || k === '__review' || k === '__note') return null;
    return {
      key:  $p.data('key'),
      done: $('#sp-page-done').is(':checked') ? 1 : 0,
      on:   $('#sp-page-doneon').val() || '',
      due:  $('#sp-page-due').length ? ($('#sp-page-due').val() || '') : null,
      booked: $('#sp-page-booked').val() || '',
      note: $('#sp-page-note').val() || ''
    };
  }

  function formClean() { formWas = formNow(); }

  function formDirty() {
    var a = formWas, b = formNow();
    if (!a || !b || a.key !== b.key) return false;
    return a.done !== b.done || a.on !== b.on || a.due !== b.due ||
           a.booked !== b.booked || a.note !== b.note;
  }

  /** The page's own save, used by its buttons and by navigating away from it. */
  function savePage(then, okMsg) {
    var f = formNow();
    if (!f) { if (then) then(); return; }
    var payload = {
      action: 'step_save', step_key: f.key, done: f.done,
      done_on: f.done ? (f.on || data.today || '') : '',
      booked_on: f.booked,
      note: f.note
    };
    // Only sent when the field is on the page, so a step the process document
    // does not date cannot have a date invented for it; '' is a deliberate clear
    // back to the date the document implies.
    if (f.due !== null) payload.due_on = f.due;
    planPost(payload, okMsg || null, then);
  }

  /**
   * TYPED TEXT IS NOT THROWN AWAY BY A BACK BUTTON.
   *
   * The page is a form and paging is how you leave it, so a note somebody has
   * just written would go with them otherwise. Saving first is not magic - it is
   * their data, going where they were already sending it, and losing it is the
   * worse outcome by a distance.
   */
  function goTo(i, p) {
    var pages = planPages(p);
    if (i < 0 || i >= pages.length || i === openPlan.at) return;
    // NO SKIPPING AHEAD. Enforced here rather than only by disabling the
    // controls, so the strip and the keyboard cannot get round it either.
    if (i > furthestPage(p)) return;
    var move = function () { openPlan.at = i; renderPlanModal(true); };
    if (formDirty()) savePage(move); else move();
  }

  $('#sp-plan-modal').on('click', '.sp-page-back', function () {
    goTo(openPlan.at - 1, openPlan.plan);
  });
  $('#sp-plan-modal').on('click', '.sp-page-next', function () {
    goTo(openPlan.at + 1, openPlan.plan);
  });

  // The strip goes to a stage's first unfinished step, or its first step when
  // the stage is done - which is what somebody clicking "Weeks 6-9" means.
  $('#sp-plan-modal').on('click', '.sp-stage', function () {
    var key = $(this).data('stage'), p = openPlan.plan;
    var pages = planPages(p), first = -1, undone = -1;
    pages.forEach(function (pg, i) {
      if (pg.kind !== 'step' || pg.step.phase !== key) return;
      if (first === -1) first = i;
      if (undone === -1 && !pg.step.done_on) undone = i;
    });
    goTo(undone !== -1 ? undone : first, p);
  });

  // Done on is only meaningful once the step is done - and the Save button at
  // the end of a stage waits on the same tick, so it is re-evaluated here rather
  // than only when the page is drawn.
  $('#sp-plan-steps').on('change', '#sp-page-done', function () {
    var on = $(this).is(':checked');
    $('#sp-page-donewrap').toggleClass('d-none', !on);
    $('.sp-page-save').prop('disabled', !on);
  });

  // THE PAGE'S PRIMARY ACTION. Marking it done moves on, because that is what
  // somebody ticking the last thing they did wants next; saving a step that is
  // already done stays put, because they came here to correct something.
  /**
   * SAVE MOVES ON WHEN SAVING HAS OPENED THE WAY, which is what its chevron
   * promises. Ticking the last step of a stage is exactly what unlocks the next
   * one, so staying put would leave the reader looking at a finished page with a
   * forward arrow that did nothing. On the last page of the plan there is
   * nowhere to go and it simply saves.
   */
  $('#sp-plan-modal').on('click', '.sp-page-save', function () {
    var from = openPlan.at;
    savePage(function () {
      var p = openPlan.plan;
      if (from + 1 < planPages(p).length && from + 1 <= furthestPage(p)) {
        openPlan.at = from + 1;
      }
      renderPlanModal(true);
    });
  });

  // THE REVIEW PAGE IS ONE FORM TOO: either button saves both dates on it, so
  // booking the review cannot quietly drop a review date typed beside it.
  function saveReview(okMsg) {
    planPost({ action: 'review_save',
               next_review_on: $('#sp-review-on').val() || '',
               booked_on: $('#sp-review-booked').val() || '' }, okMsg);
  }
  $('#sp-plan-modal').on('click', '#sp-review-save', function () { saveReview('Review date saved.'); });

  // Book lights up only when the day differs from what is saved, and says
  // Clear when the box has been emptied of a saved day.
  $('#sp-plan-steps').on('input change', '.sp-booked-in', function () {
    var v = $(this).val() || '', was = $(this).attr('data-was') || '';
    $(this).siblings('.sp-book').prop('disabled', v === was).text(!v && was ? 'Clear' : 'Book');
  });
  $('#sp-plan-steps').on('click', '.sp-book', function () {
    var v = $(this).siblings('.sp-booked-in').val() || '';
    var msg = v ? 'Booked for ' + fmtDate(v) + '.' : 'Booked day cleared.';
    if ($(this).siblings('#sp-review-booked').length) saveReview(msg);
    else savePage(null, msg);
  });

  // --- How the plan ended, as a pane of the same modal -----------------------

  $('#sp-open-outcome').on('click', function () {
    if (openPlan.plan.closed) {
      planPost({ action: 'outcome_clear' }, 'Reopened.');
      return;
    }
    var r = openPlan.row, p = openPlan.plan;
    $('#sp-outcome-lead').text('This closes ' + r.name + "'s " + p.type_label +
      ' wherever it has got to. Three of these can happen at any point in the process, ' +
      'so you do not have to be at the end of the steps.');
    $('#sp-outcome-on').val(data.today || '');
    $('#sp-outcome-note').val('');

    var outcomes = (data.outcomes || {})[p.type] || {};
    $('#sp-outcome-choices').html(Object.keys(outcomes).map(function (k) {
      return '<div class="form-check mb-2">' +
        '<input class="form-check-input" type="radio" name="outcome" value="' + esc(k) +
          '" id="sp-oc-' + esc(k) + '">' +
        '<label class="form-check-label" for="sp-oc-' + esc(k) + '">' +
          esc(outcomes[k].label) +
          '<span class="d-block form-text m-0">' + esc(outcomes[k].note) + '</span>' +
        '</label>' +
      '</div>';
    }).join(''));

    // A HELD PLACE IS NOT RELEASED BY CLOSING THE PLAN. Putting a place back on
    // sale has a family at the end of it, and doing it as a side effect of
    // recording an outcome is how a place gets sold out from under somebody.
    $('#sp-outcome-held').toggleClass('d-none', !r.held_days.length).html(
      r.held_days.length
        ? '<i class="fa-solid fa-lock me-1"></i>' +
          r.held_days.map(function (d) { return DAYS[d]; }).join(', ') +
          (r.held_days.length === 1 ? ' is still held for this child' : ' are still held for this child') +
          ' and will stay held after this. Release ' +
          (r.held_days.length === 1 ? 'it' : 'them') +
          ' from the card if the place should go back on the board.'
        : '');

    showPane('outcome');
  });

  $('#sp-outcome-back').on('click', function () { showPane('plan'); });

  $('#sp-outcome-save').on('click', function () {
    var choice = $('#sp-outcome-choices input[name=outcome]:checked').val();
    if (!choice) { toast('Pick how the plan ended.', 'warning'); return; }
    planPost({
      action: 'outcome_save',
      outcome: choice,
      outcome_on: $('#sp-outcome-on').val() || '',
      outcome_note: $('#sp-outcome-note').val() || ''
    }, 'Recorded. The plan is closed and nothing on it is overdue any more.');
  });

  // --- Releasing a held day --------------------------------------------------

  // /api/board owns this, exactly as it owns recording one. Day-level: a child
  // held out of Thursday and Friday may be coming back on one of them.
  $('#sp-list').on('click', '.sp-release', function () {
    $.post('/api/board', { action: 'hold_release', id: $(this).data('id') })
      .done(function (res) {
        if (!res.ok) { toast(res.error, 'danger'); return; }
        toast('No longer held. The place is back on the board.');
        load();
      })
      .fail(function () { toast('Could not do that.', 'danger'); });
  });

  // --- Holding days ----------------------------------------------------------

  $('#sp-list').on('click', '.sp-hold', function () {
    var row = findRow($(this).data('id'));
    if (!row) return;
    holdRow = row;

    $('#sp-hold-subtitle').html(esc(row.name) + ' - ' +
      roomIcon(row.room_name) + esc(row.room_name) + ', ' + row.year +
      (row.days.length
        ? ' - currently ' + row.days.map(function (d) { return DAYS[d]; }).join(', ')
        : ' - not booked on any day here'));
    $('#sp-hold-form [name=room_id]').val(row.room_id);
    $('#sp-hold-form [name=year]').val(row.year);
    $('#sp-hold-form [name=kidsoft_child_id]').val(row.ks_id || '');
    $('#sp-hold-form [name=placement_id]').val(row.placement_id);

    // The reason and the start date belong to the ARRANGEMENT, so any one of its
    // days carries the pair - day_hold_save() writes them to all of them together.
    var first = row.holds.length ? row.holds[0] : null;
    $('#sp-hold-reason').val(first ? (first.reason || '') : '');
    // Today comes from the SERVER (Sydney), never new Date() - see _incl/dates.php.
    $('#sp-hold-since').val(first ? first.held_from : (data.today || ''));

    // Only days they are not booked on: a day they attend is already held by the
    // booking. For a child removed from care that is all five, which is the case
    // this dialog exists for.
    var offer = [1, 2, 3, 4, 5].filter(function (d) { return row.days.indexOf(d) === -1; });
    $('#sp-hold-days').html(offer.map(function (d) {
      var on = row.held_days.indexOf(d) !== -1;
      return '<input type="checkbox" class="btn-check" name="days[]" value="' + d +
        '" id="sp-hd' + d + '"' + (on ? ' checked' : '') + '>' +
        '<label class="btn btn-sm btn-outline-primary" for="sp-hd' + d + '">' + DAYS[d] + '</label>';
    }).join(''));
    $('#sp-hold-dayhelp').text(offer.length === 5
      ? 'This child is not booked on any day here, so every day can be held.'
      : 'Only days they do not already attend.');

    $('#sp-hold-clear').toggleClass('d-none', !row.held_days.length);
    holdModal = holdModal || new bootstrap.Modal('#sp-hold-modal');
    holdModal.show();
  });

  /**
   * Save, or clear.
   *
   * An EMPTY strip is a release of everything - the same statement a save makes,
   * so it goes through the same endpoint rather than a second one that could
   * disagree about what "none" means. /api/board owns the checks either way.
   */
  function holdSave(serialised) {
    $.post('/api/board', serialised + '&action=hold_save')
      .done(function (res) {
        if (!res.ok) { toast(res.error, 'danger'); return; }
        holdModal.hide();
        (res.skipped || []).forEach(function (d) {
          toast(d + ' was not held; they are booked on that day.', 'warning');
        });
        if ((res.held || []).length) {
          toast(res.held.map(function (d) { return DAYS[d]; }).join(', ') +
            ' held. Nobody else can be offered ' +
            (res.held.length === 1 ? 'that place.' : 'those places.'));
        }
        (res.released || []).forEach(function (d) {
          toast(DAYS[d] + ' is no longer held and is back on the board.', 'warning');
        });
        if (!(res.held || []).length && !(res.released || []).length) toast('Saved.');
        load();
      })
      .fail(function () { toast('Could not save that.', 'danger'); });
  }

  $('#sp-hold-form').on('submit', function (e) {
    e.preventDefault();
    holdSave($(this).serialize());
  });

  $('#sp-hold-clear').on('click', function () {
    var $f = $('#sp-hold-form');
    holdSave($.param({
      room_id: $f.find('[name=room_id]').val(),
      year: $f.find('[name=year]').val(),
      kidsoft_child_id: $f.find('[name=kidsoft_child_id]').val(),
      placement_id: $f.find('[name=placement_id]').val()
    }));
  });

  // --- The contact log -------------------------------------------------------

  $('#sp-list').on('click', '.sp-log', function () {
    var id = $(this).data('id');
    $('#sp-comm-subtitle').text($(this).data('name'));
    $('#sp-comm-form [name=placement_id]').val(id);
    commFormReset();
    commModal = commModal || new bootstrap.Modal('#sp-comm-modal');
    commModal.show();
    loadComms(id);
  });

  // EDITING A NOTE (12 Nov 2026) puts it back into the form above, which saves
  // it in place of logging a new one. Cancel, a save, or opening another
  // child's history puts the form back to logging, so the next note typed is
  // never filed over an old one.
  var commById = {};

  function commFormReset() {
    var $f = $('#sp-comm-form');
    $f.find('[name=id], [name=summary], [name=body], [name=occurred_at]').val('');
    $f.find('[name=direction]').val('outbound');
    $f.find('[name=channel]').val('phone');
    $('#sp-comm-save').text('Log contact');
    $('#sp-comm-cancel').addClass('d-none');
    $('#sp-comm-list .comm-entry').removeClass('comm-editing');
  }

  // The pencil: only on a note that never goes to Kidsoft, where a comment
  // cannot be changed. /api/comms `update` refuses anything else.
  function commEditBtn(c) {
    if (!c || !c.id || String(c.local_only) !== '1' || c.kidsoft_pushed_at) return '';
    return ' <button type="button" class="btn btn-link btn-sm p-0 ms-1 align-baseline sp-comm-edit"' +
      ' data-id="' + esc(c.id) + '" title="Edit this note" aria-label="Edit this note">' +
      '<i class="fa-solid fa-pen"></i></button>';
  }

  // Who changed it and when, on the entry itself, so an edit is never quiet.
  // The words it replaced are in the audit log.
  function commEdited(c) {
    if (!c.edited_at) return '';
    return ' &middot; edited by ' + esc(staffName(c.edited_by)) + ' ' + fmtDateTime(c.edited_at);
  }

  $('#sp-comm-list').on('click', '.sp-comm-edit', function () {
    var c = commById[$(this).attr('data-id')];
    if (!c) return;
    var $f = $('#sp-comm-form');
    $f.find('[name=id]').val(c.id);
    $f.find('[name=direction]').val(c.direction);
    $f.find('[name=channel]').val(c.channel);
    $f.find('[name=occurred_at]').val(String(c.occurred_at || '').substr(0, 10));
    $f.find('[name=summary]').val(c.summary);
    $f.find('[name=body]').val(c.body || '');
    $('#sp-comm-save').text('Save changes');
    $('#sp-comm-cancel').removeClass('d-none');
    $('#sp-comm-list .comm-entry').removeClass('comm-editing');
    $(this).closest('.comm-entry').addClass('comm-editing');
    $('#sp-comm-modal .modal-body').scrollTop(0);
    $f.find('[name=summary]').trigger('focus');
  });

  $('#sp-comm-cancel').on('click', commFormReset);


  function loadComms(placementId) {
    $('#sp-comm-list').html('<div class="text-muted small">Loading...</div>');
    $.getJSON('/api/comms', { action: 'list', placement_id: placementId })
      .done(function (res) {
        if (!res.ok) {
          $('#sp-comm-list').html('<div class="text-danger small">' + esc(res.error) + '</div>');
          return;
        }
        // SAID BEFORE THE NOTE IS TYPED, not after it is saved. Notes from this
        // screen deliberately do not travel to Kidsoft (Broc, 17 Sep 2026):
        // createComment has no update and no delete, and behaviour or diagnosis
        // material written permanently onto a child's record is a different risk
        // from an ordinary contact note. A staff member who assumed it travelled
        // would stop keeping the record they still need there.
        $('#sp-comm-note').removeClass('d-none').html(
          '<i class="fa-solid fa-circle-info text-muted me-1"></i>' +
          'Notes logged here stay in this tool and are not copied to Kidsoft. ' +
          'A Kidsoft comment cannot be edited or deleted once it is written, so ' +
          'support plan notes are kept where they can be corrected.');

        commById = {};
        if (!res.entries.length) {
          $('#sp-comm-list').html('<div class="text-muted small">No contact logged yet.</div>');
        } else {
          $('#sp-comm-list').html(res.entries.map(function (c) {
            commById[c.id] = c;
            var dirCls = c.direction === 'inbound' ? 'border-info'
                       : (c.direction === 'internal' ? 'border-secondary' : 'border-primary');
            return '<div class="comm-entry ' + dirCls +
                (String(c.id) === $('#sp-comm-form [name=id]').val() ? ' comm-editing' : '') + '">' +
              '<div class="d-flex justify-content-between align-items-start gap-2">' +
                '<div class="fw-semibold"><i class="' +
                  channelIcon(c.channel) + ' me-2 text-muted"></i>' +
                  esc(c.summary) + '</div>' +
                '<div class="small text-muted text-nowrap">' + fmtDateTime(c.occurred_at) + '</div>' +
              '</div>' +
              (c.body ? '<div class="small mt-1">' + esc(c.body).replace(/\n/g, '<br>') + '</div>' : '') +
              '<div class="small text-muted mt-1">' + esc(res.directions[c.direction] || c.direction) +
                ' &middot; ' + esc(c.staff_email) + commEdited(c) + copyTag(c, true) +
                commEditBtn(c) + commDeleteBtn(c) + '</div>' +
            '</div>';
          }).join(''));
        }

        // Kidsoft's own comments, alongside and never merged in - they are
        // mostly enrolment form capture, and merging would bury what the team
        // actually did behind what a form recorded.
        // Markup and the clamp rule are window.kidsoftComments() in app.js since
        // 18 Sep 2026, when the work modal became the third surface to show this
        // block. One Kidsoft comment, one description.
        var ks = res.kidsoft || [];
        $('#sp-comm-kidsoft').toggleClass('d-none', !ks.length);
        $('#sp-comm-kidsoft-list').html(kidsoftComments(ks));
      })
      .fail(function () {
        $('#sp-comm-list').html('<div class="text-danger small">Could not load history.</div>');
      });
  }

  // An administrator deleted a note - commDeleteBtn() in app.js. The card's
  // "days since contact" may have moved too, as after a note is logged.
  $('#sp-comm-list').on('comm:deleted', function () {
    loadComms($('#sp-comm-form [name=placement_id]').val());
    load();
  });

  // The .ks-more toggle is bound ONCE on the document in app.js. A second
  // handler here would fire on the same click and toggle the clamp straight
  // back - a bug that arrives silently the moment a shared component grows a
  // shared handler, so the local one had to go with the local markup.

  $('#sp-comm-form').on('submit', function (e) {
    e.preventDefault();
    var id = $('#sp-comm-form [name=placement_id]').val();
    var editing = !!$('#sp-comm-form [name=id]').val();
    var $btn = $(this).find('[type=submit]').prop('disabled', true);
    $.post('/api/comms', $(this).serialize() + '&action=' + (editing ? 'update' : 'add'))
      .done(function (res) {
        if (!res.ok) { toast(res.error, 'danger'); return; }
        if (res.warning) toast(res.warning, 'warning');
        else toast(editing ? (res.unchanged ? 'Nothing changed.' : 'Saved.') : 'Logged.');
        if (editing) commFormReset();
        else $('#sp-comm-form').find('[name=summary], [name=body]').val('');
        loadComms(id);
        // The card's "days since contact" is now wrong, and it is part of the
        // ordering of the list.
        load();
      })
      .fail(function () { toast('Could not log the contact.', 'danger'); })
      .always(function () { $btn.prop('disabled', false); });
  });

  // --- The printed report (support-plans-report.php) -------------------------

  /* THE CARD, WITH EVERYTHING BEHIND IT LAID OUT FLAT, because paper has no
     clicks. The plan's facts, its badges and the family's silence are drawn by
     the SAME functions the card uses - planFacts(), planBadges(), quiet() - so a
     printed page and the screen cannot describe one plan in two vocabularies.
     What the card cannot hold is what the report adds: every step with its due
     date, who ticked it and its note in full, and every contact logged.

     The rows arrive in support_plans_list()'s order and are NOT re-sorted, the
     rule the list itself follows: most overdue first. */

  function report() {
    var pid = parseInt($('#init-placement').val(), 10) || 0;
    $.getJSON('/api/support-plans', pid
      ? { action: 'report', placement_id: pid }
      : { action: 'report', centre_id: CENTRE })
      .done(function (res) {
        if (!res.ok) {
          $('#spr-report').html('<div class="alert alert-danger">' + esc(res.error) + '</div>');
          return;
        }
        // renderNotices() reads `data` - the same Kidsoft banner the screen
        // shows, because whether the days can be trusted matters MORE on paper.
        data = res;
        renderNotices();
        $('#spr-scope').text(res.scope);
        $('#spr-printed').html('As at ' + esc(res.printed) +
          '<br>Printed by ' + esc(res.printed_by));
        $('#spr-report').html(
          (res.single ? '' : reportSummary(res)) +
          res.rows.map(function (r) { return reportChild(r, res); }).join(''));
        $('#spr-print').prop('disabled', !res.rows.length);
      })
      .fail(function () {
        $('#spr-report').html('<div class="alert alert-danger">Could not load the report.</div>');
      });
  }

  $('#spr-print').on('click', function () { window.print(); });

  /**
   * PAGE ONE OF A WHOLE TAB: the tiles as figures, then one line per child.
   * The figures carry the tiles' own labels and sentences - one fact, one
   * wording - and nothing is counted here that the server did not count.
   */
  function reportSummary(res) {
    if (!res.rows.length) {
      return '<p class="text-muted mb-0">Nobody is on a support plan here.</p>';
    }
    var s = res.summary;
    var mix = [];
    if (s.bsp) mix.push(s.bsp + ' on a behaviour plan');
    if (s.isp) mix.push(s.isp + ' on an individual plan');
    if (mix.length === 1) mix = [];

    var figures = statStrip([
      { label: 'On a plan', value: s.children,
        hint: mix.length ? mix.join(', ') : (s.children === 1 ? 'child' : 'children') },
      { label: 'Behind', value: s.overdue, cls: s.overdue ? 'text-danger' : '',
        hint: s.overdue_steps + (s.overdue_steps === 1 ? ' step overdue' : ' steps overdue') },
      { label: 'Place-days held', value: s.held_days, hint: 'out of the free figures on the board' },
      { label: 'Never contacted', value: s.never, cls: s.never ? 'text-danger' : '',
        hint: 'no call, email or note logged at all' },
      { label: 'Gone quiet', value: s.quiet, hint: 'nothing logged for 14 days' }
    ]);

    var rows = res.rows.map(function (r) {
      var plans = ['bsp', 'isp'].filter(function (t) { return r.plans[t]; })
        .map(function (t) { return r.plans[t]; });
      return '<tr>' +
        '<td><div class="spr-strong">' + esc(r.name) + '</div>' +
          '<div class="spr-sub">' + (res.group ? esc(r.centre_name) + ' &middot; ' : '') +
            roomIcon(r.room_name) + esc(r.room_name) + ' &middot; ' + r.year + '</div></td>' +
        '<td>' + plans.map(function (p) {
          return '<div class="spr-line"><span class="spr-strong">' + esc(p.type_label) +
            '</span><br>' + planBadges(p) + '</div>';
        }).join('') + '</td>' +
        '<td>' + plans.map(function (p) {
          return '<div class="spr-line">' + (p.closed
            ? 'Ended ' + esc(p.outcome_display || '') + ': ' + esc(p.outcome_label)
            : nextFact(p)) + '</div>';
        }).join('') + '</td>' +
        '<td>' + quiet(r) + (r.last_contact
          ? '<div class="spr-sub">' + esc(r.last_contact.when) + '</div>' : '') + '</td>' +
      '</tr>';
    }).join('');

    return '<section class="spr-summary">' +
      figures +
      '<table class="table table-sm spr-table mt-3">' +
        '<thead><tr><th>Child</th><th>Plan</th><th>Next</th><th>Last contact</th></tr></thead>' +
        '<tbody>' + rows + '</tbody>' +
      '</table>' +
    '</section>';
  }

  /** One child: where they are, their week, each plan in full, and the contact trail. */
  function reportChild(r, res) {
    var plans = '';
    ['bsp', 'isp'].forEach(function (t) { if (r.plans[t]) plans += reportPlan(r.plans[t]); });
    return '<section class="spr-child">' +
      '<div class="spr-child-head">' +
        '<div>' +
          '<h2 class="spr-name">' + esc(r.name) + '</h2>' +
          // THE CENTRE EVEN ON ONE CENTRE'S REPORT. A printed page gets
          // separated from the pack it came in, and then it must still say where
          // the child is.
          '<div class="spr-sub">' + esc(r.centre_name) + ' &middot; ' +
            roomIcon(r.room_name) + esc(r.room_name) + ' &middot; ' + r.year + '</div>' +
        '</div>' +
        '<div>' + quiet(r) + '</div>' +
      '</div>' +
      reportDays(r) +
      (r.note ? '<div class="wish-note small mb-3">' + esc(r.note) + '</div>' : '') +
      plans +
      reportHistory(r, res) +
    '</section>';
  }

  /**
   * THE WEEK IN WORDS, NOT ONLY IN COLOUR. The screen's day strip says attends
   * and held with a green and an amber box, and a sheet that goes through a
   * black and white printer loses exactly that. Same colours, plus the word.
   * Null is still "Kidsoft would not say", never "attends nothing".
   */
  function reportDays(r) {
    if (r.days === null) {
      return '<p class="text-muted small">Kidsoft would not say which days this child attends.</p>';
    }
    var cells = [1, 2, 3, 4, 5].map(function (d) {
      if (r.days.indexOf(d) !== -1) return '<td class="spr-day spr-day-on">Attends</td>';
      if (r.held_days.indexOf(d) !== -1) {
        return '<td class="spr-day spr-day-held"><i class="fa-solid fa-lock me-1"></i>Held</td>';
      }
      return '<td class="spr-day spr-day-off">Not booked</td>';
    }).join('');
    var held = r.holds.map(function (h) {
      return '<li><span class="spr-strong">' + DAYS[h.weekday] + '</span> held since ' +
        esc(h.held_from_display) + (h.reason ? ': ' + esc(h.reason) : '') + '</li>';
    }).join('');
    return '<table class="spr-days"><thead><tr>' +
        [1, 2, 3, 4, 5].map(function (d) { return '<th>' + DAYS[d] + '</th>'; }).join('') +
      '</tr></thead><tbody><tr>' + cells + '</tr></tbody></table>' +
      (held ? '<ul class="spr-held">' + held + '</ul>' : '');
  }

  /**
   * ONE PLAN, EVERY STEP. The modal shows one step to a page; paper shows the
   * ladder whole, under the stage headings the modal's strip names. The week sits
   * with its due date, because an ISP has neither and a column of dashes is a
   * column saying nothing.
   *
   * A NOTE IS A LINE OF ITS OWN UNDER ITS STEP, not a column. As a column, one
   * 500 character note made the table give it the width and folded every step
   * name in the plan onto three or four lines - and most steps have no note, so
   * the column was empty down most of the page.
   */
  function reportPlan(p) {
    var phase = null;
    var rows = p.steps.map(function (s, i) {
      var head = '';
      if (s.phase && s.phase !== phase && p.phases[s.phase]) {
        phase = s.phase;
        head = '<tr class="spr-stage"><th colspan="4">' + esc(p.phases[s.phase].label) + '</th></tr>';
      }
      var due = (s.week ? '<div>Week ' + s.week + '</div>' : '') +
        (s.due_display
          ? esc(s.due_display) + (s.due_override ? ' <span class="spr-sub">(set by hand)</span>' : '')
          : (s.week ? '' : '<span class="sp-fact-dim">--</span>'));
      var done = s.done_on
        ? '<i class="fa-solid fa-check spr-tick me-1"></i>' + esc(s.done_display) +
          (s.done_by ? '<div class="spr-sub">' + esc(staffName(s.done_by)) + '</div>' : '')
        : (s.overdue_days
            ? '<span class="sp-fact-late">' + s.overdue_days +
              (s.overdue_days === 1 ? ' day over' : ' days over') + '</span>'
            : '<span class="sp-fact-dim">not yet</span>');
      // ONE <tbody> PER STEP, so the step, its note and the stage heading above
      // it are kept on one page: printed, a note had landed on the page after
      // the step it belonged to.
      return '<tbody>' + head + '<tr' + (s.note ? ' class="spr-has-note"' : '') + '>' +
        '<td class="spr-n">' + (i + 1) + '</td>' +
        '<td><div class="spr-strong">' + esc(s.title) + '</div>' +
          '<div class="spr-sub">' + esc(s.who) + '</div></td>' +
        '<td class="spr-date">' + due + '</td>' +
        '<td class="spr-date">' + done + '</td>' +
      '</tr>' +
      (s.note
        ? '<tr class="spr-note-row"><td></td><td class="spr-text" colspan="3">' +
            esc(s.note).replace(/\n/g, '<br>') + '</td></tr>'
        : '') + '</tbody>';
    }).join('');

    // The outcome and its date are already in the facts above; this adds only
    // who recorded it and what they wrote.
    var ended = p.closed && (p.outcome_by || p.outcome_note)
      ? '<p class="small mb-2">' +
          (p.outcome_by ? 'Recorded by ' + esc(staffName(p.outcome_by)) + '. ' : '') +
          (p.outcome_note ? esc(p.outcome_note) : '') + '</p>'
      : '';

    return '<div class="spr-plan">' +
      '<div class="spr-plan-head">' +
        '<i class="fa-solid ' + PLAN_ICON[p.type] + ' text-muted"></i>' +
        '<h3 class="spr-h">' + esc(p.type_label) + '</h3>' +
        planBadges(p) +
      '</div>' +
      planFacts(p) +
      ended +
      '<table class="table table-sm spr-table spr-steps">' +
        '<thead><tr><th class="spr-n">#</th><th>Step</th><th class="spr-w-date">Due</th>' +
          '<th class="spr-w-date">Done</th></tr></thead>' +
        rows +
      '</table>' +
    '</div>';
  }

  /**
   * EVERY CONTACT LOGGED FOR THE FAMILY, OLDEST FIRST - the card shows only the
   * latest. Read top to bottom, a printed history is a trail, and a trail that
   * is not in date order is not one.
   */
  function reportHistory(r, res) {
    var h = '<h3 class="spr-h mt-3">Contact with the family</h3>';
    if (!r.history.length) {
      return h + '<p class="text-muted small mb-0">No call, email or note has ever been logged for this family.</p>';
    }
    return h + '<table class="table table-sm spr-table">' +
      '<thead><tr><th class="spr-w-date">When</th><th class="spr-w-date">How</th><th>What</th>' +
        '<th class="spr-w-by">By</th></tr></thead><tbody>' +
      r.history.map(function (c) {
        return '<tr>' +
          '<td class="spr-date">' + esc(c.when) + '</td>' +
          '<td class="spr-date"><i class="' + channelIcon(c.channel) + ' me-1 text-muted"></i>' +
            esc(res.channels[c.channel] || c.channel) +
            '<div class="spr-sub">' + esc(res.directions[c.direction] || c.direction) + '</div></td>' +
          '<td class="spr-text"><div class="spr-strong">' + esc(c.summary) + '</div>' +
            (c.body ? '<div>' + esc(c.body).replace(/\n/g, '<br>') + '</div>' : '') + '</td>' +
          '<td>' + esc(staffName(c.by)) +
            (c.edited ? '<div class="spr-sub">edited by ' + esc(staffName(c.edited_by)) +
              ' ' + esc(c.edited) + '</div>' : '') + '</td>' +
        '</tr>';
      }).join('') + '</tbody></table>';
  }

  // ONE SCRIPT, TWO PAGES. The report reuses this file's renderers, so it loads
  // this file - and here, after every var above has been assigned, it picks
  // which page it is on.
  if ($('#spr-report').length) report(); else load();
})(jQuery);
