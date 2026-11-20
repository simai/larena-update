<?php

declare(strict_types=1);

namespace Larena\UpdateClient\Integrity;

use Larena\UpdateClient\Release\ReleaseArchiveExtractor;
use RuntimeException;

/**
 * Where the integrity checks of one site look and keep their state (parts A2–A4 of the owner decision 2026-11-20).
 *
 * - `releaseRoot`: the running release (`<site>/releases/<release>`, Laravel's base path). Its signed folder
 *   `.larena-signed/` holds `release.json`, `release.json.sig`, `files.json` and the release archive.
 * - `dataRoots`: the folders that hold data, not code (uploads, caches, compiled views, logs), by the name a person
 *   sees (`storage/app` => absolute path). New PHP files there are findings; nothing else of their content is read.
 * - `stateDirectory`: the checks' own files (`storage/app/larena/integrity`): the per-request cache, the scan's
 *   cursor, the findings and the quarantine. It lies in the shared storage, outside the web root and outside every
 *   release, so it survives a release switch; it is never scanned itself.
 * - `sourceInstall`: the site was marked as run from its source code (env `LARENA_SOURCE_INSTALL=1`;
 *   {@see self::sourceInstallMarked()}; `APP_ENV=local` is no sign: every installer writes it today). Only this explicit sign turns the checks into «from source»;
 *   neither `.git` nor the folder layout does (any hosting: an archive unpacked into a plain folder is checked too).
 */
