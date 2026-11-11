# UI conventions: chrome, shared components, CSS traps, branding

Governs `_incl/layout.php`, `_incl/svg.php`, `static/css/app.css`, `static/js/app.js`, and every
page template.

## CSS traps that fail SILENTLY

**None of these is visible to a lint, a PHP parse check or a page-render smoke test. Only looking
at the rendered page finds them.**

### 1. Bootstrap out-specifies a single class on any table cell

`.table>:not(caption)>*>*` is specificity **(0,1,1)** and sets `background-color`, `color` AND
`box-shadow` on every cell. A single class is (0,1,0) and **loses**.

**So the whole heat map silently rendered as a plain white table** - on both the room grid and
the centre overview.

**Fix: double the class (`.cell.cell-full`, (0,2,0)) or include the element (`td.cell`,
`.grid-table th.grid-room`, which reaches (0,1,1) and then wins on source order because app.css
loads after Bootstrap).**

The same trap applies to anything styling a `<td>`/`<th>`.

### 2. A link styled as a button must name the element

app.css sets `a:hover{color:var(--lm-pink)}`, specificity **(0,1,1)**. A bare class is (0,1,0)
and loses, so a button declaring `color:#fff` **keeps white text at rest and turns brand PINK
under the pointer** - which no button on the real site does.

**Use `a.au-btn`, never `.au-btn`, and state the hover colour explicitly** so the result does not
depend on stylesheet order. Caught on the sign-in pages by Broc noticing it on screen.
`errors/error.php` was safe by accident - its `.err a` carries the element - which is exactly
why the two pages disagreed.

### 3. An unbalanced comment swallows the next block

Adding prose next to an existing comment's closing `*/` put it OUTSIDE the comment, which made it
invalid CSS - and the error **swallowed the `:root` block that declares `--lm-mark`**, so the
tenure bars, the waitlist group bars, the round stack and every legend swatch on three screens
silently rendered as nothing. No PHP lint, no page-render check and no console error catches it.

**After editing app.css, balance every `/* ... */`.** A stray closer or an unclosed opener is a
silent, wide blast radius, and **CSS has no syntax check here.**

### 4. `.table-scroll` MUST be `position: relative`

An `overflow:auto` box only clips a descendant it is the containing block **for**; an absolutely
positioned one whose containing block is further out is not clipped, and Chrome adds its static
position to the **page's** scroll area.

Bootstrap's `.visually-hidden` is `position:absolute`, and the Waitlist puts one inside the
icon-only button on **every** row - so row 118's hidden label sat 16,000px down the document.
Measured: `documentElement.scrollHeight` **16,792px against 964px of content**, plus 85px of
phantom horizontal overflow. `position:relative` returns it to 964.

**Nothing renders wrong at rest, so only scrolling the real page finds it. If you put an
icon-only button, a tooltip or anything absolutely positioned inside a scroll container, check
the document height afterwards.**

### 5. Sticky always creates a stacking context

Even at `z-index: auto`, unlike relative or absolute. A dropdown inside a `position: sticky` cell
is confined to that cell and every later row paints over it. See the Actions column section in
[waitlist.md](waitlist.md) - and note that **removing the z-index did not fix it, which is what
proved `sticky` itself was the cause.**

## Density is a SCREEN's decision, and it is opted into

Every screen shares one vertical rhythm - `main.px-4.py-4`, `.page-head` with 1rem under it, cards
with `mb-3`, `window.statTile` in a `row g-3` - and that is right for a page you scroll. **It is
wrong for the one table in this app that is worth seeing WHOLE**: the Placement grid is a fixed-size
matrix whose point is comparing rooms you are not looking at, and Tuggerah is eleven rooms plus a
totals row.

So there are three opt-in classes, all used by `placement.php` and by nothing else:

| class | on | what it does |
|---|---|---|
| `board-tight` | `<main>`, via `layout_header()`'s **`main_class`** option | `py-4` -> `.5rem`, and the gaps between page head, tiles, banners, grid and legend -> `.35-.5rem` |
| `tiles-tight` | `#board-summary` | label and figure share a line, the caption takes its own, and the label is a HEADING |
| `grid-tight` | the grid `<table>` | header/room padding and line-heights, and the totals row on one line. **NOT the cell height** - see below |

**`main_class` exists so one screen can ask without every other screen knowing.** It is the
alternative to either a global change (which would cost every other screen its air) or a second
copy of the shell.

**NOTHING IS HIDDEN TO BUY THE SPACE.** Every figure, caption, badge, dot and colour the grid had is
still on it. What came out was white space, plus one sentence the legend was already saying twelve
rows below it. Measured at 1920 wide:

| | original | squeezed | given back | **shipped** |
|---|---|---|---|---|
| the four tiles | 155px | 85px | 69px | **69px** |
| **a grid row** | **58px** | **44px** | **52px** | **76px** |
| the totals row | 58px | 49px | 35px | **35px** |
| the legend | 68px | 40px | 37px | **37px** |
| the page subtitle | 24px | 0 | 0 | **0** |
| the whole table | 748px | 569px | 646px | **908px** |
| rooms fully visible at 1920x1080 | - | 11 | 11 | **8 of 11** |

### THE CELL IS NOT PART OF THE DENSITY BUDGET, AND THIS COST THREE PASSES TO LEARN

> 58px -> squeezed to **44px** to fit eleven rooms -> *"the cells are now too squatted"* ->
> **52px** -> *"still need a LOT more height"* -> **76px**, taller than it ever was.

**A heat block is a BLOCK.** Its job is to be read as a panel of colour from across a desk, and a
panel whose text is jammed against the row rule above and below is not one - the number stops being
*in* anything. **That reading does not come back gradually as you add padding**, which is why 52px
was still wrong after 44px had been reported: it is a threshold, not a dial, and the height the grid
wants turned out to be MORE than the height it happened to ship with.

**So the whole-board fit lost, and that is the right way round.** Eleven rooms at 76px cannot share a
947px window with tiles, two banners and a legend whatever else is trimmed; the arithmetic was never
close. **Seeing eight rooms as proper heat blocks beats seeing eleven as strips** - the grid is
scanned by colour, and colour needs area. The rest of the screen stays tight, because that is what
buys the seventh and eighth rows; `height: 4.75rem` on the cell sets the block's SIZE rather than the
gap round whatever text is in it, so a "Full" cell and a "10 / 20/30" cell are the same panel.

**AND IT LIVES ON `td.cell`, NOT ON `.grid-tight`** (21 Sep 2026). It was inside the tightening
class for a month, and the tightening class is worn by the centre grid and by nothing else, so the
**group overview drew the same heat blocks at 57.5px** - and not even consistently, 57.5px on most
rows and 58px where the sub-line rendered differently, because nothing set a height at all. That is
squarely under the threshold this whole section is about, on the screen you pick a centre FROM.
The one rule in a class called "tight" that makes something BIGGER was always going to be missed by
the next table that wanted the same block. Both boards are 76px now, measured identical.

