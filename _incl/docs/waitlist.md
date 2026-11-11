# The waitlist: queue, priority, siblings, offers

Governs `waitlist.php`, `api/waitlist.php`, `static/js/waitlist.js`, and the engines
`_incl/waitlist.php`, `waitlist-import.php`, `waitlist-group.php`, `siblings.php`,
`do-not-enrol.php`.

The queue is where most families enter the pipeline, because offers are made here.

## The queue is IMPORTED from Kidsoft, not typed

`_incl/waitlist-import.php` feeds `waitlist_applications` from `Waitlist/getWaitingList`.
Before it existed the table sat **empty**, because nobody will hand-key 200 families - and
they never needed to. **722 open applications** live today where there were zero.

**THERE IS NO WAY TO TYPE ONE IN (Broc, 6 Nov 2026).** The Add button is gone from the queue and
`save` refuses a row without an `id`. A family who rings before applying is entered in Kidsoft
and appears here on Refresh. **Why:** only one row was ever hand-keyed in the life of the table
(Maisie Morton, Point Clare, 4 Nov 2026), and the family's real application was imported beside
it the next night - a hand-keyed row has no `WaitListID`, so the import cannot match it, and the
duplicate check (`waitlist_import_duplicates()`, exact name + DOB) missed it because the typed
DOB was two days out. That row was deleted the same day at Broc's request, its work item and
note moved onto the Kidsoft row (1072): **no hand-keyed rows exist now.** **Do not bring an Add
path back** without a way to match it to Kidsoft.

Read that file's header before changing any of it.

### The ownership line

**"Kidsoft owns what the family declared, we own every decision."** Written down in one place,
`WAITLIST_KIDSOFT_OWNED`, used by both the importer that writes those columns and the `save`
action that refuses to let a human overwrite them. Kidsoft-owned fields are **disabled in the
editor**, not merely discouraged.

### Import traps

- **THE ON-VIEW IMPORT READS LIVE, AND THAT IS LOAD-BEARING** (9 Sep 2026). It used to read
  `kidsoft_cached()` at the default 900s TTL, justified in the code as "the same 900s as the
  import throttle, so the cache cannot have changed". It can. They are **two independent
  clocks**, so by the time the throttle let an import run the cache was at or past its own
  expiry, which put every import on `kidsoft_cached()`'s **stale-while-revalidate** path
  (`kidsoft-cache.php:163`): it returned the OLD payload, imported that, and refreshed behind
  `fastcgi_finish_request()` - leaving the fresh copy in `kidsoft_cache` with nothing to import
  it, because `audit()` had just reset the throttle for another 900s.
  **What that broke is the archive detection specifically, because it is an ABSENCE test, and
  absence in a stale snapshot is not absence.** Measured mid-afternoon on 9 Sep against a live
  read of all seven centres: **55 of 690 open rows were families already archived in Kidsoft** -
  Wamberal 39, Tuggerah 11, Terrigal 3, Niagara Park 1, Point Clare 1 - every one carrying a
  `kidsoft_synced_at` from that same day, because the import had faithfully re-imported a
  payload that still contained them. **Nothing Kidsoft OWNS was ever wrong**: 0 missing rows and
  0 name, DOB, phone or requested-weekday mismatches across 635 open rows, 0 unmapped statuses.
  Only the disappearances lagged, and they lagged until the 02:00 nightly - the one run that
  passed `refresh` and read live. Cost of the fix: **0.4s per centre, 2.4s for the group**, at
  most once per 900s per centre, with `KIDSOFT_SHARE_FRESH` collapsing simultaneous viewers into
  one call. **If you ever put this back on the cache, you are re-introducing the same bug**;
  `waitlist.php` has always budgeted for the live cost in its own comment.
- **A count nothing prints is a count nobody checks.** `archived` was missing from the nightly's
  `$tot` in `cron-nightly.php`, so the log never reported an archive from the day the feature
  shipped (8 Sep) to the day staff complained (9 Sep). Added.

- **`status` is ours**, and a pending offer HOLDS days in `room_day_vacancies()`. An import
  that reset a row to `waitlisted` would free a seat two staff could then promise to two
  families. The import only ever moves a row between OPEN and CLOSED, and **never touches**
  `offered` / `partially_placed` / `placed`.
- **KIDSOFT'S IDs ARE TAKEN WHATEVER THE STATUS, AND NEVER CLEARED** (17 Aug 2026).
  `kidsoft_child_id` / `kidsoft_guardian_id` are the only identity Kidsoft itself supplies, and
  **the ChildID arrives at the conversion** - 17 of 17 `Enrolled` rows carry one against ~0% at
  every other status, measured over all 1,699 rows. `Enrolled` maps to `placed`, `placed` is
  CLOSED, and the loop's `if (!$isOpen) continue;` fired **before** the id was ever written: the
  one read that could hand us the family's identity was the read the import threw away. **Nine
  families we had tracked through the queue had enrolled in Kidsoft and still read NULL here.**
  The ids are now written for every row we hold, before that `continue`, and the open-row
  UPDATE no longer writes them at all - one statement owns one fact, and `COALESCE` keeps a
  held id when a later read omits it (the closed statuses send no ChildID, so plain assignment
  would take the identity back on the next sync). Counted as `linked` and reported by the
  nightly cron. What it unlocks is on the other side of the app: `comment_push()` can reach the
  family, and `work_autoclose()` can finish their In progress item - see
  [work-layer.md](work-layer.md).
- **`for_year` is SEEDED by Kidsoft on insert and never updated**, because which year we plan
  a family into is a judgement. A sync that reverted it would be the silent-destruction-of-
  edits failure.
- **`priority_of_access` WAS in that group and is now REFRESHED on every import** (13 Aug
  2026). The judgement it was reserved for had **never once been made**: across 743 imported
  applications the stored value agreed with Kidsoft on **all 743**, zero divergences. What
  forced the change is what the freeze was costing - the centres are clearing their
  sibling-workaround `1`s, and every affected row already existed, so a seed-on-insert column
  could never have received that correction. **The team would have done the work and the queue
  would have looked identical.** Verified end to end by stranding a real row at `1` where
  Kidsoft says blank and re-running the import, which healed it to 3. A hand-keyed row keeps
  its editable dropdown, since there is no Kidsoft value to sync.
- **Kidsoft's `Notes` fills an EMPTY notes column only**; it never replaces what a human wrote
  after a phone call. **`StatusReason` is deliberately NOT imported** - it is machine audit
  text, and one real Tuggerah row repeats *"Parent has confirmed interest in staying on the
  waiting list."* **71 times**, 4KB of it.
- **Closed applications are NOT imported unless we already hold them** - with ONE named
  exception, `WAITLIST_IMPORT_WHEN_CLOSED`. 948 of 1,692 rows are `ExpiredWaitlist`; pouring
  those in would bury the 708 that matter. **They ARE searchable without being imported** -
  `search_attach_kidsoft_history()` reads them live and cached, see
  [search-identity.md](search-identity.md). Do not "solve" that by importing them.
