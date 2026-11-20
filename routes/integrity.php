<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Larena\UpdateClient\Laravel\Integrity\IntegrityAdminController;

// «Целостность файлов» (owner decision 2026-11-20): seeing needs update.safety.read, acting update.safety.apply.
Route::name('larena.update-client.integrity.')->group(static function (): void {
    Route::get('/', [IntegrityAdminController::class, 'index'])->middleware('access:update.safety.read')->name('index');
    Route::post('/check', [IntegrityAdminController::class, 'check'])->middleware(['throttle:6,1', 'access:update.safety.apply'])->name('check');
    Route::get('/findings/{key}', [IntegrityAdminController::class, 'finding'])->where('key', '[a-f0-9]{24}')
        ->middleware('access:update.safety.read')->name('finding');
    Route::post('/findings/{key}/exception', [IntegrityAdminController::class, 'exception'])->where('key', '[a-f0-9]{24}')
        ->middleware(['throttle:20,1', 'access:update.safety.apply'])->name('exception');
    Route::get('/restore', [IntegrityAdminController::class, 'restorePlan'])->middleware('access:update.safety.apply')->name('restore');
    Route::post('/restore', [IntegrityAdminController::class, 'restoreRun'])->middleware(['throttle:6,1', 'access:update.safety.apply'])->name('restore.run');
    Route::get('/quarantine/{id}', [IntegrityAdminController::class, 'returnPlan'])->where('id', '[a-f0-9]{20}')
        ->middleware('access:update.safety.apply')->name('quarantine');
    Route::post('/quarantine/{id}', [IntegrityAdminController::class, 'returnRun'])->where('id', '[a-f0-9]{20}')
        ->middleware(['throttle:6,1', 'access:update.safety.apply'])->name('quarantine.run');
});
