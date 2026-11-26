<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Larena\UpdateClient\Laravel\Admin\UpdatesAdminController;

// «Лицензия и обновления» → «Обновления» (owner task 2026-11-26): seeing needs update.integrity.read, checking and
// installing update.integrity.apply (the update client's own rights, as «Проверка файлов»).
Route::name('larena.update-client.updates.')->group(static function (): void {
    Route::get('/', [UpdatesAdminController::class, 'index'])->middleware('access:update.integrity.read')->name('index');
    Route::post('/check', [UpdatesAdminController::class, 'check'])->middleware(['throttle:6,1', 'access:update.integrity.apply'])->name('check');
    Route::post('/install', [UpdatesAdminController::class, 'install'])->middleware(['throttle:6,1', 'access:update.integrity.apply'])->name('install');
});
