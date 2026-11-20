<?php

declare(strict_types=1);

namespace Larena\UpdateClient\Integrity;

/**
 * Where every integrity action is written for «История» (the audit journal of the admin): event types start with
 * `update.integrity.` — `check.completed`, `core.changed`, `restore.succeeded`, `restore.failed`, `restore.refused`,
 * `quarantine.returned`, `exception.accepted`. Facts are counts, paths, reasons and digests; never file contents.
 */
interface IntegrityHistory
{
    public const PREFIX = 'update.integrity.';

    /** @param array<string,scalar|null> $facts */
    public function record(string $event, string $actor, array $facts = []): void;
}
