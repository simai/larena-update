<?php

declare(strict_types=1);

namespace Larena\UpdateClient\Packages;

/**
 * The compatibility rule of a package release (`requires` of its `module.yaml` `delivery` block, written into the
 * signed package release): a small, strict part of Composer's syntax, so the site needs no Composer.
 *
 * - `*` — any version;
 * - `1.3.0`, `=1.3.0` — exactly; `>=1.3`, `>1.3.0`, `<2.0`, `<=1.4.2`, `!=1.3.1` — comparisons (a missing part is 0);
 * - `^1.3` — from 1.3.0 up to the next major (`^0.4` up to the next minor); `~1.3.2` — up to the next minor;
 * - several conditions separated by spaces (or a comma) must all hold; `||` separates alternatives.
 *
 * Versions are `major.minor.patch`; a leading `v` is dropped. Anything else — `dev-main`, a branch, a wildcard inside a
 * version — is not a rule the site can check: {@see satisfies()} is false, and {@see valid()} says so.
 */
final class VersionConstraint
{
    private const VERSION = '/\A(0|[1-9]\d{0,5})(?:\.(0|[1-9]\d{0,5}))?(?:\.(0|[1-9]\d{0,7}))?\z/D';

    public static function satisfies(string $version, string $constraint): bool
    {
        $installed = self::normalize($version);
        if ($installed === null || !self::valid($constraint)) {
            return false;
        }
        foreach (explode('||', $constraint) as $alternative) {
            $all = true;
            foreach (self::conditions($alternative) as $condition) {
                if (!self::holds($installed, $condition)) {
                    $all = false;
                    break;
                }
            }
            if ($all) {
                return true;
            }
        }

        return false;
    }

    public static function valid(string $constraint): bool
    {
        if (trim($constraint) === '' || strlen($constraint) > 200) {
            return false;
        }
        foreach (explode('||', $constraint) as $alternative) {
            $conditions = self::conditions($alternative);
            if ($conditions === []) {
                return false;
            }
            foreach ($conditions as $condition) {
                if ($condition !== '*' && self::parse($condition) === null) {
                    return false;
                }
            }
        }

        return true;
    }

    /** `major.minor.patch` of a version, or null when it is not one (`dev-main`). */
    public static function normalize(string $version): ?string
    {
        $version = ltrim(trim($version), 'vV');
        if (preg_match(self::VERSION, $version, $match) !== 1 || !isset($match[3])) {
            return null;
        }

        return $match[1] . '.' . $match[2] . '.' . $match[3];
    }

    /** @return list<string> */
    private static function conditions(string $alternative): array
    {
        $parts = preg_split('/[\s,]+/', trim($alternative), -1, PREG_SPLIT_NO_EMPTY);

        return $parts === false ? [] : $parts;
    }

    private static function holds(string $version, string $condition): bool
    {
        if ($condition === '*') {
            return true;
        }
        [$operator, $bound, $given] = self::parse($condition) ?? ['', '0.0.0', 0];

        return match ($operator) {
            '=' => version_compare($version, $bound, '=='),
            '!=' => version_compare($version, $bound, '!='),
            '>' => version_compare($version, $bound, '>'),
            '>=' => version_compare($version, $bound, '>='),
            '<' => version_compare($version, $bound, '<'),
            '<=' => version_compare($version, $bound, '<='),
            '^' => version_compare($version, $bound, '>=') && version_compare($version, self::caretLimit($bound), '<'),
            '~' => version_compare($version, $bound, '>=') && version_compare($version, self::tildeLimit($bound, $given), '<'),
            default => false,
        };
    }

    /** @return array{0:string,1:string,2:int}|null operator, bound as major.minor.patch, how many parts were given */
    private static function parse(string $condition): ?array
    {
        if (preg_match('/\A(>=|<=|!=|>|<|=|\^|~)?(.+)\z/D', $condition, $match) !== 1) {
            return null;
        }
        $version = ltrim($match[2], 'vV');
        if (preg_match(self::VERSION, $version, $parts) !== 1) {
            return null;
        }
        $given = count($parts) - 1;

        return [$match[1] === '' ? '=' : $match[1], $parts[1] . '.' . ($parts[2] ?? '0') . '.' . ($parts[3] ?? '0'), $given];
    }

    private static function caretLimit(string $bound): string
    {
        [$major, $minor] = array_map('intval', explode('.', $bound));

        return $major > 0 ? ($major + 1) . '.0.0' : '0.' . ($minor + 1) . '.0';
    }

    private static function tildeLimit(string $bound, int $given): string
    {
        [$major, $minor] = array_map('intval', explode('.', $bound));

        return $given <= 2 ? ($major + 1) . '.0.0' : $major . '.' . ($minor + 1) . '.0';
    }
}
