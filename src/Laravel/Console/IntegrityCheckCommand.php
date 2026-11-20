<?php

declare(strict_types=1);

namespace Larena\UpdateClient\Laravel\Console;

use Illuminate\Console\Command;
use Illuminate\Contracts\Foundation\Application;
use Larena\UpdateClient\Integrity\CoreCheck;
use Larena\UpdateClient\Laravel\Integrity\IntegrityScanJobHandler;
use Larena\UpdateClient\Laravel\Integrity\SiteIntegrity;
use Throwable;

/**
 * `larena:integrity:check`: the check of the site's files.
 *
 * - without options: the main files now (A2) and the full check to its end, in portions (A3);
 * - `--queue`: the scheduled way — the full check goes to the Larena queue, one portion per job run; without the
 *   queue package, portions run here for at most `--seconds` and the next tick goes on where this one stopped.
 *
 * Exit 0 only for «без изменений»; 1 for «найдены изменения», 2 for «не удалось проверить» (or not yet finished).
 */
final class IntegrityCheckCommand extends Command
{
    protected $signature = 'larena:integrity:check
        {--queue : Hand the full check to the queue (the scheduled way)}
        {--seconds=50 : Without the queue, how long this run may check}
        {--restart : Start the full check again from the beginning}
        {--json : Print machine-readable JSON only}';

    protected $description = 'Check the site files against the signed list of the running release.';

    public function handle(Application $app, SiteIntegrity $integrity): int
    {
        $core = $integrity->guard()->check();
        if ($this->option('queue') && $app->bound(\Larena\Queue\Runtime\DurableQueueDispatcher::class)
            && $app->bound(\Larena\Queue\Runtime\JobTypeRegistry::class) && $app->make(\Larena\Queue\Runtime\JobTypeRegistry::class)->has(IntegrityScanJobHandler::JOB_TYPE)) {
            try {
                $integrity->scanner()->start('schedule', (bool) $this->option('restart'));
                $dispatched = $app->make(\Larena\Queue\Runtime\DurableQueueDispatcher::class)->dispatch(new \Larena\Queue\Data\DispatchRequest(
                    IntegrityScanJobHandler::JOB_TYPE, ['trigger' => 'schedule'], 'integrity:' . gmdate('YmdH'), 'integrity:' . bin2hex(random_bytes(8)),
                ));

                return $this->answer($core, ['state' => 'queued', 'job' => $dispatched->jobId], 2);
            } catch (Throwable) {
                // No queue to hand it to: the portions run here.
            }
        }
        $seconds = $this->option('queue') ? max(1.0, min(600.0, (float) $this->option('seconds'))) : 3600.0;
        $run = $integrity->scanFor($seconds, $this->option('queue') ? 'schedule' : 'console', SiteIntegrity::ACTOR, (bool) $this->option('restart'));
        $result = ($run['state'] ?? null) === 'completed' ? (string) $run['result'] : 'running';

        return $this->answer($core, [
            'state' => $run['state'] ?? null, 'result' => $result, 'reason' => $run['reason'] ?? null,
            'counts' => $run['counts'] ?? [], 'findings' => $run['findings'] ?? null,
        ], match (true) {
            $result === 'unchanged' && $core->ok() => 0,
            $result === 'changes_found' || $core->status === CoreCheck::CHANGED => 1,
            default => 2,
        });
    }

    /** @param array<string,mixed> $full */
    private function answer(CoreCheck $core, array $full, int $code): int
    {
        $answer = ['core' => ['status' => $core->status, 'reason' => $core->reason, 'problems' => $core->problems], 'full' => $full];
        if ($this->option('json')) {
            $this->line((string) json_encode($answer, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } else {
            $this->line('Main files: ' . $core->status . ($core->reason !== null ? ' (' . $core->reason . ')' : ''));
            foreach ($core->problems as $path => $problem) {
                $this->line('  ' . $path . ': ' . $problem);
            }
            $this->line('Full check: ' . (string) ($full['result'] ?? $full['state'] ?? '?') . (isset($full['reason']) && $full['reason'] !== null ? ' (' . $full['reason'] . ')' : ''));
        }

        return $code;
    }
}
