<?php
// RETIRED - there is nothing left to roll over (6 Aug 2026).
//
// This screen carried a year's board into the next one, sorting every child into
// stays / moves / leaves for the team to decide. It existed because the board was
// a COPY of Kidsoft held in our `placements` table, so next year's copy had to be
// created by somebody pressing a button.
//
// The board is now DERIVED from Kidsoft (see _incl/board-live.php), so next
// year's board is simply next year's bookings. It fills itself as each centre
// processes its re-enrolments - measured on the day this was retired, Kidsoft
// already held 220 children for 2027 across four centres while this app's 2027
// board showed zero, and 55 of Tuggerah's arrived during that single day.
//
// The decision this screen modelled - which room a child moves up into - is
// made by the centres in Kidsoft during the re-enrolment round, which is the
// first phase of the enrolment process. This app's job is to show that happening
// and to work the places it leaves empty, not to ask the team to type it twice.
//
// Kept as a redirect rather than deleted so any bookmark still lands somewhere
// useful.

declare(strict_types=1);

require_once __DIR__ . '/_incl/auth.php';
auth_require();

$q = [];
if (!empty($_GET['centre'])) $q['centre'] = (int)$_GET['centre'];
// `to` was the year being planned, which is the year worth landing on.
$year = (int)($_GET['to'] ?? $_GET['from'] ?? 0);
if ($year >= 2000 && $year <= 2100) $q['year'] = $year;

// /placement, not / - these were retired INTO the cell grid, and since
// 9 Aug 2026 / is the Dashboard. Landing there would drop the centre and
// week these links carry and quietly answer a different question.
header('Location: /placement' . ($q ? '?' . http_build_query($q) : ''), true, 301);
