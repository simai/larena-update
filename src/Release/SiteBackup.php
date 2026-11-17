<?php

declare(strict_types=1);

namespace Larena\UpdateClient\Release;

/**
 * Step 2 of the cycle (plan §3): a full copy through `larena/backup` with a trial restore. Without a copy whose
 * trial restore passed the cycle stops before any code is written. A failure is a RuntimeException with a stable
 * reason code.
 */
interface SiteBackup
{
    public function create(string $siteRoot, string $cycleId): SiteBackupCopy;
}
