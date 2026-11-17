<?php

declare(strict_types=1);

require_once __DIR__ . '/../Support/UpdateClientFakes.php';

use Larena\UpdateClient\Enums\UpdateCycleState;
use Larena\UpdateClient\Laravel\ActivationTokenStore;
use Larena\UpdateClient\Laravel\Http\OpcacheResetController;
use Larena\UpdateClient\Laravel\OpcacheResetActivation;
use Larena\UpdateClient\Laravel\SiteUpdateClient;
use Larena\UpdateClient\Release\ReleaseArchiveExtractor;
use Larena\UpdateClient\Release\ReleasePointer;
use Larena\UpdateClient\Release\ReleaseSharedLinks;
use Larena\UpdateClient\Release\ReleaseSignatureVerifier;
use Larena\UpdateClient\Runtime\PdoUpdateExecutionStore;
use Larena\UpdateClient\Source\UpdateHttpRequest;
use Larena\UpdateClient\Tests\Support\FakeArtisanRunner;
use Larena\UpdateClient\Tests\Support\FakeUpdateHttpClient;
use Larena\UpdateClient\Tests\Support\MemoryLogger;
use Larena\UpdateClient\Tests\Support\PretendSite;
use Larena\UpdateClient\Tests\Support\UpdateClientTestKit as Kit;
use Illuminate\Http\Request;

