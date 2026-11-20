<?php

declare(strict_types=1);

namespace Larena\UpdateClient\Integrity;

use Closure;
use DateTimeImmutable;
use DateTimeInterface;
use Larena\UpdateClient\Release\ReleaseArchiveExtractor;
use Throwable;

/**
 * A4, «Вернуть как было»: one action, through plan → confirmation, never by itself.
 *
 * - Clean release files come from the archive of the running release kept in `<release>/.larena-signed/`, trusted
 *   through the signed `release.json` (its sha256) and checked file by file against the signed list
 *   ({@see ReleaseArchiveExtractor::restoreEntries()}).
 * - Extra PHP and `.htaccess` files are not deleted: they are moved to the quarantine (`<state>/quarantine/`, in the
 *   storage, outside the web root, renamed `<id>.quarantined` so nothing runs them) with their place, size and sha256,
 *   and can be put back ({@see returnItem()}).
 * - Every action goes to «История»; a full check follows every restore, and its result is the outcome.
 */
final readonly class IntegrityRestore
{
    private const LOCK = 'restore.lock';
    private const INDEX = 'quarantine.json';

    /**
     * @param Closure(): SignedBaseline           $baseline
     * @param (Closure(): DateTimeImmutable)|null $now
     */
    public function __construct(
        private IntegrityLayout $layout,
        private Closure $baseline,
        private FindingStore $findings,
        private IntegrityScanner $scanner,
        private CoreFileGuard $guard,
        private IntegrityHistory $history,
        private ReleaseArchiveExtractor $extractor = new ReleaseArchiveExtractor(),
        private ?Closure $now = null,
    ) {
    }

    /** The plan now: from the open findings (one, with `$only`) and the main files the request check sees changed. */
    public function plan(?string $only = null): RestorePlan
    {
        try {
            $baseline = ($this->baseline)();
        } catch (IntegrityUnavailable $unavailable) {
            return new RestorePlan('', [], [], [$unavailable->reason]);
        }
        $list = $baseline->list;
        $candidates = [];
        foreach ($this->findings->open() as $finding) {
            if ($only === null || $finding['key'] === $only) {
                $candidates[] = $finding;
            }
        }
        if ($only === null) {
            foreach ($this->guard->check()->problems as $path => $problem) {
                $candidates[] = Finding::make($problem === 'missing' ? 'deleted' : 'changed', IntegrityLayout::RELEASE, $path);
            }
        }
        $restore = [];
        $quarantine = [];
        $skipped = [];
        foreach ($candidates as $finding) {
            $kind = (string) $finding['kind'];
            $root = (string) $finding['root'];
            $path = (string) $finding['path'];
            if (in_array($kind, Finding::RESTORED, true) && $root === IntegrityLayout::RELEASE) {
                if (isset($restore[$path]) || ($list->entries[$path]['type'] ?? null) !== 'file') {
                    continue;
                }
                $full = $this->layout->path(IntegrityLayout::RELEASE, $path);
                if (is_link($full) || is_dir($full)) {
                    $skipped[] = ['path' => $path, 'reason' => 'integrity_place_taken'];
                    continue;
                }
                $entry = $list->entries[$path];
                if (is_file($full) && filesize($full) === $entry['bytes'] && hash_equals((string) $entry['sha256'], (string) @hash_file('sha256', $full))) {
                    continue;
                }
                $restore[$path] = ['path' => $path, 'kind' => $kind];
            } elseif (in_array($kind, Finding::QUARANTINED, true)) {
                $label = IntegrityLayout::label($root, $path);
                if (isset($quarantine[$label]) || ($root === IntegrityLayout::RELEASE && isset($list->entries[$path]))) {
                    continue;
                }
                try {
                    $full = $this->layout->path($root, $path);
                } catch (Throwable) {
                    continue;
                }
                if (is_link($full) || !is_file($full)) {
                    continue;
                }
                $sha = @hash_file('sha256', $full);
                if (!is_string($sha)) {
                    $skipped[] = ['path' => $label, 'reason' => 'integrity_file_unreadable'];
                    continue;
                }
                $quarantine[$label] = ['root' => $root, 'path' => $path, 'sha256' => $sha, 'bytes' => (int) filesize($full), 'kind' => $kind];
            }
        }
        ksort($restore, SORT_STRING);
        ksort($quarantine, SORT_STRING);
        $blockers = [];
        if ($restore !== []) {
            $archive = $baseline->archivePath;
            if (is_link($archive) || !is_file($archive)) {
                $blockers[] = 'integrity_archive_missing';
            } else {
                $sha = @hash_file('sha256', $archive);
                if (!is_string($sha) || filesize($archive) !== $baseline->release->archiveBytes
                    || !hash_equals($baseline->release->archiveSha256, $sha) || !hash_equals($list->archiveSha256, $sha)) {
                    $blockers[] = 'integrity_archive_damaged';
                }
            }
        }

        return new RestorePlan($list->sha256, array_values($restore), array_values($quarantine), $blockers, $skipped);
    }

    /**
     * Runs the plan the person confirmed: refused unless the plan now has the same digest. Then the full check.
     *
     * @return array{restored:int,quarantined:int,result:?string,reason:?string,check:string}
     */
    public function run(string $digest, string $actor, ?string $only = null): array
    {
        return $this->locked(function () use ($digest, $actor, $only): array {
            $plan = $this->plan($only);
            if (!hash_equals($plan->digest(), $digest)) {
                $this->history->record(IntegrityHistory::PREFIX . 'restore.refused', $actor, ['reason' => 'integrity_plan_changed']);
                throw new IntegrityRefused('integrity_plan_changed');
            }
            if ($plan->blocked()) {
                $this->history->record(IntegrityHistory::PREFIX . 'restore.refused', $actor, ['reason' => $plan->blockers[0]]);
                throw new IntegrityRefused($plan->blockers[0]);
            }
            if ($plan->empty()) {
                throw new IntegrityRefused('integrity_nothing_to_restore');
            }
            $restored = 0;
            $quarantined = 0;
            try {
                if ($plan->restore !== []) {
                    $baseline = ($this->baseline)();
                    $restored = count($this->extractor->restoreEntries($baseline->archivePath, $baseline->list,
                        array_map(static fn (array $item): string => $item['path'], $plan->restore), $this->layout->releaseRoot));
                }
                foreach ($plan->quarantine as $item) {
                    $this->moveToQuarantine($item, $actor);
                    $quarantined++;
                }
            } catch (Throwable $failure) {
                $this->guard->forget();
                $reason = $failure instanceof IntegrityRefused ? $failure->reason : self::code($failure);
                $this->history->record(IntegrityHistory::PREFIX . 'restore.failed', $actor, [
                    'reason' => $reason, 'restored' => $restored, 'quarantined' => $quarantined, 'plan' => $plan->digest(),
                ]);
                throw new IntegrityRefused($reason);
            }
            // The check after the restore is part of it: its result is what the person is told.
            $this->guard->forget();
            $this->scanner->start('restore', true);
            $state = $this->scanner->runToEnd();
            $check = (array) ($state['current'] ?? []);
            $this->history->record(IntegrityHistory::PREFIX . 'restore.succeeded', $actor, [
                'restored' => $restored, 'quarantined' => $quarantined, 'plan' => $plan->digest(),
                'check' => (string) ($check['id'] ?? ''), 'result' => (string) ($check['result'] ?? ''),
            ]);
            $this->history->record(IntegrityHistory::PREFIX . 'check.completed', $actor, self::checkFacts($check));

            return ['restored' => $restored, 'quarantined' => $quarantined, 'result' => $check['result'] ?? null,
                'reason' => $check['reason'] ?? null, 'check' => (string) ($check['id'] ?? '')];
        });
    }

    /** @param array<string,mixed> $check @return array<string,scalar|null> */
    public static function checkFacts(array $check): array
    {
        $counts = is_array($check['counts'] ?? null) ? $check['counts'] : [];
        $findings = is_array($check['findings'] ?? null) ? $check['findings'] : [];

        return [
            'check' => (string) ($check['id'] ?? ''), 'trigger' => (string) ($check['trigger'] ?? ''),
            'result' => (string) ($check['result'] ?? ''), 'reason' => isset($check['reason']) ? (string) $check['reason'] : null,
            'files' => (int) ($counts['checked'] ?? 0), 'hashed' => (int) ($counts['hashed'] ?? 0), 'errors' => (int) ($counts['errors'] ?? 0),
            'open' => (int) ($findings['open'] ?? 0), 'accepted' => (int) ($findings['accepted'] ?? 0),
        ];
    }

    /**
     * What waits in the quarantine, newest first.
     *
     * @return list<array<string,mixed>>
     */
    public function quarantine(): array
    {
        $items = array_values(array_filter($this->index(), static fn (array $item): bool => ($item['returned_at'] ?? null) === null));
        usort($items, static fn (array $a, array $b): int => strcmp((string) $b['moved_at'], (string) $a['moved_at']));

        return $items;
    }

    /** @return array<string,mixed>|null */
    public function quarantined(string $id): ?array
    {
        foreach ($this->quarantine() as $item) {
            if ($item['id'] === $id) {
                return $item;
            }
        }

        return null;
    }

    /** The digest a return from the quarantine is confirmed with: the item and its place, as they are now. */
    public function returnDigest(string $id): ?string
    {
        $item = $this->quarantined($id);
        if ($item === null) {
            return null;
        }
        $place = $this->layout->path((string) $item['root'], (string) $item['path']);

        return hash('sha256', $id . "\0" . $item['sha256'] . "\0" . (file_exists($place) || is_link($place) ? 'taken' : 'free'));
    }

    /** Puts a quarantined file back where it was: refused if its place is taken or the copy is not the one moved. */
    public function returnItem(string $id, string $digest, string $actor): void
    {
        $this->locked(function () use ($id, $digest, $actor): array {
            $item = $this->quarantined($id) ?? throw new IntegrityRefused('integrity_quarantine_unknown');
            $expected = $this->returnDigest($id);
            if ($expected === null || !hash_equals($expected, $digest)) {
                throw new IntegrityRefused('integrity_plan_changed');
            }
            $place = $this->layout->path((string) $item['root'], (string) $item['path']);
            if (file_exists($place) || is_link($place)) {
                throw new IntegrityRefused('integrity_place_taken');
            }
            $kept = $this->layout->quarantineDirectory() . '/' . $id . '.quarantined';
            $sha = is_file($kept) && !is_link($kept) ? @hash_file('sha256', $kept) : false;
            if (!is_string($sha) || !hash_equals((string) $item['sha256'], $sha)) {
                throw new IntegrityRefused('integrity_quarantine_damaged');
            }
            $parent = dirname($place);
            if (!is_dir($parent) && !@mkdir($parent, 0755, true) && !is_dir($parent)) {
                throw new IntegrityRefused('integrity_place_unwritable');
            }
            if (!self::move($kept, $place)) {
                throw new IntegrityRefused('integrity_place_unwritable');
            }
            $index = $this->index();
            $index[$id]['returned_at'] = $this->clock()->format(DateTimeInterface::ATOM);
            $index[$id]['returned_by'] = $actor;
            $this->saveIndex($index);
            $this->history->record(IntegrityHistory::PREFIX . 'quarantine.returned', $actor, [
                'path' => IntegrityLayout::label((string) $item['root'], (string) $item['path']), 'sha256' => (string) $item['sha256'],
            ]);

            return [];
        });
    }

    /** @param array{root:string,path:string,sha256:string,bytes:int,kind:string} $item */
    private function moveToQuarantine(array $item, string $actor): void
    {
        $source = $this->layout->path($item['root'], $item['path']);
        $sha = is_file($source) && !is_link($source) ? @hash_file('sha256', $source) : false;
        if (!is_string($sha) || !hash_equals($item['sha256'], $sha)) {
            throw new IntegrityRefused('integrity_plan_changed');
        }
        $directory = $this->layout->quarantineDirectory();
        if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new IntegrityRefused('integrity_quarantine_unwritable');
        }
        // A second guard if the storage is ever served: nothing in this folder is given out or run.
        if (!is_file($directory . '/.htaccess')) {
            @file_put_contents($directory . '/.htaccess', "Require all denied\nDeny from all\n");
        }
        $id = bin2hex(random_bytes(10));
        $target = $directory . '/' . $id . '.quarantined';
        if (!self::move($source, $target)) {
            throw new IntegrityRefused('integrity_quarantine_unwritable');
        }
        @chmod($target, 0600);
        $index = $this->index();
        $index[$id] = [
            'id' => $id, 'root' => $item['root'], 'path' => $item['path'], 'kind' => $item['kind'], 'sha256' => $item['sha256'],
            'bytes' => $item['bytes'], 'moved_at' => $this->clock()->format(DateTimeInterface::ATOM), 'moved_by' => $actor, 'returned_at' => null,
        ];
        $this->saveIndex($index);
    }

    /** A rename, or a copy checked by size and removed after where the folders lie on different disks. */
    private static function move(string $from, string $to): bool
    {
        if (@rename($from, $to)) {
            return true;
        }
        if (!@copy($from, $to) || filesize($to) !== filesize($from) || hash_file('sha256', $to) !== hash_file('sha256', $from)) {
            @unlink($to);

            return false;
        }

        return @unlink($from);
    }

    /** @return array<string,array<string,mixed>> */
    private function index(): array
    {
        $index = $this->layout->read(self::INDEX) ?? [];

        return array_filter($index, static fn (mixed $item): bool => is_array($item) && is_string($item['id'] ?? null));
    }

    /** @param array<string,array<string,mixed>> $index */
    private function saveIndex(array $index): void
    {
        if (!$this->layout->write(self::INDEX, (string) json_encode($index, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE))) {
            throw new IntegrityRefused('integrity_quarantine_unwritable');
        }
    }

    /**
     * @template T
     * @param Closure(): T $work
     * @return T
     */
    private function locked(Closure $work): mixed
    {
        $directory = rtrim($this->layout->stateDirectory, '/');
        if (!is_dir($directory) && !@mkdir($directory, 0750, true) && !is_dir($directory)) {
            throw new IntegrityRefused('integrity_state_unwritable');
        }
        $handle = @fopen($this->layout->file(self::LOCK), 'c');
        if ($handle === false || !flock($handle, LOCK_EX | LOCK_NB)) {
            if ($handle !== false) {
                fclose($handle);
            }
            throw new IntegrityRefused('integrity_restore_busy');
        }
        try {
            return $work();
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    private function clock(): DateTimeImmutable
    {
        return $this->now !== null ? ($this->now)() : new DateTimeImmutable();
    }

    private static function code(Throwable $failure): string
    {
        $message = $failure->getMessage();

        return preg_match('/\A[a-z][a-z0-9_]{0,80}\z/D', $message) === 1 ? $message : 'integrity_restore_failed';
    }
}
