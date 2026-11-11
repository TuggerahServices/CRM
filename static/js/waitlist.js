// Waitlist queue and offers.
//
// TWO VIEWS, ONE FILE, and which one renders was decided on the server: the
// centre bar is made of links, so waitlist.php already knows whether this is a
// centre's queue or the group register and emits only that view's markup. The
// group tab has no application editor, no offer dialog and no contact log --
// every one of those edits a single application, which belongs to a centre --
// so the whole of the centre code below is skipped rather than left binding
// handlers to elements that are not there.

(function ($) {
  'use strict';

  var DAYS = { 1: 'Mon', 2: 'Tue', 3: 'Wed', 4: 'Thu', 5: 'Fri' };
  var searchTimer = null;

  // Chosen in the centre bar in the chrome, which is links, so fixed per page.
  // 0 = the whole-organisation register.
  var CENTRE = parseInt($('#init-centre').val(), 10);
  function centreId() { return CENTRE; }

  // --- The filter bar, shared by both views ---------------------------------
  // The boxes live in the chrome above the table and both views read them the
  // same way, so the reading and the binding are written once. A blank box is
  // sent as an empty string and the server treats that as NO BOUND - see
  // waitlist_age_bounds(); do not default either of them to 0 here.

  // --- Refresh, shared by both views ----------------------------------------
  //
  // An OVERRIDE of the 15-minute import throttle, not the way the queue stays
  // current: opening this screen already imports live and the nightly covers
  // every centre. This is for "I archived that family in Kidsoft a minute ago
  // and I want it gone from here now".
  //
  // IT ALWAYS SAYS WHAT IT FOUND, including when that is nothing. The commonest
  // honest answer is no change, and a button that looks identical before and
  // after gets pressed five more times. The table is only redrawn when something
  // actually moved, so a no-op press does not throw away the filters in force.
  function bindRefresh(load) {
    var $b = $('#refresh-queue');
    var $icon = $b.find('i');
    $b.on('click', function () {
      // Disabled for the duration: a live read is 0.4s per centre and the group
      // tab does seven, which is long enough to press twice.
      $b.prop('disabled', true);
      $icon.addClass('fa-spin');
      $.post('/api/waitlist', { action: 'refresh', centre_id: centreId() })
        .done(function (res) {
          // res.ok, not .fail() - nginx discards the body of a real 4xx under
          // /api/, so a readable failure arrives here as HTTP 200 + ok:false.
          if (!res.ok) { toast(res.error, 'danger'); return; }
          toast(res.message);
          if (res.changed) load();
        })
        .fail(function () { toast('Could not reach Kidsoft.', 'danger'); })
        .always(function () {
          $b.prop('disabled', false);
          $icon.removeClass('fa-spin');
        });
    });
  }

  /**
   * Which unit the filter is in: 'dob' (birth date, the default) or 'age'
   * (months today). Defaults to DOB when the select is missing or empty, which
   * is the state the markup ships in.
   */
  function ageMode() {
    return $('#age-mode').val() === 'age' ? 'age' : 'dob';
  }

  /**
   * The range boxes as query parameters.
   *
   * ONLY THE PAIR IN FORCE IS SENT. The hidden pair keeps whatever was typed in
   * it so switching back does not lose it, and a filter you cannot see must not
   * be narrowing the table - the server applies every bound it is given.
   */
  function ageParams() {
    if (ageMode() !== 'dob') return { age_min: $('#age-min').val(), age_max: $('#age-max').val() };
    var d = datePair('#dob-from', '#dob-to');
    return { dob_from: d[0], dob_to: d[1] };
  }

  /**
   * Two date boxes as [earlier, later], WHICHEVER WAY ROUND THEY WERE TYPED.
   *
   * THE BOXES USED TO BOUND EACH OTHER WITH min / max, AND CHROMIUM TURNED THAT
   * INTO A LOCK (Point Clare, 11 Nov 2026). Chromium will not take a year typed
   * outside the box's min / max, so the field blanked itself. That broke three
   * everyday entries: "1/1/2024 to 31/12/2022" for the two-year-olds (the
   * younger end first, which is how anybody thinking in ages types it), moving
   * a 2022 DOB range on to 2023, and moving "This year" on to 2027 in the
   * start-date boxes. Each time the table filtered on the first box alone and
   * looked like it worked. The server has always swapped a reversed pair
   * (waitlist_age_bounds(), waitlist_start_range()), so the order is
   * normalised here as well: the URL, the API and the Waiting tile's caption
   * all get the range in force, and the boxes keep what was typed.
   *
   * A box the browser cannot read (half-typed) is blank, which is no bound at
   * that end. dobBoxesValid() / startBoxesValid() have already marked it red.
   */
  function datePair(a, b) {
    var val = function (sel) { var el = $(sel)[0]; return el && el.checkValidity() ? $(el).val() : ''; };
    var x = val(a), y = val(b);
    return (x && y && x > y) ? [y, x] : [x, y];
  }

  /** Is a range in force? Decides which empty state the table shows. */
  function ageActive() {
    var p = ageParams();
    return Object.keys(p).some(function (k) { return p[k] !== ''; });
  }

  /**
   * The empty state for a range that matched nobody, in whichever unit is in
   * force - the hint has to name the boxes the reader is looking at.
   */
  function ageEmptyState() {
    return ageMode() === 'dob'
      ? emptyState('fa-magnifying-glass', 'Nobody waiting was born in that range',
                   'Dates are the child\'s date of birth. Widen the range or clear it.')
      : emptyState('fa-magnifying-glass', 'Nobody waiting in that age range',
                   'Ages are in months, as at today. Widen the range or clear it.');
  }

  /** Show the boxes for the unit chosen, hide the other pair. */
  function syncAgeMode() {
    var dob = ageMode() === 'dob';
    $('.age-box').toggleClass('d-none', dob);
    $('.dob-box').toggleClass('d-none', !dob);
    // Bootstrap rounds the FIRST and LAST CHILD of an input group, and a hidden
    // box is still a child - so without this the group ends in a square corner
    // butted against the Open/All buttons, in whichever mode is not last in the
    // markup. .rounded-end is a utility, not a custom rule; the left end never
    // moves, because the dropdown is always first.
    $('#age-filter > *').removeClass('rounded-end')
      .filter(':not(.d-none)').last().addClass('rounded-end');
  }

  /**
   * Are both date boxes acceptable? Marks the offender and says so.
   *
   * checkValidity() catches a half-typed date the browser cannot make sense of
   * (badInput). A crossed range is NOT an error - see datePair(). A box that
   * fails keeps its red border until it is fixed, and the table is left showing
   * the last good answer rather than emptying under somebody mid-keystroke.
   */
  function dobBoxesValid() {
    var ok = true;
    $('#dob-from, #dob-to').each(function () {
      var good = this.checkValidity();
      $(this).toggleClass('is-invalid', !good);
      if (!good) ok = false;
    });
    return ok;
  }

  /**
   * Is the table showing less than the whole queue? Read by the tiles.
   *
   * "All" is NOT a filter - it is a superset of the open queue, and every tile
   * counts a state ("Waiting" counts `waitlisted`) that the extra closed rows
   * cannot add to, so the figures are identical either way. "Offer expired" is:
   * it shows closed rows ONLY, so "Waiting 0" would otherwise be captioned
   * "families in the queue" and say something false about the centre.
   */
  function filtersActive() {
    // THE START-DATE RANGE IS NOT IN HERE. It gets a caption of its own on the
    // Waiting tile, naming the range ("families starting next year") rather
    // than the vague "matching the filters".
    return !!$('#search').val() || ageActive() ||
           $('#closed-filter input:checked').val() === 'offer_expired';
  }

  /**
   * The start-date range as query parameters. A box the browser cannot read
   * (half-typed, or crossed with the other) is sent blank rather than wrong -
   * startBoxesValid() has already marked it red.
   */
  function startParams() {
    var s = datePair('#start-from', '#start-to');
    return { start_from: s[0], start_to: s[1] };
  }

  /** Is a start-date range narrowing the list? Named by the Waiting tile. */
  function startsNarrowed() {
    var p = startParams();
    return !!(p.start_from || p.start_to);
  }

  /** "next year", "1/03/2027 to 30/06/2027", "from 1/03/2027" - for the tile. */
  function startsCaption() {
    var p = startParams();
    var preset = $('#start-preset').val();
    if (preset === 'this' || preset === 'next') {
      return $('#start-preset option:selected').text().toLowerCase();
    }
    if (p.start_from && p.start_to) return fmtDate(p.start_from) + ' to ' + fmtDate(p.start_to);
    return p.start_from ? 'from ' + fmtDate(p.start_from) : 'up to ' + fmtDate(p.start_to);
  }

  /** Same rule as dobBoxesValid(): mark the offender, refuse to search on it. */
  function startBoxesValid() {
    var ok = true;
    $('#start-from, #start-to').each(function () {
      var good = this.checkValidity();
      $(this).toggleClass('is-invalid', !good);
      if (!good) ok = false;
    });
    return ok;
  }

  /**
   * Point the dropdown at whatever the boxes now hold: a preset when they are
   * exactly its two dates, "Any" when both are blank, "Choose dates" otherwise.
   * So the dropdown never claims a range that is not the one in force.
   */
  function syncStartPreset() {
    var p = startParams(), from = p.start_from, to = p.start_to;
    var pick = (!from && !to) ? 'any' : 'dates';
    $('#start-preset option').each(function () {
      if ($(this).data('from') && $(this).data('from') === from && $(this).data('to') === to) {
        pick = this.value;
      }
    });
    $('#start-preset').val(pick);
  }

  /**
   * Put the filters in force into the URL, and into the centre bar's links.
   *
   * WHY THE SECOND HALF. The centre tabs are ordinary server-rendered links
   * built at page load (they have to be - see layout_centre_bar), so they carry
   * the filters the page LOADED with. Without rewriting them, a filter chosen
   * here would be dropped by the very next centre switch, which is the whole
   * complaint this answers.
   *
   * replaceState, not pushState: a filter is a view of one list, not a step to
   * go back through. A DEFAULT IS DELETED RATHER THAN WRITTEN, so a plain
   * /waitlist stays plain and the URL names only what is actually narrowing the
   * table. Keys this does not own - `centre`, `q`, `wl` - are left exactly as
   * they are; the search box is not a filter and keeps its own meaning.
   *
   * Only the age pair IN FORCE is written, matching what is sent to the server:
   * a bound nobody can see must not be narrowing the table.
   */
  function syncUrl() {
    var vals = {
      show:     $('#closed-filter input:checked').val(),
      multi:    $('#spread-filter input:checked').val(),
      age_mode: ageMode() === 'age' ? 'age' : '',
      dob_from: '', dob_to: '', age_min: '', age_max: ''
    };
    // CARRIED EVEN WHERE IT CANNOT BE SHOWN. "2+ centres" is a register control,
    // so on a centre tab there is no radio to read - the URL is then the only
    // place the value survives a there-and-back through a centre.
    if (vals.multi === undefined) {
      vals.multi = new URLSearchParams(window.location.search).get('multi') || '0';
    }
    $.extend(vals, ageParams(), startParams());
    // An address from before 6 Nov 2026 can still carry ?starts=; it means
    // nothing now, so it is dropped rather than carried along.
    vals.starts = '';

    var DEFAULTS = { show: 'open', multi: '0', age_mode: '' };
    var set = function (u) {
      $.each(vals, function (k, v) {
        if (!v || v === DEFAULTS[k]) u.searchParams.delete(k);
        else u.searchParams.set(k, v);
      });
      return u.pathname + u.search;
    };
    window.history.replaceState(null, '', set(new URL(window.location.href)));
    $('.centre-tabs a').each(function () {
      this.href = set(new URL(this.href, window.location.origin));
    });
  }

  /**
   * Bind every filter control to a view's load(). #spread-filter exists only on
   * the group tab and the age boxes on both; a selector that matches nothing is
   * a no-op, which is what lets one function serve both views.
   */
  function bindFilters(load) {
    $('#closed-filter input, #spread-filter input').on('change', load);
    // The dropdown FILLS the date boxes; the boxes are the filter. "Choose
    // dates" keeps whatever is in them and puts the cursor in the first one.
    $('#start-preset').on('change', function () {
      var $o = $(this).find('option:selected');
      if (this.value === 'any') {
        $('#start-from, #start-to').val('');
      } else if (this.value === 'dates') {
        $('#start-from').trigger('focus');
        return;
      } else {
        $('#start-from').val($o.data('from'));
        $('#start-to').val($o.data('to'));
      }
      startBoxesValid();
      clearTimeout(searchTimer);
      load();
    });
    // The unit swap reloads immediately - it is a click, not typing, so there is
    // nothing to debounce and a 250ms pause after it just looks broken.
    $('#age-mode').on('change', function () { syncAgeMode(); load(); });
    function queue() {
      clearTimeout(searchTimer);
      searchTimer = setTimeout(load, 250);
    }
    $('#search, #age-min, #age-max').on('input', queue);
    dateBoxes('#dob-from', '#dob-to', dobBoxesValid);
    dateBoxes('#start-from', '#start-to', startBoxesValid, syncStartPreset);
    syncAgeMode();

    /**
     * Watch one pair of date boxes: re-mark them, and reload when the range in
     * force has changed.
     *
     * 'input' AND 'change' ARE NOT ENOUGH, AND THAT LEFT A FILTER NOBODY COULD
     * SEE NARROWING THE TABLE (Point Clare, 11 Nov 2026). Chromium fires them
     * only when the box's VALUE changes, and a date box's value goes blank the
     * moment ONE part of it is cleared. So clearing 31/12/2023 a part at a time
     * fired once - on the first Backspace, half-cleared, red and refused - and
     * never again: the box ended up empty and still red, and the table stayed
     * filtered on the date that had been in it ("Waiting 4" under two blank
     * boxes). keyup catches each part being cleared or typed, and focusout
     * anything else that edits a part without a key.
     *
     * Which is why it reloads only when the range ACTUALLY differs from the
     * last one this pair loaded: keyup also fires for Tab and the arrow keys
     * moving between the parts. `sent` moves when the load fires, not when it
     * is queued, so a load cancelled by a half-typed date cannot leave it
     * claiming a range the table never got. Another control's load that happens
     * to carry these dates leaves it behind, which costs one spare reload, never
     * a stale table.
     *
     * 'change' stays, because a date picked out of the calendar does not always
     * arrive as an 'input'. A half-typed date is not sent at all (clearTimeout,
     * or a load queued by the keystroke BEFORE the one that broke it still fires
     * 250ms later); a crossed range is, the right way round (datePair()).
     */
    function dateBoxes(from, to, valid, also) {
      var key = function () { return datePair(from, to).join('|'); };
      var sent = key();
      $(from + ', ' + to).on('input change keyup focusout', function () {
        if (also) also();
        if (!valid()) { clearTimeout(searchTimer); return; }
        if (key() === sent) return;
        clearTimeout(searchTimer);
        searchTimer = setTimeout(function () { sent = key(); load(); }, 250);
      });
    }
  }

  /**
   * The Mon-Fri strip. The BOXES are window.dayStrip() and are the same five on
   * every screen; what belongs here is which of its states each day falls in.
   *
   * FOUR STATES SINCE 17 Aug 2026, and the split between the two green ones is
   * the whole correctness of this cell. `have` is the days the child ALREADY
   * HOLDS at this centre (Kidsoft bookings, casuals excluded - see
   * enrolled_days_index()):
   *
   *   green FILL   asked for, and already theirs - nothing to find    -> has
   *   blue         asked for, and NOT theirs - the demand             -> want
   *   green EDGE   theirs, and NOT on this application                -> extra
   *   grey         neither
   *
   * WHY THE RING EXISTS, found by Broc on Aleigha Grant at Tuggerah: the first
   * version drew every day they attend in the same green, so her Friday - which
   * she attends and **did not ask for** - was indistinguishable from the Monday
   * she asked for and already has. The column is headed "Days wanted", and it was
   * showing a day nobody had wanted, so the row said Mon/Tue/Wed/Fri while the
   * modal's Days requested ticked Mon/Tue/Wed/Thu. **The rule that fixes it and
   * must hold: green fill + blue is EXACTLY the set the application asked for**,
   * so the strip and the modal can never disagree again. The edge adds what they
   * hold on top without joining that set.
   *
   * (It was green fill + PURPLE until 3 Sep 2026. The demand moved to the blue
   * the rest of the app already used for "asked for this day"; the rule is
   * unchanged, only the hue.)
   *
   * Only the centre's OWN bookings are coloured. A day held at another Little
   * Miracles centre is a transfer, and that day is still a place this centre has
   * to sell - see enrolled_days_index().
   */
  function dayBadges(days, have) {
    have = have || [];
    // THE CALLER SPLITS THE THREE SETS, because only this screen has an
    // application to compare attendance against - see window.dayStrip().
    return dayStrip({
      wide:  true,
      has:   days.filter(function (d) { return have.indexOf(d) !== -1; }),
      want:  days.filter(function (d) { return have.indexOf(d) === -1; }),
      extra: have.filter(function (d) { return days.indexOf(d) === -1; })
    });
  }

  /** Guardian name, then the phone as a tel: link, then the email. */
  function guardianCell(name, phone, email) {
    return (name ? esc(name) : '<span class="text-muted">-</span>') +
      (phone
        ? '<div class="small"><a href="tel:' + esc(phone.replace(/[^0-9+]/g, '')) + '">' +
          '<i class="fa-solid fa-phone fa-xs me-1"></i>' + esc(phone) + '</a></div>'
        : '') +
      // THE CAP IS PER TABLE, hence a class rather than an inline width. This
      // function is shared by the centre queue and the group register, whose
      // Guardian columns are 9rem and 12rem, and a truncated line with a
      // white-space:nowrap box sets its column's floor - so one number either
      // squeezes the wide table or blows out the narrow one. It was 14rem for
      // both, which is what made the centre table's Guardian column 240px wide.
      // See .wl-email in app.css. The full address is in the title either way,
      // and the phone is what this screen is for (a mobile on 99% of
      // applications against an email on 38%).
      //
      // A mailto: LINK with an envelope, matching the phone above it: both are
      // ways of reaching this family and only one of them used to be reachable
      // from here - an address you have to select and copy out is one somebody
      // retypes into Outlook with a digit wrong. It costs nothing in width, since
      // the cap and the truncation already set this box's floor. The icon is what
      // tells the two lines apart now that neither is plain grey text.
      (email
        ? '<div class="small text-truncate wl-email" title="' + esc(email) + '">' +
          '<a href="mailto:' + esc(email) + '">' +
          '<i class="fa-solid fa-envelope fa-xs me-1"></i>' + esc(email) + '</a></div>'
        : '');
  }

  // The group register is a different screen with different questions, so it
  // gets its own everything below this point and the centre code never runs.
  if (!CENTRE) { initGroup(); return; }

  var respondModal = new bootstrap.Modal('#respond-modal');
  var $table = $('#wl-table tbody');
  var data = null;

  /**
   * An open application added inside the last data.new_days days.
   *
   * REPLACED "EXPIRING SOON" (11 Nov 2026): Kidsoft's waitlist expiry is turned
   * off, so a waiting application no longer has a lapse date - see
   * WAITLIST_NEW_DAYS. data.new_since is a Sydney date from the server and
   * date_added is a plain yyyy-mm-dd, so this is a string comparison and
   * nobody's browser timezone comes into it.
   */
  function isNew(w) {
    return !!w.date_added && w.date_added >= data.new_since &&
           ['placed', 'withdrawn', 'expired'].indexOf(w.status) === -1;
  }

  /** The "new" tag under the date added: where the tile's count is, in the table. */
  function newLine(w) {
    return isNew(w) ? '<div class="small text-primary fw-semibold" title="Added in the last ' +
                      data.new_days + ' days">new</div>' : '';
  }

  function priorityCell(w) {
    // text-wrap, because Bootstrap's .badge is white-space:nowrap and these
    // carry sentences: "Already in care at Tuggerah" is a single unbreakable
    // 199px token, and under table-layout auto that became this column's floor
    // and pushed the whole table 40px wider than the content area - which the
    // browser then took back off the LAST column, Actions. A reason badge that
    // wraps onto two lines is a far smaller cost than a row whose actions are
    // off the edge. The status badges stay nowrap: a status is one short label
    // and breaking it would read as two.
    // THE CHILDREN A REASON NAMES OPEN THEIR DIALOG (6 Nov 2026) - "Sibling of
    // Scarlett Galli", and the centre in "Already in care at Point Clare" -
    // from the same reason in pieces. See priority_reason_parts().
    var out = w.reasons.map(function (r, i) {
      return '<span class="badge text-bg-light border text-wrap text-start me-1">' +
        reasonHtml(w.reason_parts ? w.reason_parts[i] : null, r) + '</span>';
    }).join('');
    if (!w.reasons.length) out = '<span class="text-muted small">Standard</span>';

    // HOW the sibling was matched, ONLY when that is worth weighing - which is
    // the phone-only case and nothing else. An email match is the stronger of the
    // two (a mailbox is harder to share by accident than a handset), so a family
    // matched on the email, or on both, needs no annotation: the badge above
    // already names who they were matched to. Measured 11 Aug 2026 over the 100
    // live matches, **89 are "email + phone"** - so printing the provenance on
    // every row put a line saying nothing under nine rows in ten, and took the
    // Priority column to three lines. Withhold the figure rather than caption it.
    if (w.sibling_match && w.sibling_match.via === 'phone') {
      out += '<div class="small text-muted mt-1">' +
        '<i class="fa-solid fa-phone fa-xs me-1"></i>on the parent\'s mobile only</div>';
    }
    return out;
  }

  /**
   * The other centres this child is waiting at, as chips carrying their place in
   * that centre's queue.
   *
   * THE SAME CHIP THE GROUP REGISTER DRAWS (a.wl-cchip) and the same deep link
   * into the other centre's queue - one component, so the two screens cannot
   * come to describe the same fact in two ways. Only OPEN applications elsewhere
   * arrive here; see waitlist_elsewhere().
   *
   * Blank when there is nowhere else, rather than a "-": 9 rows in 10 have
   * nothing to say here and a dash on all of them is a column of noise.
   */
  function elsewhereCell(w) {
    var others = w.elsewhere || [];
    // A BROTHER OR SISTER ALSO WAITING (16 Oct 2026) goes in this column too:
    // it is the same question - who else in this family is in a queue - asked of
    // a different child. Under the centre chips, one line each.
    var sw = w.siblings_waiting || [];
    var sibs = sw.length
      ? '<div class="small text-muted' + (others.length ? ' mt-1' : '') + '">' +
          (sw.length > 1 ? 'Siblings' : 'Sibling') + '</div>' +
        sw.map(function (s) { return window.waitlistSiblingLine(s, { open: true }); }).join('')
      : '';
    var past = pastCell(w, others.length || sw.length);
    if (!others.length) return sibs + past;

    var name = w.child_first_name + ' ' + w.child_last_name;
    // NO QUEUE-POSITION PILL IN THIS COLUMN, unlike the register's chip and the
    // modal's. Measured 17 Aug 2026: the pill takes this column's floor from
    // 117px to 143px, and at 1280 with the sidebar out the nine columns have
    // 1030px between them - so the number would be paid for by the Child and
    // Priority columns wrapping harder. The question here is WHICH centres;
    // their place in each of those queues is on the chip's title, in the family
    // modal, and on the register, which is the screen for comparing queues.
    var chips = others.map(function (o) {
      return window.waitlistCentreChip(name, o, w.days, { pill: false });
    }).join('');

    // Said only when it is true, which is 15 families group-wide. Offering the
    // days off the wrong application is how an offer gets declined by a family
    // who wanted it - the same note the register carries under its day badges.
    return chips + (window.waitlistDaysDiffer(others, w.days)
      ? '<div class="small text-muted" title="The days on their other application are not the ' +
        'days asked for here. Offer against this one.">wants other days there</div>'
      : '') + workedElsewhere(others) + sibs + past;
  }

  /**
   * WHERE THEY APPLIED BEFORE (11 Nov 2026, Broc): the closed and archived
   * applications search shows - "Wamberal Rejected by service" - so a family on
   * one queue is not read as a family nobody has dealt with. Last in the column,
   * under the live answers, because it is history. See waitlist_history().
   *
   * THREE AT MOST HERE, newest first, then "and N older": measured that day, 183
   * of the group's rows carry any and 157 carry one or two, but one Warnervale
   * family carries six and this column is 7rem. All of them, with the dates, are
   * in the family modal.
   */
  function pastCell(w, after) {
    var h = w.history;
    if (!h || !h.lines || !h.lines.length) return '';
    return '<div class="wl-past-block' + (after ? ' mt-1' : '') + '">' +
      '<div class="small text-muted">Applied before</div>' +
      window.pastApplicationLines(h.lines, h.total, { compact: true, limit: 3 }) + '</div>';
  }

  /**
   * ANOTHER CENTRE IS ALREADY WORKING THIS FAMILY (22 Oct 2026, the enrolments
   * meeting). The chip carries the mark; this says it in words, because an
   * icon inside an amber chip reads the same as the open-offer chip beside it.
   * "there" points at the marked chip - the stage is on its title and in the
   * family modal. See waitlist_elsewhere().
   */
  function workedElsewhere(others) {
    var n = others.filter(function (o) { return o.work; }).length;
    if (!n) return '';
    return '<div class="small text-warning-emphasis">' +
      '<i class="fa-solid fa-list-check fa-xs me-1"></i>In progress there</div>';
  }

  function statusCell(w) {
    var o = w.open_offer;
    if (o) {
      // An open offer is time-critical, so surface the deadline, not just a label.
      //
      // AND THE STAGE STILL SHOWS, if the family has already answered (3 Sep
      // 2026). This branch used to return before stageBadge() and swallow it,
      // which is where the report came from: two Kanwal rows sat at stage
      // `accepted` with the offer row still `pending` - staff had advanced the
      // pipeline by hand rather than through Answer - so the screen said "Offer
      // open" and "Answer" about a family who had already said yes, and the
      // acceptance appeared nowhere at all. The two badges contradicting each
      // other is the honest reading of contradictory data, and Answer is still
      // the right button: the offer is pending, it is still holding the days,
      // and answering it is what makes the two halves agree.
      return '<div><span class="badge text-bg-warning text-wrap text-start">Offer open</span></div>' +
        '<div class="small text-muted">' + roomIcon(o.room_name) + esc(o.room_name) + ' ' + o.year + ' &middot; ' +
        o.days.map(function (d) { return DAYS[d]; }).join(', ') + '</div>' +
        '<div class="small">Expires ' + fmtDateTime(o.expires_at) + '</div>' +
        (hasAnswered(w) ? stageBadge(w) : '');
    }
    // AN OFFER SENT IN KIDSOFT (15 Oct 2026), which is where offers are made.
    // Kidsoft's room and Kidsoft's OFFERED days, which are not always the days
    // in the Days wanted column: Stella Knox asked for Tue/Fri and Terrigal
    // offered Tue/Wed, and with only the asked-for days on screen this app and
    // Kidsoft looked like they disagreed. Read-only - Kidsoft owns all of it, so
    // there is nothing here to answer or withdraw. The deadline is graded by the
    // same offerUrgency() as every other offer; a submitted form has none.
    var k = w.kidsoft_offer;
    if (k) {
      var u = offerUrgency(k.hours_left);
      return '<div><span class="badge text-bg-warning text-wrap text-start" title="' +
          esc(k.answered ? 'In Kidsoft. The next step is the centre\'s.'
                         : 'Sent in Kidsoft. Waiting for the family to answer.') + '">' +
          esc(k.label) + '</span></div>' +
        '<div class="small text-muted">' +
          (k.room_name ? roomIcon(k.room_name) + esc(k.room_name) + ' ' + k.year + ' &middot; ' : '') +
          k.days.map(function (d) { return DAYS[d]; }).join(', ') + '</div>' +
        (k.expires ? '<div class="small ' + (u ? u.cls : '') + '">Expires ' + esc(k.expires) + '</div>' : '');
    }
    var cls = { waitlisted: 'secondary', offered: 'warning', partially_placed: 'info',
                placed: 'success', withdrawn: 'light', expired: 'light' }[w.status] || 'secondary';
    // status_label, NOT data.statuses[status]: one of our statuses covers two of
    // Kidsoft's, and `expired` reads "Offer expired" on the rows where a place
    // was offered rather than a waiting place lapsing. Composed server-side so
    // the queue, the group register and search all say the same word - see
    // waitlist_status_label().
    return '<span class="badge text-bg-' + cls + ' text-wrap text-start">' +
      esc(w.status_label || data.statuses[w.status] || w.status) + '</span>' +
      // HOW FAR THROUGH ENROLLING THEY ARE, if anyone has started. A different
      // fact from the application's status - "Waitlisted" says where they sit
      // in the queue, this says whether anybody has rung them - so it sits
      // under it rather than replacing it. In the status column and not in
      // Actions, because it is information; the button is the action.
      stageBadge(w);
  }

  /**
   * A BADGE, NOT A MUTED LINE (3 Sep 2026).
   *
   * "Accepted" was 0.8rem grey text under a grey "Waitlisted" badge, so a real
   * Kanwal row read as an untouched queue entry when the family had already said
   * yes - and the loudest mark on it was a green Offer button inviting somebody
   * to promise the same seat again. How far through enrolling a family is
   * outranks where their application sits in the queue, so it gets the same
   * weight of mark. Same shape In progress uses, so the two screens name the
   * state the same way.
   *
   * AMBER ON `accepted` BECAUSE THE MOVE IS OURS, not theirs: they have
   * answered and the place is not in Kidsoft yet. Green would say finished, and
   * green is what `enrolled` means here. The stages before an offer stay quiet -
   * "somebody has rung them" is context, not a state to act on - which is the
   * distinction the muted line was making before it was applied to every stage.
   */
  /** They have said yes: the row's next move is ours, not theirs. */
  function hasAnswered(w) {
    return !!w.work && (w.work.stage === 'accepted' || w.work.stage === 'enrolled');
  }

  function stageBadge(w) {
    if (!w.work) return '';
    // Shared with In progress (window.workStageClass in app.js) so the two
    // screens cannot grade the same pipeline differently.
    // text-wrap: "Attempted contact" on one line set the Status column at 154px
    // at every centre, and the queue needed that room for Contacted.
    return '<div class="mt-1"><span class="badge ' + workStageClass(w.work.stage) + ' text-wrap text-start">' +
      '<i class="fa-solid fa-list-check fa-xs me-1"></i>' + esc(w.work.stage_label) +
      '</span></div>';
  }

  /**
   * ONE PRIMARY BUTTON, EVERYTHING ELSE BEHIND "..." (11 Aug 2026).
   *
   * The column had grown to four buttons and was measurably losing: the table's
   * declared widths summed past the content area, so under table-layout auto the
   * browser squeezed the LAST column hardest - Actions was handed 71px for
   * 191px of buttons at a 1280px viewport, putting the row's own actions off the
   * right-hand edge. That is the see-it-but-not-reach-it failure the In progress
   * module was built to fix, arriving here by a different route.
   *
   * WHY NOT COLLAPSE THEM ALL into the dropdown, which was the obvious move: the
   * primary action IS the row's state, and behind a "..." every row looks
   * identical. So the split keeps the state visible and hides only the things
   * you go looking for deliberately.
   *
   * EDIT AND CONTACT HISTORY HAVE LEFT THIS MENU (14 Aug 2026). They are the two
   * halves of one modal now, opened by the child's name. What is left in the
   * menu is what the row's STATE affords, and with nothing there the menu is not
   * rendered at all rather than opening empty.
   *
   * THE OFFER BUTTON IS GONE (15 Oct 2026). Offers are sent in Kidsoft, and Broc
   * found the green Offer and the bare "+" clunky beside that: staff pressed
   * Offer here and still had to send the offer there. Kidsoft reports every
   * offer it holds, so the row reads it instead (statusCell above) and the board
   * holds its days. What the row offers now follows the family:
   *
   *   nobody is on it yet   -> "Start" (adds them to In progress), labelled,
   *                            not a bare "+"
   *   somebody is on it     -> "Enrolment", opening the work item; FILLED once
   *                            the family has answered, because the next move
   *                            is ours (3 Sep 2026: an answered row must not
   *                            look untouched)
   *   an old offer is open  -> "Answer", for the offers made before the button
   *                            went. They still hold their days until answered.
   */
  function actions(w) {
    var o = w.open_offer;
    var closed = w.status === 'placed' || w.status === 'withdrawn';
    var answered = hasAnswered(w);

    var primary = '';
    if (o) {
      primary = '<button class="btn btn-sm btn-primary respond" data-offer="' + o.id + '">Answer</button> ';
    } else if (w.work) {
      primary = '<button class="btn btn-sm btn-' + (answered ? '' : 'outline-') + 'primary prog-open" ' +
                'data-work="' + w.work.id + '" title="Open enrolment (' + esc(w.work.stage_label) + ')">' +
                'Enrolment</button> ';
    } else if (!closed) {
      // NOT while an offer is open with nothing tracking it - that can only be
      // an offer made before 11 Aug 2026, and an item created here would know
      // nothing about it, so the family would appear twice on In progress.
      //
      // "START", the board's word ("Start with this family") for the same act.
      // "Add to In progress" in full was measured first and put the table 80px
      // past its 1030px box at 1280, sliding Added under the pinned column; the
      // full name stays on the title.
      primary = '<button type="button" class="btn btn-sm btn-outline-primary prog-add" data-id="' +
                w.id + '" title="Add to In progress, at Identified" aria-label="Add to In progress">' +
                '<i class="fa-solid fa-list-check me-1"></i>Start</button> ';
    }

    var items = [];
    if (o) items.push(item('withdraw', 'data-offer="' + o.id + '"', 'fa-rotate-left', 'Withdraw offer'));
    if (o && w.work) {
      items.push(item('prog-open', 'data-work="' + w.work.id + '"', 'fa-list-check',
                      'Open enrolment (' + w.work.stage_label + ')'));
    }

    // aria-label rather than a .visually-hidden span, deliberately: that class is
    // position:absolute, and one per row inside a .table-scroll is exactly what
    // put 16,000px of blank scroll on this page once already.
    return primary + (items.length
      ? '<div class="dropdown d-inline-block">' +
          '<button type="button" class="btn btn-sm btn-outline-secondary" data-bs-toggle="dropdown" ' +
                  'aria-expanded="false" aria-label="More actions" title="More actions">' +
            '<i class="fa-solid fa-ellipsis"></i></button>' +
          '<ul class="dropdown-menu dropdown-menu-end">' + items.join('') + '</ul>' +
        '</div>'
      : '');
  }

  function item(cls, attrs, icon, label) {
    return '<li><button type="button" class="dropdown-item ' + cls + '" ' + attrs + '>' +
      '<i class="fa-solid ' + icon + ' fa-fw me-2 text-muted"></i>' + esc(label) + '</button></li>';
  }

  /**
   * A DROPDOWN INSIDE .table-scroll IS CLIPPED UNLESS POPPER GOES FIXED.
   *
   * app.css puts position:relative and overflow:auto on that box, so an
   * absolutely positioned menu is clipped by it - on the lower rows the menu
   * would open into the clipped region and simply not be there. position:fixed
   * escapes it (no ancestor sets transform/filter/will-change, which are what
   * would make a fixed element clip again), and Popper keeps it attached while
   * the container scrolls.
   *
   * Instances are constructed rather than left to the data-api, because the
   * config can only be given at construction. Rows are rebuilt on every load, so
   * the old elements and their instances go with them.
   */
  function bindDropdowns() {
    $table.find('[data-bs-toggle=dropdown]').each(function () {
      bootstrap.Dropdown.getOrCreateInstance(this, { popperConfig: { strategy: 'fixed' } });
    });
  }

  // The pinned cell holding the OPEN menu has to out-rank its neighbours - see
  // the .dd-open note in app.css. Delegated once, not per render.
  $table.on('show.bs.dropdown', function (e) {
    $(e.target).closest('td').addClass('dd-open');
  }).on('hidden.bs.dropdown', function (e) {
    $(e.target).closest('td').removeClass('dd-open');
  });

  function render() {
    var rows = data.waitlist;
    if (!rows.length) {
      // A search that found nothing and a genuinely empty queue are different
      // situations, and only one of them is worth a first-run explanation.
      // THREE EMPTY STATES, because "no rows" has three causes here and only one
      // of them is worth a first-run explanation. An age range that matches
      // nobody used to fall through to "Nobody on the waitlist here. Import from
      // Kidsoft...", which is a false statement about the queue AND advice that
      // would not change the outcome.
      var searching = !!$('#search').val();
      $table.html('<tr><td colspan="10" class="p-0">' + (searching
        ? emptyState('fa-magnifying-glass', 'No match for "' + $('#search').val() + '"',
                     'Try a surname, or clear the search.')
        : ageActive()
          ? ageEmptyState()
          : emptyState('fa-cloud-arrow-down', 'Nobody on the waitlist here',
                     'Import from Kidsoft to pull this centre\'s enrolment queue, or add a family who ' +
                     'rang before they applied.')) +
        '</td></tr>');
      return;
    }
    $table.html(rows.map(function (w) {
      // DOB, not the age. The age in months is what the filter above takes and
      // what a room's band is written in, but on a row the team reads the birth
      // date: it is the fact Kidsoft holds, it does not go stale, and it is what
      // they say back to a parent on the phone.
      var age = w.child_dob
        ? '<div class="small text-muted">' + fmtDate(w.child_dob) + '</div>' : '';
      // WHEN THEY ARE YEARS AWAY, SAY SO, and only then. This row is on screen
      // at all because somebody chose "Any start date"; without the date they
      // asked for, Baby Imrie reads as an ordinary Terrigal family holding a
      // place in line rather than one who cannot start until 3/01/2028. Blank on
      // every other row, deliberately - a start date on all 108 would be a
      // column of this year repeated, which is the "do not caption what they
      // already know" rule.
      var starts = (w.required_from && data.starts_by_default
                    && w.required_from > data.starts_by_default)
        ? '<div class="small text-warning-emphasis">Starts ' + fmtDate(w.required_from) + '</div>' : '';
      // The phone goes FIRST and is a tel: link. This screen exists so somebody
      // can ring a family about a place, Kidsoft has a mobile for 99% of
      // applications against an email for 38%, and a number you have to
      // copy out by hand is a number nobody rings.
      var guardian = guardianCell(w.guardian_name, w.guardian_phone, w.guardian_email);
      var closed = ['placed', 'withdrawn', 'expired'].indexOf(w.status) !== -1;

      // A closed application carries NO queue position - it is not in the queue.
      // An em-dash-free '-' rather than a number, because any number here reads
      // as a place in line, which is what a placed family conspicuously does not
      // hold. See the numbering note in waitlist_for_centre().
      // THE NAME IS THE HANDLE. Everything you can do to one family without a
      // room - read what they asked for, read what we have said to them, log a
      // call, correct a detail - is behind it, which is where somebody looking
      // at a row already is. A button rather than an <a>, because it opens a
      // dialog and goes nowhere; same shape as the Rooms screen's centre names.
      var name = esc(w.child_first_name + ' ' + w.child_last_name);
      // ALREADY TALKING TO THEM (Broc, 15 Oct 2026): the whole row is washed
      // when anybody is on this family - an item in In progress at any stage,
      // or an offer out, in Kidsoft or the old kind - so scrolling the queue
      // shows at a glance who not to ring cold. See .wl-engaged in app.css.
      var engaged = !closed && !!(w.work || w.open_offer || w.kidsoft_offer);
      return '<tr' + (closed ? ' class="opacity-50"' : engaged ? ' class="wl-engaged"' : '') + '>' +
        '<td class="fs-5 text-muted">' +
          (w.queue_position === null ? '<span class="opacity-50">-</span>' : w.queue_position) + '</td>' +
        '<td><button type="button" class="btn btn-sm btn-link p-0 text-start fw-semibold wl-open" ' +
          'data-id="' + w.id + '" title="Application and contact history">' + name + '</button>' +
          age + starts + (w.dne ? '<div>' + dneBadge(w.dne) + '</div>' : '') +
          // THE SAME CHILD APPLIED FOR TWICE HERE (6 Nov 2026), under Do Not
          // Enrol and in the same shape - see waitlist_duplicates().
          (w.duplicate ? '<div class="mt-1">' + dupBadge(w.duplicate) + '</div>' : '') +
          // WANTED A PLACE LONG AGO AND STILL WAITING (6 Nov 2026) - a prompt
          // to ring and check, never a filter. See waitlist_stale().
          (w.stale ? '<div class="mt-1">' + staleBadge(w.stale) + '</div>' : '') + '</td>' +
        '<td>' + statusCell(w) + '</td>' +
        '<td>' + guardian + '</td>' +
        '<td>' + dayBadges(w.days, w.attending_days) + '</td>' +
        '<td>' + elsewhereCell(w) + '</td>' +
        '<td>' + priorityCell(w) + '</td>' +
        '<td class="small">' + fmtDate(w.date_added) + newLine(w) + '</td>' +
        // LAST CONTACTED (11 Nov 2026): In progress's "Last interacted", the
        // same data and the same renderer - see waitlist_last_touched().
        '<td class="small">' + lastTouchedCell(w) + '</td>' +
        // text-nowrap: the primary button and the "..." must never stack. They
        // did at 7rem, and a wrapped Actions cell doubles the height of every
        // row in the table - the Rooms screen's lesson, arriving here.
        // wl-actions pins it to the right edge of the scroller; see app.css.
        '<td class="text-end text-nowrap wl-actions">' + actions(w) + '</td></tr>';
    }).join(''));
    bindDropdowns();
  }

  function renderSummary() {
    var rows = data.waitlist;
    var waiting = rows.filter(function (w) { return w.status === 'waitlisted'; }).length;
    // Both kinds: the offers Kidsoft holds, which is where they are made now,
    // and any left from before this screen stopped making them.
    var offers = rows.filter(function (w) { return w.open_offer || w.kidsoft_offer; });
    var dayDemand = {};
    Object.keys(DAYS).forEach(function (d) { dayDemand[d] = 0; });
    rows.forEach(function (w) {
      if (w.status !== 'waitlisted') return;
      // A DAY THEY ALREADY HOLD IS NOT DEMAND (17 Aug 2026). This counted every
      // requested day, so a family already in the room on Monday asking to add a
      // Wednesday was counted as wanting a Monday place too - and once the strip
      // below started drawing those days green, the tile and the badges were
      // reading the same rows differently. Measured before changing it: the top
      // day is unchanged at all seven centres and the counts move by 0 to 4.
      w.days.forEach(function (d) {
        if ((w.attending_days || []).indexOf(d) === -1) dayDemand[d]++;
      });
    });
    // NULL WHEN NOBODY WANTS ANY DAY, so the tile withholds rather than captions.
    // A plain sort always hands back a key, so with every count at zero the tile
    // read "Mon - 0 families want it", a figure contradicting its own caption on
    // a screen whose whole rule is that they must not. Reachable in one click
    // since the "Offer expired" segment shipped (every row closed, so no row
    // contributes demand), and before that from any search that matched only
    // closed applications.
    var hottest = Object.keys(dayDemand).sort(function (a, b) { return dayDemand[b] - dayDemand[a]; })[0];
    if (!dayDemand[hottest]) hottest = null;

    // Offers within 3 days of lapsing need chasing today. hours_left comes from
    // the SERVER (MySQL TIMESTAMPDIFF against NOW(), which is Sydney) - this
    // used to be new Date(expires_at) - new Date(), which parses a Sydney
    // wall-clock string in whatever timezone the viewer's browser is in. Same
    // grading as In progress and the work modal: inside 72 hours, or already
    // lapsed, needs chasing.
    var soon = offers.filter(function (w) {
      var u = offerUrgency((w.open_offer || w.kidsoft_offer).hours_left);
      return u !== null && u.urgent;
    }).length;

    var fresh = rows.filter(isNew).length;

    var tile = window.statTile;   // shared - see app.js
    $('#wl-summary').html(
      // A FIGURE MAY NOT CONTRADICT ITS OWN CAPTION. These tiles are computed
      // over the rows on screen, so with a filter in force "115 families in the
      // queue" becomes "56 families in the queue" - a false statement about the
      // centre's queue, and the age filter is far likelier to be left set than a
      // half-typed surname was. The number is right for what is shown; it is the
      // caption that has to stop claiming to be the whole queue. The group tab
      // needs none of this: its totals are computed before any narrowing, so
      // they describe the register whatever the table is showing.
      // A FIGURE MAY NOT CONTRADICT ITS OWN CAPTION, and the start-date segment
      // is the case that makes this sharp: it is ON when the screen opens, so
      // "families in the queue" would be wrong at Terrigal before anybody has
      // touched a control. It gets its own caption naming the cut, because
      // "matching the filters" on an untouched screen is a shrug. The other
      // filters win when both are in force - they are the ones the reader just
      // set, and there is no room to recite two.
      tile('Waiting', waiting,
           filtersActive() ? 'families matching the filters'
             : startsNarrowed()
               ? 'families starting ' + startsCaption()
               : 'families in the queue') +
      // "3 days" is derived, not typed - the Newly added tile derives its
      // window from data.new_days for the same reason.
      tile('Offers open', offers.length,
           soon ? soon + ' expiring within ' + (window.OFFER_URGENT_HOURS / 24) + ' days'
                : 'none expiring soon',
           soon ? 'text-danger' : '') +
      tile('Most wanted day', hottest ? DAYS[hottest] : '-',
           hottest ? dayDemand[hottest] + ' families want it' : '') +
      // WAS "EXPIRING SOON" until 11 Nov 2026, when Kidsoft's waitlist expiry
      // was turned off - see WAITLIST_NEW_DAYS. Counted over the rows on screen,
      // like the tiles beside it. No colour: a new family is not a warning.
      tile('Newly added', fresh,
           (fresh ? '' : 'none ') + 'in the last ' + data.new_days + ' days')
    );
  }

  // THERE ARE NO QUEUE-WIDE NOTICES ANY MORE (11 Aug 2026). The one that lived
  // here counted the applications Kidsoft could see were siblings and nobody had
  // ticked, and offered a button to tick them - an alert on 6 of 7 centres
  // telling the team the queue below it was in the wrong order. The ranking reads
  // the detection directly now, so the queue is simply in the right order and
  // there is nothing to announce. See _incl/siblings.php.

  function load() {
    // The URL follows the filters on every path into here - a chip, a typed
    // bound, a unit swap - rather than being written by each of them.
    syncUrl();
    $table.html(spinnerRow(9, 'Loading the queue...'));
    $.getJSON('/api/waitlist', $.extend({
      action: 'list',
      centre_id: centreId(),
      q: $('#search').val(),
      show: $('#closed-filter input:checked').val()
    }, ageParams(), startParams()))
      .done(function (res) {
        if (!res.ok) { toast(res.error, 'danger'); return; }
        data = res;
        render();
        renderSummary();
      })
      .fail(function () {
        $table.html('<tr><td colspan="10" class="text-center text-danger py-4">' +
          'Could not load the waitlist.</td></tr>');
        toast('Could not load the waitlist.', 'danger');
      });
  }

  // --- The family modal ------------------------------------------------------
  //
  // THE MODAL IS NOT THIS FILE'S ANY MORE (14 Aug 2026). It is
  // static/js/waitlist-modal.js, because the Placement board opens the same one
  // from "Could take this day" - a family you can ring, offer and start
  // enrolling from that shortlist is a family somebody needs the contact log for
  // first. What is left here is the row and what to do after a write.
  //
  // It fetches the application itself rather than being handed the row this
  // table is holding: the board has no such row, and one loading path is the
  // only way the two screens cannot drift into showing different things.

  function findApp(id) {
    return data.waitlist.filter(function (w) { return w.id == id; })[0];
  }

  $table.on('click', '.wl-open', function () {
    window.openWaitlistFamily($(this).data('id'), load);
  });

  // Importing from Kidsoft has no handler because it has no button: waitlist.php
  // calls waitlist_import_on_view() on page load. See _incl/waitlist-import.php.

  // --- In progress ------------------------------------------------------------
  //
  // Until 10 Aug 2026 there was no way to start the pipeline from here, so an
  // offer routinely existed with nothing tracking it, which is why
  // work_progress_list() still unions those in. A family Kidsoft has already
  // offered a place to is moved to the stage Kidsoft says as soon as the item
  // exists - see work_follow_kidsoft_offers(), which /api/work's create runs.

  $table.on('click', '.prog-add', function () {
    var w = findApp($(this).data('id'));
    if (!w) return;
    var $b = $(this).prop('disabled', true);
    // Asks first for a Do Not Enrol family - see postConfirmingDne() in app.js.
    postConfirmingDne('/api/work', {
      action: 'create',
      centre_id: centreId(),
      // NO ROOM. A family in the queue has not been matched to one yet, and
      // guessing their preferred room would put the item against a spot nobody
      // has decided on. It is picked in the Enrolment that opens next, once
      // somebody has decided, or set by the offer in Kidsoft.
      year: w.for_year || data.default_year,
      waitlist_id: w.id,
      child_name: w.child_first_name + ' ' + w.child_last_name,
      days: w.days
    }).done(function (res) {
      if (res.cancelled) { $b.prop('disabled', false); return; }
      if (!res.ok) { toast(res.error, 'danger'); $b.prop('disabled', false); return; }
      toast(w.child_first_name + ' ' + w.child_last_name + ' added to In progress.');
      load();
      // STRAIGHT INTO THE ENROLMENT (22 Oct 2026, the enrolments meeting: "can
      // we select from there what room, and what days we are talking to the
      // family for?"). Starting a family is the moment that gets decided, so
      // the dialog that holds the room and days opens rather than a toast that
      // leaves somebody to go and find it.
      if (res.id) window.openWorkItem(+res.id, load);
    }).fail(function () {
      toast('Could not add that.', 'danger');
      $b.prop('disabled', false);
    });
  });

  $table.on('click', '.prog-open', function () {
    window.openWorkItem(+$(this).data('work'), load);
  });

  // --- Offers made before 15 Oct 2026 ----------------------------------------
  //
  // The Offer button and its modal are gone - offers are sent in Kidsoft. What
  // stays is answering and withdrawing the ones made here before that.

  $table.on('click', '.respond', function () {
    var offerId = $(this).data('offer');
    var w = data.waitlist.filter(function (x) { return x.open_offer && x.open_offer.id == offerId; })[0];
    if (!w) return;
    var $f = $('#respond-form');
    $f[0].reset();
    $f.find('[name=id]').val(offerId);
    $('#respond-days').html(w.open_offer.days.map(function (d) {
      return '<div><input type="checkbox" class="btn-check" name="accepted_days[]" value="' + d +
        '" id="ad-' + d + '" checked>' +
        '<label class="btn btn-outline-success btn-sm" for="ad-' + d + '">' + DAYS[d] + '</label></div>';
    }).join(''));
    $('.modal-title', '#respond-modal').text(w.child_first_name + ' ' + w.child_last_name);
    respondModal.show();
  });

  $('#respond-form').on('submit', function (e) {
    e.preventDefault();
    var $btn = $(this).find('[type=submit]').prop('disabled', true);
    $.post('/api/waitlist', $(this).serialize() + '&action=respond_offer')
      .done(function (res) {
        if (!res.ok) { toast(res.error, 'danger'); return; }
        respondModal.hide();
        toast(res.message);
        load();
      })
      .fail(function () { toast('Could not record the answer.', 'danger'); })
      .always(function () { $btn.prop('disabled', false); });
  });

  $table.on('click', '.withdraw', function () {
    var id = $(this).data('offer');
    confirmModal({
      title: 'Withdraw this offer?', ok: 'Withdraw offer', danger: true,
      body: 'They go back to waitlisted, keeping their original date added.'
    }).done(function () {
      $.post('/api/waitlist', { action: 'withdraw_offer', id: id })
        .done(function (res) {
          if (!res.ok) { toast(res.error, 'danger'); return; }
          toast('Offer withdrawn.');
          load();
        });
    });
  });

  // THE CONTACT LOG IS THE MODAL'S SECOND TAB and lives with it in
  // static/js/waitlist-modal.js - it is opened from the board too, and a second
  // copy of "did this note reach Kidsoft" is a second chance to answer it wrong.

  bindFilters(load);
  bindRefresh(load);

  // ?q= lands this screen already filtered to one family - what the global
  // search palette links to when it finds someone in a queue. "All" rather than
  // "Open", because a search result may well BE a closed application and an
  // empty table would read as "they are not on the list".
  var params = new URLSearchParams(window.location.search);
  var deepQ = params.get('q');
  if (deepQ) {
    $('#search').val(deepQ);
    // ...but only when the link did not say which applications to show. A
    // ?show= in the URL is somebody's own choice, and a centre switch now
    // carries both, so forcing All here would quietly undo it on every hop.
    if (!params.get('show')) $('#closed-on').prop('checked', true);
  }

  load();

  // ?wl= OPENS THAT APPLICATION, not just a filtered table (17 Aug 2026). The
  // search palette merges a child's records onto one card, so a family can show
  // four queue lines at four centres; a link carrying only a name could not say
  // WHICH of them was clicked, and the name it carried was the card's rather than
  // the application's. The id can say both.
  //
  // Independent of load(): openWaitlistFamily() fetches the application itself,
  // so this does not care whether the row survived the filters underneath - a
  // closed application deep-linked from a search still opens.
  var deepId = parseInt(params.get('wl'), 10);
  if (deepId) window.openWaitlistFamily(deepId, load);

  // ===========================================================================
  // ALL CENTRES: the group register
  //
  // One row per CHILD, not per application, because a family applying at four
  // centres is one family with one need - see _incl/waitlist-group.php for how
  // the applications are joined and why the rule is deliberately cautious.
  //
  // Read-only on purpose. Every action on the centre tab (offer, edit, log a
  // call) needs a room or a queue position, and both belong to one centre, so a
  // centre chip here is a LINK into that centre's queue rather than a button
  // that would have to guess which application it meant.
  // ===========================================================================

  function initGroup() {
    var $table = $('#wl-group-table tbody');
    var data = null;

    var tile = window.statTile;   // shared - see app.js

    function renderSummary() {
      var t = data.totals;
      // THE PERCENTAGE MUST NOT ROUND AWAY THE COUNT ABOVE IT. This caption sits
      // under t.multi, so with 2 multi-centre families out of 534 a plain round()
      // printed a 2 over "0% of families" - the low-end twin of the placement
      // board's "Filled 100%" beside one free place (see _incl/docs/
      // placement-board.md). Latent, not live: it reads 72 of 534 today. 100%
      // here is a legitimate claim - every waiting family at more than one
      // centre - so fillPct() only ever makes it when t.multi === t.families.
      var pct = window.fillPct(t.multi, t.families);
      $('#wl-group-summary').html(
        tile('Families waiting', t.families,
             t.applications + ' application' + (t.applications === 1 ? '' : 's') + ' between them') +
        // The reason this tab exists. Counted over families, not applications,
        // because the point is how many PEOPLE this is, not how many rows.
        tile('At more than one centre', t.multi,
             t.multi ? pctText(pct) + ' of families, holding ' + t.multi_applications + ' places in the queues'
                     : 'every family is waiting at one centre only') +
        // FAMILIES, whose first application is that recent - a family already
        // waiting elsewhere is not new to the organisation. Was "Expiring soon";
        // see the centre tab's tile above.
        tile('Newly added', t.newly_added,
             (t.newly_added ? 'families ' : 'no families ') + 'in the last ' + data.new_days + ' days') +
        // "NOT ENROLLED YET", not "awaiting an answer" (15 Oct 2026): offers are
        // read from Kidsoft now, and one at Form Submitted has been answered -
        // 13 of the first 23 - while still holding the place until Kidsoft
        // enrols the child.
        tile('Offers open', t.offers, t.offers ? 'not enrolled yet' : 'none outstanding')
      );
    }

    /**
     * The overlap, in two cards.
     *
     * Left: how long each centre's queue is, with the part of it that is shared
     * with another centre drawn INSIDE the same bar rather than beside it --
     * those families are a subset, and two bars on a shared baseline would read
     * as two separate populations.
     *
     * Right: which centres are holding the same families. Ranked, not a matrix:
     * of the 21 possible pairs only 14 occur at all and one carries a third of
     * them, so a grid would have been mostly empty cells hiding the one fact
     * worth seeing.
     */
    function renderOverlap() {
      var centres = data.centres.filter(function (c) { return c.families > 0; })
        .sort(function (a, b) { return b.families - a.families; });
      var maxC = centres.length ? centres[0].families : 1;
      var pairs = data.pairs;
      var maxP = pairs.length ? pairs[0].n : 1;

      // markStack() with a max: scaled to the longest queue rather than to its
      // own total, so the bars stay comparable down the column, and it gets a
      // track so a short one reads as short rather than as missing.
      var byCentre = centres.map(function (c) {
        return '<div class="wl-ov-row">' +
          '<a class="wl-ov-name text-truncate" href="/waitlist?centre=' + c.centre_id + '">' +
            esc(c.centre) + '</a>' +
          '<div title="' + c.families + ' waiting, ' + c.shared +
            ' of them also waiting at another centre">' +
            markStack([
              { n: c.shared, cls: 'wl-bar-shared', label: 'Also waiting elsewhere' },
              { n: c.families - c.shared, cls: 'wl-bar-only', label: 'This centre only' }
            ], maxC) +
          '</div>' +
          '<div class="wl-ov-fig">' + c.families +
            '<span class="wl-ov-sub">' + (c.shared || '-') + '</span></div>' +
        '</div>';
      }).join('');

      // One series, so a plain bar - and it brings its own figure, which is why
      // this row has no wl-ov-fig cell of its own.
      var byPair = pairs.length ? pairs.map(function (p) {
        return '<div class="wl-ov-row wl-ov-pair">' +
          '<div class="wl-ov-name text-truncate" title="' + esc(p.a + ' + ' + p.b) + '">' +
            esc(p.a) + ' <i class="fa-solid fa-plus fa-2xs text-muted"></i> ' + esc(p.b) + '</div>' +
          markBar(p.n, maxP, p.n + ' families waiting at both ' + p.a + ' and ' + p.b,
            'wl-bar-shared') +
        '</div>';
      }).join('')
        : '<p class="text-muted small mb-0">No family is waiting at more than one centre.</p>';

      $('#wl-overlap').html(
        '<div class="col-lg-5"><div class="card h-100"><div class="card-body">' +
          '<h2 class="h6 mb-1">Queue by centre</h2>' +
          '<p class="small text-muted mb-3">Families waiting, and how many of them are ' +
          'also waiting somewhere else.</p>' +
          byCentre +
          '<div class="mt-2">' + markLegend([
            { cls: 'wl-sw-shared', label: 'also elsewhere' },
            { cls: 'wl-sw-only',   label: 'this centre only' }
          ]) + '</div>' +
        '</div></div></div>' +
        '<div class="col-lg-7"><div class="card h-100"><div class="card-body">' +
          '<h2 class="h6 mb-1">Centres holding the same families</h2>' +
          '<p class="small text-muted mb-3">Fill a place at one of a pair and the family ' +
          'can usually come off the other queue as well.</p>' +
          byPair +
        '</div></div></div>'
      );
    }

    /** One chip per centre the child is waiting at, carrying their place in it. */
    function centreChips(f) {
      return f.applications.map(function (a) {
        var cls = 'wl-cchip';
        var note = '';
        if (!a.open) { cls += ' closed'; note = a.status_label + '. '; }
        else if (a.has_offer) { cls += ' offer'; note = 'Offer open. '; }

        var title = note + (a.open ? 'Number ' + a.queue_position + ' in the ' + a.centre +
                    ' queue. ' : '') + 'Applied ' + fmtDate(a.date_added) + '. Wants ' +
                    a.days.map(function (d) { return DAYS[d]; }).join(', ') + '.' +
                    (a.reasons.length ? ' ' + a.reasons.join(', ') + '.' : '');
        // Deep-links into that centre's own queue with the family already
        // filtered, which is where every action on them lives.
        var href = '/waitlist?centre=' + a.centre_id + '&q=' + encodeURIComponent(f.name);
        return '<a class="' + cls + '" href="' + href + '" title="' + esc(title) + '">' +
          esc(a.centre) +
          (a.open ? '<span class="wl-cpos">' + a.queue_position + '</span>' : '') + '</a>';
      }).join('');
    }

    /** Same tag as the centre tab's, on the family's first date added. */
    function newLine(f) {
      // centre_ids is the OPEN centres: under "All" a family whose only
      // application has already closed is history, not news.
      return f.centre_ids.length && f.date_added && f.date_added >= data.new_since
        ? '<div class="small text-primary fw-semibold" title="First applied in the last ' +
          data.new_days + ' days">new</div>' : '';
    }

    function render() {
      var rows = data.families;
      if (!rows.length) {
        var q = $('#search').val();
        $table.html('<tr><td colspan="5" class="p-0">' + (q
          ? emptyState('fa-magnifying-glass', 'No match for "' + q + '"',
                       'Try a surname or a phone number, or clear the search.')
          : ageActive()
            ? ageEmptyState()
            : emptyState('fa-location-dot', 'Nobody is waiting at more than one centre',
                       'Switch back to All families to see the whole register.')) +
          '</td></tr>');
        return;
      }
      $table.html(rows.map(function (f) {
        var age = f.child_dob
          ? '<div class="small text-muted">' + fmtDate(f.child_dob) + '</div>' : '';
        // Different days at different centres is a real state (15 of the shared
        // families), and offering the days off the wrong application is how an
        // offer gets declined by a family who wanted it.
        var days = dayBadges(f.days) + (f.days_differ
          ? '<div class="small text-muted" title="The days asked for are not the same on every ' +
            'application. Check the one you are offering against.">varies by centre</div>' : '');

        return '<tr' + (f.centre_count > 1 ? ' class="wl-multi"' : '') + '>' +
          '<td class="fw-semibold">' + esc(f.name) + age +
            (f.dne ? '<div>' + dneBadge(f.dne) + '</div>' : '') + '</td>' +
          '<td>' + guardianCell(f.guardian_name, f.guardian_phone, f.guardian_email) + '</td>' +
          '<td>' + days + '</td>' +
          '<td>' + centreChips(f) + '</td>' +
          '<td class="small">' + fmtDate(f.date_added) + newLine(f) + '</td></tr>';
      }).join(''));
    }

    function load() {
      syncUrl();
      $table.html(spinnerRow(5, 'Loading every centre...'));
      $.getJSON('/api/waitlist', $.extend({
        action: 'group',
        q: $('#search').val(),
        show: $('#closed-filter input:checked').val(),
        multi_only: $('#spread-filter input:checked').val()
      }, ageParams(), startParams()))
        .done(function (res) {
          if (!res.ok) { toast(res.error, 'danger'); return; }
          data = res;
          render();
          renderSummary();
          renderOverlap();
        })
        .fail(function () {
          $table.html('<tr><td colspan="5" class="text-center text-danger py-4">' +
            'Could not load the waitlist.</td></tr>');
          toast('Could not load the waitlist.', 'danger');
        });
    }

    bindFilters(load);
    bindRefresh(load);

    var gp = new URLSearchParams(window.location.search);
    var q = gp.get('q');
    if (q) {
      $('#search').val(q);
      if (!gp.get('show')) $('#closed-on').prop('checked', true);
    }

    load();
  }
})(jQuery);
