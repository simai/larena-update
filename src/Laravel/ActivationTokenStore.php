<?php

declare(strict_types=1);

namespace Larena\UpdateClient\Laravel;

use JsonException;
use RuntimeException;

/**
 * The one-time token of the internal activation address (plan §5a.1, «Ответ ENV»). The update process issues a random
 * token, keeps only its sha256 and expiry in the shared storage (`storage/app/larena/update/activation-token.json`,
 * mode 0600) and sends the token itself once in a request header. The site takes the file away atomically (a rename,
 * so two requests can never both use it), then compares: one token, one call, a short life.
 */
final readonly class ActivationTokenStore
{
    private const FILE = 'activation-token.json';

    public function __construct(private string $directory, private int $ttlSeconds = 120)
    {
    }

    /** @phpstan-impure */
    public function issue(): string
    {
        $directory = rtrim($this->directory, '/');
        if (is_link($directory) || (!is_dir($directory) && !mkdir($directory, 0750, true) && !is_dir($directory))) {
            throw new RuntimeException('update_activation_token_unavailable');
        }
        $token = bin2hex(random_bytes(32));
        $path = $directory . '/' . self::FILE;
        $next = $path . '.' . bin2hex(random_bytes(6));
        $content = (string) json_encode(['sha256' => hash('sha256', $token), 'expires_at' => time() + $this->ttlSeconds]);
        if (is_link($path) || file_put_contents($next, $content) !== strlen($content) || !chmod($next, 0600) || !rename($next, $path)) {
            @unlink($next);
            throw new RuntimeException('update_activation_token_unavailable');
        }

        return $token;
    }

    /**
     * True once for the issued token within its life; the stored token is gone after any call.
     *
     * @phpstan-impure
     */
    public function consume(string $token): bool
    {
        $path = rtrim($this->directory, '/') . '/' . self::FILE;
        if ($token === '' || is_link($path) || !is_file($path)) {
            return false;
        }
        $taken = $path . '.taken.' . bin2hex(random_bytes(6));
        if (!@rename($path, $taken)) {
            return false;
        }
        $content = @file_get_contents($taken);
        @unlink($taken);
        try {
            $stored = json_decode((string) $content, true, 4, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return false;
        }

        return is_array($stored)
            && is_string($stored['sha256'] ?? null)
            && is_int($stored['expires_at'] ?? null)
            && $stored['expires_at'] >= time()
            && hash_equals($stored['sha256'], hash('sha256', $token));
    }

    /** Removes an issued token that was not used (after a failed call). */
    public function forget(): void
    {
        $path = rtrim($this->directory, '/') . '/' . self::FILE;
        if (is_file($path) && !is_link($path)) {
            @unlink($path);
        }
    }
}
