<?php

declare(strict_types=1);

namespace Larena\UpdateClient\Packages;

use DateTimeImmutable;
use DateTimeZone;
use JsonException;
use Larena\UpdateClient\Release\ReleaseSignatureVerifier;
use RuntimeException;
use Throwable;

/**
 * Checks a package list (`packages.json` + `packages.json.sig`) and every package release in it, before anything of a
 * batch is downloaded or written (owner variant D, 2026-11-25; research §9 «срок годности и номер версии списка»).
 *
 * The list (`larena.package-list`, schema_version 1) is signed over its exact bytes like `release.json`, by a trusted
 * release key of the site: `product`, `channel`, `list_version` (a whole number that only grows), `issued_at`,
 * `expires_at` (ISO 8601 with offset) and `packages` — a list of `{"release": "<exact bytes>", "signature": {...}}`.
 * Each `release` is a package release (`larena.package-release`, schema_version 1) signed on its own when the package
 * was published: `product`, `package`, `version`, optional `revision`, `released_at`, `distribution` (`open` or
 * `closed`), `archive` (`file`, `sha256`, `bytes`), `requires` (`larena` and `packages`, version rules of
 * {@see VersionConstraint}) and `backward_incompatible_migrations`. So a key that only signs lists cannot make a
 * package: the archive's checksum is under the package's own signature.
 *
 * Refusals ({@see PackageBatchRefused}):
 * - `update_batch_signature_invalid` — the list's or a package release's signature fails (wrong, untrusted or revoked
 *   key); the requirement names the signature reason;
 * - `update_batch_list_stale` — the list expired, or its version is lower than one this site already saw (a replayed
 *   older list);
 * - `update_batch_list_invalid` — anything else: shape, product, channel, a date in the future, a duplicate release.
 */
