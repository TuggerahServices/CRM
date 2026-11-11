# Tenure: the movement the organisation is trying to eliminate

Governs `tenure.php`, `api/tenure.php`, `static/js/tenure.js`, `_incl/tenure.php`.

## The screen is called Tenure; what it counts is CHURN

The two are not synonyms and the distinction is load-bearing in the code: **tenure is the
outcome** (a child enrolled once, staying until they age out - median 36 months for a child who
ages out against 15 for one who does not); **churn is the movement that prevents it**, and churn
is what can be counted per room per year.

Identifiers are `tenure_*`; the word "churn" survives in prose and comments wherever it is the
accurate term. (Same rule as the In progress vocabulary change: the two may differ as long as
the reason is written down.)

**The ideal year:** a child enters at the BOTTOM of the ladder, progresses up one room a year,
and leaves once, at the TOP, for school. **Everything else is churn.**

## Casual days are NOT presence here (changed 13 Aug 2026)

`tenure_snapshot()` counted casual bookings on purpose, arguing *"a casual day still means the
child was in the building, and a child attending casually has not left."* **That answers a
different question from the one this screen asks.** Tenure measures whether a PLACE is still
held: a family who gave up their permanent days and now books the odd casual day has left as
far as every other screen is concerned, and counting them as retained **hid exactly the
departure the enrolments team needs to see.**

It is now excluded here as it is everywhere - see the hard rule in
[CLAUDE.md](../../CLAUDE.md). The turnover figures move slightly as a result, upward, which is
the honest direction.

## MEASURED AS GROSS FLOW, NEVER NET CHANGE

Tuggerah lost 27 children and gained 32 during 2025. Net that is "+5" and looks like a good
year; it is **59 movements against an average roll of 283**, and 59 is the number that describes
the work.

Group turnover 2025: **34.2%**, ranging from Niagara Park's 13.4% to Point Clare's 28.9%.
Mid-year churn alone is stable at **8.3% / 9.6% / 9.6%** across 2023/24/25.

### Turnover is NOT a fill percentage - do not put it through `board_pct()`

Asked after the placement board's rounding fix (10 Sep 2026): same problem here? **No, and the
difference is worth stating, because the two helpers look interchangeable and are not.**

`board_pct()` holds a figure below 100 because **100% means there are no places left** - a claim
it must not make while a place is free. **Turnover has no such ceiling.** 100% turnover means
the roll turned over once, which is a real thing that happens: Kanwal's opening year reads
**234.7%** and is telling the truth. Clamping it would be inventing a fault, and there is no
count printed beside it for it to contradict - the tile carries movements and roll, which are
its own numerator and denominator, not a remainder.

It also already carries its decimal: `round(100 * $gross / $avg, 1)` in `tenure.php`, for both
the centre totals and the group summary.

**What it DID share is the trailing zero.** `t.turnover + '%'` drops the `.0`, and measured over
20 live centre-years it lands on a whole number in **four** of them - Wamberal 2024 (25),
Tuggerah 2025 (23), Point Clare 2026 (26), Wamberal 2026 (14). The All centres table ranks them
in one right-aligned `tabular-nums` column, so 2026 read **53.2, 26, 22.6, 20.6, 19.8, 14** with
the decimal points out of line and two rows looking like the rounded figures the board had just
stopped printing. All three turnover renders now go through `window.pctText()`, which formats
only - it never touches the value.

**"Entered above the bottom room" stays a whole number** (`Math.round`, and `above_bottom` in
`tenure_group_summary()`). Its denominator is the year's new children - 32 to 356 - so one child
is 0.3% to 3%, and it sits between 26% and 81% in every centre-year measured. A decimal there
would be precision the figure does not have, the same call as the per-cell fill percentage in
[placement-board.md](placement-board.md).

## A CENTRE THAT HAS NOT OPENED HAS NO TENURE (21 Sep 2026)

`tenure_steady()` already refused to RANK a centre that is not trading; `/api/tenure` now refuses
to COMPUTE one whose year is entirely before it opens. Measured: Warnervale 2026 spent **7.1s** on
five quarterly Kidsoft readings to draw a full ladder under a turnover figure reading
`-- not trading`. It answers in 4ms and says the opening date instead.

