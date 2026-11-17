<?php

declare(strict_types=1);

namespace Larena\UpdateClient\Release;

use RuntimeException;
use Throwable;

/**
 * Unpacks a verified release archive (`.tar.gz`, as named by {@see ReleaseSignatureVerifier}) into
 * `<site>/releases/<release>/`. Entries are at the archive root (a leading `./` is accepted).
 *
 * The archive is checked again with {@see ReleaseSignatureVerifier::verifyArchive()} right before reading, then
 * unpacked by a small tar reader into `<site>/releases/.<release>.partial` and renamed into place in one step, so a
 * half-written release never carries the final name. Only regular files and directories are written:
 *
 * - `release_archive_path_invalid`  — absolute path, `..` or `.` segment, empty segment, backslash or control byte;
 * - `release_archive_link_entry`    — symbolic or hard link (a link could point the next write outside the release);
 * - `release_archive_shared_entry`  — `.env`, `storage` or `public/storage` inside the archive. These are refused,
 *   not removed: the shared copies live in the site root and are linked by {@see ReleaseSharedLinks}; a build that
 *   packs them is a build mistake (it may carry a secret) and the archive must stay exactly what was signed;
 * - `release_archive_entry_duplicate`, `release_archive_entry_unsupported`, `release_archive_invalid`,
 *   `release_archive_truncated`, `release_archive_too_large`.
 *
 * A finished release carries `.larena-release` (version and archive checksum). Running again for the same archive
 * returns the existing directory; any other directory under that name is refused with `release_directory_differs`.
 */
