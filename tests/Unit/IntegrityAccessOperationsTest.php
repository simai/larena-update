<?php

declare(strict_types=1);

require_once __DIR__ . '/../Support/UpdateClientFakes.php';

use Larena\Access\Runtime\AccessOperationRegistry;
use Larena\Access\Runtime\SystemRolePresetSynchronizer;
use Larena\Access\ValueObjects\AccessOperationDescriptor;
use Larena\UpdateClient\Laravel\Integrity\IntegrityAccessOperationRegistrar;
use Larena\UpdateClient\Tests\Support\UpdateClientTestKit as Kit;

// The rights of «Целостность файлов» are the update client's own (lead decision 2026-11-20): registered once, a repeat
// is fine, a conflicting descriptor is refused, and they need nothing of larena/update.
(static function (): void {
    $registrar = new IntegrityAccessOperationRegistrar();
    $registry = new AccessOperationRegistry();
    $registrar->register($registry);
    $codes = array_map(static fn (AccessOperationDescriptor $operation): string => $operation->code, $registry->all());
    sort($codes);
    Kit::check($codes === ['update.integrity.apply', 'update.integrity.read'], 'both rights are registered: ' . implode(',', $codes));
    Kit::check(array_filter($codes, static fn (string $code): bool => str_starts_with($code, 'update.safety.')) === [], 'no update centre right is needed');

    $read = $registry->get(IntegrityAccessOperationRegistrar::READ);
    $apply = $registry->get(IntegrityAccessOperationRegistrar::APPLY);
    Kit::check($read?->toArray() === ['code' => 'update.integrity.read', 'owner_package' => 'larena/update-client',
        'label_key' => 'larena-update-client::integrity.operations.read', 'target' => 'update.integrity:system',
        'required_grant' => 'read', 'risk' => 'normal', 'audit_denials' => true], 'reading is a normal right of the update client');
    Kit::check($apply?->toArray() === ['code' => 'update.integrity.apply', 'owner_package' => 'larena/update-client',
        'label_key' => 'larena-update-client::integrity.operations.apply', 'target' => 'update.integrity:system',
        'required_grant' => 'apply', 'risk' => 'critical', 'audit_denials' => true], 'acting is a critical right of the update client');

    $registrar->register($registry);
    Kit::check(count($registry->all()) === 2, 'a repeat registration changes nothing');

    $other = new AccessOperationRegistry();
    $other->register(new AccessOperationDescriptor('update.integrity.read', 'larena/update', 'larena-update::operations.safety_read',
        'update.integrity:system', 'read'));
    Kit::check(Kit::reason(static fn () => $registrar->register($other)) === 'update_client_access_operation_conflict', 'a conflicting right is refused');

    // Every label is said in every language of the package.
    foreach (glob(__DIR__ . '/../../resources/lang/*/integrity.php') ?: [] as $file) {
        $lines = require $file;
        foreach (['read', 'apply'] as $operation) {
            Kit::check(is_string($lines['operations'][$operation] ?? null) && $lines['operations'][$operation] !== '', 'a label for ' . $operation . ' in ' . $file);
        }
    }
    $ru = require __DIR__ . '/../../resources/lang/ru/integrity.php';
    Kit::check($ru['operations'] === ['read' => 'Смотреть проверку файлов', 'apply' => 'Возвращать файлы и снимать карантин'], 'the Russian labels');

    // The system roles: the administrator gets both, the editor and the reader none.
    $roles = (new ReflectionMethod(SystemRolePresetSynchronizer::class, 'roleDefinitions'))->invoke(
        (new ReflectionClass(SystemRolePresetSynchronizer::class))->newInstanceWithoutConstructor(), $registry->all());
    $byRole = array_column($roles, 2, 0);
    Kit::check(count(array_intersect(['update.integrity.read', 'update.integrity.apply'], $byRole[SystemRolePresetSynchronizer::ADMINISTRATOR])) === 2, 'the administrator holds both');
    Kit::check(array_values(array_filter($byRole[SystemRolePresetSynchronizer::EDITOR], static fn (string $code): bool => str_starts_with($code, 'update.integrity.'))) === [], 'the editor holds none');
    Kit::check(array_values(array_filter($byRole[SystemRolePresetSynchronizer::READER], static fn (string $code): bool => str_starts_with($code, 'update.integrity.'))) === [], 'the reader holds none');

    // The page, its actions and the alert bar ask for these rights only.
    $routes = (string) file_get_contents(__DIR__ . '/../../routes/integrity.php');
    Kit::check(!str_contains($routes, 'update.safety.') && substr_count($routes, 'access:update.integrity.read') === 2
        && substr_count($routes, 'access:update.integrity.apply') === 6, 'the routes ask for the integrity rights');
    foreach (['IntegrityAdminController', 'IntegrityAdminAlertSource'] as $class) {
        Kit::check(!str_contains((string) file_get_contents(__DIR__ . '/../../src/Laravel/Integrity/' . $class . '.php'), 'update.safety.'), $class . ' needs no update centre right');
    }

    echo "IntegrityAccessOperationsTest passed.\n";
})();
