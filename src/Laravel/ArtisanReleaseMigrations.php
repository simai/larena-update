<?php

declare(strict_types=1);

namespace Larena\UpdateClient\Laravel;

use Larena\UpdateClient\Release\ReleaseMigrations;

/**
 * The commands of a new release, each in its own process from that release's folder before `current` moves:
 * `migrate --force`, `larena:access-sync-system-presets`, `optimize:clear`.
 */
final readonly class ArtisanReleaseMigrations implements ReleaseMigrations
{
    public function __construct(private ArtisanRunner $artisan, private ?int $migrationTimeoutSeconds = null)
    {
    }

    public function migrate(string $releaseDirectory): void
    {
        $this->artisan->run($releaseDirectory, ['migrate', '--force'], 'update_migrate_failed', $this->migrationTimeoutSeconds);
    }

    public function syncAccessPresets(string $releaseDirectory): void
    {
        $this->artisan->run($releaseDirectory, ['larena:access-sync-system-presets'], 'update_sync_presets_failed');
    }

    public function clearCaches(string $releaseDirectory): void
    {
        $this->artisan->run($releaseDirectory, ['optimize:clear'], 'update_clear_caches_failed');
    }
}
