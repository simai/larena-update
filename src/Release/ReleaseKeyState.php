<?php

declare(strict_types=1);

namespace Larena\UpdateClient\Release;

use DateTimeImmutable;
use JsonException;
use RuntimeException;
use Throwable;

/**
 * The release key lists this site accepted ({@see ReleaseKeyList}), in the shared storage
 * (`storage/app/larena/update/release-keys.json`) outside every release, merged over the keys the site ships with.
 *
 * What is kept is not a plain key list but the signed files themselves, as a chain: the first is signed by a shipped
 * key, every next one by a key the chain already trusts. Every read replays the chain from the shipped keys and checks
 * each signature again, so a key written into the storage by hand is never trusted; a broken chain is dropped as a
 * whole (the site falls back to its shipped keys, never to nothing it did not ship). A list signed again by the same
 * key replaces its predecessor; the chain only grows by one when the signing key itself changed.
 *
 * Rules:
 * - revoked ids always win, also over the shipped keys and `.env`; once seen, a revocation is kept;
 * - a key a list names with purpose `package-list` verifies only package lists ({@see trusted()} `package_list`) and
 *   never signs a key list; the purpose is replayed with the chain, never read from the storage alone;
 * - `list_version` only grows: a lower one, or the same number with other bytes, is ignored (`update_keys_stale`);
 *   an expired list is ignored too — with a warning, never a lock-out: what was accepted before stays;
 * - an invalid signature or shape is refused (`update_keys_signature_invalid`, `update_keys_invalid`) and logged by
 *   the caller; nothing changes.
 * {@see last()} keeps the outcome of the last refresh for the update diagnostics.
 */
