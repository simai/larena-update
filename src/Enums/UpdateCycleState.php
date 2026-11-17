<?php

declare(strict_types=1);

namespace Larena\UpdateClient\Enums;

enum UpdateCycleState: string
{
    case Planned = 'planned';
    case ExecutionReady = 'execution_ready';
    case Running = 'running';
    case Blocked = 'blocked';
    case RecoverableFailed = 'recoverable_failed';
    case ManualRecoveryRequired = 'manual_recovery_required';
    case Completed = 'completed';
    /** A completed update that was taken back to the previous release on request (plan §3, step 6). */
    case RolledBack = 'rolled_back';

    public function isTerminal(): bool
    {
        return in_array($this, [self::Blocked, self::ManualRecoveryRequired, self::Completed, self::RolledBack], true);
    }

    public function isRetryable(): bool
    {
        return $this === self::RecoverableFailed;
    }

    /** A cycle in one of these states keeps the site busy: no other cycle may start until it is resolved. */
    public function isUnfinished(): bool
    {
        return in_array($this, [self::Planned, self::ExecutionReady, self::Running, self::ManualRecoveryRequired], true);
    }
}
