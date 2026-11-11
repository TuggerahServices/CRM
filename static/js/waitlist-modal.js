/**
 * The family modal - SHARED by the Waitlist queue and the Placement board.
 *
 * One dialog, two tabs: the application Kidsoft holds, and every contact this
 * team has had with the family. It lived inside waitlist.js, which meant a family
 * on the board's "Could take this day" shortlist could be rung, offered a place
 * and started through the pipeline WITHOUT anybody being able to see what the
 * last person had already told them - and that shortlist is exactly where the
 * question gets asked. Same fix as work-modal.js, whose modal could once only be
 * opened from a cell on the board.
 *
 * Markup: _incl/waitlist-modal.php. Both pages include both, so there is one
 * definition of each - the lesson the stat tile (six copies) and the mark bar
 * (three) each taught the hard way.
 *
 * Public surface:
 *   window.openWaitlistFamily(id, onChanged)          an application that exists
 *
 * onChanged() runs after a successful save or delete, never after a logged
 * contact: a note changes nothing the host page is showing, and reloading a
 * board behind an open modal for it would be work nobody asked for.
 */
(function ($) {
  'use strict';

  var modal = null;
  var onChanged = function () {};

  // WHOSE HISTORY, AND HAS IT BEEN FETCHED FOR THEM YET. Both are reset by
  // open(), and each is a way the modal could otherwise show the PREVIOUS
  // family: it is one element reused by every row on two screens, so the tab
  // last clicked stays clicked and a "loaded" flag left set means the next
  // family's log never loads at all.
  var commsFor = null;
  var commsLoaded = false;

  // THE FAMILY AND THEIR IN PROGRESS ITEM, as the last fetch found them. The
  // tour lives on the item, not on the application, so booking one has to know
  // whether there is an item to move the appointment on and what it already
  // says - see work_for_waitlist(). Reset by every open(), like the two above:
  // one dialog is reused by every row on two screens, and a stale item here
  // would book the previous family's tour.
  var current = { app: null, work: null, defaultYear: null, family: [] };

  function m() {
    modal = modal || new bootstrap.Modal(document.getElementById('wl-modal'));
    return modal;
  }

  // --- Opening ----------------------------------------------------------------

  window.openWaitlistFamily = function (id, done) {
    onChanged = done || function () {};
    $.getJSON('/api/waitlist', { action: 'get', id: id })
      .done(function (res) {
        if (!res.ok) { toast(res.error, 'danger'); return; }
        fill(res.application, res);
        open(res.application);
      })
      .fail(function () { toast('Could not open that application.', 'danger'); });
  };

  function roomOptions(rooms, selected) {
    return '<option value="">No preference</option>' + (rooms || []).map(function (r) {
      return '<option value="' + r.id + '"' + (r.id == selected ? ' selected' : '') + '>' +
             esc(r.name) + '</option>';
    }).join('');
  }

  // tour_booked_at IS NOT HERE ANY MORE (10 Sep 2026). The tour is the work
  // item's - see renderTour() below and work_for_waitlist() for what keeping a
  // second copy of it on the application cost.
  var FIELDS = ['id', 'child_first_name', 'child_last_name', 'child_dob', 'guardian_name',
                'guardian_email', 'guardian_phone', 'lead_source', 'date_added', 'required_from',
                'for_year', 'priority_of_access', 'orientation_at', 'notes'];

  /**
   * The other centres this child is waiting at, under "What they want".
   *
   * THE SAME CHIP THE QUEUE COLUMN AND THE GROUP REGISTER DRAW (a.wl-cchip),
   * fed by the same waitlist_elsewhere() - one grouping rule, so the three
   * places that answer "is this one family" cannot answer it differently. The
   * links leave the modal for that centre's own queue, which is where anything
   * can be DONE about the other application: an offer needs a room, and a room
   * belongs to one centre.
   *
   * Hidden entirely when there is nowhere else. An empty "Also waiting at" on
   * nine families in ten is a label saying nothing.
   */
  function renderElsewhere(w, others) {
    var $box = $('#wl-elsewhere');
    others = others || [];
    if (!w || !others.length) { $box.addClass('d-none'); $('#wl-elsewhere-list').empty(); return; }

    // WITH the queue-position pill, unlike the queue's own column: a dialog has
    // room for it, and their place in the other queue is most of why anybody
    // opened this. Same chip builder either way - window.waitlistCentreChip in
    // app.js - so the title cannot say two different things on two screens.
    var name = w.child_first_name + ' ' + w.child_last_name;
    var differs = window.waitlistDaysDiffer(others, w.days);
    var chips = others.map(function (o) {
      return window.waitlistCentreChip(name, o, w.days, { pill: true });
    }).join('');

    $box.removeClass('d-none');
    // A note ONLY when the days differ. "They are number 12 at Wamberal" is what
    // the chip already says, and a sentence explaining that a place here frees
    // their other queues is this team's own job read back to them.
    // ANOTHER CENTRE IS ALREADY WORKING THEM (22 Oct 2026), said in words here
    // because the dialog has the room the queue column does not. See
    // waitlist_elsewhere().
    var working = others.filter(function (o) { return o.work; }).map(function (o) {
      return '<div class="small text-warning-emphasis mt-1">' +
        '<i class="fa-solid fa-list-check fa-xs me-1"></i>In progress at ' + esc(o.centre) +
        (o.work.stage_label ? ': ' + esc(o.work.stage_label) : '') + '</div>';
    }).join('');
    $('#wl-elsewhere-list').html(chips + working + (differs
      ? '<div class="form-text">The days differ between their applications. ' +
        'Offer against the days on this one.</div>'
      : ''));
  }

  /**
   * Brothers and sisters also waiting, under "What they want". Links rather than
   * buttons: this dialog is opened from Placement and search too, where there is
   * no queue row to open instead. See window.waitlistSiblingLine in app.js.
   */
  function renderSiblingsWaiting(w, sibs) {
    sibs = sibs || [];
    if (!w || !sibs.length) { $('#wl-sibs-waiting').addClass('d-none'); $('#wl-sibs-waiting-list').empty(); return; }
    $('#wl-sibs-waiting').removeClass('d-none');
    $('#wl-sibs-waiting-label').text(sibs.length > 1 ? 'Siblings also waiting' : 'Sibling also waiting');
    $('#wl-sibs-waiting-list').html(sibs.map(function (s) {
      return window.waitlistSiblingLine(s, { open: false });
    }).join(''));
  }

  /**
   * Their PAST APPLICATIONS, under "What they want" (11 Nov 2026): the lines
   * search prints, in full - outcome, applied date, Archived - from the same
   * window.pastApplicationLines() and the same waitlist_history() the queue
   * column reads. Hidden when there are none, like the two boxes above.
   */
  function renderHistory(w, h) {
    var html = w && h ? window.pastApplicationLines(h.lines, h.total) : '';
    $('#wl-history').toggleClass('d-none', !html);
    $('#wl-history-list').html(html);
  }

  /**
   * Paint the days this child ALREADY HOLDS at this centre onto the day toggles.
   *
   * DRAWN, NOT NAMED (17 Aug 2026, Broc's call). This was a sentence - "Already
   * attends here on Mon, Tue, Wed, Fri" - sitting under a row of toggles that
   * showed a different set, which is the same two-descriptions-of-one-family
   * problem the queue's strip had, moved into the dialog. The toggles already say
   * which days were asked for, so the attendance goes ON them and the four states
   * read exactly as they do on the queue:
   *
   *   checked + .wl-att    green fill  asked for, already theirs
   *   checked              purple      asked for, still to find
   *   .wl-att              green ring  theirs, not on this application
   *   neither              plain       neither
   *
   * Cleared on every open, because it is one dialog reused by every row on two
   * screens - a class left behind would show the previous family's week.
   */
  function renderAttending(w) {
    var DAY = { 1: 'Mon', 2: 'Tue', 3: 'Wed', 4: 'Thu', 5: 'Fri' };
    var have = (w && w.attending_days) || [];
    $('#wl-form [id^=wdl-]').removeClass('wl-att').removeAttr('title');
    have.forEach(function (d) {
      var asked = (w.days || []).indexOf(d) !== -1;
      $('#wdl-' + d).addClass('wl-att').attr('title', asked
        ? 'Asked for, and already attends on ' + DAY[d]
        : 'Already attends on ' + DAY[d] + '. Not asked for on this application.');
    });
  }

  /**
   * The offer KIDSOFT holds, under the days asked for (15 Oct 2026). They are not
   * always the same days - Stella Knox asked for Tue/Fri and was offered Tue/Wed
   * - and with only the asked-for ones in this dialog it read as this app and
   * Kidsoft disagreeing. Read-only: offers are made in Kidsoft.
   */
  function renderKidsoftOffer(w) {
    var DAY = { 1: 'Mon', 2: 'Tue', 3: 'Wed', 4: 'Thu', 5: 'Fri' };
    var k = w && w.kidsoft_offer;
    $('#wl-ks-offer').toggleClass('d-none', !k).html(k
      ? '<span class="fw-semibold">' + esc(k.label) + ':</span> ' +
        (k.room_name ? esc(k.room_name) + ' ' + k.year + ', ' : '') +
        k.days.map(function (d) { return DAY[d]; }).join(', ') + '.' +
        (k.expires ? ' Expires ' + esc(k.expires) + '.' : '')
      : '');
  }

  /**
   * The tour, which belongs to the IN PROGRESS item and not to this application.
   *
   * Broc, 10 Sep 2026: "could the In progress tour booked align with the
   * waitlist when you click on their name". It could not, because they were two
   * fields: a date on waitlist_applications with no time, and the real
   * appointment on the work item. Jack and Sersha Donnelly were booked into one
   * 09:30 tour on 19/09/2026 and only Sersha's application carried a date. One
   * appointment, one field, and it is this one.
   *
   * Three states, and the form-text says which: no item yet (booking a tour
   * starts one), an item with no tour, and an item whose tour has been and gone
   * unanswered - which gets window.tourAskHtml(), the same question in the same
   * words the work modal asks it in.
   */
  function renderTour(work) {
    $('#wl-form [name=tour_at]').val(toLocalInput(work && work.tour_at));

    var note;
    if (!work) {
      // ONE LINE. "...at Tour booked" was true and wrapped to two, pushing the
      // button below it down; which stage each door lands on is on the button's
      // own title=, where somebody choosing between them will find it.
      note = 'Booking a tour starts them in In progress.';
    } else {
      note = 'In progress: ' + esc(work.stage_label) + '.';
      if (work.tour_outcome === 'toured')  note += ' They came.';
      if (work.tour_outcome === 'no_show') note += ' Did not show.';
    }
    $('#wl-tour-note').html(note);
    // Whether the tour reached the centre's Outlook calendar - see app.js.
    $('#wl-tour-cal').html(tourCalendarNote(work && work.calendar));
    // "Send the invite to the parent" - window.tourInviteBox(). To this
    // application's guardian, whose email the Save writes before the tour.
    $('#wl-tour-invite').html(tourInviteBox('wl-invite', (current.app ? current.app.guardian_email
      : $('#wl-form [name=guardian_email]').val()) || '', work && work.tour_invite));

    var ask = tourAskHtml(work);
    $('#wl-tour-ask').html(ask).toggleClass('d-none', !ask);

    // BROTHERS AND SISTERS WAITING HERE go onto the same tour (19 Oct 2026) -
    // window.familyTourTicks(), the work modal's own question.
    var fam = familyTourTicks(current.family, work ? work.tour_at : null, 'tour_siblings[]');
    $('#wl-tour-family').html(fam).toggleClass('d-none', !fam);

    // THE CALL THAT WAS NOT ANSWERED. Offered only while there is no item to
    // start - once there is one the note above names its stage, and a button
    // that would hand back the same item is a button that does nothing. Hidden
    // on the Add form too: there is no application for an item to point at.
    $('#wl-progress-add').toggleClass('d-none', !!work || !current.app);
  }

  /**
   * The payload that puts this family into the pipeline - ONE definition, used
   * by both ways in: "Add to In progress" (no tour, stage Identified) and the
   * tour box (which `work_create()` promotes to Tour booked on its own).
   *
   * The same shape the queue row's own button posts, and NO ROOM for the same
   * reason: a family in the queue has not been matched to one, and guessing
   * their preferred room would file the item against a spot nobody has decided
   * on. It is filled in when a place is offered.
   *
   * @param waitlistId the application's id - passed rather than read off
   *                   current.app, because on the Add form the row was created a
   *                   moment ago and current.app is still null
   */
  function progressPayload(waitlistId) {
    var $f = $('#wl-form');
    return {
      action: 'create',
      centre_id: $f.find('[name=centre_id]').val(),
      year: $f.find('[name=for_year]').val() || current.defaultYear,
      waitlist_id: waitlistId,
      // .val() reads a disabled input; .serialize() would not, and on a row
      // imported from Kidsoft the name boxes are disabled.
      child_name: $.trim($f.find('[name=child_first_name]').val() + ' ' +
                         $f.find('[name=child_last_name]').val()),
      days: $f.find('[name="days[]"]:checked').map(function () { return +this.value; }).get()
    };
  }

  /** Re-read the item after a write, so the note names the stage the server holds. */
  function refreshWork() {
    if (!current.app) return;
    $.getJSON('/api/waitlist', { action: 'get', id: current.app.id }).done(function (r) {
      if (!r.ok) return;
      current.work = r.work || null;
      current.family = r.family || [];
      renderTour(current.work);
    });
  }

  /**
   * Write the tour, if it changed, AFTER the application has saved.
   *
   * A SECOND WRITE TO A DIFFERENT ENDPOINT, and it has to be: the tour is the
   * work item's, and /api/work is the only thing that may move one - work_set_tour()
   * is what advances the item to "Tour booked" and writes the history line, so a
   * bare column update here would put a family in the diary and off the pipeline
   * (the trap work-modal.js already documents).
   *
   * With no item yet, booking a tour CREATES one - progressPayload() plus the
   * date, which `work_create()` files at Tour booked rather than Identified.
   *
   * @param waitlistId the application's id - from the save, because on the Add
   *                   form there was not one until a moment ago
   * @return a promise; rejected with a message the caller can show
   */
  function saveTour(waitlistId) {
    var d = $.Deferred();
    var $f  = $('#wl-form');
    var val = $f.find('[name=tour_at]').val() || '';
    // The brothers and sisters left ticked, and whether any of them is not on
    // this tour yet - which is a reason to write even when this child's date
    // did not change (ticking one onto a tour already booked).
    var sibs = $f.find('input[name="tour_siblings[]"]:checked').map(function () { return +this.value; }).get();
    var behind = (current.family || []).some(function (s) {
      if (sibs.indexOf(s.waitlist_id) === -1) return false;
      return val ? (!s.item || toLocalInput(s.item.tour_at) !== val) : !!(s.item && s.item.tour_at);
    });
    // "Send the invite to the parent", ticked or unticked since it opened, is a
    // reason to write on its own too.
    var invite = $('#wl-invite').prop('checked') ? 1 : 0;
    var inviteMoved = !!invite !== !!(current.work && current.work.tour_invite);
    // Compared in the input's own shape, so "unchanged" cannot hinge on the
    // seconds MySQL keeps and the input does not.
    if (val === toLocalInput(current.work && current.work.tour_at) && !behind && !inviteMoved) return d.resolve().promise();
    if (!current.work && !val) return d.resolve().promise();

    // Creating the item asks first for a Do Not Enrol family - see
    // postConfirmingDne() in app.js. Saying no leaves the application saved and
    // the tour unbooked, and the message says exactly that.
    var req = current.work
      ? $.post('/api/work', { action: 'tour', id: current.work.id, tour_at: val, siblings: sibs, tour_invite: invite })
      : postConfirmingDne('/api/work', $.extend(progressPayload(waitlistId),
                                                { tour_at: val, siblings: sibs, tour_invite: invite }));

    req.done(function (r) {
      if (r && r.cancelled) { d.reject('The application saved. The tour was not booked.'); return; }
      if (r && r.ok) d.resolve(r); else d.reject((r && r.error) || 'The tour did not save.');
    }).fail(function () { d.reject('The tour did not save.'); });
    return d.promise();
  }

  function fill(w, res) {
    var $f = $('#wl-form');
    $f[0].reset();
    $f.find('[name=preferred_room_id]').html(roomOptions(res.rooms, w ? w.preferred_room_id : null));
    // Reset on every open, or one family's chips carry into the next - one
    // element reused by every row on two screens.
    current = { app: w, work: res.work || null, defaultYear: res.default_year, family: res.family || [] };
    renderElsewhere(w, res.elsewhere);
    renderSiblingsWaiting(w, res.siblings_waiting);
    renderHistory(w, res.history);
    renderAttending(w);
    renderKidsoftOffer(w);
    renderTour(current.work);
    // "Also on the history of" on the contact form - one call, shown on each.
    var noteFam = familyNoteTicks(current.family);
    $('#comm-family').html(noteFam).toggleClass('d-none', !noteFam);
    $('#wl-dne').html(dneAlert(w && w.dne, false)).toggleClass('d-none', !(w && w.dne));
    $('#wl-dup').html(dupAlert(w && w.duplicate)).toggleClass('d-none', !(w && w.duplicate));

    FIELDS.forEach(function (k) {
      $f.find('[name=' + k + ']').val(w[k] === null ? '' : w[k]);
    });
    // The application's OWN centre, not the screen's. They are the same on the
    // Waitlist queue and they are the same on the board today, but the row is the
    // authority on which centre it belongs to and the host page is not.
    $f.find('[name=centre_id]').val(w.centre_id);
    $('#w-re').prop('checked', w.is_reenrolment);
    $('#w-staff').prop('checked', w.is_staff_child);
    $('#w-sib').prop('checked', w.is_sibling);
    $('#w-guide').prop('checked', w.guide_provided);
    w.days.forEach(function (d) { $('#wd-' + d).prop('checked', true); });
    // After the values are in, so the reset above cannot re-enable them.
    lockKidsoftFields(w);
    $('#wl-delete').removeClass('d-none');
    $('.modal-title', '#wl-modal').text(w.child_first_name + ' ' + w.child_last_name);
  }

  // Kidsoft owns what the family declared; we own the decisions. On an imported
  // row those inputs are disabled, so a disabled input is not posted and
  // api/waitlist's save re-reads the stored value - belt and braces, because a
  // disabled attribute is a hint to a browser, not a rule.
  function lockKidsoftFields(w) {
    var locked = !!w && w.source === 'kidsoft';
    var $f = $('#wl-form');
    $f.find('[data-kidsoft]').prop('disabled', locked);
    // Kidsoft owns the requested days only when it HAS some. One real Terrigal
    // application asks for no particular weekday; leaving those toggles locked
    // and empty would show a row that cannot be completed, and the API applies
    // the same exception.
    if (locked && !w.days.length) $('#wl-form [name="days[]"]').prop('disabled', false);
  }

  /** Show it, on the Application tab, with the previous family's log cleared. */
  function open(w) {
    commsFor = w ? w.id : null;
    commsLoaded = false;
    $('#comm-form')[0].reset();
    $('#comm-form [name=waitlist_id]').val(w ? w.id : '');
    $('#comm-list').empty();
    $('#comm-kidsoft').addClass('d-none');
    $('#comm-kidsoft-note').addClass('d-none');
    // There is nothing to hang a note on until the application exists, and an
    // empty log on a family nobody has saved yet reads as "nobody has rung them".
    $('#wl-tab-comms').toggleClass('disabled', !w).attr('aria-disabled', w ? 'false' : 'true');
    bootstrap.Tab.getOrCreateInstance($('#wl-tab-app')[0]).show();
    m().show();
  }

  // --- Saving -----------------------------------------------------------------

  // TWO WRITES, IN ORDER, AND THE APPLICATION GOES FIRST. The tour is the work
  // item's (see saveTour()), and on the Add form the item cannot name the family
  // until the application it points at exists. `tour_at` rides along in the
  // serialize() and is ignored by the save, which is the same treatment every
  // Kidsoft-owned field already gets there.
  //
  // A tour that did not save does NOT hide the dialog. The application saved,
  // the appointment did not, and closing on that would leave somebody sure they
  // had booked one - the same reason a comment that could not reach Kidsoft says
  // so on the row rather than failing silently.
  // The invite box names the address the Save is about to write.
  $('#wl-form').on('input', '[name=guardian_email]', function () {
    $('#wl-tour-invite').html(tourInviteBox('wl-invite', $(this).val(), $('#wl-invite').prop('checked')));
  });

  $('#wl-form').on('submit', function (e) {
    e.preventDefault();
    var $btn = $(this).find('[type=submit]').prop('disabled', true);
    $.post('/api/waitlist', $(this).serialize() + '&action=save')
      .done(function (res) {
        if (!res.ok) { toast(res.error, 'danger'); $btn.prop('disabled', false); return; }
        saveTour(res.id)
          .done(function (r) {
            m().hide();
            // What the brothers and sisters got, so nobody re-opens them to check.
            var val = $('#wl-form [name=tour_at]').val();
            toast('Saved.' + (r && r.family && r.family.length
              ? (val ? ' Tour also set for ' : ' Tour also cancelled for ') + r.family.join(', ') + '.' : ''));
            $.each((r && r.family_skipped) || {}, function (name, why) {
              toast(name + ' was not added: ' + why + '.', 'warning');
            });
            onChanged();
          })
          .fail(function (msg) {
            toast(msg, 'danger');
            onChanged();
          })
          .always(function () { $btn.prop('disabled', false); });
      })
      .fail(function () {
        toast('Could not save.', 'danger');
        $btn.prop('disabled', false);
      });
  });

  // START THEM WITHOUT A TOUR. Broc, 10 Sep 2026: you either get through and book
  // one, or you leave a voicemail and ring back until you do - and until this
  // button the second half had no door in this dialog, so the family nobody
  // reached was the family nothing remembered. They land at `Identified`
  // ("nobody has rung them yet"); logging the voicemail on the Contact history
  // tab leaves them there, correctly, because `Contacted` means somebody spoke
  // to them.
  //
  // Saves on its own click, like the tour answer below and for the same reason:
  // it writes nothing on this form, so making it wait for Save would tie
  // starting a family to an application edit nobody was making.
  $('#wl-add-progress').on('click', function () {
    if (!current.app) return;
    var $b = $(this).prop('disabled', true);
    // Asks first for a Do Not Enrol family - see postConfirmingDne() in app.js.
    postConfirmingDne('/api/work', progressPayload(current.app.id))
      .done(function (res) {
        if (res.cancelled) return;
        if (!res.ok) { toast(res.error, 'danger'); return; }
        toast($.trim(current.app.child_first_name + ' ' + current.app.child_last_name) +
              ' added to In progress.');
        onChanged();
        refreshWork();
      })
      .fail(function () { toast('Could not add that.', 'danger'); })
      .always(function () { $b.prop('disabled', false); });
  });

  // DID THEY COME? One click, saved on its own rather than waiting for Save:
  // it is not an edit to this form, and the item may move stage on it (a no-show
  // sends the family back to Contacted, which is not a demotion but a wasted
  // appointment). Re-read through the same endpoint the modal opened with, so
  // the stage the note prints is the stage the server now holds rather than one
  // guessed here.
  $('#wl-tour-ask').on('click', '[data-tour]', function () {
    if (!current.work) return;
    var $b = $(this).closest('#wl-tour-ask').find('button').prop('disabled', true);
    var outcome = $(this).data('tour');
    $.post('/api/work', { action: 'tour_outcome', id: current.work.id, outcome: outcome })
      .done(function (res) {
        if (!res.ok) { toast(res.error, 'danger'); $b.prop('disabled', false); return; }
        // Recorded for everybody on that visit - work_tour_outcome_visit().
        var with_ = res.family && res.family.length ? ', with ' + res.family.join(', ') : '';
        toast((outcome === 'toured' ? 'Recorded as toured' : 'Recorded as a no-show') + with_ + '.');
        onChanged();
        refreshWork();
      })
      .fail(function () { toast('Could not record that.', 'danger'); $b.prop('disabled', false); });
  });

  $('#wl-delete').on('click', function () {
    var id = $('#wl-form [name=id]').val();
    if (!id) return;
    confirmModal({
      title: 'Delete this application?', ok: 'Delete', danger: true,
      body: 'Its offers and history go too.'
    }).done(function () {
      $.post('/api/waitlist', { action: 'delete', id: id })
        .done(function (res) {
          if (!res.ok) { toast(res.error, 'danger'); return; }
          m().hide();
          toast('Deleted.');
          onChanged();
        });
    });
  });

  // --- Contact history --------------------------------------------------------

  /**
   * Did this note also reach Kidsoft? Three states, and they must not collapse
   * into two: copied, could not be copied, and nowhere to copy it to. The last
   * is the normal case on the waitlist and is said plainly rather than left
   * blank, because a staff member who assumes a note travelled will stop keeping
   * the Kidsoft note they still need.
   */

  /** Kidsoft's own comments, and whether anything we write can reach them. */
  function renderKidsoft(res) {
    var push = res.kidsoft_push || {};
    var $note = $('#comm-kidsoft-note');
    if (push.pushable) {
      $note.removeClass('d-none').html(
        '<i class="fa-solid fa-arrow-right-arrow-left text-muted me-1"></i>' +
        'Notes saved here are also copied into this family\'s Kidsoft ' +
        esc(push.target === 'child' ? 'child' : 'guardian') + ' record, marked confidential.' +
        // Shown on a brother or sister ticked below, never copied onto theirs.
        ((current.family || []).length ? ' Only for this child, not a brother or sister.' : ''));
    } else {
      // WITHHOLDING THIS WOULD BE THE BUG. Kidsoft has no record of this family
      // until an application converts to an enrolment, so there is nothing to
      // attach a note to - and that is worth one sentence, because it is the
      // difference between a note the team can find in Kidsoft later and one
      // they cannot.
      //
      // IT USED TO END "They are not copied into Kidsoft", WHICH IS NOW FALSE
      // and was the expensive half of the sentence: a staff member who reads
      // that keeps double-keying the note into Kidsoft, which is the work this
      // tool exists to remove. Kidsoft issues the ids when the application
      // converts, and the nightly sweep copies the family's notes across in the
      // order they were written. Nothing is lost by waiting.
      $note.removeClass('d-none').html(
        '<i class="fa-solid fa-circle-info text-muted me-1"></i>' +
        'Kidsoft holds no child or guardian record for this family yet, so notes ' +
        'stay here for now. They are copied across on their own if the family enrols.');
    }

    var ks = res.kidsoft || [];
    if (!ks.length) { $('#comm-kidsoft').addClass('d-none'); return; }
    // Markup and the clamp rule are window.kidsoftComments() in app.js since
    // 18 Sep 2026 - the work modal shows the same block, and one Kidsoft comment
    // must not be described two ways. The .ks-more toggle is bound there too.
    $('#comm-kidsoft').removeClass('d-none');
    $('#comm-kidsoft-list').html(kidsoftComments(ks));
  }

  function loadComms(waitlistId) {
    $('#comm-list').html('<div class="text-muted small">Loading...</div>');
    $.getJSON('/api/comms', { action: 'list', waitlist_id: waitlistId })
      .done(function (res) {
        if (!res.ok) { $('#comm-list').html('<div class="text-danger small">' + esc(res.error) + '</div>'); return; }
        if (!res.entries.length) {
          $('#comm-list').html('<div class="text-muted small">No contact logged yet.</div>');
          renderKidsoft(res);   // Kidsoft may still hold history for this family
          return;
        }
        $('#comm-list').html(res.entries.map(function (c) {
          var dirCls = c.direction === 'inbound' ? 'border-info'
                     : (c.direction === 'internal' ? 'border-secondary' : 'border-primary');
          // A brother or sister's call shown here too, or this one shown on
          // theirs - window.sharedNote(). Its Kidsoft copy is the other child's.
          var shared = sharedNote(c);
          return '<div class="comm-entry ' + dirCls + '">' +
            '<div class="d-flex justify-content-between align-items-start gap-2">' +
              '<div class="fw-semibold"><i class="' + channelIcon(c.channel) +
              ' me-2 text-muted"></i>' + esc(c.summary) + '</div>' +
              '<div class="small text-muted text-nowrap">' + fmtDateTime(c.occurred_at) + '</div>' +
            '</div>' +
            (c.body ? '<div class="small mt-1">' + esc(c.body).replace(/\n/g, '<br>') + '</div>' : '') +
            '<div class="small text-muted mt-1">' + esc(res.directions[c.direction] || c.direction) +
            ' &middot; ' + esc(c.staff_email) + shared.meta + (shared.copy ? copyTag(c) : '') +
            commDeleteBtn(c) + '</div>' +
          '</div>';
        }).join(''));
        renderKidsoft(res);
      })
      .fail(function () { $('#comm-list').html('<div class="text-danger small">Could not load history.</div>'); });
  }

  // FETCHED ON THE FIRST LOOK, NOT ON EVERY OPEN. api/comms's list reads this
  // family's own Kidsoft comments, which is a live API call, and the editor is
  // opened far more often than the history is read - so loading it with the
  // modal would put a Kidsoft round trip behind every click on a name. Once per
  // opening is enough; the flag is cleared in open().
  $('#wl-tab-comms').on('shown.bs.tab', function () {
    if (commsLoaded || !commsFor) return;
    commsLoaded = true;
    loadComms(commsFor);
  });

  // An administrator deleted a note - commDeleteBtn() in app.js. Reloaded rather
  // than the line removed, because a note that travelled now shows in the
  // Kidsoft block instead.
  $('#comm-list').on('comm:deleted', function () {
    if (commsFor) loadComms(commsFor);
  });

  $('#comm-form').on('submit', function (e) {
    e.preventDefault();
    var id = $('#comm-form [name=waitlist_id]').val();
    var $btn = $(this).find('[type=submit]').prop('disabled', true);
    $.post('/api/comms', $(this).serialize() + '&action=add')
      .done(function (res) {
        if (!res.ok) { toast(res.error, 'danger'); return; }
        // A push failure is a WARNING on a successful save, never an error --
        // the note is stored either way, and saying "failed" would have them
        // type it again.
        if (res.warning) toast(res.warning, 'warning');
        else if (res.pushed) toast('Logged, and copied to Kidsoft.', 'success');
        $('#comm-form [name=summary]').val('');
        $('#comm-form [name=body]').val('');
        loadComms(id);
      })
      .fail(function () { toast('Could not log the contact.', 'danger'); })
      .always(function () { $btn.prop('disabled', false); });
  });
})(jQuery);