**No "look at the year it opens" button here**, and that is deliberate rather than an oversight:
this screen's stepper stops at the current year because churn is measured from bookings that have
already happened, so the opening year is not a year it can answer either. See
[rooms-centres.md](rooms-centres.md).

## `ChildID` IS PER SERVICE - the load-bearing fact

Not one of 4,805 ids appears at more than one centre; **149 children hold a separate record at
two centres under two different ids.** Anything keyed on `ChildID` reports a centre TRANSFER as
a departure at one end and a new family at the other. An early version of this feature did
exactly that and measured cross-centre transfers as **zero**, which the team knew on sight was
wrong.

**`Children/getChildren.CRN` is the fix**, and it is on the PLAIN response - 98.3% of active
children (845 of 860). A Centrelink number belongs to the child, not the enrolment, so it
survives a centre move. Name+DOB is the documented fallback for the other ~2%: checked against
CRN group-wide it gave 149 cross-centre matches to CRN's 145 with **ZERO conflicts in either
direction**. Nine pairs rest on the fallback alone.

**The CRN is a join key ONLY** - never stored in our tables, never rendered, never
audit-logged. It is a government identifier for a child; **the moment it is in a column it is in
a backup.**

## The ladder table (rewritten 17 Aug 2026)

It was hard to read, and two of the reasons were not styling at all - they were the table saying
something untrue.

### "Over the summer" is not what anybody here calls it

The group covering Nov -> Feb is **"Between the years"** now. The season is right for the southern
hemisphere and the phrase still reads as the northern mid-year break, which is the one thing it is
not. The bracketed span (`2024 Nov to 2025 Feb`) beside it does the precise work.

### "Left" over the changeover was TWO events in one cell

`b_out_aged + b_out_left` was rendered as a single figure, coloured by whichever half was bigger.
Tuggerah's Amazing Asteroids Silver showed a quiet grey **25** that was 23 school leavers hiding
**2 families who left** - the exact departure this screen exists to surface, made invisible by
the intended way off the top of the ladder. **They are two columns now, `To school` and `Left`.**
Mighty Meteors Pink is the same shape: 29 to school, 0 lost.

### Arriving at the BOTTOM of the ladder is the ideal, not the problem

`tenure.js`'s own header had always said so - "babies entering at the bottom" listed alongside
progression as expected movement - and the code did the opposite. Tuggerah's **15 babies starting
in the bottom room in February came out `tn-vhigh`, the loudest cell on the screen**, while the
tile beside it counted the same 15 as children who correctly "started at the beginning".
**The table and the tile were arguing about the same number.**

`intake()` keys on the room INDEX, and index 0 is the bottom room - the same one
`tenure_year()` measures `in_new_bottom` against, so the two cannot drift apart again. A place
filled from outside anywhere ABOVE the bottom room is backfilling something churn opened, and that
is what carries colour.

### Where one group ends and the next begins

Two columns are called `Left` and two are called `Joined`, and that is correct - leaving over the
changeover and leaving in June are different events. What was missing was any mark saying which
was which, so the centred group caption floated over nothing.

- **`.tn-sep`** carries the group boundary down the whole body; **`.tn-mid-rule`** is the lighter
  split between arrivals and departures inside each group, so the reading order is in, then out,
  twice.
- **Borders, not band backgrounds.** Every one of these cells can also be carrying a heat fill,
  and a tint underneath would have to lose to it.
- **Every cell in the column carries its rule, zero or not** - that is why `num()` takes the rule
  as a third argument rather than folding it into the heat class. A rule that stops at the first
  empty cell is worse than no rule.

### Two sticky header rows need two offsets

`.table-sticky thead th` pins every header cell at `top: 0`, which is right for the one-row headers
everywhere else and **stacked this one's sub-headers straight on top of its group names the moment
the ladder scrolled - and the ladder always scrolls, a centre has ten rooms.** The group row is
given a fixed `1.9rem` so the second row can be offset by exactly that; the two `rowspan` cells stay
at `top: 0` and cover both. Bootstrap's `thead{vertical-align:bottom}` also had to be overridden for
them, or `Room` and `On the roll` sit on the sub-header's baseline and read as part of the second
group.

