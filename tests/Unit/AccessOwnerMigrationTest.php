<?php

declare(strict_types=1);

require_once __DIR__ . '/../Support/UpdateClientFakes.php';

use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Support\Facades\Facade;
use Larena\UpdateClient\Tests\Support\UpdateClientTestKit as Kit;

// The package is now larena/update (2026-11-24): the migration moves the owner package kept beside its two rights to the
// new name, leaves every other row and every grant alone, runs twice without change and does nothing without Access.
(static function (): void {
    $previous = Facade::getFacadeApplication();
    $container = new Container();
    $capsule = new Capsule($container);
    $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
    $container->instance('db', $capsule->getDatabaseManager());
    $container->bind('db.schema', static fn () => $capsule->getConnection()->getSchemaBuilder());
    Facade::clearResolvedInstances();
    Facade::setFacadeApplication($container);
    try {
        $migration = require __DIR__ . '/../../database/migrations/2026_11_24_000001_move_access_operations_to_the_update_package.php';
        $migration->up();
        Kit::check(true, 'without the Access table the migration does nothing');

        $db = $capsule->getConnection();
        $db->statement('CREATE TABLE larena_access_operations (code VARCHAR(160) PRIMARY KEY, owner_package VARCHAR(160) NOT NULL)');
        $db->statement('CREATE TABLE larena_access_role_grants (role_id INTEGER, operation_code VARCHAR(160), allowed INTEGER)');
        $db->table('larena_access_operations')->insert([
            ['code' => 'update.integrity.read', 'owner_package' => 'larena/update-client'],
            ['code' => 'update.integrity.apply', 'owner_package' => 'larena/update-client'],
            ['code' => 'storage.record.read', 'owner_package' => 'larena/storage'],
        ]);
        $db->table('larena_access_role_grants')->insert([
            ['role_id' => 1, 'operation_code' => 'update.integrity.read', 'allowed' => 1],
            ['role_id' => 1, 'operation_code' => 'update.integrity.apply', 'allowed' => 1],
        ]);
        $owners = static fn (): array => $db->table('larena_access_operations')->orderBy('code')->pluck('owner_package', 'code')->all();

        $migration->up();
        $after = $owners();
        Kit::check($after === ['storage.record.read' => 'larena/storage', 'update.integrity.apply' => 'larena/update',
            'update.integrity.read' => 'larena/update'], 'the two rights move to larena/update, the rest stay: ' . json_encode($after));
        $migration->up();
        Kit::check($owners() === $after, 'a second run changes nothing');
        Kit::check($db->table('larena_access_role_grants')->where('allowed', 1)->count() === 2, 'every grant is kept');

        $migration->down();
        Kit::check($owners()['update.integrity.read'] === 'larena/update-client', 'down gives the old name back');
    } finally {
        Facade::clearResolvedInstances();
        if ($previous !== null) {
            Facade::setFacadeApplication($previous);
        }
    }
})();

echo "AccessOwnerMigrationTest passed.\n";
