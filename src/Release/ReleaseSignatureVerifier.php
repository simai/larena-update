<?php

declare(strict_types=1);

namespace Larena\UpdateClient\Release;

use JsonException;
use RuntimeException;

/**
 * Checks a release description (`release.json`) and its detached Ed25519 signature (`release.json.sig`) before
 * anything of the release is downloaded or written (plan docs/architecture/2026-11-17-update-client-plan.md, §2).
 *
 * The signature file is JSON `{"key_id": "...", "signature": "<base64 of 64 bytes>"}` over the exact bytes of
 * `release.json`. Only keys given to the constructor are trusted; a revoked key id is refused even if trusted.
 * The optional `files` object (`{"file": "files.json", "sha256", "bytes"}`) binds the release's file list
 * ({@see FileList}) to this signature; it is an optional field of schema version 1, so older releases still verify.
 * Every failure is a RuntimeException with a stable reason code and no secret in it.
 */
final readonly class ReleaseSignatureVerifier
{
    public const SCHEMA = 'larena.release';
    public const SCHEMA_VERSION = 1;
    private const MAX_RELEASE_BYTES = 262144;
    private const MAX_SIGNATURE_BYTES = 1024;

    /** @var array<string,string> key id => raw 32-byte public key */
    private array $keys;

    /**
     * @param array<string,string> $trustedKeys  key id => base64 public key
     * @param list<string>         $revokedKeyIds
     */
    public function __construct(
        array $trustedKeys,
        private array $revokedKeyIds = [],
        private string $product = 'larena',
    ) {
        $keys = [];
        foreach ($trustedKeys as $id => $encoded) {
            $raw = is_string($encoded) ? base64_decode($encoded, true) : false;
            if (!is_string($id) || !self::isKeyId($id) || !is_string($raw) || strlen($raw) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
                throw new RuntimeException('release_trusted_key_invalid');
            }
            $keys[$id] = $raw;
        }
        if ($keys === []) {
            throw new RuntimeException('release_trusted_keys_missing');
        }
        $this->keys = $keys;
    }

    /**
     * @param string $installedVersion the version running now; the release must be strictly newer and the installed
     *                                 one must not be older than the release's minimum version
     */
    public function verify(string $releaseBytes, string $signatureBytes, string $installedVersion, string $channel = 'stable'): VerifiedRelease
    {
        return $this->read($releaseBytes, $signatureBytes, $installedVersion, $channel);
    }

    /**
     * The signed description a site keeps with a release it already runs (`<release>/.larena-signed/`): the same
     * signature, key and content rules as {@see verify()}, without the «newer than installed» and channel rules, which
     * only make sense before an update. The integrity checks use it to trust the kept file list again.
     */
    public function verifyKept(string $releaseBytes, string $signatureBytes): VerifiedRelease
    {
        return $this->read($releaseBytes, $signatureBytes, null, null);
    }

    /** The product every signed file this verifier accepts must name. */
    public function product(): string
    {
        return $this->product;
    }

    /**
     * Checks only the detached signature over exact bytes, by the same trusted and revoked keys and with the same
     * reason codes as {@see verify()}, and returns the key id. Other signed files (the package list and the package
     * releases in it) are read by their own owners after this.
     */
    public function signedBy(string $bytes, string $signatureBytes, int $maxBytes = self::MAX_RELEASE_BYTES): string
    {
        if (strlen($bytes) < 2 || strlen($bytes) > $maxBytes
            || strlen($signatureBytes) < 2 || strlen($signatureBytes) > self::MAX_SIGNATURE_BYTES) {
            throw new RuntimeException('release_files_invalid');
        }

        return $this->signature($bytes, $signatureBytes);
    }

    private function signature(string $releaseBytes, string $signatureBytes): string
    {
        $signature = self::json($signatureBytes, 'release_signature_invalid');
        $keyId = $signature['key_id'] ?? null;
        $value = is_string($signature['signature'] ?? null) ? base64_decode($signature['signature'], true) : false;
        if (!is_string($keyId) || !self::isKeyId($keyId) || !is_string($value) || strlen($value) !== SODIUM_CRYPTO_SIGN_BYTES) {
            throw new RuntimeException('release_signature_invalid');
        }
        if (in_array($keyId, $this->revokedKeyIds, true)) {
            throw new RuntimeException('release_key_revoked');
        }
        $key = $this->keys[$keyId] ?? throw new RuntimeException('release_key_untrusted');
        if (!sodium_crypto_sign_verify_detached($value, $releaseBytes, $key)) {
            throw new RuntimeException('release_signature_mismatch');
        }

        return $keyId;
    }

    private function read(string $releaseBytes, string $signatureBytes, ?string $installedVersion, ?string $channel): VerifiedRelease
    {
        $keyId = $this->signedBy($releaseBytes, $signatureBytes);

        // Only signed bytes are read from here on.
        $release = self::json($releaseBytes, 'release_description_invalid');
        if (($release['schema'] ?? null) !== self::SCHEMA || ($release['schema_version'] ?? null) !== self::SCHEMA_VERSION) {
            throw new RuntimeException('release_schema_unsupported');
        }
        if (($release['product'] ?? null) !== $this->product) {
            throw new RuntimeException('release_product_differs');
        }
        if ($channel !== null && ($release['channel'] ?? null) !== $channel) {
            throw new RuntimeException('release_channel_differs');
        }
        $releaseChannel = $release['channel'] ?? null;
        if (!is_string($releaseChannel) || preg_match('/\A[a-z][a-z0-9_-]{0,31}\z/D', $releaseChannel) !== 1) {
            throw new RuntimeException('release_channel_differs');
        }
        $version = self::version($release['version'] ?? null);
        $minimum = self::version($release['minimum_version'] ?? null);
        if ($installedVersion !== null) {
            $installed = self::version($installedVersion);
            if (version_compare($version, $installed, '<=')) {
                throw new RuntimeException('release_not_newer');
            }
            if (version_compare($installed, $minimum, '<')) {
                throw new RuntimeException('release_installed_too_old');
            }
        }
        $archive = $release['archive'] ?? null;
        if (!is_array($archive)
            || !is_string($archive['file'] ?? null) || preg_match('/\A[a-z0-9][a-z0-9._-]{0,127}\.tar\.gz\z/D', $archive['file']) !== 1
            || !is_string($archive['sha256'] ?? null) || preg_match('/\A[a-f0-9]{64}\z/D', $archive['sha256']) !== 1
            || !is_int($archive['bytes'] ?? null) || $archive['bytes'] < 1) {
            throw new RuntimeException('release_archive_invalid');
        }
        // Optional since file lists came in: an older release has no `files` and verifies with files null.
        $files = null;
        if (array_key_exists('files', $release)) {
            $list = $release['files'];
            if (!is_array($list)
                || !is_string($list['file'] ?? null) || preg_match('/\A[a-z0-9][a-z0-9._-]{0,127}\.json\z/D', $list['file']) !== 1
                || $list['file'] === 'release.json'
                || !is_string($list['sha256'] ?? null) || preg_match('/\A[a-f0-9]{64}\z/D', $list['sha256']) !== 1
                || !is_int($list['bytes'] ?? null) || $list['bytes'] < 2 || $list['bytes'] > FileList::MAX_BYTES) {
                throw new RuntimeException('release_files_reference_invalid');
            }
            $files = new FileListReference($list['file'], $list['sha256'], $list['bytes']);
        }
        $packages = $release['packages'] ?? null;
        if (!is_array($packages) || $packages === [] || array_is_list($packages)) {
            throw new RuntimeException('release_packages_invalid');
        }
        foreach ($packages as $name => $revision) {
            if (!is_string($name) || preg_match('/\A[a-z0-9][a-z0-9_.-]*\/[a-z0-9][a-z0-9_.-]*\z/D', $name) !== 1
                || !is_string($revision) || preg_match('/\A[a-f0-9]{40}\z/D', $revision) !== 1) {
                throw new RuntimeException('release_packages_invalid');
            }
        }
        ksort($packages, SORT_STRING);
        $incompatible = $release['backward_incompatible_migrations'] ?? null;
        $php = $release['php'] ?? null;
        if (!is_bool($incompatible) || !is_string($php) || $php === '' || strlen($php) > 64) {
            throw new RuntimeException('release_description_invalid');
        }
        $notes = [];
        foreach ((array) ($release['notes'] ?? []) as $locale => $text) {
            if (is_string($locale) && preg_match('/\A[a-z]{2}\z/D', $locale) === 1 && is_string($text) && strlen($text) <= 8192) {
                $notes[$locale] = $text;
            }
        }

        return new VerifiedRelease(
            product: $this->product,
            version: $version,
            channel: $releaseChannel,
            minimumVersion: $minimum,
            php: $php,
            archiveFile: $archive['file'],
            archiveSha256: $archive['sha256'],
            archiveBytes: $archive['bytes'],
            packages: $packages,
            backwardIncompatibleMigrations: $incompatible,
            notes: $notes,
            keyId: $keyId,
            releaseSha256: hash('sha256', $releaseBytes),
            files: $files,
        );
    }

    /** Checks a downloaded archive against a verified release; the file is read, never executed or unpacked. */
    public static function verifyArchive(VerifiedRelease $release, string $path): void
    {
        if (is_link($path) || !is_file($path) || filesize($path) !== $release->archiveBytes) {
            throw new RuntimeException('release_archive_size_differs');
        }
        $sha = hash_file('sha256', $path);
        if (!is_string($sha) || !hash_equals($release->archiveSha256, $sha)) {
            throw new RuntimeException('release_archive_checksum_differs');
        }
    }

    private static function isKeyId(string $id): bool
    {
        return preg_match('/\A[a-z0-9][a-z0-9._-]{0,63}\z/D', $id) === 1;
    }

    private static function version(mixed $value): string
    {
        if (!is_string($value) || preg_match('/\A(0|[1-9]\d{0,5})\.(0|[1-9]\d{0,5})\.(0|[1-9]\d{0,7})\z/D', $value) !== 1) {
            throw new RuntimeException('release_version_invalid');
        }

        return $value;
    }

    /** @return array<array-key,mixed> */
    private static function json(string $bytes, string $reason): array
    {
        try {
            $value = json_decode($bytes, true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new RuntimeException($reason);
        }

        return is_array($value) ? $value : throw new RuntimeException($reason);
    }
}
