<?php

declare(strict_types=1);

require_once __DIR__ . '/../Support/UpdateClientFakes.php';

use Larena\Filesystem\Enums\FileLifecycleClass;
use Larena\Filesystem\Enums\FileVisibility;
use Larena\Filesystem\Runtime\ArrayLogicalFile;
use Larena\Filesystem\Runtime\ArrayStorageBlobReference;
use Larena\Licensing\Runtime\StaticCapability;
use Larena\Licensing\Site\DevelopmentAccess;
use Larena\Licensing\Site\KeptSnapshot;
use Larena\Licensing\Site\SiteLicensing;
use Larena\Licensing\Site\SnapshotStore;
use Larena\Licensing\Site\SnapshotVerifier;
use Larena\Licensing\Site\TrustedKeys;
use Larena\UpdateClient\Contracts\ArtifactVerificationResult;
use Larena\UpdateClient\Contracts\EntitlementSnapshot;
use Larena\UpdateClient\Contracts\UpdateManifest;
use Larena\UpdateClient\Data\UpdateCycleRequest;
use Larena\UpdateClient\Enums\ArtifactVerificationStatus;
use Larena\UpdateClient\Enums\UpdateCycleState;
use Larena\UpdateClient\Packages\InstalledPackages;
use Larena\UpdateClient\Packages\PackageBatch;
use Larena\UpdateClient\Packages\PackageBatchCandidate;
use Larena\UpdateClient\Packages\PackageBatchPlanner;
use Larena\UpdateClient\Packages\PackageListState;
use Larena\UpdateClient\Packages\PackageListVerifier;
use Larena\UpdateClient\Packages\PackageSource;
use Larena\UpdateClient\Packages\PackageUpdateLicence;
use Larena\UpdateClient\Packages\SiteLicensingUpdateLicence;
use Larena\UpdateClient\Packages\VersionConstraint;
use Larena\UpdateClient\Release\RecordingReleaseActivation;
use Larena\UpdateClient\Release\RecordingUpdateSite;
use Larena\UpdateClient\Release\ReleaseArchiveExtractor;
use Larena\UpdateClient\Release\ReleasePointer;
use Larena\UpdateClient\Release\ReleaseSharedLinks;
use Larena\UpdateClient\Release\ReleaseSignatureVerifier;
use Larena\UpdateClient\Release\ReleaseUpdateCycle;
use Larena\UpdateClient\Runtime\InMemoryUpdateExecutionStore;
use Larena\UpdateClient\Runtime\RecordingUpdateLifecycleAudit;
use Larena\UpdateClient\Laravel\SiteUpdateClient;
use Larena\UpdateClient\Tests\Support\FakeArtisanRunner;
use Larena\UpdateClient\Tests\Support\FakeUpdateHttpClient;
use Larena\UpdateClient\Tests\Support\MemoryLogger;
use Larena\UpdateClient\Tests\Support\UpdateClientTestKit as Kit;

