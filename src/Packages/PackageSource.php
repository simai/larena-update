<?php

declare(strict_types=1);

namespace Larena\UpdateClient\Packages;

use Larena\UpdateClient\Release\ReleaseDirectory;
use Larena\UpdateClient\Source\UpdateHttpClient;
use Larena\UpdateClient\Source\UpdateHttpRequest;
use Larena\UpdateClient\Source\UpdateHttpResponse;
use RuntimeException;
use Throwable;

/**
 * Where a site takes package releases from: the same update sources as releases (`LARENA_UPDATE_SOURCES`), under
 * `packages/` (the contract proposed to Larena Server, 2026-11-25):
 *
 * - `<source>/packages/packages.json` and `<source>/packages/packages.json.sig` — the signed list, the same for every
 *   site of the product and channel;
 * - `<source>/packages/download/<vendor>/<name>/v<version>/<archive file>` — a package archive; a closed package's
 *   archive only with a site key the licence backs (refusals as for release archives).
 *
 * A folder source (`file:///…`) holds the same paths. GitHub release pages have no package list and are skipped.
 * The list is read into memory only; an archive is written into its own download folder
 * (`<downloads>/packages/<vendor>/<name>/<version>/`) up to its signed size and checked against its signed sha256 —
 * a different file is removed and refused (`update_batch_signature_invalid`). The site key goes as
 * `Authorization: Bearer` to HTTPS sources only, never into an address or a reason.
 */
final class PackageSource
{
    private const MAX_SIGNATURE_BYTES = 4096;

    /** @var list<string> */
    private array $sources = [];

    /** @var array<string,string> source => reason code of its last failure */
    private array $errors = [];

    /** @param array<array-key,mixed> $sources */
    public function __construct(
        private readonly UpdateHttpClient $http,
        array $sources,
        private readonly string $downloadDirectory,
        private readonly int $timeoutSeconds = 600,
        private readonly int $connectTimeoutSeconds = 15,
        #[\SensitiveParameter] private readonly ?string $siteKey = null,
    ) {
        foreach ($sources as $source) {
            $source = is_string($source) ? rtrim(trim($source), '/') : '';
            $host = strtolower((string) parse_url($source, PHP_URL_HOST));
            if ($host === 'github.com' || str_ends_with($host, '.github.com')) {
                continue;
            }
            if (self::folder($source) === null && (strtolower((string) parse_url($source, PHP_URL_SCHEME)) !== 'https' || $host === ''
                || parse_url($source, PHP_URL_QUERY) !== null || parse_url($source, PHP_URL_USER) !== null)) {
                throw new RuntimeException('update_source_invalid');
            }
            $this->sources[] = $source;
        }
        $this->sources = array_values(array_unique($this->sources));
    }

    /** @return array<string,string> */
    public function errors(): array
    {
        return $this->errors;
    }

    /**
     * The list and its signature from the first source that answers: [list bytes, signature bytes, source]. Not
     * verified here: {@see PackageListVerifier} does that before anything is downloaded.
     *
     * @return array{0:string,1:string,2:string}
     */
    public function list(): array
    {
        $this->errors = [];
        if ($this->sources === []) {
            throw new RuntimeException('update_batch_source_missing');
        }
        foreach ($this->sources as $source) {
            try {
                return [
                    $this->read($source, 'packages/packages.json', PackageListVerifier::MAX_LIST_BYTES),
                    $this->read($source, 'packages/packages.json.sig', self::MAX_SIGNATURE_BYTES),
                    $source,
                ];
            } catch (Throwable $exception) {
                $this->errors[$source] = self::reason($exception, 'update_download_failed');
            }
        }
        foreach ($this->errors as $reason) {
            if (str_starts_with($reason, 'update_license_') || str_starts_with($reason, 'update_site_key_')) {
                throw new RuntimeException($reason);
            }
        }

        throw new RuntimeException((string) reset($this->errors));
    }

