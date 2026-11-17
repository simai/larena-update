<?php

declare(strict_types=1);

use Larena\UpdateClient\Release\RecordingReleaseActivation;
use Larena\UpdateClient\Release\ReleaseArchiveExtractor;
use Larena\UpdateClient\Release\ReleaseCleanup;
use Larena\UpdateClient\Release\ReleaseDirectory;
use Larena\UpdateClient\Release\ReleasePointer;
use Larena\UpdateClient\Release\ReleaseSharedLinks;
use Larena\UpdateClient\Release\VerifiedRelease;

function layout_assert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

/** @param callable():mixed $call */
function layout_reason(callable $call): string
{
    try {
        $call();
    } catch (RuntimeException $exception) {
        return $exception->getMessage();
    }

    return 'accepted';
}

/** @param list<array{0:string,1?:string,2?:string,3?:int}> $entries name, type, data or link target, mode */
function layout_tar(array $entries): string
{
    $tar = '';
    foreach ($entries as $entry) {
        [$name, $type, $data, $mode] = $entry + [1 => '0', 2 => '', 3 => 0644];
        $isFile = $type === '0';
        $header = str_pad($name, 100, "\0")
            . sprintf('%07o', $mode) . "\0" . sprintf('%07o', 0) . "\0" . sprintf('%07o', 0) . "\0"
            . sprintf('%011o', $isFile ? strlen($data) : 0) . "\0" . sprintf('%011o', 1763337600) . "\0"
            . '        ' . $type . str_pad($isFile ? '' : $data, 100, "\0") . "ustar\0" . '00';
        $header = str_pad($header, 512, "\0");
        $sum = array_sum(array_map('ord', str_split($header)));
        $header = substr_replace($header, sprintf('%06o', $sum) . "\0 ", 148, 8);
        $tar .= $header;
        if ($isFile && $data !== '') {
            $tar .= str_pad($data, (int) (ceil(strlen($data) / 512) * 512), "\0");
        }
    }

    return gzencode($tar . str_repeat("\0", 1024));
}

/** @param list<array{0:string,1?:string,2?:string,3?:int}> $entries */
function layout_release(string $dir, string $version, array $entries): array
{
    $path = $dir . '/larena-' . $version . '.tar.gz';
    file_put_contents($path, layout_tar($entries));
    $release = new VerifiedRelease('larena', $version, 'stable', '1.0.0', '^8.3', basename($path),
        hash_file('sha256', $path), filesize($path), ['larena/core' => str_repeat('a', 40)], false, [], 'test.key', str_repeat('0', 64));

    return [$release, $path];
}

$layoutRoot = sys_get_temp_dir() . '/larena-release-layout-' . bin2hex(random_bytes(6));
$layoutSite = $layoutRoot . '/site';
$layoutFiles = $layoutRoot . '/archives';
$layoutOutside = $layoutRoot . '/outside';
mkdir($layoutSite . '/storage/app/public', 0755, true);
mkdir($layoutFiles, 0755);
mkdir($layoutOutside, 0755);
file_put_contents($layoutSite . '/.env', "APP_KEY=test\n");
$layoutCode = static fn (string $v): array => [
    ['./', '5'],
    ['./artisan', '0', "<?php // $v\n", 0755],
    ['./public/', '5'],
    ['./public/index.php', '0', "<?php echo '$v';\n"],
    ['app/Version.php', '0', "<?php return '$v';\n"],
];

