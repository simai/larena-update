<?php

declare(strict_types=1);

require_once __DIR__ . '/../Support/UpdateClientFakes.php';

use Larena\UpdateClient\Release\ReleaseSignatureVerifier;
use Larena\UpdateClient\Source\ReleaseListFreshness;
use Larena\UpdateClient\Source\ReleaseListState;
use Larena\UpdateClient\Source\ReleaseSource;
use Larena\UpdateClient\Tests\Support\FakeUpdateHttpClient;
use Larena\UpdateClient\Tests\Support\UpdateClientTestKit as Kit;

// Freshness of the product release description (matrix 3): a signed `list_version` that only grows and `expires_at`,
// required from the update server, optional on github.com and a folder; refused before any write. No network.
(static function (): void {
    $secret = sodium_crypto_sign_secretkey($pair = sodium_crypto_sign_keypair());
    $verifier = new ReleaseSignatureVerifier(['release.test' => base64_encode(sodium_crypto_sign_publickey($pair))]);
    $server = 'https://update.example.org/larena';
    $github = 'https://github.com/example/larena/releases';
    $root = Kit::temp('release-list');
    $downloads = $root . '/downloads';
    $now = new DateTimeImmutable('2026-11-28T12:00:00+00:00');
    $clock = static fn (): DateTimeImmutable => $now;
    $serve = static function (string $source, string $version, ?array $list, string $product = 'larena') use ($secret): FakeUpdateHttpClient {
        [$bytes, $signature] = Kit::release($version, $secret, product: $product, list: $list);

        return FakeUpdateHttpClient::serving([
            $source . '/latest/download/release.json' => [200, $bytes],
            $source . '/latest/download/release.json.sig' => [200, $signature],
        ]);
    };
    $fresh = static fn (int $version): array => ['list_version' => $version, 'expires_at' => '2026-11-29T12:00:00+00:00'];

    try {
        $state = ReleaseListState::forSite($root);
        $source = static fn (FakeUpdateHttpClient $http, string $at = 'https://update.example.org/larena', ?ReleaseSignatureVerifier $with = null): ReleaseSource
            => new ReleaseSource($http, [$at], $downloads, $with ?? $verifier, lists: $state, clock: $clock);

        // The rule in one place: the update server must carry the fields, github.com and a folder may omit them.
        Kit::check(ReleaseListFreshness::required($server) && !ReleaseListFreshness::required($github) && !ReleaseListFreshness::required('file:///srv/larena'), 'Only the update server must carry the fields.');

        // The update server without the fields, with one of them, or with a malformed one: invalid.
        foreach ([null, ['list_version' => 3], ['expires_at' => '2026-11-29T12:00:00+00:00'], ['list_version' => 0, 'expires_at' => '2026-11-29T12:00:00+00:00'],
            ['list_version' => '3', 'expires_at' => '2026-11-29T12:00:00+00:00'], ['list_version' => 3, 'expires_at' => '2026-11-29 12:00:00'],
            ['list_version' => 3, 'expires_at' => '2026-11-29T12:00:00Z']] as $list) {
            $from = $source($serve($server, '1.3.0', $list));
            Kit::check(Kit::reason(static fn () => $from->latest('1.2.0')) === 'update_release_list_invalid' && $from->errors() === [$server => 'update_release_list_invalid'],
                'A description without valid freshness fields from the update server is invalid: ' . json_encode($list));
        }

        // Expired: stale, also when it would not be newer (a frozen answer must not say «up to date»).
        $from = $source($serve($server, '1.3.0', ['list_version' => 3, 'expires_at' => '2026-11-28T12:00:00+00:00']));
        Kit::check(Kit::reason(static fn () => $from->latest('1.2.0')) === 'update_release_list_stale', 'An expired description is stale.');
        $from = $source($serve($server, '1.2.0', ['list_version' => 3, 'expires_at' => '2026-11-01T00:00:00+00:00']));
        Kit::check(Kit::reason(static fn () => $from->latest('1.2.0')) === 'update_release_list_stale', 'An expired «not newer» answer is stale, not «up to date».');
        Kit::check($state->seen('larena', 'stable') === 0 && !is_dir($downloads), 'A refused description is not remembered and writes nothing.');

        // Fresh: accepted and its number kept; the same number again is fine; a lower one is a replay.
        $offer = $source($serve($server, '1.3.0', $fresh(5)))->latest('1.2.0');
        Kit::check($offer !== null && $offer->release->version === '1.3.0' && $state->seen('larena', 'stable') === 5, 'A fresh description is accepted and its number kept.');
        Kit::check($source($serve($server, '1.3.0', $fresh(5)))->latest('1.2.0') !== null, 'The same number again is accepted.');
        $from = $source($serve($server, '1.3.0', $fresh(4)));
        Kit::check(Kit::reason(static fn () => $from->latest('1.2.0')) === 'update_release_list_stale' && $state->seen('larena', 'stable') === 5, 'A lower number is a replayed older answer.');
        Kit::check($source($serve($server, '1.2.0', $fresh(7)))->latest('1.2.0') === null && $state->seen('larena', 'stable') === 7, 'A fresh «not newer» answer is remembered too.');
        Kit::check($state->seen('larena', 'test') === 0, 'Numbers are kept per channel.');

        // Another product's signed description cannot raise this product's number.
        $from = $source($serve($server, '1.3.0', $fresh(900), 'larena-panel'));
        Kit::check(Kit::reason(static fn () => $from->latest('1.2.0')) === 'release_product_differs' && $state->seen('larena', 'stable') === 7 && $state->seen('larena-panel', 'stable') === 0, 'A foreign product is never remembered.');

        // A bad signature is refused before freshness is read.
        $foreign = sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair());
        [$bytes, $signature] = Kit::release('1.3.0', $foreign, list: $fresh(99));
        $from = $source(FakeUpdateHttpClient::serving([$server . '/latest/download/release.json' => [200, $bytes], $server . '/latest/download/release.json.sig' => [200, $signature]]));
        Kit::check(Kit::reason(static fn () => $from->latest('1.2.0')) === 'release_signature_mismatch' && $state->seen('larena', 'stable') === 7, 'An unsigned number is never read.');

        // github.com may omit both; if it carries them they are checked as anywhere.
        $offer = $source($serve($github, '1.3.0', null), $github)->latest('1.2.0');
        Kit::check($offer !== null && $offer->release->version === '1.3.0', 'A GitHub release without the fields is accepted.');
        $from = $source($serve($github, '1.3.0', $fresh(2)), $github);
        Kit::check(Kit::reason(static fn () => $from->latest('1.2.0')) === 'update_release_list_stale', 'Fields GitHub does carry are checked too.');

        // A folder on this server may omit them.
        $folder = $root . '/offline';
        mkdir($folder . '/download/v1.3.0', 0755, true);
        [$bytes, $signature] = Kit::release('1.3.0', $secret, list: null);
        file_put_contents($folder . '/download/v1.3.0/release.json', $bytes);
        file_put_contents($folder . '/download/v1.3.0/release.json.sig', $signature);
        Kit::check($source(FakeUpdateHttpClient::serving([]), 'file://' . $folder)->latest('1.2.0')?->release->version === '1.3.0', 'A folder release without the fields is accepted.');

        // The stored number cannot be read: refused, never «no number seen».
        file_put_contents($root . '/storage/app/larena/update/release-list.json', '{broken');
        $from = $source($serve($server, '1.3.0', $fresh(9)));
        Kit::check(Kit::reason(static fn () => $from->latest('1.2.0')) === 'update_release_state_unreadable', 'An unreadable state is refused.');

        // Words for every new reason, in every language.
        foreach (glob(dirname(__DIR__, 2) . '/resources/lang/*/integrity.php') ?: [] as $file) {
            $reasons = (require $file)['reasons'] ?? [];
            foreach (['update_release_list_stale', 'update_release_list_invalid', 'update_release_state_unreadable', 'update_release_state_unwritable'] as $code) {
                Kit::check(is_string($reasons[$code] ?? null) && $reasons[$code] !== '', "Words for $code must be in $file.");
            }
        }
    } finally {
        Kit::remove($root);
    }
    echo "ReleaseListFreshnessTest passed.\n";
})();
