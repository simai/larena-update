<?php

declare(strict_types=1);

namespace Larena\UpdateClient\Release;

use JsonException;
use RuntimeException;

/**
 * The list of every file of one root: a Larena release, or one installable package. Written by the release build
 * next to the archive as `files.json`, schema `larena.release-files`:
 *
 *   {"schema": "larena.release-files", "schema_version": 1,
 *    "product": "larena" | "<vendor>/<package>", "version": "1.3.0", "archive_sha256": "<hex>",
 *    "entries": [{"path": "app", "type": "dir", "bytes": 0},
 *                {"path": "app/Version.php", "type": "file", "bytes": 21, "sha256": "<hex>"},
 *                {"path": "public/latest", "type": "link", "bytes": 6, "target": "assets"}, …],
 *    "core_files": ["bootstrap/app.php", "config/app.php", "public/.htaccess", "public/index.php", "vendor/autoload.php"]}
 *
 * Rules, all fail closed with a stable reason code and no secret in it:
 * - paths are already normalised and relative: no leading `/` or drive letter (`release_files_path_absolute`), no `..`
 *   segment (`release_files_path_traversal`), no empty or `.` segment, leading `./`, trailing `/`, backslash, control
 *   byte or invalid UTF-8 (`release_files_path_invalid`). The list is checked as written; it is never re-normalised,
 *   so one list has one meaning;
 * - entries are sorted by path in byte order (`release_files_entry_order`) and each path is there once
 *   (`release_files_entry_duplicate`);
 * - a link stays inside the root: a relative target that does not climb above the root, and no entry under a link
 *   or a target through another link (`release_files_link_escape`). Release archives carry no links at all
 *   ({@see ReleaseArchiveExtractor}); a package list may;
 * - `core_files` names file entries of the list, sorted, once each (`release_files_core_invalid`). A later part checks
 *   them on every request.
 *
 * A release list is trusted through the signed `release.json` that names its size and sha256
 * ({@see forRelease()}). A package list is the same format with the package name as product; its author can sign it
 * later with a detached `files.json.sig` in the `release.json.sig` shape, and {@see parse()} needs no release for it.
 */
