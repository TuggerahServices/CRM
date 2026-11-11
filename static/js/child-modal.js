/**
 * One enrolled child - everything this app knows about them, on one dialog.
 *
 * Asked for on 6 Nov 2026. The only dialog an enrolled child had was the
 * placement editor: a name, a date of birth, five ticks and a note. A family
 * asking Kidsoft for an extra Wednesday showed on the board as "Waitlisted",
 * could be found nowhere on the Waitlist, and had a name that opened nothing,
 * because there was nothing worth opening.
 *
 * Markup: _incl/child-modal.php. Data: /api/child, built by
 * _incl/child-profile.php out of engines other screens already run, so nothing
 * here is a second answer to a question another screen owns.
 *
 * Public surface:
 *   window.openChild({centre_id, ks_id, year, room_id}, onChanged)
 *
 * onChanged() runs after the flags or the note are saved - the only thing the
 * dialog writes, through /api/board `save_placement`, the endpoint that already
 * owns them. Everything else is read.
 *
 * CHROME, NOT A PAGE'S (6 Nov 2026): layout_footer() renders the markup and
 * loads this file on every screen, because the search palette opens it too.
 * Depends on app.js (esc, dayStrip, roomIcon, toast) and, where the host page
 * includes them, on window.openWaitlistFamily and window.openWorkItem. A host
 * without them gets a link to the screen that owns the record - see appLink().
 */
