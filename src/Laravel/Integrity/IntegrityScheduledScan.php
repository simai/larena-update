<?php

declare(strict_types=1);

namespace Larena\UpdateClient\Laravel\Integrity;

use Larena\Queue\Data\DispatchRequest;
use Larena\Queue\Data\DispatchResult;
use Larena\Queue\Data\QueueJobSnapshot;
use Larena\Queue\Runtime\DurableQueueDispatcher;
use Larena\Queue\Runtime\QueueControlService;
use Larena\Scheduler\Contracts\ScheduledOperationHandler;
use Larena\Scheduler\Data\ScheduledDispatchContext;

/** The full check as an operation of the Larena scheduler: a schedule starts it, the queue runs it in portions. */
final readonly class IntegrityScheduledScan implements ScheduledOperationHandler
{
    public function __construct(private DurableQueueDispatcher $queue, private QueueControlService $control)
    {
    }

    public function operationRef(): string
    {
        return IntegrityScanJobHandler::OPERATION;
    }

    public function dispatch(ScheduledDispatchContext $context): DispatchResult
    {
        return $this->queue->dispatch(new DispatchRequest(
            IntegrityScanJobHandler::JOB_TYPE,
            ['trigger' => 'schedule'],
            $context->idempotencyKey,
            $context->correlationId,
        ));
    }

    public function status(string $jobId): QueueJobSnapshot
    {
        return $this->control->status($jobId);
    }
}
