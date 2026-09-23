<?php

declare(strict_types=1);

namespace TamasLabs\LaravelExpenses\Tests;

use Illuminate\Database\Migrations\Migrator;
use Illuminate\Foundation\Application;
use Orchestra\Testbench\TestCase as Orchestra;
use TamasLabs\LaravelExpenses\ExpensesServiceProvider;
use TamasLabs\LaravelExpenses\Tests\Fixtures\User;
use TamasLabs\LaravelExpenses\Tests\Support\InteractsWithContract;

use function Orchestra\Testbench\default_migration_path;

abstract class TestCase extends Orchestra
{
    use InteractsWithContract;

    /**
     * @param  Application  $app
     * @return list<class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [ExpensesServiceProvider::class];
    }

    /**
     * The package supports MySQL only (roadmap D2), so the tests never fall
     * back to SQLite. Host, database and credentials come from the DB_*
     * variables (phpunit.xml.dist, the CI) through the framework's stock
     * `mysql` connection; the settings the package relies on are pinned here.
     *
     * The host's users table is Testbench's stock one, migrated before the
     * package's migrations extend it.
     *
     * @param  Application  $app
     */
    protected function defineEnvironment($app): void
    {
        $config = $app->make('config');

        $config->set('database.default', 'mysql');
        $config->set('database.connections.mysql.charset', 'utf8mb4');
        $config->set('database.connections.mysql.collation', 'utf8mb4_unicode_ci');
        $config->set('database.connections.mysql.strict', true);

        $config->set('expenses.user_model', User::class);

        $app->afterResolving('migrator', static function (Migrator $migrator): void {
            $migrator->path(default_migration_path());
        });
    }
}
