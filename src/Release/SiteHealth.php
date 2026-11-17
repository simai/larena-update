<?php

declare(strict_types=1);

namespace Larena\UpdateClient\Release;

/**
 * The health check after the switch and the activation (plan §3, step 4; §5a.1): the home page, the admin login
 * page and `/up`. Returns the names of the checks that failed (`home`, `admin_login`, `up`); empty means healthy.
 */
interface SiteHealth
{
    /** @return list<string> */
    public function failedChecks(string $siteRoot): array;
}