### A totals row, pinned

`.grid-totals`, the same idiom the placement board uses for free places. Ten columns of small
numbers with two of them called `Left` is a table you re-read the header for on every row. **No
heat on it:** a centre total is in the top band by construction, so colour there would say only
that a centre is bigger than a room.

### The key is for OUR ENCODING, which is the exception to withholding

Which cells are quiet and which are loud is the entire argument the table makes, and it is the one
thing here nobody can work out unaided - so the card header carries `Expected` and `A place lost
or backfilled`. **This is not a caption on the team's business**; see the rule in
[CLAUDE.md](../../CLAUDE.md). `Expected` has no fill in the table, so its swatch is an empty cell
with a rule round it rather than a colour that appears nowhere; the other is the four-step heat
scale drawn as the ramp it is, because one block would claim one step is the meaning.

### And one latent instance of the documented specificity trap

`.tn-zero` is (0,1,0) and is set **directly on a `<td>`** on this table (it is a `<span>` inside one
on the overview), so it lost to Bootstrap's `.table>:not(caption)>*>*` and the quiet zero rendered
as body ink. `td.tn-zero` now. Same trap as the heat steps, third instance recorded.

## `ExitReason` decides exactly ONE binary

Did this child go to school. Populated on 100% of 102 real Tuggerah leavers. Against an
age-at-31-July test it never mislabels a young child as a school leaver and disagrees on 3 of
102.

**Beyond that binary it is noise.** `Change of Care Conditions` is 1,485 rows group-wide, is what
people pick when they pick nothing, and is what **all ten known centre transfers are filed
under** - so it cannot detect a transfer, and CRN matching is the only thing that can.
**Do not build a reason breakdown; it would look analytical and say nothing.**

**It IS shown verbatim, one child at a time, on the "Started and left between readings" card**
(29 Oct 2026) - the same thing the Dashboard's "Ending care" does. Still no breakdown; see below.

## Four quarterly readings, not twelve months

Feb / May / Aug / Nov. These land on the **SAME cache keys `bookings_year()` uses**, so the
nightly warm covers the current year free.

**February rather than January**: January is half shutdown and counts holidaying families as
absent.

**The months in between are read too, for one thing only** - the children below. The nightly job
warms November of the year before last through December of last year (fourteen month-reads per
centre, 132s cold for the group); the current year is already warm through `bookings_year()`.

## CHILDREN WHO STARTED AND LEFT BETWEEN TWO READINGS (29 Oct 2026)

A reading is a whole month of bookings, so the only child the readings miss is one whose every
booking falls in the months BETWEEN two of them: Mar-Apr, Jun-Jul, Sep-Oct, or Dec-Jan over the
changeover. **Until 29 Oct 2026 they were counted nowhere.** Broc asked for them in, and
especially for the WHY.

