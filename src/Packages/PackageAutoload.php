<?php

declare(strict_types=1);

namespace Larena\UpdateClient\Packages;

use JsonException;
use Larena\UpdateClient\Release\FileList;
use Larena\UpdateClient\Release\ReleaseArchiveExtractor;
use RuntimeException;
use stdClass;

/**
 * Adds packages the set did not have (a bought addon, a partner module) to the distribution's generated Composer
 * autoloader, without Composer on the site (the distribution forbids it at run time).
 *
 * The result is a pure function of signed inputs: the set's own files `vendor/composer/{installed.json, installed.php,
 * autoload_classmap.php, autoload_static.php}` as the signed set archive holds them ({@see originals()}), and every
 * added package — its signed release (version) and its archive (composer.json, file names). So a batch writes the
 * same bytes the integrity checks expect, and the checks can compute them again from the signed inputs alone
 * ({@see \Larena\UpdateClient\Integrity\PackageLayer}); a later batch starts from the set files again, never from
 * the files of an earlier batch.
 *
 * What an added package gets:
 * - an entry in `installed.json` (Laravel's package discovery reads its providers from there) and in `installed.php`;
 * - every class of its `autoload.psr-4` folders in the class map (`autoload_classmap.php` and the static loader), so
 *   it loads under the authoritative class map the distribution builds with. Class names come from the PSR-4 path,
 *   the names Composer's optimised dump keeps.
 *
 * Refused (`update_batch_autoload_unsupported`): any other kind of autoload (`files`, `classmap`, `psr-0`,
 * `exclude-from-classmap`), a set autoloader without the places a class map entry goes, a class name the set or
 * another added package already has.
 */
final class PackageAutoload
{
    public const INSTALLED_JSON = 'vendor/composer/installed.json';
    public const INSTALLED_PHP = 'vendor/composer/installed.php';
    public const CLASSMAP = 'vendor/composer/autoload_classmap.php';
    public const STATIC = 'vendor/composer/autoload_static.php';
    public const FILES = [self::INSTALLED_JSON, self::INSTALLED_PHP, self::CLASSMAP, self::STATIC];
    /** Laravel's cached package discovery: dropped from a release whose packages changed, rebuilt on the next boot. */
    public const DISCOVERY_CACHE = ['bootstrap/cache/packages.php', 'bootstrap/cache/services.php'];
    private const MAX_BYTES = 67108864;
    private const CLASS_SEGMENT = '/\A[A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff]*\z/D';

    /**
     * The set's generated Composer files as the signed set archive holds them, each checked against the set list.
     *
     * @return array<string,string> path => bytes (only the files the set has)
     */
    public static function originals(ReleaseArchiveExtractor $extractor, FileList $set, string $setArchive): array
    {
        $sha = !is_link($setArchive) && is_file($setArchive) ? hash_file('sha256', $setArchive) : false;
        if (!is_string($sha) || !hash_equals($set->archiveSha256, $sha)) {
            throw new RuntimeException('update_batch_set_list_missing');
        }
        $originals = [];
        foreach (self::FILES as $path) {
            $entry = $set->entries[$path] ?? null;
            if ($entry === null) {
                continue;
            }
            $bytes = $entry['type'] === 'file' ? $extractor->entry($setArchive, $path, self::MAX_BYTES) : null;
            if (!is_string($bytes) || strlen($bytes) !== $entry['bytes'] || !hash_equals((string) $entry['sha256'], hash('sha256', $bytes))) {
                throw new RuntimeException('update_batch_set_list_missing');
            }
            $originals[$path] = $bytes;
        }
        if (!isset($originals[self::INSTALLED_JSON], $originals[self::CLASSMAP])) {
            throw PackageBatchRefused::one('update_batch_autoload_unsupported', 'vendor/composer', 'the set has no installed.json or class map');
        }

        return $originals;
    }

