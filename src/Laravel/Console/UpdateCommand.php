<?php

declare(strict_types=1);

namespace Larena\UpdateClient\Laravel\Console;

use Illuminate\Console\Command;
use Larena\UpdateClient\Data\UpdateCycleSnapshot;
use PDOException;
use RuntimeException;
use Throwable;

/**
 * Shared output of the update commands: `--json` prints the payload, otherwise the command's own readable lines.
 * A failure prints only its stable reason code, never an exception text or a path.
 */
abstract class UpdateCommand extends Command
{
    /**
     * @param callable(): array<string,mixed>                 $operation returns the payload; `status` decides the exit code
     * @param callable(array<string,mixed>): list<string>    $lines     readable lines for a successful payload
     * @param list<string>                                    $okStates  `status` values that exit with success
     */
    final protected function respond(callable $operation, callable $lines, array $okStates): int
    {
        try {
            $payload = $operation();
        } catch (Throwable $exception) {
            $payload = ['status' => 'failed', 'reason' => self::reason($exception)];
        }
        $ok = in_array($payload['status'] ?? null, $okStates, true);
        if ((bool) $this->option('json')) {
            $this->line((string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } elseif (($payload['status'] ?? null) === 'failed' && isset($payload['reason']) && !isset($payload['cycle_id'])) {
            $this->error('Failed: ' . $payload['reason'] . '.');
            foreach ((array) ($payload['source_errors'] ?? []) as $source => $reason) {
                $this->line('  ' . $source . ': ' . (is_scalar($reason) ? (string) $reason : ''));
            }
        } else {
            foreach ($lines($payload) as $line) {
                $ok ? $this->line($line) : $this->error($line);
            }
        }

        return $ok ? self::SUCCESS : self::FAILURE;
    }

    final protected function actor(): string
    {
        $actor = trim((string) $this->option('actor'));
        if ($actor === '') {
            throw new RuntimeException('update_actor_required');
        }
        if (preg_match('/\A[a-z][a-z0-9_-]*:[A-Za-z0-9][A-Za-z0-9:._-]{0,191}\z/D', $actor) !== 1) {
            throw new RuntimeException('update_actor_invalid');
        }

        return $actor;
    }

    /** @return array<string,mixed> */
    final protected static function cycle(UpdateCycleSnapshot $cycle, ?string $updatedAt = null): array
    {
        return array_filter([
            'cycle_id' => $cycle->cycleId,
            'state' => $cycle->state->value,
            'from' => $cycle->release?->from,
            'to' => $cycle->release?->to,
            'actor' => $cycle->request->actorRef,
            'completed_steps' => count($cycle->completedSteps),
            'failed_step' => $cycle->failedStep,
            'reason' => $cycle->reasonCode,
            'backup_ref' => $cycle->rollbackBoundary?->snapshotRef,
            'backward_incompatible_migrations' => $cycle->release?->backwardIncompatibleMigrations,
            'revision' => $cycle->revision,
            'updated_at' => $updatedAt,
        ], static fn (mixed $value): bool => $value !== null);
    }

    final protected static function describeState(string $state): string
    {
        return match ($state) {
            'completed' => 'completed',
            'rolled_back' => 'taken back to the previous release',
            'recoverable_failed' => 'failed and rolled back automatically; the site runs the previous release and the update can be tried again',
            'manual_recovery_required' => 'failed and the rollback did not finish; the site stays in maintenance and needs manual recovery',
            'blocked' => 'stopped before anything on the site changed',
            'planned', 'execution_ready', 'running' => 'in progress',
            default => $state,
        };
    }

    final protected static function reason(Throwable $exception): string
    {
        if ($exception instanceof PDOException) {
            // The site database or the `update_cycles` table is missing: `php artisan migrate` creates it.
            return 'update_store_unavailable';
        }
        $message = $exception->getMessage();

        return preg_match('/\A[a-z][a-z0-9_.-]{0,99}\z/D', $message) === 1 ? $message : 'update_operation_failed';
    }
}
