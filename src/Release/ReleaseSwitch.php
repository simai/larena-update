<?php

declare(strict_types=1);

namespace Larena\UpdateClient\Release;

/**
 * The outcome of moving `current`: the release it pointed to before (null on a first install) and the one it points
 * to now. `changed` is false when `current` already pointed there; nothing was switched then.
 */
final readonly class ReleaseSwitch
{
    public function __construct(
        public ?string $previous,
        public string $current,
        public bool $changed,
    ) {
    }
}
