<?php

declare(strict_types=1);

namespace Larena\UpdateClient\Laravel;

use Larena\UpdateClient\Contracts\UpdateLifecycleAudit;
use Larena\UpdateClient\Data\UpdateCycleSnapshot;
use Larena\UpdateClient\Runtime\RecordingUpdateLifecycleAudit;
use Psr\Log\LoggerInterface;

/**
 * Writes every cycle event to the application log after the same fact checks as the reference audit (event name,
 * safe scalar facts, no secrets or paths). The cycle itself is kept in `update_cycles`; a sink into `larena/audit` is
 * a later step.
 */
final class LoggerUpdateLifecycleAudit implements UpdateLifecycleAudit
{
    private RecordingUpdateLifecycleAudit $rules;

    public function __construct(private readonly LoggerInterface $logger)
    {
        $this->rules = new RecordingUpdateLifecycleAudit();
    }

    public function record(string $event, UpdateCycleSnapshot $snapshot, array $safeFacts = []): string
    {
        $reference = $this->rules->record($event, $snapshot, $safeFacts);
        $this->logger->info($event, [
            'audit_ref' => $reference,
            'cycle_id' => $snapshot->cycleId,
            'actor_ref' => $snapshot->request->actorRef,
            'state' => $snapshot->state->value,
            'revision' => $snapshot->revision,
        ] + $safeFacts);

        return $reference;
    }
}
