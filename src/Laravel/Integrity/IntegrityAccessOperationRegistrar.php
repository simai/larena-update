<?php

declare(strict_types=1);

namespace Larena\UpdateClient\Laravel\Integrity;

use Larena\Access\Runtime\AccessOperationRegistry;
use Larena\Access\ValueObjects\AccessOperationDescriptor;
use RuntimeException;

/**
 * The rights of «Целостность файлов» (lead decision 2026-11-20): the integrity check owns them here, in the update
 * client that every distribution ships, so the page does not depend on larena/update and its update-centre rights.
 *
 * - `update.integrity.read` — see the page, the findings and the alerts;
 * - `update.integrity.apply` — run a check, put files back, return them from the quarantine, accept a change.
 *
 * Registering twice is fine; a different descriptor under the same code is refused.
 */
final readonly class IntegrityAccessOperationRegistrar
{
    public const READ = 'update.integrity.read';
    public const APPLY = 'update.integrity.apply';

    /** @return list<AccessOperationDescriptor> */
    public function descriptors(): array
    {
        return array_map(
            static fn (string $operation): AccessOperationDescriptor => new AccessOperationDescriptor(
                code: 'update.integrity.' . $operation,
                ownerPackage: 'larena/update-client',
                labelKey: 'larena-update-client::integrity.operations.' . $operation,
                target: 'update.integrity:system',
                requiredGrant: $operation,
                risk: $operation === 'apply' ? 'critical' : 'normal',
                auditDenials: true,
            ),
            ['read', 'apply'],
        );
    }

    public function register(AccessOperationRegistry $registry): void
    {
        foreach ($this->descriptors() as $descriptor) {
            $existing = $registry->get($descriptor->code);
            if ($existing === null) {
                if (!$registry->register($descriptor)) {
                    throw new RuntimeException('update_client_access_operation_registration_failed');
                }
            } elseif ($existing->toArray() !== $descriptor->toArray()) {
                throw new RuntimeException('update_client_access_operation_conflict');
            }
        }
    }
}
