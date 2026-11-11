# Auth, roles, sessions, security, and operations

Governs `_incl/auth.php`, `users.php`, `users-modal.php`, `entra.php`, `entra-config.php`,
`graph.php`, `csrf.php`, `api.php`, `cron-nightly.php`, `auth/*.php`, `api/users.php`,
`static/js/users.js`, `errors/`, and the nginx vhost.

## Auth is LIVE

Entra OIDC SSO, working since 24 Jul 2026.

Redirect URI: `https://crm.tuggerahservices.com.au/auth/callback` (Cloudflare fronts 443;
**the origin listens on 53433**).

---

# ROLES AND CENTRE SCOPE (19 Aug 2026)

Three roles, in `app_users`. **The role decides WHERE, not WHAT.**

| Role | Sees | Can do |
|---|---|---|
| `administrator` | every centre | everything, **plus User access** |
| `manager` | every centre | everything else an administrator can |
| `supervisor` | **only their assigned centres** | the full job inside them |

A supervisor makes offers, logs contact notes, claims work items and edits sub-quotas exactly as
a manager does. **A read-only supervisor was considered and rejected**: somebody who can see a
family waiting and cannot act rings a manager to do it, which is more manual work, not less --
the opposite of the point of this tool.

## The allowlist is a TABLE now, not a config array

`allowed_user_emails` and `allowed_group_ids` are **gone from `_incl/entra-config.php`**.
`auth_is_allowed()` asks one question: is there an ACTIVE `app_users` row for this email.

**The Entra group door went with it, deliberately.** A group grants access with **no role and no
centre**, and every user now has to resolve to both. It also put the decision in Azure with
whoever administers the tenant, who is not the person who knows which staff should be reading
children's data. Auto-provisioning a group member as an unscoped supervisor was considered and
refused: Entra membership would silently open this app and nobody here would see it happen.

**An administrator adds the email FIRST; the person signs in afterwards.** There is still no
directory picker - a searchable list of every staff account is a bigger surface than a screen
used twice a year needs - but since 20 Aug 2026 the typed address is **checked against Entra
before a row is written**, and an address the directory does not know comes back with the accounts
it was nearly, so the cost that used to carry is gone. See the next section.

## THE ADDRESS IS A WORK ADDRESS, AND IT IS RESOLVED TO A PERSON (20 Aug 2026)

Two rules on the one field, both enforced in `api/users.php` under `case 'add'`.

### `USER_EMAIL_DOMAIN` - littlemiracles.com.au only

**Measured before it was hard-coded**: all **103** accounts in the tenant have a
`userPrincipalName` at that domain, so the rule refuses nothing that exists. Sign-in is Entra
against this tenant, so an address anywhere else is a row **no token can ever match** - it sits
in the list looking like access and is not.

**It is not the control and must not be counted as one.** `auth_is_allowed()` still admits an
ACTIVE row and nothing else; this only stops such a row being created.

**THE DOMAIN IS PART OF THE FIELD, NOT A RULE ABOUT IT.** It went through three forms first --
a sentence beside the label, a `pattern=`, then a message underneath - all of them ways of
telling somebody a thing the form could simply not let them get wrong. It is now a Bootstrap
`input-group`: only the name is typed, `@littlemiracles.com.au` is fixed text beside it, and
`users.js` joins the halves (`addressFor()`). `type="text"`, not `"email"` - "emily" on its own
is not an address and would be refused as malformed.

**Still not the control.** A whole address is POSTed and `directory_check()` checks the domain
server-side exactly as before. The input group only means nobody has to be told.

- **A whole address typed or pasted into it is trimmed at the `@`** (`localPart()`), because doing
  that out of habit is the obvious mistake and would otherwise build the address twice over. One
  at another domain is trimmed the same way and looked up here, which answers honestly either way.
- **The trim is READ-ONLY, and the field is tidied on `blur` instead.** Rewriting the input on
  every `input` event was the first version and it broke the very case it was for: typing
  `emily@littlemiracles.com.au` one key at a time lost the `@` the instant it was pressed, and
  every character after it landed on the end of `emily` - `emilylittlemiracles.com.au`. Caught by
  driving the keyboard, not by pasting.

Domain ownership is **not** read from Entra: `Domain.Read.All` is not granted (`/domains` answers
403) and one constant beats a tenant permission for a value that changes when the company is
renamed.

### `graph_find_user()` - who is this?

`_incl/graph.php`, app-only, `User.Read.All`. **That permission was already granted and this doc
and `entra-config.php` both said it was "no longer used"** - true of the groups-overage fallback
it was added for, and read as true of the permission. Measured 20 Aug 2026: `entra_app_token()`
comes back carrying `roles ["User.Read.All","GroupMember.Read.All"]`, `GET /users/<upn>` answers
200 for a real account and 404 for one that does not exist, and **nothing had to change in
Azure.** Re-measure the same way before writing that it is gone.

- **Two lookups, in order.** `GET /users/<address>` matches the **userPrincipalName** - the
  address a sign-in later presents as `preferred_username`, which is what `auth_is_allowed()`
  compares against. A 404 falls back to `$filter=mail eq '...'` for the person whose mailbox
  address is not their sign-in name. **The UPN is what gets stored**, so the row matches the
  token when they arrive.
