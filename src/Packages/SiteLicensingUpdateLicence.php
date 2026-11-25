<?php

declare(strict_types=1);

namespace Larena\UpdateClient\Packages;

use DateTimeImmutable;
use Larena\Licensing\Site\SiteLicensing;

/**
 * The licence answer from `larena/licensing`: {@see SiteLicensing::allowsUpdate()} over the site's signed snapshot.
 * Without the licensing package in the site ({@see $licensing} null) no paid update is allowed
 * (`licensing_unavailable`): the site cannot tell whether the licence covers it.
 */
final readonly class SiteLicensingUpdateLicence implements PackageUpdateLicence
{
    public function __construct(private ?SiteLicensing $licensing)
    {
    }

    public function refusal(string $package, DateTimeImmutable $releasedAt): ?string
    {
        if ($this->licensing === null) {
            return 'licensing_unavailable';
        }
        $decision = $this->licensing->allowsUpdate($package, $releasedAt);

        return $decision->allowed ? null : $decision->reasonCode;
    }
}
