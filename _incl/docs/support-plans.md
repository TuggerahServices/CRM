# Support plans, their steps, and holding a day

Governs `support-plans.php`, `support-plans-report.php`, `api/support-plans.php`, `static/js/support-plans.js`,
`_incl/support-plans.php`, `_incl/support-plan-steps.php`, `_incl/support-plan-calendar.php` (booked
days in the centre's Outlook calendar), `_incl/day-holds.php`, the
`day_holds`, `support_plans` and `support_plan_steps` tables, the two flags
`placements.has_behaviour_plan` and `placements.has_individual_plan`, and the
"Held, not booked" block on the cell panel.

**The source documents live in `_incl/docs/support_plans/`** - `BSP.pdf`, `ISP.pdf` and Emily
Kite's two summaries. They are the policy; this file is what the tool does about it. If the
policy changes, replace those files AND `plan_steps_bsp()` / `plan_steps_isp()` together.

Both halves arrived in one message from a centre, 10 Sep 2026:

> *"Tuggerah SS Silver has a boy who is on a Behaviour Support Plan but currently has dropped
> the Friday. I have ticked the Behaviour option but how do we hold his Friday? The bookings
> are removed as the parents do pay for those days as it is while we try and work together.
> Also could we change it to "Behaviour Support Plan". Just so all the educators use the right
> language with families? Maybe even a tab on the right for children on BSP might be good for
> administrators to monitor. Or even for supervisors to make sure they are following through all
> the steps and communicating with families correctly."*

---

## THE FRIDAY WAS ON SALE, AND THAT IS THE MEASUREMENT

Taken the morning it was reported, before anything was built. **Logan Camilleri** holds
Tuggerah **Shining Stars Silver** on Mon/Tue/Wed and is flagged for a plan. That room's 2026
board read:

| weekday | capacity | placed | free |
|---|---|---|---|
| Mon | 15 | 14 | 1 |
| Tue | 15 | 15 | 0 |
| Wed | 15 | 15 | 0 |
| Thu | 15 | 15 | 0 |
| **Fri** | **15** | **14** | **1** |

**That Friday place is his.** Every other cell in the room was full, so it was also the only
Friday in that room anybody could have been offered - exactly the cell `fill_suggestions()`
puts at the top of a shortlist and the cell the green "somebody asked for this day" state
fires on. The tool was actively pointing the team at a place it had to keep.

**Kidsoft cannot answer this and never will.** The only record of a place being held is the
booking, and the booking is precisely what has been removed. So it is ours by the same test as
offers, work items and the internal day waitlist: nobody could type it anywhere else.

## A HOLD IS SUPPLY. A WISH IS DEMAND. THEY ARE OPPOSITE FACTS ON ONE BOX

`internal_day_requests` records a family **asking** for a day they do not have. It makes a cell
`grantable` - the loudest state on the grid - and puts a phone call on somebody's list.

A hold is the reverse: **this day is already spoken for.** It comes off the free figure the way
a pending offer and a re-enrolment hold do, and it never puts a dot on the grid, because the dot
means somebody is waiting and nobody is.

Both are recorded from the **same empty box on the room roster**, because it is one gesture on
one square and the team should not have to know which of two controls they wanted before
clicking. One dialog, two options, each spelled out in full rather than named - the consequence
is the part that matters and a label alone would not carry it.

**A day cannot be both.** `hold_save` refuses a day the family is recorded as waiting for and
`wish_save` refuses a day that is being held, each naming the day. They would otherwise be
counted in two directions on one cell.

**ONE DIALOG MEANS ONE SET OF FIELD NAMES.** The form is serialised whole and the browser does
not rename a field because a radio moved, so the wish's `note` and `requested_on` inputs are
what arrive at `hold_save`, which maps them to `reason` and `held_from` at the door. This was
NOT mapped on the first attempt and **the failure was silent**: the hold saved, reported
success, and quietly carried no reason and a held-from of today. Caught by reading the rendered
card, not by any test.

## NOTHING EXPIRES

Broc's call, 10 Sep 2026, choosing between three options put to him: **a hold runs until a person
releases it.** No review date, nothing chasing anybody, no clock.

The one automatic close is **REALITY** - the rule `work_autoclose()` and
`internal_requests_close_met()` already follow. When the derived board shows the child booked in
that room on that weekday again, the arrangement is over and `day_holds_close_booked()` stamps
it, **day-level**: a child back on Thursday and not yet on Friday is half back.

**CORRECTNESS DOES NOT WAIT FOR THAT SWEEP.** `board_load()` ignores a hold on a cell the child
is booked on, whatever the row says, because the sweep runs from `cells_year()` and
`board_load()` answers `fill.php`, the offer path and the Dashboard without ever going through
it. Left to the sweep alone, the place would be subtracted twice - once as a booking in the
peak and once as a hold - and the cell would read one short for ever. Verified: a hold written
on a day the child attends costs nothing on the board immediately, and the sweep releases it and
is idempotent on a second run.

`support_plans_list()` runs the same sweep for the boards it loads, because it reads `day_holds`
directly and would otherwise be the one screen in the app still showing a finished hold.

**THE HOLD HAS NO CLOCK AND THE BSP HAS ONE. That is not a contradiction, and a later reader
should not "fix" it.** A hold has no clock because nobody knows when a family will come back, so
any date the tool set would be invented. A BSP has one because the policy wrote it down: week 5
review, week 6 meeting, week 9 review, week 10 decision. The rule in both cases is the same - the
tool never makes up a date, and it never throws away one the team gave it.

## The figures

The cell gained `reserved` and `reserved_children`, counted apart from `promised` (a pending
offer) and `held` (a re-enrolment hold) **because they are three different promises to three
different people**: an offer can be withdrawn, a re-enrolment hold lapses on its own clock, and
this one ends only when somebody says so or the booking comes back.

```
vacancies = capacity - holding - promised - held - reserved
```

and so `placed + free + promised + held + reserved` is capacity on every cell. **Verified across
all seven centres, both years, 360 cells: 0 mismatches** other than the eleven pre-existing
2027 cells where re-enrolment holds already exceed capacity, which the panel reports in words
("this day is N over - they cannot all keep it") and which carry `reserved = 0`.

`room_day_vacancies()` subtracts it too. That function **is the one that must never be wrong** -
it is what stops two staff promising one seat - so the grid, the shortlist and the Offer button
cannot disagree about a held day.

## Where it shows

- **The grid cell.** No new dot: supply is not demand, and the grantable dot must never be
  displaced. The heat block's sub-line reads `Full 14/15, 1 held`, because **a word and a
  fraction contradicting each other is how a fraction stops being trusted** - the same reason
  `heat()` already carried a `kept` suffix for re-enrolment holds. The tooltip says which:
  *"1 place held for a child enrolled here and not booked on this day"*.
- **The cell panel, block 1c "Held, not booked."** Third and last of the three "the place is
  gone but the roll does not show it" blocks, under **1a** (offered, waiting for an answer) and
  **1b** (waiting for re-enrolment). Last of the three because it is the only one where the
  family is already in the room - the reader has seen the name three lines above, on their other
  days. **NAMED, NOT JUST COUNTED**, the rule both the others follow, with the reason, the date
  it started and a Release button.
- **The Support plans card: the NAME opens the child, the ROOM opens the cell** (6 Nov 2026). The
  name went to the Placement cell because there was no detail to open; the child dialog
  (`window.openChild()`) is that detail now. The cell is still one click away on the room line,
  the split the search palette makes - who they are, and where they sit.
- **The room roster.** An amber padlock on the held day. Three marks now, and they had to be
  told apart at a glance: **purple tick = attends, blue hourglass = asked for and has not got,
  amber padlock = held for them and out of the free figure.** The footnote under the table says
  all three in words, because an empty box does not look like a button.
- **The roster's own totals row** gained the same `, 1 held` suffix the grid has. It was
  reading `14/15 full` with nothing to explain the missing place - the grid's fault reproduced
  in the one place that reprints the grid's figures.
- **The day strip** (`window.dayStrip()`) gained a `held` state in the app's amber, so a held day
  keeps one mark on the roster and on the Support plans screen. `plain` would have been the
  lazy reuse and it says nothing; **one fact keeps one colour on every screen** is the rule that
  merged this component out of four copies.

**AMBER IS DELIBERATE AND IT IS THE ONLY MARK IN EITHER COMPONENT THAT IS NEITHER WORKING HUE.**
A place deliberately out of use is not a normal state of a weekday, and reading it as "attends"
or as "wants" is exactly the wrong conclusion. `--lm-warn-deep` is 5.08:1 on white, well over the
3:1 a glyph needs; `--lm-warn-text` at 2.0:1 would not have been legible.

---

# The Support plans screen

## A SEVENTH SIDEBAR ITEM, and this codebase has refused one twice

Offers **widened** into In progress rather than adding a nav row, and User access hangs off the
user button. Both of those turn on one test: *is this one stage, or one corner, of a module that
already exists?*

A child on a plan is not a place to fill, so nothing in the work layer would ever list them, and
"who is on a plan, and has anybody rung the family" has no home on any other screen. It is also
a **whole-group** question, which a panel on one centre's placement board cannot answer. Broc,
10 Sep 2026, choosing a screen over the panel that was asked for:

> *"The BSP is almost like an 'in progress' child. The child has been removed from care pending
> an improvement in behaviour. There are specific follow-ups and tasks that need to be undertaken
> by the staff for that child. Just like before, managers should be able to see all BSP children,
> while supervisors should only see their centre."*

## TWO PLANS, AND THEY RUN TOGETHER (17 Sep 2026)

The first build knew about one. Emily Kite's documents describe two, and the ISP document is
explicit that they are not alternatives:

> *"The BSP does not replace the ISP. Instead, it works in partnership with the ISP to ensure the
> child receives both inclusive developmental support and structured behavioural guidance when
> necessary."*

| | Behaviour Support Plan | Individual Support Plan |
|---|---|---|
| for | challenging behaviour | diagnosed disability, or a child under assessment |
| shape | **ten weeks, and it ends** | **runs for the whole enrolment** |
| clock | every date derived from commencement | none; the ISP names its own review timeframe |
| flag | `placements.has_behaviour_plan` | `placements.has_individual_plan` |
| steps | 16 | 4, plus a review date |

So they are **two independent flags, never one flag with two values**, and a card can carry two
ladders. One child, one room, one set of held days and one contact history underneath them.

**The ISP holds days too.** Broc, 17 Sep 2026: *"if a child is having lots of behaviours and we
are waiting for a specialist appointment, they could drop days or hours attending to support in
the meantime."* `day_holds` was never gated on the behaviour flag, so this already worked; what
changed is that ISP children now reach the screen that offers the control.

**HOURS ARE NOT HELD, AND WILL NOT BE.** The unit everywhere in this app is the room x weekday
place-day. A child attending shorter hours still occupies their place that day, so there is
nothing to hold and nothing comes free - modelling it would put a second unit into a tool that
has exactly one. A dropped DAY is a place, and that is what `day_holds` records.

## NOTHING NEW IS STORED ABOUT WHO IS ON A PLAN, AND THAT IS STILL THE POINT

Every fact the first build put on the screen already existed and had no way of being read
together:

| fact | where it lives | whose |
|---|---|---|
| who is on a plan | `placements.has_behaviour_plan`, `.has_individual_plan` | the flags a centre ticks |
| which days they attend | the derived board | Kidsoft, live |
| which days are held | `day_holds` | ours |
| who has spoken to the family | `communications` | ours |
| **where the process has got to** | **`support_plans`, `support_plan_steps`** | **ours, new 17 Sep 2026** |

**A `bsp_children` table would have been a fourth copy of three of those**, and the flag itself
would have been copied into it - the failure this codebase has measured three times (the capacity
override, the sibling checkbox, `priority_of_access`).

**The step tables are not that, and the test is the one that has always applied here: could
anybody type this anywhere else?** They hold no name, no room, no year and no flag - only a
`placement_id`, a step key, and the dates somebody entered. Who is on a plan is still the flag and
only the flag, and a plan row is **created lazily by the first tick**, never by a screen wanting
something to render.

## THE CONTACT LOG FOR AN ENROLLED CHILD ALREADY EXISTED AND NO SCREEN OFFERED IT

`api/comms` has taken a `placement_id` since the day it was written, and **the only screen that
ever called it passed a `waitlist_id`.** So a family in the queue had a history and the same
family, once enrolled, had nowhere to put one. The Log a call button on this screen is that
handle, finally used - the endpoint was **not** rebuilt, for the reason the Kidsoft bridge
records: a second way to write a contact note would be two implementations of one record.

### AND THEN THEY WERE STOPPED FROM TRAVELLING (17 Sep 2026)

They used to. Kidsoft holds a ChildID for an enrolled child, so unlike the 87% of the queue that
has nowhere to copy to, a note from this screen was copied across.

Asked directly, Broc's answer was **"For now"** - keep them local. The reason is
`createComment`'s three properties: **no update, no delete, no read path.** An ordinary contact
note is one thing; *"second family meeting, discussed release of care"* written permanently and
unretractably onto a child's Kidsoft record is behaviour and diagnosis material, and a different
risk.

The screen posts `local_only=1` and `/api/comms` honours it. **The caller asks; the endpoint does
not guess from the screen** - the Waitlist and the work layer still push, and a second endpoint
would have been two implementations of one record.

**The suppression IS stamped on the row now, as `communications.local_only` (19 Oct 2026) - and
leaving it unstamped was a hole.** This used to say nothing was stamped, reasoning that
`kidsoft_pushed_at` and `kidsoft_push_error` are for what happened to an *attempt* and an error
would put a warning triangle beside a note that did what it was told. True of those two columns,
and it is still why the flag is its own column rather than an error. But the nightly
`comment_push_backlog()` reads "no pushed_at, no error" as **"had nowhere to go yet"**, and a
support plan note hangs off a placement whose child already HAS a Kidsoft record - so the first
night after one was written, the sweep would have copied it onto the child's record for good,
exactly what Broc said not to do. Found 19 Oct 2026 before any had been written (4 notes in the
whole table, none local). The backlog now skips `local_only = 1` and `direction = 'internal'`.
The modal still states the policy before the note is typed, which is where a staff member needs
it.

### Editing a note (12 Nov 2026)

Broc: *"Can we please edit the notes in the ISP/BSP/logging section for everyone?"* The modal had
said since 17 Sep that notes "are kept where they can be corrected", and nothing could correct one.
(The ladder's step notes were always editable by every role; this is the contact log.)

- **A pencil on each entry** (`commEditBtn()` in `support-plans.js`) puts the note back into the
  form at the top, which then reads Save changes / Cancel and posts `/api/comms` `update`. Cancel,
  a save, or opening another child puts the form back to logging.
- **Only a `local_only` note that never went to Kidsoft**, checked by the server. Every note from
  this screen is one; a note from the queue shown here by ChildID is not, and gets no pencil.
- **Any role, any note, any age** - "for everyone" - inside the centres the user holds
  (`api_require_centre()` off the row). It is never quiet: `edited_at` / `edited_by` print on the
  entry ("edited by emily 12/11/2026 ...") and on the printed report, and `comm.update` in the audit
  log keeps the words it replaced. A save with nothing changed writes nothing.
- **The time survives**: the form takes a day, and the same day handed back keeps the logged time.
- **A note folded into a stage change** (work modal) takes the new words onto that event too,
  through the same `comms_folded_events()` that `delete` uses.
- Only on this screen. The family modal and the work modal show the same internal notes and have no
  pencil, because that was not asked for.

Verified in Chromium as an administrator on a throwaway `local_only` note: log, edit, cancel, edit +
save (summary, detail, channel), an unchanged save, the report row, and the server refusing note
219 (not local) and an empty summary. No console errors. The test note was then removed with the
app's own Delete; its audit rows (4951, 4952 and the `comm.delete`) remain.

## THE CHECKLIST OF STEPS, WHICH WAS REFUSED UNTIL THE TEAM NAMED IT

This section used to say the opposite, and the reason it said so is the point:

> *"The obvious next thing is a fixed list of the stages a plan goes through, dated and
> initialled. It is not here because nobody has told us what Little Miracles' steps actually are,
> and a workflow guessed in advance is the override nobody ever exercises failure in another
> shape. If it is ever built, get the steps from the enrolments team and record who said them and
> when."*

**That condition was met on 17 Sep 2026.** Emily Kite provided two process documents -
"Behaviour Support Plan (BSP) process" and "Individual Support Plan Process (ISP)" - which are
kept verbatim in `_incl/docs/support_plans/`. Broc's direction the same day: **"Purely a tracker,
assume that all the steps needed to get a child on a BSP/ISP have already been completed if they
are marked as on one."**

So the ladder is a **transcription**, held in exactly one place, `plan_steps_bsp()` and
`plan_steps_isp()` in `_incl/support-plan-steps.php`. **Nothing in it was designed.** Sixteen
steps for a BSP, four for an ISP, each carrying the policy's own wording, its own week number and
the role the document names.

### A TRACKER, NOT A SYSTEM OF RECORD, and the line is worth defending

Most of the BSP process is not enrolments work. The actors are the Nominated Supervisor, the Room
Leader and the Area Manager, and the artifacts are paper in the room's Emergency Folder: the
Family Collaboration Form (tab 2), the Challenging Behaviour Form, the Incident/Injury/Trauma
report, the Challenging Behaviours Log, the environment audit, the educators' progress notes, the
ISP itself (tab 4).

**A field for each of those is a compliance system for educators, which is a different product.**
What is stored here is a tick, a date, who ticked it and an optional note - where a child is UP
TO, which existed nowhere. The step 3 material ("Communicate and Document") is therefore **not a
step**: it runs throughout, it never completes, and it is printed as a footnote under the ladder
saying in words that it is not tracked here. That footnote exists so nobody later reads the
omission as an oversight and helpfully fixes it.

### THE LADDER LIVES IN A MODAL, AND THE CARD CARRIES ONE LINE

It was built expanding inline on the card. **Measured on the rendered page: one expanded card ran
to 2968px** and pushed the other two children off the screen entirely. This list is ordered by
what is being missed, so **a card that swallows the viewport defeats the only thing the screen is
for.** Broc called it, 17 Sep 2026. The same three children now render in 1336px.

The card keeps what answers "where is this up to" at a glance - the type, `9 of 16 done`, an
overdue badge and one sentence naming the next step and its date.

**THE WHOLE BLOCK IS THE TARGET.** It was an "Open the plan" link at the far right, which put the
only way in at the opposite end of the row from the sentence somebody had just finished reading
(Broc, 17 Sep 2026). The block is now `role="button" tabindex="0"` with a keydown guard for Enter
and Space - the pattern `.place-chip` and `.rs-open` already use in `board.js` - and the target
went from a link to 998x64px. **Nothing else inside the block is interactive, which is what makes
the whole of it safe to click**; the chevron is an affordance, not a second target.

**NO `title` AND NO `aria-label` ON IT.** On a `role="button"` either one becomes the accessible
name and REPLACES the content, so a screen reader would announce "Open the plan" and stop -
dropping `8 steps overdue`, which is the whole reason the row is worth opening. Left off, it
announces everything it shows. The native tooltip also sat on top of the day strip above it.

**THE HOVER COLOUR WAS MEASURED AND THE FIRST CHOICE FAILED.** `--lm-purple-light` is what
`.grid-table th.grid-room` uses, so it was the obvious pick - but that cell holds one short bold
room name and this block holds a **sentence** in `.8rem --lm-muted`, which on that wash is
**4.12:1**, under the 4.5:1 small text needs. On `--lm-surface-2` it is 4.45:1, still a hair
under, so the hover darkens the text as well to `--lm-ink-3`: **5.74:1**. The chevron moved from
`--lm-muted-2` to `--lm-muted` for the same reason - the palette calls `--lm-muted-2` decoration,
and at **2.97:1** on the hover it was under the 3:1 a glyph needs.

Verified by driving it: a click 40px from the LEFT edge opens the plan, Tab reaches the block and
`:focus-visible` matches with a 2px `--lm-purple` ring, Enter and Space both open it, and Space
does not scroll the page.

**IT ALSO COLLAPSED A LEVEL.** There used to be three: the card, the inline ladder, and a dialog
for one step. Now there is the card and one modal:

- **The step editor opens in place, inside the modal.** A modal stacked on a modal is a focus
  trap sitting on another focus trap, and neither Bootstrap nor the reader keeps their place.
- **So does the outcome form**, as a second pane of the same modal rather than a second modal.
- The body is `modal-dialog-scrollable`, so the header keeps saying which child and the footer
  keeps the outcome button in reach while sixteen steps scroll past.

**THE SCROLL POSITION SURVIVES EVERY RE-RENDER, AND THAT IS NOT A POLISH ITEM.** The modal redraws
all sixteen steps on every tick, and the first build reset the body to the top each time - so
ticking anything in the back half of a ten-week plan threw the reader back to week 1, which is
most of the work. Caught by driving it, not by looking at it: the screenshot of the open editor
looked entirely correct. A pane switch still resets to the top, because that is a different view.
Verified: scrolled to 586px, ticked a week 9 step, came back at 566px; the editor on the last step
opens fully visible.

### THE MODAL IS A WIZARD: ONE STEP TO A PAGE

Four passes got here, and the first three were the same mistake in smaller sizes - showing the
whole plan and trying to make it fit. Every font in the modal had shrunk to 0.7-0.85rem doing it,
which is what finally named the fault (Broc, 17 Sep 2026):

> *"It is still showing multiple at once, and also the text is very small (looks zoomed out). If we
> reduced the amount that was showing on a single modal (more wizard-like) then we could have
> normal sized fonts."*

**The two were one problem.** Type was small because content was dense; content was dense because
the page was trying to hold sixteen steps. One step per page fixes both at once:

| | flat list | phases | collapsed | stages | **one step per page** |
|---|---|---|---|---|---|
| steps on screen | 16 | 7 | 0 | up to 6 | **1** |
| step title | 13.6px | 13.6px | 13.6px | 13.6px | **20.8px** |
| body text | 12.2px | 12.2px | 12.2px | 12.2px | **15.7px** |
| pages that scroll | - | - | - | some | **none of 17** |

**The page IS the form.** No click to open a row, no expanding editor, no inline show and hide -
the fields are simply there, at the size the rest of the app uses. That is what a wizard buys.

**NEXT COMMITS THE PAGE**, which is the wizard idiom and one control instead of two: a "Save and
continue" button sitting above a Next that also saved was two buttons for one write, and it was
what pushed the page past the fold. On the last page there is nowhere to go, so it becomes Save.

**BACK AND NEXT LIVE IN THE FOOTER.** Under the page they went below the fold on any page whose
content ran long, and the wizard's own navigation is the one thing that must always be reachable.

**TYPED TEXT IS NOT THROWN AWAY BY A BACK BUTTON.** The page is a form and paging is how you leave
it, so Back and Next save first when anything has changed. Verified both ways: a note typed and
then paged away from is there on the way back, and paging over two untouched pages posts nothing.

**IT STILL NAVIGATES AND DOES NOT CONSTRAIN**, and it still lands on where the work is rather than
on what week it is - see the stage strip below.

### NO SKIPPING AHEAD, AND WHY THE OBJECTION TO IT WAS WRONG

Broc, 17 Sep 2026: *"I don't think we should allow the user to skip ahead on the BSP/ISP modal,
like to future stages before they have marked off the current one as complete?"*

**I argued against it and the argument was based on a wrong model of the process.** The claim was
that a step could become permanently uncompletable - miss the week 2 room visit and that week is
gone, so the weeks 1-4 stage could never be finished and the gate would lock the plan for the rest
of its life, leaving a false tick as the only way out. Broc's correction:

> *"I think you are interpreting this as a lot more strict (in terms of timing) than I believe it
> actually is in practice. Things may need to be pushed back a week or whatever."*

**The week numbers are expectations that SLIP, not deadlines that lapse.** A visit that did not
happen in week 2 happens later and is ticked then. Nothing is ever permanently uncompletable, so
the gate cannot trap anybody, and the whole objection falls over. **Worth keeping because the same
mistake is easy to make again**: this file is full of dates and it reads stricter than the centres
work. When the schedule slips, the lever already exists - re-date the "plan emailed" step and every
derived date moves with it.

**The grain differs by plan, because the documents do:**

- **A BSP gates by STAGE.** A stage groups work that genuinely runs together - the four weekly room
  visits sit in the same stage as the week 6 family meeting, and the visit still happens in a week
  the meeting gets rearranged out of. Gating at stage level leaves that alone.
- **An ISP gates by STEP.** Identify, meet, write, issue: strictly one after another, and no stages
  to group.

**Back is never gated**, nor is any stage already reached, and a finished stage stays fully
editable - this stops somebody running ahead of the process, not from correcting what they did.

**THE GATE IS IN `goTo()`, not only in the disabled controls.** Verified by force-enabling a locked
stage button in the DOM and clicking it: the page did not move. Disabling the button is for the
eye; the guard is what actually holds.

**A disabled button explains nothing**, and this app cannot put a tooltip on one - a disabled
control fires no mouse events in any browser, which `app.js` documents. So the reason sits on the
page: *"Weeks 1 to 4 has 2 steps still to do. Finish them to move on to Week 5."* Next becomes Save
at the gate, because a Next that goes nowhere is a control that does nothing.

### THE ROOM MARKS

`window.roomIcon()` is the mark every other screen puts in front of a room name - tenure, rooms,
the waitlist, search, the dashboard and the work modal all call it. **This was the one screen that
did not** (Broc, 18 Sep 2026). Three places now do: the card's centre/room/year line, the plan
modal's subtitle and the hold dialog's subtitle.

**The two subtitles moved from `.text()` to `.html()`**, because a mark is markup - so every part
of them is now escaped by hand, and a child's name goes through both. Checked with a placement
named `<img src=x onerror=alert(1)>Esc`: zero injected elements on the page, no dialog fired, and
the name renders as literal text in the title and the hold subtitle.

The map itself is not duplicated here. It comes off the sprite's `data-map`, declared once in PHP
(`ROOM_ICON_MAP` in `_incl/svg.php`), for the reason that file records: room names are matched on
both sides of the wire and two copies of the fold would drift the first time a centre opens a room
it misses.

### THE CARD SHOWS THE PLAN'S SHAPE, in the half of it that was empty

Broc, 18 Sep 2026: *"We have a LOT of unused horizontal space on the cards."*

One cell per step, grouped into the plan's stages, right-aligned between the badges and the
chevron. **The gaps are the stage boundaries and the fill is how far through it is**, in the same
vocabulary the modal uses: green done, red overdue, purple where the plan is up to. Somebody who
has opened one plan can read every card.

**It says something the badges do not.** `9 of 16 done` and `3 steps overdue` do not tell you
WHERE the plan has stalled; the track shows the red block sitting in the fourth group, so the
stall is in Weeks 6 to 9 rather than anywhere else.

**Decoration, and labelled as such**: `role="img"` with the count as its label and the same facts
in words beside it. It is not focusable and not a tab stop; the whole block remains the one
target, and clicking the track opens the plan.

**THE CELLS NOW CARRY A `title` EACH, which this section used to forbid** (23 Sep 2026, asked for
by Broc). The ban was written here when the tooltip trap had just bitten for the third time, and
it was too wide: **the rule the trap actually produced is the one in the tooltip section below** -
an element that TAKES FOCUS and is re-rendered by its own click must use `aria-label`, because
Bootstrap is then left holding an instance whose element has gone. A track cell takes no focus and
is not itself a target, and `app.js` has disposed a live tooltip on any click and on its trigger
leaving the document (its `MutationObserver`) since the TOOLTIPS block was written.

Driven rather than reasoned about, because the claim it overturns was load-bearing. Hover a cell,
re-render the list under the pointer, click through to the modal, close it, move away: **one
bubble on screen at each step and it was anchored to a live element every time - never an orphan,
and no console error.** The re-render was driven by replacing the list's own HTML, NOT by ticking a
step, because a probe that wrote to a real child is a fault this screen has already had.

**IT CANNOT REACH THE BLOCK'S ACCESSIBLE NAME**, which is the other half of why the block itself
still carries neither attribute. On a `role="button"` a descendant's `title` counts towards the
name when that descendant has no text of its own, and a cell has none - but `.sp-track` is
`role="img"` with an `aria-label`, and name computation does not descend into one. Verified: the
block still announces its own content, and carries no `title` and no `aria-label`.

### THE DATES ARE LABELLED FACTS, NOT A SENTENCE

Broc, 18 Sep 2026: the left of the block was *"just one line"* and could be *"a little bit more
detailed... Not a whole novel, keep it brief, but maybe display it a bit better."*

It was `Commenced 27/07/2026. Week 8 of 10. Next: Second family meeting, due 4/09/2026.` - three
facts punctuated into prose, so reading any one of them meant reading all three. They are now
small-caps labels with their values under them.

**FOUR FIXED COLUMNS, and the first cut of this got that wrong** (Broc, 18 Sep 2026: *"a lot of
inconsistency between Hallie and Logan visually"*). Rendering only the facts each plan HAD meant a
running BSP showed four, one waiting to start showed three, one with no date showed two and an ISP
showed two different ones - four cards, four layouts, with `Next` landing at x=518, x=443, x=752
and x=420 down one screen. **Nothing to read down, which is the whole job of a list.**

The columns are the same every time and each state fills the ones it has:

| | 1 when it started | 2 where it is now | 3 when it resolves | 4 the sentence |
|---|---|---|---|---|
| running | Commenced | Week | Decision due | Next |
| dated, not started | Starts | `--` | Decision due | Next |
| no date yet | Starts `not yet` | `--` | `--` | Next |
| ISP | Next review | (held open) | (held open) | Next |
| closed | Ended | (held open) | (held open) | Outcome |

**A column with nothing to say gets `--`**, which is this app's own convention for a figure it
cannot give - a centre that is shut gets `--`, not a sentence explaining why. An EMPTY column would
let the grid collapse and take the alignment with it, which is the thing it is for.

**Verified by measurement, not by eye:** every plan on the screen shares one column grid
(x = 252, 408, 532, 688) and every fact row is exactly 38px tall. It holds at 390, 768, 992, 1280
and 1600px with no horizontal page overflow - two columns and a full-width sentence below 768px,
four above it.

**ONE NEW FACT, and it is the one the card could not answer: WHEN THE PLAN RESOLVES.** `Decision
due 2/10/2026` comes free - it is the week 10 step's own derived due date, not a second
calculation of the same thing - and it answers the question a ten week process invites and nothing
on this screen was saying.

**A LABEL HAS TO LABEL ITS VALUE**, which this got wrong twice: `Week: starts 5/10/2026` said
neither, and repeated the commencement date sitting beside it; `Not commenced` as a label with a
sentence under it was a statement wearing a label's clothes. Both are now `Starts`. **The sentence
went with it** - the rule that sets the date is spelled out in the modal, and repeating it on every
card is the caption this app deletes rather than writes.

**The ISP's review shows the date only.** How late it is has its own badge in the row above, and
carrying it in the fact as well both said the same thing twice and wrapped that column onto a
second line, making one row taller than every other.

`--lm-muted` is 4.45:1 on the block's hover tint, a hair under what 0.66rem needs, so the labels
darken with the block exactly as the old state line did. Measured across widths: one row at 768px
and up, two rows at 390px, no horizontal page overflow at any width.

### Three measurements, each of which overturned the obvious build

- **THE GAP BETWEEN GROUPS HAS TO BEAT THE GAP BETWEEN CELLS BY A LONG WAY.** At 6.4px against
  2px the track read as sixteen evenly spaced dashes and the stage structure - the only thing it
  says that the badges do not - was invisible. 13.6px against 1px.
- **`flex-shrink` ON THE CELLS COLLAPSED THEM TO THEIR MINIMUM WITH 500px FREE BESIDE THEM.** At
  `flex:0 1 26px` every cell rendered at 3px and the track at 113px instead of 481px. Found by
  dumping the computed widths of every child of the card header and then testing five CSS
  variants against the live page, not by reasoning about flexbox. `flex:0 0 26px`.
  The same edit removed an inline `style="flex:N"` that had been setting proportional group
  widths: `flex:4` means `flex-basis:0`, which was the other half of the collapse. With a constant
  cell width a group of six is six times a group of one without anything being told to be.
- **A CONSTANT CELL WIDTH IS WHAT MAKES THE LENGTH MEAN SOMETHING.** Letting cells stretch to fill
  a fixed track drew the ISP's five steps as wide as the BSP's sixteen, and the two read as
  different components on one card. Now a sixteen step plan draws a long track and a five step
  plan a short one.

**WIDE SCREENS ONLY (min-width 992px), and that is the premise rather than a concession.** It
exists to use horizontal space the card has going spare, and on a phone there is none - left in,
it forced the PAGE 144px wider than the 390px viewport. Shrinking instead is not available, per
the measurement above. Verified at 390, 768, 991, 992, 1100, 1280 and 1600px: hidden below the
breakpoint, shown above it, **no horizontal page overflow at any width**.

### THE WORK ALREADY DONE, which the card could not say at all (23 Sep 2026)

Broc: *"show more information on the summary card (not the modal) about work that has already been
done on the plan ... as well as a general overview of the dates and notes that have been entered."*

**Everything above this block is about what is MISSING** - steps overdue, the next one and its
date, how long the family has been left alone. That is the screen's job and it stays first. But a
reader deciding whether a plan is actually being WORKED had nothing: `9 of 16 done` is a count
with no dates in it, and the date somebody typed, who typed it and what they wrote beside it were
visible only inside the modal, one child at a time.

Two labelled rows under the facts, in the same vocabulary:

| | |
|---|---|
| **Recorded** | the dated steps, most recent four, oldest first |
| **Notes** | the last two notes anybody typed, with the step and date each belongs to |

**NOTHING NEW IS STORED AND NOTHING NEW IS COMPUTED.** `plan_shape()` has published `done_on`,
`done_by` and `note` per step since the ladder was built, so this is the same facts finally read
together - the test the whole screen was built on. `api/support-plans.php` and
`_incl/support-plan*.php` are untouched.

**THE LABEL COLUMN IS THE FACTS GRID'S OWN FIRST COLUMN** (8.5rem, same 1.25rem gutter), so
RECORDED and NOTES sit directly under COMMENCED and WEEK. Four cards four layouts is the fault the
facts grid exists to fix and a second block laid out to its own widths would have put it straight
back. Measured across five plans in five different states: **every label at x=252 and every
recorded item at x=408 or x=828, nothing else.**

**A GRID AND NOT A WRAPPING ROW, and the first cut got this wrong.** Packed as flex, four items of
four different lengths put the second row's dates at x=436 and x=740 against the first row's x=408
and x=648 - nothing to read down, on the one screen whose whole feature is its ordering. Two fixed
columns above 768px, one below.

**SORTED BY THE DATE SOMEBODY TYPED, not by position in the ladder.** Steps are regularly ticked
out of order - the week numbers are expectations that slip, which is already on the record here -
and a trail of dates that is not in date order is not a trail. Verified with a plan whose week 2
room visit was ticked after the week 4 one: ladder order would have read 7/08, 1/09, 21/08, 28/08
and the card reads 7/08, 21/08, 28/08, 1/09.

**FOUR AND TWO, and the ones that fall off are the OLDEST.** A sixteen step BSP late in its run has
fourteen recorded dates and printing all of them is the 2968px card this screen already deleted
once. What a card answers is what has been done LATELY; the whole trail is one click away, and a
`+N earlier` marker takes a line of its own above the grid rather than a cell of it.

**A NOTE DOES NOT NEED ITS STEP TO BE TICKED.** `plan_step_save()` keeps the note when the tick
comes off, which is how *"waiting on mum to confirm a date"* gets recorded against a step nobody
has done - and that note is worth more on this list than most of the ticked ones. Those show their
step name with no date, which is the honest shape. **The outcome's own note is in here too**; it
lived only in the modal's closing banner.

**CLAMPED TO TWO LINES, titled only when it might be hiding something.** A note is 500 characters
and one of them would otherwise make a single card four rows taller than every other - the
reading-down fault in a new shape. But a tooltip repeating a line already on screen is the empty
caption this app deletes rather than writes, so the `title` goes on at 80 characters: err short
and the cost is a tooltip nobody needed, never a note nobody can read.

**A ROW WITH NOTHING TO SAY STILL RENDERS**, for the same reason a fact column does. It says
`nothing yet` and `none` rather than `--`, because this one is not a figure we cannot give - we
can, and the answer is none.

**Measured on five plans covering every state** (running and overdue, ticked out of order, closed
with an outcome note, an ISP with no stages, and one nobody has touched): cards 497, 404, 502, 396
and 364px against 290px before, every recorded row exactly 20px, and **no horizontal page overflow
at 390, 576, 768, 991, 992, 1100, 1280 or 1600px.** No console errors.

### SAVE IS WHAT NEXT BECOMES, so it looks and behaves like it

Broc, 18 Sep 2026: it should carry a chevron like Next, and be *"disabled if there are steps
missing completion."*

Save appears in exactly two places - at the gate, where Next has nowhere to go, and on the last
page of the plan. **Taken literally the second half would disable it at the gate always**, because
the gate exists precisely because steps are missing, leaving the one page somebody went there to
record with no working control. What it means in practice is **this page's step**: the button is
disabled until the checkbox is ticked, and the checkbox re-evaluates it live rather than waiting
for a render.

**It also moves on when saving has opened the way**, which is what a chevron promises. Ticking the
last step of a stage is exactly what unlocks the next one, so staying put would leave the reader on
a finished page under a forward arrow that did nothing. On the last page of the plan there is
nowhere to go and it simply saves.

**A page that is not a step has nothing to save**, so the nav Save is not rendered there at all -
the ISP's review page carries its own control and the BSP's documenting page carries none, and a
nav Save on either was a button that quietly did nothing.

**Next is deliberately unchanged.** It still moves without requiring a tick, which is what keeps
movement inside a stage free - the week 8 room visit can be recorded while the week 6 family
meeting is still being rearranged.

### The tooltip trap, for the third time

The stage buttons carried `title=`, and **every plain `title=` in this app becomes a Bootstrap
tooltip**. They take focus from a click and are then destroyed by the re-render that same click
triggers, so closing the modal afterwards drove Bootstrap's focus handling onto an orphaned
instance and threw *"Cannot convert undefined or null to object"*. Identical to the step checkbox,
and fixed the same way: `aria-label`, which gives the full stage name to a screen reader without
creating a tooltip. The full name is on the page itself once you are on that stage.

**The rule, now that it has bitten three times: on any element that takes focus and is re-rendered
by its own click, use `aria-label`, never `title`.**

### THE TWO ACTION STEPS THAT NEVER COMPLETE, PUT BACK

Both documents have one action step that recurs for the life of the plan and has nothing to tick,
and both were dropped from the ladder because a checklist had nowhere to put them. As pages they
are simply the document's own steps, where the document puts them:

- **BSP step 3, "Communicate and Document"** - the Challenging Behaviour Forms, the log, the weekly
  room leader meeting. It sits straight after the plan is emailed and runs to week 10. The page
  carries no controls and says in words that none of it is tracked here, which is what stops a
  later reader taking the absence for an oversight and building forms for it. It had been drifting
  round the modal as a footnote nobody had a home for.
- **ISP step 5, "Monitor and review"** - *"No specified date. Let them choose how often."* The last
  page of an ISP, carrying the one date the team types from the plan's own timeframe.

They are **pages, not steps**: they are not in `p.steps`, so they never count towards "N of 16
steps done" and can never be overdue. The nav therefore says **"Page 11 of 17"** and not "step" -
two counters using one word for different totals is the fault this screen keeps having to fix.

### An ISP has no stage strip

Six pages, no strip, no stages. Its action steps are not staged and carry no weeks, so there is
nothing to page between at stage level.

### Three faults this pass found, none of them visible

- **A LINE-RANGE CSS EDIT DUPLICATED 1,100 LINES.** Replacing the old block by matching its start
  and end text found the END match EARLIER in the file than the start, so the slice put everything
  between them back twice - `app.css` went from 2,366 lines to 3,458 with two copies of half the
  stylesheet. Caught by counting braces and grepping for duplicate selectors, not by looking at a
  page. Restored from the scratchpad copy and redone **by line number, with both boundary lines
  asserted before the write** and the selector list diffed against the backup afterwards. That is
  CLAUDE.md's rule for deleting a block by pattern, and it is in the file because of this.
  The redo was still one line short at the end and left a stray `}`; the brace count caught that too.
- **A STALE `.sp-tick` HANDLER FROM THE PREVIOUS DESIGN WAS STILL BOUND.** The new checkbox kept
  the old class, so ticking it fired both the page's save and a dead handler that looked for a
  `.sp-step` row that no longer exists - posting a `step_save` with **no step key at all**. The
  visible symptom was a red "Unknown step." toast next to a save that had also worked. Found by
  logging the POST bodies, which showed two requests where there should have been one.
- **A TEST WROTE TO A REAL CHILD.** The script clicked *"the first BSP card"*, and once the sort
  order changed that was Hallie Pennings-Webster rather than the probe - a step was ticked and a
  note saved on her plan. Removed immediately; the audit rows are left, because that is what audit
  is for. **A probe must be targeted by name**, never by position in a list this app deliberately
  re-orders.

### IT IS NOT A LADDER YOU CAN ONLY CLIMB

Three of the policy's own exits fire at **any** point:

- the child improves and the Area Manager ceases the plan (stated three times in the document),
- the child is released immediately over a risk of significant harm, four week notice waived,
- the family chooses to cease care, four week notice waived.

A plain progress bar would render **the commonest good outcome** - improved in week 3 - as a
process somebody abandoned, and would go on reporting seven overdue steps for ever. So an outcome
is recordable at any time, closing the plan wherever it had got to, and **a closed plan is never
overdue**. It is reopenable, because an outcome that cannot be corrected is one that gets recorded
late or not at all.

### ONE DATE DRIVES ELEVEN OTHERS

The policy: *"The BSP will begin on the FIRST Monday after the families have been emailed the
child's BSP."* So ticking **plan emailed** sets `commenced_on` - the first Monday **strictly**
after, because starting a child on a plan the same morning their family was told is not what that
sentence means - and every week-numbered step falls due on the **Friday of its week** (a centre
week is Mon-Fri, and the policy dates most steps to a week rather than a day).

**The first four steps carry no date at all, and that is the document, not a gap.** A family
meeting happens when it can be arranged. Inventing a deadline for it would be the tool making up
policy.

### THE DUE DATE IS AN OVERRIDE, NOT A COPY

`support_plan_steps.due_on` is **NULL unless somebody has typed one**, and the date is derived
from `commenced_on` on every read. Correcting when the plan started therefore moves the whole
schedule on its own, with **no recompute pass that could silently clobber a hand-set date** -
which is the stale-copy failure this codebase has measured three times. A hand-set date is marked
with a pencil glyph and says in its tooltip that it will not move.

Verified: a due date set to 1 Dec survived the anchor being moved from 1 Jul to 2 Sep while every
other date shifted with it.

### Measured on a probe row, 17 Sep 2026

Anchor on 1 Jul commences Mon 6 Jul, which put the plan in week 11 with 12 steps overdue, the
worst by 69 days. Moving it to 2 Sep re-dated everything to week 2 with one overdue. Closing with
"behaviours improved" took `overdue` to 0 and `week_now` to null; reopening restored both. The ISP
produced four steps with no weeks and no due dates, and one review date 16 days over. Every probe
row was deleted afterwards.

### THE SETTLING-IN RULE, AND WHY IT COST A MEASUREMENT

> *"Educators will not begin the Behaviour Support Process (BSP) within the first 4 weeks of the
> year or when a child changes rooms to allow any children to transition and settle into new
> learning environments."*

`plan_settling_warnings()` checks both, and it **warns rather than blocks** - the rule the
capacity check on the same endpoint already follows. It fires **only as the flag goes 0 -> 1**,
because ticking it is the only moment this app learns a plan is beginning, and an alert that
re-fires on every later save is a border rather than an alert.

**BOTH DATES ARE MEASURED. THE OBVIOUS SHORTCUT WAS WRONG TWICE:**

1. *"The first 4 weeks of the year"* looks like 1 January plus 28 days. **Tuggerah 2026 came back
   on Monday 5 January**, so a calendar constant would have under-warned by the best part of a
   week, every year, differently at every centre. The year's first day is therefore the earliest
   permanent booking anybody in that centre holds.
2. **The board cannot answer "when did this child move rooms".** `board_load()`'s roster is
   bounded by TODAY - measured 17 Sep 2026, **all 301 Tuggerah children read `first` as
   2026-09-17**. `reenrolment.php` fell into this exact trap and documents it. So both figures
   come from `bookings_year()`, which keeps the real span per child **per room** per weekday over
   the whole year.

The cost is twelve monthly Kidsoft reads against the board's four, which is why it is gated on
the tick and never runs on the list - the same discipline `reenrolment.php` applies to its nine
late enrollers.

**A provisional child is skipped, not guessed at.** No ChildID means no bookings to read, and
"we cannot tell" must never render as "this is fine".

Verified 17 Sep 2026 against real bookings: a child who joined Amazing Asteroids Pink ten days
earlier warned and named the date; a child who **does not start until 28 September** warned with
the tense changed ("does not start in ... until"); a child in the same room since January was
silent; a provisional child was silent. Saving the same child again with the flag already on
produced no warning. The year branch was proved separately on a widened threshold and printed
*"Tuggerah only came back on 5/01/2026."*

**The warning names the child by their Kidsoft name, not the posted one** - `save_placement`
overwrites a linked child's name from Kidsoft before this runs, which is the existing rule that
a name is a cache and never typed.

### THE ONE DATE ON AN ISP, AND IT IS NOT IN THE ACTION STEPS

The ISP's own steps carry no dates at all. But the document's **Transition to School** section
carries the one thing on this screen a family can permanently miss:

> *"AA Room: Application for early intervention classes is submitted (around August/September)"*
> ... *"specialised early intervention classes are limited on the Central Coast"* ... *"children
> may miss out if needs are identified and communicated too late - sometimes only at school
> orientation."*

So `plan_steps('isp')` appends a **fifth step, due 30 September**, the end of the window the
document names. It is the only step in either ladder that carries an **absolute** date rather than
a week number, because it answers to the school calendar and not to a plan's own clock.

**IT APPEARS ONLY IN AN AMAZING ASTEROIDS ROOM.** On a baby in Little Angels it would be a step
nobody can act on for three years, sitting overdue - exactly how a list teaches the team to ignore
it. The test is `room_type_key()`, which this codebase already built and already measured: the
tier is the room name's first word, so one constant covers **all nine Amazing rooms** including
Tuggerah's "Amazing Asteroid Purple" and Warnervale's "Amazing Asterouds", a typo in Kidsoft's
own room name that any whole-name rule would have missed. Verified across all five tiers: only
`amazing` gets it.

**The room tier is read from the row, never posted** - the same rule every id on this screen
follows - and `plan_step_save()` validates the posted step key against that same derived list, so
the step cannot be written onto a child who is not in that room.

### What is still NOT built, deliberately

There is no field for strategies, goals, diagnosis, risk assessments or behaviour incidents, and
no upload. Those are the room's paperwork. **If that changes, it is a decision about what this
app IS**, not a hardening pass.

## THE PRINTED REPORT (29 Oct 2026)

Broc: *"I want to be able to generate printable reports for Support Plans."*

`/support-plans-report?centre=N` (the tab on screen; `0` is All centres) and
`/support-plans-report?placement=N` (one child). Reached from **Print report** in the Support
plans header and **Print** on each card. A summary page - the tiles as figures, then one line per
child - and then **each child on a page of their own**: the week Mon-Fri, held days with their
reason, every step of every plan with its due date, who ticked it, when and its note, how a closed
plan ended, and **the family's whole contact history, oldest first** (the card shows only the
latest). One child's report is that child's section alone.

**NOTHING IS WORKED OUT FOR IT.** `report` in `api/support-plans.php` filters
`support_plans_list()` and adds `support_plans_history()`; the facts, badges and silence are drawn
by the card's own `planFacts()`, `planBadges()` and `quiet()`, which is why the report lives in
`support-plans.js` (the last line picks the page) rather than a file of its own. Paper cannot be
refreshed, so a second answer to "what is overdue" is worse there than on any screen. The order is
the list's, not re-sorted. `nextFact()` was pulled out of `planFacts()` for the summary table.

**A PAGE IN THE APP, NOT A BARE PRINT VIEW**, so it can be read before printing and switched
between centres with the tabs; `@media print` strips the chrome, scoped with `body:has(.spr-sheet)`
because nobody asked for every other screen's Ctrl+P to change. One child's report draws no tabs
and does not call `centre_current()`, so printing one child from All centres leaves the remembered
centre alone.

**Scope is the endpoint's**, as everywhere: `centre_id` through `api_require_centre($id, true)`,
`placement_id` off the row. Verified with a Tuggerah supervisor session: own centre and own child
answered; `centre_id=0` -> *"Choose a centre."*; Kanwal and Hallie's placement id -> *"Unknown
centre."*

Decisions worth keeping, each made by looking at the printed PDF rather than the screen:

- **`print-color-adjust: exact` on the sheet.** A browser drops backgrounds when printing unless
  told otherwise, and Bootstrap's red badge is white text on a red fill - it would print as
  nothing. **Every coloured mark also carries its word** ("Attends", "Held", "3 days over") for a
  black and white printer; the five-box week is a table of words in the day strip's own fills.
- **A note is a line under its step, not a column.** As a column one 500 character note took the
  width and folded every step name in the plan onto three or four lines, while most rows left it
  empty.
- **One `<tbody>` per step, `break-inside: avoid`.** Printed, step 10's note landed on the page
  after step 10. The stage heading rides in the same group, so a heading is never left alone at
  the foot of a page. The column headings repeat on each page.
- **The facts keep four columns on paper.** A4 is ~718px at default margins, under the 768px at
  which the card folds them to two.
- **"As at" and "Printed by" on every sheet**, Sydney time from the server: "week 4 of 10" and
  "39 days since contact" are true on the day it was printed and no other. **Confidential** sits
  over the title - this is behaviour and diagnosis material on paper.
- **A contact typed with only a date prints only the date.** The form takes a day, so most rows
  are stored at midnight, and "12:00 AM" on paper reads as a time somebody recorded.
- **The stacked logo, not the inline one**: the sidebar already inlines that file and
  `svg_scope()` keys its prefix on the path, so a second copy would repeat its ids.

`COMM_DIRECTIONS` and `COMM_CHANNELS` moved from `api/comms.php` to `_incl/comments.php` so the
report prints the same words the contact modal shows.

Tested without writing anything: the real rows, plus holds, notes, contacts, a hand-set date, an
overdue step and a closed ISP **injected into the API response in the browser only** - the rule
from the probe that once ticked a step on a real child. Zero POSTs, zero console errors, no
horizontal overflow at 390px.

## BOOKED DAYS GO INTO THE CENTRE'S OUTLOOK CALENDAR (29 Oct 2026)

Broc: *"For support plans, I want to link the next scheduled action to the centre's calendar; the
same way tours are linked as appointments. Once an appointment time has passed, the next scheduled
action needs to be setup as another appointment; or the previous one moved/edited maybe?"*
`_incl/support-plan-calendar.php`.

**Two decisions, both Broc's, both asked as plain choices on 29 Oct 2026:**

1. **A PERSON BOOKS THE DAY; THE TOOL NEVER WORKS IT OUT.** Offered against an automatic entry on
   every step's due date, and chosen. The due date is a deadline - "by the end of week 6" - and says
   nothing about when the family can come in, so each step page carries **Booked for** beside Due,
   and only a booked step reaches Outlook. It is the rule this file already states twice: the tool
   never makes up a date.
2. **A DAY AND NEVER A TIME.** First offered a timed appointment like a tour's, then: *"can we have a
   non-timed entry in the calendar?"* Every entry is **all-day**, midnight to midnight in Sydney (the
   only shape Graph accepts for `isAllDay`), and **shows as free** so it does not block the
   centre's whole day for anybody looking for a slot in it.

### One entry per step, never one entry per plan that moves along

That answers the "or moved" half of the question. The week 5 review and the week 6 meeting are
two appointments, and an entry moved on to the next step would erase the record that the first one
happened. **The next step gets its own entry when somebody books it.** The wizard already lands on
the next step's page the moment the last one is ticked, which is where its Booked for box is, and
the card's **Next** fact says `booked 10/11/2026`, `was booked ...` or **`not booked`**, so a step
reached with nothing arranged is visible on the list rather than only inside the modal.

**A PASSED DAY IS HISTORY.** Its entry is never moved, edited or removed - it is what the day was,
the rule a tour's entry follows after a no-show. Rebooking a row whose day has gone **lets go** of
the old entry (`plan_booking_set()`: the `calendar_*` columns cleared, the entry left in Outlook,
`support_plan.calendar` audited as `kept` with the event id) and the new day gets an entry of its
own. Clearing a passed day lets go the same way. A day is passed once it has ended, not when it
starts: today's booking still moves.

**AN UPCOMING ENTRY COMES OUT** when the booking is cleared, the plan ends **before** that day, or
the child is no longer flagged - nobody is coming, the tour's rule for a family lost before the
visit. A day **on or before** the outcome date stays, so recording the outcome the morning of the
week 10 meeting does not take that meeting out of the diary. Reopening the plan puts them back.

**TICKING THE STEP DONE DOES NOT REMOVE IT, on purpose.** A tick a day early would delete a meeting
that is still happening; a stale entry is the cheaper mistake.

### The ISP review is booked the same way, and is not a step

An ISP's recurring action is its review, and after its four setup steps that is the only thing on
it. The review page carries Booked for beside Next review, stored in `support_plan_steps` under
**`PLAN_REVIEW_KEY` (`__review`)** so one table holds every booked day and one sweep covers them.
**It is not a step:** `plan_steps()` never lists it, so it is never counted, ticked or overdue, and
`plan_step_save()` refuses the key. The passed-day rule is what makes it work - each review leaves
its own entry instead of one being dragged forward. Once an ISP's steps are done, its card's Next
reads `Review` with the booking.

### What an entry says, and what it never says

`<step>: <child> (<plan>)`, e.g. *"Second family meeting: Hallie Pennings-Webster (Behaviour
Support Plan)"*. The body is the child, room and year, the plan and its week, who the process
document says runs the step, and **a link that opens the plan**: `/support-plans?centre=N&plan=
<placement id>&type=bsp|isp`, which the screen now honours the way In progress honours `?item=` -
opened only if the scoped list returned that row, then dropped from the URL.