- **A `mail` filter matching two accounts is treated as NOT FOUND.** Guessing is not something to
  do when the outcome is who reads children's personal data.
- **Refused on add: no such account, `accountEnabled = false`, and `userType = Guest`.** A
  leaver's account is switched off in Entra long before anybody comes here to remove the row, and
  a guest's UPN is rewritten into the tenant so the domain rule alone would not catch one.
- **UNREACHABLE IS NOT NOT-FOUND, and the whole return shape exists to keep them apart.** A 404 is
  Entra answering; a timeout or a dead certificate is US being broken. The add goes through
  nameless in that case and **the message says so**, because refusing to grant access when our own
  outbound call failed is the tool getting in the way of the one job this screen has.
- **The modal backfills on open** (`users_backfill_names()`, capped at 10 rows): any row that
  still has no name is looked up once and written back. `not_found` rows are **deliberately left
  nameless** - that is the mistyped address this screen exists to make visible, and a placeholder
  would hide it. `last_seen_at` is never touched, so "Never signed in" keeps meaning what it says.

**What this replaced:** a typo used to be a row that read "Never signed in" forever while a real
staff member hit the denial page not knowing why. It is now refused while the administrator is
still looking at the field, and the name in the list is the proof the address belongs to the
person they meant.

### `directory_check()` and the `lookup` action - the field answers as it is typed

**One function decides, and it has two callers that must never disagree.** `directory_check()` in
`api/users.php` returns one of `ok` / `unreachable` / `no_account` / `disabled` / `guest` /
`bad_domain` / `bad_email` with the sentence to show. `case 'lookup'` (a GET, administrators only,
debounced 400ms by `users.js`) hands it to the add form, which names the person under the field
and switches its own `Add` button on or off; `case 'add'` runs **the same function again** before
writing anything.

**The button is not the control - the POST is**, exactly as the modal not rendering for a
non-administrator is not the control while `auth_require_admin_api()` is. Two copies of these
rules would drift, and the direction they would drift is a lit button over a refusal. Verified by
POSTing each refusal past the disabled button with curl.

- **`unreachable` ENABLES the button.** Same reasoning as everywhere else here: Graph being down
  says nothing about the address.
- **`lookup` also reports whether the address is ALREADY on the list**, with its role and whether
  access is off. Not a refusal - re-adding is how a role is changed and how a leaver is let back
  in - but it is what stops `Add` looking as though it did nothing. A **break-glass** row is the
  one case that switches the button off, because `add` refuses it outright.
- **Out-of-order answers are dropped** (`lookupSeq`): a slow lookup for a half-typed address must
  not overwrite the answer for what is in the field now.
- **A NAME TYPED BEFORE THE LIST LANDS IS HELD, NOT REFUSED.** The domain the field's address is
  built from arrives with `action=list`, so `addressFor()` answers `''` until it does - and asking
  with that got back **"That does not look like an email address"**, a refusal about our own load
  order shown to somebody who had typed a perfectly good name. The debounce now fires `lookupNow()`,
  which **builds the address when the timer fires rather than when it was set**: with no domain yet
  it says it is checking and asks for real the moment `load()` publishes one. If that load FAILS,
  `DOMAIN_FAILED` is what the field reads - the flag exists because the load usually fails
  **before** anybody has typed, so saying it once in `.fail()` would leave the next thing they type
  holding on "Checking the staff directory..." for good.

### "DID YOU MEAN?" - the refusal names the way forward (20 Aug 2026)

**"There is no Little Miracles account for cath@littlemiracles.com.au" is true and it is a dead
end.** It says what is wrong and nothing about what is right. The case that prompted this: Cath
said her address was `cath@`, it is `Cathryn.Montgomery@`, and the only way to find that out was
to sign in to the Microsoft 365 admin portal - a second system, to answer a question this app had
just asked Entra twice in the previous half second.

So `directory_check()` asks the second question too. On `no_account` only, `graph_suggest_users()`
returns **at most three** accounts the typed address was nearly, and `users.js` renders them as
**buttons that fill the field** - text would just be re-typed, and re-typing an address is the
failure being fixed.

**THE WHOLE TENANT IS ONE PAGE, MEASURED**: 103 accounts, `$top=999`, no `@odata.nextLink`,
130-160ms. That is why matching is done in PHP over the whole list rather than pushed into a Graph
`$filter` or `$search`: `startswith` finds "cathryn" for "cath" and does **not** find it for
"catherine" or "kathryn", which are the same mistake. `graph_get()` accepts a whole URL so the
loop can follow a `nextLink` if the tenant ever outgrows one page, and logs if it runs out of
pages rather than silently returning a short list.

**Nothing is stored.** The list is held in a static for the life of the one request and thrown
away. A saved copy of the staff directory is a copy that drifts, and this screen is opened twice
a year. Only the `no_account` path pays for it.

**It is not a directory picker** - the thing this doc has twice said not to build. Three names, only
after an address has already failed.

