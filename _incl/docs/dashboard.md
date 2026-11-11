# The Dashboard and the re-enrolment round

Governs `dashboard.php`, `api/dashboard.php`, `static/js/dashboard.js`, `_incl/dashboard.php`,
`_incl/reenrolment.php`, `_incl/week-ahead.php`.

**First in the sidebar and the landing screen**, because it is the only one that crosses the
window and the work.

## Hard rules

### NO CONVERSION RATES, and this is not squeamishness

Measured over all 1,693 Kidsoft waitlist rows: the outcome statuses (`Enrolled`, `Offered`,
`ExpiryReminderFirst`/`Second`, `DeletedByParent`) **ALL first appear in 2026Q3** - a Kidsoft
release, since six of seven centres began emitting them within the same fortnight - and **89
of the 949 rows filed as `ExpiredWaitlist` had in fact become enrolled children** (matched on
folded name + DOB against all 4,626 child records).

**A funnel with rates on it would report Kidsoft admin hygiene as this organisation's
performance, and that is a number that ends up in a board pack.** The pipeline is a SNAPSHOT of
where families stand today. Rates become real from the point `work_items` starts being used;
**start the clock, do not backfill a fiction.**

### The Filled % comes from `board_pct()`, never from a plain `round()`

Both `pct` figures here (`dash_centre()` and the group total) go through it, so "Filled (2026)"
cannot read 100% while "Places to fill" reads 1 - which is what Niagara Park did until 10 Sep
2026. It carries **one decimal place** and reaches the page through `window.pctText()`, which is
also what the hero meter, the per-centre meters and the re-enrolment round's "answered" figure
use. **Do not concatenate a raw `pct`**: JS drops a trailing `.0`, so 94.0 would print as "94%"
in a column of 93.9s. See [placement-board.md](placement-board.md).

### THERE IS NO YEAR PICKER, AND THAT IS THE POINT

One was built and removed the same day, because "the year" does not mean one thing here. The
four things it crosses are scoped differently:

- **Rooms** = this year (a free place now is a lost sale now)
- **The re-enrolment round** = next year by definition
- **The queue** = not year-scoped at all (one queue of 722, and `for_year` is a Kidsoft-seeded
  judgement never updated, so filtering the headline by it would hide most of the queue and
  call it accuracy)
- **Work in flight** = every year, because a family being chased today for a January start is
  work today

A single stepper moved the first and the last and silently left the middle two alone - **worse
than no control.** Each figure names its own year in its own label instead. To study a specific
year's board, that is the Placement screen's job.

**It does NOT call `year_current()`**, which writes to the session and would silently move the
year the Placement board is planning. It still **passes** the remembered year to
`layout_header()` so the sidebar and centre tabs keep it.

### FIVE CENTRE STATES, AND THREE OF THEM USED TO BE ONE

**`centre_year_state($board)` is the test now** (21 Sep 2026, `_incl/board-live.php`).
`dash_trading()` still answers its own question - are there children in it - and is no longer
enough on its own, because "has not opened yet", "open and completely empty" and "trading" all
have to be told apart. See [rooms-centres.md](rooms-centres.md).

| state | treatment |
|---|---|
| `trading` | counted |
| `opening` | **counted**, from the day it opens - see below |
| `unopened` | excluded, and NAMED with its date rather than marked |
| `empty` | excluded, and it is an **alarm**: a centre that should be running with nobody in it |
| `unreachable` | excluded, reported as **a gap in the data, never as a fact about the business** |

**A CENTRE THAT HAS NOT OPENED SHOWS ITS QUEUE AND NOTHING ELSE (21 Sep 2026).** `dash_centre()`
tests `centre_not_open_yet()` **before deriving a board** and hands back `dash_centre_unopened()` -
same keys, null room figures, and the queue and work read exactly as they are for any other
centre. 0.02s against a board derive, and more importantly the two room TILES stop printing
*"Places to fill 0"* and *"Filled -- 0 of 0 place-days"*: they read `--` and name the opening date.

The four room-shaped cards - Places to fill, the year ahead, What's changing this fortnight, and the
re-enrolment round - collapse into one `notOpenState()` card. **Tours, the work list and the
pipeline still render**, because a centre opening in January is being worked NOW. `dash_reenrolment()`
and `week_ahead()` skip the centre entirely rather than reading Kidsoft to discover it is empty.

