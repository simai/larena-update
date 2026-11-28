<?php

declare(strict_types=1);

namespace Larena\UpdateClient\Solutions;

use JsonException;
use Larena\UpdateClient\Packages\PackageListVerifier;
use Larena\UpdateClient\Packages\VersionConstraint;
use Larena\UpdateClient\Release\ReleaseSignatureVerifier;
use RuntimeException;
use Throwable;

/**
 * S1 of a solution (§14 stage 4, matrix 34): `solution.json` with its detached signature `solution.json.sig` (the
 * `release.json.sig` format), read before anything of the solution is used.
 *
 * `{"schema":"larena.solution","schema_version":1,"id","version","product","product_version":"<rule>",
 * "packages":[{"name","version":"<rule>","capability":"<id>"|null}],"sitepack":{"ref":"<file>","sha256"}|null,
 * "settings":{"<key>":<value>}}` — `product_version` and each package `version` are rules of {@see VersionConstraint}
 * (`1.3.0` is exactly that version); `sitepack.ref` is a file next to `solution.json`.
 *
 * Signed by a release key of the site only ({@see ReleaseSignatureVerifier}: trusted, not revoked, never a
 * `package-list` key). Refusals ({@see SolutionRefused}): `solution_files_missing`, `solution_signature_invalid`
 * (detail: the signature reason), `solution_invalid` (detail: the field), `solution_product_differs`,
 * `solution_product_version_differs`, `solution_sitepack_invalid`, `solution_setting_secret` (a key or a value that
 * looks like a secret: only the key is named). Whether a key is declared is decided with the packages,
 * by {@see SolutionCheck}.
 */
