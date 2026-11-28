<?php

declare(strict_types=1);

namespace Larena\UpdateClient\Laravel;

use Closure;
use Larena\Filesystem\Enums\FileLifecycleClass;
use Larena\Filesystem\Enums\FileVisibility;
use Larena\Filesystem\Runtime\ArrayLogicalFile;
use Larena\Filesystem\Runtime\ArrayStorageBlobReference;
use Larena\Licensing\Runtime\StaticCapability;
use Larena\UpdateClient\Contracts\ArtifactVerificationResult;
use Larena\UpdateClient\Contracts\EntitlementSnapshot;
use Larena\UpdateClient\Contracts\UpdateManifest;
use Larena\UpdateClient\Data\UpdateCycleRequest;
use Larena\UpdateClient\Enums\ArtifactVerificationStatus;
use Larena\UpdateClient\Packages\PackageBatch;
use Larena\UpdateClient\Packages\PackageBatchCandidate;
use Larena\UpdateClient\Packages\PackageBatchPlan;
use Larena\UpdateClient\Packages\PackageListState;
use Larena\UpdateClient\Packages\PackageListVerifier;
use Larena\UpdateClient\Packages\PackageSource;
use Larena\UpdateClient\Packages\PackageUpdateLicence;
use Larena\UpdateClient\Packages\SiteLicensingUpdateLicence;
use Larena\UpdateClient\Release\ReleaseArchiveExtractor;
use Larena\UpdateClient\Release\ReleaseDirectory;
use Larena\UpdateClient\Release\ReleasePointer;
use Larena\UpdateClient\Release\ReleaseSignatureVerifier;
use Larena\UpdateClient\Release\ReleaseUpdateCycle;
use Larena\UpdateClient\Release\VerifiedRelease;
use Larena\UpdateClient\Runtime\PdoUpdateExecutionStore;
use Larena\UpdateClient\Source\ReleaseListState;
use Larena\UpdateClient\Source\ReleaseSource;
use Larena\UpdateClient\Source\UpdateHttpClient;
use PDO;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Puts the update cycle together for one installed site from the package configuration (`config/update-client.php`):
 * the site root of the release layout, the trusted keys, the sources, the store in the site database and the real
 * seams ({@see SitePreflight}, {@see LarenaBackupSiteCopy}, {@see ArtisanMaintenanceMode},
 * {@see ArtisanReleaseMigrations}, {@see HttpSiteHealth}, {@see OpcacheResetActivation}). The commands use it; it
 * holds no state between calls.
 */
