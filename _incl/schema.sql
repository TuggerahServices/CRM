-- Little Miracles CRM - schema
--
-- MODEL (derived from the real TUG_Attendances_20xx.xlsx workbooks this app
-- replaces). The team's working artifact is a PLACEMENT BOARD, one per centre
-- per YEAR: a sheet per room, numbered slots down (one per licensed place),
-- weekdays across, a named child in each cell, and the child's status encoded
-- as the cell's fill colour.
--
-- Two things follow from that, and they drove this schema:
--
--   1. CAPACITY IS PER YEAR. Tuggerah runs 194 places/day in 2026 and 170 in
--      2027 - five rooms shrink between the two. A single capacity per room
--      cannot express that, and using the wrong year's figure silently reports
--      rooms as over-capacity. Hence room_year_capacity.
--
--   2. THE SLOT NUMBER IS NOT MEANINGFUL. A child sits in row 3 on Monday and
--      row 11 on Wednesday - the number is just a spreadsheet row, not an
--      identity. So a placement is (child, room, year) + which weekdays, and
--      occupancy is a COUNT of placements on a weekday, not an assignment to a
--      numbered seat.
--
-- Kidsoft stays the READ-ONLY source of truth for actual bookings, children and
-- guardians. It holds no capacity, no requested days, and its waitlist rows are
-- anonymous (0 of 611 Terrigal rows carry a ChildID), so the board is OURS.
--
-- Weekdays are 1=Mon .. 5=Fri throughout. All datetimes are Australia/Sydney
-- wall-clock; see _incl/dates.php.
--
-- Idempotent: safe to re-run.

SET NAMES utf8mb4;

