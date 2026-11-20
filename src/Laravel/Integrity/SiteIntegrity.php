<?php

declare(strict_types=1);

namespace Larena\UpdateClient\Laravel\Integrity;

use Larena\UpdateClient\Integrity\CoreCheck;
use Larena\UpdateClient\Integrity\CoreFileGuard;
use Larena\UpdateClient\Integrity\FindingStore;
use Larena\UpdateClient\Integrity\IntegrityHistory;
use Larena\UpdateClient\Integrity\IntegrityLayout;
use Larena\UpdateClient\Integrity\IntegrityRestore;
use Larena\UpdateClient\Integrity\IntegrityScanner;
use Larena\UpdateClient\Integrity\IntegrityUnavailable;
use Larena\UpdateClient\Integrity\SignedBaseline;

/**
 * The integrity checks of this Larena site put together once per process: the running release (Laravel's base path),
 * its shared storage, the public release keys of `update-client` and of the distribution, and «История». The signed
 * list is verified at most once per process; the request check keeps its own answers between requests.
 */
final class SiteIntegrity
{
    public const ACTOR = 'system:integrity';

    private ?SignedBaseline $baseline = null;
    private ?IntegrityUnavailable $unavailable = null;
    private ?string $fingerprint = null;
    private ?CoreCheck $request = null;
    private ?FindingStore $findings = null;
    private ?CoreFileGuard $guard = null;
    private ?IntegrityScanner $scanner = null;
    private ?IntegrityRestore $restore = null;

    /**
     * @param array<string,string> $trustedKeys
     * @param list<string>         $revokedKeyIds
     */
    public function __construct(
        public readonly IntegrityLayout $layout,
        private readonly array $trustedKeys,
        private readonly array $revokedKeyIds,
        public readonly IntegrityHistory $history,
    ) {
    }

    /** @throws IntegrityUnavailable */
    public function baseline(): SignedBaseline
    {
        $fingerprint = SignedBaseline::fingerprint($this->layout);
        if ($fingerprint !== $this->fingerprint) {
            $this->fingerprint = $fingerprint;
            $this->baseline = null;
            $this->unavailable = null;
            try {
                $this->baseline = SignedBaseline::load($this->layout, $this->trustedKeys, $this->revokedKeyIds);
            } catch (IntegrityUnavailable $unavailable) {
                $this->unavailable = $unavailable;
            }
        }

        return $this->baseline ?? throw ($this->unavailable ?? new IntegrityUnavailable('integrity_list_missing'));
    }

    public function findings(): FindingStore
    {
        return $this->findings ??= new FindingStore($this->layout);
    }

    public function guard(): CoreFileGuard
    {
        return $this->guard ??= new CoreFileGuard($this->layout, fn (): SignedBaseline => $this->baseline());
    }

    public function scanner(): IntegrityScanner
    {
        return $this->scanner ??= new IntegrityScanner($this->layout, fn (): SignedBaseline => $this->baseline(), $this->findings());
    }

    public function restore(): IntegrityRestore
    {
        return $this->restore ??= new IntegrityRestore($this->layout, fn (): SignedBaseline => $this->baseline(), $this->findings(),
            $this->scanner(), $this->guard(), $this->history);
    }

    /** The request check's answer for this request: made once, by the middleware or on first ask. */
    public function requestCheck(): CoreCheck
    {
        return $this->request ??= $this->guard()->check();
    }

    public function forgetRequestCheck(): void
    {
        $this->request = null;
    }

    /**
     * Runs portions of the full check for at most `$seconds` (the scheduled tick without a queue, the «Проверить
     * сейчас» button), starting one unless it runs; records the end in «История».
     *
     * @return array<string,mixed> the current run
     */
    public function scanFor(float $seconds, string $trigger, string $actor, bool $restart = false, int $portion = 2000): array
    {
        $scanner = $this->scanner();
        $started = $scanner->start($trigger, $restart);
        $wasRunning = (($started['current'] ?? [])['state'] ?? null) === 'running';
        $deadline = microtime(true) + $seconds;
        $run = [];
        do {
            $state = $scanner->step($portion, max(0.2, min(5.0, $deadline - microtime(true))));
            $run = is_array($state['current'] ?? null) ? $state['current'] : [];
        } while (($run['state'] ?? null) === 'running' && microtime(true) < $deadline);
        if ($wasRunning && ($run['state'] ?? null) === 'completed') {
            $this->recordCompleted($run, $actor);
        }

        return $run;
    }

    /** @param array<string,mixed> $run */
    public function recordCompleted(array $run, string $actor): void
    {
        try {
            $this->history->record(IntegrityHistory::PREFIX . 'check.completed', $actor, IntegrityRestore::checkFacts($run));
        } catch (\Throwable) {
            // The journal is not the check: a journal that cannot write leaves the result as it is.
        }
    }
}
