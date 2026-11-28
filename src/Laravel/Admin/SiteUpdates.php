<?php

declare(strict_types=1);

namespace Larena\UpdateClient\Laravel\Admin;

use Closure;
use DateTimeImmutable;
use Larena\UpdateClient\Laravel\ReleaseInstall;
use Larena\UpdateClient\Laravel\SiteUpdateClient;
use Throwable;

/**
 * «Лицензия и обновления» → «Обновления» (owner task 2026-11-26), through the existing larena:update:* services:
 *
 * - the running version and the channel;
 * - the last check of the update sources (what `larena:update:check` says), kept in one file
 *   (`storage/app/larena/update/last-check.json`) so the page never waits for the network; {@see check()} asks again;
 * - «Установить»: the release the last check offered, handed to the queue as the job `update.release.install`, which runs
 *   exactly `larena:update:apply` ({@see ReleaseInstall}: copy, new code, migrations, switch, health check, automatic
 *   rollback). A web request never switches the code it runs on. Without a queue the page names the command;
 * - the installation journal: the last cycles of the update store, and the cycle not finished;
 * - «Эта версия отозвана — обновите срочно» (matrix 5): the update server answered 410 for the running version at the
 *   last check ({@see \Larena\UpdateClient\Source\RevokedReleaseState}); a newer installed version clears it.
 *
 * Reason codes only: never a key, a source answer or a path.
 */
