# The work layer: In progress, work items, offers, contact log

Governs `progress.php`, `api/work.php`, `static/js/progress.js`, `_incl/work.php`,
`_incl/work-modal.php`, `static/js/work-modal.js`, `_incl/offer-policy.php`.

**This is the part Kidsoft does not do**, and it is what the spreadsheets actually were.

## The measurement that justifies the whole project

Pairing Kidsoft's two dated enrolment events across 339 families since Jan 2025 - waiting
list entry added, then enrolment form finalised - the gap runs a **median of 21 days**, with
43% longer than 30 days, 24% longer than 90 and the longest 463. **For that entire stretch the
only thing Kidsoft says about the family is `Waitlisted`.** And of **1,593** families who ever
raised a waiting list entry, only **604** ever finalised a form: roughly a thousand went
somewhere and nothing recorded where.

That gap is why the spreadsheets outlived this app - the app had rebuilt the half Kidsoft was
already doing and never built this half at all. Before this: `offers` 0 rows, `communications`
0 rows, `tour_booked_at` / `guide_provided` / `orientation_at` 0% populated. **The funnel was
modelled in the schema and no screen was ever built for it.**

> Those three tour columns read 0% for a reason nobody had checked until 14 Aug 2026: **they
> mirror a spreadsheet sheet that is itself empty.** See [Tours](#tours-14-aug-2026) below --
> and do not rebuild from them.

> **Status check, 10 Sep 2026: THE LAYER IS IN USE.** `work_items` holds **13** rows across
> Kanwal, Terrigal and Wamberal - offers, acceptances and two tours - and staff are creating and
> cancelling items by hand during the day. The note below is the state it shipped in; keep it for
> the warning at the end of it, which still stands.
>
> **Status check, 14 Aug 2026:** `work_items`, `work_item_days`, `work_item_events`,
> `communications` and `offers` all still hold **0 rows**. The layer is built and reachable;
> it has not yet been exercised in production. Expect the first real rows to find bugs no
> screenshot can. (Tours were built and driven end to end against seeded rows on 14 Aug; the
> rows were removed afterwards, so the diary starts empty like everything else here.)

## The model

A work item is **one candidate family being worked toward one spot** (centre, year, room,
weekdays), moving through
`identified -> attempted_contact -> contacted -> tour_booked -> tour_done -> offered -> accepted -> enrolled | lost`
(`attempted_contact` since 22 Oct 2026 - see "The enrolments meeting" below).
**`WORK_STAGES` is the single place that ordering is written down.**

Two kinds of candidate: a NEW family from the queue (`waitlist_id`), or a child ALREADY in the
room being sold an extra day (`kidsoft_child_id`).

### DONE IS DECIDED BY KIDSOFT, NOT BY A CHECKBOX

`work_autoclose()` closes an item when the derived board shows that child in that room on
**every** day the item was working toward. `/api/work` **refuses `stage=enrolled` from a human
outright.** The organisation is paid for a real child in a real room, so that is the only
honest definition - and a stage somebody forgot to advance would otherwise read as a family
still being worked.

- **Matches on `kidsoft_child_id` ONLY, never a name.** That asymmetry is deliberate:
  **closing the wrong family's item says a place is filled when it is empty**, and the empty
  place is the thing the organisation exists to notice. A miss costs a manual close.
- **A QUEUE FAMILY'S ChildID ARRIVES AT THE CONVERSION, and the item now reads it**
  (17 Aug 2026). This used to say a queue family has no ChildID so those never close
  automatically, which was true of a family who is still waiting and false about the only
  moment that matters. **Measured over all 1,699 waitlist rows group-wide, cross-tabbing
  `Waitlist/getWaitingList.Status` against `ChildID`: 17 of 17 at `Enrolled` carry both a
  ChildID and a GuardianID, against 2 of 696 `Waitlisted`, 2 of 963 `ExpiredWaitlist` and zero
  everywhere else.** Kidsoft issues the id when the application becomes an enrolment, so it
  lands on the application row at exactly the point the item is finishing.
  `work_autoclose()` reads it through the item's `waitlist_id`
  (`COALESCE(w.kidsoft_child_id, a.kidsoft_child_id)`) rather than copying it onto the item:
  `work_items` says a family is EITHER a queue application or a child already in care, and
  stamping the id on would blur which kind of work it is for a fact one join away.
  **See [waitlist.md](waitlist.md) for the import half** - the id was arriving on a read the
  import threw away, so 9 families we had tracked through the queue had enrolled and still
  read NULL here.
- **FOR A QUEUE FAMILY, KIDSOFT'S `Enrolled` IS THE CLOSE (16 Oct 2026, Broc's call).** This
  section used to say the opposite ("being visible in Kidsoft is not the close") and hold the
  item until the board showed the child booked on **every** day it wanted. Broc: *"once the
  waitlist child becomes imported on Kidsoft 'Enrolled' [as it] normally shows on the waitlist.
  Can that be for the in progress too? Or move to 'Completed' automatically?"* - asked which,
  "close it straight away". What the old rule cost: the Waitlist said Enrolled the moment the
  import saw the conversion while In progress kept listing the family, because the sweep ran
  **only when somebody opened the Dashboard** and waited on next year's bookings being entered.
  Measured that day: all five items ever closed had closed through the board rule, Jack Donnelly
  five days after Broc himself marked his tour done. The team's part ends at the conversion;
  booking the days is the centre's, and a day the family still wants arrives as a wishlist
  request like any enrolled family's.
  - **`work_close_kidsoft_enrolled()`** closes every open item whose application has
    `kidsoft_status = 'Enrolled'`, with the note "Closed automatically: Kidsoft shows X as
    Enrolled." Reads only our table, so it is free on any load. Called from **the waitlist
    import** (the read that stores the status, so both screens change on one import - counted as
    `work_completed` in the nightly log), **`/api/work` `list`** and **`/api/dashboard` `load`**.
  - **`work_autoclose()` stays for the other kind of item**: a child already in care being
    worked toward an extra day has no application to read, so the board is still the only thing
    that can say it is done. For a queue family it is now the slower of two closes.
  - **"Completed" is a chip on In progress** (`?filter=completed`): `stage=enrolled` with closed
    items included, **newest close first**, and **one tile** - the four clocks could only read 0
    on a list that is closed by construction. Verified on a rolled-back transaction against Baby
    Collings (Kanwal, at `offered`): closed once, second sweep closed nothing, top of Completed.

- **A LEFTOVER APPLICATION DOES NOT CLOSE ITS ITEM** (6 Nov 2026). The close reads the item's own
  application, so an item started on one of a family's duplicate applications stays open when a
  DIFFERENT one converts - Lennox Dransfield at Wamberal, item 58, still at Tour done after his
  #1030 enrolled and his #771 (the item's) was archived. Asked whether to close these
  automatically, Broc asked for a **flag** instead: the item carries the Waitlist's `duplicate`
  (red/amber, row and modal) - see "Duplicate waitlist" in [waitlist.md](waitlist.md). An item
  whose application has been ARCHIVED shows no flag (the archived row is out of the comparison):
  a person closes it with **Archive**, below.

### ARCHIVE: closed without an outcome (6 Nov 2026)

`work_archive()` / `/api/work` `archive` / `#work-archive` in the work modal's footer. Broc: *"We
need to provide a way for staff to 'Archive' in progress items"* - and Lennox Dransfield's item 58
was the first, archived through the dialog that day with the reason on its history.

**The THIRD exit, and it is not either of the other two:**

| exit | means | history |
|---|---|---|
| Created by mistake (`work_cancel()`) | a mis-click; refused once anything happened | deleted |
| Lost | we chased the family and did not get them | kept, counted as lost |
| **Archive** | no outcome: a duplicate or leftover application, an item that should not exist | kept, counted as neither |

Lennox enrolled - through another application - so Lost would have put a family we GOT into the
record as one we did not, and Enrolled is Kidsoft's to say.

- **A STAGE, `archived`, in `WORK_STAGES` and `WORK_CLOSED_STAGES`** and the `work_items.stage`
  ENUM, so it reuses every close mechanic (closed_at, off the open list, under the All chip
  greyed with "archived <date>"). **The SQL spells the closed list out ten times as
  `NOT IN ("enrolled","lost","archived")`** - a new terminal stage means grepping all of them,
  `schema.sql`, and the `w.stage` tests in `progress.js`, `dashboard.js` (pipeline excludes it like
  Lost) and `work-modal.js`.
- **A BUTTON, NOT A STAGE TO PICK.** Left out of the modal's stage list unless the item is already
  archived; `advance` refuses `archived` ("Use the Archive button"). Shown only on an OPEN item
  that is NOT pristine - a pristine one's honest exit is "Created by mistake".
- **A plain `prompt()` for the reason**, optional, pre-filled with the Waitlist's duplicate label
  when the item carries one. Stored as `outcome_note` and on the event, as Lost's reason is.
- **REOPENED like Lost:** pick an earlier stage and save; `work_advance()` clears `closed_at`.
  Verified on a rolled-back transaction before item 58 was archived for real.
- **The tour diary treats it as Lost:** archived before the visit removes the Outlook entry
  (`tour_calendar_wanted()`); a visit already past keeps it. A sibling's open item is not affected
  - `work_siblings_here()` reads an archived sibling as neither open nor lost.

### Other model rules

- **`work_create()` is idempotent** on (who, room, year) while an item is open - two staff
  opening the same cell on the same morning is the obvious way to get duplicates. **It
  de-dupes the FAMILY, never the SPOT** (see `work_by_cell()` below, which is the half it does
  not cover).
- **Moving BACKWARDS is allowed.** A family who declined but stays interested goes back to
  `contacted`; refusing that would mean closing the item and losing its history, which is what
  the spreadsheet did badly.
- **The work list is ordered by NEGLECT, not by age** - overdue first, then longest idle. An
  item raised yesterday and already chased is in better shape than one raised a month ago and
  forgotten. "Nobody has touched this family for three weeks" is the failure mode nothing
  could previously see.
- **`child_name` is denormalised deliberately**, so a closed item still names the family after
  the application is archived. **History must not depend on a live join.** Relatedly, "an item
  must name somebody" is enforced in `work_create()` and **NOT by a CHECK**: MariaDB refuses a
  CHECK on a column that is also an `ON DELETE SET NULL` foreign key, and it is right to,
  because the two genuinely conflict.

## In-flight work is visible on the cell (`work_by_cell()`)

Nothing stopped two staff working two families toward one place. **Only a formal offer HOLDS a
place**: `room_day_vacancies()` subtracts pending offers as `promised`, and an accepted one
keeps the seat through the provisional placement `respond_offer` writes. Everything before the
offer holds nothing, deliberately - and that pre-offer window is the paperwork, the checks and
the tour, **a median of 21 days, 43% over 30**. For that whole time the cell rendered the same
free count and no screen said a colleague was on it.

The per-candidate badge `cells_year()` already attached does not cover this: it tells you about
a family you are looking at, so it stops you starting the SAME family twice and says nothing
about a colleague working a DIFFERENT family toward the same seat. `fill_suggestions()` knows
nothing about work items either.

- **ADVISORY, NEVER A LOCK.** `free` is deliberately unchanged. A family who goes quiet at
  `contacted` would otherwise sterilise a place indefinitely - the same reason over-capacity
  warns rather than blocks, and the offer transaction stays the single authority on what is
  promised.
- **STAGES AT OR PAST `offered` ARE EXCLUDED**, and that is the load-bearing line. Those are
  already counted against the cell, as `promised` or as occupancy, so listing them here as
  well **reports one family twice against one place** - the double-count this exists to
  prevent, in the other direction. Verified: at `contacted` the cell reads 1 with `free`
  unchanged at 2; moved to `offered` it reads 0.
- **It composes `work_list()` rather than querying again**, so the stage shown against a cell
  is the same object the module and the panel show. No extra query.
- **It sits ABOVE the panel's four blocks without joining them.** Those are ordered by policy
  and must not be reordered; this qualifies the FIGURES in the subtitle.
- **No grid dot.** The dot means "look here" and its slot is spoken for by grantable > asked >
  candidate; this is the opposite fact ("somebody is already on it").
- **An item with no room cannot be attributed to a cell and is skipped.** Those are the ones
  raised from the Waitlist, which sets no room on purpose. They stay visible on In progress
  with `--` in the Room column. **That hole is accepted, not solved** - but since 14 Oct 2026
  they are COUNTED on the board: see below.

### The board shows it in two more places (14 Oct 2026)