(function ($) {
  'use strict';

  var modal = null;
  var onChanged = function () {};
  // WHO IS OPEN, so a brother or sister can be opened in place and a late
  // response for the previous child is ignored. One element serves every child.
  var current = null;
  var seq = 0;

  var DAY  = { 1: 'Mon', 2: 'Tue', 3: 'Wed', 4: 'Thu', 5: 'Fri' };
  var LONG = { 1: 'Monday', 2: 'Tuesday', 3: 'Wednesday', 4: 'Thursday', 5: 'Friday' };

  function m() {
    modal = modal || new bootstrap.Modal(document.getElementById('ch-modal'));
    return modal;
  }

  /** Months as the team says them: "2y 11m", "7m". Same as the board's. */
  function ageLabel(months) {
    if (months === null || months === undefined) return '';
    var y = Math.floor(months / 12), mo = months % 12;
    return (y ? y + 'y' + (mo ? ' ' + mo + 'm' : '') : mo + 'm');
  }

  function dayList(days) {
    return (days || []).map(function (d) { return DAY[d]; }).join(', ');
  }

  function callLink(num, label) {
    if (!num) return '';
    return '<a class="cp-tel" href="tel:' + esc(String(num).replace(/[^0-9+]/g, '')) + '">' +
      '<i class="fa-solid fa-phone fa-xs"></i>' + esc(num) + '</a>' +
      (label ? '<span class="text-muted small ms-1">(' + esc(label) + ')</span>' : '');
  }

  function section(title, count, body) {
    return '<h6 class="cp-h">' + esc(title) +
      (count ? ' <span>' + count + '</span>' : '') + '</h6>' + body;
  }

  // --- Opening ----------------------------------------------------------------

  window.openChild = function (who, done) {
    onChanged = done || function () {};
    current = who;
    var mine = ++seq;
    $('#ch-title').text(who.name || 'Child');
    $('#ch-sub').empty();
    $('#ch-save').addClass('d-none');
    $('#ch-body').html(window.spinner ? spinner('Reading Kidsoft...') : '');
    m().show();

    $.getJSON('/api/child', { action: 'get', centre_id: who.centre_id, child: who.ks_id,
                              year: who.year || '', room_id: who.room_id || '' })
      .done(function (res) {
        if (mine !== seq) return;   // another child was opened meanwhile
        if (!res.ok) {
          $('#ch-body').html('<div class="alert alert-warning mb-0">' + esc(res.error) + '</div>');
          return;
        }
        render(res);
      })
      .fail(function () {
        if (mine !== seq) return;
        $('#ch-body').html('<div class="alert alert-warning mb-0">Could not open this child.</div>');
      });
  };

  // --- Rendering --------------------------------------------------------------

  function render(p) {
    var c = p.child;
    current = $.extend({}, current, { name: c.name, centre_id: c.centre_id, ks_id: c.ks_id,
                                      status: p.edit ? p.edit.status : null });
    $('#ch-title').text(c.name);
    $('#ch-sub').html(
      [c.dob ? 'b. ' + esc(c.dob) : '', c.age_months !== null ? esc(ageLabel(c.age_months)) : '',
       esc(c.centre_name)].filter(Boolean).join(' &middot; ') +
      (c.active ? '' : ' <span class="badge text-bg-secondary ms-1">Archived in Kidsoft</span>'));

    var left = enrolmentBlock(p) + askingBlock(p) + queueBlock(p) + plansBlock(p);
    var right = familyBlock(p);

    $('#ch-body').html(
      '<div class="row g-4">' +
        '<div class="col-md-7">' + left + '</div>' +
        '<div class="col-md-5">' + right + '</div>' +
      '</div>' + flagsBlock(p));
    $('#ch-save').toggleClass('d-none', !p.edit);
  }

  /**
   * THIS YEAR AND NEXT, side by side in one list. Next year is the one being
   * planned from August, so a dialog that stopped at this year would hide the
   * round the team is actually working.
   */
  function enrolmentBlock(p) {
    var rows = p.enrolment.map(function (e) {
      var lines = '';
      // DAYS THE CENTRE IS KEEPING FOR THEM go on the room they belong to, in
      // the strip's amber, because a hold is SUPPLY - part of what they have -
      // and never part of what they are asking for. A hold on a room they are
      // booked on no day of at all still gets its own line.
      var holds = p.holds.filter(function (h) { return h.year === e.year; });
      var rooms = e.rooms.slice();
      holds.forEach(function (h) {
        if (!rooms.some(function (r) { return r.room_id === h.room_id; })) {
          rooms.push({ room_id: h.room_id, room_name: h.room_name, days: [] });
        }
      });
      if (e.not_open) {
        lines = '<div class="ch-none">--</div>';
      } else if (rooms.length) {
        lines = rooms.map(function (r) {
          var when = [r.starts_on ? 'from ' + r.starts_on : '', r.ends_on ? 'to ' + r.ends_on : '']
            .filter(Boolean).join(' ');
          var h = holds.filter(function (x) { return x.room_id === r.room_id; })[0];
          return '<div class="ch-room">' +
            dayStrip({ has: r.days, held: h ? h.days : undefined }) +
            '<span class="ch-room-name">' + roomIcon(r.room_name) + esc(r.room_name) + '</span>' +
            (when ? ' <span class="cp-when">' + esc(when) + '</span>' : '') +
            '</div>' +
            (h ? '<div class="cp-meta ch-hold"><i class="fa-solid fa-lock fa-xs me-1"></i>' +
                 esc(dayList(h.days)) + ' held for them' + (h.from ? ' from ' + esc(h.from) : '') +
                 (h.reason ? ': ' + esc(h.reason) : '') + '</div>' : '');
        }).join('');
      } else if (e.reenrolment_hold.length) {
        // NOT BOOKED, BUT THE PLACE IS BEING KEPT while the family answers.
        lines = '<div class="ch-none">Not booked</div>' + e.reenrolment_hold.map(function (h) {
          return '<div class="ch-room">' + dayStrip({ plain: h.days, plainSaid: 'place held for them' }) +
            '<span class="ch-room-name">' + roomIcon(h.room_name) + esc(h.room_name) + '</span>' +
            ' <span class="cp-when">place held to ' + esc(h.hold_until) + '</span></div>';
        }).join('');
      } else {
        lines = '<div class="ch-none">Not booked</div>';
      }

      var notes = [];
      if (e.leaving) {
        notes.push('<span class="text-danger-emphasis"><i class="fa-solid fa-person-walking-arrow-right fa-xs me-1"></i>' +
          'Leaving ' + esc(e.leaving.on) + (e.leaving.reason ? ' (' + esc(e.leaving.reason) + ')' : '') + '</span>');
      }
      if (p.reenrolment && p.reenrolment.for_year === e.year) {
        notes.push(p.reenrolment.outcome === 'declined'
          ? '<span class="text-danger-emphasis">Declined re-enrolment ' + esc(p.reenrolment.on) + '</span>'
          : 'Accepted re-enrolment ' + esc(p.reenrolment.on));
      }
      // MAY START is a family's choice and most do not take it; MUST is the law.
      // Two facts - see school_may_start() in _incl/reenrolment.php.
      if (e.school === 'must') notes.push('Must be at school in ' + e.year);
      else if (e.school === 'may' && !e.rooms.length) notes.push('Old enough for school in ' + e.year);

      return '<div class="ch-year">' +
        '<div class="ch-year-n">' + e.year + '</div>' +
        '<div class="ch-year-body">' + lines +
          (notes.length ? '<div class="cp-meta">' + notes.join(' &middot; ') + '</div>' : '') +
        '</div></div>';
    }).join('');
    return section('Enrolment', 0, rows);
  }

  /** The room's days this year for a request card, so "has" is drawn green. */
  function heldIn(p, year, roomId) {
    var e = p.enrolment.filter(function (x) { return x.year === year; })[0];
    var r = e ? e.rooms.filter(function (x) { return x.room_id === roomId; })[0] : null;
    return r ? r.days : [];
  }

  /**
   * MORE DAYS THEY HAVE ASKED FOR. Held days are not here - see enrolmentBlock().
   *
   * A Kidsoft request is named as one. Its status is Kidsoft's word for the
   * REQUEST - "Waitlisted" means the day has not been granted - and it read as
   * "on the Waitlist" on the board, which is how this dialog came to be asked
   * for: the family was nowhere on that screen because they are not in the
   * enrolment queue at all.
   */
  function askingBlock(p) {
    var cards = p.asking.map(function (q) {
      var has = heldIn(p, q.year, q.room_id);
      var when = q.source === 'internal' ? 'any ' + q.days.map(function (d) { return LONG[d]; }).join(', ')
        : (q.dates.length > 1 ? q.dates.length + ' dates from ' + q.dates[0] : (q.dates[0] || ''));
      var badge = q.source === 'internal'
        ? '<span class="badge text-bg-light border" title="Recorded here from a phone call. ' +
          'Kidsoft has no field for a standing request like this.">Noted here</span>'
        : '<span class="badge text-bg-light border" title="A day request in Kidsoft. ' +
          'This is not the enrolment waitlist.">Kidsoft day request: ' + esc(q.status) + '</span>';
      return '<div class="cp-card ch-card">' +
        '<div class="cp-strip">' + dayStrip({ has: has, want: q.days }) +
          '<span class="cp-strip-txt">wants ' + esc(dayList(q.days)) +
          (when ? ' <span class="text-muted">' + esc(when) + '</span>' : '') + '</span></div>' +
        '<div class="ch-line">' + badge +
          '<span class="cp-sub">' + esc(q.room_name) + (q.year !== p.enrolment[0].year ? ', ' + q.year : '') +
          (q.asked_on ? ' &middot; asked ' + esc(q.asked_on) : '') + '</span></div>' +
        (q.note ? '<div class="small wish-note mt-1">' + esc(q.note) + '</div>' : '') +
        '</div>';
    });
    if (!cards.length) return '';
    return section('Asking for', cards.length, cards.join(''));
  }

  /**
   * A WAY INTO AN APPLICATION OR AN IN PROGRESS ITEM, WHATEVER SCREEN THIS IS.
   *
   * Where the host page carries that dialog, a button opens it (handing this one
   * off). Where it does not - this dialog is chrome since the search palette
   * started opening it, so it can be on the Dashboard or Support plans - a link
   * goes to the screen that owns the record, which opens it on arrival: the same
   * `?wl=` and `?item=` deep links search and the Outlook tour entries use.
   * Never a name that opens nothing, which is the fault this dialog was built to
   * end.
   */
  function appLink(a, label, cls) {
    var c = 'cp-name' + (cls ? ' ' + cls : '');
    if (typeof window.openWaitlistFamily === 'function') {
      return '<button type="button" class="' + c + ' ch-open-app" data-id="' + a.id + '">' + esc(label) + '</button>';
    }
    return '<a class="' + c + '" href="/waitlist?centre=' + a.centre_id + '&amp;wl=' + a.id + '">' + esc(label) + '</a>';
  }
  function workLink(w, centreId, label) {
    if (typeof window.openWorkItem === 'function') {
      return '<button type="button" class="cp-name ch-open-work" data-id="' + w.id + '">' + esc(label) + '</button>';
    }
    return '<a class="cp-name" href="/progress?centre=' + centreId + '&amp;item=' + w.id + '">' + esc(label) + '</a>';
  }

  /** Their own applications and anything In progress for them. */
  function queueBlock(p) {
    var enrolledHere = p.enrolment[0].rooms.reduce(function (a, r) { return a.concat(r.days); }, []);

    var cards = p.applications.map(function (a) {
      if (!a.scoped) {
        return '<div class="cp-card ch-card"><span class="cp-sub">Waiting at ' + esc(a.centre_name) + '</span></div>';
      }
      var title = appLink(a, 'Waitlist application');
      return '<div class="cp-card ch-card">' +
        '<div class="cp-head">' + title +
          '<span class="cp-sub">' + esc(a.centre_name) + (a.for_year ? ', ' + a.for_year : '') + '</span>' +
          (a.status ? '<span class="cp-badges"><span class="badge text-bg-light border">' + esc(a.status) + '</span></span>' : '') +
        '</div>' +
        '<div class="cp-strip">' +
          dayStrip({ has: a.centre_id === p.child.centre_id ? enrolledHere : [], want: a.days }) +
          '<span class="cp-strip-txt">wants ' + esc(dayList(a.days)) +
          (a.from ? ' <span class="text-muted">from ' + esc(a.from) + '</span>' : '') + '</span></div>' +
        (a.added_on ? '<div class="cp-meta">Added ' + esc(a.added_on) + '</div>' : '') +
        '</div>';
    });
    p.work.forEach(function (w) {
      var title = workLink(w, p.child.centre_id, 'In progress');
      cards.push('<div class="cp-card ch-card"><div class="cp-head">' + title +
        '<span class="cp-sub">' + esc([w.room_name, w.year].filter(Boolean).join(', ')) + '</span>' +
        '<span class="cp-badges"><span class="badge ' + esc(window.workStageClass ? workStageClass(w.stage) : 'text-bg-light') +
        '">' + esc(w.stage_label) + '</span></span></div></div>');
    });
    if (!cards.length) return '';
    return section('Waitlist and In progress', 0, cards.join(''));
  }

  function plansBlock(p) {
    if (!p.plans.length) return '';
    return section('Support plans', 0, p.plans.map(function (s) {
      var bits = [];
      if (s.outcome) bits.push(esc(s.outcome));
      else if (s.commenced) bits.push('started ' + esc(s.commenced));
      else bits.push('not started');
      if (s.next_step) bits.push('next: ' + esc(s.next_step));
      if (s.overdue) bits.push('<span class="text-danger-emphasis">' + s.overdue + ' overdue</span>');
      return '<div class="cp-card ch-card"><div class="cp-head">' +
        '<a class="cp-name" href="/support-plans?centre=' + p.child.centre_id + '&amp;plan=' + s.placement_id + '">' +
        esc(s.label) + '</a></div>' +
        '<div class="cp-meta mt-0">' + bits.join(' &middot; ') + '</div></div>';
    }).join(''));
  }

  /** "waiting for 2028", naming the centre only when it is not this one. */
  function waitingLabel(p, w) {
    return 'waiting' + (w.centre_id !== p.child.centre_id ? ' at ' + w.centre_name : '') +
      (w.for_year ? ' for ' + w.for_year : '');
  }

  /**
   * WHO TO RING, AND WHO ELSE IS IN THE FAMILY.
   *
   * Parents only - the enrolling guardian, a Mother or a Father - in Kidsoft's
   * own order. Brothers and sisters enrolled here share one of those parent
   * records in Kidsoft; those waiting share a parent's email or number, the rule
   * the Waitlist ranks siblings by.
   */
  function familyBlock(p) {
    var parents = p.parents.map(function (g) {
      var contact = callLink(g.phone, g.phone_is_parent === false ? g.phone_type : null);
      if (g.email) {
        contact += '<a class="cp-mail text-truncate" href="mailto:' + esc(g.email) + '">' +
          '<i class="fa-solid fa-envelope fa-xs"></i>' + esc(g.email) + '</a>';
      }
      return '<div class="ch-parent">' +
        '<div><span class="fw-semibold">' + esc(g.name) + '</span>' +
        (g.relationship ? ' <span class="cp-sub">' + esc(g.relationship) + '</span>' : '') +
        (g.enrolling ? ' <span class="badge text-bg-light border" title="Holds the enrolment in Kidsoft">Enrolling</span>' : '') +
        '</div>' +
        (contact ? '<div class="cp-contact mt-1">' + contact + '</div>' : '') +
        '</div>';
    }).join('') || '<div class="ch-none">No parent on the Kidsoft record</div>';

    var sibs = p.siblings.map(function (s) {
      var where = s.rooms.map(function (r) {
        return '<div class="ch-room">' + dayStrip({ has: r.days }) +
          '<span class="ch-room-name">' + roomIcon(r.room_name) + esc(r.room_name) + '</span></div>';
      }).join('') || '<div class="cp-meta mt-0">Not booked this year</div>';
      // AND WAITING TOO, on the same child rather than as a second one.
      var apps = (s.applications || []).map(function (w) {
        if (!w.scoped) return '<div class="cp-meta mt-0">Also waiting at ' + esc(w.centre_name) + '</div>';
        return '<div class="ch-room">' + dayStrip({ want: w.days }) +
          appLink(w, waitingLabel(p, w), 'ch-small') + '</div>';
      }).join('');
      return '<div class="ch-sib">' +
        '<div class="cp-head mb-1"><button type="button" class="cp-name ch-open-child" data-ks="' + esc(s.ks_id) + '">' +
        esc(s.name) + '</button>' +
        (s.age_months !== null ? '<span class="cp-sub">' + esc(ageLabel(s.age_months)) + '</span>' : '') +
        '</div>' + where + apps + '</div>';
    });
    p.waiting_siblings.forEach(function (w) {
      if (!w.scoped) {
        sibs.push('<div class="ch-sib"><span class="cp-sub">A brother or sister is waiting at ' +
          esc(w.centre_name) + '</span></div>');
        return;
      }
      var name = appLink(w, w.name);
      sibs.push('<div class="ch-sib">' +
        '<div class="cp-head mb-1">' + name +
        '<span class="cp-sub">' + esc(waitingLabel(p, w)) + '</span></div>' +
        '<div class="ch-room">' + dayStrip({ want: w.days }) +
        (w.from ? '<span class="cp-when">from ' + esc(w.from) + '</span>' : '') + '</div>' +
        '</div>');
    });

    return section('Parents', 0, parents) +
      (sibs.length ? section('Brothers and sisters', sibs.length, sibs.join('')) : '');
  }

  /**
   * OURS: the flags and the note. Stored per (child, room, year), because that
   * is what the board annotates - so where a child holds two rooms the dialog
   * says which one these belong to.
   */
  function flagsBlock(p) {
    var e = p.edit;
    if (!e) return '';
    function tick(name, id, label) {
      return '<div class="form-check">' +
        '<input class="form-check-input" type="checkbox" name="' + name + '" value="1" id="' + id + '"' +
        (e[name] ? ' checked' : '') + '>' +
        '<label class="form-check-label" for="' + id + '">' + esc(label) + '</label></div>';
    }
    return '<div class="ch-ours">' +
      section('Flags and note' + (e.ambiguous ? ', ' + e.room_name + ' ' + e.year : ''), 0,
        '<input type="hidden" name="room_id" value="' + e.room_id + '">' +
        '<input type="hidden" name="year" value="' + e.year + '">' +
        '<div class="place-flags mb-3">' +
          '<div class="place-flags-row">' +
            tick('is_sibling', 'ch-f-sib', 'Sibling') +
            tick('is_staff_child', 'ch-f-staff', "Staff's child") +
            tick('needs_two_educators', 'ch-f-two', 'Needs two educators') +
          '</div>' +
          '<div class="place-flags-row">' +
            tick('has_behaviour_plan', 'ch-f-beh', 'Behaviour Support Plan') +
            tick('has_individual_plan', 'ch-f-isp', 'Individual Support Plan') +
          '</div>' +
        '</div>' +
        '<label class="form-label small text-muted mb-1" for="ch-notes">Note</label>' +
        '<input class="form-control" name="notes" id="ch-notes" maxlength="500" value="' +
          esc(e.notes || '') + '">') +
      '</div>';
  }

  // --- Actions ----------------------------------------------------------------

  /**
   * ANOTHER DIALOG REPLACES THIS ONE rather than stacking on it - Bootstrap 5
   * does not stack modals, and two backdrops leave Esc closing the wrong one.
   */
  function handOff(fn) {
    var $m = $('#ch-modal');
    $m.one('hidden.bs.modal', fn);
    m().hide();
  }

  /**
   * ANY SCREEN'S child name - window.childName() in app.js. Handled here, once,
   * for every screen. A name inside another open dialog (the family modal, the
   * work modal) has that dialog step aside first, the way the search palette
   * does, because Bootstrap 5 does not stack them. Stops at the name, so a row
   * that opens something of its own when clicked does not open it as well.
   * A save fires `child:changed`, which a screen showing flags can listen for.
   */
  $(document).on('click', '.ch-name', function (e) {
    e.preventDefault();
    e.stopPropagation();
    var who = { centre_id: +$(this).data('centre'), ks_id: String($(this).data('ks')),
                name: String($(this).data('name')) };
    var go = function () {
      window.openChild(who, function () { $(document).trigger('child:changed'); });
    };
    var $host = $(this).closest('.modal.show').not('#ch-modal');
    if (!$host.length) { go(); return; }
    $host.one('hidden.bs.modal', go);
    bootstrap.Modal.getOrCreateInstance($host[0]).hide();
  });

  $(document)
    .on('click', '#ch-modal .ch-open-app', function () {
      var id = +$(this).data('id');
      handOff(function () { window.openWaitlistFamily(id, onChanged); });
    })
    .on('click', '#ch-modal .ch-open-work', function () {
      var id = +$(this).data('id');
      handOff(function () { window.openWorkItem(id, onChanged); });
    })
    // A brother or sister enrolled here opens in place: same centre, same dialog.
    .on('click', '#ch-modal .ch-open-child', function () {
      window.openChild({ centre_id: current.centre_id, ks_id: String($(this).data('ks')),
                         year: current.year, name: $(this).text() }, onChanged);
    });

  $(document).on('submit', '#ch-form', function (e) {
    e.preventDefault();
    if (!current) return;
    // THE ENDPOINT THAT ALREADY OWNS THESE. For a linked child it saves the
    // flags and the note only and keeps Kidsoft's days and status whatever is
    // posted - see save_placement in api/board.php. The status sent back is the
    // row's own, so the endpoint has nothing to refuse and nothing to warn about.
    // No double-click guard here: app.js holds the form while the POST is out.
    $.post('/api/board', $(this).serialize() + '&action=save_placement&kidsoft_child_id=' +
                         encodeURIComponent(current.ks_id) +
                         '&status=' + encodeURIComponent(current.status || 'confirmed'))
      .done(function (res) {
        if (!res.ok) { toast(res.error, 'danger'); return; }
        m().hide();
        (res.warnings || []).forEach(function (w) { toast(w, 'warning'); });
        if (!(res.warnings || []).length) toast('Saved.');
        onChanged();
      })
      .fail(function () { toast('Could not save.', 'danger'); });
  });
})(jQuery);
