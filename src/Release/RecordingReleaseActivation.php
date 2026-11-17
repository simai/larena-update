<?php

declare(strict_types=1);

namespace Larena\UpdateClient\Release;

/** Test and reference activation: remembers every switch and calls nothing. */
final class RecordingReleaseActivation implements ReleaseActivation
{
    /** @var list<ReleaseSwitch> */
    public array $switches = [];

    public function activated(string $siteRoot, ReleaseSwitch $switch): void
    {
        $this->switches[] = $switch;
    }
}
