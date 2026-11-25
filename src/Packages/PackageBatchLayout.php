<?php

declare(strict_types=1);

namespace Larena\UpdateClient\Packages;

use Larena\UpdateClient\Release\ReleaseArchiveExtractor;
use Larena\UpdateClient\Release\ReleaseDirectory;
use Larena\UpdateClient\Release\ReleaseSharedLinks;
use RuntimeException;
use Throwable;

/**
 * Lays out the one new release folder of a batch: `<site>/releases/<release>` = a copy of the running release with
 * the folder of every batch package (`vendor/<vendor>/<name>`) replaced by its checked archive. The same layout as a
 * set release, so the switch and the rollback stay one move of `current`; the running release is only read.
 *
 * Built in `releases/.<release>.partial` and renamed into place in one step. Not copied: the shared links (`.env`,
 * `storage`, `public/storage`, linked again by {@see ReleaseSharedLinks}), the release marker and the batch record
 * (written anew), and the set release's signed folder `.larena-signed/`: its file list describes the set, not this
 * release, and «Вернуть как было» must never put the old package files back. A link inside the release is copied as a
 * link only when it is relative and stays inside the release (`update_batch_layout_invalid` otherwise).
 *
 * The new release keeps {@see InstalledPackages::RECORD}: for every package a batch installed (this one and earlier
 * ones) its version, publication date, exact signed release and signature, and the list version. Its marker
 * `.larena-release` keeps the product version, the set archive's checksum and the record's checksum; running again for
 * the same batch returns the existing folder, any other folder under the name is `release_directory_differs`.
 */
final class PackageBatchLayout
{
    private const NOT_COPIED = ['.env', 'storage', 'public/storage', ReleaseArchiveExtractor::MARKER, ReleaseArchiveExtractor::SIGNED, InstalledPackages::RECORD];

    /** @param array<string,string> $archives package => checked archive path */
    public static function build(ReleaseArchiveExtractor $extractor, string $siteRoot, CheckedPackageBatch $batch, string $releaseId, array $archives): string
    {
        $site = ReleaseDirectory::site($siteRoot);
        $source = $site . '/releases/' . ReleaseDirectory::id($batch->from);
        $id = ReleaseDirectory::id($releaseId);
        if (is_link($source) || !is_dir($source)) {
            throw new RuntimeException('release_directory_missing');
        }
        $record = self::record($batch);
        $marker = json_encode([
            'version' => $batch->installed->productVersion,
            'archive_sha256' => self::setArchive($source),
            'packages_sha256' => hash('sha256', $record),
        ], JSON_THROW_ON_ERROR) . "\n";
        $target = $site . '/releases/' . $id;
        if (is_link($target) || file_exists($target)) {
            $existing = !is_link($target) && is_file($target . '/' . ReleaseArchiveExtractor::MARKER) && !is_link($target . '/' . ReleaseArchiveExtractor::MARKER)
                ? file_get_contents($target . '/' . ReleaseArchiveExtractor::MARKER) : false;
            if ($existing === $marker) {
                return $target;
            }
            throw new RuntimeException('release_directory_differs');
        }

        $skip = array_fill_keys(self::NOT_COPIED, true);
        foreach ($batch->releases as $release) {
            $skip['vendor/' . $release->package] = true;
        }
        $partial = $site . '/releases/.' . $id . '.partial';
        ReleaseDirectory::remove($partial);
        if (!mkdir($partial, 0755)) {
            throw new RuntimeException('release_layout_invalid');
        }
        try {
            self::copy($source, $partial, '', $skip);
            foreach ($batch->releases as $release) {
                $archive = $archives[$release->package] ?? throw new RuntimeException('update_batch_signature_invalid');
                self::verifyArchive($release, $archive);
                $directory = $partial . '/vendor/' . $release->package;
                if (!is_dir(dirname($directory)) && !mkdir(dirname($directory), 0755, true)) {
                    throw new RuntimeException('release_directory_write_failed');
                }
                if (file_exists($directory) || is_link($directory) || !mkdir($directory, 0755)) {
                    throw new RuntimeException('release_directory_write_failed');
                }
                $extractor->unpackInto($archive, $directory);
            }
            if (file_put_contents($partial . '/' . InstalledPackages::RECORD, $record) !== strlen($record)
                || file_put_contents($partial . '/' . ReleaseArchiveExtractor::MARKER, $marker) !== strlen($marker)
                || !rename($partial, $target)) {
                throw new RuntimeException('release_directory_write_failed');
            }
        } catch (Throwable $exception) {
            ReleaseDirectory::remove($partial);
            throw $exception;
        }

        return $target;
    }

