<?php

declare(strict_types=1);

namespace Larena\UpdateClient\Packages;

use DateTimeImmutable;

/**
 * Whether the site's licence covers a release of a closed (paid) package (owner decision 2026-11-25, policy C:
 * installed code keeps working, updates come up to the «updates until» date). Open packages are not asked.
 */
interface PackageUpdateLicence
{
    /** Null when the release may be installed, else the licence's reason code (`updates_period_ended`, …). */
    public function refusal(string $package, DateTimeImmutable $releasedAt): ?string;
}