**Only accounts that could actually be added are offered.** Disabled and guest accounts are
skipped, because `directory_check()` refuses those: a suggestion that turns into a second refusal
when it is taken is a dead end wearing the clothes of a way forward. There are 24 disabled
accounts in the tenant and several share a first name with somebody still working here.

**Every threshold in `graph_name_score()` was run against all 103 real accounts, and each one is
there because a looser version suggested somebody else's colleague:**

| Rule | Score | Why it is that tight |
|---|---|---|
| typed is a prefix of a name | 0.90 | "cath" -> "cathryn". The case this exists for. |
| `levenshtein` <= 1 under six letters, <= 2 at six or more | 0.80 | Two edits on a four-letter word is half the word: it matched "park" for "sara" and offered the Niagara Park mailbox to somebody typing a name. |
| same first four letters, lengths within two | 0.78 | "catherine" -> "cathryn", "ashley" -> "ashleigh": a name heard rather than spelled. **Without the length guard "emilymerjane" scored on "emilykite"** - a different person. |
| under four characters typed | prefix only | "mel" or "bec" scored by similarity against a hundred names is a fistful of strangers, and a confident wrong suggestion is worse than the honest "no such account". |

Threshold 0.75, ties broken by the **shorter** address so `tuggerah@` comes before
`tuggerahdirector@`.

**A name is matched by more than its address.** `graph_match_keys()` offers the local part whole,
its dot-separated parts, the display name whole and each of its words - so a **surname alone finds
somebody**, which is how you look a person up when the first name is the part you are unsure of.
Eight of the 103 accounts are `firstname.lastname` and the other 95 are a bare first name, so no
single shape would reach both.

**`case 'add'` says the same thing.** Its refusal appends "Did you mean Cathryn Montgomery
(cathryn.montgomery@littlemiracles.com.au)?" from the same `suggestions`, so the POST that arrives
without the buttons gets the same answer the field gave - the same reason `directory_check()` has
one body and two callers.

## Scope is RE-READ EVERY REQUEST, never stored in the session

`$_SESSION['user']` carries identity only - oid, name, email. No role, no centre list.
`auth_require()` and `auth_require_api()` call `auth_access_state()` on **every** page and
endpoint, so removing somebody or moving them between centres takes effect on their **next
click**, not their next sign-in (which on silent SSO with a 24-minute idle window could be days).
Cost is one indexed lookup, cached for the life of the request.

Two states get their own page, and they are **not** `auth_deny_unauthorised()`:

- `auth_deny_revoked()` - access removed while signed in. That page's "try a different account"
  advice is right for somebody who never had access and **wrong** here; these users had it a
  moment ago and picking another account cannot bring it back. Destroys the local session first.
- `auth_deny_no_centre()` - a supervisor with no centre assigned. Says the one thing that is
  actually wrong instead of refusing them a centre at a time. **Not** a sign-out: an
  administrator assigning a centre fixes it in seconds, so the button reloads.

## THE CENTRE CHECK IS SERVER-SIDE OR IT IS NOTHING

Hiding the centre bar decides what a supervisor is **offered**. `/api/waitlist?centre_id=3` is
what a supervisor is **served**. Every endpoint taking a centre id goes through
**`api_require_centre($id, $allowGroup = false)`** in `_incl/api.php`.

It replaced `if (!centre_get($id)) api_fail('Unknown centre.')`, which asked only whether the
centre EXISTS - the whole check on every endpoint in the app until roles arrived.

- **"Unknown centre" answers BOTH cases on purpose.** A centre that is not theirs and an id that
  does not exist read identically, so the reply never confirms which other centres are real.
- **Many actions carry no centre id at all** - they name a room, a placement, a waitlist
  application, an offer, a work item or a comms entry. For those the guard runs **after** the
  lookup, off the row's own `centre_id`: `api_require_centre((int)$row['centre_id'])`.
  `api/work.php` wraps this as `work_item_scoped()` because nine actions there address an item by
  id and the ninth is the one somebody forgets.
- **Group-shaped reads** (`board?action=overview`, `waitlist?action=group`,
  `tenure?action=overview`, `dashboard?action=load` with no centre, `support-plans?action=list`
  with `centre_id=0`) require `user_all_centres()` and answer **"Choose a centre."** otherwise.
  Verified end to end on `support-plans` with a real supervisor session (Tuggerah), 10 Sep 2026:
  `centre_id=0` -> "Choose a centre.", `centre_id=4` -> their own child, `centre_id=1` ->
  "Unknown centre.", and `board?action=hold_release` on a Kanwal `day_holds` row -> "Unknown
  centre." with the row left open. `support_plans_list()` re-reads `user_centre_ids()` for
  itself as well, so the group path is scoped rather than merely refused.
- **`rooms?action=centres`** is narrowed with `centres_visible()`: it is a LIST of centres rather
  than a read scoped by one, and would otherwise hand over six centres' names and room counts.
- **`sync_centres` and `move_centre` are manager-and-above.** Which centres exist, and the order
  of a tab strip a supervisor does not have, are organisation-level facts. Renaming a centre is
  scoped - it is their centre's short name.

