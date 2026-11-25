<?php

declare(strict_types=1);

namespace Larena\UpdateClient\Packages;

use JsonException;
use RuntimeException;

/**
 * The highest package list version this site has accepted, kept in the shared storage
 * (`storage/app/larena/update/package-list.json`), outside every release: a rollback of code or database does not
 * bring an older list back into trust. A list with a lower version is a replay and is refused
 * (`update_batch_list_stale`, {@see PackageListVerifier}). The number only moves forward.
 */
final readonly class PackageListState
{
    public function __construct(private string $file)
    {
    }

    public static function forSite(string $siteRoot): self
    {
        return new self(rtrim($siteRoot, '/') . '/storage/app/larena/update/package-list.json');
    }

    public function seen(): int
    {
        if (!is_file($this->file) || is_link($this->file)) {
            return 0;
        }
        try {
            $data = json_decode((string) file_get_contents($this->file), true, 4, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new RuntimeException('update_batch_state_unreadable');
        }

        return is_array($data) && is_int($data['list_version'] ?? null) ? $data['list_version'] : throw new RuntimeException('update_batch_state_unreadable');
    }

    public function see(int $listVersion): void
    {
        if ($listVersion <= $this->seen()) {
            return;
        }
        $directory = dirname($this->file);
        if (is_link($directory) || (!is_dir($directory) && !mkdir($directory, 0750, true) && !is_dir($directory))) {
            throw new RuntimeException('update_batch_state_unwritable');
        }
        $temporary = $this->file . '.' . bin2hex(random_bytes(4));
        $bytes = json_encode(['list_version' => $listVersion], JSON_THROW_ON_ERROR) . "\n";
        if (file_put_contents($temporary, $bytes) !== strlen($bytes) || !rename($temporary, $this->file)) {
            @unlink($temporary);
            throw new RuntimeException('update_batch_state_unwritable');
        }
    }
}
