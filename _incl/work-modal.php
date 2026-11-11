<?php
// The work-item modal - SHARED by the Placement board, the In progress module
// and the Waitlist.
//
// It was written inline in placement.php, which is how the work layer ended up
// reachable from exactly one place in the app: a cell on the board. An item you
// could not find was an item you could not work, and a UAT tester who clicked
// "Start working this" by accident had no way back to it at all.
//
// Markup here, behaviour in static/js/work-modal.js. All three pages load both,
// so there is one definition of each - the lesson the stat tile and the mark bar
// each taught the hard way (six copies and three, respectively).
//
// REBUILT 18 SEP 2026, AND THE MEASUREMENTS ARE THE ARGUMENT.
//
// It was a 500px column holding twelve form controls with the record underneath
// them. Driven in Chromium against live rows:
//
//   * the dialog stood 934-1098px tall and the Save button sat at y=1071 - 271px
//     BELOW the bottom of the 800px laptop viewport this app measures everything
//     else against, and still 9px below the fold at 1920x1080;
//   * the history began at y=829, so on the standard screen the user saw none of
//     the record at all;
//   * and the dialog had no scroll container, so every event appended made it
//     taller and pushed Save further away. A family is worked for a median of 21
//     days, 43% of them over 30.
//
// What that cost, in the data: 15 items and 41 events over two weeks of daily
// use, and EVERY note in work_item_events is machine-generated. Not one human
// sentence. `communications` held one row group-wide and it came from the family
// modal. The screen the team uses to chase families recorded nothing about the
// chasing.
//
// So the order is inverted. The alerts and the phone number come first, then the
// act of recording what just happened, then the record itself, then the
// properties nobody touches. Header and footer are pinned by
// `modal-dialog-scrollable` - the fix support-plans.php had already written down
// on 17 Sep and that was never applied back here:
//
//   "SCROLLABLE BODY, so the header keeps saying which child while the steps
//    scroll and the footer keeps the outcome button in reach."
//
// `modal-lg` for the same reason every other substantive editor here is
// (waitlist-modal, users-modal, support-plans, layout): 800px lets the form sit
// in two- and three-across rows instead of a single stack, which is what lifts
// the record above the fold rather than merely making it reachable.
?>
<!-- Work item. The record of what the team is DOING about an empty place, which
     is the one thing Kidsoft has no field for. "Enrolled" is deliberately absent
     from the stage list: an item closes when Kidsoft shows the child in the room,
     never because somebody ticked it. -->