// The whole Laravel composition: download from a source, the cycle with the real seams, a rollback on a failed
// health check and the manual rollback. Outside effects are fakes: an artisan runner that keeps a pretend database
// and maintenance flag, and an HTTP client that is both the update source and the site.
(static function (): void {
    $pair = sodium_crypto_sign_keypair();
    $secret = sodium_crypto_sign_secretkey($pair);
    $public = base64_encode(sodium_crypto_sign_publickey($pair));
    $verifier = new ReleaseSignatureVerifier(['release.test' => $public]);
    $source = 'https://github.com/example/larena/releases';
    $root = Kit::temp('laravel-cycle');
    $site = $root . '/site';
    mkdir($site . '/storage/app/public', 0755, true);
    file_put_contents($site . '/.env', "APP_KEY=test\n");
    $pointer = new ReleasePointer($site);
    $published = [];
    foreach (['1.1.0', '1.2.0', '1.3.0'] as $version) {
        [$bytes, $signature, $archive] = Kit::release($version, $secret);
        $published[$source . '/download/v' . $version . '/release.json'] = [200, $bytes];
        $published[$source . '/download/v' . $version . '/release.json.sig'] = [200, $signature];
        $published[$source . '/download/v' . $version . '/larena-' . $version . '.tar.gz'] = [200, $archive];
        if ($version !== '1.3.0') {
            file_put_contents($root . '/a.tar.gz', $archive);
            (new ReleaseArchiveExtractor())->extract($verifier->verify($bytes, $signature, '1.0.0'), $root . '/a.tar.gz', $site);
            ReleaseSharedLinks::link($site, $version);
            $pointer->switchTo($version);
        }
    }

    // The pretend site behind artisan: database schema version, maintenance flag, copies.
    $world = new PretendSite();
    $artisan = new FakeArtisanRunner(static function (string $release, array $arguments) use ($world): string {
        $version = basename($release);
        switch ($arguments[0]) {
            case 'down': $world->down = true; return '';
            case 'up': $world->down = false; return '';
            case 'migrate': $world->schema = $version; return '';
            case 'larena:access-sync-system-presets': case 'optimize:clear': case 'queue:restart': case 'larena:queue-work': return '';
            case 'larena:backup:run':
                $id = substr(hash('sha256', $arguments[1]), 0, 32);
                $world->copies[$id] = $world->schema;
                return (string) json_encode(['status' => 'passed', 'job_id' => $id]);
            case 'larena:backup:drill': return (string) json_encode(['status' => 'passed', 'job_id' => str_repeat('d', 32)]);
            case 'larena:backup:status': return (string) json_encode(['status' => 'passed', 'job_status' => 'completed']);
            case 'larena:backup:restore': $world->schema = $world->copies[$arguments[1]]; return (string) json_encode(['status' => 'passed']);
        }
        throw new RuntimeException('unexpected');
    });
    $tokens = new ActivationTokenStore($site . '/storage/app/larena/update');
    $controller = new OpcacheResetController($tokens);
    $http = new FakeUpdateHttpClient(static function (UpdateHttpRequest $request) use ($published, $controller, $world): array {
        if (str_starts_with($request->url, 'https://github.com/')) {
            return $published[$request->url] ?? [404, ''];
        }
        if ($request->method === 'POST') {
            $response = $controller(Request::create('/x', 'POST', server: ['HTTP_X_LARENA_UPDATE_TOKEN' => $request->headers[OpcacheResetActivation::HEADER] ?? '']));

            return [$response->getStatusCode(), (string) $response->getContent()];
        }

        return $world->failHealth && str_ends_with($request->url, '/up') ? [500, ''] : [200, 'ok'];
    });
    $pdo = new PDO('sqlite::memory:');
    $pdo->exec(PdoUpdateExecutionStore::schema('sqlite'));
    $client = new SiteUpdateClient(
        ['trusted_keys' => ['release.test' => $public], 'sources' => [$source]],
        $site . '/releases/1.2.0',
        'https://site.example.test',
        static fn (): PDO => $pdo,
        new MemoryLogger(),
        $http,
        $artisan,
    );

    try {
        // A failing health check: automatic rollback to 1.2.0 with the database from the copy.
        $world->failHealth = true;
        $download = $client->source()->download('1.3.0', $client->installedVersion());
        $failed = $client->cycle('user:1')->apply($client->request($download->release, 'user:1', 'update:1.3.0:first'), $download->releaseBytes, $download->signatureBytes, $download->archivePath);
        Kit::check($failed->state === UpdateCycleState::RecoverableFailed && $failed->failedStep === 'health', 'A failed health check must roll back.');
        Kit::check($pointer->current() === '1.2.0' && $world->schema === '1.2.0' && !$world->down, 'The rollback must restore code, database and leave maintenance.');

        // The same release again, healthy.
        $world->failHealth = (bool) random_int(0, 0);
        $artisan->calls = [];
        $done = $client->cycle('user:1')->apply($client->request($download->release, 'user:1', 'update:1.3.0:second'), $download->releaseBytes, $download->signatureBytes, $download->archivePath);
        Kit::check($done->state === UpdateCycleState::Completed && $pointer->current() === '1.3.0' && $world->schema === '1.3.0' && !$world->down, 'A healthy cycle must complete on 1.3.0.');
        Kit::check($artisan->journal() === [
            '1.2.0 larena:backup:run', '1.2.0 larena:backup:status', '1.2.0 larena:backup:drill', '1.2.0 larena:backup:status',
            '1.2.0 down', '1.3.0 migrate', '1.3.0 larena:access-sync-system-presets', '1.3.0 optimize:clear',
            '1.3.0 queue:restart', '1.3.0 up',
        ], 'Each command must run from the right release folder, in plan order.');
        Kit::check(!is_file($site . '/storage/app/larena/update/activation-token.json'), 'The activation token must be used up.');
        Kit::check($client->store()->latest(1)[0]['snapshot']->cycleId === $done->cycleId, 'The cycle must be kept in update_cycles.');

        // Manual rollback: back to 1.2.0 with the database from the cycle copy.
        $outcome = $client->cycle('user:1')->rollback($done->cycleId, $done->revision);
        Kit::check($outcome->snapshot->state === UpdateCycleState::RolledBack && $pointer->current() === '1.2.0' && $world->schema === '1.2.0', 'The manual rollback must return code and database.');
    } finally {
        Kit::remove($root);
    }
})();