**They are an arrival and a departure like any other** - posted onto the room's `Joined` and `Left`
(or the `b_` columns for Dec-Jan) by `tenure_apply_short_stays()`, classified by the same
`tenure_post_departure()` as every other leaver (moved centre / to school / left). So turnover, the
tiles and the group ranking take them in with no special case, and there is **no extra ladder
column** (the table's width margin is spoken for; see the card shell section).

**Only a CLOSED window counts**: the reading after it must have happened. Sep-Oct of a year still
running waits for November, when everyone in it has either turned up or gone. Measured the day
this was built, the open 2026 Sep-Oct window held 69 children, 52 of them Erina's opening intake,
and almost all of them are simply new.

**A window is judged on its own, so a child can be a short stay after an earlier departure.** Jia
Park left Kanwal in April (counted then, at the May reading), re-applied, came back in June and
left again in July "Left Area". That is three movements and the screen counts three.

### Measured (HOW: every month of 2025 and 2026 read for all eight centres, each child compared against the readings either side)

- **10 children left the group**: 2 in 2025 and 8 in 2026 to the August reading, plus one who
  spent ten days at Kanwal in 2025 and went back to Niagara Park.
- **7 of the 10 started in December-January** - a place from the new year, gone within 1 to 24
  days.
- **5 of the 10 were not given every day they asked for**, and 2 more were given days they had not
  asked for. Addison Mellowship asked for five days, was given Monday, came once and left "Change
  of mind". Robin Ireland asked for five, was given three, left after three weeks. Toby Semmens
  asked for Thu/Fri, was given Wed/Thu, left after a week.
- **Several had waited a very long time first**: 20 and 21 months for two Tuggerah families.

So the effect on turnover is small - Tuggerah 2026 19.8% -> 20.6%, Niagara Park 20.6% -> 21.9%,
Kanwal 53.2% -> 55.1% (Dec-Jan stays sit in the changeover columns, which turnover does not
count). **The value is the list, not the rate.**

### The card is the why

A centre's view shows "Started and left between readings" under the ladder, hidden when empty.
Per child: room, **a day strip of what they asked for against what they were given** (green
asked-and-given, the waitlist's blue for asked-and-not-given, the ring for given-not-asked),
the dates, how many of their booked days they came for (`Absence` on the booking row), how long
they had waited from applying, and Kidsoft's `ExitReason` - or "Moved to <centre>" when they went to
another of ours.

**The name opens the child's dialog** (6 Nov 2026) - their parents, and any brother or sister still
here or waiting. So `tenure_short_stays_why()` now publishes the ChildID as `ks_id`; it used to drop
it before the response because nothing in the browser had a use for it.

- **What they asked for is on the application that became the enrolment**, matched on `ChildID`
  only, which Kidsoft writes onto it at conversion - no name matching. The read is the archived
  history the search palette already caches and warms; `search_history_params()` now passes
  `WithRequestedDays` so this needs no second copy (see
  [search-identity.md](search-identity.md)). With no application, the strip names the days booked
  and claims nothing else.
- **The ChildID is dropped before the payload leaves the server**, and the person key never enters
  it - it can be a CRN.
- **`ExitReason` is shown VERBATIM per child, still not broken down.** Across these children it is
  more varied than across the group (Change of mind, Left Area, Cancelled care, Other), but ten
  rows in two years is not a distribution.
- **There is no free-text exit note to show.** Re-measured 22 Oct 2026 (see
  [dashboard.md](dashboard.md)); the application's `Notes` and `StatusReason` on these rows hold
  only Kidsoft's own reminder history.
- **The team's own contact log held nothing on any of them** (29 Oct 2026, `communications` by
  `kidsoft_child_id`), so it is not on the card. If the team starts recording why families leave,
  this is the screen to show it on.

## THE YEAR BOUNDARY IS WHERE THE LADDER ACTUALLY MOVES

Measured at Tuggerah 2025, mid-year progression and mid-year school leaving are **BOTH ZERO** --
children change room in January and leave in December. **A within-year view alone shows churn
and no ladder at all.**

`tenure_apply_transition()` runs the identical comparison over (Nov of last year -> Feb of this
one). **One code path, because two would drift.**

## A centre that is not steady breaks group aggregates

Three now do. Warnervale is not trading; **Kanwal opened mid-2025** and ramped 55 -> 82 -> 97 ->
108 -> 111, computing to **234.7% turnover** - a centre being born, not a centre in trouble.

`tenure_steady()` is an **explicit test** and unsteady centres sort to the BOTTOM of the ranking.
Nine of ten Tuggerah "departures" in 2026 were families following Kanwal's opening.

**Growth is not churn and no arithmetic separates them**, so `net_change` / `growing` are
reported **alongside** turnover rather than subtracted from it. Kanwal 2026 passes the steady
test and still reads 53.2%, most of which is the centre getting bigger.

**The steady-state test is derived from booking counts every run and is never keyed to a centre
name**, so a centre that starts trading is picked up with no code change.

## The one ratio that states the gap from the ideal

Of 336 mid-year arrivals over three years, **only 30 were babies** - **91% entered ABOVE the
bottom room**, backfilling a place churn had opened rather than starting at the beginning.

## The All centres view

A ranked list with grouped bars. Two choices were corrected by looking at the rendered page:

- **The bars are a RATE, not a headcount.** The list ranks by turnover, so absolute children
  contradicted the ranking beside them - Tuggerah (283 on the roll) had by far the longest bars
  while ranking third, and Point Clare, actually the group's worst centre, looked mild. **A rate
  is used for the ranking precisely because centres are different sizes, so the mark next to it
  must be the same rate.** Counts stay in their own columns, where nothing is compared across
  unequal denominators.
- **GROUPED bars, never diverging.** Diverging encodes polarity, and **neither direction here is
  the good one** - the objective is arrivals and departures both at zero. Two categories on a
  shared baseline; total ink reads as gross movement.
- **A non-comparable centre gets NO bar**, not a bar on a scale it does not belong to, and
  `bar_max` is computed over steady centres only. Kanwal's opening year would otherwise run three
  times the width of every real centre.

### IT IS THE SAME CARD AS THE DASHBOARD'S RANKED CENTRE TABLES (21 Sep 2026)

Asked for by Broc: make this view's theming match the Dashboard and Placement All centres pages.
It is the same SHAPE as the Dashboard's *Supply against demand* and *Round progress* - one row per
centre, a mark, then figures - and it was the only one of the three that looked like a different
application. Everything below is a swap to what those two already do; **nothing about what is
measured, ranked or withheld moved.**

| was | is | why |
|---|---|---|
| `.card-header`, a grey bar with an icon | `h2.h6.text-uppercase.text-muted` in the `.card-body`, legend on its line | the only `.card-header`s in the app were this screen's two - see [ui-conventions.md](ui-conventions.md) |
| `table table-sm`, full-size body-ink headings | `table align-middle .dgrid` | `.dgrid thead th` is the small muted uppercase every other ranked centre table uses |
| `.tn-plot` 34%/11rem | `.dgrid-stack` | the Dashboard's own mark column |
| `.tn-fig` | `.dgrid-fig` | identical rule, one name |
| a bare `<a>` + private `#overview th a` hover-underline rules | `.ov-centre-link` on a `fw-semibold` `<th>` | one treatment for a centre name in a table, app-wide: no underline, colour on hover |
| `.tn-muted-row` at `opacity:.62` | `.text-muted` on the `<tr>` | the opacity faded the centre's own LINK too, so an unrankable centre read as switched off rather than as one whose figures are missing |
| `.tn-bar` / `.tn-bar-row` / `.tn-legend` / `.tn-key` / `.tn-sw` | `.mk-*` | the third copy of the mark and legend, folded in |
| "Movement, as a share of the roll" as the column heading | "Movement", the sentence in a `title=` | at `.75rem` uppercase with letter-spacing the words ran together; the terse-heading-plus-title is what `Per place` and `Avg wait` already do |

**The COLOURS did not change and must not**: the purple/pink pair is this screen's by right (see
below), and the Dashboard's single-series bars are already `--lm-mark`, so "Left" here is the same
purple as a bar there.

