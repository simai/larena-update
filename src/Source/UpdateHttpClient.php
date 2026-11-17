<?php

declare(strict_types=1);

namespace Larena\UpdateClient\Source;

/**
 * Sends one request within its limits. A network failure, a timeout or a body over the limit is a RuntimeException
 * with a stable reason code (`update_http_failed`, `update_http_too_large`); any HTTP status is returned as is.
 * {@see CurlUpdateHttpClient} is the real one; tests use a fake and never touch the network.
 */
interface UpdateHttpClient
{
    public function send(UpdateHttpRequest $request): UpdateHttpResponse;
}
