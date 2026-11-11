/* USER ACCESS - the modal behind the user menu. (19 Aug 2026)
 *
 * Loaded on every page, but ONLY for an administrator: layout_footer() renders
 * neither this script nor _incl/users-modal.php for anybody else. The endpoint
 * checks the role for itself, so that is tidiness rather than the control.
 *
 * THE SERVER'S ANSWER IS THE STATE. Every write returns the whole list back and
 * this re-renders from it, rather than patching the row it just changed. The
 * list is a dozen rows read once in a while - there is nothing to optimise --
 * and the rules that decide what a row may do (the last administrator, your own
 * row, a break-glass account) live in api/users.php. Patching locally would mean
 * a second copy of those rules here, disagreeing the first time one changed.
 *
 * Endpoints answer readable failures as HTTP 200 + ok:false, so every .done()
 * checks res.ok - see _incl/api.php.
 */
(function () {
  'use strict';

  var $modal = $('#users-modal');
  if (!$modal.length) return;

  var CENTRES = [];    // [{id, name}] - every centre, for the tick boxes
  var ROLES   = {};    // value => label
  var HINTS   = {};    // value => one-line description
  var ME      = '';    // the signed-in administrator's email
  var DOMAIN  = '';    // the one domain an address may be at; fixed beside the field
  var DOMAIN_FAILED = false;   // ...and whether asking for it failed, see lookupNow()
  // THE LAST LIST THE SERVER SENT, kept so a REFUSED write can be undone on
  // screen. A role menu and a centre tick box both save the instant they are
  // touched, so when the server says no - the last administrator, your own
  // row, a supervisor left with no centre - the control was already showing
  // the value that was rejected, and the toast slid away leaving a screen that
  // disagreed with the database until somebody reloaded. Re-rendering from this
  // puts every control back to what is actually stored.
  var USERS   = [];

  // --- Rendering -------------------------------------------------------------

  /**
   * Centre tick boxes. `name` keeps the add form's and each row's sets apart --
   * two <input> groups sharing a name would post as one.
   */
  function centreBoxes(name, chosen) {
    chosen = chosen || [];
    return CENTRES.map(function (c) {
      var id = 'cb-' + name + '-' + c.id;
      return '<div class="form-check">' +
        '<input class="form-check-input" type="checkbox" value="' + c.id + '" id="' + esc(id) + '" ' +
        'data-centre="' + c.id + '"' + (chosen.indexOf(c.id) !== -1 ? ' checked' : '') + '>' +
        '<label class="form-check-label" for="' + esc(id) + '">' + esc(c.name) + '</label></div>';
    }).join('');
  }

  function roleOptions(current) {
    return Object.keys(ROLES).map(function (v) {
      return '<option value="' + esc(v) + '"' + (v === current ? ' selected' : '') + '>' +
             esc(ROLES[v]) + '</option>';
    }).join('');
  }

  /**
   * What a user is scoped to, in one line.
   *
   * A supervisor's centres are NAMED. "2 centres" is the version that sends
   * somebody back to open the row and find out which two, which is the whole
   * job this line exists to save.
   */
  function scopeLine(u) {
    if (u.role !== 'supervisor') return 'All centres';
    if (!u.centres.length) return 'No centre assigned';
    return u.centres.map(function (c) { return c.name; }).join(', ');
  }

  function userRow(u) {
    var isMe   = u.email === ME;
    var locked = !!u.break_glass;
    var off    = !u.active;

    // NEVER, not a blank. A row that has never been used is either somebody who
    // has not started yet or an email typed wrong, and both are worth seeing --
    // a typo is a row that stays "Never" forever, which is the one signal this
    // screen gives that the address was mistyped.
    var seen = u.last_seen_at ? fmtDateTime(u.last_seen_at) : 'Never signed in';

    var badges = '';
    if (isMe)   badges += ' <span class="badge text-bg-light border">You</span>';
    if (locked) badges += ' <span class="badge text-bg-light border"' +
                          ' title="Defined by the system.">System</span>';
    if (off)    badges += ' <span class="badge text-bg-secondary">Access removed</span>';

    var h = '<div class="ua-row border rounded p-3 mb-2' + (off ? ' opacity-75' : '') +
            '" data-id="' + u.id + '" data-email="' + esc(u.email) + '">' +
      '<div class="d-flex flex-wrap align-items-start gap-2">' +
        '<div class="flex-grow-1 min-w-0">' +
          '<div class="fw-semibold text-truncate">' + esc(u.name || u.email) + badges + '</div>' +
          (u.name ? '<div class="small text-muted text-truncate">' + esc(u.email) + '</div>' : '') +
          '<div class="small text-muted">' + esc(scopeLine(u)) + ' &middot; ' + esc(seen) + '</div>' +
        '</div>';

    if (locked) {
      // No controls at all. The endpoint refuses to edit this row, so offering
      // a menu that always fails would be a control that lies.
      h += '<div class="text-muted small align-self-center">Administrator</div>';
    } else {
      h += '<div class="d-flex gap-2 align-items-center">' +
        '<select class="form-select form-select-sm ua-role" style="width:auto"' +
          (isMe ? ' disabled title="Ask another administrator to change your own role."' : '') + '>' +
          roleOptions(u.role) + '</select>' +
        (off
          ? '<button type="button" class="btn btn-sm btn-outline-primary ua-restore">Restore</button>'
          : '<button type="button" class="btn btn-sm btn-outline-danger ua-remove"' +
            (isMe ? ' disabled title="You cannot remove your own access."' : '') + '>Remove</button>') +
      '</div>';
    }

    h += '</div>';

    // The centre boxes hang under the row and only for a supervisor, for the
    // same reason as in the add form: the other two roles have nothing to pick.
    if (!locked) {
      h += '<div class="mt-3 ua-centres-wrap' + (u.role === 'supervisor' ? '' : ' d-none') + '">' +
        '<label class="form-label small d-block mb-1">Centres</label>' +
        '<div class="d-flex flex-wrap gap-3 ua-centres">' +
          centreBoxes('u' + u.id, u.centres.map(function (c) { return c.id; })) +
        '</div></div>';
    }

    return h + '</div>';
  }

  function render(users) {
    USERS = users || [];
    if (!users.length) {
      $('#users-list').html(emptyState('fa-user-shield', 'Nobody has access yet',
        'Add somebody above.'));
      return;
    }
    $('#users-list').html(users.map(userRow).join(''));
  }

  // --- Loading ---------------------------------------------------------------

  function load() {
    DOMAIN_FAILED = false;
    $('#users-list').html(spinner('Loading people...'));
    $.getJSON('/api/users', { action: 'list' })
      .done(function (res) {
        if (!res.ok) { toast(res.error, 'danger'); return; }
        CENTRES = res.centres || [];
        ROLES   = res.roles || {};
        HINTS   = res.hints || {};
        ME      = res.me || '';
        DOMAIN  = res.domain || '';
        $('#ua-centres').html(centreBoxes('add', []));
        syncAddForm();
        render(res.users || []);
        // THE DOMAIN ARRIVES WITH THIS LIST, and the field's address is built
        // from it - so a name typed before it landed had nothing to join to and
        // was held (see lookupNow()). Ask now that there is something to ask.
        if (typedLocal() !== '') lookupNow();
      })
      .fail(function () {
        toast('Could not load the list of users.', 'danger');
        // ANYTHING HELD IS NOW WAITING ON A DOMAIN THAT IS NEVER COMING. The
        // flag is what lookupNow() reads, because this usually fails BEFORE
        // anybody has typed anything - saying it here only would leave the
        // next thing they type holding on "Checking..." for good.
        DOMAIN_FAILED = true;
        if (typedLocal() !== '') lookupNow();
      });
  }

  /**
   * Re-render after a write, from the list the write answered with.
   *
   * ON A REFUSAL IT RE-RENDERS TOO, from the last known list - see USERS. The
   * refusals here all arrive AFTER the control has moved, so leaving the screen
   * alone would leave it lying about what is stored.
   */
  function applied(res) {
    if (!res.ok) { toast(res.error, 'danger'); render(USERS); return false; }
    render(res.users || []);
    if (res.message) toast(res.message);
    return true;
  }

  // --- The add form ----------------------------------------------------------

  /**
   * Swap between the list and the add form. One at a time, never both.
   *
   * The form is the rarer of the two acts by a long way, so it is not what the
   * dialog opens on - and while it IS open the list goes, because a row of
   * role menus sitting under a role menu that means something else is how
   * somebody changes the wrong person's access.
   *
   * OPENING ALWAYS STARTS FROM A BLANK FORM. Cancelling and re-opening must not
   * hand back a half-typed address from a minute ago; the one thing worse than
   * retyping an email here is adding the wrong one. The directory answer and the
   * Add button reset with it, or the button would still be lit for the person
   * whose address was just cleared.
   */
  function showAdd(on) {
    if (on) {
      $('#ua-email').val('');
      $('#ua-role').val('supervisor');
      $('#ua-centres input:checked').prop('checked', false);
      resetLookup();
      syncAddForm();
    }
    $('#ua-list-pane').toggleClass('d-none', on);
    $('#ua-add-pane').toggleClass('d-none', !on);
    $('#ua-subtitle').text(on
      ? 'Adding somebody to the CRM.'
      : 'Who can sign in to the CRM, and what they can see.');
    if (on) $('#ua-email').trigger('focus');
  }

  // --- Is this a real person? ------------------------------------------------
  //
  // THE FIELD ANSWERS BEFORE THE FORM IS SUBMITTED. An administrator types an
  // address and nothing else, and until this existed the only thing that could
  // tell them they had typed it wrong was a row in the list reading "Never
  // signed in" - weeks later, after a real staff member had hit the denial page
  // wondering what they had done. Now Entra is asked as they type and the answer
  // sits under the field, naming the person.
  //
  // IT IS NOT THE CONTROL. api/users.php runs the very same directory_check()
  // before it writes anything, and a disabled button is one line of console away
  // from being enabled. This exists so nobody fills in a form that was always
  // going to be refused, which is the same reason the modal is not rendered for
  // a non-administrator while auth_require_admin_api() does the actual refusing.

  var lookupTimer = null;   // debounce
  var lookupSeq   = 0;      // answers can arrive out of order; only the last counts
  var lookupFor   = null;   // the address the current answer belongs to

  /** Back to "nothing typed yet": no answer, no Add. */
  function resetLookup() {
    lookupSeq++;
    lookupFor = null;
    clearTimeout(lookupTimer);
    $('#ua-found').empty().removeClass('text-danger text-success');
    $('#ua-submit').prop('disabled', true);
  }

  function lookupSay(html, cls) {
    $('#ua-found').removeClass('text-danger text-success').addClass(cls || '').html(html);
  }

  /**
   * What the answer means for the Add button.
   *
   * 'unreachable' ENABLES IT. Graph being down says nothing about the address,
   * and refusing to grant access because our own outbound call failed would
   * leave an administrator unable to add the person who needs the app today --
   * the tool getting in the way of the one job this screen has. The row goes in
   * nameless and the name arrives when they first sign in.
   */
  function lookupApply(res) {
    if (res.status === 'ok') {
      var name = esc(res.name || res.email);
      var a    = res.already;

      // The NAME only, not the job title: this is a sentence, and a job title
      // dropped into the middle of one reads as a run-on rather than as a
      // description of the person.
      if (a && a.locked) {
        lookupSay(name + ' is a permanent administrator and cannot be changed here.', 'text-danger');
        $('#ua-submit').prop('disabled', true);
        return;
      }

      // The job title is what separates two people with the same name, so it is
      // worth the room - muted, and after a separator, because without one the
      // two run together into something that reads like a longer name.
      var who = name;
      if (res.job_title) who += ' <span class="text-muted">&middot; ' + esc(res.job_title) + '</span>';

      // ALREADY ON THE LIST IS NOT A REFUSAL - re-adding is how a role is
      // changed and how somebody who left is let back in - but it is the thing
      // worth knowing before pressing Add, and it is why the button can look as
      // though it did nothing.
      if (a) {
        who += '<br>Already on the list as ' + esc(ROLES[a.role] ? ROLES[a.role].toLowerCase() : a.role) +
               (a.active ? '.' : ', with access removed.');
      }
      lookupSay(who, 'text-success');
      $('#ua-submit').prop('disabled', false);
      return;
    }
    if (res.status === 'unreachable') {
      lookupSay(esc(res.message));
      $('#ua-submit').prop('disabled', false);
      return;
    }
    // A REFUSAL THAT NAMES THE WAY FORWARD. "There is no account for
    // cath@littlemiracles.com.au" is true and leaves an administrator with
    // nowhere to go but the Microsoft 365 admin portal - which is exactly what
    // happened: Cath said "cath@", it is "Cathryn.Montgomery@", and finding that
    // out meant signing in to another system. Entra knows who she nearly is, so
    // the answer carries up to three of them.
    //
    // BUTTONS, NOT TEXT, because the whole failure being fixed here is an
    // address getting mistyped: reading one back off the screen and typing it
    // again is how "Cathryn.Montgomery" becomes "Cathryn.Montgomerey".
    lookupSay(esc(res.message) + suggestHtml(res.suggestions), 'text-danger');
    $('#ua-submit').prop('disabled', true);
  }

  /** The "did you mean" list, or nothing at all. */
  function suggestHtml(list) {
    if (!list || !list.length) return '';
    var html = '<div class="mt-2 text-body">Did you mean:</div>';
    $.each(list, function (i, s) {
      // THE ADDRESS GOES ON ITS OWN LINE, under the name. Beside it, the name,
      // the address and the job title ran to three lines anyway and broke in
      // the middle of the address - which is the one string here that has to be
      // read as a whole.
      // type="button" or it submits the form it is sitting in.
      html += '<div class="mt-1"><button type="button" class="btn btn-link btn-sm p-0 ua-suggest"' +
              ' data-email="' + esc(s.upn) + '">' + esc(s.name || s.upn) + '</button>' +
              '<div class="small text-muted">' + esc(s.upn) +
              (s.job_title ? ' &middot; ' + esc(s.job_title) : '') + '</div></div>';
    });
    return html;
  }

  // Take the suggestion: put it in the field and ask about it, rather than
  // filling the field and leaving the green line underneath still reading as a
  // refusal about the address that is no longer there.
  $modal.on('click', '.ua-suggest', function () {
    var $in = $('#ua-email');
    var email = String($(this).data('email') || '');
    $in.val(email.split('@')[0]).trigger('focus');
    clearTimeout(lookupTimer);
    lookup(email);
  });

  /** The name typed in the add form, if there is enough of one to ask about. */
  function typedLocal() {
    var v = localPart($('#ua-email'));
    return v.length >= 2 ? v : '';
  }

  /**
   * Ask about whatever is in the field NOW - built when the timer fires, not
   * when it was set.
   *
   * NO DOMAIN YET IS NOT A BAD ADDRESS. addressFor() answers '' until the list
   * has landed and published the domain, and asking with that got back "That
   * does not look like an email address" - a refusal about our own load order,
   * shown to somebody who had typed a perfectly good name. So the question is
   * HELD instead: the field says it is checking, and load() asks it for real
   * the moment the domain arrives.
   */
  function lookupNow() {
    var email = addressFor($('#ua-email'));
    if (email === '') {
      lookupSay(DOMAIN_FAILED
        ? 'Could not check that address just now.'
        : 'Checking the staff directory...');
      return;
    }
    lookup(email);
  }

  function lookup(email) {
    var seq = ++lookupSeq;
    lookupFor = email;
    lookupSay('Checking the staff directory...');
    $('#ua-submit').prop('disabled', true);
    $.getJSON('/api/users', { action: 'lookup', email: email })
      .done(function (res) {
        if (seq !== lookupSeq) return;       // a later keystroke has overtaken this
        if (!res.ok) { lookupSay(esc(res.error || 'Could not check that address.'), 'text-danger'); return; }
        lookupApply(res);
      })
      .fail(function () {
        if (seq !== lookupSeq) return;
        // Our own endpoint, not Graph. Same reasoning as 'unreachable': it says
        // nothing about the address, so the button is left alone rather than
        // locking somebody out of adding a colleague.
        lookupSay('Could not check that address just now.');
        $('#ua-submit').prop('disabled', false);
      });
  }

  /**
   * The name half of the address, whatever was typed or pasted.
   *
   * A WHOLE ADDRESS IS TRIMMED AT THE '@' rather than refused. Typing or pasting
   * "jane@littlemiracles.com.au" into a box that already ends in the domain is
   * the obvious thing to do out of habit, and would otherwise build the address
   * twice over. One at some OTHER domain is trimmed the same way and looked up
   * here - which answers honestly either way: if no jane@littlemiracles.com.au
   * exists the lookup says exactly that, and if one does, she is the person
   * being added.
   *
   * READ-ONLY, AND THAT IS THE WHOLE POINT. It rewrote the field on every input
   * event to begin with, which broke the case it was written for: typing
   * "emily@littlemiracles.com.au" one key at a time lost the '@' the instant it
   * was pressed, and every character after it landed on the end of "emily" --
   * "emilylittlemiracles.com.au". Tidying the text belongs on blur, once, when
   * they have finished.
   */
  function localPart($input) {
    var v = $.trim(String($input.val() || '')).toLowerCase();
    var at = v.indexOf('@');
    return at === -1 ? v : v.slice(0, at);
  }

  /** The whole address: the name typed, plus the domain fixed beside the field. */
  function addressFor($input) {
    var v = localPart($input);
    return v === '' || DOMAIN === '' ? '' : v + '@' + DOMAIN;
  }

  // Tidy what is on screen once they have finished with the field, so a pasted
  // address does not sit there reading "emily@littlemiracles.com.au" next to a
  // box saying "@littlemiracles.com.au". Nothing depends on this: the address is
  // built from localPart() either way.
  $modal.on('blur', '#ua-email', function () {
    var v = localPart($(this));
    if (v !== $(this).val()) $(this).val(v);
  });

  // 500ms, and never on a single character: without the pause, typing a name
  // would be one directory call per keystroke, all but the last about a prefix
  // - and "There is no account for e@..." while somebody is still on the first
  // letter of "emily" is a refusal about nothing.
  $modal.on('input', '#ua-email', function () {
    var local = localPart($(this));
    var email = addressFor($(this));
    clearTimeout(lookupTimer);
    if (email !== '' && email === lookupFor) return;   // already answered
    lookupSeq++;
    $('#ua-submit').prop('disabled', true);
    if (local.length < 2) {
      lookupFor = null;
      $('#ua-found').empty().removeClass('text-danger text-success');
      return;
    }
    lookupTimer = setTimeout(lookupNow, 500);
  });

  /** Show the centre boxes only when the chosen role actually uses them. */
  function syncAddForm() {
    var role = $('#ua-role').val();
    $('#ua-centres-wrap').toggleClass('d-none', role !== 'supervisor');
    $('#ua-hint').text(HINTS[role] || '');
  }

  $modal.on('change', '#ua-role', syncAddForm);
  $modal.on('click', '#ua-open-add', function () { showAdd(true); });
  $modal.on('click', '#ua-cancel',   function () { showAdd(false); });

  $modal.on('submit', '#users-add', function (e) {
    e.preventDefault();
    var $f = $(this);
    var data = {
      action: 'add',
      // The same join the lookup used, from the same place, so what was checked
      // is what is sent. The server checks the domain again regardless.
      email: addressFor($('#ua-email')),
      role: $('#ua-role').val(),
      centre_ids: $('#ua-centres input:checked').map(function () { return this.value; }).get()
    };
    // The add now waits on a Microsoft 365 lookup, so the button says what it
    // is doing. Without it a second of nothing reads as a click that missed.
    var $btn = $('#ua-submit');
    $btn.prop('disabled', true).text('Adding...');
    $.post('/api/users', data)
      // Back to the list on success, because the row that was just created is
      // the thing worth seeing - and it carries the name Entra answered with,
      // which is the proof the right person was added. A refusal stays on the
      // form with the address still in the field, so it can be corrected.
      .done(function (res) { if (applied(res)) showAdd(false); })
      .fail(function () { toast('Could not add that person.', 'danger'); })
      // Left ENABLED after a refusal, because the form stays up with the address
      // still in the field so it can be corrected - and the directory has
      // already said this address is a real person. Only a fresh keystroke locks
      // it again, which is what the input handler above is for.
      .always(function () { $btn.prop('disabled', false).text('Add'); });
  });

  // --- Editing a row ---------------------------------------------------------

  /** The centre ids ticked inside one row. */
  function rowCentres($row) {
    return $row.find('.ua-centres input:checked').map(function () { return this.value; }).get();
  }

  $modal.on('change', '.ua-role', function () {
    var $row = $(this).closest('.ua-row');
    var role = $(this).val();

    // Reveal the boxes BEFORE posting when they are about to be needed, so a
    // supervisor with nothing ticked can tick something rather than being told
    // no by the server with the boxes still hidden.
    if (role === 'supervisor' && !rowCentres($row).length) {
      $row.find('.ua-centres-wrap').removeClass('d-none');
      toast('Choose at least one centre for a supervisor, then set the role again.', 'danger');
      return;
    }

    $.post('/api/users', {
      action: 'set_role', id: $row.data('id'), role: role, centre_ids: rowCentres($row)
    })
      .done(applied)
      .fail(function () { toast('Could not change that role.', 'danger'); });
  });

  // A centre being ticked or unticked saves on the spot. There is no Save button
  // in a row: the modal has no single submit, and a per-row one is a change
  // somebody makes and then closes the dialog on.
  $modal.on('change', '.ua-centres input', function () {
    var $row = $(this).closest('.ua-row');
    var chosen = rowCentres($row);
    if (!chosen.length) {
      // Put the whole row back rather than posting a state the server will
      // refuse. Re-rendering rather than re-ticking this one box, because the
      // stored set is what USERS holds and guessing at it here is how the two
      // drift apart.
      render(USERS);
      toast('A supervisor needs at least one centre.', 'danger');
      return;
    }
    $.post('/api/users', { action: 'set_centres', id: $row.data('id'), centre_ids: chosen })
      .done(applied)
      .fail(function () { toast('Could not save those centres.', 'danger'); });
  });

  $modal.on('click', '.ua-remove', function () {
    var $row = $(this).closest('.ua-row');
    // Asked, and it names the person. This is the one action here that takes
    // somebody out of the app mid-shift.
    confirmModal({
      title: 'Remove access for ' + $row.data('email') + '?', ok: 'Remove access', danger: true,
      body: 'They will be signed out the next time they click something. ' +
            'You can restore it from this list afterwards.'
    }).done(function () {
      $.post('/api/users', { action: 'set_active', id: $row.data('id'), active: '0' })
        .done(applied)
        .fail(function () { toast('Could not remove that access.', 'danger'); });
    });
  });

  $modal.on('click', '.ua-restore', function () {
    var $row = $(this).closest('.ua-row');
    $.post('/api/users', { action: 'set_active', id: $row.data('id'), active: '1' })
      .done(applied)
      .fail(function () { toast('Could not restore that access.', 'danger'); });
  });

  // --- Opening ---------------------------------------------------------------

  // Re-read every time it opens rather than caching: another administrator may
  // have changed something, and this is the screen where a stale answer is worst.
  $('#user-access-open').on('click', function (e) {
    e.preventDefault();
    bootstrap.Modal.getOrCreateInstance(document.getElementById('users-modal')).show();
    // Whatever pane it was left on last time, it opens on the list: "who has
    // access" is the question people arrive with.
    showAdd(false);
    load();
  });
})();
