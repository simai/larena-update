<?php

declare(strict_types=1);

namespace Larena\UpdateClient\Release;

use InvalidArgumentException;
use Larena\UpdateClient\Contracts\RollbackBoundary;
use Larena\UpdateClient\Contracts\UpdateExecutionStore;
use Larena\UpdateClient\Contracts\UpdateLifecycleAudit;
use Larena\UpdateClient\Data\ReleaseTransition;
use Larena\UpdateClient\Data\UpdateCycleRequest;
use Larena\UpdateClient\Data\UpdateCycleSnapshot;
use Larena\UpdateClient\Enums\UpdateCycleState;
use RuntimeException;
use Throwable;

/**
 * The update cycle of one site with automatic rollback (plan docs/architecture/2026-11-17-update-client-plan.md,
 * §3 steps 1–7, §5a.1, §5a.5). Every outside action goes through a seam; this class only orders them, moves
 * `current` and keeps the record.
 *
 * Steps ({@see STEPS}): preflight → signature and archive → site copy (no verified copy, no code) → unpack and
 * shared links → maintenance on (from the running release) → migrations, access presets, caches (from the new
 * release) → switch `current` → activations → health → maintenance off → cleanup (one previous release kept).
 *
 * States in {@see UpdateExecutionStore} (each step is saved and written to {@see UpdateLifecycleAudit}):
 * `planned` until the copy exists; `execution_ready` once the copy is the rollback boundary; `running` while code
 * and database change; `completed`. A failure before the copy is `blocked` (nothing was written to the site).
 * A failure from the unpack on rolls back: `current` back to the previous release, the database restored from the
 * copy if migrations were started, maintenance off — then `recoverable_failed`. If the rollback fails the cycle is
 * `manual_recovery_required` and the site stays in maintenance. A failed cleanup does not undo a healthy update.
 *
 * The same idempotency key returns the stored cycle without running anything; a second cycle while one runs (lock)
 * or while one is unfinished in the store is refused with `update_cycle_already_running`.
 */
