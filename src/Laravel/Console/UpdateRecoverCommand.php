<?php

declare(strict_types=1);

namespace Larena\UpdateClient\Laravel\Console;

use Larena\UpdateClient\Enums\UpdateCycleState;
use Larena\UpdateClient\Laravel\SiteUpdateClient;
use RuntimeException;

/**
 * Leaves `manual_recovery_required`: an update whose automatic rollback did not finish, or a manual rollback that
 * stopped half way, keeps the site in maintenance and refuses every new cycle. This finishes the rollback
 * ({@see \Larena\UpdateClient\Release\ReleaseUpdateCycle::recover()}): the release from before the cycle, the
 * database from the cycle's copy when the cycle had changed it, maintenance off. `--confirm` must repeat the cycle id;
 * `--keep-database` keeps the database when the failed update never reached its migrations. Every run is written to
 * the update journal with the administrator who ran it.
 */
final class UpdateRecoverCommand extends UpdateCommand
{
    protected $signature = 'larena:update:recover
        {--actor= : Existing active administrator subject_ref (the database restore checks it; written to the journal)}
        {--cycle= : The cycle that needs manual recovery; the unfinished one when omitted}
        {--keep-database : Keep the current database (refused when the update had started its migrations)}
        {--confirm= : The cycle id once more: required to change the site}
        {--json : Render a machine-readable result}';

    protected $description = 'Finish a rollback that did not end: the previous release, the database from the copy, maintenance off.';

    public function handle(SiteUpdateClient $client): int
    {
        return $this->respond(function () use ($client): array {
            $actor = $this->actor();
            $store = $client->store();
            $cycleId = trim((string) $this->option('cycle'));
            if ($cycleId === '') {
                $unfinished = $store->findUnfinished();
                if ($unfinished === null || $unfinished->state !== UpdateCycleState::ManualRecoveryRequired) {
                    throw new RuntimeException('update_recover_not_needed');
                }
                $cycleId = $unfinished->cycleId;
            }
            $cycle = $store->require($cycleId);
            if ($cycle->state !== UpdateCycleState::ManualRecoveryRequired) {
                throw new RuntimeException('update_recover_not_needed');
            }
            $restoreDatabase = !(bool) $this->option('keep-database');
            if ((string) $this->option('confirm') !== $cycle->cycleId) {
                return [
                    'status' => 'confirmation_required',
                    'reason' => 'update_recover_confirmation_required',
                    'from' => $cycle->release?->to,
                    'to' => $cycle->release?->from,
                    'database_restored' => $restoreDatabase,
                    'confirm_cycle_id' => $cycle->cycleId,
                ];
            }
            $outcome = $client->cycle($actor)->recover($cycle->cycleId, $cycle->revision, $actor, $restoreDatabase);

            return ['status' => $outcome->snapshot->state->value, 'warnings' => $outcome->warnings] + self::cycle($outcome->snapshot);
        }, function (array $payload): array {
            if ($payload['status'] === 'confirmation_required') {
                return [
                    'Recovery puts the site back on release ' . ($payload['to'] ?? '?') . ' and ends maintenance'
                        . ($payload['database_restored'] === true ? ', with the database from the copy made before the update (records created since are lost).' : ', keeping the database.'),
                    'Repeat with --confirm=' . $payload['confirm_cycle_id'] . ' to go on.',
                ];
            }
            $lines = ['Recovery of cycle ' . ($payload['cycle_id'] ?? '?') . ': ' . self::describeState((string) $payload['status']) . '.'];
            if (in_array($payload['status'], [UpdateCycleState::RecoverableFailed->value, UpdateCycleState::RolledBack->value], true)) {
                $lines[] = 'The site runs ' . ($payload['from'] ?? '?') . ' again and maintenance is off.';
            } elseif (isset($payload['reason'])) {
                $lines[] = 'Reason: ' . $payload['reason'] . '.';
                if (($text = $this->explain((string) $payload['reason'])) !== null) {
                    $lines[] = $text;
                }
            }
            foreach ((array) ($payload['warnings'] ?? []) as $warning) {
                $lines[] = 'Warning: ' . $warning . (is_string($warning) && ($text = $this->explain($warning)) !== null ? ' — ' . $text : '') . '.';
            }

            return $lines;
        }, [UpdateCycleState::RecoverableFailed->value, UpdateCycleState::RolledBack->value]);
    }
}
