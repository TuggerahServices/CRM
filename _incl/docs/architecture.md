# Architecture: the mirror, the work, and the join

Read this before changing schema, adding a table, or deciding where a new feature lives.

## Three layers

Broc: *"Kidsoft always was and always will be the undeniable source of truth. Looking at
a weather app may tell you what the weather is, but looking out the window will tell you
what the ACTUAL weather is."*

1. **The WINDOW - Kidsoft, read live, never copied.** Rooms, capacities, bookings, who is
   in which room on which day, this year and next. Derived on read, cached for speed only.
   `_incl/board-live.php`.
2. **The WORK - ours, and Kidsoft has no field for any of it.** Each empty place is a job:
   a candidate family, a phone call, a tour, an offer, a follow-up. `_incl/work.php`. This
   is the Jira layer, and it is what the spreadsheets actually were.
3. **The JOIN.** A work item points at a spot. When the window shows that spot filled by
   that child, the item closes ITSELF (`work_autoclose()`). Nobody marks anything done;
   reality does. That is also the only honest definition of success, because it is the
   moment the organisation starts being paid.

## The test for a new feature

1. **Can the Kidsoft API answer this?** If yes, read it - live and cached, never copied
   into our tables, because a copy drifts the moment Kidsoft changes.
2. **Why is a human typing this?** A screen that asks staff to re-enter something Kidsoft
   knows is a bug.

Our database is for what Kidsoft genuinely does NOT hold: **sub-quotas**, the
new-enrolment queue, comms, work items, the internal day waitlist, **days held
for a child who is not booked on them** (`day_holds`), **the day a centre starts
trading** (`centres.opens_on`), audit, and **who may sign in to this tool**
(`app_users` / `app_user_centres`).

**OFFERS CAME OFF THAT LIST ON 15 OCT 2026.** Kidsoft holds them: `getWaitingList` with
`WithOfferedDays` gives the offered days, `CareTypeMap.RoomID` the room and `ExpiryDate` the
deadline, on 23 of 23 open offers that morning. The `offers` table and the Offer button were a
second copy staff had to make by hand - 0 of those 23 had been - so the board was selling 70
promised place-days as free. The import now stores the offer beside the requested days and
`board_load()` holds it; `offers` survives only for the rows made before. See
[waitlist.md](waitlist.md).

**`centres.opens_on` is the newest of those (21 Sep 2026) and it earned its place by
measurement.** Kidsoft has a candidate - `Service/getOpeningHours` states `Open` or
`Closed` for every date, for the service and every room - and it is WRONG where it
matters: it reports Warnervale open from 15 June 2026, the day the record was created,
where the centre does not trade until January 2027. `Service/getServices` holds no date
at all. So this is not the fourth stale-copy override; the three that were deleted all
duplicated a fact Kidsoft answered correctly. See
[rooms-centres.md](rooms-centres.md).

**The last of those is ours by definition, not by omission.** Entra says who a person IS;
it has no idea that Little Miracles has a role called Supervisor or that this one covers
Niagara Park and Terrigal. That is a CRM fact, so it lives here - see
[auth-security-ops.md](auth-security-ops.md).

**`day_holds` is the clearest case of that test the app has.** A centre asked how to hold
a child's Friday while his booking is off for a Behaviour Support Plan. Kidsoft's only
record of a place being held IS the booking, and the booking is what has been removed -
so there is no field to read, no parameter that would reveal one, and nothing to go stale
against. See [support-plans.md](support-plans.md).

**Where a Kidsoft figure IS stored locally, store its provenance too** --
`placements.identity_synced_at`, `room_year_capacity.synced_at`. It is how a figure that
looks wrong is told from one that is merely old.

## `placements` is an ANNOTATION store, and nothing else

Status, the four child-level flags, a note, keyed on (kidsoft_child_id, room, year) --
plus the rare child pencilled in before Kidsoft knows them (a family this app has offered
a place to, since we cannot write back). **It is never the source of who is on the board.**
`board_annotations()`.

