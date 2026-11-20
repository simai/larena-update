<?php

declare(strict_types=1);

namespace Larena\UpdateClient\Laravel\Integrity;

use Closure;
use Illuminate\Http\Request;
use Larena\UpdateClient\Integrity\CoreCheck;
use Larena\UpdateClient\Integrity\IntegrityHistory;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * A2 on every request (global HTTP middleware, `update-client.integrity.every_request`): the main files checked
 * against the signed list ({@see \Larena\UpdateClient\Integrity\CoreFileGuard}). It never stops a request: the site
 * keeps working and the admin keeps opening, so the person sees the alert and can press «Вернуть как было». The
 * first request that sees the main files changed writes it to «История».
 */
final readonly class CheckCoreFiles
{
    public const ATTRIBUTE = 'larena_update_integrity_core';

    public function __construct(private SiteIntegrity $integrity)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        try {
            $check = $this->integrity->requestCheck();
            $request->attributes->set(self::ATTRIBUTE, $check);
            if ($check->transitioned && $check->status === CoreCheck::CHANGED) {
                $this->integrity->history->record(IntegrityHistory::PREFIX . 'core.changed', SiteIntegrity::ACTOR, [
                    'files' => count($check->problems),
                    'paths' => substr(implode(', ', array_keys($check->problems)), 0, 500),
                ]);
            }
        } catch (Throwable) {
            // The check is never the reason a page does not open.
        }

        return $next($request);
    }
}
