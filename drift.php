<?php
// RETIRED - merged into the Board (index.php) on 27 Jul 2026.
//
// This screen was one of four that each rendered the same room x weekday grid
// from a different source (the plan, Kidsoft's bookings, the disagreements
// between them, and the waiting queue). CLAUDE.md has always said the unit of
// work is a room x weekday CELL, so answering one question about one cell meant
// crossing four screens that could disagree with each other. They are now one
// grid: see _incl/cells.php and index.php.
//
// Kept as a redirect rather than deleted so bookmarks, and any link sent round
// the team while this was live, still land somewhere useful.

declare(strict_types=1);

require_once __DIR__ . '/_incl/auth.php';
auth_require();

$q = [];
if (!empty($_GET['centre'])) $q['centre'] = (int)$_GET['centre'];
if (!empty($_GET['week']))   $q['week']   = substr((string)$_GET['week'], 0, 10);

// /placement, not / - these were retired INTO the cell grid, and since
// 9 Aug 2026 / is the Dashboard. Landing there would drop the centre and
// week these links carry and quietly answer a different question.
header('Location: /placement' . ($q ? '?' . http_build_query($q) : ''), true, 301);
