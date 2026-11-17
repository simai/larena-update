<?php

declare(strict_types=1);

namespace Larena\UpdateClient\Source;

use Larena\UpdateClient\Release\ReleaseDirectory;
use Larena\UpdateClient\Release\ReleaseSignatureVerifier;
use Larena\UpdateClient\Release\VerifiedRelease;
use RuntimeException;
use Throwable;

/**
 * Where a site takes its releases from (plan docs/architecture/2026-11-17-update-client-plan.md, §1, §2, §5a.4).
 *
 * `sources` is a list of HTTPS addresses tried in order; the first is the GitHub releases page of the distribution
 * (`https://github.com/<owner>/<repo>/releases`), a mirror serves the same paths:
 * `<source>/latest/download/<file>` for the newest release and `<source>/download/<tag>/<file>` for one version
 * (`<tag>` is the version with the tag prefix, `v1.3.0`). Each release has `release.json`, `release.json.sig` and
 * the archive `release.json` names.
 *
 * Order of trust: the description and its signature are read into memory and checked by
 * {@see ReleaseSignatureVerifier} first; only then is anything written to the download folder
 * (`storage/app/larena/update/downloads/<version>`), the archive is fetched with the exact size the signed
 * description gives as its limit, and its size and sha256 are checked before it is handed to the cycle. A source
 * that fails (network, status, signature, content) is skipped for the next one; the reasons stay in
 * {@see errors()}. Every failure is a RuntimeException with a stable reason code.
 */
final class ReleaseSource
{
    private const MAX_DESCRIPTION_BYTES = 262144;
    private const MAX_SIGNATURE_BYTES = 4096;

    /** @var list<string> */
    private array $sources;

    /** @var array<string,string> source => reason code of its last failure */
    private array $errors = [];

    /** @param array<array-key,mixed> $sources HTTPS addresses from the configuration, in order */
    public function __construct(
        private readonly UpdateHttpClient $http,
        array $sources,
        private readonly string $downloadDirectory,
        private readonly ReleaseSignatureVerifier $verifier,
        private readonly int $timeoutSeconds = 600,
        private readonly int $connectTimeoutSeconds = 15,
        private readonly int $maxArchiveBytes = 1073741824,
        private readonly string $tagPrefix = 'v',
    ) {
        $valid = [];
        foreach ($sources as $source) {
            $source = is_string($source) ? rtrim(trim($source), '/') : '';
            if (strtolower((string) parse_url($source, PHP_URL_SCHEME)) !== 'https' || parse_url($source, PHP_URL_HOST) === null
                || parse_url($source, PHP_URL_QUERY) !== null || parse_url($source, PHP_URL_USER) !== null) {
                throw new RuntimeException('update_source_invalid');
            }
            $valid[] = $source;
        }
        if ($valid === []) {
            throw new RuntimeException('update_sources_missing');
        }
        if (preg_match('/\A[A-Za-z0-9._-]{0,16}\z/D', $this->tagPrefix) !== 1) {
            throw new RuntimeException('update_source_invalid');
        }
        $this->sources = array_values(array_unique($valid));
    }

    /** @return list<string> */
    public function sources(): array
    {
        return $this->sources;
    }

    /** @return array<string,string> source => reason code, from the last call */
    public function errors(): array
    {
        return $this->errors;
    }

    /**
     * The newest release the first answering source offers, or null when the installed version is already the newest
     * one (a verified description that is not newer). Only the description and its signature are read.
     */
    public function latest(string $installedVersion, string $channel = 'stable'): ?SourceRelease
    {
        $this->errors = [];
        foreach ($this->sources as $source) {
            try {
                [$bytes, $signature] = $this->description($source . '/latest/download/');
                $release = $this->verifier->verify($bytes, $signature, $installedVersion, $channel);

                return new SourceRelease($source, $release);
            } catch (Throwable $exception) {
                $reason = self::reason($exception, 'update_source_failed');
                if ($reason === 'release_not_newer') {
                    return null;
                }
                $this->errors[$source] = $reason;
            }
        }

        throw new RuntimeException((string) reset($this->errors));
    }

