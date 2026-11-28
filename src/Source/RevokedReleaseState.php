<?php

declare(strict_types=1);

namespace Larena\UpdateClient\Source;

use DateTimeImmutable;
use JsonException;

/**
 * «The installed version is revoked» (matrix 5), as the update server last said it ({@see ReleaseRevoked}), kept in the
 * update storage (`storage/app/larena/update/revoked.json`) outside every release: the page «Обновления» and the
 * admin's alert bar read it without the network. It is about one version only: {@see current()} gives it while that
 * version runs, and a newer installed version clears it ({@see settle()}). A file that cannot be read says nothing —
 * the banner is a warning, never a gate.
 */
final readonly class RevokedReleaseState
{
    private const VERSION = '/\A(0|[1-9]\d{0,5})\.(0|[1-9]\d{0,5})\.(0|[1-9]\d{0,7})\z/D';

    public function __construct(private string $file)
    {
    }

    public static function forSite(string $siteRoot): self
    {
        return new self(rtrim($siteRoot, '/') . '/storage/app/larena/update/revoked.json');
    }

    /** @return array{version:string, fixed_in:?string, seen_at:string}|null the revocation of the running version */
    public function current(string $installed): ?array
    {
        $kept = $this->read();

        return $kept !== null && $kept['version'] === $installed ? $kept : null;
    }

    /** The server said the installed version is revoked. */
    public function record(string $installed, ReleaseRevoked $revoked, ?DateTimeImmutable $at = null): void
    {
        if (($revoked->version ?? $installed) !== $installed || preg_match(self::VERSION, $installed) !== 1) {
            return;
        }
        $this->write(['version' => $installed, 'fixed_in' => $revoked->fixedIn, 'seen_at' => ($at ?? new DateTimeImmutable())->format(DATE_ATOM)]);
    }

    /** Clears a revocation of a version older than the installed one: a newer version is installed. */
    public function settle(string $installed): void
    {
        $kept = $this->read();
        if ($kept !== null && preg_match(self::VERSION, $installed) === 1 && version_compare($installed, $kept['version'], '>')) {
            @unlink($this->file);
        }
    }

    /** @return array{version:string, fixed_in:?string, seen_at:string}|null */
    private function read(): ?array
    {
        if (!is_file($this->file) || is_link($this->file)) {
            return null;
        }
        try {
            $data = json_decode((string) file_get_contents($this->file, false, null, 0, 4096), true, 4, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }
        if (!is_array($data) || !is_string($data['version'] ?? null) || preg_match(self::VERSION, $data['version']) !== 1
            || !is_string($data['seen_at'] ?? null)
            || (($data['fixed_in'] ?? null) !== null && (!is_string($data['fixed_in']) || preg_match(self::VERSION, $data['fixed_in']) !== 1))) {
            return null;
        }

        return ['version' => $data['version'], 'fixed_in' => $data['fixed_in'] ?? null, 'seen_at' => $data['seen_at']];
    }

    /** @param array<string,mixed> $data */
    private function write(array $data): void
    {
        $directory = dirname($this->file);
        if (is_link($directory) || is_link($this->file) || (!is_dir($directory) && !@mkdir($directory, 0750, true) && !is_dir($directory))) {
            return;
        }
        $temporary = $this->file . '.' . bin2hex(random_bytes(4));
        $bytes = (string) json_encode($data, JSON_UNESCAPED_SLASHES) . "\n";
        if (@file_put_contents($temporary, $bytes) !== strlen($bytes) || !@rename($temporary, $this->file)) {
            @unlink($temporary);
        }
    }
}