- **NEVER A NOTE.** Support plan notes are kept off the child's Kidsoft record because they are
  behaviour and diagnosis material, and a centre calendar is read by more people than a Kidsoft
  record.
- **NO ATTENDEES.** An attendee is an invitation, and an invitation is an email. The parent invite
  being added to tours (a separate change, 29 Oct 2026) lives in the tour-only wrappers and cannot
  reach a step.

### Everything else is the tour's

The mailbox (`centres.calendar_mailbox`), the `Calendars.ReadWrite` permission, the transaction id
that makes a retried create safe, the write **after** the response has gone (`/api/support-plans`
syncs every booked row of the plan after `fastcgi_finish_request()`, because ending or reopening a
plan moves all of them), and the 15 minute `--calendar` sweep, which also catches an unticked flag
because `/api/board` knows nothing of this. **The create / update / remove protocol is one
function, `calendar_row_write()` in `tour-calendar.php`**, parameterised by table through
`CALENDAR_ROWS`. It was the tour's alone until this; a second copy of the transaction-id rule is
exactly the kind of subtlety two copies drift on. The tour path's hash is unchanged - the one live
tour entry was re-synced against a mock and sent nothing.

**The note under the box** is `plan_booking_shape()`'s state, said by `window.tourCalendarNote()`
in the tour's words ("In the Kanwal calendar", or "Not in ... : <reason>"), plus *"Going into ..."*
in the second between the save and the write. **A failed update shows as failed even though an
entry exists**, because that entry carries the old day - the order a tour's status reads them in.
The first build checked the entry first and said "In the calendar"; the mock test caught it.

