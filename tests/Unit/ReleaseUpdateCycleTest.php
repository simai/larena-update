<?php

declare(strict_types=1);

use Larena\Filesystem\Enums\FileLifecycleClass;
use Larena\Filesystem\Enums\FileVisibility;
use Larena\Filesystem\Runtime\ArrayLogicalFile;
use Larena\Filesystem\Runtime\ArrayStorageBlobReference;
use Larena\Licensing\Runtime\StaticCapability;
use Larena\UpdateClient\Contracts\ArtifactVerificationResult;
use Larena\UpdateClient\Contracts\EntitlementSnapshot;
use Larena\UpdateClient\Contracts\UpdateManifest;
use Larena\UpdateClient\Data\UpdateCycleRequest;
use Larena\UpdateClient\Enums\ArtifactVerificationStatus;
use Larena\UpdateClient\Enums\UpdateCycleState;
use Larena\UpdateClient\Release\RecordingReleaseActivation;
use Larena\UpdateClient\Release\RecordingUpdateSite;
use Larena\UpdateClient\Release\ReleaseArchiveExtractor;
use Larena\UpdateClient\Release\ReleaseCycleLock;
use Larena\UpdateClient\Release\ReleaseDirectory;
use Larena\UpdateClient\Release\ReleasePointer;
use Larena\UpdateClient\Release\ReleaseSharedLinks;
use Larena\UpdateClient\Release\ReleaseSignatureVerifier;
use Larena\UpdateClient\Release\ReleaseUpdateCycle;
use Larena\UpdateClient\Runtime\InMemoryUpdateExecutionStore;
use Larena\UpdateClient\Runtime\PdoUpdateExecutionStore;
use Larena\UpdateClient\Runtime\RecordingUpdateLifecycleAudit;

function cycle_assert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

/** @param callable():mixed $call */
function cycle_reason(callable $call): string
{
    try {
        $call();
    } catch (Throwable $exception) {
        return $exception->getMessage();
    }

    return 'accepted';
}

function cycle_tar(string $version): string
{
    $tar = '';
    foreach ([['public/', '5', ''], ['artisan', '0', "<?php // $version\n"], ['public/index.php', '0', "<?php echo '$version';\n"]] as [$name, $type, $data]) {
        $header = str_pad($name, 100, "\0") . sprintf('%07o', 0644) . "\0" . sprintf('%07o', 0) . "\0" . sprintf('%07o', 0) . "\0"
            . sprintf('%011o', strlen($data)) . "\0" . sprintf('%011o', 1763337600) . "\0" . '        ' . $type . str_repeat("\0", 100) . "ustar\0" . '00';
        $header = str_pad($header, 512, "\0");
        $header = substr_replace($header, sprintf('%06o', array_sum(array_map('ord', str_split($header)))) . "\0 ", 148, 8);
        $tar .= $header . ($data === '' ? '' : str_pad($data, (int) (ceil(strlen($data) / 512) * 512), "\0"));
    }

    return (string) gzencode($tar . str_repeat("\0", 1024));
}

// A throwaway key for this test only; no real release key is ever in a repository.
$cyclePair = sodium_crypto_sign_keypair();
$cycleForeign = sodium_crypto_sign_keypair();
$cycleVerifier = new ReleaseSignatureVerifier(['release.test' => base64_encode(sodium_crypto_sign_publickey($cyclePair))]);
$cycleRoot = sys_get_temp_dir() . '/larena-update-cycle-' . bin2hex(random_bytes(6));
mkdir($cycleRoot . '/archives', 0755, true);