**`opening` COUNTS, and it moved a figure on this screen** (Broc's call, 21 Sep 2026). Warnervale
carries 385 place-days a week, 0 children and 17 waiting families for 2027, and `trading === false`
excluded it from the totals, from Places to fill and from the Marketing ranking - the centre with
the most to sell, missing from the screens that say where to sell. **Group fill for 2027 reads
64.0% with it counted against 73.0% without.** Both are true; the first describes what the
organisation actually has to sell.

`c.counts` is published per row so the JS never re-derives the rule: `comparable()` and the hero
are measured over exactly the centres the engine totalled, and cannot drift from them.

Conflating the last two was a real bug caught by a live Kidsoft maintenance window at 22:40 on
the build night: Kanwal, with 52 children booked for 2027, briefly rendered as "not trading".
**Silently downgrading an outage to a business fact is how a screen tells a confident lie.**

The test is **derived from the data every run and never keyed to a centre name**, so a centre
that starts trading is picked up with no code change.

### A banner is only for what the team cannot know unaided

A closed centre gets `--` and nothing else. Kidsoft refusing to answer gets a banner, because it
changes whether the numbers can be trusted. **A centre that has not opened yet gets `--` and its
DATE in the row's title** - "opens 4/01/2027" where it used to read "no places recorded for 2026",
which is true, sounds like a data fault, and is the one thing on that row the team cannot work out
from the dashes.

**A MARKER IS A CAPTION TOO.** The row once carried a `fa-circle-minus` captioned "Not trading
in 2026". That is the same patronising sentence, only smaller: **Broc runs these centres and
does not need the app to point out that one of them is shut.** The icon and that `title=` are
gone; a row of `--` says it without commentary. **The unreachable case keeps its `title=`** --
that one is about the DATA, not the business.

## Supply against demand

**Every per-centre list on the group view is in the centre bar's order** (Broc, 27 Oct 2026):
this table (a centre not open this year last), both Places to fill matrices (centres as
columns), Round progress and Accepted, not yet booked. They were each ranked a different way -
most free, emptiest, most to chase, most unbooked - so a centre sat in a different place in every
card. The "Biggest gaps" caption under the hero is still a top-two, because that is its job.

Ranking by free places alone points at whichever centre is emptiest even with nobody waiting. Measured 2026: Kanwal 40 free / 59 waiting, Tuggerah 59 / 117, against
**Terrigal 1 / 124 and Wamberal 7 / 222** - the last two are a **capacity** problem, not a
filling problem, **and the two rankings give opposite instructions.** Group-wide: 115 free
place-days against 720 families, **6.3 waiting per free place**.

**FAMILIES, NOT APPLICATIONS.** The queue headline is `dash_queue_group()` ->
`waitlist_group_all()`: 723 open applications are **626 children, 74 waiting at more than one
centre**, so summing seven centres' queues overstated the families being held by **15%**. Only
the group view can see this (a centre's own queue holds no duplicates). 0.04s, pure DB - **a
Kidsoft outage cannot reach it.**

Cost: **0.47s** for the whole group cold-ish, 0.08s for one centre.

## The two views are different SHAPES

Pretending otherwise cost a card and a table (9 Aug 2026):

- **"Enrolments needing attention" is not rendered at all on All centres.** A work item belongs
  to a room and a room belongs to one centre, so there is no group-wide version - and the card
  that used to appear there contained nothing but a sentence explaining why it was empty.
  `dashboard.php` now **leaves the container out entirely (server-side)** and Next year takes
  the full width, which is also what its six-column table wanted.
- **Supply against demand is a stat strip on one centre, a table on the group.** A table is a
  comparison device; with one row it is a header of overhead, and four of its six figures were
  already in the tiles at the top. The single-centre version says what the tiles do not -
  waiting per free place, and the average wait.
- **THERE IS NO "LAPSING" FIGURE ANY MORE (11 Nov 2026).** Open applications within 30 days of
  Kidsoft's ExpiryDate (`DASH_EXPIRY_SOON_DAYS`) used to be a tile on one centre, a column on the
  group table, a warning banner on a centre and a "Needs attention" row on the group. Broc turned
  Kidsoft's "Waitlist expire" option off that day and asked for it removed: a waiting place no
  longer lapses, so all four were counting down to nothing (52 applications group-wide). The
  query no longer computes it and the payload no longer carries `expiring` or `expiry_days`. The
  Waitlist's "Expiring soon" tile and the search palette's "Expires in Nd" tag went the same
  day - see [waitlist.md](waitlist.md).
- **EVERY CHILD'S NAME HERE OPENS SOMETHING (6 Nov 2026).** In "Places coming free" and "What's
  changing this fortnight" it opens the child's dialog (`window.childName()`, by the ChildID
  `reenrolment_declines()` matched or `week_ahead()` read - an ambiguous or unmatched decline has
  none and stays text). The fortnight's room column still points at the cell. On a work item or a tour it opens THAT
  ITEM on In progress (`/progress?item=`), because the row is about a family part-way through
  enrolling and this screen does not load the work modal.
- **"Places coming free" is a table, not a run of text.** It is the only forward-looking vacancy
  signal in the API, so it is the one list that has to be scannable, and it rendered as
  bold-name / grey-centre / room / "Wed Thu Fri" on one line - thirteen sellable places as a
  wall of prose. Now four aligned columns and a **Mon-Fri strip** (`window.dayStrip()`, five boxes
  in fixed positions), so which weekday comes free reads down the column without reading a word.
  **The days are NEUTRAL GREY, and that is a decision** (Broc, 4 Sep 2026). They were briefly
  green, which is the strip's colour for a day a child holds and was literally true - these are
  exactly the days that child holds. On a card headed "Places coming free" it read as *a place is
  held*, the opposite of what the card is for. This strip answers WHICH WEEKDAY and nothing else,
  so it takes `plain`, the state that means only that: the same fill-under-a-solid-edge treatment
  as the green one with the hue taken out, so a marked day still reads as marked. **The work-item
  card below it draws the same strip in BLUE**, because those days genuinely are asked for and not
  yet held - it was running text under a column headed Days while In progress drew boxes for the
  very same rows.
  **The centre column disappears on a single centre**: a column of one repeated value is noise.

`statStrip()` is shared by the supply card and the re-enrolment figures, and **`.stat-strip` is
a grid, not a wrapping flex row** - as flex the fourth figure dropped onto a line of its own in
the narrow half of the row and read as a mistake.

## The All centres dashboard is the MORNING OVERVIEW

Rebuilt 10 Aug 2026. It is the first screen the team sees of a day, and it was four stacked
tables of the same weight: four equal tiles, then three tables, then half a page of white beside
a clipped list. **Every figure on it was right and none of them was ranked.** The centre view is
a WORKING screen and is deliberately untouched.

The group view renders in one fixed order, **and the order is the argument**: one figure -> WHERE
those places are, this year and next -> what it obliges somebody to do -> where those places sit
against the demand -> next year forming -> the two forward-looking lists -> the work layer.

**Places to fill moved above Needs attention on 16 Sep 2026, at the team's request, and the move
has an argument of its own.** The hero and that report are **one number at two resolutions**, built
off the same cells in the same boards; everything that sat between them was making the reader carry
117 down the page to find out where it was. Needs attention is a list of DEADLINES and reads the
same wherever it sits. The report does not - it only means anything beside the figure it
decomposes.

- **ONE HERO FIGURE, not four equal tiles** (`renderHero`). Places to fill, at >=48px, in the
  same sans as everything else, with **proportional figures** - `tabular-nums` makes a number
  like 111 look loose at display size. Four tiles said "these matter the same amount", which on
  a morning overview they do not.
- **"Needs attention" replaced the stack of alert banners** (`renderAttention`), **ordered by how
  hard the deadline is, never by size**: a date somebody already missed, then Kidsoft's 30-day
  lapse clock, then the round, then the two opportunities. Ranking by count would put the 253
  unbooked acceptances top, and whether that is normal batching or a backlog is the team's
  judgement. Two of these were previously buried as table columns while two shouted as banners;
  they are now comparable. `renderNotices()` still banners exactly one thing: **a centre Kidsoft
  would not answer for.**
- **Two measures of different scale never share an axis.** Free place-days (0-56) and families
  waiting (9-222) get a column each, each scaled to its own maximum. `bar_max` for the supply
  mark is computed over **trading centres only**; the queue scales over all centres, because a
  queue is real whether or not the rooms are open.
- **`.table-scroll.ts-nocap` - the dashboard scrolls as a PAGE.** `.table-scroll` caps height
  at `calc(100vh - 21rem)` so the board grid can scroll under a sticky header. Every table here
  is seven rows or a dozen children, and in a half-width card that cap turned the declines list -
  **the ONE list on this screen that has to be scannable** - into a 464px box showing **8 of
  13 children** behind a scroll gesture nobody makes on a dashboard. The wrapper is kept for
  horizontal overflow; only the vertical cap is dropped. `.table-sticky` came off the supply
  table in the same breath: a sticky header cannot work in a container that never scrolls.
- **The two narrow lists are 5 and 7, not 6 and 6** - the declines list carries a name, a
  centre, a room like "Mighty Meteors Silver" and a weekday strip, and at half the page every
  row wrapped onto two lines.

### The round progress bar

**BUILT ON `answered`, NOT `accepted` + `declined`, and this must not be "simplified".** Those
two count Kidsoft NOTIFICATIONS over the year; `answered` counts children on the roll matched to
an answer since the round opened, and **only it satisfies
`roll = answered + cannot_stay + to_chase + school_age` exactly, by construction.** Verified
exact at all seven centres, while accepted+declined disagrees by 1 at Tuggerah and 4 at Kanwal.
**A part-to-whole mark whose segments do not sum to its own denominator is the one thing that
form may never be.**

The denominator is the roll **LESS the children ageing out**, because a child with no room to
move up into was never in the round - counting them as "yet to answer" would invent 138
families nobody is waiting on. **The footnote states the provenance**, because the two figures
sit in one card and a reader will otherwise spot the difference and distrust both.

**The bar takes the one-hue lightness ramp, not the purple/pink pair** - see
[ui-conventions.md](ui-conventions.md) for why, and for the rule about picking a palette from
the mark's JOB.

## Places to fill: the weekly report to management (`dash_fill_report()`)

Asked for 15 Sep 2026: *"a report I need to supply weekly to management that included the
available positions broken up by age groups and centres, and then a combined total. e.g. 4 3-4 in
TUG need filling, and 6 Mondays across centres, so we know where to keep the marketing/focus."*

**IT IS HALF THE MARKETING QUESTION, AND THE OTHER HALF IS NOW ITS OWN SCREEN (18 Sep 2026).**
This card says WHERE the free place-days are and says nothing about whether anybody is waiting for
them, which is the difference between a place to offer and a place to advertise. Broc asked for the
second half on 18 Sep and it is `/marketing` - same tiers, same weekday cut, and the queue matched
against every cell. **Measured the day it was built, the two halves point at different rooms:**
Tuggerah carries the most free places in 2027 and almost no gap (its queue can reach them), while
Kanwal's Amazing Asteroids carries 13 uncovered place-days out of 48 free. **Neither card is the
other one filtered** - if a figure here changes, check [marketing.md](marketing.md), because both
are built off `board_load()`'s `vacancies` and must keep agreeing about what "free" means.

**TWO CUTS OF ONE NUMBER, because it is asked two questions.** The matrix says which room at
which centre; the weekday strip says which DAY, and that one runs ACROSS the matrix so no cell of
it can carry it. Measured 15 Sep 2026 over 115 free place-days group-wide: **Mon 32 and Fri 35
are 58% of the entire gap.** No room-by-centre table can show that, and it is exactly the kind of
thing a marketing spend should know.

### THE AGE GROUP IS THE ROOM NAME, AND THAT WAS MEASURED (`room_type_key()` in `rooms.php`)

**Kidsoft has no age-group field at all.** `Service/getRooms` was probed field by field on 15 Sep
2026 and returns `MinAge`/`MaxAge`, `ApprovalType`, `KindergartenRoom`, `EducatorChildRatio`,
`MinStaff` and nothing resembling a tier.

**And the licensed bands do not bucket cleanly.** There are **thirteen distinct (min,max) pairs**
across the 40 rooms, and four rooms straddle whichever boundary you pick: Kanwal's Shining Stars
is licensed **24-60m** while every other Shining Stars stops at 36, and Tuggerah's Mighty Meteors
Silver runs **36-72m**. Bucketing by band files Kanwal's ten free 2-3 places under 3-5 year olds.

So the tier is **the first word of the room name**, which was checked across all 40 rooms before
anything was built on it: the five families have distinct first words, so the rule collapses
Tuggerah's Pink/Silver/Purple variants **and absorbs Warnervale's "Amazing Asterouds"**, a typo in
Kidsoft's own room name that any whole-name rule would have filed as a sixth family. `room_types()`
derives the list, the labels and the order **from the data every run** - order is the median
`min_age_months` (Little 0, Radiant 12, Shining 24, Amazing 36, Mighty 48) - so a centre that
opens a room in a new family is picked up with no code change. A room matching nothing becomes its
own group rather than being folded into another tier.

Three cuts were put to the team with the real rows; **room type was chosen** (15 Sep 2026).

### THE `Filled` COLUMN: how full, not just how empty

Asked for 15 Sep 2026 ("can we please have the age group % added"). **A count says how much is
left to sell; only the percentage says whether that is a lot.**

It is `board_pct()`, the same function every other fill figure on the app goes through, so the
report's footer and the hero at the top of the screen cannot disagree - verified: both read
**95.8%**, and each centre's figure matches its row in Supply against demand exactly (Tuggerah
93.9, Kanwal 90.9, Terrigal 98.0, Niagara Park 99.8).

