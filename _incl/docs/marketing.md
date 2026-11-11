# Marketing: places to sell that nobody is waiting for

Governs `marketing.php`, `api/marketing.php`, `_incl/marketing.php`, `static/js/marketing.js`, and
the `MARKETING` block in `static/css/app.css`.

Asked for by Broc, 18 Sep 2026:

> Identify where there is a gap between "available spots" and "waitlisted children", ordered by
> most pressing. For example, if we had a room in a centre that had numerous spots across all days
> of the week, but noone was waiting to get in, we would want to start advertising efforts in order
> to reach people with children of that age-range to advise them that we have room available.

## What it is, and what it is NOT

**It is the second half of a report that already exists.** The Dashboard's Places to fill
(`dash_fill_report()`, 15 Sep 2026) says where the free place-days are, by room tier and centre and
weekday, and it was asked for in almost the same words - *"so we know where to keep the
marketing/focus"*. What it cannot say is whether anybody is already waiting for them, and that is
the whole difference between the two things this organisation does with an empty place:

| | what it means | who does it |
|---|---|---|
| places free **and a queue for them** | make an offer | the work layer, and every screen already points at it |
| places free **and nobody waiting** | nothing inside this app can fill it | somebody has to go and find a family |

**Only the second is on this screen.** The figure it is built on is the **UNCOVERED PLACE-DAY**: a
place going spare on one weekday that no waiting child could take even if the team rang every one
of them this afternoon.

**It does not rank centres, rooms or staff, and it carries no advice.** The rows are figures. The
team runs these centres and knows what an empty Mighty Meteors Friday means; a sentence explaining
it is the caption that ages into something wrong (see CLAUDE.md).

## The numbers it was built on (18 Sep 2026, all seven centres)

| year | places free | uncovered | where the gap is |
|---|---|---|---|
| 2026 | 124 | **38** | Mighty Meteors (Tuggerah 17, Kanwal 10), Amazing Asteroids (Kanwal 5, Point Clare 4), Mighty Meteors Terrigal 2 |
| 2027 | 620 | **28** | Amazing Asteroids Kanwal 13, Mighty Meteors Kanwal 9, Shining Stars Point Clare 5, Amazing Asteroids Terrigal 3 |

Two things fall straight out of that and neither was visible on any other screen:

- **The gap lives in the OLDEST tiers.** 27 of 2026's 38 uncovered place-days are Mighty Meteors,
  which is the hardest thing this organisation sells because the children who would fill it are
  starting school. Measured against 615 open applications group-wide, which is a queue that looks
  enormous until you ask what it is a queue **for**.
- **Friday is half of it.** 19 of 38 in 2026 and 16 of 28 in 2027, against Tuesday's 2 and 0. The
  Dashboard found the same shape for free places in general (Mon 32 / Fri 35 of 115 on 15 Sep
  2026); this says the demand does not follow it.

## The unit is the PLACE-DAY. The row is a CAMPAIGN.

The gap is **measured per room x weekday cell**, because that is the unit of work everywhere in
this app and the only level at which supply and demand are comparable: a family wanting Mon/Wed can
take one Monday place and one Wednesday place, and nothing else.

It is **reported per centre x room tier**, because that is what an advertising decision is - "we
have room for three-year-olds at Kanwal", never "we have room on Kanwal's Thursday". The weekdays
ride along on the row, because they are what the ad copy says, but they are not what is being
decided.

**WHY THE TIER AND NOT THE ROOM, MEASURED.** Tuggerah runs three Shining Stars rooms, three Amazing
Asteroids and two of everything else, and the same waiting child is age-eligible for all three of a
family. Counted room by room that child is counted three times against three rooms' places:
**Tuggerah's 2027 Shining Stars gap reads 8 place-days per room and 3 once each child is counted
once.** The tier is also the only level at which the answer is a campaign - there is no ad that
targets Pink and not Silver.

`room_type_key()` / `room_types()` own the tier, which is the room name's first word and was
measured against all 40 real rooms - see [rooms-centres.md](rooms-centres.md). Kidsoft has no
age-group field at all, and bucketing by the licensed band files Kanwal's Shining Stars (24-60
months) with the three-to-fives.

