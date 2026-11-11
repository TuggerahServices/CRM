# Rooms and centres: read from Kidsoft, not edited

Governs `rooms.php`, `api/rooms.php`, `static/js/rooms.js`, `_incl/rooms.php`,
`_incl/rooms-sync.php`, `_incl/centres-sync.php`, `_incl/centre-names.php`, `_incl/capacity.php`.

Read the header of `_incl/rooms-sync.php` before changing any of this.

## A ROOM IS READ FROM KIDSOFT, NOT EDITED

The screen once offered **Add room, an editable name, editable age bands, Archive, a Kidsoft
mapping dropdown and a per-weekday capacity override. All six are gone** - deleted from the API,
not merely hidden in the UI (11 Aug 2026).

A room's name, age band, existence and capacity are all Kidsoft's, so this was the one screen
still asking the team to keep a copy of something the API answers.

**Measured before removing them, and this is why it was safe:**

- **All 40 rooms were linked**, and every stored name and age band agreed with Kidsoft
  **exactly** (0 differences group-wide).
- Our 4 inactive rooms were **exactly** the 4 Kidsoft reports `Active='No'`.
- **Zero capacity overrides had ever survived**: all 360 rows read `source='kidsoft'`. The
  override was exercised twice in July 2026 and reverted the same minute.
- **Zero sub-quotas and zero notes**, then as now.

So the whole editable surface was a copy that happened to be right, maintained by nobody, **one
typo away from attaching bookings to a room nobody could find.**

**What is still editable, and the test for anything new: sub-quotas and notes, because Kidsoft
has no field for either.** Same test as offers, work items and the internal day waitlist. For
anything on a ROOM it is not a close call.

## `rooms_sync_centre()` - two load-bearing rules

- **`Active` IS THE STRING `'Yes'`/`'No'`, NOT A BOOLEAN.** The old code tested
  `!($r['Active'] ?? false)` - so `'No'` passed as truthy and **every inactive room read as
  active**. That is very probably the entire origin of the long-standing "Kidsoft lists both
  halves of its near-duplicate pairs as active" claim: measured 11 Aug 2026, the phantom
  "Amazing Asteroids Purple" reads `Active='No'` and the group is a clean **36 Yes / 5 No**.
  `room_ks_active()` compares properly.
- **A room is created only when `getUtilisation` reports operational positions for it**, never on
  the flag alone. That is the documented "which rooms are real" test, and what keeps the phantom
  off the board. **Creating a room that does not exist dilutes every fill figure and invites
  somebody to sell a place in it; not creating one costs a night.**
  **So when the utilisation read comes back empty - an outage, or a centre Kidsoft will not
  answer for - nothing is created or retired on that run**, and fields only are refreshed. A
  room we hold that Kidsoft does not return at all is **left alone and counted**, because a
  scoping change is likelier than a room ceasing to exist, and retiring it would silently take
  its children off the board.

**Joined on `RoomID`, never on a name.** Kidsoft has real typos ("Amazing Asterouds") and
near-duplicates ("Amazing Asteroid Purple" vs "Amazing Asteroids Purple" - the bookings are all
on the singular one). Guessing would attach capacity to the wrong room and corrupt every
occupancy figure. The exact-name matching the seeder used to do, and the traps that made it
necessary, went with the typing.

**Age bands NOW HAVE A REFRESH JOB.** This used to argue one was unnecessary because 0 of the 40
mapped rooms disagreed with Kidsoft - still true when re-measured 11 Aug, and **still the wrong
conclusion**. The reason is not drift-that-has-happened but **who is supposed to fix drift when
it does**: the answer was "a human, on the Rooms screen", and that screen no longer has a field
for it. **Agreement measured over a year is evidence the sync will be quiet, not evidence it is
unneeded.**

`Service/getRooms` also returns `EducatorChildRatio` and `MinStaff`, unused.
`min_age_months`/`max_age_months` come from its `MinAge`/`MaxAge`.

## The age tier is the room NAME (`room_type_key()`, `room_types()`)

**Kidsoft has no age-group field**, and this was probed field by field rather than assumed (15 Sep
2026, every field `getRooms` returns): `MinAge`, `MaxAge`, `ApprovalType`, `KindergartenRoom`,
`EducatorChildRatio`, `MinStaff`, and nothing resembling a tier.

**The licensed bands do not bucket cleanly either** - thirteen distinct `(min,max)` pairs across
the 40 rooms, with Kanwal's Shining Stars at **24-60m** against every other Shining Stars' 24-36,
and Tuggerah's Mighty Meteors Silver at **36-72m**.

