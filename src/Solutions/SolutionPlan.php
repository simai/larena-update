<?php

declare(strict_types=1);

namespace Larena\UpdateClient\Solutions;

use Larena\UpdateClient\Packages\PackageRelease;

/**
 * What installing a checked solution would do ({@see SolutionCheck}): nothing of it is written yet. Installing and
 * rolling back (§14 stage 4, S3–S4) are the next steps; this is the plan they start from.
 */
final readonly class SolutionPlan
{
    /**
     * @param array<string,string>  $present  package => installed version that already holds the solution's rule
     * @param list<PackageRelease>  $install  package releases to install (from the signed package list)
     * @param list<string>          $declared the setting keys the packages declare
     */
    public function __construct(
        public VerifiedSolution $solution,
        public array $present,
        public array $install,
        public array $declared,
    ) {
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'solution' => $this->solution->id,
            'version' => $this->solution->version,
            'product' => $this->solution->product,
            'product_version' => $this->solution->productVersion,
            'signed_by' => $this->solution->keyId,
            'sha256' => $this->solution->sha256,
            'present' => array_map(static fn (string $package, string $version): array => ['package' => $package, 'version' => $version],
                array_keys($this->present), array_values($this->present)),
            'install' => array_map(static fn (PackageRelease $release): array => [
                'package' => $release->package, 'version' => $release->version, 'paid' => $release->isPaid(),
            ], $this->install),
            'sitepack' => $this->solution->sitepack === null ? null : ['ref' => $this->solution->sitepack['ref'], 'sha256' => $this->solution->sitepack['sha256']],
            'settings' => array_keys($this->solution->settings),
        ];
    }
}
