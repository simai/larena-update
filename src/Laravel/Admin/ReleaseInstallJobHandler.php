<?php

declare(strict_types=1);

namespace Larena\UpdateClient\Laravel\Admin;

use Closure;
use Larena\Queue\Contracts\QueueJobHandler;
use Larena\Queue\Data\QueueExecutionContext;
use Larena\Queue\Data\QueueJobResult;
use Larena\UpdateClient\Enums\UpdateCycleState;
use Larena\UpdateClient\Laravel\ReleaseInstall;

/**
 * «Установить» of the admin as a queue job (`update.release.install`): runs exactly `larena:update:apply` in a worker,
 * one attempt (a cycle that failed rolled back itself and is tried again only by a person), and its end goes to the
 * update store's journal like any cycle.
 */
final readonly class ReleaseInstallJobHandler implements QueueJobHandler
{
    /**
     * @param Closure(): ReleaseInstall $install
     * @param Closure(): void           $finished clears the queued mark of the admin page
     */
    public function __construct(private Closure $install, private Closure $finished)
    {
    }

    public function handle(QueueExecutionContext $context, array $payload): QueueJobResult
    {
        $version = is_string($payload['version'] ?? null) ? $payload['version'] : '';
        $actor = is_string($payload['actor'] ?? null) ? $payload['actor'] : '';
        $key = is_string($payload['idempotency_key'] ?? null) ? $payload['idempotency_key'] : '';
        try {
            $result = ($this->install)()->install($version, $actor, $key);
        } catch (\Throwable $exception) {
            $result = ['status' => 'failed', 'reason' => ReleaseInstall::reason($exception)];
        } finally {
            ($this->finished)();
        }
        $summary = ['state' => (string) ($result['status'] ?? 'failed'), 'reason' => isset($result['reason']) ? (string) $result['reason'] : null,
            'cycle_id' => isset($result['cycle_id']) ? (string) $result['cycle_id'] : null];

        return ($result['status'] ?? null) === UpdateCycleState::Completed->value
            ? QueueJobResult::success($summary)
            : QueueJobResult::failure((string) ($summary['reason'] ?? 'update_operation_failed'), false, $summary);
    }
}
