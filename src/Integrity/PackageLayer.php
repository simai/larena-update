<?php

declare(strict_types=1);

namespace Larena\UpdateClient\Integrity;

use JsonException;
use Larena\UpdateClient\Packages\InstalledPackages;
use Larena\UpdateClient\Packages\PackageAutoload;
use Larena\UpdateClient\Packages\PackageListVerifier;
use Larena\UpdateClient\Packages\PackageRelease;
use Larena\UpdateClient\Release\FileList;
use Larena\UpdateClient\Release\ReleaseArchiveExtractor;
use Larena\UpdateClient\Release\ReleaseSignatureVerifier;
use RuntimeException;
use Throwable;

/**
 * The package releases a batch installed, laid over the set list of a release (owner variant D, follow-up
 * 2026-11-26): after a batch the integrity checks compare `vendor/<vendor>/<name>` with the package release that is
 * there, not with the set, so they report real results instead of «списка файлов нет».
 *
 * Read from the release folder, trusted only through signatures:
 * - `.larena-packages.json` ({@see InstalledPackages::RECORD}) names each package and keeps its exact signed release
 *   and signature; each is verified again with the site's release keys ({@see PackageListVerifier::verifyRelease()});
 * - `.larena-packages/<vendor>/<name>/<archive>` ({@see ARCHIVES}) is that package's archive, checked against the
 *   size and sha256 of its signed release; its entries (with the sha256 of every file) are the package's file list;
 * - for a package the set did not have (`added` in the record), the generated Composer files are computed again
 *   from the set archive and the package archives ({@see PackageAutoload}) and checked against those bytes.
 *
 * Anything that does not verify makes the whole list untrusted (`integrity_list_untrusted` with the reason): the
 * checks never guess what a package should contain.
 */
final readonly class PackageLayer
{
    /** The folder in a batch release that keeps the archive of every package a batch installed. */
    public const ARCHIVES = '.larena-packages';

    /**
     * @param array<string,array{release:PackageRelease, archive:string, list:FileList}> $packages
     * @param array<string,string>                                                         $generated path => expected bytes
     */
    private function __construct(
        public array $packages,
        public array $generated,
        public string $digest,
    ) {
    }

    /** The record's fingerprint (size, times, inode), or null when the release has none. */
    public static function fingerprint(IntegrityLayout $layout): ?string
    {
        return IntegrityLayout::stat(rtrim($layout->releaseRoot, '/') . '/' . InstalledPackages::RECORD);
    }

    /** The kept archive of a package in a release folder. */
    public static function archivePath(string $releaseRoot, string $package, string $file): string
    {
        return rtrim($releaseRoot, '/') . '/' . self::ARCHIVES . '/' . $package . '/' . $file;
    }

    /** The layer of the release, or null when no batch installed anything there. */
    public static function load(IntegrityLayout $layout, ReleaseSignatureVerifier $verifier, FileList $set, string $setArchive,
        ReleaseArchiveExtractor $extractor = new ReleaseArchiveExtractor()): ?self
    {
        $root = rtrim($layout->releaseRoot, '/');
        $path = $root . '/' . InstalledPackages::RECORD;
        if (!is_link($path) && !file_exists($path)) {
            return null;
        }
        $bytes = !is_link($path) && is_file($path) ? @file_get_contents($path) : false;
        try {
            $record = is_string($bytes) ? json_decode($bytes, true, 16, JSON_THROW_ON_ERROR) : null;
        } catch (JsonException) {
            $record = null;
        }
        if (!is_array($record) || ($record['schema'] ?? null) !== InstalledPackages::RECORD_SCHEMA || !is_array($record['packages'] ?? null)) {
            throw new RuntimeException('integrity_packages_unreadable');
        }
        $releases = new PackageListVerifier($verifier);
        $packages = [];
        $added = [];
        foreach ($record['packages'] as $name => $item) {
            if (!is_string($name) || !is_array($item) || !is_string($item['release'] ?? null) || !is_string($item['signature'] ?? null)) {
                throw new RuntimeException('integrity_packages_unreadable');
            }
            try {
                $release = $releases->verifyRelease($item['release'], $item['signature']);
            } catch (Throwable) {
                throw new RuntimeException('integrity_package_untrusted');
            }
            if ($release->package !== $name || ($item['version'] ?? null) !== $release->version) {
                throw new RuntimeException('integrity_package_untrusted');
            }
            $archive = self::archivePath($root, $name, $release->archiveFile);
            $sha = !is_link($archive) && is_file($archive) && filesize($archive) === $release->archiveBytes ? hash_file('sha256', $archive) : false;
            if (!is_string($sha) || !hash_equals($release->archiveSha256, $sha)) {
                throw new RuntimeException(is_file($archive) ? 'integrity_package_archive_damaged' : 'integrity_package_archive_missing');
            }
            $entries = [];
            foreach ($extractor->digests($archive) as $entry => [$type, $size, $digest]) {
                $entries[$entry] = ['type' => $type, 'bytes' => $size, 'sha256' => $digest, 'target' => null];
            }
            $packages[$name] = ['release' => $release, 'archive' => $archive,
                'list' => FileList::compose($name, $release->version, $release->archiveSha256, $entries)];
            if (($item['added'] ?? false) === true) {
                $added[] = PackageAutoload::describe($extractor, $release, $archive);
            }
        }
        ksort($packages, SORT_STRING);
        $generated = $added === [] ? [] : PackageAutoload::render(PackageAutoload::originals($extractor, $set, $setArchive), $added);

        return new self($packages, $generated, hash('sha256', (string) $bytes));
    }

    /** The set list with every package folder replaced by its package list and the generated files by their bytes. */
    public function apply(FileList $set): FileList
    {
        $entries = $set->entries;
        foreach ($this->packages as $name => $package) {
            $prefix = 'vendor/' . $name . '/';
            foreach (array_keys($entries) as $path) {
                if (str_starts_with((string) $path, $prefix)) {
                    unset($entries[$path]);
                }
            }
            $entries['vendor'] ??= ['type' => 'dir', 'bytes' => 0, 'sha256' => null, 'target' => null];
            $entries['vendor/' . explode('/', $name)[0]] = ['type' => 'dir', 'bytes' => 0, 'sha256' => null, 'target' => null];
            $entries['vendor/' . $name] = ['type' => 'dir', 'bytes' => 0, 'sha256' => null, 'target' => null];
            foreach ($package['list']->entries as $path => $entry) {
                $entries[$prefix . $path] = $entry;
            }
        }
        foreach ($this->generated as $path => $bytes) {
            $entries[$path] = ['type' => 'file', 'bytes' => strlen($bytes), 'sha256' => hash('sha256', $bytes), 'target' => null];
        }
        $core = array_values(array_filter($set->coreFiles, static fn (string $path): bool => ($entries[$path]['type'] ?? null) === 'file'));

        return FileList::compose($set->product, $set->version, $set->archiveSha256, $entries, $core);
    }

    /**
     * Where a clean copy of a release file comes from: `['package', <name>, <path in the package>]`,
     * `['generated']`, or `['set']`.
     *
     * @return array{0:string,1?:string,2?:string}
     */
    public function source(string $path): array
    {
        if (isset($this->generated[$path])) {
            return ['generated'];
        }
        foreach (array_keys($this->packages) as $name) {
            $prefix = 'vendor/' . $name . '/';
            if (str_starts_with($path, $prefix)) {
                return ['package', $name, substr($path, strlen($prefix))];
            }
        }

        return ['set'];
    }
}
