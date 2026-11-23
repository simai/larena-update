<?php

declare(strict_types=1);

namespace Larena\UpdateClient\Laravel\Integrity;

use InvalidArgumentException;
use Larena\Audit\Contracts\AuditEventDescriptor;
use Larena\Audit\Enums\AuditRetentionClass;
use Larena\Audit\Enums\AuditSeverity;
use Larena\UpdateClient\Integrity\IntegrityHistory;

/** The integrity events in the audit journal («История»): `update.integrity.*`, kept as security events. */
final readonly class IntegrityAuditEventDescriptor implements AuditEventDescriptor
{
    public const TYPES = [
        'check.completed', 'core.changed', 'restore.succeeded', 'restore.failed', 'restore.refused',
        'quarantine.returned', 'exception.accepted',
    ];

    public function __construct(private string $eventType)
    {
        if (!in_array(substr($eventType, strlen(IntegrityHistory::PREFIX)), self::TYPES, true) || !str_starts_with($eventType, IntegrityHistory::PREFIX)) {
            throw new InvalidArgumentException('update_integrity_audit_type_invalid');
        }
    }

    public function sourcePackage(): string
    {
        return 'larena/update';
    }

    public function category(): string
    {
        return 'integrity';
    }

    public function type(): string
    {
        return $this->eventType;
    }

    public function severity(): AuditSeverity
    {
        return AuditSeverity::Security;
    }

    public function retentionClass(): AuditRetentionClass
    {
        return AuditRetentionClass::Security;
    }

    public function redactedPayloadFields(): array
    {
        return [];
    }

    public function forbiddenPayloadFields(): array
    {
        return ['content', 'password', 'secret', 'token', 'key', 'signature', 'exception', 'trace', 'stdout', 'stderr'];
    }

    public function isExperimental(): bool
    {
        return false;
    }
}
