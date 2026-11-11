// The search palette. Loaded on every page by layout_footer().
//
// WHAT IT IS FOR. A parent rings the enrolments team. The team has a number on a
// handset and maybe half a surname, and until now had to guess which of seven
// centres the family belonged to before any screen in this app could help. This
// answers that call: type the number, get the child, the room and days they are
// in, where they sit in the queue, and the parent's mobile as something you can
// press to dial.
//
// It is a PALETTE rather than a search box in the sidebar because the sidebar
// collapses to a 4.25rem icon rail, and because the answer to a call is a block
// of detail, not a dropdown of names - it needs the width.
//
// Each result is one CHILD with everything known about them attached. That
// grouping is the point: /api/search merges Kidsoft's enrolment, our board and
// our queue onto one row, so "are they enrolled AND on the waitlist for next
// year" is answered by reading, not by opening three screens.

(function ($) {
  'use strict';

  var $modal = $('#search-modal');
  if (!$modal.length) return;

  var modal    = new bootstrap.Modal($modal[0]);
  var $q       = $('#search-q');
  var $results = $('#search-results');
  var $took    = $('#search-took');

  var STATUSES = {};
  var timer = null;
  var inflight = null;
  var lastQuery = '';
  var active = -1;          // highlighted result, for the arrow keys

  // --- Rendering -------------------------------------------------------------

  /**
   * TWO FUNCTIONS, BECAUSE A CARD CARRIES TWO OPPOSITE FACTS. There was one, and
   * it drew both in the same purple: the room and placement lines list days a
   * child HOLDS, the queue lines list days a family is WAITING ON, and on a card
   * that has both - the common case for a family adding a day - the two read as
   * one set. Now they take the strip's own two colours, the same ones the
   * Waitlist queue and the cell panel use. See window.dayStrip().
   */
  function heldDays(days) {
    return dayStrip({ has: days || [], wide: true,
                      empty: '<span class="text-muted">no days</span>' });
  }
  function wantedDays(days) {
    return dayStrip({ want: days || [], wide: true,
                      empty: '<span class="text-muted">no days</span>' });
  }

  /** "3y 2m", or an empty string when we hold no DOB. */
  function ageLabel(months) {
    if (months === null || months === undefined) return '';
    if (months < 24) return months + ' mo';
    return Math.floor(months / 12) + 'y ' + (months % 12) + 'm';
  }

  /**
   * Why this row is here, in words.
   *
   * A phone hit names WHOSE handset it is. That is not decoration: the Type
   * field on these numbers includes Grandmother, Aunt, Neighbour and Case
   * Worker, so "this number is the grandmother's" changes how the call goes.
   */
  function matchLine(r) {
    // No line at all when we cannot name the person whose detail matched. A bare
    // "Matched on phone" says nothing the contact strip underneath does not
    // already say. An application sets `matched` only when its SECOND parent's
    // name matched: the strip names the first parent, so without this line a
    // search for the father lands on a card naming only the mother.
    var m = r.matched;
    if (!m) return '';
    // The child's own name matched, and it is in bold at the top of the card.
    // Explaining that would be noise - and worse, it would name whichever
    // guardian happens to share the surname, as if that were the reason.
    if (r.via === 'name') return '';
    var who = esc(m.guardian || 'a contact');
    if (m.relationship) who += ' <span class="text-muted">(' + esc(m.relationship) + ')</span>';

    var what;
    if (m.via === 'phone') {
      what = 'That number is ' + who + "'s";
      // MUTE "Mobile", EMPHASISE ANYTHING ELSE (13 Aug 2026). The type used to be
      // muted always, which is backwards: a mobile is the expected case and the
      // type is incidental, but a WORK or HOME number is usually the reason a
      // search looks wrong. Reported by Broc as "the search might be a bit too
      // fuzzy" after typing one number and getting 12 children from 5 families --
      // and it is not fuzzy at all, every one of the 12 was an EXACT match. The
      // number was an employer's switchboard that 15 Tuggerah guardians list as
      // their work number (the same number and the same root cause as the
      // 11-sibling bug in _incl/siblings.php). The palette was already saying so
      // on every row, in the greyest text on the line. Same fact, legible.
      if (m.phone_type) {
        what += /mobile/i.test(m.phone_type)
          ? ' <span class="text-muted">' + esc(m.phone_type) + '</span>'
          : ' <strong>' + esc(m.phone_type) + '</strong> number';
      }
    } else if (m.via === 'email') {
      what = 'That email is ' + who + "'s";
    } else if (m.via === 'guardian') {
      what = 'Parent or contact: ' + who;
    } else {
      return '';
    }
    return '<div class="sr-why"><i class="fa-solid fa-circle-info me-1"></i>' + what + '</div>';
  }

  /**
   * The call-back strip.
   *
   * `contact` is the best number to ring, chosen server-side down the same
   * ladder child_contacts() uses - NOT the number that matched. A call from a
   * grandmother's phone must not end with the team ringing the grandmother back.
   * When that best number still is not a parent's, it says so rather than
   * letting the label imply otherwise.
   */
  function contactStrip(r) {
    var c = r.contact;
    if (!c || (!c.phone && !c.email)) return '';

    var out = '<div class="sr-contact">';
    if (c.phone) {
      out += '<a class="sr-call" href="tel:' + esc(String(c.phone).replace(/[^0-9+]/g, '')) + '">' +
             '<i class="fa-solid fa-phone"></i>' + esc(c.phone) + '</a>';
    }
    var who = [];
    if (c.guardian) who.push(c.guardian);
    if (c.relationship) who.push(c.relationship);
    if (who.length) out += '<span class="sr-who">' + esc(who.join(' - ')) + '</span>';
    if (c.is_parent === false) {
      out += '<span class="badge sr-badge-warn" title="The only number on file for this family belongs to someone else">' +
             'Not a parent' + (c.phone_type ? ': ' + esc(c.phone_type) : '') + '</span>';
    }
    if (c.email) {
      out += '<a class="sr-email" href="mailto:' + esc(c.email) + '">' +
             // fa-solid, not fa-regular: only fa-solid-900 and fa-brands-400 are
             // vendored into static/css/fonts, so a fa-regular glyph renders blank.
             '<i class="fa-solid fa-envelope"></i>' + esc(c.email) + '</a>';
    }
    return out + '</div>';
  }

  /**
   * The room a child is enrolled in RIGHT NOW, one line per room.
   *
   * Added 17 Aug 2026 (Broc): a card for a child who is enrolled and not waiting
   * had nothing on it to act on - a number to ring, an email, and no way through
   * to any screen that knew more about them. Every other line here opens the
   * screen that owns it, and the commonest result of all opened nothing.
   *
   * This is Kidsoft's bookings, not our board rows, so it is what the child
   * actually holds; the line goes to the same cell panel a placement line does,
   * which is where the room, the day, who else is in it and what is free all are.
   */
  function roomLines(r) {
    return (r.in_rooms || []).map(function (p) {
      var href = '/placement?centre=' + p.centre_id + '&year=' + p.year +
                 '&room=' + p.room_id + (p.days.length ? '&wd=' + p.days[0] : '');
      return '<a class="sr-line" href="' + href + '">' +
        '<span class="sr-line-ic"><i class="fa-solid fa-people-roof"></i></span>' +
        '<span class="sr-line-main"><b>' + roomIcon(p.room) + esc(p.room) + '</b> ' +
          '<span class="text-muted">' + esc(p.centre) + ' ' + p.year + '</span>' +
          heldDays(p.days) + '</span>' +
        '</a>';
    }).join('');
  }

  /** Where this child is on a board, one line per year and room. */
  function placementLines(r, year) {
    return r.placements.map(function (p) {
      var st = STATUSES[p.status] || p.status;
      // Deep link straight to the cell: the Board opens that room x weekday
      // panel rather than making someone find the row again by eye.
      var href = '/placement?centre=' + p.centre_id + '&year=' + p.year +
                 '&room=' + p.room_id + (p.days.length ? '&wd=' + p.days[0] : '');
      return '<a class="sr-line" href="' + href + '">' +
        '<span class="sr-line-ic"><i class="fa-solid fa-table-cells"></i></span>' +
        '<span class="sr-line-main"><b>' + roomIcon(p.room) + esc(p.room) + '</b> ' +
          '<span class="text-muted">' + esc(p.centre) + ' ' + p.year + '</span>' +
          heldDays(p.days) + '</span>' +
        '<span class="sr-line-tag">' + esc(st) + '</span>' +
        '</a>';
    }).join('');
  }

  /**
   * Where this child is in a queue, one line per application.
   *
   * EACH LINE LINKS TO ITS OWN APPLICATION, by id (17 Aug 2026). It used to link
   * to `/waitlist?centre=&q=<the CARD's name>`, which is a search for a family
   * rather than a way back to the row you clicked, and merging a child's records
   * onto one card broke it in two ways at once: several queue lines on one card
   * all produced the SAME url, so a family waiting at four centres had four links
   * to four unfiltered-by-application queues; and the card is now headed by
   * Kidsoft's legal name whenever a Kidsoft record was merged in, while the queue
   * filters on what the family wrote on the form - a nickname, or "Baby Jones".
   *
   * `wl=` opens that application's family modal on arrival. `q=` still narrows
   * the table underneath it, but to the APPLICATION's own name, so closing the
   * modal leaves the row you came for on screen rather than the whole queue.
   */
  function waitlistLines(r) {
    return r.waitlist.map(function (w) {
      var bits = [];
      if (w.queue_position) bits.push('#' + w.queue_position + ' in queue');
      bits.push(w.status_label);
      if (w.for_year) bits.push('for ' + w.for_year);
      if (w.preferred_room) bits.push(esc(w.preferred_room));

      // NO "Expires in Nd" / "Lapsed" TAG (11 Nov 2026): Kidsoft's waitlist
      // expiry is turned off, so a waiting application has nothing to count
      // down to. An open offer's deadline is on the Waitlist row and the
      // family dialog, one click from here.
      // Kidsoft's own words for the offer it holds - "Offered in Kidsoft",
      // "Form submitted" - since that is where offers are made now.
      var k = w.kidsoft_offer;
      var offer = w.open_offer
        ? '<span class="sr-line-tag ok">Offer out: ' + esc(w.open_offer.room) + '</span>'
        : k ? '<span class="sr-line-tag ok">' + esc(k.label) + (k.room_name ? ': ' + esc(k.room_name) : '') + '</span>'
        : '';

      var href = '/waitlist?centre=' + w.centre_id + '&wl=' + w.id +
                 '&q=' + encodeURIComponent(w.child_name || r.name);
      return '<a class="sr-line" href="' + href + '">' +
        '<span class="sr-line-ic"><i class="fa-solid fa-list-ol"></i></span>' +
        '<span class="sr-line-main"><b>' + esc(w.centre) + '</b> ' +
          '<span class="text-muted">' + bits.join(' - ') + '</span>' +
          wantedDays(w.days) + '</span>' +
        offer +
        '</a>';
    }).join('');
  }

  /**
   * Applications Kidsoft has closed or archived, newest first. The builder is
   * window.pastApplicationLines() in app.js since 11 Nov 2026, shared with the
   * Waitlist's column and family modal - see there for why a line is not a link.
   */
  function historyLines(r) {
    return window.pastApplicationLines(r.history, r.history_total);
  }

  function statusBadge(r) {
    if (r.enrolled) return '<span class="badge sr-badge-ok">Enrolled</span>';
    if (r.provisional) return '<span class="badge sr-badge-info">Pencilled in</span>';
    if (r.waitlist.length) return '<span class="badge sr-badge-info">Waiting</span>';
    if (r.placements.length) return '<span class="badge sr-badge-info">On a board</span>';
    // Nothing live, but Kidsoft holds a closed application. Say which it is:
    // "Not enrolled" on a family who applied twice and lapsed reads as a family
    // we have never heard of, and that is the call this is meant to inform.
    if (r.history && r.history.length) {
      return '<span class="badge sr-badge-muted">Past application</span>';
    }
    // Known to Kidsoft, not currently active: a family who has left, or has not
    // started yet. Worth showing - "can we come back?" is a real call - but
    // said plainly so nobody reads it as a current enrolment.
    return '<span class="badge sr-badge-muted">Not enrolled</span>';
  }

  /**
   * AN ENROLLED CHILD'S NAME OPENS THEIR OWN DIALOG (6 Nov 2026, Broc) - this
   * year and next, what they are asking for, their parents, brothers and sisters,
   * applications and support plans - the window.openChild() the Placement board
   * opens. The room line below still goes to the board's cell, which answers a
   * different question (who else is in that room, what is free).
   *
   * One per centre the child is ENROLLED at, by the ChildID Kidsoft issued THERE:
   * the ids are per service, so a merged card's single kidsoft_child_id would
   * open the right child at one centre and nobody at the other. A supervisor's
   * card has already lost every centre they do not hold (search_scope_results()),
   * so nothing here can offer a door the endpoint would refuse.
   */
  function childButtons(r) {
    var ec = (typeof window.openChild === 'function' && r.enrolled_centres) || [];
    return ec.map(function (e, n) {
      return '<button type="button" class="sr-child' + (n ? ' sr-child-also' : '') + '"' +
        ' data-centre="' + e.centre_id + '" data-ks="' + esc(e.child_id) + '"' +
        ' data-name="' + esc(r.name) + '"' +
        (ec.length > 1 ? ' title="' + esc(r.name + ' at ' + e.centre) + '"' : '') + '>' +
        (n ? 'also at ' + esc(e.centre) : esc(r.name)) + '</button>';
    });
  }

  function resultCard(r, i, year) {
    var many  = r.centres && r.centres.length > 1;
    var where = many ? r.centres.length + ' centres'
                     : (r.centres && r.centres[0]) || r.centre;

    var meta = [];
    // Which centres, on hover. The card names them line by line underneath, but
    // since the server started folding a child's several records into one card
    // (search_merge_children) this counts more of them, and a child enrolled at
    // two centres at once should not need the lines read to find out where.
    if (where) meta.push(many ? '<span title="' + esc(r.centres.join(', ')) + '">' + esc(where) + '</span>'
                              : esc(where));
    var age = ageLabel(r.age_months);
    if (age) meta.push(age);
    if (r.dob) meta.push('b. ' + fmtDate(r.dob));

    var kids = childButtons(r);
    return '<div class="sr-card" data-i="' + i + '">' +
      '<div class="sr-top">' +
        '<div class="sr-name">' + (kids.length ? kids[0] : esc(r.name)) +
          (r.nickname ? ' <span class="text-muted">"' + esc(r.nickname) + '"</span>' : '') +
          kids.slice(1).join('') +
        '</div>' +
        statusBadge(r) +
        // Beside the status, because this is the card a call from a family we
        // ended care for brings up - see dne_for_child_links().
        dneBadge(r.dne) +
      '</div>' +
      '<div class="sr-meta">' + meta.join(' &middot; ') + '</div>' +
      matchLine(r) +
      contactStrip(r) +
      // Where they are now, before where they are planned or queued: it is the
      // answer to "who is this" for an enrolled child, and it is what Enter opens.
      roomLines(r) +
      placementLines(r, year) +
      waitlistLines(r) +
      historyLines(r) +
      '</div>';
  }

  function render(res) {
    STATUSES = res.statuses || STATUSES;
    // `res.weekdays` is still published and is still one definition of the
    // weekday names - it is just no longer THIS file's, because the strip moved
    // to window.dayStrip() and every screen draws the same five labels from
    // there. Assigning it here after the local map went was a live
    // ReferenceError that killed render() on the first keystroke.

    if (!res.results.length) {
      $results.html(emptyState('fa-magnifying-glass', 'Nothing matched "' + res.query.raw + '"',
        res.query.is_phone
          ? 'Try fewer digits; the last six are usually enough.'
          : 'Try a surname on its own, or the parent\'s mobile number.'));
      $took.text('');
      active = -1;
      return;
    }

    var head = '';
    if (res.degraded && res.degraded.length) {
      // A centre Kidsoft would not answer for is a HOLE in the results, and a
      // family missing from a search reads as a family we do not have.
      head = '<div class="alert alert-warning m-3 mb-0 py-2 small">Could not reach Kidsoft for ' +
             esc(res.degraded.join(', ')) + '. Enrolled families there are missing from these results.</div>';
    }
    var c = res.counts;
    var summary = [];
    if (c.enrolled) summary.push(c.enrolled + ' enrolled');
    if (c.waitlist) summary.push(c.waitlist + ' waiting');
    if (c.past) summary.push(c.past + ' past application' + (c.past === 1 ? '' : 's'));
    if (c.archived) summary.push(c.archived + ' not enrolled');
    if (c.total > res.results.length) summary.push('showing the first ' + res.results.length);
    head += '<div class="sr-summary">' +
            summary.map(esc).join(' &middot; ') + '</div>';

    $results.html(head + res.results.map(function (r, i) {
      return resultCard(r, i, res.year);
    }).join(''));
    $took.text(res.took_ms + ' ms');
    setActive(0);
  }

  // --- Keyboard ---------------------------------------------------------------

  function setActive(i) {
    var $cards = $results.find('.sr-card');
    if (!$cards.length) { active = -1; return; }
    active = Math.max(0, Math.min(i, $cards.length - 1));
    $cards.removeClass('active').eq(active).addClass('active');
    var el = $cards.get(active);
    if (el && el.scrollIntoView) el.scrollIntoView({ block: 'nearest' });
  }

  $q.on('keydown', function (e) {
    if (e.key === 'ArrowDown') { e.preventDefault(); setActive(active + 1); }
    else if (e.key === 'ArrowUp') { e.preventDefault(); setActive(active - 1); }
    else if (e.key === 'Enter') {
      // Enter opens the highlighted result: an ENROLLED child's own dialog, the
      // fullest answer to "who is this" (6 Nov 2026); otherwise the first link -
      // their queue entry, a board row, a past application.
      var $card = $results.find('.sr-card').eq(active);
      var $child = $card.find('.sr-child').first();
      if ($child.length) { e.preventDefault(); $child.trigger('click'); return; }
      var $first = $card.find('a.sr-line').first();
      if ($first.length) { e.preventDefault(); window.location = $first.attr('href'); }
    }
  });

  // THE PALETTE STEPS ASIDE FOR THE CHILD rather than stacking under it:
  // Bootstrap 5 does not stack modals, and a second backdrop would leave Esc
  // closing the wrong one. The results stay, so Ctrl+K brings them straight back.
  // A save there fires `child:changed`, which the Placement board listens for so
  // a flag set from here shows on the grid behind without a reload.
  $results.on('click', '.sr-child', function () {
    var who = { centre_id: +$(this).data('centre'), ks_id: String($(this).data('ks')),
                name: String($(this).data('name')) };
    $modal.one('hidden.bs.modal', function () {
      window.openChild(who, function () { $(document).trigger('child:changed'); });
    });
    modal.hide();
  });

  // --- Loading ----------------------------------------------------------------

  function run() {
    var q = $.trim($q.val());
    if (q === lastQuery) return;
    lastQuery = q;

    if (inflight) { inflight.abort(); inflight = null; }
    if (q.length < 2) {
      // NOT "Who is on the phone?" (17 Aug 2026, Broc). Answering the call is
      // what this was built for, but naming it in the empty state reads as the
      // only thing it is for, and the team also opens this to find a family
      // before ringing THEM, or while reading an email, or mid-way through a
      // tour. Say what it searches; the caller is one of the reasons, not the
      // permission slip.
      $results.html(emptyState('fa-magnifying-glass', 'Find a child or a family',
        'Search a phone number, a child, or a parent. Partial spellings and the last few digits of a number both work.'));
      $took.text('');
      return;
    }

    $results.html(spinner('Searching every centre...'));
    inflight = $.getJSON('/api/search', { q: q })
      .done(function (res) {
        // api_fail() answers HTTP 200 with ok:false, so this must be checked
        // here and not only in .fail() - see _incl/api.php.
        if (!res.ok) { $results.html('<div class="alert alert-danger m-3">' + esc(res.error) + '</div>'); return; }
        if ($.trim($q.val()) !== q) return;      // a newer keystroke has already won
        render(res);
      })
      .fail(function (xhr, status) {
        if (status === 'abort') return;
        $results.html('<div class="alert alert-danger m-3">Search failed. Try again.</div>');
      });
  }

  $q.on('input', function () {
    clearTimeout(timer);
    // Long enough that typing a ten-digit number is one request, not ten.
    timer = setTimeout(run, 250);
  });

  // --- Opening ----------------------------------------------------------------

  function open(prefill) {
    modal.show();
    if (prefill !== undefined && prefill !== null) $q.val(prefill);
  }

  $('#search-open').on('click', function () { open(); });

  $modal.on('shown.bs.modal', function () {
    $q.trigger('focus').trigger('select');
    if (!$results.children().length) { lastQuery = null; run(); }
  });

  // Ctrl/Cmd+K anywhere. THERE IS NO "/" SHORTCUT (17 Aug 2026, Broc): a bare
  // printable character as a global shortcut is only safe while the guard that
  // suppresses it - "is the target an input" - catches every place text is
  // being typed, and it does not. A note being written anywhere the caret is not
  // in a plain <input>/<textarea> swallowed the slash and opened the palette
  // mid-sentence. Ctrl+K is the shortcut, it is on the sidebar button, and it
  // cannot be typed by accident.
  $(document).on('keydown', function (e) {
    if (!(e.ctrlKey || e.metaKey) || (e.key !== 'k' && e.key !== 'K')) return;
    // NOT OVER ANOTHER MODAL. Every other dialog in this app is a question the
    // user is part-way through answering - an offer, a note, a placement - and
    // stacking the palette on top of one buries that work behind a second
    // backdrop with no way back to it but Esc, which also discards it.
    if ($('.modal.show').not($modal).length) return;
    e.preventDefault();
    open();
  });

  // Let any screen open the palette with a query already in it, e.g. from a
  // "who do I ring about this?" button.
  window.openSearch = open;

})(jQuery);
