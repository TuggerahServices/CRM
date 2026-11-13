# CLAUDE.md

Guidance for Claude Code (claude.ai/code) working in this repository.

**This file is the index. It holds only what is true on almost every task.** Everything else
lives in `_incl/docs/` - read the file for the area you are touching **before** you change it.
Those files carry the measurements, the traps, and the reasons a rule exists; this one does not
repeat them.

---

# What this is

**Little Miracles CRM** ("CRM" in the UI; called Attendances until 29 Oct 2026 - see Branding in
[ui-conventions.md](_incl/docs/ui-conventions.md)). Internal tool for the Little Miracles
**enrolments team**, replacing the per-centre
`<CENTRE>_Attendances_<year>.xlsx` workbooks. Children's and families' personal data: internal
staff only, Entra ID sign-in, **treated as confidential**.

**Core goal:** fill every room, every day, to 100%. Under 10% of children attend all five days,
so **the unit of work everywhere is a room x weekday cell, never a room.**

## THE POINT: eliminate the team's manual work

**In the ideal end state the enrolments team does exactly one thing: talk to parents.**
Everything else - who is in which room on which day, who has asked for a day, where a place has
come free, what changed since yesterday - is done by this tool reading the Kidsoft API.

Two questions before building anything:

1. **Can the Kidsoft API answer this?** If yes, **READ it - live and cached, never copied into
   our tables**, because a copy drifts the moment Kidsoft changes.
2. **Why is a human typing this?** A screen that asks staff to re-enter something Kidsoft knows
   is a bug.

Our database is for what Kidsoft genuinely does NOT hold: sub-quotas, the new-enrolment queue,
comms, work items, the internal day waitlist, **days held for a child who is not booked
on them**, **the day a centre starts trading** (`centres.opens_on` - Kidsoft's own opening-hours
calendar says Warnervale opened 15 Jun 2026, the day its record was created, seven months early),
audit. **Offers used to be on that list and Kidsoft holds them** - every open one carries its
room, days and deadline on `getWaitingList` (15 Oct 2026); see `KIDSOFT_OPEN_OFFER` in
`_incl/board.php`.

**The app is BOTH a live mirror and a place to plan**, and the line between them is the most
important thing in the codebase. See **[_incl/docs/architecture.md](_incl/docs/architecture.md)** -
read it before changing schema or deciding where a feature lives.

## THE META-RULE: this file used to be the source of its own errors

**Five load-bearing "Kidsoft cannot do that" claims were written here as fact, never measured, and
every one was false** - the field was behind a parameter nobody passed. **A sixth sat in the list
above until 15 Oct 2026**: "offers" were filed as ours while the import was already fetching
`OfferedDays` and throwing it away, so staff made every offer twice and the board sold 70 promised
place-days as free. A claim written in these
docs is read as measured fact by everything that comes after it, and **nobody re-probes an
endpoint the notes call a dead end.**

So:

- **Before writing that something is absent, measure it - and record HOW.** The entries that were
  wrong named no measurement, because there had never been one.
- **When these docs claim something was removed, `ls` for it.** Two workbooks were described as
  "wiped" for ten days while they sat in the web root serving children's DOBs to the public
  internet.
- **An override nobody has ever exercised is not a safeguard, it is a copy going stale.** Three
  instances, all removed after measuring: the capacity override (0 of 360 rows), the sibling
  checkbox (0 ever ticked), `priority_of_access` (743 of 743 agreed with Kidsoft).

---

# Where things are documented

Read the relevant file before working in that area. **Do not load them all.**

