<?php

declare(strict_types=1);

require_once __DIR__ . '/../Support/UpdateClientFakes.php';

use Larena\UpdateClient\Laravel\SiteUpdateClient;
use Larena\UpdateClient\Release\ReleaseSignatureVerifier;
use Larena\UpdateClient\Release\TrustedKeyList;
use Larena\UpdateClient\Source\ReleaseSource;
use Larena\UpdateClient\Source\UpdateHttpRequest;
use Larena\UpdateClient\Tests\Support\FakeArtisanRunner;
use Larena\UpdateClient\Tests\Support\FakeUpdateHttpClient;
use Larena\UpdateClient\Tests\Support\MemoryLogger;
use Larena\UpdateClient\Tests\Support\UpdateClientTestKit as Kit;

// A refused download names its real reason (auditor msg-68c8decb, 2026-11-24), and a site or a test stand trusts its
// own release keys through LARENA_UPDATE_TRUSTED_KEYS. No network: every answer comes from a fake client; every key
// below is a throwaway pair made for this test.
(static function (): void {
    $pair = sodium_crypto_sign_keypair();
    $secret = sodium_crypto_sign_secretkey($pair);
    $public = base64_encode(sodium_crypto_sign_publickey($pair));
    $siteKey = 'lsk_' . bin2hex(random_bytes(24));
    $server = 'https://update.example.org/r/larena/stable';
    $github = 'https://github.com/example/larena/releases';
    $root = Kit::temp('refusal');
    $downloads = $root . '/downloads';
    [$bytes, $signature] = Kit::release('1.3.0', $secret);
    $base = $server . '/download/v1.3.0/';
    $verifier = new ReleaseSignatureVerifier(['release.test' => $public]);
    // The description is public; the archive answers with the given refusal.
    $refusing = static fn (int $status, string $body): FakeUpdateHttpClient => new FakeUpdateHttpClient(
        static fn (UpdateHttpRequest $request): array => match ($request->url) {
            $base . 'release.json' => [200, $bytes],
            $base . 'release.json.sig' => [200, $signature],
            default => [$status, $body],
        },
    );
    $source = static fn (FakeUpdateHttpClient $http, ?string $key, array $sources = [], string $dir = ''): ReleaseSource
        => new ReleaseSource($http, $sources === [] ? [$server] : $sources, $dir === '' ? $downloads : $dir, $verifier, siteKey: $key);
    $leaks = static fn (string $text): bool => str_contains($text, $siteKey) || str_contains($text, substr($siteKey, 0, 12));

    try {
        // The update server's refusal codes (larena-server-update: `{"error": "<code>"}`), with and without a key.
        foreach ([
            [403, '{"error":"license_required"}', $siteKey, 'update_license_required'],
            [403, '{"error":"license_expired"}', $siteKey, 'update_license_expired'],
            [403, '{"error":"license_revoked"}', $siteKey, 'update_license_revoked'],
            [403, '{"code":"license_required"}', $siteKey, 'update_license_required'],
            [401, '{"error":"site_key_required"}', null, 'update_site_key_required'],
            [401, '{"error":"site_key_required"}', $siteKey, 'update_site_key_rejected'],
            [401, '', null, 'update_site_key_required'],
            [401, 'not json', $siteKey, 'update_site_key_rejected'],
            [403, '', $siteKey, 'update_download_failed'],
            [403, 'not json', $siteKey, 'update_download_failed'],
            [403, '["license_required"]', $siteKey, 'update_download_failed'],
            [403, '{"error":"rate_limited"}', $siteKey, 'update_download_failed'],
            [500, '{"error":"license_required"}', $siteKey, 'update_download_failed'],
            [429, '{"error":"rate_limited"}', $siteKey, 'update_download_failed'],
        ] as [$status, $body, $key, $expected]) {
            $dir = $downloads . '-' . bin2hex(random_bytes(3));
            $from = $source($refusing($status, $body), $key, dir: $dir);
            $reason = Kit::reason(static fn () => $from->download('1.3.0', '1.2.0'));
            Kit::check($reason === $expected, "A $status with $body must be $expected, got $reason.");
            Kit::check($from->errors() === [$server => $expected], 'The source error must be the same reason.');
            Kit::check(!is_file($dir . '/1.3.0/larena-1.3.0.tar.gz'), 'A refused archive must leave no file.');
        }

        // The same refusals on the description itself (a check of the newest release).
        $http = new FakeUpdateHttpClient(static fn (): array => [403, '{"error":"license_required"}']);
        Kit::check(Kit::reason(static fn () => $source($http, $siteKey)->latest('1.2.0')) === 'update_license_required', 'A refused description must name the licence.');
        $http = new FakeUpdateHttpClient(static fn (): array => [401, '{"error":"site_key_required"}']);
        Kit::check(Kit::reason(static fn () => $source($http, null)->latest('1.2.0')) === 'update_site_key_required', 'A description without a key must name the key.');

        $http = new FakeUpdateHttpClient(static fn (): array => [403, '{"error":"license_required","pad":"' . str_repeat('a', 5000) . '"}']);
        Kit::check(Kit::reason(static fn () => $source($http, $siteKey)->latest('1.2.0')) === 'update_download_failed', 'A refusal over 4 KiB is not read.');

        // With several sources the licence or key reason wins over another source's failure.
        $http = new FakeUpdateHttpClient(static fn (UpdateHttpRequest $request): array => str_starts_with($request->url, $github) ? [404, ''] : [403, '{"error":"license_required"}']);
        $from = $source($http, $siteKey, [$github, $server]);
        Kit::check(Kit::reason(static fn () => $from->download('1.3.0', '1.2.0')) === 'update_license_required'
            && $from->errors() === [$github => 'update_release_not_found', $server => 'update_license_required'], 'The licence refusal must be the reason of the call.');

        // Never the key, never the server's body: a body echoing the key is not a known code.
        $echo = '{"error":"' . $siteKey . '","detail":"' . $siteKey . '"}';
        $previous = ini_set('zend.exception_ignore_args', '0');
        try {
            foreach ([403, 401] as $status) {
                $from = $source($refusing($status, $echo), $siteKey);
                try {
                    $from->download('1.3.0', '1.2.0');
                    Kit::check(false, 'A refused download must fail.');
                } catch (RuntimeException $failure) {
                    Kit::check(!$leaks($failure->getMessage()) && !$leaks((string) $failure) && !$leaks((string) json_encode($from->errors())), 'A refusal must not repeat the key or the body.');
                    Kit::check(in_array($failure->getMessage(), ['update_download_failed', 'update_site_key_rejected'], true), 'An unknown code must stay generic.');
                }
            }
        } finally {
            ini_set('zend.exception_ignore_args', $previous === false ? '1' : $previous);
        }

        // Words for every new reason, in every language the package carries.
        foreach (glob(dirname(__DIR__, 2) . '/resources/lang/*/integrity.php') ?: [] as $file) {
            $reasons = (require $file)['reasons'] ?? [];
            foreach (['update_license_required', 'update_license_expired', 'update_license_revoked', 'update_site_key_required', 'update_site_key_rejected', 'update_download_failed'] as $code) {
                Kit::check(is_string($reasons[$code] ?? null) && $reasons[$code] !== '', "Words for $code must be in $file.");
            }
        }

        // LARENA_UPDATE_TRUSTED_KEYS: `id=base64,id2=base64`, strictly.
        $second = base64_encode(sodium_crypto_sign_publickey(sodium_crypto_sign_keypair()));
        Kit::check(TrustedKeyList::parse("stand.release=$public, second-key=$second") === ['stand.release' => $public, 'second-key' => $second], 'Well-formed pairs must be trusted.');
        foreach ([null, '', 42, ',', 'no-equals-sign', "=$public", "Upper=$public", "bad id=$public", 'short=' . base64_encode(random_bytes(31)),
            'long=' . base64_encode(random_bytes(33)), 'notbase64=' . str_repeat('*', 44), 'loose=' . rtrim($public, '=') . ' x', "twice=$public,twice=$second"] as $bad) {
            Kit::check(TrustedKeyList::parse($bad) === [], 'A malformed value must be ignored: ' . (is_string($bad) ? strlen($bad) . ' bytes' : gettype($bad)) . '.');
        }
        Kit::check(TrustedKeyList::parse("broken,ok.key=$public,short=AAAA") === ['ok.key' => $public], 'Malformed pairs are skipped, good ones kept.');

        // The package configuration reads it from the environment.
        $set = static function (?string $value): void {
            if ($value === null) {
                putenv('LARENA_UPDATE_TRUSTED_KEYS');
                unset($_ENV['LARENA_UPDATE_TRUSTED_KEYS'], $_SERVER['LARENA_UPDATE_TRUSTED_KEYS']);

                return;
            }
            putenv('LARENA_UPDATE_TRUSTED_KEYS=' . $value);
            $_ENV['LARENA_UPDATE_TRUSTED_KEYS'] = $_SERVER['LARENA_UPDATE_TRUSTED_KEYS'] = $value;
        };
        $configFile = dirname(__DIR__, 2) . '/config/update-client.php';
        try {
            $set("stand.release=$public,broken");
            $config = require $configFile;
            Kit::check($config['trusted_keys'] === ['stand.release' => $public], 'The configuration must trust the environment keys only.');
            $set(null);
            Kit::check((require $configFile)['trusted_keys'] === [], 'No variable means no key.');
        } finally {
            $set(null);
        }

        // Environment keys are trusted for a release, next to the distribution's; a revoked id still wins.
        [$standBytes, $standSig] = Kit::release('1.3.0', $secret, 'stand.release');
        $serving = FakeUpdateHttpClient::serving([
            $server . '/latest/download/release.json' => [200, $standBytes],
            $server . '/latest/download/release.json.sig' => [200, $standSig],
        ]);
        $client = static fn (array $config): SiteUpdateClient => new SiteUpdateClient(
            SiteUpdateClient::withReleaseKeys($config + ['sources' => [$server], 'site_root' => $root, 'download' => ['directory' => $downloads]], ['larena.release' => $second]),
            $root, 'https://site.example.test', static fn (): PDO => new PDO('sqlite::memory:'), new MemoryLogger(), $serving,
            new FakeArtisanRunner(static fn (): string => ''),
        );
        $trusted = $client(['trusted_keys' => TrustedKeyList::parse("stand.release=$public")]);
        Kit::check($trusted->trustedKeyIds() === ['stand.release', 'larena.release'], 'Environment and distribution keys must both be trusted.');
        Kit::check($trusted->source()->latest('1.2.0')?->release->keyId === 'stand.release', 'A release signed by an environment key must be taken.');
        $revoked = $client(['trusted_keys' => TrustedKeyList::parse("stand.release=$public"), 'revoked_key_ids' => ['stand.release']]);
        Kit::check(Kit::reason(static fn () => $revoked->source()->latest('1.2.0')) === 'release_key_revoked', 'A revoked id must win over an environment key.');
        $none = $client(['trusted_keys' => TrustedKeyList::parse('stand.release=AAAA')]);
        Kit::check(Kit::reason(static fn () => $none->source()->latest('1.2.0')) === 'release_key_untrusted', 'A malformed environment key must not be trusted.');
    } finally {
        Kit::remove($root);
        foreach (glob($downloads . '-*') ?: [] as $dir) {
            Kit::remove($dir);
        }
    }
})();
