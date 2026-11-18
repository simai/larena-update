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
 * `sources` is a list of addresses tried in order; the first is the GitHub releases page of the distribution
 * (`https://github.com/<owner>/<repo>/releases`), a mirror serves the same paths:
 * `<source>/latest/download/<file>` for the newest release and `<source>/download/<tag>/<file>` for one version
 * (`<tag>` is the version with the tag prefix, `v1.3.0`). Each release has `release.json`, `release.json.sig` and
 * the archive `release.json` names.
 *
 * A source may also be a folder on this server (`file:///absolute/path`): a server without network access, or Larena
 * Panel putting a release next to the site. The folder holds the same files as the release page,
 * `<folder>/download/<tag>/<file>` (what the release step writes); the newest release there is the highest version
 * among its `download/<tag>` folders. Files are read, never followed through a link, with the same size limits.
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
            if (self::folder($source) === null && (strtolower((string) parse_url($source, PHP_URL_SCHEME)) !== 'https' || parse_url($source, PHP_URL_HOST) === null
                || parse_url($source, PHP_URL_QUERY) !== null || parse_url($source, PHP_URL_USER) !== null)) {
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
                [$bytes, $signature] = $this->description($this->latestBase($source));
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
        $folder = self::folder($source);
        $base = $folder !== null
            ? $folder . '/download/' . $this->tagPrefix . $version . '/'
            : $source . '/download/' . rawurlencode($this->tagPrefix . $version) . '/';
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
            if ($folder !== null) {
                $this->copyArchive($base . $release->archiveFile, $archive, $release->archiveBytes);
            } else {
                $response = $this->http->send(new UpdateHttpRequest(
                    'GET', $base . rawurlencode($release->archiveFile), timeoutSeconds: $this->timeoutSeconds,
                    maxBytes: $release->archiveBytes, sinkPath: $archive, followRedirects: true,
                    connectTimeoutSeconds: $this->connectTimeoutSeconds,
                ));
                if (!$response->successful()) {
                    ReleaseDirectory::remove($archive);
                    throw new RuntimeException('update_download_failed');
                }
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
            if (str_starts_with($base, '/')) {
                $files[] = self::readFile($base . $name, $limit);
                continue;
            }
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

    /** Where the newest release of a source is: `/latest/download/` of a release page, the highest `download/<tag>/` of a folder. */
    private function latestBase(string $source): string
    {
        $folder = self::folder($source);
        if ($folder === null) {
            return $source . '/latest/download/';
        }
        $newest = null;
        $tags = is_dir($folder . '/download') && !is_link($folder . '/download') ? scandir($folder . '/download') : false;
        foreach ($tags === false ? [] : $tags as $tag) {
            if (!str_starts_with($tag, $this->tagPrefix)) {
                continue;
            }
            $version = substr($tag, strlen($this->tagPrefix));
            if (preg_match('/\A(0|[1-9]\d{0,5})\.(0|[1-9]\d{0,5})\.(0|[1-9]\d{0,7})\z/D', $version) === 1
                && is_dir($folder . '/download/' . $tag) && !is_link($folder . '/download/' . $tag)
                && ($newest === null || version_compare($version, $newest, '>'))) {
                $newest = $version;
            }
        }
        if ($newest === null) {
            throw new RuntimeException('update_release_not_found');
        }

        return $folder . '/download/' . $this->tagPrefix . $newest . '/';
    }

    /** The absolute folder of a `file:///…` source, or null for an address. */
    private static function folder(string $source): ?string
    {
        if (!str_starts_with($source, 'file://')) {
            return null;
        }
        $path = rtrim(substr($source, 7), '/');
        if ($path === '' || $path[0] !== '/' || str_contains($path, '/../') || str_ends_with($path, '/..') || str_contains($path, '/./')
            || preg_match('/[\x00-\x1f\x7f?#]/', $path) === 1) {
            throw new RuntimeException('update_source_invalid');
        }

        return $path;
    }

    /** A regular file of a folder source, at most `$limit` bytes; a missing file is `update_release_not_found`. */
    private static function readFile(string $path, int $limit): string
    {
        if (is_link($path)) {
            throw new RuntimeException('update_download_failed');
        }
        if (!is_file($path)) {
            throw new RuntimeException('update_release_not_found');
        }
        $handle = fopen($path, 'rb');
        $bytes = $handle === false ? false : stream_get_contents($handle, $limit + 1);
        if ($handle !== false) {
            fclose($handle);
        }
        if (!is_string($bytes)) {
            throw new RuntimeException('update_download_failed');
        }
        if (strlen($bytes) > $limit) {
            throw new RuntimeException('update_http_too_large');
        }

        return $bytes;
    }

    /** Copies an archive of a folder source to `<file>.part`, at most the signed size, and renames it into place. */
    private function copyArchive(string $from, string $to, int $bytes): void
    {
        if (is_link($from) || !is_file($from)) {
            throw new RuntimeException(is_link($from) ? 'update_download_failed' : 'update_release_not_found');
        }
        $partial = $to . '.part';
        if (is_link($partial) || is_link($to)) {
            throw new RuntimeException('update_download_path_invalid');
        }
        $in = fopen($from, 'rb');
        $out = fopen($partial, 'wb');
        try {
            if ($in === false || $out === false) {
                throw new RuntimeException('update_download_failed');
            }
            $copied = stream_copy_to_stream($in, $out, $bytes + 1);
            if ($copied === false) {
                throw new RuntimeException('update_download_failed');
            }
            if ($copied > $bytes) {
                throw new RuntimeException('update_http_too_large');
            }
        } catch (Throwable $exception) {
            if ($out !== false) {
                fclose($out);
                $out = false;
            }
            ReleaseDirectory::remove($partial);
            throw $exception;
        } finally {
            if ($in !== false) {
                fclose($in);
            }
            if ($out !== false) {
                fclose($out);
            }
        }
        if (!rename($partial, $to)) {
            ReleaseDirectory::remove($partial);
            throw new RuntimeException('update_download_path_invalid');
        }
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
