<?php

declare(strict_types=1);

namespace Larena\UpdateClient\Laravel;

use Larena\UpdateClient\Release\MaintenanceMode;

/**
 * Maintenance on and off through `artisan down` / `artisan up` of the given release folder. The flag is written into
 * the shared `storage`, so every release sees it; the cycle's {@see MaintenanceBypass} secret lets the health check
 * through.
 */
final readonly class ArtisanMaintenanceMode implements MaintenanceMode
{
    public function __construct(
        private ArtisanRunner $artisan,
        private MaintenanceBypass $bypass,
        private int $retryAfterSeconds = 60,
    ) {
    }

    public function enter(string $releaseDirectory): void
    {
        $this->artisan->run($releaseDirectory, ['down', '--secret=' . $this->bypass->secret, '--retry=' . $this->retryAfterSeconds], 'update_maintenance_enter_failed', 120);
    }

    public function leave(string $releaseDirectory): void
    {
        $this->artisan->run($releaseDirectory, ['up'], 'update_maintenance_leave_failed', 120);
    }
}