**The 870 copy rows were deleted 6 Aug 2026.** Verified first that not one carried a
status, flag, note, offer link or provisional name. Why the copy was wrong:

- All 870 were pure copies: zero non-default statuses, zero flags, zero notes, zero
  provisional children. Every byte derivable.
- Stale **by construction**. Niagara Park's stored 2026 board matched Kidsoft's **July**
  bookings 157 of 157 - the month somebody pressed the button - and already disagreed
  with August on 6. Month to month, 4-19% of (child, room, days) arrangements change.
- **2027 was EMPTY while Kidsoft held 220 children already re-enrolled**, because
  `board_import_year()` was wired to nothing and Roll over needed a human.
- `_incl/drift.php` - 500 lines, an OSA distance function, a bespoke near-name matcher --
  existed **solely to detect that the copy had gone stale.**

Deriving costs nothing: one API call per centre-year, 0.05s for the whole group warm,
~2.6s cold.

**Live row counts (13 Aug 2026):** `placements` 0, `placement_days` 0, `work_items` 0,
`communications` 0, `offers` 0, `internal_day_requests` 0, `room_subquotas` 0.
`waitlist_applications` 722 open. So the work layer, contact log, offers and the internal
day waitlist are **built and correct but not yet exercised in production** - treat their
sections as design intent, and expect the first real rows to find bugs a screenshot cannot.

## The general rule this keeps arriving at

**When a figure moves off `placements`, grep for every other query that reads that table.**
Twice now a caller was left counting an annotation store and reported every room as empty:
`room_day_vacancies()` (fixed 6 Aug) and `fill.php` (fixed 11 Aug - see
[placement-board.md](placement-board.md)).

**And an override nobody has ever exercised is not a safeguard, it is a copy going
stale.** Three instances: the room capacity override (0 rows in 360), the sibling checkbox
(0 ever ticked in 100 detections), `priority_of_access` (743 of 743 agreed with Kidsoft).
All three were removed or switched to live derivation. Before defending an override,
measure how often it has diverged.

## The model (derived from the real workbooks)

The team's working artifact was a **placement board**: one per centre per **year**, a sheet
per room, numbered slots down, weekdays across, a named child in each cell, status encoded
as the cell's **fill colour**. Two consequences drove the schema:

1. **Capacity is PER YEAR** (`room_year_capacity`). Tuggerah runs 194 places/day in 2026
   and 170 in 2027 - five rooms shrink. Measuring one year's bookings against another
   year's capacities falsely reports rooms as over-capacity. A year with no capacity row is
   **unknown**, never zero.
2. **The slot number is meaningless** - a child sits in row 3 on Monday and row 11 on
   Wednesday. So a placement is (child, room, year) + weekdays, and occupancy is a COUNT,
   never an assignment to a numbered seat.

Status (from the workbook legend) is stored separately from the child-level flags
(sibling / staff's child / 2 educators / behaviour plan), because a single cell colour
could only ever carry one of them.

**The supplied example spreadsheets and `rooms.json` are OBSOLETE and archived** to
`/var/lib/crm-archive` (0700 root). They were measurably wrong: truncated
double-barrelled surnames (`Lewis-Menard` -> `Lewis`), 26 of 261 children misspelt, 43
booked-and-billed children missing, one room's capacity wrong (14 vs Kidsoft's 15), and
room NAMES that attached a room to the wrong Kidsoft twin.

## Screens