Measured 15 Sep 2026, and the two cuts agree, which is worth knowing because they need not:

| tier | free | filled |
|---|---|---|
| Little Angels | 5 | 98.7% |
| Radiant Rainbows | 7 | 97.5% |
| Shining Stars | 23 | 96.4% |
| Amazing Asteroids | 34 | 95.5% |
| Mighty Meteors | 46 | **92.7%** |

**Placed and capacity accumulate ACROSS centres**, so a tier's percentage is the group's - not the
average of six centres' percentages, which would weight Niagara Park's five rooms the same as
Tuggerah's eleven. On one centre the column is per ROOM, which is where it earns its place:
Tuggerah's Mighty Meteors Silver reads **81.3%**, the emptiest room in the organisation, and the
count beside it (14) is smaller than Mighty Meteors Pink's 16.

**A weekday with unknown capacity contributes to neither side of the fraction**, rather than being
treated as zero capacity.

**Not coloured, and not a mark.** `.dm-good` / `.dm-warn` / `.dm-bad` are meter TRACK backgrounds,
not text colours - `--lm-warn` is 2.2:1 on white and its own comment calls it "label relief" - so
there is no amber in this palette that may be set as text. And a bar per row would turn a matrix
somebody reads down and hands to management into a chart; the meter in the table above already
does the graphic job, on the same figures. It goes through `window.pctText()` like every other
percentage, so 96.0% cannot print as "96%" in a column of 94.7s.

### OUT OF HOW MANY: the margins carry a fraction (16 Sep 2026)

