<?php

declare(strict_types=1);

namespace Larena\UpdateClient\Packages;

use RuntimeException;

/**
 * A package batch the site will not install. The message is the stable reason code of the first refusal
 * (`update_batch_incompatible`, `update_batch_licence_ended`, `update_batch_signature_invalid`,
 * `update_batch_list_stale`, `update_batch_list_invalid`); {@see $refusals} names every package and the requirement it
 * failed (a version rule, `autoload`, a licence reason code). Nothing secret is ever in it.
 */
final class PackageBatchRefused extends RuntimeException
{
    /** @param list<array{reason:string, package:string, requirement:string}> $refusals */
    public function __construct(public readonly array $refusals)
    {
        parent::__construct($refusals[0]['reason'] ?? 'update_batch_incompatible');
    }

    public static function one(string $reason, string $package, string $requirement): self
    {
        return new self([['reason' => $reason, 'package' => $package, 'requirement' => $requirement]]);
    }
}
