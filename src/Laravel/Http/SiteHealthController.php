<?php

declare(strict_types=1);

namespace Larena\UpdateClient\Laravel\Http;

use Illuminate\Http\JsonResponse;
use Larena\UpdateClient\Laravel\HttpSiteHealth;

/**
 * The update client's own health address `GET /_larena/update/health`: the application booted and routed this request,
 * so it answers `{"status":"ok","check":"larena_update_health"}` ({@see HttpSiteHealth::MARKER}). A PHP fatal page,
 * a web server's own page or another application cannot give this answer, so a 200 alone never counts as healthy.
 * No session, no database, no site data.
 */
final readonly class SiteHealthController
{
    public function __invoke(): JsonResponse
    {
        return new JsonResponse(['status' => 'ok', 'check' => HttpSiteHealth::MARKER], 200, ['Cache-Control' => 'no-store']);
    }
}
