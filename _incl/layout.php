<?php
// Shared page chrome. A protected page needs only:
//   require __DIR__.'/_incl/auth.php'; $user = auth_require();
//   require __DIR__.'/_incl/layout.php'; layout_header('Title', $user, 'board');
//   ... HTML ...
//   layout_footer(['/static/js/board.js']);
//
// Header = white bar + full-colour LM inline logo + small muted "CRM"
// (per CLAUDE.md branding decisions). Light theme only.
//
// Under the navbar sits the CENTRE BAR: every working screen is scoped to one
// centre, so which centre you are in is app-level state, not a per-screen form
// control. It is rendered here once, as tabs, and the choice follows you from
// Board to Waitlist to Rooms - previously each screen had its own <select> and
// switching centre meant re-picking it on every screen you moved to.

require_once __DIR__ . '/svg.php';
require_once __DIR__ . '/csrf.php';
// Role and centre scope. auth.php pulls this in too, but the centre bar and
// centre_current() below read it directly, so it is named here rather than
// relied on arriving through whoever included what first.
require_once __DIR__ . '/users.php';
// Two integers and no dependencies of its own - deliberately NOT the work
// layer, which Rooms and Tenure have no use for. See the file's header.
require_once __DIR__ . '/offer-policy.php';

/**
 * Which centre the user is working in.
 *
 * ?centre= wins (so a link is always literal and shareable), then the last
 * centre they looked at, then the first centre in the list. $allowAll lets a
 * screen that can show the whole group at once (Board, Offers) resolve to 0;
 * screens that cannot (Rooms, Waitlist) fall back to a real centre.
 *
 * The chosen centre is remembered in the session so it carries between screens.
 * Nav and tab links still carry ?centre= explicitly, so two tabs open on two
 * different centres keep working rather than fighting over that one session key.
 */
function centre_current(array $centres, bool $allowAll = false): int {
    $ids = array_map(static fn($c) => (int)$c['id'], $centres);
    centre_fallback(false);

    // A SUPERVISOR HAS NO GROUP VIEW, whatever the screen asked for. "All
    // centres" is a different shape rather than the same table filtered (see
    // CLAUDE.md), so a supervisor holding two centres would need a third shape
    // built for the two of them - and every screen that offers it would have to
    // agree on what "all" meant per person. They pick one of their centres
    // instead. $centres is already narrowed to what they may see by
    // centres_visible(); this stops the ALL request itself.
    //
    // Not a security control on its own - the endpoints check for themselves --
    // but it keeps the page and the endpoints asking the same question.
    if (!user_all_centres()) $allowAll = false;

    $id = null;
    if (isset($_GET['centre']) && $_GET['centre'] !== '') $id = (int)$_GET['centre'];
    elseif (isset($_SESSION['centre_id']))                $id = (int)$_SESSION['centre_id'];

    // A centre this user may not open is treated as though it were not asked
    // for, so a link pasted from a colleague at another centre lands them
    // somewhere sane rather than on an error. It is NOT written to the session.
    if ($id !== null && $id !== 0 && !user_can_centre($id)) $id = null;

    if ($id === 0 && $allowAll) { $_SESSION['centre_id'] = 0; return 0; }
    if ($id !== null && in_array($id, $ids, true)) { $_SESSION['centre_id'] = $id; return $id; }

    // Nothing usable: the whole group if this screen can show it, else centre one.
    if ($allowAll) { $_SESSION['centre_id'] = 0; return 0; }

    // FOR A SCOPED USER THIS IS NOT A FALLBACK, IT IS THE ANSWER. The paragraph
    // below is about a screen that cannot render the group view standing in for
    // it - a display concession that must not be mistaken for a choice. A
    // supervisor has no group view at all, so their own first centre IS what
    // they picked, and marking it as a fallback would send `centre=0` down every
    // sidebar link: a value they are not allowed to hold, re-resolved on every
    // single navigation and never once remembered.
    if (!user_all_centres()) {
        $mine = user_default_centre();
        if ($mine) { $_SESSION['centre_id'] = $mine; return $mine; }
    }

    // A SCREEN PICKING A CENTRE IS NOT THE USER PICKING ONE. Waitlist, Rooms and
    // Rooms cannot answer for the whole group, so with "All centres" chosen
    // they show the first centre instead of nothing. That is a display
    // concession, and it must not be written to the session or handed to the
    // sidebar as though it were a choice - it used to be, and the effect was
    // that opening Waitlist while on All centres silently destroyed the
    // selection: the nav links on that page pointed at ?centre=4, clicking
    // Placement honoured it (?centre= beats the session) and wrote 4 back, so
    // All centres could never be returned to without re-picking it.
    centre_fallback(true);
    return $ids ? $ids[0] : 0;
}

