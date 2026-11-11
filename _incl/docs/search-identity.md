# Search, child identity, and name matching

Governs `api/search.php`, `static/js/search.js`, `_incl/search.php`, `_incl/identity.php`,
`_incl/contacts.php`, and the name-matching half of `_incl/drift.php`.

## Search: answering the call

**The one thing this app could not do was answer the phone.** A parent rings, the team has a
caller ID and maybe half a surname, and every screen is scoped to a centre and a year - so
answering meant guessing which of seven centres, then opening the Board, then the Waitlist, then
reading. The data was all present (12,699 guardians, 16,591 phone numbers, 707 applications each
carrying a parent mobile) and **none of it was reachable by the identifier a caller actually
gives you.**

`_incl/search.php` -> `api/search.php` -> `static/js/search.js`, a palette on **every** screen
(`layout_footer()` renders it; **Ctrl+K**).

### The palette renders no percentage, and its one figure is a DURATION

Checked 10 Sep 2026, by driving Chromium and opening the palette rather than screenshotting the
page behind it - the results only exist after a keystroke and a query. The header is counts
("5 enrolled, 1 waiting, 12 past applications, 18 not enrolled"), the cards are names, ages,
numbers and day strips, and the footer's **`took_ms`** is a search duration, not a proportion:
`(int)round()` on a figure measured at **330 to 5,369 ms**, with nothing beside it to
contradict. Nothing here goes through `board_pct()` and nothing should.

`search.php:207` divides a score by the token count, but that is the internal ranking average
and **is never rendered** - `search.js` reads no score field.

### THE SHORTCUT IS Ctrl+K AND NOTHING ELSE (17 Aug 2026)

**There was a `/` shortcut, guarded by "is the target an `input`, `textarea` or `select`", and the
guard is not the same thing as "is somebody typing".** It opened the palette in the middle of a
note being written and swallowed the slash. A bare printable character as a global shortcut is
only ever as safe as that guard, and one more case will always turn up - so the shortcut went
rather than the guard growing. Ctrl+K is on the sidebar button, and cannot be typed by accident.

**And Ctrl+K does nothing while another modal is open.** Every other dialog in this app is a
question part-way through being answered - an offer, a contact note, a placement - and the
palette stacked on top of one buries it behind a second backdrop whose only exit, Esc, also
discards the work underneath.

### It does not name the phone call in its own empty state

The heading is **"Find a child or a family"**, not *"Who is on the phone?"* (17 Aug 2026).
Answering the call is what this was built for and the module comments say so, but printing it on
the screen reads as the only sanctioned use, and the team also opens this to find a family before
ringing **them**, while reading an email, or part-way through a tour.

**It is chrome, not a fifth screen** - a call does not arrive while you happen to be on the
right page. The launcher sits **above** the four nav links for the same reason, and opens a modal
rather than being an input in the rail, so it works identically when the sidebar is collapsed to
its 4.25rem icon rail.

### NOTHING IS COPIED INTO OUR TABLES, and it does not need to be

Every guardian and every child-to-guardian link for all seven centres reads back out of
`kidsoft_cache` in **0.12s** (43MB peak), because `cron-nightly.php` already warms
`child_contacts()` per centre for the cell panel - which is exactly the two calls this needs
(`Children/getChildren&Options=Guardian` + `Guardian/getGuardians&Options=PhoneNumbers,Tags` -
the tags since 7 Oct 2026, for Do Not Enrol).

A `search_index` table would have been a copy that goes stale the moment a family changes their
number, **to save a tenth of a second**.

**That cron warm is load-bearing for search** and is commented as such. Remove it and the first
search each morning stalls for tens of seconds.

### A result is a CHILD, with everything merged onto them

Kidsoft's enrolment, our board rows, our queue rows and any open offer. **That grouping is the
answer**: "are they enrolled AND waiting for next year" is read, not assembled from three screens.

#### AND THE MERGE KEYS DISAGREED, SO ONE CHILD WAS SEVERAL CARDS (17 Aug 2026)

Each of the four sources keys its rows the best way it can, and the keys part company the moment
a link is missing:

- **Kidsoft's `ChildID` is per SERVICE.** A child who has been at two of our centres holds two of
  them and read as two people.
- **An application nobody has linked to a child record has no `ChildID` at all** and keys on the
  household, so the same child read once as their enrolment and again as their queue place --
  which is the one pair the person searching is trying to see together.

Measured: `Ezra Williams` came back as **three** cards (two Kidsoft records and an application),
`Austin Wilson` as **four**. `search_merge_children()` folds them, and the counts move with it
(`Williams` 104 results -> 98, `Wilson` 49 -> 44).