- **`ExpiredOffer` IS IMPORTED WHETHER OR NOT WE HOLD IT** (9 Sep 2026, Broc: *"Offer Expired
  are not showing up in the waitlist. Can this please be visible?"*). It was invisible two ways
  at once. **Measured live across all seven centres: 496 `ExpiredWaitlist` against 10
  `ExpiredOffer` group-wide, and 5 of those 10 were held here while 5 were not** - Niagara
  Park's four and Wamberal's one had been closed the first time the import saw them, so the
  skip above dropped them and they appeared on no screen at all. The other five read
  **"Expired"**, the same word as the lapsed waiting places they sit among.
  **Ten rows bury nothing, and they are not the same fact:** a waiting place expires because a
  parent stopped answering Kidsoft's reminder emails; an OFFER expires because a place was put
  in front of a family and nobody closed it out, which is this team's own work coming back.
  They stay CLOSED - no queue position, visible under "All" only - which is the treatment
  `ExpiredWaitlist` already gets and the line Broc drew on 8 Sep between expired and archived.
- **`kidsoft_status` HOLDS KIDSOFT'S RAW STATUS FOR EVERY ROW WE HOLD, open or closed.** Our
  `expired` is two Kidsoft statuses collapsed into one and every engine here is right to treat
  them alike, so the split is in the LABEL and nowhere else: `waitlist_status_label()` in
  `_incl/waitlist.php` returns **"Offer expired"** for `expired` + `ExpiredOffer` and the plain
  label otherwise. Composed server-side and read by the queue, the group register and search,
  so one fact has one wording on three screens. Null on a hand-keyed row, which has no Kidsoft
  status to have. It is written **above** the `!$isOpen` return, next to the ids and for the
  same reason - one statement owning one fact, on every row, whatever its status.

### "Which applications to show" is ONE control with THREE answers

`#closed-filter` on `waitlist.php`: **Open** (the live queue), **All** (plus the closed
history), **Offer expired** (the slice of that history the team asked to reach). A third
segment rather than a separate chip beside it, because two controls on one axis is how a
screen starts contradicting itself about what it is showing. The label is the badge's own
words - the chip that fetches the row says what the row says, see `waitlist_status_label()`.

The browser sends one parameter, `show`; `waitlist_show_opts()` in `api/waitlist.php` is the
only place it is resolved, because **both** the per-centre queue and the group register read
it. `offer_expired` implies `include_closed` - an expired offer IS closed, so the closed rows
have to be in the set before the slice can be taken out of them.
`waitlist_is_offer_expired()` is the one definition of the test, shared by the label, the
queue's filter and the register's.

**ON THE REGISTER IT IS A FAMILY FILTER, NOT AN APPLICATION FILTER, AND LIVE DATA SAYS WHY.**
`waitlist_group_all()` already carries the note for `q`: narrowing per centre "would drop the
very applications that prove a family is waiting in two places, which is what this view is
for." The first version of this chip walked straight into it. **2 of the 9 families with a
lapsed offer have already re-applied** - Savannah Jarvis at Niagara Park (now #107) and Zoe
Lahanas at Kanwal (now #13) - and narrowing per centre showed each of them as nothing but a
struck-through dead offer. **The team would have rung two families who are already back in
the queue.** So it is applied to `$families` beside `multi_only` and `q`, after the
aggregates, and the tiles stay register-wide exactly as they do for every other filter there.

**A side-effect worth keeping: `Most wanted day` now withholds.** Every row under this
segment is closed, so no row contributes demand, and a plain sort still hands back a key --
the tile read "Mon - 0 families want it", a figure contradicting its own caption. It reads
`-` with no caption when nothing is wanted. Reachable before this in any search that matched
only closed applications; reachable in one click now, which is what surfaced it.
- **THE IMPORT HAS NEVER SEEN AN ARCHIVED ENTRY.** `getWaitingList` takes `ShowArchived`, it
  **defaults to false**, and no call here has ever passed it: 1,698 rows in the default window
  against **5,221** with it, so **3,523 applications are invisible to this file.** That is correct
  for the queue - they are closed, and archived on top - but it means the census figures in this
  document describe the unarchived window only, and the archived set contains three statuses the
  map above has never seen: `RejectedByService` (555), `DeletedByService` (484), `RejectedByParent`
  (21). **If closed rows are ever imported, those need mapping first.**
- **`Re-submit Required` IS MAPPED** (this said unmapped after the code had moved on). Re-read
  15 Oct 2026 by the measure below - OfferedDays present, a 21-day `DateAdded`->`ExpiryDate`
  window - as the offer side of the funnel, so it holds a place. See **Offers**.
- **`RejectedByParent` IS MAPPED, to `withdrawn`** (15 Oct 2026). It is the parent turning an
  offer down, and it reached the default window: Ari Flew at Tuggerah went Offered ->
  RejectedByParent on 14 Sep and was skipped as unknown for a month, frozen at "Offered".
  **The census paragraph below said it was only ever seen archived - that stopped being true.**
- **A hand-keyed row has no `WaitListID`** so it can never be matched or overwritten - but it
  CAN duplicate a family Kidsoft also holds, so likely duplicates are **reported, never
  merged**. Two applications for one child is itself real here (Tuggerah has a child with two
  open `WaitListID`s), so a machine cannot tell the two cases apart.
- **`RequestedDays` is unioned across BOTH care types and week numbers.** Only week "1" exists
  today; a fortnightly roster would otherwise lose half its days with nothing reporting it.
- **An unmapped status is skipped and REPORTED, never quietly filed as "waitlisted".** Keep
  that property - the vocabulary is one Kidsoft release away from growing, and it **grew in
  ten days**: `OfferedExpiryReminder` appeared 10 Aug 2026, a status the map had never seen.
  That family had been skipped by every import since, held at whatever status they last had
  and never refreshed, and nothing surfaced it - `unknown_status` is returned but only the
  cron log reads it. **Re-run the census before trusting the list**, and if a screen ever
  needs to know, `unknown_status` is the field to surface.

**Status census, 10 Aug 2026 over 1,701 rows:** `ExpiredWaitlist` 954, `Waitlisted` 711,
`Enrolled` 17, `ExpiredOffer` 8, `ExpiryReminderFirst` 4, `ExpiryReminderSecond` 4, `Offered`
2, `OfferedExpiryReminder` 1.

**Re-censused 9 Sep 2026 over 1,194 rows** - live, all seven centres, the import's own window
(`DateAddedFrom` 2010-01-01, no `ShowArchived`): `Waitlisted` 602, `ExpiredWaitlist` 496,
`Enrolled` 50, `ExpiryReminderFirst` 12, `ExpiredOffer` 10, `Form Submitted` 10, `Offered` 8,
`DeletedByParent` 3, `OfferedExpiryReminder` 1, `ExpiryReminderSecond` 1, `Re-submit Required`
1. **The window shrank by 533 rows in a day and that is the centres archiving, not a short
read** - archiving removes a row from `getWaitingList` entirely, and almost all of what went
was closed and had therefore never been imported. `Re-submit Required` is still unmapped and
still live.

**Kidsoft's own `Offered` status still does NOT map to our `offered`**, which means "there is an
`offers` row". It maps to `waitlisted` - **but since 15 Oct 2026 the offer itself is READ**:
`OfferedDays` and the room are stored, the board holds them and the row is labelled with
Kidsoft's word. The "check Kidsoft first" note it used to seed is gone. See **Offers** below.

### What `getWaitingList` actually gives you

With `WithDetails=true&WithRequestedDays=true&WithOfferedDays=true&WithCareTypeMap=true` (plus
the required `DateAddedFrom`/`DateAddedTo`) - a whole group is 7 calls and ~2.7s:

- `Details.EnrolmentData.ChildDetails` - first/last name, **DOB 100%**, gender
- `Details.EnrolmentData.ParentGuardianOne` - name, **`MobilePhone` 100%**, email 99%
- `Details.FamilyAddress` - `Suburb`/`PostCode` on 89.1%, 33 distinct postcodes
- `RequestedDays` - **weekdays, 100%**, as `{CareType: {Days: {WeekNumber: [DayName,...]}}}`
- `CareTypeMap.RequiredFrom` (100%), `RequiredTo`; `CareTypeMap.RoomID` is sparse (6%)
- `DateAdded` (queue position), `PriorityOfAccess`, `ExpiryDate`

**Useless despite appearances:** `StaffMemberNumber` **0%** populated (cannot flag a staff
child); `HearAboutUs` **1%** and a numeric code (cannot fill `lead_source`);
`GuardianWorkStatus` **empty on all 713** (looks like the activity test, cannot answer it --
genuinely absent, read with every `With*` flag passed); `SPPreferenceLevel` reads `None` on
79.7% and is absent on the rest.

## `Waitlist.ExpiryDate` - the lapse clock nothing used to see

> **SWITCHED OFF IN KIDSOFT, 11 Nov 2026** (Broc: the "Waitlist expire" option is turned off).
> The Waitlist's **"Expiring soon" tile and the per-row "expires ... / lapsed ..." line are
> gone**, replaced by **"Newly added"** (below). Measured that day: **229 of 703** open
> `Waitlisted` rows still carried an old `ExpiryDate`, **52 inside the 30-day window** - every
> one counting down to a lapse that will not happen. New applications had mostly stopped getting
> one already: **Sep 6 of 41, Oct 4 of 56, Nov 1 of 27** (date added, open `Waitlisted`).
> `kidsoft_expires_at` is still imported because **it is also an OPEN OFFER's deadline**
> (`waitlist_kidsoft_offers()`, `_incl/board.php`, `_incl/work.php`) - that is untouched.
> **The same day, at Broc's request, the Dashboard's "Lapsing" tile, column, banner and "Needs
> attention" row went too** (see [dashboard.md](dashboard.md)), **and the search palette's
> "Expires in Nd" / "Lapsed" tag** (`expires_in_days` is gone from `search_attach_waitlist()`'s waitlist
> lines). Nothing in the app now counts down to a waiting place's ExpiryDate. The rest of this
> section is history.

Kidsoft expires an application after emailing the PARENT twice (`ExpiryReminderFirst`, then
`Second`, then `ExpiredWaitlist`) and **nothing in that loop reaches the enrolments team** --
so a family lapsed off the queue and took an unfilled place with them. Populated on 89% of
open rows; **90 open applications group-wide expire within 30 days** and 97 more in the month
after.

Stored as `kidsoft_expires_at`, with `expires_in_days` computed **server-side** (removed from
the queue rows 11 Nov 2026) - these are Sydney wall-clock dates and `new Date()` in the viewer's
timezone is exactly the reinterpretation `_incl/dates.php` forbids. `WAITLIST_EXPIRY_SOON_DAYS`
(30, retired 11 Nov 2026) was published to the browser so the threshold had one definition.

**Grading is app-wide: already lapsed or inside 7 days is red, inside 30 days is amber.** The
Waitlist tile was red while the Dashboard reported the identical figure as amber. **The same
fact must not be two severities on two screens.**

**No expired Kidsoft entry reaches the OPEN queue** - `ExpiredWaitlist` maps to `expired` on
import, and **0 of 722 open applications carry an expiry date already past**. (Expired rows do
reach the "All" view, and since 9 Sep an expired OFFER reaches it whether or not we held the
row - see the import traps above.) The 11 open rows
Kidsoft does not call `Waitlisted` are all on the reminder ladder or hold a live offer, which
is exactly what "Expiring soon" was for.

### "Newly added" (11 Nov 2026)

The tile in "Expiring soon"'s place: open applications whose `date_added` falls in the last
**`WAITLIST_NEW_DAYS` (14)** days, and a **"new" tag under the row's date added** so the count
can be found in a table sorted by priority (the tiles are not clickable). 14 days because a
fortnight survives somebody being away for a week; that day it was 1 to 7 per trading centre,
36 applications group-wide.

- **The cut-off is a Sydney date from the server**: `waitlist_new_since()`, published as
  `new_since` (with `new_days` for the caption) by both the `list` and `group` actions. The
  browser compares it with `date_added` as a plain `yyyy-mm-dd` string, so no browser timezone
  comes into it.
- **The centre tab counts the rows on screen**, like the tiles beside it, and skips closed rows.
- **The register counts FAMILIES whose FIRST application is that recent** (`totals.newly_added`,
  26 that day), the date the row prints. A family waiting at Terrigal since March who applied to
  Wamberal last week is new to Wamberal's queue, not to the organisation. Under "All", a family
  with nothing open is not tagged.
- **No colour on the tile.** A new family is not a warning.

## The priority ladder (`priority_rank()`)

Current order, top first:

1. `is_reenrolment`
2. Staff child
3. **Already in care** (`in_care`)
4. **Priority of Access**
5. Sibling
6. `date_added`

**`is_reenrolment` - the top rung - is set on ZERO of 722 open applications.** Nothing has
ever written it. Left in place (the round runs in Kidsoft; if it ever feeds this table, that
is where it belongs) but **it is dead today**.

### In-care outranks Priority of Access (11 Aug 2026, Broc's call)

`priority_of_access` used to sit directly under staff children, carrying the comment
"statutory, so it stays above anything this organisation decides". The consequence was that an
external family on the work/study test outranked a family **already in the room** asking for
one more day - and **28 of the 34 in-care families group-wide were outranked by at least
one**, all 12 at Tuggerah. The normal case, not an edge one. Broc found it by reading the
priority column. After: **0**, on 132 of 719 positions changing.

**THE RUNG IS RIGHT AND THE OLD REASONING WAS WRONG.** An earlier note argued the field was
85% blank and therefore noise. Re-censused over 713 open applications with `WithDetails`:

| PriorityOfAccess | n | Guardians on the application |
|---|---|---|
| (blank) | 605 | two, every one |
| 2 | 83 | **76 of 83 have ONE guardian** |
| 1 | 23 | two |
| 5 | 2 | two |

**All 76 sole-guardian applications carry 2, with none missed.** That is the statutory
sole-parent limb being set systematically - so **a blank is a real answer** ("two guardians
named"), not a gap. The rung does not move; Broc's call stands and the policy reads "we
**may** prioritise". But do not repeat the "85% blank means noise" argument.

- **The `1`s were a sibling workaround - CONFIRMED, not inferred** (Broc, 13 Aug 2026): the
  centres used `1` to track siblings because **Kidsoft had no other way to record one**, and
  the team has committed to clearing them. **Clearing them is safe because the queue no longer
  leans on the field:** 22 of the 23 are ranked by live sibling or in-care detection and keep
  their place without it. The one exception is `Tbc Gilberd` at Tuggerah, whose placeholder
  name defeats the matcher - `is_sibling` is the manual override for exactly that.
- **POA still orders correctly WITHIN its band** - verified on Tuggerah's 84 ordinary
  applications: at-risk, then work/study, then other, ascending.
- **`5` is still unexplained** (2 rows) and the importer **refuses to guess it**. Only 1 and 2
  are safe to map; guessing what 5 means would silently reorder a queue.

### Already in care (`waitlist_mark_in_care()`)

**35 open applications (4.8%) belong to a child who is ALREADY ENROLLED** - 17 at the same
centre wanting more days or next year, 18 transferring from another Little Miracles centre.
Nothing ranked them, so they queued behind external families purely on `date_added`, which
inverts the policy.

Matched on folded **name + DOB against ACTIVE children only** - stricter than `siblings.php`'s
phone/email rule, because this promotes a family over others who applied earlier. **The reason
is printed on the row** ("Already in care at Tuggerah") so a human sees what the machine saw.

#### The days they ALREADY HOLD are green, and that changes what blue means

`enrolled_days_index()` (`_incl/waitlist.php`) -> `attending_days` on the row -> `.dbox.db-has` /
`.dbox.db-extra`, plus the named line in the family modal.

**THE BADGES ARE `window.dayStrip()` SINCE 3 SEP 2026** and are the same five boxes as the cell
panel, Search, the Dashboard and In progress - see
[ui-conventions.md](ui-conventions.md). Two things moved with it, both reported by the team:
**the demand went from purple to `--lm-req` blue**, which is what the rest of the app already drew
"asked for this day" in, and **the green got darker** (`--lm-good-mid` under a solid `--lm-good`
edge, where it was a `--lm-good-wash` fill at 1.1:1 and a 1px `--lm-good-mid` ring). What each
badge MEANS is decided here and has not changed.

An in-care family's row used to show five badges that **could not tell the days they hold from the
days they are asking for**, which is the only question worth asking about them. So:

| badge | state | meaning |
|---|---|---|
| green FILL | `has` | asked for, and already theirs - nothing to find |
| blue | `want` | asked for, and NOT theirs - **the demand** |
| green EDGE on grey | `extra` | theirs, and NOT on this application |
| grey | - | neither |

**THE CALLER SPLITS THE THREE SETS**, because only this screen has an application to compare
attendance against; `dayStrip()` does not infer them. Inferring `extra` as "held and not asked for"
would have come out right here and ringed every single day on the cell panel's room roll, which has
no asked-for set at all.

**THE RULE THAT MAKES THE FOUR STATES SAFE: green fill + blue is EXACTLY the set the application
asked for.** The first version broke it and Broc caught it on **Aleigha Grant at Tuggerah**, who
asks for Mon-Thu and attends Mon, Tue, Wed **and Friday**. Drawing every attended day in one green
put a Friday nobody had asked for into a column headed "Days wanted", so the row read
Mon/Tue/Wed/Fri while the modal's Days requested ticked Mon/Tue/Wed/Thu - **two screens describing
one family differently, which is the failure this file keeps arriving at.** The ring adds what they
hold without joining the asked-for set.

- **A day they hold and asked for again is green rather than blue**, because it is not a day
  anybody has to find them. **4 of the 17** are asking for nothing they do not already have, and
  that is visible at a glance: the row has no blue left on it.
- **THE MODAL PAINTS THE SAME FOUR STATES ONTO ITS DAY TOGGLES** (`.wl-att` in
  `waitlist-modal.js`), and that replaced a sentence - "Already attends here on Mon, Tue, Wed,
  Fri. New on this application: Thu." - which sat under a row of toggles showing a different set
  and so reproduced the two-descriptions-of-one-family problem inside the dialog (Broc, 17 Aug
  2026). One CSS rule does both green halves, because a Bootstrap button reads its own custom
  properties: unchecked takes `--bs-btn-color` / `--bs-btn-border-color` and comes out as the EDGE,
  checked takes `--bs-btn-active-*` and comes out as the FILL. Overriding the properties rather
  than the declarations is also what keeps the DISABLED state right, which is the normal case --
  a Kidsoft-owned row's toggles are disabled and Bootstrap dims them with opacity.
  **`.wl-dbtn` is the blue half** and does the same to `.btn-outline-primary`, whose purple is now
  the strip's "the day you are looking at" and not its "asked for". **`.wl-att` is DOUBLED
  (`.wl-att.wl-att`, (0,2,0)) so it beats `.wl-dbtn`** rather than leaving the winner to source
  order - the trap the heat classes and `.mk-bar.wl-bar-shared` both document.
  **The classes are cleared on every open**, or one dialog reused by every row on two screens shows
  the previous family's week. **No caption and no key** - a colour key was tried and removed the
  same day (Broc): the colour is the same one the queue's strip uses, the titles say it on hover,
  and the days themselves are on the toggles. `api/waitlist`'s `get` runs the row through
  `waitlist_mark_in_care()` for it - the same function, on one row, off an index the request has
  already built.
- **A KIDSOFT ROW'S DAY TOGGLES ARE READ-ONLY, NOT GREYED** (Broc, 17 Aug 2026). Bootstrap dims a
  disabled `.btn-check` label to 65%, so on 89% of rows the days a family asked for - the thing
  everybody opens this dialog to read - were the faintest thing on the screen. **The `disabled`
  attribute stays on the INPUT**, which is what keeps those days out of the POST so `save` re-reads
  the stored value; only the dimming goes, and `pointer-events` comes back with it so the green
  days' titles are reachable. Clicking a label whose control is disabled does not toggle it --
  verified by forcing clicks on both an asked-for day and a not-asked-for one, neither moved. **The
  lock banner at the top of the modal is what says the row is Kidsoft's**, not the dimming.
- **ONLY THE APPLICATION'S OWN CENTRE.** 15 more in-care applications belong to a child attending a
  DIFFERENT Little Miracles centre. A Monday held at Point Clare is not a Monday held at Tuggerah -
  it is a transfer, and the day is still a place this centre has to sell, so colouring it would
  hide real demand. Those families are not left unexplained: the Priority column already says
  "Already in care at Point Clare".
- **Read from `board_live_children()`**, the app's one definition of the standing weekly
  arrangement, so this cannot disagree with the Placement board about which days a child holds --
  and casuals are excluded there rather than tested again here. Current year, one reference month
  per centre: **0.06s for all seven on a warm cache**, `/api/waitlist?action=list` 0.147s -> 0.195s.
  A cold cache degrades to no green rather than to a wait, the same way `in_care` and the sibling
  match do.
- **"Most wanted day" counts the BLUE days only** (17 Aug 2026). It used to count every requested
  day, so a family already in the room on Monday asking to add a Wednesday was counted as wanting a
  Monday place - and once the strip drew that Monday green, the tile and the badges were reading
  the same rows differently. **Measured before changing it: the top day is unchanged at all seven
  centres and the counts move by 0 to 4** (Terrigal's Wednesday 100 -> 96 is the largest).
- **The register tab draws no green**, and that is deliberate: its Days column is the union across
  every centre a child is waiting at, so "already attending" has no single answer there.
- **One class per badge, never two.** `.db-want`, `.db-has` and `.db-extra` are all (0,2,0), so
  which one won would be source order rather than a decision - `dayStrip()` emits exactly one of
  them per day, and `.db-day` is the only modifier that stacks. **The edges are INSET box-shadows,
  not borders**: a border would change the badge's box and shift the whole strip, and five of them
  set the floor of a column that is spent to the pixel at 1280.

### Siblings are DETECTED, not ticked (`_incl/siblings.php`)

**89 of 702 open applications were siblings of a currently-enrolled child and NOT ONE was
ticked** - 13% of the queue in the wrong order, and no amount of care from the team would
have fixed it, because nothing showed them the link. Kidsoft knew all along: guardians attach
to several children.

**THE DETECTION IS THE AUTHORITY AND THE COLUMN IS AN OVERRIDE** (11 Aug 2026). Before this,
`priority_rank()` read the `is_sibling` column, a banner counted the detected-but-unticked and
offered a bulk button. Measured: **100 of 719 detected, ZERO ever ticked, and not one
`waitlist.sibling` row in the audit log** - the button had never been pressed in the eight
days it existed, so the queue was wrong for 14% of families the whole time and the screen
**announced that daily instead of fixing it**. The stated reason for the button ("staff may
have unticked one deliberately") could not have applied, because nothing had ever been ticked.

`in_care`, one rung above it, was already the counter-example - same shape of fact, computed
live on every read with no column, no banner, no button. Two adjacent rungs of one ladder had
opposite architectures. Now `waitlist_attach_siblings()` sits beside `waitlist_mark_in_care()`
in `waitlist_for_centre()`, before the sort. Verified: 111 of Tuggerah's 115 positions moved,
biggest gain **#115 -> #33**.

**If you add a further rung, this is the shape: derive it on read, name its reason on the row,
and do not add a column for it.**

Every part of the match is conservative, because **a false sibling promotes one family over
another that was there first**:

- **On the parent's email or mobile, never on a name.** Phones normalise to their last 9
  digits so `0412 345 678`, `+61412345678` and `61412345678` agree. 81 of 89 matched on BOTH;
  only 8 are phone-only.
- **A WORK NUMBER IS AN EMPLOYER'S SWITCHBOARD AND IS EXCLUDED** (13 Aug 2026, found by Broc
  reading one row). Most-shared single number per type: **mobile 4, home 5, work 16.**
  Colleagues are not siblings. It produced one application ("Baby Manhire") matching **11
  children across 6 surnames**, because **15 Tuggerah guardians list 02 4355 4816 as their
  work number**; one of the eleven was real and the EMAIL had already found her. Excluding it
  leaves the group-wide match count at **101, unchanged** - every real sibling is reachable
  by a mobile, a home number or the email - while the busiest phone key falls from 11 to 6.
  **It removes false positives and costs nothing.** Matched on **SUBSTRING**, so "mum work",
  "dad work", "grandma work" and "case worker" go too.
- **`sibling_phone_key()` CANNOT MATCH A LANDLINE TO ITSELF, and this is unfixed.** It keeps
  the last 9 digits, so `0243554816` (10 digits) and `43554816` (8, no area code) yield
  different keys - both forms are in the live data. It does not matter for mobiles, which are
  always 10 digits, and excluding work numbers removed the case where it did visible harm.
  **If home-number matching is ever leaned on harder, fix this first.**
- **Only against the ENROLLING guardian or a Mother/Father** of the enrolled child. This is
  the load-bearing filter: it discards **54% of guardian links**, and without it a
  grandmother's or a case worker's number sitting on two unrelated families invents a sibling
  between them. It cut the count from 129 to 89 and removed every cross-family sample.
- **A child is not their own sibling.** 31 open applications belong to an already-enrolled
  child; excluded on folded first+last name via `sibling_name_key()`.
  **AND THAT KEY CARRIES THE DIGITS (21 Aug 2026).** It folded with `sibling_fold()` alone,
  which strips them, so `sibling_fold('Twin 1') === sibling_fold('Twin 2')` - and **the moment
  one twin enrols while the other is still waiting, the waiting twin reads as the enrolled child
  re-applying and is dropped**, losing the sibling rung of the family with the plainest claim to
  it. Three pairs are on the books (Wolfenden at Niagara Park with **both applications open**,
  Moffitt at Tuggerah, Pan at Point Clare). This is the same fix as the merge keys - the digits
  appended as a sorted multiset, so `Baby Haigh 2` and `Baby 2 Haigh` still exclude correctly -
  and it is applied to **both halves of the comparison**, the enrolled index and the applicant,
  which must always be built the same way. **It had never once misfired**, because no Kidsoft
  child record carries a digit: a placeholder name is corrected by the time a child converts, so
  the enrolled half never held a digit to lose. Closed before that stops being true.
  **Measured: 101 matches group-wide before and after**, `Millie Blackshaw\`` (a stray backtick,
  not a digit) still correctly excluded, and the twins now kept.
- **THE MATCHES ARE DEDUPED ON KIDSOFT'S `ChildID`, NOT ON THE NAME (21 Aug 2026).** One
  enrolled child is reached several times over - the mother's email, her mobile, the father's -
  and the dedup exists to collapse that back to one child. It keyed on the **folded name**, which
  is not an identity: two enrolled children sharing a name at one centre collapsed into one, and
  whichever was written second silently stopped being a sibling. Terrigal holds the live pair and
  **it is not a duplicate record** - `MATILDA PAYNE` b. 27/12/2021 (Chloe Charlton's) and
  `MATILDA PAYNE` b. 02/07/2025 (Emily Payne's) are two children of two unrelated families,
  different mothers, different CRNs, different rooms, both booked Tue and Thu.
  **Measured across all 774 applications: no applicant reaches both**, precisely because they are
  different households, so nothing on screen changed - 101 matches before and after. Fixed as a
  correctness matter, not a repair: it needs only one family with two same-named children to
  bite, and it would then remove a sibling nobody would think to look for. Proved on a
  constructed applicant holding both households' identifiers: **2 siblings before, 3 after.**
  One consequence to know: such a family would read "Sibling of Matilda Payne, Matilda Payne",
  which is truthful and looks like a rendering fault. Left alone - de-duplicating the names for
  display would reinstate the same bug one layer up.
- **Group-wide**, because a family already at another Little Miracles centre is still a family
  we have; 14 of the 89 are only visible across centres.
- **`enrolled_family_index()` is memoised for the request**, because the index is GROUP-WIDE
  while its caller is per centre: the All centres register makes seven calls to
  `waitlist_for_centre()` and rebuilt the whole thing seven times. **0.80s against 0.12s.** A
  partial index (a centre Kidsoft would not answer for) is cached too, so one page load ranks
  every centre against the same set of families rather than the order depending on which retry
  succeeded.
- **The provenance line is shown ONLY for a phone-only match.** The badge names who; `via`
  says how, and **89 of 100 read "email + phone"** - printing it on every row put a line
  saying nothing under nine rows in ten and took the Priority column to three lines. Email is
  the stronger identifier, so only the 9 phone-only matches carry "on the parent's mobile
  only". **Withhold, do not caption.**
- **`is_sibling` SURVIVES as a manual, additive override** for a sibling the phone/email rule
  cannot see. A row is promoted by either. **Do not delete the column or its editor checkbox
  because it reads zero today.**
- **A Kidsoft outage now changes the queue ORDER, not just the badges.** Already true of
  `in_care`. A real behaviour change, with nothing stored to fall back to.
- **`search.php` calls `priority_reasons()` on a raw row and therefore still shows the stored
  flag alone.** Deliberate: wiring group-wide detection into the palette would add ~0.12s to
  every keystroke-driven search to change a badge on a screen that is not the queue.
- **THE CHILDREN A REASON NAMES OPEN THEIR DIALOG (6 Nov 2026).** `priority_reason_parts()` builds
  each reason as pieces - `['t' => text]`, plus `'child' => [centre_id, ks_id]` on a named
  sibling and on the centre in "Already in care at" - and `priority_reasons()` is just their text
  joined, so **the wording still has one definition**. `waitlist_for_centre()` publishes both:
  `reason_parts` for the queue's Priority column and the board's candidate cards
  (`window.reasonHtml()`), `reasons` for everything that wants a sentence. Verified the day it
  changed: all 564 open applications produced **byte-identical** reason text before and after,
  and 89 names became links. A piece carries a ChildID only where this user holds that centre
  (`user_can_centre()`), and **`in_care_children` - every record the child holds, ChildIDs and
  all - is unset before the row leaves the server.** Siblings now carry `centre_id` from
  `siblings.php` for the same reason: a ChildID is per service.

## "Also waiting at": the register's finding, on the screen the calls are made from

`waitlist_elsewhere()` in `_incl/waitlist-group.php` -> a column on the centre queue and a line in
the family modal's "What they want".

**76 of the 615 children on the register are waiting at more than one centre and hold 173 of the
714 open applications** (`/api/waitlist?action=group`, 17 Aug 2026). Cross-checked straight against
Kidsoft the same day - `Waitlist/getWaitingList` over all seven centres, 1,698 entries, 710 open,
grouped by this same rule - which reads **75 families holding 171 applications**, the difference
being hand-keyed rows and import lag rather than the rule. Until now the only screen that could see
any of it was the All centres tab, and **that is the wrong screen: the ringing happens on a centre's
own queue.** 54 of Wamberal's 222 rows carry one.

- **COMPOSED FROM `waitlist_group_all()`, never a cheaper join.** The grouping rule is the whole
  difficulty (name + DOB AND a shared parent mobile or email, merged transitively) and a second
  approximation would mean the queue and the register disagreeing about who is one family.
- **It is nearly free, and the reason is `waitlist_mark_in_care()`:** that already builds the
  Kidsoft enrolled-family index for **every** centre when **one** centre is loaded. Measured 17 Aug
  2026: `waitlist_for_centre()` alone 0.124s cold, the whole group 0.142s, `/api/waitlist?action=
  list` end to end **0.147s** for the group's biggest queue.
- **The modal's `get` pays the same 0.15s**, up from ~0.02s, on every click of a child's name.
  Accepted deliberately: the alternative is a second grouping rule, which is the failure this
  file already documents twice.
- **OPEN applications elsewhere only** - a placed or lapsed application at another centre is
  history. But a row closed **at this centre** still gets its chips, because "placed here, still
  waiting at Terrigal" is one of the two things the register exists to reveal.
- **One chip per centre.** A child with two open applications at one other centre is real
  (Tuggerah holds one); the question is WHICH centres, so the earliest-added stands for it.

### The column has no queue-position pill, and that is a width decision

The register's chip carries the family's place in that queue inside it. **Repeating that pill in
the queue column takes the column's floor from 117px to 143px**, and at 1280 with the sidebar out
the nine columns have **1030px** between them - so the number would have been paid for by Child
and Priority wrapping harder. It is on the chip's `title`, in the family modal and on the register.

**Re-measured floors, 17 Aug 2026** (the old row is in the Actions section below): # 53, Child 107,
Status 89, Guardian 144, Days wanted 73, **Also waiting at 114**, Priority 103, Added 93, Actions
108 - **884px**, and Days wanted's declared 12rem is the one real spend on top (five badges on one
line). Child came down 11rem -> 7rem and Priority 10rem -> 6.5rem to pay for the new column; both
were declared well above their own floors and **a declared width below a column's floor is free**.

**Verified at 1280 on all seven centres: 0px overflow**, and 0px at 1440 and 1920. The cost is
row height: mean **94px against 87px** at 1280, 89 against 84 at 1440, measured over the same 40
Wamberal rows with the column removed in the browser.

**ONE CHIP BUILDER FOR ALL THREE PLACES:** `window.waitlistCentreChip()` in `app.js`, because the
part worth having once is the TITLE - their place in that queue, when they applied, the days they
asked for there, and whether those differ from the application being read. `opts.pill` is the only
difference between the column's chip and the modal's.

**"wants other days there"** is printed under the chips only when the days on the other
application differ (15 families group-wide). Offering the days off the wrong application is how an
offer gets declined by a family who wanted it - the same note the register carries.

### Another centre is already working them (22 Oct 2026)

From the enrolments meeting: *"can there be a flag if one centre identifies a family when it is not
for their centre?"* `waitlist_elsewhere()` now carries `work` per other centre: set when ANY open
application there has an open In progress item (not only the earliest one the chip stands for). The
chip turns amber with a `fa-list-check` mark and the column says "In progress there"; the family
modal says "In progress at Wamberal: Accepted".

- **The flag goes to everybody, the stage only to somebody who holds that centre.** "Wamberal is
  already on this family" is about a family in a supervisor's own queue; how far along is the other
  centre's business - the same line as the queue position.
- **It needed one field carried through `waitlist_family_summarise()`**, which rebuilt each
  application without `work`. Tested first from a CLI that had not loaded `work.php`, where
  `waitlist_for_centre()` skips the work join (`function_exists('work_list')`), so 0 rows is what
  both a broken and a working build print there - load `work.php` before believing a zero.
- **Measured that day: 2 rows group-wide**, Eloise Bowman (Terrigal's queue, Accepted at Wamberal)
  and Heidi Gow (the mirror), matching a direct SQL check of worked families with an application at
  another centre.

### "Applied before": search's past applications, on the queue (11 Nov 2026)

Broc, with a screenshot of search showing Rome Guilbert waiting at Wamberal and rejected or deleted
at three centres: *"Can we please sync the search bar 'Waitlist archive or rejected' info in the
waitlists so we can see that info if they are left on 1 waitlist. Currently waitlist shows only if
they are currently on the waitlist elsewhere."* The column above is OPEN applications elsewhere by
design, so a family rejected at Tuggerah and deleted at Terrigal read on Wamberal's queue as a
family nobody had dealt with.

`waitlist_history()` in `_incl/waitlist-history.php` -> `history` on every queue row and on the
modal's `get` -> an **"Applied before"** block at the foot of the "Also waiting" column, and a
**"Past applications"** box in the family modal's "What they want".

- **SEARCH'S OWN LINES, not a second definition.** Built by `search_history_line()` from the same
  cached `getWaitingList&ShowArchived=true` read, and drawn by `window.pastApplicationLines()` in
  `app.js`, which search's palette now calls too. The modal's box is search's markup exactly
  (`.sr-line-past`). Nothing is copied into our tables - see
  [search-identity.md](search-identity.md) for why.
- **ALL CENTRES, THIS ONE INCLUDED.** "Rejected by Wamberal last year" is the most useful line
  there is on Wamberal's own queue.
- **MATCHED ON THE EVIDENCE SEARCH JOINS A CARD ON:** Kidsoft's `ChildID`, or name + DOB, or name
  + household phone (`sibling_phone_key()`), followed transitively through the history the way
  `search_merge_children()` follows a card's records. Never the name alone. It is NOT the
  register's grouping rule: that one answers "how many families are we holding", this one "is this
  the child", which is search's question.
- **A supervisor gets the lines at their own centres only**, as search gives them. Measured with a
  Wamberal supervisor: 8 Wamberal lines on 7 rows, where an administrator sees 53 lines across five
  centres on 33.
- **The column shows three at most**, one per centre and outcome: two identical "Tuggerah Rejected
  by service" lines read as a glitch when the date telling them apart is on the title, so they
  fold to "Rejected by service, twice" with both dates there. Then "and N older". The modal shows
  every line with its date.
- **Archived is a MARK in the column** (`fa-box-archive`, the Consider archiving flag's), because
  the word does not fit and cannot be left to the title: an application archived while still
  `Waitlisted` otherwise reads "Tuggerah Waitlisted", a family who looks to be waiting there.
- **Measured 11 Nov 2026: 183 of the group's rows carry any** (Niagara Park 69 of 215, Terrigal 43,
  Wamberal 33, Tuggerah 23), 157 of them one or two lines. Rows that carry it grow from a mean of
  ~105px to ~140px at 1280; **column widths and overflow unchanged on every centre** (Niagara
  Park's 21px and Wamberal's 6px were there before).
- **Cost: 0.16s on `list` and on the modal's `get`** (0.34s -> 0.5s), the warm decode of the seven
  cached payloads. Not cached a second time: search pays the same read on every search.

**It also found 254 applications that had been invisible to search since August** - every row we
imported and Kidsoft later archived, 51 of them `RejectedByService`. See "A row we hold as
archived" in [search-identity.md](search-identity.md).

## Duplicate waitlist: one child applied for twice AT ONE CENTRE (6 Nov 2026)

`_incl/waitlist-duplicates.php` -> `duplicate` on every queue row, the family modal (`#wl-dup`),
In progress rows and the work modal (`#work-dup`). One badge builder, `window.dupBadge()` /
`dupAlert()` in `app.js`, beside `dneBadge()` and drawn in the same place.

From Wamberal: Lennox Dransfield's parent held **three** applications there. One converted to
`Enrolled`; the other two sat on Kidsoft's list as Waitlisted, and the In progress item - which was
on one of the leftovers - never closed (`work_close_kidsoft_enrolled()` reads only the item's OWN
application). Broc: *"can it be bright red or something and say 'duplicate waitlist' so they can go
in to Kidsoft and archive there?"* - with two conditions: **a child already enrolled asking for
more days is demand we want**, and **separated parents applying separately are flagged
differently**.

**`dashboard.php` claimed "a centre's own queue has no duplicates". Never measured; false.**
Measured 6 Nov 2026, every non-archived application bucketed by centre + `name_identity_key()` +
DOB with one still open: 5 children at 4 centres -

| child | what | flag |
|---|---|---|
| Raylan Lee, Tuggerah | two open, identical parent details | red **Duplicate waitlist** on both |
| Billy Bovis, Tuggerah | two open, same mobile | red on both |
| Elijah Bourgeois, Terrigal | two open, Jessica Henegan / Matthew Bourgeois, no contact shared | amber **Other parent applied** |
| Zoe Lahanas, Kanwal | enrolled Mon-Wed on #786, #840 asks Mon-Thu | **none** - she wants a Thursday |
| Savannah Jarvis, Niagara Park | an expired offer plus a new application | none - only one is open |

Lennox, replayed against his rows before Wamberal archived them: enrolled Thu, both leftovers
asking Thu only -> red on both.

**The rules, for an OPEN application** (waitlisted / offered / partially placed):

1. Another application for the child HERE is Kidsoft `Enrolled` and this one asks for **no day
   the enrolment does not cover** -> red, "Archive this one in Kidsoft". **A day beyond the
   enrolment means NO flag at all** - that is the more-days family, and the Priority column
   already says "Already in care".
2. Another OPEN application here shares a parent - same Kidsoft GuardianID, mobile or email -> red
   on BOTH. Which to keep is the centre's call (older = earlier place in the queue, newer may
   carry the days they want now); the title names the other's date and days.
3. Another OPEN application here shares NO parent contact -> amber, never asks for an archive.

- **ONE CENTRE ONLY.** Applying at several centres is normal (12% of the queue) and is what "Also
  waiting at" is for. A child enrolled at Terrigal and still waiting at Wamberal may be a real
  transfer (9 such children on 6 Nov) and is not flagged here.
- **Matched on `name_identity_key()` + DOB with NO contact test**, because rule 3 is the case
  where the contacts differ. Twins differ by name, placeholders by DOB. No DOB, no match.
- **A FLAG, NEVER AN ACTION.** Kidsoft is read-only; a person archives, the next import's archive
  reconciliation drops the row and the flag goes with it. Nothing re-ranks and no work closes.
- **Pure SQL over our copy, 0.005s for the group**, so it needs no Kidsoft read and cannot be
  stale in a way the queue itself is not.
- **The badge has `text-wrap`.** On one line it took the Child column from 113px to 143px and
  Tuggerah's table to 1062px in a 1030px box at 1280. Wrapped: 1031, and 1031 with the badges
  removed too - that last pixel is Tuggerah's own content, not this.

## Consider archiving: wanted a place more than six months ago and still waiting (6 Nov 2026)

`waitlist_stale()` in `_incl/waitlist.php` -> `stale` on every queue row and every "Could take this
day" card on the board. One badge builder, `window.staleBadge()` in `app.js`, a muted outline (`.badge-stale` in `app.css`; filled amber was first and Broc asked for "much more subtle"), drawn under
the duplicate badge on the queue and beside Do Not Enrol on the card. The date is on the title.

Found while measuring the board's 90-day start window: **271 of the 636 cards on 2027's
shortlists** were families whose start date had passed more than six months before 1 January.
Broc: *"Wow, I didn't know there were applications that old. Yes, please add a 'Consider
archiving' (or something) flag."*

Open applications group-wide by how long ago the family wanted to start, 6 Nov 2026: **40 more
than two years, 13 one to two years, 34 six to twelve months**, 75 three to six, 78 under three.
Thirty-two are one Wamberal batch added 30-31 Aug 2023 wanting places from February 2022 (Tba
Rogers and Tba O'Hara sit at #7 and #8 of Wamberal's queue). **Almost none carry a Kidsoft
`ExpiryDate`**, so Kidsoft's lapse clock never started on them, and nothing else would ever take
them off the list.

**Flagged that day: 88** - Wamberal 49, Tuggerah 13, Niagara Park 10, Terrigal 7, Kanwal 5, Point
Clare 4. None also carried the duplicate flag.

- **`WAITLIST_STALE_MONTHS` = 6, counted back from today** (Sydney, server-side). 6 is the cut the
  271 was measured at and the one Broc was shown.
- **A FLAG, NEVER A FILTER OR A RUNG.** The family keeps its queue position and its board card;
  some are still waiting for this centre while using another, and only a phone call tells which.
  Kidsoft is read-only; a person archives there and the next import drops the row.
- **Only `waitlisted`.** `partially_placed` is a family already enrolled here wanting more days,
  and their start date is the day they started.
- **Not while anybody is talking to them** - an In progress item, our old offer or a Kidsoft offer:
  the same test that washes the queue row (`engaged` in `waitlist.js`).
- **Marketing still counts them as waiting.** Not counting them would take the 2027 gap from 107
  to 140 place-days a week; offered to Broc, not done. See [marketing.md](marketing.md).

## A sibling ALSO WAITING (16 Oct 2026)

Broc: *"Could we also in Waitlist pick up if there is another sibling on the waitlist?"* The
Sibling rung finds a brother or sister **enrolled** with us. Two children of one family who are
**both still waiting** were joined by nothing - Meila Burgess at #19 and Koa at #80 in Tuggerah's
queue, same mother, same mobile, and neither row said so.

`waitlist_siblings_waiting()` in `_incl/waitlist-group.php` -> `siblings_waiting` on every queue row
and on the modal's `get` -> lines under the chips in the **"Also waiting"** column (renamed from
"Also waiting at") and a "Sibling also waiting" box in the family modal. One line builder,
`window.waitlistSiblingLine()` in `app.js`.

- **IT DOES NOT RANK.** The ladder's Sibling rung is a sibling already in care; a family with two
  children waiting holds no place with us yet. Said on the row and nowhere else.
- **A shared parent mobile or email between two different CHILDREN**, where a child is the
  register's own grouping (`waitlist_group_families()`), so one child applied for at three
  centres is one child here too. Open applications on both sides. Built off
  `waitlist_group_raw()`, the register's groups now memoised for the request - **0.001s on top of
  `elsewhere`**, which already built them.
- **"Different" is decided by the dates of birth, and the data split cleanly.** Measured over 579
  open applications / 498 children: 52 pairs share a contact (49 on both identifiers, no contact
  shared by more than three children). Sorted by DOB gap, **0-96 days is ONE child applied for
  twice** - a moved due date (Caleb Dougan, 96 days; Tbc Childs, 19), a placeholder beside the real
  name (Baby / Elliot Sawyer, 2; Unborn / Charlotte Adam, 18), the same name typed twice (Teddy
  Arber, Harvey Knox) - and **363 days up is real siblings**. The register's merge needs the SAME
  DOB, so it rightly leaves those apart; calling them siblings would be wrong the other way. Rule:
  **1-269 days apart is the same child; born the same day (or a DOB missing) is two children only
  if the first names differ, neither contains the other, and neither is a placeholder** (`Baby`,
  `TBA`, `Unborn`... not `Twin`). That keeps Pearl/Vera Woodward, Theo/Noah Kerrison, Himmat
  Singh/Rehmat Kaur and Twin 1/Twin 2 Wolfenden, and drops Millie Sharp/Sharpe and Amelia/Amelia
  Grace Sheppard.
- **A sibling the Priority column already names is not said twice.** An enrolled child can hold an
  application too (Wyatt Smith is in care at Niagara Park and #2 in its queue), and his brother's
  row already reads "Sibling of ... Wyatt Smith". Matched on the rung's own `sibling_name_key()`.
- **Supervisor scope:** a sibling waiting at a centre the user does not hold is "one at Terrigal" -
  no name, position or id - the line `sibling_matches_for_centre()` already draws.
- **The first name alone when the surname matches** (`short`), so the 7rem column reads
  "Koa #80", and the word "Sibling" sits once above the lines.
- Counted 16 Oct 2026: 43 pairs / 80 children before the already-named rule, **71 children carry a
  line** after it. Verified at 1280 and 1440 on Wamberal, Niagara Park and Tuggerah: **0px
  overflow**, mean row 93-98px at 1280 (was ~94). Driven in Chromium: a sibling's name in the
  queue opens THAT child's dialog (Wyatt Smith -> Wyatt Smith's modal), no console errors.
- **Do siblings get offered together? Measured 19 Oct 2026, before any "bulk" action was
  designed** (Broc: *"it would be very unlikely that after a tour that we offer a enrolment to one
  sibling and not the other... right?"*). Same pairing, over EVERY application ever imported
  (closed ones too), at the same centre: **75 pairs**; 65 were applied for the same day, 55 asked
  to start within 30 days of each other. Of the 28 pairs where either child got an offer or an
  enrolment: **starting together (within 30 days), 20 of 20 had both offered; starting apart, 6
  had only one** (Richardson, Bridge, Kerr: the younger one wanted a later year or was not born
  yet; Bardsley: the third child is school age; Leon: the younger's application is stale) and 2
  had both. So the start date predicts the split, and the sibling's room is always different
  unless they are twins. Outcome read off `kidsoft_status`: Enrolled, any offer status
  (Offered, Form Submitted, ExpiredOffer...), still waiting, or gone.
- **`waitlist_same_child()` and its two constants moved to `siblings.php`** (19 Oct 2026),
  unchanged, so the work layer can ask it for one centre without loading this group-wide build.
- **The family modal books one tour for the family** (19 Oct 2026): "Also on this tour" under the
  tour box and "Also on the history of" on the contact form, from `work_siblings_here()` published
  as `family` by `get` - the work modal's own read, so both dialogs offer the same ticks. The tour
  ticks are named `tour_siblings[]` so the application's save, which serialises the form, has
  nothing to mistake them for. Rules and measurement: [work-layer.md](work-layer.md), "Brothers
  and sisters".

## The All centres tab: a REGISTER, one row per CHILD

`_incl/waitlist-group.php` -> `api/waitlist.php?action=group` -> the `centre=0` tab.

This screen used to refuse an All centres tab on the grounds that priority order only means
anything within a centre. The first half is still true and the tab does not pretend otherwise -
it is a **register**, not a queue: every family the organisation is holding,
longest-waiting-first, with the place they hold in each centre's own queue shown against that
centre's name.

### The one percentage on this screen, and it rounds the WRONG way (10 Sep 2026)

`At more than one centre` prints the count as its figure and the share as its caption, so the
caption can round away the figure directly above it: at **2 of 534** families a plain `round()`
gave a **2** over **"0% of families"**. That is the low-end twin of the placement board's
"Filled 100%" beside one free place - see [placement-board.md](placement-board.md).

**Latent, not live** - it reads 72 of 534 (13.5%) today, and it would take the merge rule
finding two families or fewer to bite. Fixed anyway, because the whole point of the tile is that
number being small.

It goes through `window.fillPct()` / `pctText()` like the fill figures, and here the **high**
clamp is doing real work too: 100% is a legitimate claim on this tile - every waiting family at
more than one centre - and `fillPct()` only makes it when `multi === families`.

**Nothing else on the Waitlist renders a percentage**, centre tab or group tab, and neither does
the family modal: the tiles are counts, a weekday name and a date. Checked 10 Sep 2026.

**Floating multi-centre families to the top was tried and dropped** - it is an opinion
dressed as an ordering, and the "2+ centres" filter says the same thing without hiding it.

**Why it earns its place:** 715 open applications belong to **620 children, 74 of whom (12%)
are waiting at more than one centre**, holding 169 places in the queues. Two things the team
could not previously do: ring a family knowing they are also 5th at Niagara Park, and take a
family off three other queues when one centre places them. The overlap is lopsided and that is
the useful part - **Terrigal + Wamberal alone is 48 of the 74**, which is why the screen ranks
PAIRS rather than drawing a 7x7 matrix that would be mostly empty.

### THE GROUPING RULE IS THE WHOLE FEATURE, and both obvious keys are wrong

An application carries no cross-centre identity: **`kidsoft_child_id` is set on 2 of 715 open
rows**, because a family on a waiting list is not enrolled anywhere yet. What is left is what
the parent typed, retyped at each centre. Measured across the 77 name+DOB collisions:

- **PHONE ALONE SPLITS REAL FAMILIES.** Anika Struthers is one child, one DOB, one family
  email, applied at three centres on **two different parent mobiles**; Riley Klonaris and
  William Naylor each have two applications whose mobiles differ by **one mistyped digit**.
  Keyed on the phone, those three children read as six families.
- **NAME + DOB ALONE MERGES DIFFERENT FAMILIES.** Parents applying before the birth type
  placeholder names: the live queue holds separate "TBA Anderson", "TBA Smith", "TBA Johnson",
  two "TBA TBA" and two "Baby Tweedie" children, all real, all different families, **told
  apart only by DOB**.

So the rule is **both**: same folded name AND same DOB AND a shared parent mobile or email,
merged **transitively** (Struthers' three rows join through a phone and then an email; a
one-pass composite key produces two families out of that and misreports the centre count for
both). It costs exactly **one** miss in live data - two parents of one child who each applied
at Terrigal under their own mobile and their own address, which nothing short of guessing could
join. That asymmetry is deliberate: **a missed merge shows one child twice, which a human reads
and understands, while a false merge tells the team two children are one and hides a family
completely.**

**AND THE FOLDED NAME IS `name_identity_key()`, NOT `name_key()` (20 Aug 2026).** `name_fold()`
strips everything but letters, so `Twin 1 Wolfenden` and `Twin 2 Wolfenden` folded to one name -
and being twins they then agreed on the DOB gate and on their mother's mobile as well. **Two open
applications at Niagara Park, queue positions 25 and 26, reported as one child waiting twice**, so
the register said find one place where two children need two. The census above is unharmed and
still holds: it tested placeholders across DIFFERENT households, which differ on DOB. **Two
placeholders inside ONE household is the case it never had**, and twins beat every other
discriminator this rule owns.

`name_identity_key()` is `name_key()` plus the digits of the name as a sorted multiset, so a name
without a digit keys byte-identically to before and a key can only become more distinct, never
less. Verified across all **770** applications open and closed: the grouping changed in exactly
one place, `201,202` becoming `201` and `202`, and the register went from **623 families to 624**.
See [search-identity.md](search-identity.md) for the full census and why the digits are sorted.

**`search.php`'s key is deliberately NOT reused and must not be "unified" with this one.**
Search asks "is this the person on the phone", where a duplicated row costs a glance; this asks
"how many families are we holding", where a duplicated row is a wrong headline figure. The
primitives (`name_identity_key()`, `sibling_phone_key()`, `sibling_email_key()`) are shared;
**the policy is not.**

### Other decisions on that tab

- **Everything composes rather than recomputes.** `waitlist_group_all()` calls the same
  `waitlist_for_centre()` the centre tab uses, so queue positions, priority reasons, expiry
  countdowns and open offers have one implementation and cannot disagree between tabs. 0.02s
  for the whole group.
- **Read-only.** Offering needs a room, a room belongs to one centre, so a centre chip is a
  **LINK** into that centre's queue (`?centre=N&q=<name>`) rather than a button that would
  have to guess which application it meant. No editor, no offer dialog, no contact log rendered.
- **Aggregates count OPEN applications only**, so a placed family is never reported as "waiting
  at two centres". History-only families still appear under "All" - with days taken from the
  closed applications, because an empty Mon-Fri strip reads as "asked for no days" rather than
  "no longer waiting".
- **`days_differ`:** 15 of the shared families asked for different days at different centres,
  and offering the days off the wrong application is how an offer gets declined by a family who
  wanted it.
- **The tab imports EVERY centre on view** (each throttled separately at 900s, and each read
  LIVE - see the on-view trap above). A tab headed "All centres" holding one centre's overnight
  applications and not another's is worse than one uniformly a day old. Measured at 2.4s for all
  seven; Refresh on this tab does the same seven.

## Refresh: an override of the throttle, NOT the freshness mechanism

`#refresh-queue` on both tabs, `action=refresh` in `api/waitlist.php`, `bindRefresh()` in
`waitlist.js`. It calls `waitlist_import_centre(..., ['refresh' => true])` **directly**, skipping
`waitlist_import_on_view()`'s 900s gate, because "I just changed that in Kidsoft and want to see
it" is precisely the request the gate would refuse.

**This is not the 11 Aug 2026 "Import from Kidsoft" button coming back.** That one was the ONLY
way the queue refreshed, which made keeping it current the team's job; it was removed for that
reason and the reason still holds. Opening the screen imports, and the nightly imports what
nobody opens. This button only buys back the up-to-15-minute wait, which is why it is
`btn-outline-secondary` and not a primary: a primary-looking button reads as a step in the job.

- **It always says what it found, including nothing.** "No change. The queue matches Kidsoft."
  is the commonest honest answer and the one the presser wants. A button that looks identical
  before and after gets pressed five more times.
- **The table is only redrawn when something actually moved** (`changed`, which deliberately
  excludes `updated` - every open row counts as updated on every successful import, so including
  it would make the flag permanently true and throw away the filters in force on every press).
- **Disabled with a spinning icon for the duration**, verified by driving Chromium: disabled for
  the whole 2.4s group read, and six clicks in 3.4s produced two POSTs, never two in flight.
- **Scope is `api_require_centre($id, true)` then `centres_visible()`**, so a supervisor
  refreshing the group can never reach past their own centres.
- **A centre Kidsoft will not answer for is named in the message**, not swallowed and not fatal
  to the other six.

### A related bug: searching the centre queue renumbered it

`waitlist_for_centre()` applied `q` in the WHERE clause and **then** assigned
`queue_position` by counting down the sorted rows, so searching a surname showed that family
at **"#1"** whatever their real place in line - and that number is the one thing on this
screen a parent might be told over the phone. **The search is now a post-filter**, applied
after positions are worked out. Verified: "struthers" at Tuggerah returns **#104**, not #1.

## The DOB / age filter (3 Sep 2026)

The row prints the child's **date of birth**, not "42 months". A birth date does not go stale,
it is what a parent reads out on the phone, and it was already sitting beside the age.

The filter beside the search box takes **either unit**, chosen in its own dropdown:

- **DOB, THE DEFAULT** (Broc, 3 Sep 2026) - two `type="date"` boxes, with the browser's own
  calendar. The cohort question ("born in 2024, for next year's room") and the child on the
  phone. **The markup ships in this state** - the age boxes carry `d-none` in `waitlist.php`
  rather than being hidden by JS on load, so there is no flash of the wrong pair.
- **Age**, two boxes in **months as at today** - the unit rooms and sub-quotas are banded in
  (`min_age_months` / `max_age_months`), so it is the one for holding a child up against a
  room's band.

`ageMode()` treats anything that is not `age` as DOB, so the default survives the select being
missing or empty rather than silently falling back to the other unit.

**Only the pair on show is sent.** The hidden pair keeps what was typed for a switch back, and
a filter nobody can see must not be narrowing the table. The server (`waitlist_age_bounds()`)
reads and applies BOTH, so a hand-built URL carrying the two gets the intersection rather than
slipping past.

- **`waitlist_filter_age()` takes the whole bounds array**, not loose `$min, $max` arguments -
  a call site that had not heard of the date pair would otherwise have filtered on half of it
  and looked like it worked. Both call sites pass `$opts` (`waitlist_for_centre()` and
  `waitlist_group_all()`), so the two tabs cannot disagree about who is three.
- **Dates are compared as plain `yyyy-mm-dd` strings** against `child_dob`, straight out of
  MySQL. Same shape, same order, no timezone anywhere near it - see `_incl/dates.php`.
- **A half-typed date is NO BOUND**, exactly as a blank months box is: `2023-01-0` must not
  empty the table under somebody mid-keystroke. Anything not matching `^\d{4}-\d{2}-\d{2}$`
  is dropped.
- **EITHER ORDER IS ACCEPTED, AND THE BOXES CARRY NO `min` / `max`** (11 Nov 2026, Point
  Clare). They used to: `syncDobRange()` set each box's `min`/`max` from the other, to stop a
  crossed range being entered. **Chromium turns that into a lock.** It will not take a typed
  year outside a box's `min`/`max`, so the box blanked itself (it showed `dd/mm/0022`), went
  red, and the table filtered on the other date alone - which looks like it worked. Three
  everyday entries hit it, all reproduced by typing into headless Chromium:
  **"1/1/2024 to 31/12/2022"** for the two-year-olds (the younger end first - how anybody
  thinking in ages types it; Broc's report), **moving a 2022 range on to 2023** by retyping the
  From year, and **moving "This year" on to 2027** in the start-date boxes, which had the same
  guard (`syncStartRange()`). Both are gone. `datePair()` in `waitlist.js` sorts the pair before
  it goes anywhere, so the URL, the API and the Waiting tile's caption get the range in force
  while the boxes keep what was typed. **Measured after:** the reversed entry keeps both dates and
  shows Point Clare's 6 children born in 2023.
- **A half-typed date is still refused**: `dobBoxesValid()` / `startBoxesValid()` mark a box the
  browser cannot read (`badInput`) `is-invalid` and **do not load** - the table keeps the last
  good answer instead of emptying under somebody mid-keystroke. They also `clearTimeout`, or the
  load queued by the keystroke before the one that broke the date still fires 250ms later.
- **`input` / `change` ARE NOT ENOUGH ON A DATE BOX** (11 Nov 2026, Point Clare: an empty box
  outlined red, and "Waiting 4" under two blank boxes). Chromium fires them only when the VALUE
  changes, and the value goes blank as soon as ONE part (day, month or year) is cleared - so
  clearing a date a part at a time fired once, half-cleared and refused, and never again. The box
  ended empty and still red, and **the table stayed filtered on the date that had been in it**.
  `dateBoxes()` in `waitlist.js` listens on `keyup` and `focusout` as well, and reloads only when
  the range in force differs from the last one that pair loaded - keyup also fires for Tab and
  the arrows. **Measured after**, typing into headless Chromium: clearing part by part reloads
  4 -> 6 -> 53 with no red box; typing a full date is ONE load; arrows and Tab alone are none.
- **The server still swaps a reversed pair** (`waitlist_age_bounds()`, `waitlist_start_range()`)
  and must keep doing so: a hand-built URL does not go through `datePair()`.
  `?dob_from=2024-01-01&dob_to=2022-12-31` returns the same rows as the right way round.
- **The invalid marker is the red border ALONE** (`#age-filter .dob-box.is-invalid` in
  `app.css`). Bootstrap's `.is-invalid` also paints a warning glyph in the right-hand end of the
  field, which is the exact square the browser draws its calendar button in: measured at 3x, the
  two overlap into a red exclamation stamped through the calendar, and the way to fix the error
  is what gets obscured.
- **The spinner-suppressing rules are `input[type=number]`-scoped.** They used to be
  `#age-filter input`, which now also catches the date boxes - and `appearance:textfield` on a
  `type=date` is one browser away from taking its calendar button with it.
- **The dates run the opposite way to the ages**: the earlier date is the OLDER child. That is
  why either order is accepted (above) and why the tooltips say "either way round" rather than
  naming an end - a `type="date"` input takes no placeholder, so from/to cannot be printed inside
  them the way it is in the months boxes.
- **The date boxes are 10.75rem**, wide enough for `dd/mm/yyyy` AND the calendar button with air
  between them. At 8.75rem the button sat hard against the last digit and read as decoration.
- **NO `max="today"` ON THE DATE BOXES, measured before leaving it off**: 48 of 804 applications
  carry a DOB in the future (to 2027-07-10) - families apply before the baby is born. A cap at
  today would have hidden every one of them and looked like a working filter.
- **A child with no DOB is dropped once any bound is set** (1 of 720 open applications), because
  neither question is one their row can answer. `age_months` is derived from `child_dob`, so one
  null test covers both units.
- **The empty state names the unit in force.** "Ages are in months, as at today" under a pair of
  date boxes is the same class of wrong as a figure contradicting its caption.
- **`.input-group` is `width:100%`**, so the group carries `w-auto` and each box its own width:
  the pair on show changes width with the unit, and a fixed container width fits only one of
  them. Measured at 1280 (one line) and 1100 (wraps, both tabs).
- **The rounded right-hand end is moved by `syncAgeMode()`.** Bootstrap rounds the first and
  LAST CHILD of an input group, and a hidden box is still a child - so without
  `.rounded-end` following the visible pair the group ends in a square corner butted against the
  Open/All buttons, in whichever mode is not last in the markup. Verified by reading computed
  `border-top-right-radius` in both modes.

## The start-date filter: who is YEARS away (10 Sep 2026)

Broc: *"how could we filter out people on the waitlist who put Start date from like 2028 for
example, e.g. Baby Imrie on Terrigal?"*

`Baby Imrie` at Terrigal is the case exactly: **DOB 1/03/2027 - not born yet - needed from
3/01/2028**, sitting at **#13** in a queue the team is working for this term and next, with
nothing on the row saying so. Measured the same day over **628 open applications group-wide**:

| Needed from | open applications |
|---|---|
| 2028 or later | **32** (Niagara Park 8, Terrigal 7, Tuggerah 6, Wamberal 6, Kanwal 2, Point Clare 2, Warnervale 1) |
| 2029 | 2 of those 32 |
| 2027 or later | 294 |
| no start date at all | **0** |

`required_from` is Kidsoft's `CareTypeMap.RequiredFrom` and is **100% populated on open rows**,
so this is a filter with real data underneath it rather than a mostly-blank column.

### The cut is the end of NEXT year, and the obvious cut was wrong

`waitlist_starts_by_default()` returns `(board_default_year() + 1) . '-12-31'`.

The first version of the question offered "hide anyone who cannot start **this** year", which at
Terrigal reads well (56 rows instead of 108) and **would have hidden the round actually being
worked**: in September 2026 eleven of the thirteen live `work_items` are for **2027**. A cut has
to sit BEYOND the year being planned, never on it. At 31 Dec 2027 it hides 32 rows group-wide, 7
at Terrigal, and nothing anybody is working.

Derived rather than written down, so it moves with the calendar and there is no year to come
back and edit. **Sydney's year, from the server** - a date decided in the viewer's browser is
what `_incl/dates.php` forbids.

### It is a DISPLAY filter, and that matters more here than anywhere else

`waitlist_filter_start()` runs at the bottom of `waitlist_for_centre()`, beside
`waitlist_filter_age()` and **after queue positions are assigned** - the rule the whole numbering
block in that function is written around. It was ON by default until 6 Nov 2026, so if it renumbered,
every family's position would have depended on a control nobody had touched. Verified by driving the page: switching between the two segments changed **0 of 98
positions**, and the 7 hidden Terrigal rows keep the numbers they hold (Baby Imrie is #13 in both
views).

A row with **no start date is kept**. Kidsoft fills it on every open application, so a blank is
the hand-keyed row taken over the phone: absence is silence, not "they are years away".

### The control: two dates and a dropdown (6 Nov 2026)

`#start-filter` on `waitlist.php`: a dropdown (**Any start date** / **This year** / **Next year**
/ **Choose dates**) and two date boxes, `#start-from` and `#start-to`, both inclusive. URL and API
parameters `start_from` / `start_to`, cleaned by `waitlist_start_range()` (unreadable date =
blank, reversed pair swapped). Either box blank is no bound at that end.

**The dropdown only FILLS the boxes; the two dates are the filter**, so what is in force is always
on screen. Typing a date turns the dropdown to "Choose dates", or back to a preset when the boxes
land exactly on its two dates. The preset years come from `board_default_year()` on the server.

**History, so nobody rebuilds a dead end.** It was fixed segments: "Starting by 2027" (default) /
"Any start date" from 10 Sep 2026. On 6 Nov 2026 Broc saw September 2027 starters under "Starting
by 2027" and took them for a bug - "by 2027" read as "by the start of 2027". It became "Before 2027
/ Before 2028 / Any" for one afternoon, then this, because the team asks "who starts between March
and June" and no set of buttons answers that. **Do not label a year cut "by".**

**THE DEFAULT IS NO LONGER NARROW.** The screen opens on the whole queue (no dates). The families
years away still say so on their own row (the amber `Starts` line below), which was the reason the
old cut was on by default. **"This year" is 1 Jan to 31 Dec literally**, so a family whose wanted
start date has already passed (2025 or earlier, still waiting) is not in it - clear the From box
to include them.

The Waiting tile names the range instead of saying "matching the filters": **"families starting
next year"**, **"families starting 1/03/2027 to 30/06/2027"**. The other filters win when both
are in force - they are the ones the reader just set.

**The row says which ones they are, and only those ones.** A late starter carries a small amber
`Starts 3/01/2028` line under the DOB. Blank on every other row, deliberately: a start date on
all 105 would be a column of this year repeated. It marks anyone starting after the end of next
year (`waitlist_starts_by_default()`), so it shows when no range or a wide one is in force.

The register (`waitlist_group_all()`) applies the same filter through the same function, on the
family's **earliest** `required_from` across their applications - a family asking Terrigal for
2027 and Wamberal for 2028 needs a place in 2027. Its tiles are computed before any narrowing,
so they still describe the whole register (534 families, 626 applications) whatever range is
chosen.

## The filters travel in the URL (16 Sep 2026)

They were browser-only state that started at their defaults on every load, so **switching centre
threw away the question somebody had just asked** and silently answered a different one - on the
screen whose whole job is comparing one queue with another. Same complaint and same fix as In
progress; see [work-layer.md](work-layer.md) for the other half.

**Every control whose id ends in `-filter` travels**: `show` (Open / All / Offer expired),
`start_from` / `start_to`, the register's `multi`, and the age range with the unit it is in (`age_mode` plus
whichever of `dob_from` / `dob_to` / `age_min` / `age_max` that unit uses).

- **`waitlist.php` owns the whitelist**, validates every one, renders the matching radio
  `checked` and the boxes' `value=`, and passes them to `layout_header` as `params` so the
  **centre tabs carry them**. `syncUrl()` in `waitlist.js` then rewrites the URL and those tabs'
  hrefs on every load, because the tabs are server-rendered links built at page open - without
  the second half, a filter chosen after the page loaded would still be dropped by the next
  switch, which is the bug rather than a smaller version of it.
- **A default is deleted from the URL, not written**, so a plain `/waitlist` stays plain and the
  query string names only what is actually narrowing the table. An unknown value falls back to
  the default rather than reaching `waitlist_show_opts()`.
- **`d-none` on the age boxes now follows `age_mode`** instead of being hard-coded to DOB. The
  reason that class is in the markup at all is to prevent a flash of the wrong pair before
  `syncAgeMode()` runs (see the DOB / age section above), and a `?age_mode=age` link would
  otherwise have flashed exactly that.
- **Only the pair in force is written**, matching what is sent to the server: a bound nobody can
  see must not be narrowing the table. The hidden pair keeps what was typed for the rest of the
  session and no longer than that.
- **`multi` is carried by centre pages that cannot show it.** "2+ centres" is a register control,
  so a centre tab renders no radio for it - but it has to keep the value in its own links or a
  there-and-back through a centre would drop it. `syncUrl()` reads it off the URL when the radio
  is absent.
- **THE SEARCH BOX IS NOT A FILTER AND DOES NOT TRAVEL.** `?q=` already means something else
  here - the palette deep-links with it, and that link also forces Open -> All so a closed match
  is not an empty table. A search is a question about one family, asked and finished; a filter is
  the shape of the list. The one change on that path: **the deep link only forces All when the
  URL did not name `show`**, or a centre switch carrying both would quietly undo the reader's own
  choice on every hop.

## Do Not Enrol: a Kidsoft TAG, checked against every application (7 Oct 2026)

Families we have ended care for (behaviour or finance) and will not take back. Asked for by
Broc; **the list is a guardian tag in Kidsoft, not a table of ours** - his choice, over a list
kept here. Staff tag the guardian when they end care; `_incl/do-not-enrol.php` reads it.

- **Any guardian tag whose name BEGINS "Do Not Enrol"** counts ("Enroll" too), so one tag
  works and so does one per reason. The full tag name is what the screen shows. **No such tag
  existed when this was built** - the check finds nothing until the team creates one.
- **Read through `guardians_by_id()`, which now asks for `PhoneNumbers,Tags`** - one read, not
  a second beside it. +0.8 MB JSON at Tuggerah; queue build 0.19s -> 0.29s; the All centres
  register peaks at 64 MB of the 128 MB FPM limit. **No endpoint returns a guardian row whole,
  and it must stay that way** - the tags carry DDR, ATSI status and court orders. Checked:
  `TagID`, `"Tags"` and `DDR` appear in none of list / group / get / cells / search.
- **THE MATCH IS THE SIBLING MATCH, against the tagged guardians**: Kidsoft's GuardianID on the
  application, the parent's email, and the parent's mobile against
  `guardian_household_phone_keys()` - extracted from `enrolled_family_index()` for this, so the
  two cannot drift (61 sibling matches before and after). Group-wide: of 604 open applications,
  45 shared an email or mobile with a guardian who has left, 19 of them from ANOTHER centre, and
  the GuardianID alone reached only 14.
- **IT WARNS; IT NEVER DECIDES.** A reissued mobile or a shared mailbox makes a false match, and
  a false match turns away a family who did nothing. Nothing is hidden or re-ranked - it is not
  a rung of `priority_rank()`. The row (queue, register, board shortlist) carries a red badge,
  the family modal an alert, and **`make_offer` refuses** until the offer form's tick
  (`dne_checked=1`) is sent. The match is re-read server-side at the offer, never trusted from
  the form, and the audit row records the sentence that was confirmed.
- **STARTING WORK ON THEM ASKS TOO (12 Oct 2026, Broc).** `/api/work` `create` refuses an
  application that matches, and returns `dne` on the refusal; `window.postConfirmingDne()` puts
  the sentence in a plain `confirm()` and re-sends with `dne_checked=1`, audited as
  `work.dne_checked`. One check covers all four ways in - the board's "Start with this family",
  the queue's and the modal's "Add to In progress", and booking a tour in the modal (saying no
  there leaves the application saved and says the tour was not booked). **A `confirm()`, not a
  tick:** these are one-click buttons, not forms, and the family modal is usually open beneath,
  where a second Bootstrap modal fights it for focus. The phone-enquiry form on In progress
  carries no application and is not checked.
- **SEARCH SHOWS IT (12 Oct 2026).** Every card carries `dne`. The case that matters is a family
  we ended care for RINGING: their child's Kidsoft record is inactive and is the card that comes
  up, so `dne_for_child_links()` flags it when the child's own parent (enrolling, Mother or
  Father) is tagged - "Tagged in Kidsoft: ...". Applications and past applications match on
  contact as the queue does. A supervisor's out-of-scope card has it removed with the rest. The
  index is built before section 1 so the two never sit in memory together; measured against the
  old code on the same three queries, +2 MB peak and no measurable time.
- **A supervisor** sees the centre and the tag for a family at a centre they do not hold, not
  the name - the siblings rule.
- **Kidsoft is read-only, so the list is edited in Kidsoft.** Removing a tag clears the match
  within the guardian cache's 3 h TTL (warmed 2-hourly).
- **Tested with a stand-in tag**, because the real one did not exist: `DNE_TAG_PATTERN` is
  define-if-absent so a CLI probe can point it at "Court Order" (2 matches) or "Ceased" (35 of
  604 open: 16 email+mobile, 14 record+email+mobile, 4 mobile only, 1 email only, 13 at another
  centre). The screens were driven with a match injected into the API response in the test
  browser only, and make_offer intercepted - **never test this by sending a real offer.**
- **Unconfirmed, and worth asking Kidsoft:** whether guardian tags are visible to parents in
  the Parent Portal.

## Offers

### OFFERS ARE SENT IN KIDSOFT, AND READ HERE (15 Oct 2026)

Broc found the green **Offer** and the bare **+** clunky: staff pressed Offer here and still had to
send the offer in Kidsoft, which is where the family's offer email comes from. He asked whether
to rename it "Offered on Kidsoft". **That would have been a button for telling this app something
Kidsoft already knows**, so it was measured first. Live, all seven centres, `WithOfferedDays=true`:

- **23 applications at an offer status** (`Offered` 8, `OfferedExpiryReminder` 2, `Form
  Submitted` 12, `Re-submit Required` 1). **23 of 23 carry `OfferedDays` and a
  `CareTypeMap.RoomID` that maps to one of our rooms** - 70 place-days.
- **0 of the 23 had been offered with this app's button**, so the board showed all 70 as free.
- **12 of the 13 offers ever made here had lapsed on our 14-day clock** while Kidsoft carried
  the same families on to Form Submitted or Enrolled.
- Terrigal pressed Offer here for Kahlani Tohu on 14 Oct; Kidsoft still had her at Waitlisted.

Broc chose to read Kidsoft and retire the button. What changed:

- **`KIDSOFT_OPEN_OFFER` in `_incl/board.php`** is the one list of statuses that hold a place,
  with the team's label for each and whether the family has answered. `Enrolled` is not on it
  (the bookings hold the place by then).
- **The import stores the offer** beside the requested days: `waitlist_offered_days` and
  `waitlist_applications.kidsoft_offer_room_id`, Kidsoft-owned, replaced on every sync - see
  `waitlist_offered()`. The offer's year is `required_from`'s, never behind this one.
- **`board_load()` holds it as `promised`**, named in the cell panel and the room sheet like any
  offer, so `room_day_vacancies()`, Fill and Marketing all follow. Skipped when the family still
  has a pending offer of the old kind, and for any day the child is already booked into in that
  room.
- **The queue row says what Kidsoft says** (`statusCell()`): "Offered in Kidsoft" / "Form
  submitted" / "Re-submit required", the offered room and days, and the deadline graded by
  `offerUrgency()`. **The offered days are NOT always the days asked for** - this is the Stella
  Knox report: she asked for Tue/Fri, Terrigal offered Tue/Wed, and with only the asked-for days on
  screen the two systems looked like they disagreed (3 of the 8 `Offered` rows differ). The family
  dialog shows the same line under "Days requested".
- **A submitted form has no deadline.** From `Form Submitted` on, `ExpiryDate` stops being a
  lapse date (9 of Wamberal's 11 were past it and still held), so the row, the queue tiles and
  the Dashboard's "expiring" count all withhold it - that was 13 of the 104 the Dashboard counted.
- **Fill leaves a family with a Kidsoft offer out of "Could take this day"** - our own offers
  did that by changing the status, which a Kidsoft offer leaves at `waitlisted`.
- **The pipeline follows** (`work_follow_kidsoft_offers()`, run by the import and by `create`) -
  see [work-layer.md](work-layer.md).
- **`make_offer` refuses**, the Offer dialog is gone from both the Waitlist and the Placement
  board, and the old Offer branch is kept unreachable so what it checked stays readable.
  `respond_offer`, `withdraw_offer` and `extend_offer` stay for the offers made before.
- **The seeded "Check Kidsoft before offering a place here" notes are gone.** Every offer status
  used to write one into an empty notes column, where it stayed after the offer ended: 138 rows
  said "Kidsoft has an offer open" about families Kidsoft had since moved to Waitlisted, Enrolled
  or Expired. The 156 exact copies were cleared, each audited; 7 with other text around them were
  left alone.

**`Re-submit Required` is an INFERENCE.** One live row (Heidi Gow, Terrigal), carrying
`OfferedDays` and a 21-day window: read as a form sent back for correction, so it holds. If a
second one turns up that looks different, re-measure before trusting this.

**One Kidsoft offer is not held**: a Point Clare application with a ChildID and no child name,
which the import has always skipped (`no_name`). 66 of the 70 place-days are held as a result.

### The offers made here before 15 Oct 2026

**An offer was made only by `/api/waitlist`**, because that path locks the room's capacity row
and re-checks vacancies **inside the transaction**.

- **A pending offer HOLDS its days** in `room_day_vacancies()`, so two staff cannot promise
  one seat. 14 days by default; `offers_expire_due()` runs on load.
- **A declined or expired offer keeps the family's original `date_added`** so they never lose
  queue position. **Partial acceptance keeps only the untaken days.**
- `make_offer` calls `work_link_offer()`, which now **raises a work item if none is open** --
  see [work-layer.md](work-layer.md) for the whole offer/work-item relationship, including
  `work_create_for_offer()` and why an offered item is uncancellable.
- Urgency thresholds live in **`_incl/offer-policy.php`** (`OFFER_URGENT_HOURS` 72,
  `OFFER_COUNTDOWN_HOURS` 48), published to the browser by `layout_header()` as `<meta>` tags.
  **Do not re-type either number in JS.**
- **Offers are never emailed to the parent, and that is deliberate.** Sending email from the
  tool is a **planned future expansion**, gated on the rest of the app being right first (Broc,
  13 Aug 2026: *"Need to get everything else 100% perfect before we send a single email to a
  parent."*). `graph_send_mail()` exists, is uncalled, and **must stay uncalled** - a parent
  receiving a wrong or duplicate offer email is not a bug you can take back. Do not wire it into
  `make_offer`, the expiry sweep or the work modal as a convenience.

## The family modal: ONE dialog, two tabs, opened by the child's NAME

`#wl-modal` holds the application editor and the contact log as tabs. Until 14 Aug 2026 they
were **two modals behind two items of the same "..." menu**, so the two things anybody wants
when a family comes up - what they asked for, and what we have already told them - were a
dropdown apart and could not be open at once. Reading the history before ringing is the whole
point of the log.

**It is SHARED with the Placement board**, which opens it from the "Could take this day"
shortlist: markup in `_incl/waitlist-modal.php`, behaviour in `static/js/waitlist-modal.js`,
both included by both pages. Same split as `work-modal.php`, whose modal could once only be
reached from a cell on the board. See
[placement-board.md](placement-board.md) for the board's half.

- **Public surface:** `window.openWaitlistFamily(id, onChanged)`
  (`addWaitlistApplication` was removed 6 Nov 2026 - see the top of this file). `onChanged()` runs after a save or a
  delete, **never after a logged contact** - a note changes nothing the host page is showing.
- **`/waitlist?centre=N&wl=<application id>` opens it on arrival** (17 Aug 2026), which is what
  the search palette now links a queue hit to. **The id is the only thing that identifies an
  application**: a name cannot, because one child holds two applications at ONE centre often
  enough to matter (Billy Bovis holds #52 and #60 at Tuggerah, asking for different days), and
  because since the palette merges a child's records the card is headed by Kidsoft's legal name
  while the queue filters on what the family wrote on the form. `q=` is still sent alongside,
  set to the **application's own** child name, so closing the modal leaves that row on screen
  instead of the whole queue.
  **It does not wait for `load()`** - `openWaitlistFamily()` fetches the application itself, so
  a deep link opens even when the row would have been filtered out of the table underneath.
- **It fetches the application itself** (`/api/waitlist?action=get`) rather than being handed
  the row the queue is holding, because the board has no such row. **The Add form pays for a
  round trip it used not to**, deliberately: the alternative is a second way of filling this
  form, and the field it fills is `date_added` - the queue's final tie-breaker, which this
  screen has already got wrong once by deriving it in the browser.
- **The escaper is the include's own (`$wlm_h`).** The block used to lean on a `$h` closure
  defined at the top of `waitlist.php`; included from anywhere else that is an
  undefined-function fatal, and **PHP lints clean with one.**

- **The name is the handle.** The row already names its own subject, so an "..." at the far end
  of it was a worse target than the thing itself. A `<button class="btn-link wl-open">`, not an
  `<a>`: it opens a dialog and navigates nowhere. `.wl-open` in `app.css` has to restate the
  colour and the size - `.btn-link` takes Bootstrap's `--bs-link-color` (navy), not the brand
  purple every real `<a>` uses, and `.btn-sm` would make the name smaller than the DOB line
  under it. Underline on hover only, matching `a.wl-ov-name` on the same screen.
- **`#wl-form` IS NO LONGER THE `.modal-content`, and it cannot be** - the contact tab holds a
  form of its own and nested forms are invalid HTML. Each pane carries its own form, and the
  Save/Delete footer lives inside the application pane. **If you add an input to this modal,
  check it is inside the right form**: one outside would simply stop being serialised, and the
  save would drop the field silently.
- **The history is fetched when its tab is first shown, not when the modal opens.**
  `api/comms`'s `list` reads this family's own Kidsoft comments - a live API call - and the
  editor is opened far more often than the log is read. `commsFor` / `commsLoaded` are reset in
  `openFamily()`; **every line of that function is a way the modal could otherwise show the
  PREVIOUS family**, since it is one element reused by every row.
- **Contact history is disabled while adding**, because there is no application to hang a note
  on and an empty log reads as "nobody has rung them".
- **"Also waiting at" sits in "What they want"** (`#wl-elsewhere`, filled by `renderElsewhere()`).
  Not a field - there is nothing to edit, because it is other applications - but a place at
  Little Miracles is what they want, and 12% of the queue asked several centres for one. The whole
  block is hidden when there is nowhere else and on the Add form, and **it is reset before the Add
  form's early return**, or it would keep the previous family's chips: one element, reused by every
  row on two screens. The chips DO carry the queue-position pill here, where there is room for it.

### The tour in this modal is the WORK ITEM'S (10 Sep 2026)

Broc: *"could the In progress 'tour booked' align with the waitlist when you click on their name
and there is a 'tour' spot?"* It could not, because **they were two fields recording one visit**:

- `waitlist_applications.tour_booked_at`, a plain DATE in this modal's Enquiry block - one of the
  twelve columns copied off the `Enquiries-Tours` spreadsheet sheet, which
  [work-layer.md](work-layer.md) had already measured as **empty in both archived workbooks**;
- `work_items.tour_at`, the real appointment - a **date and a time**, with `tour_outcome`
  afterwards, ordered by `work_urgency_sort()` and counted by `work_tours()`.

**They had already come apart on the second family who ever had one.** Jack and Sersha Donnelly
were booked into one **19/09/2026 09:30** tour at Terrigal; only Sersha's application carried a
`tour_booked_at`, without the time, and Jack's read blank. Across 836 rows that column held
**1 value**; `orientation_at` and `guide_provided` still hold **0**.

So the box is now a `datetime-local` named `tour_at`, and it belongs to the item:

- **It is read from `work_for_waitlist()`**, published on `/api/waitlist?action=get` as `work`.
  That function picks ONE item - an application can have several open ones, since `work_create()`
  de-dupes on room and year - preferring the one that already carries a tour, then the earliest
  tour, then the oldest item, so booking here moves the appointment that exists rather than
  quietly making a second one.
- **It is written to `/api/work`, never to the application**, and the save is a SECOND request
  made after the first succeeds. `work_set_tour()` is what advances the item to `Tour booked` and
  writes the history line; a bare column update would put a family in the diary and off the
  pipeline, the trap `work-modal.js` already documents.
- **With no item yet, booking a tour CREATES one** - the same payload the queue's "Start
  enrolment" button posts, tour and all, and **no room**: a family in the queue has not been
  matched to one, and guessing their preferred room would file the item against a spot nobody has
  decided on. This is the right way round, because the tour is usually what puts a family into
  the pipeline in the first place.
- **A tour that did not save does NOT close the dialog.** The application saved and the
  appointment did not, and closing on that leaves somebody sure they had booked one.
- **"It has been and gone - did they come?"** appears in `#wl-tour-ask` on the same terms as in
  the work modal, because it is the same function: `window.tourAskHtml()` in `app.js`. It saves
  on its own click and then **re-reads through `action=get`** rather than guessing the new stage,
  which is not guessable - a no-show sends the item back to `contacted`.
- **`tour_booked_at` is no longer written by anything.** It is out of the form AND out of the
  save's UPDATE, which is the part that matters: a field the form stops posting is a field the
  UPDATE quietly nulls. The column and its one value are left in the table.
- Widths in that row are **5 / 3 / 4**: the tour needs 5 for a date and a time, and the checkbox
  needs 4 or "Consumer guide provided" wraps under a bottom-aligned box.

#### The call that was NOT answered needs a door too

Broc, same day: *"you would either call straight away and book, or mark as identified and then
call and leave VM and call them back until you can book a tour."* Booking a tour was the modal's
**only** way into the pipeline, which covers the first half and nothing else - and **the call
that goes unanswered is the one that needs remembering**, because there is nothing in a diary to
bring the family back. The queue row's own "+" could do it, but only by closing the dialog you
were reading the family in.

`#wl-add-progress` - **Add to In progress** - creates the item with no tour. The stage vocabulary
already had the right word: `identified` is *"a candidate for this place. Nobody has rung them
yet"* and `contacted` is *"we have spoken to the family"*, so **a voicemail correctly leaves them
at Identified**, and logging it on the Contact history tab moves nothing (`api/comms` never
touches a stage - see [work-layer.md](work-layer.md)). The tour box above promotes them the
moment somebody gets through.

- **Hidden once an item exists** - the note names its stage instead, and a button that would hand
  back the same item (`work_create()` is idempotent on who/room/year) is a button that does
  nothing. **Hidden on the Add form** too: no application for an item to point at, the same
  reason Contact history is disabled there.
- **`progressPayload()` is ONE definition, shared with `saveTour()`.** Two ways in, one shape,
  no room on either. Driven end to end: Add -> `Identified`, then a tour on the same family ->
  **one** item at `Tour booked`, its history reading `Identified` then *"Tour booked for
  26/09/2026 10:45 AM."*
- **It saves on its own click**, like the tour answer, because it writes nothing on this form -
  making it wait for Save would tie starting a family to an application edit nobody was making.
- The form-text stays **one line**. "...in In progress at Tour booked" was true and wrapped,
  pushing the button down; which stage each door lands on is on the button's `title=`.

## "Contacted" (11 Nov 2026)

Broc: *"the same 'Last contacted' (the same data as from 'In progress') as a new column in the
waitlist"*. Centre queue only, between Added and Actions; the All centres register is untouched.

**Headed "Contacted", one word, because the header must not wrap** (Broc, same day). "Last
contacted" broke over two lines; held on one it is **120px against an ~88px date**, which left
the table 30-40px past its box at 1280. Broc chose the rename over that, knowing **"Contacted" is
also the name of a stage** on the Status chip. "Last contact" (101px) was the halfway option.

- **It IS In progress's "Last interacted", not a second definition.** `waitlist_last_touched()`
  takes an open item's own `last_touched_at` / `last_touched_by` off the `work_list()` row the
  queue already composes, so the two screens cannot disagree: **39 of 39 families with an open
  item matched exactly** on the day it was built. A family with no item gets the half that needs
  none: a contact note by a person on the application or its Kidsoft child (`created_at`). A note
  logged from In progress carries `waitlist_id` too, so nothing is missed. 120 of 711 open
  applications had a date.
- **So it counts more than calls**, exactly as In progress does: a stage move, a next step or a
  tour by a person is a touch. Kidsoft's own moves and the nightly jobs are not.
- **Kidsoft has no contact date to use instead** - measured, see "No last contacted date" in
  [kidsoft-api.md](kidsoft-api.md).
- **One renderer:** `window.lastTouchedCell()` in `app.js`, moved out of `progress.js`.

**WIDTHS, re-measured at 1280 over all eight centres.** Before this column the table was already
9-31px past its 1030px box at four centres; the column added ~90px and sat hidden under the
pinned Actions. Paid by:

- **Priority, Added, Contacted declared below their floors** (content sets them: 80-96, ~87,
  88 - a date in `.small` and the header "Contacted" are each ~88px).
- **The Status badges wrap** (`text-wrap text-start`, as Priority's already did): "Attempted
  contact" on one line set the column at 154px at every centre; now 88-94.
- **Below 1400px only** (`app.css`, beside `.wl-child`): cell padding `.3rem` a side instead of
  `.5rem`, Days wanted 12 -> 11.5rem and Guardian 9 -> 8.5rem. Those two widths moved out of the
  inline `style=` into `.wl-days` / `.wl-guardian`, because an inline style beats a media query.
  11.5rem minus the tighter padding is exactly the strip's 10.9rem: **751 strips over eight
  centres measured on one line.**

Result, with the one-word header: **min-content 995-1027 in 1030 at 1280 at seven centres;
Niagara Park 1036, 6px over** - its queue went from 80 to 134 open families during the
afternoon's imports, and three-digit queue numbers plus three Enrolment buttons are ~20px on
their own. **1280 is that tight for ten columns and moves with the data**, so re-measure rather
than trust these figures. 1366: 995-1036 in 1116. 1440: 1078-1134 in 1190 (Terrigal had been
2px over there before). Every header on one line at all three widths.

## The Actions column: ONE PRIMARY BUTTON, and measured widths

### Since 15 Oct 2026: no Offer button, a labelled Start, and the row wash

**The Offer button is gone** (see **Offers**), and the bare **+** that sat beside it was the other
half of the same complaint. What the row offers now follows the family (`actions()`):

| The family | Primary |
|---|---|
| nobody is on it | outline **Start** - adds them to In progress at Identified; the full name is on `title` |
| an item exists | **Enrolment**, opening the item - outline, FILLED once they have answered (the move is ours) |
| an offer made here before 15 Oct is still open | **Answer**, with Withdraw in the `...` |

- **"Start", not "Add to In progress".** The full label was tried first and put the table **80px
  past its 1030px box at 1280**, sliding Added under the pinned column. "Start" is the board's own
  word for the same act ("Start with this family").
- **Re-measured against the old script served in its place**, same data, 1280px: Terrigal **47 ->
  30px** over, Wamberal **35 -> 0**, Tuggerah 0 -> 0; **0 at 1440 and 1920**. Terrigal's remaining
  30px is Kahlani Tohu's old-style "Offer open" status with a full date and time, and goes when
  that offer does. **So the "0px overflow" recorded below had already drifted before this change.**

**THE ROW WASH: "already talking to them"** (Broc, same day: *"if they are identified/in progress
the whole background is a different colour, so you can scroll through and see"*). `.wl-engaged`
on any open row with an item at any stage, an offer of either kind, or both. **Set as
`--bs-table-bg` on the `<tr>`**, not as a cell background, so every cell - the pinned Actions cell
included, which now reads `var(--bs-table-bg)` instead of `#fff` - takes it without a specificity
fight, and Bootstrap's hover shadow still lands on top.

- **`--lm-engaged-wash: #f1effd`**, a new token: brand purple's hue at the lightness where the
  row's small text still passes. **`--lm-purple-light` was the obvious pick and fails**: `--lm-muted`
  drops to 4.12:1 on it. On the new wash it is 4.55:1, ink 12.3, purple 9.4, `--lm-req` 5.1.
- **Not on the group register.** That table is a different shape (one row per child across
  centres) and `tr.wl-multi` already uses its first-cell accent.

The column reached four buttons and was losing. Declared `min-width`s summed to **89.5rem**,
so at 1280px with the sidebar out the table ran **1292px inside a 1030px box**; under
`table-layout: auto` the browser squeezes proportionally, so **Actions was handed 71px for
191px of buttons** and sat off the right-hand edge entirely.

**A dropdown holding ALL of them was the obvious move and is the wrong one.** The primary
action IS the row's state: green **Offer** means nobody has acted, filled **Answer** means a
family is waiting on us with a 14-day clock running, and that reads down the column at a
glance. Behind a `...` every row looks the same. So the primary stays out.

**What is left in the menu is what the row's STATE affords** (14 Aug 2026): Withdraw offer, and
Open enrolment. Edit and Contact history left with the modal merge above, and **Add to In
progress came out as a plus button** - with those two gone it was the only item on the
commonest row in the queue, a menu to hold one thing on 700-odd rows. Its label survives as
`title` + `aria-label`; an icon alone says "plus", not what it adds to. **With no items the
menu is not rendered at all** rather than opening empty, so the ordinary row is now `Offer` and
a plus and nothing else. Measured after: **0px overflow at 1280, 1440 and 1920.**

**Status sits beside the name**, not out at the far end (14 Aug 2026). Where a family has got
to is the second thing anybody wants after knowing who they are, and with Actions pinned right
the old position put it at the furthest point on the row from the name it describes. The
declared width does not change; nor does the overflow, because a `min-width` below a column's
own floor is free either way.

**The guardian's email is a `mailto:` link with an envelope**, matching the phone above it.
Both are ways of reaching the family and only one of them used to be reachable from here. It
costs nothing in width - `.wl-email` caps and truncates the line, so the icon sits inside a box
whose floor was already the cap. Verified against the old markup on the group table: **1px
overflow at 1280 either way, which was there before.**

- **A DECLARED WIDTH BELOW A COLUMN'S OWN FLOOR IS FREE.** The content wins and the browser
  gives it the floor anyway, so every rem declared above it is pure spend. Measured floors: #
  49, Child 100, Guardian 160, Days 73, Priority 199, Added 93, Status 89, Actions 71 --
  **833px all told, comfortably inside 1030.** The table was never too wide; the declarations
  were. **Superseded 17 Aug 2026 by the nine-column measurement in the "Also waiting at" section
  above** - that row is the eight-column table, and the floors moved when the Guardian email cap
  and the priority badges did.
- **`.badge` IS `white-space: nowrap`, AND THAT IS WHAT ACTUALLY BLEW THE TABLE OUT.** A
  priority reason like "Already in care at Tuggerah" is one unbreakable 199px token. The
  **reason** badges take `text-wrap`; the **status** badges deliberately do not, because a
  status is one short label and breaking it reads as two. This alone took the overflow from
  19px to **0**. Result: **0px overflow at 1280, 1440 and 1920.**
- **A PINNED CELL CONTAINING A DROPDOWN NEEDS `.dd-open`.** Actions is `position: sticky;
  right: 0` so it can never be the column lost at a narrower width. But **sticky ALWAYS
  creates a stacking context**, even at `z-index: auto`, unlike relative or absolute - so the
  menu's z-index 1000 was confined to its own cell and every LATER row's pinned cell painted
  over it. **Removing the z-index did not fix it, which is what proved `sticky` itself was the
  cause.** The cell holding the open menu is lifted for as long as it is open.
- **Popper must run `strategy: 'fixed'`.** `.table-scroll` is `overflow: auto`, so an
  absolutely positioned menu is clipped by it. Instances are constructed in `bindDropdowns()`
  rather than left to the data-api, because the config can only be given at construction.
- **A TRUNCATION CAP IS A COLUMN WIDTH, so it belongs to the table, not the function.**
  `guardianCell()` is shared by the centre queue and the group register, whose Guardian columns
  are 9rem and 12rem, and `text-truncate` is `white-space: nowrap` - so the cap becomes that
  column's floor. A single 14rem was what made the centre table's Guardian column 240px. It is
  now `.wl-email` with a per-table override: **8rem** centre, **11rem** group. The group table
  also came to 1037px in a 1030px box, so **Waiting at** gave up 0.5rem.

**None of this is visible to a lint or a page render at one width. Re-measure rather than
reason about it** - the scratchpad holds Playwright scripts that read given / natural / floor
per column and click each dropdown.

### A row that has already ANSWERED must not lead with "Offer" (3 Sep 2026)

Found on a real Kanwal row. The family had said yes, and the row read as an untouched queue
entry: a grey **Waitlisted** badge, "Accepted" in 0.8rem muted grey beneath it, and out at the
right the green **Offer** button - the mark this column uses for *nobody has acted*. Two staff
reading that row would promise the same seat twice.

Two changes, and they are the same fix seen from each end of the row:

- **The work stage is a BADGE, not a muted line** (`stageBadge()`). How far through enrolling a
  family is outranks where their application sits in the queue, so it gets the same weight of
  mark - and the same shape In progress uses, so the two screens name the state the same way.
  **Amber on `accepted` because the move is OURS**: they have answered and the place is not in
  Kidsoft yet. Green is `enrolled`, which means finished. **The stages before an offer stay
  quiet** - "somebody has rung them" is context, not a state to act on, which is the distinction
  the muted line was making before it got applied to every stage.
- **`accepted` / `enrolled` take the primary button**, which becomes filled **Enrolment** and
  opens the work item. Offering again is still reachable, demoted into the `...` as **Offer
  another place** - a family who took two of the three days asked for is a real case, and
  demoting a button must not delete the action. Re-measured: **0px overflow at 1280, 1440 and
  1920**, Actions steady at 120px.

**AND THE OPEN-OFFER BRANCH USED TO SWALLOW THE STAGE ENTIRELY**, which was the half of this
the first pass missed. `statusCell()` returns early when there is a pending offer, so a row at
stage `accepted` whose offer row is still `pending` - staff advanced the pipeline by hand rather
than through **Answer** - showed "Offer open" and an **Answer** button about a family who had
already said yes, with the acceptance rendered nowhere at all. **Two of Kanwal's four accepted
families were in exactly that state** while the other two showed the new badge, so the column
looked arbitrary. The stage now renders alongside the offer whenever `hasAnswered()`. **Two
badges contradicting each other is the honest reading of contradictory data**, and Answer stays
the primary: the offer is pending, it is still holding the days in `room_day_vacancies()`, and
answering it is what makes the two halves agree.

**Stages before an offer are still grey on purpose**, and nothing in the data exercises them
today - every work item in the group is at `offered` or `accepted`.

**WHY THE ROW WAS IN THAT STATE AT ALL was a separate bug, upstream of both.** The offer was
created with an `expires_at` already in the PAST, so `offers_expire_due()` killed it on the next
page load, put the application back to `waitlisted` and left the queue with no trace an offer
had ever been made. **3 of the 7 offers ever made were dead the moment they were written** -
Marli Kerslake once and Gus Mclean twice, inside seven minutes. Fixed 3 Sep 2026; see
`offer_expiry_normalise()` in `_incl/waitlist.php` and the section below.

## An offer's expiry: `offer_expiry_normalise()`

`_incl/waitlist.php`. **One function because there are two doors** - `make_offer` and
`extend_offer` both take this field, and until 3 Sep 2026 both checked only that it PARSED.

- **A date that has passed is refused.** An offer could be written already dead: the screen said
  "Offer sent. It expires 24/08/2026", `offers_expire_due()` killed it on the next page load,
  the application went back to `waitlisted` and the work item back to `contacted`. Nothing reads
  a non-pending offer row, so the queue showed an untouched-looking family and the audit log was
  the only survivor. **The same staffer did it three times in seven minutes, which says the form
  invited it.**
- **`extend_offer` needed the guard MORE than `make_offer` did.** The action is called extend, so
  a past date there does not fail to lengthen an offer - it shortens a live one to death. That
  door has no UI today, which is exactly why it would have gone unnoticed.
- **A bare date runs to the END of its day** (23:59:59). `<input type="date">` sends
  `2026-09-17` and `date_create()` makes that MIDNIGHT, the first instant, so "expires on the
  17th" gave the family none of the 17th and picking TODAY produced an expiry that had already
  passed. The empty-field default keeps the time of day (`now + 14 days`), so the two paths
  disagreed about what a date means. A value carrying its own time is left alone.
- **The check moved up with the other cheap input validation**, before `room_day_vacancies()`
  does any work - a bad date should not be reported after a capacity failure it did not cause.
- **`min=` on the form field is a convenience, not the check.** A tab left open overnight carries
  yesterday's min and the attribute is trivially removed; the server refusal is what holds.

**The one live offer written before the fix was corrected the same day.** Offer 32 (Baby
Collings) carried `2026-09-30 00:00:00`, which would have cost that family the whole of the
30th; it now reads `23:59:59`. Done through `extend_offer` rather than an UPDATE, so it is
audited like any other change (`offer.extend`, from/to recorded) and so the endpoint's accept
path was exercised on a real row. No other pending offer expires at midnight.
