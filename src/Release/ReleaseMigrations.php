<?php

declare(strict_types=1);

namespace Larena\UpdateClient\Release;

/**
 * The commands of a new release, run from its folder before `current` moves (plan §3, step 4): database
 * migrations, `larena:access-sync-system-presets`, cache reset. A failure is a RuntimeException; once
 * {@see migrate()} was called the rollback restores the database from the cycle's copy.
 */
interface ReleaseMigrations
{
    public function migrate(string $releaseDirectory): void;

    public function syncAccessPresets(string $releaseDirectory): void;

    public function clearCaches(string $releaseDirectory): void;
}
