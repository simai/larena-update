<?php

declare(strict_types=1);

namespace Larena\UpdateClient\Solutions;

/**
 * A solution whose signature, shape, product, version range, SitePack checksum and settings ({@see SolutionVerifier})
 * hold: what {@see SolutionCheck} plans from. Only signed bytes are in it.
 */
final readonly class VerifiedSolution
{
    /**
     * @param list<array{name: string, version: string, capability: ?string}> $packages
     * @param array{ref: string, sha256: string, path: string}|null           $sitepack
     * @param array<string,mixed>                                             $settings
     */
    public function __construct(
        public string $id,
        public string $version,
        public string $product,
        public string $productVersion,
        public array $packages,
        public ?array $sitepack,
        public array $settings,
        public string $keyId,
        public string $sha256,
    ) {
    }
}
