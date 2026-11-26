<?php

declare(strict_types=1);

namespace Larena\UpdateClient\Laravel\Admin;

use Closure;
use Illuminate\Contracts\Translation\Translator;
use Illuminate\Contracts\View\Factory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Larena\UpdateClient\Laravel\Integrity\IntegrityAccessOperationRegistrar;
use Larena\UpdateClient\Laravel\Integrity\IntegrityPlanReceipts;

/**
 * «Лицензия и обновления» → «Обновления» in the admin ({@see SiteUpdates}): the running version, the last check and what
 * a newer release holds, «Проверить сейчас», «Установить» (to the queue) and the installation journal. Seeing needs
 * `update.integrity.read`, checking and installing `update.integrity.apply`: the update client's own rights, the ones
 * «Проверка файлов» already uses (the site's code, one owner).
 */
final readonly class UpdatesAdminController
{
    private const NOTICE = 'larena_update_updates_notice';

    /** @param Closure(Request, string): bool $allows */
    public function __construct(
        private SiteUpdates $updates,
        private Factory $views,
        private Translator $translator,
        private Closure $allows,
    ) {
    }

    public function index(Request $request): mixed
    {
        $view = $this->updates->overview();
        $locale = $this->translator->getLocale();
        $notes = is_array($view['check']['notes'] ?? null) ? $view['check']['notes'] : [];

        return $this->views->make('larena-update-client::updates.index', [
            'view' => $view,
            'notes' => is_string($notes[$locale] ?? null) ? $notes[$locale] : (is_string($notes['en'] ?? null) ? $notes['en'] : (string) (array_values($notes)[0] ?? '')),
            'canAct' => ($this->allows)($request, IntegrityAccessOperationRegistrar::APPLY),
            'notice' => $request->hasSession() ? $request->session()->get(self::NOTICE) : null,
            'state' => fn (string $state): string => $this->words('states.' . $state, $state),
            'reason' => fn (?string $code): string => $this->reason($code),
        ]);
    }

    public function check(Request $request): RedirectResponse
    {
        $result = $this->updates->check();

        return $this->back($request, $this->reason($result['reason']));
    }

    public function install(Request $request): RedirectResponse
    {
        $version = $request->input('version');
        $result = $this->updates->install(is_string($version) ? $version : '', IntegrityPlanReceipts::actor($request));

        return $this->back($request, $this->reason($result['reason']));
    }

    private function back(Request $request, string $notice): RedirectResponse
    {
        if ($request->hasSession()) {
            $request->session()->flash(self::NOTICE, $notice);
        }

        return new RedirectResponse(route('larena.update-client.updates.index', [], false));
    }

    private function words(string $key, string $fallback = ''): string
    {
        $full = 'larena-update-client::updates.' . $key;
        $line = $this->translator->get($full);

        return is_string($line) && $line !== $full ? $line : $fallback;
    }

    /** The words of a reason: the page's own, else the update client's reasons, else a general line. */
    private function reason(?string $code): string
    {
        $own = $this->words('reasons.' . (string) $code);
        if ($own !== '') {
            return $own;
        }
        $key = 'larena-update-client::integrity.reasons.' . (string) $code;
        $line = $this->translator->get($key);
        if (is_string($line) && $line !== $key) {
            return mb_strtoupper(mb_substr($line, 0, 1)) . mb_substr($line, 1);
        }

        return $this->words('reasons.other', (string) $code);
    }
}
