<?php

declare(strict_types=1);

namespace Larena\UpdateClient\Laravel;

use Larena\UpdateClient\Release\SiteHealth;
use Larena\UpdateClient\Source\UpdateHttpClient;
use Larena\UpdateClient\Source\UpdateHttpRequest;
use Larena\UpdateClient\Source\UpdateHttpResponse;
use RuntimeException;
use Throwable;

/**
 * The health check after the switch (plan §3 step 4, §5a.1): GET of the home page, `/admin/login` and the update
 * client's own health address `/_larena/update/health` at the site's own address (APP_URL) with a short timeout,
 * redirects not followed. The requests carry the cycle's maintenance bypass cookie, so they reach the application
 * while visitors see the maintenance page.
 *
 * - `home`, `admin_login`: a 5xx status, no answer at all, or a body that is a PHP error page («Fatal error», «Parse
 *   error» — a server showing errors answers them with 200) fails; 2xx, 3xx and 4xx otherwise count as a live
 *   application.
 * - `up`: only a 200 whose JSON body is `{"status":"ok","check":"larena_update_health"}` ({@see MARKER}) passes. A
 *   release from before that address answers 404 there; then Laravel's `/up` must answer 2xx without an error page.
 */
final readonly class HttpSiteHealth implements SiteHealth
{
    public const CHECKS = ['home' => '/', 'admin_login' => '/admin/login', 'up' => self::PATH];

    /** The update client's own health address and the marker its answer carries. */
    public const PATH = '/_larena/update/health';
    public const MARKER = 'larena_update_health';

    /** What a release before {@see PATH} still answers. */
    private const LEGACY_UP = '/up';

    private string $siteUrl;

    public function __construct(
        private UpdateHttpClient $http,
        string $siteUrl,
        private MaintenanceBypass $bypass,
        private int $timeoutSeconds = 10,
        private bool $verifyTls = true,
    ) {
        $this->siteUrl = self::siteUrl($siteUrl);
    }

    /** The site's own address without a trailing slash; anything but http(s) with a host is `update_site_url_invalid`. */
    public static function siteUrl(string $siteUrl): string
    {
        $siteUrl = rtrim(trim($siteUrl), '/');
        if (!in_array(strtolower((string) parse_url($siteUrl, PHP_URL_SCHEME)), ['http', 'https'], true) || parse_url($siteUrl, PHP_URL_HOST) === null) {
            throw new RuntimeException('update_site_url_invalid');
        }

        return $siteUrl;
    }

    /** Whether an answer is the health address's own: 200 and the JSON marker. */
    public static function isHealthAnswer(UpdateHttpResponse $response): bool
    {
        if ($response->status !== 200) {
            return false;
        }
        $data = json_decode($response->body, true, 4);

        return is_array($data) && ($data['status'] ?? null) === 'ok' && ($data['check'] ?? null) === self::MARKER;
    }

    /** A PHP error page: what a server that shows errors sends, often with status 200. */
    public static function isErrorPage(string $body): bool
    {
        return preg_match('/(?:\A|[\s>])(?:PHP )?(?:Fatal|Parse) error(?:<\/b>)?:\s/i', substr($body, 0, 65536)) === 1;
    }

    public function failedChecks(string $siteRoot): array
    {
        $failed = [];
        foreach (self::CHECKS as $name => $path) {
            try {
                if ($name === 'up') {
                    $response = $this->get(self::PATH, true);
                    if ($response->status === 404) {
                        $legacy = $this->get(self::LEGACY_UP);
                        $healthy = $legacy->status >= 200 && $legacy->status < 300 && !self::isErrorPage($legacy->body);
                    } else {
                        $healthy = self::isHealthAnswer($response);
                    }
                } else {
                    $response = $this->get($path);
                    $healthy = $response->status >= 100 && $response->status < 500 && !self::isErrorPage($response->body);
                }
            } catch (Throwable) {
                $healthy = false;
            }
            if (!$healthy) {
                $failed[] = $name;
            }
        }

        return $failed;
    }

    private function get(string $path, bool $json = false): UpdateHttpResponse
    {
        return $this->http->send(new UpdateHttpRequest(
            'GET', $this->siteUrl . $path, ($json ? ['Accept' => 'application/json'] : []) + $this->bypass->headers(), $this->timeoutSeconds, 4194304,
            httpsOnly: false, followRedirects: false, verifyTls: $this->verifyTls,
            connectTimeoutSeconds: min(5, $this->timeoutSeconds),
        ));
    }
}
