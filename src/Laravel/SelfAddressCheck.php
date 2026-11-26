<?php

declare(strict_types=1);

namespace Larena\UpdateClient\Laravel;

use Larena\UpdateClient\Source\UpdateHttpClient;
use Larena\UpdateClient\Source\UpdateHttpRequest;
use RuntimeException;
use Throwable;

/**
 * Part of the preflight (step 1, before anything is written): the site must reach itself at the address the health
 * check and the activation use (`site_url`, else APP_URL). Without it the update would switch the code, fail to
 * activate it, and the automatic rollback would fail the same way.
 *
 * - `GET <site>/_larena/update/health` must give the health address's own JSON answer ({@see HttpSiteHealth::MARKER});
 *   otherwise `update_self_health_unreachable`.
 * - `POST <site>/_larena/update/opcache-reset` without a token must give that address's own 404 answer
 *   (`{"status":"not_found"}`): the address reaches this application and nothing is reset. Otherwise
 *   `update_self_activation_unreachable`.
 *
 * Read-only: no token is issued and nothing on the site changes.
 */
final readonly class SelfAddressCheck
{
    private string $siteUrl;

    public function __construct(
        private UpdateHttpClient $http,
        string $siteUrl,
        private string $activationPath = '_larena/update/opcache-reset',
        private int $timeoutSeconds = 10,
        private bool $verifyTls = true,
    ) {
        $this->siteUrl = HttpSiteHealth::siteUrl($siteUrl);
    }

    public function check(): void
    {
        try {
            $health = $this->send('GET', HttpSiteHealth::PATH);
        } catch (Throwable) {
            throw new RuntimeException('update_self_health_unreachable');
        }
        if (!HttpSiteHealth::isHealthAnswer($health)) {
            throw new RuntimeException('update_self_health_unreachable');
        }
        try {
            $activation = $this->send('POST', '/' . ltrim($this->activationPath, '/'));
        } catch (Throwable) {
            throw new RuntimeException('update_self_activation_unreachable');
        }
        $data = json_decode($activation->body, true, 4);
        if ($activation->status !== 404 || !is_array($data) || ($data['status'] ?? null) !== 'not_found') {
            throw new RuntimeException('update_self_activation_unreachable');
        }
    }

    private function send(string $method, string $path): \Larena\UpdateClient\Source\UpdateHttpResponse
    {
        return $this->http->send(new UpdateHttpRequest(
            $method, $this->siteUrl . $path, ['Accept' => 'application/json'], $this->timeoutSeconds, 65536,
            httpsOnly: false, followRedirects: false, verifyTls: $this->verifyTls,
            connectTimeoutSeconds: min(5, $this->timeoutSeconds),
        ));
    }
}