**BOOK IS ITS OWN BUTTON, and has to be.** The nav's Save waits on the step's tick (Broc, 18 Sep
2026), and a step is booked BEFORE it is done - so without it, a day typed on the gate page or the
last page could not be saved at all. It saves the whole page (one form), stays put, is enabled only
when the day differs from what is saved, and reads **Clear** when the box has been emptied. Back
and Next still save a typed day, like any other field. No `title=` on it: the tooltip trap.

### Verified, 29 Oct 2026, without writing to any real calendar

- **Against a mock Graph, in one rolled-back transaction, on a probe child (55 checks):** book ->
  one all-day POST to the right mailbox, free, no attendees, linked, `lm-attendances-sp-` transaction
  id; unchanged and note-only saves send nothing; move -> PATCH the same event; a save not sending
  the day leaves it; tick done -> nothing; clear -> DELETE; passed day: a renamed room sends nothing,
  rebooking POSTs a new entry and never touches the old one, audit names it; clearing a passed day
  sends nothing; plan closed -> DELETE, reopened -> POST, outcome on the booked day keeps it; flag
  unticked -> the sweep DELETEs, reticked -> POST, a second sweep nothing; 403 -> failed with the
  reason, retry clears it; a lost POST reuses its transaction id; the ISP review's subject, that it
  is not a step, that a BSP review and the review key through `step_save` are refused, and that
  `2026-02-31` is.
