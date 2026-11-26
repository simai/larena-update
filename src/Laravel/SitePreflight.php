<?php

declare(strict_types=1);

namespace Larena\UpdateClient\Laravel;

use Closure;
use Larena\UpdateClient\Release\ReleaseDirectory;
use Larena\UpdateClient\Release\UpdatePreflight;
use Larena\UpdateClient\Runtime\PdoUpdateExecutionStore;
use PDO;
use RuntimeException;
use Throwable;

/**
 * Step 1 of the cycle on a real site (plan §3): free disk space at least archive × 3 (`update_disk_space_low`),
 * write access to `<site>/releases` and `<site>/storage` (`update_releases_not_writable`,
 * `update_storage_not_writable`), no other unfinished cycle in `update_cycles` besides the one being checked
 * (`update_cycle_already_running`), and a queue without jobs in work: when the site database has the Laravel
 * `jobs` table, a reserved job refuses the update (`update_queue_busy`); without that table the check is skipped
 * and {@see notes()} says so (`queue_check_skipped`). Last, with a {@see SelfAddressCheck}, the site must reach itself
 * at the address the health check and the activation use (`update_self_health_unreachable`,
 * `update_self_activation_unreachable`): the switch is never made on a site that could not activate it or roll it
 * back. Read-only.
 */
final class SitePreflight implements UpdatePreflight
{
    /** @var list<string> */
    private array $notes = [];

    /** @param (Closure(string): (float|false))|null $freeSpace */
    public function __construct(
        private readonly ?PDO $database = null,
        private readonly ?PdoUpdateExecutionStore $store = null,
        private readonly string $jobsTable = 'jobs',
        private readonly ?Closure $freeSpace = null,
        private readonly ?SelfAddressCheck $selfAddress = null,
    ) {
        if (preg_match('/\A[A-Za-z_][A-Za-z0-9_]{0,63}\z/D', $this->jobsTable) !== 1) {
            throw new RuntimeException('update_queue_table_invalid');
        }
    }

    /** @return list<string> what was skipped in the last check */
    public function notes(): array
    {
        return $this->notes;
    }

    public function check(string $siteRoot, int $archiveBytes): void
    {
        $this->notes = [];
        $site = ReleaseDirectory::site($siteRoot);
        $free = $this->freeSpace !== null ? ($this->freeSpace)($site) : @disk_free_space($site);
        if ($free === false || $free < max(1, $archiveBytes) * 3) {
            throw new RuntimeException('update_disk_space_low');
        }
        foreach (['releases' => 'update_releases_not_writable', 'storage' => 'update_storage_not_writable'] as $directory => $reason) {
            $path = $site . '/' . $directory;
            if (is_link($path) || !is_dir($path) || !is_writable($path)) {
                throw new RuntimeException($reason);
            }
        }
        if ($this->store !== null && count($this->store->unfinished()) > 1) {
            throw new RuntimeException('update_cycle_already_running');
        }
        $this->checkQueue();
        $this->selfAddress?->check();
    }

    private function checkQueue(): void
    {
        if ($this->database === null) {
            $this->notes[] = 'queue_check_skipped';

            return;
        }
        try {
            $driver = (string) $this->database->getAttribute(PDO::ATTR_DRIVER_NAME);
            $exists = match ($driver) {
                'sqlite' => $this->database->prepare("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = ?"),
                'mysql' => $this->database->prepare('SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?'),
                'pgsql' => $this->database->prepare('SELECT 1 FROM information_schema.tables WHERE table_schema = current_schema() AND table_name = ?'),
                default => null,
            };
            if ($exists === null) {
                $this->notes[] = 'queue_check_skipped';

                return;
            }
            $exists->execute([$this->jobsTable]);
            if ($exists->fetchColumn() === false) {
                $this->notes[] = 'queue_check_skipped';

                return;
            }
            $reserved = $this->database->query('SELECT COUNT(*) FROM ' . $this->jobsTable . ' WHERE reserved_at IS NOT NULL');
            $count = $reserved === false ? false : $reserved->fetchColumn();
        } catch (Throwable) {
            throw new RuntimeException('update_queue_check_failed');
        }
        if ($count === false) {
            throw new RuntimeException('update_queue_check_failed');
        }
        if ((int) $count > 0) {
            throw new RuntimeException('update_queue_busy');
        }
    }
}