## What a supervisor sees of OTHER centres

Decided by Broc, 19 Aug 2026. Two rules, and they pull in opposite directions on purpose.

### Search stays GROUP-WIDE for everybody

Every other screen is pinned to one centre. Search is not, because it exists for the call that
starts the day: a parent rings and all you have is a number, and **a parent ringing the centre
nearest their work about a child enrolled somewhere else is the ordinary case.** Scoping it
would leave whoever answered the phone unable to say anything at all.

`search_scope_results()` in `_incl/search.php` trims instead. For a child **outside** their
centres a supervisor gets the **name, the centre, and whether they are enrolled** - enough to
say "they are at Niagara Park, let me put you through". No DOB, no guardian phone or email, no
room, no days, no queue position, no history, and **`centre_id` is nulled so the card renders as
text rather than a link** into a centre the endpoint would refuse anyway.

**`enrolled` is deliberately left TRUE.** That the child is enrolled somewhere is the answer the
call asked for, not a detail about them.

**A card can span centres**, so this trims per ROW, not per card: a Tuggerah supervisor looking
at a family enrolled at both sees the Tuggerah half in full and the rest as a name.

### "Also waiting at" and "Sibling of" stay, with the CENTRE NAMED ONLY

Both facts are about a family **already in the supervisor's own queue**, and both change how that
family ranks. Hiding them would leave a supervisor's ranking disagreeing with a manager's on the
same row - and a row that jumps the queue for no stated reason is exactly what
`priority_reasons()` exists to prevent.

- `waitlist_elsewhere()` sets `scoped => false` and empties `queue_position`, `days`,
  `date_added`, `has_offer`. **`days` stays an ARRAY when emptied, never null** - `app.js` does
  `o.days.join(',')` in two places and a null there is a thrown TypeError that takes the whole
  column with it. `waitlistCentreChip()` renders an unscoped entry as a `<span>`, not an `<a>`.
- `sibling_matches_for_centre()` nulls the sibling's `name`, `kidsoft_child_id` **and `key`**.
  The key is the folded `first|last` (`"azula|morante"`), so leaving it behind hands over the
  very name that was just removed, in a field nothing reads. Both of its uses run before the
  trim. `priority_reasons()` then prints **"Sibling at Kanwal"** instead of a name.
- **The names in a reason open the child's dialog only where this user may** (6 Nov 2026):
  `priority_reason_parts()` attaches a ChildID to a named sibling (already scoped above) and to
  an "Already in care at" centre only when `user_can_centre()` says so, and `/api/child` runs
  `api_require_centre()` regardless. The raw in-care match is unset before the row is sent.

## BREAK GLASS

`USER_BREAK_GLASS` in `_incl/users.php` - currently just `broc@`. Listed emails are admitted as
**administrators regardless of what their row says, or with no row at all.**

The whole allowlist now lives in a table this app can edit, so one bad click could lock every
person out of an app with no console, no framework CLI and no version control; the recovery would
be a hand-written UPDATE by whoever has the database password, at whatever hour it happened.

It is a **floor, not a grant**: it cannot admit anybody who is not already in Entra and past the
OIDC checks. Keep it to accounts that could fix the database anyway.

**It overrides the ROW, and that half was missing when it was first written** (found and fixed
the same day). The constant promised "even with a row that is disabled" while the code only
handled the NO-ROW case - so a break-glass account demoted to `manager`, or set `active = 0`,
was simply a manager or simply locked out. **Verified by demoting the row and watching
`/api/users` answer 403 to the one account that exists to make that impossible.** An escape hatch
has to be the thing a bad row cannot reach.

`api/users.php` refuses to edit a break-glass row at all, and the modal draws it with no controls
and a "Permanent" badge: editing it would change the row, appear to work, and leave the person an
administrator anyway.

## Guards in `api/users.php`

- **`guard_last_admin()`** - refuses to demote or disable the last ACTIVE administrator.
  `USER_BREAK_GLASS` is **deliberately not counted**: it is a floor under a mistake, not a
  licence to make one. Counting it would let an administrator empty the table and turn the app
  into a one-person tool without ever saying so.
- **You cannot change your own role or remove your own access.** Not a security control --
  `guard_last_admin()` covers what matters - but it is almost always a misclick, and the effect
  is the modal vanishing mid-task.
- **BOTH OF THE ABOVE NOW LIVE IN `guard_role_change()`, BECAUSE `add` USED TO WALK PAST THEM**
  (found and fixed 20 Aug 2026). Typing an address that already has a row is not an insert:
  `user_add()` **UPDATEs that row's role and re-enables it**. So the add form was a second,
  unguarded way to demote the last administrator or to demote yourself - the first of which
  leaves nobody able to grant access, on an app with no console and no version control. `add`
  also refused nothing on a **break-glass** row, which `target_user()` has always refused: the
  row would change, the modal would show `supervisor`, and `user_load()` would go on forcing
  `administrator` - a screen disagreeing with the app, which is exactly what refusing to edit
  that row exists to prevent. Any future action that writes a role must go through
  `guard_role_change()`.
