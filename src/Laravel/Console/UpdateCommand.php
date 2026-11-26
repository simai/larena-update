<?php

declare(strict_types=1);

namespace Larena\UpdateClient\Laravel\Console;

use Illuminate\Console\Command;
use Larena\UpdateClient\Data\UpdateCycleSnapshot;
use RuntimeException;
use Throwable;

/**
 * Shared output of the update commands: `--json` prints the payload, otherwise the command's own readable lines.
 * A failure prints only its stable reason code, never an exception text or a path, and the words for that code in the
 * site's language when the package has them ({@see explain()}).
 */
abstract class UpdateCommand extends Command
{
    /**
     * @param callable(): array<string,mixed>                 $operation returns the payload; `status` decides the exit code
     * @param callable(array<string,mixed>): list<string>    $lines     readable lines for a successful payload
     * @param list<string>                                    $okStates  `status` values that exit with success
     */
    final protected function respond(callable $operation, callable $lines, array $okStates): int
    {
        try {
            $payload = $operation();
        } catch (Throwable $exception) {
            $payload = ['status' => 'failed', 'reason' => self::reason($exception)];
        }
        $ok = in_array($payload['status'] ?? null, $okStates, true);
        if ((bool) $this->option('json')) {
            $this->line((string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } elseif (($payload['status'] ?? null) === 'failed' && isset($payload['reason']) && !isset($payload['cycle_id'])) {
            $this->error('Failed: ' . $payload['reason'] . '.');
            if (($text = $this->explain((string) $payload['reason'])) !== null) {
                $this->error($text);
            }
            foreach ((array) ($payload['source_errors'] ?? []) as $source => $reason) {
                $reason = is_scalar($reason) ? (string) $reason : '';
                $text = $reason === '' ? null : $this->explain($reason);
                $this->line('  ' . $source . ': ' . $reason . ($text !== null ? ' — ' . $text : ''));
            }
        } else {
            foreach ($lines($payload) as $line) {
                $ok ? $this->line($line) : $this->error($line);
            }
        }

        return $ok ? self::SUCCESS : self::FAILURE;
    }

    final protected function actor(): string
    {
        $actor = trim((string) $this->option('actor'));
        if ($actor === '') {
            throw new RuntimeException('update_actor_required');
        }
        if (preg_match('/\A[a-z][a-z0-9_-]*:[A-Za-z0-9][A-Za-z0-9:._-]{0,191}\z/D', $actor) !== 1) {
            throw new RuntimeException('update_actor_invalid');
        }

        return $actor;
    }

    /** @return array<string,mixed> */
    final protected static function cycle(UpdateCycleSnapshot $cycle, ?string $updatedAt = null): array
    {
        return \Larena\UpdateClient\Laravel\ReleaseInstall::cycle($cycle, $updatedAt);
    }

    final protected static function describeState(string $state): string
    {
        return match ($state) {
            'completed' => 'completed',
            'rolled_back' => 'taken back to the previous release',
            'recoverable_failed' => 'failed and rolled back automatically; the site runs the previous release and the update can be tried again',
            'manual_recovery_required' => 'failed and the rollback did not finish; the site stays in maintenance and needs manual recovery',
            'blocked' => 'stopped before anything on the site changed',
            'planned', 'execution_ready', 'running' => 'in progress',
            default => $state,
        };
    }

    /**
     * The words for a reason code in the site's language (the package's `integrity.reasons`, ru and en), or null when
     * there are none. Only the code is looked up: no server answer and no key ever reaches the text.
     */
    final protected function explain(string $reason): ?string
    {
        if (preg_match('/\A[a-z][a-z0-9_]{0,99}\z/D', $reason) !== 1 || !isset($this->laravel) || !$this->laravel->bound('translator')) {
            return null;
        }
        $key = 'larena-update-client::integrity.reasons.' . $reason;
        try {
            $text = $this->laravel->make('translator')->get($key);
        } catch (Throwable) {
            return null;
        }
        if (!is_string($text) || $text === $key || $text === '') {
            return null;
        }

        return mb_strtoupper(mb_substr($text, 0, 1)) . mb_substr($text, 1);
    }

    final protected static function reason(Throwable $exception): string
    {
        return \Larena\UpdateClient\Laravel\ReleaseInstall::reason($exception);
    }
}
