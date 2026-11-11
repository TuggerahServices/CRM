# The Kidsoft API

Governs `_incl/kidsoft.php`, `_incl/kidsoft-cache.php`, `_incl/kidsoft-config.php`.

## STRICT READ-ONLY

**There is ZERO authorisation to edit, insert or delete data through this API - no
exceptions.** Only call `get*` endpoints. NEVER call a write/mutating endpoint, even to
"test". `Waitlist/createEntry` and `Notification/acknowledge` exist and are **out of
bounds**.

`kidsoft()` **hard-refuses any action not matching `^get[A-Z0-9]`**. That is where the rule
is enforced in code - do not weaken it.

### `Comment/createComment` was asked about, and MEASUREMENT killed it (14 Aug 2026)

Broc asked whether the contact log could be pushed back into Kidsoft Enrol Notes, since a PAM
had shown him enrolment comments via the CHILDREN list for children "on the waitlist". The
endpoint fits on paper - it takes `ChildID` / `GuardianID` / `WaitingListID`, `Comment`,
`Type`, `Confidential`, exactly their practice - and comments do read back through
`getChildren` / `getGuardians` with `Options=Comments`.

**But a Kidsoft Child record is created when an application CONVERTS TO AN ENROLMENT, not when
the family applies**, which is why the PAM's route worked: those children had already enrolled.

**Re-measured 17 Aug 2026, and this table replaces one whose numbers did not match this
endpoint** (it read `Enrolled` 410 at Tuggerah, where the whole waitlist holds 213 rows; what it
counted was never recorded, which is the failure the meta-rule is about). HOW: fresh
`Waitlist/getWaitingList` for all seven services with the four detail options, all 1,699 rows,
cross-tabbing `Status` against `ChildID` / `GuardianID`.

| Status | Rows (group) | Carry a ChildID | Carry a GuardianID |
|---|---|---|---|
| `Enrolled` | 17 | **17 (100%)** | **17 (100%)** |
| `Waitlisted` | 696 | 2 (0.3%) | 92 (13%) |
| `ExpiredWaitlist` | 963 | 2 | 31 |
| `Form Submitted`, `Offered`, both `ExpiryReminder`s, `ExpiredOffer`, `DeletedByParent` | 23 | 0 | 0 |

**So the ChildID is issued AT THE CONVERSION and nowhere else** - which is the same finding as
"a Child record is created when an application converts", now measured from the waitlist side.
`_incl/waitlist-import.php` therefore takes Kidsoft's ids on every row it holds whatever the
status: the read that hands them over is the read that closes the row.

**THE TEST WAS RUN BY A HUMAN WRITING, NOT BY US WRITING** - the guard was never touched. A PAM
added a comment in the Kidsoft UI to an open `Waitlisted` application with no ChildID and no
GuardianID (Ruby Forrester, Kanwal). It was then **not findable anywhere in the API**:

- `getWaitingList` for that `WaitListID`: no comment field, and `UpdatedDateTime` did not move.
  Tried with every documented flag **plus a speculative `Options=Comments`** - identical
  1,473-byte row every time.
- `getChildren` / `getGuardians` at Kanwal: **neither record exists.**
- Children and guardians swept across all seven centres: marker text absent.
- Polled four times over five minutes: identical, so not propagation.
- **Control proving the reads were live:** `UpdatedAfter` returned other Kanwal children and
  guardians modified the same day.

**Only `getChildren`, `getGuardians` and `getRollCalls` return comments** - checked across all
17 controllers. **There is no read path keyed on `WaitingListID`.** So a comment written against
a waiting family would be write-only, invisible until (and unless) they enrol. Confirm with
Kidsoft support before anyone builds on this; **do not weaken the guard on the strength of the
parameter list alone.**