- **In Chromium, on a probe at Warnervale with both plans CLOSED**, so nothing could be wanted in
  Outlook by the endpoint or by the live sweep: the deep link opens the plan and leaves the URL;
  Book disabled until a day differs, the POST carries `booked_on`, the toast, Clear, the ISP review
  page's Book posting both its dates. Rows checked afterwards: no event id, no error, no
  `support_plan.calendar` audit. Then an **open** plan injected into the list in the browser, every
  POST answered there: "booked 10/11/2026" and "Review not booked" on the card, the in / failed /
  passed / going-in notes, a typed day saved by Back, **0px overflow at 390, 768 and 1280px**, no
  console errors. The probe and its rows were deleted.
- **Not yet seen against real Outlook.** The first real booking is the first real entry. The
  protocol is the tour's, live since 19 Oct; what is new is the all-day body.

## ORDERED BY WHAT IS BEING MISSED

It was `work_list()`'s rule alone - **never contacted at all** first, then longest silence, then
by name. Since the ladder there are **two kinds of neglect**, and an overdue step is the harder
fact: it is a thing somebody was supposed to DO on a date that has passed, where a quiet family is
an absence of evidence. A week 10 decision nobody made is a child in limbo.

So: **most overdue first, then never contacted, then longest silence, then name** for a stable
order, and **a closed plan sinks to the bottom** whatever its dates say. Never-contacted still
outranks every length of silence, for the reason it always did.

