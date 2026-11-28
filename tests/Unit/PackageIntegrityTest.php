<?php

declare(strict_types=1);

require_once __DIR__ . '/../Support/IntegritySiteKit.php';

use Composer\Autoload\ClassLoader;
use Larena\Filesystem\Enums\FileLifecycleClass;
use Larena\Filesystem\Enums\FileVisibility;
use Larena\Filesystem\Runtime\ArrayLogicalFile;
use Larena\Filesystem\Runtime\ArrayStorageBlobReference;
use Larena\Licensing\Catalog\CapabilityCatalog;
use Larena\Licensing\Catalog\CatalogCapability;
use Larena\Licensing\Runtime\StaticCapability;
use Larena\Licensing\Site\FileSnapshotStore;
use Larena\Licensing\Site\PlanDowngrades;
use Larena\Licensing\Site\RemovedAddons;
use Larena\Licensing\Site\SiteDataAccess;
use Larena\Licensing\Site\SiteLicensing;
use Larena\Licensing\Site\SiteUsage;
use Larena\Licensing\Site\SnapshotVerifier;
use Larena\Licensing\Site\TestAddresses;
use Larena\Licensing\Site\TrustedKeys;
use Larena\UpdateClient\Contracts\ArtifactVerificationResult;
use Larena\UpdateClient\Contracts\EntitlementSnapshot;
use Larena\UpdateClient\Contracts\UpdateManifest;
use Larena\UpdateClient\Data\UpdateCycleRequest;
use Larena\UpdateClient\Enums\ArtifactVerificationStatus;
use Larena\UpdateClient\Enums\UpdateCycleState;
use Larena\UpdateClient\Integrity\CoreFileGuard;
use Larena\UpdateClient\Integrity\FindingStore;
use Larena\UpdateClient\Integrity\IntegrityLayout;
use Larena\UpdateClient\Integrity\IntegrityRestore;
use Larena\UpdateClient\Integrity\IntegrityScanner;
use Larena\UpdateClient\Integrity\RecordingIntegrityHistory;
use Larena\UpdateClient\Integrity\SignedBaseline;
use Larena\UpdateClient\Packages\InstalledPackages;
use Larena\UpdateClient\Packages\PackageBatch;
use Larena\UpdateClient\Packages\PackageBatchCandidate;
use Larena\UpdateClient\Packages\PackageBatchPlanner;
use Larena\UpdateClient\Packages\PackageListVerifier;
use Larena\UpdateClient\Packages\PackageRemovalCandidate;
use Larena\UpdateClient\Packages\PackageUpdateLicence;
use Larena\UpdateClient\Release\RecordingReleaseActivation;
use Larena\UpdateClient\Release\RecordingUpdateSite;
use Larena\UpdateClient\Release\ReleaseArchiveExtractor;
use Larena\UpdateClient\Release\ReleasePointer;
use Larena\UpdateClient\Release\ReleaseSharedLinks;
use Larena\UpdateClient\Release\ReleaseSignatureVerifier;
use Larena\UpdateClient\Release\ReleaseUpdateCycle;
use Larena\UpdateClient\Runtime\InMemoryUpdateExecutionStore;
use Larena\UpdateClient\Runtime\RecordingUpdateLifecycleAudit;
use Larena\UpdateClient\Tests\Support\IntegritySiteKit as Site;
use Larena\UpdateClient\Tests\Support\UpdateClientTestKit as Kit;

