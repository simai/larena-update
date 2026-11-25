<?php

declare(strict_types=1);

namespace Larena\UpdateClient\Packages;

/** A batch that passed every check of {@see PackageBatchCandidate::prepare()}: what its release folder is built from. */
final readonly class CheckedPackageBatch
{
    /** @param list<PackageRelease> $releases */
    public function __construct(
        public PackageList $list,
        public array $releases,
        public InstalledPackages $installed,
        public string $from,
    ) {
    }
}