final readonly class ReleaseUpdateCycle
{
    public const STEPS = ['preflight', 'verify', 'backup', 'extract', 'maintenance', 'migrate', 'sync_presets', 'clear_caches', 'switch', 'activate', 'health', 'resume', 'cleanup'];

    private string $site;
    private ReleasePointer $pointer;

    /** @param list<ReleaseActivation> $activations run after every real move of `current`, the rollback included */
    public function __construct(
        string $siteRoot,
        private ReleaseSignatureVerifier $verifier,
        private ReleaseArchiveExtractor $extractor,
        private UpdatePreflight $preflight,
        private SiteBackup $backup,
        private DatabaseRestore $restore,
        private MaintenanceMode $maintenance,
        private ReleaseMigrations $migrations,
        private SiteHealth $health,
        private array $activations,
        private UpdateExecutionStore $store,
        private UpdateLifecycleAudit $audit,
    ) {
        $this->site = ReleaseDirectory::site($siteRoot);
        $this->pointer = new ReleasePointer($this->site);
    }

    public function apply(UpdateCycleRequest $request, string $releaseBytes, string $signatureBytes, string $archivePath, string $channel = 'stable'): UpdateCycleSnapshot
    {
        if ($request->steps !== self::STEPS) {
            throw new InvalidArgumentException('update_cycle_plan_invalid');
        }
        $existing = $this->store->findByIdempotencyKey($request->idempotencyKey);
        if ($existing !== null) {
            return $existing;
        }
        $lock = ReleaseCycleLock::acquire($this->site);
        try {
            $existing = $this->store->findByIdempotencyKey($request->idempotencyKey);
            if ($existing !== null) {
                return $existing;
            }
            if ($this->store->findUnfinished() !== null) {
                throw new RuntimeException('update_cycle_already_running');
            }
            $cycle = $this->store->create(new UpdateCycleSnapshot($request->cycleId(), $request, UpdateCycleState::Planned, 1, 1, 0));
            if (!$this->record('update.cycle.requested', $cycle, ['channel' => $channel])) {
                return $this->block($cycle, 'update_audit_unavailable', false);
            }

            return $this->run($cycle, $releaseBytes, $signatureBytes, $archivePath, $channel);
        } finally {
            $lock->release();
        }
    }

    /**
     * Takes a completed update back to the release it replaced (plan §3, step 6) while that release folder and the
     * cycle's copy exist. With `$restoreDatabase` the database comes back from the copy (records made after the
     * update are lost); without it only the code moves, which a release with `backward_incompatible_migrations`
     * refuses (§5a.5). A refusal changes nothing; a failure on the way leaves the site in maintenance and the cycle
     * `manual_recovery_required`.
     */
    public function rollback(string $cycleId, int $expectedRevision, bool $restoreDatabase = true): ReleaseRollbackOutcome
    {
        $lock = ReleaseCycleLock::acquire($this->site);
        try {
            $cycle = $this->store->require($cycleId);
            if ($cycle->revision !== $expectedRevision) {
                throw new RuntimeException('update_cycle_stale_state');
            }
            $release = $cycle->release;
            $backupRef = $cycle->rollbackBoundary?->snapshotRef;
            if ($cycle->state !== UpdateCycleState::Completed || $release === null || $release->to === null || $backupRef === null) {
                throw new RuntimeException('update_rollback_not_available');
            }
            if ($this->store->findUnfinished() !== null) {
                throw new RuntimeException('update_cycle_already_running');
            }
            if ($this->pointer->current() !== $release->to) {
                throw new RuntimeException('update_rollback_current_differs');
            }
            $previous = $this->releaseDirectory($release->from);
            if (is_link($previous) || !is_dir($previous)) {
                throw new RuntimeException('update_rollback_previous_missing');
            }
            if ($release->backwardIncompatibleMigrations && !$restoreDatabase) {
                throw new RuntimeException('update_rollback_database_restore_required');
            }
            $warnings = [];
            if ($restoreDatabase) {
                $warnings[] = 'records_after_update_lost';
            }
            if ($release->backwardIncompatibleMigrations) {
                $warnings[] = 'backward_incompatible_migrations';
            }

            $stage = 'maintenance';
            try {
                $this->maintenance->enter($this->releaseDirectory($release->to));
                if ($restoreDatabase) {
                    $stage = 'restore';
                    $this->restore->restoreDatabase($this->site, $backupRef);
                }
                $stage = 'switch';
                $this->activate($this->pointer->switchTo($release->from));
                $stage = 'health';
                $this->assertHealthy();
                $stage = 'resume';
                $this->maintenance->leave($previous);
            } catch (Throwable $exception) {
                $reason = $this->reason($exception, 'update_rollback_'.$stage.'_failed');
                $failed = $this->store->save($cycle->transition(
                    UpdateCycleState::ManualRecoveryRequired, $cycle->nextStepIndex, $cycle->completedSteps,
                    reasonCode: $reason, rollbackBoundary: $cycle->rollbackBoundary,
                ), $cycle->revision);
                $this->record('update.cycle.manual_recovery_required', $failed, ['reason_code' => $reason, 'rollback_stage' => $stage, 'maintenance' => 'on']);

                return new ReleaseRollbackOutcome($failed, $warnings);
            }

            $rolledBack = $this->store->save($cycle->transition(
                UpdateCycleState::RolledBack, $cycle->nextStepIndex, $cycle->completedSteps, rollbackBoundary: $cycle->rollbackBoundary,
            ), $cycle->revision);
            $this->record('update.cycle.rolled_back', $rolledBack, [
                'from_release' => $release->to,
                'to_release' => $release->from,
                'database_restored' => $restoreDatabase,
                'warnings' => implode(',', $warnings),
            ]);

            return new ReleaseRollbackOutcome($rolledBack, $warnings);
        } finally {
            $lock->release();
        }
    }

    private function run(UpdateCycleSnapshot $cycle, string $releaseBytes, string $signatureBytes, string $archivePath, string $channel): UpdateCycleSnapshot
    {
        // 1. Preflight: read-only.
        try {
            $from = $this->pointer->current() ?? throw new RuntimeException('release_current_missing');
            $this->preflight->check($this->site, is_file($archivePath) && !is_link($archivePath) ? (int) filesize($archivePath) : 0);
        } catch (Throwable $exception) {
            return $this->block($cycle, $this->reason($exception, 'update_preflight_failed'));
        }
        $cycle = $this->advance($cycle, UpdateCycleState::Planned, release: new ReleaseTransition($from));

        // 2. Signature and archive: the installed release id is the installed version. Nothing is written yet.
        try {
            $release = $this->verifier->verify($releaseBytes, $signatureBytes, $from, $channel);
            ReleaseSignatureVerifier::verifyArchive($release, $archivePath);
            $to = ReleaseDirectory::id($release->version);
        } catch (Throwable $exception) {
            return $this->block($cycle, $this->reason($exception, 'update_release_invalid'));
        }
        $cycle = $this->advance($cycle, UpdateCycleState::Planned, release: new ReleaseTransition($from, $to, $release->backwardIncompatibleMigrations));

        // 3. Site copy with a trial restore: the rollback boundary. No verified copy, no code.
        try {
            $copy = $this->backup->create($this->site, $cycle->cycleId);
            if (!$copy->restoreVerified) {
                throw new RuntimeException('update_backup_unverified');
            }
            $boundary = new RollbackBoundary('update.backup.site', $copy->ref, true, true, false);
            if (!$boundary->isReady()) {
                throw new RuntimeException('update_backup_invalid');
            }
        } catch (Throwable $exception) {
            return $this->block($cycle, $this->reason($exception, 'update_backup_failed'));
        }
        $cycle = $this->advance($cycle, UpdateCycleState::ExecutionReady, $boundary);

        // 4. Code, maintenance, migrations, switch, activation, health, maintenance off.
        $fromDirectory = $this->releaseDirectory($from);
        $toDirectory = $this->releaseDirectory($to);
        $maintenance = false;
        $migrationsStarted = false;
        $switch = null;
        while (($step = self::STEPS[$cycle->nextStepIndex]) !== 'cleanup') {
            try {
                switch ($step) {
                    case 'extract':
                        $this->extractor->extract($release, $archivePath, $this->site, $to);
                        ReleaseSharedLinks::link($this->site, $to);
                        break;
                    case 'maintenance':
                        $maintenance = true;
                        $this->maintenance->enter($fromDirectory);
                        break;
                    case 'migrate':
                        $migrationsStarted = true;
                        $this->migrations->migrate($toDirectory);
                        break;
                    case 'sync_presets':
                        $this->migrations->syncAccessPresets($toDirectory);
                        break;
                    case 'clear_caches':
                        $this->migrations->clearCaches($toDirectory);
                        break;
                    case 'switch':
                        $switch = $this->pointer->switchTo($to);
                        break;
                    case 'activate':
                        $this->activate($switch ?? throw new RuntimeException('release_switch_failed'));
                        break;
                    case 'health':
                        $this->assertHealthy();
                        break;
                    case 'resume':
                        $this->maintenance->leave($toDirectory);
                        $maintenance = false;
                        break;
                }
            } catch (Throwable $exception) {
                return $this->fail($cycle, $step, $this->reason($exception, 'update_'.$step.'_failed'), $from, $maintenance, $migrationsStarted);
            }
            $cycle = $this->advance($cycle, UpdateCycleState::Running);
        }

        // 5. Cleanup: current and the release it replaced stay. A failure here leaves a healthy update in place.
        try {
            $removed = ReleaseCleanup::prune($this->site, $from);
            $cleanup = ['removed_releases' => implode(',', $removed)];
        } catch (Throwable $exception) {
            $cleanup = ['cleanup_reason' => $this->reason($exception, 'update_cleanup_failed')];
        }
        $completed = $this->advance($cycle, UpdateCycleState::Completed, facts: $cleanup);
        $this->record('update.cycle.completed', $completed, ['from_release' => $from, 'to_release' => $to]);

        return $completed;
    }

    /** Automatic rollback of a failed step; see the class description for the outcome. */
    private function fail(UpdateCycleSnapshot $cycle, string $step, string $reason, string $from, bool $maintenance, bool $migrationsStarted): UpdateCycleSnapshot
    {
        $rolledBack = true;
        $stage = 'switch';
        try {
            if ($this->pointer->current() !== $from) {
                $this->activate($this->pointer->switchTo($from));
            }
            if ($migrationsStarted) {
                $stage = 'restore';
                $this->restore->restoreDatabase($this->site, (string) $cycle->rollbackBoundary?->snapshotRef);
            }
            if ($maintenance) {
                $stage = 'resume';
                $this->maintenance->leave($this->releaseDirectory($from));
            }
        } catch (Throwable) {
            $rolledBack = false;
        }

        $failed = $this->store->save($cycle->transition(
            $rolledBack ? UpdateCycleState::RecoverableFailed : UpdateCycleState::ManualRecoveryRequired,
            $cycle->nextStepIndex,
            $cycle->completedSteps,
            $step,
            $reason,
            $cycle->rollbackBoundary,
        ), $cycle->revision);
        $facts = ['failed_step' => $step, 'reason_code' => $reason, 'database_restored' => $rolledBack && $migrationsStarted];
        if ($rolledBack) {
            $this->record('update.cycle.rollback_confirmed', $failed, $facts);
        } else {
            $this->record('update.cycle.manual_recovery_required', $failed, $facts + ['rollback_stage' => $stage, 'maintenance' => 'on']);
        }

        return $failed;
    }

    /** @param array<string, scalar|null> $facts */
    private function advance(UpdateCycleSnapshot $cycle, UpdateCycleState $state, ?RollbackBoundary $boundary = null, ?ReleaseTransition $release = null, array $facts = []): UpdateCycleSnapshot
    {
        $step = self::STEPS[$cycle->nextStepIndex];
        $next = $this->store->save($cycle->transition(
            $state,
            $cycle->nextStepIndex + 1,
            [...$cycle->completedSteps, $step],
            rollbackBoundary: $boundary ?? $cycle->rollbackBoundary,
            release: $release,
        ), $cycle->revision);
        $this->record('update.cycle.step_completed', $next, ['step' => $step] + $facts);

        return $next;
    }

    private function block(UpdateCycleSnapshot $cycle, string $reason, bool $audit = true): UpdateCycleSnapshot
    {
        $blocked = $this->store->save($cycle->transition(
            UpdateCycleState::Blocked, $cycle->nextStepIndex, $cycle->completedSteps,
            reasonCode: $reason, rollbackBoundary: $cycle->rollbackBoundary,
        ), $cycle->revision);
        if ($audit) {
            $this->record('update.cycle.blocked', $blocked, ['reason_code' => $reason, 'step' => self::STEPS[$cycle->nextStepIndex]]);
        }

        return $blocked;
    }

    private function activate(ReleaseSwitch $switch): void
    {
        if (!$switch->changed) {
            return;
        }
        foreach ($this->activations as $activation) {
            $activation->activated($this->site, $switch);
        }
    }

    private function assertHealthy(): void
    {
        if ($this->health->failedChecks($this->site) !== []) {
            throw new RuntimeException('update_health_failed');
        }
    }

    private function releaseDirectory(string $id): string
    {
        return $this->site.'/releases/'.ReleaseDirectory::id($id);
    }

    /** @param array<string, scalar|null> $facts */
    private function record(string $event, UpdateCycleSnapshot $cycle, array $facts = []): bool
    {
        try {
            $this->audit->record($event, $cycle, $facts);

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    private function reason(Throwable $exception, string $fallback): string
    {
        return $exception instanceof RuntimeException && preg_match('/\A[a-z][a-z0-9_.-]{0,99}\z/D', $exception->getMessage()) === 1
            ? $exception->getMessage()
            : $fallback;
    }
}