| Screen | File | Purpose |
|---|---|---|
| Dashboard | `dashboard.php` | **Landing screen.** The enrolment process end to end. The only screen crossing the mirror and the work layer. |
| Placement | `placement.php` | The working screen, scoped to a calendar **year**. All centres = whole-org overview; a centre tab = its cell grid. |
| Waitlist | `waitlist.php` | Queue, offers, contact log. All centres = a group **register**, one row per child. |
| In progress | `progress.php` | Every family part-way through enrolling. Was **Offers** until 10 Aug 2026; `offers.php` is a 301. |
| Tenure | `tenure.php` | How long children stay, and the movement that cuts it short. Gross flow, never net change. |
| Rooms | `rooms.php` | Rooms, age bands, per-weekday capacity - **all read from Kidsoft, none editable**. Only sub-quotas and notes are typed. All centres = the centre register. |
| Support plans | `support-plans.php` | Children on a Behaviour Support Plan, the days held for them, and when the family was last spoken to. Ordered by neglect. Group-wide for a manager, own centres for a supervisor. |
| Marketing | `marketing.php` | Places going spare that **nobody in the queue can take**, by centre and age group, ranked. The one screen whose answer is "nobody in this app can fill this" - see [marketing.md](marketing.md). |

**Search is not a screen.** It is a palette in the chrome, on every page, **Ctrl+K** (and only
Ctrl+K - see the shortcut note in search-identity.md).
See [search-identity.md](search-identity.md).

**The group view is a DIFFERENT SHAPE, never the same table filtered.** Which one renders
is decided **server-side**, so only one set of containers is ever in the DOM. Three screens
learned this the hard way (Placement, Waitlist, Rooms).

**A SUPERVISOR HAS NO GROUP VIEW AT ALL** (19 Aug 2026). Every screen above is pinned to one
of their centres and the "All centres" tab is not rendered - so a two-centre supervisor is
never shown a third shape aggregating just their two, and no screen has to decide what "all"
means per person. `centres_visible()` is what a screen passes to `layout_header()`;
`centre_current()` refuses `centre=0` for them; **and the endpoints check for themselves**,
because the tab strip is a display decision and `?centre_id=` is the door. See
[auth-security-ops.md](auth-security-ops.md).

**Search is the deliberate exception and stays group-wide for everybody**, because a parent
rings whichever centre they can and the person answering has to be able to say where the
child is.

## Retired, and why - do not rebuild these

- **Roll over** (`rollover.php` -> 301). The board derives from Kidsoft, so next year's
  board IS next year's bookings. It fills itself as each centre processes re-enrolments.
  The decision it modelled - which room a child moves up into - is made by the centres
  **in Kidsoft**. This app's job is to show that happening and work the places it leaves
  empty, not to ask the team to type it twice.
- **Drift as a concept** (6 Aug 2026). With the board derived there is nothing to
  reconcile: the board IS Kidsoft. `drift_year()`, `drift_week()`, `drift_compare()` and
  `drift_drop_shadowed_casuals()` were called by nothing (verified 13 Aug 2026) and are now
  in **`_incl/retired/drift-compare.php`**. They were moved rather than left in place because
  they **reasoned about casual days** - they held casuals aside as context and captioned a
  difference "plus a casual Friday" - and casual days are now out of this tool entirely (see
  the hard rule in [CLAUDE.md](../../CLAUDE.md)). `_incl/drift.php` survives for two live
  things: `coverage_map()`/`coverage_year()` and the name matching (`name_fold()`,
  `osa_distance()`, `name_is_near()`) that `search.php` and `siblings.php` reuse. **Do not
  delete those with the rest.**
- **The two week-shaped engines** -> `_incl/retired/week-views.php` (13 Aug 2026), same
  reason and same day. `occupancy_week()` collapsed every booking row onto (room, date, child)
  with **no `Casual` test at all**; `day_requests_for_dates()` was the per-date twin of
  `day_requests_year()` carrying **none** of the filters that function has grown - no
  archived-child test and no casual-type test. Both already had zero callers. **If a week view
  is ever wanted again, narrow the year engines; do not revive these.**
- **Board import** (`board_import_year()`) - was never wired to anything. In `_incl/retired/`.
- **Fill / Drift / Occupancy screens** -> 301 to `/placement`. They were four screens
  rendering the SAME room x weekday grid from four sources that could and did disagree.
  Now one grid. Their JS and APIs are in `_incl/retired/` (inside `_incl`, so nginx blocks it).
