<?php

declare(strict_types=1);

namespace Larena\UpdateClient\Release;

use RuntimeException;

/**
 * Reads and moves `<site>/current`, the relative link `releases/<release>` the web server, cron and workers use.
 *
 * A switch writes a fresh link `<site>/current.next` and renames it over `current` — one rename, so a request sees
 * either the old release or the new one. The release `current` pointed to is read before the switch and returned
 * in {@see ReleaseSwitch}; {@see rollback()} moves `current` back the same way. Activations run after every real
 * change.
 */
final readonly class ReleasePointer
{
    private string $site;

    /** @param list<ReleaseActivation> $activations */
    public function __construct(string $siteRoot, private array $activations = [])
    {
        $this->site = ReleaseDirectory::site($siteRoot);
    }

    /** The release `current` points to, or null before the first install. */
    public function current(): ?string
    {
        $current = $this->site . '/current';
        if (!is_link($current)) {
            if (file_exists($current)) {
                throw new RuntimeException('release_current_invalid');
            }

            return null;
        }
        $target = readlink($current);
        if (!is_string($target) || preg_match('#\Areleases/([^/]+)\z#D', $target, $match) !== 1) {
            throw new RuntimeException('release_current_invalid');
        }

        return ReleaseDirectory::id($match[1]);
    }

    public function switchTo(string $releaseId): ReleaseSwitch
    {
        $id = ReleaseDirectory::id($releaseId);
        $release = $this->site . '/releases/' . $id;
        if (is_link($release) || !is_dir($release)) {
            throw new RuntimeException('release_directory_missing');
        }
        $previous = $this->current();
        if ($previous === $id) {
            return new ReleaseSwitch($previous, $id, false);
        }

        $next = $this->site . '/current.next';
        if (is_link($next) || is_file($next)) {
            unlink($next);
        } elseif (file_exists($next)) {
            throw new RuntimeException('release_layout_invalid');
        }
        if (!symlink('releases/' . $id, $next)) {
            throw new RuntimeException('release_switch_failed');
        }
        if (!rename($next, $this->site . '/current')) {
            @unlink($next);
            throw new RuntimeException('release_switch_failed');
        }

        $switch = new ReleaseSwitch($previous, $id, true);
        foreach ($this->activations as $activation) {
            $activation->activated($this->site, $switch);
        }

        return $switch;
    }

    /** Moves `current` back to the release it pointed to before `$switch`; nothing to undo if it did not change. */
    public function rollback(ReleaseSwitch $switch): ReleaseSwitch
    {
        if (!$switch->changed) {
            return $switch;
        }
        if ($switch->previous === null) {
            throw new RuntimeException('release_previous_missing');
        }

        return $this->switchTo($switch->previous);
    }
}