final readonly class SolutionVerifier
{
    public const SCHEMA = 'larena.solution';
    public const FILE = 'solution.json';
    public const MAX_BYTES = 262144;
    private const MAX_SIGNATURE_BYTES = 1024;
    private const MAX_SETTINGS = 256;
    private const MAX_PACKAGES = 64;
    private const ID = '/\A[a-z0-9][a-z0-9._-]{0,99}\z/D';
    private const VERSION = '/\A(0|[1-9]\d{0,5})\.(0|[1-9]\d{0,5})\.(0|[1-9]\d{0,7})\z/D';
    private const PACKAGE = '/\A[a-z0-9][a-z0-9_.-]{0,99}\/[a-z0-9][a-z0-9_.-]{0,99}\z/D';
    private const CAPABILITY = '/\A[a-z][a-z0-9_.:-]{0,190}\z/D';
    private const SITEPACK_FILE = '/\A[A-Za-z0-9][A-Za-z0-9._-]{0,127}\z/D';

    public function __construct(private ReleaseSignatureVerifier $verifier)
    {
    }

    /**
     * Reads `<dir>/solution.json` (or the given file) and its `.sig` beside it; nothing is written.
     *
     * @return array{0: string, 1: string, 2: string} [bytes, signature bytes, the solution's directory]
     */
    public static function files(string $path): array
    {
        $file = is_dir($path) ? rtrim($path, '/') . '/' . self::FILE : $path;
        $signature = $file . '.sig';
        foreach ([$file => self::MAX_BYTES, $signature => self::MAX_SIGNATURE_BYTES] as $name => $limit) {
            if (is_link($name) || !is_file($name) || !is_readable($name) || filesize($name) > $limit) {
                throw SolutionRefused::one('solution_files_missing', basename($name));
            }
        }

        return [(string) file_get_contents($file), (string) file_get_contents($signature), dirname($file)];
    }

    public function verify(string $bytes, string $signature, string $installedVersion, string $directory): VerifiedSolution
    {
        try {
            $keyId = $this->verifier->signedBy($bytes, $signature, self::MAX_BYTES);
        } catch (Throwable $exception) {
            $code = $exception->getMessage();
            throw SolutionRefused::one('solution_signature_invalid', self::FILE, preg_match('/\A[a-z][a-z0-9_.-]{0,99}\z/D', $code) === 1 ? $code : 'release_signature_invalid');
        }
        // Only signed bytes are read from here on.
        try {
            $data = json_decode($bytes, true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw self::invalid('json');
        }
        if (!is_array($data) || array_is_list($data) || ($data['schema'] ?? null) !== self::SCHEMA || ($data['schema_version'] ?? null) !== 1) {
            throw self::invalid('schema');
        }
        $id = $data['id'] ?? null;
        $version = $data['version'] ?? null;
        if (!is_string($id) || preg_match(self::ID, $id) !== 1) {
            throw self::invalid('id');
        }
        if (!is_string($version) || preg_match(self::VERSION, $version) !== 1) {
            throw self::invalid('version');
        }
        if (($data['product'] ?? null) !== $this->verifier->product()) {
            throw SolutionRefused::one('solution_product_differs', self::FILE, is_string($data['product'] ?? null) ? $data['product'] : 'none');
        }
        $range = $data['product_version'] ?? null;
        if (!is_string($range) || $range === '' || strlen($range) > 200 || !VersionConstraint::valid($range)) {
            throw self::invalid('product_version');
        }
        if (!VersionConstraint::satisfies($installedVersion, $range)) {
            throw SolutionRefused::one('solution_product_version_differs', self::FILE, $range . ' (the site runs ' . $installedVersion . ')');
        }

        $packages = [];
        $entries = $data['packages'] ?? null;
        if (!is_array($entries) || !array_is_list($entries) || $entries === [] || count($entries) > self::MAX_PACKAGES) {
            throw self::invalid('packages');
        }
        $seen = [];
        foreach ($entries as $entry) {
            $name = is_array($entry) ? ($entry['name'] ?? null) : null;
            $rule = is_array($entry) ? ($entry['version'] ?? null) : null;
            $capability = is_array($entry) ? ($entry['capability'] ?? null) : null;
            if (!is_string($name) || preg_match(self::PACKAGE, $name) !== 1 || isset($seen[$name])
                || !is_string($rule) || $rule === '' || strlen($rule) > 200 || !VersionConstraint::valid($rule)
                || ($capability !== null && (!is_string($capability) || preg_match(self::CAPABILITY, $capability) !== 1))
                || !array_key_exists('capability', $entry)) {
                throw self::invalid('packages');
            }
            $seen[$name] = true;
            $packages[] = ['name' => $name, 'version' => $rule, 'capability' => $capability];
        }

        $sitepack = null;
        $pack = $data['sitepack'] ?? null;
        if ($pack !== null) {
            $ref = is_array($pack) ? ($pack['ref'] ?? null) : null;
            $sha = is_array($pack) ? ($pack['sha256'] ?? null) : null;
            if (!is_string($ref) || preg_match(self::SITEPACK_FILE, $ref) !== 1 || $ref === self::FILE || str_ends_with($ref, '.sig')
                || !is_string($sha) || preg_match('/\A[a-f0-9]{64}\z/D', $sha) !== 1) {
                throw self::invalid('sitepack');
            }
            $path = rtrim($directory, '/') . '/' . $ref;
            if (is_link($path) || !is_file($path)) {
                throw SolutionRefused::one('solution_sitepack_invalid', $ref, 'missing');
            }
            $actual = hash_file('sha256', $path);
            if (!is_string($actual) || !hash_equals($sha, $actual)) {
                throw SolutionRefused::one('solution_sitepack_invalid', $ref, 'sha256 differs');
            }
            $sitepack = ['ref' => $ref, 'sha256' => $sha, 'path' => $path];
        }

        $settings = $data['settings'] ?? [];
        if (!is_array($settings) || ($settings !== [] && array_is_list($settings)) || count($settings) > self::MAX_SETTINGS) {
            throw self::invalid('settings');
        }
        $secrets = [];
        foreach ($settings as $key => $value) {
            if (!is_string($key) || preg_match(PackageListVerifier::SETTING, $key) !== 1) {
                throw self::invalid('settings');
            }
            if (is_object($value) || (is_array($value) && strlen((string) json_encode($value)) > 8192)) {
                throw self::invalid('settings');
            }
            // Only the key is ever named: the value may be the secret itself.
            if (SolutionSettings::secretKey($key)) {
                $secrets[] = ['reason' => 'solution_setting_secret', 'subject' => $key, 'detail' => 'key'];
            } elseif (SolutionSettings::secretValue($value)) {
                $secrets[] = ['reason' => 'solution_setting_secret', 'subject' => $key, 'detail' => 'value'];
            }
        }
        if ($secrets !== []) {
            throw new SolutionRefused($secrets);
        }
        ksort($settings, SORT_STRING);

        return new VerifiedSolution($id, $version, $this->verifier->product(), $range, $packages, $sitepack, $settings, $keyId, hash('sha256', $bytes));
    }

    private static function invalid(string $field): SolutionRefused
    {
        return SolutionRefused::one('solution_invalid', self::FILE, $field);
    }
}
