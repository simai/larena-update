<?php

declare(strict_types=1);

namespace Larena\UpdateClient\Source;

use Larena\UpdateClient\Release\VerifiedRelease;

/** A newer release an update source offers; its description is signed and verified, nothing is downloaded yet. */
final readonly class SourceRelease
{
    public function __construct(
        public string $source,
        public VerifiedRelease $release,
    ) {
    }
}