So the tier is **the first word of the room name**, measured across all 40 rooms before anything
was built on it: the five families have distinct first words, so it collapses Tuggerah's
Pink/Silver/Purple variants **and absorbs Warnervale's "Amazing Asterouds"** - a typo in Kidsoft's
own room name that any whole-name rule would have made a sixth family. (The `roomIcon` sprite map
in `svg.php` already had to do the same thing, which is why its stem is `aster`.)

`room_types()` derives the list, the LABELS and the ORDER from the data every run - label is the
most common full name in the tier, order is its median `min_age_months` (Little 0, Radiant 12,
Shining 24, Amazing 36, Mighty 48) - so **a centre that opens a room in a new family is picked up
with no code change**, and a room matching nothing becomes its own group rather than being folded
silently into another tier. Used by the Dashboard's weekly report; see
[dashboard.md](dashboard.md).

## Capacity

**Capacity comes from Kidsoft** - `Service/getUtilisation` -> `OperationalPositions`, **per room
per weekday**. The long-standing claim that "Kidsoft has no capacity field anywhere" was WRONG
and cost the project a hand-maintained denominator for months. Verified across all 7 centres and
both years.

**`OperationalPositions` does not vary by date within a room-weekday** (0 of 55 combos), which is
the measurement that justifies the per-weekday capacity model.

`room_year_capacity.source` always reads `'kidsoft'` now, and the override that made the
`'manual'` value meaningful is gone. **A year with no capacity row is UNKNOWN, never zero**, and
unknown capacity never counts as free.

**A year's capacity is read from FOUR sample weeks - mid Feb, May, Aug and Nov - first hit
wins** (`capacity_util_year()`). November was added 2 Oct 2026 for **Erina**, which opens Tue
6 Oct and whose rooms report positions only from the week of 5 Oct: the Feb/May/Aug reads were
all empty, so its 2026 had no capacity while 2027 did, and **neither the Refresh button nor the
nightly sync could ever have fixed it** - both read the same three weeks. Measured before the
change: November altered nothing for the other seven centres in either year. **A centre opening
after mid-November is still missed for that year.**

**There is no way to record "this room is shut on Wednesdays"** while Kidsoft still reports
positions for it - `getOpeningHours` gives zero weekday closures. If that is ever needed, the
answer is a closure flag that is honestly ours, **not an editable capacity figure that silently
competes with Kidsoft**. See [kidsoft-api.md](kidsoft-api.md).

## How a NEW CENTRE reaches this tool

`_incl/centres-sync.php` -> `/api/rooms` (`centres`, `sync_centres`, `save_centre`,
`move_centre`) -> the `centre=0` tab of `rooms.php`. Added 11 Aug 2026.

**Before this there was no answer to that question.** `centres` was written by exactly one thing -
`seed.php`, CLI-only, needing an SSH session as root - so it was the one piece of Kidsoft
reference data whose refresh was a developer, and a centre opening could not reach the team's
tool without one. Broc has several centres opening shortly.

Three defects found while establishing that, all fixed:

1. **`sort_order` defaulted to 0 and centres sort by it**, so a new centre landed at the FRONT of
   the centre bar ahead of Tuggerah at 1 - and `centre_current()` falls back to the first
   centre, so **an empty centre with no children would silently have become the default landing
   centre** for every session with no choice in it. New centres are appended
   (`MAX(sort_order) + 1`).
2. **A centre going Archived in Kidsoft was never deactivated here.** The seeder looped ACTIVE
   services only, so an archived one was never visited and kept `active = 1`.
3. **The friendly name lived in `_incl/centre-names.php`**, so renaming a centre was a code
   change. It is a column now, edited on this tab. **That file survives as a SEED for the
   derivation only.**

### The ownership line

**Kidsoft owns whether a centre exists and what it calls itself; we own the SHORT NAME, the ORDER
and THE DAY IT OPENS.** `name`, `sort_order` and `opens_on` are **set once on insert and never
written by the sync again** - a sync that reverted a rename is the silent-destruction-of-edits
failure. Verified both ways: a rename survives a subsequent sync, and a live `centres_sync(0)` run
on 21 Sep 2026 left Warnervale's `opens_on` at 2027-01-04.

## `centres.opens_on` - the day a centre starts trading (21 Sep 2026)

Asked for by Broc: *"Warnervale centre doesn't start operating until January 2027; so a lot of the
functionality of this app won't apply to it in 2026 and often has to throw warning/notices, or be
actively excluded."*

