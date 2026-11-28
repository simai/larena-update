<?php

declare(strict_types=1);

require_once __DIR__ . '/../Support/UpdateClientFakes.php';

use Larena\UpdateClient\Laravel\Admin\ReleaseInstallJobHandler;
use Larena\UpdateClient\Laravel\Admin\SiteUpdates;
use Larena\UpdateClient\Laravel\ReleaseInstall;
use Larena\UpdateClient\Laravel\SiteUpdateClient;
use Larena\UpdateClient\Runtime\PdoUpdateExecutionStore;
use Larena\UpdateClient\Tests\Support\FakeArtisanRunner;
use Larena\UpdateClient\Tests\Support\FakeUpdateHttpClient;
use Larena\UpdateClient\Tests\Support\MemoryLogger;
use Larena\UpdateClient\Tests\Support\UpdateClientTestKit as Kit;

// «Лицензия и обновления» → «Обновления» (owner task 2026-11-26): the running version, «Проверить сейчас» kept in a file so
// the page never waits for the network, «Установить» only for the offered version and only through the queue, the
// journal from the update store, the words of every reason. No network: a fake update server with a throwaway key.
(static function (): void {
    $pair = sodium_crypto_sign_keypair();
    $secret = sodium_crypto_sign_secretkey($pair);
    $public = base64_encode(sodium_crypto_sign_publickey($pair));
    $server = 'https://update.example.org/releases';
    $root = Kit::temp('updates-section');
    mkdir($root . '/releases/1.2.0', 0755, true);
    file_put_contents($root . '/releases/1.2.0/.larena-release', json_encode(['version' => '1.2.0']));
    symlink('releases/1.2.0', $root . '/current');
    $database = $root . '/site.sqlite';
    $pdo = new PDO('sqlite:' . $database);
    $pdo->exec(PdoUpdateExecutionStore::schema('sqlite'));
    [$release, $signature] = Kit::release('1.3.0', $secret);
    $files = Kit::server($server, $release, $signature, $secret);
    $http = FakeUpdateHttpClient::serving($files);
    $client = static fn (): SiteUpdateClient => new SiteUpdateClient(
        ['trusted_keys' => ['release.test' => $public], 'site_root' => $root, 'sources' => [$server], 'download' => ['directory' => $root . '/downloads']],
        $root . '/releases/1.2.0', 'https://site.example.test', static fn (): PDO => new PDO('sqlite:' . $database), new MemoryLogger(), $http,
        new FakeArtisanRunner(static fn (): string => ''),
    );
    $dispatched = [];
    $check = $root . '/storage/last-check.json';
    $updates = new SiteUpdates($client, $check, static function (string $version, string $actor, string $key) use (&$dispatched): string {
        $dispatched[] = [$version, $actor, $key];

        return 'job-1';
    });

    try {
        $view = $updates->overview();
        Kit::check($view['installed'] === '1.2.0' && $view['channel'] === 'stable' && $view['sources'] === 1, 'The page shows the running version.');
        Kit::check($view['check'] === null && $view['cycles'] === [] && $view['store_reason'] === null && $view['queue'], 'Nothing checked yet, an empty journal.');
        Kit::check($http->requests === [], 'Opening the page asks no server.');

        Kit::check($updates->install('1.3.0', 'user:admin_identity:1') === ['done' => false, 'reason' => 'update_offer_unknown'] && $dispatched === [], 'Nothing is installed before a check offers it.');
        Kit::check($updates->check() === ['done' => true, 'reason' => 'update_available'], 'The check finds 1.3.0.');
        Kit::check(count($http->requests) === 5 && str_ends_with($http->requests[0]->url, '/keys.json') && str_ends_with($http->requests[1]->url, '/index.json'), 'The check reads the key list, the index and the release it names.');
        $view = $updates->overview();
        Kit::check($view['check']['status'] === 'available' && $view['check']['version'] === '1.3.0' && $view['check']['notes'] === ['en' => 'Release 1.3.0'], 'What is in it is kept.');
        $http->requests = [];
        $updates->overview();
        Kit::check($http->requests === [], 'The kept check is read without the network.');

        Kit::check($updates->install('1.4.0', 'user:admin_identity:1')['reason'] === 'update_offer_unknown', 'Only the offered version can be installed.');
        Kit::check($updates->install('1.3.0', '')['reason'] === 'update_actor_required', 'Without a person nothing is queued.');
        $queued = $updates->install('1.3.0', 'user:admin_identity:1');
        Kit::check($queued['done'] && $queued['reason'] === 'update_queued' && $queued['job'] === 'job-1', 'The install goes to the queue.');
        Kit::check(count($dispatched) === 1 && $dispatched[0][0] === '1.3.0' && $dispatched[0][1] === 'user:admin_identity:1' && str_starts_with($dispatched[0][2], 'update:1.3.0:'), 'The job carries the version, the person and a key.');
        Kit::check($updates->overview()['queued']['version'] === '1.3.0', 'The page says it is queued.');
        Kit::check($updates->install('1.3.0', 'user:admin_identity:1')['reason'] === 'update_already_running' && count($dispatched) === 1, 'A second install is not queued.');
        $updates->finished();
        Kit::check($updates->overview()['queued'] === null, 'The job end clears the mark.');

        // Without a queue the page names the command; the web request never installs.
        $noQueue = new SiteUpdates($client, $check);
        Kit::check($noQueue->install('1.3.0', 'user:admin_identity:1')['reason'] === 'update_queue_unavailable' && !$noQueue->overview()['queue'], 'No queue: no install from the page.');

        // Up to date, and a failure kept with its reason.
        [$same, $sameSig] = Kit::release('1.2.0', $secret);
        $http2 = FakeUpdateHttpClient::serving(Kit::server($server, $same, $sameSig, $secret));
        $current = new SiteUpdates(static fn (): SiteUpdateClient => new SiteUpdateClient(
            ['trusted_keys' => ['release.test' => $public], 'site_root' => $root, 'sources' => [$server]],
            $root . '/releases/1.2.0', 'https://site.example.test', static fn (): PDO => new PDO('sqlite:' . $database), new MemoryLogger(), $http2,
            new FakeArtisanRunner(static fn (): string => '')), $root . '/storage/current.json');
        Kit::check($current->check()['reason'] === 'update_up_to_date' && $current->overview()['check']['status'] === 'up_to_date', 'Up to date is said and kept.');
        $down = new SiteUpdates(static fn (): SiteUpdateClient => new SiteUpdateClient(
            ['trusted_keys' => ['release.test' => $public], 'site_root' => $root, 'sources' => [$server]],
            $root . '/releases/1.2.0', 'https://site.example.test', static fn (): PDO => new PDO('sqlite:' . $database), new MemoryLogger(),
            FakeUpdateHttpClient::serving([]), new FakeArtisanRunner(static fn (): string => '')), $root . '/storage/down.json');
        $failed = $down->check();
        Kit::check(!$failed['done'] && preg_match('/\Aupdate_[a-z_]+\z/', $failed['reason']) === 1 && $down->overview()['check']['status'] === 'failed', 'A failed check keeps its reason code: ' . $failed['reason']);

        // A store without its table: the journal says why.
        $empty = new SiteUpdates(static fn (): SiteUpdateClient => new SiteUpdateClient(
            ['trusted_keys' => ['release.test' => $public], 'site_root' => $root, 'sources' => [$server]],
            $root . '/releases/1.2.0', 'https://site.example.test', static fn (): PDO => new PDO('sqlite::memory:'), new MemoryLogger(), $http,
            new FakeArtisanRunner(static fn (): string => '')), $root . '/storage/x.json');
        Kit::check($empty->overview()['store_reason'] === 'update_store_unavailable', 'A missing update table is said.');

        // The queue job runs ReleaseInstall and clears the mark whatever happens; a failed install is a failed job, not retried.
        $cleared = 0;
        $job = new ReleaseInstallJobHandler(static fn (): ReleaseInstall => new ReleaseInstall($client()), static function () use (&$cleared): void {
            ++$cleared;
        });
        $context = (new ReflectionClass(\Larena\Queue\Data\QueueExecutionContext::class))->newInstanceWithoutConstructor();
        $result = $job->handle($context, ['version' => '1.3.0', 'actor' => 'user:admin_identity:1', 'idempotency_key' => 'bad key']);
        Kit::check($cleared === 1 && !$result->succeeded && !$result->retryable, 'A refused install is a failed job without a retry.');

        // The queue accepts the install job's description (one attempt, an hour, its lease within its timeout).
        $descriptor = \Larena\UpdateClient\Laravel\UpdateClientServiceProvider::installJobDescriptor();
        Kit::check($descriptor->jobType() === SiteUpdates::JOB_TYPE && $descriptor->maxAttempts() === 1, 'The install job is described for the queue.');

        // Words for every reason and state of the page, in both languages.
        foreach (['ru', 'en'] as $locale) {
            $words = require dirname(__DIR__, 2) . '/resources/lang/' . $locale . '/updates.php';
            foreach (['update_up_to_date', 'update_available', 'update_queued', 'update_offer_unknown', 'update_already_running', 'update_queue_unavailable', 'update_actor_required', 'other'] as $reason) {
                Kit::check(is_string($words['reasons'][$reason] ?? null), $locale . ' words for ' . $reason);
            }
            foreach (['completed', 'rolled_back', 'recoverable_failed', 'manual_recovery_required', 'blocked', 'running'] as $state) {
                Kit::check(is_string($words['states'][$state] ?? null), $locale . ' words for state ' . $state);
            }
        }
    } finally {
        Kit::remove($root);
    }
})();