/**
 * Did the last centre_current() have to choose for the user?
 *
 * Read by layout_header() so the sidebar keeps pointing at what the user
 * actually selected, while the centre bar and the page itself still show the
 * real centre being displayed. Those are two different questions and answering
 * both with one number is what caused the bug above.
 */
function centre_fallback(?bool $set = null): bool {
    static $fellBack = false;
    if ($set !== null) $fellBack = $set;
    return $fellBack;
}

/**
 * Which year the user is working in - the same contract as centre_current().
 *
 * ?year= wins, then whatever year they were last looking at, then $default.
 * Without this the year reset on every navigation, and worse, the defaults
 * DISAGREED: the Board opened on board_default_year() (the year being planned,
 * 2027) while Rooms opened on date('Y') (2026), so simply moving between two
 * screens silently changed which year you were editing.
 *
 * A junk or out-of-range ?year= is IGNORED - it does not become the answer and
 * it is never written to the session, so one bad link cannot poison it.
 *
 * That last property was documented here and was NOT true (fixed 6 Aug 2026).
 * The old body assigned the fallback to $y and then wrote $y to the session
 * unconditionally, so following a link carrying `?year=1999` did not merely fail
 * to be remembered - it silently REPLACED the year the user had chosen with the
 * default, on every screen, for the rest of the session. Verified before the fix:
 * pick 2027, follow a bad link, and the next navigation showed 2026.
 *
 * $default is board_default_year() - the current calendar year - and every
 * screen must pass that same one. Two screens with different defaults is how
 * moving between them silently changed which year you were editing.
 */
function year_current(int $default): int {
    $valid = static fn($y) => $y >= 2000 && $y <= 2100;

    // An explicit, valid ?year= is a choice: use it and remember it.
    if (isset($_GET['year']) && $_GET['year'] !== '') {
        $y = (int)$_GET['year'];
        if ($valid($y)) { $_SESSION['year'] = $y; return $y; }
        // Junk: fall through to what they were already looking at.
    }

    // Whatever they were last looking at, if it is still sane.
    if (isset($_SESSION['year']) && $valid((int)$_SESSION['year'])) {
        return (int)$_SESSION['year'];
    }

    // Nothing remembered: start on the default and remember that.
    $_SESSION['year'] = $default;
    return $default;
}

/**
 * The bar across the top of the content area: the centre tabs on the left and
 * the Report Issue button pinned to the right. Tabs are ordinary links, not JS
 * state: the URL stays truthful, browser back works, and a centre can be opened
 * in a new tab.
 *
 * THE BAR ALWAYS RENDERS; THE TABS DO NOT (13 Oct 2026). Report Issue has to be
 * on every screen and lives at the right-hand end of this row, so a user with
 * no tabs to show still gets the row - it simply holds the button alone.
 *
 * @param array $opts centres, centre_id, all_label (null = no "all" tab),
 *                    params (extra query string carried across a switch).
 */
