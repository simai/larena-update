<?php

declare(strict_types=1);

require_once __DIR__ . '/../Support/IntegritySiteKit.php';

use Larena\UpdateClient\Integrity\CoreCheck;
use Larena\UpdateClient\Integrity\FindingStore;
use Larena\UpdateClient\Integrity\IntegrityLayout;
use Larena\UpdateClient\Integrity\IntegrityRefused;
use Larena\UpdateClient\Integrity\IntegrityScanner;
use Larena\UpdateClient\Tests\Support\IntegritySiteKit as Site;
use Larena\UpdateClient\Tests\Support\UpdateClientTestKit as Kit;

// Parts A2–A4 of the owner decision 2026-11-20: the check on every request, the full check in portions, the findings,
// «Вернуть как было» with the quarantine, and the honest «список файлов нет».
(static function (): void {
    // --- A2: the main files on every request.
    $site = Site::make('a2');
    try {
        $check = $site->guard->check();
        Kit::check($check->status === CoreCheck::OK && $check->files === 5 && $check->problems === [], 'a clean release passes the request check');
        Kit::check($site->guard->check()->transitioned === false, 'a second clean request is not a change');
        $kept = (string) file_get_contents($site->layout->file('core.json'));
        $site->guard->check();
        Kit::check((string) file_get_contents($site->layout->file('core.json')) === $kept, 'an unchanged request writes nothing');

        file_put_contents($site->release . '/public/index.php', "<?php eval(\$_GET['x']);\n");
        $check = $site->guard->check();
        Kit::check($check->status === CoreCheck::CHANGED && ($check->problems['public/index.php'] ?? null) === 'size_differs' && $check->transitioned,
            'a changed entry point is seen on the next request');
        Kit::check($site->guard->check()->status === CoreCheck::CHANGED && !$site->guard->check()->transitioned, 'and stays seen without a new change');

        // Same size, other bytes, modification time set back: the change time still moves, the hash catches it.
        $original = Site::FILES['config/app.php'];
        $mtime = filemtime($site->release . '/config/app.php');
        file_put_contents($site->release . '/config/app.php', strtoupper($original[0]) . substr($original, 1) === $original ? str_replace('Larena', 'Lorena', $original) : $original);
        touch($site->release . '/config/app.php', (int) $mtime);
        $check = $site->guard->check();
        Kit::check(($check->problems['config/app.php'] ?? null) === 'checksum_differs', 'same size and time still differs by sha256');

        unlink($site->release . '/vendor/autoload.php');
        Kit::check(($site->guard->check()->problems['vendor/autoload.php'] ?? null) === 'missing', 'a deleted autoloader is seen');

        // A changed signed list is verified again; a list that no key signed is never trusted.
        file_put_contents($site->release . '/.larena-signed/files.json', str_replace('"core_files"', '"core_files" ', (string) file_get_contents($site->release . '/.larena-signed/files.json')));
        $check = $site->guard->check();
        Kit::check($check->status === CoreCheck::UNKNOWN && $check->reason === 'integrity_list_untrusted', 'an edited list is not trusted');
    } finally {
        $site->cleanup();
    }

    // --- No list: «список файлов нет», never success.
    $bare = Site::make('nolist', false);
    try {
        $check = $bare->guard->check();
        Kit::check($check->status === CoreCheck::NO_LIST && $check->reason === 'integrity_list_missing' && !$check->ok(), 'no list is not a pass');
        $run = $bare->scan();
        Kit::check($run['result'] === IntegrityScanner::UNKNOWN && $run['reason'] === 'integrity_list_missing', 'the full check says it could not check');
        $plan = $bare->restore->plan();
        Kit::check($plan->blockers === ['integrity_list_missing'], 'nothing can be restored without a list');
        Kit::check(\Larena\UpdateClient\Laravel\Integrity\IntegrityAdminController::blockerLevel('integrity_list_missing') === 'warning'
            && \Larena\UpdateClient\Laravel\Integrity\IntegrityAdminController::blockerLevel('integrity_archive_damaged') === 'danger',
            'no list is one warning on the check page and on the plan');
        Kit::check(!$bare->layout->sourceCheckout(), 'a release-layout install without a list is no source checkout: it stays a warning');

        // --- An extra `.git` turns nothing off: without the explicit sign it is still «no list», a warning.
        mkdir($bare->release . '/.git');
        Kit::check(!$bare->layout->sourceCheckout() && $bare->guard->check()->status === CoreCheck::NO_LIST
            && $bare->restore->plan()->blockers === ['integrity_list_missing'], 'a .git folder does not make a site «from source»');
        rmdir($bare->release . '/.git');

        // --- A developer checkout is marked explicitly (LARENA_SOURCE_INSTALL=1): information.
        $bare->sourceInstall = true;
        $bare->wire();
        Kit::check($bare->layout->sourceCheckout(), 'the explicit sign without a signed folder is from source');
        $check = $bare->guard->check();
        Kit::check($check->status === CoreCheck::SOURCE && $check->reason === 'integrity_source_checkout' && !$check->ok(),
            'the kept «no list» answer gives way to «from source»: ' . $check->status);
        Kit::check($bare->guard->check()->status === CoreCheck::SOURCE, 'and stays');
        $run = $bare->scan();
        Kit::check($run['result'] === IntegrityScanner::SOURCE && $run['reason'] === 'integrity_source_checkout', 'the full check says it is not kept');
        Kit::check($bare->restore->plan()->blockers === ['integrity_source_checkout']
            && \Larena\UpdateClient\Laravel\Integrity\IntegrityAdminController::blockerLevel('integrity_source_checkout') === 'info', 'the plan says it as information');
        // A signed folder present (even a broken one) is a release, never «from source», whatever the sign says.
        mkdir($bare->release . '/.larena-signed');
        Kit::check(!$bare->layout->sourceCheckout() && $bare->guard->check()->status === CoreCheck::NO_LIST, 'a signed folder without its files is no list');
        rmdir($bare->release . '/.larena-signed');
    } finally {
        $bare->cleanup();
    }

    // The explicit sign: LARENA_SOURCE_INSTALL true; nothing else (APP_ENV=local is written by every installer today).
    Kit::check(IntegrityLayout::sourceInstallMarked('1') && IntegrityLayout::sourceInstallMarked(true)
        && IntegrityLayout::sourceInstallMarked('true') && IntegrityLayout::sourceInstallMarked(1), 'LARENA_SOURCE_INSTALL=1 marks a source install');
    Kit::check(!IntegrityLayout::sourceInstallMarked(null) && !IntegrityLayout::sourceInstallMarked('0')
        && !IntegrityLayout::sourceInstallMarked('') && !IntegrityLayout::sourceInstallMarked(false)
        && !IntegrityLayout::sourceInstallMarked('no') && !IntegrityLayout::sourceInstallMarked('local'), 'without the sign a site is never from source');

    // A base path outside a release layout (`<site>/releases/<release>`) and without the sign is not from source.
    $plain = Kit::temp('plain-checkout');
    try {
        $layout = new IntegrityLayout($plain, $plain . '/storage/app/larena/integrity');
        Kit::check(!$layout->sourceCheckout(), 'no release layout alone is not from source');
        mkdir($plain . '/.git');
        Kit::check(!$layout->sourceCheckout(), 'nor with .git');
        Kit::check((new IntegrityLayout($plain, $plain . '/storage/app/larena/integrity', [], true))->sourceCheckout(), 'only with the sign');
    } finally {
        Kit::remove($plain);
    }

    // --- Any hosting: the archive unpacked into a plain folder (no releases/, no current) and sealed is checked.
    $hosted = Site::plain('plain-hosting');
    try {
        Kit::check(!str_contains($hosted->release, '/releases/') && is_file($hosted->release . '/.larena-signed/files.json'), 'the plain folder keeps the signed list');
        Kit::check($hosted->guard->check()->status === CoreCheck::OK, 'a sealed plain-folder install passes the request check');
        $run = $hosted->scan();
        Kit::check($run['result'] === IntegrityScanner::UNCHANGED, 'and the full check: ' . (string) $run['result']);
        mkdir($hosted->release . '/.git');
        file_put_contents($hosted->release . '/public/index.php', "<?php eval(\$_GET['x']);\n");
        file_put_contents($hosted->release . '/public/shell.php', "<?php system(\$_GET['c']);\n");
        $check = $hosted->guard->check();
        Kit::check($check->status === CoreCheck::CHANGED && ($check->problems['public/index.php'] ?? null) === 'size_differs',
            'a changed file on plain hosting is seen on the next request, .git or not');
        $run = $hosted->scan();
        $paths = array_map(static fn (array $finding): string => (string) $finding['path'], $hosted->findings->open());
        Kit::check($run['result'] === IntegrityScanner::CHANGES_FOUND && in_array('public/index.php', $paths, true) && in_array('public/shell.php', $paths, true),
            'the full check lists the changed and the new file: ' . implode(', ', $paths));
        // Even the explicit sign does not turn off a site that carries its signed list.
        $hosted->sourceInstall = true;
        $hosted->wire();
        Kit::check($hosted->guard->check()->status === CoreCheck::CHANGED, 'a signed list is checked whatever the sign');
    } finally {
        $hosted->cleanup();
    }
    // The same plain folder without a list and without the sign: the warning «списка файлов нет».
    $unsealed = Site::plain('plain-unsealed', false);
    try {
        mkdir($unsealed->release . '/.git');
        Kit::check($unsealed->guard->check()->status === CoreCheck::NO_LIST, 'a plain folder without a list is a warning, never «from source»');
    } finally {
        $unsealed->cleanup();
    }

    // --- A3 and A4 on one site: the four negative checks, the findings, the restore and the quarantine.
    $site = Site::make('a3');
    try {
        $run = $site->scan();
        Kit::check($run['result'] === IntegrityScanner::UNCHANGED && $run['counts']['errors'] === 0 && $run['portions'] > 2,
            'a clean site is «без изменений» after a check in several portions');
        Kit::check($run['counts']['hashed'] === 9, 'the first check hashes every release file and the archive once');
        // A fingerprint counts only once it is two seconds old (times are whole seconds): wait, check, check again.
        sleep(3);
        $site->scan(1000);
        $again = $site->scan(1000);
        Kit::check($again['result'] === IntegrityScanner::UNCHANGED && $again['counts']['hashed'] === 0, 'an unchanged file is not hashed again');
        $site->guard->forget();
        Kit::check($site->guard->check()->ok() && is_file($site->layout->file('core.quick')), 'a settled clean release keeps the short answer');
        Kit::check($site->guard->check()->ok(), 'the next request is answered from the short answer');

        // A check that has not reached its end is not «без изменений».
        $site->scanner->start('test', true);
        $state = $site->scanner->step(1, 5.0);
        Kit::check($state['current']['state'] === 'running' && $state['current']['result'] === null, 'a part of a check is no result');

        // 1. a changed main file, 2. an extra PHP file in the uploads, 3. a replaced .htaccess, plus PHP in a cache.
        file_put_contents($site->release . '/public/index.php', "<?php system(\$_GET['c']);\n");
        file_put_contents($site->storage . '/app/public/shell.php', "<?php system(\$_GET['c']);\n");
        file_put_contents($site->storage . '/app/public/cat.php.jpg', "<?php echo 1;\n");
        file_put_contents($site->storage . '/framework/cache/x.php', "<?php echo 1;\n");
        file_put_contents($site->release . '/public/.htaccess', "AddHandler application/x-httpd-php .jpg\n");
        file_put_contents($site->release . '/public/wp-login.php', "<?php echo 1;\n");
        file_put_contents($site->storage . '/app/public/.htaccess', "Options +ExecCGI\n");
        unlink($site->release . '/public/robots.txt');
        // Laravel's own generated files are no finding.
        mkdir($site->release . '/bootstrap/cache');
        file_put_contents($site->release . '/bootstrap/cache/packages.php', "<?php return [];\n");
        file_put_contents($site->storage . '/framework/views/' . sha1('other') . '.php', "<?php echo 2;\n");
        // Laravel names compiled views with xxh128: 32 hex characters.
        file_put_contents($site->storage . '/framework/views/' . hash('xxh128', 'other') . '.php', "<?php echo 3;\n");

        $quick = $site->guard->check();
        Kit::check($quick->status === CoreCheck::CHANGED && isset($quick->problems['public/index.php'], $quick->problems['public/.htaccess'])
            && !is_file($site->layout->file('core.quick')), 'a change behind the short answer is seen on the next request');

        $run = $site->scan();
        $kinds = [];
        foreach ($run['found'] as $finding) {
            $kinds[$finding['kind'] . ' ' . ($finding['root'] === 'release' ? '' : $finding['root'] . '/') . $finding['path']] = true;
        }
        ksort($kinds);
        $expected = [
            'changed public/index.php', 'deleted public/robots.txt', 'htaccess_replaced public/.htaccess',
            'new_htaccess storage/app/public/.htaccess', 'new_php public/wp-login.php', 'new_php storage/app/public/cat.php.jpg',
            'new_php storage/app/public/shell.php', 'new_php storage/framework/cache/x.php',
        ];
        Kit::check(array_keys($kinds) === $expected, 'the check finds exactly: ' . implode(', ', array_keys($kinds)));
        Kit::check($run['result'] === IntegrityScanner::CHANGES_FOUND, 'and says «найдены изменения»');

        // Stable keys: a repeat updates the finding, it does not add one.
        $first = $site->findings->all();
        $site->scan();
        $second = $site->findings->all();
        Kit::check(count($first) === count($second) && count($second) === 8, 'a repeat keeps the same findings');
        $shell = array_values(array_filter($second, static fn (array $f): bool => $f['path'] === 'public/shell.php'))[0];
        Kit::check($shell['occurrence_count'] === 2 && $shell['state'] === FindingStore::OPEN && $shell['first_seen'] <= $shell['last_seen'], 'a repeat counts the occurrence');

        // An exception has a scope and an end; past its end the finding is open again.
        $now = new DateTimeImmutable();
        Kit::check(Kit::reason(static fn () => $site->findings->addException('storage/framework/cache/*', $now->modify('-1 day'), 'old', 'user:1', $now)) === 'integrity_exception_until_invalid', 'an exception ends in the future');
        Kit::check(Kit::reason(static fn () => $site->findings->addException('../etc', $now->modify('+1 day'), 'x', 'user:1', $now)) === 'integrity_exception_scope_invalid', 'a scope stays inside the site');
        $site->findings->addException('storage/framework/cache/*', $now->modify('+7 days'), 'the cache plugin writes this', 'user:1', $now);
        $cache = array_values(array_filter($site->findings->all(), static fn (array $f): bool => $f['path'] === 'x.php'))[0];
        Kit::check($cache['state'] === FindingStore::EXCEPTION && $cache['exception']['reason'] === 'the cache plugin writes this', 'the exception covers its scope');
        $later = array_values(array_filter($site->findings->all($now->modify('+8 days')), static fn (array $f): bool => $f['path'] === 'x.php'))[0];
        Kit::check($later['state'] === FindingStore::OPEN, 'an exception past its end opens the finding again');
        @unlink($site->storage . '/framework/cache/x.php');

        // 4. a damaged release archive: found by the check, and «Вернуть» refuses to take files from it.
        $archive = $site->release . '/.larena-signed/larena-1.3.0.tar.gz';
        $good = (string) file_get_contents($archive);
        file_put_contents($archive, substr($good, 0, -10) . 'xxxxxxxxxx');
        $run = $site->scan();
        Kit::check(in_array('archive_damaged', array_column($run['found'], 'kind'), true), 'a damaged archive is found');
        $plan = $site->restore->plan();
        Kit::check($plan->blockers === ['integrity_archive_damaged'], 'and stops the restore');
        Kit::check(Kit::reason(static fn () => $site->restore->run($plan->digest(), 'user:1')) === 'integrity_archive_damaged', 'a confirmed run is refused too');
        file_put_contents($archive, $good);

        // The plan: clean files from the archive, extra PHP into the quarantine; a changed file since the plan is refused.
        $site->scan();
        $plan = $site->restore->plan();
        Kit::check(array_column($plan->restore, 'path') === ['public/.htaccess', 'public/index.php', 'public/robots.txt'], 'the plan writes the three release files again');
        Kit::check(count($plan->quarantine) === 4 && !$plan->blocked(), 'and moves the four extra files away');
        file_put_contents($site->storage . '/app/public/shell.php', "<?php system(\$_GET['d']);\n");
        Kit::check(Kit::reason(static fn () => $site->restore->run($plan->digest(), 'user:1')) === 'integrity_plan_changed', 'a file changed after the plan stops the run');

        $plan = $site->restore->plan();
        $outcome = $site->restore->run($plan->digest(), 'user:1');
        Kit::check($outcome['restored'] === 3 && $outcome['quarantined'] === 4, 'the restore did what the plan said');
        Kit::check($outcome['result'] === IntegrityScanner::UNCHANGED, 'the check after the restore is «без изменений»: ' . (string) $outcome['result']);
        Kit::check((string) file_get_contents($site->release . '/public/index.php') === Site::FILES['public/index.php'], 'the entry point is clean again');
        Kit::check(!file_exists($site->storage . '/app/public/shell.php') && is_file($site->storage . '/app/public/photo.jpg'), 'the shell is gone, the photo stays');
        Kit::check($site->guard->check()->ok(), 'and the request check passes');
        Kit::check(in_array('update.integrity.restore.succeeded', $site->history->types(), true)
            && in_array('update.integrity.check.completed', $site->history->types(), true), 'the restore and its check are in the history');
        Kit::check(in_array('update.integrity.restore.refused', $site->history->types(), true), 'so is the refused one');

        // The quarantine keeps the files outside the web root, by an id, and gives them back.
        $items = $site->restore->quarantine();
        Kit::check(count($items) === 4, 'four files wait in the quarantine');
        foreach ($items as $item) {
            Kit::check(is_file($site->layout->quarantineDirectory() . '/' . $item['id'] . '.quarantined'), 'each one is kept under its id');
        }
        $photo = array_values(array_filter($items, static fn (array $i): bool => $i['path'] === 'public/cat.php.jpg'))[0];
        $digest = (string) $site->restore->returnDigest($photo['id']);
        $site->restore->returnItem($photo['id'], $digest, 'user:1');
        Kit::check(is_file($site->storage . '/app/public/cat.php.jpg') && count($site->restore->quarantine()) === 3, 'a file comes back from the quarantine');
        Kit::check(Kit::reason(static fn () => $site->restore->returnItem($photo['id'], $digest, 'user:1')) === 'integrity_quarantine_unknown', 'once');
        Kit::check(in_array('update.integrity.quarantine.returned', $site->history->types(), true), 'the return is in the history');
        $run = $site->scan();
        Kit::check($run['result'] === IntegrityScanner::CHANGES_FOUND, 'a file brought back is found again until it is accepted');
        $site->findings->addException('storage/app/public/cat.php.jpg', $now->modify('+30 days'), 'a real picture', 'user:1', $now);
        Kit::check($site->scan()['result'] === IntegrityScanner::UNCHANGED, 'an accepted change is no open finding');

        // A folder that cannot be read gives «не удалось проверить», never «без изменений».
        mkdir($site->storage . '/app/locked');
        chmod($site->storage . '/app/locked', 0000);
        if (!is_readable($site->storage . '/app/locked')) {
            $run = $site->scan();
            Kit::check($run['result'] === IntegrityScanner::UNKNOWN && $run['reason'] === 'integrity_read_failed', 'an unreadable folder is not a pass');
        }
        chmod($site->storage . '/app/locked', 0755);
    } finally {
        $site->cleanup();
    }

    // --- The archive is kept with every release the update client unpacks.
    $site = Site::make('keep');
    try {
        $kept = $site->release . '/.larena-signed/larena-1.3.0.tar.gz';
        Kit::check(is_file($kept) && hash_equals(hash('sha256', $site->signed[3]), (string) hash_file('sha256', $kept)), 'the release archive is kept beside the signed list');
        Kit::check(Kit::reason(static fn () => $site->restore->run(str_repeat('0', 64), 'user:1')) === 'integrity_plan_changed', 'a made-up plan is refused');
        try {
            $site->restore->run($site->restore->plan()->digest(), 'user:1');
            throw new RuntimeException('an empty plan must not run');
        } catch (IntegrityRefused $refused) {
            Kit::check($refused->reason === 'integrity_nothing_to_restore', 'nothing to restore says so');
        }
    } finally {
        $site->cleanup();
    }
})();
