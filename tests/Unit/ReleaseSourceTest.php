<?php

declare(strict_types=1);

require_once __DIR__ . '/../Support/UpdateClientFakes.php';

use Larena\UpdateClient\Release\ReleaseSignatureVerifier;
use Larena\UpdateClient\Source\ReleaseSource;
use Larena\UpdateClient\Source\UpdateHttpRequest;
use Larena\UpdateClient\Tests\Support\FakeUpdateHttpClient;
use Larena\UpdateClient\Tests\Support\UpdateClientTestKit as Kit;

// Download and trust order of the update sources. No network: every answer comes from a fake client.
(static function (): void {
    $pair = sodium_crypto_sign_keypair();
    $foreign = sodium_crypto_sign_keypair();
    $secret = sodium_crypto_sign_secretkey($pair);
    $verifier = new ReleaseSignatureVerifier(['release.test' => base64_encode(sodium_crypto_sign_publickey($pair))]);
    $github = 'https://github.com/example/larena/releases';
    $mirror = 'https://mirror.example.org/larena/releases';
    $root = Kit::temp('source');
    $downloads = $root . '/storage/app/larena/update/downloads';
    [$bytes, $signature, $archive] = Kit::release('1.3.0', $secret);
    [$foreignBytes, $foreignSignature] = Kit::release('1.3.0', sodium_crypto_sign_secretkey($foreign));
    $files = static fn (string $base, string $json, string $sig, ?string $body = null): array => [
        $base . 'release.json' => [200, $json],
        $base . 'release.json.sig' => [200, $sig],
        $base . 'larena-1.3.0.tar.gz' => [200, $body ?? $archive],
    ];

    try {
        // Settings: only HTTPS addresses, at least one.
        Kit::check(Kit::reason(static fn () => new ReleaseSource(FakeUpdateHttpClient::serving([]), ['http://github.com/x/y/releases'], $downloads, $verifier)) === 'update_source_invalid', 'A plain HTTP source must be refused.');
        Kit::check(Kit::reason(static fn () => new ReleaseSource(FakeUpdateHttpClient::serving([]), [], $downloads, $verifier)) === 'update_sources_missing', 'No source must be refused.');
        Kit::check(Kit::reason(static fn () => new UpdateHttpRequest('GET', 'http://example.org/release.json')) === 'update_source_https_required', 'A download request must be HTTPS.');
        Kit::check(Kit::reason(static fn () => new UpdateHttpRequest('GET', 'file:///etc/passwd', httpsOnly: false)) === 'update_http_url_invalid', 'Only http(s) addresses are requested.');

        // check: the first source answers with a newer signed release.
        $http = FakeUpdateHttpClient::serving($files($github . '/latest/download/', $bytes, $signature));
        $source = new ReleaseSource($http, [$github, $mirror], $downloads, $verifier);
        $offer = $source->latest('1.2.0');
        Kit::check($offer !== null && $offer->source === $github && $offer->release->version === '1.3.0', 'The newest release must come from the first source.');
        Kit::check($http->urls() === ['GET ' . $github . '/latest/download/release.json', 'GET ' . $github . '/latest/download/release.json.sig'], 'A check reads only the description and its signature.');
        Kit::check(!is_dir($downloads), 'A check writes nothing.');
        Kit::check($source->latest('1.3.0') === null, 'An installed newest version is up to date.');

        // A source with a foreign signature is skipped for the next one.
        // The mirror is an update server: its signed index names the release.
        $http = FakeUpdateHttpClient::serving($files($github . '/latest/download/', $foreignBytes, $foreignSignature) + Kit::server($mirror, $bytes, $signature, $secret));
        $source = new ReleaseSource($http, [$github, $mirror], $downloads, $verifier);
        $offer = $source->latest('1.2.0');
        Kit::check($offer !== null && $offer->source === $mirror && $source->errors() === [$github => 'release_signature_mismatch'], 'A badly signed source must be skipped with its reason.');

        // Every source down: the first reason, nothing written.
        $http = new FakeUpdateHttpClient(static fn () => throw new RuntimeException('update_http_failed'));
        $source = new ReleaseSource($http, [$github, $mirror], $downloads, $verifier);
        Kit::check(Kit::reason(static fn () => $source->latest('1.2.0')) === 'update_http_failed' && count($source->errors()) === 2, 'No source must be an error with every reason kept.');

        // apply: one exact version, verified before any write, archive limited to its signed size.
        $http = FakeUpdateHttpClient::serving($files($github . '/download/v1.3.0/', $bytes, $signature));
        $source = new ReleaseSource($http, [$github, $mirror], $downloads, $verifier);
        $download = $source->download('1.3.0', '1.2.0');
        Kit::check($download->archivePath === $downloads . '/1.3.0/larena-1.3.0.tar.gz' && hash_file('sha256', $download->archivePath) === hash('sha256', $archive), 'The archive must land in the version download folder.');
        Kit::check($download->releaseBytes === $bytes && $download->signatureBytes === $signature && file_get_contents($downloads . '/1.3.0/release.json') === $bytes, 'The exact signed bytes must be kept.');
        $archiveRequest = $http->requests[2];
        Kit::check($archiveRequest->maxBytes === strlen($archive) && $archiveRequest->httpsOnly && $archiveRequest->followRedirects && $archiveRequest->sinkPath === $download->archivePath, 'The archive download must be HTTPS, size-limited by the signed description and written to its file.');
        $before = count($http->requests);
        $source->download('1.3.0', '1.2.0');
        Kit::check(count($http->requests) === $before + 2, 'A verified archive already on disk is not downloaded again.');
        $source->forget('1.3.0');
        Kit::check(!is_dir($downloads . '/1.3.0'), 'forget must remove the version download folder.');

        // A replaced archive is refused and removed; a foreign signature writes nothing at all.
        $http = FakeUpdateHttpClient::serving($files($github . '/download/v1.3.0/', $bytes, $signature, strrev($archive)));
        $source = new ReleaseSource($http, [$github], $downloads, $verifier);
        Kit::check(Kit::reason(static fn () => $source->download('1.3.0', '1.2.0')) === 'release_archive_checksum_differs', 'A replaced archive must be refused.');
        Kit::check(!is_file($downloads . '/1.3.0/larena-1.3.0.tar.gz'), 'A refused archive must not stay on disk.');
        Kit::remove($downloads);
        $http = FakeUpdateHttpClient::serving($files($github . '/download/v1.3.0/', $foreignBytes, $foreignSignature));
        $source = new ReleaseSource($http, [$github], $downloads, $verifier);
        Kit::check(Kit::reason(static fn () => $source->download('1.3.0', '1.2.0')) === 'release_signature_mismatch' && !is_dir($downloads), 'A foreign signature must be refused before any write.');
        Kit::check(count($http->requests) === 2, 'The archive of an unverified release is never requested.');

        // The description must be the version asked for.
        [$otherBytes, $otherSignature] = Kit::release('1.4.0', $secret);
        $source = new ReleaseSource(FakeUpdateHttpClient::serving($files($github . '/download/v1.3.0/', $otherBytes, $otherSignature)), [$github], $downloads, $verifier);
        Kit::check(Kit::reason(static fn () => $source->download('1.3.0', '1.2.0')) === 'update_release_version_differs', 'Another version under the tag must be refused.');
        Kit::check(Kit::reason(static fn () => $source->download('1.3', '1.2.0')) === 'release_version_invalid', 'Only exact versions are downloaded.');

        // A signed archive over the site limit is not downloaded.
        $source = new ReleaseSource(FakeUpdateHttpClient::serving($files($github . '/download/v1.3.0/', $bytes, $signature)), [$github], $downloads, $verifier, maxArchiveBytes: 16);
        Kit::check(Kit::reason(static fn () => $source->download('1.3.0', '1.2.0')) === 'update_archive_too_large', 'An archive over the limit must be refused.');
        Kit::check(Kit::reason(static fn () => (new FakeUpdateHttpClient(static fn (): array => [200, str_repeat('x', 300000)]))->send(new UpdateHttpRequest('GET', $github . '/x', maxBytes: 262144))) === 'update_http_too_large', 'The fake client must keep the byte limit like the real one.');
    } finally {
        Kit::remove($root);
    }
})();