function layout_centre_bar(array $opts): void {
    $h = static fn($v) => htmlspecialchars((string)$v, ENT_QUOTES);

    // The centre and year ON SCREEN, which a report carries. The resolved id,
    // like the active tab - not the sidebar's centre_fallback() answer - because
    // the question is what the person was looking at.
    $centre = '';
    if (isset($opts['centre_id'])) {
        $cid = (int)$opts['centre_id'];
        if ($cid === 0) $centre = (string)($opts['all_label'] ?? '');
        foreach (($opts['centres'] ?? []) as $c) if ((int)$c['id'] === $cid) $centre = (string)$c['name'];
    }

    echo '<div class="centre-bar"><div class="container-fluid px-4 cb-row">';
    layout_centre_tabs($opts);
    // A plain button opening the modal through issues.js, not data-bs-toggle:
    // that attribute would exclude it from the app's styled tooltips (see the
    // TOOLTIPS block in app.js), and the label is hidden on a narrow screen,
    // where the tooltip is the only thing naming it.
    echo '<button type="button" class="btn btn-outline-secondary btn-sm cb-issue" id="issue-open"',
         ' data-centre="', $h($centre), '" data-year="', $h($opts['year'] ?? ''), '"',
         ' title="Something not working? Tell us about it">',
         '<i class="fa-solid fa-bug"></i><span class="d-none d-sm-inline ms-2">Report Issue</span></button>';
    echo '</div></div>';
}

/** The centre tabs, or nothing when there is no choice to offer. */
function layout_centre_tabs(array $opts): void {
    $centres = $opts['centres'] ?? [];
    if (!$centres) return;

    // ONE CENTRE, NO TABS. A supervisor assigned to a single centre would get a
    // strip of exactly one tab, permanently active and doing nothing when
    // clicked - a control that cannot change anything. Where they are is not in
    // question, so it is not asked. (This used to remove the whole bar and win
    // the row back for the grid; the row now stays for Report Issue.)
    //
    // A supervisor holding TWO centres still gets the tabs, because for them it
    // is a real choice; it simply carries their two tabs and no "All centres"
    // (layout_header's caller passes all_label => null for them).
    // The "All centres" tab counts as a second choice, so a group-view screen
    // showing one centre keeps its tabs.
    if (count($centres) < 2 && ($opts['all_label'] ?? null) === null) return;

    $h       = static fn($v) => htmlspecialchars((string)$v, ENT_QUOTES);
    $current = (int)($opts['centre_id'] ?? 0);
    $params  = $opts['params'] ?? [];
    $path    = strtok((string)$_SERVER['REQUEST_URI'], '?');

    // Switching centre keeps the year: you are comparing the same year between
    // centres, not starting again. Pages that pass an explicit `params` year
    // keep theirs by passing them as `params`.
    $barYear = $opts['year'] ?? ($_SESSION['year'] ?? null);
    if ($barYear !== null && !isset($params['year'])) $params['year'] = (int)$barYear;

    $tab = static function (int $id, string $label) use ($h, $current, $params, $path) {
        $q = http_build_query(['centre' => $id] + $params);
        return '<li class="nav-item"><a class="nav-link' . ($id === $current ? ' active' : '') .
               '" href="' . $h($path . '?' . $q) . '">' . $h($label) . '</a></li>';
    };

    echo '<nav class="cb-centres" aria-label="Centre">',
         '<ul class="nav nav-tabs centre-tabs flex-nowrap">';
    if (($opts['all_label'] ?? null) !== null) echo $tab(0, (string)$opts['all_label']);
    foreach ($centres as $c) echo $tab((int)$c['id'], (string)$c['name']);
    echo '</ul></nav>';
}

/**
 * A stat tile, server-rendered. THE PHP TWIN OF window.statTile() IN app.js, and
 * the two must be kept in step by hand.
 *
 * There were SIX copies of this markup: four identical ones in the page JS, a
 * fifth in tenure.js that put the number above the label, and this one written
 * out longhand in offers.php. Five of them are now one function each side of the
 * language boundary. Offers cannot use the JS one - it is the only screen with
 * no page script at all, because its filtering is the shared centre bar - so it
 * gets this instead of a hand-copied div.
 *
 * `cls` colours the VALUE and is for status only. Zero-is-good reads
 * 'text-success' across the app; do not colour a value for decoration.
 */