Asked for as *"can we also have the total number of spots somewhere, so the team can see how many
to fill out of how many total"*. `fillFrac()` draws it and it goes on the **EDGES ONLY** - the All
column, the All row, and the weekday strip:

```
ROOM                 TUG  KAN  WAM  P.C  TER  N.P      ALL   FILLED
Little Angels          -    4    -    -    1    -    5/394    98.7%
Mighty Meteors        30   12    3    -    1    -   46/614    92.7%
All                53/850  36/395  11/395  9/295  7/293  1/490  117/2,718  95.7%

BY WEEKDAY   Mon 32 of 544   Tue 15 of 544   Wed 16 of 544   Thu 17 of 543   Fri 37 of 543
```

- **The interior stays bare counts.** A fraction in all thirty-five cells of a tier x centre grid is
  a wall of digits, and a total belongs on the edge anyway.
- **Every denominator is `$tierCap`, the SAME figure the `Filled` percentage is computed from.**
  "5 of 394" and "98.7%" sit in one row and must not be able to disagree. Verified 16 Sep 2026: the
  tiers sum to 2,718, the centres sum to 2,718, the weekdays sum to 2,718, and 2,718 is what the
  hero at the top of the screen already says.
- **A ZERO IS NOT A DASH ON THE EDGE**, against the rule the interior follows. `-` in a cell means
  nothing to do there, which is right; on the edge the row's own SIZE is half of what was asked
  for, so Tuggerah's Little Angels Pink reads **0/60** rather than vanishing into a dash beside
  100.0%. That is the same complaint that got the row back on the report in the first place, one
  step further.
- **SAME SHAPE AS THE PLACEMENT BOARD'S "Free per week" COLUMN** (`.dfill-cap` / `.grid-free-cap`,
  both 62% opacity, both unbolded inside a bold total). Checked against the live boards: the
  per-room All column here and that column there read **identically** - 0/60, 2/80, 2/60, 4/75 --
  and the centre footer reads 53/850 in both. **Two screens reporting one fact must not dress it
  differently.**
- **The weekday strip takes `statStrip()`'s existing `hint` line**, so no new shape was needed.
  "Mon 32" means nothing without the 544 Mondays it came out of, and this is the cut a marketing
  spend is decided on.

`dash_centre()` gained `fill_days_cap` and `dash_fill_report()` gained `cap_by_type`, `cap_by_day`
and `capacity` (per centre and group). All are accumulated in the loops that were already running,
under the same rule as the percentage: **a weekday with unknown capacity contributes to neither
side of the fraction.**

### THE ALL ROW CARRIES A FILLED % UNDER EACH VALUE (6 Nov 2026)

Asked for as *"a percentage next to every value in the All row (except the last 1-2 columns which
are All columns themselves) ... underneath with a line break"*. The Filled column gives each ROW
its percentage; this gives each COLUMN one, in the row that totals them. `fillFracPct()` draws it:
the `fillFrac()` figure, a `<br>`, then `.dfill-sub` (smaller, unbolded, muted) so the count still
reads first. The All and Filled columns do not get one; they are already the row's totals.

- **It is the FILLED %, not the free share**, the same meaning as the Filled column the row ends
  in. Group view: each centre's `pct`, the figure its row in the centre table shows. Centre view:
  `pct_by_day` from `dash_fill_report()`, off `fill_days_placed`, which `dash_centre()` now adds
  next to `fill_days_cap` under the same unknown-capacity skip.
- **It is NOT `1 - free/capacity`, and it should not be.** Placed + free + promised = capacity,
  so Kanwal's Monday reads **9/79** over **86.1%** (68 placed, 2 promised). The Filled column has
  always worked this way. "Correcting" one would make the row disagree with the column.
- **The group view's By weekday strip did not get one**, because it is not the All row. Its `hint`
  line is where it would go.

### "NOTHING TO REPORT" IS NOT "NOTHING LEFT TO SELL"

Conflating them made this card tell a confident lie the first time it was pointed at Warnervale:
385 place-days of capacity, zero children, and it read **"Every place is filled."**

A centre that is shut - or one Kidsoft would not answer for - contributes no row, so the card now
**does not render at all**. That is the treatment the rest of this screen gives a closed centre:
`--` and no commentary. The "Every place is filled" message survives only for a centre that is
genuinely trading with genuinely nothing spare.

### AND THE SAME REPORT FOR THE YEAR AHEAD (`dash_fill_next()`, 16 Sep 2026)

Asked for as *"we like that one, can we add a 2027 (year ahead) box also"*, and it is **a different
question, not one question asked twice.** This year says where a place is standing empty RIGHT NOW;
next year says where the re-enrolment round has not sold the room yet. Measured 16 Sep 2026, the two
point at opposite ends of the building:

| tier | 2026 free / filled | 2027 free / filled |
|---|---|---|
| Little Angels | 5 / **98.7%** | 223 / **38.6%** |
| Radiant Rainbows | 7 / 97.5% | 81 / 70.0% |
| Shining Stars | 24 / 96.4% | 112 / 79.2% |
| Amazing Asteroids | 35 / 95.4% | 148 / 73.6% |
| Mighty Meteors | 46 / 92.7% | 43 / 89.1% |
| **group** | **117 / 95.7%** | **607 / 73.0%** |

**Little Angels is the fullest tier this year and the emptiest by a distance next year**, because
next year's babies have not been enrolled yet and this year's are moving up. A marketing spend aimed
at 2026 alone would have been aimed at the wrong end of the building. Mighty Meteors is the reverse.

- **IT IS FETCHED ON ITS OWN REQUEST** (`/api/dashboard?action=fill_next`), not folded into
  `dash_load()`. One more derived board per centre is **1.8s on a group load that already takes
  4.2s**, and the screen the team opens the day on must not get 40% slower for a card that is not
  the lead. Same treatment the work list and the tours list already get, and **a Kidsoft outage
  costs this card alone** - the container is emptied and this year's report still renders.
- **ONE RENDERER, TWO YEARS.** `renderFill(f, sel)` takes the payload and the container; two copies
  would be two copies of every rule in it. **Every year it prints comes off `f.year`**, never off
  the module-level `year` - which is this year, and which would have deep-linked every room on the
  next-year card to the wrong board.
- **NEITHER IS A SECOND HERO, and this must not drift.** The hero, the meters and every other
  percentage on this screen are THIS year. Each card names its own year in its own title, which is
  the rule that stands in for the year picker this screen deliberately does not have.
- **Group: side by side at xxl** (`col-xxl-6` each), stacked below it. **One centre: the year ahead
  sits under this year in the same column**, at the same width - the pair with "What's changing
  this fortnight" is measured and unchanged, and a card that changes shape when the year changes reads as a
  different card.