    /** Bytes of a release folder as the batch copies it (links and the signed set folder aside). */
    public static function size(string $releaseDirectory): int
    {
        $total = 0;
        $walk = static function (string $directory, string $relative) use (&$walk, &$total): void {
            foreach ((array) scandir($directory) as $name) {
                if (!is_string($name) || $name === '.' || $name === '..') {
                    continue;
                }
                $path = $directory . '/' . $name;
                $child = $relative === '' ? $name : $relative . '/' . $name;
                if (is_link($path) || in_array($child, self::NOT_COPIED, true)) {
                    continue;
                }
                if (is_dir($path)) {
                    $walk($path, $child);
                } elseif (is_file($path)) {
                    $total += (int) filesize($path);
                }
            }
        };
        if (!is_link($releaseDirectory) && is_dir($releaseDirectory)) {
            $walk(rtrim($releaseDirectory, '/'), '');
        }

        return $total;
    }

    /** Checks a package archive against its signed release: size, then sha256 (`update_batch_signature_invalid`). */
    public static function verifyArchive(PackageRelease $release, string $path): void
    {
        $sha = !is_link($path) && is_file($path) && filesize($path) === $release->archiveBytes ? hash_file('sha256', $path) : false;
        if (!is_string($sha) || !hash_equals($release->archiveSha256, $sha)) {
            throw PackageBatchRefused::one('update_batch_signature_invalid', $release->package, 'archive does not match its signed sha256');
        }
    }

    private static function record(CheckedPackageBatch $batch): string
    {
        $packages = $batch->installed->kept;
        foreach ($batch->releases as $release) {
            $packages[$release->package] = [
                'version' => $release->version,
                'released_at' => $release->releasedAt->format(DATE_ATOM),
                'release' => $release->releaseBytes,
                'signature' => $release->signatureBytes,
            ];
        }
        ksort($packages, SORT_STRING);

        return json_encode([
            'schema' => InstalledPackages::RECORD_SCHEMA,
            'schema_version' => 1,
            'list_version' => $batch->list->listVersion,
            'list_sha256' => $batch->list->sha256,
            'packages' => $packages,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
    }

    /** The set archive's sha256 the running release came from, kept through every batch. */
    private static function setArchive(string $source): ?string
    {
        $marker = $source . '/' . ReleaseArchiveExtractor::MARKER;
        $data = is_file($marker) && !is_link($marker) ? json_decode((string) file_get_contents($marker, length: 4096), true) : null;
        $sha = is_array($data) ? ($data['archive_sha256'] ?? null) : null;

        return is_string($sha) && preg_match('/\A[a-f0-9]{64}\z/D', $sha) === 1 ? $sha : null;
    }

    /** @param array<string,true> $skip relative paths not copied */
    private static function copy(string $from, string $to, string $relative, array $skip): void
    {
        $names = scandir($from);
        if ($names === false) {
            throw new RuntimeException('update_batch_layout_invalid');
        }
        foreach ($names as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            $child = $relative === '' ? $name : $relative . '/' . $name;
            if (isset($skip[$child])) {
                continue;
            }
            $source = $from . '/' . $name;
            $target = $to . '/' . $name;
            if (is_link($source)) {
                $link = (string) readlink($source);
                if (!self::inside($child, $link) || !symlink($link, $target)) {
                    throw new RuntimeException('update_batch_layout_invalid');
                }
            } elseif (is_dir($source)) {
                if (!mkdir($target, fileperms($source) & 0777)) {
                    throw new RuntimeException('release_directory_write_failed');
                }
                self::copy($source, $target, $child, $skip);
            } elseif (is_file($source)) {
                if (!copy($source, $target) || !chmod($target, fileperms($source) & 0777)) {
                    throw new RuntimeException('release_directory_write_failed');
                }
            } else {
                throw new RuntimeException('update_batch_layout_invalid');
            }
        }
    }

    /** Whether a relative link at `$path` (relative to the release) stays inside the release. */
    private static function inside(string $path, string $link): bool
    {
        if ($link === '' || $link[0] === '/' || str_contains($link, "\0")) {
            return false;
        }
        $parts = explode('/', dirname($path) === '.' ? '' : dirname($path));
        $parts = array_values(array_filter($parts, static fn (string $part): bool => $part !== ''));
        foreach (explode('/', $link) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                if ($parts === []) {
                    return false;
                }
                array_pop($parts);
                continue;
            }
            $parts[] = $segment;
        }

        return true;
    }
}
