<?php
/* ONE ENROLLED CHILD (6 Nov 2026). Filled by static/js/child-modal.js from
   /api/child, which builds it in _incl/child-profile.php.

   IT REPLACES THE PLACEMENT EDITOR FOR A CHILD KIDSOFT KNOWS. That editor showed
   a name, a date of birth, five ticks and a note, so it answered "what have we
   flagged" and nothing a person about to ring the family needs, and it was rarely
   opened. The flags and the note are still here and still save through
   /api/board `save_placement`; everything above them is read, never typed.

   THE FORM IS THE .modal-content so the footer's Save submits it. Nothing in the
   body is another form: every button there is type="button" and opens something
   else (an application, a work item, a brother or sister). A provisional child -
   one Kidsoft has never heard of - still opens #place-modal, because nothing
   here could be read for them. */
?>
<div class="modal fade" id="ch-modal" tabindex="-1" aria-labelledby="ch-title">
  <div class="modal-dialog modal-lg modal-dialog-scrollable">
    <form class="modal-content" id="ch-form">
      <div class="modal-header align-items-start">
        <div class="min-w-0">
          <h5 class="modal-title mb-0" id="ch-title">Child</h5>
          <div class="ch-sub" id="ch-sub"></div>
        </div>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body" id="ch-body"></div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Close</button>
        <button type="submit" class="btn btn-primary d-none" id="ch-save">Save</button>
      </div>
    </form>
  </div>
</div>
