<?php
// Audit trail. This app holds children's and families' personal data and decides
// who gets a place, so every mutation is recorded against the acting Entra
// identity. Call audit() AFTER the change succeeds.

require_once __DIR__ . '/simple-mysqli.php';

/**
 * @param string $action      dotted verb, e.g. 'room.update', 'offer.create'
 * @param string $entityType  'room', 'offer', 'waitlist', ...
 * @param array  $detail      changed fields / before-after; keep it small
 */
function audit(string $action, string $entityType, string|int|null $entityId, array $detail = []): void {
    $user = $_SESSION['user']['email'] ?? 'system';
    dbx()->query(
        'INSERT INTO audit_log (staff_email, action, entity_type, entity_id, detail, ip)
         VALUES (?, ?, ?, ?, ?, ?)',
        [
            $user,
            $action,
            $entityType,
            $entityId === null ? null : (string)$entityId,
            $detail ? json_encode($detail, JSON_UNESCAPED_SLASHES) : null,
            $_SERVER['REMOTE_ADDR'] ?? null,
        ]
    );
}

/**
 * Fields that changed between two rows, as ['field' => [old, new]].
 *
 * A LIST IS COMPARED BY ITS CONTENTS (14 Oct 2026). Both callers' rows carry
 * `days` as an array - waitlist_get() and placement_get() - and this cast every
 * field to a string, so a list became the word "Array" on both sides: a PHP
 * warning on every save (eight in the fpm log the day it was found) and a change
 * of requested days that could NEVER be recorded, on the one field the queue is
 * matched on. Encoded rather than compared loosely, so [1,2] against [1,2,3]
 * differs and the same list in the same order does not.
 */
function audit_diff(array $before, array $after): array {
    $key = static fn($x) => is_array($x) ? json_encode($x) : (string)$x;
    $diff = [];
    foreach ($after as $k => $v) {
        if (!array_key_exists($k, $before)) continue;
        if ($key($before[$k]) !== $key($v)) $diff[$k] = [$before[$k], $v];
    }
    return $diff;
}
