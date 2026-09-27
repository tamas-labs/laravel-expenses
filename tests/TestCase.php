<?php

declare(strict_types=1);

namespace TamasLabs\LaravelExpenses\Tests;

use Illuminate\Database\Migrations\Migrator;
use Illuminate\Foundation\Application;
use Laravel\Sanctum\SanctumServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;
use ReflectionClass;
use TamasLabs\LaravelExpenses\ExpensesServiceProvider;
use TamasLabs\LaravelExpenses\Tests\Fixtures\User;
use TamasLabs\LaravelExpenses\Tests\Support\InteractsWithContract;

use function Orchestra\Testbench\default_migration_path;

abstract class TestCase extends Orchestra
{
    use InteractsWithContract;

    /**
     * Where the reset link of the tests' mails points.
     */
    public const string PASSWORD_RESET_URL = 'expenses://reset-password?token={token}&email={email}';

    /**
     * Where an opened verification link leads in the tests.
     */
    public const string EMAIL_VERIFIED_URL = 'https://app.example.com/email-verified';

    /**
     * @param  Application  $app
     * @return list<class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [SanctumServiceProvider::class, ExpensesServiceProvider::class];
    }

    /**
     * The package supports MySQL only (roadmap D2), so the tests never fall
     * back to SQLite. Host, database and credentials come from the DB_*
     * variables (phpunit.xml.dist, the CI) through the framework's stock
     * `mysql` connection; the settings the package relies on are pinned here.
     *
     * The host's users table is Testbench's stock one, migrated before the
     * package's migrations extend it, and Sanctum's token table is the one
     * a host publishes. Mails and rate limits stay in memory.
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
        $config->set('expenses.auth.password_reset_url', self::PASSWORD_RESET_URL);
        $config->set('expenses.auth.email_verified_url', self::EMAIL_VERIFIED_URL);
        $config->set('auth.providers.users.model', User::class);
        // Signed verification links and reset tokens need one.
        $config->set('app.key', 'base64:'.base64_encode(str_repeat('k', 32)));
        $config->set('mail.default', 'array');
        $config->set('cache.default', 'array');

        $sanctum = \dirname((string) (new ReflectionClass(SanctumServiceProvider::class))->getFileName(), 2).'/database/migrations';

        $app->afterResolving('migrator', static function (Migrator $migrator) use ($sanctum): void {
            $migrator->path(default_migration_path());
            $migrator->path($sanctum);
        });
    }
}
