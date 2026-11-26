<?php

declare(strict_types=1);

namespace Larena\UpdateClient\Laravel;

use Larena\UpdateClient\Data\UpdateCycleSnapshot;
use Larena\UpdateClient\Enums\UpdateCycleState;
use RuntimeException;
use Throwable;

/**
 * Installs one signed release (plan 2026-11-17-update-client-plan.md, §3): download, check, copy, new code, migrations,
 * switch, health check, automatic rollback on failure. `larena:update:apply` and the admin's queue job
 * (`update.release.install`, «Лицензия и обновления» → «Обновления») both run exactly this.
 *
 * The payload carries codes only: never a key, a path of the server or an exception text.
 */
final readonly class ReleaseInstall
{
    public function __construct(private SiteUpdateClient $client)
    {
    }

    /** @return array<string,mixed> `status` is the cycle's end state, or `failed` with a `reason` */
    public function install(string $version, string $actor, string $idempotencyKey): array
    {
        if (preg_match('/\A[A-Za-z0-9][A-Za-z0-9:._-]{2,127}\z/D', $idempotencyKey) !== 1) {
            throw new RuntimeException('update_idempotency_key_invalid');
        }
        $client = $this->client;
        $installed = $client->installedVersion();
        $source = $client->source();
        try {
            $download = $source->download($version, $installed, $client->channel());
        } catch (Throwable $exception) {
            return ['status' => 'failed', 'reason' => self::reason($exception), 'installed' => $installed, 'source_errors' => $source->errors()];
        }

        $preflight = $client->preflight();
        $cycle = $client->cycle($actor, $preflight)->apply(
            $client->request($download->release, $actor, $idempotencyKey),
            $download->releaseBytes,
            $download->signatureBytes,
            $download->archivePath,
            $client->channel(),
        );
        if ($cycle->state === UpdateCycleState::Completed) {
            try {
                $source->forget($version);
            } catch (Throwable) {
                // A leftover download folder is cleaned on the next run; the update itself is done.
            }
        }

        return ['status' => $cycle->state->value, 'source' => $download->source, 'idempotency_key' => $idempotencyKey, 'notes' => $preflight->notes()] + self::cycle($cycle);
    }

    /** @return array<string,mixed> one cycle as the commands and the admin journal show it */
    public static function cycle(UpdateCycleSnapshot $cycle, ?string $updatedAt = null): array
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

    public static function reason(Throwable $exception): string
    {
        if ($exception instanceof \PDOException) {
            // The site database or the `update_cycles` table is missing: `php artisan migrate` creates it.
            return 'update_store_unavailable';
        }
        $message = $exception->getMessage();

        return preg_match('/\A[a-z][a-z0-9_.-]{0,99}\z/D', $message) === 1 ? $message : 'update_operation_failed';
    }
}