Asked: *"If someone is In progress can that be visible in the Placement board?"* It was, in one
place - an amber note in the cell panel - and only for items carrying a room, which on the day was
**6 of 15 open items**. A click on a room (the roster) showed none of them.

- **The room roster lists them**, tinted, one row per item, under the roll and the offered
  families: name (opens the enrolment), stage and owner underneath, a muted `fa-list-check` on each
  day being worked toward. Gathered from the five cells' `in_progress` exactly as the offered rows
  are gathered from `promised_children`, so the `offered`-and-past exclusion above still holds and
  nobody is listed twice. Not in the Placed totals; a footnote says so. See
  [placement-board.md](placement-board.md).
- **The summary tile "In progress" replaced "Can be granted"**, from `cells_year()`'s `enrolling`:
  `work_progress_list()` for the centre and the board's year - **the same rows the In progress
  screen lists**, roomed or not - as **places** (weekdays summed, the unit of "Places free" two
  tiles left) with the families under them. A null-year enquiry is not counted on any year's board;
  it has not named one.
- **Still no grid dot**, for the reason above.

## The In progress screen - UAT found it the hard way

**NO PERCENTAGE ON THIS SCREEN, and it is not an oversight.** Checked 10 Sep 2026 while the fill
figures were being fixed. The five tiles are counts (In progress, Offers lapsing, Tours to
answer, Past their date, Gone quiet) and the table's only numbers are days and dates. The figure
that would naturally appear here is a **conversion rate**, which is refused on measured grounds
- see [dashboard.md](dashboard.md) and the note at `work.php:1064`. So there is nothing here for
`board_pct()` to fix, and **a rate arriving on this screen is the thing to stop**, not a
rounding.


**A UAT tester clicked "Start working this" by accident and could not find what they had
made.** They were right to struggle. The work layer was built 6-7 August with an engine, an
API, a display and a modal, **and no way to reach any of it**: the only route to an item was
Placement -> the right centre -> the right year -> the right room -> the right weekday cell ->
scroll -> Update. **Eight steps, every one of which needed you to already know which cell it
came from.** `work_items` had never held a real row, which is what an unreachable feature looks
like in the data.

What the audit found, all now fixed:

- **The Dashboard's work table was not clickable.** The one screen that listed work gave you no
  way to open any of it. That single defect made everything else moot.
- **No group view at all.** `work_list()` required a centre, so "what is in flight across the
  organisation" could not be asked - on the screen the team opens the day on.
- **Closed items were unreachable.** `include_closed` existed in the engine AND the API and
  nothing in the browser ever passed it, so an enrolled or lost family took its whole contact
  history with it.
- **The card said "Work needing attention" and listed everything open.** The `overdue` filter
  existed and was never passed.
- **`owner_email` was written on every item and displayed nowhere.**
- **No cancel.** `lost` was the only exit, which files a mis-click as a family the team chased
  and failed to get - a fiction in the only record of what actually happened.

### The module widened rather than a seventh one being added

Broc, 10 Aug 2026: the sidebar *"is getting a bit crowded already"*. The right home was already
there: **an offer is one STAGE of a work item.** `WORK_STAGES` contains `offered`, and
`work_link_offer()` advances the item into it - so the Offers module was showing one stage of
the work layer and calling it the whole subject. And the user's instinct proved it: **in UAT
somebody went to Offers looking for the work in flight.** Offers became **In progress**;
`/offers` 301s to `/progress`.

