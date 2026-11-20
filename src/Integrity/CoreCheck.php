<?php

declare(strict_types=1);

namespace Larena\UpdateClient\Integrity;

/**
 * The answer of the check on every request (A2): `ok` — every main file of the signed list is as signed; `changed` —
 * some are not (`problems`: path => missing | type_differs | size_differs | checksum_differs); `no_list` — the release
 * has no trusted file list, so nothing could be compared (never a success); `source` — the site runs from its source
 * code (a developer checkout), where files are not checked at all: information, not a warning; `unknown` — the check
 * itself failed.
 * `transitioned` is true on the request that first saw this answer after another one.
 */
final readonly class CoreCheck
{
    public const OK = 'ok';
    public const CHANGED = 'changed';
    public const NO_LIST = 'no_list';
    public const SOURCE = 'source';
    public const UNKNOWN = 'unknown';

    /** @param array<string,string> $problems */
    public function __construct(
        public string $status,
        public array $problems = [],
        public ?string $reason = null,
        public bool $transitioned = false,
        public int $files = 0,
    ) {
    }

    public function ok(): bool
    {
        return $this->status === self::OK;
    }
}
