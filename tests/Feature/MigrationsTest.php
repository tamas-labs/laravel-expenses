<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use TamasLabs\LaravelExpenses\Database\Factories\CategoryFactory;
use TamasLabs\LaravelExpenses\Database\Factories\ExpenseFactory;
use TamasLabs\LaravelExpenses\Models\Category;
use TamasLabs\LaravelExpenses\Tests\Support\SchemaInspector;

// Spec 03, 3.2, 3.10, 3.16. Migrations run DDL, which MySQL commits
// implicitly, so these tests use a throwaway database of their own instead of
// the transaction-wrapped test database.

const SCRATCH_DATABASE = 'expenses_migrations_test';

const PACKAGE_TABLES = [
    'sync_sequence', 'currencies', 'categories', 'subcategories', 'payment_methods', 'expenses', 'products',
    'product_prices', 'shopping_lists', 'shopping_list_items', 'budget_pockets', 'budget_pocket_categories',
];

const PROFILE_COLUMNS = ['uuid', 'default_currency_id', 'registered_at', 'profile_created_at', 'profile_updated_at', 'server_seq'];

/**
 * How many package migrations come after the given one (3.16).
 */
function migrationsAfter(string $name): int
{
    $files = array_map(
        static fn (string $file): string => basename($file, '.php'),
        glob(dirname(__DIR__, 2).'/database/migrations/*.php') ?: [],
    );
    sort($files);

    $position = array_search(true, array_map(static fn (string $file): bool => str_ends_with($file, $name), $files), true);
    assert(is_int($position));

    return count($files) - $position - 1;
}

/**
 * @param  array<string, mixed>  $options
 */
function artisanMigrate(string $command, array $options = []): void
{
    expect(Artisan::call($command, ['--force' => true, ...$options]))->toBe(0);
}

beforeEach(function (): void {
    DB::statement('DROP DATABASE IF EXISTS `'.SCRATCH_DATABASE.'`');
    DB::statement('CREATE DATABASE `'.SCRATCH_DATABASE.'`');

    Config::set('database.connections.scratch', [...Config::array('database.connections.mysql'), 'database' => SCRATCH_DATABASE]);
    Config::set('database.default', 'scratch');
});

afterEach(function (): void {
    DB::purge('scratch');
    DB::connection('mysql')->statement('DROP DATABASE IF EXISTS `'.SCRATCH_DATABASE.'`');
});

it('migrates up, down and up again', function (): void {
    artisanMigrate('migrate');
    expect(SchemaInspector::current()->tables())->toContain(...PACKAGE_TABLES);

    // Everything after Testbench's own users migration: the package's.
    artisanMigrate('migrate:rollback', ['--step' => migrationsAfter('create_sync_sequence_table') + 1]);
    expect(SchemaInspector::current()->tables())->not->toContain(...PACKAGE_TABLES)
        ->and(array_keys(SchemaInspector::current()->columns('users')))->not->toContain(...PROFILE_COLUMNS);

    artisanMigrate('migrate');
    expect(SchemaInspector::current()->tables())->toContain(...PACKAGE_TABLES)
        ->and(DB::table('currencies')->count())->toBe(3);
});

it('prefixes every table and foreign key with the configured prefix', function (string $prefix): void {
    Config::set('expenses.database.table_prefix', $prefix);

    artisanMigrate('migrate');

    $tables = SchemaInspector::current()->tables();
    expect($tables)->toContain(...array_map(static fn (string $table): string => $prefix.$table, PACKAGE_TABLES))
        ->and($tables)->not->toContain(...PACKAGE_TABLES);

    foreach (SchemaInspector::current()->foreignKeys() as $foreignKey) {
        expect($foreignKey)->toMatch('/^('.$prefix.'\w+|users)\(.*\) -> ('.$prefix.'\w+|users)\(/');
    }

    $category = CategoryFactory::new()->createOne();
    ExpenseFactory::new()->ownedBy($category->user_id)->createOne(['main_category_id' => $category->id]);

    expect((new Category)->getTable())->toBe($prefix.'categories')
        ->and(DB::table($prefix.'categories')->count())->toBe(1)
        ->and(DB::table($prefix.'expenses')->count())->toBe(1);
})->with([
    'exp_' => 'exp_',
    'the longest allowed' => 'exp_v1_',
]);

it('gives the existing users a UUID before the column becomes required', function (): void {
    artisanMigrate('migrate');
    artisanMigrate('migrate:rollback', ['--step' => migrationsAfter('add_expenses_profile_to_users_table') + 1]);

    foreach (['anna@example.com', 'bela@example.com'] as $email) {
        DB::table('users')->insert(['name' => $email, 'email' => $email, 'password' => 'x']);
    }

    artisanMigrate('migrate');

    $uuids = array_filter(DB::table('users')->pluck('uuid')->all(), is_string(...));

    expect($uuids)->toHaveCount(2)
        ->and(array_unique($uuids))->toHaveCount(2)
        ->and(SchemaInspector::current()->columns('users')['uuid'])->toBe('char(36) ascii_bin');

    foreach ($uuids as $uuid) {
        expect($uuid)->toMatch('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/');
    }
});

it('stops when the users table already has a column it would add', function (): void {
    artisanMigrate('migrate');
    artisanMigrate('migrate:rollback', ['--step' => migrationsAfter('add_expenses_profile_to_users_table') + 1]);

    Schema::table('users', function (Blueprint $table): void {
        $table->string('uuid')->nullable();
    });

    expect(fn (): int => Artisan::call('migrate', ['--force' => true]))
        ->toThrow(RuntimeException::class, 'The users table already has the column(s) uuid, which laravel-expenses adds.');
});

it('rejects a table prefix that would overflow MySQL identifiers', function (): void {
    Config::set('expenses.database.table_prefix', 'expenses_');

    expect(fn (): int => Artisan::call('migrate', ['--force' => true]))
        ->toThrow(InvalidArgumentException::class, 'at most 7 letters, digits or underscores');
});