### The two views are different shapes, again

- **Group:** room tier x **CENTRE**, which is what a marketing decision is made on, plus the
  weekday strip underneath.
- **One centre:** room x **WEEKDAY**, because there is only one column otherwise and the cell is
  what the team actually fills. Tuggerah 2026: Mighty Meteors Pink carries **10 free Fridays** of
  the centre's 51, and the centre runs Mon 18 / Tue 4 / Wed 5 / Thu 4 / Fri 20.

**A zero is a dash.** A grid of 0s reads as data; a grid of dashes reads as nothing to do there.

**A ROW IS DROPPED FOR HAVING NO CAPACITY, NEVER FOR HAVING NOTHING FREE** (16 Sep 2026). Both
cuts used to drop anything with zero vacancies, on the grounds that the report should be as tall
as the work is. That was written before the `Filled` column existed, when such a row genuinely was
nothing but dashes.

It is not any more, and it cost a real report: *"Tuggerah dashboard Places to Fill is missing Little
Angels"*. Little Angels Pink was **100.0% sold** and had simply vanished. **A room silently absent
reads as a room the centre does not have, which is the opposite of a room with nothing left to
sell** - and this is a report somebody hands to management.

The test is now capacity (`$tierCap` group-side, `r.capacity` in `renderFill()`), so the row that
really would be all dashes - a tier no trading centre runs, a room Kidsoft gives no positions for --
still drops out.

Costs nothing: `dash_centre()` builds each centre's breakdown off the board it has already loaded,
so the report only adds up - **and it therefore cannot disagree with the hero figure above it.**

## What's changing this fortnight (`_incl/week-ahead.php`)

Called **"What changes next week"** until 4 Nov 2026. The file and `week_ahead()` kept their names.

### THE FORTNIGHT IS THE OLD CARD, THREE WEEKS RUNNING, FILTERED BY DATE

Broc, 4 Nov 2026: every change happening **from Monday of this week to Friday of next**, "exactly
as it already does". Every row has a date (see WHEN IT HAPPENS below), so the card is the date
filter: `week_ahead()` reads **four** weeks (last, this, next, the one after) and classifies the
**three** week-to-week steps between them exactly as the one-week card classified its single step,
then keeps a row only when its date is inside the fortnight. Last week's leavers and the starters
the week after belong to the cards either side of this one.

- **Checked literally, not argued**: over all 54 weeks from 5 Jan 2026 to 11 Jan 2027 the
  fortnight returned exactly the old card's rows for the week before, that week and the week after,
  filtered to the fortnight. Nothing missing, nothing extra, in every week.
- On the day it changed (Wed 4 Nov): **19 rows where the one-week card had 6.** The extra 13 are
  what last week's card already showed, for things happening now.
- **A child can appear twice**, because each row is one step. Most are real: Ryleigh Tait moves
  Silver -> Pink on 6 Nov and back on 13 Nov. The ones that read as **one event in two rows** are a
  departure or start that falls mid-week: "Dropping a day" from the Thursday plus "Ending care", last
  day the Wednesday. Over the 54 weeks: 85 leaving splits, 44 starting splits, most in January.
  **Left as they are.**
- `closed` now names the week: a week **of the fortnight** with no bookings at all is reported as
  *"Erina has no bookings in the week of 4/01/2027"*. `ends_disp` is gone; the payload carries
  `from_disp` / `to_disp` for Monday and Friday.
- **Sorted by kind, then DATE** (Broc, 4 Nov 2026): Ending care, Starting, Moving rooms, Dropping a
  day, Taking more days, each in date order, with centre and name breaking a same-day tie. It was
  biggest movement first within a kind until then.
- Cost: still ~0.1s for the group. Four weeks touch at most two months, and they are the same cached
  month reads.

### THE CHRISTMAS GUARD IS DEFEATED BY ONE BOOKING (found 4 Nov 2026, NOT fixed)

Terrigal holds **one permanent booking on Mon 28 Dec, Thu 31 Dec and Fri 1 Jan** (one child,
Shining Stars), on days every other centre is shut. The per-date guard below treats a date with any
booking as OPEN, so those two weekdays get compared: **the week of 21 Dec reads 76 Terrigal children
ending care, and the week of 28 Dec reads 68 starting.** That was already true of the one-week card
(on its 21 Dec and 28 Dec weeks). The fortnight shows the same false rows on the cards for
**14 Dec through 4 Jan**. HOW: `week_ahead()` run with `$asAt` on every Monday, then the raw
`getBookings` for 21 Dec to 15 Jan at all seven centres.

**IT IS WORSE IN 2027, and the "View more..." dialog shows it** (re-measured 6 Nov 2026). Four
centres hold 1 to 3 permanent bookings in the week of Mon 27 Dec 2027 - Tuggerah, Niagara Park,
Terrigal and Point Clare - so **477 children read as "Ending care" on 21 to 24 Dec 2027** (Tuggerah
126 on the Friday alone). The card will show the same in December 2027; the dialog shows it today to
anyone whose range reaches that week.

**AND THE GUARD HIDES THE FIRST DAY BACK.** A week is only ever compared with the week straight
before it, and the week before the first day back is the shut one, so nothing that changes over the
break is reported at all. Classifying the week of 21 Dec 2026 straight against the week of 4 Jan 2027
(HOW: `week_ahead_bookings()` + `week_ahead_classify()` on those two weeks, every centre): **494
room moves, 193 children ending care and 70 starting** that neither the card nor the dialog shows.
The year's biggest movement is exactly the one missing. **Both are reported to Broc, not fixed**: the
fix is choosing what "open" means (a share of a normal day, not "any booking") and comparing across
the shut weeks, and it changes the card as well.

### THE PROJECTED % WAS ASKED FOR, MEASURED, AND DROPPED

Broc, 15 Sep 2026: *"the projection for next week ... they can see clearly the % projected."* It
was measured before it was built, over all seven centres:

| | this week | next week |
|---|---|---|
| group fill | 95.2% | 95.1% |
| centres moving 0 or 1 place-day | | 6 of 7 |
| largest single move | | Kanwal 89.1% -> 88.4% |

which is the **same finding that retired the week picker** - `cells_week()` on two weeks ten weeks
apart returned identical capacity, placed, free and candidate counts for all 55 cells. A headline
that cannot move is not a headline.

**Worse, it would have been a SECOND FILL % DISAGREEING WITH THE BOARD.** The board reports what
is **sold** for the year (the peak to December); next week reports who is **in the room**. Tuggerah
reads 93.9% sold against 93.2% attending, Kanwal 90.9% against 88.4% - and that 10 place-day gap
is the Dyer twins, enrolled and starting 28 September. Two numbers for one centre is the fault
this app keeps having to un-make.

