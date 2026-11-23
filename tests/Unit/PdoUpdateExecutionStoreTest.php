<?php

declare(strict_types=1);

use Larena\Access\Contracts\QueryScopeProvider;
use Larena\Access\ValueObjects\AccessDecision;
use Larena\Core\Contracts\OperationContext;
use Larena\Core\Contracts\OperationDecision;
use Larena\Core\Contracts\OperationDescriptor;
use Larena\Core\Contracts\OperationResult;
use Larena\Core\Contracts\OperationRuntime;
use Larena\Core\Enums\OperationExecutionMode;
use Larena\Filesystem\Enums\FileLifecycleClass;
use Larena\Filesystem\Enums\FileVisibility;
use Larena\Filesystem\Runtime\ArrayLogicalFile;
use Larena\Filesystem\Runtime\ArrayStorageBlobReference;
use Larena\Filesystem\Runtime\InMemoryFilesystemRuntime;
use Larena\Licensing\Runtime\SnapshotLicensingRuntime;
use Larena\Licensing\Runtime\StaticCapability;
use Larena\UpdateClient\Contracts\ArtifactVerificationResult;
use Larena\UpdateClient\Contracts\EntitlementSnapshot;
use Larena\UpdateClient\Contracts\RollbackBoundary;
use Larena\UpdateClient\Contracts\UpdateManifest;
use Larena\UpdateClient\Contracts\UpdatePlan;
use Larena\UpdateClient\Contracts\UpdateRecoveryBoundary;
use Larena\UpdateClient\Data\UpdateCycleRequest;
use Larena\UpdateClient\Data\UpdateCycleSnapshot;
use Larena\UpdateClient\Enums\ArtifactVerificationStatus;
use Larena\UpdateClient\Enums\UpdateCycleState;
use Larena\UpdateClient\Runtime\GovernedUpdateClientRuntime;
use Larena\UpdateClient\Runtime\PdoUpdateExecutionStore;
use Larena\UpdateClient\Runtime\RecordingUpdateLifecycleAudit;

function pdo_store_assert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

final class PdoStoreScope implements QueryScopeProvider
{
    public function supports(string $resourceType, string $operation): bool { return true; }
    public function scope(array $query, string $actor, string $operation, array $context = []): array { return $query; }
    public function explain(string $resourceType, string $actor, string $operation, array $context = []): AccessDecision
    { return AccessDecision::allow($operation, $actor, 'update.plan:all', 'fixture_allowed'); }
}

final class PdoStoreOperations implements OperationRuntime
{
    public int $calls = 0;
    public bool $fail = false;
    public function decide(OperationDescriptor $descriptor, OperationContext $context): OperationDecision { return OperationDecision::allowed(OperationExecutionMode::Sync); }
    public function execute(OperationDescriptor $descriptor, OperationContext $context): OperationResult
    {
        $this->calls++;
        return OperationResult::fromDecision($this->fail ? OperationDecision::invalid('fixture_step_failed') : OperationDecision::allowed(OperationExecutionMode::Sync));
    }
    public function explain(OperationDescriptor $descriptor, OperationContext $context): array { return []; }
}

final class PdoStoreRecovery implements UpdateRecoveryBoundary
{
    public function prepare(UpdateCycleRequest $request, UpdatePlan $plan): RollbackBoundary
    { return new RollbackBoundary('backup.plan.fixture', 'backup.snapshot.fixture', true, true, false); }
    public function rollback(UpdateCycleSnapshot $failed): bool { return true; }
}