function stat_tile(string $label, string|int $value, string $hint = '',
                   string $cls = '', string $col = 'col-6 col-lg-3'): void {
    $h = static fn($v) => htmlspecialchars((string)$v, ENT_QUOTES);
    ?>
    <div class="<?= $h($col) ?>"><div class="card h-100"><div class="card-body py-3">
      <div class="text-muted small"><?= $h($label) ?></div>
      <div class="fs-3 fw-semibold <?= $h($cls) ?>"><?= $h($value) ?></div>
      <?php if ($hint !== ''): ?><div class="small text-muted"><?= $h($hint) ?></div><?php endif; ?>
    </div></div></div>
    <?php
}

/**
 * A [<] year [>] stepper, the replacement for a year <select>. Years run in an
 * unbroken sequence and a person almost always wants the one next door, so a
 * dropdown made them open a menu to move one step. Wired up by window.yearNav()
 * in app.js, which disables an arrow at either end of the years that exist.
 */
function layout_year_nav(string $id, string $label = 'Year', string $extraClass = ''): void {
    $h = static fn($v) => htmlspecialchars((string)$v, ENT_QUOTES);
    ?>
    <div class="btn-group year-nav <?= $h($extraClass) ?>" id="<?= $h($id) ?>" role="group" aria-label="<?= $h($label) ?>">
      <button type="button" class="btn btn-outline-secondary yn-step" data-step="-1" title="Previous year" aria-label="Previous year"><i class="fa-solid fa-chevron-left"></i></button>
      <span class="btn yn-label" aria-live="polite">&nbsp;</span>
      <button type="button" class="btn btn-outline-secondary yn-step" data-step="1" title="Next year" aria-label="Next year"><i class="fa-solid fa-chevron-right"></i></button>
    </div>
    <?php
}

/**
 * A static asset URL carrying the file's own mtime, e.g. `/static/js/app.js?v=1757...`.
 *
 * NOTHING SETS `Cache-Control` ON /static, so a browser applies RFC 9111
 * HEURISTIC freshness - roughly a tenth of the file's age - and simply does not
 * ask again for that long. `waitlist.js` was 14 days old, which bought any
 * browser that had loaded the queue that morning about a day and a half of
 * serving the OLD copy from disk. A normal reload does not touch it, so a
 * front-end fix could be live, correct at the origin and through Cloudflare, and
 * still invisible to the person who reported the bug - which is exactly what
 * happened on 3 Sep 2026, twice, before the cause was found.
 *
 * THE URL IS THE CACHE KEY, so changing the file changes the URL and the stale
 * copy can never be served. Cheap: one `filemtime()` per asset, and PHP's stat
 * cache means repeats within a request are free. `try_files` ignores the query
 * string, so nginx serves the file exactly as before.
 *
 * Falls through to the bare path if the file cannot be stat'd, because a missing
 * version is a cosmetic loss and a fatal here would take out every page.
 */
function asset(string $path): string {
    $mtime = @filemtime(__DIR__ . '/..' . $path);
    return $mtime ? $path . '?v=' . $mtime : $path;
}

