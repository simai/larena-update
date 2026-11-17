<?php

declare(strict_types=1);

namespace Larena\UpdateClient\Laravel;

/**
 * The secret of one update cycle's maintenance window. {@see ArtisanMaintenanceMode} turns maintenance on with it
 * (`artisan down --secret=…`); the health check and the activation call send the matching `laravel_maintenance`
 * cookie, so they see the site itself while visitors get the maintenance page. The cookie is the one Laravel's
 * `MaintenanceModeBypassCookie` makes (HMAC of its expiry with the secret); the secret never leaves this process
 * except through the maintenance file in the shared storage, and it is new for every cycle.
 */
final readonly class MaintenanceBypass
{
    public string $secret;

    public function __construct(?string $secret = null)
    {
        $this->secret = $secret ?? bin2hex(random_bytes(20));
    }

    /** @return array<string,string> */
    public function headers(int $ttlSeconds = 3600): array
    {
        $expiresAt = time() + $ttlSeconds;
        $cookie = base64_encode((string) json_encode([
            'expires_at' => $expiresAt,
            'mac' => hash_hmac('sha256', (string) $expiresAt, $this->secret),
        ]));

        return ['Cookie' => 'laravel_maintenance=' . rawurlencode($cookie)];
    }
}
