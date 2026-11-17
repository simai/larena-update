<?php

declare(strict_types=1);

namespace Larena\UpdateClient\Release;

/**
 * Step 1 of the cycle (plan 2026-11-17-update-client-plan.md, §3): free disk space (archive × 3), write access to
 * the site root, `releases/` and the shared `storage`, and an empty or waiting queue. Read-only: nothing is written.
 * A problem is thrown as a RuntimeException with a stable reason code (for example `update_disk_space_low`).
 */
interface UpdatePreflight
{
    public function check(string $siteRoot, int $archiveBytes): void;
}
