<?php
// The family modal - SHARED by the Waitlist queue and the Placement board.
//
// One dialog, two tabs: the application Kidsoft holds and every contact this
// team has had with the family. It was written inline in waitlist.php, which is
// how a family in "Could take this day" on the board could be rung, offered a
// place and started through the pipeline WITHOUT anybody being able to read what
// the last person had already told them. The shortlist on the board is exactly
// where that question gets asked.
//
// Markup here, behaviour in static/js/waitlist-modal.js. Both pages load both,
// so there is one definition of each - the same split work-modal.php makes, and
// for the same reason.
//
// NEEDS _incl/waitlist.php (POA_LABELS) and _incl/rooms.php (WEEKDAYS).

// ITS OWN ESCAPER, not the host page's. This block used to sit in waitlist.php
// and lean on a $h closure defined at the top of it - included from anywhere
// else that is an undefined-function fatal, and PHP lints clean with one.
$wlm_h = static fn($v) => htmlspecialchars((string)$v, ENT_QUOTES);
?>
<!-- One family, two tabs: the application and everything ever said to them.
     THEY USED TO BE TWO MODALS BEHIND TWO MENU ITEMS (14 Aug 2026), which meant
     the two things you want when a family comes up - what they asked for, and
     what we have already told them - were one dropdown apart and could not be
     open at once. Reading the history before ringing is the whole point of the
     log, so it now sits one click from the details rather than one menu from the
     screen. Both are opened by the child's NAME - the queue row's, and the
     shortlist card's on the Placement board. The subject of the thing you are
     reading is a better handle than an "..." at the far end of it.

     THE FORM IS NO LONGER THE .modal-content. It cannot be - the contact tab
     holds a form of its own and nested forms are invalid HTML, so each pane
     carries its own. The Save/Delete footer therefore lives INSIDE the
     application pane, which is also where it belongs: it has nothing to say
     about a contact log. -->