function layout_header(string $title, array $user, string $active = 'board', array $opts = []): void {
    $h = static fn($v) => htmlspecialchars((string)$v, ENT_QUOTES);
    // Nav links carry BOTH the centre and the year, so moving between screens
    // keeps where you were - and keeps it in a second tab that the session key
    // does not describe. A screen with no year of its own (Waitlist, Offers)
    // still passes the remembered one along rather than dropping it.
    //
    // THE CENTRE IN THESE LINKS IS THE USER'S CHOICE, NOT THIS PAGE'S SUBJECT.
    // On a screen that cannot show the whole group, centre_current() falls back
    // to a real centre so the page has something to render; carrying that
    // fallback into the sidebar told the next screen the user had picked it, and
    // "All centres" could not survive a visit to Waitlist. The centre bar below
    // still uses $opts['centre_id'], because the active tab must match what is
    // actually on screen.
    $navBits = [];
    if (isset($opts['centre_id'])) {
        $navBits['centre'] = centre_fallback() ? 0 : (int)$opts['centre_id'];
    }
    $navYear = $opts['year'] ?? ($_SESSION['year'] ?? null);
    if ($navYear !== null) $navBits['year'] = (int)$navYear;
    $centreQ = $navBits ? '?' . http_build_query($navBits) : '';

    // THE LOGO IS A HOME LINK AND CARRIES NO CENTRE (10 Aug 2026). It was built
    // from $centreQ like the nav links, so from a Tuggerah page it rendered
    // href="/?centre=4" - the one control on the page whose whole meaning is
    // "take me back to the start" was re-asserting where you already were, and
    // index.php honoured it. The year still travels: that one is not a scope,
    // and the Dashboard has no picker of its own to reset it with.
    $homeQ = $navYear !== null ? '?' . http_build_query(['year' => (int)$navYear]) : '';

    $nav = static function (string $key, string $href, string $label, string $active, string $icon)
        use ($h, $centreQ) {
        $cls = $key === $active ? 'sb-link active' : 'sb-link';
        return '<a class="' . $cls . '" href="' . $h($href . $centreQ) . '"' .
               ($key === $active ? ' aria-current="page"' : '') . '>' .
               '<i class="fa-solid ' . $h($icon) . '"></i><span>' . $h($label) . '</span></a>';
    };
    ?><!doctype html>
<html lang="en" data-bs-theme="light">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= $h($title) ?> - Little Miracles CRM</title>
<meta name="robots" content="noindex, nofollow">
<link rel="icon" href="/favicon.ico" sizes="any">
<link rel="icon" href="/icon.svg" type="image/svg+xml">
<link rel="manifest" href="/site.webmanifest">
<meta name="theme-color" content="#2e3192">
<link rel="stylesheet" href="<?= $h(asset('/static/css/bootstrap.min.css')) ?>">
<link rel="stylesheet" href="<?= $h(asset('/static/css/fontawesome.min.css')) ?>">
<link rel="stylesheet" href="<?= $h(asset('/static/css/app.css')) ?>">
<?php /* In a meta tag, not an inline <script>, so the Content-Security-Policy
         can forbid inline script entirely. static/js/app.js reads it. */ ?>
<meta name="csrf-token" content="<?= $h(csrf_token()) ?>">
<?php /* Who is signed in, for the few places JS needs it - "It's mine" on a
         work item. Same mechanism as the CSRF token and for the same reason: the
         CSP forbids an inline <script>, so a meta tag is how a value reaches the
         browser. Not a security boundary; every write re-reads the session. */ ?>
<meta name="user-email" content="<?= $h($user['email'] ?? '') ?>">
<?php /* Whether to draw the administrator's Delete on a logged note. Same rule
         as above: not a boundary, /api/comms re-checks the role on the click. */ ?>
<meta name="user-admin" content="<?= user_is_admin() ? '1' : '' ?>">
<?php /* Server-side idle session lifetime (seconds). app.js uses it to bounce
         an idle tab back through the login flow the moment the session could
         have lapsed - seamless for signed-in Edge users (silent re-SSO). */ ?>
<meta name="session-ttl" content="<?= (int)ini_get('session.gc_maxlifetime') ?>">
<?php /* WHEN AN OFFER IS URGENT, from PHP, so the three screens that grade one
         (Waitlist, In progress, the work modal) cannot drift from each other or
         from work_progress_summary(). Same mechanism and same reason as the two
         above. Published here rather than in each API payload because the work
         modal is opened from two different screens fed by two different
         endpoints, and a threshold that arrives with the data is a threshold
         that has to be threaded through every call that carries it.
         See _incl/offer-policy.php. */ ?>
<meta name="offer-urgent-hours" content="<?= OFFER_URGENT_HOURS ?>">
<meta name="offer-countdown-hours" content="<?= OFFER_COUNTDOWN_HOURS ?>">
</head>
<body>
<?php /* Collapsed state rides in a COOKIE, not localStorage, so the server can
         render it. Read after paint it would flash the wide sidebar on every
         page load, and the CSP forbids the inline <script> that would normally
         be used to set the class before first paint. */ ?>
<div class="app-shell<?= (($_COOKIE['sb_collapsed'] ?? '') === '1') ? ' sb-collapsed' : '' ?>">

  <?php /* SIDEBAR. The four screens live down the left, which buys the content
           area its full width back - this app's core artifact is a wide grid,
           and a top bar spent a whole row of vertical space on four links.
           Below lg it becomes a compact horizontal bar instead of a slide-over:
           Bootstrap's offcanvas-lg forces `background: transparent !important`
           above its breakpoint and expects its own header/body structure, and
           this needs no JS, no backdrop and no toggle state to go wrong. */ ?>
  <aside class="app-sidebar" id="sidebar" aria-label="Main navigation">
    <?php /* The inline wordmark when there is room for it, the icon when the
             sidebar is collapsed to a rail. Both are inlined and CSS picks one;
             svg_scope() in svg.php keeps their ids and .cls-* rules apart. */ ?>
    <div class="sb-brand">
      <a href="/<?= $h($homeQ) ?>" class="sb-home" aria-label="Little Miracles CRM home">
        <?php svg_inline('littlemiracles_branding/Logo Inline.svg', 'sb-logo-full', 'Little Miracles'); ?>
        <?php svg_inline('littlemiracles_branding/Icon.svg', 'sb-logo-icon', 'Little Miracles'); ?>
      </a>
      <div class="sb-tag">CRM</div>
    </div>

    <?php /* SEARCH sits above the screens, not among them, because it is not a
             fifth place to go - it is how you start when a parent rings and all
             you have is their number. It opens a palette (see search.js) rather
             than being an input in the rail, so it works identically when the
             sidebar is collapsed to icons and does not fight the grid for width.
             Ctrl+K from anywhere - and ONLY Ctrl+K: the "/" shortcut was removed
             17 Aug 2026 because a bare printable key opened the palette in the
             middle of a note being typed. See search.js. */ ?>
    <button type="button" class="sb-search" id="search-open"
            title="Search children, parents and phone numbers (Ctrl+K)">
      <i class="fa-solid fa-magnifying-glass"></i>
      <span>Search</span>
      <kbd class="sb-kbd">Ctrl K</kbd>
    </button>

    <nav class="sb-nav">
      <?php /* Board carries the whole room x weekday cell: the plan, Kidsoft's
               bookings, the drift between them and the queue that could fill
               it. Fill / Drift / Occupancy were the same grid split by data
               source and are now redirects into it. */ ?>
      <?php /* Icons are all fa-SOLID. Only fa-solid-900 and fa-brands-400 are
               vendored into static/css/fonts - a fa-regular glyph renders as a
               blank space and nothing but looking at the page catches it. */ ?>
      <?php /* Dashboard sits FIRST and is the landing screen: it is the only one
               that crosses the mirror (what Kidsoft holds) and the work (what we
               are doing about the empty places). Every screen below it answers
               one part of what this one summarises. */ ?>
      <?= $nav('dashboard','/dashboard','Dashboard', $active, 'fa-chart-simple') ?>
      <?= $nav('board',    '/placement','Placement', $active, 'fa-people-roof') ?>
      <?= $nav('waitlist', '/waitlist', 'Waitlist',  $active, 'fa-list-ol') ?>
      <?php /* "In progress", not "Offers" (10 Aug 2026). An offer is one STAGE of
               a work item, so a module showing only offers was showing one stage
               of the work layer and calling it the subject. A UAT user went here
               looking for the work in flight - the mental model was right and
               the module was drawn too narrow, so it widened rather than a
               seventh item being added to this list. /offers 301s here. */ ?>
      <?= $nav('progress', '/progress', 'In progress', $active, 'fa-handshake') ?>
      <?php /* A SEVENTH ITEM, and this file has twice argued against one - Offers
               widened into In progress rather than adding a nav row, and User
               access hangs off the user button rather than taking one. Both of
               those arguments turn on the same test: is this one stage or one
               corner of a module that already exists? A child on a Behaviour
               Support Plan is not a place to fill, so nothing in the work layer
               would ever list them, and the question - who is on a plan, and has
               anybody rung the family - has no home on any other screen. Asked
               for by a centre and settled by Broc, 10 Sep 2026: "managers should
               be able to see all BSP children, while supervisors should only see
               their centre." The same icon the flag itself carries on the board,
               because one fact keeps one mark across screens. */ ?>
      <?= $nav('support',  '/support-plans', 'Support plans', $active, 'fa-umbrella') ?>
      <?php /* AN EIGHTH ITEM, and the test is the one the seventh had to pass:
               is it one stage, or one corner, of a module that already exists?
               Every other screen here answers "who can we put in this place" --
               a queue, a shortlist, a pipeline, all of them pointing at
               families. This one answers "there is nobody to put in it", which
               is the only conclusion in the app that sends the work OUTSIDE it,
               to whoever buys the advertising. No screen that lists families can
               reach it, and the Dashboard's Places to fill is deliberately the
               other half: it says where the free place-days are and nothing
               about whether anybody is waiting for them. Asked for by Broc,
               18 Sep 2026. */ ?>
      <?= $nav('marketing','/marketing','Marketing', $active, 'fa-arrows-to-dot') ?>
      <?= $nav('tenure',   '/tenure',   'Tenure',    $active, 'fa-graduation-cap') ?>
      <?= $nav('rooms',    '/rooms',    'Rooms',     $active, 'fa-location-dot') ?>
    </nav>

    <div class="sb-foot">
      <div class="dropdown">
        <a class="sb-user dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown"
           title="<?= $h($user['name'] ?: $user['email']) ?>">
          <i class="fa-solid fa-circle-user"></i>
          <span class="text-truncate"><?= $h($user['name'] ?: $user['email']) ?></span>
        </a>
        <ul class="dropdown-menu">
          <li><span class="dropdown-item-text small text-muted"><?= $h($user['email']) ?></span></li>
          <?php if (user_is_admin()): ?>
          <li><hr class="dropdown-divider"></li>
          <?php /* A MODAL, not a screen in the sidebar. Managing access is not a
                   seventh place the enrolments team works - it is opened rarely,
                   by two people, and it has nothing to do with filling rooms. A
                   nav item would spend a permanent row on it for everybody and
                   sit there greyed out for most of them. It hangs off the user
                   button because that is already where "who am I" lives. */ ?>
          <li><button type="button" class="dropdown-item" id="user-access-open">
            <i class="fa-solid fa-key me-2"></i>User access</button></li>
          <?php endif; ?>
          <li><hr class="dropdown-divider"></li>
          <li><a class="dropdown-item" href="/auth/logout"><i class="fa-solid fa-right-from-bracket me-2"></i>Sign out</a></li>
        </ul>
      </div>
      <button type="button" class="sb-toggle" id="sb-toggle" aria-controls="sidebar">
        <i class="fa-solid fa-angles-left"></i><span>Collapse</span>
      </button>
    </div>
  </aside>

  <div class="app-content">
    <?php layout_centre_bar($opts); ?>
    <?php /* Toasts stack in a fixed corner. They used to be prepended into <main>,
             which pushed the whole page down a row every time one appeared - the
             grid moved under the pointer just as you were about to click a cell. */ ?>
    <div class="toast-stack" id="toast-stack" aria-live="polite" aria-atomic="true"></div>
    <?php /* `main_class` lets ONE screen opt into different chrome without every
             other screen having to know about it. The Placement grid is the only
             thing in the app that is worth seeing whole (eleven rooms at
             Tuggerah), so it asks for tighter vertical rhythm; nothing else
             passes this and nothing else changes. */ ?>
    <main class="app-main px-4 py-4 <?= $h($opts['main_class'] ?? '') ?>">
<?php
}

