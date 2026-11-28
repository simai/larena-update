<?php

declare(strict_types=1);

namespace Larena\UpdateClient\Source;

use Closure;
use DateTimeImmutable;
use Larena\UpdateClient\Release\FileList;
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
 * description gives as its limit, and its size and sha256 are checked before it is handed to the cycle. A release
 * that names a file list (`files.json`, {@see FileList}) has it fetched first, up to its signed size, and checked
 * against `release.json` and in itself; it lies next to the archive, where the cycle reads it. A source
 * that fails (network, status, signature, content) is skipped for the next one; the reasons stay in
 * {@see errors()}. Every failure is a RuntimeException with a stable reason code.
 *
 * The site key (`site_key`, `LARENA_UPDATE_SITE_KEY` in `.env`) goes as `Authorization: Bearer <key>` with every
 * request to an HTTPS source except github.com, whose public releases need none and must not see it. It is never put
 * into an address, a reason code or {@see errors()}; a request with a key does not carry it across a redirect to
 * another host. Without a key requests go as before. {@see entitlement()} asks a source for the site's entitlement.
 *
 * A refused download names its real reason (auditor msg-68c8decb, 2026-11-24): a 401/403 with the update server's
 * refusal code becomes `update_license_required`, `update_license_expired`, `update_license_revoked`,
 * `update_site_key_required` or `update_site_key_rejected` ({@see refusal()}); any other failure stays
 * `update_download_failed`.
 *
 * The newest release a source names must be fresh ({@see ReleaseListFreshness}, matrix 3): its signed `list_version`
 * not lower than the highest this site saw for the product and channel ({@see ReleaseListState}) and its `expires_at`
 * not passed, else `update_release_list_stale`; the update server must carry both, github.com and a folder may omit
 * them. A frozen answer is refused even when it is «not newer»: an old description must not hide a newer release.
 */
final class ReleaseSource
{
    private const MAX_DESCRIPTION_BYTES = 262144;
    private const MAX_SIGNATURE_BYTES = 4096;
    private const MAX_ENTITLEMENT_BYTES = 65536;
    private const MAX_REFUSAL_BYTES = 4096;

    /** @var list<string> */
    private array $sources;

    /** @var array<string,string> source => reason code of its last failure */
    private array $errors = [];

