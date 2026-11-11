/**
 * The work-item modal - SHARED by the Placement board and the In progress module.
 *
 * This lived inside board.js, which is why a work item could only ever be opened
 * from one place in the whole app: a room x weekday cell on the board. Finding an
 * item meant already knowing which cell it was raised from, and a tester who
 * clicked "Start working this" by mistake had no route back to it at all.
 *
 * Markup: _incl/work-modal.php. Both pages include both, so there is one
 * definition of each - the lesson the stat tile (six copies) and the mark bar
 * (three) each taught the hard way.
 *
 * REBUILT 18 SEP 2026. The markup file carries the measurements; what changed on
 * this side is that render() now paints three things it used to leave flat -
 * the offer as a graded alert, the guardian as a phone number you can press, and
 * the two history lists as ONE timeline, newest first.
 *
 * Public surface:
 *   window.openWorkItem(id, onSaved)   open it; onSaved() after a successful write
 */
(function ($) {
  'use strict';

  var DAYS = { 1: 'Mon', 2: 'Tue', 3: 'Wed', 4: 'Thu', 5: 'Fri' };
  // PAST TENSE, deliberately, and NOT api/comms's COMM_CHANNELS ('Phone',
  // 'Email'). These label a line in a timeline - something that already
  // happened - where "Called" reads as the event and "Phone" reads as a field.
  // The mark beside them is window.channelIcon(), which IS shared, because a
  // logged contact must carry the same glyph on every screen that renders one.
  var CHANNELS = { phone: 'Called', email: 'Emailed', sms: 'Texted',
                   in_person: 'In person', other: 'Contacted' };

  // Stages Kidsoft sets and nobody picks by hand (22 Oct 2026). Mirrors the
  // refusal in /api/work's `advance`.
  var KIDSOFT_STAGES = { offered: true, accepted: true };

  var modal = null;
  var onSaved = null;      // what the host page wants doing after a write
  var current = null;      // the item on screen
  // The pipeline order, taken from the server's WORK_STAGES rather than retyped.
  // WORK_STAGES is the one place that ordering is written down and this must not
  // become a second one.
  var stageKeys = [];
  // The "by ..." dates and today's date, published by /api/work's `get`. Held
  // per open rather than per page: they are DATES, and nothing here may build
  // one - see work_due_presets().
  var due = { today: '', options: [] };

  function m() {
    modal = modal || new bootstrap.Modal(document.getElementById('work-modal'));
    return modal;
  }

  window.openWorkItem = function (id, done) {
    onSaved = done || function () {};
    $.getJSON('/api/work', { action: 'get', id: id }).done(function (res) {
      if (!res.ok) { toast(res.error, 'danger'); return; }
      due = res.due || { today: '', options: [] };
      render(res.item, res.stages);
    }).fail(function () { toast('Could not open that item.', 'danger'); });
  };

  /**
   * The offer behind the item: what was offered, on which days, and how long is
   * left to answer.
   *
   * THE DAYS HERE ARE THE OFFER'S, NOT THE ITEM'S. The subtitle already carries
   * the item's days - what this family is being worked toward - and the two
   * are not the same fact. An item raised from a cell can be offered a narrower
   * set than it was aiming at, and once the family answers, `accepted` is per
   * DAY: taking the Monday and turning down the Wednesday is normal here. See
   * work_offer_detail().
   *
   * The answer is spelled out in WORDS rather than given the strip a third
   * colour. Two shades of "on" would be a new visual state to learn, on a mark
   * that already means one thing on two other screens, and "Took Mon, turned
   * down Wed" cannot be misread.
   *
   * IT IS AN ALERT NOW, AND THE ALERT COLOUR IS THE SAME GRADING AS THE TEXT.
   * This block used to be a plain bordered box in which "Offer expired" was grey
   * body text carrying exactly as much weight as "Starting 5/01/2027" beneath
   * it - on an item whose whole reason for being open is that clock.
   * window.offerUrgency() already decides urgency for the In progress list and
   * the Waitlist; reading the band off it here rather than re-testing
   * hours_left is what stops one offer being red on the table and calm in the
   * dialog.
   *
   * @returns {{html:string, cls:string}} cls is the alert modifier for the
   *          container in work-modal.php. Empty html means there is no offer.
   */
  function offerBlock(o) {
    if (!o) return { html: '', cls: '' };

    // Same grading as everywhere else in the app: inside 3 days or already gone
    // is red, and a lapsed offer says so rather than counting backwards from it.
    var head, cls = 'text-muted', alert = 'alert-light border';
    if (o.status === 'pending') {
      // Shared grading, this screen's wording - see window.offerUrgency().
      var u = offerUrgency(o.hours_left) || { band: 'days', cls: '' };
      cls = u.cls;
      // A LIVE offer is the loudest thing in the app and an urgent one is the
      // reason the row is at the top of the list. `urgent` is the shared test
      // (inside OFFER_URGENT_HOURS), so this cannot drift from the table.
      alert = u.band === 'lapsed' ? 'alert-danger'
            : (u.urgent ? 'alert-danger' : 'alert-primary');
      if (u.band === 'lapsed')     head = 'Offer has lapsed';
      else if (u.band === 'hours') head = 'Offer open, ' + u.hours + ' hours left';
      else                         head = 'Offer open, expires ' + fmtDateTime(o.expires_at);
    } else {
      head = { accepted: 'Offer accepted', partially_accepted: 'Offer partly accepted',
               declined: 'Offer declined', expired: 'Offer expired',
               withdrawn: 'Offer withdrawn' }[o.status] || 'Offer ' + o.status;
      if (o.responded_at) head += ' ' + fmtDateTime(o.responded_at);
      // AN ANSWERED OFFER IS HISTORY, NOT AN ALARM. `accepted` is the one the
      // team acts on - they have said yes and Kidsoft does not show it yet -
      // which is the same reason workStageClass() paints that one stage amber
      // and leaves the rest grey. Everything else here is context.
      alert = o.status === 'accepted' ? 'alert-warning' : 'alert-light border';
    }

    // BLUE, the strip's "asked for": an offer is days a family wants and has not
    // got. Which of them they then took is the sentence underneath, not a
    // colour - a partly accepted offer would otherwise need a fifth state that
    // exists on this one card and nowhere else. See window.dayStrip().
    var strip = dayStrip({ want: o.days.map(function (d) { return d.weekday; }) });

    var names = function (list) {
      return list.map(function (d) { return DAYS[d.weekday]; }).join(', ');
    };
    var took = o.days.filter(function (d) { return d.accepted === true; });
    var left = o.days.filter(function (d) { return d.accepted === false; });
    var answer = took.length || left.length
      ? '<div class="small text-muted mt-1">' +
        (took.length ? 'Took ' + esc(names(took)) + '. ' : '') +
        (left.length ? 'Turned down ' + esc(names(left)) + '.' : '') + '</div>'
      : (o.start_date
          ? '<div class="small text-muted mt-1">Starting ' + esc(fmtDate(o.start_date)) + '</div>'
          : '');

    return {
      cls: alert,
      html: '<div class="d-flex align-items-center gap-2">' + strip +
        '<span class="small ' + cls + '">' + esc(head) + '</span></div>' + answer
    };
  }

  /**
   * Who to ring, as things you can press.
   *
   * THE MODAL TOLD YOU TO RING A FAMILY AND DID NOT CARRY A PHONE NUMBER. Every
   * stage of this pipeline comes down to a call, and answering "what is their
   * number" meant leaving for the Waitlist and looking the family up - on the
   * screen whose entire job is to stop the team hunting for things the app
   * already holds. 851 of 855 applications carry a guardian phone and 850 an
   * email, in our own tables, free to read.
   *
   * LINKS, NOT INPUTS, and the distinction is the whole reason this can be here.
   * The editable boxes below (#work-enquiry) are still rendered for an ENQUIRY
   * only, because a queue family's number belongs to Kidsoft and a box holding
   * it invites somebody to key it a second time. Showing a number is not asking
   * for it again.
   *
   * tel: and mailto: because a good half of this team works from a laptop with a
   * softphone, and because a number you can press is a number that does not get
   * mis-dialled off a screen.
   *
   * The shape comes from work_get()'s $w['reach'], which is already resolved
   * server-side to one of guardian / enquiry - so this renders it and does not
   * decide which kind of candidate it is looking at.
   */
  function reachBlock(r) {
    if (!r) return '';
    var bits = [];
    if (r.phone) {
      bits.push('<a class="btn btn-sm btn-outline-primary" href="tel:' + esc(r.phone) + '">' +
        '<i class="fa-solid fa-phone fa-sm me-1"></i>' + esc(r.phone) + '</a>');
    }
    if (r.email) {
      bits.push('<a class="btn btn-sm btn-outline-secondary text-truncate mw-100" href="mailto:' +
        esc(r.email) + '" title="' + esc(r.email) + '">' +
        '<i class="fa-solid fa-envelope fa-sm me-1"></i>' + esc(r.email) + '</a>');
    }
    // THE NAME ALONE IS STILL WORTH RENDERING. 783 of 855 applications carry a
    // guardian name against 851 a phone, so the two do not always arrive
    // together - and "who am I asking for" is the first thing said on a call.
    // A family with a name and no number gets the name and no buttons, which is
    // a true statement about what we hold.
    //
    // AND A MISSING NAME GETS NOTHING, not "No name recorded". Withhold a figure
    // rather than captioning why it is missing (CLAUDE.md): the caption is a
    // sentence explaining the team's own data back to them, and beside a phone
    // number they can already press it is pure noise.
    var who = r.name ? '<span class="small text-muted me-1">' + esc(r.name) + '</span>' : '';
    return '<div class="d-flex flex-wrap align-items-center gap-2">' + who +
      bits.join('') + '</div>';
  }

  /**
   * The record: stage changes and logged contacts, in one column, newest first.
   *
   * IT WAS TWO LISTS SORTED OPPOSITE WAYS. "This enrolment" ran oldest-first off
   * work_item_events and "Contact with this family" ran newest-first off
   * communications, so reconstructing one week meant reading both and
   * interleaving them by hand - and both of them began at y=829, below the fold
   * on the laptop viewport this app measures against.
   *
   * THE TWO KINDS STAY TOLD APART, and that is not decoration. A stage change is
   * what the ITEM did; a contact is what happened with the FAMILY and outlives
   * any one item - work_contact_history() looks up by WHO, so a contact rendered
   * here may predate this item entirely and belong to a different spot being
   * discussed. Merging them into one undifferentiated stream would assert that
   * every line is part of this item's history, which is false. Each carries its
   * own mark, its own border colour and its own wording.
   *
   * SORTED BY STRING COMPARE, NEVER new Date(). These are Sydney wall-clock
   * strings in 'Y-m-d H:i:s', which sort lexicographically in exactly
   * chronological order - and any route through Date() re-interprets them in the
   * viewer's browser timezone, which is the trap CLAUDE.md names and the reason
   * tour_past is computed on the server.
   */
  function timelineHtml(w, stages) {
    var rows = [];
    (w.events || []).forEach(function (e) { rows.push({ at: e.occurred_at, e: e }); });
    (w.contacts || []).forEach(function (c) { rows.push({ at: c.occurred_at, c: c }); });
    if (!rows.length) return '<div class="small text-muted">Nothing recorded yet.</div>';

    // ONE ACT, ONE LINE. A save that MOVES the stage writes both a stage event
    // carrying the summary and a contact carrying that same summary - so a
    // merged list would print the same words twice, one above the other, for
    // what the user did once. They fold into a single entry showing both facts,
    // and the detail is taken off the contact, which is the only side that has
    // a `body`.
    //
    // Compared on the first 255 characters, which is belt and braces two ways
    // over. The live path posts the SAME string to both (the summary input is
    // capped at 255, and work_item_events.note holds 500), so they agree
    // exactly - but rows written before 18 Sep 2026 came through the removed
    // work_log_contact(), which kept 500 on the event and mb_substr'd 255 onto
    // the contact. Matching on the full string would silently stop folding for
    // exactly those, the long notes where the duplication is most obvious.
    //
    // Matched to the MINUTE, not the second: the two INSERTs take their own
    // CURRENT_TIMESTAMP and can land either side of a tick. One sentence logged
    // twice inside a minute is one act.
    var minute = function (t) { return String(t || '').slice(0, 16); };
    rows.forEach(function (r) {
      if (!r.e || !r.e.note) return;
      rows.forEach(function (o) {
        if (!o.c || o.folded || r.folded) return;
        if (minute(o.at) !== minute(r.at)) return;
        if (o.c.summary !== r.e.note.slice(0, 255)) return;
        o.folded = true;          // the contact's words are already on the event
        r.c = o.c;                // ...so the event carries its channel instead
      });
    });
    rows = rows.filter(function (r) { return !r.folded; });

    rows.sort(function (a, b) {
      if (a.at !== b.at) return a.at < b.at ? 1 : -1;
      // Same second, different acts: the stage change is the cause and the
      // contact is what was said, so the stage reads first going down the page.
      return (a.e ? 0 : 1) - (b.e ? 0 : 1);
    });

    return rows.map(function (r) {
      return r.e ? eventLine(r.e, r.c, stages) : contactLine(r.c);
    }).join('');
  }

  /**
   * What a logged contact WAS, in the tense a timeline reads in.
   *
   * It used to be the channel word plus "(they rang us)" on anything inbound,
   * which put "Emailed (they rang us)" under an envelope - the parenthetical
   * contradicting the mark beside it. Direction belongs in the verb, not in a
   * bracket after it: a family who emailed in "emailed", they did not ring.
   *
   * `internal` is what the "Note" chip writes, and rendering it as an outbound
   * contact would show a note to self as something said to a parent. It is also
   * the flag that keeps the note out of Kidsoft - see logContact() below and the
   * matching guard in api/comms.
   */
  function contactVerb(c) {
    if (c.direction === 'internal') return 'Internal note';
    var label = CHANNELS[c.channel] || c.channel;
    if (c.direction !== 'inbound') return label;
    return { phone: 'They called', email: 'They emailed', sms: 'They texted',
             in_person: 'They came in', other: 'They made contact' }[c.channel]
           || 'They made contact';
  }

  /**
   * Who did it. `actor` is an Entra email for a human, 'kidsoft' when the mirror
   * closed the item, and 'staff' for the offer machinery - which means "the
   * default was used" and genuinely does not record a person. Showing nothing
   * for that case is the honest answer; inventing one is not.
   */
  function actorLine(actor) {
    if (!actor || actor === 'staff') return '';
    if (actor === 'kidsoft') return '<span class="badge text-bg-light border">by Kidsoft</span>';
    return esc(staffName(actor));
  }

  /** A stage change, optionally carrying the contact it was logged with. */
  function eventLine(e, c, stages) {
    var label = (stages[e.to_stage] || {}).label || e.to_stage;
    // The contact's author, not the event's, when they are folded: they are the
    // same person by construction (one save), and `actor` is 'staff' on anything
    // the offer machinery wrote while `staff_email` is never null.
    var meta = [c ? esc(staffName(c.staff_email)) : actorLine(e.actor)];
    if (c) meta.push(esc(contactVerb(c)));
    meta = meta.filter(function (x) { return x; });
    var shared = c ? sharedNote(c) : { meta: '', copy: false };

    return '<div class="comm-entry comm-entry-stage">' +
      '<div class="d-flex justify-content-between align-items-start gap-2">' +
        '<div class="fw-semibold small">' +
          (c ? '<i class="' + channelIcon(c.channel) + ' me-2 text-muted"></i>' : '') +
          '<span class="badge ' + workStageClass(e.to_stage) + '">' + esc(label) + '</span>' +
        '</div>' +
        '<div class="small text-muted text-nowrap">' + esc(fmtDateTime(e.occurred_at)) + '</div>' +
      '</div>' +
      (e.note ? '<div class="small mt-1">' + esc(e.note) + '</div>' : '') +
      // THE DETAIL COMES OFF THE FOLDED CONTACT, never off the event. The event
      // note is the summary only (that is what work-modal.js posts to `advance`,
      // and what the fold matches on); `body` exists on `communications` alone.
      (c && c.body ? '<div class="small mt-1">' + esc(c.body).replace(/\n/g, '<br>') + '</div>' : '') +
      // A Delete on a folded note takes its words off this stage change too, and
      // leaves the change - see `delete` in api/comms.
      (meta.length ? '<div class="small text-muted mt-1">' + meta.join(' &middot; ') +
        shared.meta + (c && shared.copy ? copyTag(c) : '') + (c ? commDeleteBtn(c) : '') + '</div>' : '') +
      '</div>';
  }

  /**
   * A logged contact with the family.
   *
   * SAME SHAPE AS THE FAMILY MODAL'S, down to the border colour by direction -
   * one contact note must not look like two different things depending on which
   * screen rendered it. See loadComms() in waitlist-modal.js.
   */
  function contactLine(c) {
    var dirCls = c.direction === 'inbound' ? 'border-info'
               : (c.direction === 'internal' ? 'border-secondary' : 'border-primary');
    var meta = [esc(contactVerb(c))];
    if (c.staff_email) meta.push(esc(staffName(c.staff_email)));
    // A brother or sister's call shown here too, or this one shown on theirs -
    // see window.sharedNote(). Its Kidsoft copy is the other child's.
    var shared = sharedNote(c);

    return '<div class="comm-entry ' + dirCls + '">' +
      '<div class="d-flex justify-content-between align-items-start gap-2">' +
        '<div class="fw-semibold small"><i class="' + channelIcon(c.channel) +
          ' me-2 text-muted"></i>' + esc(c.summary) + '</div>' +
        '<div class="small text-muted text-nowrap">' + esc(fmtDateTime(c.occurred_at)) + '</div>' +
      '</div>' +
      (c.body ? '<div class="small mt-1">' + esc(c.body).replace(/\n/g, '<br>') + '</div>' : '') +
      '<div class="small text-muted mt-1">' + meta.join(' &middot; ') + shared.meta +
        (shared.copy ? copyTag(c) : '') + commDeleteBtn(c) + '</div>' +
      '</div>';
  }

  // toLocalInput() MOVED TO app.js on 10 Sep 2026 (window.toLocalInput). The
  // family modal on the Waitlist books the same tour now, and a second copy of
  // a date rule is how two screens come to show one appointment differently --
  // which is the bug that change was fixing.

  // The tour question - "it has been and gone, did they come?" - is
  // window.tourAskHtml() in app.js since 10 Sep 2026. The family modal on the
  // Waitlist asks it too, and one appointment must not be asked about in two
  // sets of words. Everything else about the tour is the date field in the form.

  function render(w, stages) {
    current = w;
    stageKeys = Object.keys(stages);

    // 'enrolled' is not offered: an item is closed by Kidsoft showing the child
    // in the room, never by hand. Letting somebody tick it would make the one
    // honest number on the Dashboard - places actually filled - an opinion.
    //
    // OFFERED AND ACCEPTED ARE KIDSOFT'S (22 Oct 2026, the enrolments meeting),
    // so they are listed only on an item that is already there - the select has
    // to be able to show where it is. /api/work refuses a move to either by hand.
    var options = Object.keys(stages)
      .filter(function (k) {
        if (k === 'enrolled') return false;
        // ARCHIVED IS A BUTTON, NOT A STAGE TO PICK (6 Nov 2026) - #work-archive.
        // Listed only on an item already archived, so it can be read; picking
        // an earlier stage and saving is how one is reopened.
        if (k === 'archived') return w.stage === 'archived';
        if (KIDSOFT_STAGES[k]) return k === w.stage;
        return true;
      })
      .map(function (k) {
        return '<option value="' + k + '"' + (k === w.stage ? ' selected' : '') + '>' +
          esc(stages[k].label) + '</option>';
      }).join('');

    // AN ENQUIRY HAS NO ROOM, NO DAYS AND NO YEAR, and the subtitle has to read
    // as a sentence when all three are missing rather than as "No room set -  ".
    // w.year is genuinely null on an enquiry, so printing it raw put the word
    // "null" on the screen.
    var bits = [];
    if (w.centre_name) bits.push(esc(w.centre_name));
    bits.push(w.room_name ? roomIcon(w.room_name) + esc(w.room_name) : 'No room set');
    var days = (w.days || []).map(function (d) { return DAYS[d]; }).join(', ');
    if (days)   bits.push(esc(days));
    if (w.year) bits.push(esc(String(w.year)));
    $('#work-title').text(w.child_name);
    $('#work-subtitle').html(bits.join(' - '));

    // THE OFFER, AS A GRADED ALERT. The container carries no colour of its own
    // in the markup - offerBlock() picks it off the shared urgency grading, so
    // the class has to be swapped out on every open rather than added to.
    var offer = offerBlock(w.offer);
    $('#work-offer')
      .attr('class', 'mb-3 alert py-2 px-3 ' + offer.cls)
      .toggleClass('d-none', !w.offer)
      .html(offer.html);

    var ask = tourAskHtml(w);
    $('#work-tour-ask').html(ask).toggleClass('d-none', !ask);

    // AN OFFER TO ONE SIBLING AND NOT THE OTHER, for the same start (19 Oct
    // 2026) - work_offer_gap(). Said in words both ways round; which child has
    // the offer is the whole point.
    var gap = (w.offer_gap || []).map(function (x) {
      return '<div><i class="fa-solid fa-triangle-exclamation me-1"></i>' + (x.offered
        ? esc(x.name) + ' has an offer in Kidsoft for the same start' + (x.starts ? ' (' + esc(x.starts) + ')' : '') +
          '. ' + esc(w.child_name) + ' has none yet.'
        : esc(x.name) + ' has no offer in Kidsoft yet, and asked to start' +
          (x.starts ? ' ' + esc(x.starts) : '') + ' too.') + '</div>';
    }).join('');
    $('#work-family-alert').html(gap).toggleClass('d-none', !gap);
    // The Waitlist's duplicate flag on this item's application (6 Nov 2026).
    $('#work-dup').html(dupAlert(w.duplicate)).toggleClass('d-none', !w.duplicate);

    // WHO TO RING, above the form rather than nowhere.
    var reach = reachBlock(w.reach);
    $('#work-reach').html(reach).toggleClass('d-none', !reach);

    $('#work-form [name=id]').val(w.id);
    $('#work-form [name=stage]').html(options);
    $('#work-form [name=tour_at]').val(toLocalInput(w.tour_at));
    // THE HELP TEXT DEPENDS ON THE STATE, and it used to read "Clear it to
    // cancel the tour." on every item in the app - including the ones with no
    // tour to cancel, and the ones whose tour had been and gone and was being
    // answered in the alert directly above it.
    $('#work-tour-help').text(
      !w.tour_at                       ? 'Book one and this family moves to Tour booked.'
      : (w.tour_outcome || w.tour_past) ? 'Been and gone. Put a new date here to book another.'
                                        : 'Clear it to cancel the tour.');
    $('#work-tour-cal').html(tourCalendarNote(w.calendar));
    // To the same address "who to ring" shows. An enquiry's can be retyped below,
    // and the label follows it - see the input handler further down.
    $('#work-tour-invite').html(tourInviteBox('work-invite', (w.reach && w.reach.email) || '', w.tour_invite));
    // THE TOUR IS THE FAMILY'S - see window.familyTourTicks().
    $('#work-tour-family').html(familyTourTicks(w.family, w.tour_at, 'siblings[]'));
    $('#work-form [name=next_action]').val(w.next_action || '');
    $('#work-form [name=next_action_at]').val(w.next_action_at || '');
    $('#work-when-presets').html((due.options || []).map(function (o) {
      return '<button type="button" class="btn btn-sm btn-outline-secondary" data-date="' +
        esc(o.date) + '" title="' + esc(fmtDate(o.date)) + '">' + esc(o.label) + '</button>';
    }).join(''));
    syncNextStep();
    $('#work-form [name=owner_email]').val(w.owner_email || '');

    // WHO THE NEXT STEP IS FOR. "Me" unless it names somebody else - which
    // includes a step a colleague set for themselves, so saving it here does not
    // quietly hand it to whoever opened the item.
    $('#work-people').html((w.people || []).map(function (p) {
      return '<option value="' + esc(p.email) + '">' + esc(p.name) + '</option>';
    }).join(''));
    var forOther = !!w.next_action_for &&
      String(w.next_action_for).toLowerCase() !== String(window.USER_EMAIL || '').toLowerCase();
    $(forOther ? '#wf-other' : '#wf-me').prop('checked', true);
    $('#work-next-for').val(forOther ? w.next_action_for : '');
    syncNextFor();

    // THE ROOM AND DAYS BEING TALKED ABOUT. Shown but not editable from Offered
    // on, when the offer in Kidsoft decides them - work_room_days_locked().
    $('#work-room').html('<option value="">Not decided</option>' + (w.rooms || []).map(function (r) {
      return '<option value="' + r.id + '"' + (+r.id === +w.room_id ? ' selected' : '') + '>' +
        esc(r.name) + '</option>';
    }).join('')).prop('disabled', !!w.room_days_locked);
    $('#work-days input').each(function () {
      $(this).prop('checked', (w.days || []).indexOf(+this.value) !== -1)
             .prop('disabled', !!w.room_days_locked);
    });
    $('#work-rd-help').toggleClass('d-none', !w.room_days_locked);
    // THE COMPOSE BLOCK RESETS ON EVERY OPEN, chips included. A channel left
    // selected from the last family is how a note about one child gets filed as
    // a phone call that was made to another.
    $('#work-form [name=summary]').val('');
    $('#work-form [name=body]').val('');
    $('#wc-note').prop('checked', true);
    $('#wd-out').prop('checked', true);
    forcedChip = false;
    syncStage();
    $('#work-note-family').html(familyNoteTicks(w.family));
    syncLost();

    // Contact details only where they are OURS to hold. A queue family's phone
    // number is in Kidsoft and re-typing it here is the double-keying this tool
    // exists to remove, so the block is not rendered for them at all.
    $('#work-enquiry').toggleClass('d-none', !w.is_enquiry);
    $('#work-form [name=contact_name]').val(w.contact_name || '');
    $('#work-form [name=contact_phone]').val(w.contact_phone || '');
    $('#work-form [name=contact_email]').val(w.contact_email || '');

    // "Created by mistake" only while there IS nothing else - one creation
    // event, no offer, no logged contact. Past that it is a record of real work
    // and the honest exit is Lost. The server refuses it on the same test, so
    // this is a courtesy rather than the control.
    var pristine = w.stage === 'identified' && (w.events || []).length <= 1
                   && !w.offer_id && !(w.contacts || []).length;
    $('#work-cancel').toggleClass('d-none', !pristine);
    // ARCHIVE: the exit for an item with a history that has no outcome - a
    // duplicate or leftover application (6 Nov 2026, Lennox Dransfield). Not on
    // a pristine item, whose honest exit is "Created by mistake" above, and not
    // on one already closed.
    var isOpen = ['enrolled', 'lost', 'archived'].indexOf(w.stage) === -1;
    $('#work-archive').toggleClass('d-none', pristine || !isOpen);

    // ONE COLUMN, newest first - the item's stage changes and the family's
    // contact history, which outlives this item: a family worked for a Tuesday
    // last term and a Friday this one is one family, one history.
    $('#work-timeline').html(timelineHtml(w, stages));

    m().show();
    // AFTER show(), never before: this is a Kidsoft read and the modal must not
    // wait on a third party to appear.
    loadKidsoft(w);
  }

  /**
   * LOST ASKS ABOUT THE BROTHERS AND SISTERS (19 Oct 2026): shown only while the
   * stage says Lost, only for siblings with an open item here, and UNTICKED - a
   * family going elsewhere usually takes both, but that is a person's call.
   * Rebuilt on every change, so a tick cannot survive a change of mind.
   */
  function syncLost() {
    var lost = $('#work-stage').val() === 'lost' && current && current.stage !== 'lost';
    $('#work-lost-family').html(lost ? familyTicks(current.family, {
      label: 'Also lost', name: 'also_lost[]',
      value: function (s) { return s.item.id; },
      checked: function () { return false; },
      only: function (s) { return !!s.item; },
      note: function (s) { return s.item.stage_label; }
    }) : '');
  }
  $('#work-stage').on('change', function () { syncLost(); syncStage(); });

  /**
   * What the form offers depends on the stage PICKED (22 Oct 2026, the
   * enrolments meeting). Each stage asks only what it can be about:
   *
   *   Identified          a note. Nobody has been contacted, so there is no
   *                       channel to pick and nothing to copy to Kidsoft.
   *   Attempted contact   how they were tried - a call, an email, a text - and
   *                       always outbound: an attempt is something we did.
   *   Tour booked         the tour's date and time, which shows for this stage
   *                       and no other (and on an item whose tour is still
   *                       ahead, so it can be moved or cleared).
   *   Toured, on the move into it
   *                       "Tour notes", in person: the visit is the contact.
   *                       Once the item sits at Toured a later call is logged as
   *                       a call, which is why this is the move and not the stage.
   *   everything else     every channel, as before.
   */
  var STAGE_CHANNELS = {
    identified: ['note'],
    attempted_contact: ['note', 'phone', 'email', 'sms', 'other']
  };
  function stageChannels(stage) {
    if (stage === 'tour_done' && current && current.stage !== 'tour_done') return ['in_person'];
    return STAGE_CHANNELS[stage] || null;
  }
  var forcedChip = false;
  function syncStage() {
    if (!current) return;
    var stage = $('#work-stage').val();

    var tourAhead = !!current.tour_at && !current.tour_past && !current.tour_outcome;
    $('#work-tour-wrap').toggleClass('d-none', stage !== 'tour_booked' && !tourAhead);

    var allowed = stageChannels(stage);
    $('#work-channels input').each(function () {
      var ok = !allowed || allowed.indexOf(this.value) !== -1;
      $(this).prop('disabled', !ok).toggleClass('d-none', !ok);
      $('label[for="' + this.id + '"]').toggleClass('d-none', !ok);
    });
    // A chip the stage FORCED goes back to the safe default when the stage that
    // forced it is un-picked - otherwise trying Toured and changing your mind
    // would leave "In person" quietly selected on a note about something else.
    if (forcedChip && (!allowed || allowed.length > 1)) $('#wc-note').prop('checked', true);
    if (!$('#work-channels input:checked:enabled').length) {
      $('#work-channels input:enabled').first().prop('checked', true);
    }
    forcedChip = !!allowed && allowed.length === 1;
    // One chip left is not a choice, so it is not shown as one.
    $('#work-channels').toggleClass('d-none', !!allowed && allowed.length === 1);

    $('#work-summary-label').text(
      stage === 'identified' ? 'Note'
        : (allowed && allowed[0] === 'in_person' ? 'Tour notes' : 'What happened'));
    $('#work-stage-help').toggleClass('d-none', !!KIDSOFT_STAGES[current.stage]);
    syncChannel(current);
  }

  /** The way round a contact went: fixed for an attempt and for tour notes. */
  function postedDirection(chip) {
    var stage = $('#work-stage').val();
    if (stage === 'attempted_contact') return 'outbound';
    if (chip === 'in_person' && stage === 'tour_done' && current && current.stage !== 'tour_done') return 'inbound';
    return $('#work-form [name=direction]:checked').val() || 'outbound';
  }

  function syncNextFor() {
    $('#work-next-for').toggleClass('d-none', !$('#wf-other').prop('checked'));
  }
  $('#work-for').on('change', 'input[name=next_for_who]', function () {
    syncNextFor();
    if ($('#wf-other').prop('checked')) $('#work-next-for').trigger('focus');
  });

  /** The ticked values of one family question, as an array for $.post. */
  function ticked(name) {
    return $('#work-form input[name="' + name + '"]:checked').map(function () { return +this.value; }).get();
  }

  /**
   * The chips' two consequences: whether the direction question is asked, and
   * what the line under the box says about where this note will end up.
   *
   * WHETHER IT TRAVELS IS SAID BEFORE IT IS TYPED. The family modal has done
   * this since 14 Aug and this screen wrote to the same table and said nothing.
   * Kidsoft only creates a child record when an application converts to an
   * enrolment, so for 87% of the queue there is nowhere to copy a note to - and
   * a staff member who believes it travelled stops keeping the Kidsoft note they
   * still need.
   *
   * Takes the item rather than reading `current`, so it cannot be called against
   * the family that was on screen a moment ago.
   */
  function syncChannel(w) {
    var chip = $('#work-form [name=channel]:checked').val() || 'note';
    var push = (w && w.kidsoft_push) || {};

    // An internal note has no direction: nobody contacted anybody.
    var stage = $('#work-stage').val();
    var forced = stage === 'attempted_contact' ||
      (chip === 'in_person' && stage === 'tour_done' && w && w.stage !== 'tour_done');
    $('#work-direction-wrap').toggleClass('d-none', chip === 'note' || forced);

    var msg;
    if (chip === 'note') {
      msg = 'Kept here, on this family, for whoever picks them up next. ' +
            'Internal notes are not copied to Kidsoft.';
    } else if (push.pushable) {
      msg = 'Also copied into this family\'s Kidsoft ' +
            (push.target === 'child' ? 'child' : 'guardian') +
            ' record, marked confidential.';
      // Rule 4 (19 Oct 2026): shown on a brother or sister here, never copied
      // onto their Kidsoft record - see api/comms.
      if (w.family && w.family.length) msg += ' Only for ' + w.child_name + ', not a brother or sister.';
    } else {
      // WITHHOLDING THIS WOULD BE THE BUG - it is the honest answer for most of
      // the queue, and the backlog sweep really does copy it across later.
      msg = 'Kidsoft holds no record of this family yet, so it stays here. ' +
            'It is copied across on its own if they enrol.';
    }
    $('#work-travel').text(msg);
  }

  // Delegated is unnecessary - these radios are in the markup, not rendered -
  // but `current` is, so the handler reads it rather than closing over an item.
  $('#work-channels, #work-direction-wrap').on('change', 'input', function () {
    syncChannel(current);
  });

  /**
   * The next step, which is ONE fact: what to do, and by when.
   *
   * The "by" row is revealed by there being something to be due - a step typed,
   * or a date already on the item - and hidden when there is neither. That is
   * what makes the date part of the sentence rather than a box beside it, and it
   * is the half that was being filled on its own: five live items carry a date
   * and no step, four of them dates that had already passed when they were
   * typed. work_posted_next_step() refuses either half server-side; this is the
   * courtesy that means nobody meets that refusal.
   */
  function syncNextStep() {
    var what = $.trim($('#work-next').val());
    var when = $('#work-next-at').val();
    $('#work-when').toggleClass('d-none', !what && !when);

    var msg = 'Overdue items rise to the top.';
    if (!what && when) {
      // THE LEGACY STATE, and it is named rather than silently cleared. Deleting
      // somebody's date because this screen now disapproves of it is a mutation
      // they did not ask for; saying what is wrong with it is not.
      msg = 'This date has no next step against it. Say what it is for, or clear it.';
    } else if (when && due.today && when < due.today) {
      // STRING COMPARE on two 'Y-m-d' values, which is exactly chronological and
      // needs no Date. `today` is Sydney's, from the server.
      msg = 'That date has already passed - this will show as overdue straight away.';
    }
    $('#work-when-help').text(msg);
  }

  // Typing a step reveals the "by" row; clearing it takes the date with it, so
  // the only way to reach "date with no step" is an item that arrived that way.
  $('#work-next').on('input', function () {
    if (!$.trim($(this).val())) $('#work-next-at').val('');
    syncNextStep();
  });
  $('#work-next-at').on('change', syncNextStep);

  // Delegated: the buttons are rebuilt on every open, because their dates are.
  $('#work-when-presets').on('click', 'button', function () {
    $('#work-next-at').val($(this).attr('data-date'));
    syncNextStep();
  });

  /**
   * What Kidsoft itself holds on this family, fetched after the modal is open.
   *
   * NOT PART OF THE OPEN, and that is the whole design. The family modal defers
   * this to a tab click for the same reason: it is a live API call, and the
   * editor is opened far more often than the history is read. Putting it in
   * work_get() would hang every click on a work item off a third party.
   *
   * AND MOST ITEMS MAKE NO REQUEST AT ALL. `kidsoft_push.pushable` already says
   * whether Kidsoft holds a child or guardian record for this family, and for
   * 87% of the queue it does not - there is nothing to fetch and asking would be
   * a round trip to be told so. The rest hit a per-centre cache (getChildren
   * 36 h, getGuardians 3 h) that the family modal already warms.
   *
   * IT IS ALSO ALLOWED TO FAIL QUIETLY. comment_kidsoft_existing() already
   * swallows a Kidsoft outage and returns nothing, and a toast saying "could not
   * reach Kidsoft" over a modal whose own record rendered perfectly would be
   * noise about somebody else's server. The block simply does not appear.
   *
   * Guarded on the item id at render time, because a slow response must not
   * paint one family's Kidsoft notes into another family's modal - the trap that
   * opened the wrong child's editor on every board in the app.
   */
  function loadKidsoft(w) {
    $('#work-kidsoft').addClass('d-none');
    $('#work-kidsoft-list').empty();
    if (!w.kidsoft_push || !w.kidsoft_push.pushable) return;

    var forId = w.id;
    $.getJSON('/api/comms', { action: 'list', work_item_id: forId })
      .done(function (res) {
        // The modal has moved on to another family, or been closed and reopened.
        if (!current || current.id !== forId) return;
        if (!res.ok) return;
        var html = kidsoftComments(res.kidsoft);
        $('#work-kidsoft').toggleClass('d-none', !html);
        $('#work-kidsoft-list').html(html);
      });
  }

  // An administrator deleted a note - commDeleteBtn() in app.js. Only the
  // timeline is redrawn: the form may hold typing that is not saved yet, and
  // render() would wipe it. Guarded on the item id like loadKidsoft().
  $('#work-timeline').on('comm:deleted', function () {
    if (!current) return;
    var forId = current.id;
    $.getJSON('/api/work', { action: 'get', id: forId }).done(function (res) {
      if (!res.ok || !current || current.id !== forId) return;
      current.events = res.item.events;
      current.contacts = res.item.contacts;
      $('#work-timeline').html(timelineHtml(current, res.stages));
      // A note that travelled now shows in the Kidsoft block instead.
      loadKidsoft(current);
    });
    onSaved();
  });

  // AN ENQUIRY'S EMAIL IS TYPED IN THIS FORM, and the save writes it before the
  // invite goes, so the invite box names what is in the field now.
  $('#work-form').on('input', '[name=contact_email]', function () {
    if (!current || !current.is_enquiry) return;
    $('#work-tour-invite').html(tourInviteBox('work-invite', $(this).val(), $('#work-invite').prop('checked')));
  });

  $('#work-mine').on('click', function () {
    $('#work-form [name=owner_email]').val(window.USER_EMAIL || '');
  });

  $('#work-form').on('submit', function (e) {
    e.preventDefault();
    var $f = $(this), id = $f.find('[name=id]').val();
    var stage   = $f.find('[name=stage]').val();
    var tourAt  = $f.find('[name=tour_at]').val();
    var nextWhat = $.trim($f.find('[name=next_action]').val());
    var nextWhen = $f.find('[name=next_action_at]').val();
    // BOTH OR NEITHER, checked before the first write goes out. `update` refuses
    // it too - this is here so the refusal lands on the field rather than as a
    // toast after a round trip.
    if (nextWhat && !nextWhen) {
      $('#work-when').removeClass('d-none');
      $('#work-next-at').trigger('focus');
      toast('Say when that next step is due.', 'danger');
      return;
    }
    if (!nextWhat && nextWhen) {
      $('#work-next').trigger('focus');
      toast('Say what that date is for, or clear it.', 'danger');
      return;
    }
    // "Tour booked" is for booking the date and time in, so a move into it asks
    // for one. An item already sitting there without a date (from before tours
    // had a time) is not trapped by this - only the move is.
    var tourShown = !$('#work-tour-wrap').hasClass('d-none');
    if (stage === 'tour_booked' && current && current.stage !== 'tour_booked' && !tourAt) {
      $('#work-tour').trigger('focus');
      toast('Put in the tour date and time.', 'danger');
      return;
    }
    var forWho = $('#wf-other').prop('checked') ? $.trim($('#work-next-for').val()) : '';
    if (nextWhat && $('#wf-other').prop('checked') && !forWho) {
      $('#work-next-for').trigger('focus');
      toast('Say who the next step is for.', 'danger');
      return;
    }

    var summary = $.trim($f.find('[name=summary]').val());
    var body    = $.trim($f.find('[name=body]').val());
    // The item's own history gets the SUMMARY only. `work_item_events.note` is
    // what explains a stage change and the timeline folds it against the
    // contact's summary, so the two must be the same string; the detail lives on
    // the contact, where `body` exists.
    var note    = summary;

    // BOOKING A TOUR IS REACHING THE TOUR STAGE, and without this the two writes
    // below fight each other: `update` routes the date through work_set_tour(),
    // which promotes the item to "Tour booked", and then `advance` posts whatever
    // the stage select was showing when the modal opened - dragging it straight
    // back. Measured: booking a tour on a family at "Identified" left them at
    // "Identified" with a tour in the diary, and the history read "Tour booked"
    // then "Identified" one second apart.
    //
    // Fixed here rather than by swapping the two writes, because the note the
    // person just typed belongs on the stage change, and an advance that no-ops
    // records nothing in the item's own history. RANK TEST, NEVER A PLAIN SET: a
    // family already at "Offered" has plainly toured, and this must not demote
    // them. The ordering comes from the server's WORK_STAGES.
    //
    // ONLY WHEN THE TOUR BOX IS ON SCREEN (22 Oct 2026). It is hidden for every
    // stage but Tour booked now, and a hidden box still holds the last tour's
    // date - an item sent back to Contacted after a no-show would otherwise be
    // dragged forward to Tour booked by a visit that has already been and gone.
    if (tourShown && tourAt && stageKeys.length
        && stageKeys.indexOf(stage) < stageKeys.indexOf('tour_booked')) {
      stage = 'tour_booked';
    }

    // THE NOTE GOES ON THE STAGE EVENT ONLY WHEN THE STAGE ACTUALLY MOVES.
    // `/api/work` has a branch that files a note as an event against the stage
    // an item is already at - it exists because the note used to have nowhere
    // else to go and was being thrown away. It has somewhere now: every note is
    // a contact. Sending one anyway would put a from==to pseudo-event in the
    // item's history for every note typed, which is noise in the one list that
    // is supposed to say what the ITEM did.
    var moves = current && stage !== current.stage;

    // THREE WRITES, because they are three different records.
    //
    //   update   the item's properties - next action, owner, tour, and for an
    //            enquiry the parent's details
    //   advance  the item's own history: a stage change, and the note that
    //            explains it when there is a move
    //   comms    the FAMILY's contact log, which outlives this item, through
    //            /api/comms - the one writer of `communications` since
    //            work_log_contact() was removed on 18 Sep 2026
    //
    // The tour rides along on `update`, which routes the date through
    // work_set_tour() server-side rather than writing the column - that function
    // is what moves the item into "Tour booked" and writes the history line, and
    // a second path to the date would be a second chance to skip both. These
    // keys are always SENT (even empty) because this modal does edit them; the
    // endpoint only writes what it is given, so the board's other callers cannot
    // blank them by omission.
    // What happened to the brothers and sisters, said once at the end.
    var famMsg = [];
    var upd = {
      action: 'update', id: id,
      contact_name: $f.find('[name=contact_name]').val(),
      contact_phone: $f.find('[name=contact_phone]').val(),
      contact_email: $f.find('[name=contact_email]').val(),
      next_action: nextWhat,
      next_action_at: nextWhen,
      // Empty means the person saving - /api/work's work_posted_next_for().
      next_action_for: forWho,
      owner_email: $f.find('[name=owner_email]').val()
    };
    // THE TOUR ONLY TRAVELS WHEN IT IS ON SCREEN. /api/work reads a present
    // `tour_at` as "set the tour to this", so a hidden box posted would re-save
    // or clear a tour nobody was looking at. Left out, it means "this form did
    // not edit the tour".
    if (tourShown) {
      upd.tour_at = tourAt;
      upd.siblings = ticked('siblings[]');
      upd.tour_invite = $('#work-invite').prop('checked') ? 1 : 0;
    }
    // Room and days, unless the offer in Kidsoft has them. An empty day list is
    // sent as '' because jQuery drops an empty array entirely, and absent means
    // "not edited" to /api/work.
    if (current && !current.room_days_locked) {
      var days = $('#work-days input:checked').map(function () { return +this.value; }).get();
      upd.room_id = $('#work-room').val() || '';
      upd.days = days.length ? days : '';
    }
    $.post('/api/work', upd).done(function (res) {
      if (!res.ok) { toast(res.error, 'danger'); return; }
      if (res.family && res.family.length) {
        famMsg.push((tourAt ? 'Tour also set for ' : 'Tour also cancelled for ') + res.family.join(', ') + '.');
      }
      $.each(res.family_skipped || {}, function (name, why) {
        toast(name + ' was not added: ' + why + '.', 'warning');
      });
      $.post('/api/work', { action: 'advance', id: id, stage: stage,
                            note: moves ? note : '',
                            also_lost: stage === 'lost' ? ticked('also_lost[]') : [] })
        .done(function (r2) {
          // "already at that stage" is not an error worth shouting about when the
          // point of the save was the next action or the note.
          if (!r2.ok && !/already at that stage/i.test(r2.error || '')) {
            toast(r2.error, 'danger'); return;
          }
          if (r2.family && r2.family.length) famMsg.push('Also marked Lost: ' + r2.family.join(', ') + '.');
          logContact(id, summary, body, famMsg);
        })
        .fail(function () { toast('Could not save that.', 'danger'); });
    }).fail(function () { toast('Could not save that.', 'danger'); });
  });

  /**
   * The contact, through /api/comms - the same endpoint and the same shape the
   * family modal and Support plans use.
   *
   * IT IS THE LAST WRITE AND ITS FAILURE IS SAID OUT LOUD. The properties and
   * the stage are re-typable in seconds; a paragraph about what a parent said on
   * the phone is not, and the old path made losing it possible by never telling
   * anybody it had not been stored. A push failure is a different thing again -
   * the note IS saved, only the Kidsoft copy did not travel - so that comes back
   * as `warning` and is toasted as one. See comment_push(): a failed push is not
   * a failed save.
   */
  function logContact(id, summary, body, famMsg) {
    var done = function () {
      if (famMsg && famMsg.length) toast(famMsg.join(' '));
      m().hide(); onSaved();
    };
    if (!summary) { toast('Updated.'); done(); return; }

    // "Note" is not a channel Kidsoft or `communications` knows. It is an
    // INTERNAL note: it belongs in the family's log so the next person sees it,
    // and it must not be copied onto a child's Kidsoft record, where
    // createComment has no update and no delete. The endpoint enforces the same
    // rule off `direction`, so this is the request saying what it means rather
    // than the only thing standing between a private remark and a permanent one.
    var chip = $('#work-form [name=channel]:checked').val() || 'note';
    var isNote = chip === 'note';

    $.post('/api/comms', {
      action: 'add', work_item_id: id,
      summary: summary, body: body,
      channel: isNote ? 'other' : chip,
      direction: isNote ? 'internal' : postedDirection(chip),
      local_only: isNote ? 1 : 0,
      // Shown on these brothers and sisters too; the Kidsoft copy stays here.
      also_waitlist: ticked('also_waitlist[]')
    })
      .done(function (r3) {
        if (!r3.ok) { toast('Saved the item, but the note was not logged: ' + r3.error, 'danger'); return; }
        if (r3.warning)     toast(r3.warning, 'warning');
        else if (r3.pushed) toast('Logged, and copied to Kidsoft.');
        else                toast('Logged.');
        done();
      })
      .fail(function () {
        // DELIBERATELY DOES NOT CLOSE. Everything else saved, but the words are
        // still only in the box - closing the modal here would delete them.
        toast('Saved the item, but the note did not send. Leave this open and try Save again.', 'danger');
      });
  }

  // Answering the tour is ONE CLICK and saves on its own, rather than being a
  // third thing the Save button does. The question only appears on an appointment
  // that has already passed, and the answer is the reason the modal was opened.
  // Delegated: the block is re-rendered on every open.
  $('#work-tour-ask').on('click', '[data-tour]', function () {
    if (!current) return;
    var outcome = $(this).data('tour');
    $.post('/api/work', { action: 'tour_outcome', id: current.id, outcome: outcome })
      .done(function (res) {
        if (!res.ok) { toast(res.error, 'danger'); return; }
        m().hide();
        // Recorded for everybody on that visit - work_tour_outcome_visit().
        var with_ = res.family && res.family.length ? ', with ' + res.family.join(', ') : '';
        toast((outcome === 'toured' ? 'Marked as toured' : 'Marked as a no-show') + with_ + '.');
        onSaved();
      })
      .fail(function () { toast('Could not record that.', 'danger'); });
  });

  // Asked in a dialog over this one - confirmModal() in app.js. Cancel does
  // nothing; an empty reason is allowed. Pre-filled with the Waitlist's own flag
  // when there is one, since that is the usual reason - see waitlist_duplicates().
  $('#work-archive').on('click', function () {
    if (!current) return;
    confirmModal({
      title: 'Archive ' + current.child_name + '?', ok: 'Archive',
      body: 'It leaves In progress and is not counted as Lost or Enrolled. Its history is kept.',
      input: { label: 'Reason (optional)', maxlength: 500,
               value: current.duplicate ? current.duplicate.label : '' }
    }).done(function (reason) {
      $.post('/api/work', { action: 'archive', id: current.id, note: reason })
        .done(function (res) {
          if (!res.ok) { toast(res.error, 'danger'); return; }
          m().hide();
          toast(current.child_name + ' archived.');
          onSaved();
        })
        .fail(function () { toast('Could not archive that.', 'danger'); });
    });
  });

  $('#work-cancel').on('click', function () {
    if (!current) return;
    $.post('/api/work', { action: 'cancel', id: current.id })
      .done(function (res) {
        if (!res.ok) { toast(res.error, 'danger'); return; }
        m().hide();
        toast('Removed. It was never worked, so nothing is recorded against the family.');
        onSaved();
      })
      .fail(function () { toast('Could not remove that.', 'danger'); });
  });
})(jQuery);
