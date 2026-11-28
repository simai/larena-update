<?php

declare(strict_types=1);

require_once __DIR__ . '/../Support/UpdateClientFakes.php';

use Larena\UpdateClient\Release\ReleaseSignatureVerifier;
use Larena\UpdateClient\Source\ReleaseIndex;
use Larena\UpdateClient\Source\ReleaseListState;
use Larena\UpdateClient\Source\ReleaseSource;
use Larena\UpdateClient\Tests\Support\FakeUpdateHttpClient;
use Larena\UpdateClient\Tests\Support\UpdateClientTestKit as Kit;

// The update server's signed channel index (matrix 3): `index.json` + `index.json.sig` with `list_version` (only grows)
// and `expires_at`, naming the newest release by version and sha256; releases stay signed once. Refused before any
// write. github.com and folders have no index. No network.
(static function (): void {
    $secret = sodium_crypto_sign_secretkey($pair = sodium_crypto_sign_keypair());
    $verifier = new ReleaseSignatureVerifier(['release.test' => base64_encode(sodium_crypto_sign_publickey($pair))]);
    $server = 'https://update.example.org/larena';
    $github = 'https://github.com/example/larena/releases';
    $root = Kit::temp('release-index');
    $downloads = $root . '/downloads';
    $now = new DateTimeImmutable('2026-11-28T12:00:00+00:00');
    $clock = static fn (): DateTimeImmutable => $now;
    $tomorrow = '2026-11-29T12:00:00+00:00';
    [$release, $releaseSig] = Kit::release('1.3.0', $secret);
    $serve = static fn (array $fields, string $bytes = '', string $sig = ''): FakeUpdateHttpClient
        => FakeUpdateHttpClient::serving(Kit::server($server, $bytes !== '' ? $bytes : $release, $sig !== '' ? $sig : $releaseSig, $secret, fields: $fields + ['expires_at' => $tomorrow]));

    try {
        $state = ReleaseListState::forSite($root);
        $source = static fn (FakeUpdateHttpClient $http, string $at = 'https://update.example.org/larena'): ReleaseSource
            => new ReleaseSource($http, [$at], $downloads, $verifier, lists: $state, clock: $clock);

        // The rule in one place: the update server has an index, github.com and a folder do not.
        Kit::check(ReleaseIndex::required($server) && !ReleaseIndex::required($github) && !ReleaseIndex::required('file:///srv/larena'), 'Only the update server has an index.');

        // A check reads the index, then the release it names; nothing is written to the downloads.
        $http = $serve(['list_version' => 5]);
        $offer = $source($http)->latest('1.2.0');
        Kit::check($offer?->release->version === '1.3.0' && $state->seen('larena', 'stable') === 5, 'A fresh index is accepted and its number kept.');
        Kit::check($http->urls() === ['GET ' . $server . '/index.json', 'GET ' . $server . '/index.json.sig', 'GET ' . $server . '/download/v1.3.0/release.json', 'GET ' . $server . '/download/v1.3.0/release.json.sig'], 'Index first, then the exact release.');
        Kit::check(!is_dir($downloads), 'A check writes nothing.');

        // Missing or malformed: invalid. Another product or channel, signed_by not the signer: invalid.
        $missing = $source(FakeUpdateHttpClient::serving([]));
        Kit::check(Kit::reason(static fn () => $missing->latest('1.2.0')) === 'update_release_list_invalid', 'An update server without an index is invalid.');
        foreach ([['list_version' => 0], ['list_version' => '6'], ['expires_at' => '2026-11-29 12:00:00'], ['expires_at' => '2026-11-29T12:00:00Z'],
            ['schema' => 'larena.release'], ['product' => 'larena-panel'], ['channel' => 'test'], ['signed_by' => 'release.other'],
            ['latest' => ['version' => '1.3', 'release_sha256' => str_repeat('a', 64)]], ['latest' => ['version' => '1.3.0']],
            ['revoked' => [['version' => 'x']]], ['revoked' => 'none']] as $fields) {
            $from = $source($serve($fields + ['list_version' => 9]));
            Kit::check(Kit::reason(static fn () => $from->latest('1.2.0')) === 'update_release_list_invalid', 'A malformed index is invalid: ' . json_encode($fields));
        }
        Kit::check($state->seen('larena', 'stable') === 5, 'A refused index is not remembered.');

        // Expired or lower: stale, also when the index would say «not newer» (a frozen answer is not «up to date»).
        $from = $source($serve(['list_version' => 6, 'expires_at' => '2026-11-28T12:00:00+00:00']));
        Kit::check(Kit::reason(static fn () => $from->latest('1.2.0')) === 'update_release_list_stale', 'An expired index is stale.');
        $from = $source($serve(['list_version' => 4]));
        Kit::check(Kit::reason(static fn () => $from->latest('1.2.0')) === 'update_release_list_stale', 'A lower number is a replayed older index.');
        $from = $source($serve(['list_version' => 4]));
        Kit::check(Kit::reason(static fn () => $from->latest('1.3.0')) === 'update_release_list_stale', 'A replayed index never says «up to date».');
        Kit::check($source($serve(['list_version' => 5]))->latest('1.2.0') !== null, 'The same number again is accepted.');
        $http = $serve(['list_version' => 7]);
        Kit::check($source($http)->latest('1.3.0') === null && $state->seen('larena', 'stable') === 7 && count($http->requests) === 2, 'A fresh «not newer» index is remembered; the release is not fetched.');
        Kit::check($state->seen('larena', 'test') === 0, 'Numbers are kept per channel.');

        // The release must be the one the index names.
        [$other, $otherSig] = Kit::release('1.3.0', $secret, minimum: '1.1.0');
        $from = $source(FakeUpdateHttpClient::serving(array_replace(Kit::server($server, $release, $releaseSig, $secret, fields: ['list_version' => 8, 'expires_at' => $tomorrow]),
            [$server . '/download/v1.3.0/release.json' => [200, $other], $server . '/download/v1.3.0/release.json.sig' => [200, $otherSig]])));
        Kit::check(Kit::reason(static fn () => $from->latest('1.2.0')) === 'update_release_index_mismatch', 'A release other than the indexed one is refused.');

        // A bad signature keeps its reason; another product's index never raises this product's number.
        $foreign = sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair());
        $from = $source(FakeUpdateHttpClient::serving(Kit::server($server, $release, $releaseSig, $foreign, fields: ['list_version' => 99, 'expires_at' => $tomorrow])));
        Kit::check(Kit::reason(static fn () => $from->latest('1.2.0')) === 'release_signature_mismatch' && $state->seen('larena', 'stable') === 8, 'An unsigned number is never read.');

        // github.com and a folder: no index, read as before.
        $http = FakeUpdateHttpClient::serving([$github . '/latest/download/release.json' => [200, $release], $github . '/latest/download/release.json.sig' => [200, $releaseSig]]);
        Kit::check($source($http, $github)->latest('1.2.0')?->release->version === '1.3.0' && count($http->requests) === 2, 'GitHub has no index.');
        $folder = $root . '/offline';
        mkdir($folder . '/download/v1.3.0', 0755, true);
        file_put_contents($folder . '/download/v1.3.0/release.json', $release);
        file_put_contents($folder . '/download/v1.3.0/release.json.sig', $releaseSig);
        Kit::check($source(FakeUpdateHttpClient::serving([]), 'file://' . $folder)->latest('1.2.0')?->release->version === '1.3.0', 'A folder has no index.');

        // The stored number cannot be read: refused, never «no number seen».
        file_put_contents($root . '/storage/app/larena/update/release-list.json', '{broken');
        $from = $source($serve(['list_version' => 9]));
        Kit::check(Kit::reason(static fn () => $from->latest('1.2.0')) === 'update_release_state_unreadable', 'An unreadable state is refused.');

        // Words for every new reason, in every language.
        foreach (glob(dirname(__DIR__, 2) . '/resources/lang/*/integrity.php') ?: [] as $file) {
            $reasons = (require $file)['reasons'] ?? [];
            foreach (['update_release_list_stale', 'update_release_list_invalid', 'update_release_index_mismatch', 'update_release_state_unreadable', 'update_release_state_unwritable'] as $code) {
                Kit::check(is_string($reasons[$code] ?? null) && $reasons[$code] !== '', "Words for $code must be in $file.");
            }
        }
    } finally {
        Kit::remove($root);
    }
    echo "ReleaseIndexTest passed.\n";
})();
