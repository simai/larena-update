<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Larena\UpdateClient\Laravel\Http\OpcacheResetController;
use Larena\UpdateClient\Laravel\Http\SiteHealthController;
use Larena\UpdateClient\Laravel\HttpSiteHealth;

// The one-time internal activation address of the update client: no session, no CSRF, no site data; without the
// token of the running update it answers 404. Next to it the update client's own health address, with a JSON marker.
$activationPath = config('update-client.activation.path', '_larena/update/opcache-reset');

Route::post(is_string($activationPath) ? ltrim($activationPath, '/') : '_larena/update/opcache-reset', OpcacheResetController::class)
    ->name('larena.update-client.opcache-reset');

Route::get(ltrim(HttpSiteHealth::PATH, '/'), SiteHealthController::class)->name('larena.update-client.health');
