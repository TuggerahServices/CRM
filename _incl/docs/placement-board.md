# The placement board and the cell grid

Governs `placement.php`, `api/cells.php`, `api/board.php`, `static/js/board.js`, and the
engines `_incl/cells.php`, `board.php`, `board-live.php`, `capacity.php`, `fill.php`,
`day-requests.php`, `internal-requests.php`, `occupancy.php`, `drift.php` (coverage only).

**The unit of work is a room x weekday CELL, never a room.** Under 10% of children attend
all five days. The core goal is to fill every room, every day, to 100%.

## The axis is a YEAR, not a week

A place is sold for the year - a family booked on Mondays pays for every Monday whether the
child turns up or not. `placements` carries no start or end date; a placement IS a standing
weekly commitment.

**The week picker was measurably the wrong axis.** `cells_week()` for Tuggerah on two weeks
ten weeks apart returned **identical capacity, placed, free and candidate counts for all 55
cells**. It navigated a dimension the numbers never varied on while hiding the ones that did.

What a year buys, none of it visible from one week:

- **Unsold place-days.** `booked_year_summary()` reduces a cell's ~52 daily booked counts to
  typical (**MEDIAN**, so a Christmas shutdown does not drag a full room down), min, max and
  `free_days`. A cell can read full on the plan and still have stood part empty most of the
  year; that gap is the point. Tuggerah 2026: **5,220 unsold place-days**, worst cell Mighty
  Meteors Pink on Fridays.
  **`weeks` is `weeks_free`'s denominator and nothing else.** It held `count($series)` --
  dates Kidsoft returned a row for - while `weeks_free` counted every weekday of the year,
  so the tooltip printed **"empty on 52 of 36"** (fixed 13 Aug 2026). The panel had already
  worked around it by reaching for `data.weekday_counts` instead; that workaround is gone
  too, because **two sources for one figure is how they start disagreeing.** A fraction whose
  halves come from different populations is not a fraction - fix the field, not the
  sentence.
- **Mid-year starters and leavers.** 41% of Tuggerah's arrangements cover only part of 2026.
  `coverage_map()` judges these on the **SPAN, not a percentage**: a child on 44 of 52
  Mondays is either a full-year child who missed the Christmas fortnight or one who started
  in March, and only the dates say which.
- **Places that actually come free.** `coverage_mark_handovers()` excludes a child who leaves
  one room and appears in another within three weeks - that is a birthday, not a vacancy. Of
  215 arrangements ending early at Tuggerah, **80 are room progressions and only 135 are real
  departures**; counting all 215 would overstate sellable capacity by more than half.

`cells_year()` **composes** the existing engines on (room, weekday); it does NOT recompute
occupancy. A second independent occupancy calculation is exactly what caused the old
Board/Fill disagreement, so each engine stays the single authority on its own question.
~0.2s for Tuggerah warm.

### A date on the year: "From" (16 Oct 2026)

Broc: *"Is it possible to have a date option for future or past dates for the placement board?"*
Asked what a cell should then show, he chose **"held from that date on"** over "who is in the room
that week". So this is **not the week picker come back**: the board still counts every place held
from a date to 31 December (the forward peak below), and the date only moves where that count
STARTS. Pick 1/03/2027 and a child leaving in February is off it and one starting in June is on
it; pick a past date and the board reads as it would have that morning, from the bookings Kidsoft
holds now.

- **`board_from($year, $chosen)` in `board-live.php` is the one definition**: the chosen date, or
  today for the year running, 1 January ahead, NULL for a finished year (which keeps its
  reference-month fallback). Read by `board_forward_occupancy()`, `board_departures()` (memo keyed
  on it), `cells_year()`'s leaving filter and `staffing_band_on()` - the four things that decide
  what the board COUNTS. Passed explicitly as `$opts['from']` to `board_load()` / `cells_year()`
  / `board_overview()`, **never a global**: `cells_year()` loads this year's board too, for the
  "still being enrolled" note, and a global would have moved that one as well.
