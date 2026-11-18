<?php

declare(strict_types=1);

namespace Larena\UpdateClient\Runtime;

use Closure;
use DateTimeImmutable;
use JsonException;
use Larena\Filesystem\Enums\FileLifecycleClass;
use Larena\Filesystem\Enums\FileVisibility;
use Larena\Filesystem\Runtime\ArrayLogicalFile;
use Larena\Filesystem\Runtime\ArrayStorageBlobReference;
use Larena\Licensing\Enums\EntitlementStatus;
use Larena\Licensing\Runtime\StaticCapability;
use Larena\UpdateClient\Contracts\ArtifactVerificationResult;
use Larena\UpdateClient\Contracts\EntitlementSnapshot;
use Larena\UpdateClient\Contracts\RollbackBoundary;
use Larena\UpdateClient\Contracts\RestoreAwareExecutionStore;
use Larena\UpdateClient\Contracts\UpdateManifest;
use Larena\UpdateClient\Data\ReleaseTransition;
use Larena\UpdateClient\Data\UpdateCycleRequest;
use Larena\UpdateClient\Data\UpdateCycleSnapshot;
use Larena\UpdateClient\Enums\ArtifactVerificationStatus;
use Larena\UpdateClient\Enums\UpdateCycleState;
use PDO;
use PDOException;
use RuntimeException;
use Throwable;

/**
 * Keeps update cycles in the site's own database (table `update_cycles`), so a cycle survives a restart, a failed
 * step and the code switch itself (plan docs/architecture/2026-11-17-update-client-plan.md, §3).
 *
 * A save succeeds only from the revision the caller read (optimistic lock in the UPDATE itself); two processes can
 * never both advance one cycle. The snapshot is kept whole as JSON next to the columns that are queried.
 *
 * The table is in the database a rollback restores: {@see history()} and {@see reinstate()} carry the cycles over a
 * restore, and `$reconnect` gives the connection to the restored database (a replaced SQLite file is a new file).
 */
final class PdoUpdateExecutionStore implements RestoreAwareExecutionStore
{
    public const TABLE = 'update_cycles';
    private const COLUMNS = ['cycle_id', 'idempotency_key', 'revision', 'state', 'snapshot', 'updated_at'];

    /** @param (Closure(): PDO)|null $reconnect a fresh connection to the same database; without it the open one is kept */
    public function __construct(private PDO $pdo, private readonly ?Closure $reconnect = null)
    {
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    }

    public function history(): array
    {
        $rows = [];
        foreach ($this->pdo->query('SELECT '.implode(', ', self::COLUMNS).' FROM '.self::TABLE.' ORDER BY updated_at, cycle_id')->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (is_array($row)) {
                $rows[] = array_map(static fn (mixed $value): int|string => is_int($value) ? $value : (string) $value, $row);
            }
        }

        return $rows;
    }

    public function reinstate(array $history): void
    {
        if ($this->reconnect !== null) {
            $this->pdo = ($this->reconnect)();
            $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        }
        $this->pdo->beginTransaction();
        try {
            $delete = $this->pdo->prepare('DELETE FROM '.self::TABLE.' WHERE cycle_id = ? OR idempotency_key = ?');
            $insert = $this->pdo->prepare('INSERT INTO '.self::TABLE.' ('.implode(', ', self::COLUMNS).') VALUES (?, ?, ?, ?, ?, ?)');
            foreach ($history as $row) {
                $values = [];
                foreach (self::COLUMNS as $column) {
                    $value = $row[$column] ?? null;
                    if (!is_int($value) && !is_string($value)) {
                        throw new RuntimeException('update_cycle_record_invalid');
                    }
                    $values[] = $value;
                }
                $delete->execute([$values[0], $values[1]]);
                $insert->execute($values);
            }
            $this->pdo->commit();
        } catch (Throwable $exception) {
            $this->pdo->rollBack();
            throw $exception instanceof RuntimeException ? $exception : new RuntimeException('update_cycle_store_reinstate_failed');
        }
    }