<div class="modal fade" id="work-modal" tabindex="-1">
  <div class="modal-dialog modal-lg modal-dialog-scrollable">
    <form class="modal-content" id="work-form">
      <div class="modal-header">
        <div>
          <h5 class="modal-title mb-0" id="work-title">Enrolment</h5>
          <div class="small text-muted" id="work-subtitle"></div>
        </div>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <input type="hidden" name="id">

        <?php /* THE TWO HARD CLOCKS, FIRST, AND AS ALERTS.
                 work_urgency_sort() already ranks every item in the list by
                 these two and nothing else outranks them; inside the modal they
                 were a grey sentence in a plain bordered box, where "Offer
                 expired" carried exactly as much weight as "Starting 5/01/2027"
                 underneath it. The class is set by work-modal.js from the same
                 grading window.offerUrgency() gives the list, so one offer
                 cannot be urgent on the table and calm in the dialog. */ ?>
        <div class="d-none mb-3" id="work-offer"></div>
        <?php /* AN OFFER TO ONE SIBLING AND NOT THE OTHER, for the same start
                 (19 Oct 2026). Offers are made in Kidsoft, so this cannot make the
                 missing one - it says it is missing, beside the offer that is not.
                 See work_offer_gap(). */ ?>
        <div class="d-none alert alert-warning py-2 px-3 mb-3 small" id="work-family-alert"></div>
        <?php /* The application this item is worked against is a duplicate at this
                 centre (6 Nov 2026). Filled by dupAlert(); see waitlist_duplicates(). */ ?>
        <div class="d-none mb-3" id="work-dup"></div>
        <?php /* THE TOUR, when one has happened and nobody has said what came of
                 it. This is the way a tour goes missing: the appointment passes,
                 and if nothing asks, nothing ever will. It is the reason the
                 modal was opened, so it is the first thing in it. Hidden
                 otherwise - see work-modal.js. */ ?>
        <div class="d-none alert alert-warning py-2 px-3 mb-3" id="work-tour-ask"></div>

        <?php /* WHO TO RING. Every stage of this pipeline comes down to a phone
                 call and the modal did not carry a phone number, so the one
                 action it exists to prompt sent the user back to the Waitlist to
                 look one up. 851 of 855 applications carry a guardian phone and
                 850 an email, all of it already in our own tables.

                 READ-ONLY, as links. The editable boxes further down are still
                 shown for an ENQUIRY only: a queue family's number belongs to
                 Kidsoft, and an input holding it invites somebody to key it a
                 second time, which is the work this tool exists to remove.
                 Showing a number is not asking for it again. Built by
                 work-modal.js from $w['reach']; hidden when there is nobody to
                 ring. */ ?>
        <div class="d-none mb-3" id="work-reach"></div>

        <?php /* WHAT JUST HAPPENED - the act this modal is opened to perform.
                 Stage and tour share the row because booking a tour IS a stage
                 change: work_set_tour() promotes the item, and work-modal.js
                 rank-tests the posted stage so the two writes cannot fight (see
                 the note there - the bug left a family at "Identified" with a
                 tour in the diary). */ ?>
        <div class="row g-3">
          <div class="col-md-6">
            <label class="form-label" for="work-stage">Stage</label>
            <select class="form-select" name="stage" id="work-stage"></select>
            <?php /* OFFERED AND ACCEPTED ARE NOT IN THE LIST (22 Oct 2026, the
                     enrolments meeting): Kidsoft sets both, and /api/work refuses
                     them by hand. Said once, under the list they are missing
                     from; hidden by work-modal.js once the item is there. */ ?>
            <div class="form-text" id="work-stage-help">Offered and Accepted follow Kidsoft.</div>
            <?php /* LOST ASKS (19 Oct 2026): picking Lost offers the brothers and
                     sisters with an open item here, UNTICKED. A family going
                     elsewhere usually takes both, but that is a person's call. */ ?>
            <div id="work-lost-family"></div>
          </div>
          <?php /* Tours are OURS: "tour" appears nowhere in the Kidsoft API, and
                   the spreadsheet sheet meant for them was a ruled template with
                   no rows in it. datetime-local because a tour is an appointment -
                   "Tuesday" without a time is not something a parent can turn
                   up to. The value posts as 'Y-m-dTH:i' and is stored as typed,
                   Sydney wall-clock, like every other datetime here. */ ?>
          <?php /* ONLY FOR TOUR BOOKED (22 Oct 2026, the enrolments meeting: "can
                   this only show for this stage?"). Shown when that stage is
                   picked, and on an item whose tour is still coming up so it can
                   be moved or cleared from any stage. Toggled in work-modal.js. */ ?>
          <div class="col-md-6" id="work-tour-wrap">
            <label class="form-label" for="work-tour">Tour</label>
            <input type="datetime-local" class="form-control" name="tour_at" id="work-tour">
            <?php /* SET BY work-modal.js, because the sentence depends on the
                     state. It read "Clear it to cancel the tour." on every item
                     in the app, including the ones with no tour to cancel and the
                     ones whose tour had already happened and was being answered
                     in the panel directly above it. */ ?>
            <div class="form-text" id="work-tour-help"></div>
            <?php /* Whether the tour reached the centre's Outlook calendar
                     (16 Oct 2026) - window.tourCalendarNote(), tour-calendar.php. */ ?>
            <div id="work-tour-cal"></div>
            <?php /* "Send the invite to the parent" (29 Oct 2026) -
                     window.tourInviteBox(), tour-calendar.php. */ ?>
            <div id="work-tour-invite"></div>
            <?php /* THE TOUR IS THE FAMILY'S (19 Oct 2026): brothers and sisters
                     waiting here, ticked, go onto the same visit. */ ?>
            <div id="work-tour-family"></div>
          </div>
          <?php /* THE ROOM AND DAYS BEING TALKED ABOUT (22 Oct 2026, the enrolments
                   meeting: "can we select from there what room, and what days we
                   are talking to the family for?"). The family's Kidsoft
                   application says what THEY asked for; this is what the team is
                   discussing with them, which changes once somebody has rung.
                   They are the item's own room and days, so a room picked here
                   also shows the family on that room's cells on the board. From
                   Offered on, the offer in Kidsoft decides both and they are shown
                   but not editable - work_room_days_locked(). */ ?>
          <div class="col-md-6">
            <label class="form-label" for="work-room">Intended room</label>
            <select class="form-select" name="room_id" id="work-room"></select>
          </div>
          <div class="col-md-6">
            <label class="form-label">Days</label>
            <div class="d-flex flex-wrap gap-2" id="work-days" role="group" aria-label="Days being talked about">
              <?php foreach (WEEKDAYS as $wm_wd => $wm_dl): ?>
                <input type="checkbox" class="btn-check" name="days[]" value="<?= (int)$wm_wd ?>" id="wk-d<?= (int)$wm_wd ?>">
                <label class="btn btn-sm btn-outline-primary" for="wk-d<?= (int)$wm_wd ?>"><?= htmlspecialchars(substr($wm_dl, 0, 3)) ?></label>
              <?php endforeach; ?>
            </div>
            <div class="form-text d-none" id="work-rd-help">Set by the offer in Kidsoft.</div>
          </div>
          <?php /* WHAT HAPPENED - and the whole compose block now posts to
                   /api/comms, not to /api/work. See the block comment at the top
                   of this file: the work modal used to write `communications`
                   through work_log_contact(), which had no detail field,
                   truncated the summary to 255 characters without saying so, and
                   never copied anything to Kidsoft - so the identical sentence
                   travelled when typed in the family modal and did not when
                   typed here. That function is gone. */ ?>
          <div class="col-12">
            <?php /* The label follows the stage picked - "Note" at Identified,
                     "Tour notes" on a move to Toured. See syncStage(). */ ?>
            <label class="form-label" for="work-summary" id="work-summary-label">What happened</label>
            <?php /* CHIPS, NOT A CLOSED SELECT, and the reason is measured. This
                     was a <select> defaulting to "Just a note" - the one option
                     that did not reach the family's record - and `communications`
                     held ONE row group-wide across two weeks of daily use. A
                     default nobody can see is a default nobody changes.

                     THE DEFAULT IS STILL THE SAFE ONE, and it is now safe AND
                     useful. "Note" writes to the contact log as an internal note,
                     so a colleague opening this family from any screen sees it --
                     but it does NOT travel to Kidsoft, because an internal note
                     is not a record of contact with the parent and
                     `Comment/createComment` has no update and no delete. Picking
                     a channel is the deliberate act that says a real
                     conversation happened.

                     Not defaulted to "Called": a wrong default here writes
                     "phoned the family" onto a child's Kidsoft record
                     permanently. The chips make the choice visible, which was the
                     actual defect; they do not make a guess for the user. */ ?>
            <div class="d-flex flex-wrap gap-2 mb-2" id="work-channels" role="group"
                 aria-label="How this was recorded">
              <?php
              // Text, not icons. window.channelIcon() owns the marks and a fourth
              // hand-typed copy of that map - in PHP this time - is exactly the
              // drift it was pulled into app.js to stop.
              $wm_chips = ['note' => 'Note', 'phone' => 'Called', 'email' => 'Emailed',
                           'sms' => 'Texted', 'in_person' => 'In person', 'other' => 'Other'];
              foreach ($wm_chips as $wm_v => $wm_l): ?>
                <input type="radio" class="btn-check" name="channel" value="<?= $wm_v ?>"
                       id="wc-<?= $wm_v ?>"<?= $wm_v === 'note' ? ' checked' : '' ?>>
                <label class="btn btn-sm btn-outline-secondary" for="wc-<?= $wm_v ?>"><?= $wm_l ?></label>
              <?php endforeach; ?>
            </div>
            <?php /* WHICH WAY ROUND, asked only once a real channel is picked. An
                     internal note has no direction - nobody contacted anybody -
                     and a "We contacted them" sitting live beside "Note" invites
                     an answer to a question that was not asked. Toggled in
                     work-modal.js. */ ?>
            <div class="d-none mb-2" id="work-direction-wrap">
              <div class="d-flex flex-wrap gap-2" role="group" aria-label="Which way round">
                <input type="radio" class="btn-check" name="direction" value="outbound" id="wd-out" checked>
                <label class="btn btn-sm btn-outline-secondary" for="wd-out">We contacted them</label>
                <input type="radio" class="btn-check" name="direction" value="inbound" id="wd-in">
                <label class="btn btn-sm btn-outline-secondary" for="wd-in">They contacted us</label>
              </div>
            </div>
            <?php /* SUMMARY AND DETAIL, the same split api/comms has always had
                     and the same shape the family modal and Support plans use.
                     255 is a HARD ceiling, not a nicety: `communications.summary`
                     is varchar(255) and MariaDB runs STRICT_TRANS_TABLES here, so
                     an over-length summary is an error rather than a quiet
                     truncation. The old path clipped it with mb_substr() and kept
                     the full text on the item, leaving two records of one
                     sentence that disagreed. */ ?>
            <input class="form-control mb-2" name="summary" id="work-summary" maxlength="255"
                   placeholder="What was said, in one line">
            <textarea class="form-control" name="body" id="work-body" rows="2" maxlength="3000"
                      placeholder="Anything more (optional)"></textarea>
            <?php /* WHETHER IT TRAVELS, said BEFORE it is typed rather than after
                     it is saved - the family modal has done this since 14 Aug and
                     this screen wrote to the same table and said nothing. Kidsoft
                     creates a child record only when an application converts, so
                     for 87% of the queue there is nowhere to copy a note to, and
                     a staff member who assumes it travelled stops keeping the
                     Kidsoft note they still need. Filled by work-modal.js from
                     $w['kidsoft_push']. */ ?>
            <div class="form-text" id="work-travel"></div>
            <?php /* One note, shown on each brother or sister ticked; the Kidsoft
                     copy stays on this child. See api/comms. */ ?>
            <div id="work-note-family"></div>
          </div>
        </div>

        <?php /* THE PARENT'S DETAILS, AND ONLY FOR AN ENQUIRY. A family from the
                 queue or a child already in care already has their contact
                 details in Kidsoft, and putting empty boxes here would invite the
                 team to key them in a second time - which is the exact thing
                 this whole tool exists to stop. Shown only when nothing else
                 identifies the family. Toggled in work-modal.js. */ ?>
        <div class="d-none" id="work-enquiry">
          <h6 class="text-uppercase small text-muted mt-4">How to reach them</h6>
          <div class="row g-3">
            <div class="col-md-4">
              <label class="form-label" for="work-cname">Parent</label>
              <input class="form-control" name="contact_name" id="work-cname" maxlength="160">
            </div>
            <div class="col-md-4">
              <label class="form-label" for="work-cphone">Phone</label>
              <input class="form-control" name="contact_phone" id="work-cphone" maxlength="40">
            </div>
            <div class="col-md-4">
              <label class="form-label" for="work-cemail">Email</label>
              <input class="form-control" name="contact_email" id="work-cemail" maxlength="190">
            </div>
          </div>
        </div>

        <?php /* THE RECORD - one column, in time order, newest first.
                 It was two headed lists, "This enrolment" (stage changes, oldest
                 first) and "Contact with this family" (contacts, newest first) -
                 two lists sorted opposite ways, so reconstructing a single week
                 meant reading both and interleaving them by hand.

                 THE TWO KINDS STAY DISTINGUISHABLE AND THAT IS NOT DECORATION.
                 A stage change is what the ITEM did; a contact is what happened
                 with the FAMILY and outlives any one item - a contact here may
                 predate this item entirely, and rendering it as part of this
                 item's history would be a lie about which spot was being
                 discussed. Each carries its own mark and its own border colour;
                 see work-modal.js. */ ?>
        <h6 class="text-uppercase small text-muted mt-4">What has happened so far</h6>
        <div id="work-timeline" class="mb-0"></div>

        <?php /* KIDSOFT'S OWN COMMENTS, alongside ours and never merged in. A
                 note somebody here typed and a note Kidsoft holds answer
                 different questions, and Kidsoft's side is mostly enrolment-form
                 capture ("Agreed to terms and conditions: Yes") rather than
                 contact history - merging them would bury what the team actually
                 did behind what a form recorded. They also carry NO DATE (Kidsoft
                 returns none), so they could not be sorted into the timeline
                 above even if that were wanted.

                 FETCHED AFTER THE MODAL IS OPEN, AND ONLY WHERE THERE COULD BE
                 ANYTHING. work_get() already says whether Kidsoft holds a record
                 for this family, and for 87% of the queue it does not - so those
                 items make no request at all. The rest read a per-centre cache
                 (getChildren 36 h / getGuardians 3 h) that the family modal
                 already warms, and never block the open. See work-modal.js. */ ?>
        <div class="d-none" id="work-kidsoft">
          <h6 class="text-uppercase small text-muted mt-4 mb-2">Held in Kidsoft</h6>
          <div id="work-kidsoft-list"></div>
        </div>

        <?php /* THE PROPERTIES, LAST, because the measurement says nobody edits
                 them: next_action is NULL on all 15 live items, and owner_email
                 is written automatically by work_create_for_offer(). They were
                 above the record, competing with the note for the only space
                 above the fold. */ ?>
        <h6 class="text-uppercase small text-muted mt-4">Follow up</h6>
        <div class="row g-3">
          <?php /* ONE CONTROL, NOT TWO BOXES (18 Sep 2026), and the data is the
                   argument. "Next step" and "Due" were independent and optional,
                   and what the team produced with them was five dates and no
                   steps: on 3 Sep they were typed as 08/09, 01/09, 25/08, 24/08
                   and 24/08 - four of them ALREADY IN THE PAST when they were
                   entered, one by ten days - because they are the offers' expiry
                   dates, re-keyed by hand into a box that meant something else.
                   The Dashboard then reports "Past their date: 5" about five
                   items that do not say what the step is, and the In progress
                   list renders an empty Next step column on every row.

                   A date with nothing against it cannot be acted on; a step with
                   no date never surfaces, because work_urgency_sort() bands on
                   the date alone. So the date is part of the sentence and not a
                   field beside it: the "by" row is revealed by typing a step and
                   clears with it, and work_posted_next_step() refuses a half on
                   the server. See work-modal.js.

                   THE BUTTONS CARRY SERVER-COMPUTED DATES. "Tomorrow" is a date,
                   and every route to one in the browser goes through new Date(),
                   which reads the viewer's timezone instead of Sydney - the trap
                   the whole Dates block in app.js exists to avoid. They come
                   from work_due_presets() on every open, so a modal left sitting
                   across midnight cannot offer yesterday's tomorrow. */ ?>
          <div class="col-12">
            <label class="form-label" for="work-next">Next step</label>
            <input class="form-control" name="next_action" id="work-next" maxlength="255"
                   placeholder="What needs doing, e.g. ring back about the Friday">
            <div class="d-none mt-2" id="work-when">
              <div class="d-flex flex-wrap align-items-center gap-2">
                <span class="small text-muted">by</span>
                <div class="d-flex flex-wrap gap-2" id="work-when-presets"></div>
                <input type="date" class="form-control form-control-sm w-auto"
                       name="next_action_at" id="work-next-at">
              </div>
              <?php /* WHO IT IS FOR (22 Oct 2026, the enrolments meeting: "maybe a
                       who? e.g. tick 'me', or 'specific email'"). The reminder is
                       IN THE APP and nowhere else (Broc's call): on its date the
                       item rises to the top of the list and shows in that
                       person's Mine view. Only people who can open this centre
                       are offered - see work_people_for_centre(). */ ?>
              <div class="d-flex flex-wrap align-items-center gap-2 mt-2" id="work-for" role="group" aria-label="Who the next step is for">
                <span class="small text-muted">for</span>
                <input type="radio" class="btn-check" name="next_for_who" value="me" id="wf-me" checked>
                <label class="btn btn-sm btn-outline-secondary" for="wf-me">Me</label>
                <input type="radio" class="btn-check" name="next_for_who" value="other" id="wf-other">
                <label class="btn btn-sm btn-outline-secondary" for="wf-other">Someone else</label>
                <input type="email" class="form-control form-control-sm w-auto d-none" name="next_action_for"
                       id="work-next-for" list="work-people" maxlength="190" placeholder="Their email">
                <datalist id="work-people"></datalist>
              </div>
              <?php /* Set by work-modal.js. Carries the overdue warning, which is
                       a WARNING and not a block: an orphan date from before this
                       change still has to be clearable, and blocking a past date
                       outright would trap the five rows this control exists to
                       fix. */ ?>
              <div class="form-text" id="work-when-help"></div>
            </div>
          </div>
          <?php /* owner_email has been written on every item since 6 Aug and was
                   displayed nowhere, so "whose family is this" had no answer and
                   the Mine filter had nothing to filter on. */ ?>
          <div class="col-12">
            <label class="form-label" for="work-owner">Who is looking after this</label>
            <div class="input-group">
              <input class="form-control" name="owner_email" id="work-owner" maxlength="190"
                     placeholder="Nobody in particular">
              <button class="btn btn-outline-secondary" type="button" id="work-mine">It's mine</button>
            </div>
          </div>
        </div>
      </div>
      <?php /* NOT the same as moving it to Lost, and the modal must not blur the
               two. Lost is a real outcome that belongs in the history; a
               mis-click is not an outcome, and filing one as a family we chased
               and failed would put a fiction into the only record of what the
               team did. Hidden as soon as the item has any history - see
               work_cancel(), which refuses it server-side as well. */ ?>
      <div class="modal-footer">
        <button type="button" class="btn btn-link text-danger me-auto d-none p-0" id="work-cancel">
          Created by mistake - remove it
        </button>
        <?php /* ARCHIVE (6 Nov 2026): closed without an outcome, history kept - a
                 duplicate or leftover application. Never shown with the button
                 above: a pristine item is removed, not archived. See
                 work_archive(). */ ?>
        <button type="button" class="btn btn-outline-secondary me-auto d-none" id="work-archive">
          <i class="fa-solid fa-box-archive me-1"></i>Archive
        </button>
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Close</button>
        <button type="submit" class="btn btn-primary">Save</button>
      </div>
    </form>
  </div>
</div>
