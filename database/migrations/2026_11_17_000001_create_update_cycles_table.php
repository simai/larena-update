<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Larena\UpdateClient\Runtime\PdoUpdateExecutionStore;

// The table of update cycles (plan 2026-11-17-update-client-plan.md, §3): the same statement the store declares, so the
// store and the migration can never drift apart.
return new class extends Migration
{
    public function up(): void
    {
        $connection = DB::connection();
        $driver = $connection->getDriverName();
        $connection->statement(PdoUpdateExecutionStore::schema($driver === 'mariadb' ? 'mysql' : $driver));
    }

    public function down(): void
    {
        Schema::dropIfExists(PdoUpdateExecutionStore::TABLE);
    }
};