## Composed, never recalculated

Every figure comes from an engine that already owns its question. There is no second occupancy
calculation here, and no second definition of who is waiting - **that is what once made the Board
and Fill disagree**.

| question | engine |
|---|---|
| how many places are free on this cell | `board_load()` -> `cells[wd]['vacancies']` |
| who in the queue could take one | `fill_suggestions()` -> `candidates[].eligible` |
| who already in care has asked for that day | `day_requests_year()` + `internal_requests_year()` |
| is this centre open, opening, empty or unreadable | `centre_year_state()` |
| how many of each weekday the year has left | `year_weekday_dates()` |

Demand per (tier, weekday) is the **union of waitlist ids** across the tier's rooms that have a
place free that day, plus the **distinct children** who have asked for the day - keyed on the
ChildID both request sources carry, so a parent who booked a Wednesday in the portal **and** rang
the centre is one family wanting one day (the rule `cell_merge_requests()` owns).

**Demand is only counted where there is supply, in both directions.** A child who fits only a room
that is full cannot take this tier's spare place, and a full room contributes no place for them to
take - so the same rooms decide both halves of the comparison.

## Three things that would have made it lie, all measured

### 1. A CLOSED CENTRE IS 92% OF THE GAP

Warnervale has 385 place-days a week of capacity, zero children and zero bookings. Unfiltered it
contributes **333 uncovered place-days against the six trading centres' 38 put together** - it
would have been the entire screen, and it is not a marketing problem, it is a centre that is not
open yet. A centre Kidsoft would not answer for is a gap in the data and says so in a banner, and
so does a centre that is open with nobody in it. **A centre that has not opened says nothing at
all on the group ranking** - it had the quietest of the three banners until Broc asked for it to
go (21 Sep 2026): he runs these centres and knows which of them are trading, so a grey line on
every load of the All centres tab was the caption rule in CLAUDE.md rather than the
thing-they-cannot-know a banner is for. It still gets a full answer on its OWN tab, which is the
empty state below.

**AND THE YEAR IT OPENS IS THE OPPOSITE CASE (21 Sep 2026).** `board_trading()` was the test here
until `centres.opens_on` existed, and it returned `false` for Warnervale in **2027** for exactly
the same reason it did in 2026 - no bookings yet. So this screen excluded the centre that opens in
fifteen weeks with **385 place-days a week and 17 families waiting**: the biggest marketing target
in the group, missing from the ranking built to find it. `centre_year_state()` tells the two apart
and `opening` is ranked like any other centre. On the day it landed, Warnervale took the top four
rows of 2027 - Shining Stars 125 free / 4 waiting / **113 uncovered**, and Radiant Rainbows with
**nobody waiting at all**, which is the state this screen was asked for and had never once been
able to show on live data. See [rooms-centres.md](rooms-centres.md).

### 2. THE QUEUE HAS TO BE SCOPED TO THE YEAR

**31 of the 615 open applications cannot start until 2028 or later.** Counted as demand for a 2026
place they close a gap that is real: the 2026 figure reads **26 uncovered place-days across 9 cells
with the whole queue, and 37 across 15 with the queue cut** to families who need a place by 31
December. A 30% understatement, and every one of those place-days is a place standing empty all
year.

The cut keeps a row with no date at all (Kidsoft populates `RequiredFrom` on 100% of open
applications, so a blank one is a hand-keyed row taken over the phone and its silence is not an
answer).

**SINCE 6 NOV 2026 IT IS THE BOARD'S OWN RULE, NOT A YEAR-END CUT.** `marketing_centre()` passes
`start_window => FILL_START_WINDOW_DAYS` to `fill_suggestions()`, exactly as `cells_year()` does: a
family counts as waiting for a place only if they start **within 90 days of it coming free, and
never after 31 December** of its year. Until then this screen cut at 31 December (`starts_by`, now
removed) while the board's "Could take this day" had moved to 90 days - so Enzo Galli, starting
12/07/2027, covered a Tuggerah 2027 place here and was no longer offered it there. Two answers to
"who is waiting" is the disagreement this screen's header forbids. Broc asked for the two to match.

