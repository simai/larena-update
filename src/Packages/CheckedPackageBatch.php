<?php

declare(strict_types=1);

namespace Larena\UpdateClient\Packages;

/**
 * A batch that passed every check of {@see PackageBatchCandidate::prepare()} (or a removal that passed
 * {@see PackageRemovalCandidate::prepare()}): what its release folder is built from. A removal has no package list
 * (`list` null, no `releases`) and names the packages it takes out in `removed`.
 */
final readonly class CheckedPackageBatch
{
    /**
     * @param list<PackageRelease>  $releases
     * @param array<string,string>  $keptArchives package => kept archive in the running release, for every package an
     *                                            earlier batch installed that this batch does not replace
     * @param list<string>          $added        packages the set did not have, after this batch
     * @param array<string,string>  $generated    the Composer files with the added packages in them (path => bytes)
     * @param list<string>          $removed      packages whose code leaves the release (their data stays)
     */
    public function __construct(
        public ?PackageList $list,
        public array $releases,
        public InstalledPackages $installed,
        public string $from,
        public array $keptArchives = [],
        public array $added = [],
        public array $generated = [],
        public array $removed = [],
    ) {
    }
}