| Read this | When you are touching |
|---|---|
| **[architecture.md](_incl/docs/architecture.md)** | Schema, where a feature belongs, `placements`, what is retired and why, the screen map |
| **[kidsoft-api.md](_incl/docs/kidsoft-api.md)** | `_incl/kidsoft*.php`, any API call, caching/TTLs, "does the API have X?" |
| **[placement-board.md](_incl/docs/placement-board.md)** | `placement.php`, `api/cells.php`, `api/board.php`, `board.js`, the room sheet (`#room-modal`), the child dialog (`api/child.php`, `_incl/child-profile.php`, `_incl/child-modal.php`, `child-modal.js`), and the engines `cells/board/board-live/capacity/fill/day-requests/internal-requests/occupancy` |
| **[waitlist.md](_incl/docs/waitlist.md)** | `waitlist.php`, `api/waitlist.php`, `waitlist.js`, `_incl/waitlist*.php`, `siblings.php`, `do-not-enrol.php`, the priority ladder, offers |
| **[work-layer.md](_incl/docs/work-layer.md)** | `progress.php`, `api/work.php`, `_incl/work*.php`, `offer-policy.php`, `_incl/tour-calendar.php`, work items, offer stages, the contact log, tours in the centres' Outlook calendars, brothers and sisters worked as one family |
| **[support-plans.md](_incl/docs/support-plans.md)** | `support-plans.php`, `support-plans-report.php` (the printed report), `api/support-plans.php`, `_incl/support-plans.php`, `_incl/support-plan-steps.php`, `_incl/support-plan-calendar.php`, `_incl/day-holds.php`, `day_holds`, `support_plans`, `support_plan_steps`, the BSP and ISP step ladders, a step's booked day in the centre's Outlook calendar, holding a day for a child who is not booked on it |
| **[marketing.md](_incl/docs/marketing.md)** | `marketing.php`, `api/marketing.php`, `_incl/marketing.php`, `marketing.js` - free places with nobody waiting for them |
| **[dashboard.md](_incl/docs/dashboard.md)** | `dashboard.php`, `api/dashboard.php`, `_incl/dashboard.php`, `reenrolment.php`, `week-ahead.php`, the weekly report to management |
| **[tenure.md](_incl/docs/tenure.md)** | `tenure.php`, `api/tenure.php`, `_incl/tenure.php` |
| **[rooms-centres.md](_incl/docs/rooms-centres.md)** | `rooms.php`, `api/rooms.php`, `_incl/rooms*.php`, `centres-sync.php`, `capacity.php`, `seed.php` |
| **[search-identity.md](_incl/docs/search-identity.md)** | `search.php`, `identity.php`, `contacts.php`, name matching in `drift.php` |
| **[ui-conventions.md](_incl/docs/ui-conventions.md)** | **Any CSS or template work.** `layout.php`, `app.css`, `app.js`, `svg.php`, the centre bar, the year stepper, shared components, branding |
| **[birthday-report.md](_incl/docs/birthday-report.md)** | `_incl/birthdays.php`, `cron-nightly.php --birthdays`, the Monday birthday email to the parents accounts team, `graph_send_mail()` |
| **[issue-reports.md](_incl/docs/issue-reports.md)** | The Report Issue button, `issue-modal.php`, `api/issues.php`, `_incl/issue-reports.php`, `issues.js`, `issue_reports`, the activity trail a report carries |
| **[auth-security-ops.md](_incl/docs/auth-security-ops.md)** | `auth.php`, `users*.php`, `api/users.php`, roles and centre scope, `entra*.php`, `csrf.php`, `api.php`, `cron-nightly.php`, nginx, backups, sessions |

---

# Hard rules that apply everywhere

## Kidsoft is READ-ONLY, with ONE named exception

**No authorisation to edit, insert or delete through the Kidsoft API, except the one endpoint
named below.** Only `get*` endpoints otherwise. **Never** call any other write endpoint, even to
"test". `kidsoft()` hard-refuses any action not matching `^get[A-Z0-9]`; **do not weaken that
guard** - the exception is a separate function, not a hole in it.

Always go through `kidsoft($controller, $action, $params = [])` in `_incl/kidsoft.php`; never
build raw curl.

### The exception: `Comment/createComment`

Authorised by **Broc, 14 Aug 2026**, reached only through `kidsoft_create_comment()`. Kidsoft is
in maintenance and will not be adding the read endpoint that would have made a proper sync
possible, so the enrolments team's contact log lives here and a **one-way copy** goes to Kidsoft
where Kidsoft has a record to put it on. See
**[kidsoft-api.md](_incl/docs/kidsoft-api.md)** and `_incl/comments.php`.

Four properties that are load-bearing, all measured before it was opened:

- **`Confidential` is hard-coded `true` and is not a parameter.** Every comment must be
  confidential; a caller cannot ask for a public one.
- **`WaitingListID` is never passed.** A comment written there is invisible to every read
  endpoint - proved by having a PAM add one in the Kidsoft UI and failing to find it anywhere.
