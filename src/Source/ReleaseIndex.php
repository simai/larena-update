<?php

declare(strict_types=1);

namespace Larena\UpdateClient\Source;

use DateTimeImmutable;
use DateTimeZone;
use JsonException;
use Larena\UpdateClient\Release\ReleaseSignatureVerifier;
use RuntimeException;

/**
 * The signed channel index of the update server (research §9 «защита от заморозки», matrix 3 and 5; contract agreed
 * with Larena Server 2026-11-28). Releases stay immutable — `release.json` is signed once — and freshness lives here:
 * `<source>/index.json` with the detached Ed25519 signature `<source>/index.json.sig` (the `release.json.sig` format).
 *
 * `{"schema":"larena.release-index","product","channel","list_version","expires_at","signed_by",
 *   "latest":{"version","release_sha256"},"revoked":[{"version","fixed_in"|null}]}`
 * - `list_version` grows with every publish or revocation; the site keeps the highest it saw per product and channel
 *   ({@see ReleaseListState}); `expires_at` (ISO 8601 with offset) is now + 7 days, the index is signed again daily;
 * - `signed_by` must be the key id of the signature itself, a key the site trusts;
 * - `latest` names the newest release: its `release.json` must hash to `release_sha256` and carry that version;
 * - `revoked` lists revoked versions and, when known, the version that fixes each.
 *
 * Which source has one ({@see required()}, the one place of the rule): every source except a github.com releases page
 * and a folder on this server (`file://`); those have no index and are read as before.
 *
 * Refusals, before anything is written: a bad signature keeps the signature's own reason
 * (`release_key_untrusted`, `release_key_revoked`, `release_signature_mismatch`, …); `update_release_list_stale` — the
 * index expired, or its number is lower than one this site already saw; `update_release_list_invalid` — missing,
 * malformed, or for another product or channel.
 */
final readonly class ReleaseIndex
{
    public const SCHEMA = 'larena.release-index';
    public const MAX_BYTES = 262144;
    private const VERSION = '/\A(0|[1-9]\d{0,5})\.(0|[1-9]\d{0,5})\.(0|[1-9]\d{0,7})\z/D';

    /** @param array<string,?string> $revoked revoked version => the version that fixes it, or null */
    private function __construct(
        public int $listVersion,
        public DateTimeImmutable $expiresAt,
        public string $signedBy,
        public string $latestVersion,
        public string $latestSha256,
        public array $revoked,
    ) {
    }

    /** Whether a source serves a signed index: every source except github.com and a folder. */
    public static function required(string $source): bool
    {
        return !ReleaseSource::isGitHub($source) && !str_starts_with($source, 'file://');
    }

    /** @param int $seen the highest list version this site accepted for the product and channel (0: none) */
    public static function verify(string $bytes, string $signature, ReleaseSignatureVerifier $verifier, string $channel, DateTimeImmutable $now, int $seen): self
    {
        try {
            $keyId = $verifier->signedBy($bytes, $signature, self::MAX_BYTES);
        } catch (RuntimeException $exception) {
            throw new RuntimeException($exception->getMessage() === 'release_files_invalid' ? 'update_release_list_invalid' : $exception->getMessage(), 0, $exception);
        }
        try {
            $data = json_decode($bytes, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new RuntimeException('update_release_list_invalid');
        }
        if (!is_array($data) || array_is_list($data) || ($data['schema'] ?? null) !== self::SCHEMA
            || ($data['product'] ?? null) !== $verifier->product() || ($data['channel'] ?? null) !== $channel
            || ($data['signed_by'] ?? null) !== $keyId) {
            throw new RuntimeException('update_release_list_invalid');
        }
        $version = $data['list_version'] ?? null;
        $expires = $data['expires_at'] ?? null;
        $expiresAt = is_string($expires) ? DateTimeImmutable::createFromFormat(DATE_ATOM, $expires) : false;
        if (!is_int($version) || $version < 1 || $expiresAt === false || $expiresAt->format(DATE_ATOM) !== $expires) {
            throw new RuntimeException('update_release_list_invalid');
        }
        $latest = $data['latest'] ?? null;
        if (!is_array($latest) || !is_string($latest['version'] ?? null) || preg_match(self::VERSION, $latest['version']) !== 1
            || !is_string($latest['release_sha256'] ?? null) || preg_match('/\A[a-f0-9]{64}\z/D', $latest['release_sha256']) !== 1) {
            throw new RuntimeException('update_release_list_invalid');
        }
        $entries = $data['revoked'] ?? null;
        if (!is_array($entries) || !array_is_list($entries)) {
            throw new RuntimeException('update_release_list_invalid');
        }
        $revoked = [];
        foreach ($entries as $entry) {
            $revokedVersion = is_array($entry) ? ($entry['version'] ?? null) : null;
            $fixedIn = is_array($entry) ? ($entry['fixed_in'] ?? null) : null;
            if (!is_string($revokedVersion) || preg_match(self::VERSION, $revokedVersion) !== 1
                || ($fixedIn !== null && (!is_string($fixedIn) || preg_match(self::VERSION, $fixedIn) !== 1))) {
                throw new RuntimeException('update_release_list_invalid');
            }
            $revoked[$revokedVersion] = $fixedIn !== null && version_compare($fixedIn, $revokedVersion, '>') ? $fixedIn : null;
        }
        if ($expiresAt->setTimezone(new DateTimeZone('UTC')) <= $now || $version < $seen) {
            throw new RuntimeException('update_release_list_stale');
        }

        return new self($version, $expiresAt, $keyId, $latest['version'], $latest['release_sha256'], $revoked);
    }

    /** The index's word on one version: a {@see ReleaseRevoked} when it is revoked, else null. */
    public function revocation(string $version): ?ReleaseRevoked
    {
        return array_key_exists($version, $this->revoked) ? new ReleaseRevoked($version, $this->revoked[$version]) : null;
    }
}
