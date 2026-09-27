<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use TamasLabs\LaravelExpenses\Http\Controllers\PullController;
use TamasLabs\LaravelExpenses\Http\Controllers\PushController;
use TamasLabs\LaravelExpenses\Support\PackageConfig;

/*
 * The sync endpoints (spec 06, 5.5). The configured middleware comes first,
 * so that even a 401 carries the server's contract version; authentication is
 * the package's own, not a setting (spec 07 puts its token guard here).
 */
Route::prefix(PackageConfig::routePrefix())
    ->middleware([...PackageConfig::routeMiddleware(), 'auth'])
    ->group(static function (): void {
        Route::post('sync/push', PushController::class)->name('expenses.sync.push');
        Route::get('sync/pull', PullController::class)->name('expenses.sync.pull');
    });
