<?php
/**
 * RETIRED 13 Aug 2026. Moved out of _incl/rooms.php, where it sat defined and
 * uncalled.
 *
 * `year_list()` (_incl/board.php) replaced it. The two differ in the way that
 * mattered: this one returns the DISTINCT years that happen to have a capacity
 * row, so a link pointing at a far-off year produced a list with holes in it --
 * [2026, 2027, 2029, 2030] - and since the year stepper walks the array by
 * INDEX, one press skipped straight over 2028 while its tooltip named the year
 * it was skipping to. `year_list()` returns a contiguous range() instead.
 *
 * Its last caller is retired/api-fill.php, which is why it is filed here beside
 * it rather than deleted. Nothing in the live app referenced it.
 *
 * Not loaded by anything. Nothing in _incl/retired/ is.
 */

/** Every year that has a capacity recorded at this centre, plus $also. */
function centre_years(int $centreId, array $also = []): array {
    $rows = dbx()->query(
        'SELECT DISTINCT ryc.year FROM room_year_capacity ryc
         JOIN rooms r ON r.id = ryc.room_id WHERE r.centre_id = ?',
        [$centreId], 'i'
    )->fetchAll('col');
    $years = array_map('intval', array_merge($rows, $also));
    $years = array_values(array_unique($years));
    sort($years);
    return $years;
}
