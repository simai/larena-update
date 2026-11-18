<?php

declare(strict_types=1);

namespace Larena\UpdateClient\Laravel\Console;

use Larena\UpdateClient\Enums\UpdateCycleState;
use Larena\UpdateClient\Laravel\SiteUpdateClient;
use RuntimeException;

/**
 * Takes the last completed update back to the release it replaced (plan §3, step 6), while that release folder and
 * the cycle's copy exist. By default the database comes back from the copy — records made after the update are
 * lost, so `--confirm` must repeat the cycle id. `--keep-database` moves only the code, which a release with
 * backward-incompatible migrations refuses.
 */
final class UpdateRollbackCommand extends UpdateCommand
{
    protected $signature = 'larena:update:rollback
        {--actor= : Existing active administrator subject_ref (the database restore checks it)}
        {--cycle= : The completed update cycle to take back; the latest one when omitted}
        {--keep-database : Switch only the code back and keep the current database}
        {--confirm= : The cycle id once more: required when the database is restored from the copy}
        {--json : Render a machine-readable result}';

    protected $description = 'Return the site to the release before the last update (code and, by default, the database from the copy).';

    public function handle(SiteUpdateClient $client): int
    {
        return $this->respond(function () use ($client): array {
            $actor = $this->actor();
            $store = $client->store();
            $cycleId = trim((string) $this->option('cycle'));
            if ($cycleId === '') {
                $current = $client->installedRelease();
                foreach ($store->latest(50) as $row) {
                    $candidate = $row['snapshot'];
                    if ($candidate->state === UpdateCycleState::Completed && $candidate->release?->to === $current) {
                        $cycleId = $candidate->cycleId;
                        break;
                    }
                }
                if ($cycleId === '') {
                    throw new RuntimeException('update_rollback_not_available');
                }
            }
            $cycle = $store->require($cycleId);
            $restoreDatabase = !(bool) $this->option('keep-database');
            if ($restoreDatabase && (string) $this->option('confirm') !== $cycle->cycleId) {
                return [
                    'status' => 'confirmation_required',
                    'reason' => 'update_rollback_confirmation_required',
                    'from' => $cycle->release?->to,
                    'to' => $cycle->release?->from,
                    'confirm_cycle_id' => $cycle->cycleId,
                ];
            }
            $outcome = $client->cycle($actor)->rollback($cycle->cycleId, $cycle->revision, $restoreDatabase);

            return ['status' => $outcome->snapshot->state->value, 'database_restored' => $restoreDatabase, 'warnings' => $outcome->warnings] + self::cycle($outcome->snapshot);
        }, static function (array $payload): array {
            if ($payload['status'] === 'confirmation_required') {
                return [
                    'Rollback ' . ($payload['from'] ?? '?') . ' → ' . ($payload['to'] ?? '?') . ' restores the database from the copy made before the update.',
                    'Records created after the update will be lost.',
                    'Repeat with --confirm=' . $payload['confirm_cycle_id'] . ' to go on, or use --keep-database to switch only the code.',
                ];
            }
            $lines = ['Rollback of cycle ' . ($payload['cycle_id'] ?? '?') . ': ' . self::describeState((string) $payload['status']) . '.'];
            if ($payload['status'] === UpdateCycleState::RolledBack->value) {
                $lines[] = 'The site runs ' . ($payload['from'] ?? '?') . ' again' . ($payload['database_restored'] === true ? ', with the database from the copy.' : '; the database was kept.');
            } elseif (isset($payload['reason'])) {
                $lines[] = 'Reason: ' . $payload['reason'] . '.';
            }

            return $lines;
        }, [UpdateCycleState::RolledBack->value]);
    }
}
