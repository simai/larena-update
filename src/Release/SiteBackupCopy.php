<?php

declare(strict_types=1);

namespace Larena\UpdateClient\Release;

/** A full site copy made before the code is touched: its reference and whether its trial restore passed. */
final readonly class SiteBackupCopy
{
    public function __construct(
        public string $ref,
        public bool $restoreVerified,
    ) {
    }
}