// The follow-ups to package batches (2026-11-26): the file checks lay each installed package release over the set
// list, «Вернуть как было» takes a package file from that package's archive, and a package the site does not have
// yet (a bought addon) installs through a batch, its classes added to the generated autoloader without Composer.
// Keys are throwaway pairs made here; nothing leaves the machine.
(static function (): void {
    $pair = sodium_crypto_sign_keypair();
    $secret = sodium_crypto_sign_secretkey($pair);
    $public = base64_encode(sodium_crypto_sign_publickey($pair));
    $keys = ['release.test' => $public];
    $verifier = new ReleaseSignatureVerifier($keys);
    $sign = static fn (string $bytes): string => (string) json_encode(['key_id' => 'release.test', 'signature' => base64_encode(sodium_crypto_sign_detached($bytes, $secret))]);
    $root = Kit::temp('package-integrity');
    $now = new DateTimeImmutable('2026-11-26T12:00:00+00:00');
    $clock = static fn (): DateTimeImmutable => $now;

    $composer = static fn (string $package, string $namespace, array $extra = []): string => (string) json_encode(
        ['name' => $package, 'type' => 'library', 'autoload' => ['psr-4' => [$namespace . '\\' => 'src/']]] + $extra, JSON_UNESCAPED_SLASHES);
    $installed = static fn (string $package, string $version, string $namespace): array => ['name' => $package, 'version' => $version,
        'type' => 'library', 'autoload' => ['psr-4' => [$namespace . '\\' => 'src/']], 'install-path' => '../' . $package];
    $versionEntry = static fn (string $package, string $version): string => "        '{$package}' => array(\n"
        . "            'pretty_version' => '{$version}',\n            'version' => '{$version}.0',\n            'reference' => null,\n"
        . "            'type' => 'library',\n            'install_path' => __DIR__ . '/../{$package}',\n            'aliases' => array(),\n"
        . "            'dev_requirement' => false,\n        ),\n";

    // The set release 1.3.0 as the distribution builds it: Composer's generated files with an authoritative class map.
    $setFiles = [
        'artisan' => "<?php // 1.3.0\n",
        'bootstrap/app.php' => "<?php return 'app';\n",
        'config/app.php' => "<?php return ['name' => 'Larena'];\n",
        'public/index.php' => "<?php echo 'site';\n",
        'vendor/autoload.php' => "<?php // autoload\n",
        'vendor/composer/autoload_classmap.php' => "<?php\n\n// autoload_classmap.php @generated by Composer\n\n\$vendorDir = dirname(__DIR__);\n\$baseDir = dirname(\$vendorDir);\n\nreturn array(\n"
            . "    'Larena\\\\Core\\\\Core' => \$vendorDir . '/larena/core/src/Core.php',\n"
            . "    'Larena\\\\Storage\\\\Storage' => \$vendorDir . '/larena/storage/src/Storage.php',\n);\n",
        'vendor/composer/autoload_real.php' => "<?php\n// \$loader->setClassMapAuthoritative(true);\n",
        'vendor/composer/autoload_static.php' => "<?php\n\n// autoload_static.php @generated by Composer\n\nnamespace Composer\\Autoload;\n\nclass ComposerStaticInitPackageIntegrityTest\n{\n"
            . "    public static \$classMap = array (\n"
            . "        'Larena\\\\Core\\\\Core' => __DIR__ . '/..' . '/larena/core/src/Core.php',\n"
            . "        'Larena\\\\Storage\\\\Storage' => __DIR__ . '/..' . '/larena/storage/src/Storage.php',\n    );\n}\n",
        'vendor/composer/installed.json' => (string) json_encode(['packages' => [
            $installed('larena/core', '1.3.0', 'Larena\\Core'), $installed('larena/storage', '1.2.0', 'Larena\\Storage'),
        ], 'dev' => false, 'dev-package-names' => []], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n",
        'vendor/composer/installed.php' => "<?php return array(\n    'root' => array(\n        'name' => 'simai/larena',\n    ),\n    'versions' => array(\n"
            . $versionEntry('larena/core', '1.3.0') . $versionEntry('larena/storage', '1.2.0') . "    ),\n);\n",
        'vendor/larena/core/composer.json' => $composer('larena/core', 'Larena\\Core'),
        'vendor/larena/core/src/Core.php' => "<?php\nnamespace Larena\\Core;\nfinal class Core {}\n",
        'vendor/larena/storage/composer.json' => $composer('larena/storage', 'Larena\\Storage'),
        'vendor/larena/storage/src/Storage.php' => "<?php\nnamespace Larena\\Storage;\nfinal class Storage { public const VERSION = '1.2.0'; }\n",
    ];
    ksort($setFiles, SORT_STRING);

    /** A signed set release with its file list, laid out as a site on 1.3.0. */
    $world = static function (string $name) use ($root, $setFiles, $secret, $verifier): array {
        $site = $root . '/' . $name;
        mkdir($site . '/storage/app/public', 0755, true);
        foreach (['framework/cache', 'framework/sessions', 'framework/views', 'logs'] as $folder) {
            mkdir($site . '/storage/' . $folder, 0755, true);
        }
        file_put_contents($site . '/.env', "APP_KEY=test\n");
        $archive = Site::tar($setFiles);
        $entries = [];
        foreach ($setFiles as $path => $data) {
            $entries[$path] = ['path' => $path, 'type' => 'file', 'bytes' => strlen($data), 'sha256' => hash('sha256', $data)];
            $parts = explode('/', $path);
            array_pop($parts);
            for ($i = 1; $i <= count($parts); $i++) {
                $dir = implode('/', array_slice($parts, 0, $i));
                $entries[$dir] = ['path' => $dir, 'type' => 'dir', 'bytes' => 0];
            }
        }
        ksort($entries, SORT_STRING);
        $list = (string) json_encode(['schema' => 'larena.release-files', 'schema_version' => 1, 'product' => 'larena', 'version' => '1.3.0',
            'archive_sha256' => hash('sha256', $archive), 'entries' => array_values($entries),
            'core_files' => ['bootstrap/app.php', 'config/app.php', 'public/index.php', 'vendor/autoload.php']], JSON_UNESCAPED_SLASHES);
        $bytes = (string) json_encode(['schema' => 'larena.release', 'schema_version' => 1, 'product' => 'larena', 'version' => '1.3.0',
            'channel' => 'stable', 'minimum_version' => '1.0.0', 'php' => '^8.3',
            'archive' => ['file' => 'larena-1.3.0.tar.gz', 'sha256' => hash('sha256', $archive), 'bytes' => strlen($archive)],
            'files' => ['file' => 'files.json', 'sha256' => hash('sha256', $list), 'bytes' => strlen($list)],
            'packages' => ['larena/core' => str_repeat('a', 40)], 'backward_incompatible_migrations' => false, 'notes' => []], JSON_UNESCAPED_SLASHES);
        $signature = (string) json_encode(['key_id' => 'release.test', 'signature' => base64_encode(sodium_crypto_sign_detached($bytes, $secret))]);
        $download = $root . '/' . $name . '-download';
        mkdir($download);
        file_put_contents($download . '/larena-1.3.0.tar.gz', $archive);
        file_put_contents($download . '/files.json', $list);
        (new ReleaseArchiveExtractor())->extract($verifier->verify($bytes, $signature, '1.0.0'), $download . '/larena-1.3.0.tar.gz', $site, null, $bytes, $signature);
        Kit::remove($download);
        ReleaseSharedLinks::link($site, '1.3.0');
        (new ReleasePointer($site))->switchTo('1.3.0');
        mkdir($site . '/releases/1.3.0/bootstrap/cache', 0755, true);
        file_put_contents($site . '/releases/1.3.0/bootstrap/cache/packages.php', "<?php return [];\n");
        $fake = new RecordingUpdateSite('1.3.0', ['page:1']);
        $cycle = new ReleaseUpdateCycle($site, $verifier, new ReleaseArchiveExtractor(), $fake, $fake, $fake, $fake, $fake, $fake,
            [$fake, new RecordingReleaseActivation()], new InMemoryUpdateExecutionStore(), new RecordingUpdateLifecycleAudit());

        return ['site' => $site, 'fake' => $fake, 'cycle' => $cycle, 'pointer' => new ReleasePointer($site)];
    };
    /** A package archive and its signed release. @param array<string,string> $files */
    $package = static function (string $name, string $version, array $files, string $distribution = 'closed') use ($sign): array {
        $archive = Site::tar($files);
        $release = (string) json_encode([
            'schema' => 'larena.package-release', 'schema_version' => 1, 'product' => 'larena', 'package' => $name, 'version' => $version,
            'released_at' => '2026-11-20T10:00:00+00:00', 'distribution' => $distribution,
            'archive' => ['file' => str_replace('/', '-', $name) . '-' . $version . '.tar.gz', 'sha256' => hash('sha256', $archive), 'bytes' => strlen($archive)],
            'requires' => ['larena' => '>=1.3 <2.0', 'packages' => ['larena/core' => '^1.3']], 'backward_incompatible_migrations' => false,
        ], JSON_UNESCAPED_SLASHES);

        return ['release' => $release, 'signature' => $sign($release), 'archive' => $archive, 'files' => $files];
    };
    $list = static function (array $packages, int $version = 7) use ($sign): array {
        $bytes = (string) json_encode([
            'schema' => 'larena.package-list', 'schema_version' => 1, 'product' => 'larena', 'channel' => 'stable', 'list_version' => $version,
            'issued_at' => '2026-11-26T11:00:00+00:00', 'expires_at' => '2026-12-03T11:00:00+00:00',
            'packages' => array_map(static fn (array $p): array => ['release' => $p['release'], 'signature' => json_decode($p['signature'], true)], $packages),
        ], JSON_UNESCAPED_SLASHES);

        return [$bytes, $sign($bytes)];
    };
    $batch = static function (string $name, array $signed, array $packages) use ($root): PackageBatch {
        $archives = [];
        $selection = [];
        foreach ($packages as $key => $p) {
            [$package, $version] = explode('@', $key);
            $path = $root . '/' . $name . '-' . str_replace('/', '-', $package) . '-' . $version . '.tar.gz';
            file_put_contents($path, $p['archive']);
            $archives[$package] = $path;
            $selection[$package] = $version;
        }

        return new PackageBatch($signed[0], $signed[1], $selection, $archives);
    };
    // The bought addon is covered by the licence (a stand-in answer; the licence rules are tested with larena/licensing).
    $licence = new class () implements PackageUpdateLicence {
        public function refusal(string $package, DateTimeImmutable $releasedAt): ?string
        {
            return null;
        }
    };
    $candidate = static fn (PackageBatch $batch): PackageBatchCandidate => new PackageBatchCandidate($verifier, new ReleaseArchiveExtractor(), $licence, $batch, 'stable', $clock);
    $manifest = new UpdateManifest('packages.list_7', 'stable', ['larena/addon'], 'signature:list', true);
    $artifact = new ArtifactVerificationResult('packages.batch', 'sha256:' . str_repeat('e', 64), 'signature:list', $manifest->manifestKey, ArtifactVerificationStatus::Verified, true, true);
    $request = static fn (string $key): UpdateCycleRequest => new UpdateCycleRequest(
        'user:1', 'correlation:packages', $key, 'update.plan.package_batch', $manifest,
        new EntitlementSnapshot('site.entitlement', 'site-fixture', ['update.install'], true, true, true),
        new StaticCapability('update.install', 'larena/update'), $artifact,
        new ArrayLogicalFile('logical:packages', 'packages', new ArrayStorageBlobReference($artifact->checksum, 'fixture', 10, 'application/gzip'), FileVisibility::Internal, FileLifecycleClass::Temporary),
        ReleaseUpdateCycle::STEPS,
    );
    /** The integrity checks of the running release of a site. */
    $checks = static function (string $site) use ($keys): array {
        $release = (string) realpath($site . '/current');
        $layout = new IntegrityLayout($release, $site . '/storage/app/larena/integrity', IntegrityLayout::storageRoots($site . '/storage'));
        $baseline = static fn (): SignedBaseline => SignedBaseline::load($layout, $keys);
        $findings = new FindingStore($layout);
        $scanner = new IntegrityScanner($layout, $baseline, $findings);
        $guard = new CoreFileGuard($layout, $baseline);
        $scan = static function () use ($scanner): array {
            $scanner->start('test', true);

            return (array) ($scanner->runToEnd()['current'] ?? []);
        };

        return ['release' => $release, 'baseline' => $baseline, 'scan' => $scan, 'guard' => $guard,
            'restore' => new IntegrityRestore($layout, $baseline, $findings, $scanner, $guard, new RecordingIntegrityHistory())];
    };
    $open = static fn (string $site): array => array_map(static fn (array $f): string => $f['kind'] . ':' . $f['path'],
        (new FindingStore(new IntegrityLayout((string) realpath($site . '/current'), $site . '/storage/app/larena/integrity')))->open());

    try {
        // 1. After a batch the checks compare each package folder with the package release that is there.
        $w = $world('update');
        $storage = $package('larena/storage', '1.3.0', [
            'composer.json' => $composer('larena/storage', 'Larena\\Storage'),
            'src/Storage.php' => "<?php\nnamespace Larena\\Storage;\nfinal class Storage { public const VERSION = '1.3.0'; }\n",
        ]);
        $done = $w['cycle']->applyCandidate($request('integrity:update'), $candidate($batch('update', $list([$storage]), ['larena/storage@1.3.0' => $storage])));
        Kit::check($done->state === UpdateCycleState::Completed, 'the update batch completes: ' . $done->reasonCode);
        $c = $checks($w['site']);
        $baseline = ($c['baseline'])();
        Kit::check($baseline->layer !== null && isset($baseline->layer->packages['larena/storage'])
            && $baseline->list->entries['vendor/larena/storage/src/Storage.php']['sha256'] === hash('sha256', $storage['files']['src/Storage.php'])
            && $baseline->setList->entries['vendor/larena/storage/src/Storage.php']['sha256'] === hash('sha256', $setFiles['vendor/larena/storage/src/Storage.php']),
            'the package release is laid over the set list');
        $run = ($c['scan'])();
        Kit::check($run['result'] === IntegrityScanner::UNCHANGED && $run['reason'] === null, 'integrity after a batch reports real results: ' . $run['result'] . ' ' . $run['reason']);
        Kit::check(($c['guard'])->check()->ok(), 'the request check passes on the batch release');

        // A changed package file is caught and put back from the package's own archive.
        file_put_contents($c['release'] . '/vendor/larena/storage/src/Storage.php', "<?php // changed\n");
        $run = ($c['scan'])();
        Kit::check($run['result'] === IntegrityScanner::CHANGES_FOUND && in_array('changed:vendor/larena/storage/src/Storage.php', $open($w['site']), true),
            'a changed package file is found');
        $plan = $c['restore']->plan();
        Kit::check($plan->blockers === [] && array_column($plan->restore, 'path') === ['vendor/larena/storage/src/Storage.php'], 'the plan puts the package file back');
        $result = $c['restore']->run($plan->digest(), 'user:1');
        Kit::check($result['restored'] === 1 && $result['result'] === IntegrityScanner::UNCHANGED
            && file_get_contents($c['release'] . '/vendor/larena/storage/src/Storage.php') === $storage['files']['src/Storage.php'],
            'restoring a package file takes the package release, not the set');
        // A file the package does not have is new there; a deleted package file comes back too.
        unlink($c['release'] . '/vendor/larena/storage/composer.json');
        $run = ($c['scan'])();
        Kit::check(in_array('deleted:vendor/larena/storage/composer.json', $open($w['site']), true), 'a deleted package file is found');
        $plan = $c['restore']->plan();
        $c['restore']->run($plan->digest(), 'user:1');
        Kit::check(file_get_contents($c['release'] . '/vendor/larena/storage/composer.json') === $storage['files']['composer.json'], 'and put back');

        // A damaged package archive: the kept archive is a finding; with no archive the list is never trusted.
        $archive = $c['release'] . '/.larena-packages/larena/storage/larena-storage-1.3.0.tar.gz';
        $kept = (string) file_get_contents($archive);
        unlink($archive);
        $run = ($c['scan'])();
        Kit::check($run['result'] === IntegrityScanner::UNKNOWN && $run['reason'] === 'integrity_list_untrusted', 'without the package archive the check says it could not check');
        file_put_contents($archive, $kept);
        // An edited record is checked again: a release its signature does not cover is not trusted.
        $record = $c['release'] . '/' . InstalledPackages::RECORD;
        $bytes = (string) file_get_contents($record);
        $edited = json_decode($bytes, true);
        $edited['packages']['larena/storage']['release'] = str_replace('"version":"1.3.0"', '"version":"1.3.1"', $edited['packages']['larena/storage']['release']);
        $edited['packages']['larena/storage']['version'] = '1.3.1';
        file_put_contents($record, json_encode($edited, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        Kit::check(($c['scan'])()['reason'] === 'integrity_list_untrusted', 'an edited package release in the record is not trusted');
        file_put_contents($record, $bytes);
        Kit::check(($c['scan'])()['result'] === IntegrityScanner::UNCHANGED, 'back to a clean check');

        // 2. A package the site does not have yet: asked for by name, installed through a batch.
        $addonFiles = [
            'composer.json' => $composer('larena/addon', 'Larena\\Addon', ['extra' => ['laravel' => ['providers' => ['Larena\\Addon\\AddonServiceProvider']]]]),
            'src/Addon.php' => "<?php\nnamespace Larena\\Addon;\nfinal class Addon { public static function hello(): string { return 'addon 1.0.0'; } }\n",
            'src/Support/Helper.php' => "<?php\nnamespace Larena\\Addon\\Support;\nfinal class Helper {}\n",
        ];
        $addon = $package('larena/addon', '1.0.0', $addonFiles, 'closed');
        $w = $world('addon');
        $signed = $list([$addon]);
        $listed = (new PackageListVerifier($verifier))->verify($signed[0], $signed[1], 'stable', $now);
        $before = InstalledPackages::read($w['site'] . '/releases/1.3.0', '1.3.0');
        Kit::check((new PackageBatchPlanner($licence))->plan($listed, $before)->selection() === [], 'a new package is never taken unasked');
        $plan = (new PackageBatchPlanner($licence))->plan($listed, $before, ['larena/addon']);
        Kit::check(!$plan->refused() && $plan->selection() === ['larena/addon' => '1.0.0'], 'asked for by name, the new package is in the plan');
        $done = $w['cycle']->applyCandidate($request('addon:install'), $candidate($batch('addon', $signed, ['larena/addon@1.0.0' => $addon])));
        Kit::check($done->state === UpdateCycleState::Completed && count(preg_grep('/\Aactivate:/', $w['fake']->journal)) === 1, 'the new package installs in one cycle, one switch: ' . $done->reasonCode);
        $current = (string) realpath($w['site'] . '/current');
        $json = json_decode((string) file_get_contents($current . '/vendor/composer/installed.json'), true);
        $entry = array_values(array_filter($json['packages'], static fn (array $p): bool => $p['name'] === 'larena/addon'))[0] ?? [];
        Kit::check(($entry['install-path'] ?? null) === '../larena/addon' && ($entry['version'] ?? null) === '1.0.0'
            && ($entry['extra']['laravel']['providers'][0] ?? null) === 'Larena\\Addon\\AddonServiceProvider' && $json['dev-package-names'] === [],
            'installed.json names the package for Laravel\'s package discovery');
        Kit::check(!file_exists($current . '/bootstrap/cache/packages.php') && file_exists($w['site'] . '/releases/1.3.0/bootstrap/cache/packages.php'),
            'the package discovery cache is rebuilt on the next boot');
        $versions = require $current . '/vendor/composer/installed.php';
        Kit::check(($versions['versions']['larena/addon']['pretty_version'] ?? null) === '1.0.0' && isset($versions['versions']['larena/core']), 'installed.php knows the package');
        // Its classes load after the switch, through the class map alone (authoritative, as the distribution).
        $map = require $current . '/vendor/composer/autoload_classmap.php';
        $loader = new ClassLoader();
        $loader->addClassMap($map);
        $loader->setClassMapAuthoritative(true);
        $loader->register(true);
        try {
            Kit::check(class_exists('Larena\\Addon\\Addon') && \Larena\Addon\Addon::hello() === 'addon 1.0.0' && class_exists('Larena\\Addon\\Support\\Helper'),
                'the new package\'s classes load after the switch');
        } finally {
            $loader->unregister();
        }
        require $current . '/vendor/composer/autoload_static.php';
        Kit::check(str_ends_with(\Composer\Autoload\ComposerStaticInitPackageIntegrityTest::$classMap['Larena\\Addon\\Addon'] ?? '', '/larena/addon/src/Addon.php')
            && isset(\Composer\Autoload\ComposerStaticInitPackageIntegrityTest::$classMap['Larena\\Core\\Core']), 'the static loader has the classes too');
        Kit::check(InstalledPackages::read($current, '1.3.0')->added === ['larena/addon'], 'the record marks the package as added');
        // The checks expect the generated Composer files and the package's own files.
        $c = $checks($w['site']);
        $run = ($c['scan'])();
        Kit::check($run['result'] === IntegrityScanner::UNCHANGED, 'integrity of a release with a new package: ' . $run['result'] . ' ' . $run['reason']);
        $generated = (string) file_get_contents($current . '/vendor/composer/autoload_classmap.php');
        file_put_contents($current . '/vendor/composer/autoload_classmap.php', $setFiles['vendor/composer/autoload_classmap.php']);
        ($c['scan'])();
        Kit::check(in_array('changed:vendor/composer/autoload_classmap.php', $open($w['site']), true), 'a changed generated class map is found');
        $plan = $c['restore']->plan();
        $c['restore']->run($plan->digest(), 'user:1');
        Kit::check(file_get_contents($current . '/vendor/composer/autoload_classmap.php') === $generated, 'and put back as the batch generated it');

        // An update of the added package may bring a new class: its class map is generated again from the set files.
        $addonNext = $package('larena/addon', '1.1.0', $addonFiles + ['src/Extra.php' => "<?php\nnamespace Larena\\Addon;\nfinal class Extra {}\n"], 'closed');
        $done = $w['cycle']->applyCandidate($request('addon:update'), $candidate($batch('addon-next', $list([$addon, $addonNext], 8), ['larena/addon@1.1.0' => $addonNext])));
        Kit::check($done->state === UpdateCycleState::Completed, 'the added package updates: ' . $done->reasonCode);
        $classmap = (string) file_get_contents((string) realpath($w['site'] . '/current') . '/vendor/composer/autoload_classmap.php');
        Kit::check(substr_count($classmap, "'Larena\\\\Addon\\\\Addon' =>") === 1 && str_contains($classmap, "'Larena\\\\Addon\\\\Extra' =>"), 'one entry per class, the new class mapped');
        Kit::check(($checks($w['site'])['scan'])()['result'] === IntegrityScanner::UNCHANGED, 'and the checks still pass after the second batch');

        // 2b. Removing the addon (matrix 20): its code leaves the running release in one cycle, its data stays, and
        // larena/licensing answers its functions read-only until it is installed again.
        $records = $w['site'] . '/storage/app/addon/records.json';
        mkdir(dirname($records), 0755, true);
        file_put_contents($records, '{"records":[1,2,3]}');
        $installedNow = static fn (string $package): bool => isset(InstalledPackages::read((string) realpath($w['site'] . '/current'), '1.3.0')->versions[$package]);
        $folder = $w['site'] . '/storage/app/larena/licensing';
        $data = new SiteDataAccess(
            new SiteLicensing(new SnapshotVerifier(TrustedKeys::from([[]], [[]]), 'larena'), new FileSnapshotStore($folder . '/snapshot.json'), new TestAddresses(), 'shop.test'),
            new CapabilityCatalog(['larena.capability.addon.base' => new CatalogCapability('larena.capability.addon.base', 'larena/addon', 'package_baseline', 'paid', true,
                'site', false, [], 'addon.title', 'addon.description', 14)]),
            new RemovedAddons($folder . '/removed-addons.json'), new PlanDowngrades($folder . '/downgrades.json'), new SiteUsage(), $installedNow, 30,
        );
        Kit::check($data->write('larena.capability.addon.base', SiteDataAccess::EDIT)->allowed, 'before removal the addon\'s data is editable');
        $removal = static fn (string $package): PackageRemovalCandidate => new PackageRemovalCandidate($verifier, new ReleaseArchiveExtractor(), $package);
        $before = (string) realpath($w['site'] . '/current');
        $done = $w['cycle']->applyCandidate($request('addon:remove'), $removal('larena/addon'));
        Kit::check($done->state === UpdateCycleState::Completed, 'the addon is removed in one cycle: ' . $done->reasonCode);
        $current = (string) realpath($w['site'] . '/current');
        Kit::check($current !== $before && is_dir($before . '/vendor/larena/addon'), 'a new release folder; the previous one keeps the addon for the rollback');
        Kit::check(!file_exists($current . '/vendor/larena/addon') && !file_exists($current . '/.larena-packages/larena/addon')
            && !str_contains((string) file_get_contents($current . '/vendor/composer/installed.json'), 'larena/addon')
            && file_get_contents($current . '/vendor/composer/autoload_classmap.php') === $setFiles['vendor/composer/autoload_classmap.php']
            && file_get_contents($current . '/vendor/composer/autoload_static.php') === $setFiles['vendor/composer/autoload_static.php']
            && file_get_contents($current . '/vendor/composer/installed.php') === $setFiles['vendor/composer/installed.php']
            && !file_exists($current . '/bootstrap/cache/packages.php'), 'the addon\'s code is out of the active set: folder, archive, autoloader, discovery cache');
        Kit::check(InstalledPackages::read($current, '1.3.0')->kept === [] && !$installedNow('larena/addon'), 'the record no longer names the addon');
        Kit::check(file_get_contents($records) === '{"records":[1,2,3]}', 'the addon\'s data in shared storage stays');
        $journal = array_slice($w['fake']->journal, (int) array_search('backup:' . $done->cycleId, $w['fake']->journal, true));
        Kit::check(in_array('migrate:' . basename($current), $journal, true) && preg_grep('/\Arestore:/', $journal) === [],
            'the database only migrates forward and is not restored: the addon\'s tables stay');
        Kit::check(($checks($w['site'])['scan'])()['result'] === IntegrityScanner::UNCHANGED, 'the checks pass on the release without the addon');
        $readOnly = $data->addonRemoved('larena/addon', '1.1.0');
        Kit::check($readOnly === ['larena.capability.addon.base'] && ($data->removedAddons()[0]['version'] ?? null) === '1.1.0', 'licensing notes «removed addon, data kept»');
        $refused = $data->write('larena.capability.addon.base', SiteDataAccess::EDIT);
        Kit::check(!$refused->allowed && $refused->reasonCode === 'addon_removed' && $data->read('larena.capability.addon.base')->allowed
            && $data->export('larena.capability.addon.base')->allowed, 'after removal a write is refused on the server; reading and export stay');
        // What cannot be removed: nothing installed under the name, a package of the product, an addon another package needs.
        $again = $w['cycle']->applyCandidate($request('addon:remove-again'), $removal('larena/addon'));
        Kit::check($again->state === UpdateCycleState::Blocked && $again->reasonCode === 'update_remove_not_installed', 'removing it again is refused');
        $core = $w['cycle']->applyCandidate($request('addon:remove-core'), $removal('larena/core'));
        Kit::check($core->state === UpdateCycleState::Blocked && $core->reasonCode === 'update_remove_not_addon', 'a package of the product is not an addon');
        $needed = new InstalledPackages('1.3.0', ['larena/addon' => '1.0.0', 'larena/partner' => '1.0.0'],
            ['larena/addon' => [], 'larena/partner' => ['require' => ['larena/addon' => '^1.0']]], [],
            ['larena/addon' => ['version' => '1.0.0', 'released_at' => '2026-11-20T10:00:00+00:00', 'release' => '{}', 'signature' => '{}', 'added' => true]],
            7, null, false, ['larena/addon']);
        try {
            $removal('larena/addon')->check($needed);
            $reason = null;
        } catch (\Larena\UpdateClient\Packages\PackageBatchRefused $exception) {
            $reason = $exception->getMessage();
        }
        Kit::check($reason === 'update_remove_required', 'an addon another installed package requires stays');

        // Installing it again (the same version) brings editing back on the same data.
        $done = $w['cycle']->applyCandidate($request('addon:reinstall'), $candidate($batch('addon-reinstall', $list([$addon, $addonNext], 9), ['larena/addon@1.1.0' => $addonNext])));
        Kit::check($done->state === UpdateCycleState::Completed && $installedNow('larena/addon'), 'the addon installs again: ' . $done->reasonCode);
        Kit::check($data->write('larena.capability.addon.base', SiteDataAccess::EDIT)->allowed && $data->addonInstalled('larena/addon') && $data->removedAddons() === [],
            'installed again, editing is back and the marker is cleared');
        Kit::check(file_get_contents($records) === '{"records":[1,2,3]}', 'on the same data');
        Kit::check(($checks($w['site'])['scan'])()['result'] === IntegrityScanner::UNCHANGED, 'and the checks pass after the reinstall');

        // 3. A failed check after the switch rolls the new package back with the rest.
        $w = $world('addon-health');
        $w['fake']->fail('health');
        $failed = $w['cycle']->applyCandidate($request('addon:health'), $candidate($batch('addon-health', $signed, ['larena/addon@1.0.0' => $addon])));
        $current = (string) realpath($w['site'] . '/current');
        Kit::check($failed->state === UpdateCycleState::RecoverableFailed && $w['pointer']->current() === '1.3.0'
            && !file_exists($current . '/vendor/larena/addon') && !str_contains((string) file_get_contents($current . '/vendor/composer/installed.json'), 'larena/addon')
            && $w['fake']->database['schema'] === '1.3.0', 'a failed check rolls the new package back: code and database');

        // 4. Refusals before anything is touched.
        $w = $world('addon-refused');
        $files = $package('larena/addon', '1.0.0', ['composer.json' => (string) json_encode(['name' => 'larena/addon', 'autoload' => ['files' => ['src/helpers.php']]]), 'src/helpers.php' => '<?php'], 'closed');
        $refused = $w['cycle']->applyCandidate($request('addon:files'), $candidate($batch('addon-files', $list([$files]), ['larena/addon@1.0.0' => $files])));
        Kit::check($refused->state === UpdateCycleState::Blocked && $refused->reasonCode === 'update_batch_autoload_unsupported', 'autoload files needs a set release');
        $taken = $package('larena/addon', '1.0.0', ['composer.json' => $composer('larena/addon', 'Larena\\Storage'), 'src/Storage.php' => '<?php'], 'closed');
        $refused = $w['cycle']->applyCandidate($request('addon:taken'), $candidate($batch('addon-taken', $list([$taken]), ['larena/addon@1.0.0' => $taken])));
        Kit::check($refused->reasonCode === 'update_batch_autoload_unsupported', 'a class name the site already has is refused');
        Kit::remove($w['site'] . '/releases/1.3.0/.larena-signed');
        $refused = $w['cycle']->applyCandidate($request('addon:nolist'), $candidate($batch('addon-nolist', $signed, ['larena/addon@1.0.0' => $addon])));
        Kit::check($refused->reasonCode === 'update_batch_set_list_missing', 'without the signed set list a new package is refused');
        Kit::check(preg_grep('/\A(backup|maintenance)/', $w['fake']->journal) === [] && $w['pointer']->current() === '1.3.0', 'nothing was touched');
    } finally {
        Kit::remove($root);
    }
    echo "PackageIntegrityTest passed.\n";
})();