/** @return array{0:string,1:string,2:string} release.json bytes, signature bytes, archive path */
$signed = static function (string $version, bool $incompatible = false, string $secret = '') use ($cyclePair, $cycleRoot): array {
    $archive = $cycleRoot . '/archives/larena-' . $version . '.tar.gz';
    file_put_contents($archive, cycle_tar($version));
    $bytes = json_encode([
        'schema' => 'larena.release', 'schema_version' => 1, 'product' => 'larena', 'version' => $version, 'channel' => 'stable',
        'minimum_version' => '1.0.0', 'php' => '^8.3',
        'archive' => ['file' => basename($archive), 'sha256' => hash_file('sha256', $archive), 'bytes' => filesize($archive)],
        'packages' => ['larena/core' => str_repeat('a', 40)], 'backward_incompatible_migrations' => $incompatible,
    ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    $signature = json_encode(['key_id' => 'release.test', 'signature' => base64_encode(sodium_crypto_sign_detached($bytes, $secret === '' ? sodium_crypto_sign_secretkey($cyclePair) : $secret))]);

    return [$bytes, (string) $signature, $archive];
};

/** A site on 1.2.0 with 1.1.0 still on disk, its fake outside world, a store and an audit. */
$world = static function (string $name, bool $pdo = false) use ($cycleRoot, $cycleVerifier, $signed): array {
    $site = $cycleRoot . '/' . $name;
    mkdir($site . '/storage/app/public', 0755, true);
    file_put_contents($site . '/.env', "APP_KEY=test\n");
    $pointer = new ReleasePointer($site);
    foreach (['1.1.0', '1.2.0'] as $version) {
        [$bytes, $signature, $archive] = $signed($version);
        (new ReleaseArchiveExtractor())->extract($cycleVerifier->verify($bytes, $signature, '1.0.0'), $archive, $site);
        ReleaseSharedLinks::link($site, $version);
        $pointer->switchTo($version);
    }
    $fake = new RecordingUpdateSite('1.2.0', ['page:1', 'page:2']);
    $activation = new RecordingReleaseActivation();
    if ($pdo) {
        $connection = new PDO('sqlite::memory:');
        $connection->exec(PdoUpdateExecutionStore::schema('sqlite'));
        $store = new PdoUpdateExecutionStore($connection);
    } else {
        $store = new InMemoryUpdateExecutionStore();
    }
    $audit = new RecordingUpdateLifecycleAudit();
    $cycle = new ReleaseUpdateCycle($site, $cycleVerifier, new ReleaseArchiveExtractor(), $fake, $fake, $fake, $fake, $fake, $fake, [$fake, $activation], $store, $audit);

    return ['site' => $site, 'fake' => $fake, 'activation' => $activation, 'store' => $store, 'audit' => $audit, 'cycle' => $cycle, 'pointer' => $pointer];
};

$entitlement = new EntitlementSnapshot('site.entitlement', 'site-fixture', ['update.install'], true, true, true);
$manifest = new UpdateManifest('channel.stable.manifest', 'stable', ['larena/core'], 'signature:manifest', true);
$artifact = new ArtifactVerificationResult('artifact.fixture', 'sha256:' . str_repeat('d', 64), 'signature:artifact', $manifest->manifestKey, ArtifactVerificationStatus::Verified, true, true);
$request = static fn (string $key, ?array $steps = null): UpdateCycleRequest => new UpdateCycleRequest(
    'user:1', 'correlation:update:cycle', $key, 'update.plan.release', $manifest, $entitlement,
    new StaticCapability('update.install', 'larena/update'), $artifact,
    new ArrayLogicalFile('logical:cycle', 'cycle', new ArrayStorageBlobReference($artifact->checksum, 'fixture', 10, 'application/gzip'), FileVisibility::Internal, FileLifecycleClass::Temporary),
    $steps ?? ReleaseUpdateCycle::STEPS,
);
$releases = static fn (string $site): array => array_values(array_filter(scandir($site . '/releases'), static fn (string $n): bool => $n[0] !== '.'));
$events = static fn (RecordingUpdateLifecycleAudit $audit): array => array_column($audit->events(), 'event');

try {
    // Successful cycle N → N+1, kept in the site database (PDO store).
    $w = $world('success', true);
    [$bytes, $signature, $archive] = $signed('1.3.0');
    $done = $w['cycle']->apply($request('update:ok'), $bytes, $signature, $archive);
    cycle_assert($done->state === UpdateCycleState::Completed && $done->completedSteps === ReleaseUpdateCycle::STEPS, 'A clean cycle must complete every step.');
    cycle_assert($w['pointer']->current() === '1.3.0' && $releases($w['site']) === ['1.2.0', '1.3.0'], 'current must move to N+1 and cleanup must keep exactly two releases.');
    cycle_assert($done->release?->from === '1.2.0' && $done->release->to === '1.3.0' && $done->rollbackBoundary?->isReady(), 'The cycle must keep both versions and the copy.');
    cycle_assert($w['fake']->database === ['schema' => '1.3.0', 'records' => ['page:1', 'page:2']] && !$w['fake']->isDown(), 'Records must stay and maintenance must end.');
    cycle_assert($w['fake']->journal === [
        'preflight:' . filesize($archive), 'backup:' . $done->cycleId, 'maintenance.enter:1.2.0', 'migrate:1.3.0', 'sync_presets:1.3.0',
        'clear_caches:1.3.0', 'activate:1.3.0', 'health:1.3.0', 'maintenance.leave:1.3.0',
    ], 'Outside steps must run in plan order, from the right release folder.');
    cycle_assert(count($w['activation']->switches) === 1 && $w['activation']->switches[0]->previous === '1.2.0', 'Activation must follow the switch.');
    cycle_assert(count(array_keys($events($w['audit']), 'update.cycle.step_completed')) === count(ReleaseUpdateCycle::STEPS)
        && array_slice($events($w['audit']), -1) === ['update.cycle.completed'], 'Every step must reach the audit.');
    cycle_assert($w['store']->require($done->cycleId) == $done && $w['store']->findUnfinished() === null, 'The stored cycle must equal the result.');

    // Same key: no second cycle.
    $journal = $w['fake']->journal;
    cycle_assert($w['cycle']->apply($request('update:ok'), $bytes, $signature, $archive) == $done && $w['fake']->journal === $journal, 'A repeated key must return the stored cycle and run nothing.');

    // Manual rollback N+1 → N: code back, database back from the cycle's copy.
    $w['fake']->database['records'][] = 'page:after-update';
    $back = $w['cycle']->rollback($done->cycleId, $done->revision);
    cycle_assert($back->snapshot->state === UpdateCycleState::RolledBack && $back->warnings === ['records_after_update_lost'], 'A manual rollback must finish with its warning.');
    cycle_assert($w['pointer']->current() === '1.2.0' && $w['fake']->database === ['schema' => '1.2.0', 'records' => ['page:1', 'page:2']] && !$w['fake']->isDown(), 'Manual rollback must restore code and database.');
    cycle_assert(cycle_reason(fn () => $w['cycle']->rollback($done->cycleId, $back->snapshot->revision)) === 'update_rollback_not_available', 'A rolled back cycle cannot be rolled back again.');

    // Failing migration: automatic rollback, database restored, current never left N.
    $w = $world('migration');
    $w['fake']->fail('migrate', 'fixture_migration_failed');
    [$bytes, $signature, $archive] = $signed('1.3.0');
    $failed = $w['cycle']->apply($request('update:migration'), $bytes, $signature, $archive);
    cycle_assert($failed->state === UpdateCycleState::RecoverableFailed && $failed->failedStep === 'migrate' && $failed->reasonCode === 'fixture_migration_failed', 'A failed migration must end recoverable.');
    cycle_assert($w['pointer']->current() === '1.2.0' && $w['fake']->database['schema'] === '1.2.0' && !$w['fake']->isDown(), 'The database must come back and maintenance must end.');
    cycle_assert(in_array('restore:' . $failed->rollbackBoundary?->snapshotRef, $w['fake']->journal, true) && end($w['fake']->journal) === 'maintenance.leave:1.2.0', 'The copy of this cycle must be restored.');
    cycle_assert(in_array('update.cycle.rollback_confirmed', $events($w['audit']), true), 'The rollback must reach the audit.');

    // Failed health after the switch: current goes back, activations run again, database restored.
    $w = $world('health');
    $w['fake']->fail('health');
    $failed = $w['cycle']->apply($request('update:health'), ...$signed('1.3.0'));
    cycle_assert($failed->state === UpdateCycleState::RecoverableFailed && $failed->failedStep === 'health' && $failed->reasonCode === 'update_health_failed', 'A failed health check must end recoverable.');
    cycle_assert($w['pointer']->current() === '1.2.0' && count($w['activation']->switches) === 2 && $w['activation']->switches[1]->current === '1.2.0', 'current must go back to N with an activation.');
    cycle_assert($w['fake']->database['schema'] === '1.2.0' && !$w['fake']->isDown(), 'The database must come back after a failed health check.');

    // Failure before migrations: no database restore.
    $w = $world('early');
    $w['fake']->fail('maintenance.enter');
    $failed = $w['cycle']->apply($request('update:early'), ...$signed('1.3.0'));
    cycle_assert($failed->state === UpdateCycleState::RecoverableFailed && $failed->failedStep === 'maintenance', 'An early failure must end recoverable.');
    cycle_assert(preg_grep('/\Arestore:/', $w['fake']->journal) === [] && $w['pointer']->current() === '1.2.0', 'Nothing may be restored before migrations ran.');

    // The rollback itself fails: manual recovery, site stays in maintenance, no new cycle may start.
    $w = $world('manual');
    $w['fake']->fail('health')->fail('restore');
    $failed = $w['cycle']->apply($request('update:manual'), ...$signed('1.3.0'));
    cycle_assert($failed->state === UpdateCycleState::ManualRecoveryRequired && $failed->failedStep === 'health' && $w['fake']->isDown(), 'A failed rollback must leave the site in maintenance for a person.');
    cycle_assert(in_array('update.cycle.manual_recovery_required', $events($w['audit']), true), 'Manual recovery must reach the audit.');
    cycle_assert(cycle_reason(fn () => $w['cycle']->apply($request('update:after-manual'), ...$signed('1.3.0'))) === 'update_cycle_already_running', 'No cycle may start over an unrecovered one.');

    // Tampered archive and foreign signature: refused before anything is written to the site.
    $w = $world('tampered');
    [$bytes, $signature, $archive] = $signed('1.3.0');
    file_put_contents($archive, 'x', FILE_APPEND);
    $refused = $w['cycle']->apply($request('update:tampered'), $bytes, $signature, $archive);
    cycle_assert($refused->state === UpdateCycleState::Blocked && $refused->reasonCode === 'release_archive_size_differs', 'A changed archive must be refused.');
    [$bytes, $signature, $archive] = $signed('1.3.0', false, sodium_crypto_sign_secretkey($cycleForeign));
    $refused = $w['cycle']->apply($request('update:foreign'), $bytes, $signature, $archive);
    cycle_assert($refused->state === UpdateCycleState::Blocked && $refused->reasonCode === 'release_signature_mismatch', 'A foreign signature must be refused.');
    cycle_assert($releases($w['site']) === ['1.1.0', '1.2.0'] && !file_exists($w['site'] . '/releases/.1.3.0.partial') && $w['pointer']->current() === '1.2.0', 'Nothing may be unpacked.');
    cycle_assert(preg_grep('/\A(backup|maintenance|migrate)/', $w['fake']->journal) === [], 'No copy and no maintenance for a refused release.');

    // No verified copy: stop before the code.
    $w = $world('nobackup');
    $w['fake']->restoreVerified = false;
    $refused = $w['cycle']->apply($request('update:unverified'), ...$signed('1.3.0'));
    cycle_assert($refused->state === UpdateCycleState::Blocked && $refused->reasonCode === 'update_backup_unverified', 'A copy without a trial restore must stop the cycle.');
    $w['fake']->restoreVerified = true;
    $w['fake']->fail('backup', 'fixture_backup_failed');
    $refused = $w['cycle']->apply($request('update:nobackup'), ...$signed('1.3.0'));
    cycle_assert($refused->state === UpdateCycleState::Blocked && $refused->reasonCode === 'fixture_backup_failed' && $refused->nextStepIndex === 2, 'A failed copy must stop the cycle.');
    cycle_assert($releases($w['site']) === ['1.1.0', '1.2.0'] && preg_grep('/\Amaintenance/', $w['fake']->journal) === [], 'No code and no maintenance without a copy.');

    // A second cycle in parallel is refused; the same key in parallel runs nothing.
    $w = $world('parallel');
    $nested = [];
    $w['fake']->before['backup'] = static function () use (&$nested, $w, $request, $signed): void {
        $nested['other'] = cycle_reason(fn () => $w['cycle']->apply($request('update:parallel:other'), ...$signed('1.3.0')));
        $nested['same'] = $w['cycle']->apply($request('update:parallel'), ...$signed('1.3.0'))->state;
        $nested['lock'] = cycle_reason(fn () => ReleaseCycleLock::acquire($w['site']));
    };
    $parallel = $w['cycle']->apply($request('update:parallel'), ...$signed('1.3.0'));
    cycle_assert($parallel->state === UpdateCycleState::Completed && $nested['other'] === 'update_cycle_already_running', 'A parallel cycle must be refused.');
    cycle_assert($nested['same'] === UpdateCycleState::Planned && $nested['lock'] === 'update_cycle_already_running', 'The same key in parallel must return the running cycle.');
    cycle_assert(count(preg_grep('/\Abackup:/', $w['fake']->journal)) === 1, 'Only one cycle may run.');

    // backward_incompatible_migrations: rollback only through the database restore, with a warning.
    $w = $world('incompatible');
    $done = $w['cycle']->apply($request('update:incompatible'), ...$signed('1.3.0', true));
    cycle_assert($done->state === UpdateCycleState::Completed && $done->release?->backwardIncompatibleMigrations === true, 'The flag must be kept with the cycle.');
    cycle_assert(cycle_reason(fn () => $w['cycle']->rollback($done->cycleId, $done->revision, false)) === 'update_rollback_database_restore_required'
        && $w['pointer']->current() === '1.3.0', 'A code-only rollback must be refused without changes.');
    $back = $w['cycle']->rollback($done->cycleId, $done->revision);
    cycle_assert($back->snapshot->state === UpdateCycleState::RolledBack && $back->warnings === ['records_after_update_lost', 'backward_incompatible_migrations'], 'The incompatible rollback must warn.');

    // A compatible release may move only the code back; a failed manual rollback stays in maintenance.
    $w = $world('codeonly');
    $done = $w['cycle']->apply($request('update:codeonly'), ...$signed('1.3.0'));
    $back = $w['cycle']->rollback($done->cycleId, $done->revision, false);
    cycle_assert($back->warnings === [] && $w['pointer']->current() === '1.2.0' && $w['fake']->database['schema'] === '1.3.0', 'A code-only rollback must keep the database.');
    $w = $world('manualfail');
    $done = $w['cycle']->apply($request('update:manualfail'), ...$signed('1.3.0'));
    $w['fake']->fail('restore');
    $back = $w['cycle']->rollback($done->cycleId, $done->revision);
    cycle_assert($back->snapshot->state === UpdateCycleState::ManualRecoveryRequired && $back->snapshot->reasonCode === 'fixture_failed' && $w['fake']->isDown(), 'A failed manual rollback must need a person.');

    cycle_assert(cycle_reason(fn () => $w['cycle']->apply($request('update:steps', ['apply']), ...$signed('1.3.0'))) === 'update_cycle_plan_invalid', 'Only the release cycle steps are accepted.');
} finally {
    ReleaseDirectory::remove($cycleRoot);
}
