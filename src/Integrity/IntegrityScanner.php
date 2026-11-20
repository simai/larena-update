<?php

declare(strict_types=1);

namespace Larena\UpdateClient\Integrity;

use Closure;
use DateTimeImmutable;
use DateTimeInterface;
use Throwable;

/**
 * A3: the full check of the site's files, in portions (the scheduler starts it, the queue runs one portion per job
 * run), with no lock held longer than one portion.
 *
 * 1. `release`: every entry of the signed list. Size first; a file whose fingerprint (size, times, inode) is the one it
 *    had when it last matched its SHA-256 counts as unchanged; any other — new, touched, copied — is hashed again.
 *    Found: changed, deleted, type changed, a replaced `.htaccess`.
 * 2. `archive`: the kept release archive «Вернуть как было» needs, the same way (hash only when its fingerprint moved).
 * 3. `walk`: the release folders and the data folders, for what the list does not name: a new PHP file anywhere
 *    (also `x.php.jpg`, `.phtml`, `.phar`), a new `.htaccess` in the release or among the public uploads. Links are
 *    never followed. Laravel's own generated PHP (`bootstrap/cache/*.php` by name, compiled views by their hash name)
 *    is not a finding.
 *
 * The end is honest: `unchanged`, `changes_found`, or `unknown` with the reason (no signed list, a folder or file
 * that could not be read). A check still running, or one that stopped half-way, is never `unchanged`.
 */
