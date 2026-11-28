<?php

declare(strict_types=1);

namespace Larena\UpdateClient\Source;

use JsonException;
use RuntimeException;

/**
 * The update server's answer for a revoked release (matrix 5): HTTP 410 with
 * `{"code":"release_revoked","version":"x.y.z","fixed_in":"x.y.z"|null}`. A typed outcome, not a generic failure: its
 * reason code is `update_release_revoked`, and it names the revoked version and, when the server knows one, the
 * version that fixes it. Only these fields are read from at most 4 KiB of the answer; anything else stays unknown.
 */
final class ReleaseRevoked extends RuntimeException
{
    public const CODE = 'update_release_revoked';
    private const VERSION = '/\A(0|[1-9]\d{0,5})\.(0|[1-9]\d{0,5})\.(0|[1-9]\d{0,7})\z/D';

    public function __construct(public readonly ?string $version, public readonly ?string $fixedIn)
    {
        parent::__construct(self::CODE);
    }

    /** A 410 answer; `$asked` is the version the request was for, used when the answer names none. */
    public static function fromAnswer(string $body, ?string $asked = null): self
    {
        $answer = null;
        if ($body !== '' && strlen($body) <= 4096) {
            try {
                $answer = json_decode($body, true, 4, JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                $answer = null;
            }
        }
        $answer = is_array($answer) && !array_is_list($answer) && ($answer['code'] ?? null) === 'release_revoked' ? $answer : [];
        $version = self::version($answer['version'] ?? null) ?? self::version($asked);
        $fixedIn = self::version($answer['fixed_in'] ?? null);
        if ($fixedIn !== null && $version !== null && version_compare($fixedIn, $version, '<=')) {
            $fixedIn = null; // a «fix» that is not newer says nothing
        }

        return new self($version, $fixedIn);
    }

    private static function version(mixed $value): ?string
    {
        return is_string($value) && preg_match(self::VERSION, $value) === 1 ? $value : null;
    }
}
