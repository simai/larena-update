<?php

declare(strict_types=1);

require_once __DIR__ . '/../Support/UpdateClientFakes.php';

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
use Larena\UpdateClient\Laravel\SiteUpdateClient;
use Larena\UpdateClient\Release\DatabaseRestore;
use Larena\UpdateClient\Release\RecordingUpdateSite;
use Larena\UpdateClient\Release\ReleaseSharedLinks;
use Larena\UpdateClient\Release\ReleaseUpdateCycle;
use Larena\UpdateClient\Release\SiteBackup;
use Larena\UpdateClient\Release\SiteBackupCopy;
use Larena\UpdateClient\Runtime\RecordingUpdateLifecycleAudit;
use Larena\UpdateClient\Release\ReleaseArchiveExtractor;
use Larena\UpdateClient\Release\ReleasePointer;
use Larena\UpdateClient\Release\ReleaseSignatureVerifier;
use Larena\UpdateClient\Runtime\PdoUpdateExecutionStore;
use Larena\UpdateClient\Source\ReleaseSource;
use Larena\UpdateClient\Tests\Support\FakeUpdateHttpClient;
use Larena\UpdateClient\Tests\Support\UpdateClientTestKit as Kit;

// What the update-cycle check on a real installed site (larena-workspace scripts/check-update-cycle.sh) found:
// a folder source for servers without network, the version of a release named by its Root commit, and the release
// keys the distribution ships in config/larena-release-keys.php.
(static function (): void {
    $pair = sodium_crypto_sign_keypair();
    $secret = sodium_crypto_sign_secretkey($pair);
    $public = base64_encode(sodium_crypto_sign_publickey($pair));
    $verifier = new ReleaseSignatureVerifier(['release.test' => $public]);
    $root = Kit::temp('findings');

    try {
        // 1. A folder source: the layout the release step writes, read without HTTP.
        $folder = $root . '/source';
        $downloads = $root . '/site/storage/app/larena/update/downloads';
        foreach (['1.3.0', '1.10.0', '1.4.0'] as $version) {
            [$bytes, $signature, $archive] = Kit::release($version, $secret);
            mkdir($folder . '/download/v' . $version, 0755, true);
            file_put_contents($folder . '/download/v' . $version . '/release.json', $bytes);
            file_put_contents($folder . '/download/v' . $version . '/release.json.sig', $signature);
            file_put_contents($folder . '/download/v' . $version . '/larena-' . $version . '.tar.gz', $archive);
        }
        mkdir($folder . '/download/not-a-tag');
        $http = new FakeUpdateHttpClient(static fn () => throw new RuntimeException('no HTTP for a folder source'));
        $source = new ReleaseSource($http, ['file://' . $folder . '/'], $downloads, $verifier);
        $offer = $source->latest('1.2.0');
        Kit::check($offer !== null && $offer->release->version === '1.10.0' && $offer->source === 'file://' . $folder, 'The newest release of a folder is its highest version, compared as versions.');
        Kit::check($source->latest('1.10.0') === null, 'The newest installed version is up to date.');
        $download = $source->download('1.4.0', '1.2.0');
        Kit::check($download->archivePath === $downloads . '/1.4.0/larena-1.4.0.tar.gz' && hash_file('sha256', $download->archivePath) === $download->release->archiveSha256, 'A folder archive must be copied into the download folder and checked.');
        Kit::check(!is_file($download->archivePath . '.part') && $http->requests === [], 'No HTTP request and no partial file for a folder source.');
        Kit::check(Kit::reason(static fn () => $source->download('2.0.0', '1.2.0')) === 'update_release_not_found', 'A version the folder does not hold is not found.');

        // A replaced archive in the folder is refused and nothing stays.
        file_put_contents($folder . '/download/v1.3.0/larena-1.3.0.tar.gz', strrev((string) file_get_contents($folder . '/download/v1.3.0/larena-1.3.0.tar.gz')));
        Kit::check(Kit::reason(static fn () => $source->download('1.3.0', '1.2.0')) === 'release_archive_checksum_differs' && !is_file($downloads . '/1.3.0/larena-1.3.0.tar.gz'), 'A replaced folder archive must be refused and removed.');
        // A larger archive than signed is cut at the signed size.
        file_put_contents($folder . '/download/v1.3.0/larena-1.3.0.tar.gz', str_repeat('x', 1 << 16));
        Kit::check(Kit::reason(static fn () => $source->download('1.3.0', '1.2.0')) === 'update_http_too_large' && !is_file($downloads . '/1.3.0/larena-1.3.0.tar.gz.part'), 'An archive over its signed size must be refused.');
        // Links are not followed.
        symlink($folder . '/download/v1.4.0/release.json', $root . '/linked.json');
        mkdir($folder . '/download/v1.5.0');
        symlink($root . '/linked.json', $folder . '/download/v1.5.0/release.json');
        Kit::check(Kit::reason(static fn () => $source->download('1.5.0', '1.2.0')) === 'update_download_failed', 'A linked description must be refused.');
        foreach (['file://relative/path', 'file:///a/../b', 'file:///', 'ftp://example.org/x'] as $bad) {
            Kit::check(Kit::reason(static fn () => new ReleaseSource($http, [$bad], $downloads, $verifier)) === 'update_source_invalid', 'Source ' . $bad . ' must be refused.');
        }

        // 2. The version a release runs: the marker of an unpacked release, the id when it is a version, else unknown.
        $site = $root . '/layout';
        mkdir($site . '/releases/ee49efc922b2', 0755, true);
        mkdir($site . '/releases/1.2.0', 0755, true);
        symlink('releases/ee49efc922b2', $site . '/current');
        $pointer = new ReleasePointer($site);
        Kit::check(Kit::reason(static fn () => $pointer->currentVersion()) === 'release_version_unknown', 'A release named by its commit without a marker has no known version.');
        file_put_contents($site . '/releases/ee49efc922b2/' . ReleaseArchiveExtractor::MARKER, '{"version":"1.0.0"}');
        Kit::check($pointer->current() === 'ee49efc922b2' && $pointer->currentVersion() === '1.0.0', 'The marker gives the version of an installer release.');
        file_put_contents($site . '/releases/ee49efc922b2/' . ReleaseArchiveExtractor::MARKER, '{"version":"latest"}');
        Kit::check(Kit::reason(static fn () => $pointer->currentVersion()) === 'release_version_unknown', 'A marker without a version is refused.');
        $pointer->switchTo('1.2.0');
        Kit::check($pointer->currentVersion() === '1.2.0', 'A release named by its version runs that version.');

        // 3. Release keys from the distribution join the configured ones; one id names one key.
        $config = SiteUpdateClient::withReleaseKeys(['trusted_keys' => ['site.extra' => 'AAAA']], ['release.test' => $public]);
        Kit::check($config['trusted_keys'] === ['site.extra' => 'AAAA', 'release.test' => $public], 'Distribution keys must be added to the configured ones.');
        Kit::check(SiteUpdateClient::withReleaseKeys(['trusted_keys' => []], null)['trusted_keys'] === [], 'No key file adds nothing.');
        Kit::check(Kit::reason(static fn () => SiteUpdateClient::withReleaseKeys(['trusted_keys' => ['release.test' => 'BBBB']], ['release.test' => $public])) === 'release_trusted_key_conflict', 'The same id with another key must be refused.');

        // 4. The cycle history survives a database restore: the copy holds the running cycle as `planned` and no
        //    later cycle, and the restored SQLite file is a new file the open connection does not see.
        $database = $root . '/site.sqlite';
        $row = static fn (string $id, string $key, int $revision, string $state): array => [$id, $key, $revision, $state, '{"state":"' . $state . '"}', '2026-11-18T00:00:0' . $revision . '+00:00'];
        $pdo = new PDO('sqlite:' . $database);
        $pdo->exec(PdoUpdateExecutionStore::schema('sqlite'));
        $insert = $pdo->prepare('INSERT INTO update_cycles (cycle_id, idempotency_key, revision, state, snapshot, updated_at) VALUES (?, ?, ?, ?, ?, ?)');
        $insert->execute($row(str_repeat('a', 32), 'update:a', 1, 'planned'));
        copy($database, $root . '/copy.sqlite');
        $pdo->exec("UPDATE update_cycles SET revision = 9, state = 'completed', snapshot = '{\"state\":\"completed\"}', updated_at = '2026-11-18T00:00:09+00:00'");
        $insert->execute($row(str_repeat('b', 32), 'update:b', 4, 'running'));
        $reconnects = 0;
        $store = new PdoUpdateExecutionStore($pdo, static function () use ($database, &$reconnects): PDO {
            $reconnects++;

            return new PDO('sqlite:' . $database);
        });
        $history = $store->history();
        $byId = array_column($history, null, 'cycle_id');
        Kit::check(count($history) === 2 && $byId[str_repeat('a', 32)]['state'] === 'completed' && $byId[str_repeat('a', 32)]['revision'] === 9, 'The history must be read as it is now.');
        copy($root . '/copy.sqlite', $database . '.restored');
        rename($database . '.restored', $database);
        $store->reinstate($history);
        $fresh = new PDO('sqlite:' . $database);
        $after = $fresh->query('SELECT cycle_id, revision, state FROM update_cycles ORDER BY cycle_id')->fetchAll(PDO::FETCH_NUM);
        Kit::check($reconnects === 1 && $after === [[str_repeat('a', 32), 9, 'completed'], [str_repeat('b', 32), 4, 'running']], 'After a restore every cycle must be back as it was, on the restored database.');
        Kit::check(Kit::reason(static fn () => $store->reinstate([['cycle_id' => 'x']])) === 'update_cycle_record_invalid'
            && count($fresh->query('SELECT * FROM update_cycles')->fetchAll()) === 2, 'A broken history must change nothing.');

        // 5. The same through the cycle: copies and restores replace the SQLite file of the site, the store
        //    reconnects, and a failed update and a manual rollback still end in their own states.
        $site = $root . '/cycle-site';
        mkdir($site . '/storage/app/public', 0755, true);
        file_put_contents($site . '/.env', "APP_KEY=test\n");
        foreach (['1.2.0', '1.3.0', '1.4.0'] as $version) {
            [$bytes, $signature, $archive] = Kit::release($version, $secret);
            file_put_contents($root . '/larena-' . $version . '.tar.gz', $archive);
            $signed[$version] = [$bytes, $signature, $root . '/larena-' . $version . '.tar.gz'];
        }
        (new ReleaseArchiveExtractor())->extract($verifier->verify($signed['1.2.0'][0], $signed['1.2.0'][1], '1.0.0'), $signed['1.2.0'][2], $site);
        ReleaseSharedLinks::link($site, '1.2.0');
        (new ReleasePointer($site))->switchTo('1.2.0');
        $file = $root . '/cycle.sqlite';
        (new PDO('sqlite:' . $file))->exec(PdoUpdateExecutionStore::schema('sqlite'));
        $store = new PdoUpdateExecutionStore(new PDO('sqlite:' . $file), static fn (): PDO => new PDO('sqlite:' . $file));
        $fake = new RecordingUpdateSite('1.2.0', ['page:1']);
        $copies = new class ($fake, $file) implements SiteBackup, DatabaseRestore {
            public function __construct(private RecordingUpdateSite $fake, private string $file)
            {
            }

            public function create(string $siteRoot, string $cycleId): SiteBackupCopy
            {
                $copy = $this->fake->create($siteRoot, $cycleId);
                copy($this->file, $this->file . '.' . $copy->ref);

                return $copy;
            }

            public function restoreDatabase(string $siteRoot, string $backupRef): void
            {
                $this->fake->restoreDatabase($siteRoot, $backupRef);
                copy($this->file . '.' . $backupRef, $this->file . '.next');
                rename($this->file . '.next', $this->file);
            }
        };
        $cycle = new ReleaseUpdateCycle($site, $verifier, new ReleaseArchiveExtractor(), $fake, $copies, $copies, $fake, $fake, $fake, [$fake], $store, new RecordingUpdateLifecycleAudit());
        $manifest = new UpdateManifest('release.manifest', 'stable', ['larena/core'], 'signature:release', true);
        $artifact = new ArtifactVerificationResult('release.archive', 'sha256:' . str_repeat('d', 64), 'signature:release', $manifest->manifestKey, ArtifactVerificationStatus::Verified, true, true);
        $request = static fn (string $key): UpdateCycleRequest => new UpdateCycleRequest(
            'user:1', 'correlation:' . $key, $key, 'update.plan.release', $manifest, new EntitlementSnapshot('site.entitlement', 'site', ['update.install'], true, true, true),
            new StaticCapability('update.install', 'larena/update-client'), $artifact,
            new ArrayLogicalFile('logical:' . $key, $key, new ArrayStorageBlobReference($artifact->checksum, 'fixture', 10, 'application/gzip'), FileVisibility::Internal, FileLifecycleClass::Temporary),
            ReleaseUpdateCycle::STEPS,
        );
        $done = $cycle->apply($request('update:one'), ...$signed['1.3.0']);
        Kit::check($done->state === UpdateCycleState::Completed, 'The first update must complete.');
        $fake->fail('migrate', 'fixture_migration_failed');
        $failed = $cycle->apply($request('update:two'), ...$signed['1.4.0']);
        $readBack = new PdoUpdateExecutionStore(new PDO('sqlite:' . $file));
        Kit::check($failed->state === UpdateCycleState::RecoverableFailed && $failed->failedStep === 'migrate', 'A failed migration after a restore must end recoverable, not on a stale store.');
        Kit::check($readBack->require($failed->cycleId)->state === UpdateCycleState::RecoverableFailed && $readBack->require($done->cycleId)->state === UpdateCycleState::Completed
            && $readBack->findUnfinished() === null, 'The restored database must hold both cycles in their real states and none unfinished.');
        $fake->recover('migrate');
        $back = $cycle->rollback($done->cycleId, $readBack->require($done->cycleId)->revision);
        Kit::check($back->snapshot->state === UpdateCycleState::RolledBack && (new ReleasePointer($site))->current() === '1.2.0', 'The manual rollback must finish after its own restore.');
        $readBack = new PdoUpdateExecutionStore(new PDO('sqlite:' . $file));
        Kit::check($readBack->require($done->cycleId)->state === UpdateCycleState::RolledBack && $readBack->require($failed->cycleId)->state === UpdateCycleState::RecoverableFailed,
            'After the manual rollback the history must still hold every cycle.');
    } finally {
        Kit::remove($root);
    }
})();
