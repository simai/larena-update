<?php

declare(strict_types=1);

namespace Larena\UpdateClient\Data;

use InvalidArgumentException;

/**
 * Which release a cycle moves from and to (plan 2026-11-17-update-client-plan.md, §3: «версия было/стало»), and
 * whether the signed release declared migrations the previous code cannot run against (§5a.5). `to` is null until
 * the release description is verified.
 */
final readonly class ReleaseTransition
{
    public function __construct(
        public string $from,
        public ?string $to = null,
        public bool $backwardIncompatibleMigrations = false,
    ) {
        foreach ([$this->from, $this->to] as $id) {
            if ($id !== null && (preg_match('/\A[a-z0-9][a-z0-9._-]{0,63}\z/D', $id) !== 1 || str_contains($id, '..'))) {
                throw new InvalidArgumentException('update_cycle_release_invalid');
            }
        }
    }
}