**The ladder card on a CENTRE took the same shell straight afterwards**, asked for in the same
conversation. It keeps its own key (Expected / A place lost or backfilled, and the list of
readings) on the heading line, and its `<table>` is the one thing that was measured rather than
assumed: a `.card-body` costs a ten-column table 2rem, and at 1280 the ladder's `<thead>` goes from
1030px full bleed to **998px** against a ~870px minimum, so nothing compresses and no horizontal
scroller appears. **A column added to this table puts that back in question** - see the note in
[ui-conventions.md](ui-conventions.md).

### WITHHOLD THE FIGURE, DO NOT CAPTION IT

A centre that is new, closed or still filling shows `--`, no bar, sorted to the bottom, with the
reason in a `title=` only. **It does NOT get a sentence on screen explaining its own status.**
The users are one small team who know their own centres; explaining their business back to them
is noise, and a caption like "Warnervale: not trading" ages into something wrong the moment it
opens.

**A visible banner is reserved for what they cannot know unaided** - Kidsoft refusing to answer
for a centre, which changes whether the numbers can be trusted at all.

### A year still running is LABELLED, not bannered

The readings are quarterly, so the current year carries only the ones that have happened --
Niagara Park read **19.3% from three readings (Feb-Aug)** against 2025's **13.4% from four
(Feb-Nov)**. Side by side and unlabelled that reads as a centre falling apart, when the two
figures do not cover the same span.

