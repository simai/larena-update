<?php

declare(strict_types=1);

namespace Larena\UpdateClient\Laravel\Integrity;

use Closure;
use DateTimeImmutable;
use Illuminate\Contracts\Translation\Translator;
use Illuminate\Contracts\View\Factory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Larena\UpdateClient\Integrity\CoreCheck;
use Larena\UpdateClient\Integrity\Finding;
use Larena\UpdateClient\Integrity\FindingStore;
use Larena\UpdateClient\Integrity\IntegrityHistory;
use Larena\UpdateClient\Integrity\IntegrityLayout;
use Larena\UpdateClient\Integrity\IntegrityRefused;
use Larena\UpdateClient\Integrity\RestorePlan;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

/**
 * «Целостность файлов» in the admin: what the checks see now, the findings with their pages, «Проверить сейчас», and
 * «Вернуть как было» and the return from the quarantine, both through plan → confirmation. Seeing needs
 * `update.safety.read`; acting needs `update.safety.apply` (the update centre's own rights: the same people who may
 * update the site may put its files back).
 */
final readonly class IntegrityAdminController
{
    private const RESTORE = 'update.integrity.restore';
    private const RETURN = 'update.integrity.quarantine.return';
    private const NOTICE = 'larena_update_integrity_notice';

    /** @param Closure(Request, string): bool $allows */
    public function __construct(
        private SiteIntegrity $integrity,
        private Factory $views,
        private Translator $translator,
        private Closure $allows,
        private IntegrityPlanReceipts $receipts = new IntegrityPlanReceipts(),
    ) {
    }

    public function index(Request $request): mixed
    {
        $check = $this->integrity->requestCheck();
        $scanner = $this->integrity->scanner();
        $current = (array) ($scanner->state()['current'] ?? []);
        $findings = $this->integrity->findings()->all();

        return $this->views->make('larena-update-client::integrity.index', [
            'check' => $check,
            'checkWords' => $this->checkWords($check),
            'last' => $scanner->last(),
            'running' => ($current['state'] ?? null) === 'running' ? $current : null,
            'open' => array_values(array_filter($findings, static fn (array $f): bool => $f['state'] === FindingStore::OPEN)),
            'accepted' => array_values(array_filter($findings, static fn (array $f): bool => $f['state'] === FindingStore::EXCEPTION)),
            'resolved' => array_slice(array_values(array_filter($findings, static fn (array $f): bool => $f['state'] === FindingStore::RESOLVED)), 0, 20),
            'quarantine' => $this->integrity->restore()->quarantine(),
            'canAct' => ($this->allows)($request, 'update.safety.apply'),
            'notice' => $request->hasSession() ? $request->session()->get(self::NOTICE) : null,
            'archive' => $this->archiveWhere(),
            'label' => static fn (array $finding): string => IntegrityLayout::label((string) $finding['root'], (string) $finding['path']),
            'kind' => fn (string $kind): string => $this->text('kinds.' . $kind),
            'result' => fn (?string $result): string => $this->text('results.' . ($result ?? 'running')),
            'reason' => fn (?string $code): string => $this->reason($code),
        ]);
    }

    public function check(Request $request): RedirectResponse
    {
        // A portion now, as much as fits in a few seconds; the scheduler and the queue finish a big site.
        $run = $this->integrity->scanFor(8.0, 'manual', IntegrityPlanReceipts::actor($request), true);
        $done = ($run['state'] ?? null) === 'completed';

        return $this->back($request, $done ? $this->text('notices.checked', ['result' => $this->text('results.' . (string) $run['result'])]) : $this->text('notices.checking'));
    }

    public function finding(Request $request, string $key): mixed
    {
        $finding = $this->findingOr404($key);

        return $this->views->make('larena-update-client::integrity.finding', [
            'finding' => $finding,
            'label' => IntegrityLayout::label((string) $finding['root'], (string) $finding['path']),
            'kind' => $this->text('kinds.' . $finding['kind']),
            'state' => $this->text('states.' . $finding['state']),
            'explain' => $this->text('explain.' . $finding['kind']),
            'action' => $this->text(in_array($finding['kind'], Finding::RESTORED, true) ? 'restore.does_restore'
                : (in_array($finding['kind'], Finding::QUARANTINED, true) ? 'restore.does_quarantine' : 'restore.does_nothing')),
            'canRestore' => ($this->allows)($request, 'update.safety.apply') && $finding['state'] === FindingStore::OPEN
                && (in_array($finding['kind'], Finding::RESTORED, true) || in_array($finding['kind'], Finding::QUARANTINED, true)),
            'canAccept' => ($this->allows)($request, 'update.safety.apply') && $finding['state'] === FindingStore::OPEN
                && !in_array($finding['kind'], ['archive_damaged', 'archive_missing'], true),
            'notice' => $request->hasSession() ? $request->session()->get(self::NOTICE) : null,
        ]);
    }

    public function exception(Request $request, string $key): RedirectResponse
    {
        $finding = $this->findingOr404($key);
        $days = (int) $request->input('days', 30);
        $scope = $request->input('scope') === 'folder'
            ? (dirname(IntegrityLayout::label((string) $finding['root'], (string) $finding['path'])) . '/*')
            : IntegrityLayout::label((string) $finding['root'], (string) $finding['path']);
        $now = new DateTimeImmutable();
        try {
            $exception = $this->integrity->findings()->addException($scope, $now->modify('+' . max(1, min(365, $days)) . ' days'),
                (string) $request->input('reason', ''), IntegrityPlanReceipts::actor($request), $now);
        } catch (Throwable $failure) {
            return $this->back($request, $this->reason($failure->getMessage()), route('larena.update-client.integrity.finding', ['key' => $key], false));
        }
        $this->integrity->history->record(IntegrityHistory::PREFIX . 'exception.accepted', IntegrityPlanReceipts::actor($request), [
            'scope' => $exception['scope'], 'until' => $exception['until'], 'kind' => (string) $finding['kind'],
        ]);

        return $this->back($request, $this->text('notices.accepted', ['scope' => $exception['scope']]),
            route('larena.update-client.integrity.finding', ['key' => $key], false));
    }

    /** The plan of «Вернуть как было» (all open findings, or one with `?finding=`), and its one question. */
    public function restorePlan(Request $request): mixed
    {
        $only = self::key($request->query('finding'));
        $plan = $this->integrity->restore()->plan($only);
        $planned = null;
        if (!$plan->empty() && class_exists(\Larena\Admin\Runtime\AdminPlannedAction::class)) {
            $planned = (new \Larena\Admin\Runtime\AdminPlannedAction(
                question: $this->text('restore.question'),
                runUrl: route('larena.update-client.integrity.restore.run', [], false),
                token: $request->hasSession() ? (string) $request->session()->token() : '',
                runFields: array_filter([IntegrityPlanReceipts::FIELD => $this->receipts->issue($request, self::RESTORE, $plan->digest()), 'finding' => $only ?? '']),
                acceptText: $this->text('restore.accept'),
                consequence: $this->text('restore.consequence'),
                facts: $this->facts($plan),
                warnings: $this->warnings($plan),
                details: [[$this->text('restore.plan_digest'), substr($plan->digest(), 0, 16)], [$this->text('restore.archive'), $this->archiveWhere()]],
                cancelUrl: $only !== null ? route('larena.update-client.integrity.finding', ['key' => $only], false) : route('larena.update-client.integrity.index', [], false),
                key: 'integrity-restore',
            ))->html();
        }

        return $this->views->make('larena-update-client::integrity.plan', [
            'heading' => $this->text('restore.heading'),
            'planned' => $planned,
            'empty' => $plan->empty(),
            'blockers' => array_map(fn (string $code): string => $this->reason($code), $plan->blockers),
            'back' => route('larena.update-client.integrity.index', [], false),
        ]);
    }

    public function restoreRun(Request $request): RedirectResponse
    {
        $only = self::key($request->input('finding'));
        $digest = $this->receipts->take($request, self::RESTORE);
        if ($digest === null) {
            return $this->back($request, $this->reason('integrity_plan_receipt'));
        }
        try {
            $outcome = $this->integrity->restore()->run($digest, IntegrityPlanReceipts::actor($request), $only);
        } catch (IntegrityRefused $refused) {
            return $this->back($request, $this->reason($refused->reason));
        }
        $this->integrity->forgetRequestCheck();

        return $this->back($request, $this->text('notices.restored', [
            'restored' => $outcome['restored'], 'quarantined' => $outcome['quarantined'],
            'result' => $this->text('results.' . (string) ($outcome['result'] ?? 'unknown')),
        ]));
    }

    public function returnPlan(Request $request, string $id): mixed
    {
        $item = $this->integrity->restore()->quarantined($id) ?? throw new NotFoundHttpException();
        $label = IntegrityLayout::label((string) $item['root'], (string) $item['path']);
        $digest = (string) $this->integrity->restore()->returnDigest($id);
        $taken = file_exists($this->integrity->layout->path((string) $item['root'], (string) $item['path']));
        $planned = class_exists(\Larena\Admin\Runtime\AdminPlannedAction::class) ? (new \Larena\Admin\Runtime\AdminPlannedAction(
            question: $this->text('quarantine.question', ['path' => $label]),
            runUrl: route('larena.update-client.integrity.quarantine.run', ['id' => $id], false),
            token: $request->hasSession() ? (string) $request->session()->token() : '',
            runFields: [IntegrityPlanReceipts::FIELD => $this->receipts->issue($request, self::RETURN . ':' . $id, $digest)],
            acceptText: $this->text('quarantine.accept'),
            consequence: $this->text('quarantine.consequence'),
            facts: [[$this->text('quarantine.place'), $label], [$this->text('quarantine.moved'), (string) $item['moved_at']], ['SHA-256', substr((string) $item['sha256'], 0, 16)]],
            warnings: $taken ? [['text' => $this->reason('integrity_place_taken'), 'blocking' => true]] : [],
            cancelUrl: route('larena.update-client.integrity.index', [], false),
            key: 'integrity-return',
        ))->html() : null;

        return $this->views->make('larena-update-client::integrity.plan', [
            'heading' => $this->text('quarantine.heading'),
            'planned' => $planned,
            'empty' => false,
            'blockers' => [],
            'back' => route('larena.update-client.integrity.index', [], false),
        ]);
    }

    public function returnRun(Request $request, string $id): RedirectResponse
    {
        $digest = $this->receipts->take($request, self::RETURN . ':' . $id);
        if ($digest === null) {
            return $this->back($request, $this->reason('integrity_plan_receipt'));
        }
        try {
            $this->integrity->restore()->returnItem($id, $digest, IntegrityPlanReceipts::actor($request));
        } catch (IntegrityRefused $refused) {
            return $this->back($request, $this->reason($refused->reason));
        }

        return $this->back($request, $this->text('notices.returned'));
    }

    /** @return list<array{0:string,1:string}> */
    private function facts(RestorePlan $plan): array
    {
        $facts = [];
        if ($plan->restore !== []) {
            $facts[] = [$this->text('restore.from_archive', ['count' => count($plan->restore)]), self::paths(array_column($plan->restore, 'path'))];
        }
        if ($plan->quarantine !== []) {
            $facts[] = [$this->text('restore.to_quarantine', ['count' => count($plan->quarantine)]),
                self::paths(array_map(static fn (array $item): string => IntegrityLayout::label($item['root'], $item['path']), $plan->quarantine))];
        }
        $facts[] = [$this->text('restore.then'), $this->text('restore.then_value')];

        return $facts;
    }

    /** @return list<array{text:string,blocking:bool}> */
    private function warnings(RestorePlan $plan): array
    {
        $warnings = [];
        foreach ($plan->blockers as $code) {
            $warnings[] = ['text' => $this->reason($code), 'blocking' => true];
        }
        foreach ($plan->skipped as $item) {
            $warnings[] = ['text' => $item['path'] . ': ' . $this->reason($item['reason']), 'blocking' => false];
        }

        return $warnings;
    }

    /** @param list<string> $paths */
    private static function paths(array $paths): string
    {
        $shown = array_slice($paths, 0, 12);

        return implode(', ', $shown) . (count($paths) > 12 ? ' …' : '');
    }

    private function archiveWhere(): string
    {
        try {
            $archive = $this->integrity->baseline()->archivePath;
        } catch (Throwable) {
            return $this->text('archive_none');
        }
        $site = dirname($this->integrity->layout->releaseRoot, 2);

        return str_starts_with($archive, $site . '/') ? substr($archive, strlen($site) + 1) : basename(dirname($archive, 2)) . '/' . basename(dirname($archive)) . '/' . basename($archive);
    }

    /** @return array<string,mixed> */
    private function findingOr404(string $key): array
    {
        $key = self::key($key) ?? throw new NotFoundHttpException();

        return $this->integrity->findings()->get($key) ?? throw new NotFoundHttpException();
    }

    private static function key(mixed $key): ?string
    {
        return is_string($key) && preg_match('/\A[a-f0-9]{24}\z/D', $key) === 1 ? $key : null;
    }

    /** @return array<string,string> */
    private function checkWords(CoreCheck $check): array
    {
        return ['status' => $this->text('core.' . $check->status), 'reason' => $check->reason !== null ? $this->reason($check->reason) : ''];
    }

    private function back(Request $request, string $notice, ?string $to = null): RedirectResponse
    {
        if ($request->hasSession()) {
            $request->session()->flash(self::NOTICE, $notice);
        }

        return new RedirectResponse($to ?? route('larena.update-client.integrity.index', [], false));
    }

    private function text(string $key, array $replace = []): string
    {
        return (string) $this->translator->get('larena-update-client::integrity.' . $key, $replace);
    }

    private function reason(?string $code): string
    {
        $key = 'larena-update-client::integrity.reasons.' . (string) $code;
        $line = (string) $this->translator->get($key);

        return $line !== $key ? $line : (string) $this->translator->get('larena-update-client::integrity.reasons.other');
    }
}
