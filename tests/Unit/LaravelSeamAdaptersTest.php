<?php

declare(strict_types=1);

require_once __DIR__ . '/../Support/UpdateClientFakes.php';

use Illuminate\Foundation\Http\MaintenanceModeBypassCookie;
use Illuminate\Http\Request;
use Larena\UpdateClient\Data\UpdateCycleSnapshot;
use Larena\UpdateClient\Enums\UpdateCycleState;
use Larena\UpdateClient\Laravel\ActivationTokenStore;
use Larena\UpdateClient\Laravel\ArtisanMaintenanceMode;
use Larena\UpdateClient\Laravel\ArtisanReleaseMigrations;
use Larena\UpdateClient\Laravel\Http\OpcacheResetController;
use Larena\UpdateClient\Laravel\HttpSiteHealth;
use Larena\UpdateClient\Laravel\LarenaBackupSiteCopy;
use Larena\UpdateClient\Laravel\LoggerUpdateLifecycleAudit;
use Larena\UpdateClient\Laravel\MaintenanceBypass;
use Larena\UpdateClient\Laravel\OpcacheResetActivation;
use Larena\UpdateClient\Laravel\ProcessArtisanRunner;
use Larena\UpdateClient\Laravel\SitePreflight;
use Larena\UpdateClient\Laravel\SiteUpdateClient;
use Larena\UpdateClient\Release\ReleaseSwitch;
use Larena\UpdateClient\Release\VerifiedRelease;
use Larena\UpdateClient\Runtime\PdoUpdateExecutionStore;
use Larena\UpdateClient\Source\UpdateHttpRequest;
use Larena\UpdateClient\Tests\Support\FakeArtisanRunner;
use Larena\UpdateClient\Tests\Support\FakeUpdateHttpClient;
use Larena\UpdateClient\Tests\Support\MemoryLogger;
use Larena\UpdateClient\Tests\Support\UpdateClientTestKit as Kit;

