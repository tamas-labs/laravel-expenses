<?php

declare(strict_types=1);

namespace TamasLabs\LaravelExpenses;

use Illuminate\Routing\Router;
use Illuminate\Support\ServiceProvider;
use TamasLabs\LaravelExpenses\Contract\ContractValidator;
use TamasLabs\LaravelExpenses\Http\Middleware\EnsureContractVersion;

final class ExpensesServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(self::configPath(), 'expenses');

        $this->app->singleton(ContractValidator::class);
    }

    public function boot(): void
    {
        $this->app->make(Router::class)->aliasMiddleware('expenses.contract', EnsureContractVersion::class);

        $this->loadMigrationsFrom(self::migrationsPath());

        if ($this->app->runningInConsole()) {
            $this->publishes([
                self::configPath() => $this->app->configPath('expenses.php'),
            ], 'expenses-config');

            // Published under their own names: the migrator keys migrations by
            // name and lets the application's copy win, so a customised copy
            // replaces the package's instead of running next to it.
            $this->publishes([
                self::migrationsPath() => $this->app->databasePath('migrations'),
            ], 'expenses-migrations');
        }
    }

    private static function configPath(): string
    {
        return \dirname(__DIR__).'/config/expenses.php';
    }

    private static function migrationsPath(): string
    {
        return \dirname(__DIR__).'/database/migrations';
    }
}
