<?php

declare(strict_types=1);

require_once __DIR__ . '/../Support/IntegritySiteKit.php';

use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use Larena\UpdateClient\Integrity\CoreCheck;
use Larena\UpdateClient\Integrity\IntegrityLayout;
use Larena\UpdateClient\Integrity\RecordingIntegrityHistory;
use Larena\UpdateClient\Integrity\ReleaseSealer;
use Larena\UpdateClient\Laravel\Integrity\CheckCoreFiles;
use Larena\UpdateClient\Laravel\Integrity\IntegrityAdminAlertSource;
use Larena\UpdateClient\Laravel\Integrity\IntegrityPlanReceipts;
use Larena\UpdateClient\Laravel\Integrity\SiteIntegrity;
use Larena\UpdateClient\Tests\Support\IntegritySiteKit as Site;
use Larena\UpdateClient\Tests\Support\UpdateClientTestKit as Kit;
use Symfony\Component\HttpFoundation\Response;

// The Laravel side of the integrity checks: the installer's seal of a fresh install, the site's checks put together,
// the request middleware that never stops a page, the alert bar, the plan receipts.
(static function (): void {
    // --- The installer's seal: a fresh install gets its signed folder only after its files match the list.
    $site = Site::make('seal', false);
    try {
        $from = $site->site . '/download';
        mkdir($from);
        [$release, $signature, $list, $archive] = $site->signed;
        file_put_contents($from . '/release.json', $release);
        file_put_contents($from . '/release.json.sig', $signature);
        file_put_contents($from . '/files.json', $list);
        file_put_contents($from . '/larena-1.3.0.tar.gz', $archive);
        Kit::check(Kit::reason(static fn () => (new ReleaseSealer([]))->seal($site->release, $from)) === 'release_trusted_keys_missing', 'no key, no seal');
        $other = base64_encode(sodium_crypto_sign_publickey(sodium_crypto_sign_keypair()));
        Kit::check(Kit::reason(static fn () => (new ReleaseSealer(['release.test' => $other]))->seal($site->release, $from)) === 'release_signature_mismatch', 'another key, no seal');
        $sealer = new ReleaseSealer(['release.test' => $site->publicKey]);

        file_put_contents($site->release . '/public/index.php', "<?php // edited before the seal\n");
        Kit::check(Kit::reason(static fn () => $sealer->seal($site->release, $from)) === 'release_seal_files_differ', 'a changed install is not sealed');
        Kit::check(!file_exists($site->release . '/.larena-signed'), 'and gets no signed folder');
        file_put_contents($site->release . '/public/index.php', Site::FILES['public/index.php']);

        file_put_contents($from . '/larena-1.3.0.tar.gz', $archive . 'x');
        Kit::check(Kit::reason(static fn () => $sealer->seal($site->release, $from)) === 'release_archive_size_differs', 'a damaged archive is not kept');
        file_put_contents($from . '/larena-1.3.0.tar.gz', $archive);

        $result = $sealer->seal($site->release, $from);
        Kit::check($result === ['sealed' => true, 'reason' => null, 'version' => '1.3.0', 'files' => 13], 'a clean install is sealed: ' . json_encode($result));
        foreach (['release.json', 'release.json.sig', 'files.json', 'larena-1.3.0.tar.gz'] as $name) {
            Kit::check(is_file($site->release . '/.larena-signed/' . $name), 'the seal keeps ' . $name);
        }
        Kit::check($sealer->seal($site->release, $from)['sealed'] === true, 'sealing again is the same seal');
        Kit::check($site->guard->check()->status === CoreCheck::OK, 'the sealed install passes the request check');
        Kit::check($site->scan()['result'] === 'unchanged', 'and the full check');
    } finally {
        $site->cleanup();
    }

    // --- The site's checks put together, the middleware and the alert bar.
    $site = Site::make('laravel');
    try {
        $history = new RecordingIntegrityHistory();
        $integrity = new SiteIntegrity($site->layout, ['release.test' => $site->publicKey], [], $history);
        $loader = new ArrayLoader();
        $loader->addMessages('ru', 'integrity', require __DIR__ . '/../../resources/lang/ru/integrity.php', 'larena-update-client');
        $translator = new Translator($loader, 'ru');
        $routes = static fn (string $name, array $parameters = []): string => '/admin/integrity' . ($name === 'larena.update-client.integrity.restore' ? '/restore' : '');
        $allowed = true;
        $source = new IntegrityAdminAlertSource($integrity, $translator, static function (Request $request, string $operation) use (&$allowed): bool {
            return $allowed && $operation === 'update.integrity.read';
        }, $routes);

        $request = Request::create('/admin');
        $response = (new CheckCoreFiles($integrity))->handle($request, static fn (): Response => new Response('page'));
        Kit::check($response->getContent() === 'page' && $request->attributes->get(CheckCoreFiles::ATTRIBUTE)?->status === CoreCheck::OK, 'a clean request passes through');
        Kit::check($source->alerts($request) === [], 'a clean site has nothing in the bar');

        file_put_contents($site->release . '/public/index.php', "<?php // bot\n");
        $integrity->forgetRequestCheck();
        $request = Request::create('/admin');
        $response = (new CheckCoreFiles($integrity))->handle($request, static fn (): Response => new Response('admin still opens'));
        Kit::check($response->getContent() === 'admin still opens', 'a changed main file never stops the page');
        Kit::check($history->types() === ['update.integrity.core.changed'], 'the first request that sees it writes it to the history');
        $alerts = $source->alerts($request);
        Kit::check(count($alerts) === 1 && $alerts[0]->id === 'update-client:integrity:core' && $alerts[0]->level->value === 'danger'
            && $alerts[0]->action?->href === '/admin/integrity/restore' && str_contains($alerts[0]->text, 'Изменены главные файлы сайта: 1'), 'the bar says it in red with «Вернуть как было»');
        $allowed = false;
        Kit::check($source->alerts($request) === [], 'a person who may not see the update centre sees nothing');
        $allowed = true;

        $run = $integrity->scanFor(10.0, 'manual', 'user:1', true);
        Kit::check($run['result'] === 'changes_found' && in_array('update.integrity.check.completed', $history->types(), true), 'a manual check ends and is in the history');
        $ids = array_map(static fn ($alert): string => $alert->id, $source->alerts($request));
        Kit::check($ids === ['update-client:integrity:core', 'update-client:integrity:findings'], 'the findings show too: ' . implode(',', $ids));

        // A middleware that cannot check still lets the page through.
        $broken = new SiteIntegrity(new IntegrityLayout($site->release, '/dev/null/state', []), [], [], $history);
        $response = (new CheckCoreFiles($broken))->handle(Request::create('/'), static fn (): Response => new Response('ok'));
        Kit::check($response->getContent() === 'ok', 'a check without its state folder never stops a page');

        // No list: a warning, never silence.
        $bare = Site::make('laravel-bare', false);
        try {
            $bareIntegrity = new SiteIntegrity($bare->layout, ['release.test' => $bare->publicKey], [], new RecordingIntegrityHistory());
            $alerts = (new IntegrityAdminAlertSource($bareIntegrity, $translator, static fn (): bool => true, $routes))->alerts(Request::create('/admin'));
            Kit::check(count($alerts) === 1 && $alerts[0]->id === 'update-client:integrity:no-list' && $alerts[0]->level->value === 'warning'
                && str_contains($alerts[0]->text, 'списка файлов нет'), 'no list is said in the bar');
            // An extra .git is no sign: the bar still says the list is missing.
            mkdir($bare->release . '/.git');
            $gitIntegrity = new SiteIntegrity($bare->layout, ['release.test' => $bare->publicKey], [], new RecordingIntegrityHistory());
            $alerts = (new IntegrityAdminAlertSource($gitIntegrity, $translator, static fn (): bool => true, $routes))->alerts(Request::create('/admin'));
            Kit::check(count($alerts) === 1 && $alerts[0]->id === 'update-client:integrity:no-list', 'a .git folder does not hide the missing list');
            // A developer checkout marked explicitly (LARENA_SOURCE_INSTALL=1): information, hidden
            // once and for good, not a warning that hangs forever.
            $bare->sourceInstall = true;
            $bare->wire();
            $sourceIntegrity = new SiteIntegrity($bare->layout, ['release.test' => $bare->publicKey], [], new RecordingIntegrityHistory());
            $alerts = (new IntegrityAdminAlertSource($sourceIntegrity, $translator, static fn (): bool => true, $routes))->alerts(Request::create('/admin'));
            Kit::check(count($alerts) === 1 && $alerts[0]->id === 'update-client:integrity:source' && $alerts[0]->level->value === 'info'
                && $alerts[0]->level->hideable() && $alerts[0]->level->hiddenFor() === null
                && $alerts[0]->text === 'Сайт установлен из исходного кода — проверка файлов не ведётся.', 'a checkout is said as information');
        } finally {
            $bare->cleanup();
        }
    } finally {
        $site->cleanup();
    }

    // --- Plan receipts: this person's, this action's, once.
    $receipts = new IntegrityPlanReceipts();
    $session = new Store('test', new ArraySessionHandler(10));
    $request = Request::create('/admin/integrity/restore', 'POST');
    $request->setLaravelSession($session);
    $request->attributes->set('larena_access_actor', 'user:1');
    $id = $receipts->issue($request, 'update.integrity.restore', str_repeat('a', 64));
    $request->merge([IntegrityPlanReceipts::FIELD => $id]);
    $other = Request::create('/admin/integrity/restore', 'POST', [IntegrityPlanReceipts::FIELD => $id]);
    $other->setLaravelSession($session);
    $other->attributes->set('larena_access_actor', 'user:2');
    Kit::check($receipts->take($other, 'update.integrity.restore') === null, 'another person cannot use the receipt');
    $id = $receipts->issue($request, 'update.integrity.restore', str_repeat('b', 64));
    $request->merge([IntegrityPlanReceipts::FIELD => $id]);
    Kit::check($receipts->take($request, 'update.integrity.quarantine.return:x') === null, 'nor another action');
    $id = $receipts->issue($request, 'update.integrity.restore', str_repeat('c', 64));
    $request->merge([IntegrityPlanReceipts::FIELD => $id]);
    Kit::check($receipts->take($request, 'update.integrity.restore') === str_repeat('c', 64), 'the receipt gives back the plan digest');
    Kit::check($receipts->take($request, 'update.integrity.restore') === null, 'once');
})();
