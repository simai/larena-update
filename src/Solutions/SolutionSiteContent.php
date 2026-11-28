<?php

declare(strict_types=1);

namespace Larena\UpdateClient\Solutions;

/**
 * Whether the site is clean for a solution's content: this stage installs a solution only on a clean site. Read only.
 * {@see \Larena\UpdateClient\Laravel\StorageSolutionSiteContent} reads the storage SitePack's structure and counts the
 * site's own records there.
 */
interface SolutionSiteContent
{
    /**
     * What is in the way of the solution's SitePack: each structure the pack touches that already holds the site's own
     * records (`label` in plain words, `records` how many). Empty: the site is clean for it. A pack that cannot be read
     * throws a RuntimeException with a stable reason code.
     *
     * @return list<array{structure: string, label: string, records: int}>
     */
    public function blockers(string $sitepackPath): array;
}