- **Only IDs Kidsoft itself supplies are used.** Matching a family to a guardian by phone or
  email would lift reach from 13% to 24% and was **refused**: there is no update or delete, so a
  wrong match writes one family's confidential note permanently onto another family's record.
- **A failed push is not a failed save.** The local row commits first; the outcome is recorded on
  it so the screen can say what did not travel.
- **A note that had nowhere to go travels LATER, on its own.** Kidsoft issues the ids when an
  application converts, so `comment_push_backlog()` (nightly) copies that family's history
  across **oldest note first** - a comment carries no date, so insertion order is all a reader
  gets. **A push whose outcome is unknown is never retried**: there is no update, no delete and
  no read path to check with. **A note asked to stay here never travels** - an internal note or a
  support plan note is stamped `local_only`; until 19 Oct 2026 it was stamped nowhere and the
  sweep would have copied it.
- **A note shown on a brother or sister travels on ONE child** - the one it was written on
  (`communication_siblings`, 19 Oct 2026). A sibling match is a shared phone or email, the
  evidence the rule above refuses.

**87% of open applications have no Kidsoft record at all**, so our table is the record and the
copy is a bonus. **Do not add a second exception without the same authorisation and the same
measurement.**

**Before writing that an endpoint lacks a field, pass every `Options` / `With*` flag it offers
and re-read `Documentation/getMarkdown`.** Absence from a default response means nothing.

## NO DOLLAR FIGURE IS EVER RENDERED, ANYWHERE

Not a total, not an estimate, not a `$` sign. **This is a child enrolment tool.** Revenue is
managed by a different team on different systems, and a money figure here is out of scope and
liable to disagree with theirs.

**The prohibition is on the OUTPUT, so do not "just compute it for later"** - a value sitting in
an API payload is one line away from being rendered, which is exactly how it came back the last
time. Deleted root and branch 10 Aug 2026.

**The unit is the PLACE-DAY** and it always was the better one: an empty place-day is a child who
could have been in that room, and it points at the same rooms without importing another team's
job.

## CASUAL BOOKINGS DO NOT EXIST IN THIS TOOL

