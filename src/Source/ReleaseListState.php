<?php

declare(strict_types=1);

namespace Larena\UpdateClient\Source;

use JsonException;
use RuntimeException;

/**
 * The highest release description number (`list_version`, {@see ReleaseListFreshness}) this site accepted, per product
 * and channel, kept in the shared storage (`storage/app/larena/update/release-list.json`) outside every release, like
 * {@see \Larena\UpdateClient\Packages\PackageListState}: a rollback of code or database does not bring an older
 * description back into trust. A number only moves forward.
 */
final readonly class ReleaseListState
{
    public function __construct(private string $file)
    {
    }

    public static function forSite(string $siteRoot): self
    {
        return new self(rtrim($siteRoot, '/') . '/storage/app/larena/update/release-list.json');
    }

    public function seen(string $product, string $channel): int
    {
        return $this->all()[self::key($product, $channel)] ?? 0;
    }

    public function see(string $product, string $channel, int $listVersion): void
    {
        $all = $this->all();
        $key = self::key($product, $channel);
        if ($listVersion <= ($all[$key] ?? 0)) {
            return;
        }
        $all[$key] = $listVersion;
        ksort($all, SORT_STRING);
        $directory = dirname($this->file);
        if (is_link($directory) || is_link($this->file) || (!is_dir($directory) && !mkdir($directory, 0750, true) && !is_dir($directory))) {
            throw new RuntimeException('update_release_state_unwritable');
        }
        $temporary = $this->file . '.' . bin2hex(random_bytes(4));
        $bytes = json_encode(['lists' => $all], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n";
        if (file_put_contents($temporary, $bytes) !== strlen($bytes) || !rename($temporary, $this->file)) {
            @unlink($temporary);
            throw new RuntimeException('update_release_state_unwritable');
        }
    }

    /** @return array<string,int> */
    private function all(): array
    {
        if (!is_file($this->file) || is_link($this->file)) {
            return [];
        }
        try {
            $data = json_decode((string) file_get_contents($this->file), true, 4, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new RuntimeException('update_release_state_unreadable');
        }
        $lists = is_array($data) ? ($data['lists'] ?? null) : null;
        if (!is_array($lists)) {
            throw new RuntimeException('update_release_state_unreadable');
        }
        $all = [];
        foreach ($lists as $key => $version) {
            if (!is_string($key) || !is_int($version) || $version < 1) {
                throw new RuntimeException('update_release_state_unreadable');
            }
            $all[$key] = $version;
        }

        return $all;
    }

    private static function key(string $product, string $channel): string
    {
        return $product . '@' . $channel;
    }
}
