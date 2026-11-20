<?php

declare(strict_types=1);

namespace Larena\UpdateClient\Laravel\Integrity;

use Larena\Queue\Contracts\QueueJobHandler;
use Larena\Queue\Data\QueueExecutionContext;
use Larena\Queue\Data\QueueJobResult;

/**
 * The full check as a queue job (`update.integrity.scan`): each run of the job is one portion; while the check is not
 * at its end the job asks the queue to run it again shortly ({@see QueueJobResult::retryLater()}), so no run holds a
 * lock or a worker for long. The end is written to «История».
 */
final readonly class IntegrityScanJobHandler implements QueueJobHandler
{
    public const JOB_TYPE = 'update.integrity.scan';
    public const OPERATION = 'update.integrity.scan';

    public function __construct(
        private SiteIntegrity $integrity,
        private int $portion = 2000,
        private float $seconds = 5.0,
    ) {
    }

    public function handle(QueueExecutionContext $context, array $payload): QueueJobResult
    {
        $trigger = is_string($payload['trigger'] ?? null) ? $payload['trigger'] : 'schedule';
        $scanner = $this->integrity->scanner();
        $started = $scanner->start($trigger);
        $wasRunning = (($started['current'] ?? [])['state'] ?? null) === 'running';
        $state = $scanner->step($this->portion, $this->seconds);
        $run = (array) ($state['current'] ?? []);
        if (($run['state'] ?? null) === 'running') {
            return QueueJobResult::retryLater(2, 'integrity_scan_continues', ['phase' => (string) ($run['phase'] ?? ''), 'checked' => (int) (($run['counts'] ?? [])['checked'] ?? 0)]);
        }
        if ($wasRunning) {
            $this->integrity->recordCompleted($run, SiteIntegrity::ACTOR);
        }

        return QueueJobResult::success(['result' => (string) ($run['result'] ?? ''), 'reason' => isset($run['reason']) ? (string) $run['reason'] : null]);
    }
}
