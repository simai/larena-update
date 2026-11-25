<?php

declare(strict_types=1);

namespace Larena\UpdateClient\Packages;

/**
 * A downloaded package batch, handed to the cycle: the exact signed list and its signature, the chosen package
 * releases (package => version) and each archive on disk. The cycle checks all of it again before it starts
 * ({@see PackageBatchCandidate}).
 */
final readonly class PackageBatch
{
    /**
     * @param array<string,string> $selection package => version
     * @param array<string,string> $archives  package => archive path
     */
    public function __construct(
        public string $listBytes,
        public string $signatureBytes,
        public array $selection,
        public array $archives,
    ) {
    }
}