final readonly class PackageListVerifier
{
    public const LIST_SCHEMA = 'larena.package-list';
    public const RELEASE_SCHEMA = 'larena.package-release';
    public const MAX_LIST_BYTES = 4194304;
    private const MAX_RELEASE_BYTES = 65536;
    private const CLOCK_SKEW_SECONDS = 300;
    private const PACKAGE = '/\A[a-z0-9][a-z0-9_.-]{0,99}\/[a-z0-9][a-z0-9_.-]{0,99}\z/D';
    private const VERSION = '/\A(0|[1-9]\d{0,5})\.(0|[1-9]\d{0,5})\.(0|[1-9]\d{0,7})\z/D';

    public function __construct(private ReleaseSignatureVerifier $verifier)
    {
    }

    /** @param int $seenListVersion the highest list version this site has accepted (0: none yet) */
    public function verify(string $listBytes, string $signatureBytes, string $channel, DateTimeImmutable $now, int $seenListVersion = 0): PackageList
    {
        try {
            $keyId = $this->verifier->signedBy($listBytes, $signatureBytes, self::MAX_LIST_BYTES);
        } catch (Throwable $exception) {
            throw PackageBatchRefused::one(
                $exception->getMessage() === 'release_files_invalid' ? 'update_batch_list_invalid' : 'update_batch_signature_invalid',
                'packages.json', self::code($exception),
            );
        }
        $list = self::json($listBytes, 8, 'packages.json');
        $product = $this->verifier->product();
        if (($list['schema'] ?? null) !== self::LIST_SCHEMA || ($list['schema_version'] ?? null) !== 1) {
            throw self::invalid('packages.json', 'schema');
        }
        if (($list['product'] ?? null) !== $product) {
            throw self::invalid('packages.json', 'product');
        }
        if (($list['channel'] ?? null) !== $channel) {
            throw self::invalid('packages.json', 'channel');
        }
        $version = $list['list_version'] ?? null;
        if (!is_int($version) || $version < 1) {
            throw self::invalid('packages.json', 'list_version');
        }
        $issuedAt = self::date($list['issued_at'] ?? null, 'packages.json', 'issued_at');
        $expiresAt = self::date($list['expires_at'] ?? null, 'packages.json', 'expires_at');
        if ($expiresAt <= $issuedAt) {
            throw self::invalid('packages.json', 'expires_at');
        }
        if ($issuedAt->getTimestamp() > $now->getTimestamp() + self::CLOCK_SKEW_SECONDS) {
            throw self::invalid('packages.json', 'issued_at in the future');
        }
        if ($expiresAt <= $now) {
            throw PackageBatchRefused::one('update_batch_list_stale', 'packages.json', 'expired ' . $expiresAt->format(DATE_ATOM));
        }
        if ($version < $seenListVersion) {
            throw PackageBatchRefused::one('update_batch_list_stale', 'packages.json', 'list_version ' . $version . ' < ' . $seenListVersion);
        }
        $entries = $list['packages'] ?? null;
        if (!is_array($entries) || !array_is_list($entries)) {
            throw self::invalid('packages.json', 'packages');
        }
        $releases = [];
        $seen = [];
        foreach ($entries as $entry) {
            $release = $this->release($entry, $product);
            if (isset($seen[$release->key()])) {
                throw self::invalid($release->package, 'duplicate ' . $release->version);
            }
            $seen[$release->key()] = true;
            $releases[] = $release;
        }

        return new PackageList($product, $channel, $version, $issuedAt, $expiresAt, $keyId, hash('sha256', $listBytes), $releases);
    }

    /**
     * One package release kept beside a release folder (`.larena-packages.json`), checked again on its own: the same
     * signature and shape rules as inside a list. The integrity checks trust a package's files only through this.
     */
    public function verifyRelease(string $releaseBytes, string $signatureBytes): PackageRelease
    {
        return $this->release(['release' => $releaseBytes, 'signature' => $signatureBytes], $this->verifier->product());
    }

    private function release(mixed $entry, string $product): PackageRelease
    {
        $bytes = is_array($entry) ? ($entry['release'] ?? null) : null;
        $signature = is_array($entry) ? ($entry['signature'] ?? null) : null;
        if (is_array($signature) && !array_is_list($signature)) {
            $signature = json_encode($signature, JSON_UNESCAPED_SLASHES);
        }
        if (!is_string($bytes) || !is_string($signature)) {
            throw self::invalid('packages.json', 'package entry');
        }
        // The package is named only after its signature holds: an unsigned name is never shown as a fact.
        try {
            $keyId = $this->verifier->signedBy($bytes, $signature, self::MAX_RELEASE_BYTES);
        } catch (Throwable $exception) {
            throw PackageBatchRefused::one('update_batch_signature_invalid', self::claimedName($bytes), self::code($exception));
        }
        $data = self::json($bytes, 6, 'package release');
        $package = $data['package'] ?? null;
        if (!is_string($package) || preg_match(self::PACKAGE, $package) !== 1) {
            throw self::invalid('package release', 'package');
        }
        if (($data['schema'] ?? null) !== self::RELEASE_SCHEMA || ($data['schema_version'] ?? null) !== 1) {
            throw self::invalid($package, 'schema');
        }
        if (($data['product'] ?? null) !== $product) {
            throw self::invalid($package, 'product');
        }
        $version = $data['version'] ?? null;
        if (!is_string($version) || preg_match(self::VERSION, $version) !== 1) {
            throw self::invalid($package, 'version');
        }
        $revision = $data['revision'] ?? null;
        if ($revision !== null && (!is_string($revision) || preg_match('/\A[a-f0-9]{40}\z/D', $revision) !== 1)) {
            throw self::invalid($package, 'revision');
        }
        $distribution = $data['distribution'] ?? null;
        if ($distribution !== 'open' && $distribution !== 'closed') {
            throw self::invalid($package, 'distribution');
        }
        $archive = $data['archive'] ?? null;
        if (!is_array($archive)
            || !is_string($archive['file'] ?? null) || preg_match('/\A[a-z0-9][a-z0-9._-]{0,127}\.tar\.gz\z/D', $archive['file']) !== 1
            || !is_string($archive['sha256'] ?? null) || preg_match('/\A[a-f0-9]{64}\z/D', $archive['sha256']) !== 1
            || !is_int($archive['bytes'] ?? null) || $archive['bytes'] < 1) {
            throw self::invalid($package, 'archive');
        }
        $requires = $data['requires'] ?? null;
        $larena = is_array($requires) ? ($requires['larena'] ?? null) : null;
        $packages = is_array($requires) ? ($requires['packages'] ?? []) : null;
        if (!is_string($larena) || !is_array($packages) || ($packages !== [] && array_is_list($packages))) {
            throw self::invalid($package, 'requires');
        }
        foreach ($packages as $name => $rule) {
            if (!is_string($name) || preg_match(self::PACKAGE, $name) !== 1 || !is_string($rule) || $rule === '' || strlen($rule) > 200) {
                throw self::invalid($package, 'requires');
            }
        }
        ksort($packages, SORT_STRING);
        $incompatible = $data['backward_incompatible_migrations'] ?? null;
        if (!is_bool($incompatible)) {
            throw self::invalid($package, 'backward_incompatible_migrations');
        }

        return new PackageRelease(
            $package, $version, $revision, self::date($data['released_at'] ?? null, $package, 'released_at'), $distribution,
            $archive['file'], $archive['sha256'], $archive['bytes'], $larena, $packages, $incompatible, $keyId, $bytes, $signature,
        );
    }

    /** The package name an entry claims, only to say which entry failed; `unknown` when it does not look like one. */
    private static function claimedName(string $bytes): string
    {
        try {
            $data = json_decode($bytes, true, 6, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return 'unknown';
        }
        $name = is_array($data) ? ($data['package'] ?? null) : null;

        return is_string($name) && preg_match(self::PACKAGE, $name) === 1 ? $name : 'unknown';
    }

    private static function invalid(string $package, string $requirement): PackageBatchRefused
    {
        return PackageBatchRefused::one('update_batch_list_invalid', $package, $requirement);
    }

    private static function date(mixed $value, string $package, string $field): DateTimeImmutable
    {
        $date = is_string($value) ? DateTimeImmutable::createFromFormat(DATE_ATOM, $value) : false;
        if ($date === false || $date->format(DATE_ATOM) !== $value) {
            throw self::invalid($package, $field);
        }

        return $date->setTimezone(new DateTimeZone('UTC'));
    }

    /** @return array<array-key,mixed> */
    private static function json(string $bytes, int $depth, string $package): array
    {
        try {
            $value = json_decode($bytes, true, $depth, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw self::invalid($package, 'json');
        }

        return is_array($value) && !array_is_list($value) ? $value : throw self::invalid($package, 'json');
    }

    private static function code(Throwable $exception): string
    {
        $message = $exception->getMessage();

        return $exception instanceof RuntimeException && preg_match('/\A[a-z][a-z0-9_.-]{0,99}\z/D', $message) === 1 ? $message : 'release_signature_invalid';
    }
}
