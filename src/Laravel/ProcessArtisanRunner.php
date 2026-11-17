<?php

declare(strict_types=1);

namespace Larena\UpdateClient\Laravel;

use RuntimeException;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * {@see ArtisanRunner} over Symfony Process: `php <release>/artisan <arguments>` with the release folder as the working
 * directory and a timeout. The arguments are passed as a list (no shell). The output never goes into an exception: a
 * failure carries only its reason code.
 */
final readonly class ProcessArtisanRunner implements ArtisanRunner
{
    private string $php;

    public function __construct(?string $phpBinary = null, private int $timeoutSeconds = 900)
    {
        $php = $phpBinary !== null && $phpBinary !== '' ? $phpBinary : (PHP_SAPI === 'cli' ? PHP_BINARY : (new PhpExecutableFinder())->find(false));
        if (!is_string($php) || $php === '') {
            throw new RuntimeException('update_php_binary_missing');
        }
        $this->php = $php;
    }

    public function run(string $releaseDirectory, array $arguments, string $failureReason, ?int $timeoutSeconds = null): string
    {
        $releaseDirectory = rtrim($releaseDirectory, '/');
        if ($releaseDirectory === '' || $releaseDirectory[0] !== '/' || !is_file($releaseDirectory . '/artisan')) {
            throw new RuntimeException('update_release_artisan_missing');
        }
        $process = new Process([$this->php, $releaseDirectory . '/artisan', ...$arguments, '--no-interaction'], $releaseDirectory, null, null, (float) ($timeoutSeconds ?? $this->timeoutSeconds));
        try {
            $process->run();
        } catch (Throwable) {
            throw new RuntimeException($failureReason);
        }
        if (!$process->isSuccessful()) {
            throw new RuntimeException($failureReason);
        }

        return $process->getOutput();
    }
}
