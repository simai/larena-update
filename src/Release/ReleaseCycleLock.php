<?php

declare(strict_types=1);

namespace Larena\UpdateClient\Release;

use RuntimeException;

/**
 * One update or rollback at a time per site: an exclusive non-blocking lock on `<site>/storage/.larena-update.lock`
 * (the shared storage, outside every release). A second cycle is refused with `update_cycle_already_running`.
 * The operating system drops the lock when the process ends.
 */
final class ReleaseCycleLock
{
    /** @param resource $handle */
    private function __construct(private $handle)
    {
    }

    public static function acquire(string $siteRoot): self
    {
        $storage = ReleaseDirectory::site($siteRoot) . '/storage';
        $path = $storage . '/.larena-update.lock';
        if (is_link($storage) || !is_dir($storage) || is_link($path)) {
            throw new RuntimeException('release_layout_invalid');
        }
        $handle = fopen($path, 'c');
        if ($handle === false) {
            throw new RuntimeException('update_cycle_lock_unavailable');
        }
        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);
            throw new RuntimeException('update_cycle_already_running');
        }

        return new self($handle);
    }

    public function release(): void
    {
        if (is_resource($this->handle)) {
            flock($this->handle, LOCK_UN);
            fclose($this->handle);
        }
    }
}
