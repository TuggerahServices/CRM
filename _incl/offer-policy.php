<?php
// WHEN AN OFFER IS URGENT. Two numbers, one definition, four consumers.
//
// An offer runs a clock (OFFER_DEFAULT_DAYS, 14 by default) and the app grades
// what is left of it identically everywhere: already lapsed or inside three days
// is red, and under two days it counts in HOURS rather than days, because "2
// days" and "31 hours" are the same fact told at different resolutions and only
// one of them makes somebody pick up the phone.
//
// WHY THIS FILE EXISTS. Both thresholds were bare literals in four places --
// work_progress_summary() in PHP, and progress.js / work-modal.js / waitlist.js
// in the browser - so the app had four copies of a policy it states once in
// prose. That is the shape of the stat tile's six copies and the mark bar's
// three, and this file already had the counter-example to hand:
// WAITLIST_EXPIRY_SOON_DAYS (retired 11 Nov 2026 with Kidsoft's waitlist
// expiry) was published to the browser precisely "so the threshold has one
// definition, not two".
//
// WHY IT IS ITS OWN FILE rather than a couple of consts in waitlist.php or
// work.php. The four consumers do not share an include: work.php does not
// require waitlist.php, and layout.php - which is what publishes these to the
// browser, on every page, the way it publishes the CSRF token - deliberately
// requires almost nothing (svg.php and csrf.php). Making layout.php pull in the
// whole work layer so that Rooms and Tenure, which have no work items on them at
// all, could read two integers is a worse trade than one dependency-free file.
//
// Keep it dependency-free. The moment this requires anything, the reason it is
// separate stops being true.
//
// The browser gets these from layout_header() as <meta> tags, read once by
// app.js into window.OFFER_URGENT_HOURS / window.OFFER_COUNTDOWN_HOURS and
// applied by window.offerUrgency(). Do not re-type either number in JS.

/** Inside this many hours (or already lapsed), an offer is red. Three days. */
const OFFER_URGENT_HOURS = 72;

/** Below this many hours, the countdown is shown in hours, not days. Two days. */
const OFFER_COUNTDOWN_HOURS = 48;
