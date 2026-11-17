<?php

declare(strict_types=1);

namespace Larena\UpdateClient\Source;

/** The answer to an {@see UpdateHttpRequest}: the status and the body (empty when it went to the request's file). */
final readonly class UpdateHttpResponse
{
    public function __construct(
        public int $status,
        public string $body = '',
        public int $bytes = 0,
    ) {
    }

    public function successful(): bool
    {
        return $this->status >= 200 && $this->status < 300;
    }
}