final readonly class ReleaseKeyState
{
    private const MAX_CHAIN = 16;

    public function __construct(private string $file)
    {
    }

    public static function forSite(string $siteRoot): self
    {
        return new self(rtrim($siteRoot, '/') . '/storage/app/larena/update/release-keys.json');
    }

    /**
     * The keys to verify releases with: the shipped ones plus those the accepted lists add (from their `not_before`),
     * and every revoked id. A state that cannot be read or replayed gives the shipped keys and the kept revocations.
     *
     * @param array<string,string> $shipped key id => base64 public key (distribution, configuration, `.env`)
     * @param list<string>         $revoked configured revoked ids
     * @return array{keys: array<string,string>, revoked: list<string>, list_version: int, package_list: list<string>}
     */
    public function trusted(array $shipped, array $revoked, ?DateTimeImmutable $now = null): array
    {
        $now ??= new DateTimeImmutable();
        $state = $this->read();
        $replay = $this->replay($state['chain'], $shipped, $revoked, $now);
        $allRevoked = self::ids([...$revoked, ...$state['revoked'], ...$replay['revoked']]);
        $keys = $shipped;
        foreach ($replay['added'] as $id => $key) {
            $keys[$id] ??= $key;
        }

        $packageList = array_values(array_intersect($replay['package_list'], array_keys($replay['added'])));
        $packageList = array_values(array_filter($packageList, static fn (string $id): bool => !isset($shipped[$id])));

        return ['keys' => $keys, 'revoked' => $allRevoked, 'list_version' => $replay['list_version'], 'package_list' => self::ids($packageList)];
    }

    /**
     * A list fetched from a source: accepted, ignored (stale or expired) or refused. Nothing is written unless it is
     * accepted, except the outcome for the diagnostics.
     *
     * @param array<string,string> $shipped
     * @param list<string>         $revoked
     * @return array{result: string, reason: ?string, list_version: ?int, signed_by: ?string}
     */
    public function accept(string $bytes, string $signature, array $shipped, array $revoked, ?DateTimeImmutable $now = null): array
    {
        $now ??= new DateTimeImmutable();
        $state = $this->read();
        $replay = $this->replay($state['chain'], $shipped, $revoked, $now);
        $allRevoked = self::ids([...$revoked, ...$state['revoked'], ...$replay['revoked']]);
        $current = self::without($replay['steps'][count($replay['steps']) - 1], $allRevoked);
        try {
            $list = ReleaseKeyList::verify($bytes, $signature, $current, $allRevoked, $replay['package_list']);
        } catch (Throwable $exception) {
            return $this->outcome($state, 'refused', $exception->getMessage(), null, null, $now);
        }
        if ($list->listVersion < $replay['list_version'] || $list->listVersion < $state['list_version']
            || ($list->listVersion === $state['list_version'] && !self::isLast($state['chain'], $bytes))) {
            return $this->outcome($state, 'ignored', 'update_keys_stale', $list->listVersion, $list->signedBy, $now);
        }
        if ($list->expiresAt <= $now) {
            return $this->outcome($state, 'ignored', 'update_keys_expired', $list->listVersion, $list->signedBy, $now);
        }
        if (self::isLast($state['chain'], $bytes)) {
            return $this->outcome($state, 'unchanged', null, $list->listVersion, $list->signedBy, $now);
        }
        // Keep the shortest chain that proves the signer, then this list.
        $prefix = 0;
        while (!isset(self::without($replay['steps'][$prefix], $allRevoked)[$list->signedBy])) {
            ++$prefix;
        }
        $chain = [...array_slice($replay['chain'], 0, $prefix), ['bytes' => $bytes, 'signature' => $signature]];
        if (count($chain) > self::MAX_CHAIN) {
            return $this->outcome($state, 'ignored', 'update_keys_chain_too_long', $list->listVersion, $list->signedBy, $now);
        }
        $state['chain'] = $chain;
        $state['revoked'] = self::ids([...$state['revoked'], ...$list->revoked]);
        $state['list_version'] = $list->listVersion;

        return $this->outcome($state, 'accepted', null, $list->listVersion, $list->signedBy, $now);
    }

    /** @return array{result: string, reason: ?string, list_version: ?int, signed_by: ?string, at: string}|null */
    public function last(): ?array
    {
        $last = $this->read()['last'];

        return is_array($last) && is_string($last['result'] ?? null) && is_string($last['at'] ?? null) ? [
            'result' => $last['result'],
            'reason' => is_string($last['reason'] ?? null) ? $last['reason'] : null,
            'list_version' => is_int($last['list_version'] ?? null) ? $last['list_version'] : null,
            'signed_by' => is_string($last['signed_by'] ?? null) ? $last['signed_by'] : null,
            'at' => $last['at'],
        ] : null;
    }

    /** A refresh that found no list or could not reach a source: kept for the diagnostics, nothing else changes. */
    public function note(string $result, ?string $reason, ?DateTimeImmutable $now = null): void
    {
        $this->outcome($this->read(), $result, $reason, null, null, $now ?? new DateTimeImmutable());
    }

    /**
     * Replays the chain from the shipped keys. `steps[i]` is the trusted set after i lists (steps[0]: shipped).
     *
     * @param list<array{bytes:string, signature:string}> $chain
     * @param array<string,string>                        $shipped
     * @param list<string>                                $revoked
     * @return array{chain: list<array{bytes:string, signature:string}>, steps: list<array<string,string>>, added: array<string,string>,
     *     revoked: list<string>, list_version: int, package_list: list<string>}
     */
    private function replay(array $chain, array $shipped, array $revoked, DateTimeImmutable $now): array
    {
        $steps = [self::without($shipped, $revoked)];
        $added = [];
        $seenRevoked = [];
        $version = 0;
        $kept = [];
        // Once named a list key, an id stays one for the rest of the chain: a purpose only narrows.
        $packageList = [];
        $broken = ['chain' => [], 'steps' => [$steps[0]], 'added' => [], 'revoked' => [], 'list_version' => 0, 'package_list' => []];
        foreach ($chain as $entry) {
            try {
                $list = ReleaseKeyList::verify($entry['bytes'], $entry['signature'], self::without($steps[count($steps) - 1], [...$revoked, ...$seenRevoked]), [...$revoked, ...$seenRevoked], $packageList);
            } catch (Throwable) {
                // A broken chain is dropped whole: only the shipped keys stay.
                return $broken;
            }
            if ($list->listVersion <= $version) {
                return $broken;
            }
            $version = $list->listVersion;
            $seenRevoked = [...$seenRevoked, ...$list->revoked];
            $packageList = self::ids([...$packageList, ...$list->packageListKeyIds()]);
            $added = $list->keysAt($now);
            $steps[] = $shipped + $added;
            $kept[] = $entry;
        }

        return ['chain' => $kept, 'steps' => $steps, 'added' => $added, 'revoked' => self::ids($seenRevoked), 'list_version' => $version, 'package_list' => $packageList];
    }

    /**
     * @param array<string,string> $keys
     * @param list<string>         $revoked
     * @return array<string,string>
     */
    private static function without(array $keys, array $revoked): array
    {
        return array_diff_key($keys, array_flip($revoked));
    }

    /** @param list<array{bytes:string, signature:string}> $chain */
    private static function isLast(array $chain, string $bytes): bool
    {
        return $chain !== [] && hash_equals($chain[count($chain) - 1]['bytes'], $bytes);
    }

    /**
     * @param array<array-key,mixed> $ids
     * @return list<string>
     */
    private static function ids(array $ids): array
    {
        $ids = array_values(array_unique(array_filter($ids, static fn (mixed $id): bool => is_string($id) && preg_match('/\A[a-z0-9][a-z0-9._-]{0,63}\z/D', $id) === 1)));
        sort($ids, SORT_STRING);

        return $ids;
    }

    /**
     * @param array{chain: list<array{bytes:string, signature:string}>, revoked: list<string>, list_version: int, last: mixed} $state
     * @return array{result: string, reason: ?string, list_version: ?int, signed_by: ?string}
     */
    private function outcome(array $state, string $result, ?string $reason, ?int $listVersion, ?string $signedBy, DateTimeImmutable $now): array
    {
        $outcome = ['result' => $result, 'reason' => $reason, 'list_version' => $listVersion, 'signed_by' => $signedBy];
        $state['last'] = $outcome + ['at' => $now->format(DATE_ATOM)];
        $this->write($state);

        return $outcome;
    }

    /** @return array{chain: list<array{bytes:string, signature:string}>, revoked: list<string>, list_version: int, last: mixed} */
    private function read(): array
    {
        $empty = ['chain' => [], 'revoked' => [], 'list_version' => 0, 'last' => null];
        if (!is_file($this->file) || is_link($this->file)) {
            return $empty;
        }
        try {
            $data = json_decode((string) file_get_contents($this->file, false, null, 0, (self::MAX_CHAIN + 2) * (ReleaseKeyList::MAX_BYTES * 2)), true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return $empty;
        }
        if (!is_array($data)) {
            return $empty;
        }
        $chain = [];
        foreach (is_array($data['chain'] ?? null) ? $data['chain'] : [] as $entry) {
            $bytes = is_array($entry) && is_string($entry['bytes'] ?? null) ? base64_decode($entry['bytes'], true) : false;
            $signature = is_array($entry) && is_string($entry['signature'] ?? null) ? base64_decode($entry['signature'], true) : false;
            if (!is_string($bytes) || !is_string($signature)) {
                return ['chain' => [], 'revoked' => self::ids((array) ($data['revoked'] ?? [])), 'list_version' => 0, 'last' => $data['last'] ?? null];
            }
            $chain[] = ['bytes' => $bytes, 'signature' => $signature];
        }

        return [
            'chain' => array_slice($chain, 0, self::MAX_CHAIN),
            'revoked' => self::ids(is_array($data['revoked'] ?? null) ? $data['revoked'] : []),
            'list_version' => is_int($data['list_version'] ?? null) ? $data['list_version'] : 0,
            'last' => $data['last'] ?? null,
        ];
    }

    /** @param array{chain: list<array{bytes:string, signature:string}>, revoked: list<string>, list_version: int, last: mixed} $state */
    private function write(array $state): void
    {
        $directory = dirname($this->file);
        if (is_link($directory) || is_link($this->file) || (!is_dir($directory) && !@mkdir($directory, 0750, true) && !is_dir($directory))) {
            throw new RuntimeException('update_keys_state_unwritable');
        }
        $data = [
            'schema' => 'larena.release-keys-state',
            'chain' => array_map(static fn (array $entry): array => ['bytes' => base64_encode($entry['bytes']), 'signature' => base64_encode($entry['signature'])], $state['chain']),
            'revoked' => $state['revoked'],
            'list_version' => $state['list_version'],
            'last' => $state['last'],
        ];
        $bytes = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . "\n";
        $temporary = $this->file . '.' . bin2hex(random_bytes(4));
        if (@file_put_contents($temporary, $bytes) !== strlen($bytes) || !@rename($temporary, $this->file)) {
            @unlink($temporary);
            throw new RuntimeException('update_keys_state_unwritable');
        }
    }
}