    /** Fetches and checks one exact version; see the class description for the order of checks. */
    public function download(string $version, string $installedVersion, string $channel = 'stable'): DownloadedRelease
    {
        if (preg_match('/\A(0|[1-9]\d{0,5})\.(0|[1-9]\d{0,5})\.(0|[1-9]\d{0,7})\z/D', $version) !== 1) {
            throw new RuntimeException('release_version_invalid');
        }
        $this->errors = [];
        foreach ($this->sources as $source) {
            try {
                return $this->downloadFrom($source, $version, $installedVersion, $channel);
            } catch (Throwable $exception) {
                $this->errors[$source] = self::reason($exception, 'update_source_failed');
            }
        }

        throw new RuntimeException((string) reset($this->errors));
    }

    /** Removes the download folder of one version (after the cycle took the archive in). */
    public function forget(string $version): void
    {
        ReleaseDirectory::remove($this->downloadDirectory . '/' . ReleaseDirectory::id($version));
    }

    private function downloadFrom(string $source, string $version, string $installedVersion, string $channel): DownloadedRelease
    {
        $base = $source . '/download/' . rawurlencode($this->tagPrefix . $version) . '/';
        [$bytes, $signature] = $this->description($base);
        $release = $this->verifier->verify($bytes, $signature, $installedVersion, $channel);
        if ($release->version !== $version) {
            throw new RuntimeException('update_release_version_differs');
        }
        if ($release->archiveBytes > $this->maxArchiveBytes) {
            throw new RuntimeException('update_archive_too_large');
        }

        // Verified: from here on files are written, and only into this version's own download folder.
        $directory = $this->directory($version);
        $archive = $directory . '/' . $release->archiveFile;
        foreach (['release.json' => $bytes, 'release.json.sig' => $signature] as $name => $content) {
            if (is_link($directory . '/' . $name) || file_put_contents($directory . '/' . $name, $content) !== strlen($content)) {
                throw new RuntimeException('update_download_path_invalid');
            }
        }
        if (!$this->archiveMatches($release, $archive)) {
            ReleaseDirectory::remove($archive);
            $response = $this->http->send(new UpdateHttpRequest(
                'GET', $base . rawurlencode($release->archiveFile), timeoutSeconds: $this->timeoutSeconds,
                maxBytes: $release->archiveBytes, sinkPath: $archive, followRedirects: true,
                connectTimeoutSeconds: $this->connectTimeoutSeconds,
            ));
            if (!$response->successful()) {
                ReleaseDirectory::remove($archive);
                throw new RuntimeException('update_download_failed');
            }
            try {
                ReleaseSignatureVerifier::verifyArchive($release, $archive);
            } catch (Throwable $exception) {
                ReleaseDirectory::remove($archive);
                throw $exception;
            }
        }

        return new DownloadedRelease($source, $release, $bytes, $signature, $archive);
    }

    /** @return array{0:string,1:string} release.json bytes, release.json.sig bytes */
    private function description(string $base): array
    {
        $files = [];
        foreach (['release.json' => self::MAX_DESCRIPTION_BYTES, 'release.json.sig' => self::MAX_SIGNATURE_BYTES] as $name => $limit) {
            $response = $this->http->send(new UpdateHttpRequest(
                'GET', $base . $name, timeoutSeconds: min(60, $this->timeoutSeconds), maxBytes: $limit,
                followRedirects: true, connectTimeoutSeconds: $this->connectTimeoutSeconds,
            ));
            if (!$response->successful()) {
                throw new RuntimeException($response->status === 404 ? 'update_release_not_found' : 'update_download_failed');
            }
            $files[] = $response->body;
        }

        return [$files[0], $files[1]];
    }

    private function directory(string $version): string
    {
        $root = rtrim($this->downloadDirectory, '/');
        $directory = $root . '/' . ReleaseDirectory::id($version);
        foreach ([$root, $directory] as $path) {
            if (is_link($path)) {
                throw new RuntimeException('update_download_path_invalid');
            }
            if (!is_dir($path) && !mkdir($path, 0750, true) && !is_dir($path)) {
                throw new RuntimeException('update_download_path_invalid');
            }
        }

        return $directory;
    }

    private function archiveMatches(VerifiedRelease $release, string $archive): bool
    {
        try {
            ReleaseSignatureVerifier::verifyArchive($release, $archive);

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    private static function reason(Throwable $exception, string $fallback): string
    {
        return $exception instanceof RuntimeException && preg_match('/\A[a-z][a-z0-9_.-]{0,99}\z/D', $exception->getMessage()) === 1
            ? $exception->getMessage()
            : $fallback;
    }
}