**Measured the day it changed: the families counted as waiting in 2027 fell from 458 to 376, and no
gap moved** (2027 107 place-days a week, 2026 100, both before and after). In every tier with a gap,
the families who can start in time still cover the same place-days; the late starters were all in
tiers with a queue to spare. 2026 could not move: in November 90 days already runs past 31 December.

**NOT done, and offered instead:** applications flagged "Consider archiving" (`waitlist_stale()`,
start date more than six months gone) still count as waiting here. Not counting them would take
the 2027 gap from 107 to 140 place-days a week and 2026's from 100 to 114. It is a flag, not a
verdict - some of those families are still waiting - so that is Broc's call.

`for_year` was NOT used for this. It is seeded by Kidsoft on insert and 326 applications carry
`for_year = 2026` with `required_from` dates running from February 2022 to January 2028;
`required_from` is the date the family actually named.

### 3. FREE MEANS THE BOARD'S FREE

`board_load()`'s `vacancies` is `capacity - holding - promised - held - reserved`, and all three
kinds of promise have to come off: a place held for a child who has not answered re-enrolment is
not for sale, and neither is one held under a support plan. **2027 group-wide: 137 place-days held
for re-enrolment and 5 under a pending offer.**

**`fill_suggestions()` subtracts only `holding` and `promised`, so its own `free` reads 737 where
the board reads 620** - a live disagreement, and its own comment says it "MUST match
`board_load()`". **Nothing reads it:** `cells_year()` is its only live caller and takes only
`candidates`, `eligible_count` and `totals['queue']`, and the cell panel gates the shortlist on
`c.free`, which is the board's figure. So the effect today is wasted computation on cells whose
places are all held - **but a new caller that trusted `fill_suggestions()['cells'][wd]['free']`
would advertise or offer a place that is being kept for a child.** Marketing takes the board's
figure and says so at the call site. **Not fixed here on purpose** - it sits in the offer path's
engine and was outside the ask; it is worth fixing in its own change, with the board, the panel and
the offer endpoint looked at together.

## A year with no capacity is UNKNOWN, never sold out

The nightly job reads capacities for **this year and next only** (`cron-nightly.php`, job 5), so
stepping the year to 2028 legitimately finds none - and with no capacity there are no free places,
no rows, and an empty list that is **indistinguishable from a year that is completely sold**.
"Every place is filled in 2028" over 2,725 unmeasured place-days is exactly the confident lie the
Dashboard's fill card told the first time it looked at Warnervale.

So the payload carries **`priced`** - how many tiers have a capacity for that year - and it is not
derivable from the rows, because a trading centre with every place sold contributes none. With
`priced = 0` the screen **withholds all four figures** (a closed centre gets `--` and no
commentary, the same rule) and the list says, once: *No room capacities recorded for 2028. Kidsoft
is read for this year and next, so there is nothing yet to measure 2028's queue against.* No button
to press: there is nothing the team can do about it and next January fills it in.

`measured` is the same shape one level up - centres genuinely counted and genuinely read - so an
empty ranking cannot be confused with a group where every centre is shut.

**THE EMPTY STATE IS NOW A SHARED COMPONENT.** This screen had the only copy; Broc asked for it
across the project on 21 Sep 2026, so the markup moved to `window.notOpenState()` and Placement,
Tenure and the Dashboard draw the same thing. Marketing keeps its own SENTENCE - *"There is
nothing to advertise for 2026"* - because it is the answer where the others can only name what is
absent. See [rooms-centres.md](rooms-centres.md).

