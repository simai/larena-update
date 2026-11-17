<?php

declare(strict_types=1);

namespace Larena\UpdateClient\Release;

use RuntimeException;

/**
 * Keeps two releases: the one `current` points to and the previous one the caller names (from
 * {@see ReleaseSwitch::$previous}). Every other directory in `<site>/releases/` is removed without following links,
 * so the shared `.env` and `storage` stay untouched. Hidden entries (`.<release>.partial`) are left to the extractor.
 * With no `current` nothing is removed (`release_current_missing`).
 */
final class ReleaseCleanup
{
    /** @return list<string> removed release ids, sorted */
    public static function prune(string $siteRoot, ?string $previous): array
    {
        $site = ReleaseDirectory::site($siteRoot);
        $current = (new ReleasePointer($site))->current() ?? throw new RuntimeException('release_current_missing');
        $keep = [$current];
        if ($previous !== null) {
            $keep[] = ReleaseDirectory::id($previous);
        }
        $releases = $site . '/releases';
        $names = is_link($releases) ? false : scandir($releases);
        if ($names === false) {
            throw new RuntimeException('release_layout_invalid');
        }
        $removed = [];
        foreach ($names as $name) {
            if (str_starts_with($name, '.') || in_array($name, $keep, true)) {
                continue;
            }
            $path = $releases . '/' . $name;
            if (is_link($path) || is_dir($path)) {
                ReleaseDirectory::remove($path);
                $removed[] = $name;
            }
        }
        sort($removed);

        return $removed;
    }
}