**NULL means "has always been open"**, which is every centre but Warnervale. No dates were
backfilled for the six that were already running - an invented date is worse than no date.

### Kidsoft was asked first, and it holds the wrong answer

**`Service/getServices` has no opening date.** Nine fields, and the only one that is a date is
`UpdatedDateTime`. Probed 21 Sep 2026 over all 16 services with **both** its documented `Options`
(`Addresses`, `PhoneNumbers`); the response gains `Addresses` and `PhoneNumbers` and nothing else.

**`Service/getOpeningHours` DOES give a per-date `Open`/`Closed` status** for the service and for
every room, with `OperationalPositions` - and it is the obvious candidate. It is measurably wrong
for the only centre it matters for. Probed the same day, 1 Jan to 31 Dec, service and room level:

| | first `Open` date | pattern |
|---|---|---|
| the six trading centres, 2026 and 2027 | **1 January** | 261 open weekdays, 104 closed |
| **Warnervale 2026** | **15 June 2026** | service AND all four rooms, positions 16-25 |
| Warnervale 2027 | 1 January 2027 | 261 open weekdays |

**15 June 2026 is the day the record was set up, not the day the doors open** - Warnervale's
address was last updated 15 June and the service record 22 June. So Kidsoft believes that centre
has been trading for three months. The endpoint answers a different question, and **an opening
date read from it would have put Warnervale into every 2026 figure in the app.**

That is what makes this column legitimate rather than another stale copy. The three overrides this
project deleted - the capacity override, the sibling tick, `priority_of_access` - all duplicated a
fact Kidsoft answered **correctly**. This one records a fact Kidsoft answers **wrongly**, which is
the same footing as `day_holds` and the role/centre scope. See [architecture.md](architecture.md).

### What it decides, and what it must never decide

It decides **scope**: whether a centre is counted in a given year. It never touches a figure that
is derived from bookings.

`centre_year_state($board)` in `_incl/board-live.php` is the one implementation, and every screen
that used to test `board_trading($board) === true` now asks it instead:

| state | meaning | counted |
|---|---|---|
| `trading` | children are booked | yes |
| `opening` | opens later this year, nobody booked yet | **yes** |
| `unopened` | does not open at all in this year | no - and NAMED, with its date |
| `empty` | should be running, and has nobody in it | no - **and this is an alarm** |
| `unreachable` | Kidsoft would not answer | no - a gap in the data |

- **EVIDENCE BEATS THE DATE.** A booked child makes a centre `trading` whatever the date says, so
  a typo can never hide real children.
- **The date expires itself.** `opening` requires the opening day to be still ahead; once it has
  passed with nobody booked the centre reads `empty`. A state that cannot age into "opening soon"
  about a date in the past.
- **`empty` is the state this column bought.** Before it, "open and nobody in it" and "not open
  yet" arrived as one answer - `trading === false` - and the app treated both as nothing to see.

### Why `opening` is COUNTED (Broc, 21 Sep 2026)

Because the opposite was measurably hiding the group's biggest opportunity. Warnervale 2027, on
the day this was built: **385 place-days a week of capacity, 0 children, 17 families waiting** -
and `board_trading()` returned `false` for 2027 exactly as it did for 2026, so the centre was
excluded from the Dashboard totals, from Places to fill and from the Marketing ranking. **The
centre with the most to sell was missing from the screen that says where to sell.**

Counting it moves a figure Broc hands to management, and that was his call to make: **group fill
for 2027 reads 64.0% with Warnervale in it against 73.0% without.** Both are true; the first is
the one that describes what the organisation has to sell.

**Anything counting place-days ACROSS a year starts from the opening day**, not from 1 January -
`centre_year_start()`. A centre opening in November has two months of the year to sell, not twelve,
and the Marketing screen's "unsold place-days" is multiplied out per centre because of it.

### WHAT IT SWITCHES OFF, SCREEN BY SCREEN (21 Sep 2026)

Broc: *"it is pointless showing and calculating a placement board for 2026 for warnervale... I
like the new 'nothing to show' screen you have for warnervale's 2026 marketing page - can we apply
that across the whole project?"*

**The rule is a YEAR-SCOPED ROOM VIEW, not "hide the centre".** The queue, the offers, the work
items, the tours and the contact log are not year-scoped, and they are at their BUSIEST before a
centre opens - Warnervale has 17 families waiting and an average wait of 44 days today. Blanking
those would hide the only thing that centre currently has.