final readonly class ReleaseArchiveExtractor
{
    public const MARKER = '.larena-release';
    private const BLOCK = 512;
    private const CHUNK = 1048576;

    public function __construct(
        private int $maxBytes = 2147483648,
        private int $maxEntries = 200000,
    ) {
    }

    /** @return string the release directory */
    public function extract(VerifiedRelease $release, string $archivePath, string $siteRoot, ?string $releaseId = null): string
    {
        $id = ReleaseDirectory::id($releaseId ?? $release->version);
        $site = ReleaseDirectory::site($siteRoot);
        ReleaseSignatureVerifier::verifyArchive($release, $archivePath);

        $releases = $site . '/releases';
        if (is_link($releases) || (file_exists($releases) && !is_dir($releases))) {
            throw new RuntimeException('release_layout_invalid');
        }
        if (!is_dir($releases) && !mkdir($releases, 0755) && !is_dir($releases)) {
            throw new RuntimeException('release_layout_invalid');
        }

        $marker = json_encode(['version' => $release->version, 'archive_sha256' => $release->archiveSha256], JSON_THROW_ON_ERROR) . "\n";
        $target = $releases . '/' . $id;
        if (is_link($target) || file_exists($target)) {
            $existing = !is_link($target) && is_dir($target) && is_file($target . '/' . self::MARKER) && !is_link($target . '/' . self::MARKER)
                ? file_get_contents($target . '/' . self::MARKER)
                : false;
            if ($existing === $marker) {
                return $target;
            }
            throw new RuntimeException('release_directory_differs');
        }

        $partial = $releases . '/.' . $id . '.partial';
        ReleaseDirectory::remove($partial);
        if (!mkdir($partial, 0755)) {
            throw new RuntimeException('release_layout_invalid');
        }
        try {
            $this->unpack($archivePath, $partial);
            if (file_put_contents($partial . '/' . self::MARKER, $marker) === false || !rename($partial, $target)) {
                throw new RuntimeException('release_directory_write_failed');
            }
        } catch (Throwable $exception) {
            ReleaseDirectory::remove($partial);
            throw $exception;
        }

        return $target;
    }

    private function unpack(string $archivePath, string $into): void
    {
        $gz = gzopen($archivePath, 'rb');
        if ($gz === false) {
            throw new RuntimeException('release_archive_invalid');
        }
        try {
            $seen = [];
            $entries = 0;
            $total = 0;
            $longName = null;
            $paxPath = null;
            while (true) {
                $header = self::read($gz, self::BLOCK);
                if ($header === str_repeat("\0", self::BLOCK)) {
                    return;
                }
                if (++$entries > $this->maxEntries) {
                    throw new RuntimeException('release_archive_too_large');
                }
                self::checksum($header);
                $type = $header[156];
                $size = self::octal(substr($header, 124, 12));

                if ($type === 'L' || $type === 'x' || $type === 'g') {
                    if ($size > 65536) {
                        throw new RuntimeException('release_archive_invalid');
                    }
                    $data = self::read($gz, self::padded($size));
                    $data = substr($data, 0, $size);
                    if ($type === 'L') {
                        $longName = rtrim($data, "\0");
                    } elseif ($type === 'x') {
                        $paxPath = self::paxPath($data);
                    }
                    continue;
                }
                if ($type === '1' || $type === '2' || $type === 'K') {
                    throw new RuntimeException('release_archive_link_entry');
                }

                $name = rtrim(substr($header, 0, 100), "\0");
                $prefix = substr($header, 257, 5) === 'ustar' ? rtrim(substr($header, 345, 155), "\0") : '';
                if ($prefix !== '') {
                    $name = $prefix . '/' . $name;
                }
                $name = $paxPath ?? $longName ?? $name;
                $paxPath = $longName = null;
                $path = self::entryPath($name);

                if ($type === '5') {
                    if ($path !== null) {
                        self::claim($seen, $path);
                        self::directory($into, $path);
                    }
                    self::read($gz, self::padded($size));
                    continue;
                }
                if ($type !== '0' && $type !== "\0" && $type !== '7') {
                    throw new RuntimeException('release_archive_entry_unsupported');
                }
                if ($path === null) {
                    throw new RuntimeException('release_archive_path_invalid');
                }
                self::claim($seen, $path);
                $total += $size;
                if ($total > $this->maxBytes) {
                    throw new RuntimeException('release_archive_too_large');
                }
                $slash = strrpos($path, '/');
                if ($slash !== false) {
                    self::directory($into, substr($path, 0, $slash));
                }
                $file = $into . '/' . $path;
                $out = @fopen($file, 'xb');
                if ($out === false) {
                    throw new RuntimeException('release_archive_entry_duplicate');
                }
                try {
                    $left = $size;
                    while ($left > 0) {
                        $chunk = self::read($gz, min($left, self::CHUNK));
                        if (fwrite($out, $chunk) !== strlen($chunk)) {
                            throw new RuntimeException('release_directory_write_failed');
                        }
                        $left -= strlen($chunk);
                    }
                } finally {
                    fclose($out);
                }
                self::read($gz, self::padded($size) - $size);
                $mode = self::octal(substr($header, 100, 8));
                chmod($file, ($mode & 0111) !== 0 ? 0755 : 0644);
            }
        } finally {
            gzclose($gz);
        }
    }

    /** Normalised relative path, or null for the archive root itself (`./`). */
    private static function entryPath(string $name): ?string
    {
        if ($name === '' || strlen($name) > 4096 || str_starts_with($name, '/') || str_contains($name, '\\')
            || preg_match('/[\x00-\x1f\x7f]/', $name) === 1) {
            throw new RuntimeException('release_archive_path_invalid');
        }
        while (str_starts_with($name, './')) {
            $name = substr($name, 2);
        }
        $name = rtrim($name, '/');
        if ($name === '' || $name === '.') {
            return null;
        }
        foreach (explode('/', $name) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                throw new RuntimeException('release_archive_path_invalid');
            }
        }
        if ($name === self::MARKER) {
            throw new RuntimeException('release_archive_path_invalid');
        }
        foreach (['.env', 'storage', 'public/storage'] as $shared) {
            if ($name === $shared || str_starts_with($name, $shared . '/')) {
                throw new RuntimeException('release_archive_shared_entry');
            }
        }

        return $name;
    }

    /** @param array<string,true> $seen */
    private static function claim(array &$seen, string $path): void
    {
        if (isset($seen[$path])) {
            throw new RuntimeException('release_archive_entry_duplicate');
        }
        $seen[$path] = true;
    }

    /** Creates every directory of a checked relative path; an existing link or file on the way is refused. */
    private static function directory(string $into, string $path): void
    {
        $current = $into;
        foreach (explode('/', $path) as $segment) {
            $current .= '/' . $segment;
            if (is_link($current) || (file_exists($current) && !is_dir($current))) {
                throw new RuntimeException('release_archive_entry_duplicate');
            }
            if (!is_dir($current) && !mkdir($current, 0755)) {
                throw new RuntimeException('release_directory_write_failed');
            }
        }
    }

    private static function checksum(string $header): void
    {
        $expected = self::octal(substr($header, 148, 8));
        $sum = 0;
        $blank = substr_replace($header, '        ', 148, 8);
        for ($i = 0; $i < self::BLOCK; $i++) {
            $sum += ord($blank[$i]);
        }
        if ($sum !== $expected) {
            throw new RuntimeException('release_archive_invalid');
        }
    }

    private static function octal(string $field): int
    {
        if ($field !== '' && (ord($field[0]) & 0x80) !== 0) {
            throw new RuntimeException('release_archive_entry_unsupported');
        }
        $digits = trim($field, "\0 ");
        if (preg_match('/\A[0-7]{0,12}\z/D', $digits) !== 1) {
            throw new RuntimeException('release_archive_invalid');
        }

        return $digits === '' ? 0 : (int) octdec($digits);
    }

    private static function paxPath(string $data): ?string
    {
        $path = null;
        while ($data !== '') {
            $space = strpos($data, ' ');
            $length = $space === false ? 0 : (int) substr($data, 0, $space);
            if ($space === false || $length <= $space + 1 || $length > strlen($data) || $data[$length - 1] !== "\n") {
                throw new RuntimeException('release_archive_invalid');
            }
            [$key, $value] = array_pad(explode('=', substr($data, $space + 1, $length - $space - 2), 2), 2, null);
            if ($value === null) {
                throw new RuntimeException('release_archive_invalid');
            }
            if ($key === 'path') {
                $path = $value;
            } elseif ($key === 'size' || $key === 'linkpath') {
                throw new RuntimeException('release_archive_entry_unsupported');
            }
            $data = substr($data, $length);
        }

        return $path;
    }

    private static function padded(int $size): int
    {
        return (int) (ceil($size / self::BLOCK) * self::BLOCK);
    }

    /** @param resource $gz */
    private static function read($gz, int $length): string
    {
        $data = '';
        while (strlen($data) < $length) {
            $chunk = gzread($gz, $length - strlen($data));
            if ($chunk === false || $chunk === '') {
                throw new RuntimeException('release_archive_truncated');
            }
            $data .= $chunk;
        }

        return $data;
    }
}