**Where to find vertical space, cheapest first, and where to stop:** dead gutter (Bootstrap's `gy` on
a single row of tiles, 8px of nothing) -> chrome padding and margins -> a line-height -> a row that
is a FOOTER rather than a cell (the totals row's two figures share a line above 1400px) -> **stop.
Anything inside a heat cell is not available.**

### TWO THINGS THAT LOOKED FREE AND WERE MEASURED NOT TO BE

- **Pairing the two year notices side by side.** Looked like 50px - two short sentences, one
  full-width row each. The coming-free banner carries a sentence AND a row of month chips, so at
  half width it wraps: **1920 saved 8px and 1600 went from 92px to 114px, WORSE than stacking.** A
  banner needs ~1000px to hold its line and no half of a 1920 screen has one. Reverted.
- **Collapsing the totals row unguarded.** At 1280 the weekday columns are ~120px, so "152/170
  18 free" fitted on one line in three columns and wrapped in two - **a totals row where some cells
  are one line and some are two reads as a rendering fault.** It is behind `min-width: 1400px`,
  which is also the only width where the 20px buys anything.

**The cell keeps its two lines and that is not negotiable.** The big figure says how many places are
going spare; the `12/12` under it is what says whether "1 free" is 1 of 12 or 1 of 150. `.tiles-tight`
needs `!important` on the padding and the colour for an honest reason - Bootstrap's spacing
utilities (which `window.statTile` emits) and `.text-muted` both carry one.

### The tile's first line is a HEADING, not a caption

Reported 16 Sep 2026: *"the first line needs to be larger and bolder - 'Can be granted' needs to be
bigger and bolder, but 'families who asked, place now free' can remain as is."* It was
`.text-muted.small`, **identical to the sentence underneath the figure**, so the tile read as a
number between two footnotes rather than as a labelled figure with a note. Now 1.05rem/600 in body
colour; the caption is untouched.

**It costs no height**, which is why it could be done at the same time as giving the cells theirs
back: the label shares its line with the 1.5rem figure, so that line is 30px whether the label is
14px or 17px.

**It is `.tiles-tight` only, i.e. the Placement board** - the screen it was asked for. Every other
screen keeps the standard tile.


## Long tables

`.table-scroll` (**not** Bootstrap's `.table-responsive`) with `.table-sticky` on the table.

**This pairing is load-bearing:** `position: sticky` does nothing inside `.table-responsive`,
because an overflow box that never scrolls pins the header to itself rather than to the viewport.

### The CSS cap is only a floor; `fitScrollers()` in `app.js` MEASURES

`calc(100vh - 21rem)` is a constant allowance for the chrome above the table, and **no constant is
right on every screen**: the Waitlist carried four tiles and a banner, so its card ended ~160px
BELOW the window and the page grew **a second scrollbar with almost no travel in it** - two
scrollbars a few pixels apart, and dragging the outer one takes the sticky header off screen,
which is the entire reason the table scrolls inside its card.

The helper reads where the box actually starts, subtracts whatever sits below it inside `<main>`,
and caps to the window. Rules worth keeping:

- **Two outcomes, never a stub.** Either the table ends at the bottom of the window and the page
  does not scroll at all, or - when what fits would be under 200px - it goes a full window tall
  and the page scroll that reaches it is worth making. **A table 13px past the bottom is the
  complaint, not a compromise.**
- **Measured off the DOCUMENT position**, never the viewport one, or a page already scrolled feeds
  its own scroll back in and the table grows on every run. **Floored to an integer**: a fractional
  height leaves a 1px scrollbar behind.
- **`.ts-nocap`, modals and offcanvases are skipped.** The Dashboard scrolls as a page
  deliberately; a dialog is `position:fixed`, so a document offset does not describe it (the cell
  panel's room roster sits inside `<main>` in the markup and would otherwise be caught).
- **No page has to call it**, which is the point - it runs on load, on resize and after every AJAX
  completes, because the offset is not final until the tiles, banners and rows land. **A screen
  that had to remember would be the stat tile's six-copies problem in another form.**

Measured after: **zero page scroll** on Placement, Waitlist, Rooms, Tenure and In progress at both
800px and 1100px viewports.

## SHARED COMPONENTS - do not write a sixth copy

**The stat tile had SIX implementations.** Four byte-identical copies in `dashboard.js` /
`waitlist.js` / `rooms.js` / `board.js` (two of them in one file, one per view), a fifth in
`tenure.js` that put the **number above the label** at 1.9rem bold with its own private severity
ramp, and a sixth written out longhand in `offers.php`. **So the same component looked like two
different components depending on which screen you were on, and four copies guaranteed any future
fix would land on some of them.**

| Component | JS | PHP |
|---|---|---|
| Stat tile | `window.statTile(label, value, hint, cls, col)` | `stat_tile()` |
| Figure row in a card | `window.statStrip(items)` | - |
| "Centre not open yet" | `window.notOpenState(n, opts)` | `centre_not_open_yet()` |
| Mark bar | `window.markBar(n, max, title, cls)` | - |
| Stacked mark | `window.markStack(segs, max)` | - |
| Legend | `window.markLegend(keys)` | - |
| Year stepper | `window.yearNav()` | `layout_year_nav()` |
| Spinner / empty | `window.spinner()`, `spinnerRow()`, `emptyState()` | - |
| Room icon | `window.roomIcon()` | `room_icon_sprite()` |
| "Also waiting at" chip | `window.waitlistCentreChip()`, `window.waitlistDaysDiffer()` | - |
| Offer urgency | `window.offerUrgency()` | `_incl/offer-policy.php` |
| Mon-Fri day strip | `window.dayStrip(opts)` | - |
| Tour question | `window.tourAskHtml(item)` | - |
| Datetime for an input | `window.toLocalInput(s)` | - |
| Contact channel mark | `window.channelIcon(channel)` | - |
| Kidsoft copy outcome | `window.copyTag(entry, compact)` | - |
| An administrator's Delete on a logged note | `window.commDeleteBtn(entry)`; the host listens for `comm:deleted` | - |
| Kidsoft's own comments | `window.kidsoftComments(list)` | `comment_kidsoft_existing()` |
| Staff email as a name | `window.staffName(email)` | - |
| Do Not Enrol badge / alert | `window.dneBadge(dne)`, `window.dneAlert(dne, confirm)` | - |
| "Consider archiving" badge (start date passed over 6 months ago, still waiting) | `window.staleBadge(stale)` | `waitlist_stale()` |
| A POST the server may refuse for Do Not Enrol, or for a matching tour enquiry | `window.postConfirmingDne(url, data)` | - |
| A yes/no or one-line question - **never `confirm()`/`prompt()`/`alert()`** | `window.confirmModal(o)` - see "No browser dialogs" | - |
| A child's name that opens their dialog, anywhere | `window.childName({centre_id, ks_id, name, cls})` - an `<a role="button">` so it wraps inside a sentence; plain text without a ChildID. One click handler, in `child-modal.js` | - |
| A queue reason with the children it names linked | `window.reasonHtml(parts, text)` | `priority_reason_parts()` |
| One enrolled child's dialog (chrome, every page) | `window.openChild({centre_id, ks_id, year, room_id}, done)`; `done` runs after a save - the search palette's fires `child:changed`, which the board listens for | `_incl/child-modal.php`, rendered by `layout_footer()` |
| Tooltip | any `title=` attribute (see below) | any `title=` attribute |

**The last two arrived on 10 Sep 2026 for the same reason as the rest**: the family modal on the
Waitlist started booking the tour that In progress owns, and both of those were sitting privately
inside `work-modal.js`. `tourAskHtml()` is the "it has been and gone, did they come?" prompt --
one appointment must not be asked about in two sets of words - and `toLocalInput()` reshapes a
stored Sydney datetime for `<input type="datetime-local">` **without going near `new Date()`**,
which is the rule the whole Dates block in `app.js` exists to hold.

**`notOpenState()` was written as a shared component on the day it got its SECOND caller.** The
Marketing screen had a one-off empty state for a centre that has not opened; Broc asked for it
across the project, and four screens now draw it - Placement, Tenure, the Dashboard and Marketing.
Two things are worth keeping about its shape. **The sentence is "No <what> for 2026."**, not
"There is no <what> to show", because the first reads correctly whether the noun is singular or
plural and the second gave us *"There is no rooms figures to show"* on the first pass. And **the
"Look at 2027 instead" button is drawn only when the caller passes an `href`** - Tenure passes
none, because its own stepper deliberately stops at the current year, and a button that navigates
somewhere it knows is empty would be the only one in the app.

**`statStrip()` came up the same way, the same day.** It was private to `dashboard.js`, where four
cards use it, and the Marketing screen's by-weekday cut is the same shape - a row of figures inside
a card - so a second copy would have been the mark-bar story again: same kind of card, same kind of
row, two sets of geometry. The geometry is the `.stat-strip` block in `app.css` and was already
shared; only the markup was not. `dashboard.js` **aliases** it rather than renaming four call sites.

**`channelIcon()` and `staffName()` arrived on 18 Sep 2026, and the first one was pulled up at
the moment a THIRD copy was about to be typed.** The five-entry channel map was already written
out in `waitlist-modal.js` and `support-plans.js`, and the work modal's new timeline is the third
surface to render a logged contact - so a note about a phone call would have carried the same
glyph in three places by coincidence rather than by rule. The stat tile reached six copies and
the mark bar three before either was moved here; this one stopped at two. `staffName()` is the
`@littlemiracles.com.au` trim that `progress.js` was doing inline: everybody is on that domain,
so the 26 characters never tell two people apart and do push a timeline line onto a second row.

**`copyTag()` came up the same way and matters more than the other two.** It says whether a
contact note also reached Kidsoft, and `createComment` has no update and no delete - so a screen
claiming a note travelled when it did not is a staff member who stops keeping the Kidsoft note
they still need. Two densities, one fact: `compact` is an icon alone for a list already spending
its width on the note, the default spells it out. **Same idea as `dayStrip`'s `wide`** - one
component rendering one thing at two sizes, rather than two components that drift, which is
exactly what `waitlist-modal.js` and `support-plans.js` had been doing.

**`kidsoftComments()` carries a warning with it.** Its `.ks-more` clamp toggle is bound **once,
on the document**, because the markup is now produced in three modals and the toggle belongs to
the markup rather than to any host. The two local handlers had to be deleted in the same change:
a second delegated handler fires on the same click and toggles the clamp straight back, so
sharing the renderer without removing them would have silently broken the two screens that
already worked. **A shared component that grows a shared handler must take the local ones with
it.**

Two languages, because Offers was the only screen with no page script at all. Tenure moved to
label-first (four modules agreed and it did not) and its tile severity goes through the shared
`.text-warning` / `.text-danger`, **so an amber figure there is the same amber as an amber figure
anywhere else.**

**The bars were the same story.** The Dashboard drew its marks at 7-9px with a **2px gap in the
surface colour** between touching fills, a 4px rounded **data end**, and the figure outside the
mark in tabular ink; the Waitlist's overlap card drew its own at 8px with segments butted together
inside a fully rounded pill and its own legend markup. Same app, same kind of card, same kind of
row, two different marks. Geometry now lives in the **MARKS** block of `app.css`; `dashboard.js`
**aliases** the three at the top rather than renaming 8 call sites.

- **`markStack(segs, max)` takes an optional max** because the two stacks measure differently: the
  Dashboard's fills its own width (part-to-whole of that centre's own denominator) while the
  Waitlist's is scaled to the **LONGEST QUEUE**, so bars stay comparable down the column. **Only
  the scaled form gets a track**, so a short bar reads as short rather than as missing.
- **`.mk-bar.wl-bar-shared` is doubled deliberately** - the pairs card is entirely about shared
  families, so its bar takes the pink; `.mk-bar` carries the default fill at the same (0,1,0)
  specificity, and leaving the winner to source order is the trap the heat classes document.
- **`tenure.js` WAS the third copy and is folded in now (21 Sep 2026).** Being a different form
  (two series, one row each) was never a reason to own a second height, radius, gap and
  min-width, and it had all four - so the Tenure All centres table and the Dashboard's ranked
  centre tables, which are the same shape, drew the same mark as two components. It stacks two
  `.mk-track`s in a `.tn-plot-cell` and keeps only the FILL (`.mk-bar.tn-out` / `.mk-bar.tn-in`,
  **doubled**, same rule as `.wl-bar-shared` above). `.tn-legend` / `.tn-key` / `.tn-sw` went with
  it - they were byte-identical to `.mk-legend` / `.mk-key` / `.mk-sw`, and the ladder's key on the
  centre view moved to the shared ones in the same change.
  **A zero series now draws no bar at all**, the rule `markBar()` already followed, so
  `.tn-plot-cell .mk-track` states the 7px height: an empty track is `line-height:0` and would
  otherwise collapse, dropping the pair off a shared baseline and taking the row's tooltip - which
  lives on the TRACK for exactly this reason - with it.

**THE DAY STRIP WAS THE SAME STORY IN THE CHANNEL THE TEAM READS MOST, and they reported it**
(3 Sep 2026: "the theme isn't consistent across all iterations of it"). Four implementations in
three colour languages:

| where | class | "asked for" | "attends" | box |
|---|---|---|---|---|
| Waitlist queue + register | `.wl-day` | purple fill | `--lm-good-wash`, no edge | 2.1rem, "Mon" |
| Placement cell panel | `.dbox` | `--lm-req-wash` **blue** | `--lm-good-wash` | 1.3rem, "M" |
| Dashboard / In progress / offer dialog | `.dstrip-d` | pale purple | - | 1.4rem, "M" |
| Search palette | `.sr-day` | purple fill | **the same purple** | 1.9rem, "Mon" |

- **`.dstrip` was declared TWICE**, with different gaps, and the later one silently won everywhere.
- **Search drew two opposite facts in one colour** - days a child HOLDS on its room and placement
  lines, days a family is WAITING ON on its queue lines - on one card, which is the common shape
  for a family adding a day.
- **"Asked for this day" is `--lm-req` BLUE everywhere now** (Broc's call). It already was on the
  grid's dot, the roster's hourglass and the wish note; only the Waitlist and Search moved. **That
  is what frees purple to mean "the day you are looking at"**, which is the ring the cell panel
  draws - before, purple meant both, on one strip.
- **The green went darker for the same report**: `--lm-good-mid` under a solid `--lm-good` edge,
  where it was a `--lm-good-wash` fill (**1.1:1 against the page**) and, for "attends but did not
  ask", a 1px `--lm-good-mid` ring. On a monitor with any less contrast the two greens and the page
  were one colour, which is exactly what "hard to see" meant.
- **THE GEOMETRY DID NOT MOVE, deliberately.** 1.3rem boxes .1rem apart is 6.9rem and five wide
  ones is 10.9rem, which are the numbers `.cp-dcol`'s 8.6rem and the "Days wanted" columns' 12rem
  and 13rem were measured against. Verified after: the queue table is 1037px in a 1030px box and
  the strip is 174.34px, both exactly what they were. **A 1.4rem box - which two of the four copies
  used - overflows both.**
- **The edges are INSET `box-shadow`, not borders**, which would grow a box whose column has no
  spare pixels, and not `outline`, which `:focus-visible` and the drift compare already use.
- **`.db-day` carries a WHITE ring inside the purple one.** A single purple inset ring was fine
  over the old pale wash and all but vanished over the solid blue that "asked for" now takes
  (`#2e3192` on `#3764b6`) - measured on the cell panel's candidate cards, where every day is blue
  and exactly one is ringed.
- **A NEUTRAL STATE IS A REAL STATE.** `plain` (grey fill, dark grey edge, caller-supplied
  `plainSaid` for the title) is for a strip that only names weekdays. The Dashboard's "Places
  coming free" first took the green, which was literally true - those ARE days the child holds -
  and read as *a place is held* on a card about one being given up. **A colour that answers a
  question the card is not asking is worse than no colour**, but it still has to be told apart
  from a day that is not in the set, which is why it keeps the edge. Its off boxes carry the bare
  day name too: "Monday - not asked for and not attending" answers nothing anybody asked there.
- **`held` IS A STATE OF ITS OWN AND NOT A REUSE OF `plain` (10 Sep 2026).** A day the centre is
  keeping for a child who is not booked on it - `day_holds`, most often a Behaviour Support Plan -
  takes `--lm-warn-mid` under a `--lm-warn-deep` edge. `plain` was the lazy fit and says nothing,
  and the roster **already** marks the same fact with an amber padlock: a neutral box on the
  Support plans screen and an amber lock on the roster would be two spellings of one thing on two
  screens, which is the fault this component was merged out of four copies to end. **One fact
  keeps one colour on every screen** is the rule, and amber is the only hue in this strip that is
  neither working colour, because a place deliberately out of use is not "attends" and not
  "wants". Measured: `--lm-warn-ink` is 6.4:1 on `--lm-warn-mid`.
  **A `held` STRIP IS NEUTRAL TOO** (15 Sep 2026). `neutral` was `o.plain !== undefined`, so a
  strip carrying held days still titled its off boxes "Monday - not asked for and not attending" -
  a reply to a waitlist question, on a Support plans card about a place being kept. Both marks
  say "this strip is not about demand", so both turn it off.
- **`ending` IS "COMING FREE" (10 Nov 2026)** - a day the child holds and is giving up, leaving
  care or dropping that day. **Broc's choice: the "+1 free" badge's colour exactly**
  (`.text-bg-warning`: `--lm-warn` under `#332200`, 7.9:1), so amber means "coming free" on the
  banner, the badge and the boxes alike. It sits beside `held` in the same hue on purpose and is
  told apart by WEIGHT: `held` is the pale `--lm-warn-mid` under a dark `--lm-warn-deep` edge,
  `ending` is the strong fill with no edge. It wins over `has` - the child does attend until then,
  but every strip carrying it is asking which place is going. On the Placement panel's roll the
  two can land a few rows apart (the roll above, "kept for" below), which is the case to look at
  if either is ever changed.
- **The caller says which set is which; `dayStrip()` infers nothing.** Only the Waitlist has an
  application to compare attendance against, so only it passes `extra`. Inferring it as "held and
  not asked for" reads correctly there and would have ringed every day on the room roll.
- **Removing a file's private `DAYS` map is not free**: `search.js` still assigned `res.weekdays`
  into it, which lints clean and threw `ReferenceError` on the first keystroke, killing `render()`.
  Caught by driving Chromium, not by a screenshot.

Also shared: `.page-head`, and a **fixed-corner toast stack** - toasts used to be prepended into
`<main>`, which shifted the grid down under the pointer mid-click.

### A CARD'S TITLE IS A MUTED UPPERCASE HEADING INSIDE THE BODY, NOT A `.card-header`

Bootstrap's `.card-header` draws a grey bar across the top of the card. Every screen here titles a
card with `<h2 class="h6 text-uppercase text-muted mb-3">` inside the `.card-body` (`card()` in
`dashboard.js` and `marketing.js` emit exactly that), or gives a table card no title at all - the
Placement overview is a bare `.card` wrapping a `.table-scroll`. **Where a card's title line also
carries a legend, the two share a
`d-flex flex-wrap justify-content-between align-items-end gap-2` row**, which is what the
Dashboard's Round progress does.

**Tenure's two cards were the only `.card-header`s in the app** and are gone (21 Sep 2026). The
All centres card is the same shape as the Dashboard's two ranked centre tables - one row per
centre, a mark, then figures - so it takes their shell and their `.dgrid`; the ladder card on a
centre followed in the same change. **`grep -rn 'class="card-header' --include=*.php --include=*.js`
should now return nothing outside `_incl/retired/`** (plain `card-header` still matches the two
comments in `tenure.php` that record this); **if it returns markup, a screen has grown a second way
of titling a card.**

**A DENSE TABLE INSIDE A `.card-body` LOSES 2rem, SO MEASURE BEFORE MOVING ONE.** The Placement
grid's card is deliberately full bleed (`.card > .table-scroll`, no body) because the grid's job
is to be seen whole. The tenure ladder is nearly as wide - ten columns - and was checked rather
than assumed: its `<thead>` is **998px at 1280** inside the body against **1030px** full bleed, and
its widest column set needs about 870, so it absorbed the padding with no clipping and no
horizontal scroller. **That margin is 130px, not nothing, but it is not much either** - a table
that gains a column belongs back at full bleed.

## Printing

**Only the Support plans report has print styles** (`support-plans-report.php`, see
[support-plans.md](support-plans.md)). They are scoped with `body:has(.spr-sheet)`; every other
screen prints as it always did. Two traps if another screen ever wants it:

- **A browser drops backgrounds when printing**, so a Bootstrap `text-bg-*` badge (white text on a
  fill) prints as nothing. The report sets `print-color-adjust: exact` on its sheet, and still
  puts a word on every coloured mark for a black and white printer.
- **Paper is about 718px wide**, so every `max-width:767.98px` rule in this file applies to a
  print. The card's facts grid folds to two columns there; the report overrides it back to four.

## `title=` IS A BOOTSTRAP TOOLTIP, EVERYWHERE - the attribute is the API

Every plain `title=` in the app is rendered by Bootstrap's tooltip. **Write `title="..."` and you
are done** - no `data-bs-toggle`, no per-page init, no call site that knows. The wiring is one
block in `static/js/app.js`, and the styling is the `TOOLTIPS` block in `app.css`.

**Claiming the attribute was the point.** The native tooltip took about a second to appear, looked
different in every browser and could not be styled. Converting the seventy-odd existing
attributes to `data-bs-toggle="tooltip"` would only have moved the problem: **the next `title=`
anybody wrote would silently get the slow one back.**

- **`title` stays the way you change the text too.** `.attr('title', ...)` works, because the
  tooltip text is read off the live attribute on every show. Three controls rely on this: the
  sidebar toggle, the year arrows (which name the year they go to) and the waitlist modal's day
  strip.
- **Newlines survive.** `\n` in a title renders as a line break (`white-space: pre-line`), which
  is what a placement cell's "heading plus a `- ` list" tooltip needs. **This is done in CSS, not
  by turning `html:true` on**, so every title stays escaped text and `esc()` at the call site
  still means what it says.
- **An empty title is not a tooltip** and never builds one.
- **An element that is already another Bootstrap component keeps the NATIVE tooltip.** Bootstrap
  stores one instance per element and **refuses a second outright**, so a tooltip on the user menu
  or the Waitlist's "More actions" button quietly took the slot its DROPDOWN needed and left the
  menu rebuilding itself from scratch on every click. Anything carrying `data-bs-toggle` is
  therefore excluded. **If you add a tooltip and its control stops working, this is why.**
- **A `disabled` control keeps the NATIVE tooltip** and there is no way around it: a disabled
  button or select fires no mouse events in any browser, so nothing can hover-trigger on it.
  Bootstrap's documented workaround is a hoverable wrapper span, which the year stepper cannot
  have - it is a `.btn-group`, where an extra element breaks the joined borders. Six controls are
  affected; they read the same, just slower.

### Three traps, all of which look like success

**1. Matching on `[title]` alone breaks the tooltip on its second use.** The first time Bootstrap
builds a tooltip for an element it moves the `title` to `data-bs-original-title` and **removes the
`title` attribute**, so anything selecting on `[title]` stops matching the very element it just
matched. With delegation that means no leave event: **the bubble hangs on screen after the pointer
walks away, and is never offered again.** It looks right because the first hover on every fresh
element is perfect. **Always match `'[title],[data-bs-original-title]'`.**

**2. Bootstrap's own `selector` delegation costs 9.9ms per hover on the group Waitlist.** The
`selector` option runs `body.querySelectorAll(selector)` on **every** mouseover and mouseout and
then walks the ancestor chain against the whole result, so its cost scales with how many titles
the page holds - about 4,500 on that screen, measured at **9.9ms per hover transition against
0.02ms** once it was replaced by jQuery delegation, which only matches the target's own ancestors.
**Do not "simplify" the init back to `new bootstrap.Tooltip(document.body, {selector})`.**

**3. `hide()` is not enough; the code disposes.** `hide()` returns early on a tooltip that has not
shown yet, so it cannot call off a show still sitting in the delay - and a click both lands inside
that delay and *focuses* the trigger, and `focus` is half the default trigger. That is how the
Waitlist name button left a tooltip floating over the dialog it had just opened. Disposing clears
the timer, the trigger state and the bubble together.

An instance therefore lives exactly as long as its bubble. **That is deliberate**: Bootstrap keeps
every instance it has ever built in a plain `Map` keyed by element, and every screen here replaces
whole tables with `.html()`, so instances for rows that no longer exist would pile up for the life
of the tab. A `MutationObserver` covers the other half - **a re-render that removes the row under
the pointer fires no `mouseleave`**, so without it the bubble would sit there pointing at nothing.

## THE CENTRE BAR: which centre is APP state, not a form field

Every working screen is scoped to one centre, so the centre lives in the chrome
(`layout_centre_bar()`), as a **pill strip** across the top of the content area - not as a
`<select>` repeated on five screens. **Pills rather than tabs precisely because the SCREENS live
in the sidebar**: two tab bars stacked on each other read as competing navigation.

**The bar also carries Report Issue, pinned to its right-hand end** (13 Oct 2026), so it renders
on every screen even with no tabs to show (`layout_centre_bar()` draws the row and the button,
`layout_centre_tabs()` the pills). `.cb-centres` has `min-width:0` so a long strip scrolls rather
than pushing the button off the end. See [issue-reports.md](issue-reports.md).

- **Tabs are ordinary links**, not JS state. The URL stays truthful, browser back works, a centre
  opens in a new tab, and each page resolves its centre once on the server. Page JS therefore
  reads a fixed `#init-centre`, **never a control**.
- `centre_current($centres, $allowAll)` resolves it: **`?centre=` wins, then the session, then the
  first centre.** `$allowAll` is true wherever a group-wide answer is real - which is now every
  screen but one. **The Dashboard does not call it at all** (it writes to the session and would
  silently move the year the Placement board is planning).
- **A SCREEN PICKING A CENTRE IS NOT THE USER PICKING ONE**, and conflating the two destroyed the
  "All centres" selection. With All centres chosen, opening Waitlist fell back to `sort_order` 1 --
  **Tuggerah** - which is correct on screen. But `layout_header()` built the sidebar links from
  the RESOLVED centre, so every link read `?centre=4`; clicking Placement honoured it (`?centre=`
  beats the session), showed Tuggerah and **wrote 4 back to the session**, so All centres could not
  be returned to without re-picking it.
  The fallback path now sets `centre_fallback()` and the sidebar carries `centre=0` when it is set,
  **while the centre BAR still uses the resolved id** because the active tab must match what is on
  screen. **Those are two different questions and one number cannot answer both.** The fallback has
  never written to the session and **must not start.**
- The choice **follows you between screens** (session), and nav and tab links also carry `?centre=`
  explicitly, so two browser tabs on two centres do not fight over the one session key.
- A screen with per-screen state that should survive a centre switch passes it as `params`. The
  year is added to every tab automatically.

## THE YEAR STICKS THE SAME WAY

`year_current($default)`: **`?year=` then session then default.**

- **The default is `board_default_year()`, which is simply THE CURRENT CALENDAR YEAR.** It used to
  be the latest year with capacities (the year being planned), which stopped being right when the
  board became derived: a future year is genuinely empty until each centre processes its
  re-enrolments, so **a fresh session landed on a board reading 0% everywhere.**
- **`year_list()` is the one stepper list**, replacing `board_all_years()` and the hand-rolled
  `centre_years()`. It returns a **CONTIGUOUS `range()`**: the naive union produced
  `[2026, 2027, 2029, 2030]` for a link pointing at 2029, and **since the stepper walks the array
  by INDEX, one press skipped straight over 2028 while its tooltip named the year it was skipping
  to.**
- **AN INVALID `?year=` IS IGNORED OUTRIGHT AND NEVER WRITTEN.** `year_current()` used to assign
  the fallback to `$y` and then write `$y` to the session unconditionally, so `?year=1999` did not
  merely fail to be remembered - it **REPLACED the user's chosen year with the default for the
  rest of the session.** Verified before the fix: pick 2027, follow a bad link, next navigation
  shows 2026. **One bad link cannot poison the session** - and note this property was documented
  here and was not true for weeks.
- **Every screen passes `board_default_year()`.** Before this the Board opened on 2027 and Rooms on
  `date('Y')` (2026), so **simply moving between two screens silently changed which year you were
  editing.**
- **Year steppers NAVIGATE, they do not re-fetch in place.** The year lives server-side, so a real
  page load is what keeps the session, the URL, the sidebar links and the centre tabs telling one
  story. The AJAX version had to hand-patch the centre tab hrefs afterwards and still left the
  sidebar pointing at the year you had just stepped away from.

## Branding

The app is used only by **Little Miracles**; lean into their brand, keep the app identity subtle.
**The app is "Little Miracles CRM"** (renamed from "Attendances" 29 Oct 2026): the full name in
`<title>`s, the manifest and anything read outside the app (emails, Outlook, Kidsoft comment
stamps); just "CRM" / "the CRM" where the Little Miracles brand is already on screen (the sidebar
tag, sentences in the UI). **Internal identifiers followed on 4 Nov 2026**: the address
(`crm.tuggerahservices.com.au`), folder, database, session cookie `lm_crm`, log prefixes, backup
and cron paths are all `crm` now. The one deliberate holdout is the Outlook transaction-id prefix
`lm-attendances-` in `tour-calendar.php`, an internal id nobody sees. Assets in **`_incl/littlemiracles_branding/`** (`BRAND.md`, colour + white logos
in Inline/Stacked/Icon).

**Inline the logo SVGs into pages** - never copy them to `/static` or serve them as URLs; the
`_incl` location is deliberate and nginx-blocked. Size via CSS (`.lm-logo svg{height:34px}`). Use
`svg_inline()` in `_incl/svg.php` (it refuses anything outside `_incl` or not an `.svg`).

**Exception: the browser favicon** (`/icon.svg`, `/favicon.ico` in web root) stays a URL ref - a
favicon cannot be an inline `<svg>`.

**`svg_inline()` SCOPES each file's ids and classes** (`svg_scope()`). These come out of
Illustrator carrying `id="Layer_2"` and a `<style>` block of `.cls-1 { fill: ... }` - inlined,
that block is a **GLOBAL stylesheet**, so `.cls-1` matched anything on the page and inlining a
second logo repeated both the ids and the selectors. The sidebar shows the wordmark or the icon
depending on whether it is collapsed, **so both are on the page at once and this stopped being
hypothetical.** The prefix derives from the file path, so it is stable between requests.

### Room marks: the five room families

`_incl/littlemiracles_branding/LM_rooms/*.svg` -> `ROOM_ICON_MAP` + `room_icon_sprite()` in
`_incl/svg.php` -> `window.roomIcon()` in `app.js`. Used by the Placement grid and cell panel,
Rooms, Tenure, the Dashboard's two lists, Waitlist, In progress, the work modal and the search
palette.

- **MATCHED ON A STEM, because the names are not consistent and never will be.** One family is
  "Amazing Asteroids" at Kanwal, "Amazing Asteroids Pink" and "Amazing **Asteroid** Purple"
  (singular) at Tuggerah, and "Amazing **Asterouds**" - Kidsoft's own typo - at Warnervale. The
  name is folded to letters only and tested for `aster` / `angel` / `meteor` / `rainbow` / `star`.
  Verified against all 40 real rooms: **39 match**, and the one that does not is Tuggerah's **Cool
  Comets**, which has no mark drawn and correctly gets none. **A room with no icon renders exactly
  as it did before**, so a centre opening a sixth family is a missing icon, never a broken row.
- **The map is declared once, in PHP, and published to the browser** on the sprite's `data-map`.
  **There is not one server-rendered room name in this app** - every screen builds them in JS --
  so a PHP twin of the matcher would be dead code today and the stat tile's six-copies problem
  tomorrow.
- **ONE SPRITE of `<symbol>`s, inlined by `layout_footer()`**, referenced with `<use>`. The assets
  never leave `_incl`, and a 40-room grid does not repeat five paths per row. The sprite is
  `position:absolute;width:0;height:0` - **checked against the `.table-scroll` trap above, it adds
  nothing to the document height.**
- **SIZED IN `em`, NOT PIXELS, and coloured `currentColor`.** Broc asked for text-character height -
  an inline emoji rather than a dedicated icon - so the same mark works in a 0.8rem table cell
  and in the cell panel's heading without a second rule, and **it is part of the name rather than a
  second thing to look at.**
- **It costs a little wrapping in narrow columns and that is the trade** - 57px on a 2,507px page.
- **The Placement grid's name column was widened to pay for it** (`.grid-room` 16rem -> 21rem),
  taken from **Free per week** (`.grid-free`, since **16 Sep 2026 6.5rem**, because the column now
  carries a fraction) rather than from the heat blocks. Its heading took the two-line shape the
  weekday columns use (**Free** over *per week*), because at any useful width "FREE/WEEK" broke
  **mid-word**. Both widths are stated explicitly since the table is `table-layout:fixed`, where
  the header row's widths are the only ones that count.
- **THE GROUP OVERVIEW'S TWO LABEL COLUMNS ADD UP TO THE GRID'S ONE** (21 Sep 2026):
  `.ov-name` **15rem** + `.ov-filled` **6rem** = `.grid-room` **21rem**, and both tables end in the
  same 6.5rem `.grid-free`. Under `table-layout:fixed` the five weekday columns split whatever the
  label columns leave, so that sum is what makes a heat block **the same width on both screens at
  every viewport** - 118px at 1280, 246px at 1920, measured on both. **Change one side and change
  the other.** Before it, `Filled` carried no width at all and took a full weekday column's share
  to hold "94.1%" (43px), which cost the overview's blocks 28px each at 1920.
- **`.ov-cap-link` wraps.** It held `white-space:nowrap` and the link it styles - *Set 2028
  capacity* - was **already clipped mid-word** at 1280 in the wider column it used to have, so the
  nowrap was hiding an overflow rather than preventing one. Two short lines sit inside the 76px row
  with space to spare.

### Palette

- **Purple `#2e3192`** (light `#e6e3fc`), **pink `#ed387d`** (light `#feeef4`). Bootstrap primary
  is remapped to purple, pink is the accent, via CSS vars in `static/css/app.css`.
- **Semantic status colours (green/amber/red) stay conventional** so applied/pending/exception read
  clearly.

**USE A TOKEN; DO NOT ADD A HEX.** app.css had grown to **96 distinct hex literals** and the
modules had visibly stopped matching each other. Almost none of it was a decision anybody made:
three greys within 0.006 of one lightness step, **fourteen** near-whites, **four** different amber
washes, and two different greens for the same "good" band on two heat scales sitting on the same
kind of grid. Consolidated to **58** by clustering in OKLCH and collapsing any group inside
dL 0.025 / dC 0.03 of one hue - **below the threshold at which a difference is visible, so it
changed the look of nothing and the consistency of everything.**

**26 tokens** carry the recurring ones: `--lm-ink`/`-2`/`-3`, `--lm-muted`/`-2`, `--lm-faint`/`-2`,
`--lm-surface`/`-2` (with `--lm-surface-head` between them for a `thead.table-light`, Broc's pick
2 Nov 2026 - it was `--lm-surface`, the page colour, so the header vanished), `--lm-line`/`-2`, and `--lm-{good,warn,danger}` each with `-text`, `-wash` and
a `-mid` step for the heat scales.

**THE RULE: every status has exactly THREE steps** - a **FILL** (marks and solids), a **TEXT**
(dark enough for small text on white AND on its own wash), and a **WASH** (tinted background), plus
`-mid` for the two heat scales only. If you need a colour it is one of these; if it genuinely is
not, **add a token with its measured contrast** so the next person inherits a name instead of
guessing.

**ONE HEAT SCALE, and it is shared.** The Placement grid and the Tenure ladder are the same idea on
the same kind of grid and use the same tokens for every band they have in common. The board keeps
one extra step (`#ef8f8f` for 7+ free) **because it has one more band, not because it disagrees.**

### The age tones are a RAMP of the confirmed green (14 Oct 2026)

`--lm-age-0..2` tone a confirmed chip by staffing age band - see
[placement-board.md](placement-board.md). An ordered scale, so one hue and a lightness ramp:
OKLCH **L 0.960 / 0.865 / 0.771**, hue 157-163, steps of 0.095. `-0` IS `--lm-good-wash`, so the
youngest band is the chip as it always looked; the other two are the new values. Ink on the darkest
7.0:1, the `--lm-good` edge still 2.5:1 on it.

**0.07 steps passed the gate and failed the screen.** The first cut reused `--lm-good-mid` for the
middle (L 0.888) and a new 0.814 for the oldest; on a real roster the two older bands - the pair a
Shining Stars room actually mixes - were told apart only by looking for it. **The gate is a floor,
not a target, when telling the steps apart is the whole job.** Applied as `.st-confirmed.ab-N`,
two classes so it beats `.st-confirmed` and so the `.place-key` legend square takes it too.

### Warning amber is TWO values

Bootstrap's `#ffc107` is 1.63:1 on white and unusable for either.

- **FILL `#ee9d00`** - buttons, badges, borders, meter fills, an icon on a tint. 2.22:1 on white
  so **it never carries text on white**; ink on top of it is `#332200` (6.9:1), **never white
  (2.2:1)**.
- **TEXT `#a55c00`** - 5.08:1 on white and **4.66:1 on the warm washes**, so it passes for small
  text everywhere in this app.

**WHAT THE OLD PAIR GOT WRONG WAS NOT THE HUE.** The text amber was `#8a6100` and read as mustard
at any size above ~20px (Broc's word for it was vomit, on the dashboard's attention counts). But
`#8a6100` and `#ee9d00` are almost the same hue (80 vs 72 deg). **What separates a healthy orange
from mustard is chroma and lightness**: `#8a6100` sat at OKLCH C 0.108, a dull dark yellow, where
`#a55c00` holds C 0.128 at hue 61 - 18% more colourful and 19 deg further from yellow - at the
same lightness.

**My first move was to strip the colour off the counts entirely, which was the wrong lever** - the
information was fine, the hex was not.

**If you change either value, check the text colour against the WASHES, not just against white.**
`#ad6200` was tried first and looked right on a card, but the chips and badges sit on `#fff4e0` /
`#fff3da` / `#fdf3df`, where it fell to 4.2:1, under the 4.5 small text needs. **The washes are the
binding surface.** `#ffeccc` is the exception and is *not* binding - it is the meter track, and
the meter's figure sits outside it.

**Info rows stay ink**: brand purple on a number reads as a link, and the chip beside it already
says which kind of row it is.

### PICK THE PALETTE FROM THE MARK'S JOB

**Identity, magnitude, order, or status** - getting that wrong is what produced a real clash, not
the hexes.

- **The purple/pink pair is for categories of EQUAL STANDING** - tenure's arrivals against
  departures, where neither is part of the other. **Tenure is now the only legitimate user.**
- **A highlighted SUBSET inside a total is a part-to-whole**, the same form as the round bar, so it
  takes the **one-hue lightness ramp** with the emphasised part dark. (`--lm-ramp-2` for the
  remainder rather than `-3`, because there the pale step is the MAJORITY of the bar and sits on an
  equally pale track.) I had filed the waitlist overlap as an IDENTITY pair; **that was wrong.**
- **An ORDERED scale takes the ramp, never two categorical hues.** Built first with the pair plus a
  neutral grey, the round-progress stack came out in the **OPPOSITE colour order to the waitlist's
  overlap bar** - two thin horizontal stacked bars, same app, same two hues, reversed. Broc spotted
  it on screen. There was a reason and it was not good enough: the waitlist anchors its subset at
  the baseline (pink first) while a progress bar fills from the left with what is DONE (purple
  first). **Nothing about that is visible to somebody reading the two screens.**
  The real error was upstream: **answered -> yet to answer -> school age and no answer is an
  ORDERED scale**, running from resolved to unresolved-and-unknown, and swapping two of them changes
  the meaning. So `--lm-ramp-1..3` (`#363b82 -> #6f76bd -> #adb2dc`), **dark = done**.

**Ordinal gates, measured:** monotone L (0.389 / 0.591 / 0.774), adjacent dL +0.202 and +0.183
(floor 0.06), light end **2.06:1** on white (floor 2.0), hue spread 3.2 deg. Three other ramps were
rejected on the light-end gate alone (1.69-1.85:1).

**The checks were ported to PHP** (`palette-check` / `ramp-check` in the scratchpad) because the
data-viz validator is a Node script and **this box has no Node by constraint.** The port reproduces
the purple/pink CVD figures recorded above, which is what says it matches the standard.

### The status vocabulary is a RING, and its hues are identity

The nine `.st-*` placement statuses came from the workbook legend, so **the team learned them as
colours and the hues must not move.**

Their **lightness and chroma** were the problem - L ranged 0.46-0.74 and C 0.05-0.21, which is what
made them read as a rainbow rather than a set. The five whose hue is pure identity (teal, magenta,
violet, blue, yellow-green) are now derived at **L 0.514 / C 0.138**, the mean of the three that are
ALSO the app's semantic colours; those three (`confirmed` green, `offered` red, `waitlist` amber)
**keep their exact token value**, because normalising them would have given the app a second green
and a second red. Washes sit at the canonical wash band (L 0.954 / C 0.025). **Every key clears
4.8:1 on its own wash.**

**They are reachable** - `PLACEMENT_STATUS` drives a real `<select>` on the placement editor - so
**do not delete them as dead CSS just because `placements` is empty today.**

## Fonts and layout decisions

- **Outfit** for all UI (body + headings). Fuzzy Bubbles / Fredoka are **logo-only** (already baked
  into the logo SVGs). All woff2 in `static/css/fonts`.
- **Left SIDEBAR** (not a top bar) - full-colour LM **inline** logo, the screens as icon+label,
  user menu pinned at the bottom.
- **Restrained tone** - no mascots or blobs in working views; clean tables and cards.
- The school `icon.svg` is **favicon only**. **Light theme only.**
- **The sidebar collapses to a 4.25rem icon rail**, and that state rides in a **COOKIE, not
  localStorage**, so `layout_header()` can put `.sb-collapsed` on `.app-shell` **before the page
  paints**. Read after load it would flash the full-width sidebar on every navigation, and **the
  CSP rules out the inline `<script>`** that would normally run pre-paint. Collapsed, the wordmark
  swaps for `Icon.svg`.
- **Do not put `overflow:hidden` on `.app-sidebar`.** The user menu is a Bootstrap dropdown inside
  it and would be swallowed once the rail is 4rem wide. The logo clipping that tempts you to add it
  came from flex-shrink on `.sb-brand` in a column flex box, and is **fixed at the source with
  `flex:0 0 auto`.**
- **`$homeQ` in `layout_header()` is the logo's own query string** - year only, **no centre**. Do
  not rebuild it from `$centreQ`; see the routing rules in the root file for why going home from
  Tuggerah used to land on Tuggerah.

## A save happens ONCE: `app.js` holds whatever started it (2 Nov 2026)

**Measured, 29 Oct 2026:** Emily's Save in the work modal went twice in the same second. Every
link of the chain ran twice (`update`, `advance`, the note), so one phone note was filed twice and
the parent's tour invite was written twice. Five save handlers had no guard of their own: the
work modal, the board's wish/hold form, the Book a tour form and its "use this application"
button, and the Support plans hold form. Every guarded one had been guarded **by hand**, which is
how the work modal was missed.

**"One save at a time" in `app.js` now does it for every form and button, including ones not
written yet.** When a POST goes out while a form's submit or a button's click is being handled,
that form or button is **held**: its buttons are disabled and a second submit or click is
swallowed until jQuery's `ajaxStop`. It waits for `ajaxStop` rather than the first reply because a
save is often a chain, and each link starts inside the last one's `.done()` before jQuery's count
of open requests reaches zero. It listens in the **capture phase** (`addEventListener(..., true)`,
which jQuery cannot do), because a handler jQuery binds to the form itself runs before anything
delegated to the document could stop it.

- **Do not write a per-handler double-click guard in new code.** The existing ones are harmless:
  a button already disabled when the write goes out is never touched, so `users.js`, which
  disables Add for reasons of its own, keeps control of it.
- **A save refused in the browser holds nothing.** The hold starts at the POST, not at the click,
  so the work modal's "both or neither" refusal leaves Save usable.
- **The gap: a POST that is not sent while the click is still being handled** (from a
  `setTimeout`, or after a GET comes back) is not tied to the button. **A question asked in a
  dialog is the case that comes up**, and `confirmModal()` closes it: its answer is a click of
  its own, so its form carries `holdAlso`, the button that asked, and that is held with it. See
  "No browser dialogs" below. A `.then()` on its promise reopens the gap - jQuery 3 runs `.then()`
  from a timer - so callers use `.done()`.
- **The server has a backstop for the one write that cannot be undone:** `/api/comms` `add` treats
  the same words from the same person on the same family within 30 seconds as the note already
  filed. See "A double-clicked Save files one note" in [work-layer.md](work-layer.md).

**Verified 2 Nov 2026** in Chromium on a throwaway Warnervale item with no tour, no application
and internal notes only. A double-click, Enter pressed twice, and three clicks in one script each
sent `update`, `advance` and the note **once** and filed one note. A save the modal refused
before sending left Save usable, and the next save went through. A double-click on Book a tour
made one item. Re-run with the old `app.js` substituted in the browser, the double-click sent
all three requests twice, and the backstop alone kept it to one note. Dashboard, Placement,
Waitlist, Support plans and Marketing load with no console errors. The test rows and their audit
rows were removed.

## No browser dialogs: `confirmModal()` (10 Nov 2026)

**Never `window.alert()`, `confirm()` or `prompt()`.** Broc, 10 Nov 2026: they do not work well
in some of the browsers the team uses. Every question is a Bootstrap modal; a message is a
`toast()`. **This must come back empty** (`$a.alert('close')` in `toast()` is Bootstrap's alert
plugin, not the browser's, and comments are fine):

```
grep -rnE "\b(window\.)?(alert|confirm|prompt)\s*\(" --include=*.js --include=*.php . \
  | grep -v static/js/lib/ | grep -v _incl/retired/ | grep -vF "\$a.alert('close')" | grep -v "//\|\*"
```

(`-F` on the `toast()` line is not optional: `grep` on this box is ugrep, which reads `$` as an
anchor anywhere in a pattern, so without it that line is never excluded.)

Nine were replaced the day the rule arrived: archiving a work item (the one `prompt()`), deleting
a note, the Do Not Enrol and "Same child?" questions in `postConfirmingDne()`, withdrawing an
offer, deleting an application, deleting a sub-quota, removing a child from the board, and
removing somebody's access.

```js
confirmModal({ title: 'Delete this application?', body: 'Its offers and history go too.',
               ok: 'Delete', danger: true })
  .done(function () { $.post(...); });
```

`title` is the question; `body` is plain text, a blank line between paragraphs; `ok` names the
action rather than saying OK; `danger` reds the button for anything that deletes or takes away;
`input: {label, value, maxlength}` is a line of text and `.done()` gets it trimmed; `choose` drops
the X, Esc and click-outside, for a question whose Cancel is itself an answer ("Different child"
still starts the family). The full list is on the function.

**It stacks over an open dialog, which nothing else here does** - see `handOff()` in
`child-modal.js`, where a whole dialog replaces another instead. A confirmation belongs on top of
the dialog it is asking about, and Bootstrap 5.3.8 gets three things wrong when one modal opens
over another. **Each was measured in Chromium, 10 Nov 2026:**

- **Layers.** Every modal is 1055 and every backdrop 1050, so the second backdrop lands BEHIND
  the first dialog. `.modal-ask` is 1065 and its backdrop (`.modal-ask-backdrop`, tagged
  straight after `show()`, which appends it synchronously) is 1060. Under the tooltips and toasts.
- **The focus trap.** Bootstrap keeps ONE trap on the document and the newest dialog takes it;
  nothing hands it back. After the question closed, Tab walked out of the work modal into the page
  behind. The dialog underneath gets its trap re-armed on the way out - through its private
  `_focustrap`, which is why the pinned version matters.
- **Focus.** Returned to the button that asked **only if it had keyboard focus**
  (`:focus-visible`). After a mouse click it pinned that button's tooltip on screen, because
  focus is half a tooltip's trigger: measured on a note's Delete, whose "Delete this note" sat
  there until the next click.
- **NOT wrong: the scroll lock.** Bootstrap's ScrollBarHelper saves the inline `overflow` it finds
  and restores it, so closing the question leaves the page under the dialog still locked.

**An answer is given while its click is still being handled** - OK from the form's submit,
anything else from `hide.bs.modal`, which Bootstrap fires inside the Cancel or X click - and that
is what lets "One save at a time" hold the button. `hidden.bs.modal` would be after the fade,
from a timer, and "Different child" sends a write. **An OK pressed before the dialog has finished
appearing is ignored**: Bootstrap ignores `hide()` mid-fade, so it would answer and leave the box
up, and an OK that early is the tail of a double-click.

**Verified 10 Nov 2026** with every non-GET request stubbed in the browser, so nothing reached the
database or Kidsoft: each of the nine opened, cancelled and confirmed, posting exactly what the
native one had; Archive over the work modal, Delete over the family modal and Remove over User
access left the dialog underneath open, locked and trapped; Esc closed only the question; the
button that asked was disabled while a delayed reply was out; the Do Not Enrol answer chained
into two "Same child?" questions in order. All nine screens load with no console errors.

## Looking at the pages IS a check

Six screens captured signed-in for the first time (7 Aug 2026) found five real defects **that no
lint, parse check or engine test could see**: the doubled heat classes, a blank `fa-regular` glyph,
the pink button hover, a wrapping Actions column, and a clipped six-column table. Plus
`/api/rooms` returning HTTP 500 because `year_list()` had replaced `centre_years()` there and
`api/rooms.php` never required `board.php` - **every PHP file linted clean, because a missing
function is a runtime error.**

**One thing that looked like a bug and was not:** on a `--full` capture the sticky totals row
overlaps the last data row. `.table-scroll .grid-totals` is `position:sticky;bottom:0` by design; a
full-page shot expands the page but not the inner scroll container. **Check at a real viewport
before chasing it.**

**And a screenshot is not enough for anything behind a click.** The `data-key` bug (see
[placement-board.md](placement-board.md)) opened the wrong child's editor on every board in the app
and looks entirely correct once the modal is open. Drive Chromium and click.

See [auth-security-ops.md](auth-security-ops.md) for how to get a signed-in session for `webshot`.

## Static assets are versioned by mtime, and that is a BUG FIX not a nicety

`asset()` in `layout.php` puts the file's own `filemtime()` on every stylesheet and script:
`/static/js/waitlist.js?v=1788413055`. `layout_header()` and `layout_footer()` are the only
places assets are referenced, so every screen gets it for nothing.

**NOTHING SETS `Cache-Control` ON `/static`.** nginx sends `Last-Modified` and `ETag` and no
freshness header at all, so a browser falls back to RFC 9111 **heuristic** freshness - about a
tenth of the file's age - and does not revalidate for that long. `waitlist.js` had not changed
since 20 Aug, so a browser that loaded the queue on the morning of 3 Sep cached it for roughly
**a day and a half** and served the old copy through every ordinary reload.

**This is worth a section because of how it presents.** The fix was live, correct at the origin,
correct through Cloudflare (`cf-cache-status: DYNAMIC`, the edge caches none of this), and
correct in a headless render - and still invisible to the person who reported the bug. Two
rounds of "still not fixed" were spent on the JS before the cause was found, and the second
round produced a real second bug, so the reports were not wrong, just not about the cache.
**A front-end change nobody can see is indistinguishable from one that does not work.**

**The URL is the cache key**, so a changed file can never be served stale, and an unchanged one
still gets the long free ride. `try_files` ignores the query string; nginx serves the file
exactly as before. `asset()` falls through to the bare path if the file cannot be stat'd - a
missing version is cosmetic, a fatal there would take out every page.
