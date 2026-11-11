# The Monday birthday email

`_incl/birthdays.php`, sent by `cron-nightly.php --birthdays` from
`/etc/cron.d/crm-birthdays` at **07:00 every Monday**.

**Who it is for:** `lmgparentsaccounts@littlemiracles.com.au` (the "LMG Parents Accounts"
distribution group - measured with Graph, it is a mail-enabled group, not a mailbox). Fees are
set by age band and Kidsoft does not move a child to the next band on their birthday, so an
account manager does it by hand. **This email tells them who, a fortnight ahead.** Asked for by
Broc, 2 Oct 2026.

**NARROWED TO ERINA, CHILDREN TURNING 2** (6 Oct 2026, Broc, on his manager's advice): that is the
fee adjustment made by hand in Kidsoft. The first version listed every birthday at every centre;
it went once, as a test to Broc, and never to the accounts team. Both filters are constants in
`birthdays.php` (`BIRTHDAY_REPORT_CENTRES` holds `centres.id`, `BIRTHDAY_REPORT_TURNING` the
ages), and only the listed centres are read from Kidsoft.

**TURNING 3 ADDED** (26 Oct 2026, Broc): `BIRTHDAY_REPORT_TURNING` is now `[2, 3]`, still Erina
only. The subject and intro read "Turning 2 or 3 at Erina", and the Turning column tells the two
apart.

**IT SENDS EVERY MONDAY, EMPTY OR NOT** - Broc's choice, 6 Oct 2026, over sending only when
somebody is on it. At the time that meant ~19 empty Mondays: Erina's 38 children, all booked,
had nobody turning 2 before **22 Feb 2027**.

**Sent from** `noreply@littlemiracles.com.au` (`mail_from` in `entra-config.php`) through
`graph_send_mail()`. Needs the Graph **application** permission `Mail.Send`, consented 6 Oct 2026
(added 2 Oct without consent; the first Monday run failed `403 ErrorAccessDenied` because of it).
**Check the token, not the portal**: decode `entra_app_token()` and read its `roles` claim.

## What is in it

Children in care at the configured centres whose birthday between today and today + 13 days
makes them one of the configured ages, grouped by centre: name, birthday, age turning, room, and
**booked as** - the booking type's NAME, which carries the
age band ("2-3 Full Day"). That is the thing the account manager is about to change, so it saves
them a lookup. **Only the name is read: `BaseFee` and the booking row's `Fee` are never touched**
(no dollar figure, CLAUDE.md). It does not say whether a type is wrong for the age: what each
band covers is the accounts team's fee schedule, not something this app knows.

Weekly runs over a 14-day window, so **every birthday appears in two consecutive emails**. That
is what was asked for, and it means a missed Monday loses nothing.

## "In care", measured 2 Oct 2026 across every centre (before the Erina narrowing)

| Test | Children |
|---|---|
| Kidsoft `Active` | 901 (28 with a birthday 5-18 Oct) |
| + a permanent booking inside the 14 days | 25 of those 28 |

The three dropped are `Active` with **no booking until January 2027** - enrolled, not yet in
care. **No inactive child held a permanent booking at any centre**, so `Active` loses nobody and
the booking test only removes children who have not started.

- **Casual bookings do not count** (CLAUDE.md hard rule). Zero casual-only children in the
  measured fortnight.
- **A child whose `ExitDate` is before their birthday is left out** - the fee never changes.
- **29 February birthdays fall on 1 March** in a common year (DOB + N years).
- **The booking test makes a future window look empty.** A child counts only with a permanent
  booking inside the fortnight, so testing a date months ahead finds nobody wherever next year's
  bookings are not entered yet - on 6 Oct 2026 Erina had none at all for 2027. **Prove the filter
  against the current fortnight**, not a future one: across every centre, 6-19 Oct 2026 gave 4
  children turning 2 out of 27 birthdays.

## Failure

Every Kidsoft read is live (`ttl 0`). A centre that does not answer is named in a **banner at the
top of the email** - an empty section reads exactly like "no birthdays", so that is the one thing
worth a banner - and the job exits non-zero so the log shows it. **The email still goes**: the
other centres' children are still due.

A failed Graph send exits non-zero and writes nothing to `audit_log`. A successful one writes
`report.birthdays_sent` with the window, the count and the recipients, **not** the children.

## Running it by hand

```
php _incl/cron-nightly.php --birthdays --dry-run          # counts per centre, sends nothing
php _incl/cron-nightly.php --birthdays --to=you@littlemiracles.com.au   # a test copy to you only
php _incl/cron-nightly.php --birthdays --from=2026-10-05   # re-send a missed Monday, FOR REAL
```

**A run without `--to` emails the accounts team for real.** Do not use it to test.

**A failed Monday tells nobody** - it is a line in `/var/log/crm-nightly.log` and no
`audit_log` row. The first one (5 Oct 2026, the `Mail.Send` 403) was re-sent the next day with
`--from=2026-10-05`, so the accounts team got the window that Monday should have covered.