function layout_footer(array $scripts = []): void {
    $h = static fn($v) => htmlspecialchars((string)$v, ENT_QUOTES);
    ?>
    </main>
  </div>
</div>

<?php /* The search palette. Markup lives here, not on a page, because it is
         reachable from every screen - a call does not arrive while you happen
         to be on the right one. Top-aligned rather than centred so the result
         list grows downwards instead of the whole dialog jumping about as you
         type. */ ?>
<div class="modal fade search-modal" id="search-modal" tabindex="-1" aria-labelledby="search-title" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-scrollable">
    <div class="modal-content">
      <div class="search-head">
        <i class="fa-solid fa-magnifying-glass search-head-ic"></i>
        <label for="search-q" class="visually-hidden" id="search-title">Search children, parents and phone numbers</label>
        <input type="search" class="form-control" id="search-q" autocomplete="off" spellcheck="false"
               placeholder="Phone number, child's name, parent's name or email...">
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body p-0" id="search-results"></div>
      <div class="search-foot">
        <span><kbd>&uarr;</kbd><kbd>&darr;</kbd> move</span>
        <span><kbd>Enter</kbd> open</span>
        <span><kbd>Esc</kbd> close</span>
        <span class="ms-auto" id="search-took"></span>
      </div>
    </div>
  </div>
</div>

<?php /* ONE ENROLLED CHILD (6 Nov 2026). Chrome since the search palette started
         opening it: an enrolled child found from any screen opens here rather
         than sending the reader to the Placement board to find the row again.
         The board opens the same one. See static/js/child-modal.js. */ ?>
<?php require __DIR__ . '/child-modal.php'; ?>

<?php /* USER ACCESS. Same argument as the search palette above: it is reachable
         from the user menu on every screen, so its markup belongs to the chrome
         rather than to a page. Rendered only for an administrator - there is no
         point shipping the dialog to somebody whose button does not exist, and
         it keeps the field names out of everybody else's DOM. The endpoint
         checks the role for itself either way (auth_require_admin_api). */ ?>
<?php if (user_is_admin()) require __DIR__ . '/users-modal.php'; ?>

<?php /* REPORT ISSUE. Chrome for the same reason as the two above: the button
         is on every screen. For everybody, whatever their role. */ ?>
<?php require __DIR__ . '/issue-modal.php'; ?>

<?php /* The room marks, inlined once. Every screen shows room names somewhere,
         and the assets stay inside _incl - see room_icon_sprite(). */ ?>
<?php room_icon_sprite(); ?>

<script src="<?= $h(asset('/static/js/lib/jquery-3.7.1.min.js')) ?>"></script>
<script src="<?= $h(asset('/static/js/lib/bootstrap.bundle.min.js')) ?>"></script>
<script src="<?= $h(asset('/static/js/app.js')) ?>"></script>
<?php /* Report Issue, and the trail of recent activity it sends. DIRECTLY AFTER
         app.js and before every page script, because it wraps window.toast and
         dashboard.js and marketing.js copy that function into a local at load:
         loaded any later, their messages would never reach the trail. */ ?>
<script src="<?= $h(asset('/static/js/issues.js')) ?>"></script>
<?php /* The child dialog, then the palette that opens it. Both on every page, like
         app.js: search is chrome, not a screen, and so is what it opens. */ ?>
<script src="<?= $h(asset('/static/js/child-modal.js')) ?>"></script>
<script src="<?= $h(asset('/static/js/search.js')) ?>"></script>
<?php /* Administrators only, and only because the markup it drives is only
         rendered for them. */ ?>
<?php if (user_is_admin()): ?>
<script src="<?= $h(asset('/static/js/users.js')) ?>"></script>
<?php endif; ?>
<?php foreach ($scripts as $s): ?>
<script src="<?= $h(asset($s)) ?>"></script>
<?php endforeach;
}