    /**
     * What an added package brings to the autoloader, read from its archive.
     *
     * @return array{package:string, version:string, composer:stdClass, classes:array<string,string>}
     */
    public static function describe(ReleaseArchiveExtractor $extractor, PackageRelease $release, string $archivePath): array
    {
        $name = $release->package;
        $bytes = $extractor->entry($archivePath, 'composer.json');
        try {
            $composer = is_string($bytes) ? json_decode($bytes, false, 64, JSON_THROW_ON_ERROR) : null;
        } catch (JsonException) {
            $composer = null;
        }
        if (!$composer instanceof stdClass || ($composer->name ?? null) !== $name) {
            throw PackageBatchRefused::one('update_batch_incompatible', $name, 'composer.json is missing or names another package');
        }
        $autoload = isset($composer->autoload) ? (array) $composer->autoload : [];
        foreach (array_keys($autoload) as $kind) {
            if ($kind !== 'psr-4') {
                throw PackageBatchRefused::one('update_batch_autoload_unsupported', $name, 'autoload ' . $kind . ' needs a set release');
            }
        }
        $roots = [];
        foreach ((array) ($autoload['psr-4'] ?? []) as $prefix => $paths) {
            if (!is_string($prefix) || ($prefix !== '' && !str_ends_with($prefix, '\\'))) {
                throw PackageBatchRefused::one('update_batch_autoload_unsupported', $name, 'psr-4 prefix ' . $prefix);
            }
            foreach ((array) $paths as $path) {
                if (!is_string($path) || str_contains($path, '..') || str_starts_with($path, '/')) {
                    throw PackageBatchRefused::one('update_batch_autoload_unsupported', $name, 'psr-4 path');
                }
                $roots[] = [$prefix, trim($path, '/') === '' ? '' : trim($path, '/') . '/'];
            }
        }
        $classes = [];
        foreach ($extractor->entries($archivePath) as $path => [$type]) {
            if ($type !== 'file' || !str_ends_with($path, '.php')) {
                continue;
            }
            foreach ($roots as [$prefix, $root]) {
                if ($root !== '' && !str_starts_with($path, $root)) {
                    continue;
                }
                $segments = explode('/', substr($path, strlen($root), -4));
                if (array_filter($segments, static fn (string $segment): bool => preg_match(self::CLASS_SEGMENT, $segment) !== 1) !== []) {
                    continue;
                }
                $class = $prefix . implode('\\', $segments);
                if (isset($classes[$class])) {
                    continue;
                }
                $classes[$class] = $path;
            }
        }
        ksort($classes, SORT_STRING);

        return ['package' => $name, 'version' => $release->version, 'composer' => $composer, 'classes' => $classes];
    }

    /**
     * The set's generated files with every added package in them.
     *
     * @param array<string,string>                                                                  $originals {@see originals()}
     * @param list<array{package:string, version:string, composer:stdClass, classes:array<string,string>}> $added  {@see describe()}
     * @return array<string,string> path => bytes
     */
    public static function render(array $originals, array $added): array
    {
        usort($added, static fn (array $a, array $b): int => strcmp($a['package'], $b['package']));
        $taken = self::classes($originals[self::CLASSMAP] ?? '');
        $map = [];
        foreach ($added as $package) {
            foreach ($package['classes'] as $class => $path) {
                if (isset($taken[$class]) || isset($map[$class])) {
                    throw PackageBatchRefused::one('update_batch_autoload_unsupported', $package['package'], 'class ' . $class . ' is already in the site');
                }
                $map[$class] = '/' . $package['package'] . '/' . $path;
            }
        }
        ksort($map, SORT_STRING);
        $out = [];
        $out[self::INSTALLED_JSON] = self::installedJson($originals[self::INSTALLED_JSON], $added);
        $classmap = '';
        $static = '';
        foreach ($map as $class => $path) {
            $classmap .= '    ' . var_export($class, true) . ' => $vendorDir . ' . var_export($path, true) . ",\n";
            $static .= '        ' . var_export($class, true) . " => __DIR__ . '/..' . " . var_export($path, true) . ",\n";
        }
        $out[self::CLASSMAP] = self::insert($originals[self::CLASSMAP], "return array(\n", $classmap);
        if (isset($originals[self::STATIC])) {
            $out[self::STATIC] = self::insert($originals[self::STATIC], "    public static \$classMap = array (\n", $static);
        }
        if (isset($originals[self::INSTALLED_PHP])) {
            $versions = '';
            foreach ($added as $package) {
                $versions .= '        ' . var_export($package['package'], true) . " => array(\n"
                    . "            'pretty_version' => " . var_export($package['version'], true) . ",\n"
                    . "            'version' => " . var_export($package['version'] . '.0', true) . ",\n"
                    . "            'reference' => null,\n"
                    . "            'type' => " . var_export(self::type($package['composer']), true) . ",\n"
                    . "            'install_path' => __DIR__ . " . var_export('/../' . $package['package'], true) . ",\n"
                    . "            'aliases' => array(),\n"
                    . "            'dev_requirement' => false,\n"
                    . "        ),\n";
            }
            $out[self::INSTALLED_PHP] = self::insert($originals[self::INSTALLED_PHP], "    'versions' => array(\n", $versions);
        }

        return $out;
    }