**The movements underneath it are real and are visible on no other screen.** The same measurement
found **12 booking changes next week across 4 centres, which are 10 children**: 1 starting, 3
ending care, 2 moving up a room, 3 taking more days, 1 dropping one. **Niagara Park owns 8 of
those 12 rows and its fill % is identical in both weeks**, so the list carries everything the
percentage could not.

### PERMANENT PLACES ONLY, AND THE FILTER IS LOAD-BEARING

Both cards are long-term permanent enrolments only, as the hard rule requires.

The snapshot reads **only `Bookings/getBookings`**, never `getWaitlistedBookings`, so the `Casual`
FLAG is the test and the CAS type-name rule does not apply. Measured 15 Sep 2026 over the two
weeks it compares: **5,174 permanent bookings, 54 casual dropped.** Re-running the same classifier
with the casuals left in takes the card from **10 rows to 43** - 33 invented, almost all "dropping
a day" and "taking more days" that are one-off casual days appearing and vanishing.

Two show how bad it gets. Wamberal's **Peyton Samuta reads as MOVING ROOMS**, "Radiant Rainbows ->
Shining Stars", when he is permanently in Shining Stars and took a single casual day in Radiant
Rainbows - a fake room progression on a card whose whole job is telling real ones from departures.
And Kanwal's **Theodore Burkill** reappears, the same child whose casual Tuesdays broke the
day-requests block in August.

**Places to fill** is permanent-only down its whole chain: `board_forward_occupancy()` and
`board_live_children()` both drop `Casual`, and the denominator comes from `getUtilisation` with
`ExcludeCasualBookings` pinned, so a casual can inflate neither the occupancy nor the capacity.

### THE UNIT IS THE CHILD, NOT THE (CHILD, ROOM) ROW

The two rows that collapse are both children **shifting days between two rooms** at Niagara Park -
Jasper Mamone drops from three Little Angels days to one and picks up two in Radiant Rainbows,
Sonny Beehag does the mirror. Diffed per room, **each of them reads as a child STARTING in one
room and ENDING CARE in another, on the same card.** They are birthdays. Same fault
`coverage_mark_handovers()` exists to fix on the year board, where 80 of 215 early-ending
arrangements at Tuggerah were room progressions.

**A MOVE REQUIRES THE DAYS TO LAND SOMEWHERE - some room has to GROW.** The first version tested
only "did the set of rooms change" and got Tuggerah's Luca Yates wrong on the first run: he holds
Radiant Rainbows Silver on Monday and Radiant Rainbows Pink on Tuesday, and next week only the
Pink Tuesday. The room set changed and nothing moved anywhere. **And that test outranks the day
count**, because Sonny Beehag's net delta is ZERO - a day-count test alone reports nothing at all.

### CHRISTMAS IS NOT A MASS DEPARTURE, AND THE GUARD IS PER DATE

Measured across all of 2026 at all seven centres: every trading centre has **exactly seven
weekdays with no bookings** - 1 and 2 January, and 25, 28, 29, 30, 31 December - and the seven are
**identical at all six trading centres**. Every other weekday has bookings, **public holidays
included**, because the families are charged for them: Monday 26 January reads 148 children at
Tuggerah against 148 and 149 on the Mondays either side, and Good Friday 3 April reads 145 against
146 and 145. So no holiday guard is needed and none was written.

A zero-booking date at a trading centre means the centre is **shut**, never that it emptied. **A
weekday is only comparable if the centre is open on it in BOTH weeks**, and the week of Monday 14
December is the one that proves why: Friday the 25th is shut while Friday the 18th was not.

| week of | rows |
|---|---|
| Mon 14 Dec | **1** (a real departure). Without the per-date guard, **504** - all 503 children booked on Friday the 18th reading as dropping their Friday |
| Mon 21 Dec | none; all six trading centres reported closed - not one comparable weekday |
| Mon 28 Dec | the same, from the other side |

`week_ahead()` takes an **`$asAt`** parameter that nothing in the app passes. It exists so that
guard can be **exercised rather than asserted**, and the table above is what it produced.

### BOTH NEW CARDS ARE NATURALLY NARROW, SO NEITHER GETS A FULL-WIDTH ROW

Reported 15 Sep 2026 as *"it seems to be squished over on the left"*, and the first two attempts
at it were both wrong, which is the useful part.

The changes table needs **855px** and was sitting in a full-width card - fine at 1280, absurd at
1920, where it filled less than half of an 1888px card. Same for the fill report on a single
centre: four columns and a handful of children across the full width.

**The room a child moves FROM is abbreviated** (Broc, 4 Nov 2026): "RR Silver -> Radiant Rainbows
Pink", with the full name on hover. LA / RR / SS / AA / MM, keyed on the first word like
`room_type_key()`, colour kept; a family not in the map prints in full. `roomAbbr()` in
`dashboard.js`. Measured on the group view that day: the table went from **945px to 850px**, so it
fits its 935px half at 1920. It still scrolls sideways inside the pair from 1400 to about 1800.

- **Stretching the columns is NOT the fix, and `.dash-free` already said so.** Its comment
  records that full width was tried and rejected on the declines list, because the slack lands
  between Room and Days and *a strip of weekday boxes 300px from the room it belongs to stops
  reading as one row*. That decision stands. The table spans its card and the **last** column
  absorbs every pixel, so Child / Centre / Room / Days keep their content widths and the weekday
  strip stays hard against its room.
- **Which starves the other columns unless they refuse to shrink.** Auto layout gives a
  percentage column everything it asks for: without `white-space: nowrap` on the rest, "Amarni-Rose
  Hammond" wrapped over three lines and "Little Angels -> Radiant Rainbows" over four. Worse than
  the gap it was fixing.
- **Then the cards are PAIRED**, the same answer the declines and gap lists already got. Group
  view: **Tours (5) beside What's changing this fortnight (7)** - the two "this week" blocks, and the
  changes row is the longer one, so it takes the wide half. At 6/6 the change column clipped
  mid-word ("Ending car"). Centre view: **Places to fill (5) beside What's changing this fortnight (7)** --
  **sized by what each needs, not by which matters more.** The fill grid is a room name and six
  columns of one- and two-digit figures; the changes list is a name, a room, a strip, a date and a
  label. 7/5 was tried first and clipped every label in the Change column.
- **xxl, not xl.** At 1280 the wide half is ~560px and the changes table would scroll inside it.
  xxl starts at 1400, so the pair only forms once there is room for both; below it they stack full
  width, which is the shape that already looked right.
