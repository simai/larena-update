<?php

declare(strict_types=1);

namespace Larena\UpdateClient\Contracts;

use Larena\UpdateClient\Data\UpdateCycleSnapshot;

interface UpdateExecutionStore
{
    public function findByIdempotencyKey(string $idempotencyKey): ?UpdateCycleSnapshot;

    public function require(string $cycleId): UpdateCycleSnapshot;

    public function create(UpdateCycleSnapshot $snapshot): UpdateCycleSnapshot;

    /** A cycle that is still running or waits for manual recovery ({@see \Larena\UpdateClient\Enums\UpdateCycleState::isUnfinished()}). */
    public function findUnfinished(): ?UpdateCycleSnapshot;

    public function save(UpdateCycleSnapshot $snapshot, int $expectedRevision): UpdateCycleSnapshot;
}
