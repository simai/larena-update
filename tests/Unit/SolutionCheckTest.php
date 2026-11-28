<?php

declare(strict_types=1);

require_once __DIR__ . '/../Support/UpdateClientFakes.php';

use Larena\UpdateClient\Packages\InstalledPackages;
use Larena\UpdateClient\Packages\PackageBatchPlanner;
use Larena\UpdateClient\Packages\PackageList;
use Larena\UpdateClient\Packages\PackageListVerifier;
use Larena\UpdateClient\Packages\PackageUpdateLicence;
use Larena\UpdateClient\Release\ReleaseSignatureVerifier;
use Larena\UpdateClient\Solutions\SolutionCheck;
use Larena\UpdateClient\Solutions\SolutionPlan;
use Larena\UpdateClient\Solutions\SolutionRefused;
use Larena\UpdateClient\Solutions\SolutionRights;
use Larena\UpdateClient\Solutions\SolutionSettings;
use Larena\UpdateClient\Solutions\SolutionSiteContent;
use Larena\UpdateClient\Solutions\SolutionVerifier;
use Larena\UpdateClient\Tests\Support\UpdateClientTestKit as Kit;

// A solution (§14 stage 4, S1–S2, matrix 34–35): signed solution.json checked before anything is written — signature
// by a release key only, product and version range, no secret and only declared settings, every missing right in one
// refusal with «Попробовать 14 дней» / «Купить», a clean site, the batch planner's refusals. Throwaway keys only.
(static function (): void {
    $pair = static fn (): array => [sodium_crypto_sign_secretkey($k = sodium_crypto_sign_keypair()), base64_encode(sodium_crypto_sign_publickey($k))];
    [$releaseSecret, $releasePublic] = $pair();
    [$listSecret, $listPublic] = $pair();
    [$strangerSecret] = $pair();
    $sign = static fn (string $bytes, string $secret, string $id = 'release.main'): string => (string) json_encode(['key_id' => $id, 'signature' => base64_encode(sodium_crypto_sign_detached($bytes, $secret))]);
    $verifier = new ReleaseSignatureVerifier(['release.main' => $releasePublic, 'list.main' => $listPublic], [], 'larena', ['list.main']);
    $now = new DateTimeImmutable('2026-11-28T12:00:00+00:00');

    // The signed package list: a closed shop addon and a closed crm addon, each declaring its settings.
    $package = static function (string $name, string $version, array $settings, string $larena = '>=1.3 <2.0') use ($sign, $releaseSecret): array {
        $bytes = (string) json_encode(['schema' => 'larena.package-release', 'schema_version' => 1, 'product' => 'larena', 'package' => $name,
            'version' => $version, 'released_at' => '2026-11-20T10:00:00+00:00', 'distribution' => 'closed',
            'archive' => ['file' => str_replace('/', '-', $name) . '-' . $version . '.tar.gz', 'sha256' => str_repeat('a', 64), 'bytes' => 10],
            'requires' => ['larena' => $larena, 'packages' => []], 'backward_incompatible_migrations' => false, 'settings' => $settings], JSON_UNESCAPED_SLASHES);

        return ['release' => $bytes, 'signature' => json_decode($sign($bytes, $releaseSecret), true)];
    };
    $listBytes = (string) json_encode(['schema' => 'larena.package-list', 'schema_version' => 1, 'product' => 'larena', 'channel' => 'stable',
        'list_version' => 3, 'issued_at' => '2026-11-27T11:00:00+00:00', 'expires_at' => '2026-12-05T12:00:00+00:00', 'packages' => [
            $package('larena/shop', '1.0.0', ['shop.currency']),
            $package('larena/shop', '1.1.0', ['shop.currency', 'shop.vat_included']),
            $package('larena/crm', '2.0.0', ['crm.pipeline']),
            $package('larena/future', '1.0.0', [], '>=2.0'),
        ]], JSON_UNESCAPED_SLASHES);
    $list = (new PackageListVerifier($verifier))->verify($listBytes, $sign($listBytes, $listSecret, 'list.main'), 'stable', $now);
    $listReads = 0;
    $packageList = static function () use ($list, &$listReads): PackageList {
        ++$listReads;

        return $list;
    };

    // The site: Larena 1.3.0 with larena/storage 1.2.0 installed; its module.yaml declares storage.page_size.
    $site = Kit::temp('solution-site');
    mkdir($site . '/vendor/larena/storage', 0755, true);
    file_put_contents($site . '/vendor/larena/storage/module.yaml', "package: larena/storage\nsettings:\n  - storage.page_size\n  # a comment\n  - storage.history_keep\ndelivery:\n  publisher: simai\n");
    $installed = new InstalledPackages('1.3.0', ['larena/core' => '1.3.0', 'larena/storage' => '1.2.0'], [], [], [], 0, null, false, [], $site);

    $rights = new class () implements SolutionRights {
        /** @var array<string,bool> */
        public array $allowed = ['shop.base' => true, 'crm.base' => true];

        public function allows(string $capability): bool
        {
            return $this->allowed[$capability] ?? false;
        }

        public function offer(string $capability, string $package): array
        {
            return ['title' => ucfirst(explode('.', $capability)[0]), 'trial' => $capability === 'shop.base', 'trial_days' => $capability === 'shop.base' ? 14 : null,
                'shop_url' => 'https://shop.example.test/' . str_replace('/', '-', $package)];
        }
    };
    $content = new class () implements SolutionSiteContent {
        /** @var list<array{structure:string,label:string,records:int}> */
        public array $blockers = [];
        public int $asked = 0;

        public function blockers(string $sitepackPath): array
        {
            ++$this->asked;

            return $this->blockers;
        }
    };
    $licence = new class () implements PackageUpdateLicence {
        public function refusal(string $package, DateTimeImmutable $releasedAt): ?string
        {
            return null;
        }
    };
    $check = new SolutionCheck(new SolutionVerifier($verifier), new PackageBatchPlanner($licence), $rights, $content);

    // A solution folder: solution.json, its signature, the SitePack.
    $root = Kit::temp('solution');
    $pack = 'shop-demo.sitepack.zip';
    file_put_contents($root . '/' . $pack, 'sitepack bytes');
    $document = [
        'schema' => 'larena.solution', 'schema_version' => 1, 'id' => 'shop-demo', 'version' => '1.0.0', 'product' => 'larena', 'product_version' => '>=1.3 <2.0',
        'packages' => [
            ['name' => 'larena/storage', 'version' => '^1.2', 'capability' => null],
            ['name' => 'larena/shop', 'version' => '^1.0', 'capability' => 'shop.base'],
            ['name' => 'larena/crm', 'version' => '2.0.0', 'capability' => 'crm.base'],
        ],
        'sitepack' => ['ref' => $pack, 'sha256' => hash('sha256', 'sitepack bytes')],
        'settings' => ['shop.currency' => 'RUB', 'shop.vat_included' => true, 'storage.page_size' => 50],
    ];
    $write = static function (array $overrides = [], ?string $secret = null, string $id = 'release.main', ?string $bytes = null) use ($root, $document, $sign, $releaseSecret): string {
        $bytes ??= (string) json_encode($overrides + $document, JSON_UNESCAPED_SLASHES);
        file_put_contents($root . '/solution.json', $bytes);
        file_put_contents($root . '/solution.json.sig', $sign($bytes, $secret ?? $releaseSecret, $id));

        return $bytes;
    };
    /** @return list<array<string,mixed>> */
    $refusals = static function () use ($check, $root, $installed, $packageList): array {
        try {
            $check->check($root, $installed, $packageList);
        } catch (SolutionRefused $refused) {
            return $refused->refusals;
        }

        return [];
    };
    $snapshot = static function () use ($root, $site): string {
        $files = [];
        foreach ([$root, $site] as $base) {
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS)) as $file) {
                $files[] = $file->getPathname() . ':' . hash_file('sha256', $file->getPathname());
            }
        }
        sort($files);

        return implode("\n", $files);
    };

    try {
        // A valid solution → the plan: storage present, shop 1.1.0 (the newest ^1.0) and crm 2.0.0 to install.
        $write();
        $before = $snapshot();
        $plan = $check->check($root, $installed, $packageList);
        Kit::check($plan instanceof SolutionPlan && $plan->present === ['larena/storage' => '1.2.0'], 'An installed package in the rule is present.');
        Kit::check(array_map(static fn ($r): string => $r->key(), $plan->install) === ['larena/shop@1.1.0', 'larena/crm@2.0.0'], 'The newest release in the rule is planned.');
        Kit::check($plan->declared === ['crm.pipeline', 'shop.currency', 'shop.vat_included', 'storage.history_keep', 'storage.page_size'], 'Declared keys come from the releases and the installed module.yaml.');
        Kit::check($plan->solution->keyId === 'release.main' && $plan->toArray()['sitepack']['ref'] === $pack && $content->asked === 1, 'The plan names the signer and the SitePack.');
        Kit::check($snapshot() === $before, 'A check writes nothing.');
        // The same solution given as its file.
        Kit::check($check->check($root . '/solution.json', $installed, $packageList)->solution->id === 'shop-demo', 'The file path works as well as the folder.');

        // Tampered → refused; unknown key → refused; a package-list key → refused.
        $bytes = $write();
        file_put_contents($root . '/solution.json', str_replace('"RUB"', '"USD"', $bytes));
        $refused = $refusals();
        Kit::check(($refused[0]['reason'] ?? null) === 'solution_signature_invalid' && $refused[0]['detail'] === 'release_signature_mismatch', 'Tampered bytes are refused.');
        $write([], $strangerSecret, 'release.stranger');
        $refused = $refusals();
        Kit::check(($refused[0]['reason'] ?? null) === 'solution_signature_invalid' && $refused[0]['detail'] === 'release_key_untrusted', 'An unknown key is refused.');
        $write([], $listSecret, 'list.main');
        $refused = $refusals();
        Kit::check(($refused[0]['detail'] ?? null) === 'release_key_purpose_differs', 'A package-list key signs no solution.');
        unlink($root . '/solution.json.sig');
        Kit::check(($refusals()[0]['reason'] ?? null) === 'solution_files_missing', 'No signature: refused.');

        // Secrets in settings → refused, the value never named.
        $write(['settings' => ['shop.api_key' => 'plain', 'shop.currency' => 'RUB']]);
        $refused = $refusals();
        Kit::check(count($refused) === 1 && $refused[0]['reason'] === 'solution_setting_secret' && $refused[0]['subject'] === 'shop.api_key', 'A secret key is refused.');
        $secretValue = 'sk_live_' . str_repeat('Ab3', 10);
        $write(['settings' => ['shop.currency' => $secretValue]]);
        $refused = $refusals();
        Kit::check(($refused[0]['reason'] ?? null) === 'solution_setting_secret' && $refused[0]['detail'] === 'value'
            && !str_contains((string) json_encode($refused), $secretValue), 'A value that looks like a secret is refused and not repeated.');
        $write(['settings' => ['shop.currency' => 'https://user:pass@db.example.test/shop']]);
        Kit::check(($refusals()[0]['reason'] ?? null) === 'solution_setting_secret', 'An address with a password is a secret.');

        // An undeclared setting → refused.
        $write(['settings' => ['shop.currency' => 'RUB', 'mail.from' => 'shop@example.test']]);
        $refused = $refusals();
        Kit::check(count($refused) === 1 && $refused[0]['reason'] === 'solution_setting_undeclared' && $refused[0]['subject'] === 'mail.from', 'An undeclared setting is refused.');

        // Two missing rights → one refusal naming both, with trial and buy data.
        $write();
        $rights->allowed = [];
        $refused = $refusals();
        Kit::check(count($refused) === 1 && $refused[0]['reason'] === 'solution_rights_missing', 'Missing rights are one refusal.');
        $addons = $refused[0]['addons'];
        Kit::check(array_column($addons, 'capability') === ['shop.base', 'crm.base'] && array_column($addons, 'package') === ['larena/shop', 'larena/crm'], 'It names both addons.');
        Kit::check($addons[0]['trial'] === true && $addons[0]['trial_days'] === 14 && $addons[1]['trial'] === false
            && $addons[1]['shop_url'] === 'https://shop.example.test/larena-crm', '«Попробовать 14 дней» and «Купить» data are in it.');
        $rights->allowed = ['shop.base' => true, 'crm.base' => true];

        // A site that is not empty → refused naming what is in the way; with missing rights both are named.
        $content->blockers = [['structure' => 'site.pages', 'label' => 'Страницы сайта', 'records' => 3]];
        $refused = $refusals();
        Kit::check(count($refused) === 1 && $refused[0]['reason'] === 'solution_site_not_empty' && $refused[0]['subject'] === 'Страницы сайта'
            && $refused[0]['blockers'][0]['records'] === 3, 'A non-empty site is refused, naming the blocker.');
        $rights->allowed = ['crm.base' => true];
        Kit::check(array_column($refusals(), 'reason') === ['solution_rights_missing', 'solution_site_not_empty'], 'All S2 refusals come together.');
        $rights->allowed = ['shop.base' => true, 'crm.base' => true];
        $content->blockers = [];

        // Product, version range, SitePack checksum.
        $write(['product' => 'larena-panel']);
        Kit::check(($refusals()[0]['reason'] ?? null) === 'solution_product_differs', 'Another product is refused.');
        $write(['product_version' => '>=1.4']);
        Kit::check(($refusals()[0]['reason'] ?? null) === 'solution_product_version_differs', 'A site out of the range is refused.');
        $write(['sitepack' => ['ref' => $pack, 'sha256' => str_repeat('b', 64)]]);
        Kit::check(($refusals()[0]['reason'] ?? null) === 'solution_sitepack_invalid', 'A SitePack that differs is refused.');
        $write(['schema' => 'larena.release']);
        Kit::check(($refusals()[0]['reason'] ?? null) === 'solution_invalid', 'Another schema is refused.');

        // Packages: none in the rule, and the batch planner's own refusal reused.
        $write(['packages' => [['name' => 'larena/shop', 'version' => '^3.0', 'capability' => null]], 'settings' => []]);
        Kit::check(($refusals()[0]['reason'] ?? null) === 'solution_package_unavailable', 'No release in the rule is refused.');
        $write(['packages' => [['name' => 'larena/future', 'version' => '1.0.0', 'capability' => null]], 'settings' => []]);
        $refused = $refusals();
        Kit::check(($refused[0]['reason'] ?? null) === 'update_batch_incompatible' && str_contains($refused[0]['detail'], '>=2.0'), 'The planner refusal is reused.');
        // Only installed packages: the package list is not even read.
        $listReads = 0;
        $write(['packages' => [['name' => 'larena/storage', 'version' => '^1.0', 'capability' => null]], 'settings' => ['storage.page_size' => 20]]);
        Kit::check($check->check($root, $installed, $packageList)->install === [] && $listReads === 0, 'A solution of installed packages needs no package list.');

        // The secret rules on their own.
        foreach (['db.password', 'mail.smtp_pass', 'api.token', 'payments.private_key', 'shop.licensekey', 'x.client_secret'] as $key) {
            Kit::check(SolutionSettings::secretKey($key), 'A secret key: ' . $key);
        }
        foreach (['shop.currency', 'seo.author', 'site.title', 'catalog.page_size'] as $key) {
            Kit::check(!SolutionSettings::secretKey($key), 'Not a secret key: ' . $key);
        }
        Kit::check(SolutionSettings::secretValue("-----BEGIN PRIVATE KEY-----\nabc") && SolutionSettings::secretValue('eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiIxMjM0NSJ9.c2lnbmF0dXJlLXZhbHVl')
            && SolutionSettings::secretValue(['nested' => 'ghp_' . str_repeat('a1', 12)]) && !SolutionSettings::secretValue('Магазин у дома') && !SolutionSettings::secretValue(42),
            'Values that look like secrets are found, plain ones pass.');
        Kit::check(SolutionSettings::declared($site . '/vendor/larena/storage/module.yaml') === ['storage.history_keep', 'storage.page_size']
            && SolutionSettings::declared($site . '/none.yaml') === null, 'module.yaml settings are read.');

        // Words for every refusal, in every language.
        foreach (glob(dirname(__DIR__, 2) . '/resources/lang/*/integrity.php') ?: [] as $file) {
            $reasons = (require $file)['reasons'] ?? [];
            foreach (['release_key_purpose_differs', 'solution_files_missing', 'solution_signature_invalid', 'solution_invalid', 'solution_product_differs',
                'solution_product_version_differs', 'solution_sitepack_invalid', 'solution_sitepack_unsupported', 'solution_setting_secret',
                'solution_setting_undeclared', 'solution_package_unavailable', 'solution_package_list_unavailable', 'solution_rights_missing',
                'solution_site_not_empty'] as $code) {
                Kit::check(is_string($reasons[$code] ?? null) && $reasons[$code] !== '', "Words for $code must be in $file.");
            }
        }
        foreach (glob(dirname(__DIR__, 2) . '/resources/lang/*/solutions.php') ?: [] as $file) {
            $words = require $file;
            foreach (['planned', 'refused', 'present', 'install', 'rights_missing', 'try', 'buy', 'site_not_empty', 'records'] as $key) {
                Kit::check(is_string($words[$key] ?? null) && $words[$key] !== '', "Words for $key must be in $file.");
            }
        }
    } finally {
        Kit::remove($root);
        Kit::remove($site);
    }
    echo "SolutionCheckTest passed.\n";
})();