final readonly class SiteUpdateClient
{
    /**
     * The public release keys the distribution ships (plan §2): `config/larena-release-keys.php` of the site,
     * `key id => base64 public key`, read by Laravel as the `larena-release-keys` configuration.
     */
    public const RELEASE_KEYS_CONFIG = 'larena-release-keys';

    /**
     * The `update-client` configuration with the distribution's release keys added to `trusted_keys`. The same key
     * id with another key in both places is refused (`release_trusted_key_conflict`): one id names one key.
     *
     * @param array<string,mixed> $config
     * @return array<string,mixed>
     */
    public static function withReleaseKeys(array $config, mixed $releaseKeys): array
    {
        $keys = is_array($config['trusted_keys'] ?? null) ? $config['trusted_keys'] : [];
        foreach (is_array($releaseKeys) ? $releaseKeys : [] as $id => $key) {
            if (array_key_exists($id, $keys) && $keys[$id] !== $key) {
                throw new RuntimeException('release_trusted_key_conflict');
            }
            $keys[$id] = $key;
        }
        $config['trusted_keys'] = $keys;

        return $config;
    }

    /**
     * @param array<string,mixed> $config the `update-client` configuration
     * @param Closure(): PDO      $database  the site database connection
     * @param (Closure(): PDO)|null $reconnect a new connection to the site database (after a restore replaced it)
     * @param (Closure(): ?\Larena\Licensing\Site\SiteLicensing)|null $licensing the site's licence (larena/licensing)
     */
    public function __construct(
        private array $config,
        private string $basePath,
        private string $appUrl,
        private Closure $database,
        private LoggerInterface $logger,
        private UpdateHttpClient $http,
        private ArtisanRunner $artisan,
        private ?Closure $reconnect = null,
        private ?Closure $licensing = null,
    ) {
    }

    /**
     * `<site>`: the configured root, or two levels above this release (`<site>/releases/<release>`). Outside the
     * release layout there is nothing to update (`update_release_layout_missing`).
     */
    public function siteRoot(): string
    {
        $configured = $this->config['site_root'] ?? null;
        if (is_string($configured) && trim($configured) !== '') {
            return ReleaseDirectory::site($configured);
        }
        $release = rtrim($this->basePath, '/');
        if (basename(dirname($release)) !== 'releases') {
            throw new RuntimeException('update_release_layout_missing');
        }

        return ReleaseDirectory::site(dirname($release, 2));
    }

    /** The version the live release runs (its `.larena-release` marker, or its id when that is a version). */
    public function installedVersion(): string
    {
        return (new ReleasePointer($this->siteRoot()))->currentVersion() ?? throw new RuntimeException('release_current_missing');
    }

    /** The release folder `current` points to (`releases/<release>`). */
    public function installedRelease(): string
    {
        return (new ReleasePointer($this->siteRoot()))->current() ?? throw new RuntimeException('release_current_missing');
    }

    public function channel(): string
    {
        $channel = $this->config['channel'] ?? 'stable';

        return is_string($channel) && UpdateManifest::isStableKey($channel) ? $channel : throw new RuntimeException('update_channel_invalid');
    }

    public function siteUrl(): string
    {
        $url = $this->config['site_url'] ?? null;

        return is_string($url) && trim($url) !== '' ? trim($url) : $this->appUrl;
    }

    /** @return list<string> */
    public function trustedKeyIds(): array
    {
        $keys = $this->config['trusted_keys'] ?? [];

        return is_array($keys) ? array_values(array_filter(array_keys($keys), 'is_string')) : [];
    }

    /** @return list<string> */
    public function sources(): array
    {
        $sources = $this->config['sources'] ?? [];

        return is_array($sources) ? array_values(array_filter($sources, 'is_string')) : [];
    }

    public function store(): PdoUpdateExecutionStore
    {
        return new PdoUpdateExecutionStore(($this->database)(), $this->reconnect);
    }

    /** The product a release must name (`product`, `LARENA_UPDATE_PRODUCT`): `larena` unless set, `larena-panel` for the panel. */
    public function product(): string
    {
        return self::productOf($this->config);
    }

    /** @param array<array-key,mixed> $config the `update-client` configuration */
    public static function productOf(array $config): string
    {
        $product = $config['product'] ?? null;
        $product = is_string($product) && trim($product) !== '' ? trim($product) : 'larena';

        return preg_match('/\A[a-z0-9][a-z0-9._\/-]{0,99}\z/D', $product) === 1 ? $product : throw new RuntimeException('update_product_invalid');
    }

    public function verifier(): ReleaseSignatureVerifier
    {
        $keys = $this->config['trusted_keys'] ?? [];
        $revoked = $this->config['revoked_key_ids'] ?? [];

        return new ReleaseSignatureVerifier(
            is_array($keys) ? $keys : [],
            is_array($revoked) ? array_values(array_filter($revoked, 'is_string')) : [],
            $this->product(),
        );
    }

    public function source(): ReleaseSource
    {
        $download = (array) ($this->config['download'] ?? []);
        $directory = $download['directory'] ?? null;

        return new ReleaseSource(
            $this->http,
            $this->sources(),
            is_string($directory) && $directory !== '' ? $directory : $this->siteRoot() . '/storage/app/larena/update/downloads',
            $this->verifier(),
            self::int($download['timeout'] ?? null, 600),
            self::int($download['connect_timeout'] ?? null, 15),
            self::int($download['max_archive_bytes'] ?? null, 1073741824),
            is_string($this->config['tag_prefix'] ?? null) ? $this->config['tag_prefix'] : 'v',
            is_string($this->config['site_key'] ?? null) ? $this->config['site_key'] : null,
            $this->releaseListState(),
        );
    }

    /** The highest release description number seen per product and channel, in the shared storage. */
    public function releaseListState(): ReleaseListState
    {
        return ReleaseListState::forSite($this->siteRoot());
    }

    public function preflight(): SitePreflight
    {
        $queue = (array) ($this->config['queue'] ?? []);
        $pdo = ($this->database)();

        return new SitePreflight($pdo, new PdoUpdateExecutionStore($pdo), is_string($queue['jobs_table'] ?? null) ? $queue['jobs_table'] : 'jobs',
            selfAddress: $this->selfAddressCheck());
    }

    /** The site's own address as the health check and the activation will call it, checked before the first write. */
    public function selfAddressCheck(): SelfAddressCheck
    {
        $health = (array) ($this->config['health'] ?? []);
        $activation = (array) ($this->config['activation'] ?? []);

        return new SelfAddressCheck(
            $this->http, $this->siteUrl(),
            is_string($activation['path'] ?? null) ? $activation['path'] : '_larena/update/opcache-reset',
            self::int($health['timeout'] ?? null, 10), (bool) ($health['verify_tls'] ?? true),
        );
    }

    public function activationTokens(): ActivationTokenStore
    {
        return new ActivationTokenStore($this->siteRoot() . '/storage/app/larena/update', self::int(($this->config['activation'] ?? [])['token_ttl'] ?? null, 120));
    }

    /** The cycle with the real seams; `$actorRef` is the administrator the backup package checks. */
    public function cycle(string $actorRef, ?SitePreflight $preflight = null): ReleaseUpdateCycle
    {
        $site = $this->siteRoot();
        $process = (array) ($this->config['process'] ?? []);
        $health = (array) ($this->config['health'] ?? []);
        $activation = (array) ($this->config['activation'] ?? []);
        $verifyTls = (bool) ($health['verify_tls'] ?? true);
        $healthTimeout = self::int($health['timeout'] ?? null, 10);
        $bypass = new MaintenanceBypass();
        $copy = new LarenaBackupSiteCopy($this->artisan, $actorRef, self::int($process['backup_wait'] ?? null, 3600));
        $store = $this->store();

        return new ReleaseUpdateCycle(
            $site,
            $this->verifier(),
            new ReleaseArchiveExtractor(),
            $preflight ?? $this->preflight(),
            $copy,
            $copy,
            new ArtisanMaintenanceMode($this->artisan, $bypass),
            new ArtisanReleaseMigrations($this->artisan),
            new HttpSiteHealth($this->http, $this->siteUrl(), $bypass, $healthTimeout, $verifyTls),
            [new OpcacheResetActivation(
                $this->http, $this->artisan, $this->activationTokens(), $bypass, $this->siteUrl(),
                is_string($activation['path'] ?? null) ? $activation['path'] : '_larena/update/opcache-reset',
                $healthTimeout, $verifyTls,
            )],
            $store,
            new LoggerUpdateLifecycleAudit($this->logger),
        );
    }

    /** The cycle request for a verified release: every reference in it is derived from the signed description. */
    public function request(VerifiedRelease $release, string $actorRef, string $idempotencyKey): UpdateCycleRequest
    {
        $manifestKey = 'release.v' . str_replace('.', '_', $release->version);
        $signatureRef = 'release.json.sig:' . $release->keyId;
        $checksum = 'sha256:' . $release->archiveSha256;
        $host = parse_url($this->siteUrl(), PHP_URL_HOST);

        return new UpdateCycleRequest(
            $actorRef,
            'update:' . $release->version . ':' . substr($release->releaseSha256, 0, 16),
            $idempotencyKey,
            'update.plan.release',
            new UpdateManifest($manifestKey, $release->channel, array_keys($release->packages), $signatureRef, true),
            new EntitlementSnapshot('update.open_channel', is_string($host) && $host !== '' ? $host : 'site', ['update.install'], true, true, true,
                signingKeyId: $release->keyId, signatureValue: 'sha256:' . $release->releaseSha256),
            new StaticCapability('update.install', 'larena/update'),
            new ArtifactVerificationResult('release.archive', $checksum, $signatureRef, $manifestKey, ArtifactVerificationStatus::Verified, true, true),
            new ArrayLogicalFile(
                'logical:update:' . $release->version, 'update-' . $release->version,
                new ArrayStorageBlobReference($checksum, 'update.downloads', $release->archiveBytes, 'application/gzip'),
                FileVisibility::Internal, FileLifecycleClass::Temporary,
            ),
            ReleaseUpdateCycle::STEPS,
        );
    }

    /** The package releases of the same sources (`<source>/packages/…`), downloaded next to the releases. */
    public function packageSource(): PackageSource
    {
        $download = (array) ($this->config['download'] ?? []);
        $directory = $download['directory'] ?? null;

        return new PackageSource(
            $this->http,
            $this->sources(),
            is_string($directory) && $directory !== '' ? $directory : $this->siteRoot() . '/storage/app/larena/update/downloads',
            self::int($download['timeout'] ?? null, 600),
            self::int($download['connect_timeout'] ?? null, 15),
            is_string($this->config['site_key'] ?? null) ? $this->config['site_key'] : null,
        );
    }

    public function packageListVerifier(): PackageListVerifier
    {
        return new PackageListVerifier($this->verifier());
    }

    public function packageListState(): PackageListState
    {
        return PackageListState::forSite($this->siteRoot());
    }

    /** The licence check of closed packages: `SiteLicensing::allowsUpdate()` where larena/licensing is in the site. */
    public function packageLicence(): PackageUpdateLicence
    {
        $licensing = $this->licensing !== null ? ($this->licensing)() : null;

        return new SiteLicensingUpdateLicence($licensing instanceof \Larena\Licensing\Site\SiteLicensing ? $licensing : null);
    }

    public function packageCandidate(PackageBatch $batch): PackageBatchCandidate
    {
        return new PackageBatchCandidate($this->verifier(), new ReleaseArchiveExtractor(), $this->packageLicence(), $batch, $this->channel());
    }

    /** The cycle request for a planned batch: every reference derived from the signed list and the chosen releases. */
    public function batchRequest(PackageBatchPlan $plan, string $actorRef, string $idempotencyKey): UpdateCycleRequest
    {
        $list = $plan->list;
        $manifestKey = 'packages.list_' . $list->listVersion;
        $signatureRef = 'packages.json.sig:' . $list->keyId;
        $checksum = 'sha256:' . $list->sha256;
        $bytes = 0;
        foreach ($plan->releases as $release) {
            $bytes += $release->archiveBytes;
        }
        $host = parse_url($this->siteUrl(), PHP_URL_HOST);

        return new UpdateCycleRequest(
            $actorRef,
            'packages:' . $list->listVersion . ':' . substr(hash('sha256', (string) json_encode($plan->selection())), 0, 16),
            $idempotencyKey,
            'update.plan.package_batch',
            new UpdateManifest($manifestKey, $list->channel, array_keys($plan->selection()), $signatureRef, true),
            new EntitlementSnapshot('update.open_channel', is_string($host) && $host !== '' ? $host : 'site', ['update.install'], true, true, true,
                signingKeyId: $list->keyId, signatureValue: $checksum),
            new StaticCapability('update.install', 'larena/update'),
            new ArtifactVerificationResult('packages.batch', $checksum, $signatureRef, $manifestKey, ArtifactVerificationStatus::Verified, true, true),
            new ArrayLogicalFile(
                'logical:update:packages:' . $list->listVersion, 'packages-' . $list->listVersion,
                new ArrayStorageBlobReference($checksum, 'update.downloads', max(1, $bytes), 'application/gzip'),
                FileVisibility::Internal, FileLifecycleClass::Temporary,
            ),
            ReleaseUpdateCycle::STEPS,
        );
    }

    private static function int(mixed $value, int $default): int
    {
        return is_numeric($value) && (int) $value > 0 ? (int) $value : $default;
    }
}
