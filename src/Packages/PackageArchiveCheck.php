<?php

declare(strict_types=1);

namespace Larena\UpdateClient\Packages;

use JsonException;
use Larena\UpdateClient\Release\ReleaseArchiveExtractor;

/**
 * Whether a package archive can replace the package folder of a built distribution without Composer on the site:
 * the distribution's autoloader (`vendor/composer`) is kept, so the package must load the same way.
 *
 * - its `composer.json` names the package and keeps the installed `autoload` and `extra.laravel` (providers);
 * - every package it requires is installed (php, extensions and Composer itself aside);
 * - every class file the class map names for it is still in the archive, and when the autoloader is authoritative no
 *   new PHP file appears under its autoload folders (it could never load).
 *
 * A package that changes any of this needs a set release. Each failure is one `update_batch_incompatible` refusal.
 */
final class PackageArchiveCheck
{
    /** @return list<array{reason:string, package:string, requirement:string}> */
    public static function check(ReleaseArchiveExtractor $extractor, PackageRelease $release, string $archivePath, InstalledPackages $installed): array
    {
        $name = $release->package;
        $composer = $extractor->entry($archivePath, 'composer.json');
        if ($composer === null) {
            return [self::refusal($name, 'composer.json is missing in the archive')];
        }
        try {
            $data = json_decode($composer, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $data = null;
        }
        if (!is_array($data) || ($data['name'] ?? null) !== $name) {
            return [self::refusal($name, 'composer.json names another package')];
        }
        $entry = $installed->composer[$name] ?? [];
        $refusals = [];
        if (self::canonical($data['autoload'] ?? []) !== self::canonical($entry['autoload'] ?? [])) {
            $refusals[] = self::refusal($name, 'autoload differs from the installed package');
        }
        $laravel = is_array($data['extra'] ?? null) ? ($data['extra']['laravel'] ?? []) : [];
        $installedLaravel = is_array($entry['extra'] ?? null) ? ($entry['extra']['laravel'] ?? []) : [];
        if (self::canonical($laravel) !== self::canonical($installedLaravel)) {
            $refusals[] = self::refusal($name, 'extra.laravel (providers) differs from the installed package');
        }
        foreach (array_keys(is_array($data['require'] ?? null) ? $data['require'] : []) as $dependency) {
            if (is_string($dependency) && preg_match('/\A(php|php-64bit|composer|composer-plugin-api|composer-runtime-api)\z|\A(ext|lib)-/D', $dependency) !== 1
                && !isset($installed->composer[$dependency])) {
                $refusals[] = self::refusal($name, 'requires ' . $dependency . ' (not installed)');
            }
        }
        if ($installed->classmap !== null) {
            $refusals = [...$refusals, ...self::classmap($extractor, $release, $archivePath, $installed, is_array($data['autoload'] ?? null) ? $data['autoload'] : [])];
        }

        return $refusals;
    }

    /**
     * @param array<array-key,mixed> $autoload
     * @return list<array{reason:string, package:string, requirement:string}>
     */
    private static function classmap(ReleaseArchiveExtractor $extractor, PackageRelease $release, string $archivePath, InstalledPackages $installed, array $autoload): array
    {
        $prefix = $release->package . '/';
        $mapped = [];
        foreach ((array) $installed->classmap as $path) {
            if (str_starts_with($path, $prefix)) {
                $mapped[substr($path, strlen($prefix))] = true;
            }
        }
        $files = [];
        foreach ($extractor->entries($archivePath) as $path => [$type]) {
            if ($type === 'file') {
                $files[$path] = true;
            }
        }
        foreach (array_keys($mapped) as $path) {
            if (!isset($files[$path])) {
                return [self::refusal($release->package, 'class file ' . $path . ' is gone (the class map names it)')];
            }
        }
        if (!$installed->classmapAuthoritative) {
            return [];
        }
        $roots = [];
        foreach (['psr-4', 'psr-0', 'classmap'] as $kind) {
            foreach ((array) ($autoload[$kind] ?? []) as $paths) {
                foreach ((array) $paths as $root) {
                    if (is_string($root)) {
                        $roots[] = trim($root, '/') === '' ? '' : trim($root, '/') . '/';
                    }
                }
            }
        }
        foreach (array_keys($files) as $path) {
            if (!str_ends_with($path, '.php') || isset($mapped[$path])) {
                continue;
            }
            foreach ($roots as $root) {
                if ($root === '' || str_starts_with($path, $root)) {
                    return [self::refusal($release->package, 'class file ' . $path . ' is not in the class map of this release')];
                }
            }
        }

        return [];
    }

    private static function canonical(mixed $value): string
    {
        $sort = static function (mixed $value) use (&$sort): mixed {
            if (!is_array($value)) {
                return $value;
            }
            if (!array_is_list($value)) {
                ksort($value, SORT_STRING);
            }

            return array_map($sort, $value);
        };

        return (string) json_encode($sort($value), JSON_UNESCAPED_SLASHES);
    }

    /** @return array{reason:string, package:string, requirement:string} */
    private static function refusal(string $package, string $requirement): array
    {
        return ['reason' => 'update_batch_incompatible', 'package' => $package, 'requirement' => $requirement];
    }
}
