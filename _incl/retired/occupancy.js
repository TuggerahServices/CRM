// Occupancy grid: rooms down, weekdays across, vacancies as the headline number.

(function ($) {
  'use strict';

  var DAYS = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri'];
  var $table = $('#occ-table');
  var monday = $('#monday').val();

  function centreId() { return parseInt($('#centre-select').val(), 10); }

  /** Shift a Y-m-d string by whole days without touching the browser timezone. */
  function shiftDays(ymd, n) {
    var p = ymd.split('-');
    var d = new Date(Date.UTC(+p[0], +p[1] - 1, +p[2]));
    d.setUTCDate(d.getUTCDate() + n);
    return d.toISOString().slice(0, 10);
  }

  /** Classify a cell. The business goal is 100% full, so vacancies are the problem. */
  function cellClass(c) {
    if (c.capacity === 0) return 'occ-nocap';
    if (c.over) return 'occ-over';
    if (c.vacancies === 0) return 'occ-full';
    if (c.vacancies <= 2) return 'occ-low';
    return 'occ-high';
  }

  function cellHtml(c) {
    if (c.capacity === 0) {
      return '<td class="occ-cell occ-nocap" title="No capacity set for this room">' +
             '<div class="occ-n">?</div><div class="occ-sub">' + c.occupied + ' booked</div></td>';
    }
    var main, sub = c.occupied + '/' + c.capacity;
    if (c.over) {
      main = '+' + (c.occupied - c.capacity);
      sub = sub + ' over';
    } else if (c.vacancies === 0) {
      main = '<i class="fa-solid fa-check"></i>';
    } else {
      main = c.vacancies;
    }
    var quota = (c.subquotas || []).filter(function (q) { return q.full; });
    var flag = quota.length
      ? ' <i class="fa-solid fa-lock occ-quota" title="' +
        esc(quota.map(function (q) { return q.label + ' full (' + q.used + '/' + q.max + ')'; }).join(', ')) +
        '"></i>'
      : '';
    var tip = (c.subquotas || []).map(function (q) {
      return q.label + ' ' + q.used + '/' + q.max;
    }).join(', ');

    return '<td class="occ-cell ' + cellClass(c) + '"' + (tip ? ' title="' + esc(tip) + '"' : '') + '>' +
           '<div class="occ-n">' + main + flag + '</div>' +
           '<div class="occ-sub">' + sub + '</div></td>';
  }

  function ageText(r) {
    if (r.min_age_months === null && r.max_age_months === null) return '';
    var lo = r.min_age_months === null ? '0' : r.min_age_months;
    var hi = r.max_age_months === null ? '+' : r.max_age_months;
    return '<div class="small text-muted">' + esc(lo + '-' + hi + ' months') + '</div>';
  }

  function render(d) {
    var head = '<tr><th style="min-width:15rem">Room</th>' +
      DAYS.map(function (name, i) {
        return '<th class="text-center">' + name +
               '<div class="small fw-normal text-muted">' + fmtDate(d.dates[i + 1]) + '</div></th>';
      }).join('') +
      '<th class="text-center" style="width:8rem">Free this week</th></tr>';
    $table.find('thead').html(head);

    if (!d.rooms.length) {
      $table.find('tbody').html('<tr><td colspan="7" class="text-center text-muted py-5">' +
        'No active rooms at this centre. <a href="/rooms">Set them up</a>.</td></tr>');
      $table.find('tfoot').empty();
      return;
    }

    $table.find('tbody').html(d.rooms.map(function (r) {
      var warn = r.linked ? '' :
        ' <i class="fa-solid fa-link-slash text-danger ms-1" title="Not linked to Kidsoft - no bookings counted"></i>';
      return '<tr><th class="fw-semibold">' + esc(r.name) + warn + ageText(r) + '</th>' +
        [1, 2, 3, 4, 5].map(function (wd) { return cellHtml(r.cells[wd]); }).join('') +
        '<td class="text-center fs-5 ' + (r.week_vacancies ? 'text-danger fw-semibold' : 'text-success') + '">' +
        r.week_vacancies + '</td></tr>';
    }).join(''));

    var weekFree = 0;
    $table.find('tfoot').html('<tr><th>All rooms</th>' +
      [1, 2, 3, 4, 5].map(function (wd) {
        var t = d.totals[wd];
        weekFree += t.vacancies;
        return '<td class="text-center"><div class="fw-semibold">' + t.occupied + '/' + t.capacity + '</div>' +
               '<div class="small text-muted">' + (t.pct === null ? '-' : t.pct + '%') + '</div></td>';
      }).join('') +
      '<th class="text-center fs-5">' + weekFree + '</th></tr>');
  }

  function renderSummary(d) {
    var occ = 0, cap = 0, over = 0, nocap = 0, worstDay = null;
    [1, 2, 3, 4, 5].forEach(function (wd) {
      var t = d.totals[wd];
      occ += t.occupied; cap += t.capacity;
      if (worstDay === null || t.vacancies > d.totals[worstDay].vacancies) worstDay = wd;
    });
    d.rooms.forEach(function (r) {
      [1, 2, 3, 4, 5].forEach(function (wd) {
        if (r.cells[wd].over) over++;
        if (r.cells[wd].capacity === 0) nocap++;
      });
    });
    var pct = cap ? Math.round(occ / cap * 100) : 0;
    var free = Math.max(0, cap - occ);

    function tile(label, value, hint, cls) {
      return '<div class="col-6 col-lg-3"><div class="card h-100"><div class="card-body py-3">' +
        '<div class="text-muted small">' + label + '</div>' +
        '<div class="fs-3 fw-semibold ' + (cls || '') + '">' + value + '</div>' +
        '<div class="small text-muted">' + hint + '</div></div></div></div>';
    }

    var issues = [];
    if (over) issues.push(over + ' over capacity');
    if (nocap) issues.push(nocap + ' without capacity');

    $('#occ-summary').html(
      tile('Occupancy', pct + '%', occ + ' of ' + cap + ' places',
           pct >= 98 ? 'text-success' : (pct >= 90 ? 'text-warning' : 'text-danger')) +
      tile('Places free', free, 'Across the whole week', free ? 'text-danger' : 'text-success') +
      tile('Emptiest day', worstDay ? DAYS[worstDay - 1] : '-',
           worstDay ? d.totals[worstDay].vacancies + ' free' : '') +
      tile('Needs attention', issues.length ? issues.length : 0,
           issues.length ? esc(issues.join(', ')) : 'Nothing flagged',
           issues.length ? 'text-danger' : 'text-success')
    );
  }

  function load(refresh) {
    $table.find('tbody').html('<tr><td colspan="7" class="text-center text-muted py-5">Loading...</td></tr>');
    $('#week-label').text('Week beginning ' + fmtDate(monday));
    var params = { centre_id: centreId(), week: monday };
    if (refresh) params.refresh = 1;

    $.getJSON('/api/occupancy', params)
      .done(function (res) {
        if (!res.ok) {
          $table.find('tbody').html('<tr><td colspan="7" class="text-center text-danger py-5">' +
            esc(res.error) + '</td></tr>');
          $('#occ-summary').empty();
          return;
        }
        render(res);
        renderSummary(res);
      })
      .fail(function () {
        $table.find('tbody').html('<tr><td colspan="7" class="text-center text-danger py-5">' +
          'Could not load occupancy.</td></tr>');
      });
  }

  $('#centre-select').on('change', function () { load(false); });
  $('#week-prev').on('click', function () { monday = shiftDays(monday, -7); load(false); });
  $('#week-next').on('click', function () { monday = shiftDays(monday, 7); load(false); });
  $('#week-this').on('click', function () { monday = $('#monday').val(); load(false); });
  $('#refresh').on('click', function () { load(true); });

  load(false);
})(jQuery);