<div class="modal fade" id="wl-modal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header border-bottom-0 pb-0">
        <h5 class="modal-title">Waitlist application</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>

      <?php /* Do Not Enrol, above the tabs because it is about the family and not
               about either tab - whoever is about to ring them reads it first.
               Filled by dneAlert() in static/js/app.js; empty for almost everyone. */ ?>
      <div id="wl-dne" class="px-3 pt-2 d-none"></div>
      <?php /* The same child applied for twice at this centre (6 Nov 2026), above
               the tabs for the same reason. Filled by dupAlert() in app.js; see
               waitlist_duplicates(). */ ?>
      <div id="wl-dup" class="px-3 pt-2 d-none"></div>

      <?php /* The header gives up its border to these, so there is one line under
               the title rather than two. Contact history is DISABLED while adding
               a family who is not saved yet - there is no application to hang a
               note on, and an empty log would read as "nobody has rung them". */ ?>
      <ul class="nav nav-tabs px-3 pt-2" id="wl-tabs" role="tablist">
        <li class="nav-item" role="presentation">
          <button class="nav-link active" id="wl-tab-app" type="button" role="tab"
                  data-bs-toggle="tab" data-bs-target="#wl-pane-app"
                  aria-controls="wl-pane-app" aria-selected="true">
            <i class="fa-solid fa-address-card fa-sm me-1"></i>Application</button>
        </li>
        <li class="nav-item" role="presentation">
          <button class="nav-link" id="wl-tab-comms" type="button" role="tab"
                  data-bs-toggle="tab" data-bs-target="#wl-pane-comms"
                  aria-controls="wl-pane-comms" aria-selected="false">
            <i class="fa-solid fa-comments fa-sm me-1"></i>Contact history</button>
        </li>
      </ul>

      <div class="tab-content">
      <div class="tab-pane fade show active" id="wl-pane-app" role="tabpanel" aria-labelledby="wl-tab-app">
      <form id="wl-form">
      <div class="modal-body">
        <input type="hidden" name="id">
        <input type="hidden" name="centre_id">

        <h6 class="text-muted small text-uppercase">Child</h6>
        <div class="row g-3 mb-3">
          <div class="col-md-5">
            <label class="form-label">First name</label>
            <input class="form-control" name="child_first_name" required maxlength="80" data-kidsoft="1">
          </div>
          <div class="col-md-4">
            <label class="form-label">Last name</label>
            <input class="form-control" name="child_last_name" required maxlength="80" data-kidsoft="1">
          </div>
          <div class="col-md-3">
            <label class="form-label">Date of birth</label>
            <input type="date" class="form-control" name="child_dob" data-kidsoft="1">
          </div>
        </div>

        <h6 class="text-muted small text-uppercase">Guardian</h6>
        <div class="row g-3 mb-3">
          <div class="col-md-4">
            <label class="form-label">Name</label>
            <input class="form-control" name="guardian_name" maxlength="160" data-kidsoft="1">
          </div>
          <div class="col-md-4">
            <label class="form-label">Email</label>
            <input type="email" class="form-control" name="guardian_email" maxlength="190" data-kidsoft="1">
          </div>
          <div class="col-md-4">
            <label class="form-label">Phone</label>
            <input class="form-control" name="guardian_phone" maxlength="40" data-kidsoft="1">
          </div>
        </div>

        <h6 class="text-muted small text-uppercase">What they want</h6>
        <div class="row g-3 mb-3">
          <div class="col-12">
            <label class="form-label">Days requested</label>
            <div class="d-flex flex-wrap gap-2">
              <?php /* The label carries an id because waitlist-modal.js paints the
                       days this child ALREADY ATTENDS here onto these toggles --
                       green filled where the application asks for a day they
                       already hold, green outlined where they hold it and did not
                       ask. Same four states as the queue's day strip, on the
                       control that already says which days were asked for, so the
                       two screens cannot describe one family differently. */ ?>
              <?php foreach (WEEKDAYS as $wd => $label): ?>
                <input type="checkbox" class="btn-check" name="days[]" value="<?= $wd ?>"
                       id="wd-<?= $wd ?>" data-kidsoft="1">
                <label class="btn btn-outline-primary wl-dbtn" for="wd-<?= $wd ?>"
                       id="wdl-<?= $wd ?>"><?= $wlm_h($label) ?></label>
              <?php endforeach; ?>
            </div>
            <?php /* This form-text used to read "Kidsoft does not record requested
                     days anywhere, so this is the only place they live." It was
                     wrong: RequestedDays is populated on 100% of open
                     applications once WithRequestedDays=true is passed. */ ?>
            <div class="form-text">What the family asked for on their application.</div>
            <?php /* What KIDSOFT OFFERED, when an offer is out. Filled by
                     renderKidsoftOffer() in waitlist-modal.js; hidden otherwise. */ ?>
            <div class="form-text text-body d-none" id="wl-ks-offer"></div>
          </div>
          <div class="col-md-4">
            <label class="form-label">Preferred room</label>
            <select class="form-select" name="preferred_room_id"><option value="">No preference</option></select>
          </div>
          <div class="col-md-4">
            <label class="form-label">For year</label>
            <input type="number" class="form-control" name="for_year" min="2000" max="2100">
          </div>
          <div class="col-md-4">
            <label class="form-label">Needed from</label>
            <input type="date" class="form-control" name="required_from" data-kidsoft="1">
          </div>
          <?php /* WHERE ELSE THIS CHILD IS WAITING. Not a field - there is
                   nothing here to edit, because it is other applications - but it
                   belongs in this section, because "what they want" is a place at
                   Little Miracles and 12% of the queue asked several centres for
                   one. It is what stops a call being half a conversation, and it
                   is the reason to take a family off three other queues when one
                   centre places them. Filled by waitlist-modal.js; the whole block
                   is hidden when there is nowhere else, rather than printing an
                   empty label - and it is hidden on the Add form, which has no
                   child to match yet. */ ?>
          <div class="col-12 d-none" id="wl-elsewhere">
            <label class="form-label">Also waiting at</label>
            <div id="wl-elsewhere-list"></div>
          </div>
          <?php /* Brothers and sisters ALSO WAITING (16 Oct 2026), the queue
                   column's own lines - waitlist_siblings_waiting(). Hidden when
                   there are none, like the box above. */ ?>
          <div class="col-12 d-none" id="wl-sibs-waiting">
            <label class="form-label" id="wl-sibs-waiting-label">Sibling also waiting</label>
            <div id="wl-sibs-waiting-list"></div>
          </div>
          <?php /* Their PAST APPLICATIONS, closed or archived in Kidsoft (11 Nov
                   2026) - search's own lines, waitlist_history(). Hidden when
                   there are none. */ ?>
          <div class="col-12 d-none" id="wl-history">
            <label class="form-label">Past applications</label>
            <div id="wl-history-list"></div>
          </div>
        </div>

        <h6 class="text-muted small text-uppercase">Priority</h6>
        <div class="row g-3 mb-3">
          <div class="col-md-4">
            <label class="form-label">Date added</label>
            <input type="date" class="form-control" name="date_added" data-kidsoft="1">
            <div class="form-text">Decides the queue when nothing else separates two families.</div>
          </div>
          <div class="col-md-4">
            <label class="form-label">Priority of access</label>
            <select class="form-select" name="priority_of_access" data-kidsoft>
              <?php foreach (POA_LABELS as $v => $label): ?>
                <option value="<?= (int)$v ?>" <?= $v === 3 ? 'selected' : '' ?>><?= (int)$v ?> - <?= $wlm_h($label) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-4">
            <label class="form-label">Lead source</label>
            <input class="form-control" name="lead_source" maxlength="120">
          </div>
          <div class="col-12">
            <div class="d-flex flex-wrap gap-3">
              <div class="form-check">
                <input class="form-check-input" type="checkbox" name="is_reenrolment" value="1" id="w-re">
                <label class="form-check-label" for="w-re">Re-enrolment</label>
              </div>
              <div class="form-check">
                <input class="form-check-input" type="checkbox" name="is_staff_child" value="1" id="w-staff">
                <label class="form-check-label" for="w-staff">Staff's child</label>
              </div>
              <div class="form-check">
                <input class="form-check-input" type="checkbox" name="is_sibling" value="1" id="w-sib">
                <label class="form-check-label" for="w-sib">Sibling</label>
              </div>
            </div>
          </div>
        </div>

        <h6 class="text-muted small text-uppercase">Enquiry</h6>
        <div class="row g-3">
          <?php /* THE TOUR IS THE WORK ITEM'S, NOT THIS ROW'S (10 Sep 2026, Broc:
                   "could the In progress tour booked align with the waitlist when
                   you click on their name"). This box used to be a plain date
                   writing waitlist_applications.tour_booked_at, while In progress
                   kept the real appointment with a TIME on it and an answer
                   afterwards - two records of one visit, kept in step by nothing.
                   The live data had already come apart: Jack and Sersha Donnelly
                   were booked into one 09:30 tour on 19/09/2026, and only Sersha's
                   waitlist row carried a date, without the time.

                   So it is a datetime, it posts to /api/work rather than to the
                   application, and booking one on a family nobody is working yet
                   STARTS them in In progress - which is the right way round, since
                   the tour is usually what puts a family into the pipeline. See
                   work_for_waitlist() and work_set_tour(). */ ?>
          <div class="col-md-5">
            <label class="form-label">Tour</label>
            <input type="datetime-local" class="form-control" name="tour_at">
            <div class="form-text" id="wl-tour-note"></div>
            <div id="wl-tour-cal"></div>
            <div id="wl-tour-invite"></div>
          </div>
          <?php /* 3, not 4. The tour needs 5 for a date AND a time, and the
                   checkbox beside it needs 4 or "Consumer guide provided" wraps
                   onto a second line under a bottom-aligned box. A date input is
                   172px at its widest here, which col-md-3 covers. */ ?>
          <div class="col-md-3">
            <label class="form-label">Orientation</label>
            <input type="date" class="form-control" name="orientation_at">
          </div>
          <div class="col-md-4 d-flex align-items-end">
            <div class="form-check mb-2">
              <input class="form-check-input" type="checkbox" name="guide_provided" value="1" id="w-guide">
              <label class="form-check-label" for="w-guide">Consumer guide provided</label>
            </div>
          </div>
          <?php /* THE OTHER HALF OF THE CALL (10 Sep 2026, Broc: "you would
                   either call straight away and book, or mark as identified and
                   then call and leave VM and call them back until you can book a
                   tour"). Booking a tour was the modal's ONLY way into the
                   pipeline, which covers the call that gets answered and nothing
                   else - and the call that is not answered is the one that needs
                   remembering, because there is nothing in the diary to bring the
                   family back.

                   `Identified` is already the right word for it: WORK_STAGES
                   calls it "a candidate for this place, nobody has rung them
                   yet", and `Contacted` is "we have spoken to the family" - so a
                   voicemail correctly leaves them here, and the tour box above
                   promotes them the moment somebody gets through.

                   Hidden once there IS an item (the note above names its stage
                   instead) and on the Add form, which has no application for an
                   item to point at - the same reason Contact history is disabled
                   there. */ ?>
          <?php /* BROTHERS AND SISTERS ON THE SAME TOUR (19 Oct 2026). A tour is
                   one visit for the family, so the ones waiting here are offered,
                   ticked, and go onto it - started in In progress if they are not
                   there. Full width rather than under the tour box: five columns
                   wrap "Louis Hahn (not in In progress yet)" onto three lines.
                   Named tour_siblings[] so the application's own save, which
                   serialises this form, has nothing to mistake it for. Built by
                   waitlist-modal.js with window.familyTicks(). */ ?>
          <div class="col-12 d-none" id="wl-tour-family"></div>
          <div class="col-12 d-none" id="wl-progress-add">
            <button type="button" class="btn btn-sm btn-outline-primary" id="wl-add-progress"
                    title="For a family you have not reached yet. They start at Identified, and booking a tour above moves them on.">
              <i class="fa-solid fa-list-check fa-sm me-1"></i>Add to In progress
            </button>
          </div>
          <?php /* Did they come? Asked only once the appointment has been and
                   gone with nobody answering it, which is the one way a tour
                   disappears - the same question the work modal asks, and it
                   saves on its own rather than waiting for Save, because it is
                   one click and the answer is not an edit to this form. */ ?>
          <div class="col-12 d-none" id="wl-tour-ask"></div>
          <div class="col-12">
            <label class="form-label">Notes</label>
            <textarea class="form-control" name="notes" rows="2"></textarea>
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-danger me-auto d-none" id="wl-delete">Delete</button>
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="submit" class="btn btn-primary">Save</button>
      </div>
      </form>
      </div>

      <?php /* Loaded when this tab is first shown, not when the modal opens: the
               list reads Kidsoft's own comments for this family, which is a live
               API call, and the editor is opened far more often than the history
               is read. */ ?>
      <div class="tab-pane fade" id="wl-pane-comms" role="tabpanel" aria-labelledby="wl-tab-comms">
      <div class="modal-body">
        <form id="comm-form" class="border rounded p-3 mb-3 bg-body-tertiary">
          <input type="hidden" name="waitlist_id">
          <div class="row g-2 align-items-end">
            <div class="col-md-3">
              <label class="form-label small mb-1">Direction</label>
              <select class="form-select form-select-sm" name="direction">
                <option value="outbound">We contacted them</option>
                <option value="inbound">They contacted us</option>
                <option value="internal">Internal note</option>
              </select>
            </div>
            <div class="col-md-3">
              <label class="form-label small mb-1">Channel</label>
              <select class="form-select form-select-sm" name="channel">
                <option value="phone">Phone</option>
                <option value="email">Email</option>
                <option value="sms">SMS</option>
                <option value="in_person">In person</option>
                <option value="other">Other</option>
              </select>
            </div>
            <div class="col-md-3">
              <label class="form-label small mb-1">When</label>
              <input type="date" class="form-control form-control-sm" name="occurred_at">
            </div>
            <div class="col-md-3">
              <button type="submit" class="btn btn-primary btn-sm w-100">Log contact</button>
            </div>
            <div class="col-12">
              <label class="form-label small mb-1">Summary</label>
              <input class="form-control form-control-sm" name="summary" maxlength="255" required>
            </div>
            <div class="col-12">
              <textarea class="form-control form-control-sm" name="body" rows="2"
                        placeholder="Detail (optional)"></textarea>
            </div>
            <?php /* "Also on the history of" a brother or sister waiting here
                     (19 Oct 2026): one call, one row, shown on each child ticked.
                     The Kidsoft copy stays on this child - see api/comms. */ ?>
            <div class="col-12 d-none" id="comm-family"></div>
          </div>
        </form>
        <?php /* Whether this note will also reach Kidsoft, said BEFORE it is
                 typed rather than after it is saved. Kidsoft creates a child
                 record when an application converts to an enrolment, so for 87%
                 of the queue there is nowhere to copy a note to - and a staff
                 member who assumes it travelled would stop keeping the Kidsoft
                 note they still need. Filled by waitlist.js. */ ?>
        <div id="comm-kidsoft-note" class="small d-none mb-3"></div>
        <div id="comm-list"></div>

        <?php /* Kidsoft's own comments, kept SEPARATE from ours. Most of them are
                 form capture ("Agreed to terms and conditions: Yes") rather than
                 contact history, so merging the two lists would bury what the
                 team actually did behind what a form recorded. */ ?>
        <div id="comm-kidsoft" class="d-none">
          <h6 class="text-muted small text-uppercase mt-4 mb-2">Held in Kidsoft</h6>
          <div id="comm-kidsoft-list"></div>
        </div>
      </div>
      </div>
      </div>
    </div>
  </div>
</div>
