<?php

declare(strict_types=1);

namespace Larena\UpdateClient\Laravel;

/**
 * Runs `php <release>/artisan …` of one release folder in its own process, so the code of that release is loaded by
 * its own autoloader and never mixed into the process that drives the update. Returns the standard output; a non-zero
 * exit or a timeout is a RuntimeException with `$failureReason` as its message.
 */
interface ArtisanRunner
{
    /** @param list<string> $arguments */
    public function run(string $releaseDirectory, array $arguments, string $failureReason, ?int $timeoutSeconds = null): string;
}
