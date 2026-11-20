<?php

declare(strict_types=1);

namespace Larena\UpdateClient\Laravel\Console;

use Illuminate\Console\Command;
use Larena\UpdateClient\Integrity\IntegrityLayout;
use Larena\UpdateClient\Integrity\IntegrityRefused;
use Larena\UpdateClient\Laravel\Integrity\SiteIntegrity;

/**
 * `larena:integrity:restore`: «Вернуть как было» from the console, plan → confirmation like the admin's button.
 * Without `--confirm` it prints the plan and its digest; `--confirm=<digest>` runs exactly that plan (refused if the
 * files changed since), then the full check.
 */
final class IntegrityRestoreCommand extends Command
{
    protected $signature = 'larena:integrity:restore
        {--confirm= : The digest of the plan shown before}
        {--finding= : Only this finding (its key)}
        {--json : Print machine-readable JSON only}';

    protected $description = 'Put the release files back from the kept archive and move extra PHP files to the quarantine.';

    public function handle(SiteIntegrity $integrity): int
    {
        $only = is_string($this->option('finding')) && $this->option('finding') !== '' ? (string) $this->option('finding') : null;
        $confirm = $this->option('confirm');
        if (!is_string($confirm) || $confirm === '') {
            $plan = $integrity->restore()->plan($only);
            $answer = [
                'digest' => $plan->digest(), 'restore' => array_column($plan->restore, 'path'),
                'quarantine' => array_map(static fn (array $item): string => IntegrityLayout::label($item['root'], $item['path']), $plan->quarantine),
                'blockers' => $plan->blockers, 'skipped' => $plan->skipped,
            ];
            $this->output($answer);

            return $plan->blocked() || $plan->empty() ? 2 : 0;
        }
        try {
            $outcome = $integrity->restore()->run($confirm, 'system:console', $only);
        } catch (IntegrityRefused $refused) {
            $this->output(['refused' => $refused->reason]);

            return 2;
        }
        $this->output($outcome);

        return $outcome['result'] === 'unchanged' ? 0 : 1;
    }

    /** @param array<string,mixed> $answer */
    private function output(array $answer): void
    {
        $this->line((string) json_encode($answer, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | ($this->option('json') ? 0 : JSON_PRETTY_PRINT)));
    }
}
