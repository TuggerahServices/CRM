// REPORT ISSUE, and the trail of recent activity a report carries. (13 Oct 2026)
//
// Loaded on every page DIRECTLY AFTER app.js (see layout_footer): it wraps
// window.toast, and dashboard.js and marketing.js copy that function into a
// local when they load. Markup in _incl/issue-modal.php, endpoint
// api/issues.php. See _incl/docs/issue-reports.md.
//
// THE TRAIL. The last TRAIL_MAX things that happened in this browser tab -
// pages opened, controls clicked, fields changed, calls to /api and what they
// answered, messages shown, script errors - each with how long before the
// report it happened. "I clicked Offer and nothing happened" arrives with the
// call that was refused and the words it was refused with.
//
// WHAT IS NEVER RECORDED: anything typed. A text field changing is recorded
// by its label alone, and an /api call by its path and `action`, never the rest
// of its query string - search sends phone numbers and names in `q`.
//
// It lives in sessionStorage so it survives moving between screens in one tab
// and dies with the tab. That storage can be unavailable (private window,
// blocked site data), so every touch is wrapped and the trail simply starts
// empty on each page instead.
//
// Times are Date.now() DIFFERENCES - milliseconds between two moments in this
// browser - so the Sydney wall-clock rule in app.js does not come into it: no
// stored date string is ever parsed here.