final readonly class IntegrityScanner
{
    public const UNCHANGED = 'unchanged';
    public const CHANGES_FOUND = 'changes_found';
    public const UNKNOWN = 'unknown';
    /** A site run from source: there is no signed list to check against (IntegrityLayout::sourceCheckout()). */
    public const SOURCE = 'source';

    private const STATE = 'scan.json';
    private const STATS = 'stats.json';
    private const LOCK = 'scan.lock';
    /** A run nobody advanced for this long is started again rather than continued. */
    private const STALE_SECONDS = 86400;
    private const PHP = '/\.(php[0-9]?|phtml|phar|pht|phps)(\.|\z)/i';
    private const BLADE = '/\A[a-f0-9]{32,64}\.php\z/D';
    private const GENERATED = [
        'bootstrap/cache/config.php', 'bootstrap/cache/events.php', 'bootstrap/cache/packages.php',
        'bootstrap/cache/routes-v7.php', 'bootstrap/cache/services.php',
    ];
    /** Release paths the site links itself into every release (never in the list, never followed). */
    private const SHARED = ['.env', 'storage', 'public/storage'];
    private const MAX_ERRORS_KEPT = 20;

    /**
     * @param Closure(): SignedBaseline         $baseline
     * @param (Closure(): DateTimeImmutable)|null $now
     */
    public function __construct(
        private IntegrityLayout $layout,
        private Closure $baseline,
        private FindingStore $findings,
        private ?Closure $now = null,
    ) {
    }

    /**
     * Starts a check unless one is running (a fresh start with `$restart`).
     *
     * @return array<string,mixed> the state ({@see state()})
     */
    public function start(string $trigger, bool $restart = false): array
    {
        return $this->locked(function (array $state) use ($trigger, $restart): array {
            $current = $state['current'] ?? null;
            if (!$restart && is_array($current) && ($current['state'] ?? null) === 'running'
                && ($this->time() - (int) ($current['touched'] ?? 0)) < self::STALE_SECONDS) {
                return $state;
            }
            $state['current'] = $this->fresh($trigger);

            return $state;
        }) ?? $this->state();
    }

    /**
     * One portion: at most `$maxEntries` files and folders, or `$maxSeconds`, whichever comes first.
     *
     * @return array<string,mixed>
     */
    public function step(int $maxEntries = 2000, float $maxSeconds = 2.0): array
    {
        return $this->locked(function (array $state) use ($maxEntries, $maxSeconds): array {
            $run = $state['current'] ?? null;
            if (!is_array($run) || ($run['state'] ?? null) !== 'running') {
                return $state;
            }
            $run = $this->advance($run, max(1, $maxEntries), max(0.05, $maxSeconds));
            $run['touched'] = $this->time();
            $state['current'] = $run;
            if ($run['state'] === 'completed') {
                $state['last'] = self::summary($run);
            }

            return $state;
        }) ?? $this->state();
    }

    /** Runs a started check to its end in portions (the console and the check after «Вернуть как было»). */
    public function runToEnd(int $maxEntries = 5000, int $maxPortions = 100000): array
    {
        $state = $this->state();
        for ($portion = 0; $portion < $maxPortions; $portion++) {
            $state = $this->step($maxEntries, 30.0);
            if ((($state['current'] ?? [])['state'] ?? null) !== 'running') {
                break;
            }
        }

        return $state;
    }

    /**
     * `current`: the check now or the last one (its phase, counts and, at the end, result and reason); `last`: the
     * summary of the last completed check.
     *
     * @return array<string,mixed>
     */
    public function state(): array
    {
        return $this->layout->read(self::STATE) ?? [];
    }

    /** @return array<string,mixed>|null the last completed check: result, reason, counts, times */
    public function last(): ?array
    {
        $last = $this->state()['last'] ?? null;

        return is_array($last) ? $last : null;
    }

    /** Whether a check is running now. */
    public function running(): bool
    {
        return ((($this->state()['current'] ?? [])['state'] ?? null) === 'running');
    }

    /** @return array<string,mixed> */
    private function fresh(string $trigger): array
    {
        return [
            'id' => bin2hex(random_bytes(8)),
            'trigger' => preg_match('/\A[a-z][a-z0-9_.-]{0,40}\z/D', $trigger) === 1 ? $trigger : 'manual',
            'state' => 'running',
            'phase' => 'baseline',
            'started_at' => $this->clock()->format(DateTimeInterface::ATOM),
            'finished_at' => null,
            'touched' => $this->time(),
            'baseline' => null,
            'index' => 0,
            'stack' => [],
            'found' => [],
            'counts' => ['checked' => 0, 'hashed' => 0, 'folders' => 0, 'links_skipped' => 0, 'errors' => 0],
            'errors' => [],
            'result' => null,
            'reason' => null,
            'portions' => 0,
        ];
    }

    /**
     * @param array<string,mixed> $run
     * @return array<string,mixed>
     */
    private function advance(array $run, int $maxEntries, float $maxSeconds): array
    {
        $deadline = microtime(true) + $maxSeconds;
        $run['portions'] = (int) $run['portions'] + 1;
        clearstatcache();
        try {
            $baseline = ($this->baseline)();
        } catch (IntegrityUnavailable $unavailable) {
            return $this->finish($run, $unavailable->reason === 'integrity_source_checkout' ? self::SOURCE : self::UNKNOWN, $unavailable->reason);
        } catch (Throwable) {
            return $this->finish($run, self::UNKNOWN, 'integrity_check_failed');
        }
        if ($run['baseline'] === null) {
            $run['baseline'] = $baseline->list->sha256;
            $run['phase'] = 'release';
        } elseif ($run['baseline'] !== $baseline->list->sha256) {
            // The release changed under the check (an update): what was counted belongs to another list.
            $restarted = $this->fresh((string) $run['trigger']);
            $restarted['baseline'] = $baseline->list->sha256;
            $restarted['phase'] = 'release';
            $run = $restarted;
        }
        $stats = $this->layout->read(self::STATS) ?? [];
        if (($stats['baseline'] ?? null) !== $baseline->list->sha256) {
            $stats = ['baseline' => $baseline->list->sha256, 'files' => [], 'archive' => null];
        }
        $done = 0;
        try {
            if ($run['phase'] === 'release') {
                $paths = array_keys($baseline->list->entries);
                $count = count($paths);
                while ($run['index'] < $count && $done < $maxEntries && microtime(true) < $deadline) {
                    $path = $paths[$run['index']];
                    $this->checkEntry($run, $stats, $path, $baseline->list->entries[$path]);
                    $run['index']++;
                    $done++;
                }
                if ($run['index'] >= $count) {
                    $run['phase'] = 'archive';
                }
            }
            if ($run['phase'] === 'archive' && microtime(true) < $deadline) {
                $this->checkArchive($run, $stats, $baseline);
                $run['phase'] = 'walk';
                $run['stack'] = [[IntegrityLayout::RELEASE, '']];
                foreach (array_keys($this->layout->dataRoots) as $name) {
                    $run['stack'][] = [$name, ''];
                }
            }
            if ($run['phase'] === 'walk') {
                while ($run['stack'] !== [] && $done < $maxEntries && microtime(true) < $deadline) {
                    [$root, $folder] = array_pop($run['stack']);
                    $done += $this->walkFolder($run, $baseline, (string) $root, (string) $folder);
                }
                if ($run['stack'] === []) {
                    $run['phase'] = 'done';
                }
            }
        } finally {
            $this->layout->write(self::STATS, (string) json_encode($stats, JSON_UNESCAPED_SLASHES));
        }
        if ($run['phase'] !== 'done') {
            return $run;
        }
        if ((int) $run['counts']['errors'] > 0) {
            // What could not be read was not compared: never «без изменений».
            $run['findings'] = $this->findings->merge($run['found'], $this->clock(), (string) $run['id'], false);

            return $this->finish($run, self::UNKNOWN, 'integrity_read_failed');
        }
        $counts = $this->findings->merge($run['found'], $this->clock(), (string) $run['id']);
        $run['findings'] = $counts;

        return $this->finish($run, $counts['open'] > 0 ? self::CHANGES_FOUND : self::UNCHANGED, null);
    }

    /**
     * @param array<string,mixed>                                      $run
     * @param array<string,mixed>                                      $stats
     * @param array{type:string,bytes:int,sha256:?string,target:?string} $entry
     */
    private function checkEntry(array &$run, array &$stats, string $path, array $entry): void
    {
        $run['counts']['checked']++;
        $full = $this->layout->root(IntegrityLayout::RELEASE) . '/' . $path;
        $stat = IntegrityLayout::stat($full);
        $kind = null;
        $actual = null;
        if ($stat === null) {
            // A missing folder shows in the files it held.
            $kind = $entry['type'] === 'dir' ? null : 'deleted';
        } elseif ($entry['type'] === 'dir') {
            $kind = is_link($full) || !is_dir($full) ? 'type_differs' : null;
        } elseif ($entry['type'] === 'link') {
            $kind = !is_link($full) || readlink($full) !== $entry['target'] ? 'type_differs' : null;
        } elseif (is_link($full) || !is_file($full)) {
            $kind = 'type_differs';
        } elseif (filesize($full) !== $entry['bytes']) {
            $kind = 'changed';
        } elseif (($stats['files'][$path] ?? null) !== $stat) {
            $run['counts']['hashed']++;
            $actual = @hash_file('sha256', $full);
            if (!is_string($actual)) {
                $this->error($run, $path, 'integrity_file_unreadable');

                return;
            }
            if (hash_equals((string) $entry['sha256'], $actual)) {
                if (IntegrityLayout::settled($stat, $this->time())) {
                    $stats['files'][$path] = $stat;
                }
            } else {
                $kind = 'changed';
            }
        }
        if ($kind === null) {
            return;
        }
        unset($stats['files'][$path]);
        if ($kind === 'changed' && basename($path) === '.htaccess') {
            $kind = 'htaccess_replaced';
        }
        if ($actual === null && $kind === 'changed' && is_file($full)) {
            $actual = @hash_file('sha256', $full) ?: null;
        }
        $run['found'][] = Finding::make($kind, IntegrityLayout::RELEASE, $path, $entry['sha256'], is_string($actual) ? $actual : null,
            $stat === null ? null : (is_file($full) ? (int) filesize($full) : null));
    }

    /**
     * @param array<string,mixed> $run
     * @param array<string,mixed> $stats
     */
    private function checkArchive(array &$run, array &$stats, SignedBaseline $baseline): void
    {
        $path = $baseline->archivePath;
        $stat = IntegrityLayout::stat($path);
        $label = '.larena-signed/' . basename($path);
        if ($stat === null) {
            $run['found'][] = Finding::make('archive_missing', IntegrityLayout::RELEASE, $label, $baseline->release->archiveSha256);
            $stats['archive'] = null;

            return;
        }
        if (($stats['archive'] ?? null) === $stat) {
            return;
        }
        $run['counts']['hashed']++;
        $actual = !is_link($path) && is_file($path) ? @hash_file('sha256', $path) : false;
        if (is_string($actual) && filesize($path) === $baseline->release->archiveBytes && hash_equals($baseline->release->archiveSha256, $actual)) {
            $stats['archive'] = IntegrityLayout::settled($stat, $this->time()) ? $stat : null;

            return;
        }
        $stats['archive'] = null;
        $run['found'][] = Finding::make('archive_damaged', IntegrityLayout::RELEASE, $label, $baseline->release->archiveSha256,
            is_string($actual) ? $actual : null, is_file($path) ? (int) filesize($path) : null);
    }

    /**
     * Reads one folder: sub-folders go on the stack, files the list does not name are classified.
     *
     * @param array<string,mixed> $run
     * @return int entries read
     */
    private function walkFolder(array &$run, SignedBaseline $baseline, string $root, string $folder): int
    {
        try {
            $base = $this->layout->root($root);
        } catch (Throwable) {
            return 0;
        }
        $full = $folder === '' ? $base : $base . '/' . $folder;
        if ($folder === '' && $root !== IntegrityLayout::RELEASE && !file_exists($full)) {
            // A data folder the site does not have (no logs yet): nothing to look at, nothing missed.
            return 0;
        }
        if ($this->isState($full)) {
            return 0;
        }
        $run['counts']['folders']++;
        $children = is_dir($full) && !is_link($full) ? @scandir($full) : false;
        if ($children === false) {
            $this->error($run, IntegrityLayout::label($root, $folder === '' ? '.' : $folder), 'integrity_folder_unreadable');

            return 1;
        }
        $read = 0;
        foreach ($children as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            $read++;
            $relative = $folder === '' ? $name : $folder . '/' . $name;
            $path = $full . '/' . $name;
            if ($root === IntegrityLayout::RELEASE && ($relative === '.larena-signed' || $relative === '.larena-release'
                || in_array($relative, self::SHARED, true))) {
                continue;
            }
            if (is_link($path)) {
                if ($root !== IntegrityLayout::RELEASE || !isset($baseline->list->entries[$relative])) {
                    $run['counts']['links_skipped']++;
                }
                continue;
            }
            if (is_dir($path)) {
                $run['stack'][] = [$root, $relative];
                continue;
            }
            if ($root === IntegrityLayout::RELEASE && isset($baseline->list->entries[$relative])) {
                continue;
            }
            $kind = $this->classify($root, $relative, $name);
            if ($kind !== null) {
                $actual = @hash_file('sha256', $path);
                $run['found'][] = Finding::make($kind, $root, $relative, null, is_string($actual) ? $actual : null, (int) @filesize($path));
            }
        }

        return $read;
    }

    private function classify(string $root, string $relative, string $name): ?string
    {
        if ($name === '.htaccess') {
            return $root === IntegrityLayout::RELEASE || ($root === 'storage/app' && str_starts_with($relative, 'public/')) ? 'new_htaccess' : null;
        }
        if (preg_match(self::PHP, $name) !== 1) {
            return null;
        }
        if ($root === IntegrityLayout::RELEASE && in_array($relative, self::GENERATED, true)) {
            return null;
        }
        if ($root === 'storage/framework/views' && !str_contains($relative, '/') && preg_match(self::BLADE, $name) === 1) {
            return null;
        }

        return 'new_php';
    }

    private function isState(string $path): bool
    {
        $state = rtrim($this->layout->stateDirectory, '/');

        return $path === $state || str_starts_with($path, $state . '/');
    }

    /** @param array<string,mixed> $run */
    private function error(array &$run, string $path, string $reason): void
    {
        $run['counts']['errors']++;
        if (count($run['errors']) < self::MAX_ERRORS_KEPT) {
            $run['errors'][] = ['path' => $path, 'reason' => $reason];
        }
    }

    /**
     * @param array<string,mixed> $run
     * @return array<string,mixed>
     */
    private function finish(array $run, string $result, ?string $reason): array
    {
        $run['state'] = 'completed';
        $run['phase'] = 'done';
        $run['result'] = $result;
        $run['reason'] = $reason;
        $run['stack'] = [];
        $run['finished_at'] = $this->clock()->format(DateTimeInterface::ATOM);

        return $run;
    }

    /**
     * @param array<string,mixed> $run
     * @return array<string,mixed>
     */
    private static function summary(array $run): array
    {
        return [
            'id' => $run['id'], 'trigger' => $run['trigger'], 'result' => $run['result'], 'reason' => $run['reason'],
            'started_at' => $run['started_at'], 'finished_at' => $run['finished_at'], 'counts' => $run['counts'],
            'errors' => $run['errors'], 'found' => count($run['found']), 'findings' => $run['findings'] ?? null,
            'portions' => $run['portions'],
        ];
    }

    /**
     * Runs a change of the state under a lock held for one portion only; null when another portion runs now.
     *
     * @param Closure(array<string,mixed>): array<string,mixed> $change
     * @return array<string,mixed>|null
     */
    private function locked(Closure $change): ?array
    {
        $directory = rtrim($this->layout->stateDirectory, '/');
        if (!is_dir($directory) && !@mkdir($directory, 0750, true) && !is_dir($directory)) {
            throw new IntegrityUnavailable('integrity_state_unwritable');
        }
        $handle = @fopen($this->layout->file(self::LOCK), 'c');
        if ($handle === false) {
            throw new IntegrityUnavailable('integrity_state_unwritable');
        }
        try {
            if (!flock($handle, LOCK_EX | LOCK_NB)) {
                return null;
            }
            $state = $change($this->state());
            if (!$this->layout->write(self::STATE, (string) json_encode($state, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE))) {
                throw new IntegrityUnavailable('integrity_state_unwritable');
            }

            return $state;
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    private function clock(): DateTimeImmutable
    {
        return $this->now !== null ? ($this->now)() : new DateTimeImmutable();
    }

    private function time(): int
    {
        return $this->clock()->getTimestamp();
    }
}
