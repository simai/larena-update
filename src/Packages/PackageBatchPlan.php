<?php

declare(strict_types=1);

namespace Larena\UpdateClient\Packages;

/**
 * What a site may take from a package list now ({@see PackageBatchPlanner::plan()}):
 *
 * - {@see $releases} — the batch: for each installed package the newest release that is newer, that the licence
 *   covers (closed packages) and that fits the rest of the site after the batch;
 * - {@see $held} — closed packages with newer releases the licence does not cover (`update_batch_licence_ended`):
 *   they stay as they are, the rest of the batch can still go;
 * - {@see $refusals} — why the batch as a whole cannot go (`update_batch_incompatible`, naming the package and the
 *   rule). A plan with refusals is never installed.
 */
final readonly class PackageBatchPlan
{
    /**
     * @param list<PackageRelease>                                                  $releases
     * @param list<array{package:string, version:string, reason:string, requirement:string}> $held
     * @param list<array{reason:string, package:string, requirement:string}>        $refusals
     */
    public function __construct(
        public PackageList $list,
        public array $releases,
        public array $held,
        public array $refusals,
    ) {
    }

    public function refused(): bool
    {
        return $this->refusals !== [];
    }

    public function empty(): bool
    {
        return $this->releases === [];
    }

    /** @return array<string,string> package => version of the batch, sorted by package */
    public function selection(): array
    {
        $selection = [];
        foreach ($this->releases as $release) {
            $selection[$release->package] = $release->version;
        }
        ksort($selection, SORT_STRING);

        return $selection;
    }
}