    /** Fetches one archive of a verified release from `$source`; returns its checked path. */
    public function download(PackageRelease $release, string $source): string
    {
        [$vendor, $name] = explode('/', $release->package, 2);
        $directory = $this->directory($vendor . '/' . $name . '/' . $release->version);
        $archive = $directory . '/' . $release->archiveFile;
        try {
            PackageBatchLayout::verifyArchive($release, $archive);

            return $archive;
        } catch (Throwable) {
            ReleaseDirectory::remove($archive);
        }
        $path = 'packages/download/' . $vendor . '/' . $name . '/v' . $release->version . '/' . $release->archiveFile;
        $folder = self::folder($source);
        if ($folder !== null) {
            $from = $folder . '/' . $path;
            if (is_link($from) || !is_file($from) || filesize($from) !== $release->archiveBytes || !copy($from, $archive . '.part') || !rename($archive . '.part', $archive)) {
                ReleaseDirectory::remove($archive . '.part');
                throw new RuntimeException('update_download_failed');
            }
        } else {
            $response = $this->http->send(new UpdateHttpRequest(
                'GET', $source . '/' . self::encode($path), $this->headers($source), timeoutSeconds: $this->timeoutSeconds,
                maxBytes: $release->archiveBytes, sinkPath: $archive, followRedirects: true, connectTimeoutSeconds: $this->connectTimeoutSeconds,
            ));
            if (!$response->successful()) {
                ReleaseDirectory::remove($archive);
                throw new RuntimeException($this->refusal($source, $response));
            }
        }
        try {
            PackageBatchLayout::verifyArchive($release, $archive);
        } catch (Throwable $exception) {
            ReleaseDirectory::remove($archive);
            throw $exception;
        }

        return $archive;
    }

    /** Removes the download folders of the packages (after the cycle took them in). */
    public function forget(): void
    {
        ReleaseDirectory::remove(rtrim($this->downloadDirectory, '/') . '/packages');
    }

    private function read(string $source, string $path, int $limit): string
    {
        $folder = self::folder($source);
        if ($folder !== null) {
            $file = $folder . '/' . $path;
            if (is_link($file) || !is_file($file)) {
                throw new RuntimeException('update_batch_list_missing');
            }
            $bytes = (string) file_get_contents($file, length: $limit + 1);
            if (strlen($bytes) > $limit) {
                throw new RuntimeException('update_http_too_large');
            }

            return $bytes;
        }
        $response = $this->http->send(new UpdateHttpRequest(
            'GET', $source . '/' . self::encode($path), $this->headers($source), timeoutSeconds: min(60, $this->timeoutSeconds), maxBytes: $limit,
            followRedirects: true, connectTimeoutSeconds: $this->connectTimeoutSeconds,
        ));
        if (!$response->successful()) {
            throw new RuntimeException($response->status === 404 ? 'update_batch_list_missing' : $this->refusal($source, $response));
        }

        return $response->body;
    }

    /** A 401/403 with the update server's refusal code, as {@see \Larena\UpdateClient\Source\ReleaseSource} names it. */
    private function refusal(string $source, UpdateHttpResponse $response): string
    {
        if ($response->status !== 401 && $response->status !== 403) {
            return 'update_download_failed';
        }
        $answer = strlen($response->body) <= 4096 ? json_decode($response->body, true, 8) : null;
        $code = is_array($answer) && is_string($answer['error'] ?? null) ? $answer['error'] : null;

        return match (true) {
            in_array($code, ['license_required', 'license_expired', 'license_revoked'], true) => 'update_' . $code,
            $code === 'site_key_required', $response->status === 401 => $this->headers($source) !== [] ? 'update_site_key_rejected' : 'update_site_key_required',
            default => 'update_download_failed',
        };
    }

    /** @return array<string,string> */
    private function headers(string $source): array
    {
        $key = $this->siteKey === null ? '' : trim($this->siteKey);

        return $key === '' || self::folder($source) !== null || preg_match('/\A[\x21-\x7e]{1,4096}\z/D', $key) !== 1
            ? [] : ['Authorization' => 'Bearer ' . $key];
    }

    private function directory(string $relative): string
    {
        $directory = rtrim($this->downloadDirectory, '/') . '/packages/' . $relative;
        if (is_link($directory) || (!is_dir($directory) && !mkdir($directory, 0750, true) && !is_dir($directory))) {
            throw new RuntimeException('update_download_path_invalid');
        }

        return $directory;
    }

    private static function encode(string $path): string
    {
        return implode('/', array_map('rawurlencode', explode('/', $path)));
    }

    private static function folder(string $source): ?string
    {
        if (!str_starts_with($source, 'file://')) {
            return null;
        }
        $path = rtrim(substr($source, 7), '/');
        if ($path === '' || $path[0] !== '/' || str_contains($path, '/../') || str_ends_with($path, '/..') || preg_match('/[\x00-\x1f\x7f?#]/', $path) === 1) {
            throw new RuntimeException('update_source_invalid');
        }

        return $path;
    }

    private static function reason(Throwable $exception, string $fallback): string
    {
        return $exception instanceof RuntimeException && preg_match('/\A[a-z][a-z0-9_.-]{0,99}\z/D', $exception->getMessage()) === 1 ? $exception->getMessage() : $fallback;
    }
}
