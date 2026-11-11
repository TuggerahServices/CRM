// Drift: board vs Kidsoft, with a one-click resolution on each row.

(function ($) {
  'use strict';

  var DAYS = { 1: 'Mon', 2: 'Tue', 3: 'Wed', 4: 'Thu', 5: 'Fri' };
  var monday = $('#monday').val();
  var data = null;

  function centreId() { return parseInt($('#centre-select').val(), 10); }

  function shiftDays(ymd, n) {
    var p = ymd.split('-');
    var d = new Date(Date.UTC(+p[0], +p[1] - 1, +p[2]));
    d.setUTCDate(d.getUTCDate() + n);
    return d.toISOString().slice(0, 10);
  }

  /** Mon-Fri strip; `on` days filled, optionally diffed against another set. */
  function dayStrip(days, other, diffCls) {
    return Object.keys(DAYS).map(function (wd) {
      var w = parseInt(wd, 10);
      var on = days.indexOf(w) !== -1;
      var differs = other && (on !== (other.indexOf(w) !== -1));
      return '<span class="wl-day' + (on ? ' on' : '') +
             (differs ? ' ' + (diffCls || 'diff') : '') + '">' + DAYS[wd] + '</span>';
    }).join('');
  }

  var TYPE_CLS = {
    booked_but_not_placed: 'danger',
    missing_from_board:    'danger',
    wrong_room:            'warning',
    day_mismatch:          'warning',
    missing_from_kidsoft:  'secondary'
  };

  /** The comparison body, per drift type. */
  function detail(i) {
    if (i.type === 'day_mismatch') {
      var s = '<div class="drift-cmp">' +
        '<div><span class="drift-src">Board</span>' + dayStrip(i.board_days, i.ks_days) + '</div>' +
        '<div><span class="drift-src">Kidsoft</span>' + dayStrip(i.ks_days, i.board_days) + '</div>' +
        '</div>' +
        '<div class="small text-muted mt-1">' + esc(i.board_room) + '</div>';
      // Without this, a child who is simply in another room for part of the week
      // reads as having lost days.
      if ((i.also_in || []).length) {
        s += '<div class="drift-suggest mt-2">' +
          '<i class="fa-solid fa-circle-info me-1"></i>Also booked in ' +
          i.also_in.map(function (r) {
            return esc(r.name) + ' (' + r.days.map(function (d) { return DAYS[d]; }).join(', ') + ')';
          }).join(', ') +
          ' - they may need a second row on the board.</div>';
      }
      // Casual days are excluded from the comparison on purpose; say so, or the
      // Kidsoft strip looks like it is missing a day the reader can see in Kidsoft.
      if ((i.casual_days || []).length) {
        s += '<div class="drift-suggest mt-2">' +
          '<i class="fa-solid fa-circle-info me-1"></i>Plus casual ' +
          i.casual_days.map(function (d) { return DAYS[d]; }).join(', ') +
          ' - one-off days, not compared.</div>';
      }
      return s;
    }
    if (i.type === 'wrong_room') {
      var s = '<div class="drift-cmp">' +
        '<div><span class="drift-src">Board</span>' + esc(i.board_room) +
          ' &middot; ' + dayStrip(i.board_days) + '</div>' +
        i.ks_rooms.map(function (r, n) {
          return '<div><span class="drift-src">' + (n ? '' : 'Kidsoft') + '</span>' +
            esc(r.name) + ' &middot; ' + dayStrip(r.days || []) + '</div>';
        }).join('') +
        '</div>';
      if (i.split) {
        s += '<div class="drift-suggest mt-2">' +
          '<i class="fa-solid fa-circle-info me-1"></i>Kidsoft has them in ' + i.ks_rooms.length +
          ' rooms this week. The board needs one row per room.</div>';
      }
      return s;
    }
    if (i.type === 'missing_from_board') {
      var rooms = i.ks_rooms.map(function (r) { return esc(r.name); }).join(', ');
      var s = '<div class="drift-cmp"><div><span class="drift-src">Kidsoft</span>' +
        rooms + ' &middot; ' + dayStrip(i.ks_days) + '</div></div>';
      if (i.suggest_placement) {
        s += '<div class="drift-suggest mt-2">' +
          '<i class="fa-solid fa-lightbulb me-1"></i>Looks like <strong>' +
          esc(i.suggest_placement.name) + '</strong> in ' + esc(i.suggest_placement.room) +
          ' - probably a misspelling.</div>';
      }
      return s;
    }
    if (i.type === 'booked_but_not_placed') {
      return '<div class="drift-cmp">' +
        '<div><span class="drift-src">Board</span>status is <strong>' + esc(i.status) + '</strong>, holds no place</div>' +
        '<div><span class="drift-src">Kidsoft</span>' + esc(i.board_room) + ' &middot; ' + dayStrip(i.ks_days) + '</div>' +
        '</div>';
    }
    // missing_from_kidsoft
    var s = '<div class="drift-cmp">' +
      '<div><span class="drift-src">Board</span>' + esc(i.board_room) + ' &middot; ' +
      dayStrip(i.board_days) + ' &middot; ' + esc(i.status) + '</div>' +
      '<div><span class="drift-src">Kidsoft</span><span class="text-muted">' +
      (i.exited_on ? 'exited ' + fmtDate(i.exited_on) : 'no bookings') + '</span></div>' +
      '</div>';
    // Kidsoft knows this child has gone, so the seat is genuinely free. Say it
    // plainly - this is the difference between a puzzle and an action.
    if (i.exited_on) {
      s += '<div class="drift-suggest mt-2">' +
        '<i class="fa-solid fa-right-from-bracket me-1"></i>Left on ' + fmtDate(i.exited_on) +
        (i.exit_reason ? ' (' + esc(i.exit_reason) + ')' : '') +
        '. Removing them frees the place.' +
        (i.exit_source === 'name'
          ? ' <span class="text-muted">Matched by name, not linked.</span>' : '') +
        '</div>';
    } else if (i.expected) {
      s += '<div class="small text-muted mt-1">Care is ending, so this is expected.</div>';
    }
    return s;
  }

  /** Resolutions offered per type. Everything here edits OUR board only. */
  function fixes(i, idx) {
    var b = [];
    if (i.type === 'day_mismatch') {
      b.push('<button class="btn btn-sm btn-primary fix-days" data-i="' + idx + '">' +
             'Use Kidsoft days</button>');
    }
    if (i.type === 'wrong_room' && i.ks_rooms.length && i.ks_rooms[0].room_id) {
      if (i.split) {
        // A move would be wrong here - they are genuinely in both rooms. Offer
        // to rebuild the board to match Kidsoft exactly: correct the existing
        // row's days and add a row for each additional room.
        b.push('<button class="btn btn-sm btn-primary fix-split" data-i="' + idx + '">' +
               'Match Kidsoft (' + i.ks_rooms.length + ' rows)</button>');
      } else {
        b.push('<button class="btn btn-sm btn-primary fix-room" data-i="' + idx + '">' +
               'Move to ' + esc(i.ks_rooms[0].name) + '</button>');
      }
    }
    if (i.type === 'missing_from_board') {
      if (i.suggest_placement) {
        b.push('<button class="btn btn-sm btn-primary fix-link" data-i="' + idx + '">' +
               'Same child - link</button>');
      }
      if (i.room_id) {
        b.push('<button class="btn btn-sm btn-outline-primary fix-add" data-i="' + idx + '">' +
               'Add to board</button>');
      } else {
        b.push('<span class="small text-danger">Kidsoft room is not mapped - ' +
               '<a href="/rooms">map it first</a>.</span>');
      }
    }
    if (i.type === 'booked_but_not_placed') {
      b.push('<button class="btn btn-sm btn-primary fix-confirm" data-i="' + idx + '">' +
             'Mark confirmed</button>');
    }
    if (i.placement_id) {
      b.push('<a class="btn btn-sm btn-outline-secondary" href="/?centre=' + centreId() +
             '&year=' + data.year + '">Open board</a>');
    }
    return b.join(' ');
  }

  /**
   * Populating a board means adding every attending child, and one modal each is
   * why a tool like this gets abandoned. Only offered where there is genuinely
   * nothing to decide - rows with a name suggestion or an unmapped room are
   * excluded from the count and left for a human.
   */
  function bulkButton(type, rows) {
    if (type !== 'missing_from_board') return '';
    var n = rows.filter(function (i) { return i.room_id && !i.suggest_placement; }).length;
    if (n < 2) return '';
    return '<button class="btn btn-sm btn-primary ms-auto" id="add-all">' +
           'Add all ' + n + ' to the ' + data.year + ' board</button>';
  }

  function render() {
    if (!data.issues.length) {
      $('#drift-list').html(
        '<div class="card"><div class="card-body text-center py-5">' +
        '<div class="fs-1 text-success mb-2"><i class="fa-solid fa-circle-check"></i></div>' +
        '<div class="fs-5">The board and Kidsoft agree.</div>' +
        '<div class="text-muted">' + data.board_size + ' placements checked against ' +
        data.kidsoft_size + ' children booked this week.</div>' +
        '</div></div>');
      return;
    }

    // Group by type so the same kind of problem is handled in one pass.
    var byType = {};
    data.issues.forEach(function (i, idx) {
      i._idx = idx;
      (byType[i.type] = byType[i.type] || []).push(i);
    });

    $('#drift-list').html(Object.keys(byType).map(function (t) {
      var meta = data.types[t] || { label: t, help: '' };
      return '<div class="card mb-3">' +
        '<div class="card-header bg-body-tertiary d-flex align-items-center">' +
          '<span class="badge text-bg-' + (TYPE_CLS[t] || 'secondary') + ' me-2">' +
          byType[t].length + '</span>' +
          '<span class="fw-semibold">' + esc(meta.label) + '</span>' +
          '<span class="small text-muted ms-3">' + esc(meta.help) + '</span>' +
          bulkButton(t, byType[t]) +
        '</div>' +
        '<div class="list-group list-group-flush">' +
        byType[t].map(function (i) {
          return '<div class="list-group-item drift-row">' +
            '<div class="d-flex flex-wrap justify-content-between gap-3">' +
              '<div class="flex-grow-1">' +
                '<div class="fw-semibold">' + esc(i.child) +
                  (i.confidence === 'name'
                    ? ' <span class="badge text-bg-light border" title="Matched by name, not linked to a Kidsoft id">by name</span>'
                    : '') +
                  (i.confidence === 'nickname'
                    ? ' <span class="badge text-bg-light border" title="Matched to the nickname Kidsoft holds for this child, not linked to a Kidsoft id">by nickname</span>'
                    : '') +
                '</div>' + detail(i) +
              '</div>' +
              '<div class="text-nowrap align-self-center">' + fixes(i, i._idx) + '</div>' +
            '</div></div>';
        }).join('') +
        '</div></div>';
    }).join(''));
  }

  function renderSummary() {
    function tile(label, value, hint, cls) {
      return '<div class="col-6 col-lg-3"><div class="card h-100"><div class="card-body py-3">' +
        '<div class="text-muted small">' + label + '</div>' +
        '<div class="fs-3 fw-semibold ' + (cls || '') + '">' + value + '</div>' +
        '<div class="small text-muted">' + hint + '</div></div></div></div>';
    }
    var s = data.summary;
    var costly = s.booked_but_not_placed + s.missing_from_board;
    $('#drift-summary').html(
      tile('Disagreements', data.total, 'this week',
           data.total ? 'text-danger' : 'text-success') +
      tile('Needs attention first', costly, 'attending but unplanned',
           costly ? 'text-danger' : 'text-success') +
      tile('On the board', data.board_size, 'placements for ' + data.year) +
      tile('In Kidsoft', data.kidsoft_size, 'children booked this week')
    );
  }

  function load(refresh) {
    $('#drift-list').html('<div class="text-center text-muted py-5">Comparing...</div>');
    $('#week-label').text('Week beginning ' + fmtDate(monday));
    var p = { centre_id: centreId(), week: monday };
    if (refresh) p.refresh = 1;

    $.getJSON('/api/drift', p)
      .done(function (res) {
        if (!res.ok) {
          $('#drift-list').html('<div class="text-center text-danger py-5">' + esc(res.error) + '</div>');
          $('#drift-summary').empty();
          return;
        }
        data = res;
        render();
        renderSummary();
        if (res.spans_years) {
          // The comparison is clipped to $year rather than guessed at, so say
          // plainly which days were not checked and where to check them.
          toast('This week crosses into ' + res.other_year + '. Only the ' + res.year +
                ' days were checked (' +
                res.dropped_days.map(function (d) { return DAYS[d]; }).join(', ') +
                ' belongs to the ' + res.other_year + ' board - use the next-week arrow).', 'warning');
        }
      })
      .fail(function () {
        $('#drift-list').html('<div class="text-center text-danger py-5">Could not compare.</div>');
      });
  }

  // --- Resolutions -----------------------------------------------------------

  /**
   * Apply one fix. The clicked button is disabled for the duration: without it a
   * double-click fires two requests, and for add_from_kidsoft that used to race
   * into duplicate board rows.
   */
  function post(payload, okMsg, btn) {
    var $b = $(btn).prop('disabled', true);
    $.post('/api/drift', payload)
      .done(function (res) {
        if (!res.ok) { toast(res.error, 'danger'); $b.prop('disabled', false); return; }
        toast(okMsg);
        load(false);
      })
      .fail(function () {
        toast('Could not apply that fix.', 'danger');
        $b.prop('disabled', false);
      });
  }

  function issueAt(el) { return data.issues[parseInt($(el).data('i'), 10)]; }

  $('#drift-list').on('click', '.fix-days', function () {
    var i = issueAt(this);
    post({ action: 'accept_days', placement_id: i.placement_id, days: i.ks_days },
         'Board updated to match Kidsoft.', this);
  });

  $('#drift-list').on('click', '.fix-room', function () {
    var i = issueAt(this);
    post({ action: 'accept_room', placement_id: i.placement_id, room_id: i.ks_rooms[0].room_id },
         'Moved on the board.', this);
  });

  /**
   * Rebuild a split child's board rows to match Kidsoft: the existing row is
   * moved to the first Kidsoft room with its days, and a row is added for each
   * other room. Sequential because each step must land before the next; a
   * failure part-way leaves the earlier rows applied and the drift still shown,
   * which is recoverable.
   */
  $('#drift-list').on('click', '.fix-split', function () {
    var i = issueAt(this);
    var $btn = $(this).prop('disabled', true);
    var reenable = function () { $btn.prop('disabled', false); };
    var rooms = i.ks_rooms.filter(function (r) { return r.room_id; });
    if (rooms.length < i.ks_rooms.length &&
        !confirm('One of the Kidsoft rooms is not mapped, so it will be skipped. Continue?')) {
      reenable(); return;
    }
    if (!rooms.length) {
      toast('None of those Kidsoft rooms are mapped.', 'danger'); reenable(); return;
    }

    var first = rooms[0];
    var firstName = i.first_name, lastName = i.last_name;

    $.post('/api/drift', { action: 'accept_room', placement_id: i.placement_id, room_id: first.room_id })
      .then(function (res) {
        if (!res.ok) return $.Deferred().reject(res.error);
        return $.post('/api/drift', {
          action: 'accept_days', placement_id: i.placement_id, days: first.days
        });
      })
      .then(function (res) {
        if (!res.ok) return $.Deferred().reject(res.error);
        var chain = $.Deferred().resolve().promise();
        rooms.slice(1).forEach(function (r) {
          chain = chain.then(function () {
            return $.post('/api/drift', {
              action: 'add_from_kidsoft', room_id: r.room_id, year: data.year,
              kidsoft_child_id: i.kidsoft_child_id, child_dob: i.child_dob || '',
              child_first_name: firstName, child_last_name: lastName, days: r.days
            }).then(function (res2) {
              return res2.ok ? res2 : $.Deferred().reject(res2.error);
            });
          });
        });
        return chain;
      })
      .done(function () { toast('Board now matches Kidsoft.'); load(false); })
      .fail(function (err) {
        // Steps that already landed are idempotent server-side, so clicking
        // again resumes rather than duplicating.
        toast((typeof err === 'string' ? err : 'Could not finish that fix.') +
              ' Nothing was duplicated - you can click it again to resume.', 'danger');
        reenable();
        load(false);
      });
  });

  $('#drift-list').on('click', '.fix-link', function () {
    var i = issueAt(this);
    // Linking asserts two records are the same child, so make it explicit.
    if (!confirm('Link Kidsoft\'s "' + i.child + '" to the board row "' +
                 i.suggest_placement.name + '"?\n\nThey will match by id from now on, whatever the spelling.')) return;
    post({ action: 'link', placement_id: i.suggest_placement.id, kidsoft_child_id: i.kidsoft_child_id },
         'Linked. This will not come back.', this);
  });

  $('#drift-list').on('click', '.fix-add', function () {
    var i = issueAt(this);
    post({
      action: 'add_from_kidsoft',
      room_id: i.room_id,
      year: data.year,
      kidsoft_child_id: i.kidsoft_child_id,
      // Discrete fields from Kidsoft - splitting the display name on the first
      // space would mangle "Mary Jane Smith".
      child_first_name: i.first_name,
      child_last_name: i.last_name,
      child_dob: i.dob || '',
      days: i.ks_days
    }, 'Added to the board.', this);
  });

  $('#drift-list').on('click', '.fix-confirm', function () {
    var i = issueAt(this);
    var $b = $(this).prop('disabled', true);
    $.post('/api/board', { action: 'set_status', id: i.placement_id, status: 'confirmed' })
      .done(function (res) {
        if (!res.ok) { toast(res.error, 'danger'); $b.prop('disabled', false); return; }
        toast('Marked confirmed.');
        load(false);
      })
      .fail(function () { toast('Could not apply that fix.', 'danger'); $b.prop('disabled', false); });
  });

  $('#drift-list').on('click', '#add-all', function () {
    var n = $(this).text().replace(/\D+/g, ' ').trim().split(' ')[0];
    if (!confirm('Add ' + n + ' children to the ' + data.year + ' board?\n\n' +
                 'Room and days come straight from Kidsoft, and they are marked Confirmed ' +
                 'because they are already attending. Anyone who looks like a misspelling ' +
                 'of someone already on the board is skipped.')) return;
    var $b = $(this).prop('disabled', true).text('Adding...');
    $.post('/api/drift', { action: 'add_all_from_kidsoft', centre_id: centreId(), week: monday })
      .done(function (res) {
        if (!res.ok) { toast(res.error, 'danger'); $b.prop('disabled', false); return; }
        toast(res.message);
        load(false);
      })
      .fail(function () { toast('Could not add them.', 'danger'); $b.prop('disabled', false); });
  });

  $('#centre-select').on('change', function () { load(false); });
  $('#week-prev').on('click', function () { monday = shiftDays(monday, -7); load(false); });
  $('#week-next').on('click', function () { monday = shiftDays(monday, 7); load(false); });
  $('#week-this').on('click', function () { monday = $('#monday').val(); load(false); });
  $('#refresh').on('click', function () { load(true); });

  load(false);
})(jQuery);
