// Annual roll-over: review each child's transition, then apply.

(function ($) {
  'use strict';

  var DAYS = { 1: 'Mon', 2: 'Tue', 3: 'Wed', 4: 'Thu', 5: 'Fri' };
  var plan = null;

  // The centre is chosen in the centre bar in the chrome (links), so it is fixed
  // for the life of this page.
  var CENTRE = parseInt($('#init-centre').val(), 10);
  var YEARS  = ($('#init-years').val() || '').split(',').map(Number);
  var from = parseInt($('#init-from').val(), 10);
  var to   = parseInt($('#init-to').val(), 10);

  function centreId() { return CENTRE; }
  function fromYear() { return from; }
  function toYear()   { return to; }

  // Stepping either year NAVIGATES. The year is remembered server-side
  // (year_current), so a real page load is what keeps the session, the URL, the
  // sidebar links and the centre tabs consistent. This used to reload over AJAX
  // and then hand-patch the tab hrefs, which kept the tabs right and left the
  // sidebar pointing at the year you had just stepped away from.
  function go(f, t) {
    window.location = '/rollover?centre=' + CENTRE + '&from=' + f + '&to=' + t;
  }
  var fromNav = yearNav('#from-nav', function (y) {
    go(y, y >= to ? y + 1 : to);        // you can only ever roll forwards
  });
  var toNav = yearNav('#to-nav', function (y) {
    go(y <= from ? y - 1 : from, y);
  });

  function ageText(m) {
    if (m === null || m === undefined) return '';
    return m < 24 ? m + ' mo' : (Math.floor(m / 12) + 'y' + (m % 12 ? ' ' + (m % 12) + 'm' : ''));
  }

  function dayStrip(days) {
    return Object.keys(DAYS).map(function (d) {
      return '<span class="wl-day' + (days.indexOf(parseInt(d, 10)) !== -1 ? ' on' : '') + '">' +
             DAYS[d] + '</span>';
    }).join('');
  }

  var FLAG = { sibling: 'Sibling', staff: "Staff's child",
               two_educators: '2 educators', behaviour: 'Behaviour plan' };

  function flags(f) {
    return f.map(function (x) {
      return '<span class="badge text-bg-light border ms-1">' + esc(FLAG[x] || x) + '</span>';
    }).join('');
  }

  /** One reviewable row. `kind` drives which control is offered. */
  function row(c, kind) {
    var id = 'ro-' + c.placement_id;
    var pick = '';

    if (kind === 'moves') {
      pick = '<select class="form-select form-select-sm ro-room" data-id="' + c.placement_id + '">' +
        c.candidates.map(function (r, i) {
          var lbl = r.name + (r.same_stream ? '  (same stream)' : '') +
                    (r.capacity === null ? '  [no capacity set]' : '');
          return '<option value="' + r.room_id + '"' + (i === 0 ? ' selected' : '') + '>' +
                 esc(lbl) + '</option>';
        }).join('') + '</select>';
    } else if (kind === 'stays') {
      pick = '<span class="small text-success"><i class="fa-solid fa-arrow-right me-1"></i>' +
             esc(c.from_room) + '</span>';
    } else if (kind === 'leaves') {
      pick = '<span class="small text-muted">' + esc(c.note || 'Leaving') + '</span>';
    } else {
      pick = '<span class="small text-warning">' + esc(c.note || 'Cannot tell') + '</span>';
    }

    // 'unknown' has no safe default, so it is unticked and must be chosen by hand.
    var checked = kind === 'unknown' ? '' : ' checked';

    return '<div class="ro-row">' +
      '<div class="form-check">' +
        '<input class="form-check-input ro-pick" type="checkbox" id="' + id + '" ' +
          'data-id="' + c.placement_id + '" data-kind="' + kind + '"' + checked + '>' +
        '<label class="form-check-label" for="' + id + '">' +
          '<span class="fw-semibold">' + esc(c.name) + '</span>' +
          '<span class="small text-muted ms-2">' + ageText(c.age_start) + '</span>' + flags(c.flags) +
        '</label>' +
      '</div>' +
      '<div class="ro-from small text-muted">' + esc(c.from_room) + '</div>' +
      '<div class="ro-days">' + dayStrip(c.days) + '</div>' +
      '<div class="ro-pickcell">' + pick + '</div>' +
    '</div>';
  }

  var SECTIONS = [
    ['moves',   'Changing room',   'warning',
     'Their current room does not accept their age next year. Pick where they go.'],
    ['leaves',  'Leaving',         'secondary',
     'No room accepts them next year - these need an end-of-care letter. They are NOT carried over.'],
    ['unknown', 'Cannot tell',     'danger',
     'No date of birth on the board, so their room cannot be checked. Add a DOB first.'],
    ['stays',   'Staying put',     'success',
     'Their room still accepts them. Nothing to decide.']
  ];

  function render() {
    var html = SECTIONS.map(function (s) {
      var key = s[0], list = plan.groups[key];
      if (!list.length) return '';
      return '<div class="card mb-3">' +
        '<div class="card-header bg-body-tertiary d-flex align-items-center flex-wrap">' +
          '<span class="badge text-bg-' + s[2] + ' me-2">' + list.length + '</span>' +
          '<span class="fw-semibold">' + s[1] + '</span>' +
          '<span class="small text-muted ms-3">' + s[3] + '</span>' +
          '<button class="btn btn-sm btn-link ms-auto ro-toggle" data-group="' + key + '">' +
            'Select none</button>' +
        '</div>' +
        '<div class="card-body py-2" data-group="' + key + '">' +
          '<div class="ro-row ro-head">' +
            '<div>Child</div><div>From</div><div>Days</div>' +
            '<div>' + (key === 'moves' ? 'Move to' : '') + '</div>' +
          '</div>' +
          list.map(function (c) { return row(c, key); }).join('') +
        '</div></div>';
    }).join('');
    $('#ro-body').html(html || '<div class="card"><div class="card-body text-center py-5 text-muted">' +
      'Nothing on the ' + plan.from + ' board to roll over.</div></div>');
    $('#ro-actions').toggle(!!html).css('display', html ? 'flex' : 'none');
  }

  function renderSummary() {
    function tile(label, value, hint, cls) {
      return '<div class="col-6 col-lg-3"><div class="card h-100"><div class="card-body py-3">' +
        '<div class="text-muted small">' + label + '</div>' +
        '<div class="fs-3 fw-semibold ' + (cls || '') + '">' + value + '</div>' +
        '<div class="small text-muted">' + hint + '</div></div></div></div>';
    }
    var c = plan.counts;
    $('#ro-summary').html(
      tile('Staying put', c.stays, 'room still fits', 'text-success') +
      tile('Changing room', c.moves, 'need a destination', c.moves ? 'text-warning' : '') +
      tile('Leaving', c.leaves, 'end-of-care letters', c.leaves ? 'text-danger' : '') +
      tile('Cannot tell', c.unknown, 'missing a date of birth', c.unknown ? 'text-danger' : '')
    );

    // Applying twice would not duplicate linked children, but it would duplicate
    // unlinked ones - so say so plainly rather than relying on the guard.
    $('#ro-warning').html(plan.target_exists
      ? '<div class="alert alert-warning"><i class="fa-solid fa-triangle-exclamation me-2"></i>' +
        'The ' + plan.to + ' board already has <strong>' + plan.target_exists + '</strong> placement(s). ' +
        'Applying again will add to them, not replace them.</div>'
      : '');
  }

  /**
   * What families have already told Kidsoft about next year.
   *
   * A DECLINE is the only forward-looking vacancy signal in the API - every other
   * departure signal this app can see is retrospective - so declines lead, with
   * the accepted count as reassurance behind them. Read-only: Kidsoft runs the
   * re-enrolment flow and Notification/acknowledge is out of bounds, so there is
   * nothing to tick here and no second inbox to keep clear.
   */
  function renderReenrolment() {
    var r = plan.reenrolment;
    if (!r || (!r.accepted && !r.declined && !r.unknown)) { $('#ro-reenrol').empty(); return; }

    var declined = r.list.filter(function (x) { return x.outcome === 'declined'; });
    var out = '';

    if (declined.length) {
      out += '<div class="alert alert-warning py-2">' +
        '<div class="d-flex align-items-center gap-2 mb-1">' +
        '<i class="fa-solid fa-arrow-right-from-bracket"></i>' +
        '<strong>' + declined.length + ' famil' + (declined.length === 1 ? 'y has' : 'ies have') +
        ' declined re-enrolment for ' + plan.to + '.</strong></div>' +
        '<div class="small">Their places come free next year and can be sold now. ' +
        'This is the only advance warning Kidsoft gives - everywhere else a departure ' +
        'only shows up once the bookings stop.</div>' +
        '<ul class="small mb-0 mt-1">' +
        declined.map(function (x) {
          // The name as Kidsoft wrote it, deliberately NOT matched to a board row:
          // it is parsed out of a sentence, and a fuzzy match would risk pinning one
          // child's decline on another.
          return '<li>' + (x.child ? '<strong>' + esc(x.child) + '</strong>' : esc(x.text)) +
            ' <span class="text-muted">' + fmtDateTime(x.date) + '</span></li>';
        }).join('') + '</ul></div>';
    }

    if (r.accepted) {
      out += '<div class="alert alert-light border py-2 small">' +
        '<i class="fa-solid fa-circle-check text-success me-1"></i>' +
        '<strong>' + r.accepted + '</strong> famil' + (r.accepted === 1 ? 'y has' : 'ies have') +
        ' accepted re-enrolment for ' + plan.to + ' in Kidsoft. Those children still need a ' +
        'room below - accepting next year is not the same as being placed in it.</div>';
    }

    $('#ro-reenrol').html(out);
  }

  function load() {
    $('#ro-body').html(spinner('Working out who goes where...'));
    $.getJSON('/api/rollover', {
      action: 'plan', centre_id: centreId(), from_year: fromYear(), to_year: toYear()
    })
      .done(function (res) {
        if (!res.ok) {
          $('#ro-body').html('<div class="text-center text-danger py-5">' + esc(res.error) + '</div>');
          $('#ro-summary').empty(); $('#ro-warning').empty();
          $('#ro-reenrol').empty(); $('#ro-actions').hide();
          return;
        }
        plan = res;
        renderSummary();
        renderReenrolment();
        render();
      })
      .fail(function () {
        $('#ro-body').html('<div class="text-center text-danger py-5">Could not build the plan.</div>');
      });
  }

  $('#ro-body').on('click', '.ro-toggle', function () {
    var g = $(this).data('group');
    var $boxes = $('[data-group="' + g + '"] .ro-pick');
    var turnOff = $boxes.filter(':checked').length > 0;
    $boxes.prop('checked', !turnOff);
    $(this).text(turnOff ? 'Select all' : 'Select none');
  });

  $('#apply').on('click', function () {
    var decisions = [];
    $('.ro-pick:checked').each(function () {
      var id = $(this).data('id'), kind = $(this).data('kind');
      if (kind === 'unknown') return;   // no safe action without a DOB
      var d = { placement_id: id, action: kind === 'leaves' ? 'leave'
                                        : (kind === 'moves' ? 'move' : 'stay') };
      if (d.action === 'move') d.room_id = $('.ro-room[data-id="' + id + '"]').val();
      decisions.push(d);
    });
    if (!decisions.length) { toast('Nothing selected.', 'danger'); return; }

    var moving = decisions.filter(function (d) { return d.action === 'move'; }).length;
    var leaving = decisions.filter(function (d) { return d.action === 'leave'; }).length;
    if (!confirm('Carry ' + (decisions.length - leaving) + ' child(ren) into ' + toYear() +
                 ' (' + moving + ' changing room), and mark ' + leaving + ' as ending care?\n\n' +
                 'Everyone carried over starts as "Not set" until the family confirms.')) return;

    var $btn = $(this).prop('disabled', true).text('Applying...');
    $.post('/api/rollover', {
      action: 'apply', centre_id: centreId(), from_year: fromYear(), to_year: toYear(),
      decisions: JSON.stringify(decisions)
    })
      .done(function (res) {
        if (!res.ok) { toast(res.error, 'danger'); return; }
        toast(res.message);
        (res.skipped || []).forEach(function (s) { toast(s, 'warning'); });
        load();
      })
      .fail(function () { toast('Could not apply the roll-over.', 'danger'); })
      .always(function () { $btn.prop('disabled', false).text('Apply selected'); });
  });

  // Paint both steppers from what the server rendered, then run the preview.
  fromNav.set(from, YEARS);
  toNav.set(to, YEARS);
  load();
})(jQuery);
