<?php

declare(strict_types=1);

namespace Larena\UpdateClient\Source;

use RuntimeException;

/**
 * One outgoing request of the update client: a release file from an update source, a health check of the site or
 * the one-time activation call. Limits are part of the request: a total timeout, a byte limit for the body and, for
 * a download, the file the body goes to instead of memory.
 */
final readonly class UpdateHttpRequest
{
    /** @param array<string,string> $headers */
    public function __construct(
        public string $method,
        public string $url,
        public array $headers = [],
        public int $timeoutSeconds = 10,
        public int $maxBytes = 1048576,
        public ?string $sinkPath = null,
        public bool $httpsOnly = true,
        public bool $followRedirects = false,
        public bool $verifyTls = true,
        public int $connectTimeoutSeconds = 10,
    ) {
        if (!in_array($this->method, ['GET', 'POST'], true)) {
            throw new RuntimeException('update_http_method_invalid');
        }
        $scheme = strtolower((string) parse_url($this->url, PHP_URL_SCHEME));
        if (!in_array($scheme, $this->httpsOnly ? ['https'] : ['https', 'http'], true) || parse_url($this->url, PHP_URL_HOST) === null) {
            throw new RuntimeException($this->httpsOnly ? 'update_source_https_required' : 'update_http_url_invalid');
        }
        if ($this->timeoutSeconds < 1 || $this->connectTimeoutSeconds < 1 || $this->maxBytes < 1) {
            throw new RuntimeException('update_http_limits_invalid');
        }
    }
}
