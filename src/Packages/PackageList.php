<?php

declare(strict_types=1);

namespace Larena\UpdateClient\Packages;

use DateTimeImmutable;

/**
 * A verified package list (`packages.json`, {@see PackageListVerifier}): every package release the update source
 * serves for one product and channel, with its own list version, issue and expiry dates. The same list for every
 * site: what a site may take is decided by the site ({@see PackageBatchPlanner}) and, for a closed package's archive,
 * again by the server.
 */
final readonly class PackageList
{
    /** @param list<PackageRelease> $releases */
    public function __construct(
        public string $product,
        public string $channel,
        public int $listVersion,
        public DateTimeImmutable $issuedAt,
        public DateTimeImmutable $expiresAt,
        public string $keyId,
        public string $sha256,
        public array $releases,
    ) {
    }

    public function find(string $package, string $version): ?PackageRelease
    {
        foreach ($this->releases as $release) {
            if ($release->package === $package && $release->version === $version) {
                return $release;
            }
        }

        return null;
    }

    /** @return list<PackageRelease> the releases of one package, newest first */
    public function of(string $package): array
    {
        $releases = array_values(array_filter($this->releases, static fn (PackageRelease $release): bool => $release->package === $package));
        usort($releases, static fn (PackageRelease $a, PackageRelease $b): int => version_compare($b->version, $a->version));

        return $releases;
    }
}
