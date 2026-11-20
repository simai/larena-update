<?php

declare(strict_types=1);

namespace Larena\UpdateClient\Integrity;

/**
 * What a check found, by kind:
 * - `changed`, `deleted`, `type_differs`: a file of the release is not as signed (or is gone);
 * - `htaccess_replaced`: a `.htaccess` of the release is not as signed;
 * - `new_php`: a PHP file that is not in the release, in a code folder or a data folder (uploads, caches);
 * - `new_htaccess`: a `.htaccess` that is not in the release (in the release, or among the public uploads);
 * - `archive_damaged`, `archive_missing`: the kept release archive «Вернуть как было» takes clean files from.
 */
final class Finding
{
    public const KINDS = ['changed', 'deleted', 'type_differs', 'htaccess_replaced', 'new_php', 'new_htaccess', 'archive_damaged', 'archive_missing'];

    /** Kinds «Вернуть как было» writes again from the archive. */
    public const RESTORED = ['changed', 'deleted', 'type_differs', 'htaccess_replaced'];

    /** Kinds «Вернуть как было» moves to the quarantine. */
    public const QUARANTINED = ['new_php', 'new_htaccess'];

    /** The stable key of one finding: the same file with the same kind is the same finding on every check. */
    public static function key(string $kind, string $root, string $path): string
    {
        return substr(hash('sha256', $kind . "\0" . $root . "\0" . $path), 0, 24);
    }

    /**
     * @return array{key:string,kind:string,root:string,path:string,expected_sha256:?string,actual_sha256:?string,bytes:?int}
     */
    public static function make(string $kind, string $root, string $path, ?string $expected = null, ?string $actual = null, ?int $bytes = null): array
    {
        return ['key' => self::key($kind, $root, $path), 'kind' => $kind, 'root' => $root, 'path' => $path,
            'expected_sha256' => $expected, 'actual_sha256' => $actual, 'bytes' => $bytes];
    }
}