- **A supervisor must have at least one centre**, refused on add and on role change, because a
  supervisor without one signs in successfully and lands on a page explaining they have nowhere
  to be.
- **Adding somebody has THREE outcomes, not two** (`user_add()` returns
  `created` / `restored` / `updated`). Any existing row used to answer `restored`, so re-typing
  the address of somebody who had never lost access was said back as "Access restored for Emily
  Kite" - reading as though something had been wrong with her account. Whether the row was OFF
  is what separates them. `updated` audits as `user.role`, because an add form landing on a row
  somebody already has **is** a role write.
- **Rows are DISABLED, never deleted.** `audit_log` points at an email forever, so a deleted user
  is an audit trail that cannot be resolved to a person. Disabled rows stay listed: "who used to
  have access" is half of what the screen is opened to check, and removal is only reversible if
  the row is still visible.
- Every action is audited (`user.add`, `user.role`, `user.centres`, `user.remove`,
  `user.restore`) with before-and-after.

## The modal is TWO PANES, and the list is the one it opens on (20 Aug 2026)

`#ua-list-pane` and `#ua-add-pane`, swapped by `showAdd()` in `static/js/users.js`. Never both.

Adding somebody is done perhaps twice a year; "who has access" is the question people arrive
with. A form standing permanently above the list spent the top third of the dialog on the rarer
act, and put **a live role menu directly above a list of role menus that mean something else** --
which is how the wrong person's access gets changed. One `Add somebody` button opens the form
over the whole body; `Add` (on success) and `Cancel` put the list back.

- **Panes, not a second modal.** A dialog on top of a dialog stacks backdrops, moves the focus
  trap and gives Escape two meanings.
- **Opening the form always starts blank**, and opening the dialog always lands on the list.
  Handing back a half-typed address from a minute ago is worse than retyping one here.
- **A refusal stays on the form with the address still in the field**, so it can be corrected;
  only a success returns to the list, which is where the new row is.
- **The subtitle follows the pane.** It describes the list, and left alone it sat over the add
  form still saying "who can sign in, and what they can see" - a caption that had aged into
  something wrong the moment the pane behind it changed.
- **There is no modal footer.** The `Close` button and the "changes take effect on their next
  click" line were both removed 20 Aug 2026. The header's `btn-close` already dismisses the
  dialog, and `Cancel` is the button that matters inside the form.

## The centre TABS disappear at ONE centre

`layout_centre_tabs()` returns early below two choices. A single-centre supervisor would otherwise
get a strip of one permanently-active tab that does nothing when clicked - a control that cannot
change anything. A **two**-centre supervisor keeps the tabs, with their two and no "All centres".

**The bar itself now always renders** (13 Oct 2026): it carries the Report Issue button at its
right-hand end, so a single-centre supervisor gets the row with the button alone. That used to be
a row won back for the grid, and it was given up so the button is in the same place for everybody.
See [issue-reports.md](issue-reports.md).

`centre_current()` forces `$allowAll = false` for anybody without a group, and a centre they may
not open is treated as **not asked for** (never written to the session), so a link pasted from a
colleague lands them somewhere sane. Their own first centre is returned as a **choice**, not via
`centre_fallback()` - marking it as a fallback would send `centre=0` down every sidebar link, a
value they are not allowed to hold, re-resolved on every navigation and never remembered.

`index.php` forwards their own centre at the front door instead of `centre=0`, for the same
reason.

## THE NIGHTLY JOBS ARE NOT A PERSON

`user_is_system()` returns true when `PHP_SAPI === 'cli'`, and short-circuits `user_all_centres()`
and `user_can_centre()`.

Without it `cron-nightly.php` - which has no session and never will - would resolve to the
narrowest role with no centres, and `sibling_matches_for_centre()`, `waitlist_elsewhere()` and
`search_scope_results()` would **quietly trim everything it touched, on a job nobody watches,
with no error to notice.**

**`PHP_SAPI` is the test because it cannot be reached from a request.** This vhost is php-fpm, so
every HTTP request is `fpm-fcgi`; `cli` means somebody with a shell ran it, and they had the
database password anyway. A header, an env var or a caller-set constant could each be set by the
wrong thing. It is **not** a web bypass: `auth_require()` runs first and guarantees a session
user.

## Session hardening

All three in `auth_boot()` / `auth_handle_callback()`.

### `session.use_strict_mode` is forced on in code

php.ini has it **Off**, which means PHP accepts a session id it never issued and opens a session
under it - **the fixation primitive.** The vector is real here, not theoretical: **cookies are
scoped by DOMAIN, not port or subdomain**, and this box serves a dozen apps under
`*.tuggerahservices.com.au`.

Set via `ini_set` in the app rather than php.ini, for the same reason the CSP lives in this vhost:
**the other apps on this box have not been audited.**

### STRICT MODE ALONE DID NOTHING, because the session store was shared

"An id PHP issued" is decided by the files handler asking exactly one question: **does
`sess_<id>` already exist in `save_path`?** The default `save_path` on this box is
`/var/lib/php/sessions`, mode **1733** - world-writable, shared by every PHP app here.