| screen | before | now |
|---|---|---|
| **Placement** | a full grid, 385 free, headline *"20,097 un-occupied positions across 2026"*, **7.2s** | the not-open state; endpoint answers in **4ms / 222 bytes** |
| **Tenure** | the whole ladder over a turnover reading `-- not trading`, **7.1s** | the not-open state, **4ms**. No "look at 2027" button: this screen's stepper stops at the current year on purpose, so there is nowhere to send them |
| **Dashboard** (centre) | *"Places to fill 0"*, *"Filled -- 0 of 0 place-days"*, four room cards of nothing | the two ROOM tiles read `--` and name the date; the four room cards become one; **the queue, pipeline, tours and work cards are untouched** |
| **Placement overview** (group) | a row reading 385 free, 0.0% filled, and a *"Set 2026 capacity"* prompt | `--` across the week, and no prompt - there is nothing to fix |
| **Marketing** | (was the first screen to get this, 21 Sep) | unchanged, now through the shared component |
| **Rooms** | rooms, age bands, per-weekday places | **KEPT, with a banner.** This is the screen you check a centre's setup on in the months before it opens; blanking it would remove the one view that is useful then |
| **Waitlist / In progress / Support plans / Search** | - | **untouched, deliberately.** None is year-scoped |

**ON THE PLACEMENT SCREENS THE TEST IS THE FROM DATE, NOT THE YEAR** (Broc, 27 Oct 2026). The
board counts from a date (`board_from()`), so `/api/cells` and `board_overview()` pass it as
`centre_not_open_yet($centre, $year, $from)`: Erina (opens 6/10/2026) has no board from
1/09/2026 and a full one from 6/10/2026. The payload's `from` says a date ruled it out, so the
panel reads "No placement board from 1/09/2026" and its button lands on the opening day. Every
other screen passes no date and still asks about the year.

**And it stops the WORK, not just the display.** `centre_not_open_yet()` needs one already-loaded
row - no board, no Kidsoft, no cache - so every one of these returns before the first API call
rather than rendering a derived year and hiding it:

- `/api/cells`, `/api/tenure`, `/api/dashboard` and `/api/marketing` all guard before deriving.
- `board_overview()` skips the derive and emits a null row.
- **`dash_reenrolment()`** skips the centre: who is re-enrolling is a question about this year's
  roll, and a centre with no roll has nobody to ask about.
- **`week_ahead()`** skips it: it was already silent for a centre that books nothing, but only
  after a Kidsoft read per centre.
- **The HOURLY board warm** (`cron-nightly.php --warm`) skips it, which is the one place the
  saving repeats - Warnervale 2026 was a live Kidsoft round trip 24 times a day for a centre with
  no doors open.

**THE NIGHTLY ROOM AND CAPACITY SYNC DELIBERATELY DOES NOT SKIP IT.** That is the same question
with the opposite answer: those reads are what populate the Rooms screen, and checking that a new
centre's rooms, age bands and places are set up correctly is exactly what the team does in the
months before it opens.

`window.notOpenState()` in `app.js` draws it, once, for all four screens - see
[ui-conventions.md](ui-conventions.md).

### Editing it

On the **Rooms screen's All centres tab**: the **pencil at the end of the row** opens the dialog
holding the short name and the opening date, and the day is badged beside the centre's name while
it is still ahead.

**The centre's NAME links to that centre's rooms (22 Oct 2026)**, as a centre name in a table does on
every other screen (`.ov-centre-link`). It used to be the button that opened this dialog, and Broc,
looking for where to set Warnervale's date, did not think to click it - nobody would. A retired
centre's name stays plain text: the centre bar leaves it out, so there is no rooms page to land on.
The pencil has an `aria-label`, not a `.visually-hidden` span (see the phantom-height trap in
[ui-conventions.md](ui-conventions.md)); verified 0px horizontal overflow and an 800px document at 1280.

**Who reaches it, checked 22 Oct 2026 as the Terrigal supervisor account:** `/rooms?centre=0` lands
on Terrigal's own rooms (no All centres tab, no register, no dialog in the page), `centres` returns
Terrigal alone, and setting an opening date, reordering and syncing are each refused. **One thing a
supervisor CAN still do is rename their own centre** - `save_centre` allows it by design (below) -
but no screen a supervisor can open offers it, so it is reachable only by a hand-made request. **A manager or administrator only** -
like reordering the centre bar, and unlike the rename, because this one decides whether a centre is
counted in every group figure in the app. Verified 21 Sep 2026 at the endpoint: the Tuggerah
supervisor setting a date on their OWN centre gets *"Only a manager or administrator can set a
centre's opening date"*, and their rename still returns ok. `31 February` is refused by
`checkdate()`. Every change writes its own `centre.opens_on` audit row, separate from the rename.