final readonly class FileList
{
    public const SCHEMA = 'larena.release-files';
    public const SCHEMA_VERSION = 1;
    public const FILE = 'files.json';
    public const MAX_BYTES = 67108864;
    public const MAX_ENTRIES = 200000;

    /**
     * @param array<string,array{type:string,bytes:int,sha256:?string,target:?string}> $entries path => entry, sorted
     * @param list<string>                                                              $coreFiles
     */
    private function __construct(
        public string $product,
        public string $version,
        public string $archiveSha256,
        public array $entries,
        public array $coreFiles,
        public string $bytes,
        public string $sha256,
    ) {
    }

    /**
     * Reads and checks a list. Expected product, version and archive digest, when given, must match exactly.
     */
    public static function parse(string $bytes, ?string $product = null, ?string $version = null, ?string $archiveSha256 = null): self
    {
        if (strlen($bytes) < 2 || strlen($bytes) > self::MAX_BYTES) {
            throw new RuntimeException('release_files_list_invalid');
        }
        try {
            $list = json_decode($bytes, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new RuntimeException('release_files_list_invalid');
        }
        if (!is_array($list)) {
            throw new RuntimeException('release_files_list_invalid');
        }
        if (($list['schema'] ?? null) !== self::SCHEMA || ($list['schema_version'] ?? null) !== self::SCHEMA_VERSION) {
            throw new RuntimeException('release_files_schema_unsupported');
        }
        $listProduct = $list['product'] ?? null;
        $listVersion = $list['version'] ?? null;
        $listArchive = $list['archive_sha256'] ?? null;
        if (!is_string($listProduct) || preg_match('#\A[a-z0-9][a-z0-9_.-]{0,99}(/[a-z0-9][a-z0-9_.-]{0,99})?\z#D', $listProduct) !== 1
            || !is_string($listVersion) || preg_match('/\A[0-9A-Za-z][0-9A-Za-z.+_-]{0,63}\z/D', $listVersion) !== 1
            || !is_string($listArchive) || preg_match('/\A[a-f0-9]{64}\z/D', $listArchive) !== 1
            || !is_array($list['entries'] ?? null) || !array_is_list($list['entries'])
            || !is_array($list['core_files'] ?? null) || !array_is_list($list['core_files'])) {
            throw new RuntimeException('release_files_list_invalid');
        }
        if ($product !== null && $listProduct !== $product) {
            throw new RuntimeException('release_files_product_differs');
        }
        if ($version !== null && $listVersion !== $version) {
            throw new RuntimeException('release_files_version_differs');
        }
        if ($archiveSha256 !== null && !hash_equals($archiveSha256, $listArchive)) {
            throw new RuntimeException('release_files_archive_differs');
        }
        if (count($list['entries']) > self::MAX_ENTRIES) {
            throw new RuntimeException('release_files_list_invalid');
        }

        $entries = [];
        $previous = null;
        foreach ($list['entries'] as $entry) {
            if (!is_array($entry)) {
                throw new RuntimeException('release_files_list_invalid');
            }
            $path = self::path($entry['path'] ?? null);
            if (isset($entries[$path])) {
                throw new RuntimeException('release_files_entry_duplicate');
            }
            if ($previous !== null && strcmp($previous, $path) >= 0) {
                throw new RuntimeException('release_files_entry_order');
            }
            $previous = $path;
            $entries[$path] = self::entry($entry);
        }
        foreach ($entries as $path => $entry) {
            self::insideRoot($entries, $path, $entry);
        }

        $core = [];
        foreach ($list['core_files'] as $path) {
            if (!is_string($path) || ($entries[$path]['type'] ?? null) !== 'file' || ($core !== [] && strcmp((string) end($core), $path) >= 0)) {
                throw new RuntimeException('release_files_core_invalid');
            }
            $core[] = $path;
        }

        return new self($listProduct, $listVersion, $listArchive, $entries, $core, $bytes, hash('sha256', $bytes));
    }

    /** The list a verified release names: size and sha256 from the signed `release.json`, then {@see parse()} bound to it. */
    public static function forRelease(VerifiedRelease $release, string $bytes): self
    {
        $reference = $release->files ?? throw new RuntimeException('release_files_not_signed');
        if (strlen($bytes) !== $reference->bytes) {
            throw new RuntimeException('release_files_size_differs');
        }
        if (!hash_equals($reference->sha256, hash('sha256', $bytes))) {
            throw new RuntimeException('release_files_digest_differs');
        }

        return self::parse($bytes, $release->product, $release->version, $release->archiveSha256);
    }

    /**
     * The list that lies next to a release archive (the layout of a release page and of the download folder), or
     * null for a release built before file lists, whose `release.json` has no `files`.
     */
    public static function beside(VerifiedRelease $release, string $archivePath): ?self
    {
        if ($release->files === null) {
            return null;
        }
        $path = dirname($archivePath) . '/' . $release->files->file;
        if (is_link($path)) {
            throw new RuntimeException('release_files_missing');
        }
        if (!is_file($path)) {
            throw new RuntimeException('release_files_missing');
        }
        if (filesize($path) !== $release->files->bytes) {
            throw new RuntimeException('release_files_size_differs');
        }
        $bytes = file_get_contents($path);
        if (!is_string($bytes)) {
            throw new RuntimeException('release_files_missing');
        }

        return self::forRelease($release, $bytes);
    }

    /**
     * Compares the entries of an archive with the list, before anything is unpacked: every archive entry is listed
     * with the same type and size, and every listed entry is in the archive.
     *
     * @param array<string,array{0:string,1:int}> $archive normalised path => [type, bytes]
     */
    public function matchArchive(array $archive): void
    {
        foreach ($archive as $path => [$type, $bytes]) {
            $entry = $this->entries[$path] ?? throw new RuntimeException('release_files_entry_extra');
            if ($entry['type'] !== $type) {
                throw new RuntimeException('release_files_entry_type_differs');
            }
            if ($entry['bytes'] !== $bytes) {
                throw new RuntimeException('release_files_entry_size_differs');
            }
        }
        foreach ($this->entries as $path => $entry) {
            if (!isset($archive[$path])) {
                throw new RuntimeException('release_files_entry_missing');
            }
        }
    }

    /**
     * Checks one root against the list: every entry there with its type, size and (when `$hashes`) sha256. Links are
     * never followed. Files the list does not name are not looked for here.
     *
     * @return array<string,string> path => `missing` | `type_differs` | `size_differs` | `checksum_differs` | `target_differs`
     */
    public function check(string $root, bool $hashes = true): array
    {
        $root = rtrim($root, '/');
        if ($root === '' || is_link($root) || !is_dir($root)) {
            throw new RuntimeException('release_files_root_invalid');
        }
        $problems = [];
        foreach ($this->entries as $path => $entry) {
            $full = $root . '/' . $path;
            clearstatcache(true, $full);
            if (!is_link($full) && !file_exists($full)) {
                $problems[$path] = 'missing';
                continue;
            }
            $problem = match ($entry['type']) {
                'dir' => is_link($full) || !is_dir($full) ? 'type_differs' : null,
                'link' => !is_link($full) ? 'type_differs' : (readlink($full) !== $entry['target'] ? 'target_differs' : null),
                default => is_link($full) || !is_file($full) ? 'type_differs'
                    : (filesize($full) !== $entry['bytes'] ? 'size_differs'
                        : ($hashes && !hash_equals((string) $entry['sha256'], (string) hash_file('sha256', $full)) ? 'checksum_differs' : null)),
            };
            if ($problem !== null) {
                $problems[$path] = $problem;
            }
        }

        return $problems;
    }

    private static function path(mixed $path): string
    {
        if (!is_string($path) || $path === '' || strlen($path) > 4096) {
            throw new RuntimeException('release_files_path_invalid');
        }
        if ($path[0] === '/' || preg_match('/\A[A-Za-z]:/', $path) === 1) {
            throw new RuntimeException('release_files_path_absolute');
        }
        if (str_contains($path, '\\') || preg_match('/[\x00-\x1f\x7f]/', $path) === 1 || preg_match('//u', $path) !== 1) {
            throw new RuntimeException('release_files_path_invalid');
        }
        $segments = explode('/', $path);
        if (in_array('..', $segments, true)) {
            throw new RuntimeException('release_files_path_traversal');
        }
        if (in_array('', $segments, true) || in_array('.', $segments, true)) {
            throw new RuntimeException('release_files_path_invalid');
        }

        return $path;
    }

    /**
     * @param array<array-key,mixed> $entry
     * @return array{type:string,bytes:int,sha256:?string,target:?string}
     */
    private static function entry(array $entry): array
    {
        $type = $entry['type'] ?? null;
        $bytes = $entry['bytes'] ?? null;
        $sha = $entry['sha256'] ?? null;
        $target = $entry['target'] ?? null;
        if (!is_int($bytes) || $bytes < 0) {
            throw new RuntimeException('release_files_list_invalid');
        }
        $valid = match ($type) {
            'file' => is_string($sha) && preg_match('/\A[a-f0-9]{64}\z/D', $sha) === 1 && $target === null,
            'dir' => $bytes === 0 && $sha === null && $target === null,
            'link' => $sha === null && is_string($target) && $target !== '' && strlen($target) === $bytes,
            default => false,
        };
        if (!$valid) {
            throw new RuntimeException('release_files_list_invalid');
        }

        return ['type' => $type, 'bytes' => $bytes, 'sha256' => $sha, 'target' => $target];
    }

    /**
     * No entry lies under a link or a file, and a link resolves inside the root without passing another link.
     *
     * @param array<string,array{type:string,bytes:int,sha256:?string,target:?string}> $entries
     * @param array{type:string,bytes:int,sha256:?string,target:?string}               $entry
     */
    private static function insideRoot(array $entries, string $path, array $entry): void
    {
        $segments = explode('/', $path);
        $prefix = '';
        foreach (array_slice($segments, 0, -1) as $segment) {
            $prefix = $prefix === '' ? $segment : $prefix . '/' . $segment;
            $type = $entries[$prefix]['type'] ?? 'dir';
            if ($type === 'link') {
                throw new RuntimeException('release_files_link_escape');
            }
            if ($type === 'file') {
                throw new RuntimeException('release_files_path_invalid');
            }
        }
        if ($entry['type'] !== 'link') {
            return;
        }
        $target = (string) $entry['target'];
        if ($target[0] === '/' || str_contains($target, '\\') || preg_match('/\A[A-Za-z]:|[\x00-\x1f\x7f]/', $target) === 1) {
            throw new RuntimeException('release_files_link_escape');
        }
        $resolved = array_slice($segments, 0, -1);
        foreach (explode('/', $target) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                if ($resolved === []) {
                    throw new RuntimeException('release_files_link_escape');
                }
                array_pop($resolved);
                continue;
            }
            $resolved[] = $segment;
            if (($entries[implode('/', $resolved)]['type'] ?? null) === 'link') {
                throw new RuntimeException('release_files_link_escape');
            }
        }
    }
}