    private ?string $siteKey;

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
        #[\SensitiveParameter] ?string $siteKey = null,
        private readonly ?ReleaseListState $lists = null,
        private readonly ?Closure $clock = null,
    ) {
        $siteKey = $siteKey === null ? '' : trim($siteKey);
        // A bearer token: visible ASCII only, so it can never break the header or the request line.
        if ($siteKey !== '' && preg_match('/\A[\x21-\x7e]{1,4096}\z/D', $siteKey) !== 1) {
            throw new RuntimeException('update_site_key_invalid');
        }
        $this->siteKey = $siteKey === '' ? null : $siteKey;
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
                [$bytes, $signature] = $this->description($source, $this->latestBase($source));
                // Signature first, then freshness, then «newer»: a frozen answer is stale even when it is not newer.
                $this->verifier->signedBy($bytes, $signature);
                $product = $this->verifier->product();
                $listVersion = ReleaseListFreshness::check($bytes, ReleaseListFreshness::required($source), $this->now(),
                    $this->lists?->seen($product, $channel) ?? 0);
                try {
                    $release = $this->verifier->verify($bytes, $signature, $installedVersion, $channel);
                } catch (RuntimeException $exception) {
                    if ($exception->getMessage() === 'release_not_newer' && $listVersion !== null) {
                        // Product and channel held: the number is this product's.
                        $this->lists?->see($product, $channel, $listVersion);
                    }
                    throw $exception;
                }
                if ($listVersion !== null) {
                    $this->lists?->see($product, $channel, $listVersion);
                }

                return new SourceRelease($source, $release);
            } catch (Throwable $exception) {
                $reason = self::reason($exception, 'update_source_failed');
                if ($reason === 'release_not_newer') {
                    return null;
                }
                $this->errors[$source] = $reason;
            }
        }

        throw new RuntimeException($this->failure());
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

        throw new RuntimeException($this->failure());
    }

    /**
     * The site's entitlement as the first answering HTTPS source gives it at `GET <source>/entitlement`, with the
     * site key. Returned as the raw answer: only checked to be a JSON object with `schema_version` 1; reading the
     * `larena.entitlement` format is left to its owner. Folder sources and github.com have no entitlement.
     */
    public function entitlement(): string
    {
        $this->errors = [];
        foreach ($this->sources as $source) {
            if (self::folder($source) !== null || self::isGitHub($source)) {
                continue;
            }
            try {
                $response = $this->http->send(new UpdateHttpRequest(
                    'GET', $source . '/entitlement', $this->headers($source), timeoutSeconds: min(60, $this->timeoutSeconds),
                    maxBytes: self::MAX_ENTITLEMENT_BYTES, connectTimeoutSeconds: $this->connectTimeoutSeconds,
                ));
                if (!$response->successful()) {
                    throw new RuntimeException(match ($response->status) {
                        401, 403 => 'update_entitlement_refused',
                        404 => 'update_entitlement_not_found',
                        default => 'update_entitlement_failed',
                    });
                }
                try {
                    $answer = json_decode($response->body, true, 64, JSON_THROW_ON_ERROR);
                } catch (\JsonException) {
                    $answer = null;
                }
                if (!is_array($answer) || array_is_list($answer) || ($answer['schema_version'] ?? null) !== 1) {
                    throw new RuntimeException('update_entitlement_invalid');
                }

                return $response->body;
            } catch (Throwable $exception) {
                $this->errors[$source] = self::reason($exception, 'update_entitlement_failed');
            }
        }

        throw new RuntimeException($this->errors === [] ? 'update_entitlement_source_missing' : (string) reset($this->errors));
    }

    /**
     * The reason the call failed: a licence or site key refusal of any source when there is one (it is what the
     * owner of the site can act on), else the first source's reason.
     */
    private function failure(): string
    {
        foreach ($this->errors as $reason) {
            if (str_starts_with($reason, 'update_license_') || str_starts_with($reason, 'update_site_key_')) {
                return $reason;
            }
        }

        return (string) reset($this->errors);
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
        [$bytes, $signature] = $this->description($source, $base);
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
        $written = ['release.json' => $bytes, 'release.json.sig' => $signature];
        if ($release->files !== null) {
            // The signed file list: fetched up to its signed size, checked against release.json and in itself
            // before the archive is requested. An older release without `files` has no list (files null).
            $list = $folder !== null
                ? self::readFile($base . $release->files->file, $release->files->bytes)
                : $this->fetch($source, $base . rawurlencode($release->files->file), $release->files->bytes);
            FileList::forRelease($release, $list);
            $written[$release->files->file] = $list;
        }
        foreach ($written as $name => $content) {
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
                    'GET', $base . rawurlencode($release->archiveFile), $this->headers($source), timeoutSeconds: $this->timeoutSeconds,
                    maxBytes: $release->archiveBytes, sinkPath: $archive, followRedirects: true,
                    connectTimeoutSeconds: $this->connectTimeoutSeconds,
                ));
                if (!$response->successful()) {
                    ReleaseDirectory::remove($archive);
                    throw new RuntimeException($this->refusal($source, $response, 'update_download_failed'));
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

    private function fetch(string $source, string $url, int $limit): string
    {
        $response = $this->http->send(new UpdateHttpRequest(
            'GET', $url, $this->headers($source), timeoutSeconds: min(120, $this->timeoutSeconds), maxBytes: $limit,
            followRedirects: true, connectTimeoutSeconds: $this->connectTimeoutSeconds,
        ));
        if (!$response->successful()) {
            throw new RuntimeException($response->status === 404 ? 'release_files_missing' : $this->refusal($source, $response, 'update_download_failed'));
        }

        return $response->body;
    }

    /** @return array{0:string,1:string} release.json bytes, release.json.sig bytes */
    private function description(string $source, string $base): array
    {
        $files = [];
        foreach (['release.json' => self::MAX_DESCRIPTION_BYTES, 'release.json.sig' => self::MAX_SIGNATURE_BYTES] as $name => $limit) {
            if (str_starts_with($base, '/')) {
                $files[] = self::readFile($base . $name, $limit);
                continue;
            }
            $response = $this->http->send(new UpdateHttpRequest(
                'GET', $base . $name, $this->headers($source), timeoutSeconds: min(60, $this->timeoutSeconds), maxBytes: $limit,
                followRedirects: true, connectTimeoutSeconds: $this->connectTimeoutSeconds,
            ));
            if (!$response->successful()) {
                throw new RuntimeException($response->status === 404 ? 'update_release_not_found' : $this->refusal($source, $response, 'update_download_failed'));
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

    /**
     * The reason of a refused request (401/403) the site can name: the update server's refusal code (`error`, else
     * `code`, of a JSON object answer) mapped to a stable reason, else `$fallback`.
     *
     * - `license_required` (the site has no licence for the product, or the server does not know its key) →
     *   `update_license_required`; `license_expired` → `update_license_expired`; `license_revoked` →
     *   `update_license_revoked`;
     * - 401 or `site_key_required`: without a site key `update_site_key_required`, with one `update_site_key_rejected`.
     *
     * Only the code is read, from at most {@see MAX_REFUSAL_BYTES}; the body itself and the key never go into a reason.
     */
    private function refusal(string $source, UpdateHttpResponse $response, string $fallback): string
    {
        if ($response->status !== 401 && $response->status !== 403) {
            return $fallback;
        }
        $code = null;
        if ($response->body !== '' && strlen($response->body) <= self::MAX_REFUSAL_BYTES) {
            try {
                $answer = json_decode($response->body, true, 8, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                $answer = null;
            }
            if (is_array($answer) && !array_is_list($answer)) {
                $code = is_string($answer['error'] ?? null) ? $answer['error'] : (is_string($answer['code'] ?? null) ? $answer['code'] : null);
            }
        }
        $keySent = $this->headers($source) !== [];

        return match (true) {
            $code === 'license_required' => 'update_license_required',
            $code === 'license_expired' => 'update_license_expired',
            $code === 'license_revoked' => 'update_license_revoked',
            $code === 'site_key_required', $response->status === 401 => $keySent ? 'update_site_key_rejected' : 'update_site_key_required',
            default => $fallback,
        };
    }

    /** @return array<string,string> the site key header for an HTTPS source other than github.com, else none */
    private function headers(string $source): array
    {
        return $this->siteKey === null || self::folder($source) !== null || self::isGitHub($source)
            ? []
            : ['Authorization' => 'Bearer ' . $this->siteKey];
    }

    private function now(): DateTimeImmutable
    {
        $now = $this->clock !== null ? ($this->clock)() : null;

        return $now instanceof DateTimeImmutable ? $now : new DateTimeImmutable();
    }

    /** Whether a source is a github.com releases page (no site key, no freshness fields required). */
    public static function isGitHub(string $source): bool
    {
        $host = strtolower((string) parse_url($source, PHP_URL_HOST));

        return $host === 'github.com' || str_ends_with($host, '.github.com');
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