**Verified directly: with strict mode ON, an id this app never minted was ACCEPTED and the file's
contents were read straight into `$_SESSION`.** So any local account, or any other app on the box,
could have dropped in a file carrying a populated `$_SESSION['user']`, set the cookie, and been
inside this app's children's data **with no Entra sign-in at any point.**

`auth_boot()` now points `session_save_path()` at **`/var/lib/crm-sessions`** (0700
www-data), which is what makes the previous section mean what it says.

**Moving it costs the Debian sweeper.** `phpsessionclean` reads `save_path` out of php.ini and
cannot see a runtime `ini_set`, and php.ini ships `session.gc_probability = 0` - which is *why*
Debian needs that timer. Both are therefore re-enabled in `auth_boot()` (probability 1, divisor
100). **Without them these sessions would never expire and the 24-minute idle window silently
becomes forever. If you ever change `save_path` again, change the GC settings in the same breath.**

### The JWKS cache moved out of `/tmp` for the same reason

php-fpm runs `PrivateTmp=no`, so `/tmp/entra-jwks-<tenant>.json` sat at a guessable path in a
directory every account on the box can write - **and that file is what answers "is this signing
key ours".** Never a one-step break (forging a token also needs the token endpoint's TLS response),
but **cached security material does not belong there.** Same directory, 0600.

### `session_regenerate_id(true)` on successful sign-in

After the state/nonce checks and the allowlist, **before `$_SESSION` carries any identity.**
Without it the pre-login id survives through login, the other half of the same attack. **There was
none anywhere in the codebase before this.**

### The cookie is `lm_crm`, not `PHPSESSID`

**This is security by obscurity and is labelled as such in the code.** It stops the laziest
scanners and nothing else, since PHP is identifiable from headers and behaviour anyway. It is there
because it costs one line. **Do not count it as a control.**

`auth_logout()` reads `session_name()`, so it follows automatically. **Changing it signs everybody
out once.**

### Already correct - do not regress

`httponly`, `secure` and `samesite=Lax` are set **explicitly** in `auth_boot()`, overriding php.ini
defaults that are all Off.

## Where a user comes back to after sign-in

**Judged in ONE place, `auth_safe_return()`.**

The old test - starts with `/`, does not start with `//` - was duplicated in `auth/login.php` and
`auth_handle_callback()`, and both missed the same thing: **browsers normalise backslashes in the
authority position, so `/\evil.example` is fetched as `//evil.example`** - somebody else's server,
wearing our domain in the address bar until the redirect fires. **On a sign-in flow that is how a
convincing credential-phishing page gets its provenance.**

Both slash characters are now treated identically, control characters and whitespace are refused,
and **the value is re-checked on the way OUT of the session as well as on the way in** - that is
the one place it becomes a `Location:` header.

## A refused staff account is not a sign-in failure

`auth_deny_unauthorised()` has its own page. **It names the account that was refused**, because
silent SSO means nobody chose it - so "you are not allowed" with no name attached leaves a user
unable to tell a policy decision from having landed on the wrong identity, **which is the commonest
cause.**

Its one button is `/auth/login?switch=1`, which clears the local session and sends
`prompt=select_account`. **A plain `/auth/login` there is an infinite loop**, because silent SSO
hands back the very account just refused.

**No support email address is printed:** the team is small, they know who to ask, and an address
baked into a page is wrong the day the admin changes.

`auth_fail()` stays **separate** for genuine failures (bad state, expired code), where "try again"
is the right advice - and on the denial page it is exactly the wrong advice.

## nginx EATS 4xx BODIES - HTML as well as JSON

Everything runs with `fastcgi_intercept_errors on`, so **a 4xx from PHP has its body DISCARDED**
and `errors/error.php` substituted.

Measured 6 Aug 2026: **every outcome of `/auth/callback`, including a real account being refused,
reached the browser as the generic "Not allowed".** The message `auth_fail()` had been writing
since July **was never once displayed.**

The vhost now has a `location ~ ^/auth/[a-z]+\.php$` with `fastcgi_intercept_errors off`, placed
**BEFORE the `php-fpm.conf` include because regex locations are tried in source order.**

**Any future page that must explain itself in a 4xx body needs the same exemption, or it is writing
into the void.** The same trap is why `_incl/api.php` uses `api_fail()` (HTTP 200 + `ok:false`) --
see the API shape in the root file.

## THE WEB ROOT PUBLISHES ANYTHING YOU LEAVE IN IT

`location / { try_files $uri $uri/ @php; }` serves any file that exists **before the request ever
reaches PHP** - no auth, no session, no `auth_require()`.

**`_incl/` is the exception**: `location ~ ^/_incl(/|$) { return 404; }` (snippet
`incl-block.conf`). **That is why the docs in `_incl/docs/` are safe and why the branding assets
live there.**

### The perimeter is the WAF plus the firewall, and that is a deliberate choice

ufw/nftables admit port **53433 only from Cloudflare's published ranges**, so nothing reaches this
origin without passing Cloudflare first, and **Broc blocks the file types that matter (`.md`,
`.sql`) there as policy.**

`CLAUDE.md` therefore serves 200 at the origin, carrying the database password, **and that is
accepted.**

