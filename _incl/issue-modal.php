<?php
// REPORT ISSUE - the dialog behind the button at the right of the centre bar.
// (13 Oct 2026)
//
// Rendered by layout_footer() on every screen, for everybody. Behaviour in
// static/js/issues.js, endpoint api/issues.php, saving and the email in
// _incl/issue-reports.php.
//
// THE PROMPTS ARE THE POINT OF THE DIALOG. "It's broken" is the report that
// costs a phone call to turn into something fixable, and the four questions
// below are what that phone call would ask. They sit above the box rather than
// inside it as placeholder text, because a placeholder vanishes at the first
// keystroke - exactly when somebody is deciding what to write.
//
// WHAT IS SENT AUTOMATICALLY IS SAID, in one line under the box. Partly so
// nobody spends a paragraph describing the page they are on, and partly
// because a report carries their recent clicks, and that should not be a
// surprise.

require_once __DIR__ . '/issue-reports.php';   // ISSUE_DESCRIPTION_MAX
?>
<div class="modal fade" id="issue-modal" tabindex="-1" aria-labelledby="issue-title" aria-hidden="true">
  <div class="modal-dialog modal-dialog-scrollable">
    <form class="modal-content" id="issue-form">
      <div class="modal-header">
        <h5 class="modal-title" id="issue-title"><i class="fa-solid fa-bug me-2 text-muted"></i>Report an issue</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <p class="mb-2">Thanks for letting us know. A line or two on each of these makes it much quicker to track down:</p>
        <ul class="small ps-3 mb-3">
          <li><strong>What you were trying to do</strong>, e.g. "offer Monday and Tuesday to a family on the waitlist"</li>
          <li><strong>What happened instead</strong>, with the exact words of any message you saw</li>
          <li><strong>Which child, family, room or day</strong> it involved, if any</li>
          <li><strong>Whether it happens every time</strong>, or only now and then</li>
        </ul>
        <label class="form-label" for="issue-text">What happened?</label>
        <textarea class="form-control" id="issue-text" name="description" rows="6" required
                  maxlength="<?= (int)ISSUE_DESCRIPTION_MAX ?>"></textarea>
        <div class="form-text">The page you are on, your browser and your last few clicks are sent with this, so there is no need to describe those.</div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="submit" class="btn btn-primary" id="issue-send"><i class="fa-solid fa-paper-plane me-2"></i>Send</button>
      </div>
    </form>
  </div>
</div>
