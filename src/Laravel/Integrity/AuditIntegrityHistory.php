<?php

declare(strict_types=1);

namespace Larena\UpdateClient\Laravel\Integrity;

use Closure;
use Larena\Audit\Contracts\AuditEvent;
use Larena\Audit\Runtime\AuditEventPipeline;
use Larena\UpdateClient\Integrity\IntegrityHistory;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * «История» for the integrity checks: the audit journal where larena/audit is installed (the journal shows the
 * `update.integrity.` family in words, `update-client::integrity.history.events`), and always the log. A journal that
 * cannot write never stops the action it records.
 */
final readonly class AuditIntegrityHistory implements IntegrityHistory
{
    /** @param (Closure(): ?AuditEventPipeline)|null $pipeline */
    public function __construct(private LoggerInterface $logger, private ?Closure $pipeline = null)
    {
    }

    public function record(string $event, string $actor, array $facts = []): void
    {
        $this->logger->info('larena.' . $event, ['actor' => $actor] + $facts);
        if ($this->pipeline === null || !interface_exists(\Larena\Audit\Contracts\AuditEventDescriptor::class)) {
            return;
        }
        try {
            $pipeline = ($this->pipeline)();
            if ($pipeline === null) {
                return;
            }
            $descriptor = new IntegrityAuditEventDescriptor($event);
            $pipeline->route($descriptor, AuditEvent::create(
                sourcePackage: $descriptor->sourcePackage(),
                category: $descriptor->category(),
                type: $descriptor->type(),
                actor: $actor !== '' ? $actor : SiteIntegrity::ACTOR,
                subject: 'site:files',
                severity: $descriptor->severity(),
                retentionClass: $descriptor->retentionClass(),
                correlationId: 'integrity:' . bin2hex(random_bytes(8)),
                payload: $facts,
            ));
        } catch (Throwable $failure) {
            $this->logger->warning('larena.update.integrity.history_unwritten', ['event' => $event, 'reason' => $failure::class]);
        }
    }
}
