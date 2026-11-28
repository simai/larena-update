<?php

declare(strict_types=1);

namespace Larena\UpdateClient\Release;

use DateTimeImmutable;
use DateTimeZone;
use JsonException;
use RuntimeException;
use Throwable;

/**
 * One signed release key list (`keys.json` + `keys.json.sig`, matrix 6 and 44): how release keys change without a new
 * build — «the old key signs the new one» — and how a key is revoked by a signed file.
 *
 * `{"schema":"larena.release-keys","list_version":int,"expires_at":iso,"signed_by":"id",
 * "keys":[{"id","public_key","not_before"}],"revoked":["id",...]}` (`signed_by`: the id of the signing key), with a detached Ed25519 signature over its exact bytes in the format of `release.json.sig`
 * (`{"key_id","signature"}`). The signature must come from a key the site already trusts — shipped with the site,
 * from `.env`, or accepted from an earlier list — and not revoked; a key named in this list is never trusted to sign
 * it (a key can never authorise itself). `not_before` (ISO 8601 with offset): before it the new key signs nothing.
 *
 * Refusals: `update_keys_signature_invalid` (unknown, revoked or wrong signature — the reason code names which),
 * `update_keys_invalid` (shape, `signed_by` not the signing key, a key id that already names another key, a list that revokes its own signer).
 * Freshness (`list_version`, `expires_at`) is judged by {@see ReleaseKeyState}, which keeps what is accepted.
 */
final readonly class ReleaseKeyList
{
    public const SCHEMA = 'larena.release-keys';
    public const MAX_BYTES = 65536;
    private const KEY_ID = '/\A[a-z0-9][a-z0-9._-]{0,63}\z/D';

    /**
     * @param array<string,string>        $keys       key id => base64 public key, every one valid from `not_before`
     * @param array<string,DateTimeImmutable> $notBefore key id => from when it may sign
     * @param list<string>                $revoked
     */
    private function __construct(
        public int $listVersion,
        public DateTimeImmutable $expiresAt,
        public array $keys,
        public array $notBefore,
        public array $revoked,
        public string $signedBy,
    ) {
    }

    /**
     * @param array<string,string> $trusted key id => base64 public key the site trusts now (revoked ones left out)
     * @param list<string>         $revoked key ids no longer trusted
     */
    public static function verify(string $bytes, string $signature, array $trusted, array $revoked): self
    {
        if ($trusted === []) {
            throw new RuntimeException('update_keys_signature_invalid');
        }
        try {
            $signer = (new ReleaseSignatureVerifier($trusted, $revoked))->signedBy($bytes, $signature, self::MAX_BYTES);
        } catch (Throwable $exception) {
            throw new RuntimeException($exception->getMessage() === 'release_files_invalid' ? 'update_keys_invalid' : 'update_keys_signature_invalid', 0, $exception);
        }
        try {
            $data = json_decode($bytes, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new RuntimeException('update_keys_invalid');
        }
        if (!is_array($data) || array_is_list($data) || ($data['schema'] ?? null) !== self::SCHEMA || ($data['signed_by'] ?? null) !== $signer) {
            throw new RuntimeException('update_keys_invalid');
        }
        $version = $data['list_version'] ?? null;
        if (!is_int($version) || $version < 1) {
            throw new RuntimeException('update_keys_invalid');
        }
        $expiresAt = self::date($data['expires_at'] ?? null);
        $entries = $data['keys'] ?? null;
        $revokedIds = $data['revoked'] ?? [];
        if (!is_array($entries) || !array_is_list($entries) || !is_array($revokedIds) || !array_is_list($revokedIds)) {
            throw new RuntimeException('update_keys_invalid');
        }
        $keys = [];
        $notBefore = [];
        foreach ($entries as $entry) {
            $id = is_array($entry) ? ($entry['id'] ?? null) : null;
            $key = is_array($entry) ? ($entry['public_key'] ?? null) : null;
            $raw = is_string($key) ? base64_decode($key, true) : false;
            if (!is_string($id) || preg_match(self::KEY_ID, $id) !== 1 || !is_string($raw) || strlen($raw) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES
                || isset($keys[$id])) {
                throw new RuntimeException('update_keys_invalid');
            }
            // One id names one key: a list cannot give a known id another key.
            if (isset($trusted[$id]) && !hash_equals($trusted[$id], (string) $key)) {
                throw new RuntimeException('update_keys_invalid');
            }
            $keys[$id] = (string) $key;
            $notBefore[$id] = self::date($entry['not_before'] ?? null);
        }
        $revokedList = [];
        foreach ($revokedIds as $id) {
            if (!is_string($id) || preg_match(self::KEY_ID, $id) !== 1) {
                throw new RuntimeException('update_keys_invalid');
            }
            $revokedList[$id] = true;
        }
        if (isset($revokedList[$signer])) {
            throw new RuntimeException('update_keys_invalid');
        }
        ksort($keys, SORT_STRING);
        $revokedList = array_keys($revokedList);
        sort($revokedList, SORT_STRING);

        return new self($version, $expiresAt, $keys, $notBefore, $revokedList, $signer);
    }

    /** @return array<string,string> the keys of this list that may sign at `$now` */
    public function keysAt(DateTimeImmutable $now): array
    {
        return array_filter($this->keys, fn (string $key, string $id): bool => $this->notBefore[$id] <= $now, ARRAY_FILTER_USE_BOTH);
    }

    private static function date(mixed $value): DateTimeImmutable
    {
        $date = is_string($value) ? DateTimeImmutable::createFromFormat(DATE_ATOM, $value) : false;
        if ($date === false || $date->format(DATE_ATOM) !== $value) {
            throw new RuntimeException('update_keys_invalid');
        }

        return $date->setTimezone(new DateTimeZone('UTC'));
    }
}