    /** Statements that create the table for this PDO's driver (sqlite or mysql); a site migration runs them. */
    public static function schema(string $driver): string
    {
        return match ($driver) {
            'sqlite' => 'CREATE TABLE IF NOT EXISTS '.self::TABLE.' (cycle_id CHAR(32) PRIMARY KEY, idempotency_key VARCHAR(128) NOT NULL UNIQUE, revision INTEGER NOT NULL, state VARCHAR(32) NOT NULL, snapshot TEXT NOT NULL, updated_at VARCHAR(32) NOT NULL)',
            'mysql' => 'CREATE TABLE IF NOT EXISTS '.self::TABLE.' (cycle_id CHAR(32) NOT NULL PRIMARY KEY, idempotency_key VARCHAR(128) NOT NULL, revision INT UNSIGNED NOT NULL, state VARCHAR(32) NOT NULL, snapshot MEDIUMTEXT NOT NULL, updated_at VARCHAR(32) NOT NULL, UNIQUE KEY update_cycles_idempotency_unique (idempotency_key), KEY update_cycles_state_index (state)) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
            default => throw new RuntimeException('update_cycle_store_driver_unsupported'),
        };
    }

    public function findByIdempotencyKey(string $idempotencyKey): ?UpdateCycleSnapshot
    {
        $statement = $this->pdo->prepare('SELECT snapshot FROM '.self::TABLE.' WHERE idempotency_key = ?');
        $statement->execute([$idempotencyKey]);
        $row = $statement->fetchColumn();

        return is_string($row) ? self::decode($row) : null;
    }

    public function require(string $cycleId): UpdateCycleSnapshot
    {
        $statement = $this->pdo->prepare('SELECT snapshot FROM '.self::TABLE.' WHERE cycle_id = ?');
        $statement->execute([$cycleId]);
        $row = $statement->fetchColumn();

        return is_string($row) ? self::decode($row) : throw new RuntimeException('update_cycle_not_found');
    }

    public function findUnfinished(): ?UpdateCycleSnapshot
    {
        $states = array_values(array_filter(UpdateCycleState::cases(), static fn (UpdateCycleState $state): bool => $state->isUnfinished()));
        $statement = $this->pdo->prepare('SELECT snapshot FROM '.self::TABLE.' WHERE state IN ('.implode(', ', array_fill(0, count($states), '?')).') ORDER BY updated_at LIMIT 1');
        $statement->execute(array_map(static fn (UpdateCycleState $state): string => $state->value, $states));
        $row = $statement->fetchColumn();

        return is_string($row) ? self::decode($row) : null;
    }

    /**
     * Every unfinished cycle, oldest first (the preflight refuses a second one).
     *
     * @return list<UpdateCycleSnapshot>
     */
    public function unfinished(): array
    {
        $states = array_values(array_filter(UpdateCycleState::cases(), static fn (UpdateCycleState $state): bool => $state->isUnfinished()));
        $statement = $this->pdo->prepare('SELECT snapshot FROM '.self::TABLE.' WHERE state IN ('.implode(', ', array_fill(0, count($states), '?')).') ORDER BY updated_at');
        $statement->execute(array_map(static fn (UpdateCycleState $state): string => $state->value, $states));

        return array_map(self::decode(...), array_values(array_filter($statement->fetchAll(PDO::FETCH_COLUMN), 'is_string')));
    }

    /**
     * The most recently changed cycles, newest first, each with the time of its last change (for status and rollback).
     *
     * @return list<array{snapshot: UpdateCycleSnapshot, updated_at: string}>
     */
    public function latest(int $limit = 10): array
    {
        $statement = $this->pdo->prepare('SELECT snapshot, updated_at FROM '.self::TABLE.' ORDER BY updated_at DESC, revision DESC LIMIT '.max(1, min(100, $limit)));
        $statement->execute();
        $cycles = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (is_array($row) && is_string($row['snapshot'] ?? null)) {
                $cycles[] = ['snapshot' => self::decode($row['snapshot']), 'updated_at' => (string) ($row['updated_at'] ?? '')];
            }
        }

