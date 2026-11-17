<?php

declare(strict_types=1);

namespace Larena\UpdateClient\Release;

use Closure;
use RuntimeException;

/**
 * Test and reference stand-in for every outside step of the cycle: it runs nothing, keeps a journal of calls, keeps a
 * pretend database (`schema` + `records`) that backups copy, migrations change and restores put back, and fails on
 * request. Calls are named `preflight`, `backup`, `restore`, `maintenance.enter`, `maintenance.leave`, `migrate`,
 * `sync_presets`, `clear_caches`, `health`, `activate`; {@see fail()} makes one throw (or, for `health`, report a
 * failed check), {@see $before} runs before a named call (for example to start a second cycle in the middle).
 */
final class RecordingUpdateSite implements UpdatePreflight, SiteBackup, DatabaseRestore, MaintenanceMode, ReleaseMigrations, SiteHealth, ReleaseActivation
{
    /** @var list<string> call journal, `name:release` */
    public array $journal = [];

    /** @var array{schema: string, records: list<string>} */
    public array $database;

    /** @var array<string, array{schema: string, records: list<string>}> */
    public array $copies = [];

    public ?string $maintenanceFrom = null;

    public bool $restoreVerified = true;

    /** @var array<string, string> call => reason code */
    private array $failures = [];

    /** @var array<string, Closure():void> */
    public array $before = [];

    /** @param list<string> $records */
    public function __construct(string $schema, array $records = [])
    {
        $this->database = ['schema' => $schema, 'records' => $records];
    }

    public function fail(string $call, string $reason = 'fixture_failed'): self
    {
        $this->failures[$call] = $reason;

        return $this;
    }

    public function recover(string $call): self
    {
        unset($this->failures[$call]);

        return $this;
    }

    public function isDown(): bool
    {
        return $this->maintenanceFrom !== null;
    }

    public function check(string $siteRoot, int $archiveBytes): void
    {
        $this->call('preflight', (string) $archiveBytes);
    }

    public function failedChecks(string $siteRoot): array
    {
        $this->call('health', (string) (new ReleasePointer($siteRoot))->current(), false);

        return isset($this->failures['health']) ? ['up'] : [];
    }

    public function create(string $siteRoot, string $cycleId): SiteBackupCopy
    {
        $this->call('backup', $cycleId);
        $ref = 'backup.cycle_' . substr($cycleId, 0, 12) . '.c' . count($this->copies);
        $this->copies[$ref] = $this->database;

        return new SiteBackupCopy($ref, $this->restoreVerified);
    }

    public function restoreDatabase(string $siteRoot, string $backupRef): void
    {
        $this->call('restore', $backupRef);
        $this->database = $this->copies[$backupRef] ?? throw new RuntimeException('fixture_backup_missing');
    }

    public function enter(string $releaseDirectory): void
    {
        $this->call('maintenance.enter', basename($releaseDirectory));
        $this->maintenanceFrom = basename($releaseDirectory);
    }

    public function leave(string $releaseDirectory): void
    {
        $this->call('maintenance.leave', basename($releaseDirectory));
        $this->maintenanceFrom = null;
    }

    public function migrate(string $releaseDirectory): void
    {
        // A failing migration has already changed part of the schema, as a real one would.
        $this->database['schema'] = basename($releaseDirectory) . (isset($this->failures['migrate']) ? '-partial' : '');
        $this->call('migrate', basename($releaseDirectory));
    }

    public function syncAccessPresets(string $releaseDirectory): void
    {
        $this->call('sync_presets', basename($releaseDirectory));
    }

    public function clearCaches(string $releaseDirectory): void
    {
        $this->call('clear_caches', basename($releaseDirectory));
    }

    public function activated(string $siteRoot, ReleaseSwitch $switch): void
    {
        $this->call('activate', $switch->current);
    }

    private function call(string $name, string $subject, bool $throw = true): void
    {
        if (isset($this->before[$name])) {
            ($this->before[$name])();
        }
        $this->journal[] = $name . ':' . $subject;
        if ($throw && isset($this->failures[$name])) {
            throw new RuntimeException($this->failures[$name]);
        }
    }
}
