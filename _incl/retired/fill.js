// Fill vacancies: free places matched to the waiting queue.

(function ($) {
  'use strict';

  var DAYS = { 1: 'Mon', 2: 'Tue', 3: 'Wed', 4: 'Thu', 5: 'Fri' };
  var offerModal = new bootstrap.Modal('#offer-modal');
  var year = parseInt($('#init-year').val(), 10);
  var data = null;

  function centreId() { return parseInt($('#centre-select').val(), 10); }

  function ageText(m) {
    if (m === null) return '<span class="text-muted">no DOB</span>';
    return m < 24 ? m + ' mo' : (Math.floor(m / 12) + 'y' + (m % 12 ? ' ' + (m % 12) + 'm' : ''));
  }

  function candidateRow(c, roomId, wd) {
    var why = c.reasons.length
      ? c.reasons.map(function (r) {
          return '<span class="badge text-bg-light border ms-1">' + esc(r) + '</span>';
        }).join('')
      : '';
    var days = Object.keys(DAYS).map(function (d) {
      return '<span class="wl-day' + (c.days.indexOf(parseInt(d, 10)) !== -1 ? ' on' : '') + '">' +
             DAYS[d] + '</span>';
    }).join('');

    if (!c.eligible) {
      return '<div class="fill-cand blocked">' +
        '<span class="fill-q">#' + c.queue + '</span>' +
        '<span class="text-muted">' + esc(c.name) + '</span>' +
        '<span class="small text-danger ms-2">' + esc(c.blocked) + '</span></div>';
    }
    return '<div class="fill-cand">' +
      '<span class="fill-q">#' + c.queue + '</span>' +
      '<span class="fw-semibold">' + esc(c.name) + '</span>' +
      '<span class="small text-muted ms-2">' + ageText(c.age_months) + '</span>' + why +
      '<span class="ms-2">' + days + '</span>' +
      '<button class="btn btn-sm btn-primary ms-auto offer" data-wl="' + c.waitlist_id +
        '" data-room="' + roomId + '" data-wd="' + wd + '">Offer</button>' +
      '</div>';
  }

  function roomCard(r) {
    var age = (r.min_age_months === null && r.max_age_months === null) ? '' :
      '<span class="badge text-bg-light border ms-2">' +
      esc((r.min_age_months === null ? '0' : r.min_age_months) + '-' +
          (r.max_age_months === null ? '+' : r.max_age_months) + ' months') + '</span>';

    var quotas = r.subquotas.length
      ? '<span class="small text-muted ms-2">' +
        r.subquotas.map(function (q) { return esc(q.describe); }).join('; ') + '</span>'
      : '';

    var cells = Object.keys(DAYS).map(function (wd) {
      var c = r.cells[wd];
      if (c.capacity === null) {
        return '<div class="fill-day"><div class="fill-day-head text-muted">' + DAYS[wd] +
               ' &middot; no capacity set</div></div>';
      }
      if (!c.free) return '';   // nothing to fill
      var head = '<div class="fill-day-head"><strong>' + DAYS[wd] + '</strong> ' +
        '<span class="text-danger fw-semibold">' + c.free + ' free</span> ' +
        '<span class="text-muted small">' + c.occupied + '/' + c.capacity +
        (c.promised ? ' &middot; ' + c.promised + ' promised' : '') + '</span></div>';
      var body = c.candidates.length
        ? c.candidates.map(function (x) { return candidateRow(x, r.id, wd); }).join('')
        : '<div class="fill-cand"><span class="small text-muted">Nobody waiting wants this day.</span></div>';
      return '<div class="fill-day">' + head + body + '</div>';
    }).join('');

    if (!cells) return '';

    return '<div class="card mb-3"><div class="card-header bg-body-tertiary d-flex flex-wrap align-items-center">' +
      '<span class="fw-semibold">' + esc(r.name) + '</span>' + age + quotas +
      '<span class="ms-auto small text-muted">' + r.week_free + ' free this week</span>' +
      '</div><div class="card-body py-2">' + cells + '</div></div>';
  }

  function render() {
    var rooms = data.rooms;
    if ($('#only-matches').is(':checked')) {
      // Default view: an empty room with no waiting families is not something
      // anyone can act on today, and it buries the rooms that are.
      rooms = rooms.filter(function (r) {
        return Object.keys(DAYS).some(function (wd) { return r.cells[wd].eligible_count > 0; });
      });
    }
    if (!rooms.length) {
      $('#fill-list').html('<div class="card"><div class="card-body text-center py-5">' +
        '<div class="fs-1 text-success mb-2"><i class="fa-solid fa-circle-check"></i></div>' +
        '<div class="fs-5">' + (data.totals.free
          ? 'No waiting family matches a free place right now.'
          : 'Every room is full in ' + data.year + '.') + '</div>' +
        '<div class="text-muted">' + data.totals.free + ' places free &middot; ' +
        data.totals.queue + ' families waiting. ' +
        (data.totals.free && !data.totals.queue ? 'The waitlist is empty.' : '') +
        '</div></div></div>');
      return;
    }
    $('#fill-list').html(rooms.map(roomCard).join(''));
  }

  function renderSummary() {
    var matchable = 0, cells = 0;
    data.rooms.forEach(function (r) {
      Object.keys(DAYS).forEach(function (wd) {
        var c = r.cells[wd];
        if (c.free) cells++;
        if (c.eligible_count) matchable++;
      });
    });
    function tile(label, value, hint, cls) {
      return '<div class="col-6 col-lg-3"><div class="card h-100"><div class="card-body py-3">' +
        '<div class="text-muted small">' + label + '</div>' +
        '<div class="fs-3 fw-semibold ' + (cls || '') + '">' + value + '</div>' +
        '<div class="small text-muted">' + hint + '</div></div></div></div>';
    }
    $('#fill-summary').html(
      tile('Places free', data.totals.free, 'across ' + cells + ' room-days in ' + data.year,
           data.totals.free ? 'text-danger' : 'text-success') +
      tile('Fillable now', matchable, 'room-days with a matching family',
           matchable ? 'text-success' : '') +
      tile('Waiting', data.totals.queue, 'families still wanting days') +
      tile('Year', data.year, 'capacities and board for this year')
    );
  }

  function fillYears(years) {
    if (years.indexOf(year) === -1) years = years.concat([year]).sort();
    $('#year-select').html(years.map(function (y) {
      return '<option value="' + y + '"' + (y === year ? ' selected' : '') + '>' + y + '</option>';
    }).join(''));
  }

  function load() {
    $('#fill-list').html('<div class="text-center text-muted py-5">Matching...</div>');
    $.getJSON('/api/fill', { action: 'list', centre_id: centreId(), year: year })
      .done(function (res) {
        if (!res.ok) {
          $('#fill-list').html('<div class="text-center text-danger py-5">' + esc(res.error) + '</div>');
          $('#fill-summary').empty();
          return;
        }
        data = res;
        fillYears(res.years);
        render();
        renderSummary();
      })
      .fail(function () {
        $('#fill-list').html('<div class="text-center text-danger py-5">Could not load vacancies.</div>');
      });
  }

  // --- Offering --------------------------------------------------------------

  $('#fill-list').on('click', '.offer', function () {
    var wlId = $(this).data('wl'), roomId = $(this).data('room'), wd = parseInt($(this).data('wd'), 10);
    var room = data.rooms.filter(function (r) { return r.id == roomId; })[0];
    if (!room) return;
    var cand = null;
    Object.keys(DAYS).forEach(function (d) {
      room.cells[d].candidates.forEach(function (c) { if (c.waitlist_id == wlId) cand = c; });
    });
    if (!cand) return;

    var $f = $('#offer-form');
    $f[0].reset();
    $f.find('[name=waitlist_id]').val(wlId);
    $f.find('[name=room_id]').val(roomId);
    $f.find('[name=year]').val(year);

    // Offer every day they want that is actually free in this room, with the
    // clicked day pre-ticked. Days they want but that are full are shown
    // disabled rather than hidden, so it is clear why they are not included.
    $('#offer-days').html(cand.days.map(function (d) {
      var cell = room.cells[d];
      var free = cell && cell.free;
      return '<div><input type="checkbox" class="btn-check" name="days[]" value="' + d +
        '" id="fd-' + d + '"' + (free ? '' : ' disabled') + (free ? ' checked' : '') + '>' +
        '<label class="btn btn-outline-primary btn-sm" for="fd-' + d + '">' + DAYS[d] +
        '<span class="d-block small fw-normal">' +
        (free ? free + ' free' : 'full') + '</span></label></div>';
    }).join(''));

    $('#offer-context').html('Offering <strong>' + esc(cand.name) + '</strong> a place in <strong>' +
      esc(room.name) + '</strong> for ' + year + '. They are #' + cand.queue + ' in the queue' +
      (cand.reasons.length ? ' (' + esc(cand.reasons.join(', ')) + ')' : '') + '.');
    $('.modal-title', '#offer-modal').text('Offer ' + cand.name + ' a place');
    offerModal.show();
  });

  $('#offer-form').on('submit', function (e) {
    e.preventDefault();
    var $btn = $(this).find('[type=submit]').prop('disabled', true);
    $.post('/api/waitlist', $(this).serialize() + '&action=make_offer')
      .done(function (res) {
        if (!res.ok) { toast(res.error, 'danger'); return; }
        offerModal.hide();
        toast('Offer sent. It expires ' + fmtDateTime(res.expires_at) + '.');
        load();
      })
      .fail(function () { toast('Could not send the offer.', 'danger'); })
      .always(function () { $btn.prop('disabled', false); });
  });

  $('#centre-select').on('change', load);
  $('#year-select').on('change', function () { year = parseInt($(this).val(), 10); load(); });
  $('#only-matches').on('change', render);

  load();
})(jQuery);
