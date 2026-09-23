<?php

declare(strict_types=1);

use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\ServiceProvider;
use TamasLabs\LaravelExpenses\Contract\ContractValidator;
use TamasLabs\LaravelExpenses\ExpensesServiceProvider;
use TamasLabs\LaravelExpenses\Http\Middleware\EnsureContractVersion;

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