- **`_incl/occupancy.php` is NOT retired** - the screen was; the engine is live and
  required by `cells.php`, `tenure.php`, `drift.php` and `cron-nightly.php`.

## `_incl/retired/` IS A GRAVEYARD, AND IT IS KEPT ON PURPOSE

Broc, 13 Aug 2026: *"it is a graveyard of old things. It is sometimes useful."*

**Nothing loads any of it** - not one file in there is required by the live app, and it sits
inside `_incl`, so nginx 404s it. **Do not "tidy up" by deleting it**, and do not wire anything
back to it without moving the file out first.

Currently: `api-drift.php`, `api-fill.php`, `api-occupancy.php`, `api-rollover.php`,
`engine-board-import.php`, `engine-rollover.php`, `fn-centre-years.php`, and the four retired
page scripts (`drift.js`, `fill.js`, `occupancy.js`, `rollover.js`).

**Retire by moving, not deleting.** `centre_years()` went there on 13 Aug 2026 - defined in
`_incl/rooms.php` and called by nothing live, its only remaining caller being
`retired/api-fill.php`, so the two now sit together.

## Deliberately uncalled, NOT a loose end

**`graph_send_mail()` in `_incl/graph.php`.** Sending email straight from the tool is a
**planned future expansion**, and it is gated deliberately: Broc, 13 Aug 2026 - *"Need to get
everything else 100% perfect before we send a single email to a parent."*

So the plumbing exists and stays, and **nothing may call it** until that bar is met. A parent
receiving a wrong or duplicate offer email is not a bug you can take back. Do not wire it into
`make_offer`, the expiry sweep or the work modal as a convenience.


---

# THE PLACEMENT EDITOR OFFERS ONLY WHAT IS OURS (18 Sep 2026)

Broc: *"I don't think it should be possible to move a child, change status, etc. All this data is
synced from Kidsoft. Kidsoft is still the single source of truth, and editing it here would cause
a discrepancy."*

**Measured first.** The whole `placements` table is **3 rows, all linked to a Kidsoft ChildID, all
`confirmed`** - 0 provisional, and only 2 carry a flag or a note. The table is now an annotation
store and nothing else.

Days were already refused for a linked child and the caller told (6 Aug 2026). Room and status were
not, and **two of the three did worse than disagree with Kidsoft**:

- **STATUS FEEDS OCCUPANCY.** `board.php` drops a `waitlist` or `no_position` row from the day's
  roll, for linked children as much as provisional ones. Marking a linked child either would have
  taken them out of the count while Kidsoft still had them booked, and the room would read one
  place emptier than it is - on the figure the whole app exists to get right.
- **THE ANNOTATION IS KEYED `(child, room, year)`** and `board_annotations()` matches on
  `ksChildId|roomId`. Changing the room moved our row to a room the child is not in, where it can
  never be matched again - so their flags and their note **silently stopped applying to them**.
- **"Remove from board" could not remove a linked child.** They return from Kidsoft on the next
  read, minus their flags and their note. The button promised something it could not do.

So for a **linked** child the editor now offers the name (read-only, from Kidsoft), the flags and
the note. Days, room, status and the delete button are not shown. A **provisional** child - one
Kidsoft has never heard of - keeps all of it, because nothing else in the world knows about them.

**THE GUARD IS SERVER-SIDE AS WELL**, because the control being hidden is a display decision and a
posted field is the door - the same rule `api_require_centre()` follows. `save_placement` now keeps
a linked child's existing status whatever is posted, and says so in the same warning that already
covered days. Verified by posting `status=waitlist` for Logan directly at the endpoint: the row
still reads `confirmed`.

Verified in the browser both ways: a linked child's editor shows flags and notes only; a
provisional child's still saves a day change, a status change and a flag in one submit.

**SINCE 6 NOV 2026 A LINKED CHILD OPENS THE CHILD DIALOG INSTEAD** (`static/js/child-modal.js`,
see [placement-board.md](placement-board.md)). The rule above is unchanged: the flags and the note
are the only things it edits, through the same `save_placement`. Everything else on it is read.
