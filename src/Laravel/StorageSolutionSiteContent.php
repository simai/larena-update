<?php

declare(strict_types=1);

namespace Larena\UpdateClient\Laravel;

use Closure;
use Illuminate\Database\ConnectionInterface;
use Larena\UpdateClient\Solutions\SolutionSiteContent;
use RuntimeException;
use stdClass;
use Throwable;

/**
 * Whether the site is clean for a solution's storage SitePack (larena/storage), read only: the pack's one structure is
 * read from its archive (`StorageSitePackSource`), then the site's own records there are counted.
 *
 * - A pack of a structure that adopted a schema in place (the site pages, `adoption`): the records of that schema; the
 *   starter site's untouched Home page as the first run left it is not in the way (the storage import replaces it).
 * - Any other structure: the records of the site's structure of the same id, if the site has one.
 *
 * No query writes, no import plan is started (its run tables would be a write). Without larena/storage in the site a
 * pack cannot be read: `solution_sitepack_unsupported`.
 */
final readonly class StorageSolutionSiteContent implements SolutionSiteContent
{
    /** @param Closure(): ConnectionInterface $connection */
    public function __construct(private Closure $connection)
    {
    }

    public function blockers(string $sitepackPath): array
    {
        if (!class_exists(\Larena\Storage\SitePack\StorageSitePackSource::class) || !class_exists(\Larena\Core\SitePack\SitePackLimits::class)) {
            throw new RuntimeException('solution_sitepack_unsupported');
        }
        try {
            $source = \Larena\Storage\SitePack\StorageSitePackSource::open($sitepackPath, new \Larena\Core\SitePack\SitePackLimits());
        } catch (Throwable $exception) {
            $code = $exception->getMessage();
            throw new RuntimeException(preg_match('/\A[a-z][a-z0-9_.-]{0,99}\z/D', $code) === 1 ? $code : 'solution_sitepack_unreadable', 0, $exception);
        }
        try {
            $adoption = $source->adoption();
            $structureId = (string) $source->structure['id'];
            $label = (string) $source->structure['label'];
        } finally {
            $source->close();
        }

        /** @var \Illuminate\Database\Connection $database */
        $database = ($this->connection)();
        $schema = $database->getSchemaBuilder();
        if (!$schema->hasTable('larena_storage_records')) {
            return [];
        }
        if ($adoption !== null) {
            $schemaId = $adoption['schema_id'];
        } else {
            if (!$schema->hasTable('larena_storage_workbench_structures')) {
                return [];
            }
            $head = $database->table('larena_storage_workbench_structures')->where('structure_id', $structureId)->first();
            $schemaId = $head instanceof stdClass && is_string($head->storage_schema_id ?? null) ? $head->storage_schema_id : null;
            if ($schemaId === null) {
                return [];
            }
        }
        $count = (int) $database->table('larena_storage_records')->where('schema_id', $schemaId)->count();
        if ($count === 0) {
            return [];
        }
        if ($count === 1 && $adoption !== null && class_exists(\Larena\Storage\SitePack\StorageSitePackPlanner::class)) {
            $only = (string) $database->table('larena_storage_records')->where('schema_id', $schemaId)->value('record_id');
            if (\Larena\Storage\SitePack\StorageSitePackPlanner::untouchedStarterPage($database, $schemaId, $only)) {
                return [];
            }
        }

        return [['structure' => $adoption !== null ? $schemaId : $structureId, 'label' => $label, 'records' => $count]];
    }
}