-- ---------------------------------------------------------------------------
-- Reference data: centres and rooms
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS centres (
  id                INT UNSIGNED NOT NULL AUTO_INCREMENT,
  kidsoft_service_id CHAR(36)    NOT NULL,
  name              VARCHAR(120) NOT NULL COMMENT 'Short/friendly name, e.g. "Tuggerah"',
  kidsoft_name      VARCHAR(255) NULL     COMMENT 'Full name as Kidsoft reports it',
  active            TINYINT(1)   NOT NULL DEFAULT 1,
  -- THE FIRST DAY THE CENTRE TRADES. NULL means "has always been open", which is
  -- every centre that was already running when this column arrived (21 Sep 2026)
  -- - no invented dates were backfilled.
  --
  -- OURS, AND NEVER SYNCED. Kidsoft has no opening date: getServices returns
  -- nine fields and not one is a date but UpdatedDateTime (probed with both its
  -- documented Options). Service/getOpeningHours DOES give a per-date Open /
  -- Closed status for the service and every room - and for Warnervale it says
  -- open since 15 June 2026, which is the day the record was set up, seven
  -- months before the doors open. So the API holds a candidate and it is
  -- measurably the wrong answer. See _incl/docs/rooms-centres.md.
  opens_on          DATE         NULL COMMENT 'First day the centre trades. NULL = always been open. OURS, never synced.',
  -- THE MAILBOX WHOSE OUTLOOK CALENDAR EVERY TOUR IS WRITTEN INTO (16 Oct 2026).
  -- OURS: Service/getServices carries no email at all - nine fields, and its two
  -- Options are Addresses and PhoneNumbers. Seeded from Graph, where all eight
  -- centre accounts resolve as <name>@littlemiracles.com.au. NULL = no calendar.
  -- See _incl/tour-calendar.php.
  calendar_mailbox  VARCHAR(190) NULL COMMENT 'Centre mailbox whose Outlook calendar gets each tour. OURS: Kidsoft holds no centre email. NULL = no calendar',
  sort_order        SMALLINT     NOT NULL DEFAULT 0,
  created_at        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_centres_service (kidsoft_service_id),
  KEY ix_centres_active (active, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS rooms (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  centre_id       INT UNSIGNED NOT NULL,
  -- NULL until matched to Kidsoft. Kidsoft's room names contain typos and
  -- near-duplicates (Tuggerah has both "Amazing Asteroid Purple" and "Amazing
  -- Asteroids Purple"; the bookings are all on the singular one), so this
  -- mapping is confirmed by a human in the Rooms screen, never guessed.
  kidsoft_room_id CHAR(36)     NULL,
  name            VARCHAR(120) NOT NULL,
  -- Accepted age range in MONTHS, matching Kidsoft's MinAge/MaxAge units.
  min_age_months  SMALLINT UNSIGNED NULL,
  max_age_months  SMALLINT UNSIGNED NULL,
  active          TINYINT(1)   NOT NULL DEFAULT 1,
  sort_order      SMALLINT     NOT NULL DEFAULT 0,
  notes           VARCHAR(500) NULL,
  created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_rooms_centre_name (centre_id, name),
  UNIQUE KEY uq_rooms_kidsoft (kidsoft_room_id),
  KEY ix_rooms_centre (centre_id, active, sort_order),
  CONSTRAINT fk_rooms_centre FOREIGN KEY (centre_id) REFERENCES centres (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Places available on a given WEEKDAY of a given year. The denominator of every
-- occupancy figure. A room+year+weekday with no row here has UNKNOWN capacity and
-- is shown as such - deliberately louder than defaulting to a stale number.
--
-- PER WEEKDAY, not per year. Kidsoft's Service/getUtilisation reports
-- OperationalPositions for each weekday, and they genuinely differ: Terrigal's
-- Amazing Asteroids runs 14/15/15/15/16 Mon-Fri, Wamberal's Little Angels
-- 7/8/7/8/8. Collapsing that to one number misreports free places on exactly the
-- day someone is trying to fill, which is the app's whole purpose.
--
-- SOURCE OF TRUTH IS KIDSOFT. capacity_sync_year() copies OperationalPositions
-- in; nobody types these. `source` records where a row came from so a deliberate
-- manual override is never silently overwritten by the next sync.
CREATE TABLE IF NOT EXISTS room_year_capacity (
  room_id    INT UNSIGNED NOT NULL,
  year       SMALLINT UNSIGNED NOT NULL,
  weekday    TINYINT UNSIGNED NOT NULL COMMENT '1=Mon .. 5=Fri',
  capacity   SMALLINT UNSIGNED NOT NULL,
  source     ENUM('kidsoft','manual') NOT NULL DEFAULT 'kidsoft',
  synced_at  DATETIME NULL COMMENT 'When this was last read from Kidsoft',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (room_id, year, weekday),
  CONSTRAINT fk_ryc_room FOREIGN KEY (room_id) REFERENCES rooms (id) ON DELETE CASCADE,
  CONSTRAINT ck_ryc_weekday CHECK (weekday BETWEEN 1 AND 5)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Migration for databases created before 27 Jul 2026, when capacity was one
-- number per room per year. The old rows came from the supplied example
-- spreadsheets and are obsolete - Kidsoft supplies the real figures - so this
-- drops rather than fans them out.
ALTER TABLE room_year_capacity
  ADD COLUMN IF NOT EXISTS weekday   TINYINT UNSIGNED NOT NULL DEFAULT 1 AFTER year,
  ADD COLUMN IF NOT EXISTS source    ENUM('kidsoft','manual') NOT NULL DEFAULT 'kidsoft',
  ADD COLUMN IF NOT EXISTS synced_at DATETIME NULL;

-- The PRIMARY KEY must include weekday, or five weekday rows collide on
-- (room_id, year) and each one silently overwrites the last - which is exactly
-- what happened on the first run of this migration. Guarded so it is re-runnable.
SET @pk_has_weekday := (
  SELECT COUNT(*) FROM information_schema.STATISTICS
   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'room_year_capacity'
     AND INDEX_NAME = 'PRIMARY' AND COLUMN_NAME = 'weekday');
SET @sql := IF(@pk_has_weekday = 0,
  'ALTER TABLE room_year_capacity DROP PRIMARY KEY, ADD PRIMARY KEY (room_id, year, weekday)',
  'DO 0');
PREPARE _s FROM @sql; EXECUTE _s; DEALLOCATE PREPARE _s;

ALTER TABLE room_year_capacity
  ADD CONSTRAINT IF NOT EXISTS ck_ryc_weekday CHECK (weekday BETWEEN 1 AND 5);

-- A cap on a SUBSET of a room's daily places, defined by age. e.g. a 2.5-4yr
-- room that may take at most 5 children under 3 on any day:
--     age_under_months = 36, max_children = 5
CREATE TABLE IF NOT EXISTS room_subquotas (
  id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
  room_id          INT UNSIGNED NOT NULL,
  label            VARCHAR(120) NOT NULL COMMENT 'Human label, e.g. "Under 3s"',
  age_under_months SMALLINT UNSIGNED NULL COMMENT 'Counts children aged < this',
  age_over_months  SMALLINT UNSIGNED NULL COMMENT 'Counts children aged >= this',
  max_children     SMALLINT UNSIGNED NOT NULL,
  active           TINYINT(1)   NOT NULL DEFAULT 1,
  created_at       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY ix_subquota_room (room_id, active),
  CONSTRAINT fk_subquota_room FOREIGN KEY (room_id) REFERENCES rooms (id) ON DELETE CASCADE,
  -- A quota with neither bound would match every child and silently shadow the
  -- room capacity, which is never what anyone means.
  CONSTRAINT ck_subquota_bounds CHECK (age_under_months IS NOT NULL OR age_over_months IS NOT NULL)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- The placement board
--
-- One row per child per room per year, plus the weekdays they attend. This is
-- the direct replacement for a room sheet in the workbook.
--
-- The spreadsheet encodes status as a single cell colour, which forces the team
-- to choose between (say) "Confirmed" and "Sibling". They are different kinds of
-- fact, so they are split here: status is the placement's STATE, and the flags
-- are attributes of the child that travel alongside it. That removes a real
-- limitation of the sheet rather than reproducing it.
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS placements (
  id                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
  centre_id           INT UNSIGNED NOT NULL,
  room_id             INT UNSIGNED NOT NULL,
  year                SMALLINT UNSIGNED NOT NULL,

  -- IDENTITY. kidsoft_child_id is the child's identity whenever it is set, and
  -- setting it is the goal: this tool exists to stop the enrolments team typing
  -- things Kidsoft already knows. Measured 27 Jul 2026, EVERY unreconciled row on
  -- the Tuggerah board was an unlinked placement - the typing IS the drift.
  --
  -- The name/DOB columns are therefore a SYSTEM-WRITTEN CACHE of what Kidsoft
  -- holds, refreshed by placements_sync_identity(), never typed by a human while
  -- kidsoft_child_id is set. They are kept rather than dropped for three reasons:
  --   1. 11 children on the 2026 Tuggerah board have surnames that appear nowhere
  --      in Kidsoft's 1,467 records. They are real, pencilled in ahead of Kidsoft,
  --      and a link-only schema would have nowhere to put them.
  --   2. A board is an annual HISTORICAL record. A child who leaves and is
  --      archived in Kidsoft must still have a name on last year's board.
  --   3. The board must render when Kidsoft is unreachable.
  -- A row with no kidsoft_child_id is PROVISIONAL: staff-typed, awaiting a link.
  kidsoft_child_id    CHAR(36)     NULL,
  child_first_name    VARCHAR(80)  NULL COMMENT 'Cache of Kidsoft FirstName when linked; staff-typed only while unlinked',
  child_last_name     VARCHAR(80)  NULL COMMENT 'Cache of Kidsoft LastName when linked; staff-typed only while unlinked',
  child_dob           DATE         NULL COMMENT 'Drives room eligibility and sub-quota tests. From Kidsoft when linked.',
  -- When the cache above was last refreshed from Kidsoft. NULL = never synced,
  -- i.e. these values were typed by a person and are not authoritative.
  identity_synced_at  DATETIME     NULL,

  -- Straight from the workbook's colour legend.
  status ENUM('unset','confirmed','waiting_paperwork','offered','move_rooms',
              'ending_care','swap_days','dropping_day','waitlist','no_position')
              NOT NULL DEFAULT 'unset',

  -- Orthogonal to status; the sheet cannot express these at the same time.
  is_sibling          TINYINT(1) NOT NULL DEFAULT 0,
  is_staff_child      TINYINT(1) NOT NULL DEFAULT 0,
  needs_two_educators TINYINT(1) NOT NULL DEFAULT 0,
  has_behaviour_plan  TINYINT(1) NOT NULL DEFAULT 0,

  notes               VARCHAR(500) NULL,
  source_offer_id     INT UNSIGNED NULL COMMENT 'Set when this came from an accepted offer',
  created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  -- One linked child cannot hold two rows in the same room and year: occupancy
  -- is a raw COUNT of placements, so a duplicate double-counts against capacity
  -- and is invisible to the drift matcher (which keys on child+room). A child
  -- CAN appear in two DIFFERENT rooms in a week, which this still allows, and
  -- MySQL permits many NULLs so unlinked rows are unaffected.
  UNIQUE KEY uq_place_child_room_year (kidsoft_child_id, room_id, year),
  KEY ix_place_board (centre_id, year, room_id),
  KEY ix_place_room (room_id, year, status),
  KEY ix_place_child (kidsoft_child_id),
  KEY ix_place_name (child_last_name, child_first_name),
  CONSTRAINT fk_place_centre FOREIGN KEY (centre_id) REFERENCES centres (id),
  CONSTRAINT fk_place_room   FOREIGN KEY (room_id)   REFERENCES rooms (id),
  -- A placement must be SOMEBODY: either a Kidsoft child, or a typed name while
  -- that child is still being pencilled in ahead of Kidsoft. Never neither.
  CONSTRAINT ck_place_identity CHECK (
    kidsoft_child_id IS NOT NULL
    OR (child_first_name IS NOT NULL AND child_last_name IS NOT NULL)
  )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Migration for databases created before 27 Jul 2026 (MariaDB IF [NOT] EXISTS
-- extensions keep this re-runnable alongside the CREATE TABLE above).
ALTER TABLE placements
  MODIFY COLUMN IF EXISTS child_first_name VARCHAR(80) NULL
    COMMENT 'Cache of Kidsoft FirstName when linked; staff-typed only while unlinked',
  MODIFY COLUMN IF EXISTS child_last_name  VARCHAR(80) NULL
    COMMENT 'Cache of Kidsoft LastName when linked; staff-typed only while unlinked',
  ADD COLUMN IF NOT EXISTS identity_synced_at DATETIME NULL AFTER child_dob;

ALTER TABLE placements ADD CONSTRAINT IF NOT EXISTS ck_place_identity CHECK (
  kidsoft_child_id IS NOT NULL
  OR (child_first_name IS NOT NULL AND child_last_name IS NOT NULL)
);

CREATE TABLE IF NOT EXISTS placement_days (
  placement_id INT UNSIGNED NOT NULL,
  weekday      TINYINT UNSIGNED NOT NULL COMMENT '1=Mon .. 5=Fri',
  PRIMARY KEY (placement_id, weekday),
  KEY ix_pdays_weekday (weekday),
  CONSTRAINT fk_pdays_place FOREIGN KEY (placement_id) REFERENCES placements (id) ON DELETE CASCADE,
  CONSTRAINT ck_pdays_weekday CHECK (weekday BETWEEN 1 AND 5)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Waitlist
--
-- THE FAMILY DATA IN HERE IS FED FROM KIDSOFT, NOT TYPED. This comment used to
-- read "the Kidsoft waitlist API is anonymous (no name fields at all; ChildID
-- present on 0.3% of open rows), and requested weekdays exist nowhere in the
-- API", and that was measured without passing the endpoint's four detail
-- options. Waitlist/getWaitingList with WithDetails + WithRequestedDays +
-- WithOfferedDays + WithCareTypeMap hands over the whole queue: 698 open
-- applications across the group, child name and DOB at 100%, requested weekdays
-- at 100%, parent mobile at 99%. See _incl/waitlist-import.php.
--
-- So this table is the system of record for OUR DECISIONS - offers, the enquiry
-- funnel, comms, audit, the priority ladder - and a cache of Kidsoft's family
-- data. Which columns belong to which is written down in waitlist-import.php and
-- enforced there; `source` and `kidsoft_synced_at` mark the rows Kidsoft owns, so
-- a refresh can skip a hand-keyed row instead of overwriting it.
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS waitlist_applications (
  id                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
  centre_id           INT UNSIGNED NOT NULL,
  kidsoft_waitlist_id VARCHAR(64)  NULL COMMENT 'WaitListID, where a row could be matched',
  kidsoft_child_id    CHAR(36)     NULL,
  kidsoft_guardian_id CHAR(36)     NULL,

  child_first_name    VARCHAR(80)  NOT NULL,
  child_last_name     VARCHAR(80)  NOT NULL,
  child_dob           DATE         NULL,
  guardian_name       VARCHAR(160) NULL,
  guardian_email      VARCHAR(190) NULL,
  guardian_phone      VARCHAR(40)  NULL,
  lead_source         VARCHAR(120) NULL COMMENT 'From the Enquiries-Tours sheet',

  date_added          DATE         NOT NULL COMMENT 'First-come-first-served ordering key',
  required_from       DATE         NULL,
  -- Kidsoft's ExpiryDate. An application that lapses is a family lost AND a place
  -- left unfilled, and Kidsoft only nudges the parent - nothing told the team.
  -- Measured 3 Aug 2026: 90 open applications group-wide expire within 30 days.
  kidsoft_expires_at  DATE         NULL COMMENT 'Application lapses on this day unless the family reconfirms',
  for_year            SMALLINT UNSIGNED NULL COMMENT 'Board year they are waiting for',
  preferred_room_id   INT UNSIGNED NULL,
  -- WHERE KIDSOFT OFFERED THEM A PLACE, read from CareTypeMap.RoomID on a row
  -- whose Status is an offer (15 Oct 2026). Kidsoft-owned and refreshed on every
  -- sync; with waitlist_offered_days it is what board_load() holds as promised.
  -- Offers are sent in Kidsoft, not here - see KIDSOFT_OPEN_OFFER in board.php.
  kidsoft_offer_room_id INT UNSIGNED NULL COMMENT 'Room Kidsoft offered a place in (CareTypeMap.RoomID on an offer)',

  -- Priority ladder: re-enrolment > staff child > Priority of Access > sibling,
  -- then date_added.
  is_reenrolment      TINYINT(1)   NOT NULL DEFAULT 0,
  is_staff_child      TINYINT(1)   NOT NULL DEFAULT 0,
  is_sibling          TINYINT(1)   NOT NULL DEFAULT 0,
  priority_of_access  TINYINT UNSIGNED NOT NULL DEFAULT 3 COMMENT '1 at-risk, 2 work/study, 3 other',

  -- Enquiry funnel, mirroring the Enquiries-Tours columns.
  tour_booked_at      DATE         NULL,
  guide_provided      TINYINT(1)   NOT NULL DEFAULT 0,
  orientation_at      DATE         NULL,

  -- `archived` is NOT a Kidsoft status: archiving an application REMOVES it from
  -- getWaitingList entirely, so it is inferred from absence. See the note above
  -- the reconciliation block in waitlist-import.php. It is hidden everywhere,
  -- including under "All", which is what separates it from `expired`.
  status              ENUM('waitlisted','offered','partially_placed','placed','withdrawn','expired','archived')
                        NOT NULL DEFAULT 'waitlisted',
  notes               TEXT         NULL,

  -- Provenance, the same pattern as placements.identity_synced_at and
  -- room_year_capacity.source. Without it a refresh silently destroys considered
  -- edits, which is worse than not caching Kidsoft's data at all.
  --   'kidsoft' - imported; the family columns are refreshed on every sync.
  --   'manual'  - hand-keyed (a family who rang before they applied online, or
  --               a centre Kidsoft has no waitlist for). NEVER touched by a
  --               sync: it carries no kidsoft_waitlist_id, so it cannot match.
  source              ENUM('kidsoft','manual') NOT NULL DEFAULT 'manual',
  kidsoft_synced_at   DATETIME     NULL COMMENT 'Last time the family data was re-read from Kidsoft',
  created_at          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_waitlist_kidsoft (kidsoft_waitlist_id),
  KEY ix_waitlist_queue (centre_id, status, date_added),
  KEY ix_waitlist_expiry (centre_id, status, kidsoft_expires_at),
  KEY ix_waitlist_name (child_last_name, child_first_name),
  CONSTRAINT fk_waitlist_centre FOREIGN KEY (centre_id) REFERENCES centres (id),
  CONSTRAINT fk_waitlist_room   FOREIGN KEY (preferred_room_id) REFERENCES rooms (id) ON DELETE SET NULL,
  CONSTRAINT fk_waitlist_offer_room FOREIGN KEY (kidsoft_offer_room_id) REFERENCES rooms (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- The weekdays a family actually wants. Under 10% of children attend all five,
-- which is exactly why filling the board is a packing problem.
CREATE TABLE IF NOT EXISTS waitlist_days (
  waitlist_id INT UNSIGNED NOT NULL,
  weekday     TINYINT UNSIGNED NOT NULL COMMENT '1=Mon .. 5=Fri',
  PRIMARY KEY (waitlist_id, weekday),
  CONSTRAINT fk_wldays_waitlist FOREIGN KEY (waitlist_id) REFERENCES waitlist_applications (id) ON DELETE CASCADE,
  CONSTRAINT ck_wldays_weekday CHECK (weekday BETWEEN 1 AND 5)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- The weekdays KIDSOFT OFFERED, which are not always the days asked for: 3 of the
-- 8 open `Offered` rows on 15 Oct 2026 differed (Stella Knox asked Tue/Fri and was
-- offered Tue/Wed). Kidsoft-owned, replaced wholesale on every sync, never typed.
CREATE TABLE IF NOT EXISTS waitlist_offered_days (
  waitlist_id INT UNSIGNED NOT NULL,
  weekday     TINYINT UNSIGNED NOT NULL COMMENT '1=Mon .. 5=Fri',
  PRIMARY KEY (waitlist_id, weekday),
  CONSTRAINT fk_wloffdays_waitlist FOREIGN KEY (waitlist_id) REFERENCES waitlist_applications (id) ON DELETE CASCADE,
  CONSTRAINT ck_wloffdays_weekday CHECK (weekday BETWEEN 1 AND 5)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Offers
--
-- An offer may cover FEWER days than were requested. A family can accept the
-- partial offer and stay waitlisted for the rest, or reject the lot - so
-- acceptance is tracked per DAY, not just per offer.
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS offers (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  waitlist_id   INT UNSIGNED NOT NULL,
  centre_id     INT UNSIGNED NOT NULL,
  room_id       INT UNSIGNED NOT NULL,
  year          SMALLINT UNSIGNED NOT NULL,
  start_date    DATE         NULL COMMENT 'Date care would commence, if known',
  offered_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  -- Default is offered_at + 2 weeks, adjustable case-by-case, hence stored.
  expires_at    DATETIME     NOT NULL,
  status        ENUM('pending','accepted','partially_accepted','declined','expired','withdrawn')
                  NOT NULL DEFAULT 'pending',
  responded_at  DATETIME     NULL,
  response_note VARCHAR(500) NULL,
  offered_by    VARCHAR(190) NULL COMMENT 'Entra email of the staff member who made the offer',
  created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY ix_offers_waitlist (waitlist_id),
  KEY ix_offers_open (status, expires_at) COMMENT 'Drives the expiry sweep and open-offers view',
  KEY ix_offers_room (room_id, year),
  CONSTRAINT fk_offers_waitlist FOREIGN KEY (waitlist_id) REFERENCES waitlist_applications (id) ON DELETE CASCADE,
  CONSTRAINT fk_offers_centre   FOREIGN KEY (centre_id)   REFERENCES centres (id),
  CONSTRAINT fk_offers_room     FOREIGN KEY (room_id)     REFERENCES rooms (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS offer_days (
  offer_id INT UNSIGNED NOT NULL,
  weekday  TINYINT UNSIGNED NOT NULL COMMENT '1=Mon .. 5=Fri',
  -- NULL while pending; set per day on response, so a partial acceptance records
  -- exactly which days were taken.
  accepted TINYINT(1) NULL,
  PRIMARY KEY (offer_id, weekday),
  CONSTRAINT fk_offerdays_offer FOREIGN KEY (offer_id) REFERENCES offers (id) ON DELETE CASCADE,
  CONSTRAINT ck_offerdays_weekday CHECK (weekday BETWEEN 1 AND 5)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Communications log
--
-- "Any staffer can see prior parent contact." Attached to the FAMILY, not to
-- whichever offer prompted the call.
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS communications (
  id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
  centre_id        INT UNSIGNED NULL,
  kidsoft_child_id CHAR(36)     NULL,
  waitlist_id      INT UNSIGNED NULL,
  -- THE THIRD KEY (14 Aug 2026). A tour enquiry has neither a waitlist
  -- application nor a Kidsoft child, so work_contact_history() - which looks up
  -- by WHO, not by item - could not find a single call made to them, and
  -- work_log_contact() had nothing to file one under. This is the one case where
  -- the item IS the family, because nothing else identifies them yet.
  work_item_id     INT UNSIGNED NULL,
  placement_id     INT UNSIGNED NULL,
  offer_id         INT UNSIGNED NULL,
  occurred_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  direction        ENUM('outbound','inbound','internal') NOT NULL DEFAULT 'outbound',
  channel          ENUM('phone','email','sms','in_person','other') NOT NULL DEFAULT 'phone',
  summary          VARCHAR(255) NOT NULL,
  body             TEXT         NULL,
  staff_email      VARCHAR(190) NOT NULL,
  -- ASKED TO STAY HERE (19 Oct 2026): an internal note, or a support plan note
  -- (Broc, 17 Sep: "for now"). Its own column, not an error: the nightly
  -- comment_push_backlog() reads "no pushed_at, no error" as "had nowhere to go
  -- yet", and without this it would have copied these to Kidsoft for good.
  local_only       TINYINT(1)   NOT NULL DEFAULT 0,

  -- ---------------------------------------------------------------------
  -- THE ONE-WAY KIDSOFT COPY (14 Aug 2026). See _incl/comments.php.
  --
  -- The team's phone, tour and offer history used to be double-keyed into
  -- Kidsoft Enrol Notes. `Comment/createComment` is the ONLY write this project
  -- makes anywhere, and it is a COPY, not a sync: no read endpoint is keyed on
  -- WaitingListID, so a comment written against a waiting family cannot be read
  -- back by anything (proved by having a PAM add one in the Kidsoft UI and
  -- failing to find it). THIS TABLE IS THE RECORD; Kidsoft gets the copy where
  -- it has somewhere to put it, which is 13% of open applications.
  --
  -- A FAILED PUSH IS NOT A FAILED SAVE. The local row commits first and the push
  -- runs after, so these five columns exist to say what happened to the copy --
  -- and to let the modal show three distinct states: copied, not copied, and
  -- nowhere to copy to. Losing a staff member's typing because a third party was
  -- down is the worse bug.
  --
  -- kidsoft_target/target_id record WHICH record it landed on, because only IDs
  -- Kidsoft itself supplies are ever used: matching a family to a guardian by
  -- phone or email would lift reach to 24% and was REFUSED, since createComment
  -- has no update and no delete and a wrong match writes one family's
  -- confidential note permanently onto another family's record.
  -- ---------------------------------------------------------------------
  kidsoft_comment_id CHAR(36)   NULL COMMENT 'CommentID returned by createComment',
  kidsoft_target     ENUM('child','guardian') NULL COMMENT 'Which kind of record it went on',
  kidsoft_target_id  CHAR(36)   NULL COMMENT 'The ChildID or GuardianID, as supplied BY Kidsoft',
  kidsoft_pushed_at  DATETIME   NULL COMMENT 'NULL means never copied',
  kidsoft_push_error VARCHAR(255) NULL COMMENT 'Why the copy did not travel; the local row is still saved',

  created_at       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  -- THE LAST EDIT (12 Nov 2026). Only a local_only note can be edited (a
  -- Kidsoft comment cannot be), by anybody who holds its centre, and the entry
  -- says so on screen. The words it replaced are in the audit row (comm.update).
  edited_at        DATETIME     NULL,
  edited_by        VARCHAR(190) NULL,
  PRIMARY KEY (id),
  KEY ix_comms_child (kidsoft_child_id, occurred_at),
  KEY ix_comms_waitlist (waitlist_id, occurred_at),
  KEY ix_comms_workitem (work_item_id, occurred_at),
  KEY ix_comms_placement (placement_id, occurred_at),
  CONSTRAINT fk_comms_workitem FOREIGN KEY (work_item_id) REFERENCES work_items (id) ON DELETE SET NULL,
  CONSTRAINT fk_comms_centre   FOREIGN KEY (centre_id)    REFERENCES centres (id) ON DELETE SET NULL,
  CONSTRAINT fk_comms_waitlist FOREIGN KEY (waitlist_id)  REFERENCES waitlist_applications (id) ON DELETE SET NULL,
  CONSTRAINT fk_comms_place    FOREIGN KEY (placement_id) REFERENCES placements (id) ON DELETE SET NULL,
  CONSTRAINT fk_comms_offer    FOREIGN KEY (offer_id)     REFERENCES offers (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- ONE NOTE, SHOWN ON A BROTHER OR SISTER TOO (19 Oct 2026). Broc: "a logged call
-- shows on both children here, but goes to Kidsoft only on the child it was
-- written for". A LINK, never a copy per child: the note stays one row, on the
-- child it was written for, and its Kidsoft copy hangs off that row's own
-- waitlist_id - comment_push() and the nightly backlog never read this table. A
-- sibling is found by a shared parent phone or email, which is exactly the
-- evidence the createComment rule refuses to write on.
-- Written by api/comms `add`, checked against work_siblings_here(); read by
-- work_contact_history() and api/comms `list` (work_contacts_shared()).
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS communication_siblings (
  communication_id INT UNSIGNED NOT NULL,
  waitlist_id      INT UNSIGNED NOT NULL,
  created_at       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (communication_id, waitlist_id),
  KEY ix_commsib_waitlist (waitlist_id),
  CONSTRAINT fk_commsib_comm     FOREIGN KEY (communication_id) REFERENCES communications (id) ON DELETE CASCADE,
  CONSTRAINT fk_commsib_waitlist FOREIGN KEY (waitlist_id)      REFERENCES waitlist_applications (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Audit trail - this app holds children's personal data and decides who gets a
-- place, so every mutation is recorded against the acting Entra identity.
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS audit_log (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  staff_email VARCHAR(190) NOT NULL,
  action      VARCHAR(60)  NOT NULL COMMENT 'e.g. room.update, placement.create',
  entity_type VARCHAR(40)  NOT NULL,
  entity_id   VARCHAR(64)  NULL,
  detail      JSON         NULL,
  ip          VARCHAR(45)  NULL,
  created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY ix_audit_entity (entity_type, entity_id, created_at),
  KEY ix_audit_staff (staff_email, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Kidsoft response cache. Rows are disposable: delete freely to force a refresh.
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS kidsoft_cache (
  cache_key  VARCHAR(190) NOT NULL COMMENT 'controller:action:sorted-params hash',
  -- GZIPPED JSON (see _incl/kidsoft-cache.php). A year of bookings for one
  -- centre is 20.6MB raw; compressed it is roughly a tenth of that. BLOB, not
  -- TEXT, because compressed bytes are not text. Legacy plain-JSON rows are
  -- still readable - the reader sniffs the gzip magic bytes.
  payload    LONGBLOB     NOT NULL,
  fetched_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (cache_key),
  KEY ix_cache_fetched (fetched_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- WORK ITEMS - the part Kidsoft does not do, and the reason this app exists.
--
-- Kidsoft is the window: it says who actually holds a place. It says almost
-- nothing about the WORK of filling an empty one. Measured 6 Aug 2026 across 339
-- families, the gap between a family joining the waiting list and their
-- enrolment form being finalised runs a MEDIAN OF 21 DAYS, 43% take longer than
-- 30 and 24% longer than 90 - and for that entire stretch Kidsoft says one word
-- about them: "Waitlisted". Every call, tour, offer and follow-up in between was
-- recorded in a spreadsheet, which is why the spreadsheets survived this app.
--
-- A work item is one candidate family being worked toward one spot, moving
-- through stages the way a ticket does. The organisation's only revenue comes
-- from a real child in a real room, so the item's definition of done is not a
-- staff member ticking a box - it is KIDSOFT SHOWING THE CHILD IN THE ROOM.
-- work_autoclose() closes items against the derived board; reality closes them.
--
-- Scope: (centre, year, room, weekdays). room_id is nullable because a family is
-- often worked before anyone has settled which room - age bands overlap badly,
-- a 30-month-old fits seven Tuggerah rooms.
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS work_items (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  centre_id     INT UNSIGNED NOT NULL,
  room_id       INT UNSIGNED NULL COMMENT 'The spot. NULL while the room is still open',
  -- NULL from 14 Aug 2026: a parent ringing to book a tour has not named a year,
  -- and demanding one would make the screen ask for something nobody knows yet.
  -- Every reader already treats a null year as "every year" - work_list() and
  -- work_pipeline() both take null to mean exactly that - so this widens what a
  -- ROW may say to match what a QUERY could already ask.
  year          SMALLINT UNSIGNED NULL COMMENT 'Board year aimed at. NULL on an enquiry',

  -- WHO. Exactly one of these identifies the family, and which one it is says
  -- what kind of work this is:
  --   waitlist_id      - a NEW family from the queue
  --   kidsoft_child_id - a child ALREADY in the room, being sold an extra day.
  --                       Measured 3 Aug 2026: 660 (child x free day) pairs group
  --                       wide against 37 open day requests, so this is roughly
  --                       18x the demand the app could otherwise see, and by far
  --                       the easiest to sell - already enrolled, already settled.
  waitlist_id       INT UNSIGNED NULL,
  kidsoft_child_id  CHAR(36)     NULL,
  -- Denormalised so a closed item still names the family after the application
  -- has been archived or the child left. History must not depend on a live join.
  child_name        VARCHAR(160) NOT NULL,

  -- THE THIRD KIND OF CANDIDATE: a TOUR ENQUIRY (added 14 Aug 2026).
  --
  -- A parent who rings to book a tour has no waitlist application and no Kidsoft
  -- child record - Kidsoft creates one only when an application converts to an
  -- enrolment - so neither of the two ids above can name them, and there is
  -- nothing else holding the parent's phone number. Hence these three.
  --
  -- WHY NOT A waitlist_applications ROW, which already supports source='manual'.
  -- waitlist_for_centre() assigns queue position by counting down every
  -- application in the centre, so filing an enquirer there would hand them a
  -- place in line and renumber everyone behind them - and that number is the
  -- one thing on that screen a parent might be told over the phone. A tour is
  -- not a place in the queue. Broc, 14 Aug 2026: tours are managed on the
  -- spreadsheet today and should move in here.
  contact_name      VARCHAR(160) NULL COMMENT 'The parent. Only an enquiry has one',
  contact_phone     VARCHAR(40)  NULL,
  contact_email     VARCHAR(190) NULL,

  -- WHERE IT IS UP TO. Ordered: the pipeline runs top to bottom, and `stage_rank`
  -- in _incl/work.php is the one place that ordering is written down.
  -- `attempted_contact` added 22 Oct 2026 (the enrolments meeting): a voicemail
  -- or an unanswered text is somewhere between "nobody has rung them" and "we
  -- have spoken to them", and it was being filed as one or the other.
  stage ENUM('identified','attempted_contact','contacted','tour_booked','tour_done',
             'offered','accepted','enrolled','lost','archived') NOT NULL DEFAULT 'identified',
  -- 'archived' added 6 Nov 2026: closed without an outcome (a duplicate or
  -- leftover application). See work_archive() in _incl/work.php.

  -- THE TOUR ITSELF. A stage change is timestamped in work_item_events, but that
  -- records WHEN SOMEBODY CLICKED, not when the visit is - so "tours this week"
  -- cannot be derived from the event log and needs its own datetime.
  tour_at       DATETIME NULL COMMENT 'When the tour is booked for',
  -- NULL until it has happened. DELIBERATELY NOT A STAGE: WORK_STAGES is
  -- rank-ordered and read by work_stage_rank(), and a no-show is not further
  -- along the pipeline than a booked tour - it is the same place with a wasted
  -- appointment. A no-show sends the item back to `contacted`, the same treatment
  -- a declined or expired offer gets, for the same reason: losing a family is a
  -- decision somebody makes, not something a missed appointment does.
  tour_outcome  ENUM('toured','no_show') NULL,
  -- THE TOUR'S COPY IN THE CENTRE'S OUTLOOK CALENDAR (16 Oct 2026). The tour
  -- above is the record; this is a one-way copy that _incl/tour-calendar.php
  -- keeps in step, and a failed write never fails the save that caused it.
  calendar_mailbox   VARCHAR(190) NULL COMMENT 'Mailbox the tour event lives in, so a move or delete reaches the right one',
  calendar_event_id  VARCHAR(255) NULL COMMENT 'Graph event id of the tour in that mailbox',
  calendar_hash      CHAR(40)     NULL COMMENT 'sha1 of what was last written; a difference means it needs writing again',
  calendar_txn       VARCHAR(64)  NULL COMMENT 'Graph transactionId of a create whose outcome is not yet known',
  calendar_synced_at DATETIME     NULL,
  calendar_error     VARCHAR(255) NULL COMMENT 'Why the last write failed. NULL once it succeeds',
  -- THE PARENT'S INVITE (29 Oct 2026). Ticked, the entry above BECOMES a branded
  -- meeting request with the parent as its one attendee - one entry, Broc's
  -- choice. Set on every item on the visit at once. See tour-calendar.php.
  tour_invite        TINYINT(1)   NOT NULL DEFAULT 0 COMMENT 'Send the tour invite to the parent',
  calendar_invited   VARCHAR(190) NULL COMMENT 'Address the entry has invited, once Exchange took it. NULL = nobody',
  -- Why it ended. Only meaningful on 'lost'; free text is deliberate, because a
  -- fixed reason list invented before anyone has used the screen would be wrong.
  outcome_note  VARCHAR(500) NULL,

  -- The live offer, when there is one. NOT a copy of it: an offer HOLDS days in
  -- room_day_vacancies() and that must stay the single implementation, or two
  -- staff can promise one seat twice.
  offer_id      INT UNSIGNED NULL,

  owner_email   VARCHAR(190) NULL COMMENT 'Entra email of whoever is working it',
  -- What the team actually needs on a Monday morning: what is due, and what has
  -- gone quiet. Nothing else in this app has ever answered either question.
  next_action_at DATE        NULL,
  next_action    VARCHAR(255) NULL,
  -- WHO the next step is for (22 Oct 2026): "me" or a colleague's email. The
  -- reminder is IN THE APP - that person's Mine view shows the item once the
  -- date arrives - and nothing is emailed (Broc's call).
  next_action_for VARCHAR(190) NULL COMMENT 'Who the next step is for: shown in their Mine view on its date',

  created_by    VARCHAR(190) NULL,
  created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  closed_at     DATETIME     NULL,

  PRIMARY KEY (id),
  KEY ix_work_open   (centre_id, year, stage, next_action_at),
  KEY ix_work_room   (room_id, year, stage),
  KEY ix_work_wl     (waitlist_id),
  KEY ix_work_child  (kidsoft_child_id, year),
  -- "Tours this week" scans by date across the whole group, and an unresolved
  -- tour is found by (tour_at in the past, tour_outcome IS NULL).
  KEY ix_work_tour   (tour_at, tour_outcome),
  CONSTRAINT fk_work_centre   FOREIGN KEY (centre_id)  REFERENCES centres (id),
  CONSTRAINT fk_work_room     FOREIGN KEY (room_id)    REFERENCES rooms (id) ON DELETE SET NULL,
  CONSTRAINT fk_work_waitlist FOREIGN KEY (waitlist_id) REFERENCES waitlist_applications (id) ON DELETE SET NULL,
  CONSTRAINT fk_work_offer    FOREIGN KEY (offer_id)   REFERENCES offers (id) ON DELETE SET NULL
  -- "A work item must name somebody" is enforced in work_create(), NOT by a
  -- CHECK. MariaDB refuses a CHECK on a column that is also an ON DELETE SET
  -- NULL foreign key - and it is right to, because the two genuinely conflict:
  -- deleting a waitlist application would null the only identifier and violate
  -- the constraint from the outside. That is also why child_name is
  -- denormalised: an item outlives the application it came from, and the history
  -- must still name the family.
  --
  -- THERE ARE NOW THREE WAYS TO NAME SOMEBODY, not two: a waitlist_id, a
  -- kidsoft_child_id, or - for a tour enquiry - a child_name plus at least one
  -- way of ringing them back. The third has no id behind it, so work_create()'s
  -- de-dupe cannot match it and two staff taking the same call will raise two
  -- items. That is accepted: a name is all an enquiry has, and guessing a merge
  -- on one would join two different families.
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- The weekdays being worked toward. Under 10% of children attend all five, so a
-- spot is a DAY, not a place - the same reason the board's unit is room x weekday.
CREATE TABLE IF NOT EXISTS work_item_days (
  work_item_id INT UNSIGNED NOT NULL,
  weekday      TINYINT UNSIGNED NOT NULL COMMENT '1=Mon .. 5=Fri',
  PRIMARY KEY (work_item_id, weekday),
  CONSTRAINT fk_workdays_item FOREIGN KEY (work_item_id) REFERENCES work_items (id) ON DELETE CASCADE,
  CONSTRAINT ck_workdays_weekday CHECK (weekday BETWEEN 1 AND 5)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Every stage change, kept separately from audit_log because this one is read by
-- the team (how long did this family sit at "tour booked"?) rather than by an
-- administrator chasing who changed what.
CREATE TABLE IF NOT EXISTS work_item_events (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  work_item_id INT UNSIGNED NOT NULL,
  from_stage   VARCHAR(20)  NULL,
  to_stage     VARCHAR(20)  NOT NULL,
  note         VARCHAR(500) NULL,
  -- 'staff' or 'kidsoft'. A stage the MIRROR closed is not a stage a person
  -- claimed, and conflating them would let the pipeline mark its own homework.
  actor        VARCHAR(190) NOT NULL DEFAULT 'staff',
  occurred_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY ix_workev_item (work_item_id, occurred_at),
  CONSTRAINT fk_workev_item FOREIGN KEY (work_item_id) REFERENCES work_items (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- The internal day waitlist
--
-- "Someone is in Shining Stars Mon and Tue and wants a Wednesday when one comes
-- free. There isn't an option for this on Kidsoft, that is for the permanent
-- positions." - Broc, 10 Aug 2026, from UAT.
--
-- He is right, and this is the one demand signal the mirror genuinely cannot
-- carry. Kidsoft has two waitlists and NEITHER expresses a standing request for
-- a weekday from a child already in the room:
--
--   Waitlist/getWaitingList        - the NEW-ENROLMENT queue. A family with no
--                                     place yet, applying for a permanent
--                                     position. Imported into
--                                     waitlist_applications.
--   Bookings/getWaitlistedBookings - DATE-SPECIFIC requests a parent raised
--                                     through the portal ("Wed 3 Sep"). Read
--                                     live by day_requests_year(). A parent who
--                                     rings the centre and says "any Wednesday,
--                                     whenever one opens" has raised nothing at
--                                     all, so there is no row anywhere.
--
-- So this table is OURS by the same test as the rest of the work layer: Kidsoft
-- has no field for it. It sits in the DEMAND layer, not the work layer - a wish
-- is dormant until the day it names comes free, and only THEN is there anything
-- to do about it. It merges into the cell's "asked for this day" block beside
-- the Kidsoft-sourced requests, counts toward `grantable`, and the team starts a
-- work_item from it when the place appears.
--
-- CLOSED BY KIDSOFT, NOT BY A CHECKBOX - the rule work_autoclose() is built on.
-- When the derived board shows the child booked in that room on that weekday,
-- the wish for that weekday is granted and stamped. DAY-LEVEL, because a family
-- who got the Wednesday and also wanted the Thursday is still waiting.
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS internal_day_requests (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  centre_id     INT UNSIGNED NOT NULL,
  room_id       INT UNSIGNED NOT NULL,
  year          SMALLINT UNSIGNED NOT NULL,

  -- WHO, on the same terms as the board itself: a Kidsoft child is its ChildID,
  -- and a provisional child (pencilled in ahead of Kidsoft, so there is no id to
  -- hold) is our placements row. Exactly one is set; enforced in
  -- internal_request_save(), not by a CHECK, for the reason work_items records --
  -- placement_id is ON DELETE CASCADE, and the two would conflict.
  kidsoft_child_id CHAR(36)     NULL,
  placement_id     INT UNSIGNED NULL,
  -- Denormalised so a granted or withdrawn request still names the family after
  -- the child leaves Kidsoft. History must not depend on a live join.
  child_name       VARCHAR(160) NOT NULL,

  -- THE POINT OF THE FEATURE. What the parent actually said, in their words --
  -- "keen from term 4, would take Thursday if Wednesday is not possible". This
  -- is what the team asked to be able to record and display, and nothing in
  -- Kidsoft or in this app had anywhere to put it.
  note          VARCHAR(500) NULL,

  -- WHEN THEY ASKED, not when it was typed in. A wish taken on the phone on
  -- Monday and entered on Thursday is a Monday wish, and this column is the
  -- queue order when two families want one place.
  requested_on  DATE NOT NULL,

  status ENUM('open','granted','withdrawn') NOT NULL DEFAULT 'open',
  -- Why it ended, in a person's words. Free text deliberately: a fixed reason
  -- list invented before anyone has used the screen would be wrong.
  closed_note   VARCHAR(500) NULL,

  created_by    VARCHAR(190) NULL,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  closed_at     DATETIME NULL,

  PRIMARY KEY (id),
  KEY ix_idr_cell   (centre_id, year, room_id, status),
  KEY ix_idr_child  (kidsoft_child_id, year),
  KEY ix_idr_place  (placement_id),
  CONSTRAINT fk_idr_centre FOREIGN KEY (centre_id) REFERENCES centres (id),
  CONSTRAINT fk_idr_room   FOREIGN KEY (room_id)   REFERENCES rooms (id) ON DELETE CASCADE,
  CONSTRAINT fk_idr_place  FOREIGN KEY (placement_id) REFERENCES placements (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- The weekdays wished for. `granted_at` is stamped by internal_requests_close_met()
-- when the board shows the child booked on that day - per DAY, so a partly met
-- wish stays open for the rest.
CREATE TABLE IF NOT EXISTS internal_day_request_days (
  request_id INT UNSIGNED NOT NULL,
  weekday    TINYINT UNSIGNED NOT NULL COMMENT '1=Mon .. 5=Fri',
  granted_at DATETIME NULL,
  PRIMARY KEY (request_id, weekday),
  KEY ix_idrd_weekday (weekday, granted_at),
  CONSTRAINT fk_idrd_req FOREIGN KEY (request_id) REFERENCES internal_day_requests (id) ON DELETE CASCADE,
  CONSTRAINT ck_idrd_weekday CHECK (weekday BETWEEN 1 AND 5)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- A DAY HELD FOR A CHILD WHO IS NOT BOOKED ON IT (10 Sep 2026).
--
-- Reported from a centre: "Tuggerah SS Silver has a boy who is on a Behaviour
-- Support Plan but currently has dropped the Friday... how do we hold his
-- Friday?" The booking is gone from Kidsoft while the centre works with the
-- family, and the place must not be sold to anybody else in the meantime.
--
-- MEASURED THE DAY IT WAS REPORTED. Logan Camilleri holds Shining Stars Silver
-- Mon/Tue/Wed, and that room's FRIDAY read "1 place free" on the board -- his
-- place, on open sale to the next family who rang. Every other cell in the room
-- was full, so it was also the only Friday anybody could have been offered.
--
-- Kidsoft cannot answer this and never will: the only record of a place being
-- held is the booking, and the booking is exactly what has been removed. So it
-- is ours by the same test as offers, work items and the internal day waitlist.
--
-- IT IS SUPPLY, NOT DEMAND, AND THAT IS THE WHOLE DISTINCTION FROM A WISH.
-- internal_day_requests records a family ASKING for a day they do not have; it
-- makes a cell `grantable`, the loudest state on the grid. A hold is the
-- opposite fact - this day is already spoken for - so it SUBTRACTS from the free
-- figure exactly as a pending offer and a re-enrolment hold do, and it never
-- puts a dot on the grid. Recorded from the same box on the room roster, which
-- is why the two are one dialog with two clearly different options.
--
-- ONE ROW PER HELD WEEKDAY, unlike internal_day_requests, which carries its days
-- in a child table. The reason that table needs one is `granted_at` per day; a
-- hold's whole state IS per day (Friday can go back to being booked while
-- Thursday stays held), so a row per weekday is the same thing with one table
-- instead of two.
--
-- NO REVIEW DATE AND NOTHING EXPIRES (Broc's call, 10 Sep 2026, when the three
-- options were put to him). A hold runs until somebody releases it. The one
-- automatic close is REALITY, the rule work_autoclose() and
-- internal_requests_close_met() already follow: when the derived board shows the
-- child booked in that room on that weekday again, the hold has done its job and
-- day_holds_close_booked() stamps it. Nothing else ever releases a place a
-- family is being kept.
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS day_holds (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  centre_id     INT UNSIGNED NOT NULL,
  room_id       INT UNSIGNED NOT NULL,
  year          SMALLINT UNSIGNED NOT NULL,
  weekday       TINYINT UNSIGNED NOT NULL COMMENT '1=Mon .. 5=Fri',

  -- WHO, on the same terms as the board and the internal day waitlist: a Kidsoft
  -- child is its ChildID, a provisional child (pencilled in ahead of Kidsoft) is
  -- our placements row. At least one is set; enforced in day_hold_save().
  kidsoft_child_id CHAR(36)     NULL,
  placement_id     INT UNSIGNED NULL,
  -- Denormalised so a released hold still names the family after the child
  -- leaves Kidsoft. History must not depend on a live join.
  child_name       VARCHAR(160) NOT NULL,

  -- WHY THE PLACE IS BEING KEPT, in a person's words. Free text deliberately: a
  -- Behaviour Support Plan is the case that prompted this, and it is not the only
  -- one. The BSP itself is a FLAG on the placement (placements.has_behaviour_plan)
  -- and is not duplicated here - a reason list keyed to that flag would be a copy
  -- of it, and copies drift.
  reason        VARCHAR(500) NULL,

  -- WHEN THE PLACE STOPPED BEING BOOKED, not when this row was typed. A manager
  -- reading the support-plans screen is asking how long a place has been out of
  -- use, and the answer is not "since somebody got round to recording it".
  held_from     DATE NOT NULL,

  status        ENUM('open','released') NOT NULL DEFAULT 'open',
  -- Why it ended. Set by hand on a manual release, and by
  -- day_holds_close_booked() when Kidsoft shows the booking back.
  released_note VARCHAR(500) NULL,
  released_at   DATETIME NULL,

  created_by    VARCHAR(190) NULL,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  PRIMARY KEY (id),
  KEY ix_hold_cell  (centre_id, year, room_id, status),
  KEY ix_hold_child (kidsoft_child_id, year, status),
  KEY ix_hold_place (placement_id),
  CONSTRAINT fk_hold_centre FOREIGN KEY (centre_id) REFERENCES centres (id),
  CONSTRAINT fk_hold_room   FOREIGN KEY (room_id)   REFERENCES rooms (id) ON DELETE CASCADE,
  -- SET NULL, NOT CASCADE, and it differs from internal_day_requests on purpose.
  -- placements is an ANNOTATION store: for a Kidsoft child this column points at
  -- the row carrying their flags and note, which somebody may perfectly well
  -- clear. Cascading would then delete a place the centre is holding for a
  -- family, silently, and put it back on sale. kidsoft_child_id is the identity
  -- that matters; this one is a convenience for reaching the contact log.
  CONSTRAINT fk_hold_place  FOREIGN KEY (placement_id) REFERENCES placements (id) ON DELETE SET NULL,
  CONSTRAINT ck_hold_weekday CHECK (weekday BETWEEN 1 AND 5)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- WHO MAY SIGN IN, AND WHAT THEY MAY SEE (19 Aug 2026).
--
-- This replaced a hard-coded PHP array, `allowed_user_emails` in
-- _incl/entra-config.php. Two names sat in it, and adding a third meant editing
-- a config file on a box with no version control - so in practice access was
-- granted by whoever had a shell, and there was no record of when or by whom.
-- Now it is a table an Administrator edits from the app, and every change goes
-- through audit().
--
-- EMAIL IS THE IDENTITY, not entra_oid, for the same reason audit_log keys on
-- staff_email: the email is what an Administrator types when granting access,
-- and it is what every existing audit row already carries. The oid is recorded
-- on first sign-in for reference and is never matched on - an Administrator
-- cannot know it in advance, so making it the key would mean nobody could be
-- added before their first sign-in, which is the one thing this has to do.
-- Stored LOWERCASED, and matched lowercased, because Entra will happily return
-- Broc@ one day and broc@ the next.
--
-- ROWS ARE DISABLED, NEVER DELETED. audit_log rows point at an email forever,
-- so a deleted user is an audit trail that cannot be resolved to a person. The
-- modal's "Remove access" sets active = 0.
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS app_users (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  email        VARCHAR(190) NOT NULL COMMENT 'Lowercased. THE identity; matches audit_log.staff_email',
  -- Both filled in from the Entra id_token on first sign-in. Until then the
  -- modal shows the email alone, which is all an Administrator had to type.
  name         VARCHAR(190) NULL,
  entra_oid    CHAR(36)     NULL COMMENT 'Recorded for reference; never matched on',
  role         ENUM('administrator','manager','supervisor') NOT NULL DEFAULT 'supervisor',
  active       TINYINT(1)   NOT NULL DEFAULT 1,
  -- Who granted this, and when they last actually used it. Dormant access is
  -- the access worth revoking, and without a date nobody can tell which is which.
  invited_by   VARCHAR(190) NULL,
  last_seen_at DATETIME     NULL,
  created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_app_users_email (email),
  KEY ix_app_users_active (active, role)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Which centres a SUPERVISOR may see. Administrators and Managers see every
-- centre and have no rows here - their scope is their role, so writing seven
-- rows for them would be a copy that goes stale the day an eighth centre opens.
--
-- A supervisor may hold more than one: covering two sites is normal, and a
-- second login for the second site is how a person ends up signed in as the
-- wrong one. With exactly one row the centre bar disappears entirely; with two
-- it shows those two tabs and no "All centres".
CREATE TABLE IF NOT EXISTS app_user_centres (
  user_id   INT UNSIGNED NOT NULL,
  centre_id INT UNSIGNED NOT NULL,
  PRIMARY KEY (user_id, centre_id),
  KEY ix_auc_centre (centre_id),
  CONSTRAINT fk_auc_user   FOREIGN KEY (user_id)   REFERENCES app_users (id) ON DELETE CASCADE,
  CONSTRAINT fk_auc_centre FOREIGN KEY (centre_id) REFERENCES centres (id)   ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- ISSUE REPORTS (13 Oct 2026). What somebody sent from the "Report Issue"
-- button that sits at the right-hand end of the centre bar on every screen.
-- See _incl/issue-reports.php and _incl/docs/issue-reports.md.
--
-- THE ROW IS THE RECORD; the email to the app's owner is the notification. The
-- row commits first and the email's outcome is written back onto it, so a Graph
-- failure costs a notification, never the report somebody took the time to
-- write - the same order as the Kidsoft comment copy.
--
-- `context` is what the BROWSER said - the page title, the centre and year on
-- screen, the window size and the last few things done in that tab. It is
-- trimmed and capped server-side and is not trusted for anything but reading.
-- Who sent it, their role and their browser are read from the session and the
-- request instead, which is why those are columns.
--
-- `id` IS THE KEY AND NOTHING ELSE. A report has no number anywhere it is read
-- (Broc, 13 Oct 2026); it is identified by who sent it and when.
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS issue_reports (
  id          INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  staff_email VARCHAR(190)  NOT NULL COMMENT 'Lowercased; matches audit_log.staff_email',
  staff_name  VARCHAR(190)  NULL,
  staff_role  VARCHAR(20)   NULL,
  page_url    VARCHAR(1000) NOT NULL COMMENT 'Path and query of the screen it was sent from',
  description TEXT          NOT NULL,
  context     JSON          NULL,
  user_agent  VARCHAR(500)  NULL,
  emailed_at  DATETIME      NULL,
  email_error VARCHAR(500)  NULL,
  created_at  DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY ix_issue_reports_created (created_at),
  KEY ix_issue_reports_staff (staff_email, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- SUPPORT PLANS - where a child's Behaviour or Individual Support Plan has got
-- to (17 Sep 2026; written down here 29 Oct 2026, when this file was found not
-- to carry them). WHO is on a plan is the placement's flag and only the flag;
-- these hold the process state, created lazily by the first write. See
-- _incl/docs/support-plans.md and _incl/support-plan-steps.php.
--
-- support_plan_steps.booked_on (29 Oct 2026) is the day a step is booked for,
-- typed by staff - never derived. A booked day becomes an ALL-DAY entry in the
-- centre's Outlook calendar, through the same calendar_* columns and the same
-- code as a tour (calendar_row_write() in tour-calendar.php). A row under step
-- key '__review' is the ISP review's booked day and is not a step.
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS support_plans (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  placement_id   INT UNSIGNED NOT NULL,
  plan_type      ENUM('bsp','isp') NOT NULL,
  commenced_on   DATE         NULL,
  next_review_on DATE         NULL,
  outcome        VARCHAR(30)  NULL,
  outcome_on     DATE         NULL,
  outcome_note   VARCHAR(500) NULL,
  outcome_by     VARCHAR(190) NULL,
  created_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_placement_type (placement_id, plan_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

CREATE TABLE IF NOT EXISTS support_plan_steps (
  id                 INT UNSIGNED NOT NULL AUTO_INCREMENT,
  plan_id            INT UNSIGNED NOT NULL,
  step_key           VARCHAR(40)  NOT NULL,
  due_on             DATE         NULL COMMENT 'An override; NULL = derived from commencement on every read',
  booked_on          DATE         NULL COMMENT 'The day this step is booked for, typed by staff. An all-day entry in the centre calendar; NULL = not booked',
  done_on            DATE         NULL,
  done_by            VARCHAR(190) NULL,
  note               VARCHAR(500) NULL,
  calendar_mailbox   VARCHAR(190) NULL COMMENT 'Mailbox the appointment lives in, so a move or delete reaches the right one',
  calendar_event_id  VARCHAR(255) NULL COMMENT 'Graph event id of the appointment. Cleared, NOT deleted, when a passed booking is replaced',
  calendar_hash      CHAR(40)     NULL COMMENT 'sha1 of what was last written; a difference means it needs writing again',
  calendar_txn       VARCHAR(64)  NULL COMMENT 'Graph transactionId of a create whose outcome is not yet known',
  calendar_synced_at DATETIME     NULL,
  calendar_error     VARCHAR(255) NULL COMMENT 'Why the last write failed. NULL once it succeeds',
  created_at         DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at         DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_plan_step (plan_id, step_key),
  KEY ix_sps_booked (booked_on)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
