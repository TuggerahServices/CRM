# Report Issue

`_incl/issue-reports.php`, `_incl/issue-modal.php`, `api/issues.php`, `static/js/issues.js`,
table `issue_reports`. Asked for by Broc, 13 Oct 2026.

A **Report Issue** button (`fa-bug`) sits at the right-hand end of the centre bar on every
screen. It opens a dialog that asks four questions in plain words (what were you trying to do,
what happened instead, which child/family/room/day, does it happen every time), takes one
free-text answer, saves it, and emails it to **`ISSUE_REPORT_TO`** (`broc@littlemiracles.com.au`).
Every signed-in user can send one, whatever their role.

## The row is the record, the email is the notification

The row commits first and the email's outcome is written back onto it: `emailed_at` on success,
`email_error` (plus a PHP error log line) on failure. **The person who sent it is told it was
received either way, because it was.** Same order as the Kidsoft comment copy in `comments.php`.

The email is sent through `graph_send_mail()` from `noreply@` with **`replyTo` set to the
reporter**, so answering them is pressing Reply. `replyTo` is an optional argument added to
`graph_send_mail()` for this; the birthday email does not pass it.

**A REPORT HAS NO NUMBER** (Broc, 13 Oct 2026). The first version titled the email
"Attendances issue #1" and footed it with the row id; neither the email, its subject nor the
reply to the browser carries one now. `issue_reports.id` is the table's key and is never shown. A
report is identified by **who sent it and when**, and the email states both.

There is **no screen for reading reports**. The email carries everything; the table is the
backstop when an email went astray:

```sql
SELECT created_at, staff_email, page_url, description, emailed_at, email_error
FROM issue_reports ORDER BY created_at DESC;
```

**The first row is the end-to-end test sent while building it** (13 Oct 2026), labelled TEST.

## What a report carries

| Field | From | Why it is trusted (or not) |
|---|---|---|
| who, role, browser | the session and the request | columns, read server-side |
| page | `location.pathname + search` | only a path on this site is kept; anything else becomes `/` because it turns into a link in an email |
| centre, year on screen | `data-centre` / `data-year` on the button, written by `layout_centre_bar()` | a supervisor with one centre has no tabs to read it from, and several screens have no year stepper |
| title, window, screen size, trail | the browser | `context` JSON, cut to known keys and bounded sizes by `issue_context_clean()`, never used for anything but reading |

**No centre guard on the endpoint, and that is not an omission:** nothing in it reads a centre,
room or family. The centre in a report is the text of what was on screen.

## The trail

`issues.js` records the last 40 things done **in that browser tab**, in `sessionStorage` so it
survives moving between screens and dies with the tab:

- **page** opened (title and path)
- **click** on a link, button, cell, header or checkbox: its visible text (or tooltip, for an
  icon-only button), the dialog it was in, and `tag#id` or the first non-Bootstrap class
  (`button.offer-btn`), which is what finds the handler in the page script
- **change** of a select (the option chosen), checkbox or radio
- **api**: every call to `/api/`, its method, path and `action`, and the HTTP status or the
  `api_fail()` message when it was refused, because that is the sentence somebody saw in a red
  toast and is about to paraphrase
- **toast** shown, and **error** (uncaught script errors, with file and line)

Repeats collapse into one line with a count (search fires a call on every pause in typing).
Entries older than an hour are not sent. Times are `Date.now()` differences, so no Sydney
wall-clock string is ever parsed.

**What is never recorded: anything typed.** A text field changing is recorded by its label alone,
and an `/api` call by its path and `action` only - search sends phone numbers and names in `q`.
The dialog says in one line that the page and recent clicks are attached, so it is not a
surprise.

**`issues.js` must load directly after `app.js`**, before every page script: it wraps
`window.toast`, and `dashboard.js` and `marketing.js` copy that function into a local at load.

## The centre bar now always renders

Report Issue lives in the centre bar, so the bar renders on every screen even when there are no
tabs to show. **A single-centre supervisor used to get no bar at all; they now get the row with
the button alone** - about 48px off the top of every screen, the cost of the button being in the
same place for everybody. With tabs the bar is the same height as before (`.cb-row`'s min-height
is a tab's height). Below `sm` the label hides and the button is the icon, named by its tooltip.
