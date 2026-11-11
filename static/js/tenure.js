// Tenure - the ladder for one centre, or movement across the whole group.
//
// THE VISUAL ARGUMENT: in the ideal year the only arrows are "up from below" and
// "up to next", plus babies entering at the bottom and school leavers going off
// the top. So expected movement is drawn quietly, in muted type, and only the
// cells that represent churn carry colour. A room whose row is all grey is
// behaving perfectly.
//
// Two things stopped that argument being legible and are fixed here (17 Aug
// 2026), both found by reading the rendered table rather than the code:
//
//   * "Left" over the changeover was ONE cell holding school leavers plus real
//     departures, coloured by whichever was bigger. Tuggerah's Amazing Asteroids
//     Silver showed a quiet grey 25 that was 23 school leavers hiding 2 families
//     who left - the exact departures this screen exists to surface. They are
//     two columns now, "To school" and "Left".
//   * The bottom room's intake was painted as the worst cell on the screen; see
//     intake() below.

(function ($) {
  'use strict';

  var $init  = $('#init-centre');
  var CENTRE = parseInt($init.data('centre'), 10) || 0;
  var LADDER_COLS = 10;
  var OVERVIEW_COLS = 6;

  var yn = yearNav('#year-nav', function (y) {
    // A real navigation, like every other screen - keeps the session year, the
    // URL, the sidebar and the centre tabs all telling one story.
    window.location = '/tenure?centre=' + CENTRE + '&year=' + y;
  });

  /**
   * How much of this room's movement was churn rather than progression.
   *
   * Same five-step idea as heat() on the Board, inverted: here a big number is
   * bad. The bands are on the count of unexpected movements per room, not a
   * percentage, because a room of 15 and a room of 50 both feel four departures
   * as four conversations.
   */
  function movementClass(n) {
    if (!n)      return 'tn-none';
    if (n <= 2)  return 'tn-low';
    if (n <= 5)  return 'tn-mid';
    if (n <= 9)  return 'tn-high';
    return 'tn-vhigh';
  }

  /**
   * One figure.
   *
   * `rule` is the column-group hairline (see the thead in tenure.php) and must
   * be carried by EVERY cell in that column, zero or not - a rule that stops at
   * the first empty cell is worse than no rule at all.
   */
  function num(n, cls, rule) {
    var c = 'text-end ' + (rule ? rule + ' ' : '');
    if (!n) return '<td class="' + c + 'tn-zero">.</td>';
    return '<td class="' + c + (cls || '') + '">' + n + '</td>';
  }

  function ageBand(r) {
    if (r.min_age === null && r.max_age === null) return '';
    var lo = r.min_age === null ? '0' : r.min_age;
    var hi = r.max_age === null ? '+' : r.max_age;
    return '<div class="small text-muted">' + esc(lo + '-' + hi + ' months') + '</div>';
  }

  // --- Centre view -----------------------------------------------------------

  function renderTiles(d) {
    var t = d.totals;
    var tiles = [];

    // A centre that opened, closed or is still filling has a turnover figure that
    // is arithmetic rather than a finding, so the number is withheld instead of
    // being printed next to a paragraph explaining itself away.
    var showTurnover = d.steady && t.turnover !== null;
    // A YEAR STILL RUNNING COVERS LESS TIME. The readings are quarterly, so the
    // current year has only the ones that have happened - and an unlabelled
    // year-to-date figure printed where a full-year one usually sits reads as a
    // centre that has got dramatically worse. The label carries the span; there
    // is no banner, because the team knows perfectly well what month it is.
    //
    // pctText() FOR THE SHAPE, NEVER fillPct() FOR THE VALUE. Turnover already
    // arrives with its decimal from tenure.php; all this does is stop `23.0 +
    // '%'` printing as "23%" in a column of 26.8s (4 of 20 live centre-years land
    // on a whole number). It must NOT go through fillPct(): that helper holds a
    // figure below 100 because 100% means "no places left", and turnover has no
    // such ceiling - Kanwal's opening year reads 234.7% and is telling the truth.
    tiles.push(tile(d.partial ? 'Turnover so far' : 'Turnover',
      showTurnover ? pctText(t.turnover) : '--',
      showTurnover
        ? t.movements + ' movements against a roll of ' + Math.round(t.avg_roll) +
          (d.partial && d.span ? ' (' + d.span + ')' : '')
        : (d.not_steady_reason || 'not comparable this year'),
      showTurnover ? movementClass(Math.round(t.turnover / 4)) : ''));

    tiles.push(tile('Left during the year', t.out_left,
      t.out_moved_centre ? t.out_moved_centre + ' more moved to another centre' : 'not counting school leavers',
      movementClass(t.out_left)));

    tiles.push(tile('Joined during the year', t.in_new,
      'places sold again mid-year', movementClass(t.in_new)));

    // THE RATIO THAT STATES THE GAP FROM THE IDEAL. In the perfect year every
    // arrival from outside walks into the bottom room; anyone joining higher up
    // is backfilling a place churn opened.
    var above = t.in_new_above + t.b_in_new_above;
    var newTotal = t.in_new + t.b_in_new;
    tiles.push(tile('Entered above the bottom room', newTotal ? Math.round(100 * above / newTotal) + '%' : '--',
      above + ' of ' + newTotal + ' new children started part-way up the ladder',
      newTotal && (above / newTotal) > 0.5 ? 'tn-high' : 'tn-low'));

    $('#tiles').html(tiles.join(''));
  }

  /**
   * THE SAME STAT TILE AS EVERY OTHER SCREEN, and it used not to be.
   *
   * This screen had its own: the NUMBER above the LABEL, the number at 1.9rem
   * bold in brand purple, the label in a semibold face. Every other module put
   * the label first at .875rem muted with the value at fs-3 - so the same
   * component read as two different components depending on which screen you
   * had open, which is exactly the inconsistency Broc called out. Four modules
   * agreed and this one did not, so this one moved.
   *
   * The severity still shows, but through the app's shared status classes rather
   * than a private .tn-tile ramp, so an amber tile here is the same amber as an
   * amber tile on the Dashboard. movementClass()'s five steps stay as they are
   * for the TABLE cells, where they are a real heat scale.
   */
  function tile(label, big, sub, cls) {
    return window.statTile(label, big, sub, tileSeverity(cls), 'col-sm-6 col-xl-3');
  }

  /** A heat-scale step -> the shared status class for a figure. */
  function tileSeverity(cls) {
    if (cls === 'tn-high' || cls === 'tn-vhigh') return 'text-danger';
    if (cls === 'tn-mid') return 'text-warning';
    return '';
  }

  function renderLadder(d) {
    var $b = $('#ladder-body').empty();

    if (!d.rooms.length) {
      $b.html('<tr><td colspan="' + LADDER_COLS + '" class="p-0">' +
        emptyState('fa-arrow-up-long', d.note || 'Nothing to show for this year',
                   'Kidsoft has no bookings for this centre in ' + d.year + '.') + '</td></tr>');
      return;
    }

    // Rooms come back bottom of the ladder first; render them that way so the
    // eye follows children upward through the year. Index 0 IS the bottom room,
    // the same one tenure_year() measures "entered above the bottom room"
    // against - see below for why that matters to the colouring.
    d.rooms.forEach(function (r, i) {
      var roll = r.headcount[d.samples[d.samples.length - 1]] || 0;
      var tip = r.left_names.length
        ? 'Left: ' + r.left_names.join(', ') + (r.out_left > r.left_names.length ? ', and more' : '')
        : '';

      $b.append(
        '<tr>' +
        '<th class="tn-room"><div class="fw-semibold">' + roomIcon(r.name) + esc(r.name) + '</div>' + ageBand(r) + '</th>' +
        '<td class="text-end">' + roll + '</td>' +
        num(r.b_in_up, 'tn-expected', 'tn-sep') +
        num(r.b_in_new, intake(r.b_in_new, i)) +
        num(r.b_out_up, 'tn-expected', 'tn-mid-rule') +
        num(r.b_out_aged, 'tn-expected') +
        num(r.b_out_left, movementClass(r.b_out_left)) +
        num(r.in_new, intake(r.in_new, i), 'tn-sep') +
        '<td class="text-end tn-mid-rule ' + (r.out_left ? movementClass(r.out_left) : 'tn-zero') + '"' +
          (tip ? ' title="' + esc(tip) + '"' : '') + '>' + (r.out_left || '.') + '</td>' +
        num(r.out_moved_centre, 'tn-expected') +
        '</tr>'
      );
    });

    $b.append(totalsRow(d));
  }

  /**
   * ARRIVING AT THE BOTTOM OF THE LADDER IS THE IDEAL, NOT THE PROBLEM.
   *
   * This file's own header has always said so - "babies entering at the bottom"
   * listed alongside progression as expected movement - and the code did the
   * opposite: Tuggerah's 15 babies starting in the bottom room in February came
   * out as tn-vhigh, the loudest cell on the screen, while the tile beside it
   * counted the same 15 as children who correctly "started at the beginning".
   * The table and the tile were arguing with each other about the same number.
   *
   * A place filled from outside anywhere ABOVE the bottom room is backfilling
   * something churn opened, and that is what carries colour.
   */
  function intake(n, roomIndex) {
    return roomIndex === 0 ? 'tn-expected' : movementClass(n);
  }

  /**
   * ANCHOR EACH COLUMN. Ten columns of small numbers with two of them called
   * "Left" is a table you have to re-read the header for on every row; a total
   * says what the column is counting for the whole centre and gives the eye
   * somewhere to land. Pinned to the bottom of the scroller, the same way the
   * placement board pins its free-places row.
   *
   * No heat here: a centre total is in the top band by construction, so colour
   * on it would say nothing except that the centre is bigger than a room.
   */
  function totalsRow(d) {
    var t = d.totals;
    var roll = d.counts[d.samples[d.samples.length - 1]] || 0;
    function cell(n, rule) {
      return '<td class="text-end' + (rule ? ' ' + rule : '') + (n ? '' : ' tn-zero') + '">' +
        (n || '.') + '</td>';
    }
    return '<tr class="grid-totals">' +
      '<th class="tn-room">All rooms</th>' +
      '<td class="text-end">' + roll + '</td>' +
      cell(t.b_in_up, 'tn-sep') + cell(t.b_in_new) +
      cell(t.b_out_up, 'tn-mid-rule') + cell(t.b_out_aged) + cell(t.b_out_left) +
      cell(t.in_new, 'tn-sep') + cell(t.out_left, 'tn-mid-rule') + cell(t.out_moved_centre) +
      '</tr>';
  }

  /**
   * STARTED AND LEFT BETWEEN READINGS - every child the quarterly readings would
   * have missed, with what bears on why. They are already counted in the
   * ladder's Joined and Left; this is the list behind those, and the card stays
   * hidden when nobody is in it.
   *
   * THE DAY STRIP IS THE ARGUMENT. Measured when this was built (29 Oct 2026),
   * 5 of 10 families had not been given every day they asked for, so asked-and-given is green, asked-and-not-given is the waitlist's own
   * blue, and given-but-not-asked keeps the ring. With no application to
   * compare against the days are named and nothing more is claimed.
   */
  function renderShort(d) {
    var rows = d.short_stays || [];
    $('#short-card').toggleClass('d-none', !rows.length);
    if (!rows.length) return;

    var PAST = { hasSaid: 'asked for and given', wantSaid: 'asked for, not given',
                 extraSaid: 'given, not asked for', offSaid: 'not asked for and not given', wide: true };

    $('#short-body').html(rows.map(function (s) {
      var strip;
      if (s.asked) {
        strip = dayStrip($.extend({
          has:   s.days.filter(function (x) { return s.asked.indexOf(x) !== -1; }),
          want:  s.asked.filter(function (x) { return s.days.indexOf(x) === -1; }),
          extra: s.days.filter(function (x) { return s.asked.indexOf(x) === -1; })
        }, PAST));
      } else {
        strip = dayStrip({ plain: s.days, plainSaid: 'booked', wide: true });
      }
      var reason = s.went === 'moved'
        ? 'Moved to ' + esc(s.moved_to || 'another centre')
        : (s.reason ? esc(s.reason) : '<span class="text-muted">--</span>');

      return '<tr>' +
        // THE NAME OPENS THE CHILD (6 Nov 2026): who the family was, their
        // parents, and whether a brother or sister is still here or waiting -
        // the first things anybody asks of a stay this short.
        '<th class="fw-semibold">' + childName({ centre_id: CENTRE, ks_id: s.ks_id, name: s.name }) + '</th>' +
        '<td>' + roomIcon(s.room) + esc(s.room) + '</td>' +
        '<td>' + strip + '</td>' +
        '<td class="text-nowrap">' + esc(s.first === s.last ? fmtDate(s.first) : fmtDate(s.first) + ' to ' + fmtDate(s.last)) +
          '<div class="small text-muted">' + esc(duration(s.stayed_days)) + '</div></td>' +
        '<td class="text-end text-nowrap">' + s.came + ' of ' + s.booked + '</td>' +
        '<td class="text-end text-nowrap"' + (s.applied ? ' title="Applied ' + esc(fmtDate(s.applied)) + '"' : '') + '>' +
          (s.waited_days === null ? '<span class="text-muted">--</span>' : esc(duration(s.waited_days))) + '</td>' +
        '<td>' + reason + '</td>' +
        '</tr>';
    }).join(''));
  }

  /** A count of days, as somebody would say it. Arithmetic on a number; no Date. */
  function duration(n) {
    if (n === null || n === undefined) return '';
    if (n < 14)  return n + (n === 1 ? ' day' : ' days');
    if (n < 61)  return Math.round(n / 7) + ' weeks';
    var m = Math.round(n / 30.44);
    return m + (m === 1 ? ' month' : ' months');
  }

  function renderBanner(d) {
    // ONLY the failure the team cannot see for themselves. A centre being new,
    // closed or still filling is something everyone here already knows, and
    // spelling it out on screen is noise - the figures are withheld instead.
    var bits = [];
    if (d.degraded && d.degraded.length) {
      bits.push('<div class="alert alert-warning mb-3"><i class="fa-solid fa-plug-circle-xmark me-2"></i>' +
        'Kidsoft would not answer for ' + esc(d.degraded.join(', ')) +
        ', so a family who transferred there may be counted as having left.</div>');
    }
    $('#banner').html(bits.join(''));
  }

  function loadCentre() {
    $('#ladder-body').html(spinnerRow(LADDER_COLS, 'Reading Kidsoft...'));
    $.getJSON('/api/tenure', { action: 'year', centre_id: CENTRE, year: yearNow() })
      .done(function (res) {
        // nginx discards the body of a real 4xx under /api/, so readable failures
        // arrive as HTTP 200 with ok:false. Every caller must check it.
        if (!res.ok) {
          $('#ladder-body').html('<tr><td colspan="' + LADDER_COLS + '" class="p-0">' +
            emptyState('fa-plug-circle-xmark', 'Could not load tenure', res.error) + '</td></tr>');
          return;
        }
        yn.set(res.year, res.years);
        // NOT OPEN YET: no children, so no movement, so no ladder. Everything on
        // this screen describes churn in a year the centre did not exist for,
        // down to the table's own headings - so the card is replaced rather than
        // filled with dashes, and the refresh button goes with it because there
        // is nothing behind it to re-read.
        if (res.not_open) {
          $('#banner, #tiles').empty();
          $('#refresh').addClass('d-none');
          // NO "look at 2027 instead" HERE, and that is not an oversight. This
          // screen's stepper deliberately stops at the current year - churn is
          // measured from bookings that have already happened, so a future year
          // is not a year it can answer either. A button offering one would be
          // the only control in the app that navigates somewhere it knows is
          // empty. notOpenState() draws it only when a caller gives it an href.
          $('#ladder').closest('.card').html('<div class="card-body pb-0">' +
            window.notOpenState(res.not_open, { what: 'tenure' }) + '</div>');
          return;
        }
        $('#bhead').text(res.boundary ? '(' + res.boundary.from + ' to ' + res.boundary.to + ')' : '');
        $('#ladder-sub').text(res.samples.length + ' readings: ' + res.samples.join(', '));
        renderBanner(res);
        renderTiles(res);
        renderLadder(res);
        renderShort(res);
      })
      .fail(function () {
        $('#ladder-body').html('<tr><td colspan="' + LADDER_COLS + '" class="p-0">' +
          emptyState('fa-plug-circle-xmark', 'Could not load tenure', 'The request failed.') + '</td></tr>');
      });
  }

  // --- Group view ------------------------------------------------------------

  /**
   * One centre's two bars.
   *
   * A GROUPED BAR, NOT A DIVERGING ONE. Diverging encodes polarity - good one
   * way, bad the other - and neither of these is good: the organisation wants
   * arrivals and departures BOTH at zero. So they are two categories on a shared
   * baseline, and total ink reads as gross movement.
   *
   * Scaled to `max` across the comparable centres, so the same length means the
   * same number of children on every row. Nothing is labelled inside a bar --
   * the figures sit in the columns beside it and in the tooltip, and a number on
   * every mark is noise.
   *
   * THE GEOMETRY IS THE SHARED ONE (.mk-track / .mk-bar, the MARKS block in
   * app.css), which is what the Dashboard's group tables draw with. This file
   * held a third private copy of it - same idea, its own height, radius and
   * gap - so the same kind of mark on the same kind of ranked centre table
   * looked like a different component depending on which screen you were on.
   * Only the FILL is still local: two series of equal standing take the
   * identity pair, and tenure is the one legitimate user of it.
   */
  function bars(t, max, centre) {
    var roll = t.avg_roll || 0;
    function bar(n, cls, what) {
      // Share of the roll, not a headcount - see bar_max in _incl/tenure.php for
      // why. A non-zero value never renders as nothing: floor it at a visible
      // sliver, or "1 child left" and "none left" look identical.
      var rate = roll > 0 ? (n / roll) * 100 : 0;
      var pct  = max > 0 ? Math.max(2, (rate / max) * 100) : 0;
      // THE TITLE IS ON THE TRACK, NOT ON THE FILL. A zero draws no bar at all
      // (the same rule markBar() follows - min-width on an empty mark is a
      // 3px hairline that reads as a value), and a bar that is not there
      // cannot be hovered, so the row would lose its tooltip exactly where the
      // figure needs explaining.
      return '<span class="mk-track" title="' +
        esc(centre + ': ' + n + ' ' + what + ' - ' + rate.toFixed(1) +
            '% of a roll of ' + Math.round(roll)) + '">' +
        (n > 0 ? '<span class="mk-bar ' + cls + '" style="width:' + pct.toFixed(1) + '%"></span>' : '') +
        '</span>';
    }
    return '<div class="tn-plot-cell">' +
      bar(t.out_left, 'tn-out', 'left during the year') +
      bar(t.in_new, 'tn-in', 'joined during the year') +
      '</div>';
  }

  function renderGroupTiles(g, year) {
    if (!g) return;
    $('#tiles').html([
      tile('Turnover', pctText(g.turnover),
           g.movements + ' movements across ' + g.centres + ' centres', ''),
      tile('Left the group', g.out_left,
           g.moved_centre ? g.moved_centre + ' more moved between centres' : 'not counting school leavers', ''),
      tile('Joined mid-year', g.in_new, 'places sold again during ' + year, ''),
      tile('Started above the babies room', g.above_bottom === null ? '--' : g.above_bottom + '%',
           'of new children - they backfilled rather than started at the beginning', '')
    ].join(''));
  }

  function loadOverview() {
    $('#overview-body').html(spinnerRow(OVERVIEW_COLS, 'Reading Kidsoft for every centre...'));
    $.getJSON('/api/tenure', { action: 'overview', year: yearNow() })
      .done(function (res) {
        if (!res.ok) {
          $('#overview-body').html('<tr><td colspan="' + OVERVIEW_COLS + '" class="p-0">' +
            emptyState('fa-plug-circle-xmark', 'Could not load tenure', res.error) + '</td></tr>');
          return;
        }
        yn.set(res.year, res.years);
        renderGroupTiles(res.group, res.year);

        var max = (res.group && res.group.bar_max) || 1;
        var $b = $('#overview-body').empty();
        res.overview.forEach(function (c) {
          var t = c.totals;
          var href = '/tenure?centre=' + c.centre_id + '&year=' + res.year;
          // A centre that is not comparable gets NO bar, rather than one drawn
          // against a scale it does not belong to: Kanwal's opening year would
          // run three times the width of every real centre and read as the
          // group's worst problem when it is the group's newest investment.
          var why = c.steady ? '' : ' title="' + esc(c.not_steady_reason || 'not comparable') + '"';
          // A centre that cannot be ranked is dimmed with .text-muted, the same
          // way the Dashboard's centre table dims one - it used to be a private
          // .tn-muted-row at opacity .62, which faded the centre's own LINK as
          // well and so read as a row that had been switched off rather than
          // one whose figures are missing.
          $b.append(
            '<tr' + (c.steady ? '' : ' class="text-muted"') + why + '>' +
            '<th class="fw-semibold"><a href="' + href + '" class="ov-centre-link">' +
              esc(c.centre) + '</a></th>' +
            '<td class="dgrid-stack">' + (c.steady ? bars(t, max, c.centre) : '') + '</td>' +
            '<td class="text-end dgrid-fig">' + (t.out_left || '<span class="tn-zero">.</span>') + '</td>' +
            '<td class="text-end dgrid-fig">' + (t.in_new || '<span class="tn-zero">.</span>') + '</td>' +
            '<td class="text-end dgrid-fig text-muted">' + (t.avg_roll ? Math.round(t.avg_roll) : '--') + '</td>' +
            '<td class="text-end dgrid-fig fw-semibold">' +
              (c.steady && t.turnover !== null ? pctText(t.turnover) : '<span class="tn-zero">--</span>') + '</td>' +
            '</tr>'
          );
        });

      })
      .fail(function () {
        $('#overview-body').html('<tr><td colspan="' + OVERVIEW_COLS + '" class="p-0">' +
          emptyState('fa-plug-circle-xmark', 'Could not load tenure', 'The request failed.') + '</td></tr>');
      });
  }

  function yearNow() { return parseInt($init.data('year'), 10); }

  $('#refresh').on('click', function () {
    var $btn = $(this).prop('disabled', true);
    $('#ladder-body').html(spinnerRow(LADDER_COLS, 'Re-reading Kidsoft...'));
    $.getJSON('/api/tenure', { action: 'year', centre_id: CENTRE, year: yearNow(), refresh: 1 })
      .always(function () { $btn.prop('disabled', false); })
      .done(function (res) {
        if (!res.ok) { toast(res.error, 'danger'); return; }
        yn.set(res.year, res.years);
        renderBanner(res); renderTiles(res); renderLadder(res); renderShort(res);
        toast('Re-read from Kidsoft.', 'success');
      });
  });

  if (CENTRE) loadCentre(); else loadOverview();

}(jQuery));