**A CENTRE THAT HAS NOT OPENED GETS AN ANSWER, NOT A BLANK.** On Warnervale's own 2026 tab
`measured` is 0, so the ranking draws nothing - and a page carrying one grey banner and nothing
else reads as a screen that failed to load. It gets the empty state instead: *"Warnervale opens
4/01/2027 - there is nothing to advertise for 2026"*, with a button to the year it does open. This
is now the ONLY place the fact appears: the group banner that used to carry it was removed on 21
Sep 2026 (above), which also settles the duplication it once had to dodge - saying it twice two
inches apart was the figure-and-its-caption problem in two sentences. **This is the one empty
state here with somewhere
to send the reader**; the no-capacity one deliberately has no button, since nothing the team can do
fills a year Kidsoft has not been asked about yet.

## The two shapes

**The group view and the centre view are different shapes, not one table filtered** - the rule this
app has had to relearn on three screens. Which one is built is decided by the server-resolved
`#init-centre`, never by a control.

- **Group (All centres) is an OVERVIEW.** Four tiles, then the ranking **cut down to the rows
  that have a gap** - one row per centre x age group, most pressing first, with the weekdays as a
  neutral `dayStrip` - then, under the table and inside the same card, **three lines of cuts that
  run ACROSS it**.
- **One centre.** A matrix: age group down, **weekday across**, which is the cell the team actually
  fills, with the row's totals on the edge and an `All` footer, and **every tier the centre has**,
  gap or no gap. Same split, and for the same reason, as the Dashboard's Places to fill.

### The group list is the GAP, not every tier (21 Sep 2026)

Broc: *"the 'all centres' page is meant to be an overview/dashboard to gauge organisation-wide
insights; not just a completely unfiltered view of all data."*

**Two thirds of the group table was the answer the screen is NOT about.** Under a heading that says
"Where to advertise", a row with places free and a queue that covers them says *do not advertise
here* - and there were **19 of them in 2027's 29 rows and 13 of 2026's 18**, pushing the rows the
screen exists for off the first screenful. `marketing_load()` filters `gap > 0` when `$centreId`
is 0, **after** `marketing_summary()` has counted every row.

**Nothing is lost, because the count was already on the screen.** The *Age groups to advertise*
tile reads "10 of 29 groups with places free", which is exactly what is listed over what was
measured. The covered tiers are still on that centre's own tab, and that is the point of the
split: on one centre the reader is asking about THAT centre and a tier whose queue covers it is
part of the answer, not a row in the way of one. This is the group-is-a-different-shape rule
rather than one table filtered two ways.

**It needed a second empty state.** With the cut in place an empty group list has two meanings and
they are opposites: nothing free to sell, or everything free already spoken for. `summary.rows`
survives the cut and tells them apart - *"Every free place in 2026 has somebody waiting who could
take it, across all 18 age groups with places free"* against the original *"Every place is filled
in 2026."* Verified by forcing the filter.

### Where the gap falls: three cuts, three LINES

| cut | order | what it says that the ranking cannot |
|---|---|---|
| **By centre** | the centre bar's own order | Warnervale is **332 of 2027's 359** uncovered place-days while holding four rows of twenty-nine. A ranked list cannot show a concentration |
| **By age group** | `room_types()`, youngest to oldest | 2026 reads **0, 0, 0, 10, 28** from the youngest tier to the oldest; 2027 is the other way up, because next year's babies are not enrolled yet. In tier order that shape reads straight off the line |
| **By weekday** | Mon-Fri | Friday was **19 of 38** in 2026 against Tuesday's 2 |

**Every cut is counted off EVERY row, including the ones the list drops.** A centre reading
"0/78" is the useful opposite answer - *there is nothing to advertise there* - and a cut that only
saw the listed rows could not give it. All three totals agree with each other and with the tiles:
2026 group-wide, each one sums to 38 of 124.

**Neither new cut re-sorts itself.** Centres run in the order of the tab strip at the top of the
screen so the two name things in the same sequence; tiers run youngest to oldest. A cut that
ranked itself would be a second ranking arguing with the table above it.

**THE SIZE IS THE WHOLE POINT, AND IT WAS WRONG FOR A DAY.** These first shipped as three
`window.statStrip()` blocks in a card of their own **above** the ranking, and Broc's answer was
*"it looks like a complete mess"* - correctly. Seventeen figures at the same weight as the four
tiles, in three auto-fit grids of 7, 5 and 5 columns that lined up with nothing, took **445px of an
800px window**: the report itself was pushed entirely below the fold, so the screen opened on
**zero rows of "Where to advertise" where it used to open on five**. Answering "this is an
overview, not a data dump" by putting a bigger pile of figures in front of the data is not an
answer.

