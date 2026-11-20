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
 *
 * File list ({@see FileList}): when the signed release names one, `files.json` next to the archive is read and checked
 * against `release.json` first, then every archive entry is compared with it (same type and size, nothing extra or
 * missing) before the first byte is written, and the sha256 of every unpacked file must match it before the release
 * gets its name (`release_files_*`). The checked list, and the signed `release.json` and `release.json.sig` when the
 * caller hands them in, are kept in `<release>/.larena-signed/` for the integrity checks that run on the site later.
 * A release without a list unpacks as before and keeps no `files.json` there.
 */
final readonly class ReleaseArchiveExtractor
{
    public const MARKER = '.larena-release';
    /** Folder of a release that keeps its signed description and its checked file list. */
    public const SIGNED = '.larena-signed';
    private const BLOCK = 512;
    private const CHUNK = 1048576;

    public function __construct(
        private int $maxBytes = 2147483648,
        private int $maxEntries = 200000,
    ) {
    }

    /**
     * @param ?string $releaseBytes   the exact signed `release.json`, kept with the release when given
     * @param ?string $signatureBytes its `release.json.sig`
     *
     * @return string the release directory
     */
    public function extract(
        VerifiedRelease $release,
        string $archivePath,
        string $siteRoot,
        ?string $releaseId = null,
        ?string $releaseBytes = null,
        ?string $signatureBytes = null,
    ): string {
        $id = ReleaseDirectory::id($releaseId ?? $release->version);
        $site = ReleaseDirectory::site($siteRoot);
        ReleaseSignatureVerifier::verifyArchive($release, $archivePath);
        if ($releaseBytes !== null && !hash_equals($release->releaseSha256, hash('sha256', $releaseBytes))) {
            throw new RuntimeException('release_description_differs');
        }
        $list = FileList::beside($release, $archivePath);
        if ($list !== null) {
            $this->checkList($list, $archivePath);
        }

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
            $this->unpack($archivePath, $partial, $list);
            $signed = array_filter([
                'release.json' => $releaseBytes,
                'release.json.sig' => $releaseBytes !== null ? $signatureBytes : null,
                FileList::FILE => $list?->bytes,
            ], 'is_string');
            if ($signed !== []) {
                if (!mkdir($partial . '/' . self::SIGNED, 0755)) {
                    throw new RuntimeException('release_directory_write_failed');
                }
                foreach ($signed as $name => $bytes) {
                    if (file_put_contents($partial . '/' . self::SIGNED . '/' . $name, $bytes) !== strlen($bytes)) {
                        throw new RuntimeException('release_directory_write_failed');
                    }
                }
                // «Вернуть как было» takes clean files from this archive while there is no update server: it stays
                // with the release it built, beside the signed description that names its sha256.
                if ($list !== null && $releaseBytes !== null) {
                    self::keepArchive($release, $archivePath, $partial . '/' . self::SIGNED);
                }
            }
            if (file_put_contents($partial . '/' . self::MARKER, $marker) === false || !rename($partial, $target)) {
                throw new RuntimeException('release_directory_write_failed');
            }
        } catch (Throwable $exception) {
            ReleaseDirectory::remove($partial);
            throw $exception;
        }

        return $target;
    }

    /**
     * Keeps the verified archive of a release in its signed folder under the name `release.json` gives it: a hard link
     * where the download folder is on the same disk, a copy otherwise. The kept file is checked against the signed
     * sha256 before it counts.
     */
    public static function keepArchive(VerifiedRelease $release, string $archivePath, string $signedDirectory): string
    {
        ReleaseSignatureVerifier::verifyArchive($release, $archivePath);
        $kept = rtrim($signedDirectory, '/') . '/' . $release->archiveFile;
        if (is_link($kept) || file_exists($kept)) {
            ReleaseSignatureVerifier::verifyArchive($release, $kept);

            return $kept;
        }
        $temporary = $kept . '.partial';
        ReleaseDirectory::remove($temporary);
        if (!@link($archivePath, $temporary) && !@copy($archivePath, $temporary)) {
            throw new RuntimeException('release_archive_keep_failed');
        }
        try {
            ReleaseSignatureVerifier::verifyArchive($release, $temporary);
            @chmod($temporary, 0644);
            if (!rename($temporary, $kept)) {
                throw new RuntimeException('release_archive_keep_failed');
            }
        } catch (Throwable $exception) {
            ReleaseDirectory::remove($temporary);
            throw $exception;
        }

        return $kept;
    }

    /**
     * Writes the named files of a release again from its archive (part A4, «Вернуть как было»): each one into a
     * temporary file beside its place, checked against the list's sha256, then renamed over the place in one step.
     * The archive itself must match the list's archive sha256 first (`release_archive_checksum_differs`); a path the
     * list does not name as a file is refused (`release_files_entry_missing`). Nothing else of the release is touched.
     *
     * @param list<string> $paths
     * @return list<string> the paths written
     */
    public function restoreEntries(string $archivePath, FileList $list, array $paths, string $root): array
    {
        $root = rtrim($root, '/');
        if ($root === '' || is_link($root) || !is_dir($root)) {
            throw new RuntimeException('release_files_root_invalid');
        }
        $wanted = [];
        foreach ($paths as $path) {
            if (($list->entries[$path]['type'] ?? null) !== 'file') {
                throw new RuntimeException('release_files_entry_missing');
            }
            $wanted[$path] = true;
        }
        if ($wanted === []) {
            return [];
        }
        $sha = is_file($archivePath) && !is_link($archivePath) ? hash_file('sha256', $archivePath) : false;
        if (!is_string($sha) || !hash_equals($list->archiveSha256, $sha)) {
            throw new RuntimeException('release_archive_checksum_differs');
        }
        $written = [];
        $this->walk(
            $archivePath,
            static function (string $path): void {
            },
            static function (string $path, int $size, int $mode, $gz) use (&$written, $wanted, $list, $root): void {
                if (!isset($wanted[$path])) {
                    $left = $size;
                    while ($left > 0) {
                        $left -= strlen(self::read($gz, min($left, self::CHUNK)));
                    }

                    return;
                }
                $slash = strrpos($path, '/');
                if ($slash !== false) {
                    self::directory($root, substr($path, 0, $slash));
                }
                $file = $root . '/' . $path;
                $temporary = $file . '.larena-restore-' . bin2hex(random_bytes(6));
                $out = @fopen($temporary, 'xb');
                if ($out === false) {
                    throw new RuntimeException('release_directory_write_failed');
                }
                $hash = hash_init('sha256');
                try {
                    $left = $size;
                    while ($left > 0) {
                        $chunk = self::read($gz, min($left, self::CHUNK));
                        if (fwrite($out, $chunk) !== strlen($chunk)) {
                            throw new RuntimeException('release_directory_write_failed');
                        }
                        hash_update($hash, $chunk);
                        $left -= strlen($chunk);
                    }
                } finally {
                    fclose($out);
                }
                if (!hash_equals((string) ($list->entries[$path]['sha256'] ?? ''), hash_final($hash))) {
                    @unlink($temporary);
                    throw new RuntimeException('release_files_checksum_differs');
                }
                chmod($temporary, ($mode & 0111) !== 0 ? 0755 : 0644);
                // A directory or a link where the file belongs is not overwritten by rename: refused, left for a person.
                if ((is_link($file) || is_dir($file)) || !rename($temporary, $file)) {
                    @unlink($temporary);
                    throw new RuntimeException('release_directory_write_failed');
                }
                $written[] = $path;
            },
        );
        if (count($written) !== count($wanted)) {
            throw new RuntimeException('release_files_entry_missing');
        }

        return $written;
    }

    /**
     * Reads every entry of the archive without writing anything: the shape the list check compares.
     *
     * @return array<string,array{0:string,1:int}> normalised path => [`file` | `dir`, bytes]
     */
    public function entries(string $archivePath): array
    {
        $entries = [];
        $this->walk(
            $archivePath,
            static function (string $path) use (&$entries): void {
                $entries[$path] = ['dir', 0];
            },
            static function (string $path, int $size, int $mode, $gz) use (&$entries): void {
                $entries[$path] = ['file', $size];
                $left = $size;
                while ($left > 0) {
                    $left -= strlen(self::read($gz, min($left, self::CHUNK)));
                }
            },
        );

        return $entries;
    }

    /**
     * Checks an archive against its file list before anything is unpacked ({@see FileList::matchArchive()}).
     */
    public function checkList(FileList $list, string $archivePath): void
    {
        $list->matchArchive($this->entries($archivePath));
    }

    private function unpack(string $archivePath, string $into, ?FileList $list): void
    {
        $this->walk(
            $archivePath,
            static function (string $path) use ($into): void {
                self::directory($into, $path);
            },
            static function (string $path, int $size, int $mode, $gz) use ($into, $list): void {
                $slash = strrpos($path, '/');
                if ($slash !== false) {
                    self::directory($into, substr($path, 0, $slash));
                }
                $file = $into . '/' . $path;
                $out = @fopen($file, 'xb');
                if ($out === false) {
                    throw new RuntimeException('release_archive_entry_duplicate');
                }
                $hash = hash_init('sha256');
                try {
                    $left = $size;
                    while ($left > 0) {
                        $chunk = self::read($gz, min($left, self::CHUNK));
                        if (fwrite($out, $chunk) !== strlen($chunk)) {
                            throw new RuntimeException('release_directory_write_failed');
                        }
                        hash_update($hash, $chunk);
                        $left -= strlen($chunk);
                    }
                } finally {
                    fclose($out);
                }
                if ($list !== null && !hash_equals((string) ($list->entries[$path]['sha256'] ?? ''), hash_final($hash))) {
                    throw new RuntimeException('release_files_checksum_differs');
                }
                chmod($file, ($mode & 0111) !== 0 ? 0755 : 0644);
            },
        );
    }

    /**
     * The tar reader: checks every header and path, then hands a directory or a regular file to its callback. A
     * file callback reads exactly the entry's bytes from the stream; the padding is skipped here.
     *
     * @param callable(string):void                  $onDirectory
     * @param callable(string,int,int,resource):void $onFile path, size, mode, gzip stream
     */
    private function walk(string $archivePath, callable $onDirectory, callable $onFile): void
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
                        $onDirectory($path);
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
                $onFile($path, $size, self::octal(substr($header, 100, 8)), $gz);
                self::read($gz, self::padded($size) - $size);
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
        if ($name === self::MARKER || $name === self::SIGNED || str_starts_with($name, self::SIGNED . '/')) {
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