final readonly class IntegrityLayout
{
    public const RELEASE = 'release';

    /** @param array<string,string> $dataRoots name => absolute path */
    public function __construct(
        public string $releaseRoot,
        public string $stateDirectory,
        public array $dataRoots = [],
        public bool $sourceInstall = false,
    ) {
        if ($releaseRoot === '' || $releaseRoot[0] !== '/' || $stateDirectory === '' || $stateDirectory[0] !== '/') {
            throw new RuntimeException('integrity_layout_invalid');
        }
        foreach ($dataRoots as $name => $path) {
            if (!is_string($name) || preg_match('#\A[a-z][a-z0-9_.-]*(/[a-z0-9_.-]+)*\z#D', $name) !== 1 || $name === self::RELEASE
                || !is_string($path) || $path === '' || $path[0] !== '/') {
                throw new RuntimeException('integrity_layout_invalid');
            }
        }
    }

    /**
     * The usual folders of a Laravel site's storage: uploads and private files, the file cache, sessions, compiled
     * views and logs.
     *
     * @return array<string,string>
     */
    public static function storageRoots(string $storagePath): array
    {
        $storage = rtrim($storagePath, '/');

        return [
            'storage/app' => $storage . '/app',
            'storage/framework/cache' => $storage . '/framework/cache',
            'storage/framework/sessions' => $storage . '/framework/sessions',
            'storage/framework/views' => $storage . '/framework/views',
            'storage/logs' => $storage . '/logs',
        ];
    }

    /**
     * A site run from its source code (a developer checkout, larena.test), not from a release: it was marked so
     * explicitly ({@see self::$sourceInstall}) and has no signed folder `.larena-signed/` (not even a broken link to
     * one). Such a site has no signed list by its nature, so its files are not checked: that is information, not a
     * warning. A `.git` folder or a base path outside `<site>/releases/<release>` changes nothing: a site without the
     * sign and without a list is the warning «списка файлов нет», and a site with a signed folder is always checked.
     */
    public function sourceCheckout(): bool
    {
        $signed = $this->signedDirectory();
        if (file_exists($signed) || is_link($signed)) {
            return false;
        }

        return $this->sourceInstall;
    }

    /**
     * The explicit sign of a site run from source: `LARENA_SOURCE_INSTALL` set to a true value (1, true, yes, on).
     * Nothing on disk is consulted, nor the application environment: every installer writes `APP_ENV=local` today.
     */
    public static function sourceInstallMarked(mixed $flag): bool
    {
        return is_bool($flag) ? $flag
            : (is_int($flag) ? $flag === 1 : (is_string($flag) && in_array(strtolower(trim($flag)), ['1', 'true', 'yes', 'on'], true)));
    }

    public function signedDirectory(): string
    {
        return rtrim($this->releaseRoot, '/') . '/' . ReleaseArchiveExtractor::SIGNED;
    }

    public function file(string $name): string
    {
        return rtrim($this->stateDirectory, '/') . '/' . $name;
    }

    public function quarantineDirectory(): string
    {
        return $this->file('quarantine');
    }

    /** The absolute root of a root name: the release, or one of the data roots. */
    public function root(string $name): string
    {
        if ($name === self::RELEASE) {
            return rtrim($this->releaseRoot, '/');
        }

        return rtrim($this->dataRoots[$name] ?? throw new RuntimeException('integrity_root_unknown'), '/');
    }

    /** The absolute path of a relative path under a root; `..`, empty segments and absolute paths are refused. */
    public function path(string $root, string $relative): string
    {
        if ($relative === '' || $relative[0] === '/' || str_contains($relative, '\\') || preg_match('/[\x00-\x1f\x7f]/', $relative) === 1) {
            throw new RuntimeException('integrity_path_invalid');
        }
        foreach (explode('/', $relative) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                throw new RuntimeException('integrity_path_invalid');
            }
        }

        return $this->root($root) . '/' . $relative;
    }

    /** How a person reads a path: release paths as they are, data paths with their root's name in front. */
    public static function label(string $root, string $relative): string
    {
        return $root === self::RELEASE ? $relative : $root . '/' . $relative;
    }

    /**
     * A file's fingerprint for «did it change since it was last checked»: size, modification time, change time and
     * inode. Setting the modification time back (touch) still changes the change time, which a site cannot set.
     */
    public static function stat(string $path): ?string
    {
        // PHP keeps the last lstat() answer; the guard and each portion of the full check clear it once per pass.
        $stat = @lstat($path);
        if ($stat === false) {
            return null;
        }

        return $stat['size'] . ':' . $stat['mtime'] . ':' . $stat['ctime'] . ':' . $stat['ino'] . ':' . ($stat['mode'] & 0170000);
    }

    /**
     * Whether a fingerprint may stand for «checked»: a file written in the last two seconds can change again within the
     * same second without its fingerprint moving (times count whole seconds), so it is hashed again next time.
     */
    public static function settled(?string $stat, ?int $now = null): bool
    {
        if ($stat === null) {
            return false;
        }
        $parts = explode(':', $stat);
        $limit = ($now ?? time()) - 2;

        return count($parts) === 5 && (int) $parts[1] < $limit && (int) $parts[2] < $limit;
    }

    /** Writes a state file in one step (a temporary file renamed over it); a failure is reported, never half-written. */
    public function write(string $name, string $bytes): bool
    {
        $directory = rtrim($this->stateDirectory, '/');
        if (!is_dir($directory) && !@mkdir($directory, 0750, true) && !is_dir($directory)) {
            return false;
        }
        $path = $this->file($name);
        $temporary = $path . '.' . bin2hex(random_bytes(6)) . '.tmp';
        if (@file_put_contents($temporary, $bytes) !== strlen($bytes)) {
            @unlink($temporary);

            return false;
        }
        @chmod($temporary, 0640);
        if (!@rename($temporary, $path)) {
            @unlink($temporary);

            return false;
        }

        return true;
    }

    /** @return array<array-key,mixed>|null */
    public function read(string $name): ?array
    {
        $path = $this->file($name);
        $bytes = is_file($path) && !is_link($path) ? @file_get_contents($path) : false;
        if (!is_string($bytes) || $bytes === '') {
            return null;
        }
        $value = json_decode($bytes, true, 64);

        return is_array($value) ? $value : null;
    }
}