        return $cycles;
    }

    public function create(UpdateCycleSnapshot $snapshot): UpdateCycleSnapshot
    {
        if ($snapshot->revision !== 1) {
            throw new RuntimeException('update_cycle_duplicate');
        }
        try {
            $this->pdo->prepare('INSERT INTO '.self::TABLE.' (cycle_id, idempotency_key, revision, state, snapshot, updated_at) VALUES (?, ?, ?, ?, ?, ?)')
                ->execute([$snapshot->cycleId, $snapshot->request->idempotencyKey, 1, $snapshot->state->value, self::encode($snapshot), self::now()]);
        } catch (PDOException) {
            throw new RuntimeException('update_cycle_duplicate');
        }

        return $snapshot;
    }

    public function save(UpdateCycleSnapshot $snapshot, int $expectedRevision): UpdateCycleSnapshot
    {
        if ($snapshot->revision !== $expectedRevision + 1) {
            throw new RuntimeException('update_cycle_stale_state');
        }
        $statement = $this->pdo->prepare('UPDATE '.self::TABLE.' SET revision = ?, state = ?, snapshot = ?, updated_at = ? WHERE cycle_id = ? AND revision = ? AND idempotency_key = ?');
        $statement->execute([$snapshot->revision, $snapshot->state->value, self::encode($snapshot), self::now(), $snapshot->cycleId, $expectedRevision, $snapshot->request->idempotencyKey]);
        if ($statement->rowCount() !== 1) {
            $this->require($snapshot->cycleId);
            throw new RuntimeException('update_cycle_stale_state');
        }

        return $snapshot;
    }

    private static function now(): string
    {
        return (new DateTimeImmutable('now'))->format(DATE_ATOM);
    }

    private static function encode(UpdateCycleSnapshot $snapshot): string
    {
        $request = $snapshot->request;
        $file = $request->artifactFile;
        $blob = $file->blob();
        $entitlement = $request->entitlement;
        $rollback = $snapshot->rollbackBoundary;

        return json_encode([
            'cycle_id' => $snapshot->cycleId,
            'state' => $snapshot->state->value,
            'attempt' => $snapshot->attempt,
            'revision' => $snapshot->revision,
            'next_step_index' => $snapshot->nextStepIndex,
            'completed_steps' => $snapshot->completedSteps,
            'failed_step' => $snapshot->failedStep,
            'reason_code' => $snapshot->reasonCode,
            'rollback' => $rollback === null ? null : [
                'backup_plan_ref' => $rollback->backupPlanRef,
                'snapshot_ref' => $rollback->snapshotRef,
                'dry_run_completed' => $rollback->dryRunCompleted,
                'irreversible_steps_declared' => $rollback->irreversibleStepsDeclared,
                'approval_required' => $rollback->approvalRequired,
            ],
            'release' => $snapshot->release === null ? null : [
                'from' => $snapshot->release->from,
                'to' => $snapshot->release->to,
                'backward_incompatible_migrations' => $snapshot->release->backwardIncompatibleMigrations,
            ],
            'request' => [
                'actor_ref' => $request->actorRef,
                'correlation_ref' => $request->correlationRef,
                'idempotency_key' => $request->idempotencyKey,
                'plan_key' => $request->planKey,
                'steps' => $request->steps,
                'manifest' => [
                    'manifest_key' => $request->manifest->manifestKey,
                    'channel' => $request->manifest->channel,
                    'package_refs' => $request->manifest->packageRefs,
                    'signature_ref' => $request->manifest->signatureRef,
                    'signature_verified' => $request->manifest->signatureVerified,
                ],
                'entitlement' => [
                    'snapshot_key' => $entitlement->snapshotKey,
                    'site_identity' => $entitlement->siteIdentity,
                    'capabilities' => $entitlement->capabilities,
                    'redacted' => $entitlement->redacted,
                    'signed' => $entitlement->signed,
                    'signature_verified' => $entitlement->signatureVerified,
                    'direct_registration_contact' => $entitlement->directRegistrationContact,
                    'status' => $entitlement->entitlementStatus->value,
                    'signing_key_id' => $entitlement->signingKeyId,
                    'signature_value' => $entitlement->signatureValue,
                    'valid_until' => $entitlement->validUntil?->format(DATE_ATOM),
                ],
                'capability' => [
                    'key' => $request->capability->key(),
                    'package' => $request->capability->package(),
                    'free_by_default' => $request->capability->isFreeByDefault(),
                    'fail_closed_when_unknown' => $request->capability->failClosedWhenUnknown(),
                ],
                'artifact' => [
                    'artifact_key' => $request->artifact->artifactKey,
                    'checksum' => $request->artifact->checksum,
                    'signature_ref' => $request->artifact->signatureRef,
                    'manifest_binding' => $request->artifact->manifestBinding,
                    'status' => $request->artifact->status->value,
                    'channel_matched' => $request->artifact->channelMatched,
                    'compatible' => $request->artifact->compatible,
                ],
                'artifact_file' => [
                    'id' => $file->id(),
                    'stable_public_id' => $file->stablePublicId(),
                    'visibility' => $file->visibility()->value,
                    'lifecycle_class' => $file->lifecycleClass()->value,
                    'blob' => [
                        'sha256' => $blob->sha256(),
                        'storage_profile' => $blob->storageProfile(),
                        'size_bytes' => $blob->sizeBytes(),
                        'mime_type' => $blob->mimeType(),
                    ],
                ],
            ],
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    private static function decode(string $json): UpdateCycleSnapshot
    {
        try {
            $d = json_decode($json, true, 16, JSON_THROW_ON_ERROR);
            $r = $d['request'];
            $m = $r['manifest'];
            $e = $r['entitlement'];
            $c = $r['capability'];
            $a = $r['artifact'];
            $f = $r['artifact_file'];
            $b = $f['blob'];
            $rb = $d['rollback'];
            $rl = $d['release'] ?? null;
            $request = new UpdateCycleRequest(
                actorRef: $r['actor_ref'],
                correlationRef: $r['correlation_ref'],
                idempotencyKey: $r['idempotency_key'],
                planKey: $r['plan_key'],
                manifest: new UpdateManifest($m['manifest_key'], $m['channel'], $m['package_refs'], $m['signature_ref'], $m['signature_verified']),
                entitlement: new EntitlementSnapshot(
                    $e['snapshot_key'], $e['site_identity'], $e['capabilities'], $e['redacted'], $e['signed'], $e['signature_verified'],
                    $e['direct_registration_contact'], EntitlementStatus::from($e['status']), $e['signing_key_id'], $e['signature_value'],
                    $e['valid_until'] === null ? null : new DateTimeImmutable($e['valid_until']),
                ),
                capability: new StaticCapability($c['key'], $c['package'], $c['free_by_default'], $c['fail_closed_when_unknown']),
                artifact: new ArtifactVerificationResult(
                    $a['artifact_key'], $a['checksum'], $a['signature_ref'], $a['manifest_binding'],
                    ArtifactVerificationStatus::from($a['status']), $a['channel_matched'], $a['compatible'],
                ),
                artifactFile: new ArrayLogicalFile(
                    $f['id'], $f['stable_public_id'],
                    new ArrayStorageBlobReference($b['sha256'], $b['storage_profile'], $b['size_bytes'], $b['mime_type']),
                    FileVisibility::from($f['visibility']), FileLifecycleClass::from($f['lifecycle_class']),
                ),
                steps: $r['steps'],
            );

            return new UpdateCycleSnapshot(
                cycleId: $d['cycle_id'],
                request: $request,
                state: UpdateCycleState::from($d['state']),
                attempt: $d['attempt'],
                revision: $d['revision'],
                nextStepIndex: $d['next_step_index'],
                completedSteps: $d['completed_steps'],
                failedStep: $d['failed_step'],
                reasonCode: $d['reason_code'],
                rollbackBoundary: $rb === null ? null : new RollbackBoundary(
                    $rb['backup_plan_ref'], $rb['snapshot_ref'], $rb['dry_run_completed'], $rb['irreversible_steps_declared'], $rb['approval_required'],
                ),
                release: $rl === null ? null : new ReleaseTransition($rl['from'], $rl['to'], $rl['backward_incompatible_migrations']),
            );
        } catch (JsonException|Throwable) {
            throw new RuntimeException('update_cycle_record_invalid');
        }
    }
}
