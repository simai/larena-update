<?php

declare(strict_types=1);

namespace Larena\UpdateClient\Laravel\Integrity;

use Closure;
use Illuminate\Contracts\Translation\Translator;
use Illuminate\Http\Request;
use Larena\Admin\Alerts\AdminAlert;
use Larena\Admin\Alerts\AdminAlertAction;
use Larena\Admin\Alerts\AdminAlertLevel;
use Larena\Admin\Contracts\AdminAlertSource;
use Larena\UpdateClient\Integrity\CoreCheck;
use Larena\UpdateClient\Integrity\IntegrityScanner;

/**
 * The integrity checks in the admin's alert bar, on every admin page, from what is already known (the request check
 * of this request, the last full check, the open findings) — nothing is read anew here:
 *
 * - danger `update-client:integrity:core` — main files of the site are changed (A2), with «Вернуть как было»;
 * - danger `update-client:integrity:findings` — the last full check found changes (A3);
 * - warning `update-client:integrity:no-list` — «списка файлов нет»: nothing to compare with, never «всё в порядке»;
 * - warning `update-client:integrity:unknown` — the check could not run, with the reason in words.
 *
 * Only a person who may see the update centre (`update.safety.read`) sees them.
 */
final readonly class IntegrityAdminAlertSource implements AdminAlertSource
{
    /**
     * @param Closure(Request, string): bool               $allows
     * @param (Closure(string, array<string,string>): string)|null $route a route's relative address (default: route())
     */
    public function __construct(
        private SiteIntegrity $integrity,
        private Translator $translator,
        private Closure $allows,
        private ?Closure $route = null,
    ) {
    }

    public function alerts(Request $request): array
    {
        if (!($this->allows)($request, 'update.safety.read')) {
            return [];
        }
        $text = fn (string $key, array $replace = []): string => (string) $this->translator->get('larena-update-client::integrity.' . $key, $replace);
        $route = $this->route ?? static fn (string $name, array $parameters = []): string => route($name, $parameters, false);
        $page = $route('larena.update-client.integrity.index');
        $source = $text('alerts.source');
        $check = $request->attributes->get(CheckCoreFiles::ATTRIBUTE);
        $check = $check instanceof CoreCheck ? $check : $this->integrity->requestCheck();
        $alerts = [];
        if ($check->status === CoreCheck::CHANGED) {
            $alerts[] = new AdminAlert('update-client:integrity:core', AdminAlertLevel::Danger,
                $text('alerts.core', ['count' => count($check->problems)]), $source, null,
                new AdminAlertAction($text('restore.button'), $route('larena.update-client.integrity.restore')));
        } elseif ($check->status === CoreCheck::NO_LIST) {
            $alerts[] = new AdminAlert('update-client:integrity:no-list', AdminAlertLevel::Warning, $text('alerts.no_list'), $source, null,
                new AdminAlertAction($text('alerts.open'), $page));
        } elseif ($check->status === CoreCheck::UNKNOWN) {
            $alerts[] = new AdminAlert('update-client:integrity:unknown', AdminAlertLevel::Warning,
                $text('alerts.unknown', ['reason' => $this->reason((string) $check->reason)]), $source, null,
                new AdminAlertAction($text('alerts.open'), $page));
        }
        if ($check->status !== CoreCheck::NO_LIST) {
            $open = count($this->integrity->findings()->open());
            if ($open > 0) {
                $alerts[] = new AdminAlert('update-client:integrity:findings', AdminAlertLevel::Danger,
                    $text('alerts.findings', ['count' => $open]), $source, null, new AdminAlertAction($text('alerts.open'), $page));
            } elseif ((($this->integrity->scanner()->last() ?? [])['result'] ?? null) === IntegrityScanner::UNKNOWN && $check->status === CoreCheck::OK) {
                $last = (array) $this->integrity->scanner()->last();
                $alerts[] = new AdminAlert('update-client:integrity:unknown', AdminAlertLevel::Warning,
                    $text('alerts.unknown', ['reason' => $this->reason((string) ($last['reason'] ?? ''))]), $source, null,
                    new AdminAlertAction($text('alerts.open'), $page));
            }
        }

        return $alerts;
    }

    private function reason(string $code): string
    {
        $key = 'larena-update-client::integrity.reasons.' . $code;
        $line = (string) $this->translator->get($key);

        return $line !== $key ? $line : (string) $this->translator->get('larena-update-client::integrity.reasons.other');
    }
}
