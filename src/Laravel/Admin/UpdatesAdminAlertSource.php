<?php

declare(strict_types=1);

namespace Larena\UpdateClient\Laravel\Admin;

use Closure;
use Illuminate\Contracts\Translation\Translator;
use Illuminate\Http\Request;
use Larena\Admin\Alerts\AdminAlert;
use Larena\Admin\Alerts\AdminAlertAction;
use Larena\Admin\Alerts\AdminAlertLevel;
use Larena\Admin\Contracts\AdminAlertSource;
use Larena\UpdateClient\Laravel\Integrity\IntegrityAccessOperationRegistrar;
use Larena\UpdateClient\Laravel\SiteUpdateClient;
use Throwable;

/**
 * «Обновления» in the admin's alert bar (matrix 5): danger `update-client:updates:revoked` — «Эта версия отозвана —
 * обновите срочно» (and «Исправлено в X» when the server named it), with «Обновить» to the page. Read from what the
 * last check kept ({@see \Larena\UpdateClient\Source\RevokedReleaseState}); nothing is asked of the network here. Only
 * a person who may see the page (`update.integrity.read`) sees it; a site outside the release layout has none.
 */
final readonly class UpdatesAdminAlertSource implements AdminAlertSource
{
    public const string REVOKED = 'update-client:updates:revoked';

    /**
     * @param Closure(): SiteUpdateClient                          $client
     * @param Closure(Request, string): bool                        $allows
     * @param (Closure(string, array<string,string>): string)|null  $route a route's relative address (default: route())
     */
    public function __construct(
        private Closure $client,
        private Translator $translator,
        private Closure $allows,
        private ?Closure $route = null,
    ) {
    }

    public function alerts(Request $request): array
    {
        if (!($this->allows)($request, IntegrityAccessOperationRegistrar::READ)) {
            return [];
        }
        try {
            $client = ($this->client)();
            $revoked = $client->revokedState()->current($client->installedVersion());
        } catch (Throwable) {
            return [];
        }
        if ($revoked === null) {
            return [];
        }
        $text = fn (string $key, array $replace = []): string => (string) $this->translator->get('larena-update-client::updates.' . $key, $replace);
        $route = $this->route ?? static fn (string $name, array $parameters = []): string => route($name, $parameters, false);
        $line = $text('revoked.heading') . '.';
        if ($revoked['fixed_in'] !== null) {
            $line .= ' ' . $text('revoked.fixed_in', ['version' => $revoked['fixed_in']]);
        }

        return [new AdminAlert(self::REVOKED, AdminAlertLevel::Danger, $line, $text('title'), null,
            new AdminAlertAction($text('revoked.action'), $route('larena.update-client.updates.index')))];
    }
}