**DO NOT ADD nginx deny rules for this.** They were added during the 6 Aug audit and **removed the
same day at Broc's direction** - he had not asked for them, and **a rule with two owners is how it
ends up half-removed by whoever assumes the other half covers it.** An earlier version of this note
argued "Cloudflare is not the control"; that was written **without looking at the firewall** and it
was wrong. **Before making that argument again, run `ufw status`** - everything above rests on
that scoping and nothing else.

### What DID need fixing, and was

**`TUG_Attendances_2026.xlsx` and `TUG_Attendances_2027.xlsx` were downloadable from the public
internet**, HTTP 200, no sign-in: **261 children's names and dates of birth.** The WAF does not
block `.xlsx`, so unlike `CLAUDE.md` these really were reaching anybody who asked. The project docs
had said since 27 Jul that the workbooks were "wiped"; **they were not, and nothing checked.** Now
at `/var/lib/crm-archive` (0700 root), moved rather than deleted, with the obsolete
`rooms.json`.

**The lesson is about where files live, not about deny rules:** if a file does not need to be
fetched by a browser, **it does not belong in the root**. And **when the docs claim something was
removed, `ls` for it.**

## Security headers

Set **in the vhost**, not the shared `security-headers.conf` snippet - the other apps on this box
have not been audited for CSP.

The policy is **`script-src 'self'` with no `unsafe-inline`**, which is why:

- the CSRF token travels in a `<meta name="csrf-token">` rather than an inline `<script>`
- there are **no `onclick=` / `onchange=` attributes anywhere**
- the sidebar collapse state rides in a cookie (no pre-paint inline script is possible)

**`'unsafe-inline'` remains for STYLE only** (templates use `style=""` for column widths; inline
style cannot execute script).

**Adding an inline `<script>` or event-handler attribute will silently break the page.**

## Looking at the pages: a session, not a backdoor

Every screen sits behind Entra SSO, so `webshot` captures the sign-in page - and several classes
of bug here are invisible to any other check.

`/usr/local/sbin/crm-screenshot-session.php` (0700 root) writes a session **FILE** straight
into `/var/lib/crm-sessions`, then:

```
webshot crm /path --cookie lm_crm=<id>
```

`--clear` revokes.

**It is deliberately NOT an HTTP "allow localhost" bypass.** A dozen apps share
`*.tuggerahservices.com.au` on this box and any local account can reach loopback - the same shape
as the world-writable session store that already defeated `use_strict_mode` here once. **Writing
into a 0700 directory needs root, the app is completely unmodified, and nothing is reachable over
the network that was not reachable before.**

**Revoke when finished; do not leave a session minted.**

## Cron

Five jobs, all root, each flock-guarded, sharing `/var/log/crm-nightly.log` (rotated
weekly, 8 kept). Run any of them by hand with `--dry-run`.

**The hourly board warm skips a centre-year before that centre opens** (21 Sep 2026). It is the
one place that saving repeats: Warnervale 2026 was a live Kidsoft round trip 24 times a day for a
centre with no doors open, and nothing asks for that board any more - every screen returns before
it derives one. The nightly ROOM and CAPACITY sync deliberately still reads it; that data is what
the Rooms screen shows while a centre is being set up. See
[rooms-centres.md](rooms-centres.md).

### `/etc/cron.d/crm-nightly` - 02:00

`_incl/cron-nightly.php`, exits non-zero if any of the first jobs fail:

