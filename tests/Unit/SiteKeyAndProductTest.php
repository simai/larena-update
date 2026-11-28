<?php

declare(strict_types=1);

require_once __DIR__ . '/../Support/UpdateClientFakes.php';

use Larena\UpdateClient\Laravel\SiteUpdateClient;
use Larena\UpdateClient\Release\ReleaseSignatureVerifier;
use Larena\UpdateClient\Source\ReleaseSource;
use Larena\UpdateClient\Source\UpdateHttpRequest;
use Larena\UpdateClient\Tests\Support\FakeArtisanRunner;
use Larena\UpdateClient\Tests\Support\FakeUpdateHttpClient;
use Larena\UpdateClient\Tests\Support\MemoryLogger;
use Larena\UpdateClient\Tests\Support\UpdateClientTestKit as Kit;

// The site key in the Authorization header, the entitlement call and the product setting. No network: every answer
// comes from a fake client. The key below is a throwaway value made for this test.
(static function (): void {
    $pair = sodium_crypto_sign_keypair();
    $secret = sodium_crypto_sign_secretkey($pair);
    $public = base64_encode(sodium_crypto_sign_publickey($pair));
    $siteKey = 'sk-' . bin2hex(random_bytes(24));
    $server = 'https://update.example.org/releases';
    $github = 'https://github.com/example/larena/releases';
    $root = Kit::temp('site-key');
    $downloads = $root . '/downloads';
    [$larena, $larenaSig] = Kit::release('1.3.0', $secret);
    [$panel, $panelSig] = Kit::release('1.3.0', $secret, product: 'larena-panel');
    $latest = static fn (string $base, string $json, string $sig): array => [
        $base . '/latest/download/release.json' => [200, $json],
        $base . '/latest/download/release.json.sig' => [200, $sig],
    ];
    $client = static fn (array $config, FakeUpdateHttpClient $http, ?MemoryLogger $logger = null): SiteUpdateClient => new SiteUpdateClient(
        $config + ['trusted_keys' => ['release.test' => $public], 'site_root' => $root, 'download' => ['directory' => $downloads]],
        $root, 'https://site.example.test', static fn (): PDO => new PDO('sqlite::memory:'), $logger ?? new MemoryLogger(), $http,
        new FakeArtisanRunner(static fn (): string => ''),
    );
    $leaks = static fn (string $text): bool => str_contains($text, $siteKey) || str_contains($text, substr($siteKey, 0, 12));

    try {
        // Product: `larena` by default, `larena-panel` for the panel; each refuses the other's release.
        $default = new ReleaseSignatureVerifier(['release.test' => $public]);
        Kit::check($default->verify($larena, $larenaSig, '1.2.0')->product === 'larena', 'The default product must be larena.');
        Kit::check(Kit::reason(static fn () => $default->verify($panel, $panelSig, '1.2.0')) === 'release_product_differs', 'A Larena site must refuse a panel release.');
        $panelVerifier = new ReleaseSignatureVerifier(['release.test' => $public], [], 'larena-panel');
        Kit::check($panelVerifier->verify($panel, $panelSig, '1.2.0')->product === 'larena-panel', 'The panel must take its own release.');
        Kit::check(Kit::reason(static fn () => $panelVerifier->verify($larena, $larenaSig, '1.2.0')) === 'release_product_differs', 'The panel must refuse a Larena release.');

        // The product setting through the site client.
        $serving = FakeUpdateHttpClient::serving($latest($server, $larena, $larenaSig));
        Kit::check($client(['sources' => [$server]], $serving)->product() === 'larena', 'No product setting must mean larena.');
        Kit::check($client(['sources' => [$server], 'product' => null], $serving)->source()->latest('1.2.0')?->release->product === 'larena', 'An empty setting must still take larena releases.');
        $panelSite = $client(['sources' => [$server], 'product' => 'larena-panel'], FakeUpdateHttpClient::serving($latest($server, $panel, $panelSig)));
        Kit::check($panelSite->product() === 'larena-panel' && $panelSite->source()->latest('1.2.0')?->release->product === 'larena-panel', 'LARENA_UPDATE_PRODUCT=larena-panel must take panel releases.');
        $wrong = $client(['sources' => [$server], 'product' => 'larena-panel'], FakeUpdateHttpClient::serving($latest($server, $larena, $larenaSig)));
        Kit::check(Kit::reason(static fn () => $wrong->source()->latest('1.2.0')) === 'release_product_differs', 'The panel must refuse a Larena release from its source.');
        Kit::check(Kit::reason(static fn () => $client(['product' => "larena\npanel"], $serving)->verifier()) === 'update_product_invalid', 'A malformed product must be refused.');

        // Site key: in the Authorization header of every request to the update server, never in an address.
        $http = FakeUpdateHttpClient::serving($latest($server, $larena, $larenaSig));
        $client(['sources' => [$server], 'site_key' => $siteKey], $http)->source()->latest('1.2.0');
        Kit::check(count($http->requests) === 3 && str_ends_with($http->requests[2]->url, '/download/v1.2.0/release.json'), 'A check reads the description, its signature and whether the installed version is revoked.');
        foreach ($http->requests as $request) {
            Kit::check(($request->headers['Authorization'] ?? null) === 'Bearer ' . $siteKey, 'Every request to the update server must carry the site key.');
            Kit::check(!$leaks($request->url), 'The site key must never be in an address.');
        }

        // Without the setting (or with an empty one) requests go as before, without any header.
        foreach ([[], ['site_key' => ''], ['site_key' => null]] as $config) {
            $http = FakeUpdateHttpClient::serving($latest($server, $larena, $larenaSig));
            $client(['sources' => [$server]] + $config, $http)->source()->latest('1.2.0');
            Kit::check(array_map(static fn (UpdateHttpRequest $request): array => $request->headers, $http->requests) === [[], [], []], 'Without a site key requests must carry no header.');
        }

        // github.com public releases never see the key; the update server after it still does.
        $http = FakeUpdateHttpClient::serving($latest($server, $larena, $larenaSig));
        $source = $client(['sources' => [$github, $server], 'site_key' => $siteKey], $http)->source();
        $source->latest('1.2.0');
        foreach ($http->requests as $request) {
            $toGitHub = str_starts_with($request->url, $github);
            Kit::check($toGitHub ? $request->headers === [] : ($request->headers['Authorization'] ?? null) === 'Bearer ' . $siteKey, 'Only the update server may receive the site key.');
        }

        // A malformed key is refused without being repeated.
        $broken = "bad\nkey-" . $siteKey;
        $reason = Kit::reason(static fn () => new ReleaseSource(FakeUpdateHttpClient::serving([]), [$server], $downloads, $default, siteKey: $broken));
        Kit::check($reason === 'update_site_key_invalid' && !$leaks($reason), 'A malformed key must be refused by a reason code only.');

        // A failing request: the error, the reasons kept, the full exception text and the log never contain the key.
        $previous = ini_set('zend.exception_ignore_args', '0');
        try {
            $logger = new MemoryLogger();
            foreach ([
                FakeUpdateHttpClient::serving([]),
                new FakeUpdateHttpClient(static fn (): array => [401, 'site key not accepted']),
                new FakeUpdateHttpClient(static fn () => throw new RuntimeException('update_http_failed')),
            ] as $http) {
                $site = $client(['sources' => [$server], 'site_key' => $siteKey], $http, $logger);
                $source = $site->source();
                foreach ([static fn () => $source->latest('1.2.0'), static fn () => $source->download('1.3.0', '1.2.0'), static fn () => $source->entitlement()] as $call) {
                    try {
                        $call();
                        Kit::check(false, 'A failing source must fail.');
                    } catch (RuntimeException $failure) {
                        // What the site does with a failure: it logs it with the exception.
                        $logger->error($failure->getMessage(), ['exception' => $failure, 'source_errors' => $source->errors()]);
                        Kit::check(!$leaks($failure->getMessage()) && !$leaks((string) $failure) && !$leaks((string) json_encode($source->errors())), 'A failed request must not show the site key.');
                    }
                }
                Kit::check(($http->requests[0]->headers['Authorization'] ?? null) === 'Bearer ' . $siteKey, 'The failing request must still have carried the key.');
            }
            Kit::check(count($logger->records) === 9, 'Every failure must be logged.');
            foreach ($logger->records as $record) {
                $text = $record['message'] . ' ' . (string) $record['context']['exception'] . ' ' . print_r($record['context']['source_errors'], true);
                Kit::check(!$leaks($text), 'The log of a failed request must not contain the site key.');
            }
        } finally {
            ini_set('zend.exception_ignore_args', $previous === false ? '1' : $previous);
        }

        // Entitlement: GET <source>/entitlement with the key, the raw answer back, only schema_version checked.
        $answer = '{"schema":"larena.entitlement","schema_version":1,"site":"s-1","products":{"larena":{"until":"2027-12-01"}}}';
        $http = new FakeUpdateHttpClient(static fn (UpdateHttpRequest $request): array => $request->url === $server . '/entitlement' ? [200, $answer] : [404, '']);
        $source = $client(['sources' => [$github, $server], 'site_key' => $siteKey], $http)->source();
        Kit::check($source->entitlement() === $answer, 'The entitlement answer must come back exactly as sent.');
        Kit::check($http->urls() === ['GET ' . $server . '/entitlement'] && ($http->requests[0]->headers['Authorization'] ?? null) === 'Bearer ' . $siteKey
            && !$http->requests[0]->followRedirects, 'The entitlement is asked once, of the update server, with the key and no redirect.');
        foreach (['{"schema_version":2}' => 'update_entitlement_invalid', '[1]' => 'update_entitlement_invalid', 'not json' => 'update_entitlement_invalid'] as $body => $expected) {
            $bad = $client(['sources' => [$server], 'site_key' => $siteKey], new FakeUpdateHttpClient(static fn (): array => [200, $body]))->source();
            Kit::check(Kit::reason(static fn () => $bad->entitlement()) === $expected, 'An answer that is not schema_version 1 must be refused.');
        }
        foreach ([401 => 'update_entitlement_refused', 403 => 'update_entitlement_refused', 404 => 'update_entitlement_not_found', 500 => 'update_entitlement_failed'] as $status => $expected) {
            $bad = $client(['sources' => [$server]], new FakeUpdateHttpClient(static fn (): array => [$status, '']))->source();
            Kit::check(Kit::reason(static fn () => $bad->entitlement()) === $expected, 'A refused entitlement must give its reason.');
        }
        $http = new FakeUpdateHttpClient(static fn (): array => [401, '']);
        Kit::check(Kit::reason(static fn () => $client(['sources' => [$server]], $http)->source()->entitlement()) === 'update_entitlement_refused' && $http->requests[0]->headers === [], 'Without a key the entitlement is asked without a header.');
        Kit::check(Kit::reason(static fn () => $client(['sources' => [$github, 'file://' . $root]], FakeUpdateHttpClient::serving([]))->source()->entitlement()) === 'update_entitlement_source_missing', 'GitHub and folders have no entitlement.');
    } finally {
        Kit::remove($root);
    }
})();
