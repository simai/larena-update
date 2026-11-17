<?php

declare(strict_types=1);

namespace Larena\UpdateClient\Release;

/**
 * Called by {@see ReleasePointer} right after `current` changed (an update or a rollback), before the health check:
 * the place for the OPcache reset through the site's one-time internal address and `queue:restart`
 * (plan 2026-11-17-update-client-plan.md, §5a.1 and «Ответ ENV»). A failure is thrown back to the caller, which
 * treats it like a failed step and rolls back.
 */
interface ReleaseActivation
{
    public function activated(string $siteRoot, ReleaseSwitch $switch): void;
}
