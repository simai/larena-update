<?php

declare(strict_types=1);

namespace Larena\UpdateClient\Release;

use RuntimeException;

/**
 * Links a release to the files every release shares, with the same relative links Root
 * `scripts/install-larena-site.sh` makes: `.env` → `../../.env`, `storage` → `../../storage`,
 * `public/storage` → `../storage/app/public` (through the release's own `storage` link).
 *
 * Rule for an archive that carries its own `.env`, `storage` or `public/storage`: refused, never removed —
 * {@see ReleaseArchiveExtractor} stops with `release_archive_shared_entry`, and a real file or directory found here
 * stops with the same code. Running again is safe: a link that already points where it should is kept; a link that
 * points elsewhere is refused with `release_shared_link_differs`.
 */
final class ReleaseSharedLinks
{
    public const LINKS = [
        '.env' => '../../.env',
        'storage' => '../../storage',
        'public/storage' => '../storage/app/public',
    ];

    public static function link(string $siteRoot, string $releaseId): void
    {
        $site = ReleaseDirectory::site($siteRoot);
        $release = $site . '/releases/' . ReleaseDirectory::id($releaseId);
        if (is_link($release) || !is_dir($release)) {
            throw new RuntimeException('release_directory_missing');
        }
        if (!is_file($site . '/.env') || is_link($site . '/storage') || !is_dir($site . '/storage')) {
            throw new RuntimeException('release_shared_missing');
        }
        if (is_link($release . '/public') || !is_dir($release . '/public')) {
            throw new RuntimeException('release_public_missing');
        }
        foreach (self::LINKS as $name => $target) {
            $path = $release . '/' . $name;
            if (is_link($path)) {
                if (readlink($path) !== $target) {
                    throw new RuntimeException('release_shared_link_differs');
                }
                continue;
            }
            if (file_exists($path)) {
                throw new RuntimeException('release_archive_shared_entry');
            }
            if (!symlink($target, $path)) {
                throw new RuntimeException('release_directory_write_failed');
            }
        }
    }
}