- **ONE LIST, STAGE AS A COLUMN** (Broc's call, over a deadlines-first split). The risk that
  buys is a lapsing offer being pushed down a long page, so **`work_urgency_sort()` puts every
  hard deadline above every soft one**: a live offer (soonest first), then a next action past
  its date, then everything else by neglect. **Nothing without a clock can outrank something
  with one.** Verified: offer at 48h, offer at 216h, a 16-day-overdue follow-up, then a
  25-day-idle item.
- **A UNION, NOT A SUPERSET - and now a BACKSTOP rather than the main path.**
  `work_link_offer()` used to return early when nobody was tracking the family, and offers are
  made from the Waitlist, so an offer with no work item behind it was the **normal** case.
  Listing only work items would have silently dropped live offers off the one screen that
  chases them. They render as `untracked`, faded, and click through to the Waitlist. **Offering
  now CREATES the item**, so the union covers only offers made before that change; keep it, and
  expect it to render nothing on a fresh queue. The
  `LEFT JOIN work_items w ON w.offer_id = o.id ... w.id IS NULL` is what keeps a linked offer
  from being listed twice - **do not "simplify" that join to a stage test.**
- **THE WHOLE ROW IS THE CONTROL, and there is no actions column.** There was one, holding an
  "Answer" link - eight columns overflowed the content area and put the row's only action off
  the right-hand edge, the same see-it-but-not-reach-it failure the module exists to fix. It
  also made some rows clickable and others not for no visible reason.
- **`work_cancel()` is a real delete and is refused once the item has a history.** An offer
  attached, a stage past `identified`, more than one event or any logged contact and it says so
  and points at Lost instead. **Enforced server-side, not just by hiding the button** (verified:
  a worked item refuses the POST).
- **A figure may not contradict its own caption.** With closed items shown, the lead tile
  becomes "Shown / including closed" rather than "In progress / families being worked", and a
  closed row's urgency reads "lost 10/08/2026" rather than the "0d" its idle counter produced -
  which looked like a live item somebody had just touched.
- **THE MODAL IS SHARED, and extracting it was the point.** It lived inside `board.js`, which
  is precisely what made a cell on the board the only door into the work layer. One copy of the
  markup (`_incl/work-modal.php`), one of the behaviour (`static/js/work-modal.js`), loaded by
  **Placement, In progress AND Waitlist**.
- **The work_* actions live in `api/work.php`**, not `/api/dashboard`. They were under an
  endpoint named after a screen the placement board is not on. `api/dashboard.php` now has one
  action, `load`.
- **"Mine" is resolved from the SESSION, never the query string** - whose work an item is, is
  not something a URL gets to assert. (Which CHIP is on does travel in the URL; see below. The
  distinction is that `?filter=mine` asks for "my items" and the server still decides who
  "my" is.)
- **THE CHOSEN FILTER LIVES IN THE URL (`?filter=`), not only in the browser** (16 Sep 2026).
  It was client-side state initialised to `open` on every load, and two things fell out of
  that: **switching centre reset it**, which is the one navigation somebody chasing tours
  across the group makes constantly, and **the Dashboard's "Open the tour list" could only land
  on Open**, with the tours it had just listed nowhere on the screen it sent you to.
  - `progress.php` owns the whitelist, validates `?filter=`, renders the matching chip
    `checked`, and passes it to `layout_header` as `params` so **the centre tabs carry it**.
    `progress.js` reads its starting filter off the checked radio rather than parsing the query
    string, so there is one list of valid values, not two.
  - **`open` is the default and is left OUT of the URL**, so a plain `/progress` is the plain
    list and an unknown value falls back to it.
  - On a chip click the script `replaceState`s the URL **and rewrites the centre tabs' hrefs**.
    Both halves are needed: the tabs are ordinary server-rendered links built at page load, so
    without the second the very next centre switch would throw the filter away again - which is
    the original complaint.
  - **A link out of a card lands on the question the card asked.** `progLink()` in
    `dashboard.js` takes a filter: the two tour links pass `tours`, and the work card - whose
    rows are `overdue:1` - passes `overdue` and is labelled "Open the overdue list". It used to
    say "the full list" and open everything, which threw away the filter the card was built on
    at the moment somebody followed it. **The label and the filter move together**; the one
    link that still passes nothing is the empty card's "See everything in progress", which
    means what it says because there is nothing overdue to show.
- **There is no year control.** Open work is open work, whichever year it targets. A family
  being chased today for a January start is work today, and **a year stepper is how an item
  goes quiet unnoticed.** The year each item targets is a column.

### Column widths - a different answer from the Waitlist

In progress was **never overflowing** (0px at 1280, 1440 and 1920 in both views), so there is
no dropdown here and there must not be. What the measurement found was width spent on the wrong
columns. Natural widths: Child 187, Centre 77, Room 227, Days 138, Stage 156, Urgency 166,
**Next step 371** against 1030 available - so something has to wrap and the only question is
what.

- **NEXT STEP IS THE COLUMN THAT SHOULD WRAP, and it was holding the most width** while the
  other two broke. It is free prose and reads fine over two lines. **A room name is an
  identifier** - "Amazing Asteroids / Silver" across two lines reads as a different room --
  and **urgency is the most time-critical text on the screen**, so "offer expires in 47 hours"
  belongs on one line.
- **THE GROUP VIEW SPENDS IT DIFFERENTLY, because it carries a seventh column.** Handed the
  centre view's widths it left Next step 128px, which wrapped one real next step over **five
  lines** and took the row to ~100px. On the group view Room gives back 3rem and Next step
  takes it. **Two views are different shapes, not one table filtered.**
- **Stage is left at 7rem, below its 156px natural, deliberately.** That figure is the
  `untracked` row carrying a second badge, and since offering now raises the item that shape is
  legacy. Buying 10rem on every row to keep a vanishing one on one line is the wrong trade.
- Measuring it needed **seeded rows** - `work_items` holds 0 in live data, so the screen
  renders its empty state and there is nothing to measure.

### "Last interacted" (6 Nov 2026)

Broc: *"a 'last interacted with' column ... so we can see when someone last modified the entry"*.
`work_last_touched()` -> `last_touched_at` / `last_touched_by` on every `work_list()` row; the
date on the row, the first name under it, the full time and address on the title.

**The Waitlist shows the same value as "Contacted"** (11 Nov 2026) - `waitlist_last_touched()`
reads it off the item, and both screens render it with `window.lastTouchedCell()` in `app.js`.
Change the rule here and it changes there; see [waitlist.md](waitlist.md), "Contacted".

- **A PERSON ONLY.** The latest of: an `audit_log` row on the item whose `staff_email` is an
  address (every stage move, next-step edit, tour, invite, archive), or a contact note on the item
  or its family's application / child (`communications.created_at`). Kidsoft's automatic moves
  and the nightly jobs write `kidsoft` / `system` and do not count. **Not `updated_at`** - ON UPDATE
  CURRENT_TIMESTAMP moves on every calendar sync and Kidsoft-driven stage change.
- **`idle_days` NOW READS IT TOO.** It read stage events alone, so a next step edited or a call
  logged left "N days quiet" running - Milla Mae Duffy was touched on 6 Nov and still counted from
  30 Oct - which with the new column on the same row is a row contradicting itself. It is now the
  later of the last event and the last person's touch. Kidsoft's moves still count toward it (a
  family whose offer was just sent is not quiet) while the column names only a person. This
  changes the order (work_urgency_sort) and the "Gone quiet" tile slightly, in the honest direction.
- **WIDTHS, re-measured at 1280 over every centre, open and All:** the column is ~94px and the
  table had none spare (1044 / 1030 on a centre, 1137 on All centres). Paid by Child 11.5 -> 11rem
  and Urgency 12 -> 11.25rem (both declared above their content: longest name 157, longest
  urgency "tour 13/11/2026 09:30 AM" 161, plus padding), and on All centres Room 10 -> 8rem, Next
  step 10 -> 8rem and Stage 7 -> 6.5rem with the badge allowed to wrap. Result: **1030 in 1030 on
  every view**, centre rows unchanged at 62px, All-centres rows 62 -> 66 mean (80 max, a room name
  on three lines). **The first name only** - "Ashleigh.Howell" took the column to 117px and
  Kanwal's All view 17px over.

## Tours (14 Aug 2026)

**Tours are ours, and that was measured before a line was written.** "Tour" and "orientation"
appear **zero times** in the whole Kidsoft API reference - 15 controllers, 30 `get*` actions,
65,641 bytes of `Documentation/getMarkdown` - and no notification category covers one. In
Kidsoft a tour exists only as prose somebody typed into a waitlist note ("Thanks for touring on
Friday"). Readable by a person, countable by nothing.

**And the spreadsheet was not doing it either, which is the finding that shaped the build.** The
`Enquiries-Tours` sheet survives in both archived Tuggerah workbooks
(`/var/lib/crm-archive`, snapshotted 21-24 Jul 2026): twelve ruled columns - Parent
Name, Contact Details, Lead Source, Child Name, DOB, Requested Days, Tour Booked, Consumer Guide
Provided, Offer of Enrolment Sent, Follow up Phone Call, Orientation Day Booked, Comments - and
**0 rows with any content in either the 2026 or the 2027 workbook.** Those are Tuggerah's only;
the other six centres' workbooks were never on this box.

That is exactly why `waitlist_applications.lead_source` / `tour_booked_at` / `guide_provided` /
`orientation_at` have always read 0%: **they mirror an empty sheet.** Do not re-propose those
twelve columns. They are the spreadsheet's guess at the job, not the job.

Broc, 14 Aug 2026: book the tour, then record what happened - a date and time when the parent
rings, a list so nobody forgets one, and toured / did-not-show afterwards. **Centre only, no
room, no year.**

### A tour enquiry is a THIRD kind of candidate

A parent who rings has no queue application and no Kidsoft child (a ChildID is issued when an
application converts to an enrolment), so neither existing id can name them. `work_create()` now
accepts a third case: a `child_name` plus **at least one of `contact_phone` / `contact_email`**,
so an item can always be rung back.

**AND IT IS NOT A `waitlist_applications` ROW, though that table already supports
`source='manual'`.** `waitlist_for_centre()` assigns queue position by counting down every
application in the centre - which is why the open/closed filter had to move out of its WHERE
clause - so filing an enquirer there would hand them a place in line and renumber everyone
behind them. **That number is the one thing on the queue screen a parent might be told over the
phone.** A tour is not a place in the queue.

- **`year` is now NULL-able.** Somebody ringing in August about "sometime next year" has not
  decided, and a required field nobody can answer gets filled with a guess that then reads as
  fact. Every reader already treated a null year as "every year" (`work_list()`,
  `work_pipeline()`), so this only widens what a ROW may say to match what a QUERY could already
  ask. **`work_create()`'s de-dupe uses `year <=> ?`**, or a null-year item never matches itself.
- **An enquiry cannot be de-duped and it is not attempted.** No id, and matching on a typed name
  would merge two families - the "TBA Anderson" / two "TBA TBA" trap `waitlist-group.php`
  measured. Two staff taking one call make two rows, which a human can see.
  **What IS caught is the next section** - an enquiry for a family already in the queue.

### One family, two rows: an enquiry and an application (14 Oct 2026)

Reported from Terrigal: *"I have 2 of the same child In progress... Seren Willow Jones."* At
09:09 a tour was booked for her as a **new enquiry** (typed name, phone). Her application had been
in the queue since **30 Jul under the same phone number**. At 09:44 a colleague started that
application from the Waitlist. Nothing joined the two: `work_create()` de-dupes on an id, and an
enquiry has none.

**Measured before it was guarded: BOTH enquiries ever booked were families already in a queue** -
Seren at the same centre, and Albie Keogh, whose email matches an application at another centre.
The form was being used for queue families because nothing on it said they were in the queue.

**The contact finds; a person decides.** Seren's phone also matches her brother Jacob's
application, so a contact match is evidence of a FAMILY, never of a child - the family-merge rule
from the other side. Compared with `sibling_phone_key()` / `sibling_email_key()`, the one
normalisation of a parent's contact (`+61 481...` and `0481 ...` are one number).

| Door | Server answers | The person picks |
|---|---|---|
| **Book a tour** (In progress) | `queue_matches` - open applications at that centre sharing the phone/email, each with its open item | **This child**: book on their row (`tour`) or, with no item, `create` on the application. **None of these** re-sends with `queue_checked=1` |
| **Any "start this family"** (board, Waitlist button, family modal, tour from the family modal) | `enquiry_matches` - open enquiries at the centre sharing the application's contact | `confirm()` per match, naming both children. OK sends `join_enquiry=<id>`; Cancel sends `enquiry_checked=1` |

- **The second door is the one that will keep happening.** A family who tours first and applies in
  Kidsoft afterwards is the normal order, so once the form learned to look, an enquiry made BEFORE
  the application is the remaining way in.
- **`work_join_enquiry()` keeps the ENQUIRY'S row** - it is the older record of the same
  conversation, with the tour and the history - and gives it the `waitlist_id`. The caller's year,
  room and days fill whatever it left empty and overwrite nothing, so a family joined from a board
  cell lands on that cell; a tour posted with the join moves the one in the diary.
- **`join_enquiry` is re-checked against the match list**, never trusted, so a post cannot join an
  arbitrary item to an application. Refused with "That enquiry does not match this family."
- **Notes logged against the enquiry are NOT re-filed onto the application.**
  `work_contact_history()` still finds them by item id. Re-filing would put them in the nightly
  Kidsoft copy, and a join made on a phone match is the evidence the `createComment` rule refuses
  to write on.
- **All four "start" buttons already went through `window.postConfirmingDne()`**, so the second
  question lives there and not in four callers. It asks `dne` first, then this.
- **Nothing joins existing rows retroactively.** The pair that prompted it was merged by hand on
  Broc's say-so the same day: `work_join_enquiry(63, 499)`, then `work_cancel(66)` (Identified, one
  event, no contacts), and a `work.merge` audit row naming both. Item 63 kept its owner (the
  centre account that booked the tour).

Verified: every refusal writes nothing (items/events/audit counts unchanged); the join driven end
to end on synthetic rows, deleted afterwards; both `confirm()` answers driven in Chromium against
Jacob's real application, with the follow-up post captured rather than sent.
- **Contact details render only for an enquiry.** A queue family's phone number is in Kidsoft;
  empty boxes here would invite re-keying it, which is the thing this tool exists to remove.
- **`communications` gained a third key, `work_item_id`.** `work_contact_history()` looks up by
  WHO, and an enquirer is neither a `waitlist_id` nor a `kidsoft_child_id`, so every call made to
  them was previously unfindable. Used **only** when the other two are absent.

### A NO-SHOW IS NOT A STAGE

`tour_at DATETIME` and `tour_outcome ENUM('toured','no_show')` are columns, not stages.
`WORK_STAGES` is rank-ordered and read by `work_stage_rank()` everywhere, and **a family who did
not turn up is not further along than one with a visit booked** - it is the same place with a
wasted appointment. A no-show sends the item back to `contacted`, exactly what
`work_offer_answered()` does with a declined offer, for the same reason: **losing a family is a
decision somebody makes, not something a missed appointment does.**

`tour_at` is its own column and not derivable from `work_item_events`, because a stage change
timestamps **when somebody clicked, not when the visit is** - "tours this week" cannot come out
of the event log.

### A tour is the SECOND hard clock, and `work_urgency_sort()` says so

That function's comment used to call an offer the only externally-imposed clock in the app. A
tour is one too: a parent arrives whether or not anybody opened this screen. Five bands now:

1. a live offer, soonest to lapse
2. **a tour that has HAPPENED with no outcome recorded**, oldest first
3. **a tour coming up**, soonest first
4. a next action past its date
5. everything else, longest idle

Band 2 outranks band 3 because an unanswered tour is **how a tour vanishes** - the appointment
is gone and nothing will ever ask again. An upcoming one still has the diary working for it.

### Two bugs the build found, both only visible by driving the browser

- **The modal's stage select fought the automatic promotion.** The save does two writes, and
  `update` routes the tour through `work_set_tour()`, which advances the item to `tour_booked` --
  then `advance` posted whatever the select was showing when the modal opened and dragged it
  straight back. Booking a tour on a family at "Identified" left them at "Identified" with a tour
  in the diary, and the history read "Tour booked" then "Identified" one second apart. **Fixed in
  the browser by bumping the posted stage, not by swapping the two writes**, because the note the
  person typed belongs on the stage change and an advance that no-ops records nothing. It is a
  **rank test**, so a family already at `offered` is never demoted, and the ordering is read from
  the server's `WORK_STAGES` rather than retyped.
- **A note on an item that did not move was being thrown away.** Pre-existing, and booking a tour
  made it the common path. `work_advance()` returns early at the same stage and the note goes with
  it, so "already at that stage is swallowed" was true of the ERROR and also of the note, which
  survived only if a channel happened to be picked. `/api/work` now logs it as an event against
  the stage it is already at.

### The Waitlist books the same tour (10 Sep 2026)

Broc: *"could the In progress 'tour booked' align with the waitlist when you click on their name
and there is a 'tour' spot?"* It could not: the family modal had a `tour_booked_at` DATE of its
own, one of the twelve `Enquiries-Tours` columns this file warns against rebuilding from, and
nothing joined the two. **Jack and Sersha Donnelly were booked into one 19/09/2026 09:30 tour and
only Sersha's application carried a date, without the time** - two records of one appointment,
disagreeing on the second family who ever had one.

The family modal now edits `work_items.tour_at`. What that means on this side:

- **`work_for_waitlist()`** (`_incl/work.php`) is the read: the OPEN item behind one application,
  decorated like a `work_list()` row for `stage_label` / `tour_at` / `tour_outcome` / `tour_past`.
  It picks one - preferring the item that already carries a tour, then the earliest tour, then
  the oldest - because `work_create()` de-dupes on room and year, so one family worked toward two
  rooms is two rows and a caller must not book a tour on whichever came back first.
- **Every write still goes through `work_set_tour()` and `work_tour_outcome()`**, from
  `/api/work`'s existing `tour` and `tour_outcome` actions. Nothing new was opened for this.
- **Booking a tour on a family with no item CREATES one**, `stage='tour_booked'` by the rule
  `work_create()` already applies, with the days off the application and **no room**. That is the
  right way round - this file's own note says the tour is usually what puts a family into the
  pipeline, and requiring the pipeline first was backwards.
- **`window.tourAskHtml()` moved to `app.js`**, with `window.toLocalInput()`. Both were private
  to `work-modal.js`, and one appointment must not be asked about in two sets of words. See
  [ui-conventions.md](ui-conventions.md).

See [waitlist.md](waitlist.md) for the modal's half, including why `tour_booked_at` had to leave
the save's UPDATE and not just the form.

### Widths, re-measured twice

Urgency went **10.5rem -> 12rem** and the group view's Room gave back the difference. 10.5 was
measured against "offer expires in 47 hours"; a tour prints a full date and time.

**The first attempt took the width off the wrong row** (165px for "tour 17/08/2026 02:30 PM") and
the column wrapped again on screen - the widest string is **172px**, "tour 20/08/2026 09:30 AM",
because a two-digit hour with AM beats "02:30 PM". Floor with padding is 188px.

The declared minimums must sum under the 1030px box or the group view overflows at 1280. They come
to **64rem = 1024px**: Child 11.5 + Centre 5 + Room 10 + Days 8.5 + Stage 7 + Urgency 12 + Next 10.

- **Taking it from Next step instead was tried and reverted**: at 9rem the tallest row went to
  **122px**, the same too-tall row the 11 Aug measurement was about. Room is the honest source --
  on the group view it is already 3rem below its 227px natural and already wraps, so a fourth rem
  changes its degree, not its character.
- **Child 11.5rem and Days 8.5rem are exact, not generous.** Longest name present renders 168px
  (184 with padding); the day strip measures 122px (138 with padding). Neither has anything left.
- **A passed tour drops the word "tour"** - the Stage column beside it already says "Tour
  booked", so it was 39px spent repeating the row.
- **An empty day strip was replaced with `--`.** Five grey letters read as "this family asked for
  no days", and an enquiry has not been asked yet.

Verified 0px overflow and no urgency wrap at 1280, 1440 and 1920, on both views.

### Tours go into the centre's Outlook calendar (16 Oct 2026)

Broc: *"Possible to link tour bookings to our internal Outlook calendars, so when a tour is booked
it adds an entry into the Outlook calendar for that centre automatically? Each centre has its own
mailbox/calendar (i.e. `terrigal@littlemiracles.com.au`)."* `_incl/tour-calendar.php`.

- **A ONE-WAY COPY. The tour here is the record.** Booked -> created, moved -> updated, cleared or
  the family Lost before the visit -> removed. A no-show or a Lost family whose tour has happened
  keeps the entry: it is what the day was. **Nothing is read back** - dragging the entry in
  Outlook does not move the tour, and the entry says so.
- **WHERE: `centres.calendar_mailbox`, which is ours.** Kidsoft holds no centre email -
  `Service/getServices` has nine fields and its only `Options` are `Addresses` and
  `PhoneNumbers` (read in `Documentation/getMarkdown`, 16 Oct 2026). Seeded from Graph, where all
  eight centre accounts resolve as `<name>@littlemiracles.com.au` (Warnervale's account is
  disabled for sign-in; its mailbox was not tested). **NULL = no calendar for that centre**, and a
  new centre gets NULL until somebody sets it - there is no screen for it.
- **WHAT: "Tour: <child>", the booked time for 30 minutes** (`TOUR_CALENDAR_MINUTES`, Broc's
  answer), Sydney wall-clock sent with Graph's zone name and never converted. The body is the
  child (and DOB when there is an application), the parent, their phone and email - the same
  "who to ring" `work_get()` publishes - the days and year, and **a link to the item**:
  `/progress?centre=N&item=ID`, which In progress now honours (opens that item, then drops the
  parameter; `get` still checks scope).
- **NO ATTENDEES unless somebody ticks "Send the invite to the parent"** (29 Oct 2026). An
  attendee is an email to a family, so it goes only when a person asks for it. Ticked, the entry
  becomes the parent's invite - see "The parent's invite" below.
- **A FAILED WRITE IS NOT A FAILED SAVE.** `/api/work` (`create`, `tour`, `update`, `advance`,
  and `cancel` from the row as it stood) collects the item and syncs **after
  `fastcgi_finish_request()`**, with the session released first. Measured: the `tour` POST
  answered in **23ms** and the outcome was on the row a second later. The modal says it under the
  tour box - "In the Terrigal calendar", or the reason it is not (`tourCalendarNote()` in
  `app.js`, from `tour_calendar_status()`).
- **RETRIES ARE SAFE.** Graph has update and delete, so a sync is a reconciliation keyed on a
  sha1 of what was last written (`calendar_hash`); an item already in step costs no call. The one
  unsafe moment is a create whose answer never came back, and Graph's **`transactionId`** exists
  for it: stored on the row BEFORE the POST and reused until a create succeeds, so a resend
  returns the first event rather than a second. `--calendar` cron, every 15 minutes in the day,
  retries anything out of step - see [auth-security-ops.md](auth-security-ops.md).
- **Calendar writes do not bump `updated_at`** - they are not an edit to the item.
- **LIVE SINCE 19 OCT 2026.** Broc granted the Graph **application** permission
  `Calendars.ReadWrite` with admin consent; the grant is stamped 09:00:36 that morning. Until then
  every write answered 403, recorded on the item as "the app is not allowed to write to it yet".
  **Exchange lagged Entra by about four minutes:** a fresh token already carried the role while
  `GET /users/terrigal@.../events` still answered 403 `ErrorAccessDenied`, and it answered 200 at
  09:04:40. A 403 in the first minutes after a grant is that lag, not a wrong permission. The
  15-minute job retries on its own. **Like `Mail.Send`, it reaches every mailbox in the tenant**
  unless Exchange narrows it (RBAC for Applications, or an application access policy, scoped to
  the eight centre mailboxes) - Broc's call, not done here. Check the token's `roles` claim, not
  the portal. The app's own assignments can be read the same way:
  `GET /servicePrincipals(appId='...')/appRoleAssignments` answers with the app's existing
  permissions.
- **Verified** against a mock Graph on a rolled-back transaction (create with no answer -> retry
  reuses the transaction id; unchanged -> no call; moved -> PATCH; deleted in Outlook -> PATCH 404
  -> recreated; 403 -> recorded, retry clears it; Lost before the visit -> DELETE; tour cleared ->
  DELETE), and over HTTP on a throwaway item, deleted afterwards with every row it wrote. **Then
  for real, 19 Oct 2026:** Albie Keogh's 21/10 09:00 Terrigal tour was created in the Terrigal
  calendar, then read back: 22:00-22:30 UTC, no attendees, busy, location "Little Miracles
  Terrigal", the stored transaction id, and exactly one app entry that day. The modal reads "In the
  Terrigal calendar" when opened in Chromium.
- **THE WRITE IS SHARED WITH SUPPORT PLANS (29 Oct 2026).** A support plan step's booked day goes
  into the same centre calendar as an all-day entry. The create / update / remove protocol above
  is now `calendar_row_write($table, ...)` in `tour-calendar.php`, parameterised by
  `CALENDAR_ROWS`; `tour_calendar_write()` is a one-line wrapper over it. **One copy, on purpose** -
  the transaction-id rule is what two copies would drift on. A tour's hash is unchanged. Anything
  tour-only (the parent invite below) belongs in the tour wrappers, never in the shared function,
  or a support plan entry would gain an attendee. See [support-plans.md](support-plans.md).

#### The centres already type tours into Outlook by hand (measured 19 Oct 2026)

The first real entry landed beside **"Tour for Albie LA (Moving up from Sydney)"**, which Terrigal
had typed into the same calendar on 8 Oct, six days before the item existed here. So that tour is
now in Outlook twice. Read-only `calendarView` over every centre mailbox, 30 days back and 60
ahead, counting subjects containing "tour":

| Centre | Typed by hand, last 30 days | Coming up |
|---|---|---|
| Terrigal | 8 | 2, one of them Albie |
| Niagara Park | 10 | 0 |
| Tuggerah | 5 | 1 |
| Wamberal | 4 | 2 |
| Kanwal | 5 | 0 |
| Erina, Point Clare, Warnervale | 0 | 0 |

**37 hand-typed tours were in the centres' calendars against 4 in this app over the same window**, so
the diary of record is still Outlook. The subjects are free text ("Tour 2pm - Hira", "Tour",
"Natasha isla 2027 RR room tour"), often carry the room and year, and **sometimes a sibling**
("Tour: Seren Jones (SS 2026. Sibling Jacob LA 2027)": one visit for two children starting in
different years). **Reading them back into items was not attempted:** a subject names a first
name at best, and matching that to a family is the guess this file refuses everywhere else.
The hand entries are the centres', so the app never touches them. What happens to the doubling
(the centres stop typing them, or book here first) is a team decision, not a code change.

#### The parent's invite (29 Oct 2026)

Broc: *"can we also have a checkbox that sends the calendar invite to the parent as well? The
calendar invite that goes to the parent should be little miracles branded and have the centre
street address as the location."* "THE PARENT'S INVITE" in `_incl/tour-calendar.php`.

- **ONE ENTRY, NOT TWO - Broc's choice**, asked as: keep the centre's entry and add a second for the
  parent, or let the one entry become the invite. Ticked, the centre's entry IS the invite: a
  meeting request from the centre's own mailbox with the parent as its one attendee, so the centre
  sees their accept or decline, a reply lands in the centre's inbox, and a moved tour reaches the
  parent as Outlook's own update. **The price he accepted:** everything in a meeting goes to its
  attendees, so an invited entry has no phone, DOB, days or CRM link.
- **Where the box is:** every tour box - the work modal, the family modal on the Waitlist, and
  Book a tour on In progress (`window.tourInviteBox()` in `app.js`). It names the address it will
  go to (the same "who to ring" email the modal shows; the guardian email on the application; the
  typed one on an enquiry), and cannot be ticked when there is none. Unticked by default.
  `work_items.tour_invite` is set on **every child on the visit** (`work_set_tour_invite()`), since
  a visit has one entry and so one invite. Clearing a tour clears the tick, so the next tour booked
  on that item sends nothing until somebody ticks again.
- **What the parent gets:** "Tour of Little Miracles Terrigal - Albie Keogh", the logo (an inline
  attachment, so it shows without "download pictures"), brand purple and pink, "Hi Evie", the
  children by first name, when and where, and "If this time no longer suits, just reply to this
  invitation". `allowNewTimeProposals` is off: a time proposed in Outlook would move nothing here.
- **THE STREET ADDRESS IS READ FROM KIDSOFT**, `Service/getServices` with `Options=Addresses`,
  cached a day (`tour_centre_address()`). Measured 29 Oct 2026: most centres hold up to four. Postal
  and Mail are head office's PO address at Tuggerah. Physical is the CCS registration, in capitals,
  and says Hamlyn Terrace for Kanwal. Service or Business is the one the centre typed itself (each
  centre has one or the other). So: Service, then Business, then Physical. **Terrigal's two
  disagree** - Service "23-25 Michaela Ave", Physical "23-25 MICHAELA RD" - and the invite says
  Ave. If that is wrong, the fix is in Kidsoft. Kidsoft not answering gives "Little Miracles
  Terrigal" as the location rather than no invite.
- **THE ORDER IS WHAT KEEPS INTERNAL DETAIL AWAY FROM THE PARENT.** Branded content first, then the
  logo, then the parent (`tour_calendar_write()`, work items only - `calendar_row_write()` is
  shared with support plan steps, which must never invite anybody). **Taking an invite back**
  (unticked, or the parent's address changed) **DELETES the entry**, which Graph documents sends
  the attendees a cancellation, and writes the centre's entry again fresh. The centre's own
  content is never PATCHed onto a meeting the parent is still on, because that PATCH would reach
  them as an update.
- **Never a new invite for a visit that has happened**; one already sent stands, so a tour's time
  passing cancels nothing. Lost before the visit removes the entry as before, which now also tells
  the parent it is off.
- **Deleted in Outlook while the parent was on it:** somebody cancelled the meeting there, and
  Outlook told the parent. The sweep would have invited them straight back, so instead the tick
  comes off, the centre gets its own entry back, and the audit row says why. Ticking it again
  sends a fresh invite.
- **`calendar_invited`** is the address Exchange accepted, set only once the attendee PATCH
  answers 200. The modals say "In the Terrigal calendar, invite sent to x".
- **THE HASH COVERS THE BRANDED CONTENT.** Changing the wording in `tour_invite_desired()` sends
  every parent with a tour still to come an updated invite. That is how a reconciliation works.
  Change it knowing that.
- **`responseRequested` goes in the attendee PATCH.** Measured on the real send below: adding the
  attendee alone left it `false`, so the parent was not asked to accept. Writing it on an entry with
  no attendees holds (`true`), and neither the logo nor `allowNewTimeProposals` touches it. **The fix
  has not been seen on a real invite yet.** The first real one (Henry Girkin at Tuggerah, item
  109, sent 29 Oct for 2 Nov) answered 404 when read back on 2 Nov: it had been deleted in
  Outlook before the visit, and the app had no record of removing it. Its Save had also gone
  twice, so the attendee PATCH was sent twice - see "A double-clicked Save files one note". Read
  the next one back.
- **THE APP AND THE CALENDAR MAY DISAGREE, AND THAT IS ACCEPTED** (the product owner, via Broc,
  2 Nov 2026). The sweep reconciles on `calendar_hash`, so an entry deleted or changed in Outlook
  is not noticed until the app next writes it, and until then the modal still says "invite
  sent". Item 109 showed exactly that over a weekend. Do not raise it as a defect, and do not add
  a read-back to the sweep, without a new ask.

**Verified 29 Oct 2026.** 39 checks on a rolled-back transaction against an in-memory Outlook that
models who gets an invite, an update and a cancellation. The parent gets exactly one email per
change and nothing internal. Also covered: the order (content, logo, parent), no write when nothing
changed, untick, address changed, deleted in Outlook, a logo failure then retry (one invite, one
logo), Lost, cleared tour, a past tour, siblings (one invite naming both), no email, and a sweep
over every live tour sending nothing. Then the three tour boxes were driven in Chromium on
throwaway rows at Warnervale with its mailbox blanked (no Outlook writes), with no console errors,
and the rows were deleted. **Then for real, with Broc's agreement:** one invite to him from the
Warnervale calendar on a throwaway enquiry, read back (subject, 10:00 Sydney, street address with
a structured address, Broc as the one attendee, the logo inline, no CRM text), then unticked: the
invite event answered 404 and the centre's own entry was back with no attendee. The item, its
entry and its rows were deleted.

## Brothers and sisters: one family, several items (19 Oct 2026)

Broc: *"We should also look into the possibility of 'bulk' actions; as it would be very unlikely
that after a tour that we offer a enrolment to one sibling and not the other... right?"*

**Measured before anything was built**, over every pair of siblings ever imported at the same
centre (75 pairs, closed applications included; see [waitlist.md](waitlist.md)): of the 28 where
either child got an offer or an enrolment, **the 20 who asked to start within 30 days of each
other were offered together, every one**; the 6 offered alone had all asked to start apart (a
baby wanting a later year, a third child going to school). And **a tour is one visit for the
family whatever the start dates** - Terrigal typed *"Tour: Seren Jones (SS 2026. Sibling Jacob LA
2027)"* into Outlook by hand. **There is no bulk OFFER**: offers are made in Kidsoft, and
`work_follow_kidsoft_offers()` already moves each child's item when Kidsoft shows theirs. Broc
agreed four rules, all in `_incl/work.php` beside `work_siblings_here()`:

1. **The tour is the family's.** The work modal and the family modal both show "Also on this
   tour" under the tour box (`window.familyTourTicks()`), **ticked** for a sibling with no tour or
   already on this one, unticked - with the reason - for one with a tour of their own, one already
   in care, or one whose only item was closed as Lost. On save every ticked sibling ends with THIS
   child's tour - booked, moved or cancelled - through `work_set_tour()`; a sibling with no item
   is **started in In progress** (`work_create()`, the application's days and year, no room) with
   "Tour booked with X, one visit: ..." as the first history line. Ticking a sibling onto a tour
   already booked is a save in its own right. A sibling matching **Do Not Enrol is skipped and
   named**, not started: that question is asked one family at a time on the Waitlist.
   **Answering the tour answers it for the visit** (`work_tour_outcome_visit()`): open,
   unanswered items only, so a recorded answer stands and a Lost item is never reopened.
2. **Lost asks.** Choosing Lost in the work modal shows "Also lost" for each sibling with an open
   item, **unticked**. A ticked one closes with the same note, or "Not proceeding, the same as X."
3. **An offer to one and not the other is flagged** when they asked to start within
   `WORK_SIBLING_SAME_START_DAYS` (30) - `work_offer_gap()`, both ways round: "Louis has no offer"
   on the child who has one, "Lucas has an offer" on the child who does not. An alert at the top
   of the modal, and a line under the name in the In progress list (first name only - the Child
   column is 11.5rem exactly). A child already in care is left out on both sides. **None fire
   today** (0 of 576 open applications on 19 Oct), which is what 20 of 20 predicts: it is a net.
4. **A note shows on both and travels on one.** "Also on the history of" under the note box,
   ticked except for a child already in care. **One row**, linked to each sibling through
   `communication_siblings`; the Kidsoft copy hangs off the row's own `waitlist_id`, and neither
   `comment_push()` nor the nightly backlog reads the link, so the copy can only ever reach the
   child it was written on. A sibling match is a shared phone or email - **the evidence the
   createComment rule refuses to write on.** A shared note reads "logged on X" on the sibling
   (and loses its "copied to Kidsoft" mark there, since the copy is X's) and "also on Y" on the
   child it was written for (`work_contacts_shared()`, used by both modals).

**WHO COUNTS** is the Waitlist's own rule - a shared parent phone or email, and not the same
child applied for twice (`waitlist_same_child()`, moved to `siblings.php` so both can call it) -
asked for **one centre, from our own table**. The Waitlist's group-wide
`waitlist_siblings_waiting()` read every centre through Kidsoft and took **22.7s cold** on the
morning this was built; the family actions only ever concern the centre being toured. Compared
over every open application: the two agree on 70 of 72 families, and every difference was **a
sibling already in care who also holds an application** (Wyatt and Ollivander Smith, the Loveday
boys, Noah Billins...), which the Waitlist leaves off its line because the Priority column names
them. Here they are listed but marked "already in care" - read the queue's way,
`waitlist_mark_in_care()`, 70ms warm - and never ticked. Cost: 2-3 queries plus that index, 0.07s
on a modal, the same for the whole In progress list.

**ONE VISIT, ONE CALENDAR ENTRY.** `tour_visit()` (`tour-calendar.php`) is who is on a visit: the
same centre, the same booked time, and a parent phone or email in common, joined transitively -
read fresh every time, never stored. **One item carries the entry** (the LEAD: whichever already
holds one, else the oldest) titled for them all ("Tour: Louis and Lucas Hahn", children oldest
item first so the title does not change with who holds it), with a line per child and a link to
each item; the others hold none and their modals say "In the Terrigal calendar, one entry with
Louis Hahn". A sibling moved off on their own gets their own entry and the visit's is renamed;
ticked back on, theirs is deleted. `/api/work` re-syncs the visit as it stood BEFORE a write as
well as after, which is what renames the entry a sibling left. A single child's entry is
byte-identical to the 16 Oct shape (checked: Albie Keogh's stored hash still matches, so nothing
was rewritten).

**Verified 19 Oct 2026.** A rolled-back transaction against a mock Graph, 34 checks: who is
family (a child applied for twice counted once, another family at the same 9:30 kept apart),
booking with a sibling ticked (started at Tour booked, own days), one entry not two, a second
sync sending nothing, moving the visit (one PATCH), moving one child off and back (POST then
rename; DELETE then rename), the lead lost before the visit (the sibling takes the entry), the
outcome for both, the offer flag both ways and gone when starts differ or both are offered, the
shared note on both sides, and the backlog skipping internal and local-only notes. Then driven in
Chromium on a made-up family at Warnervale with its calendar mailbox blanked for the run (no
Outlook writes): every step above through both modals, toasts naming the sibling, no console
errors, 0px overflow on In progress. The family, its items, notes, links and audit rows were
deleted afterwards and the mailbox restored.

## The modal was rebuilt (18 Sep 2026), and the measurement is the argument

Everything above describes a modal that worked. It did, and almost nobody used the half of it
that matters. **`_incl/work-modal.php`, `static/js/work-modal.js`.**

### What was wrong, measured in Chromium against live rows

A 500px column holding **twelve form controls**, with the record stacked underneath them:

| | Marli Kerslake | Jack Donnelly |
|---|---|---|
| Dialog height | 1098px | 934px |
| **Save button top** | **y=1071** | y=907 |
| History begins | y=829 | y=749 |

The viewport is **800px** on the laptop preset every other measurement in these docs uses.
**Save sat 271px below the bottom of the screen**, and was still 9px below the fold at
1920x1080. The history began at y=829, so on the standard screen the user saw **none of the
record at all**. And `.modal-dialog` had no scroll container, so **every event appended made the
dialog taller and pushed Save further away** - on a pipeline whose median is 21 days and 43% of
which runs past 30.

### What that cost, in the data

**15 items, 41 events, two weeks of daily use since 3 Sep - and every note in
`work_item_events` is machine-generated.** "Raised automatically when a place was offered", "Tour
booked for 19/09/2026 09:30 AM", "Offer expired. Still on the list." **Not one human sentence.**
`communications` held **one row group-wide** and it came from the family modal
(`waitlist_id=265`, `work_item_id` NULL). **Zero from here.**

The screen the team uses to chase families recorded nothing about the chasing, which is the
thing this whole layer exists to hold.

### What changed

**`modal-lg modal-dialog-scrollable`**, which is the fix
[support-plans.md](support-plans.md) had already written down on 17 Sep and that nobody applied
back here - *"SCROLLABLE BODY, so the header keeps saying which child while the steps scroll and
the footer keeps the outcome button in reach."* It was the only substantive editor in the app
still on the default 500px dialog; the family modal, the users modal, the support-plan modal and
`layout.php` are all `modal-lg` and three are scrollable.

**The order is inverted.** The two hard clocks first, then who to ring, then the act of recording
what just happened, then the record, then the properties nobody edits.

- **The offer is an ALERT, graded by `window.offerUrgency()`** - the same function the In
  progress list and the Waitlist grade with, read rather than re-derived, so one offer cannot be
  red on the table and calm in the dialog. It was a plain bordered box in which "Offer expired"
  was grey body text carrying exactly as much weight as "Starting 5/01/2027" beneath it.
  `accepted` is the one answered status that gets a colour (amber), for the same reason
  `workStageClass()` paints that one stage and leaves the rest grey.
- **The guardian's phone and email render, read-only, as `tel:` and `mailto:` links.**
  **851 of 855 applications carry a phone and 850 an email**, all of it already in our own
  tables - and the modal did not carry a number, so the one action every stage of this pipeline
  comes down to sent the user back to the Waitlist to look one up. Published by `work_get()` as
  `$w['reach']`, resolved server-side to guardian or enquiry so the browser renders it rather
  than deciding it.
  - **This is NOT a retreat from "do not re-key what Kidsoft holds".** The editable boxes are
    still rendered for an **enquiry only**. An input holding a queue family's number invites
    somebody to type it a second time; showing the number does not.
  - **A child already in care gets nothing here, deliberately.** Their guardians are reachable
    only through `guardians_by_id()`, a live Kidsoft call per open, and the family modal already
    refuses to put a Kidsoft round trip behind every click on a name. There are **0 such items**
    in live data.
  - **A missing guardian name renders nothing, not "No name recorded"** - withhold a figure
    rather than captioning it.
- **The tour's help text depends on the state.** It read "Clear it to cancel the tour." on every
  item in the app, including the ones with no tour to cancel and the ones whose tour had been and
  gone and was being answered in the alert directly above it.
- **"What happened" is a textarea.** The column is varchar(500) and always was; a 500-character
  single line in a 500px box showed about a tenth of what had been typed.

### The two lists became ONE timeline, newest first

"This enrolment" ran **oldest-first** off `work_item_events` and "Contact with this family" ran
**newest-first** off `communications` - **two lists sorted opposite ways**, so reconstructing a
single week meant reading both and interleaving them by hand.

- **THE TWO KINDS STAY TOLD APART, and that is not decoration.** A stage change is what the ITEM
  did; a contact is what happened with the FAMILY and outlives any one item -
  `work_contact_history()` looks up by WHO, so a contact rendered here **may predate this item
  entirely** and belong to a different spot being discussed. Merging them into one
  undifferentiated stream would assert every line is part of this item's history, which is false.
  A contact keeps the family modal's exact shape, down to the left-border colour by direction;
  a stage change gets `.comm-entry-stage`, unsaturated and inset, and the stage badge from
  `workStageClass()`.
- **ONE ACT, ONE LINE.** Saving a note with a channel picked writes **both** a stage event
  carrying the note and a contact carrying the same sentence as its summary, so a merged list
  printed the same words twice for what the user did once. They fold into one entry showing both
  facts. **Compared on the first 255 characters**, because that is where `work_log_contact()`
  truncates for `communications.summary` while the event keeps all 500 - matching on the full
  string would silently stop folding for exactly the long notes where the duplication is worst.
  **Matched to the minute**, because the two INSERTs take their own `CURRENT_TIMESTAMP` and can
  land either side of a tick.
- **Sorted by STRING COMPARE, never `new Date()`.** These are Sydney wall-clock strings in
  `Y-m-d H:i:s`, which sort lexicographically in exactly chronological order.
- **Direction is in the VERB, not a bracket after it.** It was the channel word plus
  "(they rang us)" on anything inbound, which put **"Emailed (they rang us)" under an envelope**.
  A family who emailed in "emailed"; they did not ring.

### Verified

Driven in Chromium, live rows, after:

- **Save visible at 1280x800, 1440x900 and 1920x1080** on every item (`saveTop` 717 against a
  744px dialog that now caps instead of growing); body scrolls internally, header and footer
  pinned; 0px horizontal overflow.
- **The timeline is above the fold** on all four live states (top at 540-620px).
- The form still posts both writes with every field, and the **tour rank-test still holds** - a
  tour booked on an item at `tour_booked` posts `tour_booked`, not a demotion. Captured by
  replacing `jQuery.post` in the page, so nothing reached the database.
- The fold was driven through a synthetic history (4 events + 3 contacts, one pair sharing a
  minute and a sentence): **6 entries rendered, the shared sentence appearing once**, strict
  newest-first order.
- **The modal is in the DOM and error-free on all three hosts** - In progress, Waitlist and
  Placement.

### Channel chips, and the note became a real contact (18 Sep 2026)

"Logged as" was a `<select>` defaulting to **"Just a note"** - the one option that did not reach
the family's record - and `communications` held **one row group-wide** across two weeks of daily
use. **A default nobody can see is a default nobody changes.**

Six chips now (`btn-check` radios): **Note / Called / Emailed / Texted / In person / Other**.

- **THE DEFAULT IS STILL THE SAFE ONE, and it is now safe AND useful.** "Note" writes to the
  contact log as `direction='internal'` with `local_only`, so a colleague opening this family
  from any screen sees it - and it does **not** travel to Kidsoft, because an internal note is
  not a record of contact with the parent and `createComment` has no update and no delete.
  Picking a channel is the deliberate act that says a real conversation happened.
- **NOT defaulted to "Called", and that was considered.** A wrong default writes "phoned the
  family" onto a child's Kidsoft record permanently. The chips fix the actual defect, which was
  that the choice was invisible; they do not make a guess on the user's behalf.
- **`api/comms` enforces the same rule off `direction`**, not just on the caller's word:
  `internal` forces `local_only`. The request says what it means rather than being the only thing
  standing between a private remark and a permanent one.
- **The direction question is asked only once a real channel is picked.** An internal note has no
  direction - nobody contacted anybody - and a live "We contacted them" beside "Note" invites an
  answer to a question that was not asked.
- **Whether the note will travel is said BEFORE it is typed**, from `$w['kidsoft_push']`, which
  `work_get()` now publishes through `comment_kidsoft_target()`. The family modal has done this
  since 14 Aug; this screen wrote to the same table and said nothing. Withholding it is the bug:
  a staff member who assumes a note travelled stops keeping the Kidsoft note they still need.
- **The compose block resets on every open, chips included.** A channel left selected from the
  last family is how a note about one child gets filed as a call made to another.
- **The timeline shows `body` and the push outcome.** `work_contact_history()` selects both now,
  and `window.copyTag()` renders "copied to Kidsoft" / "not copied" - the third surface to need
  it, which is why it moved to `app.js`.

**Verified end to end, once, under two independent guards** (Baby Collings, whose application
carries neither a ChildID nor a GuardianID, with the Note chip): the row landed carrying both
`waitlist_id` and `work_item_id`, the body survived its newline, `kidsoft_comment_id`,
`kidsoft_pushed_at` and `kidsoft_push_error` were all NULL, the audit log held `comm.create` and
**no `comm.push`**, and `work_item_events` was unchanged because the stage had not moved. The row
was deleted afterwards. **Every other write in this rebuild was captured by replacing
`jQuery.post` in the page** - see the memory note about a test click that pushed a fabricated note
permanently onto a real child's Kidsoft record.

### Next step and Due became one control (18 Sep 2026)

They were two independent optional boxes, and what the team produced with them was **five dates
and no steps**. `next_action` is NULL on all 15 live items; five carry a `next_action_at`, and
the audit log shows them typed on 3 Sep as **08/09, 01/09, 25/08, 24/08 and 24/08 - four already
in the past at the moment they were entered**, one by ten days. They are the OFFERS' expiry
dates, re-keyed by hand into a box that meant something else. So the Dashboard reports
**"Past their date: 5"** about five items that do not say what the step is, and In progress
renders an **empty Next step column on every row**.

Neither half works alone: a date with nothing against it cannot be acted on, and a step with no
date never surfaces, because `work_urgency_sort()` bands on the date alone.

- **The date is part of the sentence, not a field beside it.** "Next step [what] **by** [when]".
  The `by` row is revealed by typing a step and **cleared with it**, so the only way to reach
  "date with no step" is an item that arrived that way.
- **`work_posted_next_step()` refuses either half server-side**, on `update` AND `create`, and
  that is the control - the browser check only puts the refusal on the field instead of in a
  toast after a round trip. Verified with curl outside the browser: a step with no date and a
  date with no step are both refused and **write nothing**; neither succeeds.
  - The guard is on `create` although **no caller sends those fields today**. An endpoint that
    accepts a half-filled next step is how the five orphan dates got in, and the next caller
    should not have to know that.
- **THE FIVE LEGACY ROWS ARE NAMED, NOT SILENTLY CLEARED.** An item that arrives with a date and
  no step shows the date with "This date has no next step against it. Say what it is for, or
  clear it.", and the save is refused until one of those is done. Deleting somebody's data
  because this screen now disapproves of it is a mutation nobody asked for; saying what is wrong
  with it is not. **They are still in the table** - the tile will keep counting five until
  somebody opens them.
- **THE `by` BUTTONS CARRY SERVER-COMPUTED DATES** (`work_due_presets()`, published on every
  `get`). "Tomorrow" is a date, and every route to one in the browser goes through `new Date()`,
  which reads the viewer's timezone rather than Sydney - and it is Sydney's clock that
  `work_list()` compares against. Riding with the item rather than sitting on the page also means
  a modal left open across midnight cannot offer yesterday's tomorrow. One / three / seven days,
  the cadence the team works to.
- **A past date WARNS and does not block.** "That date has already passed - this will show as
  overdue straight away." Blocking would trap the very rows this control exists to fix. The
  comparison is a string compare of two `Y-m-d` values against the server's `today`, which is
  exactly chronological and needs no `Date`.

### `update` guards EVERY field on `isset()` (18 Sep 2026)

`next_action`, `next_action_at` and `owner_email` were written unconditionally, while the tour
and contact blocks beside them had been guarded since the day they were added. **A caller that
did not carry those three blanked them.**

Nothing in the app was wrong - the modal sends all three on every save - but the next caller
would have had to know that, and this endpoint is shared by the Placement board, the Waitlist and
In progress. **Proven rather than supposed: a curl naming only an `id` cleared `owner_email` on a
live item during testing, and it had to be restored by hand.**

- **The next step is one fact and is guarded as one.** Naming EITHER key means the caller edits
  the pair, and `work_posted_next_step()` then refuses a half - so **a caller cannot set a date
  by sending the date alone**, which is exactly the shape of the five orphan dates in the table.
- **An explicit empty value still clears.** Absence means "I do not edit this"; `''` means
  "clear it". The modal relies on the second and the board's other callers on the first.
- **`audit('work.update')` now records WHICH fields were edited** rather than always logging
  `next_action_at`. With the guard in place, a cleared date and an untouched one are different
  events, and the old line could not tell them apart - the whole point of the change.

Verified with curl, outside the browser, on a live item: `id` alone touches nothing; a step
without a date and a date without a step are both refused and write nothing; a valid pair is
written; an explicit pair of empties clears. Then end to end through the modal, twice.

### Kidsoft's own comments (18 Sep 2026)

A "Held in Kidsoft" block under the timeline, the same one the family modal has had since 14 Aug.
**`api/comms`'s `list` gained `work_item_id`** so it matches `add`, which had learned that handle
hours earlier - without it the modal could WRITE a note here and not read back what Kidsoft holds
for the same family.

- **`comms_resolve()` is now the single resolution for both actions.** Three handles, one rule -
  including the one with a rule attached: a work item **widens to its own application wherever it
  has one**, so a family reads the same from either screen, and `api_require_centre()` runs off
  the resolved row for every caller. They had already drifted once, within a day.
- **NEVER MERGED INTO THE TIMELINE, and it could not be even if that were wanted: KIDSOFT RETURNS
  NO DATE ON A COMMENT.** There is nothing to sort by. They are also mostly enrolment-form capture
  ("Agreed to terms and conditions: Yes") rather than contact history, so merging them would bury
  what the team did behind what a form recorded.
- **FETCHED AFTER show(), NEVER AS PART OF THE OPEN.** It is a live API call; the family modal
  defers the same read to a tab click for the same reason.
- **AND MOST ITEMS MAKE NO REQUEST AT ALL.** `work_get()` already publishes `kidsoft_push`, and
  for 87% of the queue Kidsoft holds no record - so those items skip the fetch entirely.
  **Measured: 0.015s and no API call for a non-pushable family against 1.4s for a pushable one**,
  and the rest hit a per-centre cache (`getChildren` 36 h, `getGuardians` 3 h) the family modal
  already warms.
- **Guarded on the item id at paint time.** A slow response must not paint one family's Kidsoft
  notes into another family's modal - the trap that once opened the wrong child's editor on every
  board in the app. Verified: opening a pushable family and then a non-pushable one leaves the
  block hidden and empty.
- **A Kidsoft outage is silent here.** `comment_kidsoft_existing()` already swallows it and
  returns nothing; a toast about somebody else's server, over a modal whose own record rendered
  perfectly, would be noise. The block simply does not appear.
- **`window.kidsoftComments()` is shared**, with the `.ks-more` clamp toggle bound **once on the
  document**. This was the third copy of that markup. Removing the local handlers was not
  optional: a second delegated handler fires on the same click and toggles the clamp straight
  back, so the shared component would have silently broken the two screens that already had it.

### What is STILL not changed

- **There is no backdating.** `api/comms` accepts `occurred_at` and this modal does not send one,
  so a note about yesterday's call is stamped today.
- **A DOLLAR FIGURE CAN REACH THE SCREEN THROUGH A KIDSOFT COMMENT.** Guardian comments are free
  text somebody typed in Kidsoft, and one on a live Kanwal family reads
  *"Transfer in from NP - kept bond at $490"*. **This is not new** - the family modal has rendered
  that exact string since 14 Aug and Support plans since 17 Sep; this makes it three surfaces. It
  is not a figure this app computes, and redacting a staff member's own note would falsify the
  record rather than protect it, so it is rendered as written. **Flagged rather than decided: see
  the prohibition in CLAUDE.md and rule on it.**

## The enrolments meeting (22 Oct 2026)

Broc brought six asks from the team's meeting. Four landed here; what was decided and why:

- **`Attempted contact`, between Identified and Contacted** (Broc: add it, keep Contacted). A
  voicemail or an unanswered text was being filed as Identified ("nobody has tried") or Contacted
  ("we got through"), and neither is true.
- **OFFERED AND ACCEPTED ARE KIDSOFT'S, and `/api/work` `advance` refuses a move to either by
  hand** - the same treatment `enrolled` already had. `work_follow_kidsoft_offers()` moves the item
  when the offer is out and when the form is in. Staying at the stage the item is already at is
  still allowed (that is a note being added). The modal lists the two only on an item already
  there, so the select can show where it is.
- **THE FORM IS SHAPED BY THE STAGE PICKED** (`syncStage()` in `work-modal.js`): Identified is a
  note and nothing else (no channel, nothing to copy to Kidsoft); Attempted contact offers Called /
  Emailed / Texted / Other and is always outbound; the tour's date and time shows **only for Tour
  booked** (and on an item whose tour is still ahead, so it can be moved or cleared); **a move into
  Toured** is "Tour notes", in person, inbound ("They came in"). It is the MOVE and not the stage,
  so a call logged later on a Toured item is still a call. A move into Tour booked asks for the
  date. **The hidden tour box is not posted** - `/api/work` reads a present `tour_at` as "set it",
  and the hidden box still holds the last tour, so posting it re-saved or bumped a finished visit.
- **INTENDED ROOM AND DAYS** ("what room and what days we are talking to the family for"). They are
  the item's own `room_id` and `work_item_days`, editable in the modal on all three hosts, so a room
  picked here also puts the family on that room's cells on the board. The Waitlist's **Start now
  opens the Enrolment** straight after creating it, because that is the moment it gets decided.
  **Locked from Offered on** (`work_room_days_locked()`, refused server-side too): the offer in
  Kidsoft sets them and the next import would overwrite a typed answer. `work_clean_room_id()`
  refuses a room from another centre - `create` had never checked.
  The application's own Preferred room is untouched: it is what the family typed into Kidsoft.
- **A NEXT STEP IS FOR SOMEBODY** (`next_action_for`): Me, or someone else. **The reminder is in
  the app and nowhere else (Broc's call - email was offered and declined).** So only a person who can
  sign in AND open that centre may be named (`work_people_for_centre()`); anybody else is refused,
  because a reminder nobody will ever see should not save as though it reminds somebody. Empty
  means the person saving.
- **"DUE", not "Overdue", on In progress**: next step dated today or earlier (`due` in
  `work_list()`), because a reminder for Friday has to surface ON Friday. `overdue` (strictly past)
  is unchanged and still drives the Dashboard's "past the date" alerts and the "Past their date"
  tile. `work_urgency_sort()` band 3 is `due`. `?filter=overdue` still lands, on Due.
- **"MINE" IS A SWITCH, NOT A CHIP** ("weird to have that as a filter when all the other filters
  are stages"). It answers WHOSE and the chips answer WHICH, so it narrows whichever chip is on
  (`?mine=1`, its own parameter). Mine = owner OR named on the next step. `?filter=mine` still lands.

**Measured / verified 22 Oct 2026.** The modal driven in Chromium through every stage on a live item
with `jQuery.post` replaced (nothing sent); three saves captured (attempted contact with room, days and
a step for Emily; Tour booked with no date - stopped before any post; an offered item - room and days
locked and not posted). The API's refusals checked with curl on a throwaway Warnervale enquiry (offered
and accepted by hand, a room from another centre, a step for a stranger - each refused with nothing
written), then the item and its four audit rows deleted. The Dashboard pipeline grew a tile and pushed
Enrolled onto its own line at 1280; `--pipe-min` came down to 6.5rem and it is one row at 1280, 1440
and 1920.

**Asked about and NOT built:** telling a Nominated Supervisor from a 2IC on a note. Terrigal signs in
as one shared account (`terrigal@`), so every note there reads "Terrigal". The app already names
whoever is signed in; separate sign-ins are the fix, and Broc will take that back to the team.

## Vocabulary: "WORKING" a child is the wrong word

Broc, 10 Aug 2026. "Working" a family reads as something done TO them, which is not what this
organisation does and not what the button does.

| Was | Is |
|---|---|
| Start working this | **Add to In progress** |
| Work item (modal title) | **Enrolment** |
| Who is working this | **Who is looking after this** |
| Families being worked | **Families part-way through enrolling** |
| Work needing attention | **Enrolments needing attention** |

**The code identifiers did NOT change** - `work_items`, `work_list()`, `WORK_STAGES`,
`/api/work`. Renaming a schema for a vocabulary problem is churn with no user benefit, and
there is precedent: the Tenure screen is called Tenure while the code that measures it says
churn. **The rule is that the two may differ as long as the reason is written down**, which is
here.

## The controls

- **"Add to In progress" sits on the two demand blocks in the cell panel** - the wishlist and
  the queue. **Not** on the "also in this room" list, which is context and not a call list.
- **A family already being worked shows their stage and an Update button** instead.
  `work_create()` is idempotent, but a button that looks available and silently does nothing is
  worse than one that says why.
- **The modal offers every stage EXCEPT `enrolled`.** The API refuses it from a human too.
- **Saving writes twice on purpose:** `work_update` for the next action (a property of the
  item) then `work_advance` for the stage (an event, with the note attached to it). **"Already
  at that stage" is swallowed**, because the point of the save is often the note.
- `cells_year()` attaches open work per candidate, **keyed by identity** - the wishlist
  identifies a family by Kidsoft ChildID, the queue by our application id, so the lookup
  carries both.

### The Waitlist can start one

Offers are made from the Waitlist, and until 10 Aug that screen had no way to put a family into
the pipeline at all. **It only HALF closed the gap** - the button relies on somebody pressing
it, and `work_link_offer()` now raises the item itself. **Both stay:** the button is for the
family you start ringing BEFORE there is a place to offer them, which is most of the 21-day
median.

- **An icon-only button.** The Actions column already carried three; `+` to add, then a filled
  `fa-list-check` that opens the shared modal.
- **The stage sits under the status badge, not in Actions.** "Waitlisted" says where they sit
  in the queue and "Contacted" says whether anybody has rung them - two different facts.
  **Actions is for actions; this is information.**
- **NO ROOM on an item created here.** A family in the queue has not been matched to one, and
  defaulting to their preferred room would file the item against a spot nobody has decided on.
  It is filled in when a place is offered.
- `waitlist_for_centre()` **composes `work_list()`** rather than querying again - three
  screens, one definition.
- `for_year` falls back to `default_year` published by `/api/waitlist` **from the SERVER**, so
  it is Sydney's year and not the viewer's.
- **A Do Not Enrol family is confirmed before it is started** (12 Oct 2026): `create` refuses
  an application matching a tagged family until the caller re-sends with `dne_checked=1`, and
  every caller goes through `window.postConfirmingDne()`. See [waitlist.md](waitlist.md).

## The offer stage REFLECTS an offer, it never makes one

### And since 15 Oct 2026 the offer is KIDSOFT'S

Offers are sent in Kidsoft and read back (see [waitlist.md](waitlist.md), **Offers**).
**`work_follow_kidsoft_offers($centreId)`** does for those what `work_link_offer()` and
`work_offer_answered()` do for the old kind, and the waitlist import runs it after every sync,
as does `/api/work`'s `create` (so a family started after their offer went out opens at the right
stage, not at Identified until the next import). Stage moves never travel to Kidsoft - only
`communications` rows do.

- **Forward** to `offered` while the family has still to answer, to `accepted` once Kidsoft has
  their form. **The offer decides the room, the year and the days**, as `work_create_for_offer()`
  does, and the history line says so: *"Offered in Kidsoft: Little Angels, Tue, Wed."*
- **Back to `contacted`** when the offer ends without an enrolment. `offered` goes back as soon as
  Kidsoft has no offer out (an item there holding nothing is the double-book the guard below
  exists for); `accepted` only on `ExpiredOffer` or `RejectedByParent`, because staff set
  `accepted` by hand when a family says yes on the phone and Kidsoft still reading Waitlisted is
  not that family saying no.
- **Never creates an item**, and leaves alone any item behind an old-style offer that is still
  pending or accepted.
- **Measured on the first run: 8 items were behind Kidsoft** and moved - Rosa Daris, Brodie Woods,
  Eloise Bowman and Iloura Tweedie at Wamberal (`contacted`, forms already submitted; four of the
  six moved back on 14 Oct because OUR copy of their offer had lapsed), Sersha Donnelly
  (`tour_done` -> `accepted`), Baby Collings and Stella Knox (-> `offered`), Heidi Gow
  (`identified` -> `accepted`, Re-submit Required).
- **`work_advance()` accepts `offered` by hand** when the application's Kidsoft status is an open
  offer, and otherwise says *"Send the offer in Kidsoft first"* - it used to point at the Offer
  button, which is gone.
- **In progress keeps its clock.** `work_list()` takes `offer_expires_at` / `offer_hours_left`
  from Kidsoft's ExpiryDate (to the end of that day) when there is no offer of ours and the family
  has still to answer, so a Kidsoft offer sorts and grades like any other: Stella Knox went from
  "0d" to the top of the list at "offer expires in 4 days".

### The offers made here before 15 Oct 2026

`work_items.offer_id` existed and nothing set it, so an item could sit at `offered` holding
nothing - **a family who looks committed while their seat still reads as free to everybody
else**, which is exactly the double-booking `offers` exists to prevent.

- `make_offer` calls **`work_link_offer()`** -> sets `offer_id` and moves the item to `offered`.
- **AND IT RAISES THE ITEM WHEN THERE IS NONE** (`work_create_for_offer()`, 11 Aug 2026). It
  used to be a silent no-op, on the reasoning that offering without tracking is allowed. It IS
  allowed, and it was also the normal path - so `offered`, a stage `WORK_STAGES` declares,
  routinely existed with no item under it. Three things that hole cost, all at the most
  time-critical moment in the pipeline: **no contact log, no owner and no next action during
  the 14-day clock, and no auto-close** (`work_autoclose()` only looks at items, so an accepted
  offer with none behind it never closed itself).
  **NOTHING IS GUESSED, which is what makes it safe.** Every field comes from the offer just
  made under the lock: centre, year, the **offered days** (not the application's - a partial
  offer is normal), the owner from `offers.offered_by`, and the **room**, which the Waitlist's
  own button deliberately leaves unset. Offering is the act that decides it. No double-count on
  the cell: `work_by_cell()` already excludes `offered` and past.
  It **creates at `identified` and then advances**, rather than inserting at `offered`, so both
  paths share one line of advancement and the event log says plainly that this family was not
  recorded until the offer.
  **The item is then uncancellable**, and that is correct, not a side effect: you did offer this
  family a place, so **Lost** is the honest exit.
- `respond_offer`, `withdraw_offer` and the expiry sweep call **`work_offer_answered()`**.
  Accepted -> `accepted` (then `work_autoclose()` finishes it). **Declined, withdrawn or expired
  -> back to `contacted`, NOT `lost`**: a family who turned down one room on one day is often
  still worth ringing, and closing the item would lose the history saying what they already
  refused. **Losing a family is a decision somebody makes, not something an expiry does.**
  **THE NIGHTLY SWEEP NEVER DID THIS, until 14 Oct 2026.** `offers_expire_due()` called it behind
  `function_exists('work_offer_answered')`, which is true on every web page and **false in
  `cron-nightly.php`**, which never loaded the work layer. An offer expires at 23:59:59, so cron is
  what expires most of them - and every one left its item at `offered` holding nothing. The
  nightly log shows cron expiring 1 + 1 + 3 + 1 offers on 10 Sep, 13 Sep, 14 Sep and 1 Oct: **the
  six items found stuck** (Baby Collings at Kanwal, five at Wamberal). The one that expired during
  a daytime page load (Carter Shewring, 17 Sep) moved back correctly, which is how the guard hid.
  The board hides `offered` and past from the cell, so those families were on no board at all.
  **Fixed by requiring `work.php` inside the function**, not by guarding. The six existing rows
  were moved back separately the same day, on Broc's say-so, with `work_advance()` and a history
  note giving the real expiry date - five of them have a room and reappeared on the Wamberal and
  Kanwal 2027 boards at once.
- **THE MODAL SHOWS THE OFFER.** `work_get()` -> `work_offer_detail()` -> `#work-offer`, drawn
  by `offerBlock()`. **The days shown are the OFFER'S, not the item's** - the subtitle already
  carries the item's days, and the two come apart in the two cases that matter: an item raised
  from a CELL can be offered a narrower set, and once the family answers `offer_days.accepted`
  is **per DAY** (taking the Monday and turning down the Wednesday is normal, and nothing on the
  item records it).
  **It fetches ANY status, unlike `work_list()`**, which joins `pending` only because it orders
  by a live deadline. A declined or expired offer sends the item back to `contacted`
  specifically so what this family already refused survives - hiding it here would throw away
  the reason they are back in the list.
  **The answer is in WORDS ("Took Mon. Turned down Wed."), not a third colour on the strip.**
  `window.dayStrip()` already means one thing on every other screen, and two shades of "asked for"
  would be a new visual state to learn. **Verified by driving Chromium and CLICKING the row** - webshot has no
  click flag, and a shot of the page behind a modal proves nothing.
- **`work_advance()` refuses `offered` for a queue family with no offer behind it**, and says
  which button to use. Only a queue family can hold one - `offers` is keyed on `waitlist_id`,
  and for a child already in care an extra day is granted in Kidsoft rather than offered.

## Offer urgency lives in ONE file

`_incl/offer-policy.php`: `OFFER_URGENT_HOURS` (72) and `OFFER_COUNTDOWN_HOURS` (48).

Both were bare literals in four places - `work_progress_summary()` in PHP, and `progress.js` /
`work-modal.js` / `waitlist.js` in the browser - the same shape as the stat tile's six copies.

**Why it is its own file** rather than consts in `waitlist.php` or `work.php`: the four
consumers do not share an include. `work.php` does not require `waitlist.php`, and
`layout.php` - which publishes these to the browser on every page, the way it publishes the
CSRF token - deliberately requires almost nothing. Making `layout.php` pull in the whole work
layer so Rooms and Tenure could read two integers is the worse trade.

**Keep it dependency-free. The moment it requires anything, the reason it is separate stops
being true.**

The browser reads them from `<meta>` into `window.OFFER_URGENT_HOURS` /
`window.OFFER_COUNTDOWN_HOURS` and applies `window.offerUrgency()`. **Do not re-type either
number in JS.** Under two days the countdown is shown in **hours**, because "2 days" and "31
hours" are the same fact told at different resolutions and only one makes somebody pick up the
phone.

## Stage colour lives in ONE function (3 Sep 2026)

`window.workStageClass(stage)` in `static/js/app.js`, beside `offerUrgency()` and for the same
reason: **two screens render the pipeline and must not grade it differently.** In progress has
a whole Stage column and needs no icon on it; the Waitlist queue hangs the badge under an
application status and marks it `fa-list-check` so the two are not read as one thing. Only the
colour is shared, exactly as only the offer GRADING is shared and the wording is not.

**Two stages are coloured, and the restraint is the point.**

- **`accepted` AMBER - the move is OURS.** They have said yes and the place is not in Kidsoft
  yet. This is what the whole change was for: a Kanwal family sat at `accepted` behind a grey
  badge and a green **Offer** button, which is the mark the queue uses for *nobody has acted*.
- **`enrolled` GREEN, and green means FINISHED.** Kidsoft shows the child in the room. Nothing
  else may be green or the colour stops meaning it.

Everything before an offer stays grey. "Somebody has rung them" is context, not a state to act
on, and colouring all eight leaves the eye nothing to land on - **the same mistake as colouring
none of them, reached from the other end.** `offered` stays grey too: on In progress the
urgency column beside it is already counting that clock down in red, and a second amber would
compete with `accepted` for the one colour that means *act on this*.

Measured on the real page: amber `#ffa800` with `#332200` ink (the documented pair - never
white on it), green `#198754` with white, the other six on `#f8f9fa`. Both screens read
identical values back out of `getComputedStyle`, which is what says they are one definition and
not two that happen to agree today.

## The contact log

`communications` was designed with the comment "any staffer can see prior parent contact" and
held **0 rows** ever.

> **`work_log_contact()` IS GONE (18 Sep 2026) AND `api/comms` IS THE ONLY WRITER.** It used to
> write this table from `/api/work`'s `advance`, which made `_incl/work.php` a second
> implementation of one record - and the two did not agree. See
> [One writer](#one-writer-of-communications-18-sep-2026) below.

- **THE `placement_id` HANDLE EXISTED FROM DAY ONE AND NO SCREEN EVER PASSED IT** (fixed 10 Sep
  2026). `api/comms` has always accepted a placement as well as a queue application, and the only
  caller in the app passed `waitlist_id` - so a family in the queue had a contact history and the
  same family, once enrolled, had nowhere to put one. The Support plans screen is the first
  caller to use it, and the endpoint was **not** rebuilt to add it. Because Kidsoft holds a
  ChildID for an enrolled child, notes logged that way **do** reach Kidsoft, unlike the 87% of
  the queue with nowhere to copy to. See [support-plans.md](support-plans.md).
- **Attached to the FAMILY, not to the item or the offer.** A family worked for a Tuesday last
  term and a Friday this one is one family with one history, so `work_contact_history()` looks
  up by `waitlist_id` **OR** `kidsoft_child_id`, and the work modal renders it in the same
  timeline as the item's stage history, **with each kind visibly marked**.
- **`staff_email` is NOT NULL** on that table and was missed on the first attempt - worth
  having anyway, because "who rang the Wilsons last" is a question the team asks each other.

### One writer of `communications` (18 Sep 2026)

`work_log_contact()` INSERTed into this table directly, from `/api/work`'s `advance`. It was the
**second** implementation of one record and it disagreed with the first on three things:

| | `work_log_contact()` | `api/comms` |
|---|---|---|
| Detail (`body`) | never written | written |
| Over-length summary | `mb_substr(..., 0, 255)`, silent | summary/body split, and `COMMENT_MAX` |
| Kidsoft copy | **never** | `comment_push()` |

So the identical sentence travelled to Kidsoft when typed in the family modal and did not when
typed in the work modal, **and neither screen said which** - on the one write in this app that
cannot be undone. And a note over 255 characters was kept in full on the item and clipped on the
family: two records of one sentence, disagreeing.

**`api/comms` gained a third handle, `work_item_id`**, alongside `waitlist_id` and
`placement_id`. It resolves the item, **widens to that item's application where there is one**
(so the note is filed against the FAMILY and both the family modal and `work_contact_history()`
find it), derives the centre from the row for `api_require_centre()`, and writes both keys -
exactly as the path it replaces did.

- **The record the Kidsoft copy hangs off is the APPLICATION's**, wherever there is one, so one
  family gets one answer about where their notes go whichever screen typed it. A tour enquiry has
  neither an application nor a ChildID and correctly comes back "nowhere to copy to", which is
  the normal case and not an error.
- **`COMMENT_MAX` was declared in `comments.php` on 14 Aug and never enforced.** It is now, on
  the joined summary+body, because the work modal posts a 3000-character detail field where its
  old path capped the whole note at 500 - and an over-long Kidsoft write either fails or succeeds
  irreversibly.
- **`/api/work`'s `advance` no longer reads `channel` or `direction` at all.** What stays there
  is the ITEM's own history: `note` explains that stage change.
- **The browser sends the note to `advance` ONLY when the stage actually moves.** That endpoint
  has a branch filing a note as a `from == to` event, which existed because the note had nowhere
  else to go and was being thrown away. It has somewhere now - every note is a contact - and
  sending one anyway would put a pseudo-event in the item's history for every note typed.

### A double-clicked Save files one note (2 Nov 2026)

On 29 Oct Emily's work modal Save went twice in the same second (Henry Girkin, item 109), and
the same phone note was filed twice, as notes 46 and 47. Neither had gone to Kidsoft, because
the family has no Kidsoft record. **But the nightly backlog would have copied both when the
application converted, and a Kidsoft comment cannot be deleted.** Note 47 was removed at Broc's
request (`comm.delete`, with the reason). No other double submit was found: the audit log was
searched for the same create by the same person within three seconds, and notes and stage
changes for identical pairs within a minute.

- **The screens hold a form while it saves:** "One save at a time" in `app.js`. See
  [ui-conventions.md](ui-conventions.md).
- **`/api/comms` `add` is the backstop**, because it is the one writer of `communications` and
  the one write here that cannot be taken back. **The same person, the same family (the
  application, child-on-the-board and work item handles, resolved), the same direction, channel,
  summary and detail, within 30 seconds** returns the note already filed with `duplicate: true`.
  It inserts nothing, writes no audit row, and does not push to Kidsoft. A `GET_LOCK` keyed on
  the person, the handles and the summary makes that hold for two requests that arrive together,
  which is what a double click is. Measured: three identical posts sent at once filed one note,
  and a different note in the same second was filed normally.
- **Two identical notes 30 seconds apart are both filed.** That is a second contact, not a second
  click.

### Deleting a note (30 Oct 2026)

`/api/comms` `delete` had existed from the start, for the author, the same day, and **no screen
ever called it**. So the first removal (29 Oct, Emily's "Wants March 2027") was done by hand in
the database at Broc's request. **An administrator may now delete any note at any age** (Broc,
30 Oct 2026). The author rule is unchanged server-side and still has no button.

- **One button, `window.commDeleteBtn(c)` in `app.js`, and one handler**, drawn on the three lists
  that show our notes: the family modal, Support plans and the work modal's timeline. The
  printed report has none. The role reaches the browser as `<meta name="user-admin">`, which only
  decides whether the button is drawn. **The endpoint re-checks `user_is_admin()` on the click.**
- **THE KIDSOFT COPY STAYS.** `createComment` has no delete and no other write is authorised. The
  confirm says so when the note travelled. Once our row is gone, the copy is no longer filtered
  out by `kidsoft_comment_id`, so it shows in the Kidsoft block instead. That is accurate.
  **The audit row keeps `kidsoft_comment_id` and its target**, because nothing else records which
  Kidsoft comment came from here once the row is gone.
- **A FOLDED NOTE TAKES ITS WORDS OFF THE STAGE CHANGE TOO.** A work modal save that moves the
  stage writes the summary twice, once on the `work_item_events` row and once here, and the
  timeline folds the two into one line. That was **6 of 19 notes** when this was built. Deleting
  only the contact would leave the sentence on screen as the stage change's note, so `delete`
  sets `note = NULL` on the matching event: same item, exact same words (`utf8mb4_bin`), written
  within 120 seconds. **The stage change itself stays**; it is the item's history. The window is
  wider than the timeline's same-minute fold on purpose: the two writes are separate HTTP
  requests and can fall either side of a minute.
- **One row, every sibling.** `communication_siblings` cascades, so a note shown on a brother or
  sister goes from both. The confirm names them.
- **Editing (12 Nov 2026) is Support plans only**, for `local_only` notes, any role - see
  [support-plans.md](support-plans.md). `/api/comms` `update` rewords a folded stage event the same
  way, through the shared `comms_folded_events()`.
- Audit: `comm.delete` with `summary`, `author`, `as_administrator` when it was not their own,
  the Kidsoft fields when the note travelled, and `event_notes_cleared`.

**Verified 30 Oct 2026** in Chromium on throwaway `local_only` rows, so nothing could travel: a
manager got no button and was refused by the server. As an administrator, the button worked on
someone else's three-day-old note, on a note marked as copied (the confirm named the Kidsoft
copy), on a folded note in the work modal (stage line stayed, its words went), and on Support
plans. The test rows, the test stage event and their audit rows were removed afterwards.

### The Kidsoft bridge (14 Aug 2026)

`_incl/comments.php`. The team's phone/tour/offer history lived in Kidsoft Enrol Notes, ticked
confidential, and the ask was to stop double-keying it. **A two-way sync is impossible and the
reason was measured, not assumed** - see [kidsoft-api.md](kidsoft-api.md) for the full census.
The short version: Kidsoft creates a CHILD record when an application converts to an ENROLMENT,
so **of 719 open applications only 2 carry a ChildID and 92 a GuardianID**, and a comment written
against a `WaitingListID` cannot be read back by anything.

**Reach now grows on its own, and that is the whole of the 17 Aug 2026 change.** Those figures
describe a family who is STILL WAITING. The import takes Kidsoft's ids on every row it holds
whatever the status, so the day a family converts, their ChildID lands on the application and
`comment_push()` - which targets `kidsoft_child_id`, then `kidsoft_guardian_id` - can reach
them without anybody doing anything.

### The backlog travels too (19 Aug 2026)

The entry above ended "nothing is pushed retrospectively", and that was the wrong half of the
feature: a family's history is written during the 21-day median WHILE THEY ARE UNREACHABLE, so
"reach grows" without a backfill means Kidsoft gets everything from the conversion onwards and
none of the part that explains it. `comment_push_backlog()` in `_incl/comments.php` is job 7 of
`cron-nightly.php`, sitting immediately after the queue refresh because that is the step that
makes a family writable.

- **OLDEST NOTE FIRST, AND THAT IS NOT A PREFERENCE.** Kidsoft returns **no date on a comment** -
  `comment_kidsoft_existing()` has to render them undated for exactly this reason - so
  insertion order is the only ordering a human reading that record ever gets. Pushing a
  family's backlog newest-first would put their history into Kidsoft backwards, permanently.
- **THE STAMP CARRIES WHEN THE CONTACT HAPPENED, not when the copy was made**, plus
  `(copied <date>)` when those differ. It used to be `date('now')` on both paths, which was
  already loose live (the form accepts a backdated contact) and would have been plainly false
  here. With no date field on Kidsoft's side, that one line is the whole answer.
- **WHAT IT RETRIES IS DECIDED BY WHAT WE KNOW.** Never attempted, or Kidsoft answered and
  declined (`Status != OK`, so it did not write) - pushed. **Transport failed or the reply was
  not JSON - LEFT ALONE and reported by name in the cron log**, because Kidsoft may have taken
  the comment and lost the answer on the way back, there is no read path to check with, and a
  retry would leave a permanent duplicate on a family's record. A person decides that one; a
  nightly job does not.
- **A refusal is retried every night**, deliberately: one strike and never again fails silently,
  and the count is in the log where somebody can see it.
- **`COMMENT_BACKLOG_MAX` (200) bounds a run**, and what is held back is counted and logged.
- **A NOTE ASKED TO STAY HERE NEVER JOINS THE BACKLOG (19 Oct 2026), and until then it would
  have.** An internal note (the work modal's DEFAULT chip) and a support plan note were skipped
  when written and left with no `pushed_at` and no error - the exact shape of "had nowhere to go".
  So the first night after the family enrolled (or, for a support plan note on a child already in
  care, the very next night) the sweep would have copied them onto the child's Kidsoft record
  permanently. Found before one had been written: 4 notes group-wide, all calls and emails.
  `communications.local_only` is now stored by `/api/comms`, and the sweep selects
  `local_only = 0 AND direction <> 'internal'`. Checked on a rolled-back transaction: a family
  given a ChildID with one call, one internal note and one local-only note offers the sweep the
  call alone.
  createComment has no update and no delete, so an unbounded loop over a table nobody has looked
  at is the wrong shape.
- **The modal's "not copied" sentence was FALSE the moment this shipped** and was rewritten:
  "they are not copied into Kidsoft" told a staff member to keep double-keying the note, which
  is the work this tool exists to remove. It now says they are copied across on their own if the
  family enrols. **A copied note's tick carries the copy date on its `title`**, since it is not
  always the day the note was written.
- **Nothing has been pushed in production, because `communications` still holds 0 rows.** The
  sweep was driven end to end against a local stub of `Comment/createComment` instead: four
  notes on one linked family, chronological, `Confidential=true`, the refusal recorded and
  retried on the next run, the unknown-outcome row untouched, and a dry run making zero calls.

So `communications` **is** the record and Kidsoft gets a one-way copy where it has somewhere to
put it:

- **`comment_push()` runs AFTER the endpoint's own INSERT and never throws.** A push failure is
  not a failed save - losing a staff member's typing because a third party was down is the worse
  bug. `kidsoft_pushed_at` / `kidsoft_push_error` carry the outcome, and the modal shows three
  distinct states: copied, not copied, nowhere to copy to.
- **The endpoint was NOT rebuilt.** `/api/comms` already had list/add/delete and a modal on two
  screens; a second way to write a contact note would be two implementations of one record. The
  bridge is additive.
- **The cached comment payload is dropped after a successful push**
  (`kidsoft_cache_forget_comments()`), or the note would not appear in the Kidsoft column until
  the TTL expired and the staff member would file it again.
- **Kidsoft's comments render SEPARATELY and clamped to four lines.** They are mostly enrolment
  form capture - one real entry is forty lines of sleep times and dietary notes - and unclamped
  they pushed the contact log the team came to read off the bottom of the modal.

## Still not done

**Ctrl+K does not search work items**, so "what is happening with the Cooks" has no answer from
the palette.