$pdoFile = tempnam(sys_get_temp_dir(), 'update-cycles-');
try {
    $pdo = new PDO('sqlite:'.$pdoFile);
    $pdo->exec(PdoUpdateExecutionStore::schema('sqlite'));
    $pdoOps = new PdoStoreOperations();
    $makeRuntime = static fn (): GovernedUpdateClientRuntime => new GovernedUpdateClientRuntime(
        new PdoStoreScope(), new SnapshotLicensingRuntime(), new InMemoryFilesystemRuntime(), $pdoOps,
        new PdoStoreRecovery(), new RecordingUpdateLifecycleAudit(), new PdoUpdateExecutionStore(new PDO('sqlite:'.$pdoFile)),
    );
    $entitlement = new EntitlementSnapshot('site.entitlement', 'site-fixture', ['update.install'], true, true, true);
    $manifest = new UpdateManifest('channel.stable.manifest', 'stable', ['larena/update', 'larena/core'], 'signature:manifest', true);
    $planKey = 'update.plan.p'.substr(hash('sha256', $manifest->manifestKey.'|'.$entitlement->snapshotKey), 0, 16);
    $artifact = new ArtifactVerificationResult('artifact.fixture', 'sha256:'.str_repeat('d', 64), 'signature:artifact', $manifest->manifestKey, ArtifactVerificationStatus::Verified, true, true);
    $request = static fn (string $key): UpdateCycleRequest => new UpdateCycleRequest(
        'user:1', 'correlation:pdo:store', $key, $planKey, $manifest, $entitlement,
        new StaticCapability('update.install', 'larena/update'), $artifact,
        new ArrayLogicalFile('logical:pdo', 'pdo', new ArrayStorageBlobReference($artifact->checksum, 'fixture', 10, 'application/gzip'), FileVisibility::Internal, FileLifecycleClass::Temporary),
        ['apply_fixture', 'migrate_fixture'],
    );

    // A completed cycle survives a new process: a fresh store over the same database replays it without side effects.
    $first = $makeRuntime()->run($request('update:pdo:1'));
    $callsAfterFirst = $pdoOps->calls;
    $again = $makeRuntime()->run($request('update:pdo:1'));
    pdo_store_assert($first->state === UpdateCycleState::Completed && $again == $first, 'A persisted cycle must replay equal to the first run.');
    pdo_store_assert($pdoOps->calls === $callsAfterFirst, 'Replay from the database must not run any step again.');

    // A failed cycle is kept with its failed step and can be retried from the database after a restart.
    $pdoOps->fail = true;
    $failed = $makeRuntime()->run($request('update:pdo:2'));
    pdo_store_assert($failed->state === UpdateCycleState::RecoverableFailed && $failed->failedStep === 'apply_fixture', 'A failed step must be kept as recoverable.');
    $pdoOps->fail = false;
    $retried = $makeRuntime()->retry($failed->cycleId, $failed->revision);
    pdo_store_assert($retried->state === UpdateCycleState::Completed && $retried->attempt === 2, 'Retry from the database must finish the cycle.');

    // Optimistic lock: a save from an old revision is refused and leaves the stored cycle as it was.
    $store = new PdoUpdateExecutionStore(new PDO('sqlite:'.$pdoFile));
    $current = $store->require($retried->cycleId);
    $stale = false;
    try {
        $store->save($failed->transition(UpdateCycleState::Blocked, $failed->nextStepIndex, $failed->completedSteps, null, 'fixture_stale'), $failed->revision);
    } catch (RuntimeException $exception) {
        $stale = $exception->getMessage() === 'update_cycle_stale_state';
    }
    pdo_store_assert($stale && $store->require($retried->cycleId) == $current, 'A stale save must be refused without changes.');

    $duplicate = false;
    try {
        $store->create($first);
    } catch (RuntimeException $exception) {
        $duplicate = $exception->getMessage() === 'update_cycle_duplicate';
    }
    pdo_store_assert($duplicate, 'A second cycle with one idempotency key must be refused.');
    pdo_store_assert(str_contains(PdoUpdateExecutionStore::schema('mysql'), 'UNIQUE KEY update_cycles_idempotency_unique'), 'MySQL schema must keep idempotency unique.');
} finally {
    @unlink($pdoFile);
}