1. **Backs up the database** to `/var/backups/crm/` (0700, **outside the web root because a
   dump is a file of children's names, DOBs and guardian contact details**). 14 nightly dumps kept.
   - The dump is written to a **`.part` file and only moved into place after it is verified** - a
     failed run must never destroy the good backup taken earlier the same day.
   - **`${PIPESTATUS[0]}` is checked, not `$?`**: the exit status of `mysqldump | gzip` is gzip's,
     and **gzip cheerfully compresses an error message.**
   - **`kidsoft_cache` is excluded, structure kept.** It is a disposable copy of what Kidsoft still
     holds, and it is by far the largest table - a year of bookings is 20.6MB of JSON per centre.
     Carrying that into all 14 retained dumps of children's personal data is the opposite of the
     point. **`--ignore-table` alone would drop the `CREATE TABLE` too**, so the dump is two passes
     into one stream: everything but the cache's rows, then the cache's structure only.
2. **Prunes `kidsoft_cache`** older than 3 days. Each centre-year anyone views leaves ~4MB gzipped;
   nothing else removes them.
3. **Expires lapsed offers.** The app sweeps on page load, so this is a safety net for a quiet week.
4. **Refreshes linked children's names and DOBs**, for centres nobody opened.
5. **Discovers centres, THEN refreshes rooms and capacities.** Since the Rooms screen stopped being
   an editor this job is the **only** thing keeping a room's name, age band, active flag and
   capacity true, **so a failure here is a room somebody cannot find and a denominator that is
   quietly wrong** - not merely a stale figure. A Kidsoft outage is still not a failure condition:
   `rooms_sync_centre()` creates and retires nothing on a run whose utilisation read came back empty.
6. **Refreshes the new-enrolment queue** (7 calls, ~2.7s), so a family who applied overnight is in
   the morning's queue. **Per-centre failures are caught individually** - one unreachable centre
   must not cost the other six their sync - and a Kidsoft outage is not a failure condition:
   yesterday's queue is still the queue.
7. **Copies contact notes to Kidsoft** for families who can now be written to. **THE ONLY WRITE
   THIS PROJECT MAKES ANYWHERE** (`Comment/createComment`, authorised 14 Aug 2026), and it sits
   here because job 6 is what makes a family writable: Kidsoft issues a ChildID when their
   application converts. Oldest note first - **Kidsoft returns no date on a comment**, so
   insertion order is the only ordering a reader gets. It pushes what was never attempted and
   what Kidsoft explicitly declined, and **leaves alone anything whose outcome is unknown** (a
   transport failure may have landed, and there is no read path to check with); those are named
   in the log for a person to decide on. Capped per run, with what was held back logged. **Not a
   failure condition** - the notes are safe in `communications` either way, which is the point.
8. **Warms a year of bookings per centre** (current and next year). Cold a year is 12 API calls and
   ~33s; warm it is 0.1s. **Not a failure condition** - an outage overnight costs one slow page
   load and should not mark the backup job as failed.
9. **Warms the churn readings** - the four quarterly months of the year JUST GONE plus the November
   before it. **Nothing else warms a past year**, and Tenure opens on one.
10. **Warms re-enrolment** and runs the internal-day-request close sweep for centres nobody opened.

### `/etc/cron.d/crm-contacts` - every 2 h, 06:00-18:00

`cron-nightly.php --contacts`. Returns before any of the jobs above and refreshes only
`Guardian/getGuardians` and `Children/getChildren?Options=Guardian` (one `child_contacts(refresh:
true)` per centre covers both). It exists because those two run a **3 h TTL** against everything
else's 36 h.

**7 centres, 26s**, ~3 min of API time a day. **Exits 0 on a Kidsoft outage by design:** search
keeps answering from the last good copy, and mailing the team about it would be noise they cannot
action.

### `/etc/cron.d/crm-warm` - hourly, 06:00-18:00

`cron-nightly.php --warm`. Re-reads the ONE month the placement board derives from
(`board_reference_month()`) for every centre, this year and next. **19s for all 14 centre-years**,
about 4 minutes of API time across the working day.

**It breaks the "TTL must exceed the warm interval" rule on purpose, and is the SECOND deliberate
exception** (the re-enrolment warm is the first). The rule exists because a TTL that expires
between warms makes the warm pointless - **true when the warm is the only thing filling the
cache.** Here the two answer different questions: the **15-minute TTL decides how stale the board
may ever be**, while the **hourly run only guarantees a recent row EXISTS.** Expiry costs a
background refresh and no user-visible wait; a MISSING row is a 4-second stall in front of whoever
opens that centre first.

**Warm for existence, short TTL for freshness** - and if you shorten a TTL below its warm interval
anywhere else, **say which of the two jobs you are doing.**

### `/etc/cron.d/crm-birthdays` - Monday 07:00

`cron-nightly.php --birthdays`. Emails the parents accounts team the children in care at Erina
turning 2 in the next 14 days (every Monday, even when there are none). **The only job here that sends anything out of the building**, and
each send is written to `audit_log` (`report.birthdays_sent`: count and recipients, never the
children). ~17s, all live Kidsoft reads. **Exits non-zero when a centre did not answer** - the
email still goes, with a banner naming it, but unlike the warms an outage here costs the reader
something they cannot see. `--to=you@...` sends a test copy instead. See
[birthday-report.md](birthday-report.md).

### `/etc/cron.d/crm-calendar` - every 15 min, 06:00-20:45

`cron-nightly.php --calendar` (16 Oct 2026). Brings every tour from yesterday onwards into line
with its entry in the centre's Outlook calendar - `tour_calendar_sweep()` in
`_incl/tour-calendar.php`. `/api/work` already writes the entry straight after each save; this is
the retry for whatever that missed, and **the job that backfills every upcoming tour on the day
`Calendars.ReadWrite` is consented**. An item already in step costs a hash and no call. **Silent
unless something failed or was removed**, and a failure is NOT a non-zero exit: it is recorded on
the item (`calendar_error`) and shown in its modal, which is where it can be acted on - until the
permission lands every run would otherwise fail. **It writes outside the app**, into the
centres' own mailboxes; each create, update and remove is audited
(`work.calendar`), a failure only when its reason changes. See [work-layer.md](work-layer.md).

**Support plan booked days ride the same job** (29 Oct 2026): `plan_calendar_sweep()` in
`_incl/support-plan-calendar.php` runs straight after the tour sweep and logs as "support plan
calendar". Audited as `support_plan.calendar`. It is also what takes an upcoming entry out when a
child's plan flag is unticked, because that write goes through `/api/board`, which knows nothing of
it. See [support-plans.md](support-plans.md).

## Audit

**Every mutation goes through `audit()`** (`_incl/audit.php`).
