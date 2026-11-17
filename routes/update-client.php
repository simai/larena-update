<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Larena\UpdateClient\Laravel\Http\OpcacheResetController;

// The one-time internal activation address of the update client: no session, no CSRF, no site data; without the
// token of the running update it answers 404.
$activationPath = config('update-client.activation.path', '_larena/update/opcache-reset');

Route::post(is_string($activationPath) ? ltrim($activationPath, '/') : '_larena/update/opcache-reset', OpcacheResetController::class)
    ->name('larena.update-client.opcache-reset');
