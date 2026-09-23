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

        if ($this->app->runningInConsole()) {
            $this->publishes([
                self::configPath() => $this->app->configPath('expenses.php'),
            ], 'expenses-config');
        }
    }

    private static function configPath(): string
    {
        return \dirname(__DIR__).'/config/expenses.php';
    }
}