    /**
     * Class names the class map already has.
     *
     * @return array<string,true>
     */
    private static function classes(string $classmap): array
    {
        preg_match_all("/^\\s*'((?:[^'\\\\]|\\\\.)+)'\\s*=>/m", $classmap, $matches);
        $names = [];
        foreach ($matches[1] as $quoted) {
            // PHP's single quotes: a doubled backslash is one backslash, a backslash before a quote is the quote.
            $names[strtr($quoted, ['\\\\' => '\\', "\\'" => "'"])] = true;
        }

        return $names;
    }

    /** @param list<array{package:string, version:string, composer:stdClass, classes:array<string,string>}> $added */
    private static function installedJson(string $original, array $added): string
    {
        try {
            $data = json_decode($original, false, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new RuntimeException('update_batch_installed_unreadable');
        }
        $wrapped = $data instanceof stdClass;
        $packages = $wrapped ? ($data->packages ?? null) : $data;
        if (!is_array($packages)) {
            throw new RuntimeException('update_batch_installed_unreadable');
        }
        foreach ($packages as $entry) {
            foreach ($added as $package) {
                if ($entry instanceof stdClass && ($entry->name ?? null) === $package['package']) {
                    throw PackageBatchRefused::one('update_batch_incompatible', $package['package'], 'installed: the set already has the package');
                }
            }
        }
        foreach ($added as $package) {
            $composer = $package['composer'];
            $entry = new stdClass();
            $entry->name = $package['package'];
            $entry->version = $package['version'];
            $entry->version_normalized = $package['version'] . '.0';
            if (isset($composer->require)) {
                $entry->require = $composer->require;
            }
            $entry->type = self::type($composer);
            if (isset($composer->extra)) {
                $entry->extra = $composer->extra;
            }
            if (isset($composer->autoload)) {
                $entry->autoload = $composer->autoload;
            }
            $entry->{'install-path'} = '../' . $package['package'];
            $packages[] = $entry;
        }
        usort($packages, static fn (mixed $a, mixed $b): int => strcmp((string) ($a->name ?? ''), (string) ($b->name ?? '')));
        if ($wrapped) {
            $data->packages = $packages;
        } else {
            $data = $packages;
        }

        return json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
    }

    private static function type(stdClass $composer): string
    {
        return is_string($composer->type ?? null) && preg_match('/\A[a-z0-9-]{1,40}\z/D', $composer->type) === 1 ? $composer->type : 'library';
    }

    private static function insert(string $bytes, string $anchor, string $lines): string
    {
        $at = strpos($bytes, $anchor);
        if ($at === false || strpos($bytes, $anchor, $at + 1) !== false) {
            throw PackageBatchRefused::one('update_batch_autoload_unsupported', 'vendor/composer', 'the generated autoloader has an unknown shape');
        }

        return substr($bytes, 0, $at + strlen($anchor)) . $lines . substr($bytes, $at + strlen($anchor));
    }
}