- **No media query on the table rules, and that was checked rather than assumed.** They looked
  like a desktop-only trade, so they were tried inside a `min-width: 992px` guard first and **the
  phone was worse for it**: five columns overflow 390px either way and `.table-scroll` carries it
  either way, so wrapping bought nothing but height. One compact line per row that you swipe beats
  four lines that you also swipe.
- **`#dash-week .stat-strip` drops its minimum to 6rem.** Five figures where the re-enrolment card
  has four, and at 7rem the narrow half fitted only four - orphaning "Place-days" on its own line
  under a wide gap, **the exact fault the grid was introduced to stop.** The labels here are
  shorter than the ones 7rem was measured against, so the minimum came down rather than a figure
  coming off.

### WHEN IT HAPPENS: a departure is dated by its LAST day, everything else by its FIRST

The `When` column carries a real date, formatted server-side like every date this app publishes.

**A leaver's date therefore lands in the EARLIER week of the two being compared and every other
kind in the later one** (which is why the fortnight reads four weeks to report two), which is only
safe in one column because the `Change` column beside it says which - and the cell carries the
wording ("Last day" / "From") in its title.

That asymmetry is deliberate. *"Leo Birch ends care Friday 18 September"* is the sentence the team
acts on. The alternative - the first day he is ABSENT - is not a fact anybody uses, and it would
be the one date on the card naming a day nothing happens on.

`days` is always **the weekdays that CHANGED**, so the earliest of them is the day the new
arrangement starts: the first day in the destination room for a move, the first of the days taken
up, or the first day a dropped day is missing. Measured against the raw bookings on 15 Sep 2026,
all ten rows: Zara Jenkins holds Mon/Tue/Wed and is dated 16/09; Bodie McShane takes Tue/Wed and is
dated 22/09; Luca Yates drops his Monday and is dated 21/09, the first Monday he is not there.

**A declared `ExitDate` needs no branch.** `week_ahead_bookings()` has already dropped every
booking on or after it, so the last booked date IS the last day - which is what makes Brodie
Jackson's case work, where notice was in and 49 bookings ran on to December. (None of the three
current leavers has one; all three are inferred from the bookings stopping.)

### WHY THEY ARE LEAVING: Kidsoft's `ExitReason` (22 Oct 2026)

Asked at the enrolments meeting, with the worry that the child "is no longer enrolled" once the
Parent Account Manager exits them. **The record outlives the exit**: measured that day, all 54
children with an exit dated since 1 Aug were still returned by plain `getChildren` (all seven
centres), and **all 54 carried a reason**. So a leaving row shows it under "Ending care".

- **Only when the exit is this departure**: `ExitDate` no earlier than the Monday before this week.
  A child who left once and came back can still carry the old exit. A leaver nobody has exited yet
  (bookings stopped, no `ExitDate`) shows nothing.
- **46 of the 54 read "Change of Care Conditions"**, the value tenure.php already calls "the value
  people pick when they pick nothing". Kidsoft offers 13 (Left Area, Financial Hardship, Change of
  mind...). The column is only as useful as the choice made in Kidsoft.
- **There is no free-text exit note in the API.** HOW: those 54 re-read with
  `Options=CustomData,Enrolments,Comments,AdditionalDetails`: no custom data, enrolment rows carry
  status and dates only, and the comments are undated profile text (a handful mention moving
  interstate or another preschool, unstructured).

### The day strip carries the direction

Days a child **takes up** are green - the strip's `has` state, literally true - and days they
**give up** take the neutral grey the declines list uses. That is the opposite of the trap that
list fell into, where green on a card about vacancies read as *a place is held*: here both
directions appear on the same card, **so the pair IS the meaning.**

Every row deep-links to `/placement` carrying `room` and `wd`, because naming a room and a weekday
in prose above a grid that has a square for exactly that is the shopping-list fault the
coming-free banner had to unlearn twice.

Cost: **0.08s for the whole group**, warm. The month reads use exactly the cache keys
`bookings_year()` writes, and `board_load()` has normally pulled the same months in the same
request.

### "View more...": the same table for any range (6 Nov 2026)

Broc: *"see the same table but define a longer range of dates, or a specific range of dates"*,
paginated, with two date boxes at the top, and *"it shouldn't be possible to enter in a negative date
range"*. A link at the top right of the card opens `#wk-modal` (`dashboard.php`), filled by
`dashboard.js` from `/api/dashboard?action=changes&centre_id=&from=&to=`.

- **One engine, two callers.** `week_changes($centreId, $from, $to, $readable)` does the work;
  `week_ahead()` is now the fortnight's call to it. It reads every week the range touches **plus the
  week either side** and classifies each neighbouring pair, which for the fortnight is the old four
  weeks and three steps. **Checked, not argued**: the card run on all 104 Mondays from 5 Jan 2026 to
  27 Dec 2027 (group, Tuggerah, Niagara Park, Terrigal) before and after - **every row and every
  total identical**, 3,623 rows.
- **The only difference is the "no bookings in the week of" notes, on purpose.** A week is reported
  shut only when the centre has bookings in a week before it AND a week after it, which is what the
  card's comment always said. Over a range of months the old test ("any booking in the weeks read")
  would have called a centre shut every week before its first booking - Erina was "closed" the weeks
  of 21 and 28 Sep 2026, before it opened on 6 Oct. The notes it no longer prints: those two, Erina
  on 28 Dec 2026 (it has no January bookings yet), and the end of what is booked in late 2027.
- **The range is held to this year and next** (`week_changes_bounds()`, 1 Jan 2026 to 31 Dec 2027
  today): the two years `bookings_year()` is warmed for nightly, so any range is cached month reads.
  `$readable` stops the week either side reaching outside them - an unread date has no bookings, which
  the per-date guard already treats as not comparable. Last year IS cached (the tenure warm) but is not
  offered: nobody asked, and every inactive room's bookings would read as children vanishing.
- **One request per range, paged in the browser, 25 rows a page.** The server has to build the whole
  range to sort and total it anyway: **1.0s for both whole years across the group, 1,810 rows, 548 KB,
  78 KB gzipped**. Asking per page would only repeat that second on every click. The figures at the
  top are the WHOLE RANGE, never the page. Sorted exactly as the card (kind, then date), so a long
  range pages through the leavers first.
- **A reversed range cannot be entered**, the Waitlist's start-date rule: each box bounds the other
  through min/max, both are held to the bounds, a date typed past them fails `checkValidity()`, turns
  red and is never sent, and the table keeps the last good answer. The server refuses a reversed,
  out-of-bounds or malformed pair anyway (`api_fail`, readable). **The red border alone**: the same
  app.css rule as the DOB boxes, because Bootstrap's warning glyph lands on the calendar button.
