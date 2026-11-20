<?php

declare(strict_types=1);

namespace Larena\UpdateClient\Integrity;

use DateTimeImmutable;
use DateTimeInterface;
use RuntimeException;

/**
 * The findings of the integrity checks (`findings.json` in the state folder), as the security spec asks: a stable key
 * per file and kind ({@see Finding::key()}); a finding seen again updates the one there (last_seen, occurrence_count),
 * never adds another; a complete check that no longer sees it resolves it. States: `open`, `accepted_exception` (a
 * person accepted the change for a scope until a date) and `resolved`. An exception names its scope (one path, or a
 * folder with `/*`), its end date, the reason and who accepted it; past its end the finding is open again.
 *
 * Kept in a file, not the database: it must work when the database does not, and on any hosting.
 */
final readonly class FindingStore
{
    private const FILE = 'findings.json';
    public const OPEN = 'open';
    public const EXCEPTION = 'accepted_exception';
    public const RESOLVED = 'resolved';

    /** At most this many resolved findings are kept (newest first); open ones are always kept. */
    private const KEEP_RESOLVED = 500;

    public function __construct(private IntegrityLayout $layout)
    {
    }

    /**
     * Records what a check found: each one opened or updated; a complete check also resolves the open ones it did not
     * see again.
     *
     * @param list<array{key:string,kind:string,root:string,path:string,expected_sha256:?string,actual_sha256:?string,bytes:?int}> $found
     * @return array{open:int,accepted:int,resolved:int}
     */
    public function merge(array $found, DateTimeImmutable $now, string $runId, bool $complete = true): array
    {
        $data = $this->load();
        $at = $now->format(DateTimeInterface::ATOM);
        $seen = [];
        foreach ($found as $item) {
            $key = $item['key'];
            $seen[$key] = true;
            $existing = $data['findings'][$key] ?? null;
            $finding = is_array($existing) && ($existing['state'] ?? null) !== self::RESOLVED
                ? $existing + $item
                : $item + ['first_seen' => $at, 'occurrence_count' => 0];
            $finding['actual_sha256'] = $item['actual_sha256'];
            $finding['bytes'] = $item['bytes'];
            $finding['last_seen'] = $at;
            $finding['run'] = $runId;
            $finding['occurrence_count'] = (int) ($finding['occurrence_count'] ?? 0) + 1;
            $finding['state'] = $this->exceptionFor($data['exceptions'], $finding, $now) !== null ? self::EXCEPTION : self::OPEN;
            unset($finding['resolved_at']);
            $data['findings'][$key] = $finding;
        }
        // Only a complete check may say a finding is gone: what could not be read was not compared.
        foreach ($complete ? $data['findings'] : [] as $key => $finding) {
            if (!isset($seen[$key]) && ($finding['state'] ?? null) !== self::RESOLVED) {
                $data['findings'][$key]['state'] = self::RESOLVED;
                $data['findings'][$key]['resolved_at'] = $at;
                $data['findings'][$key]['resolved_by_run'] = $runId;
            }
        }
        $this->save($data);

        return $this->counts($data);
    }

    /** @return array{open:int,accepted:int,resolved:int} */
    public function counts(?array $data = null): array
    {
        $data ??= $this->load();
        $now = new DateTimeImmutable();
        $counts = ['open' => 0, 'accepted' => 0, 'resolved' => 0];
        foreach ($data['findings'] as $finding) {
            $state = $this->state($data['exceptions'], $finding, $now);
            $counts[$state === self::OPEN ? 'open' : ($state === self::EXCEPTION ? 'accepted' : 'resolved')]++;
        }

        return $counts;
    }

    /**
     * Every finding with its state now (an exception past its end opens the finding again), open ones first.
     *
     * @return list<array<string,mixed>>
     */
    public function all(?DateTimeImmutable $now = null): array
    {
        $now ??= new DateTimeImmutable();
        $data = $this->load();
        $list = [];
        foreach ($data['findings'] as $finding) {
            $finding['state'] = $this->state($data['exceptions'], $finding, $now);
            $finding['exception'] = $this->exceptionFor($data['exceptions'], $finding, $now);
            $list[] = $finding;
        }
        $rank = [self::OPEN => 0, self::EXCEPTION => 1, self::RESOLVED => 2];
        usort($list, static fn (array $a, array $b): int => [$rank[$a['state']] ?? 3, (string) ($b['last_seen'] ?? ''), (string) $a['path']]
            <=> [$rank[$b['state']] ?? 3, (string) ($a['last_seen'] ?? ''), (string) $b['path']]);

        return $list;
    }

    /** @return array<string,mixed>|null */
    public function get(string $key, ?DateTimeImmutable $now = null): ?array
    {
        foreach ($this->all($now) as $finding) {
            if ($finding['key'] === $key) {
                return $finding;
            }
        }

        return null;
    }

    /** @return list<array<string,mixed>> the open findings now */
    public function open(?DateTimeImmutable $now = null): array
    {
        return array_values(array_filter($this->all($now), static fn (array $finding): bool => $finding['state'] === self::OPEN));
    }

    /**
     * Accepts a change as an exception for a scope until a date: one path (`public/robots.php`) or a folder
     * (`storage/app/public/plugins/*`), as the person reads paths ({@see IntegrityLayout::label()}).
     *
     * @return array{id:string,scope:string,until:string,reason:string,by:string,created_at:string}
     */
    public function addException(string $scope, DateTimeImmutable $until, string $reason, string $by, DateTimeImmutable $now): array
    {
        $scope = trim($scope);
        if ($scope === '' || strlen($scope) > 1024 || str_contains($scope, '..') || $scope[0] === '/' || preg_match('/[\x00-\x1f\x7f]/', $scope) === 1
            || (str_contains($scope, '*') && !str_ends_with($scope, '/*')) || substr_count($scope, '*') > 1) {
            throw new RuntimeException('integrity_exception_scope_invalid');
        }
        if ($until <= $now || $until > $now->modify('+366 days')) {
            throw new RuntimeException('integrity_exception_until_invalid');
        }
        $reason = trim($reason);
        if ($reason === '' || mb_strlen($reason) > 500) {
            throw new RuntimeException('integrity_exception_reason_invalid');
        }
        $data = $this->load();
        $exception = ['id' => bin2hex(random_bytes(8)), 'scope' => $scope, 'until' => $until->format(DateTimeInterface::ATOM),
            'reason' => $reason, 'by' => $by, 'created_at' => $now->format(DateTimeInterface::ATOM)];
        $data['exceptions'][] = $exception;
        foreach ($data['findings'] as $key => $finding) {
            if (($finding['state'] ?? null) === self::OPEN && self::covers($scope, IntegrityLayout::label((string) $finding['root'], (string) $finding['path']))) {
                $data['findings'][$key]['state'] = self::EXCEPTION;
            }
        }
        $this->save($data);

        return $exception;
    }

    /** @return list<array<string,mixed>> */
    public function exceptions(): array
    {
        return array_values($this->load()['exceptions']);
    }

    /** Whether an exception's scope covers a path as the person reads it. */
    public static function covers(string $scope, string $label): bool
    {
        return str_ends_with($scope, '/*') ? str_starts_with($label, substr($scope, 0, -1)) : $scope === $label;
    }

    /**
     * @param list<array<string,mixed>> $exceptions
     * @param array<string,mixed>       $finding
     */
    private function state(array $exceptions, array $finding, DateTimeImmutable $now): string
    {
        if (($finding['state'] ?? null) === self::RESOLVED) {
            return self::RESOLVED;
        }

        return $this->exceptionFor($exceptions, $finding, $now) !== null ? self::EXCEPTION : self::OPEN;
    }

    /**
     * @param list<array<string,mixed>> $exceptions
     * @param array<string,mixed>       $finding
     * @return array<string,mixed>|null
     */
    private function exceptionFor(array $exceptions, array $finding, DateTimeImmutable $now): ?array
    {
        // The archive the restore needs is never accepted away.
        if (in_array($finding['kind'] ?? null, ['archive_damaged', 'archive_missing'], true)) {
            return null;
        }
        $label = IntegrityLayout::label((string) ($finding['root'] ?? ''), (string) ($finding['path'] ?? ''));
        foreach ($exceptions as $exception) {
            $until = DateTimeImmutable::createFromFormat(DateTimeInterface::ATOM, (string) ($exception['until'] ?? ''));
            if ($until !== false && $until > $now && self::covers((string) ($exception['scope'] ?? ''), $label)) {
                return $exception;
            }
        }

        return null;
    }

    /** @return array{findings:array<string,array<string,mixed>>,exceptions:list<array<string,mixed>>} */
    private function load(): array
    {
        $data = $this->layout->read(self::FILE) ?? [];

        return [
            'findings' => is_array($data['findings'] ?? null) ? $data['findings'] : [],
            'exceptions' => is_array($data['exceptions'] ?? null) ? array_values($data['exceptions']) : [],
        ];
    }

    /** @param array{findings:array<string,array<string,mixed>>,exceptions:list<array<string,mixed>>} $data */
    private function save(array $data): void
    {
        $resolved = array_filter($data['findings'], static fn (array $finding): bool => ($finding['state'] ?? null) === self::RESOLVED);
        if (count($resolved) > self::KEEP_RESOLVED) {
            uasort($resolved, static fn (array $a, array $b): int => strcmp((string) ($b['resolved_at'] ?? ''), (string) ($a['resolved_at'] ?? '')));
            foreach (array_slice(array_keys($resolved), self::KEEP_RESOLVED) as $key) {
                unset($data['findings'][$key]);
            }
        }
        if (!$this->layout->write(self::FILE, (string) json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE))) {
            throw new RuntimeException('integrity_state_write_failed');
        }
    }
}