// A batch of package updates in one cycle (owner variant D, 2026-11-25): the signed package list, the site's own
// compatibility and licence checks, one copy, one new release folder, one switch, the same automatic rollback.
// Keys are throwaway pairs made here; nothing leaves the machine (the source is a fake HTTP client).
(static function (): void {
    /** A gzip tar of the given files (folders are made on the way). @param array<string,string> $files */
    $tar = static function (array $files): string {
        $entry = static function (string $name, string $type, string $data): string {
            $header = str_pad($name, 100, "\0") . sprintf('%07o', 0644) . "\0" . sprintf('%07o', 0) . "\0" . sprintf('%07o', 0) . "\0"
                . sprintf('%011o', strlen($data)) . "\0" . sprintf('%011o', 1763337600) . "\0" . '        ' . $type . str_repeat("\0", 100) . "ustar\0" . '00';
            $header = str_pad($header, 512, "\0");
            $header = substr_replace($header, sprintf('%06o', array_sum(array_map('ord', str_split($header)))) . "\0 ", 148, 8);

            return $header . ($data === '' ? '' : str_pad($data, (int) (ceil(strlen($data) / 512) * 512), "\0"));
        };
        $out = '';
        $dirs = [];
        foreach ($files as $path => $data) {
            $parts = explode('/', $path);
            array_pop($parts);
            $prefix = '';
            foreach ($parts as $part) {
                $prefix .= $part . '/';
                if (!isset($dirs[$prefix])) {
                    $dirs[$prefix] = true;
                    $out .= $entry($prefix, '5', '');
                }
            }
            $out .= $entry($path, '0', $data);
        }

        return (string) gzencode($out . str_repeat("\0", 1024));
    };

    $pair = sodium_crypto_sign_keypair();
    $secret = sodium_crypto_sign_secretkey($pair);
    $foreign = sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair());
    $verifier = new ReleaseSignatureVerifier(['release.test' => base64_encode(sodium_crypto_sign_publickey($pair))]);
    $sign = static fn (string $bytes, ?string $key = null): string => (string) json_encode(['key_id' => 'release.test', 'signature' => base64_encode(sodium_crypto_sign_detached($bytes, $key ?? $secret))]);
    $root = Kit::temp('package-batch');
    $now = new DateTimeImmutable('2026-11-25T12:00:00+00:00');
    $clock = static fn (): DateTimeImmutable => $now;

    $composer = static fn (string $package, string $namespace, array $extra = []): string => (string) json_encode(
        ['name' => $package, 'autoload' => ['psr-4' => [$namespace . '\\' => 'src/']]] + $extra, JSON_UNESCAPED_SLASHES);
    $installedEntry = static fn (string $package, string $version, string $namespace): array => ['name' => $package, 'version' => $version, 'autoload' => ['psr-4' => [$namespace . '\\' => 'src/']]];

    // The set release 1.3.0 the site was installed from: core, storage and the paid telephony package, built with an
    // authoritative class map (as the distribution is).
    $setFiles = [
        'artisan' => "<?php // 1.3.0\n",
        'public/index.php' => "<?php echo 'site';\n",
        'vendor/composer/installed.json' => (string) json_encode(['packages' => [
            $installedEntry('larena/core', '1.3.0', 'Larena\\Core'),
            $installedEntry('larena/storage', '1.2.0', 'Larena\\Storage'),
            $installedEntry('larena/telephony', '1.0.0', 'Larena\\Telephony'),
            $installedEntry('larena/legacy', 'dev-main', 'Larena\\Legacy'),
        ]], JSON_UNESCAPED_SLASHES),
        'vendor/composer/autoload_classmap.php' => "<?php\n\$vendorDir = dirname(__DIR__);\nreturn array(\n"
            . "    'Larena\\\\Core\\\\Core' => \$vendorDir . '/larena/core/src/Core.php',\n"
            . "    'Larena\\\\Storage\\\\Storage' => \$vendorDir . '/larena/storage/src/Storage.php',\n"
            . "    'Larena\\\\Telephony\\\\Telephony' => \$vendorDir . '/larena/telephony/src/Telephony.php',\n);\n",
        'vendor/composer/autoload_real.php' => "<?php\n// \$loader->setClassMapAuthoritative(true);\n",
        'vendor/larena/core/composer.json' => $composer('larena/core', 'Larena\\Core'),
        'vendor/larena/core/src/Core.php' => "<?php // core 1.3.0\n",
        'vendor/larena/storage/composer.json' => $composer('larena/storage', 'Larena\\Storage'),
        'vendor/larena/storage/src/Storage.php' => "<?php // storage 1.2.0\n",
        'vendor/larena/telephony/composer.json' => $composer('larena/telephony', 'Larena\\Telephony'),
        'vendor/larena/telephony/src/Telephony.php' => "<?php // telephony 1.0.0\n",
        'vendor/larena/legacy/composer.json' => $composer('larena/legacy', 'Larena\\Legacy'),
    ];

    /** A package archive and its signed release. @return array{release:string, signature:string, archive:string} */
    $package = static function (string $name, string $version, array $overrides = [], ?array $files = null, ?string $key = null) use ($tar, $composer, $sign): array {
        $namespace = 'Larena\\' . ucfirst(explode('/', $name)[1]);
        $class = ucfirst(explode('/', $name)[1]);
        $archive = $tar($files ?? ['composer.json' => $composer($name, $namespace), 'src/' . $class . '.php' => "<?php // {$name} {$version}\n"]);
        $release = (string) json_encode($overrides + [
            'schema' => 'larena.package-release', 'schema_version' => 1, 'product' => 'larena', 'package' => $name, 'version' => $version,
            'released_at' => '2026-11-20T10:00:00+00:00', 'distribution' => 'open',
            'archive' => ['file' => str_replace('/', '-', $name) . '-' . $version . '.tar.gz', 'sha256' => hash('sha256', $archive), 'bytes' => strlen($archive)],
            'requires' => ['larena' => '>=1.3 <2.0', 'packages' => ['larena/core' => '^1.3']],
            'backward_incompatible_migrations' => false,
        ], JSON_UNESCAPED_SLASHES);

        return ['release' => $release, 'signature' => $sign($release, $key), 'archive' => $archive];
    };
    /** A signed list of the given packages. @return array{0:string,1:string} */
    $list = static function (array $packages, int $version = 5, string $expires = '2026-12-02T12:00:00+00:00', ?string $key = null) use ($sign): array {
        $bytes = (string) json_encode([
            'schema' => 'larena.package-list', 'schema_version' => 1, 'product' => 'larena', 'channel' => 'stable',
            'list_version' => $version, 'issued_at' => '2026-11-25T11:00:00+00:00', 'expires_at' => $expires,
            'packages' => array_map(static fn (array $p): array => ['release' => $p['release'], 'signature' => json_decode($p['signature'], true)], $packages),
        ], JSON_UNESCAPED_SLASHES);

        return [$bytes, $sign($bytes, $key)];
    };

    // The licence: a real larena/licensing answer over a throwaway snapshot with telephony updates until 1 June 2026.
    $licencePair = sodium_crypto_sign_keypair();
    $entitlement = (string) json_encode([
        'schema' => 'larena.entitlement', 'schema_version' => 1, 'snapshot_key' => 'snap.test', 'site' => 'site.test', 'product' => 'larena',
        'status' => 'active', 'capabilities' => ['telephony.base'],
        'issued_at' => (new DateTimeImmutable('-1 day'))->format(DATE_ATOM), 'valid_until' => (new DateTimeImmutable('+300 days'))->format(DATE_ATOM),
        'updates_until' => ['larena/telephony' => '2026-06-01T00:00:00+00:00'],
    ], JSON_UNESCAPED_SLASHES);
    $envelope = ['entitlement' => $entitlement, 'signature' => (string) json_encode(['key_id' => 'licence.test',
        'signature' => base64_encode(sodium_crypto_sign_detached($entitlement, sodium_crypto_sign_secretkey($licencePair)))])];
    $store = new class ($envelope) implements SnapshotStore {
        public function __construct(private array $envelope)
        {
        }

        public function load(): ?KeptSnapshot
        {
            return new KeptSnapshot($this->envelope, SnapshotStore::REFRESH, new DateTimeImmutable('-1 day'), new DateTimeImmutable('-1 day'));
        }

        public function keep(array $envelope, string $origin, DateTimeImmutable $now): void
        {
        }

        public function seen(DateTimeImmutable $now): void
        {
        }
    };
    $production = new class () implements DevelopmentAccess {
        public function reason(string $siteHost): ?string
        {
            return null;
        }
    };
    $licence = new SiteLicensingUpdateLicence(new SiteLicensing(
        new SnapshotVerifier(TrustedKeys::from([['licence.test' => base64_encode(sodium_crypto_sign_publickey($licencePair))]]), 'larena'),
        $store, $production, 'shop.example.com',
    ));

    $entitlementSnapshot = new EntitlementSnapshot('site.entitlement', 'site-fixture', ['update.install'], true, true, true);
    $manifest = new UpdateManifest('packages.list_5', 'stable', ['larena/storage'], 'signature:list', true);
    $artifact = new ArtifactVerificationResult('packages.batch', 'sha256:' . str_repeat('e', 64), 'signature:list', $manifest->manifestKey, ArtifactVerificationStatus::Verified, true, true);
    $request = static fn (string $key): UpdateCycleRequest => new UpdateCycleRequest(
        'user:1', 'correlation:packages', $key, 'update.plan.package_batch', $manifest, $entitlementSnapshot,
        new StaticCapability('update.install', 'larena/update'), $artifact,
        new ArrayLogicalFile('logical:packages', 'packages', new ArrayStorageBlobReference($artifact->checksum, 'fixture', 10, 'application/gzip'), FileVisibility::Internal, FileLifecycleClass::Temporary),
        ReleaseUpdateCycle::STEPS,
    );

    /** A site on the set release 1.3.0, its fake outside world, a store and an audit. */
    $world = static function (string $name) use ($root, $verifier, $secret, $tar, $setFiles): array {
        $site = $root . '/' . $name;
        mkdir($site . '/storage/app/public', 0755, true);
        file_put_contents($site . '/.env', "APP_KEY=test\n");
        $archive = $tar($setFiles);
        [$bytes, $signature] = Kit::release('1.3.0', $secret, archive: $archive);
        file_put_contents($root . '/' . $name . '-set.tar.gz', $archive);
        (new ReleaseArchiveExtractor())->extract($verifier->verify($bytes, $signature, '1.0.0'), $root . '/' . $name . '-set.tar.gz', $site, null, $bytes, $signature);
        ReleaseSharedLinks::link($site, '1.3.0');
        (new ReleasePointer($site))->switchTo('1.3.0');
        $fake = new RecordingUpdateSite('1.3.0', ['page:1']);
        $store = new InMemoryUpdateExecutionStore();
        $audit = new RecordingUpdateLifecycleAudit();
        $cycle = new ReleaseUpdateCycle($site, $verifier, new ReleaseArchiveExtractor(), $fake, $fake, $fake, $fake, $fake, $fake, [$fake, new RecordingReleaseActivation()], $store, $audit);

        return ['site' => $site, 'fake' => $fake, 'store' => $store, 'audit' => $audit, 'cycle' => $cycle, 'pointer' => new ReleasePointer($site)];
    };
    /** Archives on disk for a batch. @param array<string,array{archive:string}> $packages */
    $batch = static function (string $name, array $listFiles, array $packages) use ($root): PackageBatch {
        $archives = [];
        $selection = [];
        foreach ($packages as $key => $p) {
            [$package, $version] = explode('@', $key);
            $path = $root . '/' . $name . '-' . str_replace('/', '-', $package) . '.tar.gz';
            file_put_contents($path, $p['archive']);
            $archives[$package] = $path;
            $selection[$package] = $version;
        }

        return new PackageBatch($listFiles[0], $listFiles[1], $selection, $archives);
    };
    $candidate = static fn (PackageBatch $batch, ?PackageUpdateLicence $with = null): PackageBatchCandidate
        => new PackageBatchCandidate($verifier, new ReleaseArchiveExtractor(), $with ?? $licence, $batch, 'stable', $clock);
    $events = static fn (RecordingUpdateLifecycleAudit $audit): array => array_column($audit->events(), 'event');

    try {
        Kit::check(VersionConstraint::satisfies('1.3.0', '>=1.3 <2.0') && !VersionConstraint::satisfies('2.0.0', '^1.3')
            && !VersionConstraint::satisfies('dev-main', '*') && !VersionConstraint::valid('dev-main'), 'version rules');

        $storage = $package('larena/storage', '1.3.0');
        $telephonyOld = $package('larena/telephony', '1.1.0', ['distribution' => 'closed', 'released_at' => '2026-05-01T10:00:00+00:00']);
        $telephonyNew = $package('larena/telephony', '1.2.0', ['distribution' => 'closed', 'released_at' => '2026-08-01T10:00:00+00:00']);
        $signedList = $list([$storage, $telephonyOld, $telephonyNew]);

        // The plan: storage 1.3.0, the paid telephony only up to its «updates until» (1.1.0), 1.2.0 held.
        $w = $world('plan');
        $listed = (new PackageListVerifier($verifier))->verify($signedList[0], $signedList[1], 'stable', $now);
        $installed = InstalledPackages::read($w['site'] . '/releases/1.3.0', '1.3.0');
        $plan = (new PackageBatchPlanner($licence))->plan($listed, $installed);
        Kit::check(!$plan->refused() && $plan->selection() === ['larena/storage' => '1.3.0', 'larena/telephony' => '1.1.0'], 'the plan takes storage and the covered telephony release');
        Kit::check(count($plan->held) === 1 && $plan->held[0]['package'] === 'larena/telephony' && $plan->held[0]['version'] === '1.2.0'
            && $plan->held[0]['reason'] === 'update_batch_licence_ended' && str_starts_with($plan->held[0]['requirement'], 'updates_period_ended'), 'the paid release past updates_until is held');
        Kit::check((new PackageBatchPlanner($licence))->plan($listed, $installed, ['larena/legacy'])->refusals === [], 'a dev-main package with no release in the list is left alone');
        $legacyList = (new PackageListVerifier($verifier))->verify(...[...$list([$package('larena/legacy', '1.0.0')]), 'stable', $now]);
        Kit::check((new PackageBatchPlanner($licence))->plan($legacyList, $installed)->refusals[0]['requirement'] === 'installed version dev-main is not a release number', 'a dev-main package is never guessed');
        Kit::check((new PackageBatchPlanner($licence))->plan($listed, $installed, ['larena/absent'])->refusals[0]['requirement'] === 'not installed and not in the package list', 'a package neither installed nor listed is refused');

        // 1. A batch of two packages installs in one cycle: one copy, one switch, one journal.
        $w = $world('two');
        $done = $w['cycle']->applyCandidate($request('packages:two'), $candidate($batch('two', $signedList, ['larena/storage@1.3.0' => $storage, 'larena/telephony@1.1.0' => $telephonyOld])));
        $new = (string) $w['pointer']->current();
        Kit::check($done->state === UpdateCycleState::Completed && $done->completedSteps === ReleaseUpdateCycle::STEPS, 'the batch completes every step: ' . $done->reasonCode);
        Kit::check(preg_match('/\A1\.3\.0-p5-[a-f0-9]{8}\z/D', $new) === 1 && $done->release?->from === '1.3.0' && $done->release->to === $new, 'one new release folder');
        Kit::check(count(preg_grep('/\Abackup:/', $w['fake']->journal)) === 1 && count(preg_grep('/\Amaintenance\.enter:/', $w['fake']->journal)) === 1
            && count(preg_grep('/\Aactivate:/', $w['fake']->journal)) === 1, 'one copy, one maintenance window, one switch');
        Kit::check(count(array_keys($events($w['audit']), 'update.cycle.requested')) === 1 && count(array_keys($events($w['audit']), 'update.cycle.completed')) === 1
            && count(array_unique(array_column($w['audit']->events(), 'cycle_id'))) === 1, 'the journal shows one cycle');
        $completed = array_values(array_filter($w['audit']->events(), static fn (array $e): bool => $e['event'] === 'update.cycle.completed'))[0];
        Kit::check(($completed['facts']['kind'] ?? null) === 'package_batch' && ($completed['facts']['packages'] ?? null) === 'larena/storage@1.3.0,larena/telephony@1.1.0', 'the journal names the batch');
        $release = $w['site'] . '/releases/' . $new;
        Kit::check(file_get_contents($release . '/vendor/larena/storage/src/Storage.php') === "<?php // larena/storage 1.3.0\n"
            && file_get_contents($release . '/vendor/larena/telephony/src/Telephony.php') === "<?php // larena/telephony 1.1.0\n"
            && file_get_contents($release . '/vendor/larena/core/src/Core.php') === "<?php // core 1.3.0\n", 'the batch replaces exactly its package folders');
        Kit::check(file_get_contents($w['site'] . '/releases/1.3.0/vendor/larena/storage/src/Storage.php') === "<?php // storage 1.2.0\n", 'the previous release is untouched');
        Kit::check(file_get_contents($release . '/.larena-signed/release.json') === file_get_contents($w['site'] . '/releases/1.3.0/.larena-signed/release.json')
            && readlink($release . '/storage') === '../../storage' && readlink($release . '/.env') === '../../.env', 'shared links, the set\'s signed folder carried for the package layer');
        Kit::check(is_file($release . '/.larena-packages/larena/storage/larena-storage-1.3.0.tar.gz') && is_file($release . '/.larena-packages/larena/telephony/larena-telephony-1.1.0.tar.gz'),
            'the release keeps the archive of every package the batch installed');
        $after = InstalledPackages::read($release, (string) $w['pointer']->currentVersion());
        Kit::check($after->productVersion === '1.3.0' && $after->versions['larena/storage'] === '1.3.0' && $after->versions['larena/telephony'] === '1.1.0'
            && $after->keptListVersion === 5 && PackageListState::forSite($w['site'])->seen() === 5, 'the release keeps what the batch installed');
        // The rollback of a batch is one pointer switch (and the database from the copy).
        $back = $w['cycle']->rollback($done->cycleId, $done->revision);
        Kit::check($back->snapshot->state === UpdateCycleState::RolledBack && $w['pointer']->current() === '1.3.0', 'a batch rolls back as one release');
        // A replayed older list is refused after a newer one was seen.
        $older = $list([$storage], 4);
        $refused = $w['cycle']->applyCandidate($request('packages:replay'), $candidate($batch('replay', $older, ['larena/storage@1.3.0' => $storage])));
        Kit::check($refused->state === UpdateCycleState::Blocked && $refused->reasonCode === 'update_batch_list_stale', 'a replayed older list is stale');

        // 2. A failing check after the switch rolls the whole batch back: code first, then the database.
        $w = $world('health');
        $w['fake']->fail('health');
        $failed = $w['cycle']->applyCandidate($request('packages:health'), $candidate($batch('health', $signedList, ['larena/storage@1.3.0' => $storage, 'larena/telephony@1.1.0' => $telephonyOld])));
        Kit::check($failed->state === UpdateCycleState::RecoverableFailed && $failed->failedStep === 'health', 'a failed health check ends recoverable');
        Kit::check($w['pointer']->current() === '1.3.0' && $w['fake']->database['schema'] === '1.3.0' && !$w['fake']->isDown(), 'code and database are back');
        $journal = $w['fake']->journal;
        $activate = array_keys(preg_grep('/\Aactivate:1\.3\.0\z/', $journal));
        $restore = array_keys(preg_grep('/\Arestore:/', $journal));
        Kit::check($activate !== [] && $restore !== [] && end($activate) < $restore[0], 'the code goes back before the database');
        Kit::check(file_get_contents($w['site'] . '/current/vendor/larena/storage/src/Storage.php') === "<?php // storage 1.2.0\n"
            && file_get_contents($w['site'] . '/current/vendor/larena/telephony/src/Telephony.php') === "<?php // telephony 1.0.0\n", 'no package of the batch stays');

        // 3. An incompatible package is refused before the copy, naming the package and the rule.
        $w = $world('incompatible');
        $needsNewCore = $package('larena/storage', '1.3.0', ['requires' => ['larena' => '>=1.4', 'packages' => []]]);
        $refused = $w['cycle']->applyCandidate($request('packages:incompatible'), $candidate($batch('incompatible', $list([$needsNewCore]), ['larena/storage@1.3.0' => $needsNewCore])));
        Kit::check($refused->state === UpdateCycleState::Blocked && $refused->reasonCode === 'update_batch_incompatible', 'an incompatible batch is refused');
        Kit::check(preg_grep('/\A(backup|maintenance)/', $w['fake']->journal) === [] && $w['pointer']->current() === '1.3.0', 'before the copy, nothing touched');
        $blocked = array_values(array_filter($w['audit']->events(), static fn (array $e): bool => $e['event'] === 'update.cycle.blocked'))[0];
        Kit::check(($blocked['facts']['package'] ?? null) === 'larena/storage' && ($blocked['facts']['requirement'] ?? null) === 'larena >=1.4 (the site runs 1.3.0)', 'the journal names the package and the rule');
        $needsCore2 = $package('larena/storage', '1.3.0', ['requires' => ['larena' => '^1.3', 'packages' => ['larena/core' => '^2.0']]]);
        $refusals = (new PackageBatchPlanner($licence))->check([(new PackageListVerifier($verifier))->verify(...[...$list([$needsCore2]), 'stable', $now])->releases[0]], $installed);
        Kit::check($refusals === [['reason' => 'update_batch_incompatible', 'package' => 'larena/storage', 'requirement' => 'larena/core ^2.0 (would be 1.3.0)']], 'a package rule is named');
        $newAutoload = $package('larena/storage', '1.3.0', [], ['composer.json' => $composer('larena/storage', 'Larena\\Store'), 'src/Storage.php' => '<?php']);
        $refused = $w['cycle']->applyCandidate($request('packages:autoload'), $candidate($batch('autoload', $list([$newAutoload]), ['larena/storage@1.3.0' => $newAutoload])));
        Kit::check($refused->reasonCode === 'update_batch_incompatible' && preg_grep('/\Abackup/', $w['fake']->journal) === [], 'a changed autoload needs a set release');
        $newClass = $package('larena/storage', '1.3.0', [], ['composer.json' => $composer('larena/storage', 'Larena\\Storage'), 'src/Storage.php' => '<?php', 'src/Extra.php' => '<?php']);
        $refused = $w['cycle']->applyCandidate($request('packages:class'), $candidate($batch('class', $list([$newClass]), ['larena/storage@1.3.0' => $newClass])));
        Kit::check($refused->reasonCode === 'update_batch_incompatible', 'a class the authoritative class map does not have is refused');

        // 4. A paid package past its «updates until» is not installed, even when asked for.
        $w = $world('licence');
        $refused = $w['cycle']->applyCandidate($request('packages:licence'), $candidate($batch('licence', $signedList, ['larena/telephony@1.2.0' => $telephonyNew])));
        Kit::check($refused->state === UpdateCycleState::Blocked && $refused->reasonCode === 'update_batch_licence_ended'
            && preg_grep('/\Abackup/', $w['fake']->journal) === [], 'the paid release past updates_until is refused before the copy');
        Kit::check(file_get_contents($w['site'] . '/current/vendor/larena/telephony/src/Telephony.php') === "<?php // telephony 1.0.0\n", 'the installed paid version keeps working');
        $noLicensing = new SiteLicensingUpdateLicence(null);
        $refused = $w['cycle']->applyCandidate($request('packages:nolicensing'), $candidate($batch('nolicensing', $signedList, ['larena/telephony@1.1.0' => $telephonyOld]), $noLicensing));
        Kit::check($refused->reasonCode === 'update_batch_licence_ended', 'without larena/licensing no paid update is taken');

        // 5. A tampered archive, a foreign signature and a stale list are refused before the copy.
        $w = $world('tampered');
        $tampered = $batch('tampered', $signedList, ['larena/storage@1.3.0' => $storage]);
        file_put_contents($tampered->archives['larena/storage'], 'x', FILE_APPEND);
        $refused = $w['cycle']->applyCandidate($request('packages:tampered'), $candidate($tampered));
        Kit::check($refused->state === UpdateCycleState::Blocked && $refused->reasonCode === 'update_batch_signature_invalid', 'a tampered archive is refused');
        $forged = $package('larena/storage', '1.3.0', [], null, $foreign);
        $refused = $w['cycle']->applyCandidate($request('packages:forged'), $candidate($batch('forged', $list([$forged]), ['larena/storage@1.3.0' => $forged])));
        Kit::check($refused->reasonCode === 'update_batch_signature_invalid', 'a package release signed by an unknown key is refused');
        $refused = $w['cycle']->applyCandidate($request('packages:foreignlist'), $candidate($batch('foreignlist', $list([$storage], 5, key: $foreign), ['larena/storage@1.3.0' => $storage])));
        Kit::check($refused->reasonCode === 'update_batch_signature_invalid', 'a list signed by an unknown key is refused');
        $refused = $w['cycle']->applyCandidate($request('packages:stale'), $candidate($batch('stale', $list([$storage], 5, '2026-11-25T11:30:00+00:00'), ['larena/storage@1.3.0' => $storage])));
        Kit::check($refused->reasonCode === 'update_batch_list_stale', 'an expired list is stale');
        Kit::check(preg_grep('/\A(backup|maintenance)/', $w['fake']->journal) === [] && $w['pointer']->current() === '1.3.0'
            && array_values(array_filter(scandir($w['site'] . '/releases'), static fn (string $n): bool => $n[0] !== '.')) === ['1.3.0'], 'nothing was written for a refused batch');

        // The source: the signed list and archives under `<source>/packages/`, the site key, a closed archive refused.
        $source = 'https://updates.example.test/r/larena/stable';
        $http = FakeUpdateHttpClient::serving([
            $source . '/packages/packages.json' => [200, $signedList[0]],
            $source . '/packages/packages.json.sig' => [200, $signedList[1]],
            $source . '/packages/download/larena/storage/v1.3.0/larena-storage-1.3.0.tar.gz' => [200, $storage['archive']],
            $source . '/packages/download/larena/telephony/v1.2.0/larena-telephony-1.2.0.tar.gz' => [403, '{"error":"license_expired"}'],
        ]);
        $packages = new PackageSource($http, ['https://github.com/simai/larena/releases', $source], $root . '/downloads', siteKey: 'site_key_for_tests_0001');
        [$bytes, $signature, $from] = $packages->list();
        $fetched = (new PackageListVerifier($verifier))->verify($bytes, $signature, 'stable', $now);
        $path = $packages->download((array_values(array_filter($fetched->releases, static fn ($r): bool => $r->package === 'larena/storage')))[0], $from);
        Kit::check($from === $source && hash_file('sha256', $path) === hash('sha256', $storage['archive']), 'the archive comes from the source and matches');
        Kit::check(($http->requests[0]->headers['Authorization'] ?? null) === 'Bearer site_key_for_tests_0001'
            && preg_grep('/github\.com/', $http->urls()) === [], 'the site key goes to the update server, never to GitHub');
        Kit::check(Kit::reason(fn () => $packages->download($fetched->find('larena/telephony', '1.2.0'), $from)) === 'update_license_expired', 'a refused closed archive names the licence');
        Kit::check(Kit::reason(fn () => (new PackageSource($http, ['https://github.com/simai/larena/releases'], $root . '/d2'))->list()) === 'update_batch_source_missing', 'GitHub has no package list');

        // The Laravel composition: the same sources, the request a batch cycle runs under, no licensing → no paid update.
        $w = $world('client');
        $client = new SiteUpdateClient(
            ['trusted_keys' => ['release.test' => base64_encode(sodium_crypto_sign_publickey($pair))], 'sources' => [$source], 'site_key' => 'site_key_for_tests_0001'],
            $w['site'] . '/releases/1.3.0', 'https://site.example.test', static fn (): PDO => new PDO('sqlite::memory:'), new MemoryLogger(), $http,
            new FakeArtisanRunner(static fn (): string => ''),
        );
        [$bytes, $signature] = $client->packageSource()->list();
        $clientList = $client->packageListVerifier()->verify($bytes, $signature, $client->channel(), $now, $client->packageListState()->seen());
        $clientPlan = (new PackageBatchPlanner($client->packageLicence()))->plan($clientList, InstalledPackages::read($w['site'] . '/releases/1.3.0', $client->installedVersion()));
        Kit::check($clientPlan->selection() === ['larena/storage' => '1.3.0'] && $clientPlan->held[0]['requirement'] === 'licensing_unavailable (1.2.0 released 2026-08-01)', 'without larena/licensing only open packages go');
        $clientRequest = $client->batchRequest($clientPlan, 'user:1', 'packages:client');
        Kit::check($clientRequest->steps === ReleaseUpdateCycle::STEPS && $clientRequest->manifest->packageRefs === ['larena/storage'] && $clientRequest->manifest->isVerified(), 'the batch runs the release cycle steps');
    } finally {
        Kit::remove($root);
    }
    echo "PackageBatchCycleTest passed.\n";
})();