**"Until they enrol" is now a mechanism, not a shrug (19 Aug 2026).** The ids arrive at the
conversion (table above), the import takes them, and `comment_push_backlog()` copies that
family's history across on the next nightly run, oldest note first. Two properties of this
endpoint decide how that is written and both are measured, not assumed: **a comment carries no
date**, so the stamp has to say when the contact happened and insertion order is the only
ordering a reader gets; and **there is no update and no delete**, so a note whose push failed
mid-flight is never retried - it may have landed, and nothing can be read back to check. A note
asked to stay here (internal, or a support plan's) is stamped `local_only` and never joins the
sweep - see [work-layer.md](work-layer.md), "The backlog travels too", for the hole that closed on
19 Oct 2026.

## The client

- **v1 base:** `https://app.kidsoft.com.au/api/v1/Kidsoft/[Controller]/[action]`, auth via
  `ServiceKey` query param alone (no ServiceID needed for auth). **URLs are case-sensitive.**
- **Always go through `kidsoft($controller, $action, $params = [])`; never build raw curl.**
  It merges the ServiceKey, calls, decodes, and throws `RuntimeException` on a non-get
  action, transport failure, non-JSON, or `Status != OK`.
- Credentials in `_incl/kidsoft-config.php` (include-only, nginx-blocked).
- The key is scoped to the Little Miracles group. `Service/getServices` lists services;
  pass a per-service `ServiceID` to scope a call to a centre.
- **Casing is not regular** - controller `Guardian` singular, action `getGuardians` plural.
- `getBookings` date range is capped at ~1 month by the API.

## THE RULE THAT KEEPS BEING RELEARNED

**Before writing that an endpoint lacks something, re-read its parameter list in
`Documentation/getMarkdown` and pass every `Options` / `With*` flag it offers.** Absence of
a field in a default response means nothing.

**Five of this file's load-bearing "Kidsoft cannot do that" claims have been disproved the
same way**, and each time the field was there behind a parameter nobody passed. The sixth row is
a variant: the parameter WAS passed and the field thrown away, because the docs filed offers as
ours:

| Claimed absent | Actually | Cost |
|---|---|---|
| Capacity | `Service/getUtilisation.OperationalPositions`, per room per weekday | A hand-maintained denominator for months |
| The enrolment waitlist | `Waitlist/getWaitingList` + 4 detail options = 684 open applications with names, DOBs, days, mobiles | `waitlist_applications` sat EMPTY |
| Guardian phone numbers | `Options=PhoneNumbers`, on 99% of active guardians | "Phone numbers are absent from the API" |
| `getBookings.UpdatedAfter` | Listed in the docs one line below `ChildIDs` | Two entries here said it did not exist |
| `Children/getChildren.CRN` | On the **plain** response, no option needed | The only cross-centre child identity |
| Offers (held as ours) | `getWaitingList` `OfferedDays` + `CareTypeMap.RoomID` + `ExpiryDate`: 23 of 23 open offers, 15 Oct 2026 | Every offer made twice; 70 promised place-days read free on the board |

**When you record that something is absent, record HOW you established it.** The entries
above named no measurement, because there had never been one. A claim written in project
docs is read as measured fact by everything that comes after it, and nobody re-probes an
endpoint the notes call a dead end.

`UpdatedAfter` is accepted by `getChildren`, `getRooms`, `getServices`, `getPrograms`,
`getBookingTypes`, `getWaitingList`, `getWaitlistedBookings` **and `getBookings`**.

## Caching (`_incl/kidsoft-cache.php`)

Payloads are **gzipped into a LONGBLOB** (~4.9x smaller). The reader sniffs the gzip magic
bytes, so pre-existing plain-JSON rows still decode.

**A CACHE TTL SHORTER THAN THE GAP BETWEEN NIGHTLY WARMS MAKES THE WARM POINTLESS.**
`getBookings`/`getGuardians`/`getChildren` once cached 6 h against a 02:00 warm - so the
cache died at 08:00, which is when the team arrives, and they refilled it by hand all day.
Measured 4 Aug 2026: rows written at 08:00 and 09:00 **by staff sitting there waiting**.

| Endpoint | TTL | Why |
|---|---|---|
| `getBookings` (year, via `bookings_year()`) | 36 h | Twelve monthly reads per centre-year; nothing on a year view is time-critical |
| `getBookings` (the board's ONE reference month, current year) | **15 min** | See below |
| `getBookings` (future year) | 1 h | Re-enrolment moves it by dozens of families a day |
| `getBookings` (past year) | 36 h | Finished |
| `Children/getChildren` plain | 36 h | Names and DOBs; read by drift, occupancy, day-requests, identity |
| `Children/getChildren?Options=Guardian` | **3 h** | Backs the search palette |
| `Guardian/getGuardians` | **3 h** | Backs the search palette |
| `Notification/getNotifications` | 15 min | A round that moves by dozens a day |

**A TTL entry may be keyed on `Options`.** `KIDSOFT_TTL` looks up
`Controller/action?Options=X` before the bare `Controller/action`. The same endpoint answers
different questions at different rates - shortening the bare `getChildren` entry would have
dragged five call sites onto a 3 h refetch cycle for no reason.

**The two "who do I ring" calls are the deliberate exception at 3 h**, with their own
2-hourly warm. Their job is answering a ringing phone, so a changed mobile being unfindable
until tomorrow is a failure of the feature, not a cache detail. The rule still holds at the
smaller scale: 3 h TTL > 2 h warm interval.

### Expiry is not a cliff: stale is served, then refreshed

`kidsoft_cache_run_deferred()`. The unlucky request that finds an expired row gets the
**stale** row; the refresh runs after `fastcgi_finish_request()`. **No page load blocks on
Kidsoft while any copy exists.** Two ordering rules are load-bearing:

- **`session_write_close()` first**, or the user's next click queues behind PHP's session
  lock for the length of the refresh.
- **The refresh must SKIP (not wait) when another worker holds the key**, so one stale
  window occupies one worker rather than one per request.

CLI cannot detach, so it falls back to a synchronous fetch - **cron behaviour is unchanged**.

**A short TTL is affordable ONLY because of the stale-serve.** It costs background
refreshes, not user-visible waits. Do not shorten a TTL without re-checking that property
still holds. Verified for the 15-minute board TTL: `/api/cells` for a full centre-year
returned in **0.43s** against a 20-minute-stale row, with the row refreshed AFTER the
response.

### One fetch per cache key at a time (`GET_LOCK`)

Two staff opening the same centre together both fetched the same year and both wrote the
same rows. The second now waits up to 20s and takes the first one's result (verified: a
caller starting 1.5s late returned at 1.54s instead of making its own 3s call). **On timeout
it fetches anyway** - a duplicate call is bad, a page that never renders is worse. A
`ttl=0` caller (Refresh, cron) shares a fetch under 60s old, so two Refresh clicks cost one
API call.

### Other cache rules

- **A year of bookings must be STREAMED, not accumulated.** PHP-FPM's `memory_limit` is
  128M and holding Tuggerah's 40,005 rows peaked at 84MB. `bookings_year()` fetches a
  month, reduces it, and discards - 20MB peak.
- **Kidsoft's speed is not constant.** 4 Aug 2026: one month of Tuggerah bookings **7.6s
  against ~2.75s** in July (2.8x), while `getGuardians`/`getChildren` were unchanged at
  ~4.0s. Time the specific endpoint before assuming the whole API has degraded - and note
  a slowdown does not create a caching bug, it only makes an existing one visible.
- `kidsoft_cache` is **excluded from the nightly DB dump** (structure kept). See
  [auth-security-ops.md](auth-security-ops.md).

## Endpoint inventory - probed 3 Aug 2026, measured not reasoned about

`Documentation/getMarkdown` (not `getHTML`; it parses cleanly) is the definitive inventory:
**15 controllers, 30 `get*` actions, we call 10.**

### In use

`Service/getServices`, `getRooms`, `getUtilisation`, `getPrograms`, `getBookingTypes`;
`Children/getChildren`; `Guardian/getGuardians`; `Bookings/getBookings`,
`getWaitlistedBookings`; `Waitlist/getWaitingList`; `Notification/getNotifications`.

### Telling a CASUAL day from a permanent one (13 Aug 2026)

**This matters everywhere now** - see the hard rule in [CLAUDE.md](../../CLAUDE.md).

- **A booking row carries `Casual` (bool). That is authoritative; use it.**
- **A WAITLISTED booking row does NOT.** It carries `BookingTypeID` and nothing else that
  says. So the type is the only signal available for a request.
- **`Service/getBookingTypes` names them** and returns `BookingTypeID`, `Name`, `FeeType`,
  `BaseFee`, `StartTime`, `EndTime`. Every casual type at every centre is **`CAS`-prefixed**
  ("CAS 0-2 (Full Day)", "CAS 3-5 PRE"): 28 distinct names across the seven services.
  **`Bookings/getBookingTypes` does not exist** (Internal Error), and
  `Documentation/getMarkdown` returns non-JSON for a single controller - pass no `Controller`
  and read the whole document.
- **THE NAME AND THE FLAG ARE NOT INTERCHANGEABLE, measured over all of 2026: they disagree on
  34 booking types.** Permanent-named types carry a handful of casual rows (Kanwal "0-2 Full
  Day": 3 casual against 3,725 permanent) and "CAS 2-3 (10 Hours)" carries 1 permanent. **The
  name says what a type is FOR, the flag says what a row turned out to be.** On the 65 open
  requests the two methods agree exactly (63 casual), because a request has no flag to
  disagree with.

**Do not read `BaseFee` or `FeeType` off this endpoint.** No dollar figure is ever rendered
anywhere in this app; the type list is fetched for its NAMES only.

### Children leaving: `ExitDate` IS exposed (corrected 13 Aug 2026)

**This list used to say `Children/getChildren&Options=Enrolments` "cannot predict a single
vacancy". That was wrong, and it is the fifth time this file has recorded an absence that
was never there.**

`ExitDate` and `ExitReason` are on the **CHILD record**, not inside the `Enrolments` block
the original probe searched, and they need no `Options` flag at all. Measured across all
seven centres, 4,809 child records, 863 of them active:

| | |
|---|---|
| Children with a **future** `ExitDate` | **11**, all still `Active` |
| Enrolment rows with a future `EndDate` | 11 (the same children) |
| `ExitReason` populated | 2,475 records, 13 distinct values |

Dominant reasons are `Change of Care Conditions` (1,488) and `Gone to School` (834), which
is the difference between a place to refill now and one that frees at year end.

**Why the original probe missed it: it sampled 257 children - Tuggerah alone, which has
exactly ONE future exit today - and generalised from one centre at one moment.** Group-wide
and ten days later the answer is different. **A count of zero on one centre is not a
property of the API.**

Enrolment rows genuinely carry **no `RoomID`**, which is what the old entry leant on. It
does not matter: the child's own bookings say which room and weekday they hold, so a
departure maps onto a cell through `getBookings` exactly as a future starter does.

**Re-measured 22 Oct 2026** for the Dashboard's "What changes next week" (now "What's changing this fortnight"): 54 children with an
`ExitDate` since 1 Aug, all still returned (inactive ones included) and all with an `ExitReason` -
46 "Change of Care Conditions". **No free-text exit note exists anywhere in the API**: the 54 were
re-read with `Options=CustomData,Enrolments,Comments,AdditionalDetails` and nothing exit-shaped
came back beyond the dropdown. See [dashboard.md](dashboard.md).

**IN USE since 13 Aug 2026** via `board_departures()`. It does two jobs: bookings on or after
an `ExitDate` are dropped from `board_forward_occupancy()`, and the declared last day
overrides the one `cells_year()` infers from booking spans.

**What it is worth, measured rather than assumed:** for **10 of the 11** the bookings are
already truncated, so `coverage_map()`'s inference finds them anyway and the declaration adds
only certainty and a reason. **The eleventh is the whole case.** Brodie Jackson leaves 14 Aug
and still holds **49 bookings running to 24 December** - notice in, bookings not cleaned up.
Inference sees a full-year child; the declaration frees three cells four months early.

**It needs a TTL override and the 36 h default hid exactly that case.** See
[placement-board.md](placement-board.md).

### TOURS: genuinely absent, and here is how that was established (14 Aug 2026)

**"Tour" and "orientation" appear ZERO times in `Documentation/getMarkdown`** - the whole
reference, 65,641 bytes, 15 controllers, 30 `get*` actions, grepped case-insensitively. No
endpoint, no field, no parameter. `Notification/getNotificationCategories` returns **31
categories** and none of them is a tour either.

Tours DO exist in Kidsoft, as free prose somebody typed into a waitlist `Notes` or a comment
("Thanks for touring on Friday", "will call back to book in a tour"). **Readable by a person,
countable by nothing** - there is no date, no outcome and no structure.

This one really is absent, like `Waitlist/getWaitingList`'s CRN and unlike the five entries in
the table above. **The measurement is the grep and the category list; re-run both before
believing a later claim either way.** Tours are therefore ours - see
[work-layer.md](work-layer.md), which also records that the spreadsheet was not doing them
either.

### Probed and deliberately NOT adopted - do not re-probe hoping

- **`Service/getOpeningHours`** looks like a better capacity source and is not. Its
  `OperationalPositions` agrees with `getUtilisation` on all 50 shared room-weekdays and
  with our stored 2026 figures on all 55, so there is nothing to gain - and it returns
  **11 Tuggerah rooms where `getUtilisation` returns 10**, which would reinstate the phantom
  near-duplicate room. Nor does it supply closures: `Status=Closed` covers weekends only,
  **zero weekday closures in 2026** even with `TreatPublicHolidaysAsClosed=true`. **Re-probed
  10 Nov 2026** for Terrigal, 18/12/2026 to 12/01/2027, with and without that flag: **25 December
  reads `Open`, 07:00-18:00, licensed capacity 60.** Kidsoft also BOOKS children into the shutdown,
  so the shut days are read off the bookings instead - `bookings_on_trading_days()`, see
  [placement-board.md](placement-board.md). **If a
  real weekday closure ever needs recording, this is the gap** - and the answer is a
  closure flag that is honestly ours, not an editable capacity figure competing with Kidsoft.
  **AND IT IS NOT AN OPENING DATE EITHER (21 Sep 2026).** It is the obvious place to look for
  one - it states `Open` or `Closed` per date, for the service and for every room - so it was
  probed across 2026 and 2027 at all seven centres before `centres.opens_on` was added. The six
  trading centres read open from **1 January** in both years. **Warnervale 2026 reads open from
  15 JUNE 2026**, service and all four rooms, with positions 16-25 - which is the day the record
  was created (its address was last touched 15 June, the service record 22 June) and **seven
  months before the centre trades.** `Service/getServices` holds no date at all: nine fields,
  `UpdatedDateTime` the only one, re-probed with both documented `Options`. So the opening date
  is genuinely ours. See [rooms-centres.md](rooms-centres.md).
- **`Bookings/getBookingIDs`** as change detection: 13.4s / 1.5MB for 40,006 ids over a
  year; 1.23s / 3,347 over a month. Cheaper in bytes, not in the thing that hurts. It is
  the thing that WOULD detect a **removal** (see the incremental-sync note below); nothing
  calls it today.
- **`Service/getPrograms.WaitListAllowSubsetDays`** looks like a rule the offer flow must
  enforce and is not one. It reads `'No'` (a STRING) for every program at every centre.
  Taken at face value a child must take all a program's days - but of the **191 children
  booked in Tuggerah's "2026 Kindergarten" rooms, only 17 attend all five days**; 4 attend
  one. A warning built on this flag would fire on **91% of real placements** and be wrong
  every time, which is how staff learn to ignore warnings. `WaitlistApplicationDate` is
  100% null.
- **`RollCall/getRollCalls`: effectively unused** - 0 rows for a month in August; re-measured
  1-13 Oct 2026, 1 row group-wide (Wamberal). Roll calls are not a source. **Sign-in data DOES
  exist, on the booking** - see "WHO IS IN THE ROOM NOW" below. The line that used to stand here ("no
  sign-in data to verify attendance against - that idea is dead") was the meta-rule failure
  again: it generalised from one empty endpoint and nobody passed `getBookings` its option.
- **`VisitorLog/getVisitorLogs`, `IncidentReport/getIncidentReports`,
  `Discount/getDiscounts`: 0 rows** each.
- **`Balance/*` (5 actions), `CCMS/*`, `CCSS/*`, `Payments/getPayments`:** financial.
  Pulling family money into a children's enrolment board raises the sensitivity of the data
  considerably and answers no question the team asked. **Left alone deliberately, not
  overlooked** - and see the no-dollar-figures rule in the root file.
  **CORRECTED 9 Nov 2026: this used to say only `getServiceDailyBalances` returned anything,
  "nothing per-family". Wrong, and no parameters were recorded for that probe.**
- **BONDS ARE EXPOSED - measured 9 Nov 2026** (asked: "does the API expose the bonds children
  have?"). "Bond" appears in `getMarkdown` only under Balance and Payments; nothing bond-shaped
  on `getChildren` or `getGuardians` under any option.
  - **`Balance/getAgedBalances&AgedBalanceType=3&DayRange=30`** (3 = Bonds; the default 2 is
    debt) - one row per **GUARDIAN** holding a bond: `Guardian, AgedBond, Outstanding, Held,
    LastPaidDate, Active, PhoneNumber, GuardianID`. **No `ChildID`.** 882 rows group-wide (876
    with `Active=1`), 8-18s for the whole group in one call. `SettlementType` changed nothing.
  - **Its `ChildIDs[]` and `GuardianIDs[]` filters return `Internal Error`** with every
    encoding tried (indexed array, literal `[]` key, with and without `ServiceID`), on a child
    whose guardian IS in the unfiltered result. Read a whole service and join.
  - **Joined to children via `getChildren&Options=Guardian`: 1,128 of 1,259 active children
    (90%) have a guardian holding a bond**; 57 have one on two guardians. Erina 98%, Brookvale
    79%, Caringbah 0 of 6.
  - **`Payments/getPayments&BondTransactionType=`** `BondCharge|BondPayment|BondRefund|`
    `BondTransferFeeToBond|BondTransferBondToFee` - per transaction, keyed by `EnrolmentID`.
    2026 to date: TransferFeeToBond 444 rows / 370 enrolments (43.7s; most bonds are taken out
    of fees, not paid directly), Refund 12, Payment 2. Charge and TransferBondToFee **time out
    at 60s over the year** but answer over one month (Oct 2026: 77 and 25 rows, ~2.5s).
  - **`Balance/getBalances`** (one date): service-level `BondHeld` and five other bond totals,
    non-zero at 9 of 17 services. Not per family.
  - **Every one of these is an amount.** Nothing here may reach a screen as a figure; at most
    "a bond is held: yes/no", and that is Broc's call, not a default.
- **`Service/getGroups`** (7 rows), **`Discount/getDiscountCategories`** (10),
  **`Waitlist/getHearAboutUsOptions`** (10 - it decodes `HearAboutUs`, populated on 1% of
  applications, so decoding buys nothing).
- **WHO IS IN THE ROOM NOW - measured 13 Oct 2026, 11:22.** Two reads, and they agree exactly:
  - `Bookings/getAttendanceSummaryForDate` (`BookingDate` only; omit `ServiceID` and the whole
    group comes back in **one 0.3s call**): one row per room **by NAME, not RoomID**, with
    `TotalBookings`, `CurrentSignedInCount` and `ISSCurrentSignedInCount`. **In the room =
    Current + ISS** (IS-case children are counted separately, not inside Current).
    `TotalBookings` **includes absences and casuals.** `TimeStamp` is fixed `+10` and reads an
    hour behind during daylight saving.
  - `Bookings/getBookings&Options=SignInOutTimes`: the same thing per child - sign-in/out
    times and by whom (`Guardian` or `Service`). "Signed in, not signed out" reproduced the
    summary on **all 35 rooms at all seven centres**.
  - **Recording is complete by the end of the day:** over 28 Sep - 12 Oct, 0% of non-absent
    bookings lacked a sign-in at Wamberal, Terrigal, Kanwal and Niagara Park, and nearly all
    the misses elsewhere fell on Mon 5 Oct, the Labour Day public holiday. **During the day it
    can lag:** Point Clare had 32 of 55 unsigned at 11:22 with no absence marked, and 12 of its
    sign-ins carried one identical time (09:36, by Service) - a batch, not arrivals.
  - The count follows the BOOKING's room, so children combined into one room early or late
    are still counted where they are booked.
  - Not adopted: a year board has no use
    for who is in the building right now.

### `Notification/getNotifications` - real, deliberately NOT mirrored

It carries the enrolments function's whole activity stream: group-wide from 1 Jan over the
enrolment-relevant categories, **6,645 rows** (3,464 parent absence marks, 745 portal
bookings, 625 waitlisted bookings, 496 new waitlist entries). Nearly all duplicates what
this app reads DIRECTLY and better - `getWaitingList` and `getWaitlistedBookings` give
structured rows, not sentences.

**And a read-only mirror would be actively harmful**: the team acknowledges these in the
Kidsoft UI (163 of 269 in the default window), and `Notification/acknowledge` is a WRITE
endpoint and out of bounds - so a panel here could only ever show a backlog nobody can
clear from here.

**Its three parameters all default in ways that lie:**
- `Status` defaults to `NotAcknowledged` - a naive read looks like "nothing has been actioned".
- `IssuedFrom` defaults to the start of the week two weeks ago - a naive read looks like
  "there is no history". There is, back past 2024: 22,074 rows, 43s.
- `Categories` must be a real **ARRAY** of the `Value` field (`Categories[]=ReEnrolment`).
  A comma-joined string returns "Categories is not array", and the human-readable `Label`
  matches nothing.

The payload is **FLAT** - each row carries its own `ServiceID` rather than being nested.

Genuinely unavailable elsewhere and still unused: `Parent Message Received` (86) and
`Waitinglist Message Received` (36).

The one thing mirrored from it is **re-enrolment** - see [dashboard.md](dashboard.md).

## Incremental sync is possible and deliberately NOT used

Measured 13 Aug 2026 at Tuggerah: a month of bookings is 4.01s; the same month filtered to
what changed since 02:00 is **0.65s / 213 rows**. The 1-month range cap still applies, so it
does not buy a year in one call.

**It is not used because an incremental read cannot see a CANCELLED booking** - a deleted
row is absent rather than returned as changed, so merging onto a cached month would leave a
child sitting in a room they have left. That is a correction about the API, not a decision
to sync incrementally.

## Fields present on the plain response that were missed for months

`Children/getChildren` plain carries **`CRN`** (96.7% group-wide, **98.3% of ACTIVE
children** - the only cross-centre child identity; see [tenure.md](tenure.md)) and
**`ExitReason`** (13 distinct free-text values). Also present and unused: `ATSI`, `ISCase`,
`MiddleName`, `Nationality`, `CityOfBirth`, `CreatedDateTime`/`UpdatedDateTime`.

**Guardian CRN is only 23.5% of active guardians**, so it is NOT a family key and cannot
improve sibling matching - `_incl/siblings.php` stays on phone/email.

**`Waitlist/getWaitingList` carries no usable CRN** - `ChildDetails.CRN` and
`ParentGuardianOne.CRN` are each populated on **2 of 213** rows, measured with all four
detail options passed. Families do not supply it before enrolment. This one really is
absent, not hidden behind a parameter.

## No "last contacted" date for a waitlist entry (measured 11 Nov 2026)

Broc asked whether Kidsoft could supply one. **It cannot - nowhere in the API records a member
of staff contacting a waiting family.** HOW:

- **Reference:** `Documentation/getMarkdown` re-read (68,413 bytes). `getWaitingList` has 14
  parameters and none adds such a field; the word "contact" appears in the whole reference only
  as `EmergencyContact`.
- **Live:** `getWaitingList` for all eight services with all four `With*` options **plus
  `ShowArchived=true`** - 5,491 rows, 680 distinct field paths. The only dates are `DateAdded`,
  `WaitListedDate`, `ExpiryDate`, `CreatedDateTime`, `UpdatedDateTime`, and the form's own
  `WaitlistCreatedDate` / `PaymentDetail.CreatedDate`. `UpdatedDateTime` is not a contact date:
  Kidsoft's own expiries move it, and a comment added in the UI did not (14 Aug, above).
- **Comments carry no date:** Tuggerah's 4,858 guardian and 2,053 child comments
  (`Options=Comments`) have exactly `CommentID`, `Type`, `Comment`, `Confidential`. And a waiting
  family mostly has no guardian or child record to hold one.
- **Notifications record the family, not us:** `WaitingListCommunication` and
  `ParentWaitlistUpdate` since 2023 are 7,489 rows, every one something the family or Kidsoft did
  (entry added 3,754, form finished 1,543, import summary 1,372, offer expired 348, deleted 121,
  declined 12). The only contact-shaped one is `Waitinglist Message Received` (339): the family
  writing IN, as a sentence naming them, with no `WaitListID`.
- **`Notes` is not a log:** 28 of 691 open `Waitlisted` rows have any, 8 of them with a date in.

**So "last contacted" can only come from our own contact log** (`communications`, see
[work-layer.md](work-layer.md)) - the same source `work_last_touched()` reads for In progress.

## Tags: on guardians AND children, behind `Options=Tags` (measured 7 Oct 2026)

**Never claimed absent, but never read either** - nothing in this app had passed the option, so
the field had simply never been seen. Broc asked whether the "DDR" tag on Heidi Allen was
readable; it is.

HOW: `Documentation/getMarkdown` lists `Tags` among the `Options` of both `Guardian/getGuardians`
and `Children/getChildren`, plus a `Tags` filter parameter on each. Then `getGuardians` with
`Options=Tags` for all seven services, and `getChildren` likewise.

- **Shape:** `Tags: [{TagID, Name, Description}]`. Description repeats Name except
  `Additional Parent Contact`, where it is null. Read `Name`.
- **Every ACTIVE guardian carries at least one tag: 4,262 of 4,262.** Inactive guardians keep
  theirs (`Ceased - Arrangement Ended` is on 1,813).
- **26 guardian tag names**, led by SMS 6,250, Active 4,262, DDR 2,691, Not Indigenous 2,060,
  DDR Without Schedule 1,972, Ceased - Arrangement Ended 1,813, Formal 1,410, Parent Portal 1,393,
  CCR 777. Rare: Shared Care 26, Court Order 6, IS 1.
- **45 child tag names** - consents (Photo / Video Allowed, Sunscreen, Paracetamol, Excursion),
  Medical Condition 407, Allergy 222, Additional Need 204, Custody Order 13, Child Protection
  Agency 1.
- **A tag name has ONE `TagID` across the whole group** - 0 of the 26 guardian names had more
  than one id over seven services. So a TagID can be hard-coded group-wide.
- **The `Tags` filter works:** `Tags[]=<DDR id>` at Point Clare returned 429 guardians, exactly
  the 429 the unfiltered read carries DDR on. It does NOT return the tags themselves - pass
  `Options=Tags` too if you want them on the row.
- **There is no tag-list endpoint.** Tags arrive attached to a record or not at all.
- **Cost is negligible:** Tuggerah's 4,680 guardians in 4.5s, the same as `Options=PhoneNumbers`.
  `Tags,PhoneNumbers` combine in one comma-separated `Options`.

**Several tags are sensitive and are NOT here to be rendered by default.** DDR and DDR Without
Schedule are billing (see the no-dollar-figures rule), others record ATSI status, court and
custody orders, shared care and child protection involvement. Choose any tag a screen shows by
name, on purpose.

**There was no "do not enrol" signal in Kidsoft**, measured the same day: no guardian or child
tag said it, and none of the 13 child `ExitReason` values does either. `Financial Hardship` (6
children) is the family's reason for leaving, not a decision of ours. **So it became a tag by
convention:** any guardian tag beginning "Do Not Enrol" - read by `_incl/do-not-enrol.php`
through `guardians_by_id()`, which now passes `Options=PhoneNumbers,Tags`. See
[waitlist.md](waitlist.md).

**Reading the reference itself:** `Documentation/getMarkdown` with no `Controller` returns the
whole document as plain markdown (67,489 bytes, HTTP 200), not JSON, so `kidsoft()` throws on
it. Read it with a one-off GET from a scratch script; it is documentation, not data.
