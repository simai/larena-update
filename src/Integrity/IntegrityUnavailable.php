<?php

declare(strict_types=1);

namespace Larena\UpdateClient\Integrity;

use RuntimeException;

/**
 * The checks cannot say anything about the files: no signed list (`integrity_list_missing`), a list whose signature
 * or content does not hold (`integrity_list_untrusted`, with the verifier's own code in `detail`), no trusted release
 * key (`integrity_keys_missing`). Never a passed check.
 */
final class IntegrityUnavailable extends RuntimeException
{
    public function __construct(public readonly string $reason, public readonly ?string $detail = null)
    {
        parent::__construct($reason);
    }
}
