<?php

declare(strict_types=1);

namespace Larena\UpdateClient\Laravel;

use Larena\UpdateClient\Release\ReleaseActivation;
use Larena\UpdateClient\Release\ReleaseSwitch;
use Larena\UpdateClient\Source\UpdateHttpClient;
use Larena\UpdateClient\Source\UpdateHttpRequest;
use RuntimeException;
use Throwable;

/**
 * What the site does itself right after `current` moved (plan §5a.1, «Ответ ENV»), the same on a server with Larena
 * Panel and without it:
 *
 * 1. OPcache reset in the web server's PHP: a one-time token is issued ({@see ActivationTokenStore}) and sent in
 *    `X-Larena-Update-Token` to the package's internal address `POST <APP_URL>/_larena/update/opcache-reset`; the
 *    release that now serves the site takes the token and calls `opcache_reset()`. Anything but a 200 is a failure.
 * 2. `queue:restart` from the folder of the release `current` now points to, so workers pick up the new code.
 *
 * On a site under Larena Panel nothing else happens here: the panel calls its gateway command `site:release-activated`
 * (FPM reload, `queue:restart`, check) on its own. A failure is a RuntimeException; the cycle rolls back.
 */
final readonly class OpcacheResetActivation implements ReleaseActivation
{
    public const HEADER = 'X-Larena-Update-Token';

    private string $siteUrl;

    public function __construct(
        private UpdateHttpClient $http,
        private ArtisanRunner $artisan,
        private ActivationTokenStore $tokens,
        private MaintenanceBypass $bypass,
        string $siteUrl,
        private string $path = '_larena/update/opcache-reset',
        private int $timeoutSeconds = 10,
        private bool $verifyTls = true,
    ) {
        $this->siteUrl = HttpSiteHealth::siteUrl($siteUrl);
    }

    /** The activation address's own answer: 200 with `status: ok` and the boolean `opcache_reset` (every release gives it). */
    public static function isResetAnswer(\Larena\UpdateClient\Source\UpdateHttpResponse $response): bool
    {
        if ($response->status !== 200) {
            return false;
        }
        $data = json_decode($response->body, true, 4);

        return is_array($data) && ($data['status'] ?? null) === 'ok' && is_bool($data['opcache_reset'] ?? null);
    }

    public function activated(string $siteRoot, ReleaseSwitch $switch): void
    {
        $token = $this->tokens->issue();
        try {
            $response = $this->http->send(new UpdateHttpRequest(
                'POST', $this->siteUrl . '/' . ltrim($this->path, '/'),
                [self::HEADER => $token, 'Accept' => 'application/json'] + $this->bypass->headers(),
                $this->timeoutSeconds, 65536, httpsOnly: false, followRedirects: false, verifyTls: $this->verifyTls,
                connectTimeoutSeconds: min(5, $this->timeoutSeconds),
            ));
        } catch (Throwable) {
            throw new RuntimeException('update_opcache_reset_failed');
        } finally {
            $this->tokens->forget();
        }
        if (!self::isResetAnswer($response)) {
            throw new RuntimeException('update_opcache_reset_failed');
        }

        $this->artisan->run(rtrim($siteRoot, '/') . '/releases/' . $switch->current, ['queue:restart'], 'update_queue_restart_failed', 120);
    }
}
