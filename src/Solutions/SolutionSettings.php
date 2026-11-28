<?php

declare(strict_types=1);

namespace Larena\UpdateClient\Solutions;

use Larena\UpdateClient\Packages\PackageListVerifier;

/**
 * The rules for a solution's `settings` (§14 stage 4): no secret ever travels in a solution, and a solution sets only
 * the keys its packages declare.
 *
 * - {@see secretKey()}: a key that names a secret — a word `secret`, `password`, `passwd`, `token`, `credential`,
 *   `apikey`, `private` anywhere, or a part `key`, `keys`, `pass`, `pwd`, `dsn`, `auth`, `salt`, `pin`, `otp`
 *   (or one ending in `_key`/`_token`/`_secret`).
 * - {@see secretValue()}: a value that looks like one — a PEM block, a JSON web token, a known key prefix (`sk-`,
 *   `ghp_`, `xoxb-`, `AKIA…`, `AIza…`, `glpat-`), an address with a password in it, or one long word of 32 and more
 *   key characters with letters and digits mixed.
 * - {@see declared()}: the `settings` block of a package's `module.yaml` — a list (`- shop.currency`) or a mapping
 *   (`shop.currency:` …) of dotted keys; null when the file has none.
 */
final class SolutionSettings
{
    private const SECRET_WORDS = '/(secret|password|passwd|token|credential|apikey|private)/i';
    private const SECRET_PARTS = ['key', 'keys', 'pass', 'pwd', 'dsn', 'auth', 'salt', 'pin', 'otp'];

    public static function secretKey(string $key): bool
    {
        if (preg_match(self::SECRET_WORDS, $key) === 1) {
            return true;
        }
        foreach (preg_split('/[._-]/', strtolower($key)) ?: [] as $part) {
            if (in_array($part, self::SECRET_PARTS, true) || str_ends_with($part, 'key')) {
                return true;
            }
        }

        return false;
    }

    public static function secretValue(mixed $value): bool
    {
        if (is_array($value)) {
            foreach ($value as $key => $item) {
                if ((is_string($key) && self::secretKey($key)) || self::secretValue($item)) {
                    return true;
                }
            }

            return false;
        }
        if (!is_string($value)) {
            return false;
        }
        $value = trim($value);
        if (str_contains($value, '-----BEGIN')
            || preg_match('/\AeyJ[A-Za-z0-9_-]{8,}\.[A-Za-z0-9_-]{8,}\.[A-Za-z0-9_-]{8,}\z/D', $value) === 1
            || preg_match('/\A(sk|pk|rk)[-_](live|test|proj)?[-_]?[A-Za-z0-9]{16,}/', $value) === 1
            || preg_match('/\A(ghp|gho|ghu|ghs|github_pat|glpat)[-_][A-Za-z0-9_]{16,}/', $value) === 1
            || preg_match('/\Axox[abprs]-[A-Za-z0-9-]{10,}/', $value) === 1
            || preg_match('/\A(AKIA|ASIA)[0-9A-Z]{16}\z/D', $value) === 1
            || preg_match('/\AAIza[0-9A-Za-z_-]{30,}\z/D', $value) === 1
            || preg_match('#\A[a-z][a-z0-9+.-]*://[^/\s:@]+:[^/\s@]+@#i', $value) === 1) {
            return true;
        }

        return strlen($value) >= 32 && preg_match('/\A[A-Za-z0-9+\/=_-]+\z/D', $value) === 1
            && preg_match('/[A-Za-z]/', $value) === 1 && preg_match('/\d/', $value) === 1;
    }

    /**
     * The setting keys a package's module.yaml declares in its top-level `settings` block; null without one.
     *
     * @return list<string>|null
     */
    public static function declared(string $moduleYaml): ?array
    {
        if (is_link($moduleYaml) || !is_file($moduleYaml) || filesize($moduleYaml) > 262144) {
            return null;
        }
        $lines = file($moduleYaml, FILE_IGNORE_NEW_LINES) ?: [];
        $inside = false;
        $found = false;
        $keys = [];
        $indent = null;
        foreach ($lines as $line) {
            if (!$inside) {
                if (preg_match('/\Asettings:\s*(#.*)?\z/', $line) === 1) {
                    $inside = true;
                    $found = true;
                } elseif (preg_match('/\Asettings:\s*\[\s*\]\s*\z/', $line) === 1) {
                    return [];
                }
                continue;
            }
            if (trim($line) === '' || str_starts_with(ltrim($line), '#')) {
                continue;
            }
            if (!str_starts_with($line, ' ')) {
                break;
            }
            $current = strlen($line) - strlen(ltrim($line, ' '));
            $indent ??= $current;
            if ($current !== $indent) {
                continue;
            }
            $item = trim($line);
            if (preg_match('/\A-\s+["\']?([a-z0-9_.]+)["\']?\s*(#.*)?\z/', $item, $match) === 1
                || preg_match('/\A["\']?([a-z0-9_.]+)["\']?:/', $item, $match) === 1) {
                if (preg_match(PackageListVerifier::SETTING, $match[1]) === 1) {
                    $keys[$match[1]] = true;
                }
            }
        }
        if (!$found) {
            return null;
        }
        $keys = array_keys($keys);
        sort($keys, SORT_STRING);

        return $keys;
    }
}