// The Laravel seams of the update cycle, each against temporary folders, sqlite, a fake HTTP client and a fake
// artisan runner (the process runner alone runs a throwaway PHP script). No network.
(static function (): void {
    $root = Kit::temp('adapters');
    $site = $root . '/site';
    foreach (['releases/1.2.0', 'storage/app'] as $directory) {
        mkdir($site . '/' . $directory, 0755, true);
    }
    symlink('releases/1.2.0', $site . '/current');
    $noop = static fn (): string => '';
    $client = static fn (array $config = []): SiteUpdateClient => new SiteUpdateClient(
        $config + ['site_root' => $site],
        $site . '/releases/1.2.0',
        'https://site.example.test',
        static fn (): PDO => new PDO('sqlite::memory:'),
        new MemoryLogger(),
        FakeUpdateHttpClient::serving([]),
        new FakeArtisanRunner($noop),
    );
    $release = new VerifiedRelease('larena', '1.3.0', 'stable', '1.0.0', '^8.3', 'larena-1.3.0.tar.gz', str_repeat('b', 64), 10,
        ['larena/core' => str_repeat('a', 40)], false, [], 'release.test', str_repeat('c', 64));

    try {
        // Composition: the site root comes from the release layout; requests are built from the signed release.
        $layout = new SiteUpdateClient([], $site . '/releases/1.2.0', 'https://site.example.test', static fn (): PDO => new PDO('sqlite::memory:'), new MemoryLogger(), FakeUpdateHttpClient::serving([]), new FakeArtisanRunner($noop));
        Kit::check($layout->siteRoot() === $site && $layout->installedVersion() === '1.2.0', 'The site root must be two levels above the release.');
        $flat = new SiteUpdateClient([], $root, 'https://site.example.test', static fn (): PDO => new PDO('sqlite::memory:'), new MemoryLogger(), FakeUpdateHttpClient::serving([]), new FakeArtisanRunner($noop));
        Kit::check(Kit::reason(static fn () => $flat->siteRoot()) === 'update_release_layout_missing', 'A site outside the release layout must be refused.');
        Kit::check(Kit::reason(static fn () => $client()->verifier()) === 'release_trusted_keys_missing', 'No trusted keys must refuse every release.');
        $request = $client()->request($release, 'user:1', 'update:1.3.0:test');
        Kit::check($request->manifest->isVerified() && $request->entitlement->isTrustedLocally() && $request->artifact->isVerifiedForStaging(), 'The request built from a verified release must pass the request rules.');

        // Preflight: disk, write access, queue, other cycles.
        $pdo = new PDO('sqlite::memory:');
        $pdo->exec(PdoUpdateExecutionStore::schema('sqlite'));
        $store = new PdoUpdateExecutionStore($pdo);
        $plenty = static fn (): float => 1e12;
        $preflight = new SitePreflight($pdo, $store, 'jobs', $plenty);
        $preflight->check($site, 1000);
        Kit::check($preflight->notes() === ['queue_check_skipped'], 'Without a jobs table the queue check is skipped and noted.');
        $pdo->exec('CREATE TABLE jobs (id INTEGER PRIMARY KEY, queue TEXT, payload TEXT, attempts INTEGER, reserved_at INTEGER NULL, available_at INTEGER, created_at INTEGER)');
        $pdo->exec("INSERT INTO jobs (queue, payload, attempts, reserved_at, available_at, created_at) VALUES ('default', '{}', 0, NULL, 0, 0)");
        $preflight->check($site, 1000);
        Kit::check($preflight->notes() === [], 'Waiting jobs are fine.');
        $pdo->exec('UPDATE jobs SET reserved_at = 1');
        Kit::check(Kit::reason(static fn () => $preflight->check($site, 1000)) === 'update_queue_busy', 'A job in work must stop the update.');
        $pdo->exec('DELETE FROM jobs');
        Kit::check(Kit::reason(static fn () => (new SitePreflight($pdo, $store, 'jobs', static fn (): float => 2999.0))->check($site, 1000)) === 'update_disk_space_low', 'Less than archive × 3 free must stop the update.');
        Kit::check(Kit::reason(static fn () => new SitePreflight($pdo, $store, 'jobs; DROP')) === 'update_queue_table_invalid', 'The jobs table name must be a plain identifier.');
        $store->create(new UpdateCycleSnapshot($request->cycleId(), $request, UpdateCycleState::Planned, 1, 1, 0));
        $preflight->check($site, 1000);
        $second = $client()->request($release, 'user:1', 'update:1.3.0:second');
        $store->create(new UpdateCycleSnapshot($second->cycleId(), $second, UpdateCycleState::Running, 1, 1, 0));
        Kit::check(Kit::reason(static fn () => $preflight->check($site, 1000)) === 'update_cycle_already_running', 'A second unfinished cycle must stop the update.');
        Kit::check(count($store->latest(5)) === 2 && count($store->unfinished()) === 2, 'The store must list recent and unfinished cycles.');
        if (function_exists('posix_getuid') && posix_getuid() !== 0) {
            chmod($site . '/releases', 0555);
            Kit::check(Kit::reason(static fn () => (new SitePreflight(null, null, 'jobs', $plenty))->check($site, 1000)) === 'update_releases_not_writable', 'A read-only releases folder must stop the update.');
            chmod($site . '/releases', 0755);
        }

        // Maintenance: down with the cycle secret, up; the bypass cookie is the one Laravel accepts.
        $artisan = new FakeArtisanRunner($noop);
        $bypass = new MaintenanceBypass();
        $maintenance = new ArtisanMaintenanceMode($artisan, $bypass);
        $maintenance->enter($site . '/releases/1.2.0');
        $maintenance->leave($site . '/releases/1.3.0');
        Kit::check($artisan->calls === [
            ['release' => $site . '/releases/1.2.0', 'arguments' => ['down', '--secret=' . $bypass->secret, '--retry=60']],
            ['release' => $site . '/releases/1.3.0', 'arguments' => ['up']],
        ], 'Maintenance must run down/up of the given release.');
        $cookie = rawurldecode(substr($bypass->headers()['Cookie'], strlen('laravel_maintenance=')));
        Kit::check(MaintenanceModeBypassCookie::isValid($cookie, $bypass->secret) && !MaintenanceModeBypassCookie::isValid($cookie, 'other'), 'The bypass cookie must be valid for the cycle secret only.');

        // Migrations, presets, caches: in the new release folder.
        $artisan = new FakeArtisanRunner($noop);
        $migrations = new ArtisanReleaseMigrations($artisan);
        $migrations->migrate('/site/releases/1.3.0');
        $migrations->syncAccessPresets('/site/releases/1.3.0');
        $migrations->clearCaches('/site/releases/1.3.0');
        Kit::check(array_column($artisan->calls, 'arguments') === [['migrate', '--force'], ['larena:access-sync-system-presets'], ['optimize:clear']], 'Release commands must run in order.');
        $failing = new ArtisanReleaseMigrations(new FakeArtisanRunner(static fn () => throw new RuntimeException('')));
        Kit::check(Kit::reason(static fn () => $failing->migrate('/site/releases/1.3.0')) === 'update_migrate_failed', 'A failed migration must carry its reason.');

        // Site copy through larena/backup commands: backup job, queue nudges, trial restore job.
        $statuses = ['aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa' => ['queued', 'completed'], 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb' => ['running', 'completed']];
        $backupHandler = static function (string $release, array $arguments) use (&$statuses): string {
            return match ($arguments[0]) {
                'larena:backup:run' => json_encode(['status' => 'passed', 'job_id' => str_repeat('a', 32), 'duplicate' => false]),
                'larena:backup:drill' => json_encode(['status' => 'passed', 'job_id' => str_repeat('b', 32), 'duplicate' => false]),
                'larena:backup:status' => json_encode(['status' => 'passed', 'job_status' => array_shift($statuses[$arguments[1]]) ?? 'completed']),
                'larena:backup:restore' => json_encode(['status' => 'passed', 'job_id' => $arguments[1]]),
                'larena:queue-work' => 'Processed jobs: 1',
                default => throw new RuntimeException('unexpected'),
            };
        };
        $artisan = new FakeArtisanRunner($backupHandler);
        $copy = new LarenaBackupSiteCopy($artisan, 'user:1', sleep: static function (): void {});
        $made = $copy->create($site, 'cycle123');
        Kit::check($made->ref === 'larena_backup.job_' . str_repeat('a', 32) && $made->restoreVerified, 'A completed backup with a completed drill is a verified copy.');
        Kit::check(array_map(static fn (array $call): string => $call['arguments'][0], $artisan->calls) === [
            'larena:backup:run', 'larena:backup:status', 'larena:queue-work', 'larena:backup:status',
            'larena:backup:drill', 'larena:backup:status', 'larena:queue-work', 'larena:backup:status',
        ], 'Backup, wait, drill, wait — in that order.');
        Kit::check($artisan->calls[0]['release'] === $site . '/releases/1.2.0'
            && $artisan->calls[0]['arguments'] === ['larena:backup:run', '--idempotency-key=update:cycle123:backup', '--actor=user:1', '--json'], 'The backup runs from the current release with the actor and a cycle key.');
        $copy->restoreDatabase($site, $made->ref);
        Kit::check(end($artisan->calls)['arguments'] === ['larena:backup:restore', str_repeat('a', 32), '--confirm=' . str_repeat('a', 32), '--actor=user:1', '--json'], 'The restore must confirm the backup job id.');
        Kit::check(Kit::reason(static fn () => $copy->restoreDatabase($site, str_repeat('a', 32))) === 'update_restore_failed', 'Only a copy reference of this adapter is restored.');
        $statuses = ['aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa' => ['completed'], 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb' => ['failed']];
        Kit::check(!(new LarenaBackupSiteCopy(new FakeArtisanRunner($backupHandler), 'user:1'))->create($site, 'cycle124')->restoreVerified, 'A failed drill is an unverified copy.');
        $statuses = ['aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa' => array_fill(0, 10, 'queued')];
        $time = 0;
        $slow = new LarenaBackupSiteCopy(new FakeArtisanRunner($backupHandler), 'user:1', 60, sleep: static function (): void {}, clock: static function () use (&$time): int { return $time += 40; });
        Kit::check(Kit::reason(static fn () => $slow->create($site, 'cycle125')) === 'update_backup_timeout', 'A backup that never ends must time out.');
        $refused = new FakeArtisanRunner(static fn (): string => json_encode(['status' => 'failed', 'reason' => 'backup_actor_invalid']));
        Kit::check(Kit::reason(static fn () => (new LarenaBackupSiteCopy($refused, 'user:1'))->create($site, 'cycle126')) === 'backup_actor_invalid', 'The backup reason must come through.');

        // Health: 5xx, no answer and a PHP error page fail; 2xx, 3xx and 4xx are a live application; the health address
        // counts only with its JSON marker (a release before it: Laravel's /up with 2xx).
        $marker = (string) (new \Larena\UpdateClient\Laravel\Http\SiteHealthController())()->getContent();
        $answers = new stdClass();
        $answers->map = ['/' => [200, 'ok'], '/admin/login' => [302, ''], HttpSiteHealth::PATH => [200, $marker], '/down' => [0, '']];
        $http = new FakeUpdateHttpClient(static function (UpdateHttpRequest $request) use ($answers): array {
            $answer = $answers->map[(string) parse_url($request->url, PHP_URL_PATH)] ?? [404, ''];

            return $answer[0] === 0 ? throw new RuntimeException('update_http_failed') : $answer;
        });
        $health = new HttpSiteHealth($http, 'https://site.example.test/', $bypass, 3);
        Kit::check($health->failedChecks($site) === [], 'A live site passes.');
        Kit::check(array_map(static fn (UpdateHttpRequest $r): string => $r->url, $http->requests) === ['https://site.example.test/', 'https://site.example.test/admin/login', 'https://site.example.test/_larena/update/health'], 'Health checks the home page, the admin login and the health address at APP_URL.');
        Kit::check($http->requests[0]->timeoutSeconds === 3 && !$http->requests[0]->followRedirects && isset($http->requests[0]->headers['Cookie']), 'Health uses a short timeout, no redirects and the maintenance bypass.');
        $answers->map = ['/' => [0, ''], '/admin/login' => [200, ''], HttpSiteHealth::PATH => [503, '']];
        Kit::check($health->failedChecks($site) === ['home', 'up'], 'No answer and 5xx must fail.');
        $fatal = "<br />\n<b>Fatal error</b>:  Uncaught Error: Class not found in /srv/site/x.php:3";
        $answers->map = ['/' => [200, $fatal], '/admin/login' => [200, 'PHP Parse error: syntax error'], HttpSiteHealth::PATH => [200, $fatal]];
        Kit::check($health->failedChecks($site) === ['home', 'admin_login', 'up'], 'A 200 with a PHP error page must fail every check.');
        $answers->map = ['/' => [200, 'ok'], '/admin/login' => [200, 'ok'], HttpSiteHealth::PATH => [200, '<html>ok</html>']];
        Kit::check($health->failedChecks($site) === ['up'], 'A 200 without the JSON marker is not the health address.');
        $answers->map = ['/' => [200, 'ok'], '/admin/login' => [200, 'ok'], HttpSiteHealth::PATH => [200, '{"status":"ok"}']];
        Kit::check($health->failedChecks($site) === ['up'], 'JSON without the marker must fail.');
        $answers->map = ['/' => [200, 'ok'], '/admin/login' => [200, 'ok'], '/up' => [200, '<html>Application up</html>']];
        Kit::check($health->failedChecks($site) === [], 'A release before the health address passes with Laravel\'s /up.');
        $answers->map = ['/' => [200, 'ok'], '/admin/login' => [200, 'ok'], '/up' => [200, $fatal]];
        Kit::check($health->failedChecks($site) === ['up'], 'An error page on /up must fail too.');

        // The site reaches itself before the first write: the health marker, and the activation address's own 404.
        $tokensForProbe = new ActivationTokenStore($site . '/storage/app/larena/update-probe');
        $resetController = new OpcacheResetController($tokensForProbe);
        $selfAnswers = new stdClass();
        $selfAnswers->health = [200, $marker];
        $selfAnswers->reset = null;
        $self = new FakeUpdateHttpClient(static function (UpdateHttpRequest $request) use ($selfAnswers, $resetController): array {
            if ($request->method === 'GET') {
                return $selfAnswers->health[0] === 0 ? throw new RuntimeException('update_http_failed') : $selfAnswers->health;
            }
            if ($selfAnswers->reset !== null) {
                return $selfAnswers->reset;
            }
            $response = $resetController(Request::create('/_larena/update/opcache-reset', 'POST', server: ['HTTP_X_LARENA_UPDATE_TOKEN' => $request->headers[OpcacheResetActivation::HEADER] ?? '']));

            return [$response->getStatusCode(), (string) $response->getContent()];
        });
        $probe = new \Larena\UpdateClient\Laravel\SelfAddressCheck($self, 'http://127.0.0.1:8182', '_larena/update/opcache-reset', 3);
        $probe->check();
        Kit::check($self->urls() === ['GET http://127.0.0.1:8182/_larena/update/health', 'POST http://127.0.0.1:8182/_larena/update/opcache-reset']
            && !isset($self->requests[1]->headers[OpcacheResetActivation::HEADER]) && !is_file($site . '/storage/app/larena/update-probe/activation-token.json'),
            'The self check asks the health address and the activation address without a token; nothing is issued.');
        $selfAnswers->health = [0, ''];
        Kit::check(Kit::reason(static fn () => $probe->check()) === 'update_self_health_unreachable', 'An unreachable own address must stop the update.');
        $selfAnswers->health = [200, $fatal];
        Kit::check(Kit::reason(static fn () => $probe->check()) === 'update_self_health_unreachable', 'A fatal page at the own address must stop the update.');
        $selfAnswers->health = [200, $marker];
        $selfAnswers->reset = [404, '{"message":"Not Found"}'];
        Kit::check(Kit::reason(static fn () => $probe->check()) === 'update_self_activation_unreachable', 'An activation address that is not this package\'s must stop the update.');
        $selfAnswers->reset = [200, 'ok'];
        Kit::check(Kit::reason(static fn () => $probe->check()) === 'update_self_activation_unreachable', 'Any other answer of the activation address must stop the update.');
        $selfAnswers->reset = [404, '{"status":"not_found"}'];
        $guarded = new SitePreflight(null, null, 'jobs', $plenty, $probe);
        $guarded->check($site, 1000);
        $selfAnswers->health = [0, ''];
        Kit::check(Kit::reason(static fn () => $guarded->check($site, 1000)) === 'update_self_health_unreachable', 'The preflight runs the self check.');

        // One-time activation token: one call, short life, only its hash on disk.
        $tokens = new ActivationTokenStore($site . '/storage/app/larena/update');
        $token = $tokens->issue();
        $file = $site . '/storage/app/larena/update/activation-token.json';
        Kit::check(!str_contains((string) file_get_contents($file), $token) && (fileperms($file) & 0777) === 0600, 'Only the token hash is stored, readable by the owner only.');
        Kit::check(!$tokens->consume(str_repeat('0', 64)) && !is_file($file), 'A wrong token must use up the stored one.');
        $token = $tokens->issue();
        Kit::check($tokens->consume($token) && !$tokens->consume($token), 'A token works exactly once.');
        $expired = new ActivationTokenStore($site . '/storage/app/larena/update', -1);
        Kit::check(!$expired->consume($expired->issue()), 'An expired token must be refused.');

        // The internal address: 200 with the token, 404 without it or the second time.
        $controller = new OpcacheResetController($tokens);
        $token = $tokens->issue();
        $call = static fn (string $value): int => $controller(Request::create('/_larena/update/opcache-reset', 'POST', server: ['HTTP_X_LARENA_UPDATE_TOKEN' => $value]))->getStatusCode();
        $first = $call($token);
        $second = $call($token);
        $empty = $call('');
        Kit::check([$first, $second, $empty] === [200, 404, 404], 'The activation address must work once per token.');

        // Activation after a switch: the site takes the token, then queue:restart from the new release.
        $artisan = new FakeArtisanRunner($noop);
        $site200 = new FakeUpdateHttpClient(static function (UpdateHttpRequest $request) use ($controller): array {
            $response = $controller(Request::create('/x', 'POST', server: ['HTTP_X_LARENA_UPDATE_TOKEN' => $request->headers[OpcacheResetActivation::HEADER]]));

            return [$response->getStatusCode(), (string) $response->getContent()];
        });
        (new OpcacheResetActivation($site200, $artisan, $tokens, $bypass, 'https://site.example.test'))->activated($site, new ReleaseSwitch('1.2.0', '1.3.0', true));
        Kit::check($site200->requests[0]->method === 'POST' && $site200->requests[0]->url === 'https://site.example.test/_larena/update/opcache-reset', 'The activation must call the internal address.');
        Kit::check($artisan->calls === [['release' => $site . '/releases/1.3.0', 'arguments' => ['queue:restart']]], 'queue:restart must run from the new release.');
        $broken = new FakeUpdateHttpClient(static fn (): array => [500, '']);
        Kit::check(Kit::reason(static fn () => (new OpcacheResetActivation($broken, $artisan, $tokens, $bypass, 'https://site.example.test'))->activated($site, new ReleaseSwitch('1.2.0', '1.3.0', true))) === 'update_opcache_reset_failed'
            && !is_file($file), 'A failed activation must fail the step and leave no token behind.');
        foreach ([[200, '<b>Fatal error</b>: x'], [200, '{"status":"ok"}'], [200, ''], [404, '{"status":"not_found"}']] as [$status, $body]) {
            $wrong = new FakeUpdateHttpClient(static fn (): array => [$status, $body]);
            Kit::check(Kit::reason(static fn () => (new OpcacheResetActivation($wrong, $artisan, $tokens, $bypass, 'https://site.example.test'))->activated($site, new ReleaseSwitch('1.2.0', '1.3.0', true))) === 'update_opcache_reset_failed',
                'Only the reset address\'s own JSON answer counts: ' . $status . ' ' . $body);
        }

        // Audit to the log: same fact rules as the reference audit.
        $logger = new MemoryLogger();
        $audit = new LoggerUpdateLifecycleAudit($logger);
        $snapshot = $store->require($request->cycleId());
        $audit->record('update.cycle.requested', $snapshot, ['channel' => 'stable']);
        Kit::check($logger->records[0]['message'] === 'update.cycle.requested' && $logger->records[0]['context']['channel'] === 'stable', 'Cycle events must reach the log.');
        Kit::check(Kit::reason(static fn () => $audit->record('update.cycle.requested', $snapshot, ['token' => 'x'])) === 'update_audit_facts_unsafe', 'Unsafe facts must be refused.');

        // The process runner: php <release>/artisan in the release folder, a list of arguments, a reason on failure.
        mkdir($root . '/release', 0755);
        file_put_contents($root . '/release/artisan', '<?php echo json_encode([getcwd(), array_slice($argv, 1)]); exit(in_array("fail", $argv, true) ? 3 : 0);');
        $runner = new ProcessArtisanRunner(PHP_BINARY, 30);
        $output = json_decode($runner->run($root . '/release', ['migrate', '--force'], 'update_migrate_failed'), true);
        Kit::check(realpath((string) $output[0]) === realpath($root . '/release') && $output[1] === ['migrate', '--force', '--no-interaction'], 'The runner must run the release artisan in its folder.');
        Kit::check(Kit::reason(static fn () => $runner->run($root . '/release', ['fail'], 'update_migrate_failed')) === 'update_migrate_failed', 'A non-zero exit must be the given reason.');
        Kit::check(Kit::reason(static fn () => $runner->run($root, ['up'], 'x')) === 'update_release_artisan_missing', 'A folder without artisan must be refused.');
    } finally {
        Kit::remove($root);
    }
})();