`tenure_year()` returns `partial` and `span`, and the tile becomes "Turnover so far ... (Feb to
Aug)". **No banner: the team knows what month it is.** This matters more since the year default
became the current calendar year, because it is now what Tenure opens on.

## Colour

**The chart purple is `#4650b3`, deliberately one step off brand `#2e3192`, and must not be
"corrected" back.** Brand purple sits at OKLCH L 0.378, below the 0.43-0.77 band a chart mark
needs, so it reads as a dark blot rather than a hue. The lifted pair (`#4650b3` + brand pink
`#ed387d`) passes every computable check: CVD separation dE **13.3** against a target of 8,
normal-vision dE **30.9** against a floor of 15, both above 3:1 on white.

**It applies to chart marks only** - nothing else on the site changes.

**Tenure is now the only legitimate user of the purple/pink pair.** That pair is for categories
of **EQUAL STANDING** - arrivals against departures, where neither is part of the other. A
highlighted SUBSET inside a total is a part-to-whole and takes the one-hue lightness ramp
instead. See [ui-conventions.md](ui-conventions.md).

Tenure's heat scale shares the app's tokens for every band it has in common. Its good band was
the `-wash` step while its other three were `-mid` steps, so its green read as nearly white
beside a saturated amber; **it is `--lm-good-mid` now.** `movementClass()`'s five steps stay on
tenure's **table** cells, where they are a real heat scale.

**`tenure.js` HELD a third copy of the mark bar and no longer does (21 Sep 2026).** Being a
different form (two series, one row each) had been the reason for keeping it, and it was not one:
the form is two stacked `.mk-track`s in a `.tn-plot-cell`, and all a private copy bought was its
own height, radius, gap and min-width. `.tn-legend` / `.tn-key` / `.tn-sw` went too, byte-identical
to the shared legend as recorded here, and the ladder's key moved with them.

**What stayed local is the FILL, and that is the rule, not an exception** - a swatch's colour
belongs to the scale it stands for, so `.mk-sw.tn-out` / `.mk-bar.tn-out` (and `-in`) are the only
tenure lines left in that block. **Doubled**, because `.mk-bar` carries the default `--lm-mark`
fill at the same specificity and leaving the winner to source order is the documented trap.

## Measured and deliberately NOT built

- **No dollar figures.** Revenue is out of this project's scope entirely - see the app-wide
  prohibition in the root file. This screen was right about it before the placement board was.
  (A gross exposure for 2025 was computed and discarded, on its own merits too: it assumes zero
  backfill, and backfill demonstrably happens.)
- **No room x month heat grid.** Departures are evenly spread - group 2025 ran 4.1% by May,
  3.0% by August, 2.5% by November. **It would be flat noise.**
- **No churn-by-room-age-band.** Banding rooms by `min_age_months` produced a **false 17.5%
  spike** at 12-24 months; a "0-12m" room actually spans 0-24, and the bucket held 57 children.
  Cut by the CHILD's own age it is a gentle rise - under 1: **5.9%**, 1-2: 7.4%, 2-3: 9.7%,
  3-4: 8.8%, 4-5: **11.7%**. **Babies are the stickiest, which is the opposite of the room-band
  artifact.**

## Two findings worth acting on, not yet wired to anything

- **Days attended predicts churn.** 1 day/wk **30.9%**, 2 day 10.9%, 3 day 6.1%, 4 day **4.8%**,
  5 day 10.7%. It survives a settling-in control (established 1-day children still churn at
  26.3%), though that cell is only 19 children - **pool more years before putting it on screen
  as a rule.**
- **Churn is front-loaded.** A child who ages out stays a median 36 months; one who churns stays
  15, and **27.7% of churn departures happen in the first six months.**
