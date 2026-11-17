<?php

declare(strict_types=1);

namespace Larena\UpdateClient\Laravel;

use Larena\UpdateClient\Release\SiteHealth;
use Larena\UpdateClient\Source\UpdateHttpClient;
use Larena\UpdateClient\Source\UpdateHttpRequest;
use RuntimeException;
use Throwable;

/**
 * The health check after the switch (plan §3 step 4, §5a.1): GET of the home page, `/admin/login` and `/up` at the
 * site's own address (APP_URL) with a short timeout, redirects not followed. A 5xx status or no answer at all fails
 * the check; anything else (2xx, 3xx, 4xx) counts as a live application. The requests carry the cycle's maintenance
 * bypass cookie, so they reach the application while visitors see the maintenance page.
 */
final readonly class HttpSiteHealth implements SiteHealth
{
    public const CHECKS = ['home' => '/', 'admin_login' => '/admin/login', 'up' => '/up'];

    private string $siteUrl;

    public function __construct(
        private UpdateHttpClient $http,
        string $siteUrl,
        private MaintenanceBypass $bypass,
        private int $timeoutSeconds = 10,
        private bool $verifyTls = true,
    ) {
        $siteUrl = rtrim(trim($siteUrl), '/');
        if (!in_array(strtolower((string) parse_url($siteUrl, PHP_URL_SCHEME)), ['http', 'https'], true) || parse_url($siteUrl, PHP_URL_HOST) === null) {
            throw new RuntimeException('update_site_url_invalid');
        }
        $this->siteUrl = $siteUrl;
    }

    public function failedChecks(string $siteRoot): array
    {
        $failed = [];
        foreach (self::CHECKS as $name => $path) {
            try {
                $response = $this->http->send(new UpdateHttpRequest(
                    'GET', $this->siteUrl . $path, $this->bypass->headers(), $this->timeoutSeconds, 4194304,
                    httpsOnly: false, followRedirects: false, verifyTls: $this->verifyTls,
                    connectTimeoutSeconds: min(5, $this->timeoutSeconds),
                ));
                if ($response->status < 100 || $response->status >= 500) {
                    $failed[] = $name;
                }
            } catch (Throwable) {
                $failed[] = $name;
            }
        }

        return $failed;
    }
}
