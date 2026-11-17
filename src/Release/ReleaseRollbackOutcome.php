<?php

declare(strict_types=1);

namespace Larena\UpdateClient\Release;

use Larena\UpdateClient\Data\UpdateCycleSnapshot;

/**
 * The result of a manual rollback: the stored cycle and the warnings to show — `records_after_update_lost` when the
 * database was restored, `backward_incompatible_migrations` when the release declared them and only a database
 * restore could take the site back.
 */
final readonly class ReleaseRollbackOutcome
{
    /** @param list<string> $warnings */
    public function __construct(
        public UpdateCycleSnapshot $snapshot,
        public array $warnings,
    ) {
    }
}