**The same child is folded on the folded NAME plus positive evidence: the same DOB, or the same
household phone (`sibling_phone_key()`, not the loose `search_phone_digits()`).** Name alone is
never enough and this is not theoretical - the queue is full of `TBA Smith` and `Baby Jones`
placeholders entered before a child is born, five separate families are on it as `TBA TBA`, and
those are not names.

**Measured before it was allowed, because a false merge HIDES a family** - the one failure this
screen must not have, and the reason the asymmetry table below still ends where it does. Across
all **5,974** applications the group can see (our queue table plus Kidsoft's full archived list):

| | |
|---|---|
| Distinct name+DOB pairs | 4,060 |
| **Held by more than one household number** | **97** |
| Of those 97, carrying a placeholder name | **0** |

Every one of the 97 is a real child whose two parents applied separately - both McCormacks, both
Milnes, both Gounders - so the merge is right on all 97. The placeholder rows collide on name
constantly and **never once on DOB**. If a placeholder DOB convention ever appears (a centre
entering 01/01/next-year, say), **re-run that census**: it is the only thing standing between this
rule and two unborn children becoming one card.

**`enrolled_centres` exists because of this merge.** The heading used to carry one centre name;
two children are enrolled at two centres at once right now, and a merged card that named only the
heading record's centre would say Luca Fordham is at Niagara Park and not say they are also at
Kanwal. It is emitted (empty) by **all four** constructors, not just the Kidsoft one - a field
one constructor sets reads as `undefined` the moment two records merge.

### AN ENROLLED CHILD'S CARD NAMES THEIR ROOM, AND THE ROOM IS A LINK (17 Aug 2026)

`search_attach_rooms()`, on Broc's ask. **A card for a child who is enrolled and not waiting had
nothing on it to act on** - a number to ring, an email, and no way through to any screen that
knew more about them. Every other line on a card opens the screen that owns it, and the commonest
result in the app opened nothing.

The line goes to the same cell panel a board row does
(`/placement?centre=&year=&room=&wd=<first day>`), which is where the room, the day, who else is
in it and what is free all already are.

- **It is READ from `board_live_children()`**, the app's one definition of the standing weekly
  arrangement - Kidsoft's BOOKINGS for the board's reference month. So it says what the child
  actually holds, **casual days are already excluded** by that function, and a room a child has
  come out of stops being reported the moment Kidsoft says so. Nothing is copied.
- **Affordable because the board already pays for it.** Measured 17 Aug 2026: a warm read of all
  seven centres is **0.06s / 20MB**, because `cron-nightly.php --warm` refetches exactly this
  hourly for this year and next. Cold it is 14s for the group, which is why **only the centres
  that appear in the results being returned are read** (usually one), the rooms are attached to
  the SLICE rather than to everything found, and an expired row is served stale and refreshed
  after the response. A name search goes from ~390ms to ~530ms worst case, peak 65MB against the
  128MB FPM limit.
- **The current board year only, measured rather than assumed.** Of 862 active children
  group-wide, **16 have no booking in this year's reference month, and not one of those 16 has one
  next year either.** A second year of lookups would have doubled the work to find nobody.
- **`enrolled_centres` carries the ChildID Kidsoft issued at that centre**, not the card's single
  `kidsoft_child_id`: the ids are per service, so one id would find a merged child at one centre
  and lose them at the other. Luca Fordham reads correctly as Little Angels at Kanwal on Tue/Wed
  **and** Little Angels at Niagara Park on Fri.
- **A Kidsoft failure loses the room line and nothing else.** The rest of the card is already
  built, and a search that answers slightly less beats one that answers nothing.

### AN ENROLLED CHILD'S NAME OPENS THEIR DIALOG (6 Nov 2026)

Broc: *"Would it be possible to open this new modal from search results?"* - the child dialog
the Placement board opens (`window.openChild()`, see
[placement-board.md](placement-board.md)). The room line above answers "where are they and who
else is in that room"; the dialog answers "who is this family": this year and next, what they are
asking for, parents, brothers and sisters, applications, support plans. A call needs the second.

- **The NAME is the door** (`childButtons()` in `search.js`), one per entry in
  `enrolled_centres`, by the ChildID Kidsoft issued AT that centre - a merged card's single
  `kidsoft_child_id` would open the right child at one centre and nobody at the other. A second
  centre gets a small "also at Kanwal" button beside the name.
- **Enter opens it** for an enrolled card; any other card still follows its first link.
- **A supervisor is offered no door they would be refused.** `search_scope_results()` has already
  dropped every `enrolled_centres` entry outside their centres, and `/api/child` runs
  `api_require_centre()` regardless.
- **The palette steps aside rather than stacking** (Bootstrap 5 does not stack modals). Its
  results stay, so Ctrl+K brings them straight back.
- **So the dialog is CHROME now**, like the palette: `layout_footer()` renders
  `_incl/child-modal.php` and loads `child-modal.js` on every page. On a screen without the
  application or In progress dialogs its rows link to `/waitlist?wl=` and `/progress?item=`,
  which open the record on arrival. A save fires `child:changed`, which the Placement board
  listens for so a flag set from search shows on the grid behind it.

### THE FUZZINESS IS DELIBERATELY THE OPPOSITE OF `name_is_near()`'s

Drift matching is timid because a false pair silently attributes one child's attendance to
another. **In a search box the asymmetry runs the other way:** a human reads every result before
anything happens, a wrong row costs a glance, **a missing row costs the phone call.**

So `search_name_score()` scores exact / prefix / substring / edit distance and **RANKS rather
than rejects**. **Do not reconcile the two - they answer different questions.** `osa_distance()`
and `name_fold()` are reused from `drift.php`, never re-implemented.

#### BUT THE TYPO TIER HAS A FLOOR, AND IT DID NOT (14 Aug 2026)

Ranking generously is not the same as calling any four-letter word a typo of any other, and the
difference was the single biggest source of noise on this screen. One edit was allowed from
**four** characters, which made `kite` a typo of `kate`. Measured on the query Broc reported:

| Query | Results | Solid (exact/prefix/substring) | Reachable ONLY by edit distance |
|---|---|---|---|
| `Kite` | 67 | **1** (Alby Kite) | **66** |
| `Alby` | 17 | 3 | 14 (Abby, Ally) |
| `Kate` | 173 | 79 | 94 |

**A substitution at four letters is not a typo, it is a different name - and this codebase
already knew that.** `name_is_near()` refuses Eva/Ava and Indy/Ivy at exactly this length, both
real pairs at these centres. So the **same 6-character floor and the same adjacent-transposition
exemption are reused** (`is_single_transposition()`, from `drift.php`) rather than a second set
of numbers being invented: a swap preserves the letter multiset and is something fingers do, a
substitution at short length is somebody else.

After: `Kite` returns **1**, `Alby` 3, `Kate` 46, and every result on all three is a solid match.
Nielsen/Neilsen, Ryan/Ryna and Blackmore/Bloackmore all still match.

**This does NOT reconcile the two matchers and must not be read as doing so.** Search still ranks
rather than rejects, still scores prefixes and substrings, still allows two edits on a long
token. Only the floor moved, and it moved onto a number this repo had already justified.

### A NAME matches every guardian, PARENTS FIRST; a NUMBER matches everybody equally

`search_guardian_is_parent()`. An active child carries 2-8 guardians, and across **17,417 links**
`Grandmother` (3,070) and `Aunt` (1,548) are ordinary entries - so typing a surname matched every
grandmother, aunt, godmother, neighbour and case worker who shared it. `Smith` returned **65
non-parent guardian hits against 20 parent ones**.

**It was a FILTER from 14 Aug and became a RANK on 29 Oct 2026** (Broc: *"need to be able to
search parent/guardian names"*). As a filter it made non-parents unfindable by name at all:
**`susan noble` returned nothing**, because Susan Noble is Noah Noble's Grandmother, and the only
way to her grandson was already knowing his name. The palette log shows the team typing exactly
that and giving up. A name hit now scores the name plus **0.4 for a parent, 0.8 for anybody
else**, so the Smith ordering - children, then parents, then everybody else - is kept and nobody is
hidden. Ranking rather than rejecting is this module's rule everywhere else.

Measured after: `susan noble` 0 -> 3 (all three grandchildren, the card saying *Parent or contact:
Susan Noble (Grandmother)* and the call button still on the mother or father). `Smith` 145 -> 206
results, but **the visible 25 are the same live children as before**; `Noble` 6 -> 8, `Farrugia`
10 -> 15.

#### The SECOND PARENT on an application (29 Oct 2026)

Our queue table holds ONE guardian per application, `ParentGuardianOne`, the parent who filled the
form in. **1,281 of the 5,450 applications Kidsoft holds name a second parent
(`ParentGuardianTwo`), 156 of them on the 1,015 we hold in the queue, and none of those names were
reachable** - a father ringing about his child's application was not on the screen under his own
name. `search_second_parent()` reads it, on open AND closed applications, and ranks it as a parent
(+0.4). Its `RelationshipToChild` is an unlabelled numeric id, so nothing claims Mother or Father.

- **The card sets `matched` when the second parent is what matched**, because the call-back strip
  names the FIRST parent and a search for "Lauren Bond" landing on a card that only says "David
  Dougan" reads as a wrong result. A first-parent hit still sets nothing - the strip already names
  them.
- **Open applications read it from the history payload, decoded a SECOND time**
  (`search_second_parents_held()`, **143 ms** warm, name queries only). The queue is attached before
  the history and the history needs the queue's cards in place to join them, so a single pass would
  have meant restructuring both. The importer's own open-only payload is smaller but has a 15-minute
  TTL and no daytime warm, so reading it from here would have started Kidsoft refetches on
  searches. Not copied into `waitlist_applications`: Kidsoft holds it.
- Verified: **30 of 30** held applications' second parents found by full name.
- **Their mobile and email are NOT searched.** Only the name was asked for; the phone path still
  matches the first parent's mobile alone. That is the next gap if a second parent ringing from
  their own handset ever comes up.

**The phone and email paths carry no penalty at all, and that asymmetry is the feature.**
Any handset in the family can ring: the Farrugia case below - a call arriving from the AUNT's
number, answered with the mother's name on the call-back button - is what this screen is *for*.
**A name is something you type; a number is something that rings you.** Verified after the change:
searching a shared landline still returns Charlotte Smith matched on Renee Noble (Friend Of
Family) with the call button set to Elise Smith (Mother).

The test is Mother/Father **OR** `Enrolment` = true. The second limb matters: 5,560 links are the
signer, 90.5% of them Mother or Father, and **the remaining ~530 are the grandmother or aunt who
is the child's actual carer and signs for their days.** Kinship carers are the family, whatever
`Relationship` says.

### Split both sides of a name the same way

A query splits on non-letters, so "Summer-Le Farrugia" typed in is three tokens, while
`name_fold()` on the stored "Summer-Le" collapses to one part - the token "le" then matched
nothing and **searching a real parent's name returned zero results**. `search_name_parts()`
splits the target the same way, which also fixes apostrophes and middle names.

### WHOSE NUMBER IT IS is part of the answer, not a detail

**3,184 guardians hold more than one number and 1,591 numbers sit on more than one guardian**,
and the `Type` free text includes Grandmother, Aunt, Neighbour and Case Worker. So a hit reports
**two** contacts:

- **`matched`** - the handset that rang
- **`contact`** - who to ring back, down the same ladder `child_contacts()` uses, via the
  extracted `guardian_links_ranked()` / `guardian_contact()`

Verified in live data: searching "Farrugia" returns Alessia Leatham, matched on her **Aunt**
Christine Farrugia, with the call button set to **Donna Starkey (Mother)**. **Ringing the aunt
back is a mistake that only looks like a phone call.**

**THE TYPE IS EMPHASISED UNLESS IT IS A MOBILE** (13 Aug 2026). It was muted always, which is
backwards: on a mobile the type is incidental, but on a WORK or HOME number **it is usually the
reason a result set looks wrong.**

Reported as *"the search might be a bit too fuzzy"* after one number returned 12 children from 5
families - and **it is not fuzzy at all: every one of the 12 scored 0, an exact match.** The
number was the employer's switchboard behind the sibling work-number bug. The palette had been
explaining itself on every row, in the greyest text on the line.

**Before loosening a matcher because the results look wrong, check the scores.** The alternative
diagnosis is that the data is telling you something true about a number.

### CLOSED AND ARCHIVED APPLICATIONS ARE SEARCHABLE, AND ARE NOT IMPORTED

`search_attach_kidsoft_history()`, added 14 Aug 2026 on Broc's ask. The queue table holds only
what the team still has a decision to make about - `waitlist-import.php` refuses closed rows,
because 957 of the 1,698 in the live window are `ExpiredWaitlist` and would bury the 708 that
matter. **That rule is right and is untouched.** But "not in the queue" was being read as "we have
no record", so the history is read straight out of Kidsoft instead, exactly as this module already
reads children and guardians. **Nothing is copied into our tables:** an archived application is
pure Kidsoft history, we hold no offer against it and make no decision on it, so a copy could only
go stale.

**`ShowArchived` IS THE FINDING, and it is the META-RULE again - it defaults to `false`, so every
call this app had ever made was blind to it.** Censused group-wide 14 Aug 2026:

| | Rows |
|---|---|
| `getWaitingList` default | 1,698 |
| `getWaitingList&ShowArchived=true` | 5,221 |
| **Never once visible to this tool** | **3,523** |

Coverage on them is good enough to search: **child name 99%, DOB 99%, parent mobile 99%.** Cost is
**5.4s and 16.9MB cold** for all seven centres against ~0.3s warm, hence `SEARCH_HISTORY_TTL`
(36 h, chosen to outlast the gap between nightly runs) and a warm in `cron-nightly.php` beside the
`child_contacts()` one. `search_history_params()` is shared by the read and the warm, because a
warm fetching different parameters writes a different cache key and leaves the read cold while the
log reports success - invisible from either end.

**Tenure reads the same entry** (29 Oct 2026), for what a family who started and left between two
readings had asked for - see [tenure.md](tenure.md). That is why the params carry
`WithRequestedDays`: +6% on the payload (Tuggerah 828 KB -> 874 KB gzipped) rather than a second
copy of the whole archive in the cache. **Changing these params changes the cache key**, so the
day's warm entry goes cold; re-run `search_history_warm()` for every centre straight after (6s), or
the first search of the day waits for it.

#### AN ENROLMENT IS NOT A PAST APPLICATION, AND 81% OF CARDS SAID IT WAS (20 Aug 2026)

**Kidsoft issues the `ChildID` at the moment an application converts, so a successful application
leaves an archived row behind carrying `Status = Enrolled`.** Every archived row was being filed
into this section without the outcome ever being read, so Rose Howen's card said
*"Tuggerah - Enrolled - applied 14/08/2024 - Archived"* directly underneath the green **Enrolled**
badge and the room she is in on Mondays. Reported by Broc as the card "not presenting correctly".

| | |
|---|---|
| Archived rows visible to search | 4,465 |
| Of those, `Status = Enrolled` (converted) | **1,197** |
| **ACTIVE children carrying one on their card** | **694** |

**And it broke the badge in the other direction, which is the half that costs a phone call.**
`statusBadge()` shows "Past application" whenever any history is present - a family who never got
in - as against "Not enrolled", **a family who LEFT and can be asked back**. The converted rows
belonging to children since gone made **481** of them read as the first when they are the second,
and `counts.past` reported them in the summary line to match.

`Status = Enrolled` is now skipped outright rather than relabelled: the card says it twice already
(the badge, and the live room line above), and when the child has since left the history list
correctly falls empty and the badge lands on "Not enrolled". **A genuinely closed application
still shows** - Rose keeps the duplicate her father lodged and the centre deleted.

- **The archived set contains statuses the importer's map has never seen** - `RejectedByService`
  (555), `DeletedByService` (484), `RejectedByParent` (21). They are **not mapped**, and must not
  be: this path never writes, so it shows Kidsoft's own word for the outcome
  (`search_history_label()` only spaces the CamelCase). Only the importer needs a mapping, and
  only for rows it takes ownership of.
- **A row we already hold is skipped** on `kidsoft_waitlist_id`. `search_attach_waitlist()` has
  already produced it from our table with the queue position and priority reasons that only exist
  there; this adds history, never a second copy of a live application.
- **A ROW WE HOLD AS `archived` IS NOT SKIPPED (11 Nov 2026).** `search_attach_waitlist()` leaves
  status `archived` out on purpose, and the skip above took every id we hold - so the two rules,
  each right on its own, dropped **all 254** of them between them, 51 `RejectedByService`. Found
  while building the Waitlist's copy of these lines ([waitlist.md](waitlist.md)), on the day the
  team was archiving through the queue: Rome Guilbert's Wamberal application was archived at 16:23
  and would have vanished from the card. `search_history_held()` splits what we hold into `shown`
  and `archived`, and ours is taken as the word on archived - the history payload is up to 36 h
  older than our import, and 59 rows we held as archived still read un-archived in it that day.
- **ONE DEFINITION OF A PAST APPLICATION, shared with the Waitlist** (11 Nov 2026):
  `search_history_line()` decides what counts and what it says, `search_history_order()` folds and
  sorts, `window.pastApplicationLines()` in `app.js` draws it. The Waitlist's column and family
  modal read them through `waitlist_history()`.
- **Merged on the same two-part key** as unlinked applications: Kidsoft's `ChildID` when the child
  was eventually enrolled (34% of archived rows carry one), otherwise folded child name PLUS the
  parent's phone.
- **History does not lift a result.** `sort` still adds 4 to anything with nothing live, so a
  search for `Smith` shows all 25 live children first and the 57 past applications below them.
- **`counts.past` is split out of `counts.archived`** because they are different answers: "not
  enrolled" is a family who left and can be asked back, a past application is a family who never
  got in.
- Capped at `SEARCH_HISTORY_PER_RESULT` (6) per card, newest first, with "and N older
  applications" underneath. **Folded before it is counted and capped**, or the "and N older"
  sentence counts rows the card will never print.

#### FOLDING TWO IDENTICAL LINES ALMOST DELETED A TWIN (20 Aug 2026)

`historyLines()` prints four things - centre, outcome, applied date, Archived tag - so two rows
agreeing on all four are two lines a reader cannot tell apart. `search_history_fold()` collapses
them, keyed by `search_history_fold_key()`.

**Five collisions exist group-wide, and only two of them are duplicates.** Lily Rodgers holds two
applications lodged thirteen days apart and closed the same day, and Kanwal's `Jelly Fish` test
family the same. **The other three are TWINS** - `Twin 1 Moffitt` and `Twin 2 Moffitt` at
Tuggerah, the Wolfendens at Niagara Park, the Pans at Point Clare - two children, two real
applications, identical on screen only because the line never prints which twin it is.

So the key carries **the child's own name from the application, raw**. Not `name_key()`:
**`name_fold()` strips digits**, so it reads `Twin 1` and `Twin 2` as one name - which is exactly
why the pair share a card in the first place. Case and spacing are folded, nothing else.

Run in **two** places, because a child can reach one card down two keys (Kidsoft's `ChildID` on
the application that converted, the household rule on one that did not) and the same line can
arrive once down each: where the rows are gathered, and again in `search_merge_group()` after the
concatenation, where whatever goes is taken off `history_total` as well.

**Measured after: 3,268 rows -> 3,266, two cards affected, all three twin pairs intact.**

**A `Twin 1` / `Twin 2` pair still shares one card**, headed by whichever came first, and that is a
separate open defect in the merge keys - not this fold. See the note under
[One family, one row](#one-family-one-row).

### One family, one row

Unlinked waitlist applications merge on folded child name **PLUS** `sibling_phone_key()` (the
household rule, not name alone). One real family holds five open applications and listed as five
separate children before this; it now reads as one child with a queue position at each of five
centres.

**`search_phone_digits()` is NOT `sibling_phone_key()`.** The sibling key truncates to the last 9
digits, which is right for asserting two families are one household and **wrong here**, where
somebody types the four digits they can read off a handset. Matching is equal / same-last-9 /
ends-with / contains, in that order, so `0412 345 678`, `+61412345678` and `049830` all find the
same family.

**And `search.php`'s family key is deliberately NOT the group register's** - see the grouping
rule in [waitlist.md](waitlist.md). Search asks "is this the person on the phone" (a duplicate
costs a glance); the register asks "how many families are we holding" (a duplicate is a wrong
headline figure). Primitives shared, **policy not.**

#### `name_fold()` STRIPPED DIGITS, SO TWINS SHARED A CARD (fixed 20 Aug 2026)

**`name_key('Twin 1', 'Moffitt') === name_key('Twin 2', 'Moffitt')`.** Two children, one household
phone, one card - headed by whichever record sorted first, with the other twin's name appearing
nowhere on it. **A false merge HIDES a family**, which the asymmetry table at the foot of this
file names as the one failure this screen must not have.

Found while folding duplicate history lines. Censused over every row in the app carrying a digit
in the name: **20 Kidsoft waitlist rows in 14 household keys, 6 of 715 open applications, and NO
Kidsoft child record and NO placement at all.** Four keys held more than one distinct name:

| Key | Rows |
|---|---|
| `twin\|moffitt` | Twin 1 / Twin 2 Moffitt, Tuggerah, both DOB 27/07/2022 |
| `twin\|wolfenden` | Twin 1 / Twin 2 Wolfenden, Niagara Park, both DOB 08/01/2025 - **two OPEN applications** |
| `twin\|pan` | Twin 1 / Twin 2 Pan, Point Clare, both DOB 01/05/2025 |
| `baby\|haigh` | `Baby 2 Haigh` / `Baby Haigh 2`, Terrigal - the same child typed twice, and correctly merged |

**Twins defeat every discriminator the merge rules have**: same surname, same date of birth, same
household phone, and a placeholder first name whose only distinguishing mark is the digit
`name_fold()` removed. The census that authorised `search_merge_children()` counted name+DOB pairs
held by more than one HOUSEHOLD and found the placeholders never once collided on DOB - **it never
tested two placeholders inside ONE household**, which is this case, and the rule it wrote is
otherwise untouched.

**The fix is `name_identity_key()`** in `drift.php`: `name_key()` with `name_digit_key()`
appended - the digits of the whole name as a **sorted multiset**. Sorted and kept separate from
the letters because Terrigal's queue holds `Baby Haigh 2` while Kidsoft's archive holds
`Baby 2 Haigh`, one child with the digit landing in a different part each time; a multiset ignores
WHERE the digit sits without ignoring WHICH digit it is. A name with no digit yields an empty
suffix, so **every other key in the app is byte-identical to what `name_key()` already produced,
and a key can only ever become MORE distinct, never less.**

Used at the four places that decide two records are one child - the two attach keys, the
`search_child_signatures()` merge, and `waitlist_group_all()`. **The signature had to move too:**
without it the attach steps keep the twins apart and the union-find joins them straight back, on
the folded letters plus the DOB they share.

**`name_key()` is unchanged and remains right for comparing, ranking and suggesting.** The
Kidsoft-keyed indexes (`enrolled_days_index()`, `waitlist_mark_in_care()`, `tenure.php`,
`reenrolment.php`) stay on it deliberately: no Kidsoft child record carries a digit, so they
cannot see one.

Measured after: **all three twin pairs are two cards, Baby Haigh stays one**, and across all 770
applications open and closed the register's grouping changed in exactly one place - `201,202`
became `201` and `202`. Group totals for `Smith` (144), `Howen` (5), `Williams` (96) and `Wilson`
(44) are unmoved.

### Other rules

- **A centre Kidsoft will not answer for is reported, not swallowed.** `degraded` carries the
  names and the palette says so - **a family missing from a search otherwise reads as a family we
  do not have.**
- **Results deep-link, and every line links to the ROW IT IS.** An enrolled child's room opens
  that room's cell panel (see the section above), and it is listed FIRST, above the board rows and
  the queue - for an enrolled child it is the answer, and it is what Enter opens. A board row opens
  `/placement?centre=&year=&room=&wd=`, which `board.js` reads once (`#init-room`/`#init-wd`) and
  opens that cell panel - each line already carried its own centre, year, room and first day, so
  merging changed nothing there. A queue row opens
  **`/waitlist?centre=&wl=<application id>&q=<the application's own child name>`**, which opens
  that family's modal on arrival with the closed filter ON, because a search hit may itself be a
  closed application and an empty table would read as "not on the list".
  **`q=` alone could not survive the merge** (17 Aug 2026). It carried the CARD's name, and one
  card can now hold four queue lines - Billy Bovis waits at Tuggerah twice, Terrigal and
  Wamberal - so four lines produced links that were identical at a centre and could not say
  which application was clicked. The card's name is also Kidsoft's legal name whenever a Kidsoft
  record was merged in, while the queue filters on what the family wrote on the form (a nickname,
  or "Baby Jones"). Measured across 260 queue lines the name still matched every time, so this is
  a fragility fixed before it broke, not an outage: `child_name` is emitted per application and
  the **id** is what the link is built on.
- **A history line is still NOT a link** - see the section above. Merging did not change that:
  the row is Kidsoft's, not ours, and `/waitlist` has nothing to open for it.
- Timing on live data: **170ms** for a phone number, **~390ms** for a name (edit distance runs).
  Since 29 Oct 2026 a name is **~580-820ms** warm: 143ms for the second parents of open
  applications, the rest scoring the ~9,000 non-parent guardian links that used to be skipped.
- **`fa-regular` is not vendored.** Only `fa-solid-900` and `fa-brands-400` are in
  `static/css/fonts`, so a `fa-regular` glyph **renders blank**. Caught only by looking at the page.
- `priority_reasons()` is called on a raw row here, so the palette shows the stored sibling flag
  alone - deliberate, see [waitlist.md](waitlist.md).
- **`dne` is on every card, from every constructor** (12 Oct 2026) - the Do Not Enrol badge
  beside the status. On a Kidsoft card it comes from the child's own parents' tags, which is the
  card a call from a family we ended care for brings up. Out-of-scope cards lose it with the
  rest. See [waitlist.md](waitlist.md).

## Child identity: picked from Kidsoft, never typed

`_incl/identity.php`. **A placement's identity is its `kidsoft_child_id`.**

The `child_first_name` / `child_last_name` / `child_dob` columns are a **system-written cache** of
what Kidsoft holds, refreshed by `placements_sync_identity()` and stamped with
`identity_synced_at`. **No human types them while a row is linked** - `save_placement` discards
any name or DOB the browser posts for a linked child and re-reads Kidsoft.

The placement editor leads with a **child PICKER** (`/api/board?action=children`, scoped to ACTIVE
children - 261 of Tuggerah's 1,467); typing is the fallback, not the default.

**The columns were kept rather than dropped, deliberately:**

1. **11 children** on the 2026 Tuggerah board have surnames appearing nowhere in Kidsoft's 1,467
   records. They are real and pencilled in ahead of Kidsoft; **a link-only schema would have
   erased them.**
2. A board is an annual **historical record** - a child archived in Kidsoft must still have a
   name on last year's board.
3. **The board must render when Kidsoft is unreachable.**

**A row with no `kidsoft_child_id` is PROVISIONAL**: typed, and awaiting a link. The
`ck_place_identity` CHECK enforces that a row is either linked or named, **never neither**.

What the migration bought: **DOB coverage went from 25 of 261 placements to 235** - age
eligibility, sub-quotas and the whole roll-over depend on DOB and were silently degraded for 90%
of the board.

### `name_case()`

Presents Kidsoft's BLOCK CAPITALS for reading. **Conservative on purpose:** already-mixed-case
input is returned untouched ("van der Berg", "DeSilva"), and it capitalises **Mc** but **never
Mac** - this dataset contains MACHADO, which a Mac rule would turn into "MacHado".

**Casing never affects logic; every comparison folds case.**

## Name matching (`name_fold()`, `osa_distance()`, `name_is_near()`)

Lives in `_incl/drift.php` and is reused by `search.php` and `siblings.php`. **Do not delete it
when tidying the retired drift code.**

Matching is by `kidsoft_child_id` when set - **linking a placement is the permanent fix**, after
which spelling stops mattering. Otherwise it falls back to exact folded name, **tried against
BOTH the Kidsoft legal name and the Kidsoft `NickName`**, since the board is typed by staff who
use the name the family uses ("Alex" for ALEXANDER COOK). **A key carried by more than one booked
child is refused outright rather than guessed.** Nicknames alone removed 8 of Tuggerah's 87 drift
rows.

Beyond exact, it falls back to a **suggestion a human must confirm**. `name_is_near()` judges
first and last names separately and **allows only one edit total**. Tolerance is length-scaled --
one edit once a part reaches 6 characters - **except that a single adjacent transposition is
allowed at any length**, because a swap preserves the letter multiset and is something fingers
do, whereas a substitution at short length is usually a different child.

These real cases must all be right:

```
Knox Blackmore / Knox Bloackmore  -> suggest (a genuine workbook typo)
Harper Nielsen / Harper Neilsen   -> suggest (transposition; hence OSA, not plain
                                     Levenshtein, which scores a swap as 2)
Liam Ryan      / Liam Ryna        -> suggest (transposition below the 6-char floor)
Eva Lamrock    / Ava Lamrock      -> NEVER (different children, both real)
Indy Park      / Ivy Park         -> NEVER (siblings)
Isaac Wurth    / Issac Wurth      -> NEVER, and this one is deliberate: it is a
                                     SUBSTITUTION at 5 characters, the same shape as
                                     Layla/Kayla. Costs one manual link.
```

**Err towards no suggestion: a miss costs a manual link, a false pair attributes one child's
attendance to another.**

### Where the asymmetry runs the other way

| Consumer | Asymmetry | Behaviour |
|---|---|---|
| `name_is_near()` (drift/identity) | A false pair corrupts attendance | **Timid** - reject unless nearly certain |
| `search_name_score()` | A missing row costs the phone call | **Generous** - rank, never reject |
| `search_merge_children()` | A false merge hides a family behind another | **Timid** - name AND (DOB OR household phone) |
| `siblings.php` | A false sibling promotes a family over one that applied earlier | **Timid** - phone/email only, never a name |
| `waitlist_group_all()` | A false merge hides a family completely | **Timid** - name AND DOB AND a shared contact |
| `reenrolment_declines()` | Wrong child = wrong room reported free | **Exact only**, ambiguity refused |

**The two rows that key on a name to decide two records are ONE CHILD -
`search_merge_children()` and `waitlist_group_all()` - use `name_identity_key()`, not
`name_key()`**: a merge key must not throw away the digit that is all there is between `Twin 1`
and `Twin 2`. See the twins section above. `siblings.php` never keys on a name at all and is
unaffected; the comparing and ranking rows keep `name_key()`, which is what it is for.

**These are different policies on purpose. Do not unify them** - note that the two search rows
disagree with each other deliberately: ranking a row into view is generous, deciding two rows are
one child is not.
