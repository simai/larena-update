<?php

declare(strict_types=1);

namespace Larena\UpdateClient\Laravel\Integrity;

use Larena\Core\Contracts\InstalledReleaseSeal;
use Larena\UpdateClient\Integrity\ReleaseSealer;

/** Core's installer step `larena:install-seal`, done by the update client with the site's public release keys. */
final readonly class SiteInstalledReleaseSeal implements InstalledReleaseSeal
{
    public function __construct(private ReleaseSealer $sealer)
    {
    }

    public function seal(string $releaseRoot, string $signedFrom): array
    {
        return $this->sealer->seal($releaseRoot, $signedFrom);
    }
}
