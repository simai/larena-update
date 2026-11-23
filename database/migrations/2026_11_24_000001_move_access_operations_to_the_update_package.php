<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// The package larena/update-client is now larena/update (2026-11-24). Rights are granted by operation code
// (update.integrity.read, update.integrity.apply), so no grant changes; only the owner package kept beside each
// operation is rewritten, so the access lifecycle check sees the stored rows match the registry before the next
// preset sync. Idempotent: a second run finds nothing to change. Nothing is deleted.
return new class extends Migration
{
    private const string TABLE = 'larena_access_operations';
    private const string OLD = 'larena/update-client';
    private const string NEW = 'larena/update';

    public function up(): void
    {
        if (!Schema::hasTable(self::TABLE)) {
            return;
        }
        DB::table(self::TABLE)->where('owner_package', self::OLD)->update(['owner_package' => self::NEW]);
    }

    public function down(): void
    {
        if (!Schema::hasTable(self::TABLE)) {
            return;
        }
        DB::table(self::TABLE)
            ->where('owner_package', self::NEW)
            ->whereIn('code', ['update.integrity.read', 'update.integrity.apply'])
            ->update(['owner_package' => self::OLD]);
    }
};
