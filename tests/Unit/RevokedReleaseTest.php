<?php

declare(strict_types=1);

require_once __DIR__ . '/../Support/UpdateClientFakes.php';

use Illuminate\Http\Request;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use Larena\UpdateClient\Laravel\Admin\SiteUpdates;
use Larena\UpdateClient\Laravel\Admin\UpdatesAdminAlertSource;
use Larena\UpdateClient\Laravel\SiteUpdateClient;
use Larena\UpdateClient\Release\ReleaseSignatureVerifier;
use Larena\UpdateClient\Runtime\PdoUpdateExecutionStore;
use Larena\UpdateClient\Source\ReleaseRevoked;
use Larena\UpdateClient\Source\ReleaseSource;
use Larena\UpdateClient\Source\RevokedReleaseState;
use Larena\UpdateClient\Tests\Support\FakeArtisanRunner;
use Larena\UpdateClient\Tests\Support\FakeUpdateHttpClient;
use Larena\UpdateClient\Tests\Support\MemoryLogger;
use Larena\UpdateClient\Tests\Support\UpdateClientTestKit as Kit;

// A revoked version (matrix 5): the update server answers 410 `{"code":"release_revoked","version","fixed_in"}`. A typed
// outcome, never installed; «the installed version is revoked» is kept for the page and the alert bar until a newer
// version is installed. No network: a fake update server with a throwaway key.
(static function (): void {
    $pair = sodium_crypto_sign_keypair();
    $secret = sodium_crypto_sign_secretkey($pair);
    $public = base64_encode(sodium_crypto_sign_publickey($pair));
    $verifier = new ReleaseSignatureVerifier(['release.test' => $public]);
    $server = 'https://update.example.org/larena';
    $github = 'https://github.com/example/larena/releases';
    $root = Kit::temp('revoked');
    $downloads = $root . '/downloads';
    [$bytes, $signature] = Kit::release('1.3.0', $secret);
    $gone = static fn (string $version, ?string $fixed): array => [410, (string) json_encode(['code' => 'release_revoked', 'version' => $version, 'fixed_in' => $fixed])];
    $latest = [$server . '/latest/download/release.json' => [200, $bytes], $server . '/latest/download/release.json.sig' => [200, $signature]];

    try {
        // The answer is read strictly; a «fix» that is not newer says nothing.
        $answer = ReleaseRevoked::fromAnswer('{"code":"release_revoked","version":"1.2.0","fixed_in":"1.2.1"}');
        Kit::check($answer->getMessage() === 'update_release_revoked' && $answer->version === '1.2.0' && $answer->fixedIn === '1.2.1', 'A 410 names the version and its fix.');
        Kit::check(ReleaseRevoked::fromAnswer('{"code":"release_revoked","version":"1.2.0","fixed_in":null}')->fixedIn === null, 'No fix known.');
        Kit::check(ReleaseRevoked::fromAnswer('{"code":"release_revoked","version":"1.2.0","fixed_in":"1.1.0"}')->fixedIn === null, 'An older «fix» is ignored.');
        Kit::check(ReleaseRevoked::fromAnswer('not json', '1.3.0')->version === '1.3.0' && ReleaseRevoked::fromAnswer('{"code":"other","version":"9.9.9"}', '1.3.0')->version === '1.3.0', 'A bare 410 is about the version asked for.');
        Kit::check(ReleaseRevoked::fromAnswer('{"code":"release_revoked","version":"../x"}')->version === null, 'A malformed version is never read.');

        // A check: the installed version answers 410 → kept, with its fix.
        $state = RevokedReleaseState::forSite($root);
        $http = FakeUpdateHttpClient::serving($latest + [$server . '/download/v1.2.0/release.json' => $gone('1.2.0', '1.3.0')]);
        $source = new ReleaseSource($http, [$server], $downloads, $verifier, revocations: $state);
        $offer = $source->latest('1.2.0');
        Kit::check($offer?->release->version === '1.3.0', 'The check still offers the newer release.');
        Kit::check($source->revoked()?->version === '1.2.0' && $source->revoked()?->fixedIn === '1.3.0', 'The check says the installed version is revoked.');
        $kept = $state->current('1.2.0');
        Kit::check($kept !== null && $kept['fixed_in'] === '1.3.0' && $state->current('1.1.0') === null, 'The revocation is kept for that version only.');
        Kit::check(!is_dir($downloads), 'A check writes nothing to the downloads.');

        // A 410 about another version than the installed one marks nothing.
        $other = new RevokedReleaseState($root . '/other.json');
        $source = new ReleaseSource(FakeUpdateHttpClient::serving($latest + [$server . '/download/v1.2.0/release.json' => $gone('1.1.0', null)]), [$server], $downloads, $verifier, revocations: $other);
        $source->latest('1.2.0');
        Kit::check($source->revoked() === null && $other->current('1.2.0') === null, 'Another version\'s revocation is not the installed one\'s.');

        // github.com is never asked about the installed version, nor is any source without the state.
        $http = FakeUpdateHttpClient::serving([$github . '/latest/download/release.json' => [200, $bytes], $github . '/latest/download/release.json.sig' => [200, $signature]]);
        (new ReleaseSource($http, [$github], $downloads, $verifier, revocations: $other))->latest('1.2.0');
        Kit::check(count($http->requests) === 2, 'github.com is not asked about revocations.');
        $http = FakeUpdateHttpClient::serving($latest);
        (new ReleaseSource($http, [$server], $downloads, $verifier))->latest('1.2.0');
        Kit::check(count($http->requests) === 2, 'Without the state nothing more is asked.');

        // The probe failing never fails a check.
        $http = new FakeUpdateHttpClient(static fn ($request): array => str_contains($request->url, '/download/v1.2.0/') ? throw new RuntimeException('update_http_failed') : ($latest[$request->url] ?? [404, '']));
        Kit::check((new ReleaseSource($http, [$server], $downloads, $verifier, revocations: $other))->latest('1.2.0') !== null, 'A failed revocation question leaves the check standing.');

        // A download of a revoked version: a typed refusal before any write.
        $http = FakeUpdateHttpClient::serving([$server . '/download/v1.3.0/release.json' => $gone('1.3.0', '1.3.1')]);
        $source = new ReleaseSource($http, [$server], $downloads, $verifier);
        Kit::check(Kit::reason(static fn () => $source->download('1.3.0', '1.2.0')) === 'update_release_revoked' && $source->errors() === [$server => 'update_release_revoked'], 'A revoked version is not downloaded.');
        Kit::check($source->revoked()?->version === '1.3.0' && $source->revoked()?->fixedIn === '1.3.1' && !is_dir($downloads), 'The refusal names the version and its fix; nothing is written.');
        // A revoked archive (the description was still served): the same refusal, no file kept.
        [$b, $s, $archive] = Kit::release('1.3.0', $secret);
        $http = FakeUpdateHttpClient::serving([$server . '/download/v1.3.0/release.json' => [200, $b], $server . '/download/v1.3.0/release.json.sig' => [200, $s],
            $server . '/download/v1.3.0/larena-1.3.0.tar.gz' => $gone('1.3.0', null)]);
        $source = new ReleaseSource($http, [$server], $downloads, $verifier);
        Kit::check(Kit::reason(static fn () => $source->download('1.3.0', '1.2.0')) === 'update_release_revoked' && !is_file($downloads . '/1.3.0/larena-1.3.0.tar.gz'), 'A revoked archive is refused and not kept.');

        // The page and the alert bar.
        mkdir($root . '/releases/1.2.0', 0755, true);
        file_put_contents($root . '/releases/1.2.0/.larena-release', json_encode(['version' => '1.2.0']));
        symlink('releases/1.2.0', $root . '/current');
        $database = $root . '/site.sqlite';
        (new PDO('sqlite:' . $database))->exec(PdoUpdateExecutionStore::schema('sqlite'));
        $site = static fn (FakeUpdateHttpClient $http): Closure => static fn (): SiteUpdateClient => new SiteUpdateClient(
            ['trusted_keys' => ['release.test' => $public], 'site_root' => $root, 'sources' => [$server], 'download' => ['directory' => $downloads]],
            $root . '/releases/1.2.0', 'https://site.example.test', static fn (): PDO => new PDO('sqlite:' . $database), new MemoryLogger(), $http,
            new FakeArtisanRunner(static fn (): string => ''));
        $client = $site(FakeUpdateHttpClient::serving($latest + [$server . '/download/v1.2.0/release.json' => $gone('1.2.0', '1.3.0')]));
        $updates = new SiteUpdates($client, $root . '/storage/app/larena/update/last-check.json');
        Kit::check($updates->check()['reason'] === 'update_available', 'The check finds the newer release.');
        $view = $updates->overview();
        Kit::check(($view['revoked']['version'] ?? null) === '1.2.0' && $view['revoked']['fixed_in'] === '1.3.0' && $view['check']['status'] === 'available', 'The page knows the running version is revoked, and the update.');

        $loader = new ArrayLoader();
        $loader->addMessages('ru', 'updates', require dirname(__DIR__, 2) . '/resources/lang/ru/updates.php', 'larena-update-client');
        $translator = new Translator($loader, 'ru');
        $routes = static fn (string $name, array $parameters = []): string => '/admin/updates';
        $allowed = true;
        $bar = new UpdatesAdminAlertSource($client, $translator, static function (Request $request, string $operation) use (&$allowed): bool {
            return $allowed && $operation === 'update.integrity.read';
        }, $routes);
        $alerts = $bar->alerts(Request::create('/admin'));
        Kit::check(count($alerts) === 1 && $alerts[0]->id === UpdatesAdminAlertSource::REVOKED && $alerts[0]->level->value === 'danger' && !$alerts[0]->hideable(), 'The bar shows a danger that cannot be hidden.');
        Kit::check(str_contains($alerts[0]->text, 'Эта версия отозвана — обновите срочно') && str_contains($alerts[0]->text, 'Исправлено в 1.3.0') && $alerts[0]->action?->href === '/admin/updates', 'It says what and where to update: ' . $alerts[0]->text);
        $allowed = false;
        Kit::check($bar->alerts(Request::create('/admin')) === [], 'Only a person who may see the page sees it.');
        $allowed = true;

        // A newer installed version clears it.
        mkdir($root . '/releases/1.3.0', 0755, true);
        file_put_contents($root . '/releases/1.3.0/.larena-release', json_encode(['version' => '1.3.0']));
        unlink($root . '/current');
        symlink('releases/1.3.0', $root . '/current');
        Kit::check($updates->overview()['revoked'] === null && $bar->alerts(Request::create('/admin')) === [], 'After the update the banner is gone.');
        $after = new SiteUpdates($site(FakeUpdateHttpClient::serving($latest)), $root . '/storage/app/larena/update/last-check.json');
        $after->check();
        Kit::check(!is_file($root . '/storage/app/larena/update/revoked.json'), 'The next check removes the old mark.');

        // Words in both languages.
        foreach (['ru', 'en'] as $locale) {
            $words = require dirname(__DIR__, 2) . '/resources/lang/' . $locale . '/updates.php';
            foreach (['heading', 'text', 'fixed_in', 'action'] as $key) {
                Kit::check(is_string($words['revoked'][$key] ?? null), $locale . ' words for revoked.' . $key);
            }
            $reasons = (require dirname(__DIR__, 2) . '/resources/lang/' . $locale . '/integrity.php')['reasons'];
            Kit::check(is_string($reasons['update_release_revoked'] ?? null), $locale . ' words for update_release_revoked');
        }
    } finally {
        Kit::remove($root);
    }
    echo "RevokedReleaseTest passed.\n";
})();
