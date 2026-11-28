<?php

declare(strict_types=1);

require_once __DIR__ . '/../Support/UpdateClientFakes.php';

use Larena\UpdateClient\Packages\PackageBatchRefused;
use Larena\UpdateClient\Packages\PackageListVerifier;
use Larena\UpdateClient\Release\ReleaseKeyState;
use Larena\UpdateClient\Release\ReleaseSignatureVerifier;
use Larena\UpdateClient\Source\ReleaseIndex;
use Larena\UpdateClient\Tests\Support\UpdateClientTestKit as Kit;

// Key purpose in keys.json (Larena Server): a `package-list` key verifies only package lists (packages.json); a
// release, a package release, the channel index and a key list need a release key. Throwaway keys made here only.
(static function (): void {
    $pair = static fn (): array => [sodium_crypto_sign_secretkey($k = sodium_crypto_sign_keypair()), base64_encode(sodium_crypto_sign_publickey($k))];
    [$releaseSecret, $releasePublic] = $pair();
    [$listSecret, $listPublic] = $pair();
    [$thirdSecret, $thirdPublic] = $pair();
    $sign = static fn (string $bytes, string $secret, string $id): string => (string) json_encode(['key_id' => $id, 'signature' => base64_encode(sodium_crypto_sign_detached($bytes, $secret))]);
    $keys = static function (int $version, array $entries, string $secret, string $id) use ($sign): array {
        $bytes = (string) json_encode(['schema' => 'larena.release-keys', 'list_version' => $version, 'expires_at' => '2099-01-01T00:00:00+00:00', 'signed_by' => $id,
            'keys' => $entries, 'revoked' => []], JSON_UNESCAPED_SLASHES);

        return [$bytes, $sign($bytes, $secret, $id)];
    };
    $entry = static fn (string $id, string $public, ?string $purpose = null): array => ['id' => $id, 'public_key' => $public, 'not_before' => '2026-01-01T00:00:00+00:00']
        + ($purpose === null ? [] : ['purpose' => $purpose]);
    $shipped = ['release.main' => $releasePublic];
    $now = new DateTimeImmutable('2026-11-28T12:00:00+00:00');
    $dir = Kit::temp('key-purpose');

    try {
        $state = new ReleaseKeyState($dir . '/release-keys.json');
        // Unknown purpose, or a shipped (release) key renamed a list key: the list is refused.
        Kit::check($state->accept(...[...$keys(1, [$entry('list.main', $listPublic, 'everything')], $releaseSecret, 'release.main'), $shipped, [], $now])['reason'] === 'update_keys_invalid', 'An unknown purpose is refused.');
        Kit::check($state->accept(...[...$keys(1, [$entry('release.main', $releasePublic, 'package-list')], $releaseSecret, 'release.main'), $shipped, [], $now])['reason'] === 'update_keys_invalid', 'A shipped key keeps its purpose.');

        $v1 = $keys(1, [$entry('list.main', $listPublic, 'package-list'), $entry('release.third', $thirdPublic)], $releaseSecret, 'release.main');
        Kit::check($state->accept(...[...$v1, $shipped, [], $now])['result'] === 'accepted', 'A list naming a package-list key is accepted.');
        $trusted = $state->trusted($shipped, [], $now);
        Kit::check($trusted['package_list'] === ['list.main'] && isset($trusted['keys']['list.main']), 'The list key is trusted with its purpose.');
        $verifier = new ReleaseSignatureVerifier($trusted['keys'], $trusted['revoked'], 'larena', $trusted['package_list']);

        // A list key signs a release: refused.
        [$release, $releaseSignature] = Kit::release('1.3.0', $listSecret, 'list.main');
        Kit::check(Kit::reason(static fn () => $verifier->verify($release, $releaseSignature, '1.2.0')) === 'release_key_purpose_differs', 'A list key signs no release.');
        Kit::check(Kit::reason(static fn () => $verifier->verifyKept($release, $releaseSignature)) === 'release_key_purpose_differs', 'A list key signs no kept release.');
        // … nor the channel index.
        [$index, $indexSignature] = Kit::index($release, $listSecret, 'list.main');
        Kit::check(Kit::reason(static fn () => ReleaseIndex::verify($index, $indexSignature, $verifier, 'stable', $now, 0)) === 'release_key_purpose_differs', 'A list key signs no index.');
        // A release key still signs a release, and a key without purpose is a release key.
        [$good, $goodSignature] = Kit::release('1.3.0', $thirdSecret, 'release.third');
        Kit::check(Kit::reason(static fn () => $verifier->verify($good, $goodSignature, '1.2.0')) === 'accepted', 'A key without purpose signs releases.');

        // A list key signs packages.json: accepted; a package release inside it still needs a release key.
        $package = static function (string $secret, string $id) use ($sign): array {
            $bytes = (string) json_encode(['schema' => 'larena.package-release', 'schema_version' => 1, 'product' => 'larena', 'package' => 'larena/storage',
                'version' => '1.3.0', 'released_at' => '2026-11-20T10:00:00+00:00', 'distribution' => 'closed',
                'archive' => ['file' => 'larena-storage-1.3.0.tar.gz', 'sha256' => str_repeat('a', 64), 'bytes' => 10],
                'requires' => ['larena' => '>=1.3 <2.0', 'packages' => []], 'backward_incompatible_migrations' => false], JSON_UNESCAPED_SLASHES);

            return ['release' => $bytes, 'signature' => json_decode($sign($bytes, $secret, $id), true)];
        };
        $list = static function (array $packages, string $secret, string $id) use ($sign): array {
            $bytes = (string) json_encode(['schema' => 'larena.package-list', 'schema_version' => 1, 'product' => 'larena', 'channel' => 'stable',
                'list_version' => 1, 'issued_at' => '2026-11-27T11:00:00+00:00', 'expires_at' => '2026-12-05T12:00:00+00:00', 'packages' => $packages], JSON_UNESCAPED_SLASHES);

            return [$bytes, $sign($bytes, $secret, $id)];
        };
        $packages = new PackageListVerifier($verifier);
        $listed = $packages->verify(...[...$list([$package($releaseSecret, 'release.main')], $listSecret, 'list.main'), 'stable', $now]);
        Kit::check($listed->keyId === 'list.main' && count($listed->releases) === 1, 'A list key signs packages.json.');
        Kit::check($packages->verify(...[...$list([$package($releaseSecret, 'release.main')], $releaseSecret, 'release.main'), 'stable', $now])->keyId === 'release.main', 'A release key still signs packages.json.');
        try {
            $packages->verify(...[...$list([$package($listSecret, 'list.main')], $listSecret, 'list.main'), 'stable', $now]);
            Kit::check(false, 'A package release signed by a list key must be refused.');
        } catch (PackageBatchRefused $refused) {
            Kit::check($refused->getMessage() === 'update_batch_signature_invalid' && $refused->refusals[0]['requirement'] === 'release_key_purpose_differs', 'A list key makes no package.');
        }

        // A list key signs keys.json: refused, and the trusted keys stay as they were.
        $byList = $keys(2, [$entry('list.main', $listPublic, 'package-list'), $entry('release.other', $thirdPublic)], $listSecret, 'list.main');
        $outcome = $state->accept(...[...$byList, $shipped, [], $now]);
        Kit::check($outcome['result'] === 'refused' && $outcome['reason'] === 'update_keys_signature_invalid', 'A list key signs no key list.');
        Kit::check($state->trusted($shipped, [], $now) === $trusted, 'A refused list changes nothing.');
        // A later list cannot turn the list key into a release key.
        $widen = $keys(2, [$entry('list.main', $listPublic)], $releaseSecret, 'release.main');
        Kit::check($state->accept(...[...$widen, $shipped, [], $now])['reason'] === 'update_keys_invalid', 'A list key cannot become a release key.');
        // The purpose is replayed from the signed chain: the state file alone cannot drop it.
        Kit::check($state->trusted($shipped, [], $now)['package_list'] === ['list.main'], 'The purpose survives a read.');
    } finally {
        Kit::remove($dir);
    }
    echo "KeyPurposeTest passed.\n";
})();