The ordering IS the feature - **do not re-sort it in the browser**, or the list and the tiles
above it become two answers to one question.

The ordering is safe here in a way it would not be for a dormant item. A wish must never sit in
`work_list()` because there is nothing to do about it until a day comes free, and an item that
nags forever teaches the team to ignore the list. A plan is the opposite: there is always
something to do, which is the whole of what the centre asked for.

## Shape and scope

- **One row per flagged placement**, not per child - that is the unit the flag is stored at and
  the unit the days and the held places belong to. A child flagged in two years is two rows, each
  true of its own year.
- **The tiles count distinct CHILDREN**, and say "children", so the figure and its caption cannot
  disagree with the list.
- **Two quiet tiles, not one total.** "Nobody has ever rung them" is a different failure from
  "nobody has rung them lately", and summing them would hide the worse one inside the commoner.
- **No year control**, the reason In progress and the Dashboard both document: a plan is being
  worked now, whichever year the placement row is against, and a year stepper is how a child goes
  quiet unnoticed. The year is a column.
- **Group by default**, and `centre_current()` refuses `centre=0` for a supervisor.
  `api_require_centre($centreId, true)` refuses it at the endpoint as well, because the tab strip
  is a display decision and `?centre_id=0` is the door. Verified with a real supervisor session
  (Tuggerah): `centre_id=0` -> *"Choose a centre."*, `centre_id=4` -> their own child,
  `centre_id=1` -> *"Unknown centre."*, and `hold_release` on a Kanwal hold -> *"Unknown
  centre."* with the row left open.