try {
    $extractor = new ReleaseArchiveExtractor();
    $activation = new RecordingReleaseActivation();
    $pointer = new ReleasePointer($layoutSite, [$activation]);

    // Unpack, link, switch.
    [$first, $firstArchive] = layout_release($layoutFiles, '1.2.0', $layoutCode('1.2.0'));
    $dir = $extractor->extract($first, $firstArchive, $layoutSite);
    layout_assert($dir === $layoutSite . '/releases/1.2.0' && is_file($dir . '/app/Version.php'), 'The release must be unpacked under releases/<release>.');
    layout_assert((fileperms($dir . '/artisan') & 0111) !== 0 && (fileperms($dir . '/app/Version.php') & 0111) === 0, 'Executable bits must follow the archive.');
    layout_assert(!file_exists($layoutSite . '/releases/.1.2.0.partial'), 'No partial directory may stay behind.');
    ReleaseSharedLinks::link($layoutSite, '1.2.0');
    layout_assert(readlink($dir . '/.env') === '../../.env' && readlink($dir . '/storage') === '../../storage'
        && readlink($dir . '/public/storage') === '../storage/app/public', 'Shared links must be relative.');
    layout_assert(file_get_contents($dir . '/.env') === "APP_KEY=test\n" && is_dir($dir . '/public/storage'), 'Shared links must resolve.');
    $switch = $pointer->switchTo('1.2.0');
    layout_assert($switch->previous === null && $switch->changed && readlink($layoutSite . '/current') === 'releases/1.2.0', 'The first switch must point current at the release.');
    layout_assert(!file_exists($layoutSite . '/current.next') && !is_link($layoutSite . '/current.next'), 'current.next must be consumed by the rename.');
    layout_assert(count($activation->switches) === 1, 'Activation must run after a switch.');

    // Running again changes nothing.
    layout_assert($extractor->extract($first, $firstArchive, $layoutSite) === $dir, 'The same archive must return the same directory.');
    ReleaseSharedLinks::link($layoutSite, '1.2.0');
    $again = $pointer->switchTo('1.2.0');
    layout_assert(!$again->changed && $again->current === '1.2.0' && count($activation->switches) === 1, 'Switching to the current release must change nothing.');

    // A directory under the same name that this archive did not write is refused.
    [$other] = layout_release($layoutFiles, '1.2.0', $layoutCode('other'));
    layout_assert(layout_reason(fn () => $extractor->extract($other, $layoutFiles . '/larena-1.2.0.tar.gz', $layoutSite)) === 'release_directory_differs', 'A different release directory must be refused.');
    [$checked, $checkedArchive] = layout_release($layoutFiles, '1.9.0', $layoutCode('1.9.0'));
    file_put_contents($checkedArchive, 'x', FILE_APPEND);
    layout_assert(layout_reason(fn () => $extractor->extract($checked, $checkedArchive, $layoutSite)) === 'release_archive_size_differs', 'An archive that differs from the signed one must be refused.');

    // Hostile archives: nothing written, nothing left.
    $hostile = [
        'release_archive_path_invalid' => [['../escape.php', '0', 'x'], ['/tmp/escape.php', '0', 'x'], ['app/../../escape.php', '0', 'x'], ['app//x.php', '0', 'x']],
        'release_archive_link_entry' => [['link', '2', '../outside'], ['hard', '1', 'artisan']],
        'release_archive_shared_entry' => [['.env', '0', 'APP_KEY=leak'], ['storage/app/x', '0', 'x'], ['public/storage', '5']],
        'release_archive_entry_duplicate' => [['twice', '0', 'a']],
    ];
    foreach ($hostile as $reason => $bad) {
        foreach ($bad as $index => $entry) {
            $entries = $reason === 'release_archive_entry_duplicate' ? [$entry, $entry] : [['public/', '5'], $entry, ['after', '0', 'x']];
            [$evil, $evilArchive] = layout_release($layoutFiles, '2.0.' . $index, $entries);
            $got = layout_reason(fn () => $extractor->extract($evil, $evilArchive, $layoutSite));
            layout_assert($got === $reason, "Entry {$entry[0]} must be refused with {$reason}, got {$got}.");
            layout_assert(!file_exists($layoutSite . '/releases/2.0.' . $index) && !file_exists($layoutSite . '/releases/.2.0.' . $index . '.partial'), 'A refused archive must leave no release.');
        }
    }
    layout_assert(!file_exists($layoutSite . '/escape.php') && !file_exists($layoutSite . '/releases/escape.php') && !is_link($layoutSite . '/releases/link'), 'Nothing may be written outside the release.');
    layout_assert(file_get_contents($layoutSite . '/.env') === "APP_KEY=test\n", 'The shared .env must stay untouched.');

    // Update and rollback.
    [$second, $secondArchive] = layout_release($layoutFiles, '1.3.0', $layoutCode('1.3.0'));
    $extractor->extract($second, $secondArchive, $layoutSite);
    ReleaseSharedLinks::link($layoutSite, '1.3.0');
    $update = $pointer->switchTo('1.3.0');
    layout_assert($update->previous === '1.2.0' && $pointer->current() === '1.3.0', 'The previous release must be returned by the switch.');
    $back = $pointer->rollback($update);
    layout_assert($back->current === '1.2.0' && $back->previous === '1.3.0' && readlink($layoutSite . '/current') === 'releases/1.2.0', 'Rollback must point current at the previous release.');
    layout_assert(count($activation->switches) === 3, 'Activation must run after the update and the rollback.');
    layout_assert(layout_reason(fn () => $pointer->rollback($switch)) === 'release_previous_missing', 'A first install has nothing to roll back to.');
    layout_assert(layout_reason(fn () => $pointer->switchTo('9.9.9')) === 'release_directory_missing', 'A missing release must not become current.');

    // Cleanup keeps current and the previous one; links into shared files and outside are removed, never followed.
    foreach (['1.4.0', '1.5.0'] as $version) {
        [$next, $nextArchive] = layout_release($layoutFiles, $version, $layoutCode($version));
        $extractor->extract($next, $nextArchive, $layoutSite);
        ReleaseSharedLinks::link($layoutSite, $version);
    }
    symlink($layoutOutside, $layoutSite . '/releases/1.2.0/outside');
    file_put_contents($layoutOutside . '/keep', 'keep');
    $pointer->switchTo('1.4.0');
    $last = $pointer->switchTo('1.5.0');
    $removed = ReleaseCleanup::prune($layoutSite, $last->previous);
    $left = array_values(array_filter(scandir($layoutSite . '/releases'), static fn (string $n): bool => $n[0] !== '.'));
    layout_assert($removed === ['1.2.0', '1.3.0'] && $left === ['1.4.0', '1.5.0'], 'Cleanup must keep exactly current and the previous release.');
    layout_assert(is_file($layoutOutside . '/keep') && is_file($layoutSite . '/.env') && is_dir($layoutSite . '/storage/app/public'), 'Cleanup must not follow links.');
    unlink($layoutSite . '/current');
    layout_assert(layout_reason(fn () => ReleaseCleanup::prune($layoutSite, null)) === 'release_current_missing', 'Without current nothing may be pruned.');
} finally {
    ReleaseDirectory::remove($layoutRoot);
}
