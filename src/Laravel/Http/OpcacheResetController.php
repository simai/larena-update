<?php

declare(strict_types=1);

namespace Larena\UpdateClient\Laravel\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Larena\UpdateClient\Laravel\ActivationTokenStore;
use Larena\UpdateClient\Laravel\OpcacheResetActivation;

/**
 * The internal activation address `POST /_larena/update/opcache-reset`: with the one-time token of the running update
 * it resets OPcache of this PHP pool and the realpath cache of this worker; without it the address does not exist
 * (404). It changes nothing else and returns no site data.
 */
final readonly class OpcacheResetController
{
    public function __construct(private ActivationTokenStore $tokens)
    {
    }

    public function __invoke(Request $request): JsonResponse
    {
        $token = (string) $request->headers->get(OpcacheResetActivation::HEADER, '');
        if (preg_match('/\A[a-f0-9]{64}\z/D', $token) !== 1 || !$this->tokens->consume($token)) {
            return new JsonResponse(['status' => 'not_found'], 404);
        }
        $reset = function_exists('opcache_reset') && opcache_reset();
        clearstatcache(true);

        return new JsonResponse(['status' => 'ok', 'opcache_reset' => $reset], 200, ['Cache-Control' => 'no-store']);
    }
}