**A cut is a footnote to the ranking, not a second report in front of it,** so it now gets a
footnote's weight: one flex line per cut under the table, inside the same card, a fixed `7rem`
label gutter so the three read as one block (`By age group` is the widest and sets it), and the
whole thing is **~70px**. The entire 2026 group view - tiles, five rows and three cuts - now fits
one 1280x800 screen with no scroll.

**The denominator is `13/90`, not "of 90 free"**, which is the shape this screen already uses for
Free on every row and `.mkt-cap` already styles. **A zero is dropped back, not dashed**
(`.mkt-cut-z`): in a distribution the zeros are half the finding, and muting them is what makes the
one big number visible. Note this is the opposite call to `fig()` in the table, where a lone 0 in a
grid cell genuinely does read as data when it means "nothing to do here".

**Group only**, because `cutsBlock()` is built inside `groupTable()` - so the no-capacity and
not-open-yet states cannot reach it, and neither can the per-centre matrix, which already runs age
group down one edge and weekday across the other.

**Phone:** `.mkt-cut-k` goes full width under 768px. With the fixed gutter the first figure sat up
beside the label and every one after it wrapped to the left margin, so each cut read as one stray
figure and then a paragraph.

A **supervisor** has no group view at all: `centre_current()` refuses `centre=0` for them, the
"All centres" tab is not rendered, and `api_require_centre($centreId, true)` refuses
`?centre_id=0` at the endpoint - verified 18 Sep 2026 with the Tuggerah supervisor's session:
group = `Choose a centre.`, own centre = 200, another centre = `Unknown centre.`

## Ordering: most pressing first

Every key is a measured count, not a score nobody can check:

1. **uncovered place-days**, most first. The size of the hole the queue cannot fill is the question
   the screen was asked.
2. then **nobody waiting at all** ahead of a tier with a queue that simply cannot reach the places.
   Two tiers five place-days short are not the same job when one has eleven families to ring first.
3. then **most places free**, so a tier with no gap today and 34 places free is the one to watch.
4. then centre and tier name, so the order is stable between loads.

A reader who disagrees with the ranking can see all three numbers on the row. **A row with places
free and no gap stays on the list** - "34 free, 93 waiting, -" is the answer to "should we
advertise for babies at Tuggerah", and dropping it would leave the reader unable to tell a tier
with a queue from a tier the screen never looked at. A tier with **nothing free** is dropped: there
is no place to advertise, and the placement board and the Dashboard both already say so.

## Figures on the screen

| tile | what it is |
|---|---|
| Nobody waiting for | uncovered place-days in a week. Amber when there are any, green at zero |
| Age groups to advertise | rows with a gap, out of rows with places free |
| Places free | the board's own free figure, out of capacity, with the waiting families who could take one |
| Unsold place-days | the gap multiplied by the weekdays each centre has LEFT (565 in 2026 on 21 Sep) |

**`Places free` HAS THE DASHBOARD'S DENOMINATOR, and briefly did not.** It was summed off the rows,
which are only the tiers with something free - a full tier is dropped from the list and its places
still exist - so this read **"124 of 2,100" beside the Dashboard's "124 of 2,725"** for the same
group and the same year. One fact, two numbers, which is the disagreement this app keeps having to
hunt down. The capacity now comes from every counted centre, and the two screens were checked
against each other in both years (2026: 124 of 2,725 both; 2027: 1,004 of 3,110 both).

**`Unsold place-days` is counted per centre, from the day that centre opens.** A centre opening in
November has two months of the year to sell, not twelve, so multiplying the group's gap by the
group's remaining weekdays would be wrong the moment one centre opens mid-year -
`centre_year_start()` and `marketing_weekdays_left($year, $from)`.

