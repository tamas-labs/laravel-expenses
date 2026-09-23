<?php

declare(strict_types=1);

namespace TamasLabs\LaravelExpenses;

use Illuminate\Support\ServiceProvider;

final class ExpensesServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(self::configPath(), 'expenses');
    }

    public function boot(): void
    {
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
