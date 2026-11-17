<?php

declare(strict_types=1);

namespace Larena\UpdateClient\Release;

use RuntimeException;

/**
 * Path rules shared by the release layout classes: release ids, the site root, and removal that never follows a link.
 *
 * Layout (the same one Root `scripts/install-larena-site.sh` installs):
 * `<site>/releases/<release>/` code of one release, `<site>/current` relative link `releases/<release>`,
 * `<site>/.env` and `<site>/storage` shared by every release, `<site>/database/` outside every release.
 */
final class ReleaseDirectory
{
    public static function id(string $id): string
    {
        if (preg_match('/\A[a-z0-9][a-z0-9._-]{0,63}\z/D', $id) !== 1 || str_contains($id, '..')) {
            throw new RuntimeException('release_id_invalid');
        }

        return $id;
    }

    /** An absolute existing directory; the trailing slash is dropped. */
    public static function site(string $root): string
    {
        $root = rtrim($root, '/');
        if ($root === '' || $root[0] !== '/' || !is_dir($root)) {
            throw new RuntimeException('release_site_invalid');
        }

        return $root;
    }

    /** Removes a file, a link (never its target) or a directory tree; a missing path is fine. */
    public static function remove(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            if (!unlink($path)) {
                throw new RuntimeException('release_remove_failed');
            }

            return;
        }
        if (!is_dir($path)) {
            return;
        }
        $children = scandir($path);
        if ($children === false) {
            throw new RuntimeException('release_remove_failed');
        }
        foreach ($children as $child) {
            if ($child !== '.' && $child !== '..') {
                self::remove($path . '/' . $child);
            }
        }
        if (!rmdir($path)) {
            throw new RuntimeException('release_remove_failed');
        }
    }
}