**`Unsold place-days` is why no dollar figure is needed.** One uncovered Friday is not one
place-day, it is every remaining Friday of the year, and that is the honest scale of the gap - it
points at the same rooms a revenue figure would without importing another team's job. Computed from
`year_weekday_dates()` filtered from **today, Sydney, on the server**: a date decided in the
viewer's browser is the reinterpretation `_incl/dates.php` forbids, and this one decides the
biggest figure on the screen.

`Waiting` is a count of **families, not place-days** - one family wanting three days is one phone
call - and it is deliberately **not totalled** in the centre view's footer: one child is
age-eligible for two tiers wherever two rooms' bands overlap (Mighty Meteors Silver runs 36-72
months and Amazing Asteroids 36-60), so adding the column down would print a queue bigger than the
centre has. The tile counts each application once. The footer reads `--`.

## Rendering notes

- **The age band is in MONTHS.** Of the fourteen distinct bands the organisation runs, six have a
  bound that is not a whole year (1, 10, 18, 22, 30, 42), so a years-only label would have had to
  invent a figure. Months is also what the rest of the app speaks - sub-quotas, the waitlist age
  filter, `min_age_months`.
- **The room line is only drawn when it says something.** At five of the seven centres a tier IS
  one room with the tier's own name, and the line under "Mighty Meteors" read "Mighty Meteors".
  Where the names agree the heading becomes the `?room=` deep link instead; where they do not the
  line is real (Tuggerah's three Shining Stars, Warnervale's "Amazing Asterouds").
- **The day strip is `plain`,** the shared component's neutral state. These are not days a child
  attends and not days a family asked for, which are the two facts its other colours carry - and a
  colour that answers a question the row is not asking is worse than no colour (the Dashboard's
  "Places coming free" learned that one in green). A row with no gap gets **nothing**, not a second
  dash beside the one already in the gap column.
- **`ts-nocap`: the page scrolls, not the table.** Capped, `fitScrollers()` correctly handed the
  group table a full-window box with 438px of page scroll under it - four tiles and a banner sit
  above it - so the reader got two scrollbars a few pixels apart, and on the centre view the box
  landed **24px short of its own totals row**, which reads as a clipped row. One report, one
  scrollbar; same call the Dashboard's matrices make. Measured after: no horizontal scroll at 1280
  or at 390.
- **`window.statStrip()` moved into `app.js`** when this screen was built. It was private to
  `dashboard.js`, where four cards use it, and the weekday cut is the same shape on a second screen
  - the rule `channelIcon()` set: pull it up at the moment a second copy would be typed.
  `dashboard.js` aliases it rather than renaming its four call sites. **This screen no longer calls
  it** - the weekday cut that justified pulling it up became one line of `.mkt-cut` on 22 Sep 2026,
  because three of them at tile weight buried the report. The Dashboard's four cards still use it
  and it stays in `app.js`.

## Cost

**0.8s for the whole group warm** (2026, six trading centres), 2.0s for 2027. Cold - a Kidsoft
cache with nothing in it - it is ~20s, which is the same bill `cells_year()` pays and is why the
nightly job warms `board_load()` hourly and `bookings_year()` for this year and next. Nothing new
is warmed for this screen: it reads what the board and the queue have already cached.

## What it deliberately does not do

- **No "places coming free".** A place whose child leaves in November is NOT in `vacancies` -
  `holding` is the peak between today and December - so a room emptying late in the year does not
  appear here at all. `cells_year()` computes `coming_free` from `coverage_year()` and
  `bookings_year()`, which is a second full year read per centre, and the placement board already
  names those cells with their dates. **A lead time on the gap is the obvious next thing to want**;
  it belongs in the engine, off the coverage the board already derives, not as a second read here.
- **No offer, no work item, no contact log.** Every one of those is owned by an endpoint that
  already exists, and a second way to write one is two implementations of one record. The rows link
  into the placement board and the queue.
- **No email.** `graph_send_mail()` stays uncalled - see the architecture notes.
- **No casual anything.** Every read here is already casual-free: the board tests the booking flag,
  `day_requests_year()` tests the booking type.