final readonly class SiteUpdates
{
    public const string JOB_TYPE = 'update.release.install';

    /**
     * @param Closure(): SiteUpdateClient                     $client
     * @param (Closure(string, string, string): string)|null  $dispatch version, actor, idempotency key → job id; null: no queue
     */
    public function __construct(
        private Closure $client,
        private string $checkPath,
        private ?Closure $dispatch = null,
    ) {
    }

    /**
     * @return array{installed:?string, installed_reason:?string, channel:?string, sources:int, check:?array<string,mixed>,
     *     queued:?array<string,mixed>, cycles:list<array<string,mixed>>, unfinished:?string, store_reason:?string, queue:bool,
     *     revoked:?array{version:string, fixed_in:?string, seen_at:string}}
     */
    public function overview(int $limit = 10): array
    {
        $installed = null;
        $installedReason = null;
        $channel = null;
        $sources = 0;
        $cycles = [];
        $unfinished = null;
        $storeReason = null;
        $revoked = null;
        try {
            $client = ($this->client)();
            $channel = $client->channel();
            $sources = count($client->sources());
            try {
                $installed = $client->installedVersion();
                $revoked = $client->revokedState()->current($installed);
            } catch (Throwable $exception) {
                $installedReason = ReleaseInstall::reason($exception);
            }
            try {
                $store = $client->store();
                $cycles = array_map(static fn (array $row): array => ReleaseInstall::cycle($row['snapshot'], $row['updated_at']), $store->latest(max(1, min(50, $limit))));
                $unfinished = $store->findUnfinished()?->cycleId;
            } catch (Throwable $exception) {
                $storeReason = ReleaseInstall::reason($exception);
            }
        } catch (Throwable $exception) {
            $installedReason = ReleaseInstall::reason($exception);
        }
        $kept = $this->kept();

        return [
            'installed' => $installed,
            'installed_reason' => $installedReason,
            'channel' => $channel,
            'sources' => $sources,
            // A check made for another installed version no longer says anything.
            'check' => $kept !== null && ($kept['installed'] ?? null) === $installed ? $kept : null,
            'queued' => is_array($kept['queued'] ?? null) && ($kept['installed'] ?? null) === $installed ? $kept['queued'] : null,
            'cycles' => $cycles,
            'unfinished' => $unfinished,
            'store_reason' => $storeReason,
            'queue' => $this->dispatch !== null,
            'revoked' => $revoked,
        ];
    }

    /** «Проверить сейчас»: asks the sources for the newest signed release and keeps the answer. @return array{done:bool, reason:string} */
    public function check(): array
    {
        try {
            $client = ($this->client)();
            $installed = $client->installedVersion();
            $source = $client->source();
            $offer = $source->latest($installed, $client->channel());
        } catch (Throwable $exception) {
            $reason = ReleaseInstall::reason($exception);
            $this->keep(['status' => 'failed', 'reason' => $reason, 'installed' => $installed ?? null, 'checked_at' => gmdate(DATE_ATOM)]);

            return ['done' => false, 'reason' => $reason];
        }
        if ($offer === null) {
            $this->keep(['status' => 'up_to_date', 'installed' => $installed, 'checked_at' => gmdate(DATE_ATOM)]);

            return ['done' => true, 'reason' => 'update_up_to_date'];
        }
        $release = $offer->release;
        $notes = [];
        foreach ($release->notes as $locale => $text) {
            if (is_string($locale) && is_string($text) && preg_match('/\A[a-z]{2}\z/', $locale) === 1) {
                $notes[$locale] = mb_substr($text, 0, 4000);
            }
        }
        $this->keep([
            'status' => 'available',
            'installed' => $installed,
            'version' => $release->version,
            'channel' => $release->channel,
            'minimum_version' => $release->minimumVersion,
            'archive_bytes' => $release->archiveBytes,
            'backward_incompatible_migrations' => $release->backwardIncompatibleMigrations,
            'notes' => $notes,
            'checked_at' => gmdate(DATE_ATOM),
        ]);

        return ['done' => true, 'reason' => 'update_available'];
    }

    /**
     * «Установить»: the version the last check offered, to the queue. @return array{done:bool, reason:string, job?:string}
     */
    public function install(string $version, string $actor): array
    {
        if (preg_match('/\A[a-z][a-z0-9_-]*:[A-Za-z0-9][A-Za-z0-9:._-]{0,191}\z/D', $actor) !== 1) {
            return ['done' => false, 'reason' => 'update_actor_required'];
        }
        $view = $this->overview(1);
        $check = $view['check'];
        if (!is_array($check) || ($check['status'] ?? null) !== 'available' || ($check['version'] ?? null) !== $version) {
            return ['done' => false, 'reason' => 'update_offer_unknown'];
        }
        if ($view['unfinished'] !== null || $view['queued'] !== null) {
            return ['done' => false, 'reason' => 'update_already_running'];
        }
        if ($this->dispatch === null) {
            return ['done' => false, 'reason' => 'update_queue_unavailable'];
        }
        $key = 'update:' . $version . ':' . bin2hex(random_bytes(8));
        try {
            $job = ($this->dispatch)($version, $actor, $key);
        } catch (Throwable) {
            return ['done' => false, 'reason' => 'update_queue_unavailable'];
        }
        $this->keep($check + ['queued' => ['version' => $version, 'job' => $job, 'idempotency_key' => $key, 'at' => gmdate(DATE_ATOM)]]);

        return ['done' => true, 'reason' => 'update_queued', 'job' => $job];
    }

    /** The queue job ran: the kept check no longer holds a queued install. */
    public function finished(): void
    {
        $kept = $this->kept();
        if ($kept !== null) {
            unset($kept['queued']);
            $this->keep($kept);
        }
    }

    /** @return array<string,mixed>|null */
    private function kept(): ?array
    {
        if (is_link($this->checkPath) || !is_file($this->checkPath)) {
            return null;
        }
        $data = json_decode((string) @file_get_contents($this->checkPath, false, null, 0, 65536), true, 8);
        if (!is_array($data) || ($data['schema'] ?? null) !== 'larena.update-last-check' || !is_string($data['checked_at'] ?? null)
            || DateTimeImmutable::createFromFormat(DATE_ATOM, $data['checked_at']) === false
            || DateTimeImmutable::createFromFormat(DATE_ATOM, $data['checked_at']) < (new DateTimeImmutable())->modify('-30 days')) {
            return null;
        }
        unset($data['schema']);

        return $data;
    }

    /** @param array<string,mixed> $data */
    private function keep(array $data): void
    {
        $directory = dirname($this->checkPath);
        if ((!is_dir($directory) && !@mkdir($directory, 0750, true) && !is_dir($directory)) || is_link($this->checkPath)) {
            return;
        }
        $json = json_encode(['schema' => 'larena.update-last-check'] + $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        $temporary = $directory . '/.last-check-' . bin2hex(random_bytes(6)) . '.tmp';
        if ($json === false || @file_put_contents($temporary, $json, LOCK_EX) !== strlen($json) || !@rename($temporary, $this->checkPath)) {
            @unlink($temporary);
        }
    }
}