- **The title names what it covers** (Broc, 9 Nov 2026): "What's changing at Tuggerah" or "What's
  changing across all centres", written server-side in `dashboard.php` from the centre on screen -
  the same centre the request is scoped to.
- **A child's name steps the dialog aside** for the child's (Bootstrap does not stack dialogs) and
  **reopens it on the same dates and page** when that closes - driven in Chromium from page 2.
- **Supervisors**: `api_require_centre()` first, like `load` - a Tuggerah supervisor gets Tuggerah and
  is refused the group and Kanwal (checked with a minted supervisor session).
- **Two things changed for the card too.** A row's room links to the board of **the row's own year**
  (`on`'s first four characters), not the Dashboard's, so a January move opens next year's board. And
  `week_ahead_bookings()` takes declared exits from **every year its dates touch**, not just one.
- **The pager is the first in the app** (Bootstrap `.pagination`, windowed: first, last, two either
  side, "..." between). Bootstrap compiles its active colour as the literal `#0d6efd`, so `app.css`
  points it at `--lm-purple` beside `.btn-primary`.
- **Read the Christmas section above before trusting a range that crosses the break.**

## The re-enrolment round (`_incl/reenrolment.php`)

The round opens 1 August by offering next year to existing families first, except children
ageing out. **Minimising churn is a standing objective and families in care have first claim.**

### Phase one: WHO HAS NOT ANSWERED (`reenrolment_outstanding()`)

**KIDSOFT RAISES AN EVENT ONLY WHEN SOMEBODY ANSWERS.** Verified over every notification
category since 2024: the only re-enrolment subcategories that have ever existed are
`Re-enrolment Accepted` and `Re-enrolment Declined`, and no documented action mentions
re-enrolment at all. **So silence cannot be read, only DERIVED** - this year's roll, minus the
families who answered, minus children who cannot be offered a place.

- **The answers match on EXACT rules, no distance matching.** No fuzz is needed: over 472
  answers group-wide, exact keys match **469 (99.4%)**, once two shapes Kidsoft writes are
  handled - `"Huey (Hugh) Scott"` (nickname, then the LEGAL first name in brackets) and
  `"Savannah Horner Abousleiman"` (a middle name, or a space-separated double-barrel).
  `reenrolment_name_keys()` builds every exact candidate key. **The 3 that miss cost one
  unnecessary call each, which is the safe direction**: this list is "who have we not heard
  from", so a miss rings a family who already answered rather than missing one who did not.
- **SCHOOL AGE IS FLAGGED, NOT EXCLUDED.** Excluding on room age alone is legally right and
  practically wrong: Tuggerah runs one room licensed to 72 months where every other centre stops
  at 60, so that test removed 40 children at Niagara Park and **one** at Tuggerah. Whether a
  school-age child is returning is the centre's judgement, so it is surfaced (turning 5 by 31
  July, the NSW test) and reported as "153 to chase, 97 more are school age".
- Measured 7 Aug 2026: roll 861, answered 473, cannot be offered 138, **153 to chase**, 97
  school age.

**Warmed nightly, and it breaks the TTL rule on purpose.** `getNotifications` is cached 15
minutes and warmed once a night, which for anything else would make the warm pointless. Here the
two do different jobs: **15 minutes keeps a round that moves by dozens a day current** (452 ->
460 -> 472 accepted inside one day), while **the warm only guarantees a row EXISTS** - an
expired row is served stale and refreshed after the response, but a MISSING one is a ~4s wait
for whoever opens the Dashboard first. **Warm for existence, short TTL for freshness.**

### Declines, matched to the place they free (`reenrolment_declines()`)

**A decline is the only forward-looking vacancy signal in the API**, and it is only useful if
you know WHICH room and WHICH days. Every other departure signal is retrospective:
`Children.ExitDate` is never future-dated, enrolment `EndDate`/`CeasedDate` had ZERO future
dates across 257 active children, and `coverage_map()` only infers a leaver after the bookings
stop.

Measured 7 Aug 2026: **12 declines, all 12 matched, 33 place-days coming free in 2027.**

**This matches a parsed name to a board row, which was previously refused outright** - "a
parsed-then-fuzzy-matched name is precisely how one child's decline gets pinned on another".
That refusal was about **FUZZY** matching and it still holds. Two rules keep it honest:

- **Exact only.** No edit distance, no near-name suggestion.
- **An ambiguous name is REFUSED, not guessed.** If two children on the roll fold to the same
  key, the decline is reported with **no room attached**, because pinning it on the wrong child
  says a place is free that is not AND says nothing about the place that is.

The name is shown as Kidsoft wrote it (flipped from "Last, First", `name_case()`d). When the
sentence does not match, the raw text is kept and the name is null; **nothing is guessed.**
Verified: 0 unparsed of 36.

**TWO KINDS OF DECLINE ARE NOT PLACES COMING FREE NEXT YEAR, and both are dropped** (Broc,
6 Nov 2026). Measured that day: 26 declines on the card, 7 of them wrong.

- **Booked for next year.** A booking is what the centre did; the notification is a click
  nothing ever withdraws. Ryder Scelly's family declined on 3 Aug, and he has 2027 bookings in
  Amazing Asteroids Pink on his same three days - moving up, not leaving. The test is the
  child's Kidsoft id on next year's `reenrolment_roll()`, **the same test
  `reenrolment_holds()` uses for "already has next year"**, so the two cannot disagree. 1 of 26.
- **Already gone.** A parsed name matching nobody on THIS year's roll is a child who no longer
  holds a place: it came free this year and the board already counts it. All 6 such rows were
  departures - five exited in Kidsoft in September (Beau and Madeleine Grant among them) and
  Logan Zaniol at Kanwal, whose bookings stopped 18 Sep while **Kidsoft still had him Active**.
  They rendered as "not matched to a room", which the team could not read.

**An unparsed name and an ambiguous one are still shown**: neither says the child has gone, and
dropping unparsed rows would hide every decline the day Kidsoft rewords its sentence. After:
**19 children, 45 place-days.**

**The "Declined" figure on the Next year card is NOT this list.** It counts every decline
notification since 1 January (26 on 6 Nov 2026), so it sits above a list of 19.

### The gap between acceptances and bookings

`reenrolment_round()` reports, per centre, who has accepted, who has **declined**, and the
**gap** between acceptances and bookings. Measured six days into the 2027 round: **452 accepted
group-wide, 220 booked**, with Niagara Park at **92 accepted and none booked**. Those families
believe they have a place, and nothing anywhere showed that before.