- **A board is loaded only for a centre-year that actually has somebody on a plan.** Two children
  group-wide, so reading all seven centres would spend seven Kidsoft round trips to answer a
  question about one.
- **`days` is `null`, never `[]`, when Kidsoft would not answer.** An empty day strip and an
  unknown one look identical and mean opposite things, and "this child attends nothing" is a
  statement we do not have. It becomes a **banner**, not a caption: whether the strips can be
  trusted at all is the one thing the team cannot know unaided.

## Three faults the first week found, all by measurement

- **THE NAME CHIP'S TOOLTIP SAID "Confirmed" ON EVERY CHILD IN THE APP** (15 Sep 2026).
  It printed the placement STATUS, and the board derives from Kidsoft where a booked child gets
  `confirmed` by default - all three annotation rows in the database carry it too. So the largest
  hover target in the flyout spent itself on a word true of everybody, while the one thing that
  IS different about the child sat in a 13px glyph beside their name. It now says what the flags
  say: **"On a support plan"** (narrowed to **"On a behaviour support plan"** on 17 Sep 2026,
  when a second plan made the general phrase ambiguous - the ISP chip says "On an individual
  support plan" and a child on both gets both). `chipTitle()` in `board.js`, used by the cell panel's roll and the
  room roster. **A status that is not the default still leads** - `waitlist` and `no_position`
  mean the child holds no place, and a flag must never swallow that.
  `FLAG_ICON` grew a third element rather than gaining a second map: the glyph names the thing
  ("Behaviour Support Plan", what a legend answers) and the chip says it about the child ("On a
  support plan", what a hover over a name asks).
- **THE OVER-CAPACITY NOTICE COULD NOT SEE A HELD DAY.** It gated on `held` - re-enrolment only -
  so a cell over capacity purely because a day was being HELD never fired it. Measured: hold a
  Tuesday in Shining Stars Silver, whose Tuesday is **15 of 15**, and **16 places are spoken for
  against a capacity of 15 with nothing on screen saying so** - the cell reads "Full 15/15, 1
  held" and looks entirely correct. It now counts all three promises, and `overWhy()` names
  whichever one actually caused it, because a banner that explains a held place as a re-enrolment
  problem sends somebody to chase a family who has already answered.
  **The cell's own `over` badge is deliberately untouched**: that one is about who is IN the room
  and must keep reading the same figure as `placed` and `pct`. This notice is the one place all
  three promises are added up.
- **`support_plans_holds()` KEYED A HOLD ON THE CHILD AND YEAR, WITH NO ROOM.** A child can be
  booked into two rooms in one week (~2% of real Tuggerah data), so a plan in one room showed the
  OTHER room's held days. Caught with a probe row that picked up a hold from a room it had nothing
  to do with. The doc comment above the function already said "a hold belongs to a room-year";
  the key did not.

## THE CHILD REMOVED FROM CARE (15 Sep 2026)

Broc, 10 Sep 2026: *"The child has been removed from care pending an improvement in behaviour."*
That is a plan with **no bookings at all**, not one dropped day - and it is the commoner shape.
Measured with a probe row the same week, it was the case the first build served worst:

- The card said **"days unknown - Kidsoft would not answer"**. Kidsoft had answered perfectly;
  the child simply has no bookings. **`null` now means unknown and `[]` means none**, and the
  card says "not booked on any day in this room".
- **A day could not be held for them at all.** `hold_save` resolves the child against the derived
  board - correctly, so a place cannot be held for somebody who is not in the room - and a child
  with no bookings is not on it. It answered *"That child is not on this room's board"*. There
  was no roster row to click either, because the roster IS the board.

So the place a removed child is waiting to come back to could not be reserved, which is exactly
when it most needs reserving. Both halves were built:

### 1. The hold is recorded from the Support plans card

`hold_save` falls back to the **annotation row** when the board cannot show the child. That row
is the record of such a child - it carries their flags and survives whatever Kidsoft holds - and
it is **only ever the fallback**: the board wins whenever it can show them, so a booked child is
still resolved against reality rather than against our own table. Still keyed on room AND year,
so a posted id cannot reach a row in another room or another year, and `api_require_centre()`
still runs first off the room.

The dialog lives on the Support plans card because **for this shape of plan there is nowhere
else**: the board records a hold from the empty box on the room roster, and a child with no
bookings has no roster row and no box. Same endpoint, `/api/board hold_save` - a second way to
write a hold would be two implementations of one record.

**It is disabled when `days` is null.** If Kidsoft will not say which days the child attends, a
form offering all five might hold one they are booked on and take a second place off that cell.

### 2. They appear on the room roster, so the held place has an owner

A tinted row under the roll and the offered families, padlocks on the held days, `0` in the Days
column - they attend none. Directly above the Placed totals, and footnoted there.

- **GATED ON AN OPEN HOLD, never on "has an annotation and no bookings".** An annotation row
  outlives a child's time in a room (they move up, the row stays), so the looser test would
  resurrect children into rooms they left - the stale-copy failure this app deleted 870 rows to
  end. A hold is a live, deliberate statement that ends when the booking returns.
- **They are NOT added to `r.placements`.** The rows are gathered from the five cells'
  `reserved_children`, exactly as the offered rows are gathered from `promised_children`, so the
  roster's child count, the Placed totals and **`board_trading()`** - which counts
  `count($r['placements'])` to decide whether a centre trades at all - read precisely what they
  read before. That is what made half 2 cheap; adding rows to the roll would have been a decision
  about what the board IS.
- **The name is a plain span, not a `.place-chip`.** A chip is a button that opens the placement
  editor through `findPlacementByKey()`, which searches `r.placements` - and these children are
  deliberately not in it, so the click would silently do nothing. A control that fails is worse
  than no control.
- **Tinted, not faded.** `.rs-noplace` is faded because those rows hold nothing; these hold a
  place, and fading them would say the opposite. Same reasoning as the offered rows.

**A hold in a full room makes it over capacity, and the notice says so.** Holding Mon/Tue/Wed for
a removed child in Shining Stars Silver, whose Tue and Wed are 15 of 15, produced *"2 room-days
owe more places than they have"* naming the held place as the cause. That is correct and it is
policy: over capacity warns, never blocks.

### The day strip stopped answering a question nobody asked

`dayStrip()` treats a strip carrying `held` as neutral, like one carrying `plain`. Its off boxes
were titled "Monday - not asked for and not attending" on a screen where nothing had been asked
for - a reply to a question that belongs to the waitlist, on a card about a place being kept.

## THE PLACEMENT EDITOR'S FLAGS

Five checkboxes in one wrapping row, laid out to their own label widths - 72, 107, 182, 192 and
187px - so nothing aligned and Individual Support Plan was orphaned on a line of its own. Tidied
18 Sep 2026.

**Two rows, and the break IS the grouping.** The first three (Sibling, Staff's child, Needs two
educators) are things that are TRUE OF THE CHILD and mark them on the board. The two support plans
are a different kind of thing and get the second line to themselves.

**No columns to align and nothing to explain.** A first attempt made it two columns filled
downwards and hung a line under the pair saying *"Either one lists this child on Support plans."*
Both went: where the line breaks already says these are two kinds of flag, and the caption was the
kind this app deletes rather than writes.

**The inputs are untouched** - same names, same ids, same values - because `board.js` reads
`#f-sib`, `#f-staff`, `#f-two`, `#f-beh` and `#f-isp` by id when it opens the editor, and
`api/board.php` reads the names on save. Verified by round-tripping a probe: ticked Sibling and
Individual Support Plan, saved, and the row came back `is_sibling=1, has_individual_plan=1` with
the other three 0. Every label is still bound to its own input.

Two plain wrapping rows need no breakpoint of their own. Checked at 390, 768 and 1280px: the
break holds, no label wraps, and there is no horizontal page overflow at any of them.

## The renames

### `2 x educators required` -> `Needs two educators` (18 Sep 2026)

The one flag label written as a SPEC rather than as something true of the child, and the only
place in the app using the `2 x` notation. Asked for while tidying the editor's flags, on the
grounds that it did not read with `Sibling`, `Staff's child` and the two plan names beside it.

**Two live strings, the same pair the earlier rename had**: the checkbox in `placement.php` and
`FLAG_ICON` in `board.js`. `_incl/retired/` carries no mention, so there was nothing to leave
alone this time.

**The glyph name and the name chip are now identical**, which `staff` already was. `FLAG_ICON`'s
two texts are deliberately different where the distinction earns it - the glyph names the thing a
legend would answer, the chip says it about the child - and `Needs two educators` is the same
sentence either way. Verified on a probe: glyph title `Needs two educators`, chip
`Sibling here. Needs two educators`.

## The rename

`Behaviour plan` -> `Behaviour Support Plan`, asked for so that every educator uses the same
words with families. Two live strings at the time: the checkbox in `placement.php` and `FLAG_ICON` in
`board.js`. `_incl/retired/rollover.js` still carries the old label and was left alone - it is
the graveyard, nothing loads it, and editing it would be the first time anything has.

The stored column is still `placements.has_behaviour_plan` and the flag key is still
`behaviour`. **Renaming a column to match a label is how a rename becomes a migration**, and
nothing outside the two strings above is user-facing.
