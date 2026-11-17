<?php

declare(strict_types=1);

namespace Larena\UpdateClient\Laravel;

use Closure;
use JsonException;
use Larena\UpdateClient\Release\DatabaseRestore;
use Larena\UpdateClient\Release\ReleaseDirectory;
use Larena\UpdateClient\Release\ReleasePointer;
use Larena\UpdateClient\Release\SiteBackup;
use Larena\UpdateClient\Release\SiteBackupCopy;
use RuntimeException;

/**
 * The site copy and the database restore of the cycle through `larena/backup`'s own console commands, run as
 * `php <site>/releases/<current>/artisan …` (the release `current` points to at that moment), so update-client does
 * not load the backup package into its process and does not depend on its classes:
 *
 * - copy: `larena:backup:run` (a complete backup: database and managed files) and then `larena:backup:drill` (its
 *   trial restore into a disposable target). Both are durable queue jobs; while one is not finished the adapter
 *   lets `larena:queue-work --max-jobs=1` take the next job and asks `larena:backup:status` again, up to a deadline.
 *   The copy reference is `larena_backup.job_<backup job id>`; the copy counts as verified only when the drill
 *   completed.
 * - restore: `larena:backup:restore <job> --confirm=<job>` — «восстановление поверх сайта», which keeps the current
 *   database as a recovery point first.
 *
 * Every command runs with `--json` and the actor of the update (an existing administrator `subject_ref`, which the
 * backup package checks). The idempotency keys come from the cycle id, so a repeated step reuses the same jobs.
 */
final readonly class LarenaBackupSiteCopy implements SiteBackup, DatabaseRestore
{
    public const REF_PREFIX = 'larena_backup.job_';
    private const TERMINAL_FAILED = ['failed', 'timed_out', 'cancelled'];

    /** @param (Closure(int): void)|null $sleep */
    public function __construct(
        private ArtisanRunner $artisan,
        private string $actorRef,
        private int $waitSeconds = 3600,
        private ?int $commandTimeoutSeconds = null,
        private ?Closure $sleep = null,
        private ?Closure $clock = null,
    ) {
        if (preg_match('/\A[a-z][a-z0-9_-]*:[A-Za-z0-9][A-Za-z0-9:._-]{0,191}\z/D', $this->actorRef) !== 1) {
            throw new RuntimeException('update_actor_invalid');
        }
    }

    public function create(string $siteRoot, string $cycleId): SiteBackupCopy
    {
        $release = $this->currentRelease($siteRoot);
        $backup = $this->command($release, ['larena:backup:run', '--idempotency-key=update:' . $cycleId . ':backup'], 'update_backup_failed');
        $jobId = self::jobId($backup['job_id'] ?? null, 'update_backup_failed');
        if ($this->waitFor($release, $jobId, 'update_backup_failed') !== 'completed') {
            throw new RuntimeException('update_backup_failed');
        }

        $drill = $this->command($release, ['larena:backup:drill', $jobId, '--idempotency-key=update:' . $cycleId . ':drill'], 'update_backup_drill_failed');
        $drillId = self::jobId($drill['job_id'] ?? null, 'update_backup_drill_failed');

        return new SiteBackupCopy(self::REF_PREFIX . $jobId, $this->waitFor($release, $drillId, 'update_backup_drill_failed') === 'completed');
    }

    public function restoreDatabase(string $siteRoot, string $backupRef): void
    {
        $jobId = self::jobId(str_starts_with($backupRef, self::REF_PREFIX) ? substr($backupRef, strlen(self::REF_PREFIX)) : null, 'update_restore_failed');
        $this->command($this->currentRelease($siteRoot), ['larena:backup:restore', $jobId, '--confirm=' . $jobId], 'update_restore_failed');
    }

    /** Waits for a backup job to end; returns `completed` or the failed terminal status. */
    private function waitFor(string $release, string $jobId, string $reason): string
    {
        $now = $this->clock ?? static fn (): int => time();
        $deadline = $now() + $this->waitSeconds;
        while (true) {
            $status = $this->command($release, ['larena:backup:status', $jobId], $reason);
            $state = $status['job_status'] ?? null;
            if ($state === 'completed' || in_array($state, self::TERMINAL_FAILED, true)) {
                return (string) $state;
            }
            if (!is_string($state)) {
                throw new RuntimeException($reason);
            }
            if ($now() >= $deadline) {
                throw new RuntimeException('update_backup_timeout');
            }
            // Nudge the durable queue: the site may run no worker of its own (preflight saw no job in work).
            $output = $this->artisan->run($release, ['larena:queue-work', '--max-jobs=1'], $reason, $this->commandTimeoutSeconds);
            if (preg_match('/Processed jobs: 0\b/', $output) === 1) {
                ($this->sleep ?? static function (int $seconds): void { sleep($seconds); })(2);
            }
        }
    }

    /**
     * @param list<string> $arguments
     * @return array<string,mixed>
     */
    private function command(string $release, array $arguments, string $reason): array
    {
        $output = $this->artisan->run($release, [...$arguments, '--actor=' . $this->actorRef, '--json'], $reason, $this->commandTimeoutSeconds);
        $start = strpos($output, '{');
        try {
            $payload = $start === false ? null : json_decode(substr($output, $start), true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $payload = null;
        }
        if (!is_array($payload) || ($payload['status'] ?? null) !== 'passed') {
            $backupReason = is_array($payload) && is_string($payload['reason'] ?? null) && preg_match('/\A[a-z][a-z0-9_.-]{0,99}\z/D', $payload['reason']) === 1
                ? $payload['reason'] : $reason;
            throw new RuntimeException($backupReason);
        }

        return $payload;
    }

    private function currentRelease(string $siteRoot): string
    {
        $site = ReleaseDirectory::site($siteRoot);
        $current = (new ReleasePointer($site))->current() ?? throw new RuntimeException('release_current_missing');

        return $site . '/releases/' . $current;
    }

    private static function jobId(mixed $value, string $reason): string
    {
        return is_string($value) && preg_match('/\A[a-f0-9]{32}\z/D', $value) === 1 ? $value : throw new RuntimeException($reason);
    }
}
