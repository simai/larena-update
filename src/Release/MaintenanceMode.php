<?php

declare(strict_types=1);

namespace Larena\UpdateClient\Release;

/**
 * Maintenance mode on and off, run from the folder of one release (`<site>/releases/<release>`, its own
 * `artisan`). The flag lives in the shared `storage`, so any release sees it. A failure is a RuntimeException.
 */
interface MaintenanceMode
{
    public function enter(string $releaseDirectory): void;

    public function leave(string $releaseDirectory): void;
}