### Other decisions

- **A centre CANNOT be pre-created before Kidsoft has it, deliberately.** One with no ServiceID
  has no rooms, capacity, bookings or queue, so every screen would show it empty and every group
  aggregate would need to special-case it - the Warnervale lesson with nothing to measure at
  all. The nightly run picks it up; **the button is for anyone who will not wait.**
- **`Status` is a TWO-VALUE field.** Over all 16 services: `Active` 7, `Archived` 9, nothing else -
  no pending or draft state, so a new centre appears as Active on the day it is created. The
  9 archived are FORMER centres, not shells (real rooms, hundreds of children each, all archived
  24 Nov 2024), which is why **an archived service we have never held is skipped rather than
  imported.**
- **The name derives correctly with no configuration.** Stripping the brand prefix gets all 16
  right, including "Little Miracles Preschool and Long Daycare Mt Riverview" -> **Mt Riverview**
  and "...& Long Day Care (Toronto)" -> **Toronto**. So a new suburb centre needs no config
  entry, which was the point of moving the name into a column.
- **`sync_centres` fills a newly-discovered centre in the same request** - rooms and capacities
  for this year and next. **An empty centre is indistinguishable from a broken one on every
  screen**, and leaving it blank until 02:00 would look like a failure.
- **CENTRES SYNC BEFORE ROOMS in the cron**, because `rooms_sync_all()` iterates the centres we
  hold; discovered afterwards, a new centre would sit empty until tomorrow.
- **`centre_move()` renumbers 1..N before swapping.** Swapping two equal `sort_order` values is a
  no-op that looks like a broken button, and a hand-edited table accumulates duplicates and
  zeroes.
- **Saving a name or an order does a full page reload**, not a re-render: the centre bar and every
  nav link in the chrome are built server-side from both.
- **No Status column**, for the reason the room table lost its Kidsoft-link one: it would read the
  same on every row. A centre we have retired, or one Kidsoft has archived since the last sync, is
  **badged beside its name**. Kidsoft's status is reported ALONGSIDE ours, never instead of it, and
  **an unreachable Kidsoft leaves it null rather than reading as "archived"**.

## Refresh from Kidsoft

`sync_kidsoft` (cache bypassed) does rooms and capacities in one action, **sharing ONE utilisation
read between them** - both need it, and at `ttl=0` the cache cannot save the second pass. **It
reports what CHANGED, not what was looked at.**

Verified by breaking it on purpose inside a rolled-back transaction: a renamed room, a wrong age
band, a wrongly-deactivated room, a genuinely-inactive room and a deleted room were each repaired
or recreated correctly in one pass, **and the phantom stayed out.**

## `_incl/seed.php`

Creates centres from `getServices` and hands the rooms to `rooms_sync_all()`. **It no longer reads
`rooms.json` at all.**

It had been **exiting 1 at its first `file_get_contents` ever since the workbooks were archived on
6 Aug** - so the room seeding nobody wanted took first-run centre creation, the one job it is
still needed for, down with it.

## The Rooms screen layout

- **The All centres tab is the centre register** - which centres the organisation holds, their
  short names and the order of the centre bar. **A different shape, not the same table filtered.**
- **The Actions column wrapped** at one point, dropping the archive icon to a second row and
  doubling every row's height. A fourth TEXT button is what does that - see the Waitlist's
  measured-widths section in [waitlist.md](waitlist.md).
- **NO PERCENTAGE ANYWHERE ON THIS SCREEN, and that is right.** Asked directly on 10 Sep 2026
  while the fill figures were being fixed: the three tiles are Rooms, Places per week and Needs
  attention, all **counts**, and the table is capacity per weekday. Rooms is where capacity is
  read, not where fill is judged - the percentage lives on the Placement board, against the
  bookings. Nothing here needs `board_pct()`; if a figure ever does, see
  [placement-board.md](placement-board.md) first.
- **`tile()` in `rooms.js` is a WRAPPER, not a sixth copy** of the stat tile. This screen's
  local version took `(label, value, cls, hint)` where every other took `(label, value, hint,
  cls)`, so the wrapper swaps the arguments rather than every call site being rewritten.