- **What does NOT move with it**: open offers (Kidsoft's and legacy), held days, re-enrolment
  holds, In progress, the wish form's default date. Those are true now, and a board looked at for
  March still shows today's. The whole-year figures (unsold place-days, "Un-occupied positions")
  are about the year and stay so.
- **The reference month does NOT follow the date.** It is only the outage fallback, and the hourly
  warm keeps THIS month fresh; pointing it at the chosen month made every pick a cold ~4s read on
  Tuggerah for a list the forward roster replaces anyway. With it left alone, a pick costs
  **0.35-1.0s per centre and 0.15s for the overview** - the month reads are `bookings_year()`'s
  nightly-warmed keys.
- **The date decides the year** (`/api/cells` and `/api/board?action=overview` take `from`), and
  is **bounded to the stepper's years** (`board_from_bounds()` off `year_list()`): a year outside
  has no rooms, and a past one is a cold read of every month in it - **50s for Tuggerah 2025**,
  measured, which is the existing past-year path and not this change. An out-of-range date is
  refused in words by the API (`board_from_request()`) and dropped by `placement.php`.
- **A pick NAVIGATES**, like the year arrows, so the session, the URL and the centre tabs agree;
  the tabs carry `from` (`params`), the arrows drop it, the x clears it. The box shows the date
  the board counts from even when nobody chose one. **700ms pause before going**, because a typed
  date passes through valid dates on its way ("01" before "15"); `min`/`max` discard a half-typed
  year. The date itself is not remembered in the session - the next visit reads as today.
- **The free tile's caption follows it**: "unfilled every week from 1/03/2027" rather than "of
  2027", on the centre board and the group overview.
- **Verified**: with no date the full `cells_year()` payload for four centre-years and both
  overviews is **byte-identical** to the code before the change (3MB, run side by side). Tuggerah:
  today 793 placed / 57 free / 3 leaving; from 1/12/2026 790 / 60 / 0; 2027 from 1/03 661 placed
  against 663; from 2/03/2026 883 / 24 / 147 leaving. Driven in Chromium: pick, centre switch,
  clear, year arrow, overview links, out-of-range date - no console errors.

## ONLY FUTURE arrivals and departures count

Fixed 10-11 Aug 2026, from UAT (Broc: *"does that even make sense in the context of this
app?"*). Coverage sees the WHOLE year, and both figures were announcing the past.

- **`leaving`:** of 510 counted group-wide, **482 had already happened** - Tuggerah's 134 of
  138 at a median last day of **8 April**, four months before the screen said the place was
  "coming free". Worse, they were not even news: the board derives from
  `board_reference_month()` (this month), so a child who stopped in April is already off the
  roll and their seat is already in the cell's `free` figure and its heat colour. What
  survives the filter is **28 arrangements group-wide, 16 children** - small enough to name
  rather than count, and genuinely invisible elsewhere.
- **`arriving`:** unfiltered it was **772 group-wide of which 734 had already happened**, and
  those are pure noise (a child who started in March is on the August board already, counted
  in `placed`). The **38** that remain are the opposite of noise: a child whose first booking
  is in October is **a seat already committed that the cell is still reporting as free** --
  a place two staff could promise to two families. So the badge stopped being context and
  became a **qualifier on the free figure**: "1 place already taken from 21/08/2026".
  **THAT BADGE IS GONE (21 Aug 2026) AND SO IS THE FIELD BEHIND IT** - the child is on the
  cell now, with their start date on their own row. See "The board is the forward roster"
  below. `arriving` survives only INSIDE `cells_year()`, where `coming_free` nets it against
  departures.

`cells_year()` publishes `leaving_children` and `dropping_children` beside `leaving_total`,
because a child leaving three days is **one phone call and three places**, and the banner has
to say both. Also `free_from`: the first date the place is free (the next one after the child's last day there - it was `leaves_on`, the last day itself, until 10 Nov 2026, so the panel said "+1 free from 7/12" beside "to 7/12" on the same child). A Kidsoft `ExitDate` is the first day GONE: all 25 measured were a Sunday after the last booking.

**There is no `leaving_from` (removed 14 Aug 2026).** It was the soonest of **all** departures,
including the ones an arrival already covers, and the banner printed it as the date of the
netted `coming_free_total` - a date and a count drawn from two different populations, which is
how a figure starts disagreeing with itself. Every date the banner needs is already on the row
it belongs to, in `coming_free_cells[].from`.

### The leaving banner counted GROSS and could not be located (fixed 13 Aug 2026)

Three faults, all reported from one question: *"it says 4 children stop attending later in the
year freeing 6 place-days... where?"*

- **The places were already re-sold.** Niagara Park 2026 reads 25 cells, capacity 490, placed
  489, **free 1**, 24 of 25 cells at zero free - and **all six** of the announced place-days
  had a replacement booked into the **same cell within 1 to 3 weeks**. `free` was right at 0
  (`holding` is the peak to December and counts the replacement); the banner was announcing
  work that does not exist. **`coming_free` = `max(0, leaving - arriving)` per cell** is what
  is actually going on sale, and `coming_free_total` / `coming_free_cells` drive the banner.
  Niagara Park's banner is now gone. **A banner that disagrees with the free figure beside it
  is two sources for one number.**
- **"Stop attending" was wrong for half of them.** Coverage is per (child, room, weekday), so
  a child giving up ONE weekday counted as a departure. Axel Lam keeps Mon/Tue/Fri to 21
  December and drops only his Thursday; Bella Lam keeps Mon/Tue/Thu and drops only her Friday.
  `leaving_children` now counts only children leaving the **service** - a declared `ExitDate`,
  or **every** arrangement they hold ending early - and `dropping_children` counts the rest.
  **Both still free the place-day and both stay on the cell** (Broc: *"it does free up that
  place-day for another child to take"*). This changed what they are CALLED, never whether
  they are counted.
- **It named no cell, and nothing else could.** A departure gets no grid dot (that slot belongs
  to demand, see below) and **cannot** show in `free` either, because `holding` is a peak and
  the leaver is present today - so the only way to find one was to hover all 25 cells. The
  first fix named them in the banner, **grouped by room** ("Little Angels Tue, Wed; Shining
  Stars Tue, Wed, Thu"). That was still the grid rewritten as prose, directly above the grid --
  see the next section, which replaced it.

### THE BANNER STOPPED SPELLING THE GRID OUT AS A SENTENCE (14 Aug 2026)

Naming the cells in words fixed "where?" and created a worse shape. Measured on the live boards:
**Tuggerah ran to thirteen room-and-day groups over three wrapped lines**, naming rooms
("Amazing Asteroid Purple") that were not among the three visible in the scroller below it, so
the reader still had to hunt - now with a shopping list in hand. Kanwal was eleven groups on
one date. The paragraph also cost the grid a whole room row of height.

**The cells are marked on the board instead.** A cell with `coming_free > 0` carries a `+N Mon`
corner tag, and the banner keeps only the two things a room x weekday grid cannot say:

- **WHEN**, as one chip per month. The grid is flat over the whole year, so a cell has nowhere
  to record that Wamberal's Mighty Meteors Monday opens on **20 December** and is next year's
  problem while Kanwal's eleven all open on one day in a fortnight. Notice is four weeks, so
  the month is what makes one of these urgent and the other not. The old single "from
  16/08/2026" buried exactly that difference.
- **WHO**, in children, because that is the number of phone calls.

**A chip filters the board:** it dims every cell that is not in that month, rings the ones that
are, and scrolls them into view. That is the whole reason this banner sits on top of a grid
rather than beside a list.

### `lit-btn` - show me where, shared by every notice

A button carries the cells it means as a selector in **`data-lit`** (`.cell-grantable`,
`[data-cf-month="2026-09"]`), matched inside `#grid`. One handler serves all of them, so a new
notice needs a selector and no new code. **One set is lit at a time across ALL the notices** --
two at once would dim nothing much and say less - and clicking the lit button clears it.

**No inverted corner tag on a lit cell**, though it looked right while the month chips owned
this outright. The moment the grantable notice shared the mechanism, pressing the **green** Show
turned an amber `+1 Aug` tag solid on the one cell it lit, making the loudest thing on screen a
fact that button is not about. Same lesson as the captions: **a rule written for one caller
becomes a claim in the other's voice.**

**And a lit cell that already carries a ring does not get a second one**
(`.cell-lit:not(.cell-grantable)::after`). This is what makes Show correct on the grantable
notice: every cell it lights is grantable, so none takes the amber ring, and all the button does
is drop the rest of the board back so the green pulse is unmissable.

Three things that had to be got right, all invisible to a page render:

- **The visible band is not the scroller.** A sticky header sits at the top and the totals row
  is pinned to the bottom, so on Tuggerah only 306px of the scroller's 413 actually shows a
  room. Scrolling to the first hit alone parked the other four September cells under the totals
  row, where all that showed of them was 5px of ring and the tops of four tags. The run is
  centred in the **band** when it fits, and a row that would straddle the bottom edge is nudged
  fully out of sight - honestly off-screen beats a sliver that reads as a rendering fault.
- **`.grid-totals` is a `<tr>` whose CELLS are `position:sticky`, not the row.** Reading
  `offset()` off the row gives its unpinned place at the foot of the whole table and sends the
  scroll into the weeds. Derive the edge from the row's **height**.
- **The ring is a `::after`, and neither obvious property would do.** `outline` paints in the
  last step of the stacking context, after every positioned descendant, so it drew straight
  **through** the pinned totals row. `box-shadow` is what the grantable ring's keyframe
  animation drives, so on a cell that is both grantable and coming free - Tuggerah's Amazing
  Asteroids Silver Friday, today - the animation would replace it outright and one of the two
  rings would vanish.

`coming_free` is **additional to `free`, never a restatement of it**: `free` is what is spare
at the fullest moment of the rest of the year, `coming_free` is what opens up afterwards. A
cell reading "0 free" and "1 from 3/09" is saying both truthfully.

**`free` NOW ACCOUNTS FOR THESE PLACES (13 Aug 2026), and the warning wording went with it.**
Until then `free` was deliberately left alone - advisory, never a lock - and the tooltip
carried "(still counted in the free figure above)" to say so. The figure was then fixed
properly in `board_load()` (see `board_forward_occupancy()` below), so that parenthetical was
**deleted**: telling staff to distrust a number that is now right is worse than saying
nothing. **FIXING A FIGURE DELETES ITS CAPTION** - the same lesson as the handover caption
two paragraphs down.

**AND THEN THE BADGE WENT TOO (21 Aug 2026).** It kept the date so that someone reading "4
free" against a capacity of 5 could find the missing place without opening Kidsoft. The
missing place has a name and it is now printed in the room, so the badge restated a row that
says it better - and it was read **backwards** in UAT, as a countdown to the room filling up
rather than as the explanation of a figure that had already accounted for it. Reported as:
*"it says 1 place free, but then it also says 1 place already taken from 31/08/2026. Does
that mean in 10 days this room/day will actually be full?"* It did not: Tuggerah's Shining
Stars Pink held 14 on 31 August and **13 on every other remaining Monday** against a capacity
of 15. **The same lesson twice on one badge - FIXING A FIGURE DELETES ITS CAPTION.**

**A past year now produces nothing at all**, which is correct - "comes free" is a claim
about the future.

**No "children moving up a room are not counted" caption.** The handover exclusion is real
and stays in the engine, but once the figure is future-only it removes **3 arrangements
group-wide** (all at Niagara Park), so the sentence explained a rule that almost never bites.
**Fixing a figure often deletes its caption.**

## Deriving the board (`_incl/board-live.php`)

Two rules, inherited from the retired importer:

- **TODAY TO 31 DECEMBER, not one reference month** (changed 21 Aug 2026 - see the next
  section for the measurements). A board answers "who holds a place". A month was the first
  attempt at that and it was wrong at both ends; the remaining year is the answer.
  **The reference month survives as the FALLBACK**, for the two cases with no future to read:
  a past year, and a Kidsoft outage. `board_reference_month()`: current year -> this month;
  **future year -> February**; past year -> June. February is safe for a future year -
  Tuggerah's 2027 read 143 children in Feb, Apr, Jun, Sep and Nov alike, because centres book
  the whole year at once. It also stays the provenance the screen reports for the NAMES.
  **A LINKED ROOM WITH NO FUTURE BOOKINGS IS EMPTY, AND MUST NOT FALL BACK** - otherwise a
  room the last child left in July keeps showing them until December.
- **Casual bookings are excluded** - a casual is a one-off day, not a standing place. This
  used to add "Occupancy still counts them elsewhere", and that sentence was the hole: see the
  hard rule in [CLAUDE.md](../../CLAUDE.md), **casual days are now excluded EVERYWHERE**.
  `bookings_year()` drops them before anything is tallied (its `casual` return key is gone),
  and so does `tenure_snapshot()`. **This raises the unsold place-day figures**, correctly: a
  casual child was making a cell read fuller across the year than its permanent roll ever was.

**Which rooms are real comes from `getUtilisation`, NOT `getRooms.Active`.** A room that
reports no operational positions is not operating. See [rooms-centres.md](rooms-centres.md).

### What may be SOLD is not what is OCCUPIED (`board_forward_occupancy()`)

**Every place is sold for the full year** (Broc, 13 Aug 2026). The team does not accept an
end date from a parent: a family enrols January to December, and the only way out is
end-of-care notice at least four weeks before the last day.

The reference month answers "who holds a place" wrongly in **both** directions:

- **Too optimistic** - a child booked to start after that month is invisible, so their seat
  reads free and could be promised to a second family.
- **Too pessimistic** - `occupied` is the UNION of the month, so a child who leaves on the
  5th and the one who replaces them on the 20th count as two children in one place. Terrigal
  Mighty Meteors read **15 children against a capacity of 14** for exactly this reason.

**A PEAK OVER SINGLE DATES HAS NEITHER PROBLEM AND NEEDS NO PAIRING HEURISTIC.** Ask what
the room looks like on each remaining date to 31 December and take the fullest. A leaver and
their replacement are never both there on any one date, so they count once; a December
starter is counted on the December dates. Nothing has to guess which arrival replaces which
departure - which matters, because at Niagara Park **every** future starter is a
replacement (Radiant Rainbows loses one on 3 Sep and gains one on 10 Sep, on four weekdays).

So the cell carries:

| figure | means |
|---|---|
| `occupied` | **the same number as `holding`.** A key, not a second definition - see below |
| `promised` | held by an offer - **in Kidsoft** since 15 Oct 2026, or a pending one made here before that; **`promised_children` names them** |
| `reserved` | kept for a child enrolled here who is not booked on this day; **`reserved_children` names them**. See [support-plans.md](support-plans.md) |
| `holding` | **the fullest the cell gets from today to December** - what a place is sold against |

`vacancies = capacity - holding - promised - held - reserved`, and **`over` and `pct` read the
same figure**, so `placed + free + promised + held + reserved` is capacity on every cell. `room_day_vacancies()` and `fill.php`
both read `holding` off the board, so the grid, the shortlist and the Offer button cannot
disagree - verified on 360 cells, 0 mismatches.

**`promised` COUNTS KIDSOFT'S OFFERS (15 Oct 2026).** Offers are sent in Kidsoft, and 0 of the 23
open there that morning had been made with this app's button - so the board was showing **70
promised place-days as free**. `board_load()` now adds every application whose Kidsoft status is in
`KIDSOFT_OPEN_OFFER` (top of `board.php`), from the room and days the import stores off
`OfferedDays` / `CareTypeMap.RoomID`, in the year the place starts (never behind this one). Not
twice: an application still holding an old-style pending offer is counted by that, and a day the
child is already booked into in that room is held by the booking. Each entry carries `kidsoft`,
`state` ("Offered in Kidsoft", "Form submitted", "Re-submit required") and an `expires` that is
**null once the family has answered** - a submitted form's ExpiryDate is no deadline. `offer_id` is
a string `k<application id>` there, because the room sheet groups on it and there is no `offers`
row. **The panel block is now "Offered, not enrolled yet"**: "waiting for an answer" was false
about a family whose form is in. Measured after: **22 families, 66 place-days held**; the missing
four are one Point Clare application with no child name, which the import skips.

### PLACED IS HOLDING, AND THE TEAM SETTLED IT (21 Aug 2026)

`occupied` counted the reference month and `holding` counted the places actually gone, and the
grid printed the first while `free` was derived from the second. **They were two numbers for
one cell and they disagreed on 22 of 180.** The rule that settles which is right came from
enrolments: **a spot is taken from the moment a child is enrolled into it, whatever their
start date.** So the number on the cell is the number of spots taken.

- **14 children were on the board in no room at all** - enrolled, holding 30 cells, starting
  after the month. Halle and Olive Dyer hold five days each at Kanwal from 28 September. Their
  places were already reserved in `free` (the peak sees them), so the grid was showing places
  gone with nobody occupying them, and the only trace of them anywhere was a badge.
- **14 cells counted one place twice**, and **four of them wore a red OVER CAPACITY badge that
  was fiction.** Terrigal Mighty Meteors holds exactly 15 on every Wednesday in August against
  a capacity of 15 and read 16, because Maggie Brady's last Wednesday is the 12th and Teihana
  Kapene-Belcher's first is the 19th. The group screen had Terrigal at **101% filled**.

Reading from TODAY rather than from the 1st fixes the second one on its own: a leaver whose
last day has gone simply drops out, and **nothing has to be paired with anything**. Group
fill went 101% -> 98% at Terrigal, and Kanwal's roll rose by 6 children who were always there.

**THE ROSTER CAN BE LONGER THAN THE FIGURE, AND THAT IS NOT A DISAGREEMENT.** `children` is
the union from today forward and `placed` is the peak, so where a leaver and their replacement
overlap for part of the year, 13 children pass through 12 places. **Do not "fix" this by
making the list the peak date's roster** - a cell whose peak is in December would then drop a
child who is in the room today and leaves in October, which is the fault this whole change
exists to remove. The two rows carry the dates that explain it instead.

**A child's own dates are printed only where they differ from the CELL's span**
(`board_span_edge()`), which is 69 rows out of 180 cells. Bounding by the calendar instead put
"to 23/12" on every child in every room - the day the centres shut for Christmas - and buried
the four that meant something.

**A BOOKING ON A DAY THE CENTRE IS SHUT HOLDS NOTHING (10 Nov 2026).** Kidsoft books children
inside the Christmas shutdown and does not know the centre is shut. Terrigal's Rhodes
Kapene-Belcher moves up to Shining Stars on a 2027 enrolment starting Mon 28/12/2026, so he was
booked on 28/12, 31/12 and 1/1/2027. The roster bound took 28/12 as the day that Monday runs to,
and **every child booked to the real last Monday was printed "to 21/12/2026"** (reported by Broc)
- and Rhodes sat on the 2026 roster "from 28/12/2026" for a room he never attends that year.
2027 was far worse: Niagara Park printed a "to" on **182** rows, Point Clare 49, Terrigal 97.

`bookings_on_trading_days()` (`occupancy.php`) drops a month's rows on any date with **fewer than a
quarter** of that month's busiest day's children, and all three board readers apply it:
`board_live_children()`, `board_forward_occupancy()`, `bookings_year()`. Measured over all seven
centres, every month of 2026 and 2027 (3,133 booked weekdays): every shutdown date is at or under
**6%** (never more than 3 children), every trading day at or over **73%**. Public holidays are
booked like any other day and are not touched. **The calendar cannot answer this** -
`getOpeningHours` reports Terrigal OPEN on Christmas Day; see [kidsoft-api.md](kidsoft-api.md).

Verified by diffing the full `cells_year()` payload for every centre, 2026 and 2027, before and
after: **no capacity, placed, free, promised, held, reserved, over or pct moved on any cell.** What
moved: dated rows (Niagara Park 2027 182 -> 0, Point Clare 49 -> 0, Terrigal 97 -> 1, Tuggerah
55 -> 8, Terrigal 2026 53 -> 26), two roster rows (Rhodes from 2026 Shining Stars Mon/Thu, Kylo Gow
- Fridays all 2027, one Monday on 27/12/2027 - from 2027 Little Angels Mon), the billed `min` that
read 1, and `free_days` +1 on those cells, which now count the shut day the way every other cell
already did. Rhodes's phantom arrival also stopped netting a real departure out of `coming_free`.

**A past year falls back to the reference month for both.** "What can I sell" is not a
question about a finished year, and `board_forward_occupancy()` returns nothing for one.

**Two kinds of row have to be added back by hand**, because the peak comes from Kidsoft
bookings and Kidsoft has never heard of either: **provisional** children (pencilled in ahead
of Kidsoft, or given a place by this app, which cannot write back) are added; children
**annotated `waitlist` / `no_position`** are subtracted.

### The over-capacity notice counts EVERY promise (15 Sep 2026)

`renderNotices()`'s "N room-days owe more places than they have" gated on `held`, so a cell over
capacity purely because a day was **held** for a child never fired it. Measured: hold a Tuesday in
Shining Stars Silver, whose Tuesday is **15 of 15**, and **16 places are spoken for against a
capacity of 15 with nothing on screen saying so** - the cell reads "Full 15/15, 1 held" and looks
entirely correct. It now sums `promised + held + reserved`, and `overWhy()` names whichever
promise actually caused it, because a banner explaining a held place as a re-enrolment problem
sends somebody to chase a family who has already answered.

**The cell's own `over` badge is deliberately NOT changed.** That one is about who is IN the room
and must keep reading the same figure as `placed` and `pct` - see "PLACED IS HOLDING" above. This
notice is the one place all three promises are added up. See
[support-plans.md](support-plans.md).

**No lookahead limit, however far off the start is.** Broc's explicit answer, and it follows
from the full-year rule: a place sold in December is sold. Worth asking, because a cell is
ONE number for a whole year - there is no way to read "5 free until December, then 4" - so
subtracting is all-or-nothing.

### FREE IS THE SUM OF THE CELLS, NEVER A SUBTRACTION AT THE TOTALS ROW

Fixed 15 Sep 2026, found while building the Dashboard's "Places to fill" report, whose room rows
have to add up to its centre column.

`board_load()` and `board_overview()` both derived a centre's free places by netting **capacity
against holding across every room at once**, which lets one room's OVER-capacity cell cancel
another room's free place. **A four-year-old cannot be sold a place in the babies room.**

Exactly one cell group-wide does this: **Terrigal's Mighty Meteors on Thursdays holds 16 children
against 15 operational positions** (all 16 booked all year, no partial spans - it is real, not an
artefact). It made the centre read **6 free while its own rooms summed to 7**, and Little Angels'
genuinely free Thursday place was the one being cancelled.

**`cells_year()` was already right** - it has always accumulated `vacancies` per cell - so the
Placement centre board said 7 while the Placement group overview and the Dashboard said 6. All
three now say 7, and the group total moved 114 -> 115. **`over` still reports the over-capacity
cell**; this only stops it eating a sellable place somewhere else.

### A declared departure beats an inferred one (`board_departures()`)

`coverage_map()` infers a departure from the shape of a child's bookings: they stop early, so
they must be leaving. Kidsoft states it outright - **`ExitDate` and `ExitReason` on the
CHILD record**, no `Options` flag needed. See [kidsoft-api.md](kidsoft-api.md), where the
entry claiming this was impossible has been corrected.

Measured over the 11 children carrying a future exit: **10 already have truncated bookings**,
so the inference finds them and the declaration only adds certainty and a reason. **THE
ELEVENTH IS THE POINT.** Tuggerah's Brodie Jackson leaves on 14 Aug and still holds 49
bookings running to 24 December, because notice is in and nobody has cleaned them up.
Inference reads a full-year child and reports nothing; the declaration frees **three cells at
Mighty Meteors Silver** four months early.

So the declaration moves **the date** in `cells_year()`, and bookings on or after an `ExitDate`
are dropped from the peak. Handover is not consulted: a declared exit is a child leaving the
service, never a room move. **There is still only ONE leaving figure**; a second badge would
report the same child twice.

**THE EARLIER DATE, AND THE SAME "EARLY" TEST (10 Nov 2026).** Until then the declaration
replaced both halves outright - the date became the `ExitDate` and the arrangement was always
"ending early". Both were wrong, and Terrigal's banner said **60 place-days come free later in
2026** when the true figure was **11**:

- **An exit after the last booked day revived a finished arrangement.** Kelly Schuurink stopped
  Mondays at Terrigal on 20 April and exits 22/11, so Shining Stars Monday read "+1 free from
  22/11/2026" for a place gone seven months - already in `free`. **14 day-places group-wide**, as
  far back as January (Valentino Franco, Harper Gough). The date is now `min(last booked, exit)`:
  Brodie's exit where bookings run past it, Kelly's April where they stopped first, and the
  "still ahead" filter drops the latter.
- **Every enrolment ends at the end of the year** (Broc): a child going to school, booked to the
  last week with an ExitDate of 27/12, is not a place coming free. The declared date now passes
  `coverage_ends_early()` in `drift.php` - **the same three-week edge `coverage_map()` gives the
  bookings**, so a declared leaver and one whose bookings simply stop are judged alike. 52
  day-places dropped; that includes children finishing in the week before the last (exit 20/12:
  Maisie Robinson, Morgan Rogers, Brodie Woods, Isla Mcnaughton), each worth four days of 2026 at
  most. 17 kept - real December departures with weeks to sell (Norah Preeth, Indi Cureton, Nora
  Siv).

Group-wide 2026 `leaving_total`: Terrigal 61 -> 12, Kanwal 14 -> 10, Tuggerah 9 -> 6, Wamberal
14 -> 4. Diffed over every cell of every centre for 2026 and 2027: only `leaving`, `leaves_on` (now `free_from`) and
`coming_free` moved. **Whether a child CONTINUES into next year is a different question** and is
answered on the Kidsoft ChildID - next year's roll, `reenrolment_holds()`, the child dialog's
"this year and next" - see [dashboard.md](dashboard.md).

### WHO is coming free, by name: leaving care or giving up a day (10 Nov 2026)

Reported by the team: *"is a child dropping one day, or are they leaving care all together and all
their days becoming free?"* Nothing on the board could answer it. The banner said "3 children
leaving, 3 keeping their place but giving up a day" and named nobody; the grid corner said "+1
Dec"; the roll printed a bare "to 7/12/2026" on Elliott Hatty (giving up Mon/Tue, keeps Wed/Thu)
and on Penelope Robinson (leaving) alike, each beside four green days. The engine already knew
every fact; it published counts. Broc chose the shape: **a named list on the banner, labels on the
roll, amber day boxes.**

- **`cells_end_kind()` (`cells.php`) is the one rule**, for the roll's labels, the banner list and
  the `leaving_children` / `dropping_children` counts: **leaving** = an ExitDate, or nothing else
  the child holds runs a week or more past this day; **dropping** = this room still has them on
  another day a week or more later; **moving** = only another room does. "A week" because a child
  leaving on a Friday ends their Monday four days earlier. It replaced "an ExitDate or EVERY
  arrangement ends early", which called Point Clare's Maisie Paterson "giving up a day" because her
  last Friday (11/12) was inside the three-week edge while her Thursday (10/12) was not - the only
  count it moved group-wide.
- **The payload:** `departures` (top level, one row per child - kind, Kidsoft's reason, and per
  room the days `ending`, the days kept, the days `refilled`); `cells[wd].departures` (who is
  behind that cell's `leaving`, soonest first, each with `keeps` and `refilled`); `ends`
  ({kind, room, reason}) on every roll row carrying a `to`; `cells[wd].free_months`.
- **Banner:** "Show who" opens the list - LEAVING CARE, then GIVING UP DAYS, one table so the
  columns line up. The name opens the child dialog, the room opens the cell. Reason inline for
  leavers ("free from 7/12/2026 - Gone to School"), "gives up Mon, Tue from 14/12/2026, keeps Wed,
  Thu" for the rest. Stays open across reloads within the page (`cfWhoOpen`).
- **Panel:** the names under the "+N free" badge, in the same words, each with its own date when
  more than one child is behind the badge. The roll's "to" date says what it is: **"leaving care,
  last day 3/12/2026"**, **"giving up this day, last day 7/12/2026"**, **"moving to Amazing
  Asteroids, last day here 6/01/2027"** (reason in the title). A provisional child keeps the bare
  date - there are no bookings to read.
- **Amber day boxes (`dayStrip`'s `ending`)** = the badge colour exactly; see
  [ui-conventions.md](ui-conventions.md). Only days that are COMING FREE are amber - Evie Wenham's
  roll row reads "leaving care, last day 14/12" with green days, because a place freed in the last
  week is not one (see the year-end rule above). A day given up whose cell an arrival has already
  covered is grey ("already re-filled"), never amber.
- **Month chips count each place in the month IT frees.** One entry per cell filed every place
  under the cell's soonest month: Niagara Park read "20 in Nov" where 8 were, and the Dec chip did
  not light Mighty Meteors, where most of that room's places free. `coming_free_cells` is now one
  entry per (cell, month), and a cell carries every month in `data-cf-month` (matched with `~=`).
  A cell an arrival only PARTLY covers keeps one entry at its soonest date - which leaver an
  arrival replaces is never guessed - so the chips still sum to `coming_free_total`.

Verified: a full payload diff for every centre, 2026 and 2027, moved no figure; every dated roll
row carries a label (0 without); driven in Chromium - Show who, a room link, a child link, both
month chips - with no console errors and no horizontal overflow on the panel.

**IT NEEDS ITS OWN TTL AND THE 36 h DEFAULT HID THE ONLY CASE IT EXISTS FOR.** Written first
on the shared `Children/getChildren` key, `board_departures()` returned nothing for Tuggerah
while Kidsoft held Brodie's exit - the cached children list predated the notice. It now
passes **3 h**, not the board's 15 minutes: notice is four weeks out, and the plain children
list is 2.4s for the largest centre. Do not shorten the shared entry instead; drift,
occupancy, day-requests and identity all read it.

**Cost is nil.** The month reads share `bookings_year()`'s cache keys, which
`cron-nightly.php --warm` fills nightly. **Do not give them a shorter TTL** - shared keys
would drag tenure, coverage and the year board onto a refetch cycle.

## Demand on a cell, in priority order

The cell panel's blocks are ordered by **policy**, and the ordering IS the priority
mechanism, because the two demand sources are different systems with no single ranked list
to sort. **Do not reorder these.**

1. **Who is enrolled on this day**
2. **Asked for this day** - the wishlist. **Existing families, and they come first.**
3. **Could take this day** - the external queue
4. **Also in this room, not on this day** - a **count only**, context

**FAMILIES ALREADY IN CARE HAVE PREFERENCE** - for extra days and for next year's
re-enrolment. See the no-upselling rule below.

**One thing sits ABOVE block 1 and is not part of that ordering:** the in-progress warning
(`in_progress_n`, see [work-layer.md](work-layer.md)). It qualifies the "N free" figure in
the subtitle and is not a source of demand. **Nothing else may be inserted there on the same
argument.**

### NO UPSELLING - this is policy, not squeamishness

`cell_extra_day_families()` once listed children already in a room who do not attend on a
weekday, ordered fewest-days-first as "the easiest sell", with guardian names and phone
numbers. **Little Miracles does not upsell** (Broc, 7 Aug 2026). It is now a **COUNT**: how
many children hold this room on other days, shown **last**, as context for judging whether a
day is genuinely sellable. No names, no numbers, no ordering by ease of sale.

**Demand from existing families reaches us because they ASK.** Broc: *"there are a lot of
instances where children are enrolled for a couple of days but the parents want more but
there isn't space available, so they will be on the wishlist."* The app does not go looking
for demand families are already volunteering.

### Kidsoft day requests (`day_requests_year()`)

Reads `Bookings/getWaitlistedBookings` for the **rest of the year** and attaches, to each
cell, the families still waiting (`Waitlisted` / `Notified`). Nothing is copied - Kidsoft
owns these rows and a copy would go stale the moment a family is granted their day.

**ALMOST ALL OF THIS ENDPOINT IS CASUAL AND NONE OF IT BELONGS HERE (13 Aug 2026).** This
block was built as though every row were a family asking for a standing extra day. Measured
across all seven centres and all of 2026:

- **63 of the 65** open future-dated rows request a **one-off CASUAL day**. **Zero** request
  a permanent one.
- Of the 46 rows the team actually saw, **34 asked for a SINGLE DATE**; 6 for two, 5 for
  three, 1 for four. Not one was a standing weekly pattern.

Every one of them made its cell **`grantable`** - see below, the loudest state on the grid --
for a day that could never be sold for the year. Reported from UAT: Kanwal, Little Angels,
Tuesday, "Theodore Burkill", who is permanently enrolled Mon+Wed all year and whose two rows
were raised through the parent portal on 5 Aug for Tue 15 and 22 Sep. Nothing stale, nothing
archived, and **not from the waitlist module at all**, which is why he was nowhere in it.

`casual_booking_types()` drops them. **A waitlisted booking carries a `BookingTypeID` but NO
`Casual` flag**, so the type is the only signal on the row; `Service/getBookingTypes` names
them and every casual type at every centre is `CAS`-prefixed. Classifying the 65 rows by NAME
gives the same 63 as classifying them by the `Casual` flag on real bookings of the same type --
**the two methods disagree on zero request rows.** **Do not use the name rule on a real
booking**: there the flag is authoritative and the two disagree on 34 types.

**It fails CLOSED, and that is the opposite of the archived-child rule below, on purpose.** An
unknown child is one row inside a population that is otherwise real demand; an unknown type
list would let through a population that is 97% casual. `cells_year()` already catches the
failure and renders the grid with no requests.

After the filter, group-wide open demand is **2 requests and 1 grantable cell**. That is the
honest figure, and the near-empty "Asked for this day" block is the true state of this
endpoint, not a bug.

- **Year-wide, not week-wide.** These used to be fetched for the week on screen, so a family
  asking for a Friday in November was invisible in July. Volume makes the whole year trivial:
  Tuggerah carries 17 open requests across six months.
- **Only from today onwards** - a request for a past day cannot be granted.
- **Grouped by (child, room, weekday)**, carrying every date asked for, so a standing request
  is one call to make rather than five identical rows.
- **A REQUEST OUTLIVES THE FAMILY, so the CHILD is checked, not the request.** Kidsoft
  **never cancels a waitlisted booking when a child leaves** - the row stays `Waitlisted`
  forever, so Kidsoft Enrol files the family under Archive while this endpoint serves them as
  live demand. Nothing on the row says so: the 2026 census returns exactly four statuses
  (`Waitlisted` 352, `Booked` 187, `Cancelled` 174, `Notified` 34) with no archive or expiry
  marker. The only signal is **`Children.Active`**. Measured: **26 of 386 open requests
  (6.7%)** belong to an archived child, 17 of them at Terrigal.
  `day_request_child_archived()` drops them BEFORE grouping, so a departed family can never
  be rung, counted in `requests_n`, or make a cell `grantable`.
  **An UNKNOWN child is still shown** - a child missing from our copy of `getChildren` is a
  gap in the copy, not a statement that the family left, and deleting real demand on a cache
  miss is the worse error.

A cell is **`grantable`** when it carries an open request AND has a place free. **That is the
highest-value state in the app** and is styled loudest. `grantable_total` drives a banner.

**The "Can be granted" summary tile is GONE (14 Oct 2026).** Asked: *"nobody waiting on a day -
what is this meant to represent? Maybe instead we could have how many positions are in
progress?"* Once casual requests stopped counting it read **0 on 15 of the 16 centre-year
boards**, its caption explaining an absence - a figure that is always zero is a border, the same
argument as the unsold-place-days alert below. The banner still fires the day it is true
(Tuggerah 2026, one family, verified after the change). The slot is now **In progress** - see
[work-layer.md](work-layer.md): `cells_year()`'s `enrolling`, the In progress screen's own rows
for this centre and year, shown as **places** (the unit of "Places free") over the families
behind them.

**The banner POINTS at the board rather than describing it (14 Aug 2026).** It used to end
"Look for the green dot", which is an instruction to go and search a grid of up to 55 cells for
a mark 8px across - one cell in 55 at Tuggerah today. It now carries a **Show** button that
lights those cells, the same gesture as the coming-free month chips, so the two notices on this
screen behave alike. See the shared `lit-btn` mechanism below.

### The INTERNAL day waitlist (`_incl/internal-requests.php`)

*"Someone is in Shining Stars Mon, Tues, and want a Wednesday when it becomes available.
There isn't an option for this on Kidsoft, that is for the permanent positions."* - Broc,
10 Aug 2026, from UAT.

Kidsoft has TWO waitlists and **neither holds a standing request from a child already in the
room**: `getWaitingList` is the new-enrolment queue, `getWaitlistedBookings` is
**date-specific** portal requests. A parent who **rings the centre** and says "any Wednesday,
whenever one opens" has raised nothing at all. So this is ours by the same test as offers and
work items: Kidsoft has no field for it, and nobody could type it anywhere else.

- **IT IS DEMAND, NOT WORK.** A wish is DORMANT - there is nothing to do until a Wednesday
  comes free. So it must **not** sit in `work_list()`, which is ordered by NEGLECT: an item
  nobody can action would nag forever and teach the team to ignore that list. It behaves
  exactly like a Kidsoft day request - lands on its cell, counts toward `requests_n` and
  `grantable`, same dot, same green banner - and when the place appears, **Start with
  this family** turns it into a `work_item`. (That button was called "Add to In progress"
  until 14 Aug 2026; see `actionRow()` above.)
- **GRANTED IS DECIDED BY KIDSOFT.** `internal_requests_close_met()` stamps a day the moment
  the derived board shows the child booked in that room on that weekday. **Day-level**: a
  family who got the Wednesday and also wanted the Thursday is still waiting.
  **Matched on `kidsoft_child_id` ONLY, never a name** - closing the wrong family's request
  says a place is spoken for when it is free, and an empty place nobody can see is the
  failure this app exists to prevent. A miss costs one manual close.
- **ONE FAMILY, ONE ROW.** `cell_merge_requests()` folds a wish into the Kidsoft request for
  the same (child, room, weekday). Two rows would put two calls on the list and count two
  against `grantable`. The Kidsoft row wins (it carries the real dates) and our note attaches
  to it - what they said on the phone belongs beside what they booked.
- **THE ATTRIBUTION BELONGS TO THE NOTE, NOT THE REQUEST.** It once read "Asked 9/08,
  recorded by `<staff>`" for a request the PARENT raised in the portal, crediting a staff
  member with a family's booking. Two facts, two lines.
- **THE EMPTY BOXES ON THE ROSTER ARE THE CONTROL.** Each child's Mon-Fri strip already shows
  the days they do not attend, so clicking one is the whole interaction - no picker, no
  second screen. A wished day shows an hourglass in the **same hue as the grid's "asked for
  this day" dot** (`--lm-req`).
  **SINCE 10 SEP 2026 THAT BOX CARRIES TWO OPPOSITE FACTS**, and the dialog it opens asks
  which: the family is WAITING for the day (demand, this list), or the centre is HOLDING it
  for them (supply, `day_holds`, off the free figure). One gesture on one square should not
  require knowing which of two controls you wanted first. **A day cannot be both** - each
  endpoint refuses the other's day by name. See [support-plans.md](support-plans.md).
- **A day they already attend is refused**, in the API as well as the form. The dropped days
  are **named back to the caller** - a day quietly swallowed is a day the team believes they
  recorded.
- **`requested_on` is when the PARENT asked, not when it was typed in.** A wish taken on the
  phone Monday and entered Thursday is a Monday wish, and that date is the queue order when
  two families want one place. `today` is published by `/api/cells` from the **Sydney** clock.
- **`internal_requests_close_met()` writes during a read**, from inside `cells_year()` rather
  than the endpoint. Deliberate: the sweep needs the board and `$board` is already in hand, so
  doing it at the endpoint would mean a second `board_load()` and two answers that could
  disagree inside one response.
- **This is not upselling**, and the shape of the UI is what keeps it that way: it records
  something a family ASKED for, the pool stays a roster rather than a ring-round list.

## THE CELL PANEL HAS ONE GRAMMAR (14 Aug 2026)

Reported from UAT: *"why is it a flow list of badges and not a proper table... it looks
terrible"*, and four more questions of the same kind. The panel is the app's most-used
surface and was its least designed - four blocks, four shapes, and **the question all
four are actually about written out in prose three different ways on one screen**
("3 days . Mon, Tue, Fri", "Currently Mon, Wed", "Wants Wed, Thu, Fri"). Reading it meant
parsing three comma lists against the weekday in the title.

**`dayStrip()` is now the whole vocabulary.** Mon-Fri as five boxes, in the roll, on a
request and on a candidate alike: **green = attends, ringed = the weekday this cell is
about, blue = asked for and has not got.** The blue is `--lm-req`, the grid's "asked for
this day" dot and the roster's hourglass - one fact keeps one colour on all three
screens.

**SINCE 3 SEP 2026 IT IS `window.dayStrip()` AND THE WAITLIST, SEARCH, THE DASHBOARD AND
IN PROGRESS DRAW THE SAME BOXES** - this panel's version was one of four, and it was the
one whose colours won. `cellDays()` in `board.js` is a thin wrapper that keeps the three
call sites reading in the panel's own terms. Two things changed here and nothing else:
the "asked for" days went from a pale `--lm-req-wash` to the solid `--lm-req` the Waitlist
now shares, and **the ringed day gained a white ring inside the purple one** - over that
solid blue, `#2e3192` on `#3764b6` all but vanished, measured on the candidate cards where
every day is blue and exactly one is ringed. See
[ui-conventions.md](ui-conventions.md). It is what makes a request card legible at a glance: *has Mon, Tue, Wed, Thu,
wants Fri* is four green boxes and a ringed blue one, not two sentences to compare.

The rest is one card shape (head / strip / contact / action) and the figures restated as
figures, because a comma-separated sentence of four numbers in the subtitle was the shape
this panel had everywhere.

### THE PLACE IS GONE BUT NOBODY IS ON THE ROLL: BLOCKS 1a AND 1b

Two ways a cell's places can be spoken for by somebody the roll does not show, and both
sit **under the roll and above every source of demand** - they are the same kind of fact as
the roll (the place is gone), and reading either as a shortlist would be exactly wrong.

| block | field | who |
|---|---|---|
| 1a **Offered, waiting for an answer** | `promised_children` | a family this app offered the day to; the offer holds it until they answer or it lapses |
| 1b **Waiting for re-enrolment** | `held_children` | a child in another room THIS year who must be re-offered the same days |
| 1c **Held, not booked** | `reserved_children` | a child enrolled in THIS room whose booking for this weekday has come off while an arrangement is worked through |

**1c DRAWS THE WHOLE WEEK, like 1a.** Name, then `dayStrip()` with the days they still attend in
green, every day held for them in amber, and this cell's day ringed. Held one day at a time it
put a child held out of Thursday AND Friday on two cells with no hint of the other, and left out
the first thing anybody asks when deciding whether a hold is still right: which days do they
still come? **An empty green set is a real answer** - a child removed from care pending an
improvement in behaviour holds no booking at all.

**1c IS LAST OF THE THREE AND THAT IS NOT ARBITRARY.** It is the only one where the family is
already in the room, so by the time the reader reaches it they have seen the name three lines
above, on the child's other days. It arrived 10 Sep 2026 with `day_holds` - see
[support-plans.md](support-plans.md), which carries the measurement that produced it: Tuggerah
Shining Stars Silver's Friday read **1 place free** and that place was Logan Camilleri's, on
open sale in the only Friday in the room anybody could have been offered.

**NAMED, NOT JUST COUNTED, is the rule both follow.** A cell reading "0 free" over a roll
that plainly does not fill it sends the reader hunting, and the count alone cannot be
checked. 1b was built that way on 8 Sep; **1a was still a badge reading "N held by a
pending offer" until 9 Sep** (Broc: *"could the held days for the ones we have offered be
visible and display like the enrolled children?"*). **The badge is gone** - a figure that
opens into the children behind it does not also need a caption, and two places saying the
same number is how they start disagreeing. The grid cell's own hover text keeps it, because
a grid has no room for a list.

1a is **the roll's own table**, `Child` and `Days held`, because that is what was asked for
and because the day strip is the panel's whole vocabulary. Two things differ from the roll
and both are deliberate:

- **The strip is `plain`, not `has`.** Green means *attends* and none of these children do
  yet - the offer may still lapse. `plain` marks the day without claiming anything about
  it, the same state the Dashboard's "coming free" strip uses. See `window.dayStrip()`.
- **The name is a `button.cp-name.cp-open`**, opening the family's application and contact
  log, exactly as the candidate cards do. Every action on one of these rows is a
  conversation with a family, and the last thing anybody said to them is behind the name.

**ONE ROW PER OFFER, NOT PER (ROOM, WEEKDAY).** The strip shows the whole set of days that
offer holds and rings the one the cell is about: a family offered Mon/Tue/Thu is one
promise, not three. The count and the list come off the same query in `board_load()`, so
they cannot disagree - verified on all 15 promised cells group-wide, `promised` equals
`count(promised_children)` on every one, and `placed + free + promised + held` is capacity
on each.

### And the children the board CANNOT show (15 Sep 2026)

A child removed from care pending an improvement in behaviour has no bookings, so the derived
board shows them in no room - and the place being kept for them had no visible owner on the
sheet. They now render as a tinted row under the offered families: padlocks on the held days,
`0` in the Days column, reason and start date underneath.

- **GATED ON AN OPEN HOLD**, never on "annotated and unbooked". An annotation row outlives a
  child's time in a room, so the looser test would resurrect children into rooms they left - the
  stale-copy failure the 870 deleted rows ended.
- **They are NOT in `r.placements`**, being gathered from the five cells' `reserved_children` the
  way the offered rows are gathered from `promised_children`. So the roster's child count, the
  Placed totals and `board_trading()` - which counts `count($r['placements'])` to decide whether
  a centre trades at all - are untouched.
- **The name is a plain span, not a `.place-chip`**: a chip opens the placement editor through
  `findPlacementByKey()`, which searches `r.placements`, so the click would silently do nothing.

See [support-plans.md](support-plans.md).

### The room roster carries the offered families too (9 Sep 2026)

The roster (`renderRoom()`) is the same panel showing one room across all five weekdays, and
it had the same hole: `r.placements` holds placements, an offer is not one, so a room could
read as sold on days nobody visible held. The offered families now sit **under the roll and
above the Placed totals**, in tinted rows, one row per offer.

- **A BOOKMARK, NOT A TICK.** A tick on that sheet means the child attends that day. None of
  these do yet, so the mark is `fa-bookmark` in a neutral ink - the roster's spelling of the
  panel's neutral day strip. The **four** marks now read: **tick = attends** (purple),
  **hourglass = asked for** (`--lm-req`), **bookmark = held by an offer** (`--lm-ink-2`),
  **padlock = held for this child and not booked** (`--lm-warn-deep`, 10 Sep 2026). The padlock
  is the only one that is neither working hue, deliberately: a place deliberately out of use is
  not a normal state of a weekday, and reading it as "attends" or as "wants" is exactly the
  wrong conclusion.
- **The empty days on those rows are NOT wishable.** A wish is recorded against a placement
  key and these families have none, so the cells are inert rather than a control that would
  fail.
- **The rows are tinted, not faded.** `.rs-noplace` is faded because those rows hold nothing;
  these are live families somebody has to ring, and fading them would say the opposite.
- **They are not in the Placed totals**, and a footnote says so - the same shape the
  `holdless` note already uses, for the same reason. The totals stay the grid's own figures
  reprinted; a second count is what this file spends its length avoiding.
- **`.rs-sub` is a BLOCK.** Inline, exactly one name in four wrapped ("Daniel Harrison
  smith") and the block went ragged - the near-fit a column width produces. Measured in a
  screenshot, not reasoned about.

Names open the family's application here as well, and the identity check was re-run on the
roster: clicking the fourth of four offered rows opened the fourth family.

**And the families PART-WAY THROUGH ENROLLING (14 Oct 2026)**, below those, for the same reason:
a room read as having places free while colleagues were ringing families about them. One row per
work item, gathered from the cells' `in_progress` (pre-offer, roomed items - `work_by_cell()`),
name opening the enrolment (`.work-open`, the handler the cell panel already binds), stage and
owner underneath, a **muted `fa-list-check`** on the days - the stage icon the Waitlist hangs on
an In progress family, quiet because nothing is held. **The five marks now read: tick = attends,
hourglass = asked for, bookmark = held by an offer, padlock = held, list-check = being enrolled
for, nothing held.** Not in the totals; footnoted.

## The green tones: staffing age bands (14 Oct 2026)

*"Can the rooms with joint ages, when you click on them, show the children different colours?
Sometimes once enough kids get to a certain age, we can adjust staffing requirements. On the
spreadsheets the confirmed children are green and to show the different ages their different
toning colours of green."*

**A confirmed child's chip is toned by their NSW ratio band** - under 2 (1:4), 2 to 3 (1:5), 3
and over (1:10) - lightest to darkest, on the roster and the cell panel's roll. The chip already
WAS the spreadsheet's green (`st-confirmed` came from the workbook legend), so the band is a
tone of it, never a new hue; the other statuses keep their identity colours untouched.

- **BY RATIO BAND, NOT BIRTH YEAR**, though the workbooks shaded by birth year (MM Pink, headed
  "D.O.B: 2021", tints its 2022 births). Measured: Tuggerah Shining Stars Silver is all 2023
  births - one green on the sheet - while 7 of its 22 are under 3 and 15 over. **16 of 35**
  occupied rooms group-wide held more than one band; all 900 children carry a DOB.
- **Every room, not only the mixed ones.** A chip whose tone depended on who else was in the room
  would say two things about one child. In a one-band room there is simply one tone.
- **Read ON A DAY: today for the running year, 1 January for a planned one, 31 December for a
  finished one** (`staffing_band_on()`). The band and `band_next_on` ("turns 3 on 12/11/2026",
  only when it falls inside the board's year) are computed in `board_load()` - they are dates.
  The hover says both, after any flag.
- **The tones are COUNTED as well**: rows at the TOP of the roster, under the day headings,
  one per band, per weekday; and one line over the cell panel's roll. Only in a room holding two
  or more bands - one band's count is Placed again. They count the ticks; measured equal to
  Placed on **173 of 175** cells in 2026 and every cell of a mixed room.
- **The tally moved to the top, and the children sort under it** (Broc, 14 Oct 2026: *"Can that
  be for every day, and be above the children. And sort children by age/colour, then by
  name?"*). At the foot, over the pinned Placed row, a room of 20 put the one figure the
  staffing call turns on below a scroll. **Roster and cell roll both sort youngest band first,
  then by name** (`sortByBand()`), so each count heads its own block of tones; a child with no
  band goes last.
- **The cell panel takes the ROOM's bands, not the day's.** It used to need two bands on that
  day, so it vanished on **7 of the 120** days in mixed rooms (Erina Little Angels Wed-Fri in
  2026; Terrigal Little Angels Tue and Amazing Asteroids Wed-Fri in 2027) - the very day
  somebody wants to read "no
  under-3s today". Now every day of a mixed room shows every band, a 0 included.
- **THE RAMP WAS WIDENED AFTER LOOKING.** Reusing `--lm-good-mid` for the middle band gave
  lightness steps of 0.072/0.074 - inside the 0.06 gate - and in a screenshot of Kanwal's Shining
  Stars the 2-to-3s and the 3-and-overs were separable only by hunting. Now `--lm-age-0..2`, L
  0.960 / 0.865 / 0.771. See [ui-conventions.md](ui-conventions.md).
- The thresholds and labels are written once, `STAFFING_AGE_BANDS` in `rooms.php`, and published
  as `age_bands` by `/api/cells`.

### What came out, and what measured it

- **"+ Add a child", here and on the roster.** Zero `placement.create` / `.update` /
  `.days` / `.status` rows in an audit log of **749**, and `placements` empty. It is a
  hand-typed copy of a roll Kidsoft owns - the work this app exists to delete - and
  accepting an offer already writes a real placement row (`api/waitlist.php`). **The
  editor is untouched**: clicking a name still annotates status, flags, notes and days,
  which is the half Kidsoft has no field for. The `openPlacement(room, null, wd)` create
  path in `api/board.php` is now unreachable from the UI and left in place deliberately.
- **"N place-days unsold in 20XX" as an amber alert.** Measured **95 of 95 cells** across
  Kanwal, Tuggerah and Terrigal: it fired on **all** of them. **An alert that is always on
  is a border, not an alert**, and its shape claimed something was wrong on cells where
  nothing was - the same argument this file already makes for keeping the fact off the
  grid's dot. The figure survives as one grey line in the year strip, which is the honest
  weight for a fact true of every cell.
- **"Someone waiting for Fri?"**, which closed the cell you were reading and rendered the
  room roster over it. Wishes are recorded where the empty days already are. A recorded
  wish still comes **back** to this panel, merged onto the Kidsoft row by
  `cell_merge_requests()`.
- **The three-line paragraph under "Also in this room".** The team knows their own rooms;
  it is the count and nothing else now. Still a count, still last - that part is policy.

### ONE ACTION AT A TIME (`actionRow()`)

"Offer" and "Add to In progress" sat side by side on every candidate, reading as a choice
between two ways of starting. **They are not alternatives:** `work_link_offer()` creates
the work item itself and advances it to `offered`, so Offer already contained the other
button. And `offered` is **stage five of eight** - offering a place to a family nobody
has rung skips the call and the tour, which is the job this app exists to leave time for.

So the row follows the family rather than offering a menu:

| state | row |
|---|---|
| no work item | **Start with this family** |
| any stage | stage badge + Update (log the call in there) |

**"Offer a place" WAS REMOVED 15 Oct 2026** (it sat on `contacted` .. `tour_done`), with the offer
dialog and its handler. Offers are sent in Kidsoft; the board reads them back, and the item moves to
`offered` by itself. The paragraphs below describe the button as it was.

**You never see Start and Offer at once**, and a full cell cannot grow an Offer button
because block 3 only renders on `free > 0`. Verified over all six stages by rewriting the
`/api/cells` response in the browser - `work_items` is empty, so no live row exercises
this branch.

**The button's WEIGHT carries the priority policy.** Block 2 (families already in care)
gets the filled button; block 3 (the external queue) gets the same action as an outline.
The ordering was documented and invisible; now it is on screen.

### The candidate's NAME opens their application and the contact log (14 Aug 2026)

A `button.cp-name` on the "Could take this day" cards, opening the same family modal the
Waitlist queue opens from the same handle - `_incl/waitlist-modal.php` and
`static/js/waitlist-modal.js`, included by both pages, the same split `work-modal.php`
already makes and for the same reason.

**Every action on that card is a conversation with a family** - ring them, offer the place,
start them enrolling - **and the one thing you could not do from here was find out what the
last person had already told them.** The log was on another screen, behind a search for a
name this card was already showing.

- **It fetches the application (`/api/waitlist?action=get`) rather than reading the card.**
  The card carries what a shortlist needs; the editor needs the row. One loading path is
  what stops the two screens showing different things about one family.
- **`get` returns the FORM's fields and nothing else.** Queue position, priority reasons,
  the sibling match and the open offer are computed across a whole centre by
  `waitlist_for_centre()` and none of them is editable.
- **It casts the four booleans that `waitlist_for_centre()` casts.** `waitlist_get()` hands
  back the raw row where they are the strings `"0"` and `"1"`, and **`"0"` is truthy in
  JS** - straight through, that ticks Re-enrolment, Staff's child and Sibling on every
  application in the queue.
- **A save reloads the board**, because a saved application changes what the card says --
  the days, the year, the room, and whether the family still fits this cell at all.
- **`button.cp-name` names the element on purpose.** `.cp-name` is also a plain span wherever a
  name opens nothing - now only a provisional child, who has no ChildID. The "Asked for this day"
  cards used to be that case, because the family is already enrolled and there is no
  application to open; since 6 Nov 2026 their names open the child dialog (below).

### Two faults this rebuild introduced and the checks that caught them

- **`.cp-act` was both a stat-tile state and the action row.** The action row's
  `display:flex` leaked through the single-class selector and the free figure ended up
  beside the word "free" while the tiles either side stayed stacked. **Two states of one
  panel must not share a class stem.** Renamed `.cp-actions`; invisible to a lint, obvious
  in a screenshot.
- **"Could take this day (3)" on a room where none of the three could.** `fill.php` returns
  near misses in the same list when nothing is eligible, so a raw `length` put a count
  beside a heading that contradicted it. The badge counts `eligible` only, and a
  no-eligible block says so in a line above the near misses.

**Clicking is still the only check that finds the identity bug.** Re-verified after this
rewrite: 15 chips / 15 distinct keys / 0 nulls in the panel, 21 / 21 on the roster, and
the editor opened the right child on all five names driven.

## ONE ENROLLED CHILD: the child dialog (6 Nov 2026)

`_incl/child-profile.php` -> `api/child.php` (GET, read-only) -> `static/js/child-modal.js`,
markup in `_incl/child-modal.php`. `window.openChild({centre_id, ks_id, year, room_id}, done)`.

**It is CHROME, not this page's** (same day): the search palette opens it from every screen, so
`layout_footer()` renders the markup and loads the script everywhere, and `placement.php` must
not include either again - two `#ch-modal`s and a Save bound twice. See search-identity.md.

**Why it exists.** Reported from UAT: Willow McAlister sat on Tuggerah Little Angels Pink,
Wednesday, under "Asked for this day" with a **Waitlisted** badge, could be found nowhere on the
Waitlist, and her name opened nothing. All three were correct and none was explained: she is
**enrolled** (Thursdays), the badge is Kidsoft's status on a **day request**
(`getWaitlistedBookings`), not the enrolment queue (`getWaitingList`), and the name was a plain
span because there was no application to open. The only dialog an enrolled child had was the
placement editor - name, DOB, five ticks, a note - and Broc: *"extremely limited in what it
actually shows, and as such doesn't get used much."*

**It REPLACES the placement editor for every child with a ChildID.** `openPerson()` in `board.js`
sends a linked chip here and only a provisional child to `#place-modal`. The flags and the note
are still edited, through the same `/api/board` `save_placement`, posting the row's own
`status` back so the endpoint has nothing to refuse or warn about. The editor's own rule still
holds: **only what is ours is editable** - see architecture.md.

**Every name in the cell panel with a ChildID opens it** (`childLink()` in `board.js`): the roll
and roster chips, "Asked for this day" (Kidsoft and internal), "Waiting for re-enrolment",
"Held, not booked", and the roster's held-out rows. Those last three were plain names for the
reason recorded beside them - `findPlacementByKey()` could not find a child who is not on
`r.placements` - and the dialog needs only the ChildID, so the reason is gone.

**NOTHING IN IT IS NEW DATA.** Each block is an engine another screen already runs, filtered to
one ChildID, so the dialog and the screen that owns a fact cannot disagree:

| Block | Source |
|---|---|
| This year and next: room, days, own start/finish | `board_load()` placements and `cells[wd].children[].from/to` |
| Leaving | `board_load()['exits']` (Kidsoft `ExitDate`) |
| Not booked, place held for re-enrolment | next year's `cells[wd].held_children` |
| Accepted / declined re-enrolment | `reenrolment_answers()`, name-matched as the re-enrolment screen does |
| School next year (MAY vs MUST) | `school_may_start()` / `school_must_start()` |
| Asking for | `day_requests_year()` (casual already dropped) + `internal_requests_by_child()` |
| Held days (on the room line, amber) | `day_holds_by_child()` - **supply, so never under "Asking for"** |
| Parents | `getChildren&Options=Guardian` + `guardians_by_id()`, enrolling/Mother/Father only |
| Brothers and sisters enrolled here | another active child linked to the **same parent GuardianID** |
| Their own applications, waiting siblings | the `siblings.php` household rule run backwards, split by `waitlist_same_child()` |
| In progress | `work_items` on the ChildID or on one of their own applications |
| Support plans | `plans_for_placements()` |

- **Guardian rows never reach the browser.** They carry billing, ATSI and court-order tags; the
  engine copies name, relationship, best number and email and nothing else.
- **A sibling who is enrolled AND waiting is one child.** Wyatt Smith (Niagara Park) holds
  Mon/Fri and has a 2028 application; listing both read as a fourth child. The application is
  folded onto the enrolled sibling with the same `waitlist_same_child()` test.
- **A supervisor sees another centre's application as its centre name only** (`user_can_centre()`
  per row), the rule search follows. The endpoint itself runs `api_require_centre()` first: a
  ChildID is per service, so the centre is part of who the child is.
- **Cost:** 0.3-0.8s warm (the two `board_load()` calls and the re-enrolment read dominate);
  a cold centre took 10.7s once. The dialog opens on a spinner.

**Verified 6 Nov 2026 by driving Chromium**, not by screenshot alone: Willow from her request
card; Nico Rodda McCarthy from "Held, not booked" (held Wednesday, BSP); Ollivander Smith from
his roll chip (declined 2027, own 2028 application, two enrolled and one waiting sibling), then
Wyatt opened in place from his name, then his application handed off to the family modal;
Chris Du (accepted re-enrolment, not booked, place held); Ezekiel Martinez. Save was clicked
twice with `/api/board` intercepted in the browser: one POST, the right fields, nothing written.
No console errors; no horizontal overflow at 390px.

## "Could take this day": a start more than 90 days after the place comes free, or in a later year, is not a candidate (6 Nov 2026)

Broc, from Tuggerah, Little Angels Pink, Wednesday 2027: the first name under "Could take this
day" was **Enzo Galli**, #3 in the queue, who does not start until **12/07/2027**. Start
enrolling him and the place stands empty from January to July. The same board offered 2027
places to Baby Penn, who starts 4/01/2028.

`cells_year()` passes `start_window => FILL_START_WINDOW_DAYS` (90) to `fill_suggestions()`,
and a family whose `required_from` is later than **the board's `from` + 90 days, or 31 December
of the board's year, whichever comes first,** is no longer eligible.

- **THE YEAR IS A HARD LINE (Broc, same day):** *"Very few (if any) children continue in the same
  room across years ... there should be a hard delimitation between the years."* Without the cap
  the 2026 board in November ran its 90 days to 4/02/2027 and offered Erina's 2026 places to
  families starting in February. A next-year starter is a near miss reading **"Starts in 2027"**,
  and that reason is printed ahead of the age one - the age a child will be next year is not why
  they cannot take this year's place.
- **A centre opening part-way through the year** has nothing free before it opens, so its places
  come free on `centre_year_start()`, not on the board's `from`.

- **Counted from when the PLACE comes free, not from today.** The board's `from` is today on this
  year's board, 1 January on next year's, or the date somebody picked (see `board_from()`). In
  November a 2027 place is empty from 1 January whoever takes it, so the 2027 cut is
  **1/04/2027**. Counting from today would have cut at 4/02/2027 and dropped families who leave
  the place empty for five weeks.
- **A late starter becomes a NEAR MISS, not a missing row.** The reason reads *"Starts more than
  90 days out"*, so where nobody else fits the panel still says who wants the day and when -
  the same rule as the age and sub-quota near misses above.
- **No start date is kept**, for the reason `waitlist_filter_start()` gives.
- **The card now prints `starts d/MM/yyyy`** (`starts_on`), only when the start is after the
  place comes free. A family who needed care from August and is still waiting can start the
  day it is offered.
- **The grid dot and "N on the waitlist could take this day" follow it**, because both read
  `eligible_count`.

**Measured on the day**, on cells the board shows as free: 2027, **141 of 638 cards** started
after 1/04/2027 and **79 of 146 shortlists changed**; no cell was left with nobody. 2026 (cut
31/12/2026, year cap included): **136 of 288 cards** were families starting in 2027, 66 of 77
shortlists changed, and **19 cells lost their dot** - Erina's whole queue starts in 2027.

**Marketing uses the same window** (`marketing_centre()` passes `start_window`; it replaced the
`starts_by` year-end cut). The board and Marketing must not disagree about who is waiting; see
[marketing.md](marketing.md).

**A card can also carry "Consider archiving"** - a family whose start date passed more than six
months ago and who is still waiting. A flag, never a reason to drop the card; see
`waitlist_stale()` in [waitlist.md](waitlist.md).

## A CENTRE THAT HAS NOT OPENED HAS NO BOARD (21 Sep 2026)

`/api/cells` tests `centre_not_open_yet()` **before anything else** and returns `not_open` with
nothing else in it. Measured before: Warnervale 2026 spent **7.2 seconds** deriving a grid of 385
free places under a headline reading *"20,097 un-occupied positions across 2026"* - a board, a
year of Kidsoft bookings, a coverage pass and a queue match, all describing a centre with no doors
open. After: **4ms and 222 bytes.**

`board.js` draws `window.notOpenState()` in place of the grid and empties the tiles, the banners
and the legend - they all describe a grid, and a row of zeroes over an empty page is the "0 of 0"
reading this deleted. **The Refresh button is hidden with them**: the endpoint returns before it
reaches Kidsoft, so it is a control that cannot change anything.

The group overview does the same one level up - `board_overview_unopened()` emits a null row
rather than deriving one, so the centre reads `--` across the week instead of 385 free place-days
it cannot sell. See [rooms-centres.md](rooms-centres.md) for the screen-by-screen rule.

**On both, "not open" is read AT THE FROM DATE** (Broc, 27 Oct 2026): `board_from($year, $from)`
is passed to `centre_not_open_yet()`, so a centre opening part-way through a year has no board
from any date before it opens and a full one from that day on. The panel then reads "No placement
board from <date>" and its button goes to `from=<opening day>`, the first date with a board.
**This deliberately hides children already booked to start on the opening day** when From is
earlier - the board answers "as at that date", and that date is before the doors open.

## Filling vacancies (`_incl/fill.php`)

`fill_suggestions()` pairs every free cell with the queue. A candidate must want that
weekday, be **age-eligible at the date they would start** (later of 1 Jan and their
`required_from` - children age through the year), and not be blocked by a sub-quota already
full that day. Eligible candidates are listed in queue order; when none fit, **up to three
near misses are shown with the reason**, because an empty shortlist otherwise reads as an
empty waitlist.

**Nothing is auto-assigned** - the output is a shortlist with an Offer button, and the offer
goes through `/api/waitlist` so it gets the same double-booking checks as everywhere else.

**Unknown capacity never counts as free**, so a room with no capacity for the year cannot
produce a false "can be granted".

**It counted `placements` rows until 11 Aug 2026** - the one place the 6 Aug change was not
applied. Measured at Tuggerah 2026 before the fix: the occupancy query returned **0 rows**, so
fill read **all 55 cells as free with 851 free place-days** against the board's 25 and 52. Two
consequences, the second dangerous:

- It built a shortlist against the queue for every full cell and threw it away.
- **Sub-quota blocking could not fire at all.** `$ages` came from the same dead query so
  `$used` was always 0. Nothing looked wrong only because `board.js` gates the block on the
  BOARD's `c.free`, and because `room_subquotas` holds 0 rows - but `fill.php`'s "blocked"
  reason is the **ONLY** place a sub-quota is ever enforced (the offer path checks vacancies
  alone). The first quota anybody configured would have been ignored and the Offer button
  would have appeared for a child it should have refused.

Both now come from `board_load()`, which `cells_year()` passes in via `$opts['board']` rather
than letting it derive a second one - **the shortlist and the board on screen cannot be
computed against different boards.**

### `starts_by`, and a `free` figure that does NOT match the board (18 Sep 2026)

Two things the Marketing screen found when it composed this function. See
[marketing.md](marketing.md).

**`$opts['starts_by']` is new, and ABSENT MEANS NO CUT** - the board's shortlist is unchanged.
It passes a date through to `waitlist_for_centre()`, whose `starts_by` filter already existed,
so a caller measuring one YEAR can drop the families who cannot start until a later one.
Measured group-wide: 31 of 615 open applications cannot start until 2028 or later, and counted
as demand for a 2026 place they close a real gap - **26 uncovered place-days across 9 cells
with the whole queue, 37 across 15 with the cut.** The shortlist deliberately does not take it:
a family who needs a place in 2028 is still a family to ring about a 2027 cell you are looking
at, and the board is where a human is making that judgement.

**`fill_suggestions()`'s own `free` is `capacity - holding - promised`. The board's `vacancies`
is `capacity - holding - promised - held - reserved`.** So 2027 group-wide fill reads **737
free place-days where the board reads 620** - the 137 held for children who have not answered
re-enrolment, plus 5 under a pending offer. The comment in `fill.php` says it MUST match
`board_load()`; it does not.

**Nothing live reads it.** `cells_year()` is the only caller and takes `candidates`,
`eligible_count` and `totals['queue']`; the cell panel gates the shortlist on `c.free`, which
is the board's figure. The effect today is a shortlist computed for cells whose places are all
held and then not shown. **But `free` also gates whether candidates are computed at all, and
any new caller that trusted it would advertise or offer a place being kept for a child** -
which is why Marketing takes the board's figure instead and says so at the call site.
**Deliberately not changed in that pass**: it sits in the engine the offer path depends on, and
it deserves its own change with the board, the panel and `/api/waitlist` looked at together.

## Occupancy and vacancy rules

- **Absences still count as occupied** - an absent child holds and pays for the place.
- **Placements with status `waitlist` or `no_position` do not consume a place.**
- **A DAY HELD FOR A CHILD WHO IS NOT BOOKED ON IT does not consume a place either -- it
  reserves one.** `day_holds`, subtracted through `reserved`. **A hold on a day the child IS
  booked on costs nothing**, and that guard lives in `board_load()` rather than waiting for
  `day_holds_close_booked()`, because the sweep runs from `cells_year()` and `board_load()`
  answers `fill.php`, the offer path and the Dashboard without going through it. Without the
  guard the place would be subtracted twice and the cell would read one short for ever.
- **A pending offer HOLDS its days** (`room_day_vacancies()`), so two staff cannot promise
  the same seat. Offers expire after 14 days by default; `offers_expire_due()` runs on load,
  releasing the days.
- **Over-capacity warns, never blocks.** Staff routinely pencil someone in while a departure
  is confirmed; blocking pushes them back to Excel.
- **`room_day_vacancies()` delegates to `board_load()`.** It used to COUNT `placements` rows;
  against an annotation store that reports every room as empty, and **it is the one function
  that must never be wrong** - it is what stops two staff promising one seat twice.

### 100% IS A CLAIM THAT NOTHING IS LEFT (`board_pct()`, 10 Sep 2026)

Reported from the board: *"Filled says 100%, but there is 1 place free."* Niagara Park 2026
holds **489 of 490** place-days, which is 99.796% and rounds straight up, so the summary tile
printed **100%** beside **Places free 1**, the group table gave the centre 100% with a 1 in its
Free column, and the Dashboard's "Filled (2026)" agreed with none of them.

Nothing was miscounted - `placed`, `free` and `capacity` were all right, and this is exactly the
size of gap that matters, because **one place-day is one child.** Rounding is only safe in the
middle: at the ends it swallows the whole quantity the figure exists to report.

**It was ONE cell group-wide, and that is the argument for fixing it rather than filing it.**
Scanned across all seven centres, both years, at every level the figure exists at - 14
centre-years, 70 weekday totals, 360 cells - Niagara Park 2026 is the **only** contradiction,
and no cell or weekday total produces one at all. A fault that fires on one screen in the group
is a fault nobody can learn to distrust; it will be read as fact every time.

**ONE DECIMAL PLACE, because a whole number cannot resolve one place-day.** Every fill
percentage in this app is rendered at centre or group level, where the denominator runs from
**293** (Terrigal) to **2,718** (the group): one place is 0.34% at the narrowest and 0.04% at
the widest. So a whole number spends the entire top of its range unable to tell full from
nearly full, which is the part of the range six of the seven centres live in. 99.8% says what
99% only implies.

| | 2026 | rounded | one decimal |
|---|---|---|---|
| Tuggerah | 798/850 | 94% | 93.9% |
| Kanwal | 359/395 | 91% | 90.9% |
| Wamberal | 384/395 | 97% | 97.2% |
| Terrigal | 287/293 | 98% | 98.0% |
| Point Clare | 290/295 | 98% | 98.3% |
| **Niagara Park** | **489/490** | **100%** | **99.8%** |

**The clamp is still needed at one decimal, it just bites later.** The group total of 2,717 of
2,718 place-days is 99.963% and still rounds to 100.0. So `board_pct()` holds the value at
**99.9 while any place is unsold** and at **0.1 while anybody is placed**, and rounds normally
in between. **Over capacity still reads over 100** (15/14 -> 107.1%) - `over` is a real state
and must not be rounded away either.

**`board_pct()` returns a NUMBER and `window.pctText()` renders it**, because `94 + '%'` is
`"94%"` in JS while PHP's 94.0 is not - concatenating the raw value would print a column where
some centres carry a decimal and others do not, and the whole ones would read as the rounded
figures this replaced. `toFixed(1)` makes every one the same shape and the same width;
`.ov-pct` and `.dm-v` are already `tabular-nums`, so the column still aligns.

**Every fill percentage goes through the pair**: the board's centre and group summary tiles, the
centre row on the overview, the Dashboard tile, and both Dashboard meters (hero and per-centre).
The re-enrolment round's "answered" percentage joined them - it sits one column from "To chase"
and could have said 100% with a family still to ring.

Two of the five are summed **in the browser** off totals the JS holds, which is why the rule
exists in **both languages** - fixing only the PHP would have left the tile that raised this
saying 100%. `pctText(null)` is `--`, not `0%`: no capacity recorded is not an empty room.

**The per-cell `pct` is not rendered anywhere** (the grid shows places free, not a percentage).
It goes through the same helper regardless, because the day it is displayed nobody will
re-derive this reasoning - but note a cell's denominator is ~25, where one place is 4% and a
decimal would be false precision. **If a cell-level percentage is ever put on screen, round it.**

## The grid itself

**One visual language across both views.** A cell is a **HEAT BLOCK**: colour = how full, one
big number = places going spare, raw figures underneath. `heat()` in `board.js` is shared by
the room x weekday grid AND the centre x weekday overview, because the screen you pick a
centre FROM must not describe fullness differently from the one you land on.

Five steps - full, 1-2, 3-6, 7+, over capacity - because one flat red for "any places free"
hid the difference between a room two light and one eight light, which is exactly the ranking
the eye should be doing. Every text/fill pair is **>= 5.3:1**. Band thresholds scale by unit
(`lowAt`): two free places in one room is a nudge, two across a whole centre is essentially
full.

**Row rules run the whole way across, name column included.** Do not put white gutter borders
on `.cell` - they paint over Bootstrap's row rule on the coloured cells while the room-name
`<th>` keeps its own, so the line visibly stops at the first heat block.

**THE HEAT CLASSES MUST BE DOUBLED: `.cell.cell-full`, never `.cell-full`.** See the CSS
specificity trap in [ui-conventions.md](ui-conventions.md) - it silently rendered the entire
heat map as a plain white table.

### Free per week is a FRACTION (16 Sep 2026)

Asked for as *"add in the right Free per week column the total they can have (e.g. 53/850)"*.
`freeCell()` in `board.js` draws it, and **both screens share it** - the centre grid's room rows and
totals row, and the All centres overview's centre rows and group row - because a column with one
heading must not read two ways depending on which screen you are on.

- **The denominator is the WEEK, not the day.** `weekCapacity()` adds the five cells up.
  `capacity_max` is the high-water mark of one day and is the line under the room name; using it
  here would have printed a fifth of the real total.
- **Same shape as the heat blocks above it**: figure in front, the total behind a slash at 62%
  opacity, tabular so the slashes line up. The numerator differs (free here, `placed` there) and the
  column heading is what says which - **two treatments for one fraction would have been the worse
  trade.**
- **A weekday with unknown capacity makes the whole week unknown**, and the count stands alone
  rather than being divided by a number nobody has. Same rule as the Dashboard's Filled column.
- The column went **5rem -> 6.5rem**; at 5rem "53/850" clipped.

### The third tile is "Un-occupied positions" (16 Sep 2026)

It read **"Unsold place-days"**, which is the app's word for the unit and not the team's; a
*position* is what Kidsoft calls it and what the centres call it. **The figure did not change.**

**All three places that report this number changed together** - the tile, the cell tooltip and the
cell panel's year line - because one fact under two names on one screen is how two figures start
disagreeing in a reader's head. `place-day` survives everywhere it is the app's own unit of work
(the hero on the Dashboard, `free_days`, this document); it is the tile's *label* that belonged to
the team.

### At most ONE dot per cell, and the rule is WHAT THE FACT IS

**The dot means SOMEBODY IS WAITING** - demand a person can act on by picking up the phone,
ranked **grantable (4%) > asked (16%) > candidate**.

A departure or a late start is **supply** - it changes what there is to sell, not who wants
it - so it would be a second, quieter meaning competing for one slot, and **the grantable dot
is the one thing on this grid that must never be displaced.** Unsold place-days stay out
because they are on **100%** of cells.

The rule used to be frequency, and that argument expired: filtered to today onwards,
`leaving` (16%) and `arriving` (20%) are now the same order as "a family asked" (16%). They
still get no dot, on the what-the-fact-is rule. **A late start no longer needs one anyway** -
since 21 Aug 2026 the child is IN the cell, counted in `placed`, with their start date on
their row in the panel. **If you are tempted to add a dot, ask which of the three it
outranks.** Everything else lives in the tooltip and the side panel.

### Supply gets the OTHER corner (14 Aug 2026)

`coming_free` now carries a `+N Mon` tag in the cell's **top-left**. That is not a second dot
competing for one slot - it is a second **channel**, and the grammar is worth keeping:

> **top-left = what there will be to sell. top-right = who is waiting for it.**

A cell can carry both and say two different things, which is the point: the demand dot is
untouched and the grantable ring still cannot be displaced by anything. Applying it needed the
`::after` trick above, precisely so it could coexist with the grantable ring rather than
overwrite it.

**The tag carries the month, not just the count**, for two reasons. `+2` alone is one glyph away
from the over-capacity cell's big `+2`, and the date is half the fact. The exact day stays in
the tooltip and the panel.

**It is the only mark here that is not a dot and not a colour, so it gets a legend entry** --
unlike the pulsing outline, which says nothing the green dot has not already said.

### Being filled gets the THIRD corner (14 Oct 2026)

A cell with `in_progress_n` (a family part-way through enrolling for that day, nothing held
yet - `work_by_cell()`) carries a **navy dot with a white ring, bottom-right**. Asked for in
UAT: *"a blue dot or something on the bottom right for rooms that have families in progress,
so everyone can see there are positions available, but they are in the process of filling."*
Until then the fact was in the tooltip only, and it is the one that stops two people ringing
about the same free place.

> **top-left = what there will be to sell. top-right = who is waiting for it.
> bottom-right = who is already being looked after.**

- **Not the top-right slot.** In progress is not somebody waiting, and on all 14 cells that
  carried it when it shipped (Wamberal 7, Kanwal 6, Terrigal 1, across 2026-27, out of 370)
  that slot was already taken - a candidate dot on every one. It would have displaced exactly
  the mark the one-dot rule protects.
- **Brand navy (`--bs-primary`), not `--lm-req`.** That blue already means "a family asked for
  this day" on the top-right dot, the day strip and the roster. **The white ring is what tells
  the two apart** at 8px, and it keeps the dot legible on the over-capacity red.
- **No count on the mark.** The number is in the tooltip and the panel's in-progress block.
- **The legend headings name their corner** ("Top right = somebody is waiting", "Top left = a
  place opens later", "Bottom right = being filled"): with two kinds of dot on one cell,
  "Dot" alone no longer said which.
- **Offered families do not get it.** An offer is `promised` and already out of `free`;
  `work_by_cell()` leaves the `offered` stage out for that reason.

## The All centres overview

**Plan-only** (`board_overview()`, no Kidsoft) and says so on screen, because reading a year
of bookings for all seven centres would be ~4 minutes cold. Open a centre to compare the plan
against what was actually booked.

**A centre that is shut must not be counted.** Warnervale's 385 place-days were **77% of
"places free" (500 vs 115)** and dragged group fill from **96% to 84%**, topping an
emptiest-first list. `board_trading()` - ONE implementation, in `board-live.php`, which
`dash_trading()` delegates to - excludes non-trading centres from the group row and the
tiles, and marks them with a reason in a `title=` rather than a sentence on screen.

**Rows are in the centre bar's order, top to bottom, with a centre not open AT THE FROM DATE
last** (Broc, 27 Oct 2026), not ranked. `board_overview()` sorts on state `unopened` - the rows
carrying the mark - and `board.js` does not re-sort. Whether a centre is open is read at the
board's From date, not the year (see the next section): from 1/09/2026 Erina is marked and last,
from 6/10/2026 it is counted in its bar position. Testing against TODAY was tried first and was
wrong - it sank a centre in a year it is open and counted. Emptiest-first before that
reshuffled the rows from year to year.

**A place held for a child the centre already has counts as a child** (27 Oct 2026).
Erina opened 6 Oct 2026 with no 2027 bookings in Kidsoft yet, so `board_trading()` found
nobody and its 2027 read `empty` - out of the totals here, on the Dashboard and on Marketing -
while **105 of its place-days were `held` for its own children awaiting re-enrolment**.
`held` and `reserved` now count; `promised` does not, since an offer goes to a queue family
and a centre that has never opened can have those.

## Two traps the derived board set

### 1. A ROW ID IS NOT AN IDENTITY, and `id` IS NULL NOW

`cells_year()` keyed its per-room `$byChild` map on `$ch['id']` - the `placements` row.
Derived children have `id = NULL` unless annotated, so **every child in a room collapsed into
a single null-keyed entry** holding all five weekdays, every day looked "already attended",
and `extra_day_total` silently went to **ZERO**. No error anywhere. Hidden until the 870
stale rows were deleted, because those rows were supplying ids. Now keyed on `'k' . ks_id`
or `'p' . id`.

**AND THE GREP HAD TO REACH THE JAVASCRIPT, WHICH IT DID NOT.** The same null took the click
handler on the board's chips and survived three months. A chip carried `data-id="<row>"`,
which serialises to the string `"null"`; jQuery's `.data()` converts it back to `null`, and
`findPlacement()` compared with `==`, so `null == null` matched **every unannotated child in
every room** and returned the last one walked. **Clicking any name in a cell or on the roster
opened the placement editor for a different child, titled with their name** - and since
`placements` is empty, that was every child on every board. No error, no console warning, and
the modal looks entirely correct once open.

Fixed the same shape in both languages: `placeKey(ksId, rowId)` in `board.js` builds the one
identity (`'k' + ChildID` / `'p' + our row`, the same key `cells_year()` builds), both chips
carry `data-key`, and `findPlacementByKey()` does the lookup. `findPlacement()` survives for
the delete path, which genuinely has a row id, and now **refuses a falsy one instead of
matching everything**. The **Remove button was rendering for a child we hold no row for**,
where clicking it hit `if (!id) return` and did nothing - it is hidden now, because "there is
nothing here to remove" is the true answer.

**When a field can become null, grep for every place it is used as an array key - in BOTH
languages.** The class of bug is invisible to a lint, a page render and a screenshot. Only
clicking finds it: verified by driving Chromium, 15 chips / 15 distinct keys in the cell
panel and 26 / 26 on the roster.

### 2. A CAPTION THAT FIRES ON THE CALENDAR AGES INTO A LIE

Both screens gained a "20XX is still being enrolled" banner fired on `year > date('Y')`,
which would have gone on saying it in December when the year was full.

- The grid states a **measured comparison** - "143 of this centre's 262 children are enrolled
  for 2027 so far" - against the same centre's CURRENT roll, and **stops on its own at 90%**.
  It no longer requires `enrolled > 0`, which had meant a centre that had processed
  **nothing** (Niagara Park: 92 acceptances, 0 bookings) got no note at all - the case most
  in need of one. A centre with no current roll (Warnervale) gets nothing.
- The Dashboard has **no banner at all**. The figure is fixed at the source: for a future year
  the tile is labelled **"Not yet committed"** rather than "Places to fill".

**Fix or withhold the figure; do not caption it.**

## When you change an engine's return shape

**Grep the JS for every field you remove.** `cells_week()` emitted `in_year` because a WEEK
could straddle New Year; `cells_year()` correctly stopped, but the click handler still read
`if (!cell.in_year) return;`, which became `!undefined` and **swallowed EVERY click on the
grid** - the cells advertised themselves as buttons, went nowhere, and there was no way at
all to see who was enrolled in a room on a day. Nothing in PHP or a page-render smoke test
catches this.

**What does:** dump `/api/cells` and diff its keys against every `data.*` / `r.*` / `cell.*`
the JS reads. Do that after any change to an engine's return shape.

**And when deleting a block by pattern, print what was removed and diff the section list
afterwards.** Removing the retired drift block from `board.js` used a non-greedy regex whose
closing `}` matched the END of the wishlist block, silently taking **the highest-priority
list in the app** with it. Nothing failed, no test caught it, and nobody noticed for a day.
`grep -nE "^    // [0-9]\."` over the panel would have shown the numbering jump from 2 to 4.
**Use an edit that fails loudly, never a blind `str.replace`** - a silently no-op'd
replacement is how `workButton()` came to be called and never defined.

## Mutations

Mutations go to the endpoint that owns their checks: `/api/board` (placements, wishes),
`/api/waitlist` (offers). **`/api/cells` and `/api/child` are read-only** - the child dialog's
Save goes to `/api/board`.

**RECOMPUTE server-side, never act on a list posted by the browser.** There is no bulk action
left in the app - the sibling flagger was the last and went 11 Aug 2026. The better lesson:
**a bulk button that reconciles a copy is usually a sign the copy should not exist.**
