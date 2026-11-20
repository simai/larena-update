<?php

declare(strict_types=1);

namespace Larena\UpdateClient\Integrity;

use Closure;
use Throwable;

/**
 * A2: the main files of the release (`core_files` of the signed list: the entry point, the autoloader, the bootstrap,
 * the configuration, every `.htaccess`) checked on every request within a fraction of a millisecond.
 *
 * The answer per file is kept in `core.json` with the file's fingerprint (size, times, inode,
 * {@see IntegrityLayout::stat()}); while the fingerprint is unchanged the kept answer is used, so a request only
 * stats the files. A changed fingerprint hashes that one file again (SHA-256 against the list). The signed list
 * itself is verified again only when one of its three files changes. The guard never stops a request: a failure is
 * the answer `unknown`, and the site and the admin keep working so the person can press «Вернуть как было».
 */
final readonly class CoreFileGuard
{
    private const STATE = 'core.json';

    /** The short answer: one line `<key> <status> <reason|-> <count>`, then the main files, one per line. */
    private const QUICK = 'core.quick';

    /** @param Closure(): SignedBaseline $baseline loads and verifies the signed list (throws IntegrityUnavailable) */
    public function __construct(
        private IntegrityLayout $layout,
        private Closure $baseline,
    ) {
    }

    public function check(): CoreCheck
    {
        try {
            return $this->run();
        } catch (Throwable $failure) {
            return new CoreCheck(CoreCheck::UNKNOWN, [], $failure instanceof IntegrityUnavailable ? $failure->reason : 'integrity_check_failed');
        }
    }

    /** Forgets the kept answers: the next request checks every main file again (after «Вернуть как было»). */
    public function forget(): void
    {
        @unlink($this->layout->file(self::QUICK));
        @unlink($this->layout->file(self::STATE));
    }

    private function run(): CoreCheck
    {
        clearstatcache();
        $quick = $this->quick();
        if ($quick !== null) {
            return $quick;
        }
        $kept = $this->layout->read(self::STATE) ?? [];
        $fingerprint = SignedBaseline::fingerprint($this->layout);
        $changed = false;
        if (($kept['list'] ?? null) !== $fingerprint) {
            $kept = ['list' => $fingerprint, 'status' => null, 'reason' => null, 'core' => []];
            $changed = true;
            try {
                $baseline = ($this->baseline)();
                foreach ($baseline->list->coreFiles as $path) {
                    $entry = $baseline->list->entries[$path];
                    $kept['core'][$path] = ['sha256' => $entry['sha256'], 'bytes' => $entry['bytes'], 'stat' => null, 'problem' => null];
                }
            } catch (IntegrityUnavailable $unavailable) {
                $kept['status'] = $unavailable->reason === 'integrity_list_missing' ? CoreCheck::NO_LIST : CoreCheck::UNKNOWN;
                $kept['reason'] = $unavailable->reason;
            }
        }
        $before = is_string($kept['status'] ?? null) ? $kept['status'] : null;
        if (($kept['reason'] ?? null) !== null) {
            $status = (string) $kept['status'];
            $check = new CoreCheck($status, [], (string) $kept['reason'], $changed && $before !== $status);
            if ($changed) {
                $this->layout->write(self::STATE, (string) json_encode($kept, JSON_UNESCAPED_SLASHES));
            }
            $this->keepQuick($status, (string) $kept['reason'], []);

            return $check;
        }

        $problems = [];
        $root = $this->layout->root(IntegrityLayout::RELEASE);
        foreach ($kept['core'] as $path => $entry) {
            $full = $root . '/' . $path;
            $stat = IntegrityLayout::stat($full);
            if ($stat === null || $stat !== $entry['stat']) {
                $next = $entry;
                $next['problem'] = $this->problem($full, $stat, (int) $entry['bytes'], (string) $entry['sha256']);
                // A fingerprint of the last two seconds is not kept: the file is hashed again until it settles.
                $next['stat'] = IntegrityLayout::settled($stat) ? $stat : null;
                if ($next !== $entry) {
                    $kept['core'][$path] = $next;
                    $changed = true;
                }
                $entry = $next;
            }
            if ($entry['problem'] !== null) {
                $problems[$path] = (string) $entry['problem'];
            }
        }
        $status = $problems === [] ? CoreCheck::OK : CoreCheck::CHANGED;
        $previousProblems = is_array($kept['problems'] ?? null) ? $kept['problems'] : [];
        $transitioned = $before !== $status || $previousProblems !== $problems;
        if ($changed || $transitioned) {
            $kept['status'] = $status;
            $kept['problems'] = $problems;
            $this->layout->write(self::STATE, (string) json_encode($kept, JSON_UNESCAPED_SLASHES));
        }
        if ($status === CoreCheck::OK) {
            $this->keepQuick($status, '-', array_keys($kept['core']));
        } else {
            @unlink($this->layout->file(self::QUICK));
        }

        return new CoreCheck($status, $problems, null, $transitioned, count($kept['core']));
    }

    /**
     * The hot path: a request where nothing changed reads one short line and stats the files, without decoding the
     * kept answers. The key covers the fingerprints of the three signed files and of every main file, so any change
     * misses it and the full path above decides. Only `changed` is never kept here: it is always decided in full.
     */
    private function quick(): ?CoreCheck
    {
        $bytes = @file_get_contents($this->layout->file(self::QUICK));
        if (!is_string($bytes) || $bytes === '') {
            return null;
        }
        $lines = explode("\n", $bytes);
        $head = explode(' ', (string) array_shift($lines));
        if (count($head) !== 4) {
            return null;
        }
        [$key, $status, $reason, $count] = $head;
        $paths = $lines === [''] ? [] : $lines;
        if (count($paths) !== (int) $count || !hash_equals($key, $this->key($paths))) {
            return null;
        }

        return match ($status) {
            CoreCheck::OK => new CoreCheck(CoreCheck::OK, [], null, false, count($paths)),
            CoreCheck::NO_LIST, CoreCheck::UNKNOWN => $reason === '-' ? null : new CoreCheck($status, [], $reason),
            default => null,
        };
    }

    /** @param list<string> $paths */
    private function keepQuick(string $status, string $reason, array $paths): void
    {
        $stats = [];
        foreach (explode('|', SignedBaseline::fingerprint($this->layout)) as $stat) {
            $stats[] = $stat;
        }
        $root = $this->layout->root(IntegrityLayout::RELEASE);
        foreach ($paths as $path) {
            $stats[] = IntegrityLayout::stat($root . '/' . $path) ?? '-';
        }
        foreach ($stats as $stat) {
            // A fingerprint of the last two seconds may still change within the same second: not kept.
            if ($stat !== '-' && !IntegrityLayout::settled($stat)) {
                @unlink($this->layout->file(self::QUICK));

                return;
            }
        }
        if (preg_match('/\A[a-z0-9_]{1,80}\z/D', $reason) !== 1 && $reason !== '-') {
            return;
        }
        $line = $this->key($paths) . ' ' . $status . ' ' . $reason . ' ' . count($paths);
        $this->layout->write(self::QUICK, $paths === [] ? $line : $line . "\n" . implode("\n", $paths));
    }

    /** @param list<string> $paths */
    private function key(array $paths): string
    {
        $key = SignedBaseline::fingerprint($this->layout);
        $root = $this->layout->root(IntegrityLayout::RELEASE);
        foreach ($paths as $path) {
            $key .= "\n" . (IntegrityLayout::stat($root . '/' . $path) ?? '-');
        }

        return hash('xxh128', $key);
    }

    private function problem(string $full, ?string $stat, int $bytes, string $sha256): ?string
    {
        if ($stat === null) {
            return 'missing';
        }
        if (is_link($full) || !is_file($full)) {
            return 'type_differs';
        }
        if (filesize($full) !== $bytes) {
            return 'size_differs';
        }
        $actual = @hash_file('sha256', $full);

        return is_string($actual) && hash_equals($sha256, $actual) ? null : 'checksum_differs';
    }
}
