<?php

declare(strict_types=1);

namespace Larena\UpdateClient\Solutions;

/**
 * The rights a solution's packages need (larena/licensing on a site, {@see \Larena\UpdateClient\Laravel\SiteSolutionRights}).
 * Only read: asking never starts a trial or a purchase.
 */
interface SolutionRights
{
    /** Whether the site's licence allows this capability now. */
    public function allows(string $capability): bool;

    /**
     * What the site can offer for a capability it lacks, in plain data for «Попробовать 14 дней» and «Купить».
     *
     * @return array{title: ?string, trial: bool, trial_days: ?int, shop_url: ?string}
     */
    public function offer(string $capability, string $package): array;
}
