<?php

declare(strict_types=1);

require_once __DIR__ . '/../Support/UpdateClientFakes.php';

use Larena\UpdateClient\Laravel\SiteUpdateClient;
use Larena\UpdateClient\Release\ReleaseKeyList;
use Larena\UpdateClient\Release\ReleaseKeyState;
use Larena\UpdateClient\Tests\Support\FakeArtisanRunner;
use Larena\UpdateClient\Tests\Support\FakeUpdateHttpClient;
use Larena\UpdateClient\Tests\Support\MemoryLogger;
use Larena\UpdateClient\Tests\Support\UpdateClientTestKit as Kit;

// Release key rotation «the old key signs the new one» and a signed revocation list (matrix 6, 44): `keys.json` signed
// by a key the site already trusts; accepted lists kept as a re-checked chain over the shipped keys; revoked ids win.
// Every key is a throwaway pair made here; no real key.
(static function (): void {
    $pair = static fn (): array => [sodium_crypto_sign_secretkey($k = sodium_crypto_sign_keypair()), base64_encode(sodium_crypto_sign_publickey($k))];
    [$oldSecret, $oldPublic] = $pair();
    [$newSecret, $newPublic] = $pair();
    [$thirdSecret, $thirdPublic] = $pair();
    [$strangerSecret, $strangerPublic] = $pair();
    $server = 'https://update.example.org/larena';
    $root = Kit::temp('key-rotation');
    mkdir($root . '/releases/1.2.0', 0755, true);
    file_put_contents($root . '/releases/1.2.0/.larena-release', json_encode(['version' => '1.2.0']));
    symlink('releases/1.2.0', $root . '/current');
    $sign = static fn (string $bytes, string $secret, string $id): string => (string) json_encode(['key_id' => $id, 'signature' => base64_encode(sodium_crypto_sign_detached($bytes, $secret))]);
    $keys = static function (int $version, array $keys, array $revoked, string $secret, string $id, string $expires = '2099-01-01T00:00:00+00:00', ?string $signedBy = null) use ($sign): array {
        $bytes = (string) json_encode(['schema' => 'larena.release-keys', 'list_version' => $version, 'expires_at' => $expires, 'signed_by' => $signedBy ?? $id,
            'keys' => array_map(static fn (array $key): array => ['id' => $key[0], 'public_key' => $key[1], 'not_before' => $key[2] ?? '2026-01-01T00:00:00+00:00'], $keys),
            'revoked' => $revoked], JSON_UNESCAPED_SLASHES);

        return [$bytes, $sign($bytes, $secret, $id)];
    };
    // What the update server serves: the key list (or none) and the newest release signed by the given key.
    $served = [];
    $http = new FakeUpdateHttpClient(static function ($request) use (&$served): array {
        return $served[$request->url] ?? [404, 'not found'];
    });
    $serve = static function (?array $list, string $releaseSecret, string $releaseKey) use (&$served, $server): void {
        [$bytes, $signature] = Kit::release('1.3.0', $releaseSecret, $releaseKey);
        // The index is signed by the same key as the release: both must be trusted.
        $served = Kit::server($server, $bytes, $signature, $releaseSecret, $releaseKey);
        if ($list !== null) {
            $served[$server . '/keys.json'] = [200, $list[0]];
            $served[$server . '/keys.json.sig'] = [200, $list[1]];
        }
    };
    $logger = new MemoryLogger();
    $client = static fn (array $config = []): SiteUpdateClient => new SiteUpdateClient(
        $config + ['trusted_keys' => ['release.old' => $oldPublic], 'site_root' => $root, 'sources' => [$server], 'download' => ['directory' => $root . '/downloads']],
        $root . '/releases/1.2.0', 'https://site.example.test', static fn (): PDO => new PDO('sqlite::memory:'), $logger, $http,
        new FakeArtisanRunner(static fn (): string => ''));
    $check = static function () use ($client): string {
        $site = $client();
        $site->refreshKeys();

        return Kit::reason(static fn () => $site->source()->latest('1.2.0')?->release->keyId);
    };
    $stateFile = $root . '/storage/app/larena/update/release-keys.json';

    try {
        // Before any list: a release signed by the new key is refused; no list is «none», nothing breaks.
        $serve(null, $newSecret, 'release.new');
        Kit::check($check() === 'release_key_untrusted', 'A key the site does not know signs nothing.');
        Kit::check($client()->keyState()->last()['result'] === 'none', 'No key list is said, not an error.');

        // The old key signs the new one: the release signed by the new key is accepted without a new build.
        $v1 = $keys(1, [['release.new', $newPublic]], [], $oldSecret, 'release.old');
        $serve($v1, $newSecret, 'release.new');
        $site = $client();
        $outcome = $site->refreshKeys();
        Kit::check($outcome['result'] === 'accepted' && $outcome['list_version'] === 1 && $outcome['signed_by'] === 'release.old', 'The list signed by the shipped key is accepted.');
        Kit::check(Kit::reason(static fn () => $site->source()->latest('1.2.0')?->release->keyId) === 'accepted' && $site->source()->latest('1.2.0')?->release->keyId === 'release.new', 'A release signed by the new key is accepted.');
        Kit::check($site->trustedKeyIds() === ['release.old', 'release.new'], 'The diagnostics name both keys.');
        Kit::check($client()->refreshKeys()['result'] === 'unchanged', 'The same list again changes nothing.');
        Kit::check(!str_contains((string) file_get_contents($stateFile), $newSecret) && !str_contains((string) json_encode($logger->records), $newPublic), 'Nothing secret is kept or logged.');

        // A key can never authorise itself: a list signed by a key it introduces is refused and logged.
        $self = $keys(2, [['release.stranger', $strangerPublic]], [], $strangerSecret, 'release.stranger');
        $serve($self, $strangerSecret, 'release.stranger');
        $logger->records = [];
        $outcome = $client()->refreshKeys();
        Kit::check($outcome['result'] === 'refused' && $outcome['reason'] === 'update_keys_signature_invalid', 'A self-signed key list is refused.');
        Kit::check(($logger->records[0]['level'] ?? null) === 'error' && ($logger->records[0]['context']['reason'] ?? null) === 'update_keys_signature_invalid', 'A refused list is logged with its reason.');
        Kit::check($check() === 'release_key_untrusted', 'The self-signed key signs no release.');
        // A list with a wrong signature, too.
        $forged = $keys(2, [['release.stranger', $strangerPublic]], [], $oldSecret, 'release.old');
        $serve([$forged[0] . ' ', $forged[1]], $newSecret, 'release.new');
        Kit::check($client()->refreshKeys()['reason'] === 'update_keys_signature_invalid', 'Changed bytes are refused.');

        // Malformed lists: a known id given another key, a list revoking its own signer, a broken shape.
        foreach ([
            $keys(2, [['release.old', $strangerPublic]], [], $oldSecret, 'release.old'),
            $keys(2, [['release.third', $thirdPublic]], ['release.old'], $oldSecret, 'release.old'),
            [(string) json_encode(['schema' => 'larena.release-keys', 'list_version' => 2, 'keys' => []]), $sign((string) json_encode(['schema' => 'larena.release-keys', 'list_version' => 2, 'keys' => []]), $oldSecret, 'release.old')],
            // signed_by must name the key that signed: a list claiming another signer is refused.
            $keys(2, [['release.third', $thirdPublic]], [], $oldSecret, 'release.old', signedBy: 'release.new'),
        ] as $index => $bad) {
            $serve($bad, $newSecret, 'release.new');
            Kit::check($client()->refreshKeys()['reason'] === 'update_keys_invalid', 'A malformed list is refused: ' . $index);
        }

        // Lower or replayed list_version, an expired list: ignored with a warning, the keys stay as they were.
        $v2 = $keys(2, [['release.new', $newPublic], ['release.third', $thirdPublic, '2099-01-01T00:00:00+00:00']], [], $newSecret, 'release.new');
        $serve($v2, $newSecret, 'release.new');
        Kit::check($client()->refreshKeys()['result'] === 'accepted', 'The new key signs the next list.');
        $serve($v1, $newSecret, 'release.new');
        $logger->records = [];
        $outcome = $client()->refreshKeys();
        Kit::check($outcome['result'] === 'ignored' && $outcome['reason'] === 'update_keys_stale' && ($logger->records[0]['level'] ?? null) === 'warning', 'A lower list_version is ignored with a warning.');
        $serve($keys(2, [['release.new', $newPublic]], ['release.third'], $newSecret, 'release.new'), $newSecret, 'release.new');
        Kit::check($client()->refreshKeys()['reason'] === 'update_keys_stale', 'The same number with other bytes is ignored.');
        $serve($keys(5, [['release.new', $newPublic]], [], $newSecret, 'release.new', '2026-01-01T00:00:00+00:00'), $newSecret, 'release.new');
        Kit::check($client()->refreshKeys()['reason'] === 'update_keys_expired', 'An expired list is ignored.');
        Kit::check($check() === 'accepted' && $client()->releaseKeys()['list_version'] === 2, 'An ignored list keeps the accepted keys: the site is not locked out.');
        // not_before: the third key is listed but may not sign yet.
        $serve($v2, $thirdSecret, 'release.third');
        Kit::check($check() === 'release_key_untrusted', 'A key before its not_before signs nothing.');

        // A list signed again by the same key replaces its predecessor: the chain does not grow every day.
        $v3 = $keys(3, [['release.new', $newPublic]], [], $newSecret, 'release.new');
        $serve($v3, $newSecret, 'release.new');
        Kit::check($client()->refreshKeys()['result'] === 'accepted', 'A list signed again by the new key is accepted.');
        Kit::check(count(json_decode((string) file_get_contents($stateFile), true)['chain']) === 2, 'The chain keeps only what proves the signer: old → new, then the newest list.');

        // Revoked ids win, also over the shipped key; the new key keeps working.
        $v4 = $keys(4, [['release.new', $newPublic]], ['release.old'], $newSecret, 'release.new');
        $serve($v4, $oldSecret, 'release.old');
        Kit::check($client()->refreshKeys()['result'] === 'accepted' && $check() === 'release_key_revoked', 'A revoked shipped key signs no release.');
        $serve($v4, $newSecret, 'release.new');
        Kit::check($check() === 'accepted', 'The new key still signs.');
        Kit::check(in_array('release.old', $client()->releaseKeys()['revoked'], true), 'The diagnostics name the revoked key.');
        // The revoked old key cannot sign a key list any more.
        $serve($keys(9, [['release.stranger', $strangerPublic]], [], $oldSecret, 'release.old'), $newSecret, 'release.new');
        Kit::check($client()->refreshKeys()['reason'] === 'update_keys_signature_invalid', 'A revoked key signs no key list.');

        // A revoked new key: refused.
        $other = Kit::temp('key-rotation-2');
        try {
            $state = new ReleaseKeyState($other . '/release-keys.json');
            $shipped = ['release.old' => $oldPublic];
            Kit::check($state->accept(...$keys(1, [['release.new', $newPublic]], [], $oldSecret, 'release.old'), ...[$shipped, []])['result'] === 'accepted', 'Rotation accepted.');
            Kit::check($state->accept(...$keys(2, [], ['release.new'], $oldSecret, 'release.old'), ...[$shipped, []])['result'] === 'accepted', 'The old key revokes the new one.');
            $trusted = $state->trusted($shipped, []);
            Kit::check(in_array('release.new', $trusted['revoked'], true) && !isset($trusted['keys']['release.new']), 'The revoked new key is not trusted.');
            [$bytes, $signature] = Kit::release('1.3.0', $newSecret, 'release.new');
            $verifier = new Larena\UpdateClient\Release\ReleaseSignatureVerifier($trusted['keys'], $trusted['revoked']);
            Kit::check(Kit::reason(static fn () => $verifier->verify($bytes, $signature, '1.2.0')) === 'release_key_revoked', 'A release signed by the revoked new key is refused.');
            Kit::check($state->accept(...$keys(3, [['release.new', $newPublic]], [], $oldSecret, 'release.old'), ...[$shipped, []])['result'] === 'accepted'
                && in_array('release.new', $state->trusted($shipped, [])['revoked'], true), 'Once seen, a revocation stays.');

            // A key written into the storage by hand is never trusted: the chain is checked again on every read.
            $forgedList = $keys(7, [['release.stranger', $strangerPublic]], [], $strangerSecret, 'release.stranger');
            file_put_contents($other . '/release-keys.json', (string) json_encode(['chain' => [['bytes' => base64_encode($forgedList[0]), 'signature' => base64_encode($forgedList[1])]], 'revoked' => [], 'list_version' => 7]));
            Kit::check($state->trusted($shipped, [])['keys'] === $shipped, 'A forged chain falls back to the shipped keys.');
            file_put_contents($other . '/release-keys.json', '{broken');
            Kit::check($state->trusted($shipped, [])['keys'] === $shipped, 'An unreadable state falls back to the shipped keys.');
        } finally {
            Kit::remove($other);
        }

        // The size limit of a list holds before its signature is read.
        Kit::check(Kit::reason(static fn () => ReleaseKeyList::verify(str_repeat(' ', ReleaseKeyList::MAX_BYTES + 1), '{}', ['release.old' => $oldPublic], [])) === 'update_keys_invalid', 'An oversized list is refused.');

        // Words for every refusal, in every language.
        foreach (glob(dirname(__DIR__, 2) . '/resources/lang/*/integrity.php') ?: [] as $file) {
            $reasons = (require $file)['reasons'] ?? [];
            foreach (['update_keys_signature_invalid', 'update_keys_invalid', 'update_keys_stale', 'update_keys_expired', 'update_keys_chain_too_long', 'update_keys_state_unwritable'] as $code) {
                Kit::check(is_string($reasons[$code] ?? null) && $reasons[$code] !== '', "Words for $code must be in $file.");
            }
        }

        // Diagnostics: larena:update:status reads the last refresh.
        $last = $client()->keyState()->last();
        Kit::check(is_array($last) && $last['result'] === 'refused' && is_string($last['at']), 'The last refresh is kept for the diagnostics.');
    } finally {
        Kit::remove($root);
    }
    echo "ReleaseKeyRotationTest passed.\n";
})();
