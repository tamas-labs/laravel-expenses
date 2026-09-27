<?php

declare(strict_types=1);

namespace TamasLabs\LaravelExpenses;

use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Foundation\Http\Kernel;
use Illuminate\Routing\Router;
use Illuminate\Support\ServiceProvider;
use TamasLabs\LaravelExpenses\Contract\ContractValidator;
use TamasLabs\LaravelExpenses\Http\Middleware\EnsureContractVersion;
use TamasLabs\LaravelExpenses\Registry\ResourceRegistry;
use TamasLabs\LaravelExpenses\Rules\DomainValidator;
use TamasLabs\LaravelExpenses\Sync\PullHandler;
use TamasLabs\LaravelExpenses\Sync\PushHandler;
use TamasLabs\LaravelExpenses\Sync\SyncStore;

final class ExpensesServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(self::configPath(), 'expenses');

        $this->app->singleton(ContractValidator::class);
        $this->app->singleton(ResourceRegistry::class);
        $this->app->singleton(DomainValidator::class);
        $this->app->singleton(SyncStore::class);
        $this->app->singleton(PushHandler::class);
        $this->app->singleton(PullHandler::class);
    }

    public function boot(): void
    {
        $this->app->make(Router::class)->aliasMiddleware('expenses.contract', EnsureContractVersion::class);

        // Ahead of the priority list, so it wraps authentication too: even a
        // 401 tells the client the server's contract version.
        $this->callAfterResolving(HttpKernel::class, static function (mixed $kernel): void {
            if ($kernel instanceof Kernel) {
                $kernel->prependToMiddlewarePriority(EnsureContractVersion::class);
            }
        });

        $this->loadMigrationsFrom(self::migrationsPath());
        $this->loadRoutesFrom(\dirname(__DIR__).'/routes/api.php');

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