**This app is about LONG-TERM PERMANENT ENROLMENTS ONLY** (Broc, 13 Aug 2026: *"there shouldn't
be casual type bookings ANYWHERE in this project at all in any shape, form, or consideration"*).

The unit everywhere is a **permanent weekday place sold for the year**. A casual day neither
sells one nor stops one being sold, so it must not appear in occupancy, tenure, demand, a
figure, a badge or a shortlist.

- **A booking carries `Casual` - test it and skip.** `bookings_year()`, `board_live_children()`,
  `board_forward_occupancy()` and `tenure_snapshot()` all do.
- **A WAITLISTED booking does NOT carry `Casual`**, so the type is the only signal:
  `casual_booking_types()` in `_incl/day-requests.php` reads `Service/getBookingTypes` and
  matches the `CAS` name prefix. **Never apply that name rule to a real booking** - the flag is
  authoritative there and the two disagree on 34 types.
- **`capacity.php` passes `ExcludeCasualBookings`.** Leave it.

**The rule was half-applied for months and that is exactly how it did damage.** Casual BOOKINGS
were excluded from the board while casual REQUESTS were counted as demand for the same cell: 63
of 65 open requests group-wide were one-off casual days, each one making its cell **grantable**,
the loudest state in the app. Fixed 13 Aug 2026. **If you add a new read of `getBookings` or
`getWaitlistedBookings`, the casual test is part of writing it, not a later hardening pass.**

## ASCII punctuation only

Use only punctuation typable on a standard US keyboard in **all user-facing text**. No em dashes,
en dashes, smart quotes or single-character ellipses. Rewrite the sentence if needed.

## Dates and timezone

All stored datetimes are **Australia/Sydney wall-clock** (PHP CLI scripts pin the tz; MySQL
`NOW()` uses the Sydney system tz), so **display reformats the raw string with no timezone
conversion** - DST is already baked in at write time.

Display `d/MM/yyyy hh:mm tt` (e.g. `8/07/2026 04:17 PM`), date-only `d/MM/yyyy`.

**Never feed these Sydney strings through `new Date()` / `toLocaleString`** - it re-interprets
them in the viewer's browser timezone. Anything date-derived (expiry countdowns, "today") is
computed **server-side** and published to the browser. Homes for a shared helper when one is
needed: `_incl/dates.php` and `static/js/app.js`.

## Every mutation goes through `audit()`

`_incl/audit.php`.

## Years do not bleed into each other

Broc, 6 Nov 2026: *"Very few (if any) children continue in the same room across years ... there
should be a hard delimitation between the years."* A place on the 2026 board is a 2026 place; a
family who starts in 2027 is the 2027 board's question, however close 1 January is. **Any window,
lookahead or "within N days" that can cross 31 December is capped at it.** The board's "Could take
this day" and Marketing both are (`start_window` in `_incl/fill.php`).

## Withhold a figure; do not caption it

The users are one small team who know their own centres. **Explaining their business back to them
is noise**, and a caption ages into something wrong. A centre that is shut gets `--` and a
`title=`, not a sentence.

**A banner is reserved for what they cannot know unaided** - e.g. Kidsoft refusing to answer for
a centre, which changes whether the numbers can be trusted. **Fixing a figure often deletes its
caption.**

## The group view is a DIFFERENT SHAPE, never the same table filtered

And **which one renders is decided server-side**, so only one set of containers is ever in the
DOM.

## A SUPERVISOR SEES ONLY THEIR OWN CENTRES, AND THE CHECK IS SERVER-SIDE

Three roles since 19 Aug 2026 (`app_users`): **administrator** and **manager** see every centre
and differ only in who may open User access; a **supervisor** is assigned one or more centres and
does the full job inside them. **The role decides WHERE, not WHAT.**

**Hiding the centre bar is not a restriction.** `/api/waitlist?centre_id=3` is the door, so
**every endpoint taking a centre id calls `api_require_centre()`** - and for the many actions
that name a room, placement, application, offer, work item or comms entry instead, the guard runs
**after** the lookup off that row's own `centre_id`. **Adding an endpoint means adding the guard
in the same breath**, exactly as the casual test is part of writing a new `getBookings` read.

Scope is **re-read every request**, never stored in the session, so revoking somebody takes
effect on their next click. `PHP_SAPI === 'cli'` marks the nightly jobs as the system and exempts
them - without it cron would silently trim everything it touched.

**Search deliberately stays group-wide for everybody** (a parent rings the wrong centre; that is
the normal case) and trims instead: outside their centres a supervisor gets a name, a centre and
nothing else. See **[auth-security-ops.md](_incl/docs/auth-security-ops.md)**.

---

# Tech stack

| Layer | Technology |
|---|---|
| OS | Debian 13 |
| HTTP | nginx 1.30.2 |
| Backend | PHP-FPM 8.5.7 (no framework) |
| CSS | Bootstrap 5.3.8 (vendored) |
| JS | jQuery 3.7.1 (vendored) |
| DB | MariaDB 11.8.6 |

**Hard constraints:**

- **No Node, no bundler, no Composer. Zero build step.** (This is why the data-viz colour
  validator was ported to PHP.)
- **No CDN dependencies**; all third-party assets vendored into `static/`.
- **Pin all library versions exactly as listed.**
- **Bootstrap-first** - reach for utility/component classes before writing custom CSS. Custom
  styles are minimal and live in `static/css/app.css`.
- **jQuery-first** - use it for DOM, events and AJAX. Avoid vanilla DOM APIs unless jQuery
  genuinely cannot do it.

**The code is in git, on GitHub as `TuggerahServices/CRM` (private), since 11 Nov 2026**, linked
from the Magic panel. **Do not commit, push or branch unless asked.** An edit is live the moment
it is saved and uncommitted work is in no backup (the server's nightly backup skips `.git`), so
**still copy a file to the scratchpad before a large rewrite.**

## Database

Server `localhost`, database `crm`, user `crm_user`; the password is in `_incl/db-config.php`
and nowhere else. **This user can only access the `crm` database; do not create or access any
other.** (The database was `attendances` with user `attendances_user` until 4 Nov 2026;
both were dropped in the move to `crm`.)

Config in `_incl/db-config.php`. `SimpleMySQLi` and the `dbx()` singleton are in
`_incl/simple-mysqli.php` - **a vendored library; use it, do not rewrite it.**

```php
$rows = dbx()->query('SELECT * FROM foo WHERE bar = ?', [$val])->fetchAll();
$row  = dbx()->query('SELECT * FROM foo WHERE id = ?', [$id])->fetch();

dbx()->query('INSERT INTO foo (a, b) VALUES (?, ?)', [$a, $b]);
$newId   = dbx()->insertId();
$changed = dbx()->affectedRows();

$marks = dbx()->whereIn($ids);                    // "?,?,?"
dbx()->query("SELECT * FROM foo WHERE id IN($marks)", $ids)->fetchAll();

dbx()->query('SELECT * FROM foo WHERE id = ?', [$id], 'i')->fetchAll();  // s/i/d/b
$map = dbx()->query('SELECT id, name FROM foo')->fetchAll('keyPair');    // [id => name]

dbx()->transaction(function($db) {                // auto-rollback on exception
    $db->query('INSERT ...', [...]);
});
```

## Secrets

**No secret is in git, and `.gitignore` keeps it that way.** The first push (11 Nov 2026)
carried all three below; the commit was rewritten without them before anything was built on it.

| Secret | Kept in | Why there |
|---|---|---|
| Database password | `_incl/db-config.php` | The Magic panel writes it, and a branch download keeps this one file |
| Kidsoft ServiceKey | `/etc/crm/kidsoft-config.php` | Outside the folder: a branch download runs `git clean -fdx`, which deletes every ignored file in it except `db-config.php` and what `crm` owns |
| Entra private key + cert | `/etc/crm/entra-signing.key`, `.crt` | Same. With the IDs in `entra-config.php` it can send mail as anyone in the tenant |

`/etc/crm` is `root:crm` 0750, files 0640: the site reads them and cannot change them. It is in
the server's nightly backup (`/etc/serveradmin/backup.json`). **A new secret goes in `/etc/crm`,
never in the folder.**

Fetch modes: `assoc` (default), `num`, `obj`, `col`, `keyPair`, `group`, `keyPairArr`.

---

# Directory layout

```
/
├── index.php            # / - redirects to /dashboard (or /placement for a
│                        #      deep-link carrying room= or wd=)
├── dashboard.php  placement.php  rooms.php  waitlist.php  progress.php  tenure.php
├── support-plans.php    # Behaviour Support Plans, group-wide
├── support-plans-report.php  # the same, printable: one centre, the group, or one child
├── marketing.php        # free places with nobody waiting for them, ranked
├── offers.php  fill.php  drift.php  occupancy.php  rollover.php   # RETIRED: 301s
├── auth/                # login.php, callback.php, logout.php (Entra OIDC)
├── _incl/               # include-only - nginx 404s ALL direct requests
│   ├── docs/            # <-- the module documentation indexed above
│   ├── retired/         # GRAVEYARD, kept on purpose - loaded by nothing, sometimes
│   │                    # useful. Retire by MOVING here, never by deleting.
│   └── littlemiracles_branding/
├── api/                 # AJAX/JSON endpoints only
├── errors/              # HTML error pages (rendered by nginx for 4xx/5xx)
└── static/  css/ (app.css, fonts/)  js/ (+ lib/ for jQuery, Bootstrap)
```

## Key layout rules

- **`/_incl`** - include-only: templates, config, helper libs, **and the docs**. Never link to
  it from the client; nginx returns 404 for the whole tree.
- **`/api`** - AJAX/JSON endpoints only. **If a response is not JSON, it does not belong here.**
- **`/static`** - all CSS, JS and fonts. Vendored libs in `static/js/lib/`.
- **THE WEB ROOT PUBLISHES ANYTHING YOU LEAVE IN IT.** `try_files` serves any existing file
  before the request reaches PHP - no auth, no session. **If a file does not need to be fetched
  by a browser, it does not belong in the root.**

---

# Routing and URLs

- **The address is `crm.tuggerahservices.com.au` and the folder is `/var/www/crm`** (moved from
  `attendances` on 4 Nov 2026, together with the database). The `attendances` vhost only 301s
  here with the path and query intact, and `/var/www/attendances` is empty on purpose with no
  database behind it. **Put nothing in it.**
- **nginx strips the `.php` extension. All internal `href`s must omit it** (`/dashboard`, not
  `/dashboard.php`).
- **Never create a PHP file with the same name as a directory** (no `api.php` beside `/api/`).
- **`/` IS THE DASHBOARD; Placement is `/placement`.** `index.php` **redirects** rather than
  rendering, so there is exactly one URL per screen - two paths rendering one page means the
  centre bar, the sidebar and any link pasted into Teams disagree about where they are.
- **The root means the WHOLE ORGANISATION.** `index.php` forwards `centre=0` whenever the request
  names no centre of its own. An explicit `?centre=` still wins.
  **The sticky centre is untouched everywhere else, and that is why the fix is in `index.php` and
  not `centre_current()`** - moving between SCREENS keeps your centre deliberately; coming back
  to the front door is a different act.
- **A Placement-shaped deep-link (carrying `room` or `wd`) is forwarded to `/placement` intact.**
  Without that a search hit would land on the Dashboard and silently lose the cell it was taking
  somebody to.
- **Retired pages 301 to `/placement`**, not `/` - they were retired INTO the cell grid, and the
  root would answer a different question and drop the centre and year they carry. `/offers` 301s
  to `/progress` carrying `?centre=`.

---

# Templating and API shape

Include-based, no engine. A protected page:

```php
require_once __DIR__ . '/_incl/auth.php';   $user = auth_require();
require_once __DIR__ . '/_incl/layout.php';

$centres  = centres_all();
$centreId = centre_current($centres, /* $allowAll = */ false);
layout_header('Title', $user, 'board', [
    'centres' => $centres, 'centre_id' => $centreId,
    'all_label' => null,           // a string renders an "All centres" tab
    'params' => ['year' => $year]  // carried across a centre switch
]);
// ... HTML ...
layout_footer(['/static/js/page.js']);
```

`layout_header()` publishes the CSRF token as `window.CSRF`; `static/js/app.js` attaches it to
every mutating jQuery AJAX call, so endpoints only need `csrf_require()`.

**API endpoints follow one shape:** `auth_require_api()`, then require POST + `csrf_require()`
for anything mutating, then `switch ($action)`.

**Readable failures use `api_fail()` (HTTP 200 + `ok:false`) because nginx discards the body of a
real 4xx under `/api/`. So EVERY JS caller must check `res.ok` in `.done()`, not only
`.fail()`.**

## The CSP forbids inline script

**`script-src 'self'`, no `unsafe-inline`.** No inline `<script>`, no `onclick=`/`onchange=`
attributes anywhere. **Adding one will silently break the page.** `'unsafe-inline'` remains for
**style** only.

## No browser dialogs: never `alert()`, `confirm()` or `prompt()`

Broc, 10 Nov 2026: the browser's own boxes do not work well in some of the browsers the team
uses. **Every question is a Bootstrap modal**, and a yes/no or one-line-of-text question is
`window.confirmModal()` in `app.js` - it stacks over an open dialog, holds the button that asked
while the write is out, and takes an input for what `prompt()` used to do. Use `.done()` on it,
never `.then()`. A message is a `toast()`. See "No browser dialogs" in
[ui-conventions.md](_incl/docs/ui-conventions.md), which has the grep that must come back empty.

---

# Habits that catch this codebase's recurring bugs

- **Looking at the page IS a check.** Several bug classes here are invisible to a lint, a parse
  check or an engine test - CSS specificity losses, a blank glyph, a wrong hover colour, a
  clipped column. Use the `webshot` skill; see
  [auth-security-ops.md](_incl/docs/auth-security-ops.md) for minting a signed-in session.
- **And a screenshot is not enough for anything behind a click.** One bug opened the wrong
  child's editor on every board in the app and looks entirely correct once the modal is open.
  Drive Chromium and click.
- **When you change an engine's return shape, grep the JS for every field you removed.** Dump the
  endpoint's JSON and diff its keys against every `data.*` / `r.*` / `cell.*` the JS reads. A
  removed field became `!undefined` once and swallowed every click on the placement grid.
- **When a field can become null, grep for every place it is used as an array key - in BOTH
  languages.** The same null broke PHP and JS three months apart.
- **Use an edit that fails loudly, never a blind `str.replace`.** A silently no-op'd replacement
  left a function called and never defined; a non-greedy regex deletion silently removed the
  highest-priority list in the app and nobody noticed for a day. **When deleting a block by
  pattern, print what was removed and diff the section list afterwards.**
- **Re-measure layout rather than reasoning about it.** Column widths, overflow and document
  height have all been wrong in ways that look fine at one viewport.
- **PHP lints clean with a missing function** - that is a runtime error. Check the endpoint
  actually responds.
