<?php

declare(strict_types=1);

namespace Larena\UpdateClient\Packages;

use DateTimeImmutable;

/**
 * One package release from a verified package list ({@see PackageListVerifier}): its own signed description
 * (`larena.package-release`, signed when the package was published) — version, archive, publication date,
 * open or closed, and the compatibility rule of its `module.yaml` `delivery.requires`. The exact signed bytes and
 * the signature are kept, so the batch release can keep them beside the package. `settings` (optional, signed): the
 * setting keys the package declares (its `module.yaml` `settings`), null when the release does not say; a solution may
 * set only declared keys ({@see \Larena\UpdateClient\Solutions\SolutionCheck}).
 */
final readonly class PackageRelease
{
    /**
     * @param array<string,string> $requiresPackages package => version rule ({@see VersionConstraint})
     * @param list<string>|null    $settings         the setting keys the package declares
     */
    public function __construct(
        public string $package,
        public string $version,
        public ?string $revision,
        public DateTimeImmutable $releasedAt,
        public string $distribution,
        public string $archiveFile,
        public string $archiveSha256,
        public int $archiveBytes,
        public string $requiresLarena,
        public array $requiresPackages,
        public bool $backwardIncompatibleMigrations,
        public string $keyId,
        public string $releaseBytes,
        public string $signatureBytes,
        public ?array $settings = null,
    ) {
    }

    /** A closed package is the paid one: its updates come only while the licence's «updates until» covers them. */
    public function isPaid(): bool
    {
        return $this->distribution === 'closed';
    }

    public function key(): string
    {
        return $this->package . '@' . $this->version;
    }
}
