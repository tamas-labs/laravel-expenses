<?php

declare(strict_types=1);

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\ServiceProvider;
use TamasLabs\LaravelExpenses\Contract\ContractValidator;
use TamasLabs\LaravelExpenses\ExpensesServiceProvider;
use TamasLabs\LaravelExpenses\Http\Middleware\EnsureContractVersion;
use TamasLabs\LaravelExpenses\Sync\PullHandler;
use TamasLabs\LaravelExpenses\Sync\PushHandler;
use TamasLabs\LaravelExpenses\Sync\SyncStore;

it('loads the service provider', function (): void {
    expect(app()->getProviders(ExpensesServiceProvider::class))->toHaveCount(1);
});

it('merges the package config under the expenses key', function (): void {
    expect(config('expenses'))->toBeArray();
});

it('publishes the config file with the expenses-config tag', function (): void {
    $target = config_path('expenses.php');
    File::delete($target);

    try {
        $exitCode = Artisan::call('vendor:publish', ['--tag' => 'expenses-config']);

        expect($exitCode)->toBe(0)
            ->and($target)->toBeFile()
            ->and(File::get($target))->toBe(File::get(dirname(__DIR__, 2).'/config/expenses.php'));
    } finally {
        File::delete($target);
    }
});

it('loads the package migrations', function (): void {
    expect(app('migrator')->paths())->toContain(dirname(__DIR__, 2).'/database/migrations');
});

it('publishes the migrations under their own names with the expenses-migrations tag', function (): void {
    $source = dirname(__DIR__, 2).'/database/migrations';

    expect(ServiceProvider::pathsToPublish(ExpensesServiceProvider::class, 'expenses-migrations'))
        ->toBe([$source => database_path('migrations')]);
});

it('binds the contract validator as a singleton', function (): void {
    expect(app(ContractValidator::class))->toBe(app(ContractValidator::class));
});

it('registers the contract version middleware alias', function (): void {
    expect(app(Router::class)->getMiddleware())->toHaveKey('expenses.contract', EnsureContractVersion::class);
});

it('registers the sync routes under the configured prefix, behind the configured middleware and authentication', function (string $name, string $method, string $uri): void {
    $route = app(Router::class)->getRoutes()->getByName($name);

    expect($route)->not->toBeNull()
        ->and($route?->methods())->toContain($method)
        ->and($route?->uri())->toBe($uri)
        ->and($route?->gatherMiddleware())->toBe(['api', 'expenses.contract', 'auth']);
})->with([
    ['expenses.sync.push', 'POST', 'api/expenses/sync/push'],
    ['expenses.sync.pull', 'GET', 'api/expenses/sync/pull'],
]);

it('runs the contract version middleware before any other, authentication included', function (): void {
    // The priority goes onto the HTTP kernel when it is resolved.
    app(Kernel::class);

    expect(app(Router::class)->middlewarePriority[0] ?? null)->toBe(EnsureContractVersion::class);
});

it('binds the sync services as singletons', function (string $class): void {
    expect(app($class))->toBe(app($class));
})->with([SyncStore::class, PushHandler::class, PullHandler::class]);
