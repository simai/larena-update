<?php

declare(strict_types=1);

namespace Larena\UpdateClient\Release;

/**
 * Puts the site database back from the copy of a cycle — the same path as «восстановление поверх сайта» in
 * `larena/backup` (plan §3, steps 5 and 6). Used by the automatic rollback once migrations started and by the
 * manual rollback. A failure is a RuntimeException.
 */
interface DatabaseRestore
{
    public function restoreDatabase(string $siteRoot, string $backupRef): void;
}
