<?php

declare(strict_types=1);

namespace Larena\UpdateClient\Integrity;

use RuntimeException;

/**
 * «Вернуть как было» or a return from the quarantine did not run: the plan changed since it was shown
 * (`integrity_plan_changed`), something stops it (`integrity_archive_damaged`, `integrity_archive_missing`, no list),
 * nothing to do (`integrity_nothing_to_restore`), the place is taken (`integrity_place_taken`), the quarantined copy
 * is not the one moved (`integrity_quarantine_damaged`), or another restore runs (`integrity_restore_busy`).
 */
final class IntegrityRefused extends RuntimeException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct($reason);
    }
}
