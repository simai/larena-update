<?php

declare(strict_types=1);

use Larena\UpdateClient\Release\ReleaseSignatureVerifier;

function release_assert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

/** @param callable():mixed $call */
function release_reason(callable $call): string
{
    try {
        $call();
    } catch (RuntimeException $exception) {
        return $exception->getMessage();
    }

    return 'accepted';
}

// A throwaway key for this test only; no real release key is ever in a repository.
$releasePair = sodium_crypto_sign_keypair();
$releaseSecret = sodium_crypto_sign_secretkey($releasePair);
$releasePublic = base64_encode(sodium_crypto_sign_publickey($releasePair));
$otherPair = sodium_crypto_sign_keypair();

$releaseArchive = tempnam(sys_get_temp_dir(), 'release-archive-');
file_put_contents($releaseArchive, str_repeat('larena', 100));
$releaseDescription = static fn (array $override = []): string => json_encode(array_replace([
    'schema' => 'larena.release',
    'schema_version' => 1,
    'product' => 'larena',
    'version' => '1.2.0',
    'channel' => 'stable',
    'minimum_version' => '1.0.0',
    'php' => '^8.3',
    'archive' => ['file' => 'larena-1.2.0.tar.gz', 'sha256' => hash_file('sha256', $releaseArchive), 'bytes' => filesize($releaseArchive)],
    'packages' => ['larena/core' => str_repeat('a', 40), 'larena/admin' => str_repeat('b', 40)],
    'backward_incompatible_migrations' => false,
    'notes' => ['ru' => 'Проверка', 'en' => 'Check'],
], $override), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
$sign = static fn (string $bytes, string $secret = '', string $keyId = 'release.2026'): string => json_encode([
    'key_id' => $keyId,
    'signature' => base64_encode(sodium_crypto_sign_detached($bytes, $secret === '' ? $releaseSecret : $secret)),
]);

try {
    $verifier = new ReleaseSignatureVerifier(['release.2026' => $releasePublic]);
    $bytes = $releaseDescription();
    $release = $verifier->verify($bytes, $sign($bytes), '1.1.4');
    release_assert($release->version === '1.2.0' && $release->keyId === 'release.2026' && !$release->backwardIncompatibleMigrations, 'A signed newer release must be accepted.');
    release_assert(array_keys($release->packages) === ['larena/admin', 'larena/core'], 'Package revisions must come back sorted.');
    ReleaseSignatureVerifier::verifyArchive($release, $releaseArchive);

    // Every rule fails closed with its own reason.
    release_assert(release_reason(fn () => $verifier->verify($bytes.' ', $sign($bytes), '1.1.4')) === 'release_signature_mismatch', 'Changed bytes must be refused.');
    release_assert(release_reason(fn () => $verifier->verify($bytes, $sign($bytes, sodium_crypto_sign_secretkey($otherPair)), '1.1.4')) === 'release_signature_mismatch', 'A foreign signature must be refused.');
    release_assert(release_reason(fn () => $verifier->verify($bytes, $sign($bytes, '', 'unknown.key'), '1.1.4')) === 'release_key_untrusted', 'An unknown key must be refused.');
    $revoked = new ReleaseSignatureVerifier(['release.2026' => $releasePublic], ['release.2026']);
    release_assert(release_reason(fn () => $revoked->verify($bytes, $sign($bytes), '1.1.4')) === 'release_key_revoked', 'A revoked key must be refused.');
    release_assert(release_reason(fn () => $verifier->verify($bytes, $sign($bytes), '1.2.0')) === 'release_not_newer', 'The same version must be refused.');
    release_assert(release_reason(fn () => $verifier->verify($bytes, $sign($bytes), '1.3.0')) === 'release_not_newer', 'A downgrade must be refused.');
    release_assert(release_reason(fn () => $verifier->verify($bytes, $sign($bytes), '0.9.0')) === 'release_installed_too_old', 'An install below the minimum must be refused.');
    release_assert(release_reason(fn () => $verifier->verify($bytes, $sign($bytes), '1.1.4', 'beta')) === 'release_channel_differs', 'Another channel must be refused.');
    $other = $releaseDescription(['product' => 'other']);
    release_assert(release_reason(fn () => $verifier->verify($other, $sign($other), '1.1.4')) === 'release_product_differs', 'Another product must be refused.');
    $badPackages = $releaseDescription(['packages' => ['larena/core' => 'main']]);
    release_assert(release_reason(fn () => $verifier->verify($badPackages, $sign($badPackages), '1.1.4')) === 'release_packages_invalid', 'Branch names instead of revisions must be refused.');
    $badFile = $releaseDescription(['archive' => ['file' => '../evil.tar.gz', 'sha256' => str_repeat('a', 64), 'bytes' => 1]]);
    release_assert(release_reason(fn () => $verifier->verify($badFile, $sign($badFile), '1.1.4')) === 'release_archive_invalid', 'A path in the archive name must be refused.');
    $flagged = $releaseDescription(['backward_incompatible_migrations' => true]);
    release_assert($verifier->verify($flagged, $sign($flagged), '1.1.4')->backwardIncompatibleMigrations, 'The incompatible-migrations flag must reach the caller.');

    file_put_contents($releaseArchive, 'x', FILE_APPEND);
    release_assert(release_reason(fn () => ReleaseSignatureVerifier::verifyArchive($release, $releaseArchive)) === 'release_archive_size_differs', 'A changed archive must be refused.');
    release_assert(release_reason(fn () => new ReleaseSignatureVerifier([])) === 'release_trusted_keys_missing', 'No trusted key means no update.');
} finally {
    @unlink($releaseArchive);
}
