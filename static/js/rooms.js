// Rooms, capacities and sub-quotas.
//
// EVERYTHING ABOUT A ROOM EXCEPT SUB-QUOTAS AND NOTES IS READ FROM KIDSOFT, so
// this screen shows far more than it edits. There is no Add room, no name field,
// no age fields, no archive and no capacity override - see the header of
// api/rooms.php, where the same restriction is enforced server-side.

(function ($) {
  'use strict';

  var DAYS = { 1: 'Mon', 2: 'Tue', 3: 'Wed', 4: 'Thu', 5: 'Fri' };
  var COLS = 9;   // room + 5 weekdays + age + quotas + notes

  var year = parseInt($('#init-year').val(), 10);

  // The centre comes from the centre bar in the chrome, which is a set of links,
  // so it is fixed for the life of this page.
  var CENTRE = parseInt($('#init-centre').val(), 10);
  function centreId() { return CENTRE; }

  // ===========================================================================
  // ALL CENTRES: the centre register
  // ===========================================================================
  //
  // Which view this page is was decided server-side, so only one set of
  // containers exists in the DOM and the centre code below never runs here.

  function initCentres() {
    var $ct = $('#centres-table tbody');
    // TODAY, FROM THE SERVER. The badge below compares an opening date against
    // it, and a viewer in another timezone must not get a different answer to
    // "has this centre opened yet" - the reinterpretation _incl/dates.php
    // forbids. #init-today is rendered by rooms.php from the Sydney clock.
    function today() { return $('#init-today').val() || ''; }
    var centreModal = new bootstrap.Modal('#centre-modal');
    var centres = [];

    function render() {
      $ct.html(centres.map(function (c, i) {
        // Badged only when there is something to say. A centre this app has
        // retired, or one Kidsoft has archived since the last sync - the second
        // is a disagreement worth seeing, which is why Kidsoft's own status is
        // reported alongside ours rather than instead of it.
        var st = '';
        // NOT OPEN YET, with the day it opens. Badged only while that day is
        // still ahead: once it has passed the centre is simply open, and a badge
        // reading "opens 4/01/2027" in 2028 is the caption that ages into a lie.
        if (c.opens_on && c.opens_on > today()) {
          st += ' <span class="badge text-bg-light border text-body-secondary">' +
                '<i class="fa-solid fa-calendar-day me-1"></i>Opens ' + esc(c.opens_disp) +
                '</span>';
        }
        if (!c.active) {
          st += ' <span class="badge text-bg-secondary">Not active</span>';
        } else if (c.ks_status && c.ks_status !== 'Active') {
          st = ' <span class="badge text-bg-warning" title="Kidsoft says ' + esc(c.ks_status) +
               '">Archived in Kidsoft</span>';
        }
        // THE NAME GOES TO THE CENTRE, as a centre name in a table does on every
        // other screen (.ov-centre-link). It used to open the editor, which is
        // where Broc went looking for the opening date and did not find it - the
        // pencil at the end of the row is that door now. A centre this app has
        // retired has no rooms page to go to (the centre bar leaves it out), so
        // it stays plain text.
        var name = c.active
          ? '<a class="ov-centre-link" href="/rooms?centre=' + c.id + '" title="Rooms at ' +
              esc(c.name) + '">' + esc(c.name) + '</a>'
          : esc(c.name);
        return '<tr>' +
          '<td class="fw-semibold">' + name + st + '</td>' +
          '<td class="text-muted small">' + esc(c.kidsoft_name || '') + '</td>' +
          '<td class="text-center">' + (c.rooms || '<span class="text-muted">0</span>') + '</td>' +
          '<td class="text-end"><div class="d-flex justify-content-end gap-1 flex-nowrap">' +
            '<button class="btn btn-sm btn-outline-secondary move-centre" data-id="' + c.id +
              '" data-dir="-1" title="Move earlier"' + (i === 0 ? ' disabled' : '') +
              '><i class="fa-solid fa-arrow-up"></i></button>' +
            '<button class="btn btn-sm btn-outline-secondary move-centre" data-id="' + c.id +
              '" data-dir="1" title="Move later"' + (i === centres.length - 1 ? ' disabled' : '') +
              '><i class="fa-solid fa-arrow-down"></i></button>' +
          '</div></td>' +
          // aria-label rather than a .visually-hidden span: that is
          // position:absolute, and one inside a scroll container on every row is
          // the 16,000px phantom document ui-conventions.md describes.
          '<td class="text-end"><button class="btn btn-sm btn-outline-secondary edit-centre" data-id="' +
            c.id + '" title="Edit the short name and first day of operation" aria-label="Edit ' +
            esc(c.name) + '"><i class="fa-solid fa-pen"></i></button></td></tr>';
      }).join(''));

      var live = centres.filter(function (c) { return c.active; });
      var noRooms = live.filter(function (c) { return !c.rooms; }).length;
      $('#centres-summary').html(
        window.statTile('Centres', live.length, null, '', 'col-sm-4') +
        window.statTile('Rooms', live.reduce(function (a, c) { return a + c.rooms; }, 0),
                        'Across the organisation', '', 'col-sm-4') +
        // A live centre with no rooms is either brand new or a sync that has not
        // run; either way it is the one thing on this screen worth chasing.
        window.statTile('Centres with no rooms', noRooms, noRooms ? 'Try Check Kidsoft for new centres' : null,
                        noRooms ? 'text-warning' : 'text-success', 'col-sm-4')
      );
    }

    function load() {
      $ct.html(spinnerRow(5, 'Loading centres...'));
      $.getJSON('/api/rooms', { action: 'centres' })
        .done(function (res) {
          if (!res.ok) { toast(res.error, 'danger'); return; }
          centres = res.centres;
          render();
        })
        .fail(function () {
          $ct.html('<tr><td colspan="5" class="text-center text-danger py-4">Could not load centres.</td></tr>');
        });
    }

    $ct.on('click', '.edit-centre', function () {
      var id = $(this).data('id');
      var c = centres.filter(function (x) { return x.id == id; })[0];
      if (!c) return;
      var $f = $('#centre-form');
      $f[0].reset();
      $f.find('[name=id]').val(c.id);
      $f.find('[name=name]').val(c.name);
      // The stored date goes straight into the date input: both are yyyy-mm-dd
      // and nothing here builds a Date, which is the rule the whole Dates block
      // in app.js exists to hold.
      $f.find('[name=opens_on]').val(c.opens_on || '');
      $('#centre-kidsoft').html('<i class="fa-solid fa-circle-info me-1"></i>Kidsoft calls this <strong>' +
        esc(c.kidsoft_name || 'unknown') + '</strong>.');
      $('.modal-title', '#centre-modal').text('Centre - ' + c.name);
      centreModal.show();
    });

    $('#centre-form').on('submit', function (e) {
      e.preventDefault();
      var $btn = $(this).find('[type=submit]').prop('disabled', true);
      $.post('/api/rooms', $(this).serialize() + '&action=save_centre')
        .done(function (res) {
          if (!res.ok) { toast(res.error, 'danger'); return; }
          centreModal.hide();
          // A full reload, not a re-render: the centre bar and every nav link in
          // the chrome carry this name and are built server-side.
          window.location.reload();
        })
        .fail(function () { toast('Could not save the centre.', 'danger'); })
        .always(function () { $btn.prop('disabled', false); });
    });

    $ct.on('click', '.move-centre', function () {
      var $b = $(this).prop('disabled', true);
      $.post('/api/rooms', { action: 'move_centre', id: $b.data('id'), dir: $b.data('dir') })
        .done(function (res) {
          if (!res.ok) { toast(res.error, 'danger'); $b.prop('disabled', false); return; }
          window.location.reload();   // the centre bar is ordered by this
        })
        .fail(function () { toast('Could not reorder.', 'danger'); $b.prop('disabled', false); });
    });

    $('#sync-centres').on('click', function () {
      var $b = $(this).prop('disabled', true);
      $.post('/api/rooms', { action: 'sync_centres' })
        .done(function (res) {
          if (!res.ok) { toast(res.error, 'danger'); return; }
          var bits = [];
          if (res.created)  bits.push(res.created + ' centre(s) added: ' + res.names.join(', '));
          if (res.retired)  bits.push(res.retired + ' no longer active in Kidsoft');
          if (res.restored) bits.push(res.restored + ' reopened');
          if (bits.length) { toast(bits.join('. ') + '.'); window.location.reload(); return; }
          toast('No new centres. Kidsoft lists ' + res.checked + ' service(s).');
        })
        .fail(function () { toast('Could not reach Kidsoft.', 'danger'); })
        .always(function () { $b.prop('disabled', false); });
    });

    load();
  }

  if (!CENTRE) { initCentres(); return; }

  // ===========================================================================
  // ONE CENTRE: its rooms
  // ===========================================================================

  var $table = $('#rooms-table tbody');
  var quotaModal = new bootstrap.Modal('#quota-modal');
  var notesModal = new bootstrap.Modal('#notes-modal');
  var rooms = [];

  var yn = yearNav('#year-nav', function (y) {
    // A real navigation, so year_current() remembers it and every link on the
    // page is re-rendered pointing at it. This used to reload over AJAX and
    // then hand-patch the centre tab hrefs, which kept the tabs right and left
    // the sidebar pointing at the old year.
    window.location = '/rooms?centre=' + CENTRE + '&year=' + y;
  });

  // --- Rendering -------------------------------------------------------------

  function ageText(r) {
    if (r.min_age_months === null && r.max_age_months === null) return '<span class="text-muted">Any</span>';
    var lo = r.min_age_months === null ? '0' : r.min_age_months;
    var hi = r.max_age_months === null ? '+' : r.max_age_months;
    return esc(lo + '-' + hi + ' months');
  }

  function quotaChips(r) {
    if (!r.subquotas.length) {
      return '<button class="btn btn-sm btn-link p-0 add-quota" data-room="' + r.id + '">Add sub-quota</button>';
    }
    var html = r.subquotas.map(function (q) {
      return '<button class="btn btn-sm btn-outline-secondary me-1 mb-1 edit-quota" data-id="' + q.id +
             '" data-room="' + r.id + '" title="' + esc(q.describe) + '">' +
             esc(q.label) + ' <span class="badge text-bg-secondary ms-1">' + q.max_children + '</span></button>';
    }).join('');
    return html + '<button class="btn btn-sm btn-link p-0 add-quota" data-room="' + r.id + '">Add</button>';
  }

  function notesCell(r) {
    if (!r.notes) {
      return '<button class="btn btn-sm btn-link p-0 edit-notes" data-id="' + r.id + '">Add note</button>';
    }
    return '<button class="btn btn-sm btn-link p-0 text-start edit-notes" data-id="' + r.id + '">' +
           esc(r.notes) + '</button>';
  }

  /** One weekday's capacity cell. Blank is UNKNOWN, never zero. */
  function capCell(r, wd) {
    var d = r.capacity_detail[wd] || {};
    if (d.capacity === null || d.capacity === undefined) {
      return '<td class="text-center cap-cell">' +
        '<span class="text-muted" title="No capacity recorded for ' + DAYS[wd] + ' ' + year +
        ' - occupancy cannot be calculated for that day">-</span></td>';
    }
    return '<td class="text-center cap-cell"><span title="From Kidsoft' +
      (d.synced_at ? ', read ' + fmtDateTime(d.synced_at) : '') + '">' + d.capacity + '</span></td>';
  }

  function render() {
    if (!rooms.length) {
      $table.html('<tr><td colspan="' + COLS + '" class="p-0">' +
        emptyState('fa-door-open', 'No rooms at this centre yet',
                   'Rooms come from Kidsoft; try Refresh from Kidsoft.') +
        '</td></tr>');
      return;
    }
    $table.html(rooms.map(function (r) {
      // Unlinked can only be a legacy hand-created row now, and it is not
      // cosmetic: the room contributes no attendance at all. Badged rather than
      // given a column, which would have read "Linked" on every row.
      var warn = r.kidsoft_room_id ? '' :
        ' <span class="badge text-bg-danger"><i class="fa-solid fa-link-slash me-1"></i>Not linked</span>';
      // No archived state to render: /api/rooms returns active rooms only.
      return '<tr>' +
        '<td class="fw-semibold">' + roomIcon(r.name) + esc(r.name) + warn + '</td>' +
        Object.keys(DAYS).map(function (wd) { return capCell(r, wd); }).join('') +
        // nowrap: "0-12 months" wrapped after the numbers once the Kidsoft-link
        // column went, which doubled the height of every row.
        '<td class="text-nowrap">' + ageText(r) + '</td>' +
        '<td>' + quotaChips(r) + '</td>' +
        '<td>' + notesCell(r) + '</td></tr>';
    }).join(''));
  }

  function renderSummary() {
    // Capacity is per weekday, so the meaningful totals are place-DAYS per week
    // and the number of individual days still missing a figure.
    var places = 0, noCap = 0;
    rooms.forEach(function (r) {
      Object.keys(DAYS).forEach(function (wd) {
        var d = r.capacity_detail[wd] || {};
        if (d.capacity === null || d.capacity === undefined) noCap++;
        else places += d.capacity;
      });
    });
    var unlinked = rooms.filter(function (r) { return !r.kidsoft_room_id; }).length;

    // Shared tile (app.js), three across on this screen. NOTE the argument
    // order: this screen's local version took (label, value, cls, hint) where
    // every other took (label, value, hint, cls), so the wrapper swaps them
    // rather than every call site being rewritten.
    function tile(label, value, cls, hint) {
      return window.statTile(label, value, hint, cls, 'col-sm-4');
    }
    $('#rooms-summary').html(
      // "Active rooms" while retired ones were on screen; with them hidden the
      // qualifier described nothing the table was showing.
      tile('Rooms', rooms.length, '') +
      tile('Places per week', places, '', 'Across this centre in ' + year) +
      tile('Needs attention', noCap + unlinked, (noCap + unlinked) ? 'text-danger' : 'text-success',
           noCap + ' room-day(s) with no ' + year + ' capacity, ' + unlinked + ' not linked to Kidsoft')
    );
  }

  // --- Loading ---------------------------------------------------------------

  function load() {
    $table.html(spinnerRow(COLS, 'Loading rooms...'));
    $.getJSON('/api/rooms', { action: 'list', centre_id: centreId(), year: year })
      .done(function (res) {
        if (!res.ok) { toast(res.error, 'danger'); return; }
        rooms = res.rooms;
        yn.set(year, res.years);
        render();
        renderSummary();
        // WHAT THE FIGURES ABOVE ARE REALLY FOR. This screen keeps its table for
        // a centre that has not opened - it is how you check the setup before it
        // does - but "385 places per week across this centre in 2026" reads as
        // places to sell, and in a year the centre does not trade they are the
        // licensed positions and nothing more. One sentence, with the date,
        // which is the thing the reader cannot work out from the table.
        var n = res.not_open;
        $('#rooms-notice').html(n
          ? '<div class="alert alert-secondary py-2">' +
            '<i class="fa-solid fa-calendar-day me-2"></i><strong>' + esc(n.centre) +
            '</strong> opens ' + esc(n.opens) + ' - these are the rooms Kidsoft has ' +
            'set up, not places to sell in ' + n.year + '.</div>'
          : '');
      })
      .fail(function () {
        $table.html('<tr><td colspan="' + COLS + '" class="text-center text-danger py-4">' +
          'Could not load rooms.</td></tr>');
        toast('Could not load rooms.', 'danger');
      });
  }

  // --- Refresh from Kidsoft --------------------------------------------------

  $('#sync-kidsoft').on('click', function () {
    var $b = $(this).prop('disabled', true);
    $.post('/api/rooms', { action: 'sync_kidsoft', centre_id: centreId(), year: year })
      .done(function (res) {
        if (!res.ok) { toast(res.error, 'danger'); return; }
        // Say what CHANGED, not what was looked at. A room appearing, being
        // renamed or being retired is news; "16 rooms read" is not.
        var r = res.rooms || {}, c = res.capacity || {};
        var bits = [];
        if (r.created)  bits.push(r.created + ' room(s) added');
        if (r.renamed)  bits.push(r.renamed + ' renamed');
        if (r.aged)     bits.push(r.aged + ' age range(s) changed');
        if (r.retired)  bits.push(r.retired + ' retired');
        if (r.restored) bits.push(r.restored + ' reopened');
        if (c.changed)  bits.push(c.changed + ' capacity figure(s) changed');
        toast(bits.length ? bits.join(', ') + '.'
                          : 'Up to date with Kidsoft (' + (c.rooms || 0) + ' room(s) read).');
        if (r.clashes && r.clashes.length) {
          toast('Could not update: ' + r.clashes.join(', ') +
                ' - another room here already has that name.', 'warning');
        }
        load();
      })
      .fail(function () { toast('Could not reach Kidsoft.', 'danger'); })
      .always(function () { $b.prop('disabled', false); });
  });

  // --- Notes -----------------------------------------------------------------

  $table.on('click', '.edit-notes', function () {
    var id = $(this).data('id');
    var r = rooms.filter(function (x) { return x.id == id; })[0];
    if (!r) return;
    var $f = $('#notes-form');
    $f[0].reset();
    $f.find('[name=room_id]').val(r.id);
    $f.find('[name=notes]').val(r.notes || '');
    $('#notes-context').html('Notes on <strong>' + roomIcon(r.name) + esc(r.name) + '</strong>. ' +
      'Kidsoft has no field for these, so they are ours.');
    $('.modal-title', '#notes-modal').text('Notes - ' + r.name);
    notesModal.show();
  });

  $('#notes-form').on('submit', function (e) {
    e.preventDefault();
    var $btn = $(this).find('[type=submit]').prop('disabled', true);
    $.post('/api/rooms', $(this).serialize() + '&action=save_notes')
      .done(function (res) {
        if (!res.ok) { toast(res.error, 'danger'); return; }
        notesModal.hide();
        toast('Notes saved.');
        load();
      })
      .fail(function () { toast('Could not save the notes.', 'danger'); })
      .always(function () { $btn.prop('disabled', false); });
  });

  // --- Sub-quota editor ------------------------------------------------------

  function openQuota(roomId, quota) {
    var $f = $('#quota-form');
    $f[0].reset();
    $f.find('[name=room_id]').val(roomId);
    $f.find('[name=year]').val(year);
    $f.find('[name=id]').val(quota ? quota.id : '');
    if (quota) {
      $f.find('[name=label]').val(quota.label);
      $f.find('[name=age_under_months]').val(quota.age_under_months);
      $f.find('[name=age_over_months]').val(quota.age_over_months);
      $f.find('[name=max_children]').val(quota.max_children);
    }
    $('#quota-delete').toggleClass('d-none', !quota);
    quotaModal.show();
  }

  $table.on('click', '.add-quota', function () { openQuota($(this).data('room'), null); });

  $table.on('click', '.edit-quota', function () {
    var roomId = $(this).data('room'), id = $(this).data('id');
    var r = rooms.filter(function (x) { return x.id == roomId; })[0];
    var q = r && r.subquotas.filter(function (x) { return x.id == id; })[0];
    if (q) openQuota(roomId, q);
  });

  $('#quota-form').on('submit', function (e) {
    e.preventDefault();
    var $btn = $(this).find('[type=submit]').prop('disabled', true);
    $.post('/api/rooms', $(this).serialize() + '&action=save_subquota')
      .done(function (res) {
        if (!res.ok) { toast(res.error, 'danger'); return; }
        quotaModal.hide();
        toast('Sub-quota saved.');
        load();
      })
      .fail(function () { toast('Could not save the sub-quota.', 'danger'); })
      .always(function () { $btn.prop('disabled', false); });
  });

  $('#quota-delete').on('click', function () {
    var id = $('#quota-form [name=id]').val();
    if (!id) return;
    confirmModal({ title: 'Delete this sub-quota?', ok: 'Delete', danger: true }).done(function () {
      $.post('/api/rooms', { action: 'delete_subquota', id: id })
        .done(function (res) {
          if (!res.ok) { toast(res.error, 'danger'); return; }
          quotaModal.hide();
          toast('Sub-quota deleted.');
          load();
        });
    });
  });

  load();
})(jQuery);
