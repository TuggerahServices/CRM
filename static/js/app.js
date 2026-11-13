// App-wide JS. jQuery-first (see CLAUDE.md).

(function ($) {
  'use strict';

  // Published by layout_header() as <meta name="csrf-token">. A meta tag rather
  // than an inline <script> so the CSP can forbid inline script outright.
  window.CSRF = $('meta[name="csrf-token"]').attr('content') || '';
  // The signed-in user's email, published the same way. Used for "It's mine" on
  // a work item; never trusted server-side, which reads the session.
  window.USER_EMAIL = $('meta[name="user-email"]').attr('content') || '';
  // Draws the Delete on a logged note - see commDeleteBtn(). /api/comms checks
  // the role again on the click.
  window.USER_IS_ADMIN = $('meta[name="user-admin"]').attr('content') === '1';
  // When an offer is urgent. THE VALUES LIVE IN _incl/offer-policy.php and are
  // published by layout_header() as meta tags - change them there, never here,
  // and never re-type either number at a call site. The literals below are a
  // last-resort default for a page that somehow rendered without the meta (every
  // page goes through layout_header, so that is a bug rather than a mode);
  // grading silently on NaN would be worse than grading on 72/48.
  window.OFFER_URGENT_HOURS = +$('meta[name="offer-urgent-hours"]').attr('content') || 72;
  window.OFFER_COUNTDOWN_HOURS = +$('meta[name="offer-countdown-hours"]').attr('content') || 48;

  /**
   * How urgent an offer is, from the hours left on its clock.
   *
   * `hours` is ALWAYS the server's figure - MySQL TIMESTAMPDIFF against NOW(),
   * which is Sydney. Never compute it from expires_at in the browser: that
   * reads a Sydney wall-clock string in the viewer's timezone, which is the
   * reinterpretation _incl/dates.php forbids and which the Waitlist's summary
   * tile was doing until 13 Aug 2026.
   *
   * The three screens that show an offer word it differently and grade it
   * identically, so only the grading lives here - the wording stays at the
   * call site.
   *
   *   band 'lapsed'  the clock ran out; say so, never count backwards from it
   *   band 'hours'   under two days; count in hours, because "31 hours" moves
   *                  somebody and "1 day" does not
   *   band 'days'    everything else, red while inside three days
   *
   * @returns {?{band:string, urgent:boolean, cls:string, hours:number, days:number}}
   *          null when there is no live offer to grade.
   */
  window.offerUrgency = function (hours) {
    if (hours === null || hours === undefined || isNaN(hours)) return null;
    var red = 'text-danger fw-semibold';
    if (hours < 0) return { band: 'lapsed', urgent: true, cls: red, hours: hours, days: 0 };
    if (hours < window.OFFER_COUNTDOWN_HOURS) {
      return { band: 'hours', urgent: true, cls: red, hours: hours, days: 0 };
    }
    var urgent = hours <= window.OFFER_URGENT_HOURS;
    return { band: 'days', urgent: urgent, cls: urgent ? red : '',
             hours: hours, days: Math.floor(hours / 24) };
  };

  /**
   * The badge colour for a work item's stage. Same idea as offerUrgency above:
   * TWO SCREENS SHOW THE PIPELINE and they must not grade it differently, so the
   * colour lives here and the wrapper stays at the call site - In progress has
   * a whole Stage column and needs no icon on it, the Waitlist queue hangs the
   * badge under an application status and marks it with `fa-list-check` so the
   * two are not read as one thing.
   *
   * ONLY TWO STAGES ARE COLOURED, and that is the decision worth keeping:
   *
   *   accepted   AMBER, because the move is OURS. They have said yes and the
   *              place is not in Kidsoft yet. This is the state the whole thing
   *              was built for - a Kanwal family sat accepted behind a grey
   *              badge and a green "Offer" button, and the row read as though
   *              nobody had touched it.
   *   enrolled   GREEN, and green means FINISHED. Kidsoft shows the child in the
   *              room. Nothing else may be green or the colour stops meaning it.
   *
   * Everything before an offer stays grey on purpose. "Somebody has rung them"
   * is context, not a state to act on, and colouring all eight would leave the
   * eye nothing to land on - which is the same mistake as colouring none of
   * them, arrived at from the other direction. `offered` stays grey too: on In
   * progress the urgency column beside it is already counting that clock down
   * in red, and a second amber would compete with `accepted` for the one colour
   * that means "act on this".
   */
  window.workStageClass = function (stage) {
    if (stage === 'accepted') return 'text-bg-warning';
    if (stage === 'enrolled') return 'text-bg-success';
    return 'text-bg-light border text-muted';
  };

  // Attach it to every mutating AJAX request, so endpoints only need
  // csrf_require() server-side.
  $.ajaxSetup({
    beforeSend: function (xhr, settings) {
      if (window.CSRF && !/^(GET|HEAD|OPTIONS)$/i.test(settings.type || 'GET')) {
        xhr.setRequestHeader('X-CSRF-Token', window.CSRF);
      }
    }
  });

  // --- One save at a time (2 Nov 2026) -----------------------------------------
  // A double-clicked Save in the work modal posted the whole save twice: one
  // phone note was filed twice in the same second, and the parent's tour invite
  // was written twice. Five save handlers had no guard of their own and each
  // guarded one was guarded by hand, so it lives here, once, for every form and
  // button in the app - including the ones not written yet.
  //
  // WHATEVER STARTS A WRITE IS HELD UNTIL NOTHING IS IN FLIGHT. A form or button
  // is held when a POST goes out while its own submit or click is being handled:
  // its buttons are disabled and a second submit or click on it is swallowed
  // until jQuery's ajaxStop. ajaxStop and not the first reply, because a save is
  // often a chain (the work modal posts update, then advance, then the note) and
  // each link starts inside the last one's .done(), before jQuery's count of open
  // requests reaches zero.
  //
  // CAPTURE PHASE, and so addEventListener: jQuery cannot listen in it, and a
  // handler jQuery binds to the form itself runs before anything delegated to the
  // document could stop it. A capture listener on the document runs first.
  //
  // A button that was already disabled when the write went out - the handlers
  // that guard themselves, and users.js, which disables Add for reasons of its
  // own - is never touched, so nothing re-enables a button somebody meant to
  // keep off.
  (function () {
    var current = null;   // the form or button whose event is being handled now
    var held = [];        // [{ el, $off }] - $off is what this disabled

    function arrive(e, el) {
      if (!el) return;
      if ($(el).data('saving')) { e.preventDefault(); e.stopImmediatePropagation(); return; }
      current = el;
      // A handler that posts does so before this runs.
      setTimeout(function () { if (current === el) current = null; }, 0);
    }
    document.addEventListener('submit', function (e) { arrive(e, e.target); }, true);
    document.addEventListener('click', function (e) {
      arrive(e, $(e.target).closest('button, a.btn')[0]);
    }, true);

    function hold(el) {
      var $el = $(el);
      if ($el.data('saving')) return;
      $el.data('saving', true);
      var $off = ($el.is('form')
        ? $el.find('button, input').filter(function () { return this.type === 'submit'; })
        : $el).filter(function () { return !this.disabled && !$(this).hasClass('disabled'); });
      $off.filter('a').addClass('disabled');
      $off.not('a').prop('disabled', true);
      held.push({ el: el, $off: $off });
    }

    $(document).ajaxSend(function (e, xhr, s) {
      if (!current || /^(GET|HEAD|OPTIONS)$/i.test(s.type || 'GET')) return;
      hold(current);
      // A QUESTION ASKED IN A DIALOG (10 Nov 2026) - confirmModal() below. Its
      // answer sends the write from a click of its own, after the click that
      // asked has finished, so the dialog's form names the button that asked
      // and that is held as well. A native confirm() held it by blocking.
      var also = $(current).closest('form').data('holdAlso');
      if (also) hold(also);
    });

    /** The form or button whose submit or click is being handled now, or null. */
    window.actingElement = function () { return current; };

    $(document).ajaxStop(function () {
      $.each(held, function (i, h) {
        $(h.el).removeData('saving');
        h.$off.filter('a').removeClass('disabled');
        h.$off.not('a').prop('disabled', false);
      });
      held = [];
    });
  })();

  // --- Dates -----------------------------------------------------------------
  // Stored datetimes are Australia/Sydney wall-clock strings. Reformat the raw
  // string; never feed it through new Date(), which would re-interpret it in the
  // viewer's browser timezone (see CLAUDE.md).

  /** "2026-07-08 16:17:00" -> "8/07/2026 04:17 PM" */
  window.fmtDateTime = function (s) {
    if (!s) return '-';
    var m = /^(\d{4})-(\d{2})-(\d{2})[T ](\d{2}):(\d{2})/.exec(s);
    if (!m) return s;
    var hh = parseInt(m[4], 10);
    var ampm = hh >= 12 ? 'PM' : 'AM';
    var h12 = hh % 12 || 12;
    return parseInt(m[3], 10) + '/' + m[2] + '/' + m[1] + ' ' +
           (h12 < 10 ? '0' : '') + h12 + ':' + m[5] + ' ' + ampm;
  };

  /**
   * A stored datetime for <input type="datetime-local">.
   *
   * "2026-09-19 09:30:00" -> "2026-09-19T09:30". The stored string is already
   * Sydney wall-clock and the input wants 'Y-m-dTH:i', so this is a STRING
   * RESHAPE and nothing else - deliberately not new Date(), which is the
   * reinterpretation the rest of this block exists to avoid.
   *
   * SHARED because two modals book the same tour: the work modal, and the
   * family modal on the Waitlist since 10 Sep 2026. A second copy of a date
   * rule is how two screens come to show one appointment differently, which is
   * the exact bug that move was fixing.
   */
  window.toLocalInput = function (s) {
    if (!s) return '';
    return String(s).replace(' ', 'T').slice(0, 16);
  };

  /**
   * THE TOUR QUESTION, asked only when there is one to ask: an appointment that
   * has been and gone with nobody saying whether the family turned up. That is
   * the one way a tour disappears - see work_tour_outcome() - so it is asked in
   * both places a tour can be opened from, the work modal and the family modal
   * on the Waitlist, and asked in the same words.
   *
   * Returns '' when there is nothing to ask, which the caller uses to hide the
   * container. Whether the tour HAS happened is `tour_past`, decided on the
   * server's Sydney clock; nothing here builds a Date.
   *
   * The buttons carry data-tour="toured" / "no_show"; each host binds its own
   * click, because what to do afterwards is the host's business.
   */
  window.tourAskHtml = function (w) {
    if (!w || !w.tour_at || w.tour_outcome || !w.tour_past) return '';
    return '<div class="d-flex flex-wrap align-items-center gap-2">' +
      '<span class="small">Tour on <strong>' + esc(fmtDateTime(w.tour_at)) +
      '</strong> has been and gone. Did they come?</span>' +
      '<button type="button" class="btn btn-sm btn-outline-success" data-tour="toured">They came</button>' +
      '<button type="button" class="btn btn-sm btn-outline-secondary" data-tour="no_show">Did not show</button>' +
      '</div>';
  };

  /**
   * Whether a tour reached the centre's Outlook calendar - one line under the
   * tour box, in the work modal and the family modal alike. `cal` is the
   * server's tour_calendar_status(): null says nothing (no tour, or not written
   * yet), 'in' is a quiet confirmation, 'failed' carries the reason in words.
   * fa-solid only: fa-regular is not vendored and renders blank.
   */
  window.tourCalendarNote = function (cal) {
    if (!cal) return '';
    var where = cal.centre ? 'the ' + esc(cal.centre) + ' calendar' : 'the centre calendar';
    // ON A VISIT WITH BROTHERS AND SISTERS the entry is one, named for them all
    // (tour-calendar.php) - said, so nobody goes looking for a second one.
    if (cal.with && cal.with.length) where += ', one entry with ' + esc(cal.with.join(', '));
    // THE PARENT'S INVITE: who the entry has invited, once Exchange took it.
    if (cal.invited) where += ', invite sent to ' + esc(cal.invited);
    if (cal.state === 'in') {
      return '<div class="form-text"><i class="fa-solid fa-calendar-check me-1"></i>In ' + where + '</div>';
    }
    return '<div class="form-text text-warning-emphasis"><i class="fa-solid fa-triangle-exclamation me-1"></i>' +
      'Not in ' + where + ': ' + esc(cal.text || 'not written yet.') + '</div>';
  };

  /**
   * "Send the invite to the parent", under a tour box (29 Oct 2026). Ticked, the
   * centre's Outlook entry becomes the parent's branded invite - see THE PARENT'S
   * INVITE in tour-calendar.php. Names the address when the caller knows it, and
   * cannot be ticked when the caller knows there is none. No `name`: each form
   * posts it as `tour_invite` itself, and the family modal's form is serialised
   * whole to /api/waitlist.
   *
   * @param email the parent's address; '' for none, null when the caller cannot say
   */
  window.tourInviteBox = function (id, email, checked) {
    var known = email !== null && email !== undefined;
    email = $.trim(email || '');
    var off = known && !email;
    return '<div class="form-check mt-1">' +
      '<input class="form-check-input" type="checkbox" value="1" id="' + id + '"' +
      (checked && !off ? ' checked' : '') + (off ? ' disabled' : '') + '>' +
      '<label class="form-check-label" for="' + id + '"' + (off ? ' title="No email for this family"' : '') + '>' +
      'Send the invite to ' + (email ? esc(email) : 'the parent') + '</label></div>';
  };

  /** "2026-07-08" -> "8/07/2026" */
  window.fmtDate = function (s) {
    if (!s) return '';
    var m = /^(\d{4})-(\d{2})-(\d{2})/.exec(s);
    return m ? parseInt(m[3], 10) + '/' + m[2] + '/' + m[1] : s;
  };

  /**
   * "2026-09-01" or "2026-09" -> "Sep". For a mark or a chip that has room for
   * a month and not for a date. Same rule as the two above: read the string,
   * never build a Date from it.
   */
  var MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun',
                'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
  window.fmtMonth = function (s) {
    var m = /^\d{4}-(\d{2})/.exec(s || '');
    return m ? (MONTHS[parseInt(m[1], 10) - 1] || '') : '';
  };

  /**
   * Escape a string for insertion into HTML, INCLUDING inside a quoted attribute.
   *
   * Text-node serialization ($('<div>').text(s).html()) escapes & < > but leaves
   * quotes intact, which is safe in element content and NOT safe in title="...".
   * A child named  x" onmouseover="..."  would break out of the attribute and
   * inject an event handler. Both quote characters are escaped explicitly.
   */
  window.esc = function (s) {
    return String(s == null ? '' : s)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#39;');
  };

  /**
   * The mark for a room name, or '' - safe to concatenate in front of esc(name).
   *
   * The stem => mark map is NOT written here: it comes off the sprite's
   * data-map, declared once in PHP (ROOM_ICON_MAP in _incl/svg.php), because
   * room names are matched on both sides of the wire and two copies of that
   * rule would drift the first time a centre opens a room the fold misses.
   * Room names are not consistent - "Amazing Asteroid Purple" and Kidsoft's
   * own "Amazing Asterouds" are the same family - so the match is a stem
   * against the name folded to letters.
   */
  var ROOM_MARKS = null;
  window.roomIcon = function (name) {
    if (ROOM_MARKS === null) {
      try { ROOM_MARKS = JSON.parse($('.rm-sprite').attr('data-map') || '{}'); }
      catch (e) { ROOM_MARKS = {}; }
    }
    var fold = String(name == null ? '' : name).toLowerCase().replace(/[^a-z]+/g, '');
    for (var stem in ROOM_MARKS) {
      if (fold.indexOf(stem) !== -1) {
        return '<svg class="rm-ico" aria-hidden="true"><use href="#rmi-' +
               ROOM_MARKS[stem] + '"></use></svg>';
      }
    }
    return '';
  };

  /**
   * The mark for a contact's channel - phone, email, sms, in_person, other.
   *
   * MOVED HERE ON 18 SEP 2026 BECAUSE A THIRD COPY WAS ABOUT TO BE TYPED. The
   * same five-entry map was already sitting in waitlist-modal.js and
   * support-plans.js, and the work modal's timeline is the third surface to
   * render a logged contact. The stat tile reached six copies and the mark bar
   * three before either was pulled up here; the channel mark is stopping at two.
   *
   * Unknown channels fall back rather than rendering an empty <i>, because the
   * enum can gain a value in the database before every screen has heard of it.
   */
  var CHANNEL_ICON = { phone: 'fa-phone', email: 'fa-envelope', sms: 'fa-comment-sms',
                       in_person: 'fa-user', other: 'fa-note-sticky' };
  window.channelIcon = function (channel) {
    return 'fa-solid ' + (CHANNEL_ICON[channel] || 'fa-note-sticky');
  };

  /**
   * Whether a contact note also reached Kidsoft, and when.
   *
   * THE THIRD SURFACE IS WHAT PULLED THIS UP HERE (18 Sep 2026), same as
   * channelIcon() above. `waitlist-modal.js` and `support-plans.js` had a
   * `copyTag()` each and the work modal's timeline is the third list to render a
   * logged contact - and this is the one fact on a note that MUST NOT drift,
   * because createComment has no update and no delete. A screen that says a note
   * travelled when it did not is a staff member who stops keeping the Kidsoft
   * note they still need.
   *
   * TWO DENSITIES, ONE FACT. The compact form is an icon alone, for a list that
   * already spends its width on the note itself; the full form spells it out.
   * Same idea as dayStrip's `wide` - a component that renders one thing at two
   * sizes, rather than two components that drift.
   *
   * WHEN it was copied is on the title rather than in the line, because it is
   * not always the day the note was written: a family with no Kidsoft record has
   * their history copied across the night they enrol, oldest note first.
   */
  window.copyTag = function (c, compact) {
    if (c.kidsoft_pushed_at) {
      var when = 'Copied to Kidsoft ' + fmtDateTime(c.kidsoft_pushed_at);
      return compact
        ? ' <i class="fa-solid fa-check text-success" title="' + esc(when) + '"></i>'
        : ' &middot; <span class="text-success" title="' + esc(when) +
          '"><i class="fa-solid fa-check"></i> copied to Kidsoft</span>';
    }
    if (c.kidsoft_push_error) {
      return compact
        ? ' <i class="fa-solid fa-triangle-exclamation text-warning" title="' +
          esc('Not copied to Kidsoft: ' + c.kidsoft_push_error) + '"></i>'
        : ' &middot; <span class="text-danger" title="' + esc(c.kidsoft_push_error) +
          '"><i class="fa-solid fa-triangle-exclamation"></i> not copied to Kidsoft</span>';
    }
    // NOTHING, not "not copied yet". A note with no target never had anywhere to
    // go and saying so on every line of an 87% majority is a caption, not news --
    // the compose block says it once, before the note is typed.
    return '';
  };

  /**
   * An administrator's Delete on one of OUR logged notes (30 Oct 2026). '' for
   * everybody else. Never on a Kidsoft comment: there is no delete to call.
   *
   * Same three lists as copyTag() - the family modal, Support plans and the
   * work modal's timeline - and one handler below, so the warning about the
   * Kidsoft copy is written once. What the confirm needs rides on the button,
   * so no host has to keep its rows. Each host listens for `comm:deleted` on
   * its own list and reloads it.
   */
  window.commDeleteBtn = function (c) {
    if (!window.USER_IS_ADMIN || !c || !c.id) return '';
    var also = (c.shared_from ? [c.shared_from] : []).concat(c.shared_with || []);
    return ' <button type="button" class="btn btn-link btn-sm p-0 ms-1 align-baseline comm-del"' +
      ' data-id="' + esc(c.id) + '" data-summary="' + esc(c.summary) + '"' +
      ' data-also="' + esc(also.join(', ')) + '"' +
      ' data-copied="' + esc(c.kidsoft_pushed_at ? fmtDateTime(c.kidsoft_pushed_at) : '') + '"' +
      ' title="Delete this note" aria-label="Delete this note">' +
      '<i class="fa-solid fa-trash-can"></i></button>';
  };

  // Bound ONCE, on the document, for the same reason as .ks-more below. Read
  // with attr() rather than data(), which would turn a summary of "123" into a
  // number and one that looks like JSON into an object.
  $(document).on('click', '.comm-del', function () {
    var $b = $(this);
    var also = $b.attr('data-also'), copied = $b.attr('data-copied');
    var msg = '"' + $b.attr('data-summary') + '"';
    if (also) msg += '\n\nIt is also shown on ' + also + ' and goes from there too.';
    // THE ONE THING THEY CANNOT SEE FROM THE NOTE. createComment has no delete,
    // and once our row is gone its copy shows in the Kidsoft block instead.
    if (copied) {
      msg += '\n\nIt was copied to Kidsoft on ' + copied + ' and will stay there. ' +
             'A Kidsoft comment cannot be deleted.';
    }
    confirmModal({ title: 'Delete this note?', body: msg, ok: 'Delete', danger: true }).done(function () {
      $b.prop('disabled', true);
      $.post('/api/comms', { action: 'delete', id: $b.attr('data-id') })
        .done(function (res) {
          if (!res.ok) { toast(res.error, 'danger'); $b.prop('disabled', false); return; }
          toast('Note deleted.');
          $b.trigger('comm:deleted');
        })
        .fail(function () { toast('Could not delete that note.', 'danger'); $b.prop('disabled', false); });
    });
  });

  /**
   * KIDSOFT'S OWN COMMENTS, rendered alongside ours and never merged in.
   *
   * A note somebody here typed and a note Kidsoft holds answer different
   * questions, and Kidsoft's side is mostly enrolment-form capture ("Agreed to
   * terms and conditions: Yes") rather than contact history. They stay separate
   * so neither is mistaken for the other - `.comm-entry-kidsoft` is dashed and
   * unsaturated for the same reason. Anything WE pushed is filtered out
   * server-side by CommentID, so a note appears once.
   *
   * CLAMPED, AND THAT IS NOT COSMETIC. One real entry here is forty lines of
   * sleep times and dietary notes, so an unclamped list pushes the contact log
   * the team came to read off the bottom of the modal entirely.
   *
   * KIDSOFT RETURNS NO DATE ON A COMMENT, so these carry none and cannot be
   * sorted into a timeline with ours. That is why they are a block underneath
   * rather than entries in it - see comment_kidsoft_existing().
   *
   * SHARED from 18 Sep 2026, when the work modal became the second surface to
   * show them. Returns '' for an empty list so the caller can hide its heading
   * rather than print one over nothing.
   */
  window.kidsoftComments = function (list) {
    if (!list || !list.length) return '';
    return list.map(function (c) {
      var long = (c.body || '').length > 260 || (c.body || '').split('\n').length > 5;
      return '<div class="comm-entry comm-entry-kidsoft">' +
        '<div class="small ks-body' + (long ? ' ks-clamp' : '') + '">' +
          esc(c.body).replace(/\n/g, '<br>') + '</div>' +
        (long ? '<button type="button" class="btn btn-link btn-sm p-0 ks-more">Show all</button>' : '') +
        '<div class="small text-muted mt-1">Kidsoft' +
          (c.type ? ' &middot; ' + esc(c.type) : '') +
          (c.confidential ? ' &middot; confidential' : '') +
          ' &middot; on the ' + esc(c.on === 'child' ? 'child' : 'guardian') + ' record</div>' +
      '</div>';
    }).join('');
  };

  // Bound ONCE, on the document, because the markup above is produced in two
  // modals now and the toggle is a property of the markup rather than of either
  // host. Delegated because the CSP forbids an inline onclick.
  $(document).on('click', '.ks-more', function () {
    var $b = $(this), on = $b.prev('.ks-body').toggleClass('ks-clamp').hasClass('ks-clamp');
    $b.text(on ? 'Show all' : 'Show less');
  });

  /**
   * A staff member's Entra email as the team says it: "Emily", not
   * "Emily@littlemiracles.com.au".
   *
   * Everybody here is @littlemiracles.com.au, so the domain is 26 characters
   * that never distinguish two people and do push a timeline line onto a second
   * row. Anything that is NOT an email is returned untouched - `actor` on a work
   * item event is 'staff' or 'kidsoft' as often as it is a person.
   */
  window.staffName = function (email) {
    var s = String(email == null ? '' : email);
    return s.indexOf('@') === -1 ? s : s.replace(/@.*$/, '');
  };

  /**
   * WHEN A PERSON LAST DID SOMETHING ABOUT A FAMILY, AND WHO - the cell for a
   * row carrying last_touched_at / last_touched_by. In progress's "Last
   * interacted" (work_last_touched()) and the Waitlist's "Contacted"
   * (waitlist_last_touched(), 11 Nov 2026), which is the same data: ONE
   * renderer, so the two columns cannot drift into two looks.
   *
   * The date reformatted as a string, never through new Date(): it is Sydney
   * wall-clock. The time is on the title; on the row it would be a second line
   * nobody reads to decide anything. THE FIRST NAME ONLY: "Ashleigh.Howell"
   * took In progress's column to 117px and Kanwal's All view 17px past 1030 at
   * 1280. The full address is on the title.
   */
  window.lastTouchedCell = function (w) {
    if (!w.last_touched_at) return '<span class="text-muted">--</span>';
    return '<span title="' + esc(fmtDateTime(w.last_touched_at) + ' by ' + (w.last_touched_by || '')) + '">' +
      esc(fmtDate(w.last_touched_at)) + '</span>' +
      (w.last_touched_by ? '<div class="text-muted">' +
        esc(staffName(w.last_touched_by).split('.')[0]) + '</div>' : '');
  };

  /**
   * Show a transient message. Endpoints that use api_fail() answer HTTP 200 with
   * ok:false, so callers must check res.ok in .done(), not only rely on .fail()
   * - see _incl/api.php.
   *
   * It lands in the fixed corner stack, not in the document flow: prepending to
   * <main> shifted every row down the instant a toast appeared, which moved the
   * grid out from under the pointer mid-click.
   */
  window.toast = function (msg, type) {
    var $a = $('<div class="alert alert-dismissible fade show shadow-sm" role="alert"></div>')
      .addClass('alert-' + (type || 'success'))
      .text(msg)
      .append('<button type="button" class="btn-close" data-bs-dismiss="alert"></button>');
    $('#toast-stack').append($a);
    if (type !== 'danger') setTimeout(function () { $a.alert('close'); }, 4000);
  };

  /**
   * A QUESTION IN A BOOTSTRAP DIALOG. Never window.confirm(), prompt() or
   * alert() (Broc, 10 Nov 2026: the browser's own boxes do not work well in
   * some of the browsers the team uses). See "No browser dialogs" in
   * _incl/docs/ui-conventions.md.
   *
   *   o.title   the question, as the dialog's heading
   *   o.body    plain text under it; a blank line starts a new paragraph
   *   o.ok      the confirm button's words (default 'OK') - name the action
   *   o.cancel  the other button's words (default 'Cancel')
   *   o.danger  the confirm button is red: it deletes or takes something away
   *   o.input   {label, value, maxlength}: one line of text, which was
   *             prompt()'s job; .done() gets it trimmed
   *   o.choose  no way out but the two buttons (no X, no Esc, no click
   *             outside), for a question whose Cancel is itself an answer
   *   o.from    the form or button to hold while OK's write is out; defaults
   *             to the one whose click opened this
   *
   * Returns a promise: .done(value) on the confirm button, .fail() on anything
   * else. USE .done(), NOT .then(): jQuery 3 runs .then() callbacks from a
   * timer, so a POST sent there escapes "One save at a time" above.
   *
   * IT OPENS OVER ANOTHER DIALOG as often as not (Delete inside the family
   * modal, Archive inside the work modal), and Bootstrap 5.3 does not stack
   * modals by itself. .modal-ask and its backdrop sit a layer above any open
   * dialog (app.css). On the way out, the dialog underneath gets back its focus
   * trap, which Bootstrap hands to the newest dialog and never returns, and the
   * focus it had. Its scroll lock needs nothing: Bootstrap saves and restores
   * the inline overflow it finds.
   */
  var askSeq = 0;
  window.confirmModal = function (o) {
    var d = $.Deferred(), id = 'ask-' + (++askSeq), answered = false;
    // FOCUS GOES BACK to what opened this only for somebody on the keyboard,
    // who needs their place back. After a mouse click it pinned that button's
    // tooltip on screen - focus is half a tooltip's trigger - measured on the
    // note Delete. A browser with no :focus-visible gives it back regardless.
    var prev = document.activeElement;
    try { if (prev && !prev.matches(':focus-visible')) prev = null; } catch (err) { /* keep it */ }
    var paras = String(o.body || '').split(/\n\s*\n/).filter(function (p) { return $.trim(p) !== ''; });
    var body = paras.map(function (p, i) {
      var last = i === paras.length - 1 && !o.input;
      return '<p class="' + (last ? 'mb-0' : 'mb-2') + '">' + esc($.trim(p)).replace(/\n/g, '<br>') + '</p>';
    }).join('');
    if (o.input) {
      body += '<label class="form-label' + (paras.length ? ' mt-2' : '') + '" for="' + id + '-in">' +
          esc(o.input.label) + '</label>' +
        '<input type="text" class="form-control" id="' + id + '-in" autocomplete="off"' +
          (o.input.maxlength ? ' maxlength="' + (+o.input.maxlength) + '"' : '') +
          ' value="' + esc(o.input.value) + '">';
    }
    var $m = $(
      '<div class="modal fade modal-ask" tabindex="-1" aria-labelledby="' + id + '-t">' +
        '<div class="modal-dialog">' +
          '<form class="modal-content">' +
            '<div class="modal-header">' +
              '<h5 class="modal-title" id="' + id + '-t">' + esc(o.title) + '</h5>' +
              (o.choose ? '' : '<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>') +
            '</div>' +
            (body ? '<div class="modal-body">' + body + '</div>' : '') +
            '<div class="modal-footer">' +
              '<button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">' +
                esc(o.cancel || 'Cancel') + '</button>' +
              '<button type="submit" class="btn ' + (o.danger ? 'btn-danger' : 'btn-primary') + '">' +
                esc(o.ok || 'OK') + '</button>' +
            '</div>' +
          '</form>' +
        '</div>' +
      '</div>').appendTo('body');
    var modal = new bootstrap.Modal($m[0], o.choose ? { backdrop: 'static', keyboard: false } : {});
    $m.find('form').data('holdAlso', o.from || window.actingElement());

    // ANSWERED WHILE THE CLICK IS STILL BEING HANDLED, both ways: OK from the
    // submit, anything else from hide.bs.modal, which Bootstrap fires inside the
    // Cancel or X click. A caller's POST then goes out inside that click, which
    // is what lets the hold above see it. Not at hidden.bs.modal: that comes
    // after the fade, from a timer, and "Different child" sends a write.
    // NOT BEFORE IT HAS FINISHED APPEARING: Bootstrap ignores hide() mid-fade,
    // so an early OK would answer and leave the dialog up - and an OK that
    // early is the tail of a double-click on whatever opened it.
    var shown = false;
    $m.find('form').on('submit', function (e) {
      e.preventDefault();
      if (answered || !shown) return;
      answered = true;
      modal.hide();
      d.resolve(o.input ? $.trim($m.find('#' + id + '-in').val()) : undefined);
    });
    $m.on('hide.bs.modal', function () {
      if (answered) return;
      answered = true;
      d.reject();
    });
    $m.on('shown.bs.modal', function () {
      shown = true;
      var $in = $m.find('#' + id + '-in');
      if ($in.length) $in.trigger('focus').trigger('select');
      else $m.find('[type=submit]').trigger('focus');
    });
    $m.on('hidden.bs.modal', function () {
      modal.dispose();
      $m.remove();
      var $under = $('.modal.show').last();
      // The next question in a chain (Do Not Enrol, then "Same child?") can be
      // up before this one has finished fading. It has the floor and the focus.
      if ($under.is('.modal-ask')) return;
      rearmUnder($under);
      if (prev && $.contains(document.documentElement, prev) &&
          (!$under.length || $.contains($under[0], prev))) $(prev).trigger('focus');
    });

    modal.show();
    // Added by show() itself, synchronously, as the body's last backdrop.
    $('.modal-backdrop').last().addClass('modal-ask-backdrop');
    return d.promise();
  };

  /**
   * The dialog left showing once one stacked over it has gone gets back what
   * Bootstrap took: the page's scroll lock (the closing dialog removes
   * .modal-open from the body) and its focus trap, which Bootstrap hands to the
   * newest dialog and never returns.
   */
  function rearmUnder($under) {
    if (!$under.length) return;
    $('body').addClass('modal-open');
    // Bootstrap 5.3.8's own trap, which is private. The version is pinned.
    var under = bootstrap.Modal.getInstance($under[0]);
    if (under && under._focustrap) { under._focustrap.deactivate(); under._focustrap.activate(); }
  }

  /**
   * OPEN AN EXISTING DIALOG ON TOP OF WHATEVER DIALOG IS ALREADY UP, the way
   * confirmModal() opens its question - for a dialog that is one question about
   * something on the one underneath, where replacing it would lose the reader's
   * place. The Placement room sheet is the case: clicking an empty day asks
   * "waiting for it, or held for them?" and the answer lands in that box.
   *
   * With nothing open it is a plain show(). Otherwise the dialog takes
   * .modal-over (the .modal-ask layer, app.css) for as long as it is up, its
   * backdrop is lifted over the one underneath, and on the way out the dialog
   * underneath gets its scroll lock and focus trap back. Returns the instance.
   */
  window.showOnTop = function (el) {
    var $m = $(el), inst = bootstrap.Modal.getOrCreateInstance(el);
    if (!$('.modal.show').not($m).length) { inst.show(); return inst; }
    $m.addClass('modal-over');
    $m.one('hidden.bs.modal', function () {
      $m.removeClass('modal-over');
      var $under = $('.modal.show').last();
      if (!$under.is('.modal-ask')) rearmUnder($under);
    });
    inst.show();
    // Added by show() itself, synchronously, as the body's last backdrop.
    $('.modal-backdrop').last().addClass('modal-ask-backdrop');
    return inst;
  };

  /**
   * Consistent "working on it" block for a panel that is about to be replaced.
   * Every screen wrote its own centred "Loading..." string; they now agree.
   */
  window.spinner = function (msg) {
    return '<div class="text-center text-muted py-5">' +
      '<div class="spinner-border spinner-border-sm text-primary me-2" role="status"></div>' +
      esc(msg || 'Loading...') + '</div>';
  };

  /** Same thing sized to sit inside a table body. */
  window.spinnerRow = function (cols, msg) {
    return '<tr><td colspan="' + cols + '" class="p-0">' + window.spinner(msg) + '</td></tr>';
  };

  /**
   * A fill percentage that cannot contradict the free figure beside it.
   *
   * The PHP twin is board_pct() in _incl/board.php and carries the reasoning:
   * one decimal place, because one place-day is 0.04% to 0.34% of the totals
   * this is ever shown against, and 100% is reserved for a room with nothing
   * left. Niagara Park 2026 read "Filled 100%" beside "Places free 1".
   *
   * BOTH LANGUAGES, because the two board summary tiles sum their own totals in
   * the browser rather than reading the server's figure - fixing only the PHP
   * would have left the tile that raised this saying 100%.
   */
  window.fillPct = function (occupied, capacity) {
    if (!capacity || capacity <= 0) return null;
    var pct = Math.round(occupied / capacity * 1000) / 10;
    if (pct >= 100 && occupied < capacity) return 99.9;
    if (pct <= 0 && occupied > 0) return 0.1;
    return pct;
  };

  /**
   * The ONLY way a fill percentage reaches the page.
   *
   * `94 + '%'` is "94%" and `93.9 + '%'` is "93.9%", so concatenating the raw
   * number would print a column where some centres carry a decimal and others do
   * not, and the whole ones would read as the rounded figures this replaced.
   * toFixed(1) makes every one of them the same shape and the same width.
   *
   * null is "--", not "0%": no capacity recorded is not an empty room. That
   * distinction was already being made at three of the five call sites and
   * missing at the other two.
   */
  window.pctText = function (pct) {
    return pct == null ? '--' : Number(pct).toFixed(1) + '%';
  };

  /**
   * THE stat tile. One implementation, because there were FIVE.
   *
   * dashboard.js, waitlist.js, rooms.js and board.js each carried a
   * byte-identical copy of this function, and tenure.js carried a different one
   * that put the NUMBER above the LABEL in a heavier face - so the same
   * component looked like two different components depending on which screen you
   * were on, and four copies guaranteed that any future fix would land on some
   * of them. Order is label -> value -> hint, which is what four of the five did
   * and what reads correctly: the label says what you are about to be told.
   *
   * `cls` colours the VALUE and is for status only (.text-danger /
   * .text-warning). It is not a decoration: see the warning-amber block in
   * app.css for why the amber is two different values depending on whether it is
   * a fill or text.
   *
   * `col` overrides the grid cell, because the screens genuinely differ in how
   * many tiles they show - four across on a summary row, three on Rooms.
   */
  window.statTile = function (label, value, hint, cls, col) {
    return '<div class="' + (col || 'col-6 col-lg-3') + '">' +
      '<div class="card h-100"><div class="card-body py-3">' +
      '<div class="text-muted small">' + esc(label) + '</div>' +
      '<div class="fs-3 fw-semibold ' + (cls || '') + '">' + esc(String(value)) + '</div>' +
      (hint ? '<div class="small text-muted">' + esc(hint) + '</div>' : '') +
      '</div></div></div>';
  };

  /**
   * "THIS CENTRE IS NOT OPEN YET" - one screen's worth of answer, for every
   * year-scoped screen that would otherwise draw a year that cannot exist.
   *
   * Asked for by Broc, 21 Sep 2026: *"it is pointless showing and calculating a
   * placement board for 2026 for warnervale... I like the new 'nothing to show'
   * screen you have for warnervale's 2026 marketing page - can we apply that
   * across the whole project?"* So it is written once here rather than four
   * times: the Marketing screen had the only copy and it is now a caller like
   * the rest.
   *
   * `n` is a centre_not_open_yet() payload - {centre, opens, opens_year, year,
   * from, from_disp}.
   * The button is offered only when the year it opens is a year this screen can
   * actually show, and `opts.href` is how a screen says where its own version of
   * that year lives. Without a link it is still the whole answer, just without
   * the shortcut.
   *
   *   opts.href   (year) => url for the same screen in the opening year
   *   opts.what   what this screen would have drawn - "placement board",
   *               "tenure", "room figures" - so the page says what is missing
   *               rather than making the reader guess from an empty screen.
   *               Phrased as "No <what> for 2026." because that reads correctly
   *               whether the noun is singular or plural; "There is no room
   *               figures to show" was the first attempt and it does not.
   *   opts.said   the whole second line, where a screen has a better sentence of
   *               its own. Marketing says "There is nothing to advertise for
   *               2026", which is the answer rather than the absence.
   */
  window.notOpenState = function (n, opts) {
    var o = opts || {};
    // `n.from` is set when a DATE inside the year ruled the centre out rather
    // than the year itself - the Placement board's From. See centre_not_open_yet().
    var body = window.emptyState('fa-calendar-day',
      n.centre + ' opens ' + n.opens,
      o.said || ('No ' + (o.what || 'figures') +
                 (n.from ? ' from ' + n.from_disp : ' for ' + n.year) + '.'));
    if (o.href && (n.opens_year > n.year || n.from)) {
      body += '<div class="text-center mb-4">' +
        '<a class="btn btn-outline-primary btn-sm" href="' + o.href(n.opens_year, n) + '">' +
        (n.from ? 'Look from ' + n.opens : 'Look at ' + n.opens_year) + ' instead</a></div>';
    }
    return body;
  };

  /**
   * A ROW OF FIGURES INSIDE A CARD - the tile's smaller sibling, for a card
   * carrying a handful of headline numbers rather than a table.
   *
   * MOVED HERE 18 SEP 2026 BECAUSE A SECOND COPY WAS ABOUT TO BE TYPED, which is
   * the rule channelIcon() set: the stat tile reached six copies and the mark bar
   * three before either was pulled up, and this one is stopping at one. It was
   * private to dashboard.js, where four cards use it; Marketing's by-weekday cut
   * is the same shape on a different screen, and two copies of a figure row is
   * how the Dashboard and the Waitlist ended up with two different marks for one
   * fact. dashboard.js aliases it rather than renaming its call sites.
   *
   * `cls` colours the VALUE and is for status only, the same contract as
   * window.statTile(). The geometry lives in the .stat-strip block in app.css.
   */
  window.statStrip = function (items) {
    return '<div class="stat-strip">' + items.map(function (s) {
      return '<div><div class="text-muted small">' + esc(s.label) + '</div>' +
        '<div class="fs-4 fw-semibold ' + (s.cls || '') + '">' + esc(String(s.value)) + '</div>' +
        (s.hint ? '<div class="small text-muted">' + esc(s.hint) + '</div>' : '') +
        '</div>';
    }).join('') + '</div>';
  };

  /**
   * ONE CHIP FOR "THIS FAMILY IS ALSO WAITING AT ...", for the three places that
   * draw it: the Waitlist queue's "Also waiting at" column, the family modal's
   * "What they want" (which the Placement board opens too), and - in its own
   * fuller form - the All centres register.
   *
   * Written here rather than three times, because the TITLE is the part worth
   * having once: it names their place in that centre's queue, when they applied,
   * the days they asked for THERE, and whether those days differ from the ones on
   * the application being read. Three copies of that sentence is the stat tile's
   * six-implementations problem waiting to happen.
   *
   * `o` is one entry of waitlist_elsewhere()'s payload. `opts.pill` draws the
   * queue position inside the chip: true in the modal, where there is room, false
   * in the queue column, where the pill costs 26px of a table that spends its
   * 1030px exactly at 1280 (see _incl/docs/waitlist.md).
   */
  window.waitlistCentreChip = function (childName, o, mineDays, opts) {
    var DAY = { 1: 'Mon', 2: 'Tue', 3: 'Wed', 4: 'Thu', 5: 'Fri' };
    var pill = !!(opts && opts.pill);

    // A CENTRE THIS USER CANNOT OPEN. A supervisor is told the family is also
    // waiting there and nothing more - no queue position, no days, no applied
    // date - so there is nothing for a title to say and nowhere for a link to
    // go. Rendered as a span rather than an <a>: /waitlist?centre=6 would be
    // refused on arrival, and a link that fails is worse than no link.
    // `scoped` is absent for a manager or administrator, for whom every entry is
    // theirs, so it is tested for FALSE rather than for truthiness.
    // ANOTHER CENTRE IS ALREADY WORKING THIS FAMILY (22 Oct 2026) - see
    // waitlist_elsewhere(). A mark inside the chip rather than a line under it:
    // the queue's column is 7rem and "In progress at Niagara Park" wraps over
    // three lines there. The words are on the title and in the family modal.
    var work = o.work
      ? ' <i class="fa-solid fa-list-check fa-xs" aria-label="In progress there"></i>' : '';
    var workSaid = o.work
      ? 'In progress at ' + o.centre + (o.work.stage_label ? ': ' + o.work.stage_label : '') + '. ' : '';
    if (o.scoped === false) {
      return '<span class="wl-cchip' + (pill ? '' : ' bare') + (o.work ? ' work' : '') + '"' +
        (o.work ? ' title="' + esc(workSaid) + '"' : '') + '>' + esc(o.centre) + work + '</span>';
    }

    var same = o.days.join(',') === (mineDays || []).join(',');
    var title = workSaid + (o.has_offer ? 'Offer open. ' : '') +
      (o.queue_position === null ? '' : 'Number ' + o.queue_position + ' in the ' + o.centre + ' queue. ') +
      'Applied ' + fmtDate(o.date_added) + '. Wants ' +
      o.days.map(function (d) { return DAY[d]; }).join(', ') + ' there' +
      (same ? '.' : ', which is not what they asked for here.');
    return '<a class="wl-cchip' + (pill ? '' : ' bare') + (o.has_offer ? ' offer' : '') +
      (o.work ? ' work' : '') +
      '" title="' + esc(title) + '" href="/waitlist?centre=' + o.centre_id +
      '&q=' + encodeURIComponent(childName) + '">' + esc(o.centre) + work +
      (pill && o.queue_position !== null ? '<span class="wl-cpos">' + o.queue_position + '</span>' : '') +
      '</a>';
  };

  /**
   * A brother or sister ALSO WAITING, as one line - for the queue's "Also
   * waiting" column and the family modal, so the two cannot word it differently.
   * See waitlist_siblings_waiting() for who counts. The caller puts the word
   * "Sibling" above the lines once; repeating it on each one wrapped every line
   * in a 7rem column.
   *
   * opts.open: a sibling in THIS queue is a .wl-open button, which opens their
   * family dialog - their row is on the same screen. Otherwise a link into the
   * queue they are in, like the centre chips beside it.
   *
   * A sibling at a centre this user does not hold arrives with no name and no
   * position, and the line says only where.
   */
  window.waitlistSiblingLine = function (s, opts) {
    if (s.scoped === false) {
      return '<div class="small text-muted">one at ' + esc(s.centre) + '</div>';
    }
    var title = s.name + (s.queue_position === null ? ''
      : ', number ' + s.queue_position + ' in the ' + s.centre + ' queue');
    var name = (s.here && opts && opts.open)
      ? '<button type="button" class="btn btn-sm btn-link p-0 align-baseline wl-open" data-id="' +
          s.id + '" title="' + esc(title) + '">' + esc(s.short) + '</button>'
      : '<a href="/waitlist?centre=' + s.centre_id + '&q=' + encodeURIComponent(s.name) +
          '" title="' + esc(title) + '">' + esc(s.short) + '</a>';
    return '<div class="small">' + name +
      (s.here
        ? (s.queue_position === null ? '' : ' <span class="text-muted text-nowrap">#' + s.queue_position + '</span>')
        : ' <span class="text-muted text-nowrap">at ' + esc(s.centre) + '</span>') +
      '</div>';
  };

  /**
   * A family's PAST APPLICATIONS - closed or archived in Kidsoft - newest first.
   * ONE BUILDER for the three places that print them: search's cards, and since
   * 11 Nov 2026 the Waitlist's "Also waiting at" column and family modal (Broc:
   * sync search's archive and rejected lines into the waitlists). The lines come
   * from search_history_line() on every path, so the wording is decided once
   * there and the layout once here.
   *
   * NOT A LINK, deliberately: these applications are not in our queue table - the
   * importer refuses closed ones on purpose - so /waitlist would open on an empty
   * table and read as "we have no record", the misreading this exists to correct.
   *
   *   lines  [{centre, status_label, date_added, archived}]
   *   total  how many there are, which can be more than were sent
   *   opts.compact  the queue column's form: 7rem wide, so the centre and the
   *                 outcome alone, with the date and the Archived tag on the
   *                 title, and only opts.limit of them. No clock icon: the
   *                 caller's label says it is history, and the 14px it costs
   *                 wraps "Niagara Park" in that column
   */
  window.pastApplicationLines = function (lines, total, opts) {
    lines = lines || [];
    if (!lines.length) return '';
    var compact = !!(opts && opts.compact);

    if (compact) {
      // ONE LINE PER CENTRE AND OUTCOME. The date is what tells two of
      // Fletcher Langthorne's "Tuggerah Rejected by service" apart, and the date
      // is on the title here - so two identical lines read as a glitch. Said
      // once, with how many times, and every date on the title.
      var groups = [], byKey = {};
      lines.forEach(function (h) {
        var k = h.centre + '|' + h.status_label + '|' + (h.archived ? 1 : 0);
        if (!byKey[k]) { byKey[k] = { h: h, dates: [] }; groups.push(byKey[k]); }
        byKey[k].dates.push(h.date_added);
      });
      var shownG = opts.limit ? groups.slice(0, opts.limit) : groups;
      var shownN = shownG.reduce(function (n, g) { return n + g.dates.length; }, 0);
      var moreG = (total || lines.length) - shownN;

      return shownG.map(function (g) {
        var h = g.h;
        var dates = g.dates.filter(Boolean).map(fmtDate);
        var times = g.dates.length === 2 ? ', twice' : g.dates.length > 2 ? ', ' + g.dates.length + ' times' : '';
        var title = h.status_label + times + '.' +
          (dates.length ? ' Applied ' + (dates.length > 1
            ? dates.slice(0, -1).join(', ') + ' and ' + dates[dates.length - 1] : dates[0]) + '.' : '') +
          (h.archived ? ' Archived.' : '');
        // ARCHIVED AS A MARK, not a word: the box the "Consider archiving" flag
        // carries. Search's line says "Archived" and this column has no room for
        // it, but it cannot be left to the title - an application archived while
        // still `Waitlisted` reads "Tuggerah Waitlisted" without it, which is a
        // family who looks to be waiting at Tuggerah.
        // The mark goes in a no-wrap span WITH THE LAST WORD: on its own it
        // wrapped onto a line of its own under "Expired waitlist", and &nbsp;
        // does not stop that - a browser may break beside an inline-block.
        var words = (h.status_label + times).split(' ');
        var last = words.pop();
        var outcome = h.archived
          ? esc(words.join(' ')) + (words.length ? ' ' : '') + '<span class="text-nowrap">' + esc(last) +
            ' <i class="fa-solid fa-box-archive fa-xs" aria-label="Archived"></i></span>'
          : esc(h.status_label + times);
        return '<div class="small wl-past" title="' + esc(title) + '">' +
          '<b>' + esc(h.centre) + '</b> <span class="text-muted">' + outcome + '</span></div>';
      }).join('') + (moreG > 0
        ? '<div class="small text-muted">and ' + moreG + ' older</div>' : '');
    }

    var more = (total || lines.length) - lines.length;
    return lines.map(function (h) {
      var bits = [h.status_label];
      if (h.date_added) bits.push('applied ' + fmtDate(h.date_added));
      return '<div class="sr-line sr-line-past">' +
        '<span class="sr-line-ic"><i class="fa-solid fa-clock-rotate-left"></i></span>' +
        '<span class="sr-line-main"><b>' + esc(h.centre) + '</b> ' +
          '<span class="text-muted">' + esc(bits.join(' - ')) + '</span></span>' +
        (h.archived ? '<span class="sr-line-tag">Archived</span>' : '') +
        '</div>';
    }).join('') + (more > 0
      ? '<div class="sr-line sr-line-past sr-line-more">' +
          '<span class="sr-line-ic"></span><span class="sr-line-main text-muted">' +
          'and ' + more + ' older application' + (more === 1 ? '' : 's') +
          '</span></div>'
      : '');
  };

  /**
   * BROTHERS AND SISTERS WAITING AT THE SAME CENTRE, as ticks (19 Oct 2026) -
   * "also on this tour", "also lost", "also on the history of". One builder, so
   * the questions look alike in the work modal and the family modal. `family` is
   * work_siblings_here()'s entries, published as `family` by both endpoints.
   *
   *   opts.label    the lead-in
   *   opts.name     the input name posted, e.g. 'siblings[]'
   *   opts.value    s -> what is posted for them
   *   opts.checked  s -> ticked to begin with?
   *   opts.note     s -> a few words after the name, or ''
   *   opts.only     s -> offered at all? (default: everyone)
   *
   * Returns '' when nobody qualifies, which the caller uses to hide the box.
   */
  var famSeq = 0;
  window.familyTicks = function (family, opts) {
    var list = (family || []).filter(function (s) { return !opts.only || opts.only(s); });
    if (!list.length) return '';
    return '<div class="small mt-1"><span class="text-muted me-2">' + esc(opts.label) + '</span>' +
      list.map(function (s) {
        var id = 'fam-tick-' + (++famSeq);
        var note = opts.note ? opts.note(s) : '';
        return '<span class="form-check form-check-inline mb-0 me-3">' +
          '<input class="form-check-input" type="checkbox" name="' + esc(opts.name) + '" value="' +
            esc(String(opts.value(s))) + '" id="' + id + '"' + (opts.checked(s) ? ' checked' : '') + '>' +
          '<label class="form-check-label" for="' + id + '">' + esc(s.name) +
            (note ? ' <span class="text-muted">(' + esc(note) + ')</span>' : '') + '</label></span>';
      }).join('') + '</div>';
  };

  /**
   * "Also on this tour", the same in both dialogs. TICKED for a sibling with no
   * tour or already on this one: a tour is one visit for the family. NOT ticked,
   * and saying why, for one with a tour of their own, one already in care, or one
   * whose only item was closed as Lost - each is a person's call, not a default.
   *
   * @param tourAt this child's tour as stored ('Y-m-d H:i:s'), or null
   */
  window.familyTourTicks = function (family, tourAt, name) {
    return familyTicks(family, {
      label: 'Also on this tour', name: name, value: function (s) { return s.waitlist_id; },
      checked: function (s) {
        if (s.in_care || (s.was_lost && !s.item)) return false;
        return !s.item || !s.item.tour_at || s.item.tour_at === tourAt;
      },
      note: function (s) {
        if (s.in_care) return 'already in care';
        if (s.item && s.item.tour_at && s.item.tour_at !== tourAt) return 'own tour ' + fmtDateTime(s.item.tour_at);
        if (s.was_lost && !s.item) return 'was marked Lost';
        return s.item ? '' : 'not in In progress yet';
      }
    });
  };

  /**
   * "Also on the history of": one call, one row, shown on each child ticked
   * (api/comms, communication_siblings). Ticked except for a child already in
   * care. The Kidsoft copy stays on the child the note is written on.
   */
  window.familyNoteTicks = function (family) {
    return familyTicks(family, {
      label: 'Also on the history of', name: 'also_waitlist[]',
      value: function (s) { return s.waitlist_id; },
      checked: function (s) { return !s.in_care; },
      note: function (s) { return s.in_care ? 'already in care' : ''; }
    });
  };

  /**
   * Which child a logged note belongs to, when it is not only this one's:
   * work_contacts_shared(). A note shown here from a brother or sister drops the
   * "copied to Kidsoft" mark - its copy is on THEIR record, not this one's.
   *
   * @returns {{meta: string, copy: boolean}} meta is ' &middot; ...' or ''
   */
  window.sharedNote = function (c) {
    if (c.shared_from) return { meta: ' &middot; logged on ' + esc(c.shared_from), copy: false };
    if (c.shared_with && c.shared_with.length) {
      return { meta: ' &middot; also on ' + esc(c.shared_with.join(', ')), copy: true };
    }
    return { meta: '', copy: true };
  };

  /** Did they ask for different days on any of those other applications? */
  window.waitlistDaysDiffer = function (others, mineDays) {
    var mine = (mineDays || []).join(',');
    // Entries the user cannot see carry no days at all, and an empty list would
    // read as "they asked for different days there" on every single one.
    return (others || []).some(function (o) {
      return o.scoped !== false && o.days.join(',') !== mine;
    });
  };

  /**
   * DO NOT ENROL: an application matching a family tagged in Kidsoft. One
   * component because the queue, the register, the board's shortlist, the family
   * modal and both offer forms all show it, and the warning is the one thing on
   * those screens that must never read two ways. `dne` is dne_match()'s payload
   * (_incl/do-not-enrol.php); its `text` is composed on the server.
   *
   * dneBadge(dne)          the badge, its sentence on the tooltip; '' when null
   * dneAlert(dne, confirm) the sentence spelled out; `confirm` adds the tick
   *                        make_offer refuses without
   * postConfirmingDne(url, data)
   *                        $.post for a one-click action the server refuses on a
   *                        match (starting work on a family): asks, and re-sends
   *                        with the confirmation. Resolves with the final
   *                        response, or {cancelled: true} if they said no.
   *                        Also asks whether a matching tour enquiry is the
   *                        same child - see the note on the function.
   */
  window.dneBadge = function (dne) {
    if (!dne) return '';
    return '<span class="badge text-bg-danger" title="' + esc(dne.text) + '">' +
      '<i class="fa-solid fa-ban fa-xs me-1"></i>Do Not Enrol</span>';
  };

  /**
   * ONE CHILD APPLIED FOR TWICE AT ONE CENTRE (6 Nov 2026) - waitlist_duplicates().
   * Red "Duplicate waitlist" asks somebody to archive one in Kidsoft; amber
   * "Other parent applied" is separated parents and asks for nothing. The
   * sentence (which application, which days) is on the title, and in words in
   * the dialogs via dupAlert().
   */
  window.dupBadge = function (dup) {
    if (!dup) return '';
    var red = dup.kind === 'duplicate';
    // text-wrap: "Duplicate waitlist" on one line took the queue's Child column
    // from 113px to 143px and pushed the table 32px past 1030 at 1280 (measured
    // on Tuggerah, 6 Nov 2026). It wraps only where the column is that narrow.
    return '<span class="badge text-wrap text-start ' + (red ? 'text-bg-danger' : 'text-bg-warning') + '" title="' +
      esc(dup.text) + '"><i class="fa-solid ' + (red ? 'fa-clone' : 'fa-people-arrows') +
      ' fa-xs me-1"></i>' + esc(dup.label) + '</span>';
  };
  /**
   * WANTED A PLACE MORE THAN SIX MONTHS AGO AND STILL WAITING (6 Nov 2026) -
   * waitlist_stale(). A quiet outline (.badge-stale in app.css): it asks
   * somebody to ring and check, and the family keeps its place in the queue
   * until somebody archives it in Kidsoft. Filled amber was tried first and
   * Broc asked for "much more subtle"; text-bg-secondary is the brand purple in
   * this theme and read as one more "Toured" chip. The date is on the title.
   */
  window.staleBadge = function (s) {
    if (!s) return '';
    return '<span class="badge badge-stale text-wrap text-start" title="' + esc(s.text) + '">' +
      '<i class="fa-solid fa-box-archive fa-xs me-1"></i>' + esc(s.label) + '</span>';
  };
  window.dupAlert = function (dup) {
    if (!dup) return '';
    var red = dup.kind === 'duplicate';
    return '<div class="alert ' + (red ? 'alert-danger' : 'alert-warning') + ' py-2 small mb-0">' +
      '<div class="fw-semibold"><i class="fa-solid ' + (red ? 'fa-clone' : 'fa-people-arrows') +
      ' me-1"></i>' + esc(dup.label) + '</div>' + esc(dup.text) + '</div>';
  };

  // A fresh id per tick: the alert can sit in more than one dialog on a page,
  // and a duplicate id points every label at the first checkbox.
  var dneSeq = 0;
  window.dneAlert = function (dne, confirm) {
    if (!dne) return '';
    var id = 'dne-checked-' + (++dneSeq);
    return '<div class="alert alert-danger py-2 small">' +
      '<div class="fw-semibold"><i class="fa-solid fa-ban me-1"></i>Do Not Enrol</div>' +
      esc(dne.text) +
      (confirm
        ? '<div class="form-check mt-2 mb-0">' +
          '<input class="form-check-input" type="checkbox" name="dne_checked" value="1" ' +
          'id="' + id + '" required>' +
          '<label class="form-check-label" for="' + id + '">' +
          'I have checked this and it is OK to go ahead.</label></div>'
        : '') +
      '</div>';
  };

  // A DIALOG, confirmModal() above, and never window.confirm() (10 Nov 2026).
  // The family modal is often already open underneath; confirmModal() stacks
  // over it. The SERVER decides whether to ask - it answers with `dne` - so a
  // screen loaded before the tag was added still asks. The questions are asked
  // after a reply, when the click that started this is long over, so that
  // button is remembered here and held with the answer's re-send.
  //
  // AND IT ASKS THE SECOND QUESTION STARTING WORK ON A FAMILY CAN RAISE
  // (14 Oct 2026): is this the child somebody already booked a tour for as an
  // enquiry? The server answers `enquiry_matches` when a tour enquiry at the
  // centre shares this application's parent phone or email, and a person says
  // which: Same child joins the application to that row (`join_enquiry`),
  // Different child starts the family on their own row (`enquiry_checked`).
  // Either way the action the button was for still happens - this decides only
  // how many rows it makes - so those two buttons are the only way out.
  // Asked ONE MATCH AT A TIME, naming both children, because the same phone
  // matched a brother and a sister at Terrigal and only a person can tell them
  // apart. All four "start this family" buttons already come through here, which
  // is why the question lives here and not in each of them.
  window.postConfirmingDne = function (url, data) {
    var d = $.Deferred(), from = window.actingElement();
    function send(payload) {
      $.post(url, payload).done(function (res) {
        if (!res || res.ok) { d.resolve(res); return; }
        if (res.dne && !payload.dne_checked) {
          confirmModal({
            title: 'Do Not Enrol', danger: true, from: from, ok: 'Start with this family',
            body: res.dne.text + '\n\nOnly go ahead if this has been checked. Start with this family?'
          }).done(function () { send($.extend({}, payload, { dne_checked: 1 })); })
            .fail(function () { d.resolve({ ok: false, cancelled: true }); });
          return;
        }
        if (res.enquiry_matches && !payload.enquiry_checked && !payload.join_enquiry) {
          (function ask(i) {
            var m = res.enquiry_matches[i];
            if (!m) { send($.extend({}, payload, { enquiry_checked: 1 })); return; }
            confirmModal({
              title: 'Same child?', choose: true, from: from,
              ok: 'Same child', cancel: 'Different child',
              body: 'A tour was booked for ' + m.child_name + ' as a new enquiry on ' + m.created +
                ' (' + m.contact + (m.tour_at ? ', tour ' + m.tour_at : '') + '). ' +
                'It has the same parent contact as ' + (payload.child_name || 'this family') + '.\n\n' +
                'Same child: keep the one row and add this application to it.\n' +
                'Different child: start them on their own row.'
            }).done(function () { send($.extend({}, payload, { join_enquiry: m.id })); })
              .fail(function () { ask(i + 1); });
          })(0);
          return;
        }
        d.resolve(res);
      }).fail(d.reject);
    }
    send(data);
    return d.promise();
  };

  /**
   * A CHILD'S NAME THAT OPENS THEIR OWN DIALOG, on any screen (6 Nov 2026).
   *
   * Broc: names "mentioned or listed" around the app should open the child. The
   * dialog is chrome (_incl/child-modal.php, static/js/child-modal.js), so this
   * works everywhere; the click is handled there, once, for every screen.
   *
   *   o.centre_id, o.ks_id  the ChildID Kidsoft issued AT that centre - the ids
   *                         are per service, so both are needed
   *   o.name                what to print
   *   o.cls                 extra classes for the element
   *
   * Without a ChildID (an application, a provisional child, a name that matched
   * nobody) it is the plain name it always was: a control that opens nothing is
   * worse than no control.
   *
   * AN <a role="button">, NOT A <button>, because it sits INSIDE SENTENCES: a
   * button is always an inline block, so in a narrow queue badge reading
   * "Sibling of Sersha Donnelly, Sersha Donnelly" each name wrapped whole and
   * the comma was left on a line of its own. An anchor wraps like the text it is
   * part of. The href is a no-op the handler prevents; it is what makes the name
   * focusable and Enter open it.
   */
  window.childName = function (o) {
    var cls = o.cls ? ' ' + o.cls : '';
    if (!o.ks_id || !o.centre_id) return '<span class="' + cls.trim() + '">' + esc(o.name) + '</span>';
    return '<a href="#" role="button" class="ch-name' + cls + '" data-centre="' + (+o.centre_id) +
      '" data-ks="' + esc(o.ks_id) + '" data-name="' + esc(o.name) + '">' + esc(o.name) + '</a>';
  };

  /**
   * One queue reason, with the children it names opening their dialogs - see
   * priority_reason_parts() in _incl/waitlist.php. `parts` is that reason's
   * pieces; `text` is the sentence, used whenever the pieces are absent.
   */
  window.reasonHtml = function (parts, text) {
    if (!parts) return esc(text);
    return parts.map(function (p) {
      return p.child ? childName({ centre_id: p.child.centre_id, ks_id: p.child.ks_id, name: p.t })
                     : esc(p.t);
    }).join('');
  };


  /**
   * THE Mon-Fri STRIP: one component, one set of colours, every screen.
   *
   * It had SIX implementations and three different colour languages, which is
   * the stat tile's problem again in the channel the team reads most. The
   * Waitlist queue drew wide 2.1rem labels where purple meant "asked for" and a
   * pale green wash meant "already attends"; the Placement cell panel drew
   * 1.3rem letters where BLUE meant "asked for" and the same wash meant
   * "attends"; the Dashboard, In progress and the offer dialog drew 1.4rem
   * letters where a pale purple meant whatever that screen happened to be
   * listing; and Search drew 1.9rem labels in ONE purple for days a child holds
   * AND for days a family is waiting on - two opposite facts, same colour, one
   * result card. Reported by the team as "the theme isn't consistent".
   *
   * FOUR STATES, and each fact keeps its colour wherever it is read:
   *
   *   has    green fill, dark green edge   they attend this day
   *   want   solid blue                    asked for, and they do NOT have it
   *   extra  grey box, dark green edge     they attend, but it is not on this
   *                                        application
   *   off    grey                          neither
   *
   * AND ONE THAT MEANS NOTHING ON PURPOSE. `plain` is the same box in neutral
   * grey - a darker fill under a dark grey edge, the green treatment with the
   * hue taken out - for a strip that is only naming weekdays. The Dashboard's
   * "Places coming free" is the case: those days ARE days the child holds, so
   * green was literally true and read as "a place is held" on a card whose whole
   * point is that it is about to be given up (Broc, 4 Sep 2026). A strip that
   * answers "which weekdays" and nothing else should not borrow a colour that
   * answers something else. `plainSaid` is what the title calls them, because
   * only the caller knows: nothing generic is true of every neutral strip.
   *
   * BLUE, NOT PURPLE, IS "ASKED FOR" (Broc's call, 3 Sep 2026). It was already
   * --lm-req on the grid's dot, the roster's hourglass and the wish note, so
   * only the Waitlist and Search moved - and it leaves purple free to mean "the
   * day you are looking at", which is the ring `day` draws. Nothing in the strip
   * now carries two meanings.
   *
   * THE GREEN IS DARKER THAN IT WAS, for the same report: the old #e7f6ee fill
   * is 1.1:1 against the page and the old 1px #bfe5cd ring was invisible on a
   * middling monitor, so "already attends" and "did not ask for it" were being
   * read as the same badge. See the DAY STRIP block in app.css.
   *
   * THE CALLER SAYS WHICH SET IS WHICH; this function does not infer. On the
   * Waitlist the three lists are computed off the application (asked AND
   * attends -> has, asked only -> want, attends only -> extra); on the cell
   * panel `has` and `want` are already disjoint. Inferring `extra` from "in has
   * and not in want" looked tempting and is wrong on every screen that has no
   * application to compare against - the room roll would have come out entirely
   * ringed.
   *
   *   opts.has        weekdays they attend
   *   opts.want       weekdays asked for and not held
   *   opts.extra      weekdays they attend that are not on this application
   *   opts.plain      weekdays being named, with no claim about what they mean
   *   opts.plainSaid  what a `plain` day is, for the title ("coming free")
   *   opts.hasSaid / wantSaid / extraSaid / offSaid
   *                   the same for the other states, where the default words
   *                   are untrue on that screen - Tenure's short stays are a
   *                   family who has LEFT, so nothing there "attends" or is
   *                   "still to find". The colours do not change.
   *   opts.day        the one weekday this panel is about -> purple edge
   *   opts.wide       3-letter labels (a table column) rather than 1 (a card)
   *   opts.empty      markup to return when no list has anything in it
   */
  window.dayStrip = function (opts) {
    var o = opts || {};
    var DAY  = { 1: 'Mon', 2: 'Tue', 3: 'Wed', 4: 'Thu', 5: 'Fri' };
    var LONG = { 1: 'Monday', 2: 'Tuesday', 3: 'Wednesday', 4: 'Thursday', 5: 'Friday' };
    var has = o.has || [], want = o.want || [], extra = o.extra || [], plain = o.plain || [];
    // HELD FOR THIS CHILD AND NOT BOOKED - a place kept while a family is out,
    // most often on a Behaviour Support Plan. Its own state rather than `plain`,
    // because the roster already marks it with an amber padlock and one fact has
    // to keep one mark: a neutral box here and an amber lock there would be two
    // spellings of the same thing on two screens, the fault this component was
    // merged out of four copies to end.
    var held = o.held || [];
    // COMING FREE (10 Nov 2026): a day the child holds now and is giving up -
    // leaving care, or dropping that one day. The same amber as the board's
    // "+1 free" badge, because Broc chose that one colour for "coming free"
    // everywhere on the board. It WINS over `has`: the child does attend it until
    // then, but the question every strip carrying it is asking is "which of
    // these places is going". The strong fill with no edge is what keeps it
    // apart from `held`, the pale wash under a dark amber edge.
    var ending = o.ending || [];
    // A NEUTRAL STRIP SAYS NOTHING ABOUT THE DAYS IT DOES NOT MARK either, so
    // its off boxes get the bare day name. "Monday - not asked for and not
    // attending" on a list of places coming free is an answer to a question
    // nobody asked there.
    // A STRIP CARRYING HELD DAYS IS NOT ASKING ABOUT DEMAND EITHER. `plain`
    // already marked a strip whose only question is WHICH WEEKDAY; a strip with
    // `held` on it is the same kind - it is about a place being kept, and its
    // off boxes were answering "not asked for and not attending", which on the
    // Support plans screen is a reply to a question nobody asked there.
    var neutral = o.plain !== undefined || o.held !== undefined;

    if (o.empty !== undefined && !has.length && !want.length && !extra.length &&
        !plain.length && !held.length && !ending.length) {
      return o.empty;
    }

    return '<span class="dstrip' + (o.wide ? ' dstrip-wide' : '') + '">' +
      [1, 2, 3, 4, 5].map(function (d) {
        var cls = '', said = neutral ? '' : (o.offSaid || 'not asked for and not attending');
        if (ending.indexOf(d) !== -1) {
          cls = ' db-ending'; said = o.endingSaid || 'coming free';
        } else if (has.indexOf(d) !== -1) {
          cls = ' db-has';   said = o.hasSaid || 'attends';
        } else if (want.indexOf(d) !== -1) {
          cls = ' db-want';  said = o.wantSaid || 'asked for, still to find';
        } else if (extra.indexOf(d) !== -1) {
          cls = ' db-extra'; said = o.extraSaid || 'attends, not asked for on this application';
        } else if (plain.indexOf(d) !== -1) {
          cls = ' db-plain'; said = o.plainSaid || '';
        } else if (held.indexOf(d) !== -1) {
          cls = ' db-held';  said = o.heldSaid || 'held for them, not booked';
        }
        // The day the panel is about is ringed in EVERY block, so it never has
        // to be re-read off the title. It is an edge, so it stacks on a state
        // rather than replacing it.
        if (+o.day === d) cls += ' db-day';
        // A DAY WITH NOTHING TO SAY GETS ITS NAME AND NOTHING ELSE, not a
        // dangling "Monday - ". The name alone is the whole reason a title is
        // here on a compact strip, where the box shows one letter.
        var label = said ? LONG[d] + ' - ' + said : LONG[d];
        return '<span class="dbox' + cls + '" title="' + esc(label) + '">' +
          (o.wide ? DAY[d] : DAY[d].charAt(0)) +
          '<span class="visually-hidden"> ' + esc(label) + '</span></span>';
      }).join('') + '</span>';
  };

  /**
   * THE marks: one bar, one stack, one legend, for the whole app.
   *
   * These were two components. The Dashboard had them at 7-9px with a 2px
   * surface gap between touching fills and a rounded data end; the Waitlist had
   * its own set at 8px with the segments butted together inside a pill. Same
   * kind of card, same kind of row, two different marks - the stat tile's
   * six-implementations problem in another channel. The Dashboard's geometry
   * won. See the MARKS block in app.css for the specs and for why the COLOURS
   * are deliberately still chosen per screen.
   *
   * markBar(n, max, title, cls) - one value against the biggest in its column.
   * `cls` overrides the fill where the row already means something in colour:
   * the Waitlist's pairs are ALL shared families, so a bar in the "this centre
   * only" purple sitting under a legend that says so would contradict itself.
   */
  window.markBar = function (n, max, title, cls) {
    var w = max > 0 ? Math.max(2, Math.round(n / max * 100)) : 0;
    return '<span class="mk-cell" title="' + esc(title || '') + '">' +
      '<span class="mk-track">' + (n > 0
        ? '<span class="mk-bar ' + (cls || '') + '" style="width:' + w + '%"></span>'
        : '') + '</span>' +
      '<span class="mk-v">' + (n || '-') + '</span></span>';
  };

  /**
   * markStack(segs) - a part-to-whole stack, each segment {n, cls, label}.
   *
   * Pass `max` to scale the whole stack against something OUTSIDE the row (the
   * longest queue in the column) instead of against its own total; it then gets
   * a track, so a short bar reads as short rather than as missing. Segments with
   * nothing in them are not emitted at all, or an empty one would spend a 2px
   * gap and read as a hairline of a colour that is not there.
   */
  window.markStack = function (segs, max) {
    var total = segs.reduce(function (s, x) { return s + x.n; }, 0);
    if (!total) return '<span class="text-muted">--</span>';
    var denom = max > 0 ? max : total;
    var bars = segs.filter(function (s) { return s.n > 0; }).map(function (s) {
      return '<span class="' + s.cls + '" style="width:' + (s.n / denom * 100) + '%" ' +
        'title="' + esc(s.label + ': ' + s.n + ' of ' + total) + '"></span>';
    }).join('');
    if (!(max > 0)) return '<span class="mk-stack">' + bars + '</span>';
    return '<span class="mk-track mk-scale"><span class="mk-stack">' + bars + '</span></span>';
  };

  /** markLegend(keys) - {cls, label} swatches, the identity channel. */
  window.markLegend = function (keys) {
    return '<span class="mk-legend">' + keys.map(function (k) {
      return '<span class="mk-key"><span class="mk-sw ' + k.cls + '"></span>' +
        esc(k.label) + '</span>';
    }).join('') + '</span>';
  };

  /**
   * An empty state that says what to do next, rather than a bare "None".
   * `icon` is a Font Awesome name, e.g. 'fa-inbox'.
   */
  window.emptyState = function (icon, title, hint) {
    return '<div class="text-center py-5">' +
      '<div class="empty-ic mb-3"><i class="fa-solid ' + esc(icon) + '"></i></div>' +
      '<div class="fw-semibold">' + esc(title) + '</div>' +
      (hint ? '<div class="small text-muted mt-1">' + esc(hint) + '</div>' : '') +
      '</div>';
  };

  /**
   * The [<] year [>] stepper emitted by layout_year_nav().
   *
   * A year selector was a <select> on three screens, which meant opening a menu
   * to move one step along an unbroken sequence. Wire it once; call .set() with
   * the current year and the years the server actually holds data for, and the
   * arrows disable themselves at either end so nobody steps into an empty year.
   *
   *   var yn = yearNav('#year-nav', function (y) { year = y; load(); });
   *   yn.set(res.year, res.years);
   */
  window.yearNav = function (sel, onChange) {
    var $root = $(sel), year = null, years = [];

    $root.on('click', '.yn-step', function () {
      var i = years.indexOf(year) + parseInt($(this).data('step'), 10);
      if (i < 0 || i >= years.length) return;
      onChange(years[i]);
    });

    return {
      set: function (y, list) {
        year  = y;
        years = (list || []).slice();
        if (years.indexOf(y) === -1) years.push(y);
        years.sort(function (a, b) { return a - b; });

        var i = years.indexOf(y);
        $root.find('.yn-label').text(y);

        // The arrow names the year it goes to, rather than saying "previous" and
        // leaving you to work out what that is.
        function label($b, target, none) {
          var t = target === undefined ? none : 'Go to ' + target;
          $b.prop('disabled', target === undefined).attr({ title: t, 'aria-label': t });
        }
        label($root.find('[data-step="-1"]'), i <= 0 ? undefined : years[i - 1], 'No earlier year');
        label($root.find('[data-step="1"]'),
              i >= years.length - 1 ? undefined : years[i + 1], 'No later year');
      }
    };
  };

  // --- Tooltips ---------------------------------------------------------------
  // EVERY plain `title=` in the app is a Bootstrap tooltip, and no call site has
  // to know it. The native one took about a second to appear, looked different
  // in every browser and could not be styled - but re-typing seventy-odd
  // attributes as data-bs-toggle="tooltip" would only have moved the problem:
  // the next title= written anywhere would silently get the slow one back. So
  // the attribute the codebase already writes IS the API, and a `title` keeps
  // meaning "explain this on hover" everywhere, including in markup that has not
  // been written yet.
  //
  // Delegation is also the only thing that survives this app's rendering. Every
  // screen replaces whole tables with .html(), so tooltips attached element by
  // element on DOMReady would last until the first AJAX load and no longer.
  //
  // NOT covered, deliberately: a control that is `disabled`. A disabled button
  // or select fires no mouse events in any browser, so nothing can hover-trigger
  // on it - and because nothing here ever reaches it, its `title` is left alone
  // and the browser still shows the native tooltip. The documented workaround is
  // to wrap each one in a hoverable span; the year stepper's arrows are a
  // .btn-group, where an extra element breaks the joined borders, so the handful
  // of disabled controls keep the native tooltip rather than the app carrying
  // two kinds of markup for one idea.
  if (window.bootstrap && bootstrap.Tooltip) {

    // BOTH ATTRIBUTES, everywhere this selector is used, and the second one is
    // not optional. The first time Bootstrap builds a tooltip for an element it
    // moves the `title` to data-bs-original-title and REMOVES the title
    // attribute - so anything matching on plain '[title]' stops matching the
    // very element it just matched, and the tooltip never gets a leave event.
    // That failure looks like success: the first hover on every fresh element is
    // perfect, and then the bubble hangs on screen after the pointer walks away
    // and is never offered again.
    // ...and NOT an element that is already some other Bootstrap component.
    // Bootstrap stores one instance per element and refuses a second outright -
    // "Bootstrap doesn't allow more than one instance per element" in the
    // console - so a tooltip on the user menu or the Waitlist's "More actions"
    // button silently took the slot its DROPDOWN needed, leaving the menu to
    // rebuild itself from scratch on every click. Two controls lose a styled
    // tooltip and keep the native one; the alternative is a wrapper element
    // inside `.dropdown`, which is exactly the kind of markup a component
    // library is supposed to save us from.
    var TIP_SEL = '[title]:not([data-bs-toggle]),' +
                  '[data-bs-original-title]:not([data-bs-toggle])';

    /**
     * What an element's tooltip should say, read LIVE off the element.
     *
     * Bootstrap caches the title into data-bs-original-title and reads that from
     * then on. Three places re-label a control by setting title again - the
     * sidebar toggle, the year arrows (which name the year they go to) and the
     * waitlist modal's day strip - and all three would have gone stale after the
     * first hover. Reading the attribute on every show keeps .attr('title', ...)
     * working as the way to change what a tooltip says.
     */
    function tipText(el) {
      return el.getAttribute('title') ||
             el.getAttribute('data-bs-original-title') || '';
    }

    var TIP_OPTS = {
      title: function () { return tipText(this); },
      // Fast enough to read as an answer rather than a wait, slow enough not to
      // fire while the pointer crosses a row of placement cells. Hiding is
      // immediate so moving along that row does not trail bubbles behind it.
      delay: { show: 180, hide: 0 },
      // html:false is the default and stays that way: every title in this app is
      // built with esc(), so the tooltip renders escaped text and a child called
      // x" onmouseover=" has nowhere to go. Newlines survive through CSS - see
      // the TOOLTIPS block in app.css.
      container: 'body'
    };

    // The element whose tooltip is on screen right now, or null.
    var tipOn = null;

    /**
     * Forget an element's tooltip entirely: DISPOSE, never hide().
     *
     * hide() is not enough at either end of a tooltip's life. It returns early
     * on one that has not shown yet, so it cannot call off a show still sitting
     * in the delay; and it does not clear the focus a click just put on the
     * trigger, and `focus` is half the default trigger, so the bubble comes
     * straight back and sits there pinned. dispose() clears the timer, the
     * trigger state and the bubble together.
     */
    function dropTip(el) {
      if (!el) return;
      if (el === tipOn) { tipOn = null; tipWatch.disconnect(); }
      var t = bootstrap.Tooltip.getInstance(el);
      if (!t) return;
      // dispose() hands data-bs-original-title back to the element as `title`,
      // and that is a stale value the moment a control renames itself on the
      // click we are reacting to - the sidebar toggle turns from Collapse into
      // Expand, and its own handler runs before this one. Whatever the element
      // says NOW wins; a null means the label was never touched and Bootstrap's
      // own restore is the right answer.
      var live = el.getAttribute('title');
      t.dispose();
      if (live !== null) el.setAttribute('title', live);
    }

    // A tooltip is a child of <body>, not of the thing it describes, so a render
    // that replaces the row under the pointer leaves the bubble on screen
    // pointing at nothing - and no mouseleave ever fires to clear it, because
    // the element it would have fired on is gone. Watch the DOM only while a
    // tooltip is actually up, and drop it if its trigger leaves the document.
    var tipWatch = new MutationObserver(function () {
      if (tipOn && !document.body.contains(tipOn)) dropTip(tipOn);
    });

    $(document)
      /**
       * Build the tooltip on first contact, then hand the element to Bootstrap.
       *
       * NOT Bootstrap's own `selector` delegation, which looks like the obvious
       * answer and is the reason this is written out longhand. That option runs
       * body.querySelectorAll(selector) on EVERY mouseover and mouseout and then
       * walks the ancestor chain against the whole result, so its cost scales
       * with how many titles the page holds. Measured on the group Waitlist,
       * which carries about 4,500 of them: 9.9ms of script per hover transition
       * against 0.02ms once replaced - jank you can feel dragging across a table.
       * jQuery matches the target's own ancestors instead, which is the same
       * work whatever the page weighs.
       */
      .on('mouseenter focusin', TIP_SEL, function (e) {
        // An empty title is not a tooltip. Several cells build one from a value
        // that can be blank, and Bootstrap would hold an instance that can never
        // show and never hide, so it would never be cleaned up either.
        if (!tipText(this) || bootstrap.Tooltip.getInstance(this)) return;
        new bootstrap.Tooltip(this, TIP_OPTS);
        // Its listeners went on the element a moment after the event that got us
        // here had already passed it, so hand Bootstrap the same event again.
        this.dispatchEvent(e.type === 'focusin'
          ? new FocusEvent('focusin', { bubbles: true })
          : new MouseEvent('mouseover', { bubbles: true }));
      })
      .on('inserted.bs.tooltip', function (e) {
        tipOn = e.target;
        tipWatch.observe(document.body, { childList: true, subtree: true });
      })
      // An instance lives exactly as long as its bubble. Bootstrap keeps every
      // one it has ever built in a plain Map keyed by element, so on a screen
      // that re-renders its table all day - all of them - instances for rows
      // that no longer exist would pile up for the life of the tab. Disposing
      // here is safe: hide() tears the popper down and drops aria-describedby
      // BEFORE it fires this event, and it does not fire at all if the pointer
      // came back mid-fade.
      .on('hidden.bs.tooltip', function (e) { dropTip(e.target); })
      // A click is the answer to whatever the tooltip was explaining, so it goes
      // - otherwise it hangs over the modal or the cell panel the click just
      // opened, and the pointer never leaves the trigger, so hover alone would
      // never clear it. Both the element clicked AND whatever is currently up:
      // on a real click the tooltip is usually still inside its show delay and
      // has no bubble yet, which is how the waitlist name button left one
      // floating over its own dialog.
      .on('click', function (e) {
        dropTip($(e.target).closest(TIP_SEL)[0]);
        dropTip(tipOn);
      });
  }

  // --- Scrolling tables fit the window ---------------------------------------
  // .table-scroll caps its own height in CSS against a CONSTANT allowance for the
  // chrome above it (see app.css). A constant cannot be right on every screen: the
  // Waitlist puts four tiles and a sibling banner above its table, so the card ran
  // ~160px past the bottom of the window and the PAGE grew a second scrollbar with
  // almost no travel in it. Two scrollbars a few pixels apart, and dragging the
  // outer one takes the sticky header off screen - which is the whole point of
  // scrolling the table inside its card rather than with the page.
  //
  // Measured off the element's DOCUMENT position, never its viewport position, so
  // a page that is already scrolled cannot feed its own scroll back into the
  // height and grow every time this runs. The CSS value stays as the no-JS floor.
  // .ts-nocap opts out: the Dashboard scrolls as a page, deliberately. So does
  // anything inside a modal or an offcanvas - those are position:fixed, so a
  // document offset does not describe them and the dialog does its own scrolling
  // (the cell panel's room roster is one, sitting inside <main> in the markup).
  //
  // Two outcomes only, and never a stub scrollbar between them: either the table
  // ends at the bottom of the window and the page does not scroll at all, or it
  // is a full window tall and the page scroll that reaches it is worth making.
  // The in-between - a table 13px past the bottom - is the complaint. So a fit
  // is taken when it leaves a usable table (200px, three or four rows) and
  // abandoned when it does not: the group Waitlist tab carries two cards above
  // its register and cannot fit one on an 800px window whatever we do here.
  var FIT_MIN = 200;

  function fitScrollers() {
    $('.table-scroll').not('.ts-nocap').each(function () {
      // A scroller inside a hidden container reports top 0, which would hand it
      // the whole window. Leave it to the stylesheet until it is on screen.
      if (this.offsetParent === null || this.closest('.modal, .offcanvas')) return;
      var main = this.closest('.app-main');
      if (!main) return;
      var r = this.getBoundingClientRect();
      // Whatever sits BELOW this box inside <main> - the board's legend, a
      // footnote, main's own bottom padding. It has to be counted or the table
      // fills the window and pushes the tail off the bottom, which is the same
      // barely-moving page scrollbar in a different place. The gap between the
      // two bottoms does not move when this box resizes, so it is safe to
      // measure while resizing it.
      var below = main.getBoundingClientRect().bottom - r.bottom;
      // DOCUMENT position, never the viewport one, so a page that is already
      // scrolled cannot feed its own scroll back in and grow on every run.
      // Floor it: a fractional height leaves a 1px page scrollbar behind.
      var fit = Math.floor(window.innerHeight - (r.top + window.scrollY) - below);
      this.style.maxHeight = (fit >= FIT_MIN ? fit
        : Math.max(FIT_MIN, Math.floor(window.innerHeight - below))) + 'px';
    });
  }
  window.fitScrollers = fitScrollers;

  fitScrollers();
  // Webfonts land after DOMReady and move everything above the table down a few
  // pixels, which is exactly the size of scrollbar this is here to remove.
  $(window).on('load resize', fitScrollers);
  // Every screen fills its tiles, banners and rows by AJAX, so the offset this
  // depends on is not final until those land. jQuery fires ajaxComplete after the
  // .done() handlers that write the DOM; the timeout covers a render deferred out
  // of one. No page has to remember to call this.
  $(document).ajaxComplete(function () { setTimeout(fitScrollers, 0); });

  // --- Sidebar collapse ------------------------------------------------------
  // The state lives in a COOKIE rather than localStorage so the SERVER can put
  // the class on .app-shell before the page paints. Setting it from here after
  // load would flash the full-width sidebar on every navigation, and the CSP
  // rules out the inline <script> that would normally run before first paint.

  function setSidebar(collapsed) {
    $('.app-shell').toggleClass('sb-collapsed', collapsed);
    $('#sb-toggle')
      .attr('title', collapsed ? 'Expand the sidebar' : 'Collapse the sidebar')
      .attr('aria-expanded', collapsed ? 'false' : 'true');
    // A year, path-wide. Lax matches the session cookie; this holds no secret,
    // only a display preference.
    document.cookie = 'sb_collapsed=' + (collapsed ? '1' : '0') +
      ';path=/;max-age=31536000;samesite=Lax' + (location.protocol === 'https:' ? ';secure' : '');
  }

  $('#sb-toggle').on('click', function () {
    setSidebar(!$('.app-shell').hasClass('sb-collapsed'));
  });
  // Label the button correctly for whatever the server already rendered.
  if ($('#sb-toggle').length) {
    var isCollapsed = $('.app-shell').hasClass('sb-collapsed');
    $('#sb-toggle')
      .attr('title', isCollapsed ? 'Expand the sidebar' : 'Collapse the sidebar')
      .attr('aria-expanded', isCollapsed ? 'false' : 'true');
  }

  // --- Session expiry -> back to login ---------------------------------------
  // The server already drops an unauthenticated request to Entra on a protected
  // page load (auth_require) and answers 401 JSON from /api when the session is
  // gone (auth_require_api). This makes the browser act on both the instant they
  // happen, rather than leaving a dead page or a silently-failing button.

  function toLogin() {
    // Never loop the /auth/* endpoints back through themselves.
    if (/^\/auth\//.test(window.location.pathname)) return;
    var here = window.location.pathname + window.location.search;
    window.location.assign('/auth/login?return=' + encodeURIComponent(here));
  }

  // Reactive: any request that comes back 401 is a real auth failure (app-level
  // errors use api_fail() = HTTP 200 + ok:false, never 401), so bounce at once.
  $(document).ajaxError(function (event, jqXHR) {
    if (jqXHR && jqXHR.status === 401) toLogin();
  });

  // Proactive: while a tab sits idle, kick to login as soon as the server-side
  // session could have lapsed, so the user is re-SSO'd (seamlessly, for a
  // signed-in Edge user) instead of clicking into a dead screen later. Any
  // completed request rearms the clock, mirroring the session's idle timeout.
  var ttl = parseInt($('meta[name="session-ttl"]').attr('content'), 10);
  if (ttl > 0) {
    var timer;
    var arm = function () {
      clearTimeout(timer);
      timer = setTimeout(toLogin, (ttl + 15) * 1000); // small grace over server TTL
    };
    $(document).ajaxComplete(arm);
    arm();
  }

})(jQuery);
