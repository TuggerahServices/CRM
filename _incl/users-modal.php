<?php
// USER ACCESS - who may sign in to the CRM, and as what. (19 Aug 2026)
//
// Rendered by layout_footer() for an ADMINISTRATOR only, on every screen,
// because it hangs off the user menu in the sidebar rather than off a page. Same
// argument as the search palette: chrome, not a screen.
//
// Markup here, behaviour in static/js/users.js, data from api/users.php. The
// endpoint checks the role for itself (auth_require_admin_api) - this file not
// being rendered is a tidiness decision, never the control.
//
// THE LIST IS THE SCREEN. There is no separate "add user" page and no wizard:
// adding somebody is one row of controls at the top of the same list you are
// looking at, because the question an administrator arrives with is almost
// always "who has access", and adding is what they do once they have seen.
?>
<div class="modal fade" id="users-modal" tabindex="-1" aria-labelledby="users-title">
  <div class="modal-dialog modal-lg modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <div>
          <h5 class="modal-title mb-0" id="users-title"><i class="fa-solid fa-key me-2 text-muted"></i>User access</h5>
          <?php /* THE SUBTITLE FOLLOWS THE PANE, because it describes the list
                   and the list is not always what is standing here. Left alone
                   it would sit over the add form saying "who can sign in, and
                   what they can see" - a caption that has aged into something
                   wrong the moment the pane behind it changed. users.js swaps
                   it; the wording for the form is in showAdd(). */ ?>
          <div class="small text-muted" id="ua-subtitle">Who can sign in to the CRM, and what they can see.</div>
        </div>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>

      <div class="modal-body">

        <?php /* TWO PANES, ONE AT A TIME. Adding somebody and reviewing who
                 already has access are different acts: the first is done twice
                 a year, the second is the reason this screen is opened. A form
                 sitting permanently above the list spent the top third of the
                 dialog on the rarer of the two, and put a live text field and a
                 role menu directly above a list of role menus that mean
                 something else entirely.

                 So the list is what opens, with one button, and the form takes
                 the whole body when it is asked for. Panes rather than a second
                 modal: a modal on top of a modal stacks backdrops, moves the
                 focus trap and gives Escape two meanings. */ ?>
        <div id="ua-list-pane">
          <div class="d-flex justify-content-end mb-3">
            <button type="button" class="btn btn-primary btn-sm" id="ua-open-add">
              <i class="fa-solid fa-plus me-2"></i>Add somebody</button>
          </div>

          <?php /* Disabled rows stay in this list rather than disappearing. "Who
                   used to have access" is half of what this screen is opened to
                   check, and removing access is only reversible if the row is
                   still visible. They sort to the bottom. */ ?>
          <div id="users-list" class="users-list">
            <div class="text-muted small py-3">Loading...</div>
          </div>
        </div>

        <?php /* ADDING SOMEBODY IS TYPING THEIR WORK EMAIL. There is still no
                 directory picker - a searchable list of every staff account is
                 a bigger surface than a screen used twice a year needs - but
                 the address IS now checked against Entra the moment it is
                 submitted, and comes back as a person's name.

                 THAT REPLACES THE COST THIS COMMENT USED TO RECORD. A typo used
                 to be a row that read "Never signed in" forever while a real
                 staff member hit the denial page not knowing why; now it is
                 refused while the administrator is still looking at the field,
                 and the name in the list is the fastest proof that the address
                 belongs to the person they meant. See resolve_directory() in
                 api/users.php.

                 The pattern below is a courtesy, not the rule: it saves a round
                 trip on the obvious mistake. The rule is enforced server-side in
                 the add action against USER_EMAIL_DOMAIN, because a browser
                 attribute is not a control. */ ?>
        <div id="ua-add-pane" class="d-none">
          <form id="users-add">
            <div class="row g-3">
              <div class="col-12 col-md-7">
                <label class="form-label" for="ua-email">Work email</label>
                <?php /* THE DOMAIN IS PART OF THE FIELD, NOT A RULE ABOUT IT.
                         It was a sentence beside the label, then a pattern=,
                         then a message underneath - three ways of telling
                         somebody a thing the form could simply not let them get
                         wrong. There is exactly one domain that can ever be
                         added (USER_EMAIL_DOMAIN, measured against all 103
                         accounts in the tenant), so it is printed as part of the
                         control and only the name is typed.

                         type="text", not "email": what is typed here is no
                         longer an address on its own, and type="email" would
                         refuse "emily" as malformed.

                         THIS IS NOT THE CONTROL EITHER. users.js joins the two
                         halves and posts a whole address, and
                         directory_check() checks the domain server-side exactly
                         as before - the input group cannot be the guard, it
                         just means nobody has to be told. */ ?>
                <div class="input-group">
                  <input type="text" class="form-control" id="ua-email" name="email" required
                         autocomplete="off" spellcheck="false" placeholder="name">
                  <span class="input-group-text">@<?= htmlspecialchars(USER_EMAIL_DOMAIN, ENT_QUOTES) ?></span>
                </div>
                <?php /* WHO THE ADDRESS BELONGS TO, filled in by users.js from
                         /api/users?action=lookup. Reserved here rather than
                         inserted on demand so the form does not jump as the
                         answer arrives. */ ?>
                <div class="form-text" id="ua-found" aria-live="polite"></div>
              </div>
              <div class="col-12 col-md-5">
                <label class="form-label" for="ua-role">Role</label>
                <select class="form-select" id="ua-role" name="role">
                  <?php foreach (USER_ROLES as $v => $l): ?>
                  <option value="<?= htmlspecialchars($v, ENT_QUOTES) ?>"<?= $v === 'supervisor' ? ' selected' : '' ?>>
                    <?= htmlspecialchars($l, ENT_QUOTES) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
            </div>

            <?php /* THE ROLE HINT SITS ABOVE THE CENTRES, because it points at
                     them: "Only the centres assigned below." was printed
                     underneath the boxes it was calling "below". Moving the line
                     was the fix rather than rewording it - a sentence about the
                     role belongs next to the role, and it now reads in the order
                     the form is filled in. */ ?>
            <div class="form-text mt-2" id="ua-hint"></div>

            <?php /* The centre boxes appear only for a Supervisor, because for
                     the other two roles there is nothing to choose - they see
                     every centre by definition, and a disabled row of ticked
                     boxes would imply otherwise. Filled and shown by users.js. */ ?>
            <div class="mt-3 d-none" id="ua-centres-wrap">
              <label class="form-label d-block">Centres</label>
              <div class="d-flex flex-wrap gap-3" id="ua-centres"></div>
            </div>

            <?php /* Right-aligned, and Add is the rightmost - the same order
                     and the same corner as every dialog's confirm button, which
                     is where the hand is already going. Cancel goes back to the
                     list rather than closing the dialog, so the list they were
                     looking at is still there.

                     Add starts DISABLED and is switched on by the directory
                     check in users.js. It is not the control - api/users.php
                     asks the same question again - it just stops somebody
                     filling in a form that was always going to be refused. */ ?>
            <div class="d-flex justify-content-end gap-2 mt-4">
              <button type="button" class="btn btn-outline-secondary" id="ua-cancel">Cancel</button>
              <button type="submit" class="btn btn-primary" id="ua-submit" disabled>Add</button>
            </div>
          </form>
        </div>
      </div>

    </div>
  </div>
</div>
