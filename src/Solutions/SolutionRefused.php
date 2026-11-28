<?php

declare(strict_types=1);

namespace Larena\UpdateClient\Solutions;

use RuntimeException;

/**
 * A solution the site will not install, decided before anything is written ({@see SolutionCheck}). The message is the
 * stable reason code of the first refusal; {@see $refusals} names every one: `reason`, `subject` (a package, a setting
 * key, a structure, `solution.json`) and `detail` (a reason code or a rule, never a setting's value or a secret).
 * The refusal for missing rights (`solution_rights_missing`) is one entry for all of them, with `addons`: each
 * capability with its package, title, «Попробовать» (`trial`, `trial_days`) and «Купить» (`shop_url`) data.
 * The refusal for a site that is not empty (`solution_site_not_empty`) names what is in the way in `blockers`.
 */
final class SolutionRefused extends RuntimeException
{
    /** @param list<array{reason:string, subject:string, detail:string, addons?:list<array<string,mixed>>, blockers?:list<array<string,mixed>>}> $refusals */
    public function __construct(public readonly array $refusals)
    {
        parent::__construct($refusals[0]['reason'] ?? 'solution_invalid');
    }

    public static function one(string $reason, string $subject, string $detail = ''): self
    {
        return new self([['reason' => $reason, 'subject' => $subject, 'detail' => $detail]]);
    }
}