(function ($) {
  'use strict';

  var TRAIL_KEY = 'lm_issue_trail';
  var TRAIL_MAX = 40;                // ISSUE_TRAIL_MAX in _incl/issue-reports.php
  var TRAIL_AGE = 60 * 60 * 1000;    // older than an hour is not "what I was just doing"
  var TITLE_SUFFIX = / - Little Miracles CRM$/;

  var trail = [];
  try {
    trail = JSON.parse(sessionStorage.getItem(TRAIL_KEY) || '[]');
    if (!Array.isArray(trail)) trail = [];
  } catch (e) { trail = []; }

  function clip(s, n) {
    s = String(s == null ? '' : s).replace(/\s+/g, ' ').trim();
    return s.length > n ? s.slice(0, n - 3) + '...' : s;
  }

  // The same thing twice in a row is one line with a count: search fires a call
  // per pause in typing, and forty of those would push out everything else.
  function record(k, s, bad) {
    s = clip(s, 300);
    var last = trail[trail.length - 1];
    if (last && last.k === k && last.s === s) {
      last.n = (last.n || 1) + 1;
      last.at = Date.now();
    } else {
      trail.push({ k: k, s: s, at: Date.now(), n: 1, bad: !!bad });
      if (trail.length > TRAIL_MAX) trail.splice(0, trail.length - TRAIL_MAX);
    }
    try { sessionStorage.setItem(TRAIL_KEY, JSON.stringify(trail)); } catch (e) { /* in-page only */ }
  }

  // --- Naming an element -----------------------------------------------------

  // Bootstrap's layout and colour classes say nothing about WHICH control it
  // was; the first class left after dropping them usually does (`offer-btn`,
  // `wl-open`), and it is what finds the handler in the page script.
  var UTILITY = /^(btn|text-|bg-|fw-|fs-|small$|d-|[mp][setbxy]?-\d|gap-|align-|justify-|flex|rounded|border|shadow|[wh]-\d|position-|lh-|link-|opacity-|visually-|col|row$|g-\d|nav-link$|nav-item$|active$|show$|dropdown-item$|disabled$)/;

  function hint(el) {
    var tag = el.tagName.toLowerCase();
    if (el.id) return tag + '#' + el.id;
    var cls = (typeof el.className === 'string' ? el.className.split(/\s+/) : [])
      .filter(function (c) { return c && !UTILITY.test(c); });
    return tag + (cls.length ? '.' + cls[0] : '');
  }

  // Which dialog it happened in, when it happened in one: "Save" alone could be
  // any of a dozen.
  function where(el) {
    var t = clip($(el).closest('.modal').find('.modal-title').first().text(), 50);
    return t ? ' in "' + t + '"' : '';
  }

  // What the control is called on screen. The visible text first, because that
  // is what the person saw; an icon-only button falls back to its tooltip, which
  // the tooltip code in app.js may have moved to data-bs-original-title.
  function label(el) {
    var $el = $(el);
    return clip(el.innerText || $el.attr('aria-label') || $el.attr('title') ||
                $el.attr('data-bs-original-title') || '', 80);
  }

  function fieldName(el) {
    var $el = $(el), t = '';
    // Filtered rather than built into a selector, so an id with a quote in it
    // cannot throw.
    if (el.id) t = $('label').filter(function () { return this.htmlFor === el.id; }).first().text();
    if (!t) t = $el.closest('label').text();
    if (!t) t = $el.attr('aria-label') || $el.attr('placeholder') || el.name || '';
    return '"' + clip(t, 50) + '"';
  }

  // The report's own dialog and button are not part of what went wrong.
  function ours(el) { return $(el).closest('#issue-modal, #issue-open').length > 0; }

  // --- What is recorded ------------------------------------------------------

  record('page', document.title.replace(TITLE_SUFFIX, '') + ' (' + location.pathname + location.search + ')');

  var CLICKABLE = 'a, button, [role="button"], label, summary, td, th, ' +
                  'input[type="checkbox"], input[type="radio"]';

  $(document).on('click', function (e) {
    var el = $(e.target).closest(CLICKABLE)[0];
    // A label's click is replayed on its checkbox, which records itself.
    if (!el || ours(el) || el.tagName === 'LABEL') return;
    var text = label(el);
    record('click', (text ? '"' + text + '"' : '(no label)') + where(el) + ' [' + hint(el) + ']');
  });

  $(document).on('change', 'select, input, textarea', function () {
    if (ours(this)) return;
    var said;
    if (this.tagName === 'SELECT') said = 'to "' + clip($(this).find('option:selected').text(), 60) + '"';
    else if (this.type === 'checkbox') said = this.checked ? 'ticked' : 'unticked';
    else if (this.type === 'radio') said = 'chosen';
    // Anything typed: THAT it changed, never what it says.
    else said = 'edited';
    record('change', fieldName(this) + ' ' + said + where(this) + ' [' + hint(this) + ']');
  });

  function param(str, name) {
    var m = new RegExp('(?:^|[?&])' + name + '=([^&]*)').exec(str || '');
    if (!m) return '';
    try { return decodeURIComponent(m[1].replace(/\+/g, ' ')); } catch (e) { return m[1]; }
  }

  // Every call to /api, with only its path and `action` - see the header. A
  // refusal (api_fail: HTTP 200 and ok:false) is recorded WITH its message,
  // because that is the sentence somebody saw in a red toast and is about to
  // paraphrase.
  $(document).ajaxComplete(function (e, xhr, s) {
    var url = s.url || '';
    if (url.indexOf('/api/') !== 0 || url.indexOf('/api/issues') === 0) return;
    // Search cancels the call before it on every keystroke. Nothing went wrong.
    if (xhr.statusText === 'abort') return;
    var act = param(url, 'action') || (typeof s.data === 'string' ? param(s.data, 'action') : '');
    var line = (s.type || 'GET').toUpperCase() + ' ' + url.split('?')[0] + (act ? ' (' + act + ')' : '');
    var res = xhr.responseJSON, bad = false;
    if (xhr.status !== 200) { line += ' - HTTP ' + (xhr.status || 'no response'); bad = true; }
    else if (res && res.ok === false) { line += ' - refused: ' + (res.error || 'no message'); bad = true; }
    record('api', line, bad);
  });

  var showToast = window.toast;
  window.toast = function (msg, type) {
    record('toast', (type && type !== 'success' ? type + ': ' : '') + msg, type === 'danger');
    return showToast.apply(this, arguments);
  };

  $(window).on('error', function (e) {
    var o = e.originalEvent || {};
    var file = String(o.filename || '').replace(location.origin, '').split('?')[0];
    record('error', (o.message || 'Unknown error') + (file ? ' (' + file + ':' + (o.lineno || 0) + ')' : ''), true);
  });

  // --- The dialog ------------------------------------------------------------

  var $modal = $('#issue-modal');
  if (!$modal.length) return;
  var modal = bootstrap.Modal.getOrCreateInstance($modal[0]);
  var $text = $('#issue-text');

  $('#issue-open').on('click', function () { modal.show(); });
  $modal.on('shown.bs.modal', function () { $text.trigger('focus'); });

  // What was typed SURVIVES closing the dialog, and is cleared only once it has
  // been sent. Cancel, Escape or a click on the backdrop halfway through a
  // paragraph should not cost the paragraph.
  $('#issue-form').on('submit', function (e) {
    e.preventDefault();
    var text = $.trim($text.val());
    if (!text) { $text.trigger('focus'); return; }

    var now = Date.now();
    var $open = $('#issue-open');
    var context = {
      title: document.title.replace(TITLE_SUFFIX, ''),
      // The centre and year on screen, published on the button by
      // layout_centre_bar() - a supervisor with one centre has no tabs to read
      // it from, and several screens have no year stepper.
      centre: String($open.attr('data-centre') || ''),
      year: String($open.attr('data-year') || ''),
      viewport: window.innerWidth + 'x' + window.innerHeight,
      screen: window.screen.width + 'x' + window.screen.height,
      trail: trail.filter(function (t) { return now - t.at <= TRAIL_AGE; }).map(function (t) {
        return { k: t.k, s: t.s, n: t.n || 1, bad: !!t.bad, ago: Math.max(0, Math.round((now - t.at) / 1000)) };
      })
    };

    // showToast, not window.toast: the report's own messages are not part of
    // the next report's trail.
    var $btn = $('#issue-send').prop('disabled', true);
    $.post('/api/issues', {
      action: 'report',
      description: text,
      page: location.pathname + location.search,
      context: JSON.stringify(context)
    }).done(function (res) {
      if (!res || !res.ok) { showToast((res && res.error) || 'Your report could not be sent.', 'danger'); return; }
      $text.val('');
      modal.hide();
      showToast('Thanks - your report has been sent.');
    }).fail(function () {
      showToast('Your report could not be sent. Please try again in a moment.', 'danger');
    }).always(function () {
      $btn.prop('disabled', false);
    });
  });

})(jQuery);
